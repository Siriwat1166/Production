<?php
// config/database.php   192.168.2.3\SQLEXPRESS ใช้ในsql
class Database {
    private $host = "192.168.2.3\\SQLEXPRESS";
    private $db_name = 'Production';
    private $username = 'PD02';
    private $password = 'Pass1234';
    private $conn;

    public function getConnection() {
        $this->conn = null;
        
        try {
            // สำหรับ SQL Server - ใช้ options ที่ support
            $this->conn = new PDO(
                "sqlsrv:Server=" . $this->host . ";Database=" . $this->db_name,
                $this->username,
                $this->password,
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
                    // ลบ PDO::ATTR_TIMEOUT และ PDO::ATTR_PERSISTENT ออก
                )
            );
            
            // Test connection ด้วยคำสั่งง่ายๆ
            $this->conn->exec("SELECT 1");
            
        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            throw new Exception("Database connection failed: " . $exception->getMessage());
        }
        
        return $this->conn;
    }
    
    // ฟังก์ชันทดสอบการเชื่อมต่อ
    public function testConnection() {
        try {
            $conn = $this->getConnection();
            if ($conn) {
                return [
                    'status' => true,
                    'message' => 'Database connection successful',
                    'server' => $this->host,
                    'database' => $this->db_name
                ];
            }
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => $e->getMessage(),
                'server' => $this->host,
                'database' => $this->db_name
            ];
        }
        
        return [
            'status' => false,
            'message' => 'Unknown connection error',
            'server' => $this->host,
            'database' => $this->db_name
        ];
    }
    
    // ฟังก์ชันตรวจสอบตารางที่จำเป็น (แก้ไข)
    public function checkRequiredTables() {
        $required_tables = [
            'Users',
            'Material_Types', 
            'Groups',
            'Suppliers',
            'SSP_Code_Generator',
            'Master_Products_ID',
            'Specific_Paperboard',
            'Specific_Ink',
            'Specific_Coating',
            'Specific_Adhesive',
            'Specific_Film',
            'Specific_Foil',
            'Specific_Plate',
            'Specific_Corrugated_box'
        ];
        
        $missing_tables = [];
        $existing_tables = [];
        
        try {
            $conn = $this->getConnection();
            
            foreach ($required_tables as $table) {
                $query = "SELECT COUNT(*) as table_count FROM INFORMATION_SCHEMA.TABLES 
                         WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = ?";
                $stmt = $conn->prepare($query);
                $stmt->execute([$table]);
                $result = $stmt->fetch();
                
                if ($result['table_count'] == 0) {
                    $missing_tables[] = $table;
                } else {
                    $existing_tables[] = $table;
                }
            }
            
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => 'Error checking tables: ' . $e->getMessage(),
                'missing_tables' => [],
                'existing_tables' => [],
                'total_required' => count($required_tables),
                'total_existing' => 0
            ];
        }
        
        return [
            'status' => count($missing_tables) == 0,
            'message' => count($missing_tables) == 0 ? 'All required tables exist' : 'Missing tables found',
            'missing_tables' => $missing_tables,
            'existing_tables' => $existing_tables,
            'total_required' => count($required_tables),
            'total_existing' => count($existing_tables)
        ];
    }
    
    // ฟังก์ชันตรวจสอบข้อมูลตัวอย่าง (แก้ไข)
    public function checkSampleData() {
        try {
            $conn = $this->getConnection();
            $results = [];
            
            // ตรวจสอบแต่ละตารางว่ามีอยู่หรือไม่ก่อน
            $tables_to_check = [
                'Users' => 'users',
                'Material_Types' => 'material_types',
                'Groups' => 'groups', 
                'Suppliers' => 'suppliers',
                'Master_Products_ID' => 'materials'
            ];
            
            foreach ($tables_to_check as $table => $key) {
                try {
                    $stmt = $conn->query("SELECT COUNT(*) as count FROM $table");
                    $results[$key] = $stmt->fetch()['count'];
                } catch (Exception $e) {
                    $results[$key] = 'Table not found';
                }
            }
            
            return [
                'status' => true,
                'data' => $results
            ];
            
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => 'Error checking sample data: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    // ฟังก์ชันสำหรับ Debug
    public function getDatabaseInfo() {
        try {
            $conn = $this->getConnection();
            
            // Get SQL Server version
            $stmt = $conn->query("SELECT @@VERSION as version");
            $version = $stmt->fetch()['version'];
            
            // Get database name
            $stmt = $conn->query("SELECT DB_NAME() as db_name");
            $db_name = $stmt->fetch()['db_name'];
            
            // Get current user
            $stmt = $conn->query("SELECT SYSTEM_USER as current_user");
            $current_user = $stmt->fetch()['current_user'];
            
            return [
                'status' => true,
                'server_version' => $version,
                'database_name' => $db_name,
                'current_user' => $current_user,
                'connection_host' => $this->host
            ];
            
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // ฟังก์ชันแสดงตารางทั้งหมดในฐานข้อมูล
    public function getAllTables() {
        try {
            $conn = $this->getConnection();
            
            $query = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
                     WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = 'dbo'
                     ORDER BY TABLE_NAME";
            $stmt = $conn->query($query);
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            return [
                'status' => true,
                'tables' => $tables,
                'count' => count($tables)
            ];
            
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => $e->getMessage(),
                'tables' => [],
                'count' => 0
            ];
        }
    }
}
?>