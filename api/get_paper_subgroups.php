<?php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT sub_group_id, sub_group_code, sub_group_name, description 
              FROM Material_Sub_Groups 
              WHERE main_group_id = 255 AND is_active = 1 
              ORDER BY sub_group_id";
    
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