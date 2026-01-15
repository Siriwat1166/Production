<?php
// pages/suppliers/edit.php - แก้ไขซัพพลายเออร์
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole('editor');

// ข้อมูลผู้ใช้
$user_id = $_SESSION['user_id'] ?? 'N/A';
$username = $_SESSION['username'] ?? 'guest';
$full_name = $_SESSION['full_name'] ?? 'Guest User';
$role = $_SESSION['role'] ?? 'viewer';

$message = '';
$message_type = '';
$supplier = null;
$supplier_id = $_GET['id'] ?? '';

// ตรวจสอบ ID
if (empty($supplier_id) || !is_numeric($supplier_id)) {
    header("Location: index.php?message=" . urlencode("ไม่พบรหัสซัพพลายเออร์") . "&type=danger");
    exit;
}

// เชื่อมต่อฐานข้อมูลและดึงข้อมูลซัพพลายเออร์
try {
    if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
        require_once "../../config/database.php";
        $database = new Database();
        $conn = $database->getConnection();
        
        // ดึงข้อมูลซัพพลายเออร์
        $stmt = $conn->prepare("
            SELECT supplier_id, supplier_code, supplier_name, contact_person, phone, email, 
                   address, tax_id, payment_terms, is_active, created_date, created_by
            FROM Suppliers 
            WHERE supplier_id = ?
        ");
        $stmt->execute([$supplier_id]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$supplier) {
            header("Location: index.php?message=" . urlencode("ไม่พบข้อมูลซัพพลายเออร์") . "&type=danger");
            exit;
        }
        
    } else {
        throw new Exception("ไม่สามารถเชื่อมต่อฐานข้อมูลได้");
    }
} catch (Exception $e) {
    header("Location: index.php?message=" . urlencode("เกิดข้อผิดพลาด: " . $e->getMessage()) . "&type=danger");
    exit;
}

// ประมวลผลการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // รับข้อมูลจากฟอร์ม
        $supplier_code = trim($_POST['supplier_code'] ?? '');
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $tax_id = trim($_POST['tax_id'] ?? '');
        $payment_terms = trim($_POST['payment_terms'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // ตรวจสอบข้อมูลที่จำเป็น
        if (empty($supplier_code) || empty($supplier_name)) {
            throw new Exception("กรุณากรอกรหัสซัพพลายเออร์และชื่อซัพพลายเออร์");
        }
        
        // ตรวจสอบรูปแบบอีเมล
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("รูปแบบอีเมลไม่ถูกต้อง");
        }
        
        // ตรวจสอบความยาวรหัส
        if (strlen($supplier_code) < 3 || strlen($supplier_code) > 20) {
            throw new Exception("รหัสซัพพลายเออร์ต้องมีความยาว 3-20 ตัวอักษร");
        }
        
        // ตรวจสอบรหัสซัพพลายเออร์ซ้ำ (ยกเว้นตัวเอง)
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM Suppliers WHERE supplier_code = ? AND supplier_id != ?");
        $checkStmt->execute([$supplier_code, $supplier_id]);
        if ($checkStmt->fetchColumn() > 0) {
            throw new Exception("รหัสซัพพลายเออร์ '$supplier_code' มีอยู่แล้วในระบบ");
        }
        
        // ตรวจสอบชื่อซัพพลายเออร์ซ้ำ (ยกเว้นตัวเอง)
        $checkNameStmt = $conn->prepare("SELECT COUNT(*) FROM Suppliers WHERE supplier_name = ? AND supplier_id != ?");
        $checkNameStmt->execute([$supplier_name, $supplier_id]);
        if ($checkNameStmt->fetchColumn() > 0) {
            throw new Exception("ชื่อซัพพลายเออร์ '$supplier_name' มีอยู่แล้วในระบบ");
        }
        
        // อัปเดตข้อมูล
        $stmt = $conn->prepare("
            UPDATE Suppliers SET 
                supplier_code = ?, supplier_name = ?, contact_person = ?, phone = ?, 
                email = ?, address = ?, tax_id = ?, payment_terms = ?, is_active = ?,
                updated_by = ?, updated_date = GETDATE()
            WHERE supplier_id = ?
        ");
        
        $stmt->execute([
            $supplier_code, $supplier_name, $contact_person, $phone, $email, $address,
            $tax_id, $payment_terms, $is_active, $user_id, $supplier_id
        ]);
        
        $message = "อัปเดตข้อมูลซัพพลายเออร์ '$supplier_name' เรียบร้อยแล้ว";
        $message_type = "success";
        
        // อัปเดตข้อมูลในตัวแปร supplier
        $supplier['supplier_code'] = $supplier_code;
        $supplier['supplier_name'] = $supplier_name;
        $supplier['contact_person'] = $contact_person;
        $supplier['phone'] = $phone;
        $supplier['email'] = $email;
        $supplier['address'] = $address;
        $supplier['tax_id'] = $tax_id;
        $supplier['payment_terms'] = $payment_terms;
        $supplier['is_active'] = $is_active;
        
    } catch (Exception $e) {
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
    <title>แก้ไขซัพพลายเออร์ - <?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
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
        
        .navbar {
            background: rgba(139, 69, 19, 0.9);
            box-shadow: 0 4px 20px rgba(139, 69, 19, 0.3);
        }
        
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(139, 69, 19, 0.15);
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(139, 69, 19, 0.1);
            margin-bottom: 25px;
        }
        
        .card-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 18px 18px 0 0 !important;
            border-bottom: none;
        }
        
        .form-control, .form-select, .form-control:focus, .form-select:focus {
            border-radius: 10px;
            border: 2px solid rgba(139, 69, 19, 0.2);
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(139, 69, 19, 0.25);
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: bold;
            transition: all 0.3s ease;
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-gradient-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(139, 69, 19, 0.3);
            color: white;
        }
        
        .btn-secondary {
            border-radius: 10px;
            padding: 12px 30px;
            border: 2px solid rgba(139, 69, 19, 0.2);
            background: rgba(255, 255, 255, 0.95);
            color: var(--primary-color);
            transition: all 0.3s ease;
            font-weight: bold;
        }
        
        .btn-secondary:hover {
            background: rgba(139, 69, 19, 0.1);
            border-color: var(--accent-color);
            color: var(--primary-color);
            transform: translateY(-2px);
        }

        .btn-outline-secondary {
            border-color: var(--accent-color);
            color: var(--accent-color);
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .btn-outline-secondary:hover {
            background: linear-gradient(135deg, var(--accent-color), var(--primary-color));
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
        }
        
        .alert {
            border-radius: 15px;
            border: none;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .alert-success {
            background: rgba(5, 150, 105, 0.1);
            color: #155724;
            border-left: 4px solid var(--success-color);
        }
        
        .alert-danger {
            background: rgba(220, 38, 38, 0.1);
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }
        
        .form-label {
            color: var(--primary-color);
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .required {
            color: var(--danger-color);
        }
        
        .text-muted {
            color: var(--accent-color) !important;
        }
        
        .fas {
            color: var(--secondary-color);
        }
        
        .navbar .fas, .card-header .fas {
            color: white;
        }
        
        .navbar-brand, .nav-link {
            color: white !important;
        }
        
        .nav-link:hover {
            color: #FFD700 !important;
            transform: translateY(-2px);
            transition: all 0.3s ease;
        }
        
        .form-text {
            color: var(--accent-color) !important;
        }
        
        .preview-box {
            background: linear-gradient(135deg, #F5DEB3, #DEB887);
            border: 2px dashed var(--accent-color);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .badge {
            padding: 8px 15px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .badge.bg-success {
            background: linear-gradient(45deg, var(--success-color), #10b981) !important;
        }
        
        .badge.bg-danger {
            background: linear-gradient(45deg, var(--danger-color), #ef4444) !important;
        }
        
        .form-check-input {
            border-radius: 8px;
            border: 2px solid rgba(139, 69, 19, 0.2);
            width: 1.5em;
            height: 1.5em;
        }
        
        .form-check-input:checked {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .form-check-label {
            color: var(--primary-color);
            font-weight: bold;
            margin-left: 10px;
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .text-primary {
            color: var(--primary-color) !important;
        }

        .container-fluid {
            max-width: 100%;
            padding: 0 15px;
            width: 100%;
            margin: 0;
        }

        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 20px;
        }

        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .breadcrumb-item a:hover {
            color: var(--secondary-color);
        }

        .breadcrumb-item.active {
            color: var(--accent-color);
            font-weight: 500;
        }

        .breadcrumb-item + .breadcrumb-item::before {
            color: var(--accent-color);
        }

        /* เพิ่ม CSS สำหรับ validation และ effects */
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: var(--danger-color);
            box-shadow: 0 0 0 0.2rem rgba(220, 38, 38, 0.25);
            animation: shake 0.5s ease-in-out;
        }
        
        .form-control.is-valid, .form-select.is-valid {
            border-color: var(--success-color);
            box-shadow: 0 0 0 0.2rem rgba(5, 150, 105, 0.25);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .form-check-input:focus {
            border-color: var(--secondary-color);
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(255, 140, 0, 0.25);
        }
        
        .table-sm td {
            color: var(--primary-color);
            padding: 8px;
        }
        
        .table-sm td strong {
            color: var(--primary-color);
        }
        
        /* Toast styling */
        .toast {
            border-radius: 15px;
        }
        
        .bg-success {
            background: linear-gradient(45deg, var(--success-color), #10b981) !important;
        }
        
        .bg-danger {
            background: linear-gradient(45deg, var(--danger-color), #ef4444) !important;
        }
        
        .bg-info {
            background: linear-gradient(45deg, var(--secondary-color), #ffb347) !important;
        }
</style>
</head>
<body>
    <!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="../dashboard.php">
            <i class="fas fa-boxes me-2"></i><?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?>
        </a>
        
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="index.php">
                <i class="fas fa-list me-1"></i> รายการซัพพลายเออร์
            </a>
            <a class="nav-link" href="../dashboard.php">
                <i class="fas fa-home me-1"></i> หน้าหลัก
            </a>
        </div>
    </div>
</nav>

<!-- Breadcrumb -->
<div class="container-fluid mt-3">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="../dashboard.php">หน้าหลัก</a></li>
            <li class="breadcrumb-item"><a href="index.php">รายการซัพพลายเออร์</a></li>
            <li class="breadcrumb-item"><a href="view.php?id=<?= $supplier['supplier_id'] ?>">รายละเอียด</a></li>
            <li class="breadcrumb-item active">แก้ไขซัพพลายเออร์</li>
        </ol>
    </nav>
</div>
        
        <!-- Header -->
        <div class="row fade-in">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-edit me-2"></i>แก้ไขซัพพลายเออร์
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <p class="text-muted mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    แก้ไขข้อมูลซัพพลายเออร์: <strong><?= htmlspecialchars($supplier['supplier_name']) ?></strong>
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <span class="badge <?= $supplier['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                                    <i class="fas fa-<?= $supplier['is_active'] ? 'check' : 'times' ?> me-1"></i>
                                    <?= $supplier['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
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
        <form method="POST" id="supplierForm" class="fade-in">
            <div class="row">
                <div class="col-lg-8">
                    <!-- ข้อมูลพื้นฐาน -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>ข้อมูลพื้นฐาน
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="supplier_code" class="form-label">
                                        รหัสซัพพลายเออร์ <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="supplier_code" name="supplier_code" 
                                           value="<?= htmlspecialchars($supplier['supplier_code']) ?>" 
                                           placeholder="เช่น SUP001, SUPPLIER_01"
                                           maxlength="20" required>
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        ความยาว 3-20 ตัวอักษร, ใช้ตัวอักษรและตัวเลขเท่านั้น
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="supplier_name" class="form-label">
                                        ชื่อซัพพลายเออร์ <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="supplier_name" name="supplier_name" 
                                           value="<?= htmlspecialchars($supplier['supplier_name']) ?>" 
                                           placeholder="เช่น บริษัท ABC จำกัด"
                                           maxlength="100" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contact_person" class="form-label">ผู้ติดต่อ</label>
                                    <input type="text" class="form-control" id="contact_person" name="contact_person" 
                                           value="<?= htmlspecialchars($supplier['contact_person']) ?>" 
                                           placeholder="เช่น คุณสมชาย ใจดี"
                                           maxlength="100">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">หมายเลขโทรศัพท์</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                           value="<?= htmlspecialchars($supplier['phone']) ?>" 
                                           placeholder="เช่น 02-123-4567, 081-234-5678"
                                           maxlength="20">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="email" class="form-label">อีเมล</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?= htmlspecialchars($supplier['email']) ?>" 
                                           placeholder="เช่น contact@company.com"
                                           maxlength="100">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ข้อมูลเพิ่มเติม -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-file-alt me-2"></i>ข้อมูลเพิ่มเติม
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="tax_id" class="form-label">เลขประจำตัวผู้เสียภาษี</label>
                                    <input type="text" class="form-control" id="tax_id" name="tax_id" 
                                           value="<?= htmlspecialchars($supplier['tax_id'] ?? '') ?>" 
                                           placeholder="เช่น 0123456789012"
                                           maxlength="13">
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        13 หลัก สำหรับบริษัทจดทะเบียน
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="payment_terms" class="form-label">เงื่อนไขการชำระเงิน</label>
                                    <select class="form-select" id="payment_terms" name="payment_terms">
                                        <option value="">เลือกเงื่อนไขการชำระเงิน</option>
                                        <option value="Cash" <?= ($supplier['payment_terms'] ?? '') == 'Cash' ? 'selected' : '' ?>>เงินสด</option>
                                        <option value="15 Days" <?= ($supplier['payment_terms'] ?? '') == '15 Days' ? 'selected' : '' ?>>15 วัน</option>
                                        <option value="30 Days" <?= ($supplier['payment_terms'] ?? '') == '30 Days' ? 'selected' : '' ?>>30 วัน</option>
                                        <option value="45 Days" <?= ($supplier['payment_terms'] ?? '') == '45 Days' ? 'selected' : '' ?>>45 วัน</option>
                                        <option value="60 Days" <?= ($supplier['payment_terms'] ?? '') == '60 Days' ? 'selected' : '' ?>>60 วัน</option>
                                        <option value="90 Days" <?= ($supplier['payment_terms'] ?? '') == '90 Days' ? 'selected' : '' ?>>90 วัน</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="address" class="form-label">ที่อยู่</label>
                                <textarea class="form-control" id="address" name="address" rows="3" 
                                          placeholder="เช่น 123 ถนนสุขุมวิท แขวงคลองตัน เขตคลองตัน กรุงเทพมหานคร 10110"
                                          maxlength="500"><?= htmlspecialchars($supplier['address'] ?? '') ?></textarea>
                                <div class="form-text">
                                    <i class="fas fa-map-marker-alt me-1"></i>
                                    ที่อยู่สำหรับการจัดส่งและติดต่อ
                                </div>
                            </div>
                            
                            <!-- สถานะการใช้งาน -->
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                               <?= $supplier['is_active'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_active">
                                            เปิดใช้งานซัพพลายเออร์นี้
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        <i class="fas fa-toggle-on me-1"></i>
                                        หากปิดการใช้งาน ซัพพลายเออร์นี้จะไม่ปรากฏในรายการเลือก
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Preview Card -->
                    <div class="card fade-in">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-eye me-2"></i>ตัวอย่างข้อมูล
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="preview-box">
                                <h6><i class="fas fa-truck me-2"></i>ซัพพลายเออร์</h6>
                                <div id="previewCode" class="fw-bold text-primary">
                                    <span id="codePreview"><?= htmlspecialchars($supplier['supplier_code']) ?></span>
                                </div>
                                <div id="previewName" class="fw-bold">
                                    <span id="namePreview"><?= htmlspecialchars($supplier['supplier_name']) ?></span>
                                </div>
                                <small class="text-muted">
                                    <span id="contactPreview"><?= htmlspecialchars($supplier['contact_person'] ?? 'ผู้ติดต่อ') ?></span>
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Info Card -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>ข้อมูลระบบ
                            </h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>รหัส ID:</strong></td>
                                    <td><?= $supplier['supplier_id'] ?></td>
                                </tr>
                                <tr>
                                    <td><strong>สร้างเมื่อ:</strong></td>
                                    <td><?= date('d/m/Y H:i', strtotime($supplier['created_date'])) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>สร้างโดย:</strong></td>
                                    <td>User ID: <?= $supplier['created_by'] ?></td>
                                </tr>
                                <tr>
                                    <td><strong>สถานะ:</strong></td>
                                    <td>
                                        <span class="badge <?= $supplier['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                                            <?= $supplier['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="card">
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>บันทึกการแก้ไข
                                </button>
                                <a href="view.php?id=<?= $supplier['supplier_id'] ?>" class="btn btn-secondary">
                                    <i class="fas fa-eye me-2"></i>ดูรายละเอียด
                                </a>
                                <a href="index.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>กลับไปรายการ
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
        // อัปเดตตัวอย่างข้อมูลแบบเรียลไทม์
        function updatePreview() {
            const code = document.getElementById('supplier_code').value || 'รหัสซัพพลายเออร์';
            const name = document.getElementById('supplier_name').value || 'ชื่อซัพพลายเออร์';
            const contact = document.getElementById('contact_person').value || 'ผู้ติดต่อ';
            
            document.getElementById('codePreview').textContent = code;
            document.getElementById('namePreview').textContent = name;
            document.getElementById('contactPreview').textContent = contact;
        }

        // เพิ่ม Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            // อัปเดตตัวอย่างเมื่อพิมพ์
            const previewFields = ['supplier_code', 'supplier_name', 'contact_person'];
            previewFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('input', updatePreview);
                }
            });

            // ตรวจสอบรูปแบบรหัสซัพพลายเออร์
            const supplierCodeField = document.getElementById('supplier_code');
            supplierCodeField.addEventListener('input', function() {
                const value = this.value.toUpperCase();
                this.value = value.replace(/[^A-Z0-9]/g, ''); // อนุญาตเฉพาะตัวอักษรและตัวเลข
                
                if (value.length < 3) {
                    this.setCustomValidity('รหัสซัพพลายเออร์ต้องมีอย่างน้อย 3 ตัวอักษร');
                } else {
                    this.setCustomValidity('');
                }
            });

            // ตรวจสอบเบอร์โทร
            const phoneField = document.getElementById('phone');
            phoneField.addEventListener('input', function() {
                const value = this.value.replace(/[^\d\-\+\(\)\ ]/g, ''); // อนุญาตเฉพาะตัวเลขและสัญลักษณ์
                this.value = value;
            });

            // ตรวจสอบเลขประจำตัวผู้เสียภาษี
            const taxIdField = document.getElementById('tax_id');
            taxIdField.addEventListener('input', function() {
                const value = this.value.replace(/\D/g, ''); // อนุญาตเฉพาะตัวเลข
                this.value = value;
                
                if (value.length > 0 && value.length !== 13) {
                    this.setCustomValidity('เลขประจำตัวผู้เสียภาษีต้องมี 13 หลัก');
                } else {
                    this.setCustomValidity('');
                }
            });

            // เพิ่มเอฟเฟกต์ fade in
            const elements = document.querySelectorAll('.fade-in');
            elements.forEach((element, index) => {
                setTimeout(() => {
                    element.style.opacity = '0';
                    element.style.transform = 'translateY(20px)';
                    element.style.transition = 'all 0.6s ease';
                    
                    setTimeout(() => {
                        element.style.opacity = '1';
                        element.style.transform = 'translateY(0)';
                    }, 100);
                }, index * 100);
            });
        });

        // ตรวจสอบฟอร์มก่อนส่ง
        document.getElementById('supplierForm').addEventListener('submit', function(e) {
            const supplierCode = document.getElementById('supplier_code').value.trim();
            const supplierName = document.getElementById('supplier_name').value.trim();
            
            if (!supplierCode || !supplierName) {
                e.preventDefault();
                alert('กรุณากรอกรหัสซัพพลายเออร์และชื่อซัพพลายเออร์');
                return false;
            }
            
            if (supplierCode.length < 3) {
                e.preventDefault();
                alert('รหัสซัพพลายเออร์ต้องมีอย่างน้อย 3 ตัวอักษร');
                return false;
            }

            // ยืนยันการแก้ไข
            if (!confirm('คุณต้องการบันทึกการแก้ไขนี้ใช่หรือไม่?')) {
                e.preventDefault();
                return false;
            }

            // แสดงสถานะการโหลด
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>กำลังบันทึก...';
            
            // คืนค่าปุ่มหากเกิดข้อผิดพลาด (หลังจาก 10 วินาที)
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }, 10000);
        });

        // Auto-dismiss alerts หลัง 5 วินาที
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                if (alert.classList.contains('show')) {
                    alert.classList.remove('show');
                    setTimeout(() => alert.remove(), 300);
                }
            });
        }, 5000);

        // เพิ่มการ validate แบบเรียลไทม์
        function addRealTimeValidation() {
            const requiredFields = document.querySelectorAll('input[required]');
            
            requiredFields.forEach(field => {
                field.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        this.classList.add('is-invalid');
                    } else {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    }
                });
                
                field.addEventListener('input', function() {
                    if (this.classList.contains('is-invalid') && this.value.trim()) {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    }
                });
            });
        }

        // เรียกใช้ validation เมื่อโหลดหน้า
        document.addEventListener('DOMContentLoaded', addRealTimeValidation);

        // แสดงการเปลี่ยนแปลงสถานะ
        document.getElementById('is_active').addEventListener('change', function() {
            const statusText = this.checked ? 'เปิดใช้งาน' : 'ปิดใช้งาน';
            const statusColor = this.checked ? 'success' : 'danger';
            
            // แสดง Toast notification
            showToast(`เปลี่ยนสถานะเป็น: ${statusText}`, statusColor);
        });

        // ฟังก์ชันแสดง Toast
        function showToast(message, type = 'info') {
            const toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            toastContainer.style.zIndex = '1055';
            
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type} border-0`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-info-circle me-2"></i>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            document.body.appendChild(toastContainer);
            
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();
            
            // ลบหลังจากหายไป
            toast.addEventListener('hidden.bs.toast', () => {
                toastContainer.remove();
            });
        }
    </script>

    <style>
        /* เพิ่ม CSS สำหรับ validation และ effects */
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
            animation: shake 0.5s ease-in-out;
        }
        
        .form-control.is-valid, .form-select.is-valid {
            border-color: #ff6b35;
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .form-check-input:focus {
            border-color: #ff9a56;
            outline: 0;
            box-shadow: 0 0 0 0.25rem rgba(255, 154, 86, 0.25);
        }
        
        .table-sm td {
            color: #ff7f50;
            padding: 8px;
        }
        
        .table-sm td strong {
            color: #e55a2b;
        }
        
        /* Toast styling */
        .toast {
            border-radius: 15px;
        }
        
        .bg-success {
            background: linear-gradient(45deg, #ff6b35, #ff8c42) !important;
        }
        
        .bg-danger {
            background: linear-gradient(45deg, #ff6b6b, #ff8e8e) !important;
        }
        
        .bg-info {
            background: linear-gradient(45deg, #ffa726, #ffcc80) !important;
        }
    </style>
</body>
</html>