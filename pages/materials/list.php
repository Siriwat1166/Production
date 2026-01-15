<?php
// pages/materials/list.php - แก้ไขปัญหา Redirect Loop
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

// เริ่ม session ถ้ายังไม่เริ่ม
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ป้องกัน redirect loop
if (!isset($_SESSION['redirect_check'])) {
    $_SESSION['redirect_check'] = 0;
}
$_SESSION['redirect_check']++;

// ถ้า redirect เกิน 3 ครั้ง ให้หยุด
if ($_SESSION['redirect_check'] > 3) {
    unset($_SESSION['redirect_check']);
    die('Error: Redirect loop detected. Please clear cookies and try again.');
}

try {
    $auth = new Auth();
    $auth->requireLogin();
    
    // รีเซ็ต redirect counter เมื่อผ่าน auth
    unset($_SESSION['redirect_check']);
    
} catch (Exception $e) {
    // ถ้ามีปัญหากับ Auth ให้แสดง error แทนการ redirect
    unset($_SESSION['redirect_check']);
    die('Authentication Error: ' . $e->getMessage() . '<br><a href="../../login.php">Login Again</a>');
}

// ข้อมูลผู้ใช้
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'guest';
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
if ($success_message) {
    unset($_SESSION['success_message']);
}
if ($error_message) {
    unset($_SESSION['error_message']);
}

