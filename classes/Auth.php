<?php
// production/classes/Auth.php - Fixed hasRole method for arrays
require_once __DIR__ . "/../config/database.php";

class Auth {
    private $conn;
    private $table_name = "Users";
    private $environment;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        
        // กำหนด environment
        $this->environment = defined('ENVIRONMENT') ? ENVIRONMENT : 'production';
        
        // ตรวจสอบและแยก session ตาม environment
        $this->validateEnvironmentSession();
    }
    
    /**
     * ตรวจสอบและแยก session ตาม environment
     */
    private function validateEnvironmentSession() {
        // ถ้ามี session แต่ environment ไม่ตรงกัน ให้ล้าง session
        if (isset($_SESSION['user_id']) && 
            (!isset($_SESSION['environment']) || $_SESSION['environment'] !== $this->environment)) {
            
            error_log("Session environment mismatch. Current: " . $this->environment . 
                     ", Session: " . ($_SESSION['environment'] ?? 'none'));
            
            // ล้าง session
            session_unset();
            session_destroy();
            
            // เริ่ม session ใหม่
            session_start();
            $_SESSION['environment'] = $this->environment;
        }
        
        // ตั้งค่า environment ใน session หากยังไม่มี
        if (!isset($_SESSION['environment'])) {
            $_SESSION['environment'] = $this->environment;
        }
    }
    
    public function login($username, $password) {
        try {
            $query = "SELECT user_id, username, full_name, role, password_hash, is_active 
                      FROM {$this->table_name} 
                      WHERE username = ? AND is_active = 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$username]);
            
            // สำหรับ SQL Server ใช้ fetch() แทน rowCount()
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            error_log("Login attempt - Username: " . $username . " - Environment: " . $this->environment);
            
            if ($user) { // ถ้า fetch() ได้ข้อมูล
                error_log("User found - Username: " . $user['username'] . ", Environment: " . $this->environment);
                
                // ตรวจสอบรหัสผ่าน
                if ($password === $user['password_hash']) {
                    // เก็บข้อมูลใน session พร้อม environment identifier
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['environment'] = $this->environment; // สำคัญ! ระบุ environment
                    $_SESSION['login_time'] = time();
                    $_SESSION['session_prefix'] = 'prod_'; // prefix สำหรับ production
                    
                    $this->updateLastLogin($user['user_id']);
                    
                    error_log("Login successful for user: " . $user['username'] . " in environment: " . $this->environment);
                    return true;
                } else {
                    error_log("Password mismatch - Environment: " . $this->environment);
                }
            } else {
                error_log("No user found with username: " . $username . " in environment: " . $this->environment);
            }
        } catch (Exception $e) {
            error_log("Login error in " . $this->environment . ": " . $e->getMessage());
        }
        
        return false;
    }
    
    public function logout() {
        error_log("Logout initiated for environment: " . $this->environment);
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit();
    }
    
    public function isLoggedIn() {
        // ตรวจสอบทั้ง user_id และ environment
        $isLoggedIn = isset($_SESSION['user_id']) && 
                     !empty($_SESSION['user_id']) &&
                     isset($_SESSION['environment']) &&
                     $_SESSION['environment'] === $this->environment;
        
        if (!$isLoggedIn && isset($_SESSION['user_id'])) {
            error_log("Login check failed - User ID exists but environment mismatch. " .
                     "Current env: " . $this->environment . 
                     ", Session env: " . ($_SESSION['environment'] ?? 'none'));
        }
        
        return $isLoggedIn;
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            error_log("Access denied - Not logged in or environment mismatch. Environment: " . $this->environment);
            header("Location: login.php");
            exit();
        }
    }
    
    /**
     * ตรวจสอบสิทธิ์ผู้ใช้ - รองรับทั้ง string และ array
     * @param string|array $required_roles - role หรือ array ของ roles ที่อนุญาต
     * @return bool
     */
    public function hasRole($required_roles) {
        if (!$this->isLoggedIn()) return false;
        if (!isset($_SESSION['role'])) return false;
        
        $user_role = $_SESSION['role'];
        
        // ถ้าเป็น array ให้ตรวจสอบว่า user role อยู่ในรายการไหม
        if (is_array($required_roles)) {
            return in_array($user_role, $required_roles);
        }
        
        // ถ้าเป็น string ให้ใช้ hierarchy แบบเดิม
        $role_hierarchy = ['viewer' => 1, 'editor' => 2, 'admin' => 3];
        $user_level = $role_hierarchy[$user_role] ?? 0;
        $required_level = $role_hierarchy[$required_roles] ?? 0;
        
        return $user_level >= $required_level;
    }
    
    /**
     * บังคับให้มี role ที่กำหนด - รองรับทั้ง string และ array
     * @param string|array $required_roles
     */
    public function requireRole($required_roles) {
        if (!$this->hasRole($required_roles)) {
            $roles_str = is_array($required_roles) ? implode(', ', $required_roles) : $required_roles;
            
            error_log("Access denied - Insufficient role. Required: " . $roles_str . 
                     ", User role: " . ($_SESSION['role'] ?? 'none') . 
                     ", Environment: " . $this->environment);
            http_response_code(403);
            die("Access denied. Required role: " . $roles_str . " (Environment: " . $this->environment . ")");
        }
    }
    
    private function updateLastLogin($user_id) {
        try {
            $query = "UPDATE {$this->table_name} SET last_login = GETDATE() WHERE user_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$user_id]);
            
            error_log("Last login updated for user ID: " . $user_id . " in environment: " . $this->environment);
        } catch (Exception $e) {
            error_log("Update last login error in " . $this->environment . ": " . $e->getMessage());
        }
    }
    
    public function getAllUsers() {
        try {
            $query = "SELECT user_id, username, full_name, role, password_hash, is_active, created_date 
                      FROM {$this->table_name} ORDER BY user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get all users error in " . $this->environment . ": " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * ตรวจสอบข้อมูล session ปัจจุบัน
     */
    public function getSessionInfo() {
        return [
            'environment' => $this->environment,
            'session_environment' => $_SESSION['environment'] ?? 'none',
            'user_id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'role' => $_SESSION['role'] ?? null,
            'login_time' => $_SESSION['login_time'] ?? null,
            'session_prefix' => $_SESSION['session_prefix'] ?? null,
            'is_logged_in' => $this->isLoggedIn()
        ];
    }
    
    /**
     * ล้าง session ของ environment อื่น
     */
    public function clearOtherEnvironmentSessions() {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['environment'] = $this->environment;
        
        error_log("Cleared sessions from other environments. Current environment: " . $this->environment);
    }
    
    public function testLogin($username, $password) {
        try {
            $query = "SELECT user_id, username, full_name, role, password_hash, is_active 
                      FROM {$this->table_name} 
                      WHERE username = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$username]);
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC); // ใช้ fetch() แทน rowCount()
            
            if ($user) { // ถ้ามีข้อมูล
                return [
                    'user_found' => true,
                    'user_data' => $user,
                    'password_input' => $password,
                    'password_stored' => $user['password_hash'],
                    'password_match_plain' => ($password === $user['password_hash']),
                    'password_match_verify' => password_verify($password, $user['password_hash']),
                    'is_active' => $user['is_active'],
                    'table_used' => $this->table_name,
                    'environment' => $this->environment,
                    'session_info' => $this->getSessionInfo()
                ];
            } else {
                return [
                    'user_found' => false,
                    'username_searched' => $username,
                    'table_used' => $this->table_name,
                    'environment' => $this->environment
                ];
            }
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'table_used' => $this->table_name,
                'environment' => $this->environment
            ];
        }
    }
    
    public function testConnection() {
        try {
            $query = "SELECT COUNT(*) as count FROM {$this->table_name}";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'status' => true,
                'message' => "Connected successfully to table {$this->table_name}",
                'user_count' => $result['count'],
                'environment' => $this->environment,
                'session_info' => $this->getSessionInfo()
            ];
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => $e->getMessage(),
                'table_used' => $this->table_name,
                'environment' => $this->environment
            ];
        }
    }
}
?>