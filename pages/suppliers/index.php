<?php
// pages/suppliers/index.php - รายการซัพพลายเออร์
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
$searchCondition = $search ? "WHERE (supplier_name LIKE ? OR supplier_code LIKE ? OR contact_person LIKE ?)" : "";

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

        // นับจำนวนซัพพลายเออร์ทั้งหมด
        $countQuery = "SELECT COUNT(*) as total_count FROM Suppliers $searchCondition";
        $countStmt = $conn->prepare($countQuery);
        $countStmt->execute($searchParams);
        $countResult = $countStmt->fetch();
        $total_suppliers = $countResult['total_count'];

        // ดึงข้อมูลซัพพลายเออร์ - ใช้ TOP แทน OFFSET/FETCH สำหรับ SQL Server เก่า
        if ($page == 1) {
            // หน้าแรก ใช้ TOP
            $dataQuery = "
                SELECT TOP ($limit) supplier_id, supplier_code, supplier_name, contact_person, phone, email, 
                       address, tax_id, payment_terms, is_active, created_date, created_by
                FROM Suppliers 
                $searchCondition
                ORDER BY supplier_name
            ";
            $stmt = $conn->prepare($dataQuery);
            $stmt->execute($searchParams);
        } else {
            // หน้าอื่นๆ ใช้ OFFSET/FETCH แต่ส่งค่า parameter แยก
            $dataQuery = "
                SELECT supplier_id, supplier_code, supplier_name, contact_person, phone, email, 
                       address, tax_id, payment_terms, is_active, created_date, created_by
                FROM Suppliers 
                $searchCondition
                ORDER BY supplier_name
                OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY
            ";
            $stmt = $conn->prepare($dataQuery);
            $stmt->execute($searchParams);
        }
        
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // ข้อมูล Demo เมื่อไม่มีฐานข้อมูล
        $suppliers = [
            [
                'supplier_id' => 1,
                'supplier_code' => 'SUP001',
                'supplier_name' => 'บริษัท Double A (1991) จำกัด',
                'contact_person' => 'นายสมชาย ใจดี',
                'phone' => '02-123-4567',
                'email' => 'contact@doublea.co.th',
                'address' => '123 ถนนสุขุมวิท กรุงเทพ 10110',
                'tax_id' => '0123456789012',
                'payment_terms' => '30 Days',
                'is_active' => 1,
                'created_date' => '2024-01-01 10:00:00',
                'created_by' => 1
            ],
            [
                'supplier_id' => 2,
                'supplier_code' => 'SUP002',
                'supplier_name' => 'บริษัท UPM Thailand จำกัด',
                'contact_person' => 'Ms. Sarah Johnson',
                'phone' => '02-234-5678',
                'email' => 'info@upm.co.th',
                'address' => '456 ถนนราชดำริ กรุงเทพ 10330',
                'tax_id' => '0987654321098',
                'payment_terms' => '45 Days',
                'is_active' => 1,
                'created_date' => '2024-01-02 11:00:00',
                'created_by' => 1
            ],
            [
                'supplier_id' => 3,
                'supplier_code' => 'SUP003',
                'supplier_name' => 'บริษัท Thai Ink จำกัด',
                'contact_person' => 'นายวิชาย เก่งกาจ',
                'phone' => '02-345-6789',
                'email' => 'sales@thaiink.com',
                'address' => '789 ถนนพระราม 4 กรุงเทพ 10500',
                'tax_id' => '1234567890123',
                'payment_terms' => 'Cash',
                'is_active' => 1,
                'created_date' => '2024-01-03 12:00:00',
                'created_by' => 1
            ]
        ];
        $total_suppliers = count($suppliers);
    }
} catch (Exception $e) {
    $message = "เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: " . $e->getMessage();
    $message_type = "warning";
    
    // ใช้ข้อมูล Demo เมื่อเกิดข้อผิดพลาด
    $suppliers = [
        [
            'supplier_id' => 1,
            'supplier_code' => 'SUP001',
            'supplier_name' => 'บริษัท Double A (1991) จำกัด (Demo)',
            'contact_person' => 'นายสมชาย ใจดี',
            'phone' => '02-123-4567',
            'email' => 'contact@doublea.co.th',
            'address' => '123 ถนนสุขุมวิท กรุงเทพ 10110',
            'tax_id' => '0123456789012',
            'payment_terms' => '30 Days',
            'is_active' => 1,
            'created_date' => '2024-01-01 10:00:00',
            'created_by' => 1
        ]
    ];
    $total_suppliers = 1;
}

// ข้อมูลเริ่มต้น
$message = $_GET['message'] ?? $message ?? '';
$message_type = $_GET['type'] ?? $message_type ?? 'info';

