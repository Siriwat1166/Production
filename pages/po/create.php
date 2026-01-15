<?php
// pages/po/create.php - หน้าสร้าง Purchase Order (Complete Fixed)
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole('editor');

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper function for input sanitization
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

// ตรวจสอบว่าฟังก์ชันถูกประกาศไว้หรือยัง (ป้องกันซ้ำ)
if (!function_exists('validateRequired')) {
    function validateRequired($value, $fieldName) {
        if (empty(trim($value))) {
            throw new Exception("กรุณากรอก{$fieldName}");
        }
        return trim($value);
    }
}

// เชื่อมต่อฐานข้อมูล
try {
    require_once "../../config/database.php";
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

// ข้อความแจ้ง
$message = '';
$message_type = '';

// ดึงข้อมูลสำหรับ dropdown
try {
    // Suppliers - ดึงจาก Suppliers table ที่ is_active = 1
    $stmt = $conn->prepare("SELECT supplier_id, supplier_code, supplier_name FROM Suppliers WHERE is_active = 1 ORDER BY supplier_name");
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // PO Types
    $stmt = $conn->prepare("SELECT po_type_id, type_code, type_name, type_name_th FROM PO_Types WHERE is_active = 1 ORDER BY type_name");
    $stmt->execute();
    $po_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    

// Products for Material PO - ใช้ supplier_id จากตาราง products โดยตรง
$stmt = $conn->prepare("
    SELECT p.id, p.SSP_Code, p.Name, p.Name2, u.unit_name, u.unit_symbol,
           g.name as group_name, mt.type_name as material_type_name,
           p.supplier_id
    FROM Master_Products_ID p 
    LEFT JOIN Units u ON p.Unit_id = u.unit_id 
    LEFT JOIN Groups g ON p.group_id = g.id
    LEFT JOIN Material_Types mt ON p.material_type_id = mt.material_type_id
    WHERE p.is_active = 1 AND p.status = 1 
    ORDER BY p.SSP_Code, p.Name
");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Units
    $stmt = $conn->prepare("SELECT unit_id, unit_code, unit_name, unit_name_th, unit_symbol FROM Units WHERE is_active = 1 ORDER BY unit_name");
    $stmt->execute();
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Material POs for Freight linking - เฉพาะ PO ที่เป็น Material (แสดงทั้งหมด ไม่ต้องกรองตาม Supplier)
    $stmt = $conn->prepare("
        SELECT ph.po_id, ph.po_number, ph.supplier_id, ph.total_amount, ph.po_date, s.supplier_name
        FROM PO_Header ph
        LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
        WHERE ph.is_material_po = 1 AND ph.status IN ('Draft', 'Approved', 'Partial') 
        ORDER BY ph.po_date DESC, ph.po_number DESC
    ");
    $stmt->execute();
    $material_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error loading dropdown data: " . $e->getMessage());
    $suppliers = [];
    $po_types = [];
    $products = [];
    $units = [];
    $material_pos = [];
    $message = "เกิดข้อผิดพลาดในการโหลดข้อมูล: " . $e->getMessage();
    $message_type = "danger";
}


// ประมวลผลการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ตรวจสอบ CSRF token
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid CSRF token");
        }
        
        $conn->beginTransaction();
        
        // รับข้อมูลพื้นฐาน
        $po_number = validateRequired(sanitizeInput($_POST['po_number']), 'เลขที่ PO');
        $po_type = sanitizeInput($_POST['po_type']);
        $supplier_id = intval($_POST['supplier_id']);
        $po_date = $_POST['po_date'];
        $notes = sanitizeInput($_POST['notes'] ?? '');
        
        // ตรวจสอบข้อมูลพื้นฐาน
        if (!$po_number || !$po_type || !$supplier_id || !$po_date) {
            throw new Exception("กรุณากรอกข้อมูลพื้นฐานให้ครบถ้วน");
        }
        
        // ตรวจสอบว่าเลขที่ PO ซ้ำหรือไม่
        $stmt = $conn->prepare("SELECT po_id FROM PO_Header WHERE po_number = ?");
        $stmt->execute([$po_number]);
        if ($stmt->fetch()) {
            throw new Exception("เลขที่ PO นี้มีอยู่ในระบบแล้ว กรุณาใช้เลขที่อื่น");
        }
        
        // 🔥 ดึง item_type_id สำหรับ Material และ Freight
        try {
            // ดึง item_type_id สำหรับ Material items
            $material_type_stmt = $conn->prepare("
                SELECT item_type_id FROM Item_Types 
                WHERE type_name = 'Material' OR type_code = 'MAT' OR type_name LIKE '%Material%'
                ORDER BY item_type_id 
            ");
            $material_type_stmt->execute();
            $material_type_result = $material_type_stmt->fetch(PDO::FETCH_ASSOC);
            $item_type_id_material = $material_type_result['item_type_id'] ?? 1; // Default เป็น 1

            // ดึง item_type_id สำหรับ Freight items
            $freight_type_stmt = $conn->prepare("
                SELECT item_type_id FROM Item_Types 
                WHERE type_name = 'Freight' OR type_code = 'FRT' OR type_name LIKE '%Freight%'
                ORDER BY item_type_id 
            ");
            $freight_type_stmt->execute();
            $freight_type_result = $freight_type_stmt->fetch(PDO::FETCH_ASSOC);
            $item_type_id_freight = $freight_type_result['item_type_id'] ?? 2; // Default เป็น 2

            error_log("Item Type IDs - Material: {$item_type_id_material}, Freight: {$item_type_id_freight}");

        } catch (PDOException $e) {
            // ถ้าไม่มีตาราง Item_Types หรือเกิดข้อผิดพลาด ใช้ค่า default
            error_log("Warning: Could not fetch item_type_id, using defaults. Error: " . $e->getMessage());
            $item_type_id_material = 1;
            $item_type_id_freight = 2;
        }
        
        // คำนวณยอดรวม
        $total_amount = 0;
        $material_amount = 0;
        $freight_amount = 0;
        $service_amount = 0;
        
        if ($po_type === 'material') {
            // คำนวณยอดรวมจาก Material Items
            if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
                for ($i = 0; $i < count($_POST['product_id']); $i++) {
                    if (!empty($_POST['product_id'][$i]) && !empty($_POST['quantity'][$i]) && !empty($_POST['unit_price'][$i])) {
                        $quantity = floatval($_POST['quantity'][$i]);
                        $unit_price = floatval($_POST['unit_price'][$i]);
                        $material_amount += $quantity * $unit_price;
                    }
                }
            }
            $total_amount = $material_amount;
        } else if ($po_type === 'freight') {
            // คำนวณยอดรวมจาก Freight Items
            if (isset($_POST['freight_amount']) && is_array($_POST['freight_amount'])) {
                foreach ($_POST['freight_amount'] as $amount) {
                    if (!empty($amount)) {
                        $freight_amount += floatval($amount);
                    }
                }
            }
            $total_amount = $freight_amount;
        }
        
        // เตรียมตัวแปรสำหรับ INSERT
        $linked_po_id = ($po_type === 'freight' && !empty($_POST['linked_po_id'])) ? intval($_POST['linked_po_id']) : null;
        $po_type_id = 1;
        $is_material_po = ($po_type === 'material') ? 1 : 0;
        $is_freight_po = ($po_type === 'freight') ? 1 : 0;
        $po_category = ($po_type === 'material') ? 'Material' : 'Freight';
        $net_amount = $total_amount; // คำนวณ net_amount = total_amount (หรือหักส่วนลดได้)

        // Log debug info
        error_log("=== PO Creation Debug ===");
        error_log("PO Number: " . $po_number);
        error_log("PO Type: " . $po_type);
        error_log("Supplier ID: " . $supplier_id);
        error_log("Total Amount: " . $total_amount);
        error_log("Net Amount: " . $net_amount);
        
        // บันทึก PO Header ด้วย OUTPUT clause
        try {
            $stmt = $conn->prepare("
                INSERT INTO PO_Header 
                (po_number, po_date, supplier_id, po_type_id, material_amount, freight_amount, service_amount, 
                 total_amount, net_amount, currency, exchange_rate, status, notes, created_by, created_date, 
                 is_material_po, is_freight_po, linked_po_id, po_category) 
                OUTPUT INSERTED.po_id
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'THB', 1.0, 'Approved', ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $po_number, $po_date, $supplier_id, $po_type_id, 
                $material_amount, $freight_amount, $service_amount, $total_amount, $net_amount,
                $notes, $_SESSION['user_id'], date('Y-m-d H:i:s'), 
                $is_material_po, $is_freight_po, $linked_po_id, $po_category
            ]);

            // รับ PO ID จาก OUTPUT clause
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $po_id = $result['po_id'] ?? null;

            if (!$po_id) {
                error_log("OUTPUT clause failed, trying fallback method");
                throw new Exception("OUTPUT method failed");
            }

            error_log("Successfully got PO ID from OUTPUT: " . $po_id);

        } catch (Exception $e) {
            error_log("OUTPUT method failed: " . $e->getMessage() . ", trying fallback");
            
            // Fallback: INSERT แบบธรรมดา แล้วค้นหา PO ID
            $stmt = $conn->prepare("
                INSERT INTO PO_Header 
                (po_number, po_date, supplier_id, po_type_id, material_amount, freight_amount, service_amount, 
                 total_amount, net_amount, currency, exchange_rate, status, notes, created_by, created_date, 
                 is_material_po, is_freight_po, linked_po_id, po_category) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'THB', 1.0, 'Approved', ?, ?, ?, ?, ?, ?, ?)
            ");

            $result = $stmt->execute([
                $po_number, $po_date, $supplier_id, $po_type_id, 
                $material_amount, $freight_amount, $service_amount, $total_amount, $net_amount,
                $notes, $_SESSION['user_id'], date('Y-m-d H:i:s'), 
                $is_material_po, $is_freight_po, $linked_po_id, $po_category
            ]);

            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Insert PO_Header failed: " . $errorInfo[2]);
            }

            // ค้นหา PO ID จาก po_number (เพราะ po_number เป็น unique)
            $search_stmt = $conn->prepare("SELECT po_id FROM PO_Header WHERE po_number = ? ORDER BY created_date DESC");
            $search_stmt->execute([$po_number]);
            $search_result = $search_stmt->fetch(PDO::FETCH_ASSOC);
            $po_id = $search_result['po_id'] ?? null;

            if (!$po_id) {
                error_log("Failed to retrieve PO ID even with fallback method");
                throw new Exception("ไม่สามารถรับ PO ID ได้แม้ใช้วิธีสำรอง");
            }

            error_log("Successfully got PO ID from fallback: " . $po_id);
        }

        // ตรวจสอบความถูกต้องของ PO ID
        if (!is_numeric($po_id) || $po_id <= 0) {
            throw new Exception("PO ID ที่ได้รับไม่ถูกต้อง: " . $po_id);
        }

        error_log("Final PO ID: " . $po_id);
        
        // บันทึกรายการสินค้าหรือค่าใช้จ่าย
        if ($po_type === 'material') {
            // บันทึก Material Items
            if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
                $line_number = 1;
    for ($i = 0; $i < count($_POST['product_id']); $i++) {
    if (!empty($_POST['product_id'][$i]) && !empty($_POST['quantity'][$i]) && !empty($_POST['unit_price'][$i])) {
        $product_id = intval($_POST['product_id'][$i]);
        $quantity = floatval($_POST['quantity'][$i]);
        $purchase_unit_id = intval($_POST['purchase_unit_id'][$i]);
        $unit_price = floatval($_POST['unit_price'][$i]);
        $total_price = $quantity * $unit_price;
        $notes_item = sanitizeInput($_POST['notes_item'][$i] ?? '');
        
        // ✨ รับข้อมูลกระป๋อง/ถัง
        $package_qty = !empty($_POST['package_qty'][$i]) ? floatval($_POST['package_qty'][$i]) : null;
        $weight_per_package = !empty($_POST['weight_per_package'][$i]) ? floatval($_POST['weight_per_package'][$i]) : null;
        
        // ✨ รับข้อมูลแผ่น
        $sheets_qty = !empty($_POST['quantity'][$i]) ? floatval($_POST['quantity'][$i]) : null;
        
        // 🔍 Validation
        if ($package_qty !== null && $weight_per_package !== null) {
            $calculated_weight = $package_qty * $weight_per_package;
            $difference = abs($calculated_weight - $quantity);
            
            if ($difference > 0.01) {
                error_log("WARNING: Line {$line_number} - Weight mismatch! Calculated: {$calculated_weight}, Entered: {$quantity}");
            }
        }
        
        // สำหรับ PAPER: ใช้ sheets_qty แทน package_qty และไม่คำนวณ weight
        // ตรวจสอบว่าเป็น PAPER type จาก material_type
        $stmt_check = $conn->prepare("SELECT material_type_id FROM Master_Products_ID WHERE id = ?");
        $stmt_check->execute([$product_id]);
        $product_info = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        if ($product_info) {
            $stmt_check2 = $conn->prepare("SELECT type_name FROM Material_Types WHERE material_type_id = ?");
            $stmt_check2->execute([$product_info['material_type_id']]);
            $type_info = $stmt_check2->fetch(PDO::FETCH_ASSOC);
            
            if ($type_info && stripos($type_info['type_name'], 'PAPER') !== false) {
                // สำหรับกระดาษ: ใช้ sheets_qty เป็น package_qty และไม่มี weight_per_package
                $package_qty = $sheets_qty;
                $weight_per_package = null;
                $quantity = $sheets_qty;  // เปลี่ยน quantity ให้เป็นจำนวนแผ่นเท่านั้น
                error_log("PAPER type detected: Using sheets_qty={$sheets_qty} as quantity");
            }
        }
        
        
$stmt = $conn->prepare("
    INSERT INTO PO_Items 
    (po_id, line_number, product_id, quantity, purchase_unit_id, stock_unit_id, 
     conversion_factor, stock_quantity, unit_price, total_price, 
     package_qty, weight_per_package, 
     item_type_id, status, notes) 
    VALUES (?, ?, ?, ?, ?, ?, 1.0, ?, ?, ?, ?, ?, ?, 'Open', ?)
");

$stmt->execute([
    $po_id, $line_number, $product_id, $quantity, $purchase_unit_id, $purchase_unit_id,
    $quantity, $unit_price, $total_price, 
    $package_qty, $weight_per_package,
    $item_type_id_material, $notes_item
]);
        
        error_log("Added material item {$line_number}: Product {$product_id}, Qty {$quantity}, Package Qty: {$package_qty}, Weight/Package: {$weight_per_package}");
        $line_number++;
    }
}
            }
        } else if ($po_type === 'freight') {
            // บันทึก Freight Items
            if (isset($_POST['freight_type']) && is_array($_POST['freight_type'])) {
                $line_number = 1;
                for ($i = 0; $i < count($_POST['freight_type']); $i++) {
                    if (!empty($_POST['freight_type'][$i]) && !empty($_POST['freight_amount'][$i])) {
                        $freight_type = sanitizeInput($_POST['freight_type'][$i]);
                        $freight_description = sanitizeInput($_POST['freight_description'][$i] ?? '');
                        $freight_amount = floatval($_POST['freight_amount'][$i]);
                        $freight_notes = sanitizeInput($_POST['freight_notes'][$i] ?? '');
                        
                        // ใช้ PO_Items table โดยใส่ข้อมูลใน item_description
                        $item_description = "{$freight_type}: {$freight_description}";
                        
                        // 🔥 เพิ่ม item_type_id ใน INSERT statement
                        $stmt = $conn->prepare("
                            INSERT INTO PO_Items 
                            (po_id, line_number, item_description, quantity, unit_price, total_price, item_type_id, status, notes) 
                            VALUES (?, ?, ?, 1.0, ?, ?, ?, 'Open', ?)
                        ");
                        
                        $stmt->execute([
                            $po_id, $line_number, $item_description, $freight_amount, $freight_amount, $item_type_id_freight, $freight_notes
                        ]);
                        
                        error_log("Added freight item {$line_number}: {$freight_type}, Amount {$freight_amount}, Type ID {$item_type_id_freight}");
                        $line_number++;
                    }
                }
            }
        }
        
        $conn->commit();
        error_log("PO created successfully: " . $po_number . " (ID: " . $po_id . ")");
        
        // เก็บ success message ใน session
        $_SESSION['success_message'] = "สร้าง PO เรียบร้อยแล้วและอนุมัติอัตโนมัติ! หมายเลข PO: " . $po_number . " (ID: " . $po_id . ")";
        
        // Redirect ไปหน้า list
        header("Location: list.php");
        exit();
        
} catch (PDOException $e) {
    $conn->rollback();
    error_log("Database error in create PO: " . $e->getMessage());
    
    // ⭐ สำหรับการพัฒนา แสดง error จริง
    if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
        $message = "Database Error: " . $e->getMessage();
    } else {
        $message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
    }
    $message_type = "danger";
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("General error in create PO: " . $e->getMessage());
    
    // ⭐ สำหรับการพัฒนา แสดง error จริง
    if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
        $message = "Error: " . $e->getMessage();
    } else {
        $message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
    }
    $message_type = "danger";
}
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้าง Purchase Order - <?= htmlspecialchars(APP_NAME ?? 'Material Management') ?></title>
    
    <!-- Security Headers -->
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <style>
        :root {
    --primary-color: #8B4513;
    --secondary-color: #FF8C00;
    --accent-color: #A0522D;
    --success-color: #059669;
    --warning-color: #d97706;
    --danger-color: #dc2626;
    --primary-gradient: linear-gradient(135deg, #8B4513, #A0522D);
    --primary-gradient-dark: linear-gradient(135deg, #A0522D, #8B4513);
}

body {
    background: linear-gradient(135deg, #F5DEB3 0%, #DEB887 50%, #D2B48C 100%);
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    color: var(--primary-color);
}

/* ลบหรือแทนที่ CSS เดิมของ .navbar */
.header-section {
    background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
    color: white;
    padding: 1.5rem 0;
    margin-bottom: 2rem;
    box-shadow: 0 4px 15px rgba(139, 69, 19, 0.4);
    border-bottom: 3px solid #FF8C00;
}

.header-section .container-fluid {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-section h5 {
    color: white;
    font-size: 1.3rem;
    font-weight: 600;
    margin: 0;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
}

.header-section small {
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.85rem;
}

.btn-back-arrow {
    color: white !important;
    text-decoration: none;
    font-size: 1.5rem;
    padding: 0.5rem;
    margin-right: 1rem;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    filter: drop-shadow(2px 2px 4px rgba(0,0,0,0.5));
}

.btn-back-arrow:hover {
    transform: translateX(-3px);
    color: #FFE5CC !important;
}

.btn-back-arrow i {
    color: white !important;
}

.btn-header {
    background: linear-gradient(135deg, #FF8C00, #FFA500);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s;
    box-shadow: 0 2px 8px rgba(255, 140, 0, 0.3);
    display: inline-block;
    white-space: nowrap;
}

.btn-header:hover {
    background: linear-gradient(135deg, #FFA500, #FF8C00);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255, 140, 0, 0.4);
}

.text-light {
    color: rgba(255, 255, 255, 0.9) !important;
}
        
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(255, 154, 86, 0.15);
            background: white;
            border: 2px solid #ffe4d1;
        }
        
        .card-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 18px 18px 0 0 !important;
            border-bottom: none;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #ffe4d1;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #ff9a56;
            box-shadow: 0 0 0 3px rgba(255, 154, 86, 0.25);
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: var(--primary-gradient-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 154, 86, 0.3);
        }
        
        .btn-secondary {
            border-radius: 10px;
            padding: 12px 30px;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: bold;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            border: none;
            border-radius: 10px;
            padding: 8px 15px;
        }
        
        .alert {
            border-radius: 15px;
            border: none;
        }
        
        .form-label {
            color: #ff7f50;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .required {
            color: #ff6b6b;
        }
        
        .section-title {
            color: #ff7f50;
            border-bottom: 3px solid #ff9a56;
            padding-bottom: 10px;
            margin-bottom: 25px;
        }
        
        .calculated-field {
            background-color: #f8f9fa;
            border-style: dashed !important;
        }
        
        .preview-box {
            background: linear-gradient(135deg, #fff3e0 0%, #ffe4d1 100%);
            border: 2px dashed #ff9a56;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .po-number-display {
            font-family: 'Courier New', monospace;
            font-size: 1.5rem;
            font-weight: bold;
            color: #ff7f50;
            letter-spacing: 2px;
        }
        
        .po-type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .po-type-card {
            padding: 25px;
            border: 3px solid #ffe4d1;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            background: white;
            user-select: none;
        }

        .po-type-card:hover {
            border-color: #ff9a56;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 154, 86, 0.15);
        }

        .po-type-card.selected {
            border-color: #2ecc71;
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            color: white;
        }

        .po-type-icon {
            font-size: 3em;
            margin-bottom: 15px;
        }

        .po-type-title {
            font-size: 1.3em;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .po-type-desc {
            font-size: 0.9em;
            opacity: 0.8;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .items-table th {
            background: linear-gradient(135deg, #34495e 0%, #2c3e50 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }

        .items-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
        }

        .items-table tr:hover {
            background: #f8f9fa;
        }

        .items-table input,
        .items-table select {
            width: 100%;
            border: 1px solid #ddd;
            padding: 8px;
            border-radius: 4px;
        }
        
        .details-section {
            display: none;
            animation: fadeInUp 0.5s ease;
        }

        .details-section.active {
            display: block;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(255, 154, 86, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(255, 154, 86, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 154, 86, 0); }
        }
        
        .btn-primary:focus {
            animation: pulse 2s infinite;
        }
        
        .freight-type-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .freight-type-option {
            padding: 15px;
            border: 2px solid #ffe4d1;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .freight-type-option:hover {
            border-color: #ff9a56;
        }

        .freight-type-option.selected {
            border-color: #2ecc71;
            background: #d5f4e6;
        }
        .breadcrumb {
            background: none;
            padding: 0;
        }
        
        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            color: var(--secondary-color);
        }
        
        .breadcrumb-item.active {
            color: var(--accent-color);
        }
        /* Select2 Custom Styles */
.select2-container--bootstrap-5 .select2-selection {
    border: 2px solid #ffe4d1 !important;
    border-radius: 10px !important;
    padding: 8px 12px !important;
    min-height: 48px !important;
}

.select2-container--bootstrap-5 .select2-selection:focus,
.select2-container--bootstrap-5.select2-container--focus .select2-selection {
    border-color: #ff9a56 !important;
    box-shadow: 0 0 0 3px rgba(255, 154, 86, 0.25) !important;
}

.select2-container--bootstrap-5 .select2-dropdown {
    border: 2px solid #ffe4d1 !important;
    border-radius: 10px !important;
}

.select2-container--bootstrap-5 .select2-results__option--highlighted {
    background-color: #ff9a56 !important;
}

.select2-container--bootstrap-5 .select2-search__field {
    border: 1px solid #ffe4d1 !important;
    border-radius: 8px !important;
}

.select2-container--bootstrap-5 .select2-search__field:focus {
    border-color: #ff9a56 !important;
    box-shadow: 0 0 0 2px rgba(255, 154, 86, 0.2) !important;
}
        /* Hide supplier field for Freight PO */
/* Supplier field styles */
        #supplier-field.freight-mode .form-select {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
        
        #supplier-field.freight-mode {
            opacity: 0.8;
        }
        
        #supplier-auto-note {
            color: #0d6efd;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
#supplier-field.freight-mode .form-select:disabled,
#supplier-field.freight-mode .select2-container--disabled {
    background-color: #f8f9fa;
    cursor: not-allowed;
    opacity: 0.8;
}

#supplier-field.freight-mode .select2-container--disabled .select2-selection {
    background-color: #f8f9fa !important;
    cursor: not-allowed !important;
}
#step2-basic-info.active #po-number-section {
    display: block !important;
}
/* ========================================
   Dynamic Product Form Styles
   ======================================== */

.product-type-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-left: 8px;
}

.badge-ink {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.badge-paper {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
}

.badge-glue {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    color: white;
}

.badge-coating {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
    color: white;
}

.badge-other {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
    color: white;
}

.form-type-ink { border-left: 4px solid #667eea; }
.form-type-paper { border-left: 4px solid #f5576c; }
.form-type-glue { border-left: 4px solid #00f2fe; }
.form-type-coating { border-left: 4px solid #38f9d7; }

.auto-calculated {
    background-color: #e7f5ff !important;
    font-weight: 600;
    color: #0d6efd;
    cursor: not-allowed;
}

.manual-input {
    background-color: #fff !important;
    border: 2px solid #198754 !important;
}

.field-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 8px;
}

.field-group label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #495057;
    margin-bottom: 4px;
}

.required-field::after {
    content: '*';
    color: #dc3545;
    font-weight: bold;
    margin-left: 4px;
}

#material-items-table .select2-container {
    width: 100% !important;
}

#material-items-table .select2-selection {
    height: auto !important;
    min-height: 31px !important;
    font-size: 0.875rem;
}

#material-items-table .select2-selection__rendered {
    line-height: 1.5;
    padding: 0.25rem 0.5rem !important;
}

#material-items-table .select2-selection__arrow {
    height: 29px !important;
}

/* 🔥 ขยายพื้นที่ของแถวตาราง */
#material-items-table tbody tr {
    vertical-align: top;
    height: auto;
}

