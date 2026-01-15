<?php
// pages/po/edit.php - หน้าแก้ไข Purchase Order
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

// ตรวจสอบ PO ID
$po_id = intval($_GET['id'] ?? 0);
if (!$po_id) {
    $_SESSION['error_message'] = "ไม่พบข้อมูล PO ที่ต้องการแก้ไข";
    header("Location: list.php");
    exit();
}

// ข้อความแจ้ง
$message = '';
$message_type = '';

// ดึงข้อมูล PO ที่ต้องการแก้ไข
try {
    $po_sql = "
        SELECT ph.*, s.supplier_name, s.supplier_code 
        FROM PO_Header ph
        LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
        WHERE ph.po_id = ?
    ";
    $stmt = $conn->prepare($po_sql);
    $stmt->execute([$po_id]);
    $po_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$po_data) {
        $_SESSION['error_message'] = "ไม่พบข้อมูล PO ที่ต้องการแก้ไข";
        header("Location: list.php");
        exit();
    }
    
    // ตรวจสอบสิทธิ์การแก้ไข
    $can_edit = false;
    if ($auth->hasRole(['editor', 'admin']) && $po_data['status'] === 'Draft') {
        $can_edit = true;
    } elseif ($auth->hasRole('admin') && $po_data['status'] === 'Approved') {
        $can_edit = true;
    }
    
    if (!$can_edit) {
        $_SESSION['error_message'] = "คุณไม่มีสิทธิ์แก้ไข PO นี้ (Status: {$po_data['status']})";
        header("Location: list.php");
        exit();
    }
    
    // ดึงรายการสินค้าใน PO
    $items_sql = "
        SELECT pi.*, p.SSP_Code, p.Name, p.Name2, u.unit_name, u.unit_symbol,
               g.name as group_name, mt.type_name as material_type_name
        FROM PO_Items pi
        LEFT JOIN Master_Products_ID p ON pi.product_id = p.id
        LEFT JOIN Units u ON pi.purchase_unit_id = u.unit_id
        LEFT JOIN Groups g ON p.group_id = g.id
        LEFT JOIN Material_Types mt ON p.material_type_id = mt.material_type_id
        WHERE pi.po_id = ?
        ORDER BY pi.line_number
    ";
    $stmt = $conn->prepare($items_sql);
    $stmt->execute([$po_id]);
    $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error loading PO data: " . $e->getMessage());
    $_SESSION['error_message'] = "เกิดข้อผิดพลาดในการโหลดข้อมูล PO";
    header("Location: list.php");
    exit();
}

