<?php
// api/get_po_items.php
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
    
    $po_id = $_GET['po_id'] ?? 0;
    
    if (empty($po_id)) {
        throw new Exception('ไม่พบรหัส PO');
    }
    
    // ดึงข้อมูล PO Header
    $query_header = "SELECT 
        ph.po_id,
        ph.po_number,
        ph.po_date,
        ph.supplier_id,
        ph.total_amount,
        ph.status,
        s.supplier_code,
        s.supplier_name
    FROM PO_Header ph
    INNER JOIN Suppliers s ON ph.supplier_id = s.supplier_id
    WHERE ph.po_id = ?";
    
    $stmt_header = $db->prepare($query_header);
    $stmt_header->execute([$po_id]);
    $po_header = $stmt_header->fetch(PDO::FETCH_ASSOC);
    
    if (!$po_header) {
        throw new Exception('ไม่พบข้อมูล PO');
    }
    
    // ดึงรายการสินค้าใน PO
    $query_items = "SELECT 
        pi.po_item_id,
        pi.po_id,
        pi.line_number,
        pi.product_id,
        pi.item_type_id,
        pi.item_description,
        pi.quantity,
        pi.purchase_unit_id,
        pi.stock_unit_id,
        pi.conversion_factor,
        pi.stock_quantity,
        pi.unit_price,
        pi.total_price,
        pi.received_quantity,
        pi.pending_quantity,
        pi.status,
        mp.SSP_Code,
        mp.Name as product_name,
        mp.material_type_id,
        pu.unit_name as purchase_unit_name,
        pu.unit_name_th as purchase_unit_name_th,
        pu.unit_code as purchase_unit_code,
        su.unit_name as stock_unit_name,
        su.unit_name_th as stock_unit_name_th,
        su.unit_code as stock_unit_code,
        pit.type_name as item_type_name,
        mt.type_name as material_type_name,
        -- คำนวณจำนวนที่ยังรับไม่ครบ
        (pi.quantity - ISNULL(pi.received_quantity, 0)) as remaining_quantity
    FROM PO_Items pi
    LEFT JOIN Master_Products_ID mp ON pi.product_id = mp.id
    LEFT JOIN Units pu ON pi.purchase_unit_id = pu.unit_id
    LEFT JOIN Units su ON pi.stock_unit_id = su.unit_id
    LEFT JOIN PO_Item_Types pit ON pi.item_type_id = pit.item_type_id
    LEFT JOIN Material_Types mt ON mp.material_type_id = mt.material_type_id
    WHERE pi.po_id = ? 
    AND pi.status != 'cancelled'
    AND (pi.quantity - ISNULL(pi.received_quantity, 0)) > 0
    ORDER BY pi.line_number";
    
    $stmt_items = $db->prepare($query_items);
    $stmt_items->execute([$po_id]);
    $po_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูลเฉพาะของกระดาษสำหรับแต่ละรายการ
    foreach ($po_items as &$item) {
        if ($item['material_type_id'] == 1 && $item['product_id']) {
            $query_paper = "SELECT 
                W_mm, L_mm, gsm, Weight_kg_per_sheet, brand, type_paperboard_TH
            FROM Specific_Paperboard
            WHERE product_id = ? AND is_active = 1";
            
            $stmt_paper = $db->prepare($query_paper);
            $stmt_paper->execute([$item['product_id']]);
            $paper_data = $stmt_paper->fetch(PDO::FETCH_ASSOC);
            
            if ($paper_data) {
                $item['paperboard_data'] = $paper_data;
                $item['weight_per_sheet'] = floatval($paper_data['Weight_kg_per_sheet']);
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'po_header' => $po_header,
        'data' => $po_items,
        'total_items' => count($po_items)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Get PO Items Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>