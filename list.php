<?php
// pages/po/list.php - หน้าแสดงรายการ Purchase Order
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();

// Helper function for input sanitization
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

// เชื่อมต่อฐานข้อมูล
try {
    require_once "../../config/database.php";
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

// ข้อความแจ้ง
$message = '';
$message_type = '';

// ตรวจสอบ success message จาก session (จากหน้า create.php)
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    $message_type = 'success';
    unset($_SESSION['success_message']); // ลบ message หลังแสดงแล้ว
}
// Parameters สำหรับการค้นหาและ Filter
$search = sanitizeInput($_GET['search'] ?? '');
$status_filter = sanitizeInput($_GET['status'] ?? '');
$type_filter = sanitizeInput($_GET['type'] ?? '');
$supplier_filter = intval($_GET['supplier'] ?? 0);
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Pagination - แปลงเป็น integer อย่างชัดเจน
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// ตรวจสอบให้แน่ใจว่าเป็น integer
$offset = intval($offset);
$per_page = intval($per_page);

// สร้าง WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(ph.po_number LIKE ? OR s.supplier_name LIKE ? OR ph.notes LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status_filter)) {
    $where_conditions[] = "ph.status = ?";
    $params[] = $status_filter;
}

if (!empty($type_filter)) {
    if ($type_filter === 'material') {
        $where_conditions[] = "ph.is_material_po = 1";
    } elseif ($type_filter === 'freight') {
        $where_conditions[] = "ph.is_freight_po = 1";
    }
}

if (!empty($supplier_filter)) {
    $where_conditions[] = "ph.supplier_id = ?";
    $params[] = $supplier_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "ph.po_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "ph.po_date <= ?";
    $params[] = $date_to;
}

$where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

try {
    // ทดสอบการเชื่อมต่อฐานข้อมูลก่อน
    $test_query = "SELECT 1 as test";
    $test_stmt = $conn->prepare($test_query);
    $test_stmt->execute();
    $test_result = $test_stmt->fetch();
    
    if (!$test_result) {
        throw new Exception("Database connection test failed");
    }
    
    // นับจำนวนทั้งหมด
    $count_sql = "
        SELECT COUNT(*) as total
        FROM PO_Header ph
        LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
        $where_clause
    ";
    
    $stmt = $conn->prepare($count_sql);
    $stmt->execute($params);
    $count_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_records = $count_result ? intval($count_result['total']) : 0;
    $total_pages = ceil($total_records / $per_page);
    
    // ดึงข้อมูล PO - ใช้ query แบบแยกเพื่อหลีกเลี่ยงปัญหา parameter binding
    $sql = "
        SELECT ph.po_id, ph.po_number, ph.po_date, ph.supplier_id, ph.total_amount, 
               ph.status, ph.is_material_po, ph.is_freight_po, ph.po_category,
               ph.created_date, ph.notes, ph.linked_po_id,
               s.supplier_name, s.supplier_code,
               u.full_name as created_by_name,
               linked_po.po_number as linked_po_number
        FROM PO_Header ph
        LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
        LEFT JOIN Users u ON ph.created_by = u.user_id
        LEFT JOIN PO_Header linked_po ON ph.linked_po_id = linked_po.po_id
        $where_clause
        ORDER BY ph.created_date DESC, ph.po_number DESC
        OFFSET $offset ROWS FETCH NEXT $per_page ROWS ONLY
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $po_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูลสำหรับ Filter Dropdowns
    $supplier_sql = "SELECT supplier_id, supplier_name FROM Suppliers WHERE is_active = 1 ORDER BY supplier_name";
    $stmt = $conn->prepare($supplier_sql);
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // สถิติ
    $stats_sql = "
        SELECT 
            COUNT(*) as total_pos,
            COUNT(CASE WHEN ph.status = 'Draft' THEN 1 END) as draft_count,
            COUNT(CASE WHEN ph.status = 'Approved' THEN 1 END) as approved_count,
            COUNT(CASE WHEN ph.status = 'Completed' THEN 1 END) as completed_count,
            COUNT(CASE WHEN ph.is_material_po = 1 THEN 1 END) as material_count,
            COUNT(CASE WHEN ph.is_freight_po = 1 THEN 1 END) as freight_count,
            ISNULL(SUM(ph.total_amount), 0) as total_value
        FROM PO_Header ph
        LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
        $where_clause
    ";
    
    $stmt = $conn->prepare($stats_sql);
    $stmt->execute($params);
    $stats_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats = $stats_result ? $stats_result : [
        'total_pos' => 0,
        'draft_count' => 0,
        'approved_count' => 0,
        'completed_count' => 0,
        'material_count' => 0,
        'freight_count' => 0,
        'total_value' => 0
    ];
    
} catch (PDOException $e) {
    error_log("Error loading PO list: " . $e->getMessage());
    error_log("SQL Error Code: " . $e->getCode());
    error_log("SQL Error Info: " . print_r($e->errorInfo, true));
    $po_list = [];
    $suppliers = [];
    $stats = [
        'total_pos' => 0,
        'draft_count' => 0,
        'approved_count' => 0,
        'completed_count' => 0,
        'material_count' => 0,
        'freight_count' => 0,
        'total_value' => 0
    ];
    $total_records = 0;
    $total_pages = 0;
    $message = "เกิดข้อผิดพลาดในการโหลดข้อมูล: " . $e->getMessage();
    $message_type = "danger";
} catch (Exception $e) {
    error_log("General error loading PO list: " . $e->getMessage());
    $po_list = [];
    $suppliers = [];
    $stats = [
        'total_pos' => 0,
        'draft_count' => 0,
        'approved_count' => 0,
        'completed_count' => 0,
        'material_count' => 0,
        'freight_count' => 0,
        'total_value' => 0
    ];
    $total_records = 0;
    $total_pages = 0;
    $message = "เกิดข้อผิดพลาดในการโหลดข้อมูล: " . $e->getMessage();
    $message_type = "danger";
}

