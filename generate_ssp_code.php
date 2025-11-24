<?php
// ===== api/generate_ssp_code.php =====

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
    $material_type_id = $input['material_type_id'] ?? null;
    $group_id = $input['group_id'] ?? null;
    $supplier_id = $input['supplier_id'] ?? null;
    
    if (!$material_type_id || !$group_id || !$supplier_id) {
        throw new Exception('Missing required parameters');
    }
    
    // ดึงข้อมูล codes
    $query = "SELECT 
                mt.type_code,
                g.id as group_code,
                s.supplier_code
              FROM Material_Types mt, Groups g, Suppliers s
              WHERE mt.material_type_id = ? 
                AND g.id = ? 
                AND s.supplier_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$material_type_id, $group_id, $supplier_id]);
    $codes = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$codes) {
        throw new Exception('Invalid parameters');
    }
    
    // สร้าง component codes
    $type_code = $codes['type_code'];
    $group_code = str_pad($codes['group_code'], 3, '0', STR_PAD_LEFT);
    $supplier_code = $codes['supplier_code'];
    
// สร้าง prefix
$prefix = $type_code . $group_code . $supplier_code;

$conn->beginTransaction();

// ดึงเลข running number ล่าสุด
$sql_last = "SELECT MAX(CAST(RIGHT(SSP_Code, 5) AS INT)) as last_number 
             FROM Master_Products_ID 
             WHERE SSP_Code LIKE ? + '%'";
             
$stmt_last = $conn->prepare($sql_last);
$stmt_last->execute([$prefix]);
$row = $stmt_last->fetch(PDO::FETCH_ASSOC);

error_log("Query prefix: " . $prefix);
error_log("Query result: " . json_encode($row));

// สร้างเลขใหม่ (+10 แทนที่จะเป็น +1)
$last_number = $row['last_number'] ?? 0;
$next_number = $last_number + 10;
$run_number = str_pad($next_number, 5, '0', STR_PAD_LEFT);

// สร้าง SSP Code เต็ม
$ssp_code = $prefix . $run_number;

error_log("Last number: " . $last_number);
error_log("Next number: " . $next_number);
error_log("Generated SSP Code: " . $ssp_code);
    
    // อัพเดท SSP_Code_Generator table (สำหรับ tracking)
    $checkQuery = "SELECT current_run_no FROM SSP_Code_Generator 
                   WHERE material_type_id = ? AND group_id = ? AND supplier_id = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->execute([$material_type_id, $group_id, $supplier_id]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        $updateQuery = "UPDATE SSP_Code_Generator 
                        SET current_run_no = ?, last_generated = GETDATE() 
                        WHERE material_type_id = ? AND group_id = ? AND supplier_id = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute([$next_number, $material_type_id, $group_id, $supplier_id]);
    } else {
        $insertQuery = "INSERT INTO SSP_Code_Generator 
                        (material_type_id, group_id, supplier_id, current_run_no, last_generated, is_active) 
                        VALUES (?, ?, ?, ?, GETDATE(), 1)";
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->execute([$material_type_id, $group_id, $supplier_id, $next_number]);
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'ssp_code' => $ssp_code,
        'run_number' => $next_number,
        'debug' => [
            'prefix' => $prefix,
            'last_number' => $last_number,
            'next_number' => $next_number
        ],
        'components' => [
            'type' => $type_code,
            'group' => $group_code,
            'supplier' => $supplier_code,
            'run' => $run_number
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>