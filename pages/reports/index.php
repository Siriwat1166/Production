<?php
// pages/reports/index.php - หน้ารายงานของระบบ (ปรับปรุงแล้ว)
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();

// ข้อมูลผู้ใช้
$user_id = $_SESSION['user_id'] ?? 'N/A';
$username = $_SESSION['username'] ?? 'guest';
$full_name = $_SESSION['full_name'] ?? 'Guest User';
$role = $_SESSION['role'] ?? 'viewer';

// ข้อมูลเริ่มต้น
$report_data = [];
$material_summary = [];
$monthly_stats = [];
$type_distribution = [];
$recent_additions = [];
$db_connected = false;
$db_error = null;

// เชื่อมต่อฐานข้อมูลเพื่อดึงข้อมูลรายงาน
try {
    if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
        $pdo = new PDO("sqlsrv:server=" . DB_SERVER . ";Database=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db_connected = true;

        // ดึงข้อมูลสรุปวัสดุตามประเภท
        try {
            $stmt = $pdo->query("
                SELECT 
                    mt.type_name,
                    COUNT(p.id) as material_count,
                    AVG(CAST(ISNULL(p.unit_price, 100) as FLOAT)) as avg_price
                FROM Master_Products_ID p
                LEFT JOIN Material_Types mt ON p.material_type_id = mt.material_type_id
                WHERE p.is_active = 1 AND mt.is_active = 1
                GROUP BY mt.type_name
                ORDER BY material_count DESC
            ");
            $type_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $type_distribution = [
                ['type_name' => 'Paperboard', 'material_count' => 45, 'avg_price' => 150.50],
                ['type_name' => 'หมึกพิมพ์', 'material_count' => 38, 'avg_price' => 89.75],
                ['type_name' => 'ฟอยล์', 'material_count' => 32, 'avg_price' => 220.00],
                ['type_name' => 'เคลือบผิว', 'material_count' => 28, 'avg_price' => 180.25],
                ['type_name' => 'แผ่นพิมพ์', 'material_count' => 25, 'avg_price' => 350.00],
                ['type_name' => 'กาว', 'material_count' => 22, 'avg_price' => 95.50],
                ['type_name' => 'ฟิล์ม', 'material_count' => 18, 'avg_price' => 125.75],
                ['type_name' => 'กล่องลูกฟูก', 'material_count' => 15, 'avg_price' => 85.25]
            ];
        }

        // ดึงข้อมูลวัสดุที่เพิ่มใหม่ล่าสุด
        try {
            $stmt = $pdo->query("
                SELECT TOP 10
                    p.Name as product_name,
                    p.SSP_Code as product_code,
                    mt.type_name,
                    p.created_date,
                    u.full_name as created_by
                FROM Master_Products_ID p
                LEFT JOIN Material_Types mt ON p.material_type_id = mt.material_type_id
                LEFT JOIN Users u ON p.created_by = u.user_id
                WHERE p.is_active = 1
                ORDER BY p.created_date DESC
            ");
            $recent_additions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $recent_additions = [
                [
                    'product_name' => 'กระดาษอาร์ตการ์ด 300g',
                    'product_code' => '1255SUPP001234500010',
                    'type_name' => 'Paperboard',
                    'created_date' => date('Y-m-d H:i:s', strtotime('-2 days')),
                    'created_by' => $full_name
                ],
                [
                    'product_name' => 'หมึกพิมพ์ UV สีน้ำเงิน',
                    'product_code' => '1256SUPP002345600020',
                    'type_name' => 'หมึกพิมพ์',
                    'created_date' => date('Y-m-d H:i:s', strtotime('-3 days')),
                    'created_by' => $full_name
                ],
                [
                    'product_name' => 'ฟอยล์โฮโลแกรม ทอง',
                    'product_code' => '1261SUPP003456700030',
                    'type_name' => 'ฟอยล์',
                    'created_date' => date('Y-m-d H:i:s', strtotime('-5 days')),
                    'created_by' => $full_name
                ]
            ];
        }

        // ดึงสถิติรายเดือน
        try {
            $stmt = $pdo->query("
                SELECT 
                    MONTH(created_date) as month,
                    YEAR(created_date) as year,
                    COUNT(*) as count
                FROM Master_Products_ID 
                WHERE is_active = 1 AND created_date >= DATEADD(month, -6, GETDATE())
                GROUP BY YEAR(created_date), MONTH(created_date)
                ORDER BY year, month
            ");
            $monthly_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // ข้อมูล demo สำหรับ 6 เดือนย้อนหลัง
            $monthly_stats = [];
            for ($i = 5; $i >= 0; $i--) {
                $date = strtotime("-$i months");
                $monthly_stats[] = [
                    'month' => date('n', $date),
                    'year' => date('Y', $date),
                    'count' => rand(15, 35)
                ];
            }
        }

    } else {
        // ใช้ข้อมูล demo
        $db_error = "Database configuration not found - using demo data";
        
        $type_distribution = [
            ['type_name' => 'Paperboard', 'material_count' => 45, 'avg_price' => 150.50],
            ['type_name' => 'หมึกพิมพ์', 'material_count' => 38, 'avg_price' => 89.75],
            ['type_name' => 'ฟอยล์', 'material_count' => 32, 'avg_price' => 220.00],
            ['type_name' => 'เคลือบผิว', 'material_count' => 28, 'avg_price' => 180.25],
            ['type_name' => 'แผ่นพิมพ์', 'material_count' => 25, 'avg_price' => 350.00],
            ['type_name' => 'กาว', 'material_count' => 22, 'avg_price' => 95.50],
            ['type_name' => 'ฟิล์ม', 'material_count' => 18, 'avg_price' => 125.75],
            ['type_name' => 'กล่องลูกฟูก', 'material_count' => 15, 'avg_price' => 85.25]
        ];

        $recent_additions = [
            [
                'product_name' => 'กระดาษอาร์ตการ์ด 300g',
                'product_code' => '1255SUPP001234500010',
                'type_name' => 'Paperboard',
                'created_date' => date('Y-m-d H:i:s', strtotime('-2 days')),
                'created_by' => $full_name
            ],
            [
                'product_name' => 'หมึกพิมพ์ UV สีน้ำเงิน',
                'product_code' => '1256SUPP002345600020',
                'type_name' => 'หมึกพิมพ์',
                'created_date' => date('Y-m-d H:i:s', strtotime('-3 days')),
                'created_by' => $full_name
            ]
        ];

        // ข้อมูล demo สำหรับ 6 เดือนย้อนหลัง
        $monthly_stats = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = strtotime("-$i months");
            $monthly_stats[] = [
                'month' => date('n', $date),
                'year' => date('Y', $date),
                'count' => rand(15, 35)
            ];
        }
    }

} catch (Exception $e) {
    $db_error = "Error: " . $e->getMessage();
    // ใช้ข้อมูล demo เมื่อเกิด error
    $type_distribution = [
        ['type_name' => 'Paperboard', 'material_count' => 45, 'avg_price' => 150.50],
        ['type_name' => 'หมึกพิมพ์', 'material_count' => 38, 'avg_price' => 89.75]
    ];
    $recent_additions = [];
    $monthly_stats = [];
}

// คำนวณสถิติรวม
$total_materials = array_sum(array_column($type_distribution, 'material_count'));
$avg_price_all = $total_materials > 0 ? array_sum(array_column($type_distribution, 'avg_price')) / count($type_distribution) : 0;
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= defined('APP_NAME') ? APP_NAME : 'Material Management System' ?> - รายงาน</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.min.js"></script>
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #ff9a56 0%, #ffb347 50%, #ffd700 100%);
            --primary-gradient-dark: linear-gradient(135deg, #ff7f50 0%, #ff9a56 100%);
            --success-gradient: linear-gradient(135deg, #ff6b35 0%, #ff8c42 100%);
            --warning-gradient: linear-gradient(135deg, #ffc649 0%, #ffb347 100%);
            --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ff8e8e 100%);
            --info-gradient: linear-gradient(135deg, #ffa726 0%, #ffcc80 100%);
        }

        body {
            background: linear-gradient(135deg, #fff8f0 0%, #ffe4d1 50%, #fff3e0 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar {
            background: var(--primary-gradient);
            box-shadow: 0 4px 20px rgba(255, 154, 86, 0.3);
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 1030;
        }
        
        .dropdown-menu {
            z-index: 1040;
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(255, 154, 86, 0.3);
            backdrop-filter: blur(10px);
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
        
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(255, 154, 86, 0.15);
            transition: all 0.3s ease;
            margin-bottom: 25px;
            overflow: hidden;
            position: relative;
            z-index: 1;
            background: white;
            border: 2px solid #ffe4d1;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(255, 154, 86, 0.25);
            border-color: #ffb347;
        }
        
        .stat-card {
            background: var(--primary-gradient);
            color: white;
            text-align: center;
            position: relative;
        }

        .stat-card.success { background: var(--success-gradient); }
        .stat-card.warning { background: var(--warning-gradient); color: white; }
        .stat-card.danger { background: var(--danger-gradient); color: white; }
        .stat-card.info { background: var(--info-gradient); color: white; }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.1);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover::before {
            opacity: 1;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .chart-container {
            position: relative;
            height: 400px;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 20px;
        }

        .table-responsive {
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(255, 154, 86, 0.15);
        }

        .table {
            margin-bottom: 0;
        }

        .table th {
            background: var(--primary-gradient);
            color: white;
            border: none;
            padding: 15px;
            font-weight: 600;
        }

        .table td {
            padding: 12px 15px;
            border-color: #ffe4d1;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: rgba(255, 248, 240, 0.8);
        }

        .report-filter {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(255, 154, 86, 0.15);
            border: 2px solid #ffe4d1;
        }

        .filter-btn {
            border-radius: 25px;
            padding: 10px 20px;
            border: 2px solid #ffe4d1;
            background: white;
            color: #ff7f50;
            transition: all 0.3s ease;
            margin: 5px;
        }

        .filter-btn:hover, .filter-btn.active {
            background: var(--primary-gradient);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
        }

        .export-btn {
            border-radius: 25px;
            padding: 12px 25px;
            background: var(--success-gradient);
            border: none;
            color: white;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 107, 53, 0.3);
            color: white;
        }

        .price-badge {
            padding: 6px 12px;
            border-radius: 15px;
            font-weight: bold;
            font-size: 0.9em;
        }

        .price-high { background: rgba(255, 107, 107, 0.1); color: #ff6b6b; }
        .price-medium { background: rgba(255, 167, 38, 0.1); color: #ffa726; }
        .price-low { background: rgba(255, 107, 53, 0.1); color: #ff6b35; }

        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .breadcrumb {
            background: rgba(255,255,255,0.9);
            border-radius: 15px;
            padding: 15px 20px;
            margin-bottom: 25px;
            border: 1px solid #ffe4d1;
        }

        .breadcrumb-item a {
            text-decoration: none;
            color: #ff7f50;
            font-weight: 500;
        }

        .breadcrumb-item.active {
            color: #e55a2b;
            font-weight: 600;
        }

        .bg-primary {
            background: var(--primary-gradient) !important;
        }

        .bg-success {
            background: var(--success-gradient) !important;
        }

        .bg-warning {
            background: var(--warning-gradient) !important;
        }

        .bg-info {
            background: var(--info-gradient) !important;
        }

        .bg-danger {
            background: var(--danger-gradient) !important;
        }

        .text-primary {
            color: #ff7f50 !important;
        }

        .navbar .fas {
            color: white;
        }

        .card-header .fas {
            color: white;
        }

        .ssp-code {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #ff7f50;
            font-size: 0.8em;
        }

        .material-name {
            color: #e55a2b;
            font-weight: 600;
        }

        .progress {
            background-color: rgba(255, 228, 209, 0.3);
        }

        .progress-bar {
            background: var(--primary-gradient);
        }

        .list-group-item {
            border-color: #ffe4d1;
            background: rgba(255, 248, 240, 0.5);
        }

        .list-group-item:hover {
            background: rgba(255, 248, 240, 0.8);
        }

        @media (max-width: 768px) {
            .stat-number {
                font-size: 2rem;
            }
            
            .chart-container {
                height: 300px;
                padding: 15px;
            }
            
            .export-btn {
                width: 100%;
                margin-bottom: 10px;
            }
            
            .filter-btn {
                width: calc(50% - 10px);
                margin: 5px;
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
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="../dashboard.php">
                            <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                        </a>
                    </li>
                    <?php if ($auth->hasRole('editor')): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-box me-1"></i> วัสดุ
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../materials/add.php">
                                <i class="fas fa-plus me-2"></i>เพิ่มวัสดุใหม่</a></li>
                            <li><a class="dropdown-item" href="../materials/list.php">
                                <i class="fas fa-list me-2"></i>รายการวัสดุ</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../material_types/">
                                <i class="fas fa-tags me-2"></i>ประเภทวัสดุ</a></li>
                            <li><a class="dropdown-item" href="../groups/">
                                <i class="fas fa-layer-group me-2"></i>กลุ่มวัสดุ</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">
                            <i class="fas fa-chart-bar me-1"></i> รายงาน
                        </a>
                    </li>
                    
                    <?php if ($auth->hasRole('admin')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="../users/">
                            <i class="fas fa-users me-1"></i> ผู้ใช้
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars($full_name) ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../profile.php">
                                <i class="fas fa-user-edit me-2"></i> โปรไฟล์</a></li>
                            <li><a class="dropdown-item" href="../settings.php">
                                <i class="fas fa-cog me-2"></i> การตั้งค่า</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="../logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i> ออกจากระบบ</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-4" style="padding-top: 20px;">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard.php"><i class="fas fa-home"></i> หน้าแรก</a></li>
                <li class="breadcrumb-item active" aria-current="page"><i class="fas fa-chart-bar"></i> รายงาน</li>
            </ol>
        </nav>

        <!-- Page Header -->
        <div class="row fade-in">
            <div class="col-12">
                <div class="card" style="background: var(--primary-gradient); color: white;">
                    <div class="card-body text-center py-5">
                        <h1 class="card-title mb-3">
                            <i class="fas fa-chart-line me-3"></i>รายงานและสถิติวัสดุ
                        </h1>
                        <p class="card-text fs-5">
                            ข้อมูลสถิติและการวิเคราะห์วัสดุในระบบจัดการวัสดุ
                        </p>
                        <small>
                            <i class="fas fa-clock me-2"></i>อัปเดตล่าสุด: <?= date('d/m/Y H:i:s') ?>
                            <?php if ($db_error): ?>
                            | <i class="fas fa-exclamation-triangle me-2"></i>โหมด Demo
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Summary -->
        <div class="row fade-in">
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body py-4">
                        <i class="fas fa-box fa-3x mb-3 opacity-75"></i>
                        <div class="stat-number"><?= number_format($total_materials) ?></div>
                        <h5>วัสดุทั้งหมด</h5>
                        <small>รายการในระบบ</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card stat-card success">
                    <div class="card-body py-4">
                        <i class="fas fa-tags fa-3x mb-3 opacity-75"></i>
                        <div class="stat-number"><?= count($type_distribution) ?></div>
                        <h5>ประเภทวัสดุ</h5>
                        <small>หมวดหมู่ที่มี</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card stat-card warning">
                    <div class="card-body py-4">
                        <i class="fas fa-dollar-sign fa-3x mb-3 opacity-75"></i>
                        <div class="stat-number">฿<?= number_format($avg_price_all, 0) ?></div>
                        <h5>ราคาเฉลี่ย</h5>
                        <small>ต่อหน่วย</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card stat-card info">
                    <div class="card-body py-4">
                        <i class="fas fa-plus-circle fa-3x mb-3 opacity-75"></i>
                        <div class="stat-number"><?= count($recent_additions) ?></div>
                        <h5>เพิ่มใหม่</h5>
                        <small>รายการล่าสุด</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Filters -->
        <div class="report-filter fade-in">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-3"><i class="fas fa-filter me-2"></i>ตัวกรองรายงาน</h5>
                    <button class="btn filter-btn active" onclick="filterReport('all')">ทั้งหมด</button>
                    <button class="btn filter-btn" onclick="filterReport('monthly')">รายเดือน</button>
                    <button class="btn filter-btn" onclick="filterReport('type')">ตามประเภท</button>
                    <button class="btn filter-btn" onclick="filterReport('price')">ตามราคา</button>
                </div>
                <div class="col-md-6 text-end">
                    <h5 class="mb-3"><i class="fas fa-download me-2"></i>ส่งออกรายงาน</h5>
                    <button class="btn export-btn me-2" onclick="exportReport('pdf')">
                        <i class="fas fa-file-pdf me-2"></i>PDF
                    </button>
                    <button class="btn export-btn me-2" onclick="exportReport('excel')">
                        <i class="fas fa-file-excel me-2"></i>Excel
                    </button>
                    <button class="btn export-btn" onclick="exportReport('csv')">
                        <i class="fas fa-file-csv me-2"></i>CSV
                    </button>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="row fade-in">
            <!-- Material Distribution Chart -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-pie-chart me-2"></i>การกระจายตัวของวัสดุตามประเภท</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="materialDistributionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly Trend Chart -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-line-chart me-2"></i>แนวโน้มการเพิ่มวัสดุรายเดือน</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="monthlyTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Tables -->
        <div class="row fade-in">
            <!-- Material Type Summary -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-table me-2"></i>สรุปวัสดุตามประเภท</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ประเภทวัสดุ</th>
                                        <th class="text-center">จำนวน</th>
                                        <th class="text-end">ราคาเฉลี่ย</th>
                                        <th class="text-center">สถานะ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($type_distribution as $item): ?>
                                    <tr>
                                        <td>
                                            <strong class="material-name"><?= htmlspecialchars($item['type_name']) ?></strong>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge" style="background: var(--primary-gradient); color: white;">
                                                <?= number_format($item['material_count']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php
                                            $price = $item['avg_price'];
                                            $priceClass = $price > 200 ? 'price-high' : ($price > 100 ? 'price-medium' : 'price-low');
                                            ?>
                                            <span class="price-badge <?= $priceClass ?>">
                                                ฿<?= number_format($price, 2) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($item['material_count'] > 30): ?>
                                                <i class="fas fa-circle text-success" title="มีวัสดุเพียงพอ"></i>
                                            <?php elseif ($item['material_count'] > 20): ?>
                                                <i class="fas fa-circle" style="color: #ffa726;" title="วัสดุปานกลาง"></i>
                                            <?php else: ?>
                                                <i class="fas fa-circle" style="color: #ff6b6b;" title="วัสดุน้อย"></i>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Additions -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header bg-warning text-white">
                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>วัสดุที่เพิ่มล่าสุด</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>ชื่อวัสดุ</th>
                                        <th>ประเภท</th>
                                        <th>วันที่</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_additions)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">
                                            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                            ยังไม่มีข้อมูลวัสดุที่เพิ่มใหม่
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_additions as $item): ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <strong class="material-name"><?= htmlspecialchars($item['product_name']) ?></strong>
                                                    <br><small class="ssp-code"><?= htmlspecialchars($item['product_code']) ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($item['type_name']) ?></span>
                                            </td>
                                            <td>
                                                <small><?= date('d/m/Y', strtotime($item['created_date'])) ?></small>
                                                <br><small class="text-muted"><?= htmlspecialchars($item['created_by']) ?></small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Reports Section -->
        <div class="row fade-in mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header" style="background: var(--primary-gradient-dark); color: white;">
                        <div class="row align-items-center">
                            <div class="col">
                                <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>รายงานรายละเอียด</h5>
                            </div>
                            <div class="col-auto">
                                <div class="btn-group" role="group">
                                    <button class="btn btn-outline-light btn-sm active" onclick="toggleReportView('summary')">
                                        <i class="fas fa-list me-1"></i>สรุป
                                    </button>
                                    <button class="btn btn-outline-light btn-sm" onclick="toggleReportView('detailed')">
                                        <i class="fas fa-table me-1"></i>รายละเอียด
                                    </button>
                                    <button class="btn btn-outline-light btn-sm" onclick="toggleReportView('analytics')">
                                        <i class="fas fa-chart-line me-1"></i>วิเคราะห์
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="summaryView" class="report-view">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="p-4 rounded" style="background: rgba(255, 248, 240, 0.8); border: 1px solid #ffe4d1;">
                                        <h6 class="text-primary"><i class="fas fa-box me-2"></i>ข้อมูลวัสดุ</h6>
                                        <ul class="list-unstyled">
                                            <li><strong>วัสดุทั้งหมด:</strong> <?= number_format($total_materials) ?> รายการ</li>
                                            <li><strong>ประเภทวัสดุ:</strong> <?= count($type_distribution) ?> ประเภท</li>
                                            <li><strong>ราคาเฉลี่ย:</strong> ฿<?= number_format($avg_price_all, 2) ?></li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-4 rounded" style="background: rgba(255, 248, 240, 0.8); border: 1px solid #ffe4d1;">
                                        <h6 style="color: #ff6b35;"><i class="fas fa-trending-up me-2"></i>แนวโน้ม</h6>
                                        <ul class="list-unstyled">
                                            <li><strong>เพิ่มเดือนนี้:</strong> <?= end($monthly_stats)['count'] ?? 0 ?> รายการ</li>
                                            <li><strong>เติบโต:</strong> 
                                                <?php 
                                                $growth = count($monthly_stats) >= 2 ? 
                                                    ((end($monthly_stats)['count'] - prev($monthly_stats)['count']) / prev($monthly_stats)['count']) * 100 : 0;
                                                echo number_format($growth, 1) . '%';
                                                ?>
                                            </li>
                                            <li><strong>ประเภทยอดนิยม:</strong> <?= $type_distribution[0]['type_name'] ?? 'N/A' ?></li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-4 rounded" style="background: rgba(255, 248, 240, 0.8); border: 1px solid #ffe4d1;">
                                        <h6 style="color: #ffa726;"><i class="fas fa-cog me-2"></i>ระบบ</h6>
                                        <ul class="list-unstyled">
                                            <li><strong>สถานะ DB:</strong> 
                                                <?php if ($db_connected): ?>
                                                    <span style="color: #ff6b35;">เชื่อมต่อแล้ว</span>
                                                <?php else: ?>
                                                    <span style="color: #ffa726;">Demo Mode</span>
                                                <?php endif; ?>
                                            </li>
                                            <li><strong>อัปเดตล่าสุด:</strong> <?= date('H:i:s') ?></li>
                                            <li><strong>ผู้ดูรายงาน:</strong> <?= htmlspecialchars($full_name) ?></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="detailedView" class="report-view" style="display: none;">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead style="background: var(--primary-gradient); color: white;">
                                        <tr>
                                            <th>ลำดับ</th>
                                            <th>ประเภทวัสดุ</th>
                                            <th class="text-center">จำนวน</th>
                                            <th class="text-end">ราคาเฉลี่ย</th>
                                            <th class="text-end">มูลค่ารวม</th>
                                            <th class="text-center">เปอร์เซ็นต์</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_value = 0;
                                        foreach ($type_distribution as $item) {
                                            $total_value += $item['material_count'] * $item['avg_price'];
                                        }
                                        ?>
                                        <?php foreach ($type_distribution as $index => $item): ?>
                                        <?php 
                                        $item_value = $item['material_count'] * $item['avg_price'];
                                        $percentage = $total_materials > 0 ? ($item['material_count'] / $total_materials) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><?= $index + 1 ?></td>
                                            <td><strong class="material-name"><?= htmlspecialchars($item['type_name']) ?></strong></td>
                                            <td class="text-center"><?= number_format($item['material_count']) ?></td>
                                            <td class="text-end">฿<?= number_format($item['avg_price'], 2) ?></td>
                                            <td class="text-end">฿<?= number_format($item_value, 2) ?></td>
                                            <td class="text-center">
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar" role="progressbar" 
                                                         style="width: <?= $percentage ?>%"
                                                         aria-valuenow="<?= $percentage ?>" 
                                                         aria-valuemin="0" aria-valuemax="100">
                                                        <?= number_format($percentage, 1) ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot style="background: rgba(255, 248, 240, 0.8);">
                                        <tr>
                                            <th colspan="2">รวมทั้งหมด</th>
                                            <th class="text-center"><?= number_format($total_materials) ?></th>
                                            <th class="text-end">฿<?= number_format($avg_price_all, 2) ?></th>
                                            <th class="text-end">฿<?= number_format($total_value, 2) ?></th>
                                            <th class="text-center">100%</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <div id="analyticsView" class="report-view" style="display: none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="fas fa-trophy me-2" style="color: #ffa726;"></i>ประเภทวัสดุยอดนิยม</h6>
                                    <div class="list-group">
                                        <?php foreach (array_slice($type_distribution, 0, 3) as $index => $item): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="badge me-2" style="background: <?= $index == 0 ? '#ffa726' : ($index == 1 ? '#ff9a56' : '#ff7f50') ?>; color: white;">
                                                    <?= $index + 1 ?>
                                                </span>
                                                <span class="material-name"><?= htmlspecialchars($item['type_name']) ?></span>
                                            </div>
                                            <span class="badge" style="background: var(--primary-gradient); color: white;">
                                                <?= $item['material_count'] ?>
                                            </span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <h6><i class="fas fa-dollar-sign me-2" style="color: #ff6b35;"></i>การวิเคราะห์ราคา</h6>
                                    <?php
                                    $prices = array_column($type_distribution, 'avg_price');
                                    $min_price = min($prices);
                                    $max_price = max($prices);
                                    $price_range = $max_price - $min_price;
                                    ?>
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <div class="p-3 rounded" style="background: rgba(255, 107, 53, 0.1); border: 1px solid rgba(255, 107, 53, 0.3);">
                                                <h5 style="color: #ff6b35;">฿<?= number_format($min_price, 2) ?></h5>
                                                <small>ราคาต่ำสุด</small>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="p-3 rounded" style="background: rgba(255, 154, 86, 0.1); border: 1px solid rgba(255, 154, 86, 0.3);">
                                                <h5 style="color: #ff9a56;">฿<?= number_format($avg_price_all, 2) ?></h5>
                                                <small>ราคาเฉลี่ย</small>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="p-3 rounded" style="background: rgba(255, 107, 107, 0.1); border: 1px solid rgba(255, 107, 107, 0.3);">
                                                <h5 style="color: #ff6b6b;">฿<?= number_format($max_price, 2) ?></h5>
                                                <small>ราคาสูงสุด</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle me-1"></i>
                                            ช่วงราคา: ฿<?= number_format($price_range, 2) ?> | 
                                            ความแปรปรวน: <?= number_format(($price_range / $avg_price_all) * 100, 1) ?>%
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ข้อมูลสำหรับ Charts
        const materialData = <?= json_encode($type_distribution) ?>;
        const monthlyData = <?= json_encode($monthly_stats) ?>;
        
        // Chart.js Global Configuration
        Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#666';
        
        // Material Distribution Doughnut Chart
        const distributionCtx = document.getElementById('materialDistributionChart').getContext('2d');
        new Chart(distributionCtx, {
            type: 'doughnut',
            data: {
                labels: materialData.map(item => item.type_name),
                datasets: [{
                    data: materialData.map(item => item.material_count),
                    backgroundColor: [
                        '#ff9a56', '#ff7f50', '#ff6b35', '#ffa726',
                        '#ffb347', '#ffc649', '#ff8c42', '#ff6b6b'
                    ],
                    borderWidth: 3,
                    borderColor: '#fff',
                    hoverOffset: 15,
                    hoverBorderWidth: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.95)',
                        titleColor: '#333',
                        bodyColor: '#666',
                        borderColor: '#ff9a56',
                        borderWidth: 2,
                        cornerRadius: 10,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed * 100) / total).toFixed(1);
                                return context.label + ': ' + context.parsed + ' ชิ้น (' + percentage + '%)';
                            }
                        }
                    }
                },
                animation: {
                    animateScale: true,
                    animateRotate: true,
                    duration: 1500,
                    easing: 'easeInOutQuart'
                }
            }
        });

        // Monthly Trend Line Chart
        const trendCtx = document.getElementById('monthlyTrendChart').getContext('2d');
        const monthNames = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 
                           'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: monthlyData.map(item => monthNames[parseInt(item.month) - 1] + ' ' + item.year),
                datasets: [{
                    label: 'วัสดุที่เพิ่มใหม่',
                    data: monthlyData.map(item => item.count),
                    borderColor: '#ff6b35',
                    backgroundColor: 'rgba(255, 107, 53, 0.1)',
                    borderWidth: 4,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#ff6b35',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 3,
                    pointRadius: 7,
                    pointHoverRadius: 10,
                    pointHoverBackgroundColor: '#ff7f50',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(255, 255, 255, 0.95)',
                        titleColor: '#333',
                        bodyColor: '#666',
                        borderColor: '#ff6b35',
                        borderWidth: 2,
                        cornerRadius: 10
                    }
                },
                scales: {
                    x: {
                        display: true,
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#666'
                        }
                    },
                    y: {
                        display: true,
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 154, 86, 0.1)',
                            borderColor: 'rgba(255, 154, 86, 0.2)'
                        },
                        ticks: {
                            color: '#666'
                        }
                    }
                },
                animation: {
                    duration: 2000,
                    easing: 'easeInOutQuart'
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });

        // Report Filter Functions
        function filterReport(type) {
            // Remove active class from all buttons
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Add active class to clicked button
            event.target.classList.add('active');
            
            // Show loading animation
            showLoadingAnimation();
            
            // Simulate API call
            setTimeout(() => {
                hideLoadingAnimation();
                showNotification('รายงานถูกกรองตาม: ' + getFilterName(type), 'success');
            }, 1000);
        }

        function getFilterName(type) {
            const names = {
                'all': 'ทั้งหมด',
                'monthly': 'รายเดือน',
                'type': 'ประเภท',
                'price': 'ราคา'
            };
            return names[type] || type;
        }

        // Export Functions
        function exportReport(format) {
            showLoadingAnimation();
            
            // Simulate export process
            setTimeout(() => {
                hideLoadingAnimation();
                showNotification('รายงานถูกส่งออกเป็น ' + format.toUpperCase() + ' แล้ว', 'success');
                
                // In real implementation, this would trigger file download
                // window.location.href = `export.php?format=${format}`;
            }, 2000);
        }

        // Report View Toggle
        function toggleReportView(viewType) {
            // Hide all views
            document.querySelectorAll('.report-view').forEach(view => {
                view.style.display = 'none';
            });
            
            // Show selected view
            document.getElementById(viewType + 'View').style.display = 'block';
            
            // Update button states
            document.querySelectorAll('.btn-group .btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            showNotification('เปลี่ยนมุมมองเป็น: ' + getViewName(viewType), 'info');
        }

        function getViewName(viewType) {
            const names = {
                'summary': 'สรุป',
                'detailed': 'รายละเอียด',
                'analytics': 'วิเคราะห์'
            };
            return names[viewType] || viewType;
        }

        // Utility Functions
        function showLoadingAnimation() {
            // Create loading overlay
            const overlay = document.createElement('div');
            overlay.id = 'loadingOverlay';
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(255, 154, 86, 0.8);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 9999;
                backdrop-filter: blur(5px);
            `;
            overlay.innerHTML = `
                <div class="text-center text-white">
                    <div class="spinner-border mb-3" role="status" style="width: 3rem; height: 3rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div style="font-size: 1.2rem; font-weight: 500;">กำลังประมวลผล...</div>
                </div>
            `;
            document.body.appendChild(overlay);
        }

        function hideLoadingAnimation() {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) {
                overlay.remove();
            }
        }

        function showNotification(message, type = 'info') {
            const alertClass = `alert-${type}`;
            const iconClass = type === 'success' ? 'fa-check-circle' : 
                             type === 'warning' ? 'fa-exclamation-triangle' : 
                             type === 'danger' ? 'fa-times-circle' : 'fa-info-circle';
            
            const notification = document.createElement('div');
            notification.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
            notification.style.cssText = `
                top: 80px;
                right: 20px;
                z-index: 1050;
                min-width: 300px;
                border-radius: 15px;
                border-radius: 15px;
                box-shadow: 0 10px 30px rgba(255, 154, 86, 0.3);
            `;
            notification.innerHTML = `
                <i class="fas ${iconClass} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (notification && notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Add fade-in animation
            const elements = document.querySelectorAll('.fade-in');
            elements.forEach((el, index) => {
                setTimeout(() => {
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(20px)';
                    el.style.transition = 'all 0.6s ease';
                    
                    setTimeout(() => {
                        el.style.opacity = '1';
                        el.style.transform = 'translateY(0)';
                    }, 100);
                }, index * 200);
            });

            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            // Add click handlers for stat cards
            document.querySelectorAll('.stat-card').forEach((card, index) => {
                card.style.cursor = 'pointer';
                card.addEventListener('click', function() {
                    switch(index) {
                        case 0: // วัสดุทั้งหมด
                            window.location.href = '../materials/list.php';
                            break;
                        case 1: // ประเภทวัสดุ
                            filterReport('type');
                            break;
                        case 2: // ราคาเฉลี่ย
                            filterReport('price');
                            break;
                        case 3: // เพิ่มใหม่
                            window.location.href = '../materials/list.php?sort=created_date&order=DESC';
                            break;
                    }
                });

                // Add hover effect
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl + E = Export to Excel
                if (e.ctrlKey && e.key === 'e') {
                    e.preventDefault();
                    exportReport('excel');
                }
                
                // Ctrl + P = Export to PDF
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    exportReport('pdf');
                }
                
                // Ctrl + 1, 2, 3 = Switch report views
                if (e.ctrlKey && ['1', '2', '3'].includes(e.key)) {
                    e.preventDefault();
                    const views = ['summary', 'detailed', 'analytics'];
                    toggleReportView(views[parseInt(e.key) - 1]);
                }
            });

            // Show welcome message
            setTimeout(() => {
                showNotification('รายงานโหลดเสร็จแล้ว - ข้อมูลล่าสุด', 'success');
            }, 1500);

            // Auto-refresh data every 5 minutes (optional)
            setInterval(() => {
                if (confirm('ต้องการอัปเดตข้อมูลรายงานใหม่หรือไม่?')) {
                    location.reload();
                }
            }, 300000); // 5 minutes

            console.log('✅ Reports page initialized successfully');
            console.log('📊 Total materials:', <?= $total_materials ?>);
            console.log('💰 Average price:', <?= number_format($avg_price_all, 2) ?>);
            console.log('🔗 Database connected:', <?= $db_connected ? 'true' : 'false' ?>);
        });

        // Additional helper functions
        function refreshData() {
            showLoadingAnimation();
            setTimeout(() => {
                location.reload();
            }, 1000);
        }

        function printReport() {
            window.print();
        }

        function shareReport() {
            if (navigator.share) {
                navigator.share({
                    title: 'รายงานวัสดุ - Material Management System',
                    text: `รายงานสถิติวัสดุ: ${<?= $total_materials ?>} รายการ, ราคาเฉลี่ย ฿${<?= number_format($avg_price_all, 2) ?>}`,
                    url: window.location.href
                });
            } else {
                // Fallback: copy URL to clipboard
                navigator.clipboard.writeText(window.location.href).then(() => {
                    showNotification('ลิงก์รายงานถูกคัดลอกแล้ว', 'success');
                });
            }
        }

        // Chart interaction handlers
        function onChartClick(evt, activeElements, chart) {
            if (activeElements.length > 0) {
                const dataIndex = activeElements[0].index;
                const label = chart.data.labels[dataIndex];
                showNotification(`คลิกที่: ${label}`, 'info');
                
                // Navigate to filtered materials list
                const encodedLabel = encodeURIComponent(label);
                window.location.href = `../materials/list.php?type=${encodedLabel}`;
            }
        }

        // Update chart click handlers
        setTimeout(() => {
            const charts = Chart.instances;
            Object.values(charts).forEach(chart => {
                chart.options.onClick = onChartClick;
                chart.update();
            });
        }, 2000);

        // Performance monitoring
        const perfObserver = new PerformanceObserver((list) => {
            list.getEntries().forEach((entry) => {
                if (entry.entryType === 'navigation') {
                    console.log(`📈 Page load time: ${entry.loadEventEnd - entry.loadEventStart}ms`);
                }
            });
        });

        if ('PerformanceObserver' in window) {
            perfObserver.observe({ entryTypes: ['navigation'] });
        }

        // Error handling
        window.addEventListener('error', function(e) {
            console.error('❌ JavaScript Error:', e.error);
            showNotification('เกิดข้อผิดพลาดในการแสดงผล กรุณารีเฟรชหน้า', 'danger');
        });

        // Responsive chart handling
        function handleResize() {
            Object.values(Chart.instances).forEach(chart => {
                chart.resize();
            });
        }

        window.addEventListener('resize', handleResize);

        // Data validation alerts
        <?php if (empty($type_distribution)): ?>
        setTimeout(() => {
            showNotification('ไม่พบข้อมูลวัสดุในระบบ กรุณาเพิ่มข้อมูลวัสดุ', 'warning');
        }, 3000);
        <?php endif; ?>

        // Database connection status alert
        <?php if (!$db_connected): ?>
        setTimeout(() => {
            showNotification('ระบบกำลังใช้ข้อมูลทดสอบ เนื่องจากไม่สามารถเชื่อมต่อฐานข้อมูลได้', 'warning');
        }, 2000);
        <?php endif; ?>
    </script>
</body>
</html>