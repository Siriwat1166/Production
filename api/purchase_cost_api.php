<?php
// api/purchase_cost_api.php - API สำหรับ Purchase Cost Dashboard
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

// JSON Headers
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Config
try {
    require_once __DIR__ . '/../config.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Config file not found: ' . $e->getMessage()]);
    exit;
}

// Authentication - Check session
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Database Connection
try {
    $pdo = new PDO(
        "sqlsrv:Server=" . DB_SERVER . ";Database=" . DB_NAME,
        DB_USERNAME,
        DB_PASSWORD,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
        )
    );
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_summary_stats':
            getSummaryStats();
            break;

        case 'get_cost_trend':
            getCostTrend();
            break;

        case 'get_cost_breakdown':
            getCostBreakdown();
            break;

        case 'get_top_products':
            getTopProducts();
            break;

        case 'get_price_trend':
            getPriceTrend();
            break;

        case 'get_freight_analysis':
            getFreightAnalysis();
            break;

        case 'get_supplier_comparison':
            getSupplierComparison();
            break;

        case 'get_monthly_report':
            getMonthlyReport();
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'error' => 'Invalid action: ' . $action,
                'available_actions' => [
                    'get_summary_stats',
                    'get_cost_trend',
                    'get_cost_breakdown',
                    'get_top_products',
                    'get_price_trend',
                    'get_freight_analysis',
                    'get_supplier_comparison',
                    'get_monthly_report'
                ]
            ]);
            break;
    }
} catch (Exception $e) {
    error_log("Purchase Cost API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}

/**
 * สถิติภาพรวม (Summary Cards)
 */
function getSummaryStats() {
    global $pdo;

    try {
        // รับพารามิเตอร์วันที่
        $date_from = $_GET['date_from'] ?? date('Y-m-01'); // เริ่มต้นเดือน
        $date_to = $_GET['date_to'] ?? date('Y-m-d'); // วันนี้

        $stats = [];

        // Total Purchase Amount
        $stmt = $pdo->prepare("
            SELECT
                ISNULL(SUM(total_amount), 0) as total_purchase,
                COUNT(po_id) as total_orders,
                ISNULL(AVG(total_amount), 0) as avg_order_value
            FROM PO_Header
            WHERE po_date BETWEEN ? AND ?
                AND status NOT IN ('cancelled', 'draft')
        ");
        $stmt->execute([$date_from, $date_to]);
        $result = $stmt->fetch();

        $stats['total_purchase'] = floatval($result['total_purchase']);
        $stats['total_orders'] = intval($result['total_orders']);
        $stats['avg_order_value'] = floatval($result['avg_order_value']);

        // Material, Freight, Service Cost
        $stmt = $pdo->prepare("
            SELECT
                ISNULL(SUM(material_amount), 0) as total_material,
                ISNULL(SUM(freight_amount), 0) as total_freight,
                ISNULL(SUM(service_amount), 0) as total_service
            FROM PO_Header
            WHERE po_date BETWEEN ? AND ?
                AND status NOT IN ('cancelled', 'draft')
        ");
        $stmt->execute([$date_from, $date_to]);
        $result = $stmt->fetch();

        $stats['total_material'] = floatval($result['total_material']);
        $stats['total_freight'] = floatval($result['total_freight']);
        $stats['total_service'] = floatval($result['total_service']);

        // Format numbers
        $stats['total_purchase_formatted'] = number_format($stats['total_purchase'], 2);
        $stats['avg_order_value_formatted'] = number_format($stats['avg_order_value'], 2);
        $stats['total_material_formatted'] = number_format($stats['total_material'], 2);
        $stats['total_freight_formatted'] = number_format($stats['total_freight'], 2);
        $stats['total_service_formatted'] = number_format($stats['total_service'], 2);

        echo json_encode($stats);

    } catch (Exception $e) {
        error_log("getSummaryStats Error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * แนวโน้มค่าใช้จ่าย (Cost Trend over Time)
 */
function getCostTrend() {
    global $pdo;

    try {
        $months = intval($_GET['months'] ?? 12); // ย้อนหลัง 12 เดือน

        $stmt = $pdo->prepare("
            SELECT
                FORMAT(po_date, 'yyyy-MM') as month,
                ISNULL(SUM(material_amount), 0) as material_cost,
                ISNULL(SUM(freight_amount), 0) as freight_cost,
                ISNULL(SUM(service_amount), 0) as service_cost,
                ISNULL(SUM(total_amount), 0) as total_cost,
                COUNT(po_id) as po_count
            FROM PO_Header
            WHERE po_date >= DATEADD(MONTH, -?, GETDATE())
                AND status NOT IN ('cancelled', 'draft')
            GROUP BY FORMAT(po_date, 'yyyy-MM')
            ORDER BY month
        ");
        $stmt->execute([$months]);
        $results = $stmt->fetchAll();

        echo json_encode($results ?: []);

    } catch (Exception $e) {
        error_log("getCostTrend Error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * สัดส่วนค่าใช้จ่าย (Cost Breakdown)
 */
function getCostBreakdown() {
    global $pdo;

    try {
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT
                ISNULL(SUM(material_amount), 0) as material_cost,
                ISNULL(SUM(freight_amount), 0) as freight_cost,
                ISNULL(SUM(service_amount), 0) as service_cost,
                ISNULL(SUM(total_amount), 0) as total_cost
            FROM PO_Header
            WHERE po_date BETWEEN ? AND ?
                AND status NOT IN ('cancelled', 'draft')
        ");
        $stmt->execute([$date_from, $date_to]);
        $result = $stmt->fetch();

        $total = floatval($result['total_cost']);

        $breakdown = [
            [
                'category' => 'Material Cost',
                'amount' => floatval($result['material_cost']),
                'percentage' => $total > 0 ? round((floatval($result['material_cost']) / $total) * 100, 2) : 0
            ],
            [
                'category' => 'Freight Cost',
                'amount' => floatval($result['freight_cost']),
                'percentage' => $total > 0 ? round((floatval($result['freight_cost']) / $total) * 100, 2) : 0
            ],
            [
                'category' => 'Service Cost',
                'amount' => floatval($result['service_cost']),
                'percentage' => $total > 0 ? round((floatval($result['service_cost']) / $total) * 100, 2) : 0
            ]
        ];

        echo json_encode($breakdown);

    } catch (Exception $e) {
        error_log("getCostBreakdown Error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * สินค้าที่ซื้อมากที่สุด (Top Purchased Products)
 */
function getTopProducts() {
    global $pdo;

    try {
        $limit = intval($_GET['limit'] ?? 10);
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT TOP (?)
                p.id as product_id,
                ISNULL(p.SSP_Code, 'N/A') as product_code,
                ISNULL(p.Name, 'ไม่ระบุชื่อ') as product_name,
                SUM(pi.quantity) as total_quantity,
                SUM(pi.total_price) as total_cost,
                AVG(pi.unit_price) as avg_unit_price,
                COUNT(DISTINCT ph.po_id) as po_count,
                ISNULL(u.unit_symbol, 'ชิ้น') as unit_symbol
            FROM PO_Items pi
            INNER JOIN PO_Header ph ON pi.po_id = ph.po_id
            LEFT JOIN Master_Products_ID p ON pi.product_id = p.id
            LEFT JOIN Units u ON pi.purchase_unit_id = u.unit_id
            WHERE ph.po_date BETWEEN ? AND ?
                AND ph.status NOT IN ('cancelled', 'draft')
                AND pi.status != 'cancelled'
                AND pi.product_id IS NOT NULL
            GROUP BY p.id, p.SSP_Code, p.Name, u.unit_symbol
            ORDER BY total_cost DESC
        ");
        $stmt->execute([$limit, $date_from, $date_to]);
        $results = $stmt->fetchAll();

        // Add formatted fields
        foreach ($results as &$item) {
            $item['total_quantity_formatted'] = number_format(floatval($item['total_quantity']), 2);
            $item['total_cost_formatted'] = number_format(floatval($item['total_cost']), 2);
            $item['avg_unit_price_formatted'] = number_format(floatval($item['avg_unit_price']), 2);
        }

        echo json_encode($results ?: []);

    } catch (Exception $e) {
        error_log("getTopProducts Error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * แนวโน้มราคาสินค้า (Price Trend by Product)
 */
function getPriceTrend() {
    global $pdo;

    try {
        $product_id = intval($_GET['product_id'] ?? 0);
        $months = intval($_GET['months'] ?? 12);

        if ($product_id <= 0) {
            echo json_encode(['error' => 'product_id is required']);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT
                FORMAT(ph.po_date, 'yyyy-MM') as month,
                ph.po_date,
                AVG(pi.unit_price) as avg_price,
                MIN(pi.unit_price) as min_price,
                MAX(pi.unit_price) as max_price,
                SUM(pi.quantity) as quantity,
                COUNT(DISTINCT ph.po_id) as po_count
            FROM PO_Items pi
            INNER JOIN PO_Header ph ON pi.po_id = ph.po_id
            WHERE pi.product_id = ?
                AND ph.po_date >= DATEADD(MONTH, -?, GETDATE())
                AND ph.status NOT IN ('cancelled', 'draft')
                AND pi.status != 'cancelled'
            GROUP BY FORMAT(ph.po_date, 'yyyy-MM'), ph.po_date
            ORDER BY ph.po_date
        ");
        $stmt->execute([$product_id, $months]);
        $results = $stmt->fetchAll();

        echo json_encode($results ?: []);

    } catch (Exception $e) {
        error_log("getPriceTrend Error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * วิเคราะห์ค่าขนส่ง (Freight Cost Analysis)
 */
function getFreightAnalysis() {
    global $pdo;

    try {
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');

        // สถิติค่าขนส่งรวม
        $stmt = $pdo->prepare("
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
        $summary = $stmt->fetch();

        // รายละเอียด Freight PO พร้อม allocation
        $stmt = $pdo->prepare("
            SELECT TOP 20
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
        ");
        $stmt->execute([$date_from, $date_to]);
        $details = $stmt->fetchAll();

        // Format numbers
        foreach ($details as &$item) {
            $item['freight_amount_formatted'] = number_format(floatval($item['freight_amount']), 2);
            $item['po_date_formatted'] = date('d/m/Y', strtotime($item['po_date']));
        }

        $result = [
            'summary' => [
                'total_freight_pos' => intval($summary['total_freight_pos']),
                'total_freight_amount' => floatval($summary['total_freight_amount']),
                'avg_freight_per_po' => floatval($summary['avg_freight_per_po']),
                'total_freight_amount_formatted' => number_format(floatval($summary['total_freight_amount']), 2),
                'avg_freight_per_po_formatted' => number_format(floatval($summary['avg_freight_per_po']), 2)
            ],
            'details' => $details
        ];

        echo json_encode($result);

    } catch (Exception $e) {
        error_log("getFreightAnalysis Error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * เปรียบเทียบผู้ขาย (Supplier Cost Comparison)
 */
function getSupplierComparison() {
    global $pdo;

    try {
        $limit = intval($_GET['limit'] ?? 10);
        $date_from = $_GET['date_from'] ?? date('Y-m-01');
        $date_to = $_GET['date_to'] ?? date('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT TOP (?)
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
        ");
        $stmt->execute([$limit, $date_from, $date_to]);
        $results = $stmt->fetchAll();

        // Add formatted fields
        foreach ($results as &$item) {
            $item['total_amount_formatted'] = number_format(floatval($item['total_amount']), 2);
            $item['avg_po_value_formatted'] = number_format(floatval($item['avg_po_value']), 2);
            $item['total_material_formatted'] = number_format(floatval($item['total_material']), 2);
            $item['total_freight_formatted'] = number_format(floatval($item['total_freight']), 2);
        }

        echo json_encode($results ?: []);

    } catch (Exception $e) {
        error_log("getSupplierComparison Error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * รายงานรายเดือน (Monthly Cost Report)
 */
function getMonthlyReport() {
    global $pdo;

    try {
        $months = intval($_GET['months'] ?? 12);

        $stmt = $pdo->prepare("
            SELECT
                FORMAT(po_date, 'yyyy-MM') as month,
                DATENAME(MONTH, po_date) as month_name,
                YEAR(po_date) as year,
                ISNULL(SUM(material_amount), 0) as material,
                ISNULL(SUM(freight_amount), 0) as freight,
                ISNULL(SUM(service_amount), 0) as service,
                ISNULL(SUM(total_amount), 0) as total,
                COUNT(po_id) as po_count
            FROM PO_Header
            WHERE po_date >= DATEADD(MONTH, -?, GETDATE())
                AND status NOT IN ('cancelled', 'draft')
            GROUP BY FORMAT(po_date, 'yyyy-MM'), DATENAME(MONTH, po_date), YEAR(po_date)
            ORDER BY month
        ");
        $stmt->execute([$months]);
        $results = $stmt->fetchAll();

        // Add formatted fields
        foreach ($results as &$item) {
            $item['material_formatted'] = number_format(floatval($item['material']), 2);
            $item['freight_formatted'] = number_format(floatval($item['freight']), 2);
            $item['service_formatted'] = number_format(floatval($item['service']), 2);
            $item['total_formatted'] = number_format(floatval($item['total']), 2);
        }

        echo json_encode($results ?: []);

    } catch (Exception $e) {
        error_log("getMonthlyReport Error: " . $e->getMessage());
        throw $e;
    }
}
?>
