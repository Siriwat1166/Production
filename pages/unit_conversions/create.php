<?php
// pages/unit_conversions/create.php - เพิ่ม/แก้ไขการแปลงหน่วย
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin', 'editor']);

// ข้อมูลผู้ใช้
$user_id = $_SESSION['user_id'] ?? 'N/A';
$username = $_SESSION['username'] ?? 'guest';
$full_name = $_SESSION['full_name'] ?? 'Guest User';
$role = $_SESSION['role'] ?? 'viewer';

$message = '';
$message_type = '';
$isEdit = false;
$conversion_id = null;

// เชื่อมต่อฐานข้อมูล
try {
    require_once "../../config/database.php";
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ตรวจสอบว่าเป็นการแก้ไขหรือไม่
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $isEdit = true;
    $conversion_id = $_GET['id'];

    try {
        $stmt = $conn->prepare("SELECT * FROM PRODUCT_UOM_CONVERSIONS WHERE conversion_id = ?");
        $stmt->execute([$conversion_id]);
        $existingData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingData) {
            header("Location: index.php?error=conversion_not_found");
            exit();
        }
    } catch (Exception $e) {
        $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $message_type = "danger";
    }
}

// ดึงข้อมูลสินค้าสำหรับ dropdown
try {
    $stmt = $conn->prepare("SELECT id, SSP_Code, Name FROM Master_Products_ID WHERE is_active = 1 ORDER BY SSP_Code");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $products = [];
}

