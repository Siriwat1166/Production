<?php
// pages/po/ajax/search_master_po.php - ค้นหา PO หลักสำหรับเชื่อมโยง
require_once "../../../config/config.php";
require_once "../../../classes/Auth.php";

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=" . DB_SERVER . ";Database=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $po_number = $_GET['po_number'] ?? '';
    
    if (empty($po_number)) {
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุเลข PO']);
        exit;
    }
    
    // ค้นหา PO
    $stmt = $pdo->prepare("
        SELECT 
            ph.po_id, ph.po_number, ph.po_date, ph.supplier_id, ph.po_type_id,
            ph.material_amount, ph.freight_amount, ph.service_amount, ph.total_amount,
            ph.currency, ph.exchange_rate, ph.delivery_date, ph.payment_terms,
            ph.status, ph.notes,
            s.supplier_name, s.supplier_code,
            pt.type_name, pt.cost_category,
            COUNT(pi.po_item_id) as item_count
        FROM PO_Header ph
        JOIN Suppliers s ON ph.supplier_id = s.supplier_id
        JOIN PO_Types pt ON ph.po_type_id = pt.po_type_id
        LEFT JOIN PO_Items pi ON ph.po_id = pi.po_id
        WHERE ph.po_number = ? AND ph.status != 'CANCELLED'
        GROUP BY ph.po_id, ph.po_number, ph.po_date, ph.supplier_id, ph.po_type_id,
                 ph.material_amount, ph.freight_amount, ph.service_amount, ph.total_amount,
                 ph.currency, ph.exchange_rate, ph.delivery_date, ph.payment_terms,
                 ph.status, ph.notes, s.supplier_name, s.supplier_code,
                 pt.type_name, pt.cost_category
    ");
    
    $stmt->execute([$po_number]);
    $po_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$po_data) {
        echo json_encode([
            'success' => false, 
            'message' => "ไม่พบ PO เลขที่ $po_number หรือ PO นี้ถูกยกเลิกแล้ว"
        ]);
        exit;
    }
    
    // ตรวจสอบว่ามี Freight PO เชื่อมโยงแล้วหรือไม่
    $stmt = $pdo->prepare("
        SELECT po_number, total_amount 
        FROM PO_Header 
        WHERE po_number LIKE ? AND po_type_id = 2
        ORDER BY po_number
    ");
    $stmt->execute([$po_number . '-F%']);
    $freight_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงรายการสินค้าใน PO
    $stmt = $pdo->prepare("
        SELECT 
            pi.po_item_id, pi.line_number, pi.product_id, pi.item_description,
            pi.quantity, pi.unit_price, pi.total_price,
            mp.SSP_Code, mp.Name as product_name,
            u1.unit_name as purchase_unit, u2.unit_name as stock_unit
        FROM PO_Items pi
        LEFT JOIN Master_Products_ID mp ON pi.product_id = mp.id
        LEFT JOIN Units u1 ON pi.purchase_unit_id = u1.unit_id
        LEFT JOIN Units u2 ON pi.stock_unit_id = u2.unit_id
        WHERE pi.po_id = ?
        ORDER BY pi.line_number
    ");
    $stmt->execute([$po_data['po_id']]);
    $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'po_data' => $po_data,
        'po_items' => $po_items,
        'freight_pos' => $freight_pos,
        'message' => 'พบข้อมูล PO แล้ว'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}
?>

<?php
// pages/po/ajax/get_product_units.php - ดึงหน่วยของสินค้า
require_once "../../../config/config.php";
require_once "../../../classes/Auth.php";

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=" . DB_SERVER . ";Database=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $product_id = $_GET['product_id'] ?? 0;
    
    if (!$product_id) {
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุ product_id']);
        exit;
    }
    
    // ดึงหน่วยของสินค้า
    $stmt = $pdo->prepare("
        SELECT 
            pu.unit_id, pu.unit_type, pu.conversion_factor,
            pu.is_base_unit, pu.is_purchase_unit, pu.is_stock_unit, pu.is_issue_unit,
            u.unit_code, u.unit_name, u.unit_symbol
        FROM Product_Units pu
        JOIN Units u ON pu.unit_id = u.unit_id
        WHERE pu.product_id = ? AND pu.is_active = 1
        ORDER BY pu.is_base_unit DESC, u.unit_name
    ");
    
    $stmt->execute([$product_id]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูลสินค้า
    $stmt = $pdo->prepare("
        SELECT 
            mp.id, mp.SSP_Code, mp.Name, mp.Name2,
            mt.type_name as material_type,
            g.name as group_name
        FROM Master_Products_ID mp
        LEFT JOIN Material_Types mt ON mp.material_type_id = mt.material_type_id
        LEFT JOIN Groups g ON mp.group_id = g.id
        WHERE mp.id = ?
    ");
    
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'product' => $product,
        'units' => $units
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}
?>

<?php
// pages/po/ajax/calculate_conversion.php - คำนวณการแปลงหน่วย
require_once "../../../config/config.php";
require_once "../../../classes/Auth.php";

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=" . DB_SERVER . ";Database=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $product_id = $_POST['product_id'] ?? 0;
    $from_unit_id = $_POST['from_unit_id'] ?? 0;
    $to_unit_id = $_POST['to_unit_id'] ?? 0;
    $quantity = $_POST['quantity'] ?? 0;
    
    if (!$product_id || !$from_unit_id || !$to_unit_id) {
        echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
        exit;
    }
    
    // ดึงปัจจัยแปลงหน่วย
    $stmt = $pdo->prepare("
        SELECT conversion_factor 
        FROM Product_Units 
        WHERE product_id = ? AND unit_id = ?
    ");
    
    $stmt->execute([$product_id, $from_unit_id]);
    $from_factor = $stmt->fetchColumn();
    
    $stmt->execute([$product_id, $to_unit_id]);
    $to_factor = $stmt->fetchColumn();
    
    if ($from_factor === false || $to_factor === false) {
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลการแปลงหน่วย']);
        exit;
    }
    
    if ($to_factor == 0) {
        echo json_encode(['success' => false, 'message' => 'ไม่สามารถแปลงหน่วยได้']);
        exit;
    }
    
    // คำนวณการแปลง
    $converted_quantity = ($quantity * $from_factor) / $to_factor;
    $conversion_factor = $from_factor / $to_factor;
    
    echo json_encode([
        'success' => true,
        'converted_quantity' => round($converted_quantity, 4),
        'conversion_factor' => round($conversion_factor, 6),
        'calculation' => [
            'from_factor' => $from_factor,
            'to_factor' => $to_factor,
            'original_quantity' => $quantity
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}
?>

<?php
// pages/po/ajax/save_draft.php - บันทึก PO แบบ Draft
require_once "../../../config/config.php";
require_once "../../../classes/Auth.php";

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=" . DB_SERVER . ";Database=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $user_id = $_SESSION['user_id'];
    
    // รับข้อมูลจาก POST
    $po_data = json_decode($_POST['po_data'], true);
    
    if (!$po_data) {
        echo json_encode(['success' => false, 'message' => 'ข้อมูล PO ไม่ถูกต้อง']);
        exit;
    }
    
    $pdo->beginTransaction();
    
    // ตรวจสอบว่า PO นี้มีอยู่แล้วหรือไม่ (สำหรับ update draft)
    $stmt = $pdo->prepare("SELECT po_id FROM PO_Header WHERE po_number = ? AND status = 'DRAFT'");
    $stmt->execute([$po_data['po_number']]);
    $existing_po_id = $stmt->fetchColumn();
    
    if ($existing_po_id) {
        // อัปเดต PO ที่มีอยู่
        $sql = "UPDATE PO_Header SET 
                    po_date = ?, supplier_id = ?, po_type_id = ?,
                    delivery_date = ?, payment_terms = ?, notes = ?,
                    updated_by = ?, updated_date = GETDATE()
                WHERE po_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $po_data['po_date'], $po_data['supplier_id'], $po_data['po_type_id'],
            $po_data['delivery_date'], $po_data['payment_terms'], $po_data['notes'],
            $user_id, $existing_po_id
        ]);
        
        // ลบรายการเดิม
        $pdo->prepare("DELETE FROM PO_Items WHERE po_id = ?")->execute([$existing_po_id]);
        
        $po_id = $existing_po_id;
    } else {
        // สร้าง PO ใหม่
        $sql = "INSERT INTO PO_Header (
                    po_number, po_date, supplier_id, po_type_id,
                    material_amount, freight_amount, service_amount, total_amount,
                    tax_amount, net_amount, currency, exchange_rate,
                    delivery_date, payment_terms, status, notes,
                    created_by, created_date
                ) VALUES (?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 'THB', 1, ?, ?, 'DRAFT', ?, ?, GETDATE())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $po_data['po_number'], $po_data['po_date'], $po_data['supplier_id'], $po_data['po_type_id'],
            $po_data['delivery_date'], $po_data['payment_terms'], $po_data['notes'], $user_id
        ]);
        
        $po_id = $pdo->lastInsertId();
    }
    
    // เพิ่มรายการใหม่
    if (isset($po_data['items']) && is_array($po_data['items'])) {
        $line_number = 1;
        foreach ($po_data['items'] as $item) {
            if (isset($item['product_id']) && $item['product_id'] > 0) {
                $sql_item = "INSERT INTO PO_Items (
                    po_id, line_number, product_id, item_type_id,
                    item_description, quantity, purchase_unit_id, stock_unit_id,
                    conversion_factor, stock_quantity, unit_price, total_price,
                    status, pending_quantity
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)";
                
                $stmt_item = $pdo->prepare($sql_item);
                $stmt_item->execute([
                    $po_id, $line_number, $item['product_id'], $item['item_type_id'] ?? 1,
                    $item['description'] ?? '', $item['quantity'], $item['purchase_unit_id'] ?? 1,
                    $item['stock_unit_id'] ?? 1, $item['conversion_factor'] ?? 1,
                    $item['stock_quantity'] ?? $item['quantity'], $item['unit_price'],
                    $item['total_price'], $item['quantity']
                ]);
                
                $line_number++;
            }
        }
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'บันทึก Draft สำเร็จแล้ว',
        'po_id' => $po_id,
        'po_number' => $po_data['po_number']
    ]);
    
} catch (Exception $e) {
    $pdo->rollback();
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}
?>

<?php
// pages/po/check_po_number.php - ตรวจสอบเลข PO ซ้ำ
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=" . DB_SERVER . ";Database=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $po_number = $_GET['po_number'] ?? '';
    $exclude_po_id = $_GET['exclude_po_id'] ?? 0;
    
    if (empty($po_number)) {
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุเลข PO']);
        exit;
    }
    
    $sql = "SELECT po_id, po_number, status FROM PO_Header WHERE po_number = ?";
    $params = [$po_number];
    
    if ($exclude_po_id > 0) {
        $sql .= " AND po_id != ?";
        $params[] = $exclude_po_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $existing_po = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_po) {
        echo json_encode([
            'success' => false,
            'exists' => true,
            'message' => "เลข PO {$po_number} มีอยู่แล้ว (สถานะ: {$existing_po['status']})",
            'existing_po' => $existing_po
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'exists' => false,
            'message' => "เลข PO {$po_number} สามารถใช้ได้"
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}
?>