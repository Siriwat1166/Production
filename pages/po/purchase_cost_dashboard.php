<?php
// pages/po/purchase_cost_dashboard.php - Purchase Cost Analytics Dashboard
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();

// เชื่อมต่อฐานข้อมูล
try {
    require_once "../../config/database.php";
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

// ==================== API HANDLER ====================
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=UTF-8');
    
    $action = $_GET['action'];
    
    try {
        switch ($action) {
            case 'get_summary_stats':
                getSummaryStats($conn);
                break;
            case 'get_cost_trend':
                getCostTrend($conn);
                break;
            case 'get_cost_breakdown':
                getCostBreakdown($conn);
                break;
            case 'get_top_products':
                getTopProducts($conn);
                break;
            case 'get_supplier_comparison':
                getSupplierComparison($conn);
                break;
            case 'get_monthly_report':
                getMonthlyReport($conn);
                break;
            case 'get_freight_analysis':
                getFreightAnalysis($conn);
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log("Database Error [$action]: " . $e->getMessage());
        error_log("SQL State: " . $e->getCode());
        http_response_code(500);
        echo json_encode([
            'error' => 'Database error',
            'action' => $action,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'sql_state' => $e->errorInfo[0] ?? null
        ]);
    } catch (Exception $e) {
        error_log("API Error [$action]: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode([
            'error' => 'Server error',
            'action' => $action,
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]);
    }
    exit;
}

// ==================== API FUNCTIONS ====================

function getSummaryStats($conn) {
    try {
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        
        // ทดสอบว่า columns มีอยู่จริงหรือไม่
        $test_query = "SELECT TOP 1 
            total_amount, 
            material_amount, 
            freight_amount, 
            service_amount 
        FROM PO_Header";
        
        try {
            $test_stmt = $conn->query($test_query);
            $test_stmt->fetch();
        } catch (PDOException $e) {
            // ถ้า column ไม่มี ให้ลอง query แบบง่ายกว่า
            error_log("Column test failed: " . $e->getMessage());
            throw new Exception("Database schema error. Missing columns: " . $e->getMessage());
        }
        
        $stmt = $conn->prepare("
            SELECT
                ISNULL(SUM(total_amount), 0) as total_purchase,
                COUNT(po_id) as total_orders,
                ISNULL(AVG(total_amount), 0) as avg_order_value,
                ISNULL(SUM(material_amount), 0) as total_material,
                ISNULL(SUM(freight_amount), 0) as total_freight,
                ISNULL(SUM(service_amount), 0) as total_service
            FROM PO_Header
            WHERE po_date BETWEEN ? AND ?
                AND status NOT IN ('cancelled', 'draft')
        ");
        
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . print_r($conn->errorInfo(), true));
        }
        
        $executed = $stmt->execute([$date_from, $date_to]);
        
        if (!$executed) {
            throw new Exception("Failed to execute statement: " . print_r($stmt->errorInfo(), true));
        }
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception("No data returned from query");
        }
        
        $result['total_purchase_formatted'] = number_format($result['total_purchase'], 2);
        $result['avg_order_value_formatted'] = number_format($result['avg_order_value'], 2);
        $result['total_material_formatted'] = number_format($result['total_material'], 2);
        $result['total_freight_formatted'] = number_format($result['total_freight'], 2);
        $result['total_service_formatted'] = number_format($result['total_service'], 2);
        
        echo json_encode($result);
        
    } catch (PDOException $e) {
        error_log("PDO Error in getSummaryStats: " . $e->getMessage());
        throw new Exception("Database error: " . $e->getMessage());
    } catch (Exception $e) {
        error_log("Error in getSummaryStats: " . $e->getMessage());
        throw $e;
    }
}