#material-items-table textarea.form-control-sm {
    resize: vertical;
    min-height: 50px;
    max-height: 150px;
    padding: 0.375rem 0.75rem;
}

#material-items-table .form-control-sm {
    font-size: 0.875rem;
}
    </style>
</head>
<body>
    
<!-- Header -->
<div class="header-section">
    <div class="container-fluid" style="max-width: 98%;">
        <div class="d-flex justify-content-between align-items-center w-100">
            <div class="d-flex align-items-center">
                <a href="../dashboard.php" class="btn-back-arrow">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h5 class="mb-0">
                        <i class="fas fa-plus-circle me-2"></i>สร้าง Purchase Order
                    </h5>
                    <small class="text-light">สร้างใบสั่งซื้อใหม่ ระบบจะสร้างเลขที่ PO อัตโนมัติตามประเภทที่เลือก</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="list.php" class="btn-header">📋 รายการ PO</a>
                <span class="text-white">
                    <i class="fas fa-user-circle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'System Administrator'); ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-10">

            <!-- Alert Messages -->
            <?php if ($message): ?>
            <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Main Form -->
<form method="POST" id="poForm" novalidate>
    <!-- CSRF Token -->
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    
    <!-- ขั้นตอนที่ 1: เลือกประเภท PO ก่อน -->
    <div class="card" id="step1-po-type">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="fas fa-tags me-2"></i>ขั้นตอนที่ 1: เลือกประเภท PO
            </h5>
        </div>
        <div class="card-body">
            <div class="po-type-selector">
                <div class="po-type-card" data-type="material">
                    <div class="po-type-icon">📦</div>
                    <div class="po-type-title">PO วัตถุดิบ (Material)</div>
                    <div class="po-type-desc">สำหรับสั่งซื้อวัตถุดิบ สินค้า และอุปกรณ์ต่างๆ</div>
                </div>
                <div class="po-type-card" data-type="freight">
                    <div class="po-type-icon">🚚</div>
                    <div class="po-type-title">PO ค่าขนส่ง (Freight)</div>
                    <div class="po-type-desc">สำหรับค่าขนส่ง ค่าภาษีศุลกากร และค่าใช้จ่ายอื่นๆ</div>
                </div>
            </div>
            <input type="hidden" name="po_type" id="po_type" value="">
        </div>
    </div>
    
    <!-- ขั้นตอนที่ 2: ข้อมูลพื้นฐาน (แสดงหลังเลือกประเภท PO) -->
