<?php
// ===== api/validate_ssp_code.php =====
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $ssp_code = $input['ssp_code'] ?? '';
    
    if (empty($ssp_code)) {
        throw new Exception('SSP Code is required');
    }
    
    // ตรวจสอบว่า SSP Code ซ้ำหรือไม่
    $query = "SELECT COUNT(*) as count FROM Master_Products_ID WHERE SSP_Code = ?";
    $stmt = $conn->prepare($query);
    $stmt->execute([$ssp_code]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $is_available = $result['count'] == 0;
    
    echo json_encode([
        'is_available' => $is_available,
        'message' => $is_available ? 'SSP Code available' : 'SSP Code already exists'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>