// ดึงข้อมูลสำหรับ dropdown (เหมือนหน้า create)
try {
    // Suppliers
    $stmt = $conn->prepare("SELECT supplier_id, supplier_code, supplier_name FROM Suppliers WHERE is_active = 1 ORDER BY supplier_name");
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Products
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
    
    // Material POs for Freight linking
    $stmt = $conn->prepare("
        SELECT ph.po_id, ph.po_number, ph.supplier_id, ph.total_amount, ph.po_date, s.supplier_name
        FROM PO_Header ph
        LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
        WHERE ph.is_material_po = 1 AND ph.status IN ('Draft', 'Approved', 'Partial') AND ph.po_id != ?
        ORDER BY ph.po_date DESC, ph.po_number DESC
    ");
    $stmt->execute([$po_id]);
    $material_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error loading dropdown data: " . $e->getMessage());
    $suppliers = [];
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
        $po_type = $po_data['is_material_po'] ? 'material' : 'freight'; // ใช้ประเภทเดิม
        $supplier_id = intval($_POST['supplier_id']);
        $po_date = $_POST['po_date'];
        $notes = sanitizeInput($_POST['notes'] ?? '');
        
        // ตรวจสอบข้อมูลพื้นฐาน
        if (!$po_number || !$supplier_id || !$po_date) {
            throw new Exception("กรุณากรอกข้อมูลพื้นฐานให้ครบถ้วน");
        }
        
        // ตรวจสอบว่าเลขที่ PO ซ้ำหรือไม่ (ยกเว้นตัวเอง)
        $stmt = $conn->prepare("SELECT po_id FROM PO_Header WHERE po_number = ? AND po_id != ?");
        $stmt->execute([$po_number, $po_id]);
        if ($stmt->fetch()) {
            throw new Exception("เลขที่ PO นี้มีอยู่ในระบบแล้ว กรุณาใช้เลขที่อื่น");
        }
        
        // ดึง item_type_id
        try {
            $material_type_stmt = $conn->prepare("
                SELECT item_type_id FROM Item_Types 
                WHERE type_name = 'Material' OR type_code = 'MAT' OR type_name LIKE '%Material%'
                ORDER BY item_type_id 
            ");
            $material_type_stmt->execute();
            $material_type_result = $material_type_stmt->fetch(PDO::FETCH_ASSOC);
            $item_type_id_material = $material_type_result['item_type_id'] ?? 1;

            $freight_type_stmt = $conn->prepare("
                SELECT item_type_id FROM Item_Types 
                WHERE type_name = 'Freight' OR type_code = 'FRT' OR type_name LIKE '%Freight%'
                ORDER BY item_type_id 
            ");
            $freight_type_stmt->execute();
            $freight_type_result = $freight_type_stmt->fetch(PDO::FETCH_ASSOC);
            $item_type_id_freight = $freight_type_result['item_type_id'] ?? 2;

        } catch (PDOException $e) {
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
        
        // เตรียมตัวแปรสำหรับ UPDATE
        $linked_po_id = ($po_type === 'freight' && !empty($_POST['linked_po_id'])) ? intval($_POST['linked_po_id']) : null;
        $net_amount = $total_amount;

        // อัพเดท PO Header
        $stmt = $conn->prepare("
            UPDATE PO_Header SET
            po_number = ?, po_date = ?, supplier_id = ?, material_amount = ?, freight_amount = ?, 
            service_amount = ?, total_amount = ?, net_amount = ?, notes = ?, linked_po_id = ?,
            updated_by = ?, updated_date = ?
            WHERE po_id = ?
        ");
        
        $result = $stmt->execute([
            $po_number, $po_date, $supplier_id, $material_amount, $freight_amount, 
            $service_amount, $total_amount, $net_amount, $notes, $linked_po_id,
            $_SESSION['user_id'], date('Y-m-d H:i:s'), $po_id
        ]);

        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Update PO_Header failed: " . $errorInfo[2]);
        }

        // ลบรายการเดิมทั้งหมด
        $stmt = $conn->prepare("DELETE FROM PO_Items WHERE po_id = ?");
        $stmt->execute([$po_id]);
        
        // บันทึกรายการใหม่
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
                        
                        $stmt = $conn->prepare("
                            INSERT INTO PO_Items 
                            (po_id, line_number, product_id, quantity, purchase_unit_id, stock_unit_id, 
                             conversion_factor, stock_quantity, unit_price, total_price, item_type_id, status, notes) 
                            VALUES (?, ?, ?, ?, ?, ?, 1.0, ?, ?, ?, ?, 'Open', ?)
                        ");
                        
                        $stmt->execute([
                            $po_id, $line_number, $product_id, $quantity, $purchase_unit_id, $purchase_unit_id,
                            $quantity, $unit_price, $total_price, $item_type_id_material, $notes_item
                        ]);
                        
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
                        
                        $item_description = "{$freight_type}: {$freight_description}";
                        
                        $stmt = $conn->prepare("
                            INSERT INTO PO_Items 
                            (po_id, line_number, item_description, quantity, unit_price, total_price, item_type_id, status, notes) 
                            VALUES (?, ?, ?, 1.0, ?, ?, ?, 'Open', ?)
                        ");
                        
                        $stmt->execute([
                            $po_id, $line_number, $item_description, $freight_amount, $freight_amount, $item_type_id_freight, $freight_notes
                        ]);
                        
                        $line_number++;
                    }
                }
            }
        }
        
        $conn->commit();
        
        // เก็บ success message ใน session
        $_SESSION['success_message'] = "แก้ไข PO เรียบร้อยแล้ว! หมายเลข PO: " . $po_number . " (ID: " . $po_id . ")";
        
        // Redirect ไปหน้า list
        header("Location: list.php");
        exit();
        
} catch (PDOException $e) {
    $conn->rollback();
    error_log("Database error in edit PO: " . $e->getMessage());
    
    if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
        $message = "Database Error: " . $e->getMessage();
    } else {
        $message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
    }
    $message_type = "danger";
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("General error in edit PO: " . $e->getMessage());
    
    if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
        $message = "Error: " . $e->getMessage();
    } else {
        $message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
    }
    $message_type = "danger";
}
}

