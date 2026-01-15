<?php
// pages/material_types/index.php - รายการประเภทวัสดุ
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

// Pagination - แปลงเป็น integer และตรวจสอบค่า
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20; // ตายตัว integer
$offset = ($page - 1) * $limit; // คำนวณเป็น integer

// Search
$search = $_GET['search'] ?? '';
$searchCondition = $search ? "WHERE (type_name LIKE ? OR type_code LIKE ? OR description LIKE ?)" : "";

// เชื่อมต่อฐานข้อมูล
$db_connected = false;
try {
    if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
        require_once "../../config/database.php";
        $database = new Database();
        $conn = $database->getConnection();
        $db_connected = true;

        // สร้างเงื่อนไขการค้นหา
        $searchParams = [];
        if ($search) {
            $searchParams = ["%$search%", "%$search%", "%$search%"];
        }

        // นับจำนวนประเภทวัสดุทั้งหมด
        $countQuery = "SELECT COUNT(*) as total_count FROM Material_Types $searchCondition";
        $countStmt = $conn->prepare($countQuery);
        $countStmt->execute($searchParams);
        $countResult = $countStmt->fetch();
        $total_material_types = $countResult['total_count'];

        // ดึงข้อมูลประเภทวัสดุ - ใช้ TOP แทน OFFSET/FETCH สำหรับ SQL Server เก่า
        if ($page == 1) {
            // หน้าแรก ใช้ TOP
            $dataQuery = "
                SELECT TOP ($limit) material_type_id, type_code, type_name, description, is_active, created_date, created_by
                FROM Material_Types 
                $searchCondition
                ORDER BY type_name
            ";
            $stmt = $conn->prepare($dataQuery);
            $stmt->execute($searchParams);
        } else {
            // หน้าอื่นๆ ใช้ OFFSET/FETCH แต่ส่งค่า parameter แยก
            $dataQuery = "
                SELECT material_type_id, type_code, type_name, description, is_active, created_date, created_by
                FROM Material_Types 
                $searchCondition
                ORDER BY type_name
                OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY
            ";
            $stmt = $conn->prepare($dataQuery);
            $stmt->execute($searchParams);
        }
        
        $material_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // ข้อมูล Demo เมื่อไม่มีฐานข้อมูล
        $material_types = [
            [
                'material_type_id' => 1,
                'type_code' => 'PAPER',
                'type_name' => 'กระดาษ',
                'description' => 'กระดาษทุกชนิด เช่น A4, A3, กระดาษโฟโต้',
                'is_active' => 1,
                'created_date' => '2024-01-01 10:00:00',
                'created_by' => 1
            ],
            [
                'material_type_id' => 2,
                'type_code' => 'TOOL',
                'type_name' => 'เครื่องมือ',
                'description' => 'เครื่องมือและอุปกรณ์ช่าง',
                'is_active' => 1,
                'created_date' => '2024-01-02 11:00:00',
                'created_by' => 1
            ],
            [
                'material_type_id' => 3,
                'type_code' => 'CHEMICAL',
                'type_name' => 'สารเคมี',
                'description' => 'สารเคมีและน้ำยาทำความสะอาด',
                'is_active' => 1,
                'created_date' => '2024-01-03 12:00:00',
                'created_by' => 1
            ]
        ];
        $total_material_types = count($material_types);
    }
} catch (Exception $e) {
    $message = "เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: " . $e->getMessage();
    $message_type = "warning";
    
    // ใช้ข้อมูล Demo เมื่อเกิดข้อผิดพลาด
    $material_types = [
        [
            'material_type_id' => 1,
            'type_code' => 'PAPER',
            'type_name' => 'กระดาษ (Demo)',
            'description' => 'กระดาษทุกชนิด เช่น A4, A3, กระดาษโฟโต้',
            'is_active' => 1,
            'created_date' => '2024-01-01 10:00:00',
            'created_by' => 1
        ]
    ];
    $total_material_types = 1;
}

// ข้อมูลเริ่มต้น
$message = $_GET['message'] ?? $message ?? '';
$message_type = $_GET['type'] ?? $message_type ?? 'info';

