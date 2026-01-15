<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // ดึงข้อมูลกลุ่มทั้งหมดที่ active
    $query = "SELECT id, name, description 
              FROM Groups 
              WHERE is_active = 1 
              ORDER BY id";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>