// ดึงข้อมูลหน่วยจาก UNITS_OF_MEASURE (ใช้ตารางใหม่ ถ้า error ใช้ Units)
try {
    $stmt = $conn->prepare("
        SELECT uom_id, code, name, category 
        FROM UNITS_OF_MEASURE 
        WHERE is_active = 1 
        ORDER BY category, name
    ");
    $stmt->execute();
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $stmt = $conn->prepare("
            SELECT unit_id as uom_id, unit_code as code, unit_name_th as name, 
                   unit_type as category
            FROM Units 
            WHERE is_active = 1 
            ORDER BY unit_type, unit_name_th
        ");
        $stmt->execute();
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $units = [];
    }
}

// จัดกลุ่มหน่วยตามประเภท
$unitsByCategory = [];
foreach ($units as $unit) {
    $category = $unit['category'] ?? 'อื่นๆ';
    $unitsByCategory[$category][] = $unit;
}

// ------------------- ส่วนบันทึกข้อมูล -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $product_id = intval($_POST['product_id'] ?? 0);
        $from_uom_id = intval($_POST['from_uom_id'] ?? 0);
        $to_uom_id = intval($_POST['to_uom_id'] ?? 0);
        $conversion_factor = floatval($_POST['conversion_factor'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (!$product_id || !$from_uom_id || !$to_uom_id || $conversion_factor <= 0) {
            throw new Exception("กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน และอัตราแปลงต้องมากกว่า 0");
        }

        if ($from_uom_id === $to_uom_id) {
            throw new Exception("หน่วยต้นทางและปลายทางต้องไม่เหมือนกัน");
        }

        // ตรวจสอบว่ามีการแปลงซ้ำหรือไม่
        $checkQuery = "
            SELECT conversion_id FROM PRODUCT_UOM_CONVERSIONS 
            WHERE product_id = ? AND from_uom_id = ? AND to_uom_id = ?
        ";

        if ($isEdit) {
            $checkQuery .= " AND conversion_id != ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->execute([$product_id, $from_uom_id, $to_uom_id, $conversion_id]);
        } else {
            $stmt = $conn->prepare($checkQuery);
            $stmt->execute([$product_id, $from_uom_id, $to_uom_id]);
        }

        if ($stmt->fetch()) {
            throw new Exception("มีการแปลงหน่วยนี้อยู่แล้วในระบบ");
        }

        if ($isEdit) {
            $stmt = $conn->prepare("
                UPDATE PRODUCT_UOM_CONVERSIONS SET
                    product_id = ?, from_uom_id = ?, to_uom_id = ?, 
                    conversion_factor = ?, notes = ?, is_active = ?,
                    updated_date = GETDATE(), updated_by = ?
                WHERE conversion_id = ?
            ");
            $stmt->execute([
                $product_id, $from_uom_id, $to_uom_id, $conversion_factor, 
                $notes, $is_active, $user_id, $conversion_id
            ]);
            $message = "อัปเดตการแปลงหน่วยเรียบร้อยแล้ว";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO PRODUCT_UOM_CONVERSIONS (
                    product_id, from_uom_id, to_uom_id, conversion_factor, 
                    notes, is_active, created_date, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, GETDATE(), ?)
            ");
            $stmt->execute([
                $product_id, $from_uom_id, $to_uom_id, $conversion_factor, 
                $notes, $is_active, $user_id
            ]);
            $message = "เพิ่มการแปลงหน่วยใหม่เรียบร้อยแล้ว";
        }

        $message_type = "success";

        header("refresh:2;url=index.php?message=" . urlencode($message) . "&type=success");
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "danger";
    }
}
?>


<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'แก้ไข' : 'เพิ่ม' ?>การแปลงหน่วย - <?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?></title>
    
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
        
        .navbar-brand, .nav-link {
            color: white !important;
        }
        
        .nav-link:hover {
            color: #ffe4d1 !important;
        }
        
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(255, 154, 86, 0.15);
            background: white;
            border: 2px solid #ffe4d1;
            margin-bottom: 25px;
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
            border: 2px solid #ffe4d1;
            background: white;
            color: #ff7f50;
        }
        
        .btn-secondary:hover {
            background: #ffe4d1;
            border-color: #ff9a56;
            color: #ff7f50;
        }
        
        .form-label {
            color: #ff7f50;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .required {
            color: #ff6b6b;
        }
        
        .alert {
            border-radius: 15px;
            border: none;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .conversion-preview {
            background: linear-gradient(135deg, #fff3e0 0%, #ffe4d1 100%);
            border: 2px dashed #ff9a56;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
        }
        
        .conversion-arrow {
            font-size: 2rem;
            color: #ff9a56;
            margin: 0 15px;
        }
        
        .unit-category-header {
            background: rgba(255, 154, 86, 0.1);
            color: #ff7f50;
            padding: 8px 12px;
            font-weight: bold;
            font-size: 0.9rem;
            border-radius: 5px;
            margin: 5px 0;
        }
        
        .form-text {
            color: #ffa726 !important;
        }
        
        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 20px;
        }
        
        .breadcrumb-item a {
            color: #ff7f50;
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            color: #ff9a56;
        }
        
        .breadcrumb-item.active {
            color: #d2691e;
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .calculator-section {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #ffe4d1;
        }
        
        .example-conversions {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .example-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .example-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../dashboard.php">
                <i class="fas fa-exchange-alt me-2"></i><?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?>
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php">
                    <i class="fas fa-list me-1"></i> รายการการแปลงหน่วย
                </a>
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-home me-1"></i> หน้าหลัก
                </a>
            </div>
        </div>
    </nav>

    <!-- Breadcrumb -->
    <div class="container mt-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard.php">หน้าหลัก</a></li>
                <li class="breadcrumb-item"><a href="index.php">จัดการการแปลงหน่วย</a></li>
                <li class="breadcrumb-item active"><?= $isEdit ? 'แก้ไข' : 'เพิ่ม' ?>การแปลงหน่วย</li>
            </ol>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="container mt-4">
        
        <!-- Header -->
        <div class="card fade-in">
            <div class="card-header">
                <h4 class="mb-0">
                    <i class="fas fa-<?= $isEdit ? 'edit' : 'plus-circle' ?> me-2"></i>
                    <?= $isEdit ? 'แก้ไข' : 'เพิ่ม' ?>การแปลงหน่วย
                </h4>
            </div>
            <div class="card-body">
                <p class="text-muted mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    กำหนดอัตราการแปลงหน่วยสำหรับสินค้าแต่ละชนิด เช่น 1 กิโลกรัม = 1000 กรัม
                </p>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Main Form -->
        <form method="POST" id="conversionForm" class="fade-in">
            <div class="row">
                <div class="col-lg-8">
                    <!-- ข้อมูลการแปลงหน่วย -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-cogs me-2"></i>ข้อมูลการแปลงหน่วย
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="product_id" class="form-label">
                                        เลือกสินค้า <span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="product_id" name="product_id" required onchange="updatePreview()">
                                        <option value="">-- เลือกสินค้า --</option>
                                        <?php foreach ($products as $product): ?>
                                        <option value="<?= $product['id'] ?>" 
                                                data-code="<?= htmlspecialchars($product['SSP_Code']) ?>"
                                                data-name="<?= htmlspecialchars($product['Name']) ?>"
                                                <?= ($isEdit && $existingData['product_id'] == $product['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($product['SSP_Code']) ?> - <?= htmlspecialchars($product['Name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="from_uom_id" class="form-label">
                                        หน่วยต้นทาง <span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="from_uom_id" name="from_uom_id" required onchange="updatePreview()">
                                        <option value="">-- เลือกหน่วยต้นทาง --</option>
                                        <?php foreach ($unitsByCategory as $category => $categoryUnits): ?>
                                            <optgroup label="<?= htmlspecialchars($category) ?>">
                                                <?php foreach ($categoryUnits as $unit): ?>
                                                <option value="<?= $unit['uom_id'] ?>"
                                                        data-name="<?= htmlspecialchars($unit['name']) ?>"
                                                        data-code="<?= htmlspecialchars($unit['code']) ?>"
                                                        <?= ($isEdit && $existingData['from_uom_id'] == $unit['uom_id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($unit['name']) ?> (<?= htmlspecialchars($unit['code']) ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="to_uom_id" class="form-label">
                                        หน่วยปลายทาง <span class="required">*</span>
                                    </label>
                                    <select class="form-select" id="to_uom_id" name="to_uom_id" required onchange="updatePreview()">
                                        <option value="">-- เลือกหน่วยปลายทาง --</option>
                                        <?php foreach ($unitsByCategory as $category => $categoryUnits): ?>
                                            <optgroup label="<?= htmlspecialchars($category) ?>">
                                                <?php foreach ($categoryUnits as $unit): ?>
                                                <option value="<?= $unit['uom_id'] ?>"
                                                        data-name="<?= htmlspecialchars($unit['name']) ?>"
                                                        data-code="<?= htmlspecialchars($unit['code']) ?>"
                                                        <?= ($isEdit && $existingData['to_uom_id'] == $unit['uom_id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($unit['name']) ?> (<?= htmlspecialchars($unit['code']) ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="conversion_factor" class="form-label">
                                        อัตราการแปลง <span class="required">*</span>
                                    </label>
                                    <input type="number" class="form-control" id="conversion_factor" 
                                           name="conversion_factor" step="0.0001" min="0.0001" required
                                           placeholder="เช่น 1000 (1 กิโลกรัม = 1000 กรัม)"
                                           value="<?= $isEdit ? htmlspecialchars($existingData['conversion_factor']) : '' ?>"
                                           onchange="updatePreview()" oninput="updatePreview()">
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        จำนวนหน่วยปลายทางที่ได้จาก 1 หน่วยต้นทาง
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">ตัวเลือกเพิ่มเติม</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_active" 
                                               name="is_active" <?= (!$isEdit || $existingData['is_active']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_active">
                                            เปิดใช้งาน
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="notes" class="form-label">หมายเหตุ</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3" 
                                              placeholder="หมายเหตุเพิ่มเติม เช่น วิธีการวัด, เงื่อนไขพิเศษ"><?= $isEdit ? htmlspecialchars($existingData['notes']) : '' ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Preview -->
                    <div class="card fade-in">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-eye me-2"></i>ตัวอย่างการแปลง
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="conversion-preview" id="conversionPreview">
                                <div class="d-flex align-items-center justify-content-center">
                                    <div class="text-center">
                                        <div class="fw-bold">1</div>
                                        <small id="fromUnitName">หน่วยต้นทาง</small>
                                    </div>
                                    <div class="conversion-arrow">
                                        <i class="fas fa-equals"></i>
                                    </div>
                                    <div class="text-center">
                                        <div class="fw-bold text-primary" id="conversionResult">-</div>
                                        <small id="toUnitName">หน่วยปลายทาง</small>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <small class="text-muted" id="productPreview">เลือกสินค้าเพื่อดูตัวอย่าง</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Calculator -->
                    <div class="calculator-section">
                        <h6 class="fw-bold mb-3">
                            <i class="fas fa-calculator me-2"></i>เครื่องคิดเลขแปลงหน่วย
                        </h6>
                        <div class="input-group mb-2">
                            <input type="number" class="form-control" id="calcInput" 
                                   placeholder="จำนวน" onchange="calculateConversion()">
                            <span class="input-group-text" id="calcFromUnit">หน่วย</span>
                        </div>
                        <div class="text-center mb-2">
                            <i class="fas fa-arrow-down text-primary"></i>
                        </div>
                        <div class="input-group">
                            <input type="text" class="form-control" id="calcResult" 
                                   placeholder="ผลลัพธ์" readonly>
                            <span class="input-group-text" id="calcToUnit">หน่วย</span>
                        </div>
                    </div>
                    
                    <!-- Examples -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-lightbulb me-2"></i>ตัวอย่างการแปลงหน่วยทั่วไป
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="example-conversions">
                                <div class="example-item">
                                    <span>น้ำหนัก:</span>
                                    <span>1 kg = 1,000 g</span>
                                </div>
                                <div class="example-item">
                                    <span>กระดาษ:</span>
                                    <span>1 รีม = 500 แผ่น</span>
                                </div>
                                <div class="example-item">
                                    <span>ปริมาตร:</span>
                                    <span>1 ลิตร = 1,000 มล.</span>
                                </div>
                                <div class="example-item">
                                    <span>ความยาว:</span>
                                    <span>1 เมตร = 100 ซม.</span>
                                </div>
                                <div class="example-item">
                                    <span>พื้นที่:</span>
                                    <span>1 ตร.ม. = 10,000 ตร.ซม.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="card">
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i><?= $isEdit ? 'อัปเดต' : 'บันทึก' ?>การแปลงหน่วย
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="testConversion()">
                                    <i class="fas fa-flask me-2"></i>ทดสอบการแปลง
                                </button>
                                <a href="index.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i>ยกเลิก
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // อัปเดตตัวอย่างการแปลง
        function updatePreview() {
            const fromSelect = document.getElementById('from_uom_id');
            const toSelect = document.getElementById('to_uom_id');
            const conversionFactor = document.getElementById('conversion_factor').value;
            const productSelect = document.getElementById('product_id');
            
            // อัปเดตชื่อหน่วย
            const fromUnitName = fromSelect.options[fromSelect.selectedIndex]?.text || 'หน่วยต้นทาง';
            const toUnitName = toSelect.options[toSelect.selectedIndex]?.text || 'หน่วยปลายทาง';
            const productName = productSelect.options[productSelect.selectedIndex]?.text || 'เลือกสินค้าเพื่อดูตัวอย่าง';
            
            document.getElementById('fromUnitName').textContent = fromUnitName;
            document.getElementById('toUnitName').textContent = toUnitName;
            document.getElementById('productPreview').textContent = productName;
            
            // อัปเดตผลการแปลง
            if (conversionFactor && conversionFactor > 0) {
                document.getElementById('conversionResult').textContent = parseFloat(conversionFactor).toLocaleString();
            } else {
                document.getElementById('conversionResult').textContent = '-';
            }
            
            // อัปเดต calculator
            document.getElementById('calcFromUnit').textContent = fromSelect.options[fromSelect.selectedIndex]?.dataset.code || 'หน่วย';
            document.getElementById('calcToUnit').textContent = toSelect.options[toSelect.selectedIndex]?.dataset.code || 'หน่วย';
            
            calculateConversion();
        }

        // คำนวณในเครื่องคิดเลข
        function calculateConversion() {
            const inputValue = parseFloat(document.getElementById('calcInput').value) || 0;
            const conversionFactor = parseFloat(document.getElementById('conversion_factor').value) || 0;
            
            if (inputValue > 0 && conversionFactor > 0) {
                const result = inputValue * conversionFactor;
                document.getElementById('calcResult').value = result.toLocaleString();
            } else {
                document.getElementById('calcResult').value = '';
            }
        }

        // ทดสอบการแปลง
        function testConversion() {
            const fromUnit = document.getElementById('from_uom_id').options[document.getElementById('from_uom_id').selectedIndex]?.text;
            const toUnit = document.getElementById('to_uom_id').options[document.getElementById('to_uom_id').selectedIndex]?.text;
            const factor = document.getElementById('conversion_factor').value;
            
            if (!fromUnit || !toUnit || !factor) {
                alert('กรุณากรอกข้อมูลให้ครบถ้วนก่อนทดสอบ');
                return;
            }
            
            const testValue = prompt(`ทดสอบการแปลง: ใส่จำนวน ${fromUnit} ที่ต้องการแปลง`, '1');
            
            if (testValue && !isNaN(testValue) && testValue > 0) {
                const result = parseFloat(testValue) * parseFloat(factor);
                alert(`ผลการแปลง:\n${parseFloat(testValue).toLocaleString()} ${fromUnit} = ${result.toLocaleString()} ${toUnit}`);
            }
        }

        // ตรวจสอบฟอร์มก่อนส่ง
        document.getElementById('conversionForm').addEventListener('submit', function(e) {
            const productId = document.getElementById('product_id').value;
            const fromUomId = document.getElementById('from_uom_id').value;
            const toUomId = document.getElementById('to_uom_id').value;
            const conversionFactor = parseFloat(document.getElementById('conversion_factor').value);
            
            if (!productId || !fromUomId || !toUomId || !conversionFactor) {
                e.preventDefault();
                alert('กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน');
                return false;
            }
            
            if (fromUomId === toUomId) {
                e.preventDefault();
                alert('หน่วยต้นทางและปลายทางต้องไม่เหมือนกัน');
                return false;
            }
            
            if (conversionFactor <= 0) {
                e.preventDefault();
                alert('อัตราการแปลงต้องมากกว่า 0');
                return false;
            }

            // แสดงสถานะการโหลด
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>กำลังบันทึก...';
            
            // คืนค่าปุ่มหากเกิดข้อผิดพลาด
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }, 10000);
        });

        // เรียกใช้ updatePreview เมื่อโหลดหน้า
        document.addEventListener('DOMContentLoaded', function() {
            updatePreview();
            
            // เพิ่มการ validate แบบเรียลไทม์
            const requiredFields = document.querySelectorAll('select[required], input[required]');
            requiredFields.forEach(field => {
                field.addEventListener('change', function() {
                    if (this.value) {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    } else {
                        this.classList.add('is-invalid');
                        this.classList.remove('is-valid');
                    }
                });
            
            // Auto-dismiss alerts
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    if (alert.classList.contains('show')) {
                        alert.classList.remove('show');
                        setTimeout(() => alert.remove(), 300);
                    }
                });
            }, 5000);
        });

        // ป้องกันการส่งฟอร์มซ้ำ
        let isSubmitting = false;
        document.getElementById('conversionForm').addEventListener('submit', function(e) {
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }
            isSubmitting = true;
        });

        // เพิ่มการตรวจสอบหน่วยซ้ำ
        function checkDuplicateUnits() {
            const fromUomId = document.getElementById('from_uom_id').value;
            const toUomId = document.getElementById('to_uom_id').value;
            
            if (fromUomId && toUomId && fromUomId === toUomId) {
                alert('หน่วยต้นทางและปลายทางต้องไม่เหมือนกัน');
                document.getElementById('to_uom_id').value = '';
                updatePreview();
                return false;
            }
            return true;
        }

        // เพิ่ม event listener สำหรับตรวจสอบหน่วยซ้ำ
        document.getElementById('from_uom_id').addEventListener('change', checkDuplicateUnits);
        document.getElementById('to_uom_id').addEventListener('change', checkDuplicateUnits);
    </script>
</body>
</html>