<div class="card mt-4 details-section" id="step2-basic-info">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-clipboard-list me-2"></i>ขั้นตอนที่ 2: ข้อมูลพื้นฐาน
        </h5>
    </div>
    <div class="card-body">
        <!-- ✅ เลขที่ PO - ย้ายขึ้นมาด้านบน -->
        <div class="row mb-4" id="po-number-section">
            <div class="col-md-12 mb-3">
                <div class="preview-box">
                    <h5><i class="fas fa-edit me-2"></i>เลขที่ PO <span class="required">*</span></h5>
                    <div class="row justify-content-center">
                        <div class="col-md-6">
                            <input type="text" class="form-control form-control-lg text-center" 
                                   id="po_number" name="po_number" 
                                   placeholder="กรอกเลขที่ PO เช่น PO-2024-001" 
                                   style="font-family: 'Courier New', monospace; font-weight: bold; font-size: 1.2rem; letter-spacing: 1px;"
                                   value="<?= htmlspecialchars($_POST['po_number'] ?? '') ?>">
                        </div>
                    </div>
                    <small class="text-muted">
                        ตัวอย่าง: PO-2024-001, MAT-202412-001
                    </small>
                </div>
            </div>
        </div>
        
        <!-- ✅ เลือก PO วัตถุดิบ - ย้ายลงมาด้านล่าง (แสดงเฉพาะ Freight PO) -->
        <div class="row mb-4 d-none" id="linked-po-section">
            <div class="col-md-12 mb-3">
                <label for="linked_po_id" class="form-label">
                    <i class="fas fa-link me-2"></i>เลือก PO วัตถุดิบที่ต้องการเพิ่มค่าขนส่ง <span class="required">*</span>
                </label>
                <select id="linked_po_id" name="linked_po_id" class="form-select">
                    <option value="">-- เลือก PO วัตถุดิบ --</option>
                    <?php foreach ($material_pos as $po): ?>
                    <option value="<?= htmlspecialchars($po['po_id']) ?>"
                            data-supplier-id="<?= htmlspecialchars($po['supplier_id']) ?>"
                            data-supplier-name="<?= htmlspecialchars($po['supplier_name']) ?>"
                            data-po-number="<?= htmlspecialchars($po['po_number']) ?>"
                            data-po-amount="<?= htmlspecialchars($po['total_amount']) ?>"
                            <?= (($_POST['linked_po_id'] ?? '') == $po['po_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($po['po_number']) ?> - <?= htmlspecialchars($po['supplier_name']) ?> 
                        (<?= number_format($po['total_amount'], 2) ?> บาท) - <?= date('d/m/Y', strtotime($po['po_date'])) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>Supplier จะถูกเลือกอัตโนมัติตาม PO วัตถุดิบที่เลือก
                </small>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="po_date" class="form-label">
                    วันที่ <span class="required">*</span>
                </label>
                <input type="date" class="form-control" id="po_date" name="po_date" 
                       value="<?= date('Y-m-d') ?>" required>
            </div>
            
            <!-- Supplier Field -->
            <div class="col-md-6 mb-3" id="supplier-field">
                <label for="supplier_id" class="form-label">
                    Supplier <span class="required" id="supplier-required">*</span>
                </label>
                <select class="form-select select2-supplier" id="supplier_id" name="supplier_id" required 
                        data-placeholder="พิมพ์ค้นหาหรือเลือก Supplier">
                    <option value="">เลือก Supplier</option>
                    <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?= htmlspecialchars($supplier['supplier_id']) ?>"
                            data-supplier-name="<?= htmlspecialchars($supplier['supplier_name']) ?>"
                            data-supplier-code="<?= htmlspecialchars($supplier['supplier_code']) ?>"
                            <?= (($_POST['supplier_id'] ?? '') == $supplier['supplier_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($supplier['supplier_name']) ?> (<?= htmlspecialchars($supplier['supplier_code']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted d-none" id="supplier-auto-note">
                    <i class="fas fa-info-circle me-1"></i>ดึงข้อมูลจาก PO วัตถุดิบที่เลือกโดยอัตโนมัติ
                </small>
            </div>
        </div>
                        
        <div class="row">
            <div class="col-md-12 mb-3">
                <label for="notes" class="form-label">หมายเหตุ</label>
                <textarea class="form-control" id="notes" name="notes" rows="2" 
                          placeholder="หมายเหตุเพิ่มเติม"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
</div>

                    <!-- รายละเอียด PO วัตถุดิบ -->
                    <div id="material-details" class="details-section">
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-box me-2"></i>รายละเอียดสินค้า
                                </h5>
                            </div>
                            <div class="card-body">
                         <!-- Alert แจ้งเตือน -->
                                <div class="alert alert-info mb-3">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>คำแนะนำ:</strong> ระบบจะแสดงฟิลด์ที่เหมาะสมตามประเภทสินค้าที่เลือก
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered" id="material-items-table">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="3%" class="text-center">#</th>
                                                <th width="18%">รหัสสินค้า</th>
                                                <th width="28%">รายละเอียดสินค้า</th>
                                                <th width="8%">หน่วย</th>
                                                <th width="8%">ราคา/หน่วย</th>
                                                <th width="8%">รวม</th>
                                                <th width="20%">หมายเหตุ</th>
                                                <th width="5%" class="text-center">ลบ</th>
                                            </tr>
                                        </thead>
                                        <tbody id="items-tbody">
                                            <!-- แถวจะถูก generate ด้วย JavaScript -->
                                        </tbody>
                                    </table>
                                </div>
                                        
                                <div class="mt-3">
                                    <button type="button" class="btn btn-primary" onclick="addMaterialRow()">
                                        <i class="fas fa-plus me-2"></i>เพิ่มรายการ
                                    </button>
                                </div>
                                
                                <!-- สรุปยอดรวม -->
                                <div class="row mt-4">
                                    <div class="col-md-6 ms-auto">
                                        <div class="card">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0">สรุปยอดรวม</h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>ยอดรวมสินค้า:</span>
                                                    <span id="material-total" class="fw-bold">0.00 บาท</span>
                                                </div>
                                                <hr>
                                                <div class="d-flex justify-content-between">
                                                    <span class="fw-bold">รวมทั้งหมด:</span>
                                                    <span id="material-grand-total" class="fw-bold text-primary">0.00 บาท</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- รายละเอียด PO ค่าขนส่ง -->
                    <div id="freight-details" class="details-section">
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-truck me-2"></i>รายละเอียดค่าขนส่ง
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info">
                                    <strong>คำแนะนำ:</strong> กรุณาเลือก PO วัตถุดิบจากด้านบนก่อน แล้วระบุประเภทค่าใช้จ่าย
                                </div>

                                <h6 class="section-title">ประเภทค่าใช้จ่าย</h6>
                                <div class="freight-type-selector">
                                    <div class="freight-type-option" data-freight-type="shipping">
                                        <h6>🚢 ค่าขนส่ง</h6>
                                        <small>ค่าขนส่งทางเรือ บก อากาศ</small>
                                    </div>
                                    <div class="freight-type-option" data-freight-type="customs">
                                        <h6>🏛️ ค่าภาษีศุลกากร</h6>
                                        <small>ภาษีนำเข้า ค่าธรรมเนียม</small>
                                    </div>
                                    <div class="freight-type-option" data-freight-type="insurance">
                                        <h6>🛡️ ค่าประกันภัย</h6>
                                        <small>ประกันสินค้า ประกันขนส่ง</small>
                                    </div>
                                    <div class="freight-type-option" data-freight-type="handling">
                                        <h6>📦 ค่าดำเนินการ</h6>
                                        <small>ค่าจัดการ ค่าบริการ</small>
                                    </div>
                                    <div class="freight-type-option" data-freight-type="other">
                                        <h6>📋 อื่นๆ</h6>
                                        <small>ค่าใช้จ่ายอื่นที่เกี่ยวข้อง</small>
                                    </div>
                                </div>

                                <table class="items-table" id="freight-items-table">
                                    <thead>
                                        <tr>
                                            <th width="25%">ประเภทค่าใช้จ่าย</th>
                                            <th width="30%">รายละเอียด</th>
                                            <th width="15%">จำนวนเงิน</th>
                                            <th width="25%">หมายเหตุ</th>
                                            <th width="5%">ลบ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>
                                                <select name="freight_type[]" class="form-select" required>
                                                    <option value="">-- เลือกประเภท --</option>
                                                    <option value="shipping">ค่าขนส่ง</option>
                                                    <option value="customs">ค่าภาษีศุลกากร</option>
                                                    <option value="insurance">ค่าประกันภัย</option>
                                                    <option value="handling">ค่าดำเนินการ</option>
                                                    <option value="other">อื่นๆ</option>
                                                </select>
                                            </td>
                                            <td><input type="text" name="freight_description[]" required placeholder="รายละเอียด" class="form-control"></td>
                                            <td><input type="number" name="freight_amount[]" step="0.01" required placeholder="0.00" class="form-control"></td>
                                            <td><input type="text" name="freight_notes[]" placeholder="หมายเหตุ" class="form-control"></td>
                                            <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">❌</button></td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-primary" onclick="addFreightRow()">
                                        <i class="fas fa-plus me-2"></i>เพิ่มรายการ
                                    </button>
                                </div>
                                
                                <!-- สรุปยอดรวม -->
                                <div class="row mt-4">
                                    <div class="col-md-6 ms-auto">
                                        <div class="card">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0">สรุปยอดรวม</h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>ยอดรวมค่าขนส่ง:</span>
                                                    <span id="freight-total" class="fw-bold">0.00 บาท</span>
                                                </div>
                                                <hr>
                                                <div class="d-flex justify-content-between">
                                                    <span class="fw-bold">รวมทั้งหมด:</span>
                                                    <span id="freight-grand-total" class="fw-bold text-primary">0.00 บาท</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="card mt-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <a href="../dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i>ยกเลิก
                                </a>
                                
                                <div>
                                    <button type="button" class="btn btn-outline-primary me-2" onclick="resetForm()">
                                        <i class="fas fa-redo me-2"></i>รีเซ็ต
                                    </button>
                                    <button type="button" class="btn btn-success" id="submitPOBtn" onclick="window.handlePOSubmit()">
                                        <i class="fas fa-save me-2"></i>สร้าง PO
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

<!-- ========================================
     Confirmation Modal - สรุปรายละเอียด PO ก่อนยืนยัน
     ======================================== -->
<div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="confirmationModalLabel">
                    <i class="fas fa-check-circle me-2"></i>ยืนยันการสร้าง PO
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-4">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>กรุณาตรวจสอบรายละเอียดก่อนยืนยัน</strong> - หลังจากยืนยันแล้ว PO จะถูกบันทึกลงระบบ
                </div>
                
                <!-- สรุปข้อมูลพื้นฐาน -->
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>ข้อมูลพื้นฐาน</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="text-muted">ประเภท PO:</td>
                                        <td class="fw-bold" id="summary-po-type"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">เลขที่ PO:</td>
                                        <td class="fw-bold text-primary" id="summary-po-number"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Supplier:</td>
                                        <td class="fw-bold" id="summary-supplier"></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td width="40%" class="text-muted">วันที่:</td>
                                        <td class="fw-bold" id="summary-date"></td>
                                    </tr>
                                    <tr id="summary-linked-po-row" style="display: none;">
                                        <td class="text-muted">PO วัตถุดิบที่เชื่อมโยง:</td>
                                        <td class="fw-bold text-success" id="summary-linked-po"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">หมายเหตุ:</td>
                                        <td id="summary-notes" class="text-muted fst-italic"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- รายการสินค้า/ค่าใช้จ่าย -->
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-list me-2"></i>รายการ</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm" id="summary-items-table">
                                <thead class="table-light">
                                    <!-- Headers will be dynamically generated -->
                                </thead>
                                <tbody>
                                    <!-- Rows will be dynamically generated -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- สรุปยอดรวม -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="fas fa-calculator me-2"></i>สรุปยอดรวม</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 ms-auto">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td class="text-end"><span id="summary-total-label">ยอดรวม:</span></td>
                                        <td class="text-end fw-bold" id="summary-total"></td>
                                    </tr>
                                    <tr class="border-top">
                                        <td class="text-end"><strong>รวมทั้งหมด:</strong></td>
                                        <td class="text-end fw-bold text-success fs-5" id="summary-grand-total"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>แก้ไข
                </button>
                <button type="button" class="btn btn-success" id="confirmSubmitBtn" onclick="window.handleConfirmSubmit()">
                    <i class="fas fa-check me-2"></i>ยืนยันสร้าง PO
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery (จำเป็นสำหรับ Select2) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        // ========================================
        // DYNAMIC PRODUCT FORM - CONFIGURATION
        // ========================================
        const PRODUCT_TYPES = {
            'INK': {
                name: 'หมึก',
                badgeClass: 'badge-ink',
                formClass: 'form-type-ink',
                icon: '🖨️',
                fields: ['package_qty', 'weight_per_package', 'total_weight'],
                required: ['package_qty', 'weight_per_package'],
                autoCalculate: true
            },
            'GLUE': {
                name: 'กาว',
                badgeClass: 'badge-glue',
                formClass: 'form-type-glue',
                icon: '🧴',
                fields: ['package_qty', 'weight_per_package', 'total_weight'],
                required: ['package_qty', 'weight_per_package'],
                autoCalculate: true
            },
            'COATING': {
                name: 'สารเคลือบ',
                badgeClass: 'badge-coating',
                formClass: 'form-type-coating',
                icon: '✨',
                fields: ['package_qty', 'weight_per_package', 'total_weight'],
                required: ['package_qty', 'weight_per_package'],
                autoCalculate: true
            },
            'PAPER': {
                name: 'กระดาษ/แผ่น/กิโล',
                badgeClass: 'badge-paper',
                formClass: 'form-type-paper',
                icon: '📄',
                fields: ['paper_quantity'],
                required: ['paper_quantity'],
                autoCalculate: false,
                units: ['kg', 'sheet']
            },
            'OTHER': {
                name: 'อื่นๆ',
                badgeClass: 'badge-other',
                formClass: 'form-type-other',
                icon: '📦',
                fields: ['total_weight'],
                required: ['total_weight'],
                autoCalculate: false
            }
        };

        // Helper Functions
        function sanitizeInput(input) {
            return input.toString().trim();
        }

        function formatCurrency(amount) {
            return new Intl.NumberFormat('th-TH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(amount);
        }

        // ========================================
        // HELPER FUNCTIONS FOR PRODUCT TYPES
        // ========================================

        function getProductType(productData) {
            if (!productData) return 'OTHER';
            
            const materialType = (productData.materialType || '').toLowerCase();
            const groupName = (productData.groupName || '').toLowerCase();
            const productName = (productData.productName || '').toLowerCase();
            
            // ตรวจจากชื่อประเภทวัตถุดิบ
            if (materialType.includes('ink') || materialType.includes('หมึก')) return 'INK';
            if (materialType.includes('glue') || materialType.includes('กาว')) return 'GLUE';
            if (materialType.includes('coating') || materialType.includes('เคลือบ')) return 'COATING';
            if (materialType.includes('paper') || materialType.includes('กระดาษ')) return 'PAPER';
            
            // ตรวจจากชื่อกลุ่ม
            if (groupName.includes('ink') || groupName.includes('หมึก')) return 'INK';
            if (groupName.includes('paper') || groupName.includes('กระดาษ')) return 'PAPER';
            if (groupName.includes('glue') || groupName.includes('กาว')) return 'GLUE';
            if (groupName.includes('coating') || groupName.includes('เคลือบ')) return 'COATING';
            
            // ตรวจจากชื่อสินค้า
            if (productName.includes('ink') || productName.includes('หมึก')) return 'INK';
            if (productName.includes('paper') || productName.includes('กระดาษ')) return 'PAPER';
            if (productName.includes('glue') || productName.includes('กาว')) return 'GLUE';
            
            return 'OTHER';
        }

        function generateDynamicFields(productType) {
            const config = PRODUCT_TYPES[productType];
            if (!config) return '';
            
            let html = '<div class="field-group ' + config.formClass + '">';
            
            html += '<div class="mb-2">';
            html += '<span class="product-type-badge ' + config.badgeClass + '">';
            html += config.icon + ' ' + config.name;
            html += '</span>';
            html += '</div>';
            
            if (config.fields.includes('package_qty')) {
                const isRequired = config.required.includes('package_qty');
                html += '<div class="mb-2">';
                html += '<label class="' + (isRequired ? 'required-field' : '') + '">จำนวนกระป๋อง/ถัง</label>';
                html += '<input type="number" name="package_qty[]" step="1" min="0" ';
                html += 'placeholder="เช่น 10" class="form-control form-control-sm package-qty" ';
                html += (isRequired ? 'required ' : '');
                html += 'title="จำนวนกระป๋อง/ถัง">';
                html += '<small class="text-muted"><i class="fas fa-box"></i> หน่วยบรรจุ</small>';
                html += '</div>';
            }
            
            if (config.fields.includes('weight_per_package')) {
                const isRequired = config.required.includes('weight_per_package');
                html += '<div class="mb-2">';
                html += '<label class="' + (isRequired ? 'required-field' : '') + '">น้ำหนักต่อกระป๋อง</label>';
                html += '<input type="number" name="weight_per_package[]" step="0.01" min="0" ';
                html += 'placeholder="เช่น 20.00" class="form-control form-control-sm weight-per-package" ';
                html += (isRequired ? 'required ' : '');
                html += 'title="น้ำหนักต่อหน่วยบรรจุ">';
                html += '<small class="text-muted"><i class="fas fa-weight"></i> KG/Liter ต่อหน่วย</small>';
                html += '</div>';
            }
            
            if (config.fields.includes('total_weight')) {
                const isReadonly = config.autoCalculate;
                html += '<div class="mb-2">';
                html += '<label class="required-field">น้ำหนักรวม</label>';
                html += '<input type="number" name="quantity[]" step="0.01" min="0" required ';
                html += 'placeholder="0.00" ';
                html += 'class="form-control form-control-sm total-weight ' + (isReadonly ? 'auto-calculated' : 'manual-input') + '" ';
                html += (isReadonly ? 'readonly ' : '');
                html += 'title="น้ำหนักรวมทั้งหมด">';
                html += '<small class="text-muted"><i class="fas fa-' + (isReadonly ? 'calculator' : 'edit') + '"></i> ';
                html += (isReadonly ? 'คำนวณอัตโนมัติ' : 'กรอกเอง');
                html += '</small>';
                html += '</div>';
            }
            
            if (config.fields.includes('paper_quantity')) {
                const isRequired = config.required.includes('paper_quantity');
                html += '<div class="mb-2">';
                html += '<label class="' + (isRequired ? 'required-field' : '') + '">จำนวน</label>';
                html += '<input type="number" name="quantity[]" step="0.01" min="0" ';
                html += 'placeholder="ใส่จำนวน" class="form-control form-control-sm total-weight" ';
                html += (isRequired ? 'required ' : '');
                html += 'title="จำนวน">';
                html += '<small class="text-muted"><i class="fas fa-boxes"></i> เลือกหน่วยจากตาราง</small>';
                html += '</div>';
            }
            
            if (config.fields.includes('sheet_count')) {
                html += '<div class="mb-2">';
                html += '<label>จำนวนแผ่น (ถ้ามี)</label>';
                html += '<input type="number" name="sheet_count[]" step="1" min="0" ';
                html += 'placeholder="เช่น 500" class="form-control form-control-sm sheet-count" ';
                html += 'title="จำนวนแผ่นกระดาษ">';
                html += '<small class="text-muted"><i class="fas fa-file"></i> Sheets</small>';
                html += '</div>';
            }
            
            html += '</div>';
            return html;
        }

        function showAlert(message, type = 'info') {
    // ✅ ลบ alert เก่าออกก่อน (ถ้ามี)
    const existingAlerts = document.querySelectorAll('.alert:not(.alert-info):not(.form-alert)');
    existingAlerts.forEach(alert => {
        if (alert.parentNode) {
            alert.remove();
        }
    });
    
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.container-fluid .row .col-lg-10');
    if (container && container.children.length > 2) {
        container.insertBefore(alertDiv, container.children[2]);
    } else if (container) {
        container.insertBefore(alertDiv, container.firstChild);
    } else {
        console.warn('Alert container not found');
        return;
    }
    
    // ✅ Scroll to top เพื่อให้เห็น alert
    window.scrollTo({ top: 0, behavior: 'smooth' });
    
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 5000);
}


    function updatePOTypeDisplay() {
    const poTypeInput = document.getElementById('po_type');
    const poType = poTypeInput.value;
    
    console.log('=== PO Type Changed ===');
    console.log('Selected PO Type:', poType);
    
    hideAllSections();
    
    const step2BasicInfo = document.getElementById('step2-basic-info');
    const poNumberSection = document.getElementById('po-number-section');
    const poNumberInput = document.getElementById('po_number');
    const supplierField = document.getElementById('supplier-field');
    const supplierInput = document.getElementById('supplier_id');
    const supplierRequired = document.getElementById('supplier-required');
    const supplierAutoNote = document.getElementById('supplier-auto-note');
    
    if (poType === 'material') {
        // Material PO - ให้เลือก Supplier ได้
        step2BasicInfo.classList.add('active');
        poNumberSection.style.display = 'block';
        poNumberInput.setAttribute('required', 'required');
        document.getElementById('material-details').classList.add('active');
        
        // ซ่อน Linked PO section (แสดงเฉพาะ Freight)
        const linkedPOSection = document.getElementById('linked-po-section');
        const linkedPOInput = document.getElementById('linked_po_id');
        if (linkedPOSection) {
            linkedPOSection.classList.add('d-none');
        }
        if (linkedPOInput) {
            linkedPOInput.removeAttribute('required');
            linkedPOInput.value = '';
        }
        
        // แสดง Supplier field แบบเลือกได้
        if (supplierField) {
            supplierField.classList.remove('freight-mode');
            supplierField.style.display = 'block';
        }
        if (supplierInput) {
            supplierInput.setAttribute('required', 'required');
            supplierInput.disabled = false;
            supplierInput.value = '';
        }
        if (supplierRequired) {
            supplierRequired.style.display = 'inline';
        }
        if (supplierAutoNote) {
            supplierAutoNote.classList.add('d-none');
        }
        
        // Initialize Select2 สำหรับ Supplier
        setTimeout(() => {
            initSupplierSelect2();
        }, 100);
        
        // Destroy Linked PO Select2 ถ้ามี
        if (typeof jQuery !== 'undefined' && $('#linked_po_id').hasClass('select2-hidden-accessible')) {
            $('#linked_po_id').select2('destroy');
        }
        
        console.log('✓ Material mode: Supplier enabled');
        // ❌ ลบบรรทัดนี้
        // showAlert('เลือก PO วัตถุดิบ - กรุณากรอกเลขที่ PO และรายละเอียดสินค้า', 'success');
        
    } else if (poType === 'freight') {
        // Freight PO - แสดง Supplier แต่ disable
        step2BasicInfo.classList.add('active');
        
        // ✅ แสดงช่อง PO Number สำหรับ Freight
        if (poNumberSection) {
            poNumberSection.style.display = 'block';
        }
        if (poNumberInput) {
            poNumberInput.setAttribute('required', 'required');
        }
        
        // ✅ ตั้งค่า label
        const poNumberLabel = poNumberSection ? poNumberSection.querySelector('h5') : null;
        if (poNumberLabel) {
            poNumberLabel.innerHTML = '<i class="fas fa-edit me-2"></i>เลขที่ PO <span class="required">*</span>';
        }
        
        document.getElementById('freight-details').classList.add('active');
        
        // แสดง Linked PO section (อันดับแรก)
        const linkedPOSection = document.getElementById('linked-po-section');
        const linkedPOInput = document.getElementById('linked_po_id');
        if (linkedPOSection) {
            linkedPOSection.classList.remove('d-none');
        }
        if (linkedPOInput) {
            linkedPOInput.setAttribute('required', 'required');
        }
        
        // แสดง Supplier field แบบ disabled
        if (supplierField) {
            supplierField.classList.add('freight-mode');
            supplierField.style.display = 'block';
        }
        if (supplierInput) {
            supplierInput.setAttribute('required', 'required');
            supplierInput.disabled = true;
            supplierInput.value = '';
        }
        if (supplierRequired) {
            supplierRequired.style.display = 'none';
        }
        if (supplierAutoNote) {
            supplierAutoNote.classList.remove('d-none');
        }
        
        // Destroy Supplier Select2 ถ้ามี (เพราะจะ disable)
        if (typeof jQuery !== 'undefined' && $('#supplier_id').hasClass('select2-hidden-accessible')) {
            $('#supplier_id').select2('destroy');
        }
        
        // Initialize Select2 สำหรับ Linked PO
        setTimeout(() => {
            initLinkedPOSelect2();
            setupLinkedPOChangeHandler();
        }, 100);
        
        console.log('✓ Freight mode: PO Number required, Supplier disabled (auto-fill from Linked PO)');
        // ❌ ลบบรรทัดนี้
        // showAlert('เลือก PO ค่าขนส่ง - กรุณากรอกเลขที่ PO และเลือก PO วัตถุดิบที่ต้องการเพิ่มค่าขนส่ง', 'info');
    }
    
    console.log('=== Display Update Complete ===');
}


        function hideAllSections() {
    document.querySelectorAll('.details-section').forEach(section => {
        section.classList.remove('active');
    });
    // ซ่อน step2 ด้วย
    const step2 = document.getElementById('step2-basic-info');
    if (step2) step2.classList.remove('active');
}



        // Calculation Functions
        function calculateMaterialTotal() {
            let total = 0;
            document.querySelectorAll('#material-items-table tbody tr').forEach(row => {
                const totalPriceField = row.querySelector('.total-price');
                if (totalPriceField && totalPriceField.value) {
                    total += parseFloat(totalPriceField.value) || 0;
                }
            });
            
            document.getElementById('material-total').textContent = formatCurrency(total) + ' บาท';
            document.getElementById('material-grand-total').textContent = formatCurrency(total) + ' บาท';
            
            return total;
        }

        function calculateFreightTotal() {
            let total = 0;
            document.querySelectorAll('#freight-items-table tbody tr').forEach(row => {
                const amountField = row.querySelector('input[name="freight_amount[]"]');
                if (amountField && amountField.value) {
                    total += parseFloat(amountField.value) || 0;
                }
            });
            
            document.getElementById('freight-total').textContent = formatCurrency(total) + ' บาท';
            document.getElementById('freight-grand-total').textContent = formatCurrency(total) + ' บาท';
            
            return total;
        }

        function updateRowTotal(row) {
            const quantityField = row.querySelector('input[name="quantity[]"]');
            const priceField = row.querySelector('input[name="unit_price[]"]');
            const totalField = row.querySelector('.total-price');
            
            if (quantityField && priceField && totalField) {
                const quantity = parseFloat(quantityField.value) || 0;
                const price = parseFloat(priceField.value) || 0;
                const total = quantity * price;
                
                totalField.value = total.toFixed(2);
                calculateMaterialTotal();
            }
        }

        function addMaterialRow() {
    // ดึง tbody และสร้างแถวใหม่
    const tbody = document.getElementById('items-tbody');
    const rowCount = tbody.querySelectorAll('tr').length;
    const rowIndex = rowCount + 1;
    
    const newRow = document.createElement('tr');
    newRow.innerHTML = `
        <td class="text-center align-middle">${rowIndex}</td>
        <td style="word-wrap: break-word; word-break: break-word; white-space: normal;">
            <select name="product_id[]" class="form-select form-select-sm product-select" required>
                <option value="">-- เลือกสินค้า --</option>
                <?php foreach ($products as $product): ?>
                <option value="<?= htmlspecialchars($product['id']) ?>" 
                        data-ssp="<?= htmlspecialchars($product['SSP_Code']) ?>"
                        data-name="<?= htmlspecialchars($product['Name']) ?>"
                        data-name2="<?= htmlspecialchars($product['Name2'] ?? '') ?>"
                        data-group="<?= htmlspecialchars($product['group_name'] ?? '') ?>"
                        data-material-type="<?= htmlspecialchars($product['material_type_name'] ?? '') ?>"
                        data-supplier-id="<?= htmlspecialchars($product['supplier_id'] ?? '') ?>">
                    <?= htmlspecialchars($product['SSP_Code']) ?> - <?= htmlspecialchars($product['Name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="dynamic-fields-container" style="word-wrap: break-word; word-break: break-word; white-space: normal;">
            <div class="text-muted text-center py-3">
                <i class="fas fa-arrow-left me-2"></i>กรุณาเลือกสินค้าก่อน
            </div>
        </td>
        <td>
            <select name="purchase_unit_id[]" class="form-select form-select-sm" required>
                <option value="">-- หน่วย --</option>
                <?php foreach ($units as $unit): ?>
                <option value="<?= htmlspecialchars($unit['unit_id']) ?>">
                    <?= htmlspecialchars($unit['unit_name']) ?>
                    <?php if (!empty($unit['unit_symbol'])): ?>
                    (<?= htmlspecialchars($unit['unit_symbol']) ?>)
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" name="unit_price[]" step="0.01" min="0" required 
                   placeholder="0.00" class="form-control form-control-sm unit-price">
        </td>
        <td>
            <input type="text" name="total_price[]" readonly 
                   class="form-control form-control-sm calculated-field total-price" 
                   value="0.00">
        </td>
        <td style="padding: 8px;">
            <textarea name="notes_item[]" placeholder="หมายเหตุ" 
                   class="form-control form-control-sm" rows="2" style="resize: vertical; min-height: 50px;"></textarea>
        </td>
        <td class="text-center align-middle">
            <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(newRow);
    // 🔥 Initialize Select2 สำหรับ Product select ที่เพิ่มใหม่
const newProductSelect = newRow.querySelector('select[name="product_id[]"]');
if (newProductSelect && typeof initProductSelect2 === 'function') {
    initProductSelect2(newProductSelect);
    console.log('✅ Product Select2 initialized for new row');
}
    attachRowEventListeners(newRow);
    
    // 🔥 เรียก updateProductsBySupplier ทันทีเพื่อให้ filter ทำงาน
    const currentSupplier = document.getElementById('supplier_id');
    if (currentSupplier && currentSupplier.value) {
        updateProductsBySupplier(currentSupplier.value);
        console.log('✅ Applied supplier filter to new row');
    }
    
    console.log('✅ Added new row:', rowIndex);
}

function removeRow(button) {
    const row = button.closest('tr');
    row.remove();
    updateRowNumbers();
    calculateTotals();
}

function updateRowNumbers() {
    const rows = document.querySelectorAll('#items-tbody tr');
    rows.forEach((row, index) => {
        row.querySelector('td:first-child').textContent = index + 1;
    });
}

// ========================================
// EVENT LISTENERS
// ========================================

function attachRowEventListeners(row) {
    const productSelect = row.querySelector('.product-select');
    productSelect.addEventListener('change', function() {
        handleProductChange(this);
    });
    
    row.addEventListener('input', function(e) {
        if (e.target.classList.contains('package-qty') || 
            e.target.classList.contains('weight-per-package')) {
            calculateTotalWeight(row);
        }
        
        if (e.target.classList.contains('total-weight') || 
            e.target.classList.contains('unit-price')) {
            calculateRowTotal(row);
        }
    });
}

function handleProductChange(selectElement) {
    const row = selectElement.closest('tr');
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    
    if (!selectedOption.value) {
        row.querySelector('.dynamic-fields-container').innerHTML = `
            <div class="text-muted text-center py-3">
                <i class="fas fa-arrow-left me-2"></i>กรุณาเลือกสินค้าก่อน
            </div>
        `;
        return;
    }
    
    const productData = {
        materialType: selectedOption.dataset.materialType,
        groupName: selectedOption.dataset.group,
        productName: selectedOption.dataset.name
    };
    
    const productType = getProductType(productData);
    const fieldsHTML = generateDynamicFields(productType);
    row.querySelector('.dynamic-fields-container').innerHTML = fieldsHTML;
    
    attachFieldEventListeners(row, productType);
    
    console.log('✅ Product type:', productType);
}

function attachFieldEventListeners(row, productType) {
    const config = PRODUCT_TYPES[productType];
    
    // Attach listeners ให้กับ dynamic fields
    const totalWeightInput = row.querySelector('input[name="quantity[]"]');
    const unitPriceInput = row.querySelector('.unit-price');
    
    console.log('🔗 Attaching listeners - Qty:', totalWeightInput, 'Price:', unitPriceInput);
    
    if (totalWeightInput) {
        totalWeightInput.addEventListener('input', function() {
            console.log('📝 Quantity changed:', this.value);
            calculateRowTotal(row);
        });
    }
    
    if (unitPriceInput) {
        unitPriceInput.addEventListener('input', function() {
            console.log('📝 Unit price changed:', this.value);
            calculateRowTotal(row);
        });
    }
    
    if (config && config.autoCalculate) {
        const packageQtyInput = row.querySelector('.package-qty');
        const weightPerPackageInput = row.querySelector('.weight-per-package');
        
        if (packageQtyInput && weightPerPackageInput) {
            packageQtyInput.addEventListener('input', () => calculateTotalWeight(row));
            weightPerPackageInput.addEventListener('input', () => calculateTotalWeight(row));
        }
    }
}

// ========================================
// CALCULATIONS
// ========================================

function calculateTotalWeight(row) {
    const packageQty = parseFloat(row.querySelector('.package-qty')?.value || 0);
    const weightPerPackage = parseFloat(row.querySelector('.weight-per-package')?.value || 0);
    const totalWeightInput = row.querySelector('.total-weight');
    
    if (totalWeightInput && packageQty > 0 && weightPerPackage > 0) {
        const totalWeight = packageQty * weightPerPackage;
        totalWeightInput.value = totalWeight.toFixed(2);
        totalWeightInput.title = `คำนวณจาก: ${packageQty} × ${weightPerPackage}`;
        calculateRowTotal(row);
    }
}

function calculateRowTotal(row) {
    const quantityInput = row.querySelector('input[name="quantity[]"]');
    const unitPriceInput = row.querySelector('.unit-price');
    const totalPriceInput = row.querySelector('.total-price');
    
    if (quantityInput && unitPriceInput && totalPriceInput) {
        const quantity = parseFloat(quantityInput.value) || 0;
        const unitPrice = parseFloat(unitPriceInput.value) || 0;
        const total = quantity * unitPrice;
        
        totalPriceInput.value = total.toFixed(2);
        console.log('✅ Calculate: ', quantity, ' × ', unitPrice, ' = ', total.toFixed(2));
        calculateTotals();
    }
}

function calculateTotals() {
    let grandTotal = 0;
    
    const totalPriceInputs = document.querySelectorAll('#items-tbody .total-price');
    if (totalPriceInputs.length === 0) {
        // If no material items, check freight
        const freightAmountInputs = document.querySelectorAll('#freight-items-table input[name="freight_amount[]"]');
        freightAmountInputs.forEach(input => {
            grandTotal += parseFloat(input.value || 0);
        });
        
        const formatted = grandTotal.toLocaleString('th-TH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' บาท';
        
        document.getElementById('freight-total').textContent = formatted;
        document.getElementById('freight-grand-total').textContent = formatted;
    } else {
        totalPriceInputs.forEach(input => {
            grandTotal += parseFloat(input.value || 0);
        });
        
        const formatted = grandTotal.toLocaleString('th-TH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' บาท';
        
        document.getElementById('material-total').textContent = formatted;
        document.getElementById('material-grand-total').textContent = formatted;
    }
}

// ========================================
// INITIALIZATION
// ========================================

document.addEventListener('DOMContentLoaded', function() {
    addMaterialRow();
    console.log('✅ Dynamic Product Form initialized');
});

        function removeRow(button) {
            const tbody = button.closest('tbody');
            const row = button.closest('tr');
            
            if (tbody.rows.length > 1) {
                row.remove();
                
                // อัพเดทยอดรวม
                const tableId = tbody.closest('table').id;
                if (tableId === 'material-items-table') {
                    calculateMaterialTotal();
                } else if (tableId === 'freight-items-table') {
                    calculateFreightTotal();
                }
            } else {
                showAlert('ต้องมีรายการอย่างน้อย 1 รายการ', 'warning');
            }
        }

        function addFreightRow() {
            const tbody = document.querySelector('#freight-items-table tbody');
            const firstRow = tbody.querySelector('tr');
            const newRow = firstRow.cloneNode(true);
            
            // ล้างค่าในแถวใหม่
            newRow.querySelectorAll('input, select').forEach(input => {
                if (input.type !== 'button') {
                    input.value = '';
                    input.selectedIndex = 0;
                }
            });
            
            tbody.appendChild(newRow);
            
            // เพิ่ม event listeners สำหรับแถวใหม่
            addFreightRowListeners(newRow);
        }

        // Event Listeners for Freight Rows
        function addFreightRowListeners(row) {
            const amountField = row.querySelector('input[name="freight_amount[]"]');
            
            if (amountField) {
                amountField.addEventListener('input', calculateFreightTotal);
                amountField.addEventListener('blur', calculateFreightTotal);
            }
        }

        // Form Validation
function validateForm() {
    const poType = document.getElementById('po_type').value;
    let isValid = true;
    let firstErrorField = null;
    let errorMessages = []; // ✅ เก็บ error messages
    
    // ตรวจสอบว่าเลือกประเภท PO หรือยัง
    if (!poType) {
        showAlert('กรุณาเลือกประเภท PO', 'danger');
        return false;
    }
    
    // ตรวจสอบฟิลด์พื้นฐานตามประเภท PO
    const poDateField = document.getElementById('po_date');
    if (!poDateField || !poDateField.value.trim()) {
        poDateField.classList.add('is-invalid');
        if (!firstErrorField) firstErrorField = poDateField;
        errorMessages.push('กรุณาเลือกวันที่');
        isValid = false;
    } else {
        poDateField.classList.remove('is-invalid');
    }
    
    if (poType === 'material') {
        // Material PO - ต้องมีเลขที่ PO และ Supplier
        const poNumberField = document.getElementById('po_number');
        const supplierField = document.getElementById('supplier_id');
        
        if (!poNumberField || !poNumberField.value.trim()) {
            poNumberField.classList.add('is-invalid');
            if (!firstErrorField) firstErrorField = poNumberField;
            errorMessages.push('กรุณากรอกเลขที่ PO');
            isValid = false;
        } else {
            poNumberField.classList.remove('is-invalid');
            
            // ตรวจสอบรูปแบบเลข PO
            if (!/^[A-Za-z0-9\-]+$/.test(poNumberField.value.trim())) {
                poNumberField.classList.add('is-invalid');
                errorMessages.push('รูปแบบเลขที่ PO ไม่ถูกต้อง ใช้ตัวอักษรภาษาอังกฤษ ตัวเลข และเครื่องหมาย - เท่านั้น');
                isValid = false;
            }
        }
        
        if (!supplierField || !supplierField.value.trim()) {
            supplierField.classList.add('is-invalid');
            if (!firstErrorField) firstErrorField = supplierField;
            errorMessages.push('กรุณาเลือก Supplier');
            isValid = false;
        } else {
            supplierField.classList.remove('is-invalid');
        }
        
    } else if (poType === 'freight') {
        // Freight PO - ต้องมีเลขที่ PO และต้องเลือก Linked PO
        const poNumberField = document.getElementById('po_number');
        const linkedPOField = document.getElementById('linked_po_id');
        const supplierField = document.getElementById('supplier_id');
        
        // ✅ ตรวจสอบเลขที่ PO สำหรับ Freight
        if (!poNumberField || !poNumberField.value.trim()) {
            poNumberField.classList.add('is-invalid');
            if (!firstErrorField) firstErrorField = poNumberField;
            errorMessages.push('กรุณากรอกเลขที่ PO');
            isValid = false;
        } else {
            poNumberField.classList.remove('is-invalid');
            
            // ตรวจสอบรูปแบบเลข PO
            if (!/^[A-Za-z0-9\-]+$/.test(poNumberField.value.trim())) {
                poNumberField.classList.add('is-invalid');
                errorMessages.push('รูปแบบเลขที่ PO ไม่ถูกต้อง ใช้ตัวอักษรภาษาอังกฤษ ตัวเลข และเครื่องหมาย - เท่านั้น');
                isValid = false;
            }
        }
        
        if (!linkedPOField || !linkedPOField.value.trim()) {
            linkedPOField.classList.add('is-invalid');
            if (!firstErrorField) firstErrorField = linkedPOField;
            errorMessages.push('กรุณาเลือก PO วัตถุดิบที่ต้องการเพิ่มค่าขนส่ง');
            isValid = false;
        } else {
            linkedPOField.classList.remove('is-invalid');
        }
        
        // ตรวจสอบว่า Supplier ถูกเลือกอัตโนมัติหรือยัง
        if (!supplierField || !supplierField.value.trim()) {
            errorMessages.push('กรุณาเลือก PO วัตถุดิบเพื่อให้ระบบเลือก Supplier อัตโนมัติ');
            isValid = false;
        }
    }
    
    // ตรวจสอบรายการตามประเภท
    if (poType === 'material') {
        // ตรวจสอบรายการสินค้า
        const materialRows = document.querySelectorAll('#material-items-table tbody tr');
        let hasValidItem = false;
        
        materialRows.forEach((row, index) => {
            const productSelect = row.querySelector('select[name="product_id[]"]');
            const quantity = row.querySelector('input[name="quantity[]"]');
            const unit = row.querySelector('select[name="purchase_unit_id[]"]');
            const price = row.querySelector('input[name="unit_price[]"]');
            
            if (productSelect.value && quantity.value && unit.value && price.value) {
                hasValidItem = true;
                // ลบ invalid classes
                [productSelect, quantity, unit, price].forEach(field => {
                    field.classList.remove('is-invalid');
                });
            } else if (productSelect.value || quantity.value || unit.value || price.value) {
                // มีการกรอกบางส่วน ให้แสดงข้อผิดพลาด
                [productSelect, quantity, unit, price].forEach(field => {
                    if (!field.value) {
                        field.classList.add('is-invalid');
                        if (!firstErrorField) firstErrorField = field;
                    }
                });
                isValid = false;
            }
        });
        
        if (!hasValidItem) {
            errorMessages.push('กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ');
            isValid = false;
        }
    } else if (poType === 'freight') {
        // ตรวจสอบรายการค่าใช้จ่าย
        const freightRows = document.querySelectorAll('#freight-items-table tbody tr');
        let hasValidFreight = false;
        
        freightRows.forEach(row => {
            const typeSelect = row.querySelector('select[name="freight_type[]"]');
            const description = row.querySelector('input[name="freight_description[]"]');
            const amount = row.querySelector('input[name="freight_amount[]"]');
            
            if (typeSelect.value && description.value && amount.value) {
                hasValidFreight = true;
                [typeSelect, description, amount].forEach(field => {
                    field.classList.remove('is-invalid');
                });
            } else if (typeSelect.value || description.value || amount.value) {
                [typeSelect, description, amount].forEach(field => {
                    if (!field.value) {
                        field.classList.add('is-invalid');
                        if (!firstErrorField) firstErrorField = field;
                    }
                });
                isValid = false;
            }
        });
        
        if (!hasValidFreight) {
            errorMessages.push('กรุณาเพิ่มรายการค่าใช้จ่ายอย่างน้อย 1 รายการ');
            isValid = false;
        }
    }
    
    // ✅ แสดง error message เพียง 1 ข้อความ (ข้อความแรกที่พบ)
    if (!isValid) {
        if (errorMessages.length > 0) {
            showAlert(errorMessages[0], 'danger'); // แสดงเฉพาะข้อความแรก
        }
        
        if (firstErrorField) {
            firstErrorField.focus();
            firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
    
    return isValid;
}

        function resetForm() {
            if (confirm('คุณต้องการรีเซ็ตฟอร์มใช่หรือไม่?')) {
                document.getElementById('poForm').reset();
                
                // ล้าง validation classes
                document.querySelectorAll('.is-invalid').forEach(field => {
                    field.classList.remove('is-invalid');
                });
                
                // ล้าง PO type selection
                document.querySelectorAll('.po-type-card').forEach(card => {
                    card.classList.remove('selected');
                });
                
                // ล้าง freight type selection
                document.querySelectorAll('.freight-type-option').forEach(option => {
                    option.classList.remove('selected');
                });
                
                // รีเซ็ตยอดรวม
                ['material-total', 'material-grand-total', 'freight-total', 'freight-grand-total'].forEach(id => {
                    const element = document.getElementById(id);
                    if (element) element.textContent = '0.00 บาท';
                });
                
                // ซ่อนส่วนรายละเอียด
                hideAllSections();
                
                // ตั้งค่าวันที่เป็นวันปัจจุบัน
                document.getElementById('po_date').valueAsDate = new Date();
                
                showAlert('ฟอร์มถูกรีเซ็ตแล้ว', 'info');
            }
        }

        // Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
    console.log('🔧 DOMContentLoaded event triggered');
    console.log('Current URL:', window.location.href);
    
    // 🔥 PO Type Selection - เพิ่มเข้ามาก่อน
    console.log('📌 Attaching PO Type Card listeners...');
    const poTypeCards = document.querySelectorAll('.po-type-card');
    console.log('Found PO Type Cards:', poTypeCards.length);
    
    if (poTypeCards.length === 0) {
        console.error('❌ No PO Type Cards found! Check HTML structure.');
    }
    
    poTypeCards.forEach((card, index) => {
        console.log(`Card ${index}:`, card.dataset.type, card);
        
        card.addEventListener('click', function(e) {
            console.log('✅ PO Type Card clicked (event):', e);
            console.log('✅ PO Type Card clicked (this.dataset.type):', this.dataset.type);
            
            // เพิ่มทันทีเพื่อให้เห็นการตอบสนองทันที
            this.classList.add('selected');
            document.querySelectorAll('.po-type-card').forEach(c => {
                if (c !== this) c.classList.remove('selected');
            });
            
            // ตั้งค่า hidden input
            const type = this.dataset.type;
            const poTypeInput = document.getElementById('po_type');
            if (!poTypeInput) {
                console.error('❌ po_type input not found!');
                return;
            }
            
            poTypeInput.value = type;
            console.log('✅ Set po_type to:', type, 'Value is now:', poTypeInput.value);
            
            // อัพเดทการแสดงผลหลังจากกำหนด value
            try {
                updatePOTypeDisplay();
                console.log('✅ updatePOTypeDisplay() executed successfully');
            } catch (err) {
                console.error('❌ Error in updatePOTypeDisplay():', err);
            }
            
            e.preventDefault();
            e.stopPropagation();
            return false;
        });
    });

    // Freight Type Selection
    console.log('📌 Attaching Freight Type Option listeners...');
    document.querySelectorAll('.freight-type-option').forEach(option => {
        option.addEventListener('click', function() {
            console.log('✅ Freight Type Option clicked:', this.dataset.freightType);
            document.querySelectorAll('.freight-type-option').forEach(o => o.classList.remove('selected'));
            this.classList.add('selected');
            
            // อัพเดทตัวเลือกในตาราง
            const freightType = this.dataset.freightType;
            const selectElement = document.querySelector('#freight-items-table tbody tr:last-child select[name="freight_type[]"]');
            if (selectElement) {
                selectElement.value = freightType;
            }
        });
    });
    
    // เพิ่ม event listeners สำหรับแถววัสดุ (เฉพาะที่มีอยู่แล้ว)
    console.log('📌 Attaching Material Row listeners...');
    document.querySelectorAll('#material-items-table tbody tr').forEach(addMaterialRowListeners);
    
    // เพิ่ม event listeners สำหรับแถวค่าขนส่ง (เฉพาะที่มีอยู่แล้ว)
    console.log('📌 Attaching Freight Row listeners...');
    document.querySelectorAll('#freight-items-table tbody tr').forEach(addFreightRowListeners);
    
    // 🔥 Supplier Product Filter
    console.log('📌 Setting up Supplier change handler...');
    const supplierSelect = document.getElementById('supplier_id');
    if (supplierSelect) {
        // 🔥 Apply initial filter if supplier is already selected on page load
        if (supplierSelect.value) {
            console.log('✅ Supplier already selected on page load:', supplierSelect.value);
            setTimeout(() => {
                updateProductsBySupplier(supplierSelect.value);
            }, 500);
        }
        
        // Native select change
        supplierSelect.addEventListener('change', function() {
            const selectedSupplierId = this.value;
            console.log('✅ Supplier changed (native):', selectedSupplierId);
            updateProductsBySupplier(selectedSupplierId);
        });
        
        // Select2 change (if Select2 is used)
        if (typeof jQuery !== 'undefined') {
            jQuery(supplierSelect).on('select2:select select2:clear', function(e) {
                const selectedSupplierId = this.value;
                console.log('✅ Supplier changed (Select2):', selectedSupplierId);
                updateProductsBySupplier(selectedSupplierId);
            });
        }
    }
    
    // ========================================
    // CONFIRMATION MODAL
    // ========================================
    
    function showConfirmationModal() {
        console.log('📋 Generating PO Summary...');
        
        // รวบรวมข้อมูลพื้นฐาน
        const poType = document.getElementById('po_type').value;
        const poNumber = document.getElementById('po_number').value;
        const poDate = document.getElementById('po_date').value;
        const notes = document.getElementById('notes').value;
        
        // Supplier
        const supplierSelect = document.getElementById('supplier_id');
        const supplierText = supplierSelect.options[supplierSelect.selectedIndex]?.text || '-';
        
        // PO Type Display
        const poTypeDisplay = poType === 'material' ? 
            '📦 PO วัตถุดิบ (Material)' : 
            '🚚 PO ค่าขนส่ง (Freight)';
        
        // แสดงข้อมูลพื้นฐาน
        document.getElementById('summary-po-type').innerHTML = poTypeDisplay;
        document.getElementById('summary-po-number').textContent = poNumber || '-';
        document.getElementById('summary-supplier').textContent = supplierText;
        document.getElementById('summary-date').textContent = formatDateThai(poDate);
        document.getElementById('summary-notes').textContent = notes || 'ไม่มี';
        
        // Linked PO (สำหรับ Freight)
        if (poType === 'freight') {
            const linkedPOSelect = document.getElementById('linked_po_id');
            const linkedPOText = linkedPOSelect?.options[linkedPOSelect.selectedIndex]?.text || '-';
            document.getElementById('summary-linked-po').textContent = linkedPOText;
            document.getElementById('summary-linked-po-row').style.display = '';
        } else {
            document.getElementById('summary-linked-po-row').style.display = 'none';
        }
        
        // สร้างตารางรายการ
        if (poType === 'material') {
            generateMaterialSummary();
        } else {
            generateFreightSummary();
        }
        
        // แสดง Modal
        const modal = new bootstrap.Modal(document.getElementById('confirmationModal'));
        modal.show();
        
        console.log('✅ Confirmation modal displayed');
    }
    
    function generateMaterialSummary() {
        const table = document.getElementById('summary-items-table');
        const thead = table.querySelector('thead');
        const tbody = table.querySelector('tbody');
        
        // Headers
        thead.innerHTML = `
            <tr>
                <th width="5%" class="text-center">#</th>
                <th width="20%">รหัสสินค้า</th>
                <th width="30%">รายละเอียด</th>
                <th width="10%" class="text-end">จำนวน</th>
                <th width="10%">หน่วย</th>
                <th width="12%" class="text-end">ราคา/หน่วย</th>
                <th width="13%" class="text-end">รวม</th>
            </tr>
        `;
        
        // Rows
        const rows = document.querySelectorAll('#items-tbody tr');
        let totalAmount = 0;
        tbody.innerHTML = '';
        
        rows.forEach((row, index) => {
            const productSelect = row.querySelector('select[name="product_id[]"]');
            const productText = productSelect?.options[productSelect.selectedIndex]?.text || '-';
            
            const quantity = row.querySelector('input[name="quantity[]"]')?.value || '0';
            
            const unitSelect = row.querySelector('select[name="purchase_unit_id[]"]');
            const unitText = unitSelect?.options[unitSelect.selectedIndex]?.text || '-';
            
            const unitPrice = parseFloat(row.querySelector('input[name="unit_price[]"]')?.value || 0);
            const totalPrice = parseFloat(row.querySelector('input[name="total_price[]"]')?.value || 0);
            
            totalAmount += totalPrice;
            
            // รวบรวมรายละเอียดจาก dynamic fields
            const dynamicDetails = [];
            
            // ตรวจสอบฟิลด์ต่างๆ
            const packageQty = row.querySelector('input[name="package_qty[]"]')?.value;
            const weightPerPkg = row.querySelector('input[name="weight_per_package[]"]')?.value;
            const totalWeight = row.querySelector('input[name="total_weight[]"]')?.value;
            const gsm = row.querySelector('input[name="gsm[]"]')?.value;
            const thickness = row.querySelector('input[name="thickness[]"]')?.value;
            
            if (packageQty) dynamicDetails.push(`${packageQty} แพ็ค`);
            if (weightPerPkg) dynamicDetails.push(`${weightPerPkg} กก./แพ็ค`);
            if (totalWeight) dynamicDetails.push(`รวม ${totalWeight} กก.`);
            if (gsm) dynamicDetails.push(`${gsm} แกรม`);
            if (thickness) dynamicDetails.push(`หนา ${thickness} มม.`);
            
            const detailsText = dynamicDetails.length > 0 ? 
                `<small class="text-muted">${dynamicDetails.join(' • ')}</small>` : '';
            
            tbody.innerHTML += `
                <tr>
                    <td class="text-center">${index + 1}</td>
                    <td>${productText}</td>
                    <td>${detailsText}</td>
                    <td class="text-end">${parseFloat(quantity).toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
                    <td>${unitText}</td>
                    <td class="text-end">${unitPrice.toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
                    <td class="text-end fw-bold">${totalPrice.toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
                </tr>
            `;
        });
        
        // อัปเดตยอดรวม
        document.getElementById('summary-total-label').textContent = 'ยอดรวมสินค้า:';
        document.getElementById('summary-total').textContent = totalAmount.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' บาท';
        document.getElementById('summary-grand-total').textContent = totalAmount.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' บาท';
    }
    
    function generateFreightSummary() {
        const table = document.getElementById('summary-items-table');
        const thead = table.querySelector('thead');
        const tbody = table.querySelector('tbody');
        
        // Headers
        thead.innerHTML = `
            <tr>
                <th width="5%" class="text-center">#</th>
                <th width="30%">รายละเอียดค่าใช้จ่าย</th>
                <th width="15%">หน่วย</th>
                <th width="20%" class="text-end">ราคา/หน่วย</th>
                <th width="15%" class="text-end">จำนวน</th>
                <th width="15%" class="text-end">รวม</th>
            </tr>
        `;
        
        // Rows
        const descInputs = document.querySelectorAll('input[name="freight_description[]"]');
        const unitSelects = document.querySelectorAll('select[name="freight_unit[]"]');
        const amountInputs = document.querySelectorAll('input[name="freight_amount[]"]');
        const qtyInputs = document.querySelectorAll('input[name="freight_qty[]"]');
        const totalInputs = document.querySelectorAll('input[name="freight_total[]"]');
        
        let totalAmount = 0;
        tbody.innerHTML = '';
        
        descInputs.forEach((descInput, index) => {
            const description = descInput.value || '-';
            const unitSelect = unitSelects[index];
            const unitText = unitSelect?.options[unitSelect.selectedIndex]?.text || '-';
            const amount = parseFloat(amountInputs[index]?.value || 0);
            const qty = parseFloat(qtyInputs[index]?.value || 0);
            const total = parseFloat(totalInputs[index]?.value || 0);
            
            totalAmount += total;
            
            tbody.innerHTML += `
                <tr>
                    <td class="text-center">${index + 1}</td>
                    <td>${description}</td>
                    <td>${unitText}</td>
                    <td class="text-end">${amount.toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
                    <td class="text-end">${qty.toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
                    <td class="text-end fw-bold">${total.toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
                </tr>
            `;
        });
        
        // อัปเดตยอดรวม
        document.getElementById('summary-total-label').textContent = 'ยอดรวมค่าขนส่ง:';
        document.getElementById('summary-total').textContent = totalAmount.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' บาท';
        document.getElementById('summary-grand-total').textContent = totalAmount.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' บาท';
    }
    
    function formatDateThai(dateString) {
        if (!dateString) return '-';
        
        const date = new Date(dateString);
        const day = date.getDate().toString().padStart(2, '0');
        const month = (date.getMonth() + 1).toString().padStart(2, '0');
        const year = date.getFullYear() + 543; // แปลงเป็น พ.ศ.
        
        return `${day}/${month}/${year}`;
    }
    
    // ========================================
    // END CONFIRMATION MODAL
    // ========================================
    
    // Form Validation and Confirmation
    console.log('📌 Setting up Form submit handler...');
    const poForm = document.getElementById('poForm');
    if (poForm) {
        poForm.addEventListener('submit', function(e) {
            // ป้องกัน default submit
            e.preventDefault();
            
            // Validate form
            if (!validateForm()) {
                return false;
            }
            
            // แสดง confirmation modal
            showConfirmationModal();
            
            return false; // ป้องกัน submit จริง
        });
    }
    
    // ปุ่มยืนยันใน Modal
    const confirmSubmitBtn = document.getElementById('confirmSubmitBtn');
    if (confirmSubmitBtn) {
        confirmSubmitBtn.addEventListener('click', function() {
            // ปิด modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('confirmationModal'));
            if (modal) {
                modal.hide();
            }
            
            // เปิด disabled fields ก่อน submit (สำหรับ Freight PO)
            const poType = document.getElementById('po_type').value;
            if (poType === 'freight') {
                const supplierSelect = document.getElementById('supplier_id');
                if (supplierSelect) {
                    supplierSelect.disabled = false;
                    console.log('✅ Supplier enabled for submit');
                }
            }
            
            // แสดง loading state
            const submitBtn = poForm.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>กำลังสร้าง PO...';
            
            // Submit form จริง
            console.log('🚀 Submitting form...');
            poForm.submit();
            
            // Reset button หลังจาก timeout (fallback)
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }, 10000);
        });
    }
    
    // Real-time validation
    console.log('📌 Setting up Real-time validation...');
    document.querySelectorAll('input, select').forEach(field => {
        field.addEventListener('blur', function() {
            if (this.hasAttribute('required') && !this.value.trim()) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });
        
        field.addEventListener('input', function() {
            if (this.classList.contains('is-invalid') && this.value.trim()) {
                this.classList.remove('is-invalid');
            }
        });
    });
    
    console.log('✅ PO Create Form initialized successfully');
    
    // Initialize Select2 for all existing Product selects
console.log('📌 Initializing Product Select2 for existing rows...');
const existingProductSelects = document.querySelectorAll('select[name="product_id[]"]');
existingProductSelects.forEach((select, index) => {
    initProductSelect2(select);
    console.log(`✓ Product Select2 initialized for row ${index + 1}`);
});
    
    // Initialize Select2 for Supplier
    console.log('📌 Initializing Select2...');
    initSupplierSelect2();
    
    // Add event listener for weight calculations
    console.log('📌 Setting up weight calculation listener...');
    document.addEventListener('input', function(e) {
        const row = e.target.closest('tr');
        if (!row) return;
        
        if (e.target.classList.contains('package-qty') || e.target.classList.contains('weight-per-package')) {
            const packageQty = parseFloat(row.querySelector('.package-qty')?.value || 0);
            const weightPerPackage = parseFloat(row.querySelector('.weight-per-package')?.value || 0);
            const totalWeightInput = row.querySelector('.total-weight');
            
            if (totalWeightInput) {
                if (packageQty > 0 && weightPerPackage > 0) {
                    const totalWeight = packageQty * weightPerPackage;
                    totalWeightInput.value = totalWeight.toFixed(2);
                    totalWeightInput.readOnly = true;
                    totalWeightInput.style.backgroundColor = '#e7f5ff';
                } else {
                    totalWeightInput.readOnly = false;
                    totalWeightInput.style.backgroundColor = '';
                }
                
                // Trigger calculation for total price
                totalWeightInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    });
    
    console.log('✅ Package calculation initialized');
});
function initSupplierSelect2() {
    if (typeof jQuery !== 'undefined' && jQuery.fn.select2) {
        // Check if element exists
        const supplierSelect = $('#supplier_id');
        if (supplierSelect.length === 0) {
            console.warn('⚠️ Supplier select element not found');
            return;
        }
        
        // Destroy ก่อนถ้ามีอยู่แล้ว
        if (supplierSelect.hasClass('select2-hidden-accessible')) {
            supplierSelect.select2('destroy');
        }
        
        supplierSelect.select2({
            theme: 'bootstrap-5',
            placeholder: 'พิมพ์ค้นหาหรือเลือก Supplier',
            allowClear: true,
            width: '100%',
            dropdownParent: supplierSelect.parent(),
            language: {
                noResults: function() {
                    return 'ไม่พบข้อมูล';
                },
                searching: function() {
                    return 'กำลังค้นหา...';
                }
            }
        });

        // Event handler
        supplierSelect.on('select2:select select2:clear', function(e) {
            const selectedSupplierId = this.value;
            updateProductsBySupplier(selectedSupplierId);
        });
        
        console.log('✅ Supplier Select2 initialized successfully');
    } else {
        console.error('❌ jQuery or Select2 not loaded');
    }
}

// Initialize Select2 for Product dropdowns (รหัสสินค้า)
function initProductSelect2(selectElement) {
    if (typeof jQuery !== 'undefined' && jQuery.fn.select2) {
        const $select = $(selectElement);
        
        if ($select.length === 0) {
            console.warn('⚠️ Product select element not found');
            return;
        }
        
        // Destroy เดิมก่อน
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.select2('destroy');
        }
        
        // Initialize
        $select.select2({
            theme: 'bootstrap-5',
            placeholder: 'พิมพ์ค้นหาหรือเลือกสินค้า',
            allowClear: true,
            width: '100%',
            dropdownParent: $select.parent(),
            language: {
                noResults: function() {
                    return 'ไม่พบสินค้า';
                },
                searching: function() {
                    return 'กำลังค้นหา...';
                }
            }
        });
        
        // 🔥 Add change event listener for Select2
        $select.on('select2:select select2:clear', function(e) {
            const selectNativeElement = selectElement;
            console.log('✅ Product changed (Select2):', selectNativeElement.value);
            handleProductChange(selectNativeElement);
        });
        
        console.log('✅ Product Select2 initialized');
    } else {
        console.error('❌ jQuery or Select2 not loaded');
    }
}

// Initialize Select2 for Linked PO dropdown
function initLinkedPOSelect2() {
    if (typeof jQuery !== 'undefined' && jQuery.fn.select2) {
        const $linkedPO = $('#linked_po_id');
        if ($linkedPO.length === 0) return;
        
        if ($linkedPO.hasClass('select2-hidden-accessible')) {
            $linkedPO.select2('destroy');
        }
        
        $linkedPO.select2({
            theme: 'bootstrap-5',
            placeholder: 'พิมพ์ค้นหาหรือเลือก PO วัตถุดิบ',
            allowClear: true,
            width: '100%',
            dropdownParent: $linkedPO.parent(),
            language: {
                noResults: function() { return 'ไม่พบ PO'; },
                searching: function() { return 'กำลังค้นหา...'; }
            }
        });
        
        console.log('✅ Linked PO Select2 initialized');
    }
}
// Setup Linked PO Change Handler - อัปเดต Supplier อัตโนมัติ
function setupLinkedPOChangeHandler() {
    const linkedPOSelect = document.getElementById('linked_po_id');
    const supplierSelect = document.getElementById('supplier_id');
    
    if (!linkedPOSelect || !supplierSelect) {
        console.error('❌ Linked PO or Supplier select not found');
        return;
    }
    
    // ฟังก์ชันสำหรับอัปเดต Supplier
    function updateSupplierFromLinkedPO() {
        const selectedOption = linkedPOSelect.options[linkedPOSelect.selectedIndex];
        
        if (selectedOption && selectedOption.value) {
            const supplierId = selectedOption.dataset.supplierId;
            const supplierName = selectedOption.dataset.supplierName;
            const poNumber = selectedOption.dataset.poNumber;
            
            console.log('=== Linked PO Changed ===');
            console.log('Selected PO:', poNumber);
            console.log('Supplier ID:', supplierId);
            console.log('Supplier Name:', supplierName);
            
            if (supplierId) {
                // เปิด disabled ชั่วคราว
                supplierSelect.disabled = false;
                
                // เซ็ตค่า Supplier
                supplierSelect.value = supplierId;
                
                // ถ้าใช้ Select2
                if (typeof jQuery !== 'undefined') {
                    // Destroy Select2 ถ้ามี
                    if ($('#supplier_id').hasClass('select2-hidden-accessible')) {
                        $('#supplier_id').select2('destroy');
                    }
                    
                    // เซ็ตค่า
                    $('#supplier_id').val(supplierId).trigger('change');
                }
                
                // ปิด disabled อีกครั้ง
                supplierSelect.disabled = true;
                
                // แสดง notification
                showAlert(
                    `เลือก PO ${poNumber} จาก ${supplierName} แล้ว - Supplier ถูกเลือกอัตโนมัติ`, 
                    'success'
                );
                
                console.log('✓ Supplier auto-selected:', supplierId, supplierName);
            } else {
                console.warn('⚠️ No supplier_id found in selected PO');
                supplierSelect.disabled = false;
                supplierSelect.value = '';
                supplierSelect.disabled = true;
            }
        } else {
            // ถ้าไม่ได้เลือก PO ให้ล้าง Supplier
            supplierSelect.disabled = false;
            supplierSelect.value = '';
            
            if (typeof jQuery !== 'undefined' && $('#supplier_id').hasClass('select2-hidden-accessible')) {
                $('#supplier_id').select2('destroy');
                $('#supplier_id').val(null).trigger('change');
            }
            
            supplierSelect.disabled = true;
            console.log('✓ Linked PO cleared, Supplier reset');
        }
    }
    
    // Event listener สำหรับ native select
    linkedPOSelect.addEventListener('change', updateSupplierFromLinkedPO);
    
    // Event listener สำหรับ Select2
    if (typeof jQuery !== 'undefined') {
        $('#linked_po_id').off('select2:select select2:clear').on('select2:select select2:clear', function(e) {
            updateSupplierFromLinkedPO();
        });
    }
    
    console.log('✅ Linked PO change handler setup complete');
}

// 🔥 เพิ่มฟังก์ชันนี้หลัง DOMContentLoaded (นอกฟังก์ชัน)
function updateProductsBySupplier(selectedSupplierId) {
    console.log('=== updateProductsBySupplier called ===');
    console.log('Selected Supplier ID:', selectedSupplierId);
    
    // Fetch products from API based on supplier
    let apiUrl = '../../api/get_products.php';
    if (selectedSupplierId) {
        apiUrl += '?supplier_id=' + encodeURIComponent(selectedSupplierId);
    }
    
    console.log('Fetching from:', apiUrl);
    
    fetch(apiUrl)
        .then(response => response.json())
        .then(data => {
            console.log('API Response:', data);
            
            if (!data.success) {
                console.error('❌ API Error:', data.message);
                return;
            }
            
            const products = data.data || [];
            console.log('Received', products.length, 'products from API');
            
            const productSelects = document.querySelectorAll('select[name="product_id[]"]');
            console.log('Found product selects:', productSelects.length);
            
            productSelects.forEach((select, selectIndex) => {
                // Save current value
                const currentValue = select.value;
                const currentText = select.querySelector(`option[value="${currentValue}"]`)?.textContent || '';
                
                // Get placeholder option
                const placeholderOption = select.querySelector('option[value=""]');
                
                // Remove all options except placeholder
                while (select.options.length > 1) {
                    select.remove(1);
                }
                
                // Add options from API
                products.forEach(product => {
                    const option = document.createElement('option');
                    option.value = product.id;
                    option.textContent = `${product.SSP_Code} - ${product.Name}`;
                    option.setAttribute('data-ssp', product.SSP_Code || '');
                    option.setAttribute('data-name', product.Name || '');
                    option.setAttribute('data-name2', product.Name2 || '');
                    option.setAttribute('data-group', product.group_name || '');
                    option.setAttribute('data-material-type', product.material_type_name || '');
                    option.setAttribute('data-supplier-id', selectedSupplierId);
                    select.appendChild(option);
                    
                    if (currentValue && product.id == currentValue) {
                        select.value = currentValue;
                    }
                });
                
                console.log(`✓ Updated select ${selectIndex} with ${products.length} products`);
                
                // Trigger change to refresh Select2 if it's initialized
                if (typeof jQuery !== 'undefined' && jQuery(select).hasClass('select2-hidden-accessible')) {
                    console.log(`  Refreshing Select2 for select ${selectIndex}`);
                    jQuery(select).trigger('change');
                } else {
                    // Trigger native change event
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            
            console.log('=== updateProductsBySupplier completed ===');
        })
        .catch(error => {
            console.error('❌ Fetch error:', error);
            showAlert('เกิดข้อผิดพลาดในการโหลดสินค้า: ' + error.message, 'danger');
        });
}


// ============================================================
// GLOBAL FUNCTIONS FOR PO CONFIRMATION MODAL
// ============================================================

window.handlePOSubmit = function() {
    console.log('🔥 ===== PO SUBMIT CLICKED =====');
    
    // Validate form
    console.log('⏳ Validating form...');
    const isValid = validateForm();
    console.log('✓ Validation result:', isValid);
    
    if (!isValid) {
        console.log('❌ Validation failed');
        return false;
    }
    
    console.log('✅ Validation passed - checking PO number...');
    
    // 🔥 Check for duplicate PO number
    const poNumberInput = document.getElementById('po_number');
    const poNumber = poNumberInput?.value?.trim();
    
    if (!poNumber) {
        showAlert('กรุณากรอกเลขที่ PO', 'danger');
        return false;
    }
    
    // Call check_po_number.php to verify
    const formData = new FormData();
    formData.append('po_number', poNumber);
    
    fetch('./check_po_number.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log('✓ PO Check Result:', data);
        
        if (data.error) {
            showAlert('เกิดข้อผิดพลาดในการตรวจสอบเลข PO: ' + data.message, 'danger');
            return;
        }
        
        if (data.exists) {
            // PO number already exists
            showAlert('⚠️ เลข PO "' + poNumber + '" มีอยู่แล้ว (Status: ' + data.status + ')\n\nกรุณาใช้เลข PO ใหม่', 'warning');
            poNumberInput.focus();
            poNumberInput.classList.add('is-invalid');
            return;
        }
        
        // PO number is unique, proceed to show modal
        console.log('✅ PO number is unique - showing modal');
        window.showPOSummary();
    })
    .catch(error => {
        console.error('❌ Fetch error:', error);
        showAlert('เกิดข้อผิดพลาดในการตรวจสอบเลข PO: ' + error.message, 'danger');
    });
};


window.showPOSummary = function() {
    console.log('📋 ===== GENERATING PO SUMMARY =====');
    
    try {
        // รวบรวมข้อมูลพื้นฐาน
        const poType = document.getElementById('po_type').value;
        const poNumber = document.getElementById('po_number').value;
        const poDate = document.getElementById('po_date').value;
        const notes = document.getElementById('notes').value;
        
        // Supplier
        const supplierSelect = document.getElementById('supplier_id');
        const supplierText = supplierSelect.options[supplierSelect.selectedIndex]?.text || '-';
        
        // PO Type Display
        const poTypeDisplay = poType === 'material' ? 
            '📦 PO วัตถุดิบ (Material)' : 
            '🚚 PO ค่าขนส่ง (Freight)';
        
        // แสดงข้อมูลพื้นฐาน
        document.getElementById('summary-po-type').innerHTML = poTypeDisplay;
        document.getElementById('summary-po-number').textContent = poNumber || '-';
        document.getElementById('summary-supplier').textContent = supplierText;
        document.getElementById('summary-date').textContent = window.formatThaiDate(poDate);
        document.getElementById('summary-notes').textContent = notes || 'ไม่มี';
        
        // Linked PO (Freight)
        if (poType === 'freight') {
            const linkedPOSelect = document.getElementById('linked_po_id');
            const linkedPOText = linkedPOSelect?.options[linkedPOSelect.selectedIndex]?.text || '-';
            document.getElementById('summary-linked-po').textContent = linkedPOText;
            document.getElementById('summary-linked-po-row').style.display = '';
        } else {
            document.getElementById('summary-linked-po-row').style.display = 'none';
        }
        
        // สร้างตารางรายการ
        if (poType === 'material') {
            window.generateMaterialItems();
        } else {
            window.generateFreightItems();
        }
        
        // แสดง Modal
        const modalElement = document.getElementById('confirmationModal');
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
        
        console.log('✅ Modal displayed');
        
    } catch (error) {
        console.error('❌ Error generating summary:', error);
        throw error;
    }
};

window.generateMaterialItems = function() {
    console.log('📦 Generating material items summary...');
    
    const table = document.getElementById('summary-items-table');
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');
    
    // Headers
    thead.innerHTML = `
        <tr>
            <th width="5%" class="text-center">#</th>
            <th width="15%">รหัสสินค้า</th>
            <th width="20%">รายละเอียด</th>
            <th width="12%" class="text-end">จำนวน</th>
            <th width="10%">หน่วย</th>
            <th width="13%" class="text-end">ราคา/หน่วย</th>
            <th width="13%" class="text-end">รวม</th>
            <th width="12%">หมายเหตุ</th>
        </tr>
    `;
    
    // Rows
    const rows = document.querySelectorAll('#items-tbody tr');
    let totalAmount = 0;
    tbody.innerHTML = '';
    
    rows.forEach((row, index) => {
        const productSelect = row.querySelector('select[name="product_id[]"]');
        const productText = productSelect?.options[productSelect.selectedIndex]?.text || '-';
        
        const quantity = parseFloat(row.querySelector('input[name="quantity[]"]')?.value || 0);
        
        const unitSelect = row.querySelector('select[name="purchase_unit_id[]"]');
        const unitText = unitSelect?.options[unitSelect.selectedIndex]?.text || '-';
        
        const unitPrice = parseFloat(row.querySelector('input[name="unit_price[]"]')?.value || 0);
        const totalPrice = parseFloat(row.querySelector('input[name="total_price[]"]')?.value || 0);
        
        const notesItem = row.querySelector('input[name="notes_item[]"]')?.value || '-';
        
        totalAmount += totalPrice;
        
        // รวบรวมรายละเอียดจาก dynamic fields
        const dynamicDetails = [];
        const packageQty = row.querySelector('input[name="package_qty[]"]')?.value;
        const weightPerPkg = row.querySelector('input[name="weight_per_package[]"]')?.value;
        const totalWeight = row.querySelector('input[name="total_weight[]"]')?.value;
        const gsm = row.querySelector('input[name="gsm[]"]')?.value;
        const thickness = row.querySelector('input[name="thickness[]"]')?.value;
        
        if (packageQty) dynamicDetails.push(`${packageQty} แพ็ค`);
        if (weightPerPkg) dynamicDetails.push(`${weightPerPkg} กก./แพ็ค`);
        if (totalWeight) dynamicDetails.push(`รวม ${totalWeight} กก.`);
        if (gsm) dynamicDetails.push(`${gsm} แกรม`);
        if (thickness) dynamicDetails.push(`หนา ${thickness} มม.`);
        
        const detailsText = dynamicDetails.length > 0 ? 
            `<small class="text-muted">${dynamicDetails.join(' • ')}</small>` : '-';
        
        tbody.innerHTML += `
            <tr>
                <td class="text-center">${index + 1}</td>
                <td><small>${productText}</small></td>
                <td>${detailsText}</td>
                <td class="text-end"><strong>${quantity.toLocaleString('th-TH', {minimumFractionDigits: 2})}</strong></td>
                <td>${unitText}</td>
                <td class="text-end">${unitPrice.toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
                <td class="text-end fw-bold">${totalPrice.toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
                <td><small class="text-muted">${notesItem}</small></td>
            </tr>
        `;
    });
    
    // อัปเดตยอดรวม
    document.getElementById('summary-total-label').textContent = 'ยอดรวมสินค้า:';
    document.getElementById('summary-total').textContent = totalAmount.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' บาท';
    document.getElementById('summary-grand-total').textContent = totalAmount.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' บาท';
    
    console.log('✅ Material summary generated');
};

window.generateFreightItems = function() {
    console.log('🚚 Generating freight items summary...');
    
    const table = document.getElementById('summary-items-table');
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');
    
    // Headers
    thead.innerHTML = `
        <tr>
            <th width="5%" class="text-center">#</th>
            <th width="35%">รายละเอียดค่าใช้จ่าย</th>
            <th width="15%">หน่วย</th>
            <th width="15%" class="text-end">ราคา/หน่วย</th>
            <th width="15%" class="text-end">จำนวน</th>
            <th width="15%" class="text-end">รวม</th>
        </tr>
    `;
    
    // Rows
    const descInputs = document.querySelectorAll('input[name="freight_description[]"]');
    const unitSelects = document.querySelectorAll('select[name="freight_unit[]"]');
    const amountInputs = document.querySelectorAll('input[name="freight_amount[]"]');
    const qtyInputs = document.querySelectorAll('input[name="freight_qty[]"]');
    const totalInputs = document.querySelectorAll('input[name="freight_total[]"]');
    
    let totalAmount = 0;
    tbody.innerHTML = '';
    
    descInputs.forEach((descInput, index) => {
        const description = descInput.value || '-';
        const unitSelect = unitSelects[index];
        const unitText = unitSelect?.options[unitSelect.selectedIndex]?.text || '-';
        const amount = parseFloat(amountInputs[index]?.value || 0);
        const qty = parseFloat(qtyInputs[index]?.value || 0);
        const total = parseFloat(totalInputs[index]?.value || 0);
        
        totalAmount += total;
        
        tbody.innerHTML += `
            <tr>
                <td class="text-center">${index + 1}</td>
                <td>${description}</td>
                <td>${unitText}</td>
                <td class="text-end">${amount.toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
                <td class="text-end"><strong>${qty.toLocaleString('th-TH', {minimumFractionDigits: 2})}</strong></td>
                <td class="text-end fw-bold">${total.toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
            </tr>
        `;
    });
    
    // อัปเดตยอดรวม
    document.getElementById('summary-total-label').textContent = 'ยอดรวมค่าขนส่ง:';
    document.getElementById('summary-total').textContent = totalAmount.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' บาท';
    document.getElementById('summary-grand-total').textContent = totalAmount.toLocaleString('th-TH', {minimumFractionDigits: 2}) + ' บาท';
    
    console.log('✅ Freight summary generated');
};

window.formatThaiDate = function(dateString) {
    if (!dateString) return '-';
    
    const date = new Date(dateString);
    const day = date.getDate().toString().padStart(2, '0');
    const month = (date.getMonth() + 1).toString().padStart(2, '0');
    const year = date.getFullYear() + 543; // พ.ศ.
    
    return `${day}/${month}/${year}`;
};

window.handleConfirmSubmit = function() {
    console.log('🔥 ===== CONFIRM BUTTON CLICKED =====');
    
    try {
        // ปิด modal
        const modalElement = document.getElementById('confirmationModal');
        const modal = bootstrap.Modal.getInstance(modalElement);
        
        if (modal) {
            modal.hide();
            console.log('✓ Modal hidden');
        }
        
        // รอ modal ปิดเสร็จ
        setTimeout(() => {
            window.submitPOForm();
        }, 300);
        
    } catch (error) {
        console.error('❌ Error:', error);
        window.submitPOForm();
    }
};

window.submitPOForm = function() {
    console.log('🚀 ===== SUBMITTING FORM =====');
    
    const poForm = document.getElementById('poForm');
    if (!poForm) {
        console.error('❌ Form not found');
        return;
    }
    
    // เปิด disabled fields (Freight PO)
    const poType = document.getElementById('po_type').value;
    if (poType === 'freight') {
        const supplierSelect = document.getElementById('supplier_id');
        if (supplierSelect) {
            supplierSelect.disabled = false;
            console.log('✓ Supplier enabled');
        }
    }
    
    // Loading state
    const submitBtn = document.getElementById('submitPOBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>กำลังสร้าง PO...';
    }
    
    console.log('✓ Submitting...');
    poForm.submit();
};

// ============================================================
// END GLOBAL FUNCTIONS
// ============================================================

        // Success callback
        <?php if ($message_type === 'success'): ?>
        setTimeout(() => {
            showAlert('สร้าง PO เรียบร้อยแล้ว! ต้องการสร้าง PO ใหม่อีกหรือไม่?', 'success');
        }, 100);
        <?php endif; ?>
    </script>
</body>
</html>