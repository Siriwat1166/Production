<?php
// pages/po/create.php - หน้าสร้าง Purchase Order
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole('editor');

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
    
    // Products for Material PO - ดึงจากตาราง Master_Products_ID (mat)
    $stmt = $conn->prepare("
        SELECT p.id, p.SSP_Code, p.Name, p.Name2, u.unit_name, u.unit_symbol,
               g.name as group_name, mt.type_name as material_type_name
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
    
    // Material POs for Freight linking - เฉพาะ PO ที่เป็น Material
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

    function validateRequired($value, $fieldName) {
        if (empty(trim($value))) {
            throw new Exception("กรุณากรอก{$fieldName}");
        }
        return trim($value);
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
        
        // บันทึก PO Header
        $linked_po_id = ($po_type === 'freight' && !empty($_POST['linked_po_id'])) ? intval($_POST['linked_po_id']) : null;
        
        $stmt = $conn->prepare("
            INSERT INTO PO_Header 
            (po_number, po_date, supplier_id, po_type_id, material_amount, freight_amount, service_amount, 
             total_amount, currency, exchange_rate, status, notes, created_by, created_date, 
             is_material_po, is_freight_po, linked_po_id, po_category) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'THB', 1.0, 'Draft', ?, ?, GETDATE(), ?, ?, ?, ?)
        ");
        
        $po_type_id = 1; // Default PO Type
        $is_material_po = ($po_type === 'material') ? 1 : 0;
        $is_freight_po = ($po_type === 'freight') ? 1 : 0;
        $po_category = ($po_type === 'material') ? 'Material' : 'Freight';
        
        $stmt->execute([
            $po_number, $po_date, $supplier_id, $po_type_id, 
            $material_amount, $freight_amount, $service_amount, $total_amount,
            $notes, $_SESSION['user_id'], 
            $is_material_po, $is_freight_po, $linked_po_id, $po_category
        ]);
        
        $po_id = $conn->lastInsertId();
        
        if (!$po_id) {
            throw new Exception("ไม่สามารถสร้าง PO ได้");
        }
        
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
                        
                        $stmt = $conn->prepare("
                            INSERT INTO PO_Items 
                            (po_id, line_number, product_id, quantity, purchase_unit_id, stock_unit_id, 
                             conversion_factor, stock_quantity, unit_price, total_price, status, notes) 
                            VALUES (?, ?, ?, ?, ?, ?, 1.0, ?, ?, ?, 'Open', ?)
                        ");
                        
                        $stmt->execute([
                            $po_id, $line_number, $product_id, $quantity, $purchase_unit_id, $purchase_unit_id,
                            $quantity, $unit_price, $total_price, $notes_item
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
                        
                        // ใช้ PO_Items table โดยใส่ข้อมูลใน item_description
                        $item_description = "{$freight_type}: {$freight_description}";
                        
                        $stmt = $conn->prepare("
                            INSERT INTO PO_Items 
                            (po_id, line_number, item_description, quantity, unit_price, total_price, status, notes) 
                            VALUES (?, ?, ?, 1.0, ?, ?, 'Open', ?)
                        ");
                        
                        $stmt->execute([
                            $po_id, $line_number, $item_description, $freight_amount, $freight_amount, $freight_notes
                        ]);
                        
                        $line_number++;
                    }
                }
            }
        }
        
        $conn->commit();
        
        $message = "สร้าง PO เรียบร้อยแล้ว! หมายเลข PO: " . $po_number;
        $message_type = "success";
        
        // Clear form data
        $_POST = [];
        
    } catch (PDOException $e) {
        $conn->rollback();
        error_log("Database error in create PO: " . $e->getMessage());
        $message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
        $message_type = "danger";
    } catch (Exception $e) {
        $conn->rollback();
        error_log("General error in create PO: " . $e->getMessage());
        $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
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
    <meta http-equiv="X-Frame-Options" content="DENY">
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
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../dashboard.php">
                <i class="fas fa-shopping-cart me-2"></i><?= htmlspecialchars(APP_NAME ?? 'Material Management') ?>
            </a>
            
            <div class="navbar-nav ms-auto">
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
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-plus-circle me-2"></i>สร้าง Purchase Order
                        </h4>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            สร้างใบสั่งซื้อใหม่ ระบบจะสร้างเลขที่ PO อัตโนมัติตามประเภทที่เลือก
                        </p>
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

                <!-- Preview Box -->
                <div class="preview-box">
                    <h5><i class="fas fa-edit me-2"></i>เลขที่ PO</h5>
                    <div class="row justify-content-center">
                        <div class="col-md-6">
                            <input type="text" class="form-control form-control-lg text-center" 
                                   id="po_number" name="po_number" 
                                   placeholder="กรอกเลขที่ PO เช่น PO-2024-001" 
                                   style="font-family: 'Courier New', monospace; font-weight: bold; font-size: 1.2rem; letter-spacing: 1px;"
                                   value="<?= htmlspecialchars($_POST['po_number'] ?? '') ?>" required>
                        </div>
                    </div>
                    <small class="text-muted">
                        ตัวอย่าง: PO-2024-001, MAT-202412-001, FRT-202412-001
                    </small>
                </div>

                <!-- Main Form -->
                <form method="POST" id="poForm" novalidate>
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    
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
                                           value="<?= date('Y-m-d') ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="supplier_id" class="form-label">
                                        Supplier <span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="supplier_id" name="supplier_id" required>
                                        <option value="">เลือก Supplier</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?= htmlspecialchars($supplier['supplier_id']) ?>"
                                                <?= (($_POST['supplier_id'] ?? '') == $supplier['supplier_id']) ? 'selected' : '' ?>>
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
                                              placeholder="หมายเหตุเพิ่มเติม"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- เลือกประเภท PO -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-tags me-2"></i>เลือกประเภท PO
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

                    <!-- รายละเอียด PO วัตถุดิบ -->
                    <div id="material-details" class="details-section">
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-box me-2"></i>รายละเอียดสินค้า
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info">
                                    <strong>คำแนะนำ:</strong> กรอกรายละเอียดสินค้าที่ต้องการสั่งซื้อ สามารถเพิ่มรายการได้หลายรายการ
                                </div>
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
                                                            data-material-type="<?= htmlspecialchars($product['material_type_name'] ?? '') ?>">
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
                                                <small class="text-muted" id="product-info-0" style="display: none;">
                                                    <i class="fas fa-info-circle me-1"></i>
                                                    <span class="product-details"></span>
                                                </small>
                                            </td>
                                            <td><input type="number" name="quantity[]" step="0.01" required placeholder="0.00" class="form-control"></td>
                                            <td>
                                                <select name="purchase_unit_id[]" class="form-select" required>
                                                    <option value="">-- หน่วย --</option>
                                                    <?php foreach ($units as $unit): ?>
                                                    <option value="<?= htmlspecialchars($unit['unit_id']) ?>"
                                                            data-symbol="<?= htmlspecialchars($unit['unit_symbol']) ?>"
                                                            data-name-th="<?= htmlspecialchars($unit['unit_name_th'] ?? '') ?>">
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
                                            <td><input type="number" name="unit_price[]" step="0.01" required placeholder="0.00" class="form-control"></td>
                                            <td><input type="text" name="total_price[]" readonly class="form-control calculated-field total-price"></td>
                                            <td><input type="text" name="notes_item[]" placeholder="หมายเหตุ" class="form-control"></td>
                                            <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">❌</button></td>
                                        </tr>
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
                                    <strong>คำแนะนำ:</strong> เลือก PO วัตถุดิบที่ต้องการคิดค่าขนส่ง และระบุประเภทค่าใช้จ่าย
                                </div>
                                
                                <div class="row mb-4">
                                    <div class="col-md-12 mb-3">
                                        <label for="linked_po_id" class="form-label">เลือก PO วัตถุดิบ <span class="required">*</span></label>
                                        <select id="linked_po_id" name="linked_po_id" class="form-select" required>
                                            <option value="">-- เลือก PO วัตถุดิบ --</option>
                                            <?php foreach ($material_pos as $po): ?>
                                            <option value="<?= htmlspecialchars($po['po_id']) ?>"
                                                    <?= (($_POST['linked_po_id'] ?? '') == $po['po_id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($po['po_number']) ?> - <?= htmlspecialchars($po['supplier_name']) ?> 
                                                (<?= number_format($po['total_amount'], 2) ?> บาท) - <?= date('d/m/Y', strtotime($po['po_date'])) ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
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
                                    <button type="submit" class="btn btn-success">
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

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
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

        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const container = document.querySelector('.container .row .col-lg-10');
            container.insertBefore(alertDiv, container.children[1]);
            
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // PO Number Preview Functions - ไม่ใช้แล้ว เปลี่ยนเป็น manual input
        function updatePOTypeDisplay() {
            const poTypeInput = document.getElementById('po_type');
            const poType = poTypeInput.value;
            
            // แสดง/ซ่อนส่วนรายละเอียด
            hideAllSections();
            
            if (poType === 'material') {
                document.getElementById('material-details').classList.add('active');
                showAlert('แสดงฟอร์มรายการสินค้า - เตรียมความพร้อมสำหรับ PO วัตถุดิบ', 'success');
            } else if (poType === 'freight') {
                document.getElementById('freight-details').classList.add('active');
                showAlert('แสดงฟอร์มค่าขนส่ง - เตรียมความพร้อมสำหรับ PO ค่าใช้จ่าย', 'info');
            }
        }

        function hideAllSections() {
            document.querySelectorAll('.details-section').forEach(section => {
                section.classList.remove('active');
            });
        }

        // PO Type Selection
        document.querySelectorAll('.po-type-card').forEach(card => {
            card.addEventListener('click', function() {
                // เอาการเลือกออกจากทุกการ์ด
                document.querySelectorAll('.po-type-card').forEach(c => c.classList.remove('selected'));
                
                // เลือกการ์ดนี้
                this.classList.add('selected');
                
                // ตั้งค่า hidden input
                const type = this.dataset.type;
                document.getElementById('po_type').value = type;
                
                // อัพเดทการแสดงผล
                updatePOTypeDisplay();
            });
        });

        // Freight Type Selection
        document.querySelectorAll('.freight-type-option').forEach(option => {
            option.addEventListener('click', function() {
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
            const requiredFields = ['po_number', 'po_date', 'supplier_id', 'po_type'];
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
            
            // ตรวจสอบรูปแบบเลข PO
            const poNumber = document.getElementById('po_number').value.trim();
            if (poNumber && !/^[A-Z0-9\-]+$/.test(poNumber)) {
                document.getElementById('po_number').classList.add('is-invalid');
                showAlert('รูปแบบเลขที่ PO ไม่ถูกต้อง ใช้ตัวอักษรภาษาอังกฤษ ตัวเลข และเครื่องหมาย - เท่านั้น', 'danger');
                isValid = false;
            }
            
            const poType = document.getElementById('po_type').value;
            
            if (!poType) {
                showAlert('กรุณาเลือกประเภท PO', 'danger');
                return false;
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
                    showAlert('กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ', 'danger');
                    isValid = false;
                }
            } else if (poType === 'freight') {
                // ตรวจสอบการเลือก PO
                const linkedPO = document.getElementById('linked_po_id');
                if (!linkedPO.value) {
                    linkedPO.classList.add('is-invalid');
                    if (!firstErrorField) firstErrorField = linkedPO;
                    isValid = false;
                } else {
                    linkedPO.classList.remove('is-invalid');
                }
                
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
            // เพิ่ม event listeners สำหรับแถวที่มีอยู่แล้ว
            document.querySelectorAll('#material-items-table tbody tr').forEach(addMaterialRowListeners);
            document.querySelectorAll('#freight-items-table tbody tr').forEach(addFreightRowListeners);
            
            // Form Validation
            document.getElementById('poForm').addEventListener('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                    return false;
                }
                
                // แสดง loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>กำลังสร้าง PO...';
                
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
            
            console.log('✅ PO Create Form initialized successfully');
        });

        // Success callback
        <?php if ($message_type === 'success'): ?>
        setTimeout(() => {
            showAlert('สร้าง PO เรียบร้อยแล้ว! ต้องการสร้าง PO ใหม่อีกหรือไม่?', 'success');
        }, 100);
        <?php endif; ?>
    </script>
</body>
</html>