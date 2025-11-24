<?php
// production/config/config.php - Environment Separated

// กำหนด session name แยกสำหรับ production
ini_set('session.name', 'PRODUCTION_SESSION');

// กำหนด session cookie path แยก
ini_set('session.cookie_path', '/PD/production/');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Environment Detection
define('ENVIRONMENT', 'production');

// กำหนด session environment
if (!isset($_SESSION['environment']) || $_SESSION['environment'] !== 'production') {
    // ล้าง session ที่มาจาก environment อื่น
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['environment'] = 'production';
}

// Application settings
define('APP_NAME', 'Material Management System - Production');
define('APP_VERSION', '1.0.0');

// *** Database Constants ***
define('DB_SERVER', '192.168.2.3\\SQLEXPRESS');
define('DB_NAME', 'Production');
define('DB_USERNAME', 'PD02');
define('DB_PASSWORD', 'Pass1234');

// Error reporting สำหรับ production
error_reporting(0);
ini_set('display_errors', 0);

// Log errors instead
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// สร้าง logs directory ถ้ายังไม่มี
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Timezone
date_default_timezone_set('Asia/Bangkok');

// Debug mode - ปิดใน production
define('DEBUG_MODE', true);

// Security settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0); // เปลี่ยนเป็น 1 ถ้าใช้ HTTPS
ini_set('session.use_strict_mode', 1);

// Session timeout (30 minutes)
ini_set('session.gc_maxlifetime', 1800);

// *** ฟังก์ชันทดสอบการเชื่อมต่อฐานข้อมูล (แก้ไขคำสั่ง SQL) ***
function testDatabaseConnection() {
    try {
        $pdo = new PDO(
            "sqlsrv:Server=" . DB_SERVER . ";Database=" . DB_NAME,
            DB_USERNAME,
            DB_PASSWORD,
            array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
            )
        );
        
        // ทดสอบการเชื่อมต่อด้วยคำสั่งที่ปลอดภัยสำหรับ SQL Server
        $stmt = $pdo->query("SELECT 1 as test_connection");
        $result = $stmt->fetch();
        
        if ($result && $result['test_connection'] == 1) {
            return [
                'status' => true,
                'message' => 'Database connection successful',
                'server' => DB_SERVER,
                'database' => DB_NAME
            ];
        } else {
            return [
                'status' => false,
                'message' => 'Connection test failed',
                'server' => DB_SERVER,
                'database' => DB_NAME
            ];
        }
        
    } catch (PDOException $e) {
        error_log("Database Connection Failed: " . $e->getMessage());
        return [
            'status' => false,
            'message' => $e->getMessage(),
            'server' => DB_SERVER,
            'database' => DB_NAME
        ];
    }
}

// *** ฟังก์ชันช่วยเหลือ ***
function formatDate($date, $format = 'd/m/Y') {
    if (empty($date)) return '-';
    return date($format, strtotime($date));
}

function formatDateTime($datetime, $format = 'd/m/Y H:i:s') {
    if (empty($datetime)) return '-';
    return date($format, strtotime($datetime));
}

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

function generateRandomString($length = 10) {
    return substr(str_shuffle('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $length);
}

// *** ฟังก์ชันตรวจสอบสถานะระบบ ***
function getSystemStatus() {
    // ทดสอบการเชื่อมต่อฐานข้อมูล
    $dbStatus = testDatabaseConnection();
    
    // ตรวจสอบการเขียนไฟล์ log
    $logWritable = is_writable(__DIR__ . '/../logs');
    
    // ตรวจสอบ PHP Extensions ที่จำเป็น
    $requiredExtensions = ['pdo', 'pdo_sqlsrv', 'sqlsrv'];
    $missingExtensions = [];
    
    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $missingExtensions[] = $ext;
        }
    }
    
    return [
        'database' => $dbStatus,
        'log_writable' => $logWritable,
        'missing_extensions' => $missingExtensions,
        'php_version' => PHP_VERSION,
        'environment' => ENVIRONMENT,
        'app_version' => APP_VERSION
    ];
}

// Log environment info
error_log("Production environment initialized - Session ID: " . session_id() . " - Environment: " . ENVIRONMENT);

// *** ทดสอบการเชื่อมต่อฐานข้อมูลเมื่อโหลด config (เฉพาะเมื่อ Debug) ***
if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
    $db_test = testDatabaseConnection();
    if (!$db_test['status']) {
        error_log("Database Connection Test Failed: " . $db_test['message']);
    } else {
        error_log("Database Connection Test Successful");
    }
}
?>