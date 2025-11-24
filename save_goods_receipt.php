<?php
// api/save_goods_receipt.php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // เริ่ม Transaction
    $db->beginTransaction();
    
    // รับข้อมูลจากฟอร์ม
    $receipt_type = $_POST['receipt_type'] ?? '';
    $gr_number = $_POST['gr_number'] ?? '';
    $receipt_date = $_POST['receipt_date'] ?? date('Y-m-d');
    $warehouse_id = $_POST['warehouse_id'] ?? 0;
    $received_by = $_SESSION['user_id'];
    $po_id = ($receipt_type === 'WITH_PO') ? ($_POST['po_id'] ?? null) : null;
    $supplier_id = ($receipt_type === 'WITHOUT_PO') ? ($_POST['supplier_id'] ?? null) : null;
    $receipt_reason = $_POST['receipt_reason'] ?? null;
    $invoice_number = $_POST['invoice_number'] ?? null;
    $invoice_date = $_POST['invoice_date'] ?? null;
    $delivery_note_number = $_POST['delivery_note_number'] ?? null;
    $notes = $_POST['notes'] ?? null;
    
    // ตรวจสอบข้อมูลที่จำเป็น
    if (empty($gr_number) || empty($receipt_date) || empty($warehouse_id)) {
        throw new Exception('กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน');
    }
    
    // ตรวจสอบรายการสินค้า
    $items = $_POST['items'] ?? [];
    if (empty($items)) {
        throw new Exception('กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ');
    }
    
    // คำนวณยอดรวม
    $total_quantity = 0;
    $total_amount = 0;
    
    foreach ($items as $item) {
        $qty = floatval($item['quantity_received'] ?? 0);
        $cost = floatval($item['unit_cost'] ?? 0);
        $total_quantity += $qty;
        $total_amount += ($qty * $cost);
    }
    
    // 1. บันทึก Goods_Receipt Header
    $query_header = "INSERT INTO Goods_Receipt (
        gr_number, po_id, receipt_date, warehouse_id, 
        total_quantity, total_amount, status, received_by,
        invoice_number, invoice_date, delivery_note_number,
        notes, created_date, receipt_type
    ) VALUES (
        :gr_number, :po_id, :receipt_date, :warehouse_id,
        :total_quantity, :total_amount, 'pending', :received_by,
        :invoice_number, :invoice_date, :delivery_note_number,
        :notes, GETDATE(), :receipt_type
    )";
    
    $stmt_header = $db->prepare($query_header);
    $stmt_header->execute([
        ':gr_number' => $gr_number,
        ':po_id' => $po_id,
        ':receipt_date' => $receipt_date,
        ':warehouse_id' => $warehouse_id,
        ':total_quantity' => $total_quantity,
        ':total_amount' => $total_amount,
        ':received_by' => $received_by,
        ':invoice_number' => $invoice_number,
        ':invoice_date' => $invoice_date,
        ':delivery_note_number' => $delivery_note_number,
        ':notes' => $notes,
        ':receipt_type' => $receipt_type
    ]);
    
    // ดึง gr_id ที่สร้างใหม่
    $gr_id = $db->lastInsertId();
    
    // 2. บันทึก Goods_Receipt_Items
    $query_item = "INSERT INTO Goods_Receipt_Items (
        gr_id, po_item_id, product_id, 
        quantity_ordered, quantity_received, quantity_accepted,
        received_unit_id, stock_unit_id, conversion_factor, stock_quantity,
        unit_cost, total_cost, warehouse_id, location_id,
        batch_lot, supplier_lot_number, quality_status
    ) VALUES (
        :gr_id, :po_item_id, :product_id,
        :quantity_ordered, :quantity_received, :quantity_accepted,
        :received_unit_id, :stock_unit_id, :conversion_factor, :stock_quantity,
        :unit_cost, :total_cost, :warehouse_id, :location_id,
        :batch_lot, :supplier_lot_number, 'pending'
    )";
    
    $stmt_item = $db->prepare($query_item);
    
    foreach ($items as $item) {
        $product_id = intval($item['product_id'] ?? 0);
        $po_item_id = isset($item['po_item_id']) ? intval($item['po_item_id']) : null;
        $quantity_ordered = floatval($item['quantity_ordered'] ?? 0);
        $quantity_received = floatval($item['quantity_received'] ?? 0);
        $quantity_accepted = $quantity_received; // เริ่มต้นให้เท่ากับจำนวนรับ
        $received_unit_id = intval($item['received_unit_id'] ?? 0);
        $stock_unit_id = intval($item['stock_unit_id'] ?? 0);
        $stock_quantity = floatval($item['stock_quantity'] ?? 0);
        $unit_cost = floatval($item['unit_cost'] ?? 0);
        $total_cost = $quantity_received * $unit_cost;
        $supplier_lot_number = $item['supplier_lot_number'] ?? null;
        
        // คำนวณ conversion factor
        $conversion_factor = ($quantity_received > 0 && $stock_quantity > 0) 
            ? ($stock_quantity / $quantity_received) 
            : 1;
        
        $stmt_item->execute([
            ':gr_id' => $gr_id,
            ':po_item_id' => $po_item_id,
            ':product_id' => $product_id,
            ':quantity_ordered' => $quantity_ordered,
            ':quantity_received' => $quantity_received,
            ':quantity_accepted' => $quantity_accepted,
            ':received_unit_id' => $received_unit_id,
            ':stock_unit_id' => $stock_unit_id,
            ':conversion_factor' => $conversion_factor,
            ':stock_quantity' => $stock_quantity,
            ':unit_cost' => $unit_cost,
            ':total_cost' => $total_cost,
            ':warehouse_id' => $warehouse_id,
            ':location_id' => null, // จะระบุทีหลัง
            ':batch_lot' => null, // จะสร้างทีหลัง
            ':supplier_lot_number' => $supplier_lot_number
        ]);
        
        $gr_item_id = $db->lastInsertId();
        
        // 3. สร้าง Lot Tracking (สำหรับสินค้าที่ต้องติดตาม Lot)
        if (!empty($supplier_lot_number)) {
            $query_lot = "INSERT INTO Lot_Batch_Tracking (
                product_id, supplier_id, supplier_lot_number,
                quantity_received, quantity_remaining, unit_id,
                warehouse_id, lot_status, received_date,
                created_by, created_date
            ) VALUES (
                :product_id, :supplier_id, :supplier_lot_number,
                :quantity_received, :quantity_remaining, :unit_id,
                :warehouse_id, 'available', GETDATE(),
                :created_by, GETDATE()
            )";
            
            $stmt_lot = $db->prepare($query_lot);
            $stmt_lot->execute([
                ':product_id' => $product_id,
                ':supplier_id' => $supplier_id ?? ($po_id ? getSupplierFromPO($db, $po_id) : null),
                ':supplier_lot_number' => $supplier_lot_number,
                ':quantity_received' => $stock_quantity,
                ':quantity_remaining' => $stock_quantity,
                ':unit_id' => $stock_unit_id,
                ':warehouse_id' => $warehouse_id,
                ':created_by' => $received_by
            ]);
        }
        
        // 4. บันทึก Receipt Status History
        $query_history = "INSERT INTO Receipt_Item_Status_History (
            gr_item_id, status_id, status_date, changed_by, notes
        ) VALUES (
            :gr_item_id, 1, GETDATE(), :changed_by, 'รับเข้าสินค้าเบื้องต้น'
        )";
        
        $stmt_history = $db->prepare($query_history);
        $stmt_history->execute([
            ':gr_item_id' => $gr_item_id,
            ':changed_by' => $received_by
        ]);
    }
    
    // 5. บันทึก Receipt Source Mapping
    if ($receipt_type === 'WITH_PO' && $po_id) {
        $query_mapping = "INSERT INTO Receipt_Source_Mapping (
            gr_id, source_type, source_id
        ) VALUES (
            :gr_id, 'PO', :source_id
        )";
        
        $stmt_mapping = $db->prepare($query_mapping);
        $stmt_mapping->execute([
            ':gr_id' => $gr_id,
            ':source_id' => $po_id
        ]);
    } else if ($receipt_type === 'WITHOUT_PO') {
        // สร้าง Direct Receipt Header
        $query_direct = "INSERT INTO Direct_Receipt_Header (
            receipt_number, receipt_date, supplier_id, receipt_reason,
            total_items, total_quantity, estimated_value, status,
            created_by, created_date, notes
        ) VALUES (
            :receipt_number, :receipt_date, :supplier_id, :receipt_reason,
            :total_items, :total_quantity, :estimated_value, 'completed',
            :created_by, GETDATE(), :notes
        )";
        
        $stmt_direct = $db->prepare($query_direct);
        $stmt_direct->execute([
            ':receipt_number' => $gr_number,
            ':receipt_date' => $receipt_date,
            ':supplier_id' => $supplier_id,
            ':receipt_reason' => $receipt_reason,
            ':total_items' => count($items),
            ':total_quantity' => $total_quantity,
            ':estimated_value' => $total_amount,
            ':created_by' => $received_by,
            ':notes' => $notes
        ]);
        
        $direct_receipt_id = $db->lastInsertId();
        
        // Mapping
        $query_mapping = "INSERT INTO Receipt_Source_Mapping (
            gr_id, source_type, source_id
        ) VALUES (
            :gr_id, 'DIRECT', :source_id
        )";
        
        $stmt_mapping = $db->prepare($query_mapping);
        $stmt_mapping->execute([
            ':gr_id' => $gr_id,
            ':source_id' => $direct_receipt_id
        ]);
    }
    
    // 6. บันทึก Audit Log
    $query_audit = "INSERT INTO Audit_Log (
        table_name, record_id, action, new_values, 
        changed_by, changed_date, ip_address
    ) VALUES (
        'Goods_Receipt', :record_id, 'INSERT', :new_values,
        :changed_by, GETDATE(), :ip_address
    )";
    
    $stmt_audit = $db->prepare($query_audit);
    $stmt_audit->execute([
        ':record_id' => $gr_id,
        ':new_values' => json_encode([
            'gr_number' => $gr_number,
            'receipt_type' => $receipt_type,
            'total_quantity' => $total_quantity,
            'total_amount' => $total_amount
        ], JSON_UNESCAPED_UNICODE),
        ':changed_by' => $received_by,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    // Commit Transaction
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'บันทึกการรับเข้าสินค้าเรียบร้อยแล้ว',
        'gr_id' => $gr_id,
        'gr_number' => $gr_number
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    
    error_log("Save Goods Receipt Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Helper function
function getSupplierFromPO($db, $po_id) {
    $query = "SELECT supplier_id FROM PO_Header WHERE po_id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$po_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['supplier_id'] : null;
}
?>