// กำหนดประเภท PO สำหรับการแสดงผล
$po_type = $po_data['is_material_po'] ? 'material' : 'freight';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไข Purchase Order - <?= htmlspecialchars(APP_NAME ?? 'Material Management') ?></title>
    
    <!-- Security Headers -->
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #ff9a56 0%, #ffb347 50%, #ffd700 100%);
            --primary-gradient-dark: linear-gradient(135deg, #ff7f50 0%, #ff9a56 100%);
        }

        body {
            background: linear-gradient(135deg, #fff8f0 0%, #ffe4d1 50%, #fff3e0 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar {
            background: var(--primary-gradient);
            box-shadow: 0 4px 20px rgba(255, 154, 86, 0.3);
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
        
        .po-status-badge {
            font-size: 0.9em;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: bold;
        }
        
        .edit-header {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../dashboard.php">
                <i class="fas fa-edit me-2"></i><?= htmlspecialchars(APP_NAME ?? 'Material Management') ?>
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="list.php">
                    <i class="fas fa-list me-1"></i> รายการ PO
                </a>
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-arrow-left me-1"></i> กลับสู่หน้าหลัก
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-4" style="padding-top: 20px;">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <!-- Header -->
                <div class="card mb-4">
                    <div class="card-header edit-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0">
                                <i class="fas fa-edit me-2"></i>แก้ไข Purchase Order
                            </h4>
                            <div>
                                <span class="po-status-badge bg-<?= $po_data['status'] === 'Draft' ? 'warning' : 'success' ?> text-dark">
                                    Status: <?= htmlspecialchars($po_data['status']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="text-muted mb-1">
                                    <i class="fas fa-info-circle me-2"></i>
                                    แก้ไขข้อมูล Purchase Order
                                </p>
                                <div class="fw-bold">PO: <?= htmlspecialchars($po_data['po_number']) ?></div>
                            </div>
                            <div class="col-md-6 text-end">
                                <small class="text-muted">
                                    สร้างเมื่อ: <?= date('d/m/Y H:i', strtotime($po_data['created_date'])) ?><br>
                                    <?php if (!empty($po_data['updated_date'])): ?>
                                    แก้ไขล่าสุด: <?= date('d/m/Y H:i', strtotime($po_data['updated_date'])) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($message): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show" role="alert">
                    <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Main Form -->
                <form method="POST" id="editPoForm" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    
                    <!-- PO Number Display -->
                    <div class="preview-box">
                        <h5><i class="fas fa-edit me-2"></i>เลขที่ PO</h5>
                        <div class="row justify-content-center">
                            <div class="col-md-6">
                                <input type="text" class="form-control form-control-lg text-center" 
                                       id="po_number" name="po_number" 
                                       style="font-family: 'Courier New', monospace; font-weight: bold; font-size: 1.2rem; letter-spacing: 1px;"
                                       value="<?= htmlspecialchars($po_data['po_number']) ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Basic Information -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-clipboard-list me-2"></i>ข้อมูลพื้นฐาน
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="po_date" class="form-label">
                                        วันที่ <span class="required">*</span>
                                    </label>
                                    <input type="date" class="form-control" id="po_date" name="po_date" 
                                           value="<?= htmlspecialchars($po_data['po_date']) ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="supplier_id" class="form-label">
                                        Supplier <span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="supplier_id" name="supplier_id" required>
                                        <option value="">เลือก Supplier</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?= htmlspecialchars($supplier['supplier_id']) ?>"
                                                <?= $supplier['supplier_id'] == $po_data['supplier_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($supplier['supplier_name']) ?> (<?= htmlspecialchars($supplier['supplier_code']) ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="notes" class="form-label">หมายเหตุ</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="2" 
                                              placeholder="หมายเหตุเพิ่มเติม"><?= htmlspecialchars($po_data['notes'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PO Type Display -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-tags me-2"></i>ประเภท PO
                                <span class="badge bg-light text-dark ms-2">
                                    <?= $po_type === 'material' ? '📦 PO วัตถุดิบ' : '🚚 PO ค่าขนส่ง' ?>
                                </span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>หมายเหตุ:</strong> ไม่สามารถเปลี่ยนประเภท PO ได้ในการแก้ไข หากต้องการเปลี่ยนประเภท กรุณาสร้าง PO ใหม่
                            </div>
                        </div>
                    </div>

                    <!-- Material PO Details -->
                    <?php if ($po_type === 'material'): ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-box me-2"></i>รายละเอียดสินค้า
                            </h5>
                        </div>
                        <div class="card-body">
                            <table class="items-table" id="material-items-table">
                                <thead>
                                    <tr>
                                        <th width="25%">รหัสสินค้า</th>
                                        <th width="15%">จำนวน</th>
                                        <th width="15%">หน่วย</th>
                                        <th width="15%">ราคา/หน่วย</th>
                                        <th width="15%">รวม</th>
                                        <th width="10%">หมายเหตุ</th>
                                        <th width="5%">ลบ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $material_items = array_filter($po_items, function($item) {
                                        return !empty($item['product_id']);
                                    });
                                    
                                    if (empty($material_items)):
                                        // แสดงแถวเปล่าถ้าไม่มีข้อมูล
                                        $material_items = [['product_id' => '', 'quantity' => '', 'purchase_unit_id' => '', 'unit_price' => '', 'total_price' => '', 'notes' => '']];
                                    endif;
                                    
                                    foreach ($material_items as $index => $item): ?>
                                    <tr>
                                        <td>
                                            <select name="product_id[]" class="form-select" required>
                                                <option value="">-- เลือกสินค้า --</option>
                                                <?php foreach ($products as $product): ?>
                                                <option value="<?= htmlspecialchars($product['id']) ?>" 
                                                        data-ssp="<?= htmlspecialchars($product['SSP_Code']) ?>"
                                                        data-name="<?= htmlspecialchars($product['Name']) ?>"
                                                        data-name2="<?= htmlspecialchars($product['Name2'] ?? '') ?>"
                                                        data-group="<?= htmlspecialchars($product['group_name'] ?? '') ?>"
                                                        data-material-type="<?= htmlspecialchars($product['material_type_name'] ?? '') ?>"
                                                        data-supplier-id="<?= htmlspecialchars($product['supplier_id'] ?? '') ?>"
                                                        <?= $product['id'] == ($item['product_id'] ?? '') ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($product['SSP_Code']) ?> - <?= htmlspecialchars($product['Name']) ?>
                                                    <?php if (!empty($product['Name2'])): ?>
                                                    | <?= htmlspecialchars($product['Name2']) ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($product['group_name'])): ?>
                                                    (<?= htmlspecialchars($product['group_name']) ?>)
                                                    <?php endif; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted" id="product-info-<?= $index ?>" style="display: none;">
                                                <i class="fas fa-info-circle me-1"></i>
                                                <span class="product-details"></span>
                                            </small>
                                        </td>
                                        <td><input type="number" name="quantity[]" step="0.01" required placeholder="0.00" 
                                                   class="form-control" value="<?= htmlspecialchars($item['quantity'] ?? '') ?>"></td>
                                        <td>
                                            <select name="purchase_unit_id[]" class="form-select" required>
                                                <option value="">-- หน่วย --</option>
                                                <?php foreach ($units as $unit): ?>
                                                <option value="<?= htmlspecialchars($unit['unit_id']) ?>"
                                                        data-symbol="<?= htmlspecialchars($unit['unit_symbol']) ?>"
                                                        data-name-th="<?= htmlspecialchars($unit['unit_name_th'] ?? '') ?>"
                                                        <?= $unit['unit_id'] == ($item['purchase_unit_id'] ?? '') ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($unit['unit_name']) ?>
                                                    <?php if (!empty($unit['unit_symbol'])): ?>
                                                    (<?= htmlspecialchars($unit['unit_symbol']) ?>)
                                                    <?php endif; ?>
                                                    <?php if (!empty($unit['unit_name_th']) && $unit['unit_name_th'] !== $unit['unit_name']): ?>
                                                    - <?= htmlspecialchars($unit['unit_name_th']) ?>
                                                    <?php endif; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><input type="number" name="unit_price[]" step="0.01" required placeholder="0.00" 
                                                   class="form-control" value="<?= htmlspecialchars($item['unit_price'] ?? '') ?>"></td>
                                        <td><input type="text" name="total_price[]" readonly class="form-control calculated-field total-price" 
                                                   value="<?= htmlspecialchars($item['total_price'] ?? '') ?>"></td>
                                        <td><input type="text" name="notes_item[]" placeholder="หมายเหตุ" class="form-control" 
                                                   value="<?= htmlspecialchars($item['notes'] ?? '') ?>"></td>
                                        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">❌</button></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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
                                                <span id="material-total" class="fw-bold"><?= number_format($po_data['material_amount'], 2) ?> บาท</span>
                                            </div>
                                            <hr>
                                            <div class="d-flex justify-content-between">
                                                <span class="fw-bold">รวมทั้งหมด:</span>
                                                <span id="material-grand-total" class="fw-bold text-primary"><?= number_format($po_data['total_amount'], 2) ?> บาท</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Freight PO Details -->
                    <?php if ($po_type === 'freight'): ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-truck me-2"></i>รายละเอียดค่าขนส่ง
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-4">
                                <div class="col-md-12 mb-3">
                                    <label for="linked_po_id" class="form-label">เลือก PO วัตถุดิบ</label>
                                    <select id="linked_po_id" name="linked_po_id" class="form-select">
                                        <option value="">-- เลือก PO วัตถุดิบ --</option>
                                        <?php foreach ($material_pos as $po): ?>
                                        <option value="<?= htmlspecialchars($po['po_id']) ?>"
                                                <?= $po['po_id'] == ($po_data['linked_po_id'] ?? '') ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($po['po_number']) ?> - <?= htmlspecialchars($po['supplier_name']) ?> 
                                            (<?= number_format($po['total_amount'], 2) ?> บาท) - <?= date('d/m/Y', strtotime($po['po_date'])) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
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
                                    <?php 
                                    $freight_items = array_filter($po_items, function($item) {
                                        return !empty($item['item_description']);
                                    });
                                    
                                    if (empty($freight_items)):
                                        // แสดงแถวเปล่าถ้าไม่มีข้อมูล
                                        $freight_items = [['item_description' => '', 'unit_price' => '', 'notes' => '']];
                                    endif;
                                    
                                    foreach ($freight_items as $item): 
                                        // แยก freight_type และ description จาก item_description
                                        $item_desc = $item['item_description'] ?? '';
                                        $freight_type = '';
                                        $freight_description = '';
                                        
                                        if (strpos($item_desc, ':') !== false) {
                                            list($freight_type, $freight_description) = explode(':', $item_desc, 2);
                                            $freight_description = trim($freight_description);
                                        } else {
                                            $freight_description = $item_desc;
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <select name="freight_type[]" class="form-select" required>
                                                <option value="">-- เลือกประเภท --</option>
                                                <option value="shipping" <?= $freight_type === 'shipping' ? 'selected' : '' ?>>ค่าขนส่ง</option>
                                                <option value="customs" <?= $freight_type === 'customs' ? 'selected' : '' ?>>ค่าภาษีศุลกากร</option>
                                                <option value="insurance" <?= $freight_type === 'insurance' ? 'selected' : '' ?>>ค่าประกันภัย</option>
                                                <option value="handling" <?= $freight_type === 'handling' ? 'selected' : '' ?>>ค่าดำเนินการ</option>
                                                <option value="other" <?= $freight_type === 'other' ? 'selected' : '' ?>>อื่นๆ</option>
                                            </select>
                                        </td>
                                        <td><input type="text" name="freight_description[]" required placeholder="รายละเอียด" 
                                                   class="form-control" value="<?= htmlspecialchars($freight_description) ?>"></td>
                                        <td><input type="number" name="freight_amount[]" step="0.01" required placeholder="0.00" 
                                                   class="form-control" value="<?= htmlspecialchars($item['unit_price'] ?? '') ?>"></td>
                                        <td><input type="text" name="freight_notes[]" placeholder="หมายเหตุ" class="form-control" 
                                                   value="<?= htmlspecialchars($item['notes'] ?? '') ?>"></td>
                                        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">❌</button></td>
                                    </tr>
                                    <?php endforeach; ?>
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
                                                <span id="freight-total" class="fw-bold"><?= number_format($po_data['freight_amount'], 2) ?> บาท</span>
                                            </div>
                                            <hr>
                                            <div class="d-flex justify-content-between">
                                                <span class="fw-bold">รวมทั้งหมด:</span>
                                                <span id="freight-grand-total" class="fw-bold text-primary"><?= number_format($po_data['total_amount'], 2) ?> บาท</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Submit Buttons -->
                    <div class="card mt-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <a href="list.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i>ยกเลิก
                                </a>
                                
                                <div>
                                    <a href="view.php?id=<?= $po_id ?>" class="btn btn-outline-primary me-2">
                                        <i class="fas fa-eye me-2"></i>ดูรายละเอียด
                                    </a>
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save me-2"></i>บันทึกการแก้ไข
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Helper Functions
        function formatCurrency(amount) {
            return new Intl.NumberFormat('th-TH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(amount);
        }

        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const container = document.querySelector('.container .row .col-lg-10');
            container.insertBefore(alertDiv, container.children[2]);
            
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
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

        // Row Management Functions
        function addMaterialRow() {
            const tbody = document.querySelector('#material-items-table tbody');
            const firstRow = tbody.querySelector('tr');
            const newRow = firstRow.cloneNode(true);
            const rowIndex = tbody.rows.length;
            
            // ล้างค่าในแถวใหม่
            newRow.querySelectorAll('input, select').forEach(input => {
                if (input.type !== 'button') {
                    input.value = '';
                    input.selectedIndex = 0;
                }
            });
            
            // อัพเดท ID สำหรับ product info
            const infoElement = newRow.querySelector('[id^="product-info"]');
            if (infoElement) {
                infoElement.id = `product-info-${rowIndex}`;
                infoElement.style.display = 'none';
            }
            
            tbody.appendChild(newRow);
            
            // เพิ่ม event listeners สำหรับแถวใหม่
            addMaterialRowListeners(newRow);
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

        // Event Listeners for Material Rows
        function addMaterialRowListeners(row) {
            const quantityField = row.querySelector('input[name="quantity[]"]');
            const priceField = row.querySelector('input[name="unit_price[]"]');
            const productSelect = row.querySelector('select[name="product_id[]"]');
            
            // Calculation listeners
            [quantityField, priceField].forEach(field => {
                if (field) {
                    field.addEventListener('input', () => updateRowTotal(row));
                    field.addEventListener('blur', () => updateRowTotal(row));
                }
            });
            
            // Product selection listener
            if (productSelect) {
                productSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const infoElement = row.querySelector('.product-details');
                    const infoContainer = row.querySelector('[id^="product-info"]');
                    
                    if (selectedOption && selectedOption.value && infoElement) {
                        const sspCode = selectedOption.dataset.ssp || '';
                        const name = selectedOption.dataset.name || '';
                        const name2 = selectedOption.dataset.name2 || '';
                        const group = selectedOption.dataset.group || '';
                        const materialType = selectedOption.dataset.materialType || '';
                        
                        let details = `SSP: ${sspCode}`;
                        if (group) details += ` | กลุ่ม: ${group}`;
                        if (materialType) details += ` | ประเภท: ${materialType}`;
                        if (name2) details += ` | EN: ${name2}`;
                        
                        infoElement.textContent = details;
                        infoContainer.style.display = 'block';
                    } else if (infoContainer) {
                        infoContainer.style.display = 'none';
                    }
                });
            }
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
            const requiredFields = ['po_number', 'po_date', 'supplier_id'];
            let isValid = true;
            let firstErrorField = null;
            
            // ตรวจสอบฟิลด์พื้นฐาน
            requiredFields.forEach(fieldName => {
                const field = document.getElementById(fieldName);
                if (!field || !field.value.trim()) {
                    if (field) {
                        field.classList.add('is-invalid');
                        if (!firstErrorField) firstErrorField = field;
                    }
                    isValid = false;
                } else if (field) {
                    field.classList.remove('is-invalid');
                }
            });
            
            // ตรวจสอบรายการ
            const poType = '<?= $po_type ?>';
            
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
                        [productSelect, quantity, unit, price].forEach(field => {
                            field.classList.remove('is-invalid');
                        });
                    } else if (productSelect.value || quantity.value || unit.value || price.value) {
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
                    showAlert('กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ', 'danger');
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
                    showAlert('กรุณาเพิ่มรายการค่าใช้จ่ายอย่างน้อย 1 รายการ', 'danger');
                    isValid = false;
                }
            }
            
            if (!isValid && firstErrorField) {
                firstErrorField.focus();
                firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            
            return isValid;
        }

        // Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            // เพิ่ม event listeners สำหรับแถวที่มีอยู่แล้ว
            document.querySelectorAll('#material-items-table tbody tr').forEach(addMaterialRowListeners);
            document.querySelectorAll('#freight-items-table tbody tr').forEach(addFreightRowListeners);
            
            // Form Validation
            document.getElementById('editPoForm').addEventListener('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                    return false;
                }
                
                // แสดง loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>กำลังบันทึก...';
                
                // Reset button หลังจาก timeout (fallback)
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }, 10000);
            });
            
            // Real-time validation
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
            
            // คำนวณยอดรวมเริ่มต้น
            const poType = '<?= $po_type ?>';
            if (poType === 'material') {
                calculateMaterialTotal();
            } else if (poType === 'freight') {
                calculateFreightTotal();
            }
            
            console.log('✅ PO Edit Form initialized successfully');
        });

        // Confirmation before leaving
        let formChanged = false;
        
        document.querySelectorAll('input, select, textarea').forEach(field => {
            field.addEventListener('change', function() {
                formChanged = true;
            });
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        
        // Reset form changed flag on submit
        document.getElementById('editPoForm').addEventListener('submit', function() {
            formChanged = false;
        });
    </script>
</body>
</html>