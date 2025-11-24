<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT unit_id, unit_code, unit_name_th as unit_name, unit_symbol 
              FROM Units 
              WHERE is_active = 1 
              ORDER BY unit_name_th";
    
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