// ตัวแปรสำหรับการค้นหา
$search_query = $_GET['search'] ?? '';
$material_type_filter = $_GET['material_type'] ?? '';
$group_filter = $_GET['group'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$status_filter = $_GET['status'] ?? '';

// ข้อมูลสำหรับการแบ่งหน้า
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// ตัวแปรสำหรับแสดงข้อความ
$success_message = '';
$error_message = '';

// เชื่อมต่อฐานข้อมูล
try {
    $pdo = new PDO("sqlsrv:server=" . DB_SERVER . ";Database=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // สร้างเงื่อนไข WHERE
    $where_conditions = ['1=1'];
    $params = [];
    
    if (!empty($search_query)) {
        $where_conditions[] = "(mp.SSP_Code LIKE ? OR mp.Name LIKE ? OR mp.Name2 LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }
    
    if (!empty($material_type_filter)) {
        $where_conditions[] = "mp.material_type_id = ?";
        $params[] = $material_type_filter;
    }
    
    if (!empty($group_filter)) {
        $where_conditions[] = "mp.group_id = ?";
        $params[] = $group_filter;
    }
    
    if (!empty($supplier_filter)) {
        $where_conditions[] = "mp.supplier_id = ?";
        $params[] = $supplier_filter;
    }
    
    if (!empty($status_filter)) {
        if ($status_filter === 'active') {
            $where_conditions[] = "mp.is_active = 1";
        } else if ($status_filter === 'inactive') {
            $where_conditions[] = "mp.is_active = 0";
        }
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // นับจำนวนรายการทั้งหมด
    $count_sql = "SELECT COUNT(*) as total 
                  FROM Master_Products_ID mp 
                  LEFT JOIN Material_Types mt ON mp.material_type_id = mt.material_type_id
                  LEFT JOIN Groups g ON mp.group_id = g.id
                  LEFT JOIN Suppliers s ON mp.supplier_id = s.supplier_id
                  WHERE $where_clause";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_records / $per_page);
    
    // ดึงข้อมูล Materials
    $sql = "SELECT TOP $per_page
                mp.id,
                mp.SSP_Code,
                mp.Name,
                mp.Name2,
                mp.is_active,
                mp.status,
                mp.created_date,
                ISNULL(mt.type_name, 'ไม่ระบุ') as material_type_name,
                ISNULL(g.name, 'ไม่ระบุ') as group_name,
                ISNULL(s.supplier_name, 'ไม่ระบุ') as supplier_name,
                ISNULL(u1.unit_name, 'ไม่ระบุ') as unit_name
            FROM Master_Products_ID mp
            LEFT JOIN Material_Types mt ON mp.material_type_id = mt.material_type_id
            LEFT JOIN Groups g ON mp.group_id = g.id
            LEFT JOIN Suppliers s ON mp.supplier_id = s.supplier_id
            LEFT JOIN Units u1 ON mp.Unit_id = u1.unit_id
            WHERE $where_clause
            ORDER BY mp.created_date DESC, mp.id DESC";
    
    // สำหรับหน้าอื่นๆ ใช้ ROW_NUMBER()
    if ($page > 1) {
        $start_row = $offset + 1;
        $end_row = $offset + $per_page;
        
        $sql = "SELECT * FROM (
                    SELECT 
                        mp.id,
                        mp.SSP_Code,
                        mp.Name,
                        mp.Name2,
                        mp.is_active,
                        mp.status,
                        mp.created_date,
                        ISNULL(mt.type_name, 'ไม่ระบุ') as material_type_name,
                        ISNULL(g.name, 'ไม่ระบุ') as group_name,
                        ISNULL(s.supplier_name, 'ไม่ระบุ') as supplier_name,
                        ISNULL(u1.unit_name, 'ไม่ระบุ') as unit_name,
                        ROW_NUMBER() OVER (ORDER BY mp.created_date DESC, mp.id DESC) as row_num
                    FROM Master_Products_ID mp
                    LEFT JOIN Material_Types mt ON mp.material_type_id = mt.material_type_id
                    LEFT JOIN Groups g ON mp.group_id = g.id
                    LEFT JOIN Suppliers s ON mp.supplier_id = s.supplier_id
                    LEFT JOIN Units u1 ON mp.Unit_id = u1.unit_id
                    WHERE $where_clause
                ) ranked
                WHERE ranked.row_num BETWEEN $start_row AND $end_row
                ORDER BY ranked.row_num";
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูลสำหรับ dropdowns
    $material_types = [];
    $groups = [];
    $suppliers = [];
    
    try {
        $stmt = $pdo->query("SELECT material_type_id, type_name FROM Material_Types WHERE is_active = 1 ORDER BY type_name");
        $material_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->query("SELECT id, name FROM Groups WHERE is_active = 1 ORDER BY name");
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->query("SELECT supplier_id, supplier_name FROM Suppliers WHERE is_active = 1 ORDER BY supplier_name");
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // ถ้าไม่มี is_active column
        $stmt = $pdo->query("SELECT material_type_id, type_name FROM Material_Types ORDER BY type_name");
        $material_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->query("SELECT id, name FROM Groups ORDER BY name");
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->query("SELECT supplier_id, supplier_name FROM Suppliers ORDER BY supplier_name");
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
// ดึงสถิติ
    $stats_sql = "SELECT 
                    COUNT(*) as total_materials,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_count
                  FROM Master_Products_ID";
    $stats_stmt = $pdo->query($stats_sql);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = "เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: " . $e->getMessage();
    $materials = [];
    $total_records = 0;
    $total_pages = 0;
    $stats = ['total' => 0, 'active' => 0, 'inactive' => 0];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการวัสดุ - ระบบจัดการคลังวัสดุ</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- Select2 Bootstrap 5 Theme -->
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <style>
    :root {
        --primary-color: #8B4513;
        --secondary-color: #FF8C00;
        --accent-color: #A0522D;
        --success-color: #059669;
        --warning-color: #d97706;
        --danger-color: #dc2626;
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
        color: white;
    }
    
    .navbar .nav-link {
        color: white !important;
        transition: all 0.3s ease;
    }
    
    .navbar .nav-link:hover {
        color: #FFD700 !important;
        transform: translateY(-2px);
    }
    
    .container-fluid {
        max-width: 100%;
        padding: 0 20px;
    }
    
    .card {
        border: none;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(139, 69, 19, 0.15);
        border: 1px solid rgba(139, 69, 19, 0.1);
        margin-bottom: 20px;
        background: rgba(255, 255, 255, 0.95);
    }
    
    .card-header {
        background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
        color: white;
        border-radius: 20px 20px 0 0;
        border-bottom: none;
    }
    
    .stats-card {
        background: rgba(255, 140, 0, 0.05);
        border: 2px solid rgba(255, 140, 0, 0.2);
        border-radius: 15px;
        padding: 20px;
        text-align: center;
        transition: all 0.3s ease;
    }
    
    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(255, 140, 0, 0.2);
        border-color: var(--secondary-color);
    }
    
    .stats-number {
        font-size: 2rem;
        font-weight: bold;
        color: var(--primary-color);
    }
    
    .form-control:focus, .form-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.25);
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
        border: none;
        color: white;
    }
    
    .btn-primary:hover {
        background: linear-gradient(135deg, var(--accent-color), var(--primary-color));
        transform: translateY(-2px);
    }
    
    .btn-success {
        background: linear-gradient(135deg, var(--success-color), #047857);
        border: none;
    }
    
    .table th {
        background: rgba(139, 69, 19, 0.1);
        color: var(--primary-color);
        border: none;
        font-weight: 600;
    }
    
    .table td {
        border-color: rgba(139, 69, 19, 0.1);
        vertical-align: middle;
    }
    
    .table tbody tr:hover {
        background-color: rgba(255, 140, 0, 0.05);
    }
    
    .search-section {
        background: rgba(255, 255, 255, 0.8);
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid rgba(139, 69, 19, 0.1);
    }
    
    .pagination .page-link {
        color: var(--primary-color);
        border-color: rgba(139, 69, 19, 0.2);
    }
    
    .pagination .page-item.active .page-link {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        color: white;
    }
    
    .pagination .page-link:hover {
        color: var(--primary-color);
        background-color: rgba(139, 69, 19, 0.1);
        border-color: var(--accent-color);
    }
    
    .badge.bg-success {
        background: linear-gradient(135deg, #28a745, #20c997) !important;
    }
    
    .badge.bg-secondary {
        background: linear-gradient(135deg, #6c757d, #adb5bd) !important;
    }
    
    .alert-success {
        background: rgba(5, 150, 105, 0.1);
        color: #155724;
        border-left: 4px solid var(--success-color);
        border-radius: 12px;
    }
    
    .alert-danger {
        background: rgba(220, 53, 69, 0.1);
        color: #721c24;
        border-left: 4px solid var(--danger-color);
        border-radius: 12px;
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
    
    .text-primary {
        color: var(--primary-color) !important;
    }
    
    /* Select2 Custom Styling */
    .select2-container--default .select2-selection--single {
        border: 1px solid #ced4da;
        border-radius: 0.375rem;
        height: 38px;
        padding: 0.375rem 0.75rem;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 24px;
        padding-left: 0;
    }
    
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px;
    }
    
    .select2-container--default.select2-container--focus .select2-selection--single {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.25);
    }
    
    .select2-dropdown {
        border-color: var(--primary-color);
    }
    
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: var(--primary-color);
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
    }
</style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="../../pages/dashboard.php">
                <i class="fas fa-arrow-left me-2"></i>กลับสู่ Dashboard
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../../dashboard.php">
                    <i class="fas fa-home me-1"></i> Dashboard
                </a>
                <a class="nav-link" href="../pos/index.php">
                    <i class="fas fa-shopping-cart me-1"></i> PO
                </a>
                <a class="nav-link" href="add.php">
                    <i class="fas fa-plus me-1"></i> เพิ่มวัสดุ
                </a>
            </div>
        </div>
    </nav>

    <!-- Breadcrumb -->
    <div class="container-fluid mt-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item active">รายการวัสดุ</li>
            </ol>
        </nav>
    </div>

    <div class="container-fluid">
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="stats-card">
                    <div class="stats-number"><?= number_format($stats['total_materials']) ?></div>
                    <div class="text-muted">วัสดุทั้งหมด</div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stats-card">
                    <div class="stats-number"><?= number_format($stats['active_count']) ?></div>
                    <div class="text-muted">ใช้งานอยู่</div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stats-card">
                    <div class="stats-number"><?= number_format($stats['inactive_count']) ?></div>
                    <div class="text-muted">ไม่ใช้งาน</div>
                </div>
            </div>
        </div>

        <!-- Search and Filter Section -->
        <div class="search-section">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="search" class="form-label">คำค้นหา</label>
                    <input type="text" 
                           class="form-control" 
                           id="search" 
                           name="search" 
                           value="<?= htmlspecialchars($search_query) ?>"
                           placeholder="รหัส SSP หรือชื่อวัสดุ">
                </div>
                
                <div class="col-md-3">
                    <label for="material_type" class="form-label">ประเภท</label>
                    <select class="form-select" id="material_type" name="material_type">
                        <option value="">-- ทั้งหมด --</option>
                        <?php foreach ($material_types as $type): ?>
                        <option value="<?= $type['material_type_id'] ?>" 
                                <?= $material_type_filter == $type['material_type_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($type['type_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="group" class="form-label">กลุ่ม</label>
                    <select class="form-select" id="group" name="group">
                        <option value="">-- ทั้งหมด --</option>
                        <?php foreach ($groups as $group): ?>
                        <option value="<?= $group['id'] ?>" 
                                <?= $group_filter == $group['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($group['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="supplier" class="form-label">ชื่อซัพพลายเออร์</label>
                    <select class="form-select select2" id="supplier" name="supplier">
                        <option value="">-- ทั้งหมด --</option>
                        <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= $supplier['supplier_id'] ?>" 
                                <?= $supplier_filter == $supplier['supplier_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($supplier['supplier_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="status" class="form-label">สถานะ</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">-- ทั้งหมด --</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>ใช้งาน</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>ไม่ใช้งาน</option>
                    </select>
                </div>
                
                <div class="col-md-9 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search me-1"></i>ค้นหา
                    </button>
                    <a href="list.php" class="btn btn-secondary me-2">
                        <i class="fas fa-sync-alt me-1"></i>รีเซ็ต
                    </a>
                    <a href="add.php" class="btn btn-success">
                        <i class="fas fa-plus me-1"></i>เพิ่มวัสดุใหม่
                    </a>
                </div>
            </form>
        </div>

        <!-- Materials Table -->
        <div class="row">
            <div class="col-12">
                <div class="table-card card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>รายการวัสดุ
                        </h5>
                        <span class="badge bg-light text-dark">
                            <?= number_format($total_records) ?> รายการ
                        </span>
                    </div>
                    
                    <?php if (empty($materials)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h4>ไม่พบข้อมูลวัสดุ</h4>
                        <p>ไม่มีข้อมูลที่ตรงกับเงื่อนไขการค้นหา</p>
                        <a href="add.php" class="btn btn-primary mt-3">
                            <i class="fas fa-plus me-1"></i>เพิ่มวัสดุใหม่
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th width="5%">#</th>
                                    <th width="15%">รหัส SSP</th>
                                    <th width="25%">ชื่อวัสดุ</th>
                                    <th width="15%">ประเภท</th>
                                    <th width="10%">กลุ่ม</th>
                                    <th width="15%">ซัพพลายเออร์</th>
                                    <th width="8%">สถานะ</th>
                                    <th width="7%">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $row_number = $offset + 1;
                                foreach ($materials as $material): 
                                ?>
                                <tr>
                                    <td><?= $row_number++ ?></td>
                                    <td>
                                        <span class="fw-bold text-primary"><?= htmlspecialchars($material['SSP_Code']) ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($material['Name']) ?></div>
                                        <?php if ($material['Name2']): ?>
                                        <small class="text-muted"><?= htmlspecialchars($material['Name2']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($material['material_type_name']) ?></td>
                                    <td><?= htmlspecialchars($material['group_name']) ?></td>
                                    <td><?= htmlspecialchars($material['supplier_name']) ?></td>
                                    <td>
                                        <?php if ($material['is_active']): ?>
                                        <span class="badge bg-success">ใช้งาน</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">ไม่ใช้งาน</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="view.php?id=<?= $material['id'] ?>" 
                                               class="btn btn-outline-primary" title="ดูรายละเอียด">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?= $material['id'] ?>" 
                                               class="btn btn-outline-warning" title="แก้ไข">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if ($auth->hasRole(['admin'])): ?>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="deleteMaterial(<?= $material['id'] ?>, '<?= htmlspecialchars($material['SSP_Code']) ?>')" 
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

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="card-footer">
                        <nav>
                            <ul class="pagination pagination-sm mb-0 justify-content-center">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query(array_diff_key($_GET, ['page' => ''])) ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&<?= http_build_query(array_diff_key($_GET, ['page' => ''])) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query(array_diff_key($_GET, ['page' => ''])) ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                        
                        <div class="text-center mt-2">
                            <small class="text-muted">
                                หน้า <?= $page ?> จาก <?= $total_pages ?> 
                                (<?= number_format($total_records) ?> รายการทั้งหมด)
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
   <script>
        function refreshPage() {
            window.location.reload();
        }
        
        $(document).ready(function() {
            // Initialize Select2 for searchable dropdown
            if ($.fn.select2) {
                $('.select2').select2({
                    theme: 'bootstrap-5',
                    placeholder: 'เลือกหรือค้นหาซัพพลายเออร์',
                    allowClear: true,
                    width: '100%',
                    language: {
                        noResults: function() {
                            return "ไม่พบข้อมูล";
                        },
                        searching: function() {
                            return "กำลังค้นหา...";
                        }
                    }
                });
                console.log('Select2 initialized successfully');
            } else {
                console.error('Select2 library not loaded');
            }
            
            // แสดง tooltip
            $('[title]').tooltip();
            
            // เพิ่มเอฟเฟกต์ hover สำหรับการ์ดสถิติ
            $('.stats-card').hover(
                function() {
                    $(this).find('.stats-number').addClass('text-primary');
                },
                function() {
                    $(this).find('.stats-number').removeClass('text-primary');
                }
            );
            
            console.log('Materials List page loaded successfully');
            console.log('Total materials:', <?= $total_records ?>);
            console.log('Current page:', <?= $page ?>);
            console.log('Theme: Updated to match new Dashboard theme');
        });
        
        function deleteMaterial(materialId, sspCode) {
            if (confirm(`ต้องการลบวัสดุ ${sspCode} หรือไม่?\n\nการดำเนินการนี้ไม่สามารถยกเลิกได้`)) {
                // สร้างฟอร์มเพื่อส่งข้อมูล POST
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'delete.php';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'material_id';
                input.value = materialId;
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Keyboard shortcuts
        $(document).keydown(function(e) {
            // Ctrl + N = เพิ่มวัสดุใหม่
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'add.php';
            }
            
            // F5 = รีเฟรช
            if (e.key === 'F5') {
                e.preventDefault();
                refreshPage();
            }
            
            // Ctrl + F = Focus ช่องค้นหา
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                $('input[name="search"]').focus();
            }
        });
    </script>
</body>
</html>