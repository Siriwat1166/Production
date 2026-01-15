<?php
// api/get_warehouse_locations.php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $warehouse_id = $_GET['warehouse_id'] ?? 0;
    
    if (empty($warehouse_id)) {
        throw new Exception('ไม่พบรหัสคลัง');
    }
    
    // ดึง Locations จากคลังที่เลือก
    $query = "SELECT 
        location_id,
        warehouse_id,
        zone,
        aisle,
        rack,
        shelf,
        bin,
        location_code,
        location_type,
        capacity,
        is_active
    FROM Warehouse_Locations
    WHERE warehouse_id = ? AND is_active = 1
    ORDER BY 
        CASE 
            WHEN zone IS NOT NULL THEN zone 
            ELSE 'Z' 
        END,
        CASE 
            WHEN aisle IS NOT NULL THEN aisle 
            ELSE 'Z' 
        END,
        CASE 
            WHEN rack IS NOT NULL THEN rack 
            ELSE 'Z' 
        END,
        CASE 
            WHEN shelf IS NOT NULL THEN shelf 
            ELSE 'Z' 
        END";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$warehouse_id]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // สร้าง location code ถ้ายังไม่มี
    foreach ($locations as &$location) {
        if (empty($location['location_code'])) {
            $parts = [];
            if (!empty($location['zone'])) $parts[] = $location['zone'];
            if (!empty($location['aisle'])) $parts[] = $location['aisle'];
            if (!empty($location['rack'])) $parts[] = $location['rack'];
            if (!empty($location['shelf'])) $parts[] = $location['shelf'];
            if (!empty($location['bin'])) $parts[] = $location['bin'];
            
            $location['location_code'] = implode('-', $parts);
        }
        
        // เพิ่มข้อมูลความจุที่เหลือ (ถ้ามี)
        $location['capacity_display'] = $location['capacity'] 
            ? number_format($location['capacity'], 2) 
            : 'ไม่ระบุ';
    }
    
    echo json_encode([
        'success' => true,
        'data' => $locations,
        'total_records' => count($locations)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Get Warehouse Locations Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>