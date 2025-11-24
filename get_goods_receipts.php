<?php
// api/get_goods_receipts.php
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
    
    // รับพารามิเตอร์การกรอง
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $status = $_GET['status'] ?? null;
    $warehouse_id = $_GET['warehouse_id'] ?? null;
    
    // สร้าง query
    $query = "SELECT 
        gr.gr_id,
        gr.gr_number,
        gr.po_id,
        gr.receipt_date,
        gr.warehouse_id,
        gr.total_quantity,
        gr.total_amount,
        gr.status,
        gr.received_by,
        gr.created_date,
        gr.receipt_type,
        gr.invoice_number,
        gr.delivery_note_number,
        w.warehouse_code,
        w.warehouse_name,
        w.warehouse_name_th,
        u.full_name as received_by_name,
        ph.po_number,
        s.supplier_name,
        -- นับจำนวนรายการสินค้า
        COUNT(DISTINCT gri.gr_item_id) as total_items
    FROM Goods_Receipt gr
    INNER JOIN Warehouses w ON gr.warehouse_id = w.warehouse_id
    INNER JOIN Users u ON gr.received_by = u.user_id
    LEFT JOIN PO_Header ph ON gr.po_id = ph.po_id
    LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
    LEFT JOIN Goods_Receipt_Items gri ON gr.gr_id = gri.gr_id
    WHERE 1=1";
    
    $params = [];
    
    // เพิ่มเงื่อนไขการกรอง
    if ($date_from) {
        $query .= " AND gr.receipt_date >= ?";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $query .= " AND gr.receipt_date <= ?";
        $params[] = $date_to;
    }
    
    if ($status) {
        $query .= " AND gr.status = ?";
        $params[] = $status;
    }
    
    if ($warehouse_id) {
        $query .= " AND gr.warehouse_id = ?";
        $params[] = $warehouse_id;
    }
    
    $query .= " GROUP BY 
        gr.gr_id, gr.gr_number, gr.po_id, gr.receipt_date, gr.warehouse_id,
        gr.total_quantity, gr.total_amount, gr.status, gr.received_by,
        gr.created_date, gr.receipt_type, gr.invoice_number, gr.delivery_note_number,
        w.warehouse_code, w.warehouse_name, w.warehouse_name_th,
        u.full_name, ph.po_number, s.supplier_name";
    
    $query .= " ORDER BY gr.receipt_date DESC, gr.gr_number DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format ข้อมูล
    foreach ($receipts as &$receipt) {
        $receipt['receipt_date_formatted'] = date('d/m/Y', strtotime($receipt['receipt_date']));
        $receipt['total_amount_formatted'] = number_format($receipt['total_amount'], 2);
        
        // เพิ่มข้อมูลสถานะเป็นภาษาไทย
        $status_map = [
            'pending' => 'รอตรวจสอบ',
            'received' => 'รับเข้าแล้ว',
            'approved' => 'อนุมัติแล้ว',
            'rejected' => 'ปฏิเสธ'
        ];
        $receipt['status_th'] = $status_map[$receipt['status']] ?? $receipt['status'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $receipts,
        'total_records' => count($receipts)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Get Goods Receipts Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>