function getCostTrend($conn) {
    try {
        $months = intval($_GET['months'] ?? 12);
        
        // FIX 4: Calculate the date in PHP instead of using DATEADD with parameter
        $dateThreshold = date('Y-m-d', strtotime("-$months months"));
        
        $stmt = $conn->prepare("
            SELECT
                CONVERT(VARCHAR(7), po_date, 120) as month,
                ISNULL(SUM(material_amount), 0) as material_cost,
                ISNULL(SUM(freight_amount), 0) as freight_cost,
                ISNULL(SUM(service_amount), 0) as service_cost,
                ISNULL(SUM(total_amount), 0) as total_cost,
                COUNT(po_id) as po_count
            FROM PO_Header
            WHERE po_date >= ?
                AND status NOT IN ('cancelled', 'draft')
            GROUP BY CONVERT(VARCHAR(7), po_date, 120)
            ORDER BY month
        ");
        
        $stmt->execute([$dateThreshold]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($results);
        
    } catch (PDOException $e) {
        error_log("PDO Error in getCostTrend: " . $e->getMessage());
        throw new Exception("Database error in getCostTrend: " . $e->getMessage());
    }
}

function getCostBreakdown($conn) {
    try {
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        
        $stmt = $conn->prepare("
            SELECT
                ISNULL(SUM(material_amount), 0) as material_cost,
                ISNULL(SUM(freight_amount), 0) as freight_cost,
                ISNULL(SUM(service_amount), 0) as service_cost
            FROM PO_Header
            WHERE po_date BETWEEN ? AND ?
                AND status NOT IN ('cancelled', 'draft')
        ");
        $stmt->execute([$date_from, $date_to]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode($result);
        
    } catch (PDOException $e) {
        error_log("PDO Error in getCostBreakdown: " . $e->getMessage());
        throw new Exception("Database error in getCostBreakdown: " . $e->getMessage());
    }
}

function getTopProducts($conn) {
    try {
        $limit = intval($_GET['limit'] ?? 10);
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        
        // First, try to get product info directly from PO_Items if it has the columns
        // If your PO_Items doesn't have product_code/product_name, we'll use a different approach
        
        // Check if we can query without Products table (get product info from PO_Items)
        try {
            $test_query = "SELECT TOP 1 product_code, product_name FROM PO_Items";
            $test_stmt = $conn->query($test_query);
            $has_product_info = true;
        } catch (PDOException $e) {
            $has_product_info = false;
        }
        
        if ($has_product_info) {
            // PO_Items has product info - query directly
            $stmt = $conn->prepare("
                SELECT TOP " . $limit . "
                    pi.product_id,
                    pi.product_code,
                    pi.product_name,
                    pi.unit_symbol,
                    SUM(pi.quantity) as total_quantity,
                    SUM(pi.item_total) as total_cost,
                    AVG(pi.unit_price) as avg_unit_price,
                    COUNT(DISTINCT pi.po_id) as po_count
                FROM PO_Items pi
                INNER JOIN PO_Header ph ON pi.po_id = ph.po_id
                WHERE ph.po_date BETWEEN ? AND ?
                    AND ph.status NOT IN ('cancelled', 'draft')
                    AND pi.status != 'cancelled'
                GROUP BY pi.product_id, pi.product_code, pi.product_name, pi.unit_symbol
                ORDER BY total_cost DESC
            ");
            $stmt->execute([$date_from, $date_to]);
        } else {
            // Need to find the Products table - try common variations
            $products_table = null;
            $possible_tables = ['Products', 'Master_Products', 'tbl_products', 'Product_Master', 'ProductMaster'];
            
            foreach ($possible_tables as $table) {
                try {
                    $test = $conn->query("SELECT TOP 1 * FROM $table");
                    $products_table = $table;
                    break;
                } catch (PDOException $e) {
                    continue;
                }
            }
            
            if (!$products_table) {
                throw new Exception("Cannot find Products table. Please check your database schema.");
            }
            
            $stmt = $conn->prepare("
                SELECT TOP " . $limit . "
                    p.product_id,
                    p.product_code,
                    p.product_name,
                    ISNULL(u.unit_symbol, '') as unit_symbol,
                    SUM(pi.quantity) as total_quantity,
                    SUM(pi.item_total) as total_cost,
                    AVG(pi.unit_price) as avg_unit_price,
                    COUNT(DISTINCT pi.po_id) as po_count
                FROM PO_Items pi
                INNER JOIN PO_Header ph ON pi.po_id = ph.po_id
                INNER JOIN $products_table p ON pi.product_id = p.product_id
                LEFT JOIN Units u ON p.unit_id = u.unit_id
                WHERE ph.po_date BETWEEN ? AND ?
                    AND ph.status NOT IN ('cancelled', 'draft')
                    AND pi.status != 'cancelled'
                GROUP BY p.product_id, p.product_code, p.product_name, u.unit_symbol
                ORDER BY total_cost DESC
            ");
            $stmt->execute([$date_from, $date_to]);
        }
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as &$item) {
            $item['total_quantity_formatted'] = number_format($item['total_quantity'], 2);
            $item['total_cost_formatted'] = number_format($item['total_cost'], 2);
            $item['avg_unit_price_formatted'] = number_format($item['avg_unit_price'], 2);
        }
        
        echo json_encode($results);
        
    } catch (PDOException $e) {
        error_log("PDO Error in getTopProducts: " . $e->getMessage());
        throw new Exception("Database error in getTopProducts: " . $e->getMessage());
    }
}

function getSupplierComparison($conn) {
    try {
        $limit = intval($_GET['limit'] ?? 10);
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        
        // FIX 3: Changed TOP (?) to OFFSET-FETCH pattern
        $stmt = $conn->prepare("
            SELECT
                s.supplier_id,
                s.supplier_name,
                s.supplier_code,
                COUNT(DISTINCT ph.po_id) as total_pos,
                ISNULL(SUM(ph.total_amount), 0) as total_amount,
                ISNULL(AVG(ph.total_amount), 0) as avg_po_value,
                ISNULL(SUM(ph.material_amount), 0) as total_material,
                ISNULL(SUM(ph.freight_amount), 0) as total_freight
            FROM Suppliers s
            INNER JOIN PO_Header ph ON s.supplier_id = ph.supplier_id
            WHERE ph.po_date BETWEEN ? AND ?
                AND ph.status NOT IN ('cancelled', 'draft')
            GROUP BY s.supplier_id, s.supplier_name, s.supplier_code
            ORDER BY total_amount DESC
            OFFSET 0 ROWS FETCH NEXT ? ROWS ONLY
        ");
        
        $stmt->execute([$date_from, $date_to, $limit]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as &$item) {
            $item['total_amount_formatted'] = number_format($item['total_amount'], 2);
            $item['avg_po_value_formatted'] = number_format($item['avg_po_value'], 2);
            $item['total_material_formatted'] = number_format($item['total_material'], 2);
            $item['total_freight_formatted'] = number_format($item['total_freight'], 2);
        }
        
        echo json_encode($results);
        
    } catch (PDOException $e) {
        error_log("PDO Error in getSupplierComparison: " . $e->getMessage());
        throw new Exception("Database error in getSupplierComparison: " . $e->getMessage());
    }
}

function getMonthlyReport($conn) {
    try {
        $months = intval($_GET['months'] ?? 12);
        
        // FIX 5: Calculate date threshold in PHP
        $dateThreshold = date('Y-m-d', strtotime("-$months months"));
        
        $stmt = $conn->prepare("
            SELECT
                CONVERT(VARCHAR(7), po_date, 120) as month,
                DATENAME(MONTH, po_date) as month_name,
                YEAR(po_date) as year,
                ISNULL(SUM(material_amount), 0) as material,
                ISNULL(SUM(freight_amount), 0) as freight,
                ISNULL(SUM(service_amount), 0) as service,
                ISNULL(SUM(total_amount), 0) as total,
                COUNT(po_id) as po_count
            FROM PO_Header
            WHERE po_date >= ?
                AND status NOT IN ('cancelled', 'draft')
            GROUP BY CONVERT(VARCHAR(7), po_date, 120), DATENAME(MONTH, po_date), YEAR(po_date)
            ORDER BY month
        ");
        
        $stmt->execute([$dateThreshold]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as &$item) {
            $item['material_formatted'] = number_format($item['material'], 2);
            $item['freight_formatted'] = number_format($item['freight'], 2);
            $item['service_formatted'] = number_format($item['service'], 2);
            $item['total_formatted'] = number_format($item['total'], 2);
        }
        
        echo json_encode($results);
        
    } catch (PDOException $e) {
        error_log("PDO Error in getMonthlyReport: " . $e->getMessage());
        throw new Exception("Database error in getMonthlyReport: " . $e->getMessage());
    }
}

function getFreightAnalysis($conn) {
    try {
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');
        
        $stmt = $conn->prepare("
            SELECT
                COUNT(po_id) as total_freight_pos,
                ISNULL(SUM(freight_amount), 0) as total_freight_amount,
                ISNULL(AVG(freight_amount), 0) as avg_freight_per_po
            FROM PO_Header
            WHERE po_date BETWEEN ? AND ?
                AND is_freight_po = 1
                AND status NOT IN ('cancelled', 'draft')
        ");
        $stmt->execute([$date_from, $date_to]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // FIX 6: Changed TOP 20 to OFFSET-FETCH (though TOP 20 without parameter works)
        $stmt = $conn->prepare("
            SELECT
                ph.po_number,
                ph.po_date,
                ph.freight_amount,
                s.supplier_name,
                CASE
                    WHEN ph.linked_po_id IS NOT NULL THEN 'Allocated'
                    ELSE 'Not Allocated'
                END as allocation_status,
                linked.po_number as linked_po_number
            FROM PO_Header ph
            LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
            LEFT JOIN PO_Header linked ON ph.linked_po_id = linked.po_id
            WHERE ph.po_date BETWEEN ? AND ?
                AND ph.is_freight_po = 1
                AND ph.status NOT IN ('cancelled', 'draft')
            ORDER BY ph.po_date DESC
            OFFSET 0 ROWS FETCH NEXT 20 ROWS ONLY
        ");
        $stmt->execute([$date_from, $date_to]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($details as &$item) {
            $item['freight_amount_formatted'] = number_format($item['freight_amount'], 2);
            $item['po_date_formatted'] = date('d/m/Y', strtotime($item['po_date']));
        }
        
        echo json_encode([
            'summary' => [
                'total_freight_pos' => intval($summary['total_freight_pos']),
                'total_freight_amount' => floatval($summary['total_freight_amount']),
                'avg_freight_per_po' => floatval($summary['avg_freight_per_po']),
                'total_freight_amount_formatted' => number_format($summary['total_freight_amount'], 2),
                'avg_freight_per_po_formatted' => number_format($summary['avg_freight_per_po'], 2)
            ],
            'details' => $details
        ]);
        
    } catch (PDOException $e) {
        error_log("PDO Error in getFreightAnalysis: " . $e->getMessage());
        throw new Exception("Database error in getFreightAnalysis: " . $e->getMessage());
    }
}

$page_title = "Purchase Cost Dashboard";
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Sarabun', 'Segoe UI', sans-serif;
        }
        .stat-card {
            border-left: 4px solid;
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
        .stat-card.card-primary { border-left-color: #0d6efd; }
        .stat-card.card-success { border-left-color: #198754; }
        .stat-card.card-warning { border-left-color: #ffc107; }
        .stat-card.card-info { border-left-color: #0dcaf0; }
        .stat-card.card-danger { border-left-color: #dc3545; }
        .stat-card.card-purple { border-left-color: #6f42c1; }
        
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }
        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
        }
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 8px;
        }
        
        .chart-container {
            position: relative;
            height: 350px;
        }
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .section-title {
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 3px solid #0d6efd;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .loading-overlay.active {
            display: flex;
        }
        .loading-spinner {
            width: 4rem;
            height: 4rem;
        }
        .table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        .badge-rank {
            font-size: 1rem;
            padding: 0.5rem 0.75rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-light loading-spinner" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-chart-line me-2"></i><?php echo $page_title; ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="list.php">
                            <i class="fas fa-list me-1"></i>PO List
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../inventory/inventory_view.php">
                            <i class="fas fa-boxes me-1"></i>Inventory
                        </a>
                    </li>
                    <li class="nav-item">
                        <span class="nav-link">
                            <i class="fas fa-user me-1"></i><?php echo $_SESSION['username'] ?? 'User'; ?>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4 px-4">
        <!-- Filter Section -->
        <div class="filter-card">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-calendar-day me-2 text-primary"></i>วันที่เริ่มต้น
                    </label>
                    <input type="date" class="form-control" id="dateFrom" value="<?php echo date('Y-m-01'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-calendar-check me-2 text-primary"></i>วันที่สิ้นสุด
                    </label>
                    <input type="date" class="form-control" id="dateTo" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-clock me-2 text-primary"></i>ช่วงเวลา
                    </label>
                    <select class="form-select" id="periodSelect">
                        <option value="custom">กำหนดเอง</option>
                        <option value="today">วันนี้</option>
                        <option value="week">สัปดาห์นี้</option>
                        <option value="month" selected>เดือนนี้</option>
                        <option value="quarter">ไตรมาสนี้</option>
                        <option value="year">ปีนี้</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary w-100 py-2" id="btnRefresh">
                        <i class="fas fa-sync-alt me-2"></i>Refresh Data
                    </button>
                </div>
            </div>
        </div>

        <!-- Summary Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card stat-card card-primary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="flex-grow-1">
                                <p class="stat-label mb-2">💰 Total Purchase Value</p>
                                <h3 class="stat-value" id="totalPurchase">0.00 THB</h3>
                            </div>
                            <div class="stat-icon text-primary">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card card-success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="flex-grow-1">
                                <p class="stat-label mb-2">📦 Total Orders</p>
                                <h3 class="stat-value" id="totalOrders">0</h3>
                            </div>
                            <div class="stat-icon text-success">
                                <i class="fas fa-file-invoice"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card card-warning">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="flex-grow-1">
                                <p class="stat-label mb-2">📊 Average Order Value</p>
                                <h3 class="stat-value" id="avgOrderValue">0.00 THB</h3>
                            </div>
                            <div class="stat-icon text-warning">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card stat-card card-info">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="flex-grow-1">
                                <p class="stat-label mb-2">📦 Material Cost</p>
                                <h3 class="stat-value" id="totalMaterial">0.00 THB</h3>
                            </div>
                            <div class="stat-icon text-info">
                                <i class="fas fa-box"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card card-danger">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="flex-grow-1">
                                <p class="stat-label mb-2">🚚 Freight Cost</p>
                                <h3 class="stat-value" id="totalFreight">0.00 THB</h3>
                            </div>
                            <div class="stat-icon text-danger">
                                <i class="fas fa-truck"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card card-purple">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="flex-grow-1">
                                <p class="stat-label mb-2">⚙️ Service Cost</p>
                                <h3 class="stat-value" id="totalService">0.00 THB</h3>
                            </div>
                            <div class="stat-icon" style="color: #6f42c1;">
                                <i class="fas fa-cogs"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 fw-semibold">
                            <i class="fas fa-chart-line me-2 text-primary"></i>📈 Purchase Cost Trend (12 Months)
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="costTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 fw-semibold">
                            <i class="fas fa-chart-pie me-2 text-primary"></i>🥧 Cost Breakdown
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="costBreakdownChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Products Table -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="table-container">
                    <h5 class="section-title">
                        <i class="fas fa-trophy text-warning"></i>
                        🏆 Top 10 Most Purchased Products
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th width="80" class="text-center">Rank</th>
                                    <th>Product Code</th>
                                    <th>Product Name</th>
                                    <th class="text-end">Total Quantity</th>
                                    <th class="text-end">Total Cost (THB)</th>
                                    <th class="text-end">Avg Unit Price</th>
                                    <th class="text-center">PO Count</th>
                                </tr>
                            </thead>
                            <tbody id="topProductsBody">
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="fas fa-spinner fa-spin me-2"></i>Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Supplier Comparison Table -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="table-container">
                    <h5 class="section-title">
                        <i class="fas fa-building text-info"></i>
                        🏢 Supplier Cost Comparison
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Supplier Code</th>
                                    <th>Supplier Name</th>
                                    <th class="text-center">Total POs</th>
                                    <th class="text-end">Total Amount (THB)</th>
                                    <th class="text-end">Avg PO Value</th>
                                    <th class="text-end">Material Cost</th>
                                    <th class="text-end">Freight Cost</th>
                                </tr>
                            </thead>
                            <tbody id="supplierBody">
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="fas fa-spinner fa-spin me-2"></i>Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monthly Report Table -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="table-container">
                    <h5 class="section-title">
                        <i class="fas fa-calendar-alt text-success"></i>
                        📅 Monthly Cost Report (Last 12 Months)
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th class="text-end">Material Cost</th>
                                    <th class="text-end">Freight Cost</th>
                                    <th class="text-end">Service Cost</th>
                                    <th class="text-end">Total Cost</th>
                                    <th class="text-center">PO Count</th>
                                </tr>
                            </thead>
                            <tbody id="monthlyBody">
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <i class="fas fa-spinner fa-spin me-2"></i>Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Freight Analysis Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="table-container">
                    <h5 class="section-title">
                        <i class="fas fa-truck-loading text-danger"></i>
                        🚛 Freight Cost Analysis
                    </h5>
                    
                    <!-- Freight Summary Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card border-warning border-2">
                                <div class="card-body text-center">
                                    <h6 class="text-muted mb-2">Total Freight POs</h6>
                                    <h3 class="fw-bold mb-0" id="freightPOCount">0</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-warning border-2">
                                <div class="card-body text-center">
                                    <h6 class="text-muted mb-2">Total Freight Amount</h6>
                                    <h3 class="fw-bold mb-0" id="freightTotalAmount">0.00 THB</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-warning border-2">
                                <div class="card-body text-center">
                                    <h6 class="text-muted mb-2">Avg Freight per PO</h6>
                                    <h3 class="fw-bold mb-0" id="freightAvgAmount">0.00 THB</h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Freight Details Table -->
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Date</th>
                                    <th>Supplier</th>
                                    <th class="text-end">Freight Amount</th>
                                    <th class="text-center">Status</th>
                                    <th>Linked PO</th>
                                </tr>
                            </thead>
                            <tbody id="freightDetailsBody">
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">
                                        <i class="fas fa-spinner fa-spin me-2"></i>Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Global Variables
    let costTrendChart = null;
    let costBreakdownChart = null;
    const API_URL = window.location.pathname;

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        console.log('📊 Purchase Cost Dashboard Initialized');
        setupEventListeners();
        loadAllData();
    });

    function setupEventListeners() {
        document.getElementById('btnRefresh').addEventListener('click', () => loadAllData());
        
        document.getElementById('periodSelect').addEventListener('change', function() {
            updateDateRange(this.value);
            loadAllData();
        });
        
        document.getElementById('dateFrom').addEventListener('change', function() {
            document.getElementById('periodSelect').value = 'custom';
            loadAllData();
        });
        
        document.getElementById('dateTo').addEventListener('change', function() {
            document.getElementById('periodSelect').value = 'custom';
            loadAllData();
        });
    }

    function updateDateRange(period) {
        const today = new Date();
        let dateFrom = new Date();
        let dateTo = today;

        switch(period) {
            case 'today':
                dateFrom = today;
                break;
            case 'week':
                dateFrom.setDate(today.getDate() - today.getDay());
                break;
            case 'month':
                dateFrom.setDate(1);
                break;
            case 'quarter':
                const quarter = Math.floor(today.getMonth() / 3);
                dateFrom = new Date(today.getFullYear(), quarter * 3, 1);
                break;
            case 'year':
                dateFrom = new Date(today.getFullYear(), 0, 1);
                break;
            case 'custom':
                return;
        }

        document.getElementById('dateFrom').value = formatDateForInput(dateFrom);
        document.getElementById('dateTo').value = formatDateForInput(dateTo);
    }

    function formatDateForInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function loadAllData() {
        showLoading(true);
        
        Promise.all([
            loadSummaryStats(),
            loadCostTrend(),
            loadCostBreakdown(),
            loadTopProducts(),
            loadSupplierComparison(),
            loadMonthlyReport(),
            loadFreightAnalysis()
        ]).then(() => {
            showLoading(false);
            console.log('✅ All data loaded successfully');
        }).catch(error => {
            showLoading(false);
            console.error('❌ Error loading data:', error);
            
            // แสดง error แบบละเอียดใน console
            if (error.response) {
                console.error('Response:', error.response);
            }
            
            // แสดง alert พร้อม error details
            let errorMsg = 'เกิดข้อผิดพลาดในการโหลดข้อมูล\n\n';
            errorMsg += 'Error: ' + error.message + '\n\n';
            errorMsg += 'กรุณาเปิด Console (F12) และส่ง screenshot ให้ทีมพัฒนา';
            
            alert(errorMsg);
        });
    }

    function showLoading(show) {
        const overlay = document.getElementById('loadingOverlay');
        overlay.classList.toggle('active', show);
    }

    function getDateRange() {
        return {
            date_from: document.getElementById('dateFrom').value,
            date_to: document.getElementById('dateTo').value
        };
    }

    async function loadSummaryStats() {
        try {
            const params = new URLSearchParams({
                action: 'get_summary_stats',
                ...getDateRange()
            });

            const url = `${API_URL}?${params}`;
            console.log('📡 Loading summary stats from:', url);

            const response = await fetch(url);
            console.log('📊 Response status:', response.status);
            
            const responseText = await response.text();
            console.log('📝 Raw response (first 500 chars):', responseText.substring(0, 500));

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                console.error('❌ JSON Parse Error:', e);
                console.error('Full response:', responseText);
                throw new Error('Invalid JSON response. Check console for details.');
            }

            if (data.error) {
                console.error('❌ API Error:', data);
                throw new Error(`${data.error}: ${data.message || ''} (Action: ${data.action || 'unknown'})`);
            }

            console.log('✅ Summary data:', data);

            document.getElementById('totalPurchase').textContent = data.total_purchase_formatted + ' THB';
            document.getElementById('totalOrders').textContent = parseInt(data.total_orders).toLocaleString();
            document.getElementById('avgOrderValue').textContent = data.avg_order_value_formatted + ' THB';
            document.getElementById('totalMaterial').textContent = data.total_material_formatted + ' THB';
            document.getElementById('totalFreight').textContent = data.total_freight_formatted + ' THB';
            document.getElementById('totalService').textContent = data.total_service_formatted + ' THB';
        } catch (error) {
            console.error('❌ Error in loadSummaryStats:', error);
            throw error;
        }
    }

    async function loadCostTrend() {
        try {
            const params = new URLSearchParams({
                action: 'get_cost_trend',
                months: 12
            });

            const response = await fetch(`${API_URL}?${params}`);
            const responseText = await response.text();
            console.log('📊 Cost Trend Response:', responseText.substring(0, 500));

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                console.error('❌ JSON Parse Error in loadCostTrend:', e);
                throw new Error('Invalid JSON in Cost Trend response');
            }

            if (data.error) {
                console.error('❌ API Error in Cost Trend:', data);
                throw new Error(`${data.error}: ${data.message || ''}`);
            }

            console.log('✅ Cost Trend data:', data);

            // ถ้าไม่มีข้อมูลเลย
            if (!data || data.length === 0) {
                console.warn('⚠️ No data for Cost Trend');
                // แสดงกราฟเปล่า
                const ctx = document.getElementById('costTrendChart').getContext('2d');
                if (costTrendChart) costTrendChart.destroy();
                costTrendChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: [],
                        datasets: []
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            title: {
                                display: true,
                                text: 'ไม่มีข้อมูลในช่วง 12 เดือนที่ผ่านมา'
                            }
                        }
                    }
                });
                return;
            }

            const ctx = document.getElementById('costTrendChart').getContext('2d');
            if (costTrendChart) costTrendChart.destroy();

            costTrendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.map(item => item.month),
                    datasets: [
                        {
                            label: 'Total Cost',
                            data: data.map(item => parseFloat(item.total_cost)),
                            borderColor: '#0d6efd',
                            backgroundColor: 'rgba(13, 110, 253, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Material Cost',
                            data: data.map(item => parseFloat(item.material_cost)),
                            borderColor: '#198754',
                            borderWidth: 2,
                            tension: 0.4
                        },
                        {
                            label: 'Freight Cost',
                            data: data.map(item => parseFloat(item.freight_cost)),
                            borderColor: '#ffc107',
                            borderWidth: 2,
                            tension: 0.4
                        },
                        {
                            label: 'Service Cost',
                            data: data.map(item => parseFloat(item.service_cost)),
                            borderColor: '#dc3545',
                            borderWidth: 2,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + 
                                        parseFloat(context.parsed.y).toLocaleString('th-TH', {
                                            minimumFractionDigits: 2
                                        }) + ' THB';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: value => value.toLocaleString('th-TH') + ' THB'
                            }
                        }
                    }
                }
            });
        } catch (error) {
            console.error('❌ Error in loadCostTrend:', error);
            throw error;
        }
    }

    async function loadCostBreakdown() {
        const params = new URLSearchParams({
            action: 'get_cost_breakdown',
            ...getDateRange()
        });

        const response = await fetch(`${API_URL}?${params}`);
        const data = await response.json();

        if (data.error) throw new Error(data.error);

        const material = parseFloat(data.material_cost);
        const freight = parseFloat(data.freight_cost);
        const service = parseFloat(data.service_cost);
        const total = material + freight + service;

        const ctx = document.getElementById('costBreakdownChart').getContext('2d');
        if (costBreakdownChart) costBreakdownChart.destroy();

        costBreakdownChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Material Cost', 'Freight Cost', 'Service Cost'],
                datasets: [{
                    data: [material, freight, service],
                    backgroundColor: [
                        'rgba(25, 135, 84, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(220, 53, 69, 0.8)'
                    ],
                    borderColor: ['#198754', '#ffc107', '#dc3545'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = parseFloat(context.parsed).toLocaleString('th-TH', {
                                    minimumFractionDigits: 2
                                });
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return `${context.label}: ${value} THB (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    async function loadTopProducts() {
        try {
            const params = new URLSearchParams({
                action: 'get_top_products',
                limit: 10,
                ...getDateRange()
            });

            const response = await fetch(`${API_URL}?${params}`);
            const responseText = await response.text();
            console.log('📦 Top Products Response:', responseText.substring(0, 500));

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                console.error('❌ JSON Parse Error in loadTopProducts:', e);
                console.error('Full response:', responseText);
                throw new Error('Invalid JSON in Top Products response');
            }

            if (data.error) {
                console.error('❌ API Error in Top Products:', data);
                throw new Error(`${data.error}: ${data.message || ''}`);
            }

            const tbody = document.getElementById('topProductsBody');
            tbody.innerHTML = '';

            if (!data || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">ไม่มีข้อมูลในช่วงเวลาที่เลือก</td></tr>';
                return;
            }

            data.forEach((item, index) => {
                const rankClass = index < 3 ? 'bg-warning text-dark' : 'bg-secondary';
                tbody.innerHTML += `
                    <tr>
                        <td class="text-center">
                            <span class="badge badge-rank ${rankClass}">${index + 1}</span>
                        </td>
                        <td><code class="text-primary">${item.product_code}</code></td>
                        <td class="fw-semibold">${item.product_name}</td>
                        <td class="text-end">${item.total_quantity_formatted} ${item.unit_symbol || ''}</td>
                        <td class="text-end fw-bold text-success">${item.total_cost_formatted}</td>
                        <td class="text-end">${item.avg_unit_price_formatted}</td>
                        <td class="text-center"><span class="badge bg-info">${item.po_count}</span></td>
                    </tr>
                `;
            });
        } catch (error) {
            console.error('❌ Error in loadTopProducts:', error);
            document.getElementById('topProductsBody').innerHTML = 
                `<tr><td colspan="7" class="text-center text-danger py-4">❌ เกิดข้อผิดพลาด: ${error.message}</td></tr>`;
        }
    }

    async function loadSupplierComparison() {
        try {
            const params = new URLSearchParams({
                action: 'get_supplier_comparison',
                limit: 10,
                ...getDateRange()
            });

            const response = await fetch(`${API_URL}?${params}`);
            const responseText = await response.text();
            console.log('🏢 Supplier Response:', responseText.substring(0, 500));

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                console.error('❌ JSON Parse Error in loadSupplierComparison:', e);
                throw new Error('Invalid JSON in Supplier Comparison response');
            }

            if (data.error) {
                console.error('❌ API Error in Supplier Comparison:', data);
                throw new Error(`${data.error}: ${data.message || ''}`);
            }

            const tbody = document.getElementById('supplierBody');
            tbody.innerHTML = '';

            if (!data || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">ไม่มีข้อมูลในช่วงเวลาที่เลือก</td></tr>';
                return;
            }

            data.forEach(item => {
                tbody.innerHTML += `
                    <tr>
                        <td><code class="text-primary">${item.supplier_code}</code></td>
                        <td class="fw-semibold">${item.supplier_name}</td>
                        <td class="text-center"><span class="badge bg-primary">${item.total_pos}</span></td>
                        <td class="text-end fw-bold text-success">${item.total_amount_formatted}</td>
                        <td class="text-end">${item.avg_po_value_formatted}</td>
                        <td class="text-end">${item.total_material_formatted}</td>
                        <td class="text-end">${item.total_freight_formatted}</td>
                    </tr>
                `;
            });
        } catch (error) {
            console.error('❌ Error in loadSupplierComparison:', error);
            document.getElementById('supplierBody').innerHTML = 
                `<tr><td colspan="7" class="text-center text-danger py-4">❌ เกิดข้อผิดพลาด: ${error.message}</td></tr>`;
        }
    }

    async function loadMonthlyReport() {
        try {
            const params = new URLSearchParams({
                action: 'get_monthly_report',
                months: 12
            });

            const response = await fetch(`${API_URL}?${params}`);
            const responseText = await response.text();
            console.log('📅 Monthly Report Response:', responseText.substring(0, 500));

            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                console.error('❌ JSON Parse Error in loadMonthlyReport:', e);
                throw new Error('Invalid JSON in Monthly Report response');
            }

            if (data.error) {
                console.error('❌ API Error in Monthly Report:', data);
                throw new Error(`${data.error}: ${data.message || ''}`);
            }

            const tbody = document.getElementById('monthlyBody');
            tbody.innerHTML = '';

            if (!data || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">ไม่มีข้อมูลในช่วง 12 เดือนที่ผ่านมา</td></tr>';
                return;
            }

            data.forEach(item => {
                tbody.innerHTML += `
                    <tr>
                        <td class="fw-bold">${item.month}</td>
                        <td class="text-end">${item.material_formatted}</td>
                        <td class="text-end">${item.freight_formatted}</td>
                        <td class="text-end">${item.service_formatted}</td>
                        <td class="text-end fw-bold text-success">${item.total_formatted}</td>
                        <td class="text-center"><span class="badge bg-secondary">${item.po_count}</span></td>
                    </tr>
                `;
            });
        } catch (error) {
            console.error('❌ Error in loadMonthlyReport:', error);
            document.getElementById('monthlyBody').innerHTML = 
                `<tr><td colspan="6" class="text-center text-danger py-4">❌ เกิดข้อผิดพลาด: ${error.message}</td></tr>`;
        }
    }

    async function loadFreightAnalysis() {
        const params = new URLSearchParams({
            action: 'get_freight_analysis',
            ...getDateRange()
        });

        const response = await fetch(`${API_URL}?${params}`);
        const data = await response.json();

        if (data.error) throw new Error(data.error);

        document.getElementById('freightPOCount').textContent = data.summary.total_freight_pos;
        document.getElementById('freightTotalAmount').textContent = data.summary.total_freight_amount_formatted + ' THB';
        document.getElementById('freightAvgAmount').textContent = data.summary.avg_freight_per_po_formatted + ' THB';

        const tbody = document.getElementById('freightDetailsBody');
        tbody.innerHTML = '';

        if (data.details.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">ไม่มีข้อมูล</td></tr>';
            return;
        }

        data.details.forEach(item => {
            const statusBadge = item.allocation_status === 'Allocated'
                ? '<span class="badge bg-success">Allocated</span>'
                : '<span class="badge bg-warning text-dark">Not Allocated</span>';

            const linkedPO = item.linked_po_number
                ? `<code class="text-primary">${item.linked_po_number}</code>`
                : '<span class="text-muted">-</span>';

            tbody.innerHTML += `
                <tr>
                    <td><code class="text-primary">${item.po_number}</code></td>
                    <td>${item.po_date_formatted}</td>
                    <td>${item.supplier_name}</td>
                    <td class="text-end fw-bold">${item.freight_amount_formatted}</td>
                    <td class="text-center">${statusBadge}</td>
                    <td>${linkedPO}</td>
                </tr>
            `;
        });
    }
    </script>
</body>
</html>