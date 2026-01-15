<?php
// pages/groups/edit.php - แก้ไขกลุ่มวัสดุ
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
$group = null;
$group_id = $_GET['id'] ?? '';

// ตรวจสอบ ID
if (empty($group_id)) {
    header("Location: index.php?message=" . urlencode("ไม่พบรหัสกลุ่มวัสดุ") . "&type=danger");
    exit;
}

// เชื่อมต่อฐานข้อมูลและดึงข้อมูลกลุ่ม
try {
    if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
        require_once "../../config/database.php";
        $database = new Database();
        $conn = $database->getConnection();
        
        // ดึงข้อมูลกลุ่มวัสดุ
        $stmt = $conn->prepare("
            SELECT id, name, description, is_active, created_date, created_by, updated_date, updated_by
            FROM Groups 
            WHERE id = ?
        ");
        $stmt->execute([$group_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$group) {
            header("Location: index.php?message=" . urlencode("ไม่พบข้อมูลกลุ่มวัสดุ") . "&type=danger");
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
        $group_name = trim($_POST['group_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // ตรวจสอบข้อมูลที่จำเป็น
        if (empty($group_name)) {
            throw new Exception("กรุณากรอกชื่อกลุ่ม");
        }
        
        // ตรวจสอบชื่อกลุ่มซ้ำ (ยกเว้นตัวเอง)
        $checkNameStmt = $conn->prepare("SELECT COUNT(*) as count_result FROM Groups WHERE name = ? AND id != ?");
        $checkNameStmt->execute([$group_name, $group_id]);
        $checkNameResult = $checkNameStmt->fetch();
        if ($checkNameResult['count_result'] > 0) {
            throw new Exception("ชื่อกลุ่ม '$group_name' มีอยู่แล้วในระบบ");
        }
        
        // อัปเดตข้อมูล
        $stmt = $conn->prepare("
            UPDATE Groups SET 
                name = ?, description = ?, is_active = ?,
                updated_by = ?, updated_date = GETDATE()
            WHERE id = ?
        ");
        
        $stmt->execute([$group_name, $description, $is_active, $user_id, $group_id]);
        
        $message = "อัปเดตข้อมูลกลุ่มวัสดุ '$group_name' เรียบร้อยแล้ว";
        $message_type = "success";
        
        // อัปเดตข้อมูลในตัวแปร group
        $group['name'] = $group_name;
        $group['description'] = $description;
        $group['is_active'] = $is_active;
        
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
    <title>แก้ไขกลุ่มวัสดุ - <?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?></title>
    
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
        
        .badge {
            padding: 8px 15px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .badge.bg-success {
            background: linear-gradient(45deg, #ff6b35, #ff8c42) !important;
        }
        
        .badge.bg-danger {
            background: linear-gradient(45deg, #ff6b6b, #ff8e8e) !important;
        }
        
        .form-check-input {
            border-radius: 8px;
            border: 2px solid #ffe4d1;
            width: 1.5em;
            height: 1.5em;
        }
        
        .form-check-input:checked {
            background-color: #ff9a56;
            border-color: #ff9a56;
        }
        
        .form-check-label {
            color: #ff7f50;
            font-weight: bold;
            margin-left: 10px;
        }
        
        .group-id-badge {
            background: var(--primary-gradient);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 14px;
            display: inline-block;
            min-width: 60px;
            text-align: center;
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
                            <i class="fas fa-edit me-2"></i>แก้ไขกลุ่มวัสดุ
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <p class="text-muted mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    แก้ไขข้อมูลกลุ่มวัสดุ: <strong><?= htmlspecialchars($group['name']) ?></strong>
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <span class="group-id-badge"><?= htmlspecialchars($group['id']) ?></span>
                                <span class="badge <?= $group['is_active'] ? 'bg-success' : 'bg-danger' ?> ms-2">
                                    <i class="fas fa-<?= $group['is_active'] ? 'check' : 'times' ?> me-1"></i>
                                    <?= $group['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
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
                                    <label for="group_id_display" class="form-label">รหัสกลุ่ม</label>
                                    <input type="text" class="form-control" id="group_id_display" 
                                           value="<?= htmlspecialchars($group['id']) ?>" 
                                           readonly disabled>
                                    <div class="form-text">
                                        <i class="fas fa-lock me-1"></i>
                                        รหัสกลุ่มไม่สามารถแก้ไขได้
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="group_name" class="form-label">
                                        ชื่อกลุ่ม <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="group_name" name="group_name" 
                                           value="<?= htmlspecialchars($group['name']) ?>" 
                                           placeholder="เช่น Ink, Coating, Adhesive"
                                           maxlength="50" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">คำอธิบาย</label>
                                <textarea class="form-control" id="description" name="description" rows="3" 
                                          placeholder="เช่น หมึกสำหรับการพิมพ์ต่างๆ"
                                          maxlength="500"><?= htmlspecialchars($group['description'] ?? '') ?></textarea>
                                <div class="form-text">
                                    <i class="fas fa-info-circle me-1"></i>
                                    อธิบายประเภทของวัสดุในกลุ่มนี้
                                </div>
                            </div>
                            
                            <!-- สถานะการใช้งาน -->
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                               <?= $group['is_active'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_active">
                                            เปิดใช้งานกลุ่มวัสดุนี้
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        <i class="fas fa-toggle-on me-1"></i>
                                        หากปิดการใช้งาน กลุ่มนี้จะไม่ปรากฏในรายการเลือก
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
                                <i class="fas fa-eye me-2"></i>ตัวอย่างกลุ่ม
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="preview-box">
                                <h6><i class="fas fa-layer-group me-2"></i>กลุ่มวัสดุ</h6>
                                <div id="previewId" class="fw-bold text-primary">
                                    <span id="idPreview"><?= htmlspecialchars($group['id']) ?></span>
                                </div>
                                <div id="previewName" class="fw-bold">
                                    <span id="namePreview"><?= htmlspecialchars($group['name']) ?></span>
                                </div>
                                <small class="text-muted">
                                    <span id="descPreview"><?= htmlspecialchars($group['description'] ?: 'คำอธิบาย') ?></span>
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
                                    <td><strong>รหัสกลุ่ม:</strong></td>
                                    <td><?= htmlspecialchars($group['id']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>วันที่สร้าง:</strong></td>
                                    <td><?= date('d/m/Y H:i', strtotime($group['created_date'])) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>สร้างโดย:</strong></td>
                                    <td>User ID: <?= $group['created_by'] ?></td>
                                </tr>
                                <?php if ($group['updated_date']): ?>
                                <tr>
                                    <td><strong>แก้ไขล่าสุด:</strong></td>
                                    <td><?= date('d/m/Y H:i', strtotime($group['updated_date'])) ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td><strong>สถานะ:</strong></td>
                                    <td>
                                        <span class="badge <?= $group['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                                            <?= $group['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
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
                                <a href="view.php?id=<?= urlencode($group['id']) ?>" class="btn btn-secondary">
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
            const name = document.getElementById('group_name').value || 'ชื่อกลุ่ม';
            const desc = document.getElementById('description').value || 'คำอธิบาย';
            
            document.getElementById('namePreview').textContent = name;
            document.getElementById('descPreview').textContent = desc;
        }

        // เพิ่ม Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            // อัปเดตตัวอย่างเมื่อพิมพ์
            const previewFields = ['group_name', 'description'];
            previewFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('input', updatePreview);
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

        // ตรวจสอบฟอร์มก่อนส่ง
        document.getElementById('groupForm').addEventListener('submit', function(e) {
            const groupName = document.getElementById('group_name').value.trim();
            
            if (!groupName) {
                e.preventDefault();
                alert('กรุณากรอกชื่อกลุ่ม');
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
</body>
</html>