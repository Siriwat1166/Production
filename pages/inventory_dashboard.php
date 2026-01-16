<?php
// pages/inventory_dashboard.php - Dashboard รวมข้อมูลระบบรับเข้า-จ่ายออกสต็อก
require_once "../config/config.php";
require_once "../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();

// ข้อมูลผู้ใช้
$user_id = $_SESSION['user_id'] ?? 'N/A';
$username = $_SESSION['username'] ?? 'guest';
$full_name = $_SESSION['full_name'] ?? 'Guest User';
$role = $_SESSION['role'] ?? 'viewer';

$current_time = date('Y-m-d H:i:s');

// เชื่อมต่อฐานข้อมูล
try {
    $pdo = new PDO(
        "sqlsrv:Server=" . DB_SERVER . ";Database=" . DB_NAME,
        DB_USERNAME,
        DB_PASSWORD,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        )
    );
} catch (PDOException $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    die("Database connection failed.");
}

// Handle AJAX Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_POST['action']) {
            case 'get_kpi':
                echo json_encode(getKPIData($pdo));
                break;
            case 'get_movement_trend':
                echo json_encode(getMovementTrend($pdo));
                break;
            case 'get_top_materials':
                echo json_encode(getTopMaterials($pdo));
                break;
            case 'get_warehouse_distribution':
                echo json_encode(getWarehouseDistribution($pdo));
                break;
            case 'get_recent_movements':
                echo json_encode(getRecentMovements($pdo));
                break;
            case 'get_alerts':
                echo json_encode(getAlerts($pdo));
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        error_log("AJAX Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ฟังก์ชันดึงข้อมูล KPI
function getKPIData($pdo) {
    try {
        // 1. รับเข้าวันนี้
        $stmt = $pdo->query("
            SELECT
                COUNT(*) as count,
                ISNULL(SUM(quantity), 0) as total
            FROM Stock_Movements
            WHERE movement_type = 'RECEIPT'
              AND CAST(movement_date AS DATE) = CAST(GETDATE() AS DATE)
        ");
        $receiptToday = $stmt->fetch();

        // 2. จ่ายออกวันนี้
        $stmt = $pdo->query("
            SELECT
                COUNT(*) as count,
                ISNULL(SUM(quantity), 0) as total
            FROM Stock_Movements
            WHERE movement_type = 'ISSUE'
              AND CAST(movement_date AS DATE) = CAST(GETDATE() AS DATE)
        ");
        $issueToday = $stmt->fetch();

        // 3. สต็อกรวมทั้งหมด
        $stmt = $pdo->query("
            SELECT
                ISNULL(SUM(current_stock), 0) as total_stock,
                COUNT(*) as total_items
            FROM Inventory_Stock
            WHERE current_stock > 0
        ");
        $stockTotal = $stmt->fetch();

        // 4. มูลค่าสต็อก
        $stmt = $pdo->query("
            SELECT ISNULL(SUM(current_stock * average_cost), 0) as total_value
            FROM Inventory_Stock
            WHERE current_stock > 0
        ");
        $stockValue = $stmt->fetch();

        // 5. สต็อกต่ำ
        $stmt = $pdo->query("
            SELECT COUNT(*) as low_stock_count
            FROM Inventory_Stock
            WHERE current_stock <= reorder_point
              AND current_stock > 0
              AND reorder_point > 0
        ");
        $lowStock = $stmt->fetch();

        // 6. PO รอรับเข้า
        $stmt = $pdo->query("
            SELECT COUNT(*) as pending_po
            FROM PO_Header
            WHERE status IN ('Pending', 'Approved', 'Partial')
        ");
        $pendingPO = $stmt->fetch();

        // 7. รับเข้าเดือนนี้
        $stmt = $pdo->query("
            SELECT ISNULL(SUM(quantity), 0) as total
            FROM Stock_Movements
            WHERE movement_type = 'RECEIPT'
              AND MONTH(movement_date) = MONTH(GETDATE())
              AND YEAR(movement_date) = YEAR(GETDATE())
        ");
        $receiptMonth = $stmt->fetch();

        // 8. จ่ายออกเดือนนี้
        $stmt = $pdo->query("
            SELECT ISNULL(SUM(quantity), 0) as total
            FROM Stock_Movements
            WHERE movement_type = 'ISSUE'
              AND MONTH(movement_date) = MONTH(GETDATE())
              AND YEAR(movement_date) = YEAR(GETDATE())
        ");
        $issueMonth = $stmt->fetch();

        return [
            'success' => true,
            'data' => [
                'receipt_today' => [
                    'count' => $receiptToday['count'] ?? 0,
                    'total' => $receiptToday['total'] ?? 0
                ],
                'issue_today' => [
                    'count' => $issueToday['count'] ?? 0,
                    'total' => $issueToday['total'] ?? 0
                ],
                'stock_total' => [
                    'stock' => $stockTotal['total_stock'] ?? 0,
                    'items' => $stockTotal['total_items'] ?? 0
                ],
                'stock_value' => $stockValue['total_value'] ?? 0,
                'low_stock' => $lowStock['low_stock_count'] ?? 0,
                'pending_po' => $pendingPO['pending_po'] ?? 0,
                'receipt_month' => $receiptMonth['total'] ?? 0,
                'issue_month' => $issueMonth['total'] ?? 0
            ]
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ฟังก์ชันดึงข้อมูลแนวโน้มการเคลื่อนไหว 30 วัน
function getMovementTrend($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT
                CAST(movement_date AS DATE) as date,
                movement_type,
                ISNULL(SUM(quantity), 0) as total
            FROM Stock_Movements
            WHERE movement_date >= DATEADD(day, -30, GETDATE())
            GROUP BY CAST(movement_date AS DATE), movement_type
            ORDER BY date ASC
        ");
        $data = $stmt->fetchAll();

        return ['success' => true, 'data' => $data];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ฟังก์ชัน Top 10 วัสดุที่เคลื่อนไหวมากสุด
function getTopMaterials($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT TOP 10
                mp.Name,
                mp.SSP_Code,
                SUM(CASE WHEN sm.movement_type = 'RECEIPT' THEN sm.quantity ELSE 0 END) as total_in,
                SUM(CASE WHEN sm.movement_type = 'ISSUE' THEN sm.quantity ELSE 0 END) as total_out,
                (SUM(CASE WHEN sm.movement_type = 'RECEIPT' THEN sm.quantity ELSE 0 END) +
                 SUM(CASE WHEN sm.movement_type = 'ISSUE' THEN sm.quantity ELSE 0 END)) as total_movement
            FROM Stock_Movements sm
            JOIN Master_Products_ID mp ON sm.product_id = mp.id
            WHERE MONTH(sm.movement_date) = MONTH(GETDATE())
              AND YEAR(sm.movement_date) = YEAR(GETDATE())
            GROUP BY mp.Name, mp.SSP_Code
            ORDER BY total_movement DESC
        ");
        $data = $stmt->fetchAll();

        return ['success' => true, 'data' => $data];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ฟังก์ชันสัดส่วนสต็อกตามคลัง
function getWarehouseDistribution($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT
                w.warehouse_name_th as warehouse,
                ISNULL(SUM(ist.current_stock), 0) as total_stock
            FROM Inventory_Stock ist
            JOIN Warehouses w ON ist.warehouse_id = w.warehouse_id
            WHERE ist.current_stock > 0
            GROUP BY w.warehouse_name_th
            ORDER BY total_stock DESC
        ");
        $data = $stmt->fetchAll();

        return ['success' => true, 'data' => $data];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ฟังก์ชันรายการเคลื่อนไหวล่าสุด
function getRecentMovements($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT TOP 20
                sm.movement_date,
                sm.movement_type,
                mp.Name as material_name,
                mp.SSP_Code,
                sm.quantity,
                w.warehouse_name_th as warehouse,
                wl.aisle,
                sm.reference_number,
                sm.batch_lot
            FROM Stock_Movements sm
            LEFT JOIN Master_Products_ID mp ON sm.product_id = mp.id
            LEFT JOIN Warehouses w ON sm.warehouse_id = w.warehouse_id
            LEFT JOIN Warehouse_Locations wl ON sm.location_id = wl.location_id
            ORDER BY sm.movement_date DESC
        ");
        $data = $stmt->fetchAll();

        return ['success' => true, 'data' => $data];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ฟังก์ชัน Alerts
function getAlerts($pdo) {
    try {
        // 1. สต็อกต่ำ
        $stmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM Inventory_Stock
            WHERE current_stock <= reorder_point
              AND current_stock > 0
              AND reorder_point > 0
        ");
        $lowStock = $stmt->fetch();

        // 2. PO ค้างรับเกิน 7 วัน
        $stmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM PO_Header
            WHERE status IN ('Pending', 'Approved')
              AND DATEDIFF(day, po_date, GETDATE()) > 7
        ");
        $overduePO = $stmt->fetch();

        // 3. สินค้าไม่เคลื่อนไหว 30 วัน
        $stmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM Inventory_Stock
            WHERE current_stock > 0
              AND (last_movement_date IS NULL
                   OR DATEDIFF(day, last_movement_date, GETDATE()) > 30)
        ");
        $noMovement = $stmt->fetch();

        return [
            'success' => true,
            'data' => [
                'low_stock' => $lowStock['count'] ?? 0,
                'overdue_po' => $overduePO['count'] ?? 0,
                'no_movement' => $noMovement['count'] ?? 0
            ]
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Dashboard - ระบบรับเข้า-จ่ายออกสต็อก</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary-orange: #FF8C00;
            --primary-brown: #8B4513;
            --primary-green: #059669;
            --bg-cream: #FFF8DC;
            --bg-beige: #F5DEB3;
        }

        body {
            background: linear-gradient(135deg, #FFF8DC 0%, #FAEBD7 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-bottom: 2rem;
        }

        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-orange) 0%, var(--primary-brown) 100%);
            color: white;
            padding: 1.5rem 0;
            box-shadow: 0 4px 20px rgba(139, 69, 19, 0.3);
            margin-bottom: 2rem;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome-section h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        /* KPI Cards */
        .kpi-section {
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border-left: 5px solid var(--primary-brown);
            height: 100%;
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .kpi-card.receipt {
            border-left-color: var(--primary-green);
        }

        .kpi-card.issue {
            border-left-color: var(--primary-orange);
        }

        .kpi-card.stock {
            border-left-color: #3B82F6;
        }

        .kpi-card.value {
            border-left-color: #8B5CF6;
        }

        .kpi-card.alert {
            border-left-color: #DC2626;
        }

        .kpi-card.po {
            border-left-color: #F59E0B;
        }

        .kpi-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 1rem;
        }

        .kpi-icon.receipt { background: rgba(5, 150, 105, 0.1); color: var(--primary-green); }
        .kpi-icon.issue { background: rgba(255, 140, 0, 0.1); color: var(--primary-orange); }
        .kpi-icon.stock { background: rgba(59, 130, 246, 0.1); color: #3B82F6; }
        .kpi-icon.value { background: rgba(139, 92, 246, 0.1); color: #8B5CF6; }
        .kpi-icon.alert { background: rgba(220, 38, 38, 0.1); color: #DC2626; }
        .kpi-icon.po { background: rgba(245, 158, 11, 0.1); color: #F59E0B; }

        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.25rem;
        }

        .kpi-label {
            font-size: 0.9rem;
            color: #666;
            font-weight: 500;
        }

        .kpi-sub {
            font-size: 0.85rem;
            color: #999;
            margin-top: 0.5rem;
        }

        /* Chart Section */
        .chart-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .chart-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-brown);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-title i {
            color: var(--primary-orange);
        }

        /* Recent Movements Table */
        .table-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .movements-table {
            font-size: 0.9rem;
        }

        .movements-table thead th {
            background: linear-gradient(135deg, var(--primary-brown) 0%, #A0522D 100%);
            color: white;
            border: none;
            padding: 12px;
            font-weight: 600;
        }

        .movements-table tbody tr:hover {
            background-color: rgba(255, 140, 0, 0.1);
        }

        .badge-receipt {
            background: var(--primary-green);
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-size: 0.85rem;
        }

        .badge-issue {
            background: var(--primary-orange);
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-size: 0.85rem;
        }

        /* Alert Section */
        .alert-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .alert-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-left: 4px solid #DC2626;
            background: rgba(220, 38, 38, 0.05);
            border-radius: 8px;
            margin-bottom: 0.75rem;
        }

        .alert-item.warning {
            border-left-color: #F59E0B;
            background: rgba(245, 158, 11, 0.05);
        }

        .alert-item.info {
            border-left-color: #3B82F6;
            background: rgba(59, 130, 246, 0.05);
        }

        .alert-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.2rem;
        }

        .alert-icon.danger {
            background: rgba(220, 38, 38, 0.15);
            color: #DC2626;
        }

        .alert-icon.warning {
            background: rgba(245, 158, 11, 0.15);
            color: #F59E0B;
        }

        .alert-icon.info {
            background: rgba(59, 130, 246, 0.15);
            color: #3B82F6;
        }

        .alert-text {
            flex: 1;
            font-weight: 500;
            color: #333;
        }

        .alert-count {
            font-size: 1.5rem;
            font-weight: 700;
            margin-left: 1rem;
        }

        /* Quick Actions */
        .quick-actions {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            border-radius: 12px;
            text-decoration: none;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, var(--primary-brown), #A0522D);
            margin-bottom: 0.5rem;
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(139, 69, 19, 0.3);
            color: white;
        }

        .action-btn.green {
            background: linear-gradient(135deg, var(--primary-green), #10B981);
        }

        .action-btn.orange {
            background: linear-gradient(135deg, var(--primary-orange), #FFA500);
        }

        .action-btn i {
            margin-right: 0.5rem;
            font-size: 1.2rem;
        }

        /* Loading Spinner */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Refresh Button */
        .refresh-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-brown));
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(139, 69, 19, 0.3);
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .refresh-btn:hover {
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 6px 25px rgba(139, 69, 19, 0.5);
        }

        .refresh-btn i {
            font-size: 1.5rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .kpi-value {
                font-size: 1.5rem;
            }

            .chart-section {
                padding: 1rem;
            }

            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="dashboard-header">
        <div class="container-fluid">
            <div class="header-content">
                <div class="welcome-section">
                    <h1><i class="fas fa-chart-line me-2"></i>Inventory Dashboard - ระบบรับเข้า-จ่ายออกสต็อก</h1>
                    <p class="mb-0">
                        <i class="fas fa-user-tag me-2"></i><?= htmlspecialchars($full_name) ?> (<?= htmlspecialchars($role) ?>) |
                        <i class="fas fa-clock ms-3 me-2"></i><?= date('d/m/Y H:i', strtotime($current_time)) ?> น.
                    </p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-left me-2"></i>กลับหน้าหลัก
                    </a>
                    <a href="../logout.php" class="btn btn-outline-light btn-sm ms-2">
                        <i class="fas fa-sign-out-alt me-2"></i>ออกจากระบบ
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <!-- KPI Cards -->
        <div class="kpi-section">
            <div class="row g-3">
                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card receipt">
                        <div class="kpi-icon receipt">
                            <i class="fas fa-arrow-down"></i>
                        </div>
                        <div class="kpi-value" id="receiptTodayValue">
                            <span class="loading"></span>
                        </div>
                        <div class="kpi-label">รับเข้าวันนี้</div>
                        <div class="kpi-sub" id="receiptTodayCount">-</div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card issue">
                        <div class="kpi-icon issue">
                            <i class="fas fa-arrow-up"></i>
                        </div>
                        <div class="kpi-value" id="issueTodayValue">
                            <span class="loading"></span>
                        </div>
                        <div class="kpi-label">จ่ายออกวันนี้</div>
                        <div class="kpi-sub" id="issueTodayCount">-</div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card stock">
                        <div class="kpi-icon stock">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <div class="kpi-value" id="stockTotalValue">
                            <span class="loading"></span>
                        </div>
                        <div class="kpi-label">สต็อกคงคลัง</div>
                        <div class="kpi-sub" id="stockTotalItems">-</div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card value">
                        <div class="kpi-icon value">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="kpi-value" id="stockValueValue">
                            <span class="loading"></span>
                        </div>
                        <div class="kpi-label">มูลค่าสต็อก</div>
                        <div class="kpi-sub">ราคาเฉลี่ย</div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card receipt">
                        <div class="kpi-icon receipt">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="kpi-value" id="receiptMonthValue">
                            <span class="loading"></span>
                        </div>
                        <div class="kpi-label">รับเข้าเดือนนี้</div>
                        <div class="kpi-sub">Total this month</div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card issue">
                        <div class="kpi-icon issue">
                            <i class="fas fa-calendar-times"></i>
                        </div>
                        <div class="kpi-value" id="issueMonthValue">
                            <span class="loading"></span>
                        </div>
                        <div class="kpi-label">จ่ายออกเดือนนี้</div>
                        <div class="kpi-sub">Total this month</div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card alert">
                        <div class="kpi-icon alert">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="kpi-value" id="lowStockValue">
                            <span class="loading"></span>
                        </div>
                        <div class="kpi-label">สต็อกต่ำ</div>
                        <div class="kpi-sub">ต่ำกว่า Reorder Point</div>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="kpi-card po">
                        <div class="kpi-icon po">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div class="kpi-value" id="pendingPOValue">
                            <span class="loading"></span>
                        </div>
                        <div class="kpi-label">PO รอรับเข้า</div>
                        <div class="kpi-sub">Pending Orders</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Charts -->
            <div class="col-lg-8">
                <!-- Movement Trend Chart -->
                <div class="chart-section">
                    <div class="chart-title">
                        <i class="fas fa-chart-line"></i>
                        แนวโน้มการเคลื่อนไหวสต็อก (30 วันล่าสุด)
                    </div>
                    <canvas id="movementTrendChart" height="80"></canvas>
                </div>

                <!-- Top Materials Chart -->
                <div class="chart-section">
                    <div class="chart-title">
                        <i class="fas fa-chart-bar"></i>
                        Top 10 วัสดุที่เคลื่อนไหวมากสุด (เดือนนี้)
                    </div>
                    <canvas id="topMaterialsChart" height="80"></canvas>
                </div>

                <!-- Recent Movements Table -->
                <div class="table-section">
                    <div class="chart-title">
                        <i class="fas fa-history"></i>
                        รายการเคลื่อนไหวล่าสุด (20 รายการ)
                    </div>
                    <div class="table-responsive">
                        <table class="table movements-table">
                            <thead>
                                <tr>
                                    <th>เวลา</th>
                                    <th>ประเภท</th>
                                    <th>SSP Code</th>
                                    <th>ชื่อวัสดุ</th>
                                    <th>จำนวน</th>
                                    <th>คลัง</th>
                                    <th>พื้นที่</th>
                                    <th>Lot</th>
                                </tr>
                            </thead>
                            <tbody id="recentMovementsTable">
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <span class="loading"></span> กำลังโหลดข้อมูล...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Column: Alerts & Quick Actions -->
            <div class="col-lg-4">
                <!-- Alerts -->
                <div class="alert-section">
                    <div class="chart-title">
                        <i class="fas fa-bell"></i>
                        แจ้งเตือน
                    </div>
                    <div id="alertsContainer">
                        <div class="text-center py-3">
                            <span class="loading"></span> กำลังโหลด...
                        </div>
                    </div>
                </div>

                <!-- Warehouse Distribution Chart -->
                <div class="chart-section">
                    <div class="chart-title">
                        <i class="fas fa-warehouse"></i>
                        สัดส่วนสต็อกตามคลัง
                    </div>
                    <canvas id="warehouseChart" height="200"></canvas>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <div class="chart-title">
                        <i class="fas fa-bolt"></i>
                        Quick Actions
                    </div>
                    <a href="PO/receiving_po.php" class="action-btn green">
                        <i class="fas fa-truck-loading"></i>
                        รับเข้าสินค้า
                    </a>
                    <a href="/PD/production/inventory/dispatch_goods.php" class="action-btn orange">
                        <i class="fas fa-box-open"></i>
                        จ่ายออกสินค้า
                    </a>
                    <a href="/PD/production/inventory/stock_movements_list.php" class="action-btn">
                        <i class="fas fa-list"></i>
                        รายการเคลื่อนไหว
                    </a>
                    <a href="/PD/production/inventory/inventory_view.php" class="action-btn">
                        <i class="fas fa-boxes"></i>
                        สินค้าคงคลัง
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Refresh Button -->
    <button class="refresh-btn" onclick="refreshAllData()" title="รีเฟรชข้อมูล">
        <i class="fas fa-sync-alt"></i>
    </button>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        let movementTrendChart, topMaterialsChart, warehouseChart;

        // Format number
        function formatNumber(num) {
            if (num >= 1000000) {
                return (num / 1000000).toFixed(1) + 'M';
            } else if (num >= 1000) {
                return (num / 1000).toFixed(1) + 'K';
            }
            return Math.round(num).toLocaleString('th-TH');
        }

        // Format currency
        function formatCurrency(num) {
            if (num >= 1000000) {
                return '฿' + (num / 1000000).toFixed(2) + 'M';
            } else if (num >= 1000) {
                return '฿' + (num / 1000).toFixed(0) + 'K';
            }
            return '฿' + Math.round(num).toLocaleString('th-TH');
        }

        // Format date
        function formatDateTime(dateStr) {
            const date = new Date(dateStr);
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            return `${day}/${month} ${hours}:${minutes}`;
        }

        // Load KPI Data
        async function loadKPIData() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_kpi');

                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    const data = result.data;

                    // Receipt Today
                    document.getElementById('receiptTodayValue').textContent = formatNumber(data.receipt_today.total);
                    document.getElementById('receiptTodayCount').textContent = `${data.receipt_today.count} รายการ`;

                    // Issue Today
                    document.getElementById('issueTodayValue').textContent = formatNumber(data.issue_today.total);
                    document.getElementById('issueTodayCount').textContent = `${data.issue_today.count} รายการ`;

                    // Stock Total
                    document.getElementById('stockTotalValue').textContent = formatNumber(data.stock_total.stock);
                    document.getElementById('stockTotalItems').textContent = `${data.stock_total.items} รายการ`;

                    // Stock Value
                    document.getElementById('stockValueValue').textContent = formatCurrency(data.stock_value);

                    // Receipt Month
                    document.getElementById('receiptMonthValue').textContent = formatNumber(data.receipt_month);

                    // Issue Month
                    document.getElementById('issueMonthValue').textContent = formatNumber(data.issue_month);

                    // Low Stock
                    document.getElementById('lowStockValue').textContent = data.low_stock;

                    // Pending PO
                    document.getElementById('pendingPOValue').textContent = data.pending_po;
                }
            } catch (error) {
                console.error('Error loading KPI data:', error);
            }
        }

        // Load Movement Trend Chart
        async function loadMovementTrend() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_movement_trend');

                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    const data = result.data;

                    // Prepare data
                    const dates = [...new Set(data.map(d => d.date))].sort();
                    const receiptData = dates.map(date => {
                        const item = data.find(d => d.date === date && d.movement_type === 'RECEIPT');
                        return item ? item.total : 0;
                    });
                    const issueData = dates.map(date => {
                        const item = data.find(d => d.date === date && d.movement_type === 'ISSUE');
                        return item ? item.total : 0;
                    });

                    // Format dates
                    const labels = dates.map(d => {
                        const date = new Date(d);
                        return `${date.getDate()}/${date.getMonth() + 1}`;
                    });

                    // Destroy existing chart
                    if (movementTrendChart) {
                        movementTrendChart.destroy();
                    }

                    // Create chart
                    const ctx = document.getElementById('movementTrendChart').getContext('2d');
                    movementTrendChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'รับเข้า',
                                    data: receiptData,
                                    borderColor: '#059669',
                                    backgroundColor: 'rgba(5, 150, 105, 0.1)',
                                    tension: 0.4,
                                    fill: true
                                },
                                {
                                    label: 'จ่ายออก',
                                    data: issueData,
                                    borderColor: '#FF8C00',
                                    backgroundColor: 'rgba(255, 140, 0, 0.1)',
                                    tension: 0.4,
                                    fill: true
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: {
                                legend: {
                                    position: 'top',
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error loading movement trend:', error);
            }
        }

        // Load Top Materials Chart
        async function loadTopMaterials() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_top_materials');

                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    const data = result.data;

                    const labels = data.map(d => d.SSP_Code || d.Name.substring(0, 20));
                    const receiptData = data.map(d => d.total_in);
                    const issueData = data.map(d => d.total_out);

                    // Destroy existing chart
                    if (topMaterialsChart) {
                        topMaterialsChart.destroy();
                    }

                    // Create chart
                    const ctx = document.getElementById('topMaterialsChart').getContext('2d');
                    topMaterialsChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'รับเข้า',
                                    data: receiptData,
                                    backgroundColor: 'rgba(5, 150, 105, 0.7)'
                                },
                                {
                                    label: 'จ่ายออก',
                                    data: issueData,
                                    backgroundColor: 'rgba(255, 140, 0, 0.7)'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: {
                                legend: {
                                    position: 'top',
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error loading top materials:', error);
            }
        }

        // Load Warehouse Distribution Chart
        async function loadWarehouseDistribution() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_warehouse_distribution');

                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    const data = result.data;

                    const labels = data.map(d => d.warehouse);
                    const values = data.map(d => d.total_stock);

                    // Destroy existing chart
                    if (warehouseChart) {
                        warehouseChart.destroy();
                    }

                    // Create chart
                    const ctx = document.getElementById('warehouseChart').getContext('2d');
                    warehouseChart = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: values,
                                backgroundColor: [
                                    '#8B4513',
                                    '#FF8C00',
                                    '#059669',
                                    '#3B82F6',
                                    '#8B5CF6',
                                    '#F59E0B'
                                ]
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error loading warehouse distribution:', error);
            }
        }

        // Load Recent Movements
        async function loadRecentMovements() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_recent_movements');

                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    const data = result.data;
                    const tbody = document.getElementById('recentMovementsTable');

                    if (data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-3">ไม่มีข้อมูล</td></tr>';
                        return;
                    }

                    let html = '';
                    data.forEach(item => {
                        const badgeClass = item.movement_type === 'RECEIPT' ? 'badge-receipt' : 'badge-issue';
                        const icon = item.movement_type === 'RECEIPT' ? '↓' : '↑';
                        const typeText = item.movement_type === 'RECEIPT' ? 'รับเข้า' : 'จ่ายออก';

                        html += `
                            <tr>
                                <td>${formatDateTime(item.movement_date)}</td>
                                <td><span class="${badgeClass}">${icon} ${typeText}</span></td>
                                <td><strong>${item.SSP_Code || '-'}</strong></td>
                                <td>${item.material_name || '-'}</td>
                                <td>${formatNumber(item.quantity)}</td>
                                <td>${item.warehouse || '-'}</td>
                                <td>${item.aisle || '-'}</td>
                                <td>${item.batch_lot || '-'}</td>
                            </tr>
                        `;
                    });

                    tbody.innerHTML = html;
                }
            } catch (error) {
                console.error('Error loading recent movements:', error);
            }
        }

        // Load Alerts
        async function loadAlerts() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_alerts');

                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    const data = result.data;
                    const container = document.getElementById('alertsContainer');

                    let html = '';

                    if (data.low_stock > 0) {
                        html += `
                            <div class="alert-item">
                                <div class="alert-icon danger">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="alert-text">
                                    สต็อกต่ำกว่า Reorder Point
                                </div>
                                <div class="alert-count" style="color: #DC2626;">
                                    ${data.low_stock}
                                </div>
                            </div>
                        `;
                    }

                    if (data.overdue_po > 0) {
                        html += `
                            <div class="alert-item warning">
                                <div class="alert-icon warning">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="alert-text">
                                    PO ค้างรับเกิน 7 วัน
                                </div>
                                <div class="alert-count" style="color: #F59E0B;">
                                    ${data.overdue_po}
                                </div>
                            </div>
                        `;
                    }

                    if (data.no_movement > 0) {
                        html += `
                            <div class="alert-item info">
                                <div class="alert-icon info">
                                    <i class="fas fa-pause-circle"></i>
                                </div>
                                <div class="alert-text">
                                    สินค้าไม่เคลื่อนไหว 30 วัน
                                </div>
                                <div class="alert-count" style="color: #3B82F6;">
                                    ${data.no_movement}
                                </div>
                            </div>
                        `;
                    }

                    if (html === '') {
                        html = '<div class="text-center text-success py-3"><i class="fas fa-check-circle fa-2x mb-2"></i><br>ไม่มีการแจ้งเตือน</div>';
                    }

                    container.innerHTML = html;
                }
            } catch (error) {
                console.error('Error loading alerts:', error);
            }
        }

        // Refresh all data
        function refreshAllData() {
            loadKPIData();
            loadMovementTrend();
            loadTopMaterials();
            loadWarehouseDistribution();
            loadRecentMovements();
            loadAlerts();
        }

        // Auto refresh every 60 seconds
        setInterval(refreshAllData, 60000);

        // Initial load
        window.addEventListener('load', function() {
            refreshAllData();
        });
    </script>
</body>
</html>
