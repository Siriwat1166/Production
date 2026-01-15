<?php
// pages/po/check_po_number.php - ตรวจสอบเลข PO ซ้ำ
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

header('Content-Type: application/json; charset=utf-8');

// ป้องกัน CSRF และตรวจสอบ authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'error' => true,
        'message' => 'Unauthorized access'
    ]);
    exit();
}

$response = [
    'exists' => false,
    'message' => '',
    'error' => false
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['po_number'])) {
    $po_number = trim($_POST['po_number']);
    
    // Validate PO number format
    if (empty($po_number)) {
        echo json_encode([
            'exists' => false,
            'message' => 'PO number is empty',
            'error' => false
        ]);
        exit();
    }
    
    if (strlen($po_number) < 3) {
        echo json_encode([
            'exists' => false,
            'message' => 'PO number too short',
            'error' => false
        ]);
        exit();
    }
    
    try {
        require_once "../../config/database.php";
        $database = new Database();
        $conn = $database->getConnection();
        
        // ตรวจสอบเลข PO ซ้ำ
        $stmt = $conn->prepare("SELECT TOP 1 po_id, status FROM PO_Header WHERE po_number = ?");
        $stmt->execute([$po_number]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $response = [
                'exists' => true,
                'message' => "เลข PO '{$po_number}' มีอยู่แล้ว",
                'error' => false,
                'po_id' => $result['po_id'],
                'status' => $result['status']
            ];
        } else {
            $response = [
                'exists' => false,
                'message' => "เลข PO '{$po_number}' ใช้ได้",
                'error' => false
            ];
        }
        
        // Log การตรวจสอบ
        error_log("PO Number Check - PO: {$po_number}, Exists: " . ($response['exists'] ? 'Yes' : 'No'));
        
    } catch (PDOException $e) {
        error_log("Database error in check_po_number.php: " . $e->getMessage());
        
        $response = [
            'exists' => false,
            'message' => 'ไม่สามารถตรวจสอบได้ กรุณาลองใหม่',
            'error' => true,
            'error_detail' => DEBUG_MODE ? $e->getMessage() : 'Database connection failed'
        ];
        
        http_response_code(500);
    } catch (Exception $e) {
        error_log("General error in check_po_number.php: " . $e->getMessage());
        
        $response = [
            'exists' => false,
            'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่',
            'error' => true,
            'error_detail' => DEBUG_MODE ? $e->getMessage() : 'Internal server error'
        ];
        
        http_response_code(500);
    }
    
} else {
    $response = [
        'exists' => false,
        'message' => 'Invalid request method',
        'error' => true
    ];
    
    http_response_code(400);
}

// ส่งผลลัพธ์
echo json_encode($response);
?>