$total_pages = ceil($total_material_types / $limit);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประเภทวัสดุ - <?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?></title>
    
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
        
        .table {
            background: white;
            border-radius: 15px;
            overflow: hidden;
        }
        
        .table th {
            background: rgba(255, 154, 86, 0.1);
            color: #ff7f50;
            font-weight: bold;
            border: none;
            padding: 15px;
        }
        
        .table td {
            border: none;
            padding: 15px;
            vertical-align: middle;
            color: #ff7f50;
        }
        
        .table tbody tr:hover {
            background: rgba(255, 248, 240, 0.8);
            transform: translateX(5px);
            transition: all 0.3s ease;
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
        
        .pagination .page-link {
            border: none;
            color: #ff7f50;
            padding: 10px 15px;
            margin: 0 5px;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .pagination .page-link:hover {
            background: var(--primary-gradient);
            color: white;
            transform: translateY(-2px);
        }
        
        .pagination .page-item.active .page-link {
            background: var(--primary-gradient);
            color: white;
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
        
        .alert-warning {
            background: rgba(255, 167, 38, 0.1);
            color: #ffa726;
            border-left: 4px solid #ffa726;
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
        
        .dropdown-menu {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(255, 154, 86, 0.3);
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid #ffe4d1;
        }
        
        .dropdown-item {
            border-radius: 10px;
            margin: 2px 5px;
            transition: all 0.3s ease;
            color: #ff7f50;
        }
        
        .dropdown-item:hover {
            background: var(--primary-gradient);
            color: white;
            transform: translateX(5px);
        }
        
        .search-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(255, 154, 86, 0.15);
            border: 2px solid #ffe4d1;
        }
        
        .stats-card {
            background: rgba(255, 154, 86, 0.1);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            border: 2px solid rgba(255, 154, 86, 0.2);
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #ff7f50;
            margin-bottom: 10px;
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
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-arrow-left me-1"></i> กลับสู่หน้าหลัก
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
                            <i class="fas fa-layer-group me-2"></i>จัดการประเภทวัสดุ
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <p class="text-muted mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    จัดการประเภทวัสดุในระบบ เพิ่ม แก้ไข และดูรายการประเภทวัสดุทั้งหมด
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <a href="add.php" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>เพิ่มประเภทวัสดุใหม่
                                </a>
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

        <!-- Search Section -->
        <div class="search-section fade-in">
            <form method="GET" class="row">
                <div class="col-md-8">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" 
                               value="<?= htmlspecialchars($search) ?>" 
                               placeholder="ค้นหาประเภทวัสดุ (ชื่อ, รหัส, รายละเอียด)">
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-search me-2"></i>ค้นหา
                        </button>
                    </div>
                </div>
                <div class="col-md-4">
                    <?php if ($search): ?>
                    <a href="index.php" class="btn btn-outline-primary">
                        <i class="fas fa-times me-2"></i>ล้างการค้นหา
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Statistics -->
        <div class="row mb-4 fade-in">
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-number"><?= number_format($total_material_types) ?></div>
                    <h6>ประเภทวัสดุทั้งหมด</h6>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-number"><?= count(array_filter($material_types, fn($mt) => $mt['is_active'] == 1)) ?></div>
                    <h6>ประเภทที่ใช้งาน</h6>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-number"><?= $db_connected ? '100%' : 'Demo' ?></div>
                    <h6>สถานะระบบ</h6>
                </div>
            </div>
        </div>

        <!-- Material Types Table -->
        <div class="row fade-in">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>รายการประเภทวัสดุ
                            <?php if ($search): ?>
                            <small class="text-light">(ผลการค้นหา: "<?= htmlspecialchars($search) ?>")</small>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($material_types)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">ไม่พบข้อมูลประเภทวัสดุ</h5>
                            <p class="text-muted">
                                <?= $search ? 'ไม่พบผลลัพธ์ที่ตรงกับการค้นหา' : 'ยังไม่มีประเภทวัสดุในระบบ' ?>
                            </p>
                            <a href="add.php" class="btn btn-primary mt-3">
                                <i class="fas fa-plus me-2"></i>เพิ่มประเภทวัสดุแรก
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>รหัส</th>
                                        <th>ชื่อประเภท</th>
                                        <th>รายละเอียด</th>
                                        <th>สถานะ</th>
                                        <th>วันที่สร้าง</th>
                                        <th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($material_types as $type): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-primary"><?= htmlspecialchars($type['type_code']) ?></strong>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($type['type_name']) ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($type['description']): ?>
                                            <span class="text-muted"><?= htmlspecialchars($type['description']) ?></span>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($type['is_active']): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check me-1"></i>ใช้งาน
                                            </span>
                                            <?php else: ?>
                                            <span class="badge bg-danger">
                                                <i class="fas fa-times me-1"></i>ไม่ใช้งาน
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?= date('d/m/Y', strtotime($type['created_date'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="view.php?id=<?= $type['material_type_id'] ?>" 
                                                   class="btn btn-outline-primary btn-sm" 
                                                   title="ดูรายละเอียด">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="edit.php?id=<?= $type['material_type_id'] ?>" 
                                                   class="btn btn-outline-primary btn-sm" 
                                                   title="แก้ไข">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if ($auth->hasRole('admin')): ?>
                                                <button class="btn btn-outline-danger btn-sm" 
                                                        onclick="confirmDelete(<?= $type['material_type_id'] ?>, '<?= htmlspecialchars($type['type_name']) ?>')"
                                                        title="ลบ">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="row mt-4 fade-in">
            <div class="col-12">
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?>">
                                <?= $i ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                
                <div class="text-center mt-3">
                    <small class="text-muted">
                        แสดง <?= min($offset + 1, $total_material_types) ?> - <?= min($offset + $limit, $total_material_types) ?> 
                        จากทั้งหมด <?= number_format($total_material_types) ?> รายการ
                    </small>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
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
                    <p>คุณต้องการลบประเภทวัสดุ <strong id="materialTypeName"></strong> ใช่หรือไม่?</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>คำเตือน:</strong> การลบนี้ไม่สามารถย้อนกลับได้
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>ยกเลิก
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="fas fa-trash me-2"></i>ลบ
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ฟังก์ชันยืนยันการลบ
        function confirmDelete(materialTypeId, materialTypeName) {
            document.getElementById('materialTypeName').textContent = materialTypeName;
            document.getElementById('confirmDeleteBtn').onclick = function() {
                window.location.href = `delete.php?id=${materialTypeId}`;
            };
            
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }

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