// Status Colors
$status_colors = [
    'Draft' => 'warning',
    'Approved' => 'success',
    'Partial' => 'info',
    'Completed' => 'primary',
    'Cancelled' => 'danger'
];

// Status Labels
$status_labels = [
    'Draft' => 'แบบร่าง',
    'Approved' => 'อนุมัติแล้ว',
    'Partial' => 'บางส่วน',
    'Completed' => 'เสร็จสิ้น',
    'Cancelled' => 'ยกเลิก'
];
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการ Purchase Order - <?= htmlspecialchars(APP_NAME ?? 'Material Management') ?></title>
    
    <!-- Security Headers -->
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    
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

.navbar-brand, .nav-link {
    color: white !important;
}

.nav-link:hover {
    color: #FFD700 !important;
    transform: translateY(-2px);
    transition: all 0.3s ease;
}

.container-fluid {
    max-width: 100%;
    padding: 0 15px;
    width: 100%;
    margin: 0;
}

.fas {
    color: var(--secondary-color);
}

.navbar .fas {
    color: white;
}
        
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(255, 154, 86, 0.15);
            background: white;
            border: 2px solid #ffe4d1;
        }
        
        .card-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 18px 18px 0 0 !important;
            border-bottom: none;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #ffe4d1;
            padding: 10px 15px;
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
            padding: 12px 25px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: var(--primary-gradient-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 154, 86, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: bold;
        }
        
        .btn-outline-primary {
            border: 2px solid #ff9a56;
            color: #ff7f50;
            border-radius: 10px;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: #ff9a56;
        }
        
        .alert {
            border-radius: 15px;
            border: none;
        }
        
        .table {
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .table thead th {
            background: linear-gradient(135deg, #34495e 0%, #2c3e50 100%);
            color: white;
            border: none;
            font-weight: 600;
            padding: 15px 12px;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:hover {
            background-color: #fff3e0;
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(255, 154, 86, 0.2);
        }
        
        .table td {
            padding: 12px;
            vertical-align: middle;
            border-color: #ffe4d1;
        }
        
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            margin-bottom: 20px;
        }
        
        .stats-item {
            text-align: center;
            padding: 20px 10px;
        }
        
        .stats-number {
            font-size: 2em;
            font-weight: bold;
            display: block;
        }
        
        .stats-label {
            font-size: 0.9em;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        .pagination .page-link {
            border: 2px solid #ffe4d1;
            color: #ff7f50;
            border-radius: 8px;
            margin: 0 3px;
            padding: 10px 15px;
        }
        
        .pagination .page-link:hover {
            background-color: #ff9a56;
            border-color: #ff9a56;
            color: white;
        }
        
        .pagination .page-item.active .page-link {
            background: var(--primary-gradient);
            border-color: #ff9a56;
            color: white;
        }
        
        .badge-status {
            font-size: 0.8em;
            padding: 5px 10px;
            border-radius: 20px;
        }
        
        .po-number {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 0.95em;
        }
        
        .search-section {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(255, 154, 86, 0.1);
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.5s ease;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 0.8em;
            border-radius: 6px;
        }
        
        .table-responsive {
            border-radius: 15px;
            overflow: hidden;
        }
        
        .linked-po {
            font-size: 0.8em;
            color: #666;
        }
        
        .po-type-badge {
            font-size: 0.7em;
            padding: 3px 8px;
            border-radius: 12px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="../dashboard.php">
                <i class="fas fa-list-alt me-2"></i><?= htmlspecialchars(APP_NAME ?? 'Material Management') ?>
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="create.php">
                    <i class="fas fa-plus me-1"></i> สร้าง PO ใหม่
                </a>
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-arrow-left me-1"></i> กลับสู่หน้าหลัก
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container-fluid mt-4" style="padding-top: 20px;">
        <!-- Header -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-4 fade-in-up">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0">
                                <i class="fas fa-clipboard-list me-2"></i>รายการ Purchase Order
                            </h4>
                            <a href="create.php" class="btn btn-success">
                                <i class="fas fa-plus me-2"></i>สร้าง PO ใหม่
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show fade-in-up" role="alert">
            <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <?php if (!empty($stats)): ?>
        <div class="stats-card fade-in-up">
            <div class="row g-0">
                <div class="col-md-2">
                    <div class="stats-item">
                        <span class="stats-number"><?= number_format($stats['total_pos']) ?></span>
                        <div class="stats-label">PO ทั้งหมด</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stats-item">
                        <span class="stats-number"><?= number_format($stats['draft_count']) ?></span>
                        <div class="stats-label">แบบร่าง</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stats-item">
                        <span class="stats-number"><?= number_format($stats['approved_count']) ?></span>
                        <div class="stats-label">อนุมัติแล้ว</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stats-item">
                        <span class="stats-number"><?= number_format($stats['material_count']) ?></span>
                        <div class="stats-label">PO วัตถุดิบ</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stats-item">
                        <span class="stats-number"><?= number_format($stats['freight_count']) ?></span>
                        <div class="stats-label">PO ค่าขนส่ง</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stats-item">
                        <span class="stats-number">฿<?= number_format($stats['total_value'], 2) ?></span>
                        <div class="stats-label">มูลค่ารวม</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Search and Filter -->
        <div class="search-section fade-in-up">
            <form method="GET" id="searchForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">ค้นหา</label>
                        <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" 
                               placeholder="เลข PO, Supplier, หมายเหตุ">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">สถานะ</label>
                        <select class="form-select" name="status">
                            <option value="">ทั้งหมด</option>
                            <option value="Draft" <?= $status_filter === 'Draft' ? 'selected' : '' ?>>แบบร่าง</option>
                            <option value="Approved" <?= $status_filter === 'Approved' ? 'selected' : '' ?>>อนุมัติแล้ว</option>
                            <option value="Partial" <?= $status_filter === 'Partial' ? 'selected' : '' ?>>บางส่วน</option>
                            <option value="Completed" <?= $status_filter === 'Completed' ? 'selected' : '' ?>>เสร็จสิ้น</option>
                            <option value="Cancelled" <?= $status_filter === 'Cancelled' ? 'selected' : '' ?>>ยกเลิก</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">ประเภท</label>
                        <select class="form-select" name="type">
                            <option value="">ทั้งหมด</option>
                            <option value="material" <?= $type_filter === 'material' ? 'selected' : '' ?>>วัตถุดิบ</option>
                            <option value="freight" <?= $type_filter === 'freight' ? 'selected' : '' ?>>ค่าขนส่ง</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Supplier</label>
                        <select class="form-select" name="supplier">
                            <option value="">ทั้งหมด</option>
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= $supplier['supplier_id'] ?>" <?= $supplier_filter == $supplier['supplier_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($supplier['supplier_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">จาก</label>
                        <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">ถึง</label>
                        <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <div class="d-grid w-100">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-1"></i>ค้นหา
                            </button>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-12 text-end">
                        <a href="?" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-times me-1"></i>ล้างตัวกรอง
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- PO List -->
        <div class="card fade-in-up">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-table me-2"></i>รายการ PO
                        <?php if ($total_records > 0): ?>
                        <span class="badge bg-light text-dark ms-2"><?= number_format($total_records) ?> รายการ</span>
                        <?php endif; ?>
                    </h5>
                    <small class="text-light">
                        หน้า <?= $page ?> จาก <?= $total_pages ?> | แสดง <?= count($po_list) ?> จาก <?= number_format($total_records) ?> รายการ
                    </small>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($po_list)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">ไม่พบข้อมูล PO</h5>
                    <p class="text-muted">ลองปรับเงื่อนไขการค้นหา หรือ <a href="create.php">สร้าง PO ใหม่</a></p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th width="12%">เลขที่ PO</th>
                                <th width="8%">ประเภท</th>
                                <th width="20%">Supplier</th>
                                <th width="8%">วันที่</th>
                                <th width="10%">มูลค่า</th>
                                <th width="8%">สถานะ</th>
                                <th width="12%">เชื่อมโยง</th>
                                <th width="10%">สร้างโดย</th>
                                <th width="12%">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($po_list as $po): ?>
                            <tr>
                                <td>
                                    <div class="po-number"><?= htmlspecialchars($po['po_number']) ?></div>
                                    <?php if (!empty($po['notes'])): ?>
                                    <small class="text-muted">
                                        <i class="fas fa-sticky-note me-1"></i><?= htmlspecialchars(substr($po['notes'], 0, 50)) ?><?= strlen($po['notes']) > 50 ? '...' : '' ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($po['is_material_po']): ?>
                                    <span class="badge bg-primary po-type-badge">
                                        <i class="fas fa-box me-1"></i>วัตถุดิบ
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($po['is_freight_po']): ?>
                                    <span class="badge bg-info po-type-badge">
                                        <i class="fas fa-truck me-1"></i>ค่าขนส่ง
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($po['supplier_name']) ?></div>
                                    <?php if (!empty($po['supplier_code'])): ?>
                                    <small class="text-muted"><?= htmlspecialchars($po['supplier_code']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d/m/Y', strtotime($po['po_date'])) ?></td>
                                <td class="text-end">
                                    <strong>฿<?= number_format($po['total_amount'], 2) ?></strong>
                                </td>
                                <td>
                                    <?php
                                    $status_color = $status_colors[$po['status']] ?? 'secondary';
                                    $status_label = $status_labels[$po['status']] ?? $po['status'];
                                    ?>
                                    <span class="badge bg-<?= $status_color ?> badge-status"><?= $status_label ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($po['linked_po_number'])): ?>
                                    <div class="linked-po">
                                        <i class="fas fa-link me-1"></i>
                                        <a href="?search=<?= urlencode($po['linked_po_number']) ?>" class="text-decoration-none">
                                            <?= htmlspecialchars($po['linked_po_number']) ?>
                                        </a>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($po['created_by_name'] ?? 'N/A') ?>
                                    <br><small class="text-muted"><?= date('d/m/y H:i', strtotime($po['created_date'])) ?></small>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="view.php?id=<?= $po['po_id'] ?>" class="btn btn-outline-primary btn-sm" title="ดู">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($auth->hasRole(['editor', 'admin']) && in_array($po['status'], ['Draft', 'Approved'])): ?>
                                        <a href="edit.php?id=<?= $po['po_id'] ?>" class="btn btn-outline-warning btn-sm" title="แก้ไข">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($auth->hasRole('admin')): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm" title="ลบ" 
                                                onclick="confirmDelete(<?= $po['po_id'] ?>, '<?= htmlspecialchars($po['po_number']) ?>')">
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

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="card-footer">
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center mb-0">
                        <!-- Previous Page -->
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                <i class="fas fa-chevron-left"></i> ก่อนหน้า
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <!-- Page Numbers -->
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                        </li>
                        <?php if ($start_page > 2): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                        <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><?= $total_pages ?></a>
                        </li>
                        <?php endif; ?>
                        
                        <!-- Next Page -->
                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                ถัดไป <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                
                <div class="text-center mt-3">
                    <small class="text-muted">
                        แสดงรายการ <?= ($page - 1) * $per_page + 1 ?> - <?= min($page * $per_page, $total_records) ?> 
                        จากทั้งหมด <?= number_format($total_records) ?> รายการ
                    </small>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-bolt me-2"></i>การดำเนินการด่วน</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="create.php" class="btn btn-success">
                                <i class="fas fa-plus me-2"></i>สร้าง PO ใหม่
                            </a>
                            <a href="?status=Draft" class="btn btn-outline-warning">
                                <i class="fas fa-edit me-2"></i>ดู PO แบบร่าง (<?= $stats['draft_count'] ?? 0 ?>)
                            </a>
                            <a href="?status=Approved" class="btn btn-outline-success">
                                <i class="fas fa-check me-2"></i>ดู PO ที่อนุมัติแล้ว (<?= $stats['approved_count'] ?? 0 ?>)
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>สรุปรายงาน</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="h5 mb-1 text-primary"><?= number_format($stats['total_pos'] ?? 0) ?></div>
                                    <small>PO ทั้งหมด</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="h5 mb-1 text-success">฿<?= number_format($stats['total_value'] ?? 0, 0) ?></div>
                                    <small>มูลค่ารวม</small>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="row g-2">
                            <div class="col-6">
                                <a href="?type=material" class="btn btn-outline-primary btn-sm w-100">
                                    <i class="fas fa-box me-1"></i>วัตถุดิบ (<?= $stats['material_count'] ?? 0 ?>)
                                </a>
                            </div>
                            <div class="col-6">
                                <a href="?type=freight" class="btn btn-outline-info btn-sm w-100">
                                    <i class="fas fa-truck me-1"></i>ค่าขนส่ง (<?= $stats['freight_count'] ?? 0 ?>)
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer spacing -->
        <div class="pb-4"></div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle text-warning me-2"></i>ยืนยันการลบ
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">คุณต้องการลบ PO นี้ใช่หรือไม่?</p>
                    <div class="alert alert-warning">
                        <strong>เลขที่ PO:</strong> <span id="deletePoNumber"></span><br>
                        <small><i class="fas fa-info-circle me-1"></i>การลบจะไม่สามารถกู้คืนได้</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="button" class="btn btn-danger" onclick="deletePO()">
                        <i class="fas fa-trash me-1"></i>ลบ PO
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let deletePoId = null;
        
        // Helper Functions
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show fade-in-up`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const container = document.querySelector('.container-fluid');
            container.insertBefore(alertDiv, container.children[2]);
            
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Delete Confirmation
        function confirmDelete(poId, poNumber) {
            deletePoId = poId;
            document.getElementById('deletePoNumber').textContent = poNumber;
            
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }

        // Delete PO
        async function deletePO() {
            if (!deletePoId) return;
            
            try {
                const response = await fetch('delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        po_id: deletePoId
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('ลบ PO เรียบร้อยแล้ว', 'success');
                    // Reload page after delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert('เกิดข้อผิดพลาด: ' + (result.message || 'ไม่สามารถลบ PO ได้'), 'danger');
                }
            } catch (error) {
                showAlert('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'danger');
            }
            
            // Hide modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
            modal.hide();
            
            deletePoId = null;
        }

        // Auto-submit search form on change
        document.addEventListener('DOMContentLoaded', function() {
            const searchInputs = ['select[name="status"]', 'select[name="type"]', 'select[name="supplier"]'];
            
            searchInputs.forEach(selector => {
                const element = document.querySelector(selector);
                if (element) {
                    element.addEventListener('change', function() {
                        document.getElementById('searchForm').submit();
                    });
                }
            });
            
            // Search on Enter key
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        document.getElementById('searchForm').submit();
                    }
                });
            }

            // Show loading state for table
            const table = document.querySelector('.table');
            if (table && table.dataset.loading === 'true') {
                table.style.opacity = '0.6';
            }
            
            console.log('✅ PO List Page initialized successfully');
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+N หรือ Cmd+N = สร้าง PO ใหม่
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'create.php';
            }
            
            // F3 = Focus search
            if (e.key === 'F3') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
        });

        // Highlight current filters
        document.addEventListener('DOMContentLoaded', function() {
            const params = new URLSearchParams(window.location.search);
            const hasFilters = Array.from(params.entries()).some(([key, value]) => 
                key !== 'page' && value !== ''
            );
            
            if (hasFilters) {
                const searchSection = document.querySelector('.search-section');
                if (searchSection) {
                    searchSection.style.borderLeft = '5px solid #ff9a56';
                }
            }
        });

        // Table row click to view
        document.querySelectorAll('.table tbody tr').forEach(row => {
            row.style.cursor = 'pointer';
            row.addEventListener('click', function(e) {
                // Skip if clicked on action buttons
                if (e.target.closest('.action-buttons')) return;
                
                const viewLink = this.querySelector('a[href*="view.php"]');
                if (viewLink) {
                    window.location.href = viewLink.href;
                }
            });
        });
    </script>
</body>
</html>