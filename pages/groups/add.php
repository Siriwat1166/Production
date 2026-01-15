<?php
// pages/groups/add.php - เพิ่มกลุ่มวัสดุใหม่
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
        $group_id = trim($_POST['group_id'] ?? '');
        $group_name = trim($_POST['group_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        // ตรวจสอบข้อมูลที่จำเป็น
        if (empty($group_id) || empty($group_name)) {
            throw new Exception("กรุณากรอกรหัสกลุ่มและชื่อกลุ่ม");
        }
        
        // ตรวจสอบรูปแบบรหัสกลุ่ม (ต้องเป็นตัวเลข 3 หลัก หรือ 255)
        if (!preg_match('/^(255|00[1-9]|0[1-9][0-9]|[1-9][0-9]{2})$/', $group_id)) {
            throw new Exception("รหัสกลุ่มต้องเป็นตัวเลข 3 หลัก (001-999) หรือ 255");
        }
        
        // เชื่อมต่อฐานข้อมูล
        if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
            require_once "../../config/database.php";
            $database = new Database();
            $conn = $database->getConnection();
            
            // ตรวจสอบรหัสกลุ่มซ้ำ
            $checkStmt = $conn->prepare("SELECT COUNT(*) as count_result FROM Groups WHERE id = ?");
            $checkStmt->execute([$group_id]);
            $checkResult = $checkStmt->fetch();
            // ตรวจสอบรหัสกลุ่มซ้ำ
            $checkStmt = $conn->prepare("SELECT COUNT(*) as count_result FROM Groups WHERE id = ?");
            $checkStmt->execute([$group_id]);
            $checkResult = $checkStmt->fetch();
            if ($checkResult['count_result'] > 0) {
                throw new Exception("รหัสกลุ่ม '$group_id' มีอยู่แล้วในระบบ");
            }
            
            // ตรวจสอบชื่อกลุ่มซ้ำ
            $checkNameStmt = $conn->prepare("SELECT COUNT(*) as count_result FROM Groups WHERE name = ?");
            $checkNameStmt->execute([$group_name]);
            $checkNameResult = $checkNameStmt->fetch();
            if ($checkNameResult['count_result'] > 0) {
                throw new Exception("ชื่อกลุ่ม '$group_name' มีอยู่แล้วในระบบ");
            }
            
            // เพิ่มข้อมูลใหม่
            $stmt = $conn->prepare("
                INSERT INTO Groups (id, name, description, is_active, created_by, created_date) 
                VALUES (?, ?, ?, 1, ?, GETDATE())
            ");
            
            $stmt->execute([$group_id, $group_name, $description, $user_id]);
            
            $message = "เพิ่มกลุ่มวัสดุ '$group_name' เรียบร้อยแล้ว";
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
    <title>เพิ่มกลุ่มวัสดุใหม่ - <?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?></title>
    
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
        
        .group-example {
            background: rgba(255, 154, 86, 0.1);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #ff9a56;
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
                    <i class="fas fa-list me-1"></i> รายการกลุ่มวัสดุ
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
                            <i class="fas fa-plus-circle me-2"></i>เพิ่มกลุ่มวัสดุใหม่
                        </h4>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            สร้างกลุ่มวัสดุใหม่สำหรับจัดหมวดหมู่วัสดุต่างๆ ข้อมูลที่มี <span class="required">*</span> จำเป็นต้องกรอก
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
        <form method="POST" id="groupForm" class="fade-in">
            <div class="row">
                <div class="col-lg-8">
                    <!-- ข้อมูลพื้นฐาน -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>ข้อมูลกลุ่มวัสดุ
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="group_id" class="form-label">
                                        รหัสกลุ่ม <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="group_id" name="group_id" 
                                           value="<?= htmlspecialchars($_POST['group_id'] ?? '') ?>" 
                                           placeholder="เช่น 001, 002, 255"
                                           maxlength="3" required>
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        ตัวเลข 3 หลัก (001-999) หรือ 255 สำหรับ Paperboard
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="group_name" class="form-label">
                                        ชื่อกลุ่ม <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="group_name" name="group_name" 
                                           value="<?= htmlspecialchars($_POST['group_name'] ?? '') ?>" 
                                           placeholder="เช่น Ink, Coating, Adhesive"
                                           maxlength="50" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">คำอธิบาย</label>
                                <textarea class="form-control" id="description" name="description" rows="3" 
                                          placeholder="เช่น หมึกสำหรับการพิมพ์ต่างๆ"
                                          maxlength="500"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    อธิบายประเภทของวัสดุในกลุ่มนี้
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
                                <i class="fas fa-eye me-2"></i>ตัวอย่างกลุ่ม
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="preview-box">
                                <h6><i class="fas fa-layer-group me-2"></i>กลุ่มวัสดุ</h6>
                                <div id="previewId" class="fw-bold text-primary">
                                    <span id="idPreview">รหัสกลุ่ม</span>
                                </div>
                                <div id="previewName" class="fw-bold">
                                    <span id="namePreview">ชื่อกลุ่ม</span>
                                </div>
                                <small class="text-muted">
                                    <span id="descPreview">คำอธิบาย</span>
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Examples Card -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-lightbulb me-2"></i>ตัวอย่างกลุ่มวัสดุ
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="group-example">
                                <strong>255 - Paperboard</strong><br>
                                <small>กระดาษและแผ่นกระดาษต่างๆ</small>
                            </div>
                            
                            <div class="group-example">
                                <strong>001 - Ink</strong><br>
                                <small>หมึกพิมพ์และสีต่างๆ</small>
                            </div>
                            
                            <div class="group-example">
                                <strong>002 - Coating</strong><br>
                                <small>วัสดุเคลือบผิว</small>
                            </div>
                            
                            <div class="group-example">
                                <strong>003 - Adhesive</strong><br>
                                <small>กาวและวัสดุยึดติด</small>
                            </div>
                            
                            <div class="alert alert-info mt-3">
                                <h6><i class="fas fa-info-circle me-2"></i>หมายเหตุ</h6>
                                <ul class="mb-0 small">
                                    <li>รหัส 255 สงวนไว้สำหรับ Paperboard</li>
                                    <li>รหัส 001-999 สำหรับกลุ่มอื่นๆ</li>
                                    <li>ชื่อกลุ่มควรเป็นภาษาอังกฤษ</li>
                                    <li>คำอธิบายสามารถเป็นภาษาไทยได้</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="card">
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>บันทึกกลุ่มวัสดุ
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
            const id = document.getElementById('group_id').value || 'รหัสกลุ่ม';
            const name = document.getElementById('group_name').value || 'ชื่อกลุ่ม';
            const desc = document.getElementById('description').value || 'คำอธิบาย';
            
            document.getElementById('idPreview').textContent = id;
            document.getElementById('namePreview').textContent = name;
            document.getElementById('descPreview').textContent = desc;
        }

        // เพิ่ม Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            // อัปเดตตัวอย่างเมื่อพิมพ์
            const previewFields = ['group_id', 'group_name', 'description'];
            previewFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('input', updatePreview);
                }
            });

            // ตั้งค่าเริ่มต้นสำหรับตัวอย่าง
            updatePreview();

            // ตรวจสอบรูปแบบรหัสกลุ่ม
            const groupIdField = document.getElementById('group_id');
            groupIdField.addEventListener('input', function() {
                let value = this.value.replace(/\D/g, ''); // อนุญาตเฉพาะตัวเลข
                
                // ตัดให้เหลือ 3 หลัก
                if (value.length > 3) {
                    value = value.substring(0, 3);
                }
                
                // เติม 0 นำหน้าถ้าจำเป็น (ยกเว้น 255)
                if (value.length > 0 && value !== '255') {
                    value = value.padStart(3, '0');
                }
                
                this.value = value;
                
                // ตรวจสอบความถูกต้อง
                if (value.length === 3) {
                    const num = parseInt(value);
                    if (num === 255 || (num >= 1 && num <= 999)) {
                        this.setCustomValidity('');
                    } else {
                        this.setCustomValidity('รหัสกลุ่มต้องเป็น 001-999 หรือ 255');
                    }
                } else if (value.length > 0) {
                    this.setCustomValidity('รหัสกลุ่มต้องเป็นตัวเลข 3 หลัก');
                }
            });

            // ตรวจสอบชื่อกลุ่ม
            const groupNameField = document.getElementById('group_name');
            groupNameField.addEventListener('input', function() {
                // แปลงตัวแรกเป็นตัวใหญ่
                let value = this.value;
                if (value.length > 0) {
                    value = value.charAt(0).toUpperCase() + value.slice(1);
                    this.value = value;
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
                document.getElementById('groupForm').reset();
                updatePreview();
                
                // เล่นเอฟเฟกต์
                const form = document.getElementById('groupForm');
                form.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    form.style.transform = 'scale(1)';
                }, 150);
            }
        }

        // ตรวจสอบฟอร์มก่อนส่ง
        document.getElementById('groupForm').addEventListener('submit', function(e) {
            const groupId = document.getElementById('group_id').value.trim();
            const groupName = document.getElementById('group_name').value.trim();
            
            if (!groupId || !groupName) {
                e.preventDefault();
                alert('กรุณากรอกรหัสกลุ่มและชื่อกลุ่ม');
                return false;
            }
            
            // ตรวจสอบรูปแบบรหัส
            const num = parseInt(groupId);
            if (groupId.length !== 3 || (num !== 255 && (num < 1 || num > 999))) {
                e.preventDefault();
                alert('รหัสกลุ่มต้องเป็นตัวเลข 3 หลัก (001-999) หรือ 255');
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
    </script>
</body>
</html>