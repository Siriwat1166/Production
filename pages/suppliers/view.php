<?php
// pages/suppliers/view.php - ดูรายละเอียดซัพพลายเออร์
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();

// ข้อมูลผู้ใช้
$user_id = $_SESSION['user_id'] ?? 'N/A';
$username = $_SESSION['username'] ?? 'guest';
$full_name = $_SESSION['full_name'] ?? 'Guest User';
$role = $_SESSION['role'] ?? 'viewer';

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
            SELECT s.supplier_id, s.supplier_code, s.supplier_name, s.contact_person, s.phone, s.email, 
                   s.address, s.tax_id, s.payment_terms, s.is_active, s.created_date, s.created_by,
                   s.updated_date, s.updated_by,
                   u1.full_name as creator_name,
                   u2.full_name as updater_name
            FROM Suppliers s
            LEFT JOIN Users u1 ON s.created_by = u1.user_id
            LEFT JOIN Users u2 ON s.updated_by = u2.user_id
            WHERE s.supplier_id = ?
        ");
        $stmt->execute([$supplier_id]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$supplier) {
            header("Location: index.php?message=" . urlencode("ไม่พบข้อมูลซัพพลายเออร์") . "&type=danger");
            exit;
        }
        
        // นับจำนวนวัสดุที่ใช้ซัพพลายเออร์นี้
        try {
            $materialCountStmt = $conn->prepare("
                SELECT COUNT(*) as material_count 
                FROM Master_Products_ID 
                WHERE supplier_id = ? AND is_active = 1
            ");
            $materialCountStmt->execute([$supplier_id]);
            $countResult = $materialCountStmt->fetch();
            $materialCount = $countResult['material_count'] ?? 0;
        } catch (Exception $e) {
            // ถ้าไม่มีตาราง Master_Products_ID หรือไม่มี field supplier_id
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
    <title>รายละเอียดซัพพลายเออร์ - <?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?></title>
    
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
        
        .btn-outline-primary {
            border-color: var(--primary-color);
            color: var(--primary-color);
            border-radius: 10px;
            padding: 8px 20px;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: transparent;
            transform: translateY(-2px);
            color: white;
        }

        .btn-outline-secondary {
            border-color: var(--accent-color);
            color: var(--accent-color);
            border-radius: 10px;
            padding: 8px 20px;
            transition: all 0.3s ease;
        }

        .btn-outline-secondary:hover {
            background: linear-gradient(135deg, var(--accent-color), var(--primary-color));
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
        }

        .btn-outline-danger {
            border-color: var(--danger-color);
            color: var(--danger-color);
            border-radius: 10px;
            padding: 8px 20px;
            transition: all 0.3s ease;
        }

        .btn-outline-danger:hover {
            background: linear-gradient(135deg, var(--danger-color), #ef4444);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--accent-color), var(--primary-color));
            border: none;
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #ef4444);
            border: none;
            color: white;
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
        
        .text-primary {
            color: var(--primary-color) !important;
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
        
        .info-box {
            background: rgba(139, 69, 19, 0.05);
            border-left: 4px solid var(--accent-color);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding: 15px;
            background: rgba(245, 222, 179, 0.3);
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .info-item:hover {
            background: rgba(245, 222, 179, 0.5);
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
            background: rgba(139, 69, 19, 0.05);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            border: 2px solid rgba(139, 69, 19, 0.2);
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(139, 69, 19, 0.2);
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary-color);
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
            color: var(--primary-color);
        }
        
        .table th {
            background: rgba(139, 69, 19, 0.1);
            font-weight: bold;
        }
        
        .table td strong {
            color: var(--primary-color);
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

        .alert-success {
            background: rgba(5, 150, 105, 0.1);
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-warning {
            background: rgba(217, 119, 6, 0.1);
            color: #856404;
            border-left: 4px solid var(--warning-color);
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

        .container-fluid {
            max-width: 100%;
            padding: 0 15px;
            width: 100%;
            margin: 0;
        }

        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 40px rgba(139, 69, 19, 0.3);
        }

        .modal-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 20px 20px 0 0;
            border-bottom: none;
        }

        .modal-footer {
            border-top: 1px solid rgba(139, 69, 19, 0.1);
            border-radius: 0 0 20px 20px;
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

            .container-fluid {
                padding: 0 15px;
            }
        }

        /* Toast styling */
        .toast {
            border-radius: 15px;
        }
        
        .bg-success {
            background: linear-gradient(45deg, var(--success-color), #10b981) !important;
        }
        
        .bg-info {
            background: linear-gradient(45deg, var(--secondary-color), #ffb347) !important;
        }
        
        /* Print styles */
        @media print {
            .navbar, .action-buttons, .btn {
                display: none !important;
            }
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
            <li class="breadcrumb-item active">รายละเอียดซัพพลายเออร์</li>
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
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h4 class="mb-0">
                                    <i class="fas fa-truck me-2"></i>รายละเอียดซัพพลายเออร์
                                </h4>
                            </div>
                            <div class="col-md-4 text-end">
                                <span class="badge <?= $supplier['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                                    <i class="fas fa-<?= $supplier['is_active'] ? 'check' : 'times' ?> me-1"></i>
                                    <?= $supplier['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h5 class="text-primary mb-1"><?= htmlspecialchars($supplier['supplier_name']) ?></h5>
                                <p class="text-muted mb-0">
                                    <i class="fas fa-barcode me-2"></i>
                                    รหัส: <strong><?= htmlspecialchars($supplier['supplier_code']) ?></strong>
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <?php if ($auth->hasRole('editor')): ?>
                                <div class="action-buttons">
                                    <a href="edit.php?id=<?= $supplier['supplier_id'] ?>" class="btn btn-outline-primary">
                                        <i class="fas fa-edit me-1"></i> แก้ไข
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ข้อมูลระบบ -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-info-circle me-2"></i>ข้อมูลระบบ
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-borderless">
                                <tr>
                                    <th width="30%">รหัส ID</th>
                                    <td><?= $supplier['supplier_id'] ?></td>
                                </tr>
                                <tr>
                                    <th>วันที่สร้าง</th>
                                    <td>
                                        <?= date('d/m/Y H:i:s', strtotime($supplier['created_date'])) ?>
                                        <small class="text-muted">
                                            (<?= htmlspecialchars($supplier['creator_name'] ?: 'Unknown') ?>)
                                        </small>
                                    </td>
                                </tr>
                                <?php if ($supplier['updated_date']): ?>
                                <tr>
                                    <th>วันที่อัปเดต</th>
                                    <td>
                                        <?= date('d/m/Y H:i:s', strtotime($supplier['updated_date'])) ?>
                                        <small class="text-muted">
                                            (<?= htmlspecialchars($supplier['updater_name'] ?: 'Unknown') ?>)
                                        </small>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th>สถานะ</th>
                                    <td>
                                        <span class="badge <?= $supplier['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                                            <i class="fas fa-<?= $supplier['is_active'] ? 'check' : 'times' ?> me-1"></i>
                                            <?= $supplier['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
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
                            <div class="stats-number"><?= number_format($materialCount ?? 0) ?></div>
                            <h6>วัสดุที่ใช้ซัพพลายเออร์นี้</h6>
                            <small class="text-muted">จำนวนวัสดุที่ใช้งานอยู่</small>
                        </div>
                    </div>
                </div>
                
                <!-- การดำเนินการ -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="fas fa-cogs me-2"></i>การดำเนินการ
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <?php if ($auth->hasRole('editor')): ?>
                            <a href="edit.php?id=<?= $supplier['supplier_id'] ?>" class="btn btn-primary">
                                <i class="fas fa-edit me-2"></i>แก้ไขข้อมูล
                            </a>
                            <?php endif; ?>
                            
                            <button type="button" class="btn btn-outline-primary" onclick="printSupplier()">
                                <i class="fas fa-print me-2"></i>พิมพ์ข้อมูล
                            </button>
                            
                            <button type="button" class="btn btn-outline-primary" onclick="exportSupplier()">
                                <i class="fas fa-download me-2"></i>ส่งออกข้อมูล
                            </button>
                            
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>กลับไปรายการ
                            </a>
                            
                            <?php if ($auth->hasRole('admin') && !$supplier['is_active'] && $materialCount == 0): ?>
                            <hr>
                            <button type="button" class="btn btn-outline-danger" onclick="confirmDelete()">
                                <i class="fas fa-trash me-2"></i>ลบซัพพลายเออร์
                            </button>
                            <?php elseif ($auth->hasRole('admin')): ?>
                            <hr>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>ไม่สามารถลบได้:</strong><br>
                                <?php if ($supplier['is_active']): ?>
                                - ซัพพลายเออร์นี้ยังใช้งานอยู่<br>
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
                                <li>ข้อมูลนี้ใช้ในการจัดการวัสดุ</li>
                                <li>สามารถแก้ไขข้อมูลได้ตลอดเวลา</li>
                                <li>การปิดใช้งานจะซ่อนจากรายการเลือก</li>
                                <?php if ($materialCount > 0): ?>
                                <li class="text-warning">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    มีวัสดุ <?= $materialCount ?> รายการใช้ซัพพลายเออร์นี้
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        
                        <?php if ($supplier['is_active']): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>สถานะ:</strong> ซัพพลายเออร์นี้ใช้งานได้ปกติ
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>สถานะ:</strong> ซัพพลายเออร์นี้ถูกปิดใช้งาน
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
                    <p>คุณต้องการลบซัพพลายเออร์ <strong><?= htmlspecialchars($supplier['supplier_name']) ?></strong> ใช่หรือไม่?</p>
                    
                    <?php if ($materialCount > 0): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>คำเตือน:</strong> ซัพพลายเออร์นี้มีวัสดุ <?= $materialCount ?> รายการที่เชื่อมโยงอยู่
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
                    <?php if ($materialCount == 0 && !$supplier['is_active']): ?>
                    <button type="button" class="btn btn-danger" onclick="deleteSupplier()">
                        <i class="fas fa-trash me-2"></i>ลบ
                    </button>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>เงื่อนไขการลบ:</strong><br>
                        - ซัพพลายเออร์ต้องไม่ใช้งาน (ปิดการใช้งานแล้ว)<br>
                        - ไม่มีวัสดุที่ใช้ซัพพลายเออร์นี้
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

        // ฟังก์ชันพิมพ์ข้อมูลซัพพลายเออร์
        function printSupplier() {
            const printWindow = window.open('', '_blank');
            const content = `
                <html>
                <head>
                    <title>รายละเอียดซัพพลายเออร์ - <?= htmlspecialchars($supplier['supplier_name']) ?></title>
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
                        <h1>รายละเอียดซัพพลายเออร์</h1>
                        <h2><?= htmlspecialchars($supplier['supplier_name']) ?></h2>
                        <p>รหัส: <?= htmlspecialchars($supplier['supplier_code']) ?></p>
                        <span class="status <?= $supplier['is_active'] ? 'active' : 'inactive' ?>">
                            <?= $supplier['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
                        </span>
                    </div>
                    
                    <div class="info-section">
                        <div class="info-title">ข้อมูลติดต่อ</div>
                        <table>
                            <tr><th>ผู้ติดต่อ</th><td><?= htmlspecialchars($supplier['contact_person'] ?: 'ไม่ระบุ') ?></td></tr>
                            <tr><th>โทรศัพท์</th><td><?= htmlspecialchars($supplier['phone'] ?: 'ไม่ระบุ') ?></td></tr>
                            <tr><th>อีเมล</th><td><?= htmlspecialchars($supplier['email'] ?: 'ไม่ระบุ') ?></td></tr>
                            <tr><th>ที่อยู่</th><td><?= htmlspecialchars($supplier['address'] ?: 'ไม่ระบุ') ?></td></tr>
                        </table>
                    </div>
                    
                    <div class="info-section">
                        <div class="info-title">ข้อมูลทางธุรกิจ</div>
                        <table>
                            <tr><th>เลขประจำตัวผู้เสียภาษี</th><td><?= htmlspecialchars($supplier['tax_id'] ?: 'ไม่ระบุ') ?></td></tr>
                            <tr><th>เงื่อนไขการชำระเงิน</th><td><?= htmlspecialchars($supplier['payment_terms'] ?: 'ไม่ระบุ') ?></td></tr>
                        </table>
                    </div>
                    
                    <div class="info-section">
                        <div class="info-title">ข้อมูลระบบ</div>
                        <table>
                            <tr><th>รหัส ID</th><td><?= $supplier['supplier_id'] ?></td></tr>
                            <tr><th>วันที่สร้าง</th><td><?= date('d/m/Y H:i:s', strtotime($supplier['created_date'])) ?></td></tr>
                            <?php if ($supplier['updated_date']): ?>
                            <tr><th>วันที่อัปเดต</th><td><?= date('d/m/Y H:i:s', strtotime($supplier['updated_date'])) ?></td></tr>
                            <?php endif; ?>
                            <tr><th>จำนวนวัสดุที่ใช้</th><td><?= number_format($materialCount ?? 0) ?> รายการ</td></tr>
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
        function exportSupplier() {
            const data = {
                supplier_id: <?= $supplier['supplier_id'] ?>,
                supplier_code: "<?= htmlspecialchars($supplier['supplier_code']) ?>",
                supplier_name: "<?= htmlspecialchars($supplier['supplier_name']) ?>",
                contact_person: "<?= htmlspecialchars($supplier['contact_person'] ?: '') ?>",
                phone: "<?= htmlspecialchars($supplier['phone'] ?: '') ?>",
                email: "<?= htmlspecialchars($supplier['email'] ?: '') ?>",
                address: "<?= htmlspecialchars($supplier['address'] ?: '') ?>",
                tax_id: "<?= htmlspecialchars($supplier['tax_id'] ?: '') ?>",
                payment_terms: "<?= htmlspecialchars($supplier['payment_terms'] ?: '') ?>",
                is_active: <?= $supplier['is_active'] ? 'true' : 'false' ?>,
                created_date: "<?= $supplier['created_date'] ?>",
                material_count: <?= $materialCount ?? 0 ?>
            };
            
            const jsonString = JSON.stringify(data, null, 2);
            const blob = new Blob([jsonString], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            
            const a = document.createElement('a');
            a.href = url;
            a.download = `supplier_${data.supplier_code}_${new Date().toISOString().slice(0, 10)}.json`;
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

        // ฟังก์ชันลบซัพพลายเออร์
        function deleteSupplier() {
            if (confirm('คุณแน่ใจหรือไม่ที่ต้องการลบซัพพลายเออร์นี้?')) {
                window.location.href = `delete.php?id=<?= $supplier['supplier_id'] ?>`;
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
                printSupplier();
            }
            
            // Ctrl+E = แก้ไข
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                <?php if ($auth->hasRole('editor')): ?>
                window.location.href = 'edit.php?id=<?= $supplier['supplier_id'] ?>';
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