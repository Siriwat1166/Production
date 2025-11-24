<?php
// api/get_po_list.php
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
    
    $status = $_GET['status'] ?? 'approved';
    
    // ดึงรายการ PO ที่อนุมัติแล้วและยังรับไม่ครบ
    $query = "SELECT 
        ph.po_id,
        ph.po_number,
        ph.po_date,
        ph.supplier_id,
        ph.total_amount,
        ph.delivery_date,
        ph.status,
        s.supplier_code,
        s.supplier_name,
        -- นับจำนวนรายการทั้งหมด
        COUNT(DISTINCT pi.po_item_id) as total_items,
        -- นับจำนวนรายการที่ยังรับไม่ครบ
        SUM(CASE 
            WHEN (pi.quantity - ISNULL(pi.received_quantity, 0)) > 0 
            THEN 1 
            ELSE 0 
        END) as pending_items,
        -- คำนวณยอดรวมที่ยังค้าง
        SUM(CASE 
            WHEN (pi.quantity - ISNULL(pi.received_quantity, 0)) > 0 
            THEN (pi.quantity - ISNULL(pi.received_quantity, 0)) * pi.unit_price
            ELSE 0 
        END) as pending_amount
    FROM PO_Header ph
    INNER JOIN Suppliers s ON ph.supplier_id = s.supplier_id
    LEFT JOIN PO_Items pi ON ph.po_id = pi.po_id 
        AND pi.status != 'cancelled'
    WHERE ph.status = ?
    AND ph.is_material_po = 1
    GROUP BY 
        ph.po_id, ph.po_number, ph.po_date, ph.supplier_id,
        ph.total_amount, ph.delivery_date, ph.status,
        s.supplier_code, s.supplier_name
    HAVING SUM(CASE 
        WHEN (pi.quantity - ISNULL(pi.received_quantity, 0)) > 0 
        THEN 1 
        ELSE 0 
    END) > 0
    ORDER BY ph.po_date DESC, ph.po_number DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$status]);
    $po_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // เพิ่มข้อมูลเปอร์เซ็นต์การรับเข้า
    foreach ($po_list as &$po) {
        if ($po['total_items'] > 0) {
            $received_items = $po['total_items'] - $po['pending_items'];
            $po['receive_percentage'] = round(($received_items / $po['total_items']) * 100, 2);
        } else {
            $po['receive_percentage'] = 0;
        }
        
        // Format วันที่
        $po['po_date_formatted'] = date('d/m/Y', strtotime($po['po_date']));
        if ($po['delivery_date']) {
            $po['delivery_date_formatted'] = date('d/m/Y', strtotime($po['delivery_date']));
        }
        
        // Format จำนวนเงิน
        $po['total_amount_formatted'] = number_format($po['total_amount'], 2);
        $po['pending_amount_formatted'] = number_format($po['pending_amount'], 2);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $po_list,
        'total_records' => count($po_list)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Get PO List Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>