$total_pages = ceil($total_suppliers / $limit);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ซัพพลายเออร์ - <?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?></title>
    
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
}
        
.btn-primary:hover {
    background: var(--primary-gradient-dark);
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(139, 69, 19, 0.3);
}

.btn-success {
    background: linear-gradient(135deg, var(--success-color), #047857);
    border: none;
    border-radius: 10px;
    padding: 12px 30px;
    font-weight: bold;
    transition: all 0.3s ease;
}

.btn-success:hover {
    background: linear-gradient(135deg, #047857, var(--success-color));
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(5, 150, 105, 0.3);
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

.btn-outline-warning {
    border-color: var(--warning-color);
    color: var(--warning-color);
    border-radius: 10px;
    padding: 8px 20px;
    transition: all 0.3s ease;
}

.btn-outline-warning:hover {
    background: linear-gradient(135deg, var(--warning-color), #f59e0b);
    border-color: transparent;
    transform: translateY(-2px);
    color: white;
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
    transform: translateY(-2px);
    color: white;
}
        
.form-control, .form-select {
    border-radius: 10px;
    border: 2px solid rgba(139, 69, 19, 0.2);
    padding: 12px 15px;
    transition: all 0.3s ease;
}
        
.form-control:focus, .form-select:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(139, 69, 19, 0.25);
}
        
.table {
    background: white;
    border-radius: 15px;
    overflow: hidden;
}
        
.table th {
    background: rgba(139, 69, 19, 0.1);
    color: var(--primary-color);
    font-weight: bold;
    border: none;
    padding: 15px;
}
        
.table td {
    border: none;
    padding: 15px;
    vertical-align: middle;
    color: var(--primary-color);
}
        
.table tbody tr:hover {
    background: rgba(139, 69, 19, 0.05);
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
    background: linear-gradient(45deg, var(--success-color), #10b981) !important;
}
        
.badge.bg-danger {
    background: linear-gradient(45deg, var(--danger-color), #ef4444) !important;
}
        
.pagination .page-link {
    border: none;
    color: var(--primary-color);
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
    background: rgba(5, 150, 105, 0.1);
    color: #155724;
    border-left: 4px solid var(--success-color);
}
        
.alert-warning {
    background: rgba(217, 119, 6, 0.1);
    color: #856404;
    border-left: 4px solid var(--warning-color);
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
        
.dropdown-menu {
    border: none;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(139, 69, 19, 0.3);
    background: rgba(255, 255, 255, 0.95);
    border: 1px solid rgba(139, 69, 19, 0.1);
}
        
.dropdown-item {
    border-radius: 10px;
    margin: 2px 5px;
    transition: all 0.3s ease;
    color: var(--primary-color);
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
    box-shadow: 0 10px 30px rgba(139, 69, 19, 0.15);
    border: 1px solid rgba(139, 69, 19, 0.1);
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
    box-shadow: 0 10px 25px rgba(139, 69, 19, 0.2);
    border-color: var(--secondary-color);
}
        
.stats-number {
    font-size: 2.5rem;
    font-weight: bold;
    color: var(--primary-color);
    margin-bottom: 10px;
}
        
.fade-in {
    animation: fadeIn 0.6s ease-in;
}
        
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.container-fluid {
    max-width: 100%;
    padding: 0 20px;
}

.breadcrumb {
    background: none;
    padding: 0;
    margin-bottom: 20px;
}

.breadcrumb-item a {
    color: var(--primary-color);
    text-decoration: none;
}

.breadcrumb-item a:hover {
    color: var(--secondary-color);
}

.breadcrumb-item.active {
    color: var(--accent-color);
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

@media (max-width: 768px) {
    .container-fluid {
        padding: 0 15px;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .stats-card {
        margin-bottom: 15px;
    }
    
    .btn-group .btn {
        padding: 6px 10px;
        font-size: 0.875rem;
    }
}
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="../dashboard.php">
                <i class="fas fa-arrow-left me-2"></i>กลับสู่ Dashboard
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-home me-1"></i> Dashboard
                </a>
            </div>
        </div>
    </nav>

    <!-- Breadcrumb -->
    <div class="container-fluid mt-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard.php">หน้าหลัก</a></li>
                <li class="breadcrumb-item active">ซัพพลายเออร์</li>
            </ol>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="container-fluid">
        
        <!-- Header -->
        <div class="row fade-in">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0">
                                <i class="fas fa-truck me-2"></i>จัดการซัพพลายเออร์
                            </h4>
                            <a href="add.php" class="btn btn-success">
                                <i class="fas fa-plus me-2"></i>เพิ่มซัพพลายเออร์ใหม่
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            จัดการข้อมูลซัพพลายเออร์ เพิ่ม แก้ไข และดูรายการซัพพลายเออร์ทั้งหมด
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

        <!-- Search Section -->
        <div class="search-section fade-in">
            <form method="GET" class="row g-3">
                <div class="col-md-8">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" 
                               value="<?= htmlspecialchars($search) ?>" 
                               placeholder="ค้นหาซัพพลายเออร์ (ชื่อ, รหัส, ผู้ติดต่อ)">
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-search me-2"></i>ค้นหา
                        </button>
                    </div>
                </div>
                <div class="col-md-4">
                    <?php if ($search): ?>
                    <a href="index.php" class="btn btn-outline-secondary">
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
                    <div class="stats-number"><?= number_format($total_suppliers) ?></div>
                    <h6>ซัพพลายเออร์ทั้งหมด</h6>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-number"><?= count(array_filter($suppliers, fn($s) => $s['is_active'] == 1)) ?></div>
                    <h6>ซัพพลายเออร์ที่ใช้งาน</h6>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="stats-number"><?= $db_connected ? '100%' : 'Demo' ?></div>
                    <h6>สถานะระบบ</h6>
                </div>
            </div>
        </div>

        <!-- Suppliers Table -->
        <div class="row fade-in">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>รายการซัพพลายเออร์
                                <?php if ($search): ?>
                                <small class="text-light">(ผลการค้นหา: "<?= htmlspecialchars($search) ?>")</small>
                                <?php endif; ?>
                            </h5>
                            <div class="text-white">
                                แสดง <?= number_format(count($suppliers)) ?> รายการ จากทั้งหมด <?= number_format($total_suppliers) ?> รายการ
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($suppliers)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">ไม่พบข้อมูลซัพพลายเออร์</h5>
                            <p class="text-muted">
                                <?= $search ? 'ไม่พบผลลัพธ์ที่ตรงกับการค้นหา' : 'ยังไม่มีซัพพลายเออร์ในระบบ' ?>
                            </p>
                            <a href="add.php" class="btn btn-primary mt-3">
                                <i class="fas fa-plus me-2"></i>เพิ่มซัพพลายเออร์แรก
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>รหัส</th>
                                        <th>ชื่อซัพพลายเออร์</th>
                                        <th>ผู้ติดต่อ</th>
                                        <th>โทรศัพท์</th>
                                        <th>อีเมล</th>
                                        <th>สถานะ</th>
                                        <th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($suppliers as $supplier): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-primary"><?= htmlspecialchars($supplier['supplier_code']) ?></strong>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?= htmlspecialchars($supplier['supplier_name']) ?></strong>
                                                <?php if ($supplier['address']): ?>
                                                <br><small class="text-muted">
                                                    <i class="fas fa-map-marker-alt me-1"></i>
                                                    <?= htmlspecialchars($supplier['address']) ?>
                                                </small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($supplier['contact_person']) ?></td>
                                        <td>
                                            <?php if ($supplier['phone']): ?>
                                            <a href="tel:<?= htmlspecialchars($supplier['phone']) ?>" class="text-decoration-none">
                                                <i class="fas fa-phone me-1"></i>
                                                <?= htmlspecialchars($supplier['phone']) ?>
                                            </a>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($supplier['email']): ?>
                                            <a href="mailto:<?= htmlspecialchars($supplier['email']) ?>" class="text-decoration-none">
                                                <i class="fas fa-envelope me-1"></i>
                                                <?= htmlspecialchars($supplier['email']) ?>
                                            </a>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($supplier['is_active']): ?>
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
                                            <div class="btn-group" role="group">
                                                <a href="view.php?id=<?= $supplier['supplier_id'] ?>" 
                                                   class="btn btn-outline-primary btn-sm" 
                                                   title="ดูรายละเอียด">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="edit.php?id=<?= $supplier['supplier_id'] ?>" 
                                                   class="btn btn-outline-warning btn-sm" 
                                                   title="แก้ไข">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if ($auth->hasRole('admin')): ?>
                                                <button class="btn btn-outline-danger btn-sm" 
                                                        onclick="confirmDelete(<?= $supplier['supplier_id'] ?>, '<?= htmlspecialchars($supplier['supplier_name']) ?>')"
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
                        แสดง <?= min($offset + 1, $total_suppliers) ?> - <?= min($offset + $limit, $total_suppliers) ?> 
                        จากทั้งหมด <?= number_format($total_suppliers) ?> รายการ
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
                    <p>คุณต้องการลบซัพพลายเออร์ <strong id="supplierName"></strong> ใช่หรือไม่?</p>
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
        function confirmDelete(supplierId, supplierName) {
            document.getElementById('supplierName').textContent = supplierName;
            document.getElementById('confirmDeleteBtn').onclick = function() {
                window.location.href = `delete.php?id=${supplierId}`;
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