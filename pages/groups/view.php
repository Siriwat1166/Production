<?php
// pages/groups/view.php - ดูรายละเอียดกลุ่มวัสดุ
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();

// ข้อมูลผู้ใช้
$user_id = $_SESSION['user_id'] ?? 'N/A';
$username = $_SESSION['username'] ?? 'guest';
$full_name = $_SESSION['full_name'] ?? 'Guest User';
$role = $_SESSION['role'] ?? 'viewer';

$group = null;
$group_id = $_GET['id'] ?? '';
$materialCount = 0;

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
            SELECT g.id, g.name, g.description, g.is_active, g.created_date, g.created_by,
                   g.updated_date, g.updated_by,
                   u1.full_name as creator_name,
                   u2.full_name as updater_name
            FROM Groups g
            LEFT JOIN Users u1 ON g.created_by = u1.user_id
            LEFT JOIN Users u2 ON g.updated_by = u2.user_id
            WHERE g.id = ?
        ");
        $stmt->execute([$group_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$group) {
            header("Location: index.php?message=" . urlencode("ไม่พบข้อมูลกลุ่มวัสดุ") . "&type=danger");
            exit;
        }
        
        // นับจำนวนวัสดุในกลุ่มนี้
        try {
            $materialCountStmt = $conn->prepare("
                SELECT COUNT(*) as material_count 
                FROM Master_Products_ID 
                WHERE group_id = ? AND is_active = 1
            ");
            $materialCountStmt->execute([$group_id]);
            $countResult = $materialCountStmt->fetch();
            $materialCount = $countResult['material_count'] ?? 0;
        } catch (Exception $e) {
            // ถ้าไม่มีตาราง Master_Products_ID หรือไม่มี field group_id
            $materialCount = 0;
        }
        
    } else {
        throw new Exception("ไม่สามารถเชื่อมต่อฐานข้อมูลได้");
    }
} catch (Exception $e) {
    header("Location: index.php?message=" . urlencode("เกิดข้อผิดพลาด: " . $e->getMessage()) . "&type=danger");
    exit;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดกลุ่มวัสดุ - <?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?></title>
    
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
        
        .btn-outline-primary {
            border-color: #ff9a56;
            color: #ff9a56;
            border-radius: 10px;
            padding: 8px 20px;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: transparent;
            transform: translateY(-2px);
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
        
        .text-primary {
            color: #ff7f50 !important;
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
        
        .info-box {
            background: rgba(255, 154, 86, 0.05);
            border-left: 4px solid #ff9a56;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding: 15px;
            background: rgba(255, 248, 240, 0.5);
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .info-item:hover {
            background: rgba(255, 248, 240, 0.8);
            transform: translateX(5px);
        }
        
        .info-icon {
            width: 50px;
            height: 50px;
            background: var(--primary-gradient);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.2rem;
        }
        
        .stats-card {
            background: rgba(255, 154, 86, 0.1);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            border: 2px solid rgba(255, 154, 86, 0.2);
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(255, 154, 86, 0.2);
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #ff7f50;
            margin-bottom: 10px;
        }
        
        .table {
            background: white;
            border-radius: 15px;
            overflow: hidden;
        }
        
        .table td, .table th {
            border: none;
            padding: 15px;
            color: #ff7f50;
        }
        
        .table th {
            background: rgba(255, 154, 86, 0.1);
            font-weight: bold;
        }
        
        .table td strong {
            color: #e55a2b;
        }
        
        .group-id-badge {
            background: var(--primary-gradient);
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 18px;
            display: inline-block;
            min-width: 80px;
            text-align: center;
        }
        
        .group-icon {
            width: 80px;
            height: 80px;
            background: var(--primary-gradient);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 20px;
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .action-buttons .btn {
            flex: 1;
            min-width: 120px;
        }
        
        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
            }
            
            .info-item {
                flex-direction: column;
                text-align: center;
            }
            
            .info-icon {
                margin-right: 0;
                margin-bottom: 10px;
            }
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
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h4 class="mb-0">
                                    <i class="fas fa-layer-group me-2"></i>รายละเอียดกลุ่มวัสดุ
                                </h4>
                            </div>
                            <div class="col-md-4 text-end">
                                <span class="badge <?= $group['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                                    <i class="fas fa-<?= $group['is_active'] ? 'check' : 'times' ?> me-1"></i>
                                    <?= $group['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-2 text-center">
                                <?php
                                // แสดงไอคอนตามประเภทกลุ่ม
                                $icon = match($group['name']) {
                                    'Paperboard' => 'fas fa-file-alt',
                                    'Ink' => 'fas fa-paint-brush',
                                    'Coating' => 'fas fa-spray-can',
                                    'Adhesive' => 'fas fa-grip-horizontal',
                                    'Film' => 'fas fa-film',
                                    'Foil' => 'fas fa-certificate',
                                    'Plate' => 'fas fa-square',
                                    'Corrugated Box' => 'fas fa-box',
                                    default => 'fas fa-cube'
                                };
                                ?>
                                <div class="group-icon">
                                    <i class="<?= $icon ?>"></i>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5 class="text-primary mb-1"><?= htmlspecialchars($group['name']) ?></h5>
                                <p class="text-muted mb-2">
                                    <?= htmlspecialchars($group['description'] ?: 'ไม่มีคำอธิบาย') ?>
                                </p>
                                <span class="group-id-badge"><?= htmlspecialchars($group['id']) ?></span>
                            </div>
                            <div class="col-md-4 text-end">
                                <?php if ($auth->hasRole('editor')): ?>
                                <div class="action-buttons">
                                    <a href="edit.php?id=<?= urlencode($group['id']) ?>" class="btn btn-outline-primary">
                                        <i class="fas fa-edit me-1"></i> แก้ไข
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- ข้อมูลหลัก -->
            <div class="col-lg-8">
                <!-- ข้อมูลกลุ่มวัสดุ -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-info-circle me-2"></i>ข้อมูลกลุ่มวัสดุ
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-item">
                                    <div class="info-icon">
                                        <i class="fas fa-hashtag"></i>
                                    </div>
                                    <div>
                                        <strong>รหัสกลุ่ม</strong><br>
                                        <span class="text-primary fs-5 fw-bold">
                                            <?= htmlspecialchars($group['id']) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-item">
                                    <div class="info-icon">
                                        <i class="fas fa-tag"></i>
                                    </div>
                                    <div>
                                        <strong>ชื่อกลุ่ม</strong><br>
                                        <span class="text-muted">
                                            <?= htmlspecialchars($group['name']) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="info-item">
                                    <div class="info-icon">
                                        <i class="fas fa-align-left"></i>
                                    </div>
                                    <div>
                                        <strong>คำอธิบาย</strong><br>
                                        <span class="text-muted">
                                            <?= htmlspecialchars($group['description'] ?: 'ไม่มีคำอธิบาย') ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ข้อมูลระบบ -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-cogs me-2"></i>ข้อมูลระบบ
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="30%">รหัสกลุ่ม</th>
                                    <td><?= htmlspecialchars($group['id']) ?></td>
                                </tr>
                                <tr>
                                    <th>วันที่สร้าง</th>
                                    <td>
                                        <?= date('d/m/Y H:i:s', strtotime($group['created_date'])) ?>
                                        <small class="text-muted">
                                            (<?= htmlspecialchars($group['creator_name'] ?: 'Unknown') ?>)
                                        </small>
                                    </td>
                                </tr>
                                <?php if ($group['updated_date']): ?>
                                <tr>
                                    <th>วันที่อัปเดต</th>
                                    <td>
                                        <?= date('d/m/Y H:i:s', strtotime($group['updated_date'])) ?>
                                        <small class="text-muted">
                                            (<?= htmlspecialchars($group['updater_name'] ?: 'Unknown') ?>)
                                        </small>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th>สถานะ</th>
                                    <td>
                                        <span class="badge <?= $group['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                                            <i class="fas fa-<?= $group['is_active'] ? 'check' : 'times' ?> me-1"></i>
                                            <?= $group['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- สถิติ -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="fas fa-chart-bar me-2"></i>สถิติการใช้งาน
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="stats-card">
                            <div class="stats-number"><?= number_format($materialCount) ?></div>
                            <h6>วัสดุในกลุ่มนี้</h6>
                            <small class="text-muted">จำนวนวัสดุที่ใช้งานอยู่</small>
                        </div>
                    </div>
                </div>
                
                <!-- การดำเนินการ -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="fas fa-tools me-2"></i>การดำเนินการ
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <?php if ($auth->hasRole('editor')): ?>
                            <a href="edit.php?id=<?= urlencode($group['id']) ?>" class="btn btn-primary">
                                <i class="fas fa-edit me-2"></i>แก้ไขข้อมูล
                            </a>
                            <?php endif; ?>
                            
                            <button type="button" class="btn btn-outline-primary" onclick="printGroup()">
                                <i class="fas fa-print me-2"></i>พิมพ์ข้อมูล
                            </button>
                            
                            <button type="button" class="btn btn-outline-primary" onclick="exportGroup()">
                                <i class="fas fa-download me-2"></i>ส่งออกข้อมูล
                            </button>
                            
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>กลับไปรายการ
                            </a>
                            
                            <?php if ($auth->hasRole('admin') && !$group['is_active'] && $materialCount == 0): ?>
                            <hr>
                            <button type="button" class="btn btn-outline-danger" onclick="confirmDelete()">
                                <i class="fas fa-trash me-2"></i>ลบกลุ่มวัสดุ
                            </button>
                            <?php elseif ($auth->hasRole('admin')): ?>
                            <hr>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>ไม่สามารถลบได้:</strong><br>
                                <?php if ($group['is_active']): ?>
                                - กลุ่มนี้ยังใช้งานอยู่<br>
                                <?php endif; ?>
                                <?php if ($materialCount > 0): ?>
                                - มีวัสดุ <?= $materialCount ?> รายการเชื่อมโยง<br>
                                <?php endif; ?>
                                กรุณาปิดการใช้งานและย้ายวัสดุก่อน
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- ข้อมูลเพิ่มเติม -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="fas fa-lightbulb me-2"></i>ข้อมูลเพิ่มเติม
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="info-box">
                            <h6><i class="fas fa-info-circle me-2"></i>การใช้งาน</h6>
                            <ul class="mb-0 small">
                                <li>กลุ่มวัสดุใช้สำหรับจัดหมวดหมู่วัสดุ</li>
                                <li>รหัสกลุ่มไม่สามารถแก้ไขได้</li>
                                <li>การปิดใช้งานจะซ่อนจากรายการเลือก</li>
                                <?php if ($materialCount > 0): ?>
                                <li class="text-warning">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    มีวัสดุ <?= $materialCount ?> รายการในกลุ่มนี้
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        
                        <?php if ($group['is_active']): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>สถานะ:</strong> กลุ่มนี้ใช้งานได้ปกติ
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>สถานะ:</strong> กลุ่มนี้ถูกปิดใช้งาน
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <?php if ($auth->hasRole('admin')): ?>
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle text-danger me-2"></i>ยืนยันการลบ
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>คุณต้องการลบกลุ่มวัสดุ <strong><?= htmlspecialchars($group['name']) ?></strong> ใช่หรือไม่?</p>
                    
                    <?php if ($materialCount > 0): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>คำเตือน:</strong> กลุ่มนี้มีวัสดุ <?= $materialCount ?> รายการที่เชื่อมโยงอยู่
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>คำเตือน:</strong> การลบนี้ไม่สามารถย้อนกลับได้
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>ยกเลิก
                    </button>
                    <?php if ($materialCount == 0 && !$group['is_active']): ?>
                    <button type="button" class="btn btn-danger" onclick="deleteGroup()">
                        <i class="fas fa-trash me-2"></i>ลบ
                    </button>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>เงื่อนไขการลบ:</strong><br>
                        - กลุ่มต้องไม่ใช้งาน (ปิดการใช้งานแล้ว)<br>
                        - ไม่มีวัสดุในกลุ่มนี้
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // เพิ่มเอฟเฟกต์ fade in
        document.addEventListener('DOMContentLoaded', function() {
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

        // ฟังก์ชันพิมพ์ข้อมูลกลุ่มวัสดุ
        function printGroup() {
            const printWindow = window.open('', '_blank');
            const content = `
                <html>
                <head>
                    <title>รายละเอียดกลุ่มวัสดุ - <?= htmlspecialchars($group['name']) ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .header { text-align: center; margin-bottom: 30px; }
                        .info-section { margin-bottom: 20px; }
                        .info-title { font-weight: bold; color: #333; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
                        .info-content { padding: 10px 0; }
                        .status { padding: 5px 10px; border-radius: 5px; display: inline-block; }
                        .status.active { background-color: #d4edda; color: #155724; }
                        .status.inactive { background-color: #f8d7da; color: #721c24; }
                        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
                        th { background-color: #f5f5f5; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>รายละเอียดกลุ่มวัสดุ</h1>
                        <h2><?= htmlspecialchars($group['name']) ?></h2>
                        <p>รหัสกลุ่ม: <?= htmlspecialchars($group['id']) ?></p>
                        <span class="status <?= $group['is_active'] ? 'active' : 'inactive' ?>">
                            <?= $group['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
                        </span>
                    </div>
                    
                    <div class="info-section">
                        <div class="info-title">ข้อมูลกลุ่มวัสดุ</div>
                        <table>
                            <tr><th>รหัสกลุ่ม</th><td><?= htmlspecialchars($group['id']) ?></td></tr>
                            <tr><th>ชื่อกลุ่ม</th><td><?= htmlspecialchars($group['name']) ?></td></tr>
                            <tr><th>คำอธิบาย</th><td><?= htmlspecialchars($group['description'] ?: 'ไม่มีคำอธิบาย') ?></td></tr>
                        </table>
                    </div>
                    
                    <div class="info-section">
                        <div class="info-title">ข้อมูลระบบ</div>
                        <table>
                            <tr><th>วันที่สร้าง</th><td><?= date('d/m/Y H:i:s', strtotime($group['created_date'])) ?></td></tr>
                            <?php if ($group['updated_date']): ?>
                            <tr><th>วันที่อัปเดต</th><td><?= date('d/m/Y H:i:s', strtotime($group['updated_date'])) ?></td></tr>
                            <?php endif; ?>
                            <tr><th>จำนวนวัสดุในกลุ่ม</th><td><?= number_format($materialCount) ?> รายการ</td></tr>
                        </table>
                    </div>
                    
                    <div style="margin-top: 30px; text-align: center; color: #666; font-size: 12px;">
                        พิมพ์เมื่อ: <?= date('d/m/Y H:i:s') ?> | <?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?>
                    </div>
                </body>
                </html>
            `;
            
            printWindow.document.write(content);
            printWindow.document.close();
            printWindow.print();
        }

        // ฟังก์ชันส่งออกข้อมูล
        function exportGroup() {
            const data = {
                group_id: "<?= htmlspecialchars($group['id']) ?>",
                group_name: "<?= htmlspecialchars($group['name']) ?>",
                description: "<?= htmlspecialchars($group['description'] ?: '') ?>",
                is_active: <?= $group['is_active'] ? 'true' : 'false' ?>,
                created_date: "<?= $group['created_date'] ?>",
                updated_date: "<?= $group['updated_date'] ?: '' ?>",
                material_count: <?= $materialCount ?>
            };
            
            const jsonString = JSON.stringify(data, null, 2);
            const blob = new Blob([jsonString], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            
            const a = document.createElement('a');
            a.href = url;
            a.download = `group_${data.group_id}_${new Date().toISOString().slice(0, 10)}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            
            // แสดง Toast notification
            showToast('ส่งออกข้อมูลเรียบร้อยแล้ว', 'success');
        }

        // ฟังก์ชันยืนยันการลบ
        function confirmDelete() {
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }

        // ฟังก์ชันลบกลุ่มวัสดุ
        function deleteGroup() {
            if (confirm('คุณแน่ใจหรือไม่ที่ต้องการลบกลุ่มวัสดุนี้?')) {
                window.location.href = `delete.php?id=<?= urlencode($group['id']) ?>`;
            }
        }

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

        // เพิ่มคีย์บอร์ดช็อตคัต
        document.addEventListener('keydown', function(e) {
            // Ctrl+P = พิมพ์
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printGroup();
            }
            
            // Ctrl+E = แก้ไข
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                <?php if ($auth->hasRole('editor')): ?>
                window.location.href = 'edit.php?id=<?= urlencode($group['id']) ?>';
                <?php endif; ?>
            }
            
            // Escape = กลับ
            if (e.key === 'Escape') {
                window.location.href = 'index.php';
            }
        });
    </script>

    <style>
        /* Toast styling */
        .toast {
            border-radius: 15px;
        }
        
        .bg-success {
            background: linear-gradient(45deg, #ff6b35, #ff8c42) !important;
        }
        
        .bg-info {
            background: linear-gradient(45deg, #ffa726, #ffcc80) !important;
        }
        
        /* Print styles */
        @media print {
            .navbar, .action-buttons, .btn {
                display: none !important;
            }
        }
    </style>
</body>
</html>