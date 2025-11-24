<?php
// stock-api.php - API สำหรับหน้าดูสต็อก
error_reporting(E_ALL);
ini_set('display_errors', 0); // ปิด display_errors เพื่อไม่ให้ HTML error ปนใน JSON
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

// JSON Headers ต้องมาก่อน output อื่น ๆ
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// === Config ===
try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../classes/Auth.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Config file not found: ' . $e->getMessage()]);
    exit;
}

// Authentication (แก้ไขให้ไม่ redirect)
try {
    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication failed: ' . $e->getMessage()]);
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

// Debug mode - แสดงข้อมูล debug
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    echo json_encode([
        'debug' => true,
        'action' => $action,
        'server_time' => date('Y-m-d H:i:s'),
        'php_version' => phpversion(),
        'available_actions' => ['get_stock_summary', 'get_recent_movements', 'get_summary_stats', 'get_warehouses']
    ]);
    exit;
}

try {
    switch ($action) {
        case 'get_stock_summary':
            getStockSummary();
            break;
            
        case 'get_recent_movements':
            getRecentMovements();
            break;
            
        case 'get_summary_stats':
            getSummaryStats();
            break;
            
        case 'get_warehouses':
            getWarehouses();
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action: ' . $action, 'available_actions' => ['get_stock_summary', 'get_recent_movements', 'get_summary_stats', 'get_warehouses']]);
            break;
    }
} catch (Exception $e) {
    error_log("Stock API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}

function getStockSummary() {
    global $pdo;
    
    try {
        $sql = "
            SELECT TOP 50
                p.id AS product_id,
                p.Name AS product_name,
                p.SSP_Code AS product_code,
                w.warehouse_id,
                w.warehouse_name,
                ISNULL(wl.location_code, 'ไม่ระบุ') AS location_code,
                
                -- Stock Information
                ISNULL(i.current_stock, 0) AS current_stock,
                ISNULL(i.available_stock, 0) AS available_stock,
                ISNULL(i.reserved_stock, 0) AS reserved_stock,
                
                -- Pallet Information
                ISNULL(i.current_pallet, 0) AS current_pallet,
                ISNULL(i.available_pallet, 0) AS available_pallet,
                
                -- Unit Information
                ISNULL(u.unit_name, 'ชิ้น') AS unit_name,
                ISNULL(u.unit_symbol, 'ชิ้น') AS unit_symbol,
                
                -- Cost Information
                ISNULL(i.average_cost, 0) AS average_cost,
                
                -- Last Update
                i.last_updated,
                ISNULL(i.reorder_point, 10) AS reorder_point
                
            FROM Master_Products_ID p
            LEFT JOIN Inventory_Stock i ON p.id = i.product_id
            LEFT JOIN Warehouses w ON i.warehouse_id = w.warehouse_id
            LEFT JOIN Warehouse_Locations wl ON i.location_id = wl.location_id
            LEFT JOIN Units u ON p.Unit_id = u.unit_id
            WHERE p.is_active = 1 
                AND ISNULL(i.current_stock, 0) > 0
                AND ISNULL(w.is_active, 1) = 1
            ORDER BY p.Name
        ";
        
        $stmt = $pdo->query($sql);
        $results = $stmt->fetchAll();
        
        // Add computed fields
        foreach ($results as &$item) {
            // Stock status
            $available = floatval($item['available_stock']);
            $reorderPoint = floatval($item['reorder_point']);
            
            if ($available <= 0) {
                $item['stock_status'] = 'out';
                $item['stock_status_text'] = 'หมดสต็อก';
                $item['stock_status_color'] = 'danger';
            } elseif ($available <= $reorderPoint) {
                $item['stock_status'] = 'low';
                $item['stock_status_text'] = 'สต็อกต่ำ';
                $item['stock_status_color'] = 'warning';
            } else {
                $item['stock_status'] = 'good';
                $item['stock_status_text'] = 'มีสต็อก';
                $item['stock_status_color'] = 'success';
            }
            
            // Location display
            $item['location_display'] = $item['location_code'] ?: 'ไม่ระบุ';
            
            // Stock value
            $item['stock_value'] = floatval($item['available_stock']) * floatval($item['average_cost']);
            
            // Format numbers for display
            $item['current_stock_formatted'] = number_format(floatval($item['current_stock']), 2);
            $item['available_stock_formatted'] = number_format(floatval($item['available_stock']), 2);
            $item['reserved_stock_formatted'] = number_format(floatval($item['reserved_stock']), 2);
            $item['average_cost_formatted'] = number_format(floatval($item['average_cost']), 2);
            $item['stock_value_formatted'] = number_format($item['stock_value'], 2);
        }
        
        echo json_encode($results ?: []);
        
    } catch (Exception $e) {
        error_log("getStockSummary Error: " . $e->getMessage());
        echo json_encode(['error' => 'Stock summary error: ' . $e->getMessage()]);
    }
}

function getRecentMovements() {
    global $pdo;
    
    try {
        $sql = "
            SELECT TOP 20
                sm.movement_type,
                sm.quantity,
                sm.reference_number,
                sm.movement_date,
                sm.batch_lot,
                ISNULL(sm.quantity_pallet, 0) AS quantity_pallet,
                
                -- Product Info
                ISNULL(p.Name, 'ไม่ระบุสินค้า') AS product_name,
                
                -- Unit Info
                ISNULL(u.unit_symbol, 'ชิ้น') AS unit_symbol,
                
                -- Warehouse Info
                ISNULL(w.warehouse_name, 'ไม่ระบุคลัง') AS warehouse_name
                
            FROM Stock_Movements sm
            LEFT JOIN Master_Products_ID p ON sm.product_id = p.id
            LEFT JOIN Units u ON sm.unit_id = u.unit_id
            LEFT JOIN Warehouses w ON sm.warehouse_id = w.warehouse_id
            ORDER BY sm.movement_date DESC
        ";
        
        $stmt = $pdo->query($sql);
        $results = $stmt->fetchAll();
        
        // Add computed fields
        foreach ($results as &$item) {
            // Movement type display
            switch ($item['movement_type']) {
                case 'RECEIPT':
                    $item['movement_type_text'] = 'รับเข้า';
                    $item['movement_type_color'] = 'success';
                    $item['movement_type_icon'] = 'fa-arrow-down';
                    break;
                case 'ISSUE':
                    $item['movement_type_text'] = 'จ่ายออก';
                    $item['movement_type_color'] = 'danger';
                    $item['movement_type_icon'] = 'fa-arrow-up';
                    break;
                default:
                    $item['movement_type_text'] = $item['movement_type'];
                    $item['movement_type_color'] = 'secondary';
                    $item['movement_type_icon'] = 'fa-question';
            }
            
            $item['reference_type_text'] = 'การเคลื่อนไหว';
            
            // Format numbers
            $item['quantity_formatted'] = number_format(abs(floatval($item['quantity'])), 4);
            
            // Check if today
            if ($item['movement_date']) {
                $moveDate = new DateTime($item['movement_date']);
                $today = new DateTime();
                $item['is_today'] = $moveDate->format('Y-m-d') === $today->format('Y-m-d');
            } else {
                $item['is_today'] = false;
            }
        }
        
        echo json_encode($results ?: []);
        
    } catch (Exception $e) {
        error_log("getRecentMovements Error: " . $e->getMessage());
        echo json_encode(['error' => 'Movements error: ' . $e->getMessage()]);
    }
}

function getSummaryStats() {
    global $pdo;
    
    try {
        $stats = [];
        
        // Total products with stock
        $stmt = $pdo->query("
            SELECT COUNT(*) as total_products
            FROM Master_Products_ID p
            INNER JOIN Inventory_Stock i ON p.id = i.product_id
            WHERE p.is_active = 1 AND i.current_stock > 0
        ");
        $stats['total_products'] = intval($stmt->fetchColumn() ?: 0);
        
        // Today's receipts
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT sm.reference_number) as today_received
            FROM Stock_Movements sm
            WHERE sm.movement_type = 'RECEIPT' 
                AND CAST(sm.movement_date AS DATE) = CAST(GETDATE() AS DATE)
        ");
        $stats['today_received'] = intval($stmt->fetchColumn() ?: 0);
        
        // Today's issues
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT sm.reference_number) as today_issued
            FROM Stock_Movements sm
            WHERE sm.movement_type = 'ISSUE' 
                AND CAST(sm.movement_date AS DATE) = CAST(GETDATE() AS DATE)
        ");
        $stats['today_issued'] = intval($stmt->fetchColumn() ?: 0);
        
        // Low stock items
        $stmt = $pdo->query("
            SELECT COUNT(*) as low_stock
            FROM Master_Products_ID p
            INNER JOIN Inventory_Stock i ON p.id = i.product_id
            WHERE p.is_active = 1 
                AND i.available_stock > 0 
                AND i.available_stock <= ISNULL(i.reorder_point, 10)
        ");
        $stats['low_stock'] = intval($stmt->fetchColumn() ?: 0);
        
        echo json_encode($stats);
        
    } catch (Exception $e) {
        error_log("getSummaryStats Error: " . $e->getMessage());
        echo json_encode(['error' => 'Stats error: ' . $e->getMessage()]);
    }
}

function getWarehouses() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT 
                warehouse_id,
                warehouse_name
            FROM Warehouses 
            WHERE ISNULL(is_active, 1) = 1 
            ORDER BY warehouse_name
        ");
        
        echo json_encode($stmt->fetchAll() ?: []);
        
    } catch (Exception $e) {
        error_log("getWarehouses Error: " . $e->getMessage());
        echo json_encode(['error' => 'Warehouses error: ' . $e->getMessage()]);
    }
}
?>

