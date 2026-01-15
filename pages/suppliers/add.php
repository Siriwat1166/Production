<?php
// pages/suppliers/add.php - เพิ่มซัพพลายเออร์ใหม่ (แก้ไขให้ตรงกับ Database)
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

// ประมวลผลการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // รับข้อมูลจากฟอร์ม (ตรงกับ Database Schema)
        $supplier_code = trim($_POST['supplier_code'] ?? '');
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $tax_id = trim($_POST['tax_id'] ?? '');
        $payment_terms = trim($_POST['payment_terms'] ?? '');
        
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
        
        // เชื่อมต่อฐานข้อมูล
        if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
            require_once "../../config/database.php";
            $database = new Database();
            $conn = $database->getConnection();
            
            // ตรวจสอบรหัสซัพพลายเออร์ซ้ำ
            $checkStmt = $conn->prepare("SELECT COUNT(*) as count_result FROM Suppliers WHERE supplier_code = ?");
            $checkStmt->execute([$supplier_code]);
            $checkResult = $checkStmt->fetch();
            if ($checkResult['count_result'] > 0) {
                throw new Exception("รหัสซัพพลายเออร์ '$supplier_code' มีอยู่แล้วในระบบ");
            }
            
            // ตรวจสอบชื่อซัพพลายเออร์ซ้ำ
            $checkNameStmt = $conn->prepare("SELECT COUNT(*) as count_result FROM Suppliers WHERE supplier_name = ?");
            $checkNameStmt->execute([$supplier_name]);
            $checkNameResult = $checkNameStmt->fetch();
            if ($checkNameResult['count_result'] > 0) {
                throw new Exception("ชื่อซัพพลายเออร์ '$supplier_name' มีอยู่แล้วในระบบ");
            }
            
            // เพิ่มข้อมูลใหม่ (ตรงกับ Database Schema)
            $stmt = $conn->prepare("
                INSERT INTO Suppliers (
                    supplier_code, supplier_name, contact_person, phone, email, address, 
                    tax_id, payment_terms, is_active, created_by, created_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, GETDATE())
            ");
            
            $stmt->execute([
                $supplier_code, $supplier_name, $contact_person, $phone, $email, $address,
                $tax_id, $payment_terms, $user_id
            ]);
            
            $message = "เพิ่มซัพพลายเออร์ '$supplier_name' เรียบร้อยแล้ว";
            $message_type = "success";
            
            // ล้างฟอร์ม
            $_POST = [];
            
            // เปลี่ยนเส้นทางไปหน้ารายการหลังจาก 2 วินาที
            header("refresh:2;url=index.php?message=" . urlencode($message) . "&type=success");
            
        } else {
            throw new Exception("ไม่สามารถเชื่อมต่อฐานข้อมูลได้ - กรุณาตรวจสอบการตั้งค่า");
        }
        
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
    <title>เพิ่มซัพพลายเออร์ใหม่ - <?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?></title>
    
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

        .alert-info {
            background: rgba(139, 69, 19, 0.1);
            color: var(--primary-color);
            border-left: 4px solid var(--accent-color);
        }

        .alert-warning {
            background: rgba(217, 119, 6, 0.1);
            color: #856404;
            border-left: 4px solid var(--warning-color);
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
        
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Validation Styles */
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: var(--danger-color);
            box-shadow: 0 0 0 0.2rem rgba(220, 38, 38, 0.25);
        }
        
        .form-control.is-valid, .form-select.is-valid {
            border-color: var(--success-color);
            box-shadow: 0 0 0 0.2rem rgba(5, 150, 105, 0.25);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .container-fluid {
            max-width: 100%;
            padding: 0 15px;
            width: 100%;
            margin: 0;
        }

        .text-primary {
            color: var(--primary-color) !important;
        }

        .btn-outline-primary {
            border-color: var(--primary-color);
            color: var(--primary-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: transparent;
            color: white;
            transform: translateX(-2px);
            box-shadow: 0 4px 12px rgba(139, 69, 19, 0.3);
        }

        .breadcrumb {
            background: none;
            padding: 0;
            margin: 0;
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
            <li class="breadcrumb-item active">เพิ่มซัพพลายเออร์ใหม่</li>
        </ol>
    </nav>
</div>
    <!-- Main Content -->
    <div class="container-fluid mt-4" style="padding-top: 20px;">
        
        <!-- Header -->
        <div class="row fade-in">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-plus-circle me-2"></i>เพิ่มซัพพลายเออร์ใหม่
                        </h4>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            กรอกข้อมูลซัพพลายเออร์ใหม่ ข้อมูลที่มี <span class="required">*</span> จำเป็นต้องกรอก
                        </p>
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
                                           value="<?= htmlspecialchars($_POST['supplier_code'] ?? '') ?>" 
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
                                           value="<?= htmlspecialchars($_POST['supplier_name'] ?? '') ?>" 
                                           placeholder="เช่น บริษัท ABC จำกัด"
                                           maxlength="100" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="contact_person" class="form-label">ผู้ติดต่อ</label>
                                    <input type="text" class="form-control" id="contact_person" name="contact_person" 
                                           value="<?= htmlspecialchars($_POST['contact_person'] ?? '') ?>" 
                                           placeholder="เช่น คุณสันดร ใจดี"
                                           maxlength="100">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">หมายเลขโทรศัพท์</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" 
                                           placeholder="เช่น 02-123-4567, 081-234-5678"
                                           maxlength="20">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="email" class="form-label">อีเมล</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" 
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
                                           value="<?= htmlspecialchars($_POST['tax_id'] ?? '') ?>" 
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
                                        <option value="Cash" <?= ($_POST['payment_terms'] ?? '') == 'Cash' ? 'selected' : '' ?>>เงินสด</option>
                                        <option value="15 Days" <?= ($_POST['payment_terms'] ?? '') == '15 Days' ? 'selected' : '' ?>>15 วัน</option>
                                        <option value="30 Days" <?= ($_POST['payment_terms'] ?? '') == '30 Days' ? 'selected' : '' ?>>30 วัน</option>
                                        <option value="45 Days" <?= ($_POST['payment_terms'] ?? '') == '45 Days' ? 'selected' : '' ?>>45 วัน</option>
                                        <option value="60 Days" <?= ($_POST['payment_terms'] ?? '') == '60 Days' ? 'selected' : '' ?>>60 วัน</option>
                                        <option value="90 Days" <?= ($_POST['payment_terms'] ?? '') == '90 Days' ? 'selected' : '' ?>>90 วัน</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="address" class="form-label">ที่อยู่</label>
                                <textarea class="form-control" id="address" name="address" rows="3" 
                                          placeholder="เช่น 123 ถนนสุขุมวิท แขวงคลองตัน เขตคลองตัน กรุงเทพมหานคร 10110"
                                          maxlength="500"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                                <div class="form-text">
                                    <i class="fas fa-map-marker-alt me-1"></i>
                                    ที่อยู่สำหรับการจัดส่งและติดต่อ
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
                                    <span id="codePreview">รหัสซัพพลายเออร์</span>
                                </div>
                                <div id="previewName" class="fw-bold">
                                    <span id="namePreview">ชื่อซัพพลายเออร์</span>
                                </div>
                                <small class="text-muted">
                                    <span id="contactPreview">ผู้ติดต่อ</span>
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tips Card -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-lightbulb me-2"></i>คำแนะนำ
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle me-2"></i>การตั้งรหัสซัพพลายเออร์</h6>
                                <ul class="mb-2 small">
                                    <li><strong>SUP001, SUP002:</strong> แบบต่อเนื่อง</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-triangle me-2"></i>ข้อควรระวัง</h6>
                                <ul class="mb-0 small">
                                    <li>ตรวจสอบรหัสซ้ำก่อนบันทึก</li>
                                    <li>ใส่ข้อมูลติดต่อให้ครบถ้วน</li>
                                    <li>ตรวจสอบอีเมลและเบอร์โทร</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="card">
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>บันทึกซัพพลายเออร์
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                    <i class="fas fa-redo me-2"></i>รีเซ็ตฟอร์ม
                                </button>
                                <a href="index.php" class="btn btn-outline-secondary">
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

            // ตั้งค่าเริ่มต้นสำหรับตัวอย่าง
            updatePreview();

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

        // ฟังก์ชันรีเซ็ตฟอร์ม
        function resetForm() {
            if (confirm('คุณต้องการล้างข้อมูลทั้งหมดใช่หรือไม่?')) {
                document.getElementById('supplierForm').reset();
                updatePreview();
                
                // เล่นเอฟเฟกต์
                const form = document.getElementById('supplierForm');
                form.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    form.style.transform = 'scale(1)';
                }, 150);
            }
        }

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
    </script>
</body>
</html>