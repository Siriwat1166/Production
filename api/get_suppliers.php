<?php
// ===== api/get_suppliers.php =====
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT supplier_id, supplier_code, supplier_name 
              FROM Suppliers 
              WHERE is_active = 1 
              ORDER BY supplier_name";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>