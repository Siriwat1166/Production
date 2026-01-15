<?php
// pages/material_types/add.php - เพิ่มประเภทวัสดุใหม่
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
        // รับข้อมูลจากฟอร์ม
        $type_code = trim($_POST['type_code'] ?? '');
        $type_name = trim($_POST['type_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        // ตรวจสอบข้อมูลที่จำเป็น
        if (empty($type_code) || empty($type_name)) {
            throw new Exception("กรุณากรอกรหัสประเภทและชื่อประเภทวัสดุ");
        }
        
        // ตรวจสอบความยาวรหัส
        if (strlen($type_code) < 2 || strlen($type_code) > 10) {
            throw new Exception("รหัสประเภทต้องมีความยาว 2-10 ตัวอักษร");
        }
        
        // เชื่อมต่อฐานข้อมูล
        if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
            require_once "../../config/database.php";
            $database = new Database();
            $conn = $database->getConnection();
            
            // ตรวจสอบรหัสประเภทซ้ำ
            $checkStmt = $conn->prepare("SELECT COUNT(*) as count_result FROM Material_Types WHERE type_code = ?");
            $checkStmt->execute([$type_code]);
            $checkResult = $checkStmt->fetch();
            if ($checkResult['count_result'] > 0) {
                throw new Exception("รหัสประเภท '$type_code' มีอยู่แล้วในระบบ");
            }
            
            // ตรวจสอบชื่อประเภทซ้ำ
            $checkNameStmt = $conn->prepare("SELECT COUNT(*) as count_result FROM Material_Types WHERE type_name = ?");
            $checkNameStmt->execute([$type_name]);
            $checkNameResult = $checkNameStmt->fetch();
            if ($checkNameResult['count_result'] > 0) {
                throw new Exception("ชื่อประเภท '$type_name' มีอยู่แล้วในระบบ");
            }
            
            // เพิ่มข้อมูลใหม่
            $stmt = $conn->prepare("
                INSERT INTO Material_Types (
                    type_code, type_name, description, is_active, created_by, created_date
                ) VALUES (?, ?, ?, 1, ?, GETDATE())
            ");
            
            $stmt->execute([
                $type_code, $type_name, $description, $user_id
            ]);
            
            $message = "เพิ่มประเภทวัสดุ '$type_name' เรียบร้อยแล้ว";
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
    <title>เพิ่มประเภทวัสดุใหม่ - <?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?></title>
    
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
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: #ffe4d1;
            border-color: #ff9a56;
            color: #ff7f50;
            transform: translateY(-2px);
        }
        
        .alert {
            border-radius: 15px;
            border: none;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .alert-success {
            background: rgba(255, 107, 53, 0.1);
            color: #ff6b35;
            border-left: 4px solid #ff6b35;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border-left: 4px solid #dc3545;
        }
        
        .form-label {
            color: #ff7f50;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .required {
            color: #ff6b6b;
        }
        
        .text-muted {
            color: #d2691e !important;
        }
        
        .fas {
            color: #ff9a56;
        }
        
        .navbar .fas, .card-header .fas {
            color: white;
        }
        
        .navbar-brand, .nav-link {
            color: white !important;
        }
        
        .nav-link:hover {
            color: #ffe4d1 !important;
        }
        
        .form-text {
            color: #ffa726 !important;
        }
        
        .preview-box {
            background: linear-gradient(135deg, #fff3e0 0%, #ffe4d1 100%);
            border: 2px dashed #ff9a56;
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
        
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        
        .form-control.is-valid, .form-select.is-valid {
            border-color: #ff6b35;
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../dashboard.php">
                <i class="fas fa-boxes me-2"></i><?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?>
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php">
                    <i class="fas fa-list me-1"></i> รายการประเภทวัสดุ
                </a>
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-home me-1"></i> หน้าหลัก
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-4" style="padding-top: 20px;">
        
        <!-- Header -->
        <div class="row fade-in">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-plus-circle me-2"></i>เพิ่มประเภทวัสดุใหม่
                        </h4>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            กรอกข้อมูลประเภทวัสดุใหม่ ข้อมูลที่มี <span class="required">*</span> จำเป็นต้องกรอก
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
        <form method="POST" id="materialTypeForm" class="fade-in">
            <div class="row">
                <div class="col-lg-8">
                    <!-- ข้อมูลพื้นฐาน -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>ข้อมูลประเภทวัสดุ
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="type_code" class="form-label">
                                        รหัสประเภท <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="type_code" name="type_code" 
                                           value="<?= htmlspecialchars($_POST['type_code'] ?? '') ?>" 
                                           placeholder="เช่น PAPER, TOOL, CHEMICAL"
                                           maxlength="10" required>
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        ความยาว 2-10 ตัวอักษร, ใช้ตัวอักษรภาษาอังกฤษเท่านั้น
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="type_name" class="form-label">
                                        ชื่อประเภท <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="type_name" name="type_name" 
                                           value="<?= htmlspecialchars($_POST['type_name'] ?? '') ?>" 
                                           placeholder="เช่น กระดาษ, เครื่องมือ, สารเคมี"
                                           maxlength="50" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="description" class="form-label">รายละเอียด</label>
                                    <textarea class="form-control" id="description" name="description" rows="3" 
                                              placeholder="อธิบายรายละเอียดของประเภทวัสดุนี้..."
                                              maxlength="500"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        อธิบายลักษณะและการใช้งานของประเภทวัสดุ
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
                                <h6><i class="fas fa-layer-group me-2"></i>ประเภทวัสดุ</h6>
                                <div id="previewCode" class="fw-bold text-primary">
                                    <span id="codePreview">รหัสประเภท</span>
                                </div>
                                <div id="previewName" class="fw-bold">
                                    <span id="namePreview">ชื่อประเภท</span>
                                </div>
                                <small class="text-muted">
                                    <span id="descPreview">รายละเอียด</span>
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
                                <h6><i class="fas fa-info-circle me-2"></i>การตั้งรหัสประเภท</h6>
                                <ul class="mb-2 small">
                                    <li><strong>PAPER:</strong> กระดาษทุกชนิด</li>
                                    <li><strong>TOOL:</strong> เครื่องมือและอุปกรณ์</li>
                                    <li><strong>CHEMICAL:</strong> สารเคมี</li>
                                    <li><strong>OFFICE:</strong> อุปกรณ์สำนักงาน</li>
                                </ul>
                            </div>
                            
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-triangle me-2"></i>ข้อควรระวัง</h6>
                                <ul class="mb-0 small">
                                    <li>ใช้ตัวอักษรภาษาอังกฤษเท่านั้น</li>
                                    <li>ไม่ควรใช้สัญลักษณ์พิเศษ</li>
                                    <li>ตรวจสอบรหัสซ้ำก่อนบันทึก</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="card">
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>บันทึกประเภทวัสดุ
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
            const code = document.getElementById('type_code').value || 'รหัสประเภท';
            const name = document.getElementById('type_name').value || 'ชื่อประเภท';
            const desc = document.getElementById('description').value || 'รายละเอียด';
            
            document.getElementById('codePreview').textContent = code;
            document.getElementById('namePreview').textContent = name;
            document.getElementById('descPreview').textContent = desc;
        }

        // เพิ่ม Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            // อัปเดตตัวอย่างเมื่อพิมพ์
            const previewFields = ['type_code', 'type_name', 'description'];
            previewFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('input', updatePreview);
                }
            });

            // ตั้งค่าเริ่มต้นสำหรับตัวอย่าง
            updatePreview();

            // ตรวจสอบรูปแบบรหัสประเภท
            const typeCodeField = document.getElementById('type_code');
            typeCodeField.addEventListener('input', function() {
                const value = this.value.toUpperCase();
                this.value = value.replace(/[^A-Z0-9]/g, ''); // อนุญาตเฉพาะตัวอักษรและตัวเลข
                
                if (value.length < 2) {
                    this.setCustomValidity('รหัสประเภทต้องมีอย่างน้อย 2 ตัวอักษร');
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
                document.getElementById('materialTypeForm').reset();
                updatePreview();
                
                // เล่นเอฟเฟกต์
                const form = document.getElementById('materialTypeForm');
                form.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    form.style.transform = 'scale(1)';
                }, 150);
            }
        }

        // ตรวจสอบฟอร์มก่อนส่ง
        document.getElementById('materialTypeForm').addEventListener('submit', function(e) {
            const typeCode = document.getElementById('type_code').value.trim();
            const typeName = document.getElementById('type_name').value.trim();
            
            if (!typeCode || !typeName) {
                e.preventDefault();
                alert('กรุณากรอกรหัสประเภทและชื่อประเภทวัสดุ');
                return false;
            }
            
            if (typeCode.length < 2) {
                e.preventDefault();
                alert('รหัสประเภทต้องมีอย่างน้อย 2 ตัวอักษร');
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