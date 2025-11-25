<?php
// receiving.php - Combined Receipt Form (PO Mode + Direct Mode)
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');
ini_set('display_startup_errors', 1);

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin', 'editor']);

// --- Mode from URL (po|direct), default 'po' ---
$mode = 'po';


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
    error_log("Database Connection Error: " . $e->getMessage());
    die("Database connection failed.");
}

// ===== AJAX HANDLERS =====
if (isset($_GET['ajax']) || (isset($_POST['action']))) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();

    try {
        $action = $_GET['ajax'] ?? $_POST['action'] ?? '';

        switch ($action) {
            // -------- Direct Receipt AJAX --------
            case 'get_products_by_supplier':
                $supplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
                if ($supplierId <= 0) { echo json_encode([]); exit; }
                $stmt = $pdo->prepare("
                    SELECT id AS product_id, Name AS product_name, Unit_id AS default_unit_id, SSP_Code AS product_code
                    FROM Master_Products_ID
                    WHERE is_active = 1 AND supplier_id = :sid
                    ORDER BY Name
                ");
                $stmt->execute(['sid' => $supplierId]);
                echo json_encode($stmt->fetchAll());
                break;

            case 'get_units_for_product':
                $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
                if ($productId <= 0) { echo json_encode(['units'=>[], 'default_unit_id'=>null]); exit; }

                $def = $pdo->prepare("SELECT Unit_id FROM Master_Products_ID WHERE id = :pid");
                $def->execute(['pid' => $productId]);
                $default_unit_id = $def->fetchColumn();

                $q = $pdo->prepare("
                    SELECT pu.unit_id, u.unit_code, u.unit_name, u.unit_symbol,
                           pu.is_purchase_unit, pu.is_stock_unit, pu.is_issue_unit
                    FROM Product_Units pu
                    JOIN Units u ON u.unit_id = pu.unit_id
                    WHERE pu.product_id = :pid
                    ORDER BY 
                        CASE WHEN pu.is_purchase_unit = 1 THEN 0 ELSE 1 END,
                        CASE WHEN pu.is_stock_unit = 1 THEN 0 ELSE 1 END,
                        u.unit_name
                ");
                $q->execute(['pid' => $productId]);
                $units = $q->fetchAll();

                if (!$units) {
                    $q2 = $pdo->query("SELECT unit_id, unit_code, unit_name, unit_symbol FROM Units WHERE is_active = 1 ORDER BY unit_name");
                    $units = $q2->fetchAll();
                }
                echo json_encode(['units' => $units, 'default_unit_id' => $default_unit_id ? (int)$default_unit_id : null]);
                break;

            case 'get_locations_by_warehouse':
                $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
                if ($warehouseId <= 0) { echo json_encode([]); exit; }
                $stmt = $pdo->prepare("
                    SELECT location_id, location_code, zone
                    FROM Warehouse_Locations
                    WHERE warehouse_id = :wid AND (is_active = 1 OR is_active IS NULL)
                    ORDER BY zone, location_code
                ");
                $stmt->execute(['wid' => $warehouseId]);
                $locations = $stmt->fetchAll();
                foreach ($locations as &$loc) {
                    $loc['zone_name'] = 'Zone ' . $loc['zone'];
                }
                echo json_encode($locations);
                break;

            case 'get_product_by_code':
                $code = isset($_GET['code']) ? trim($_GET['code']) : '';
                if ($code === '') { echo json_encode(['found' => false]); exit; }
                $stmt = $pdo->prepare("
                    SELECT TOP 1 id AS product_id, Name AS product_name, Unit_id AS default_unit_id,
                           SSP_Code AS product_code, supplier_id
                    FROM Master_Products_ID
                    WHERE is_active = 1 AND SSP_Code = :code
                    ORDER BY id DESC
                ");
                $stmt->execute(['code' => $code]);
                $row = $stmt->fetch();

                if (!$row) { echo json_encode(['found' => false]); exit; }

                $supName = null;
                if (!empty($row['supplier_id'])) {
                    $s = $pdo->prepare("SELECT supplier_name FROM Suppliers WHERE supplier_id = :sid");
                    $s->execute(['sid' => (int)$row['supplier_id']]);
                    $supName = $s->fetchColumn();
                }

                echo json_encode([
                    'found' => true,
                    'product_id' => (int)$row['product_id'],
                    'product_name' => $row['product_name'],
                    'default_unit_id' => $row['default_unit_id'] ? (int)$row['default_unit_id'] : null,
                    'product_code' => $row['product_code'],
                    'supplier_id' => $row['supplier_id'] ? (int)$row['supplier_id'] : null,
                    'supplier_name' => $supName
                ]);
                break;

            case 'save_direct_receipt':
                $receiptData = [
                    'receipt_number' => $_POST['receipt_number'] ?? '',
                    'receipt_date' => $_POST['receipt_date'] ?? '',
                    'supplier_id' => !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null,
                    'receipt_reason' => $_POST['receipt_reason'] ?? '',
                    'notes' => $_POST['notes'] ?? '',
                    'items' => $_POST['items'] ?? []
                ];
                $result = saveDirectReceipt($receiptData);
                echo json_encode($result);
                break;

            // -------- PO Receipt AJAX --------
            case 'get_warehouses':
                $warehouses = getWarehouses();
                ob_clean();
                echo json_encode(['success' => true, 'warehouses' => $warehouses]);
                break;

            case 'get_po_data_with_conversions':
                if (!isset($_POST['po_number']) || empty($_POST['po_number'])) {
                    ob_clean();
                    echo json_encode(['success' => false, 'message' => 'PO number is required']);
                    exit;
                }
                ob_clean();
                echo json_encode(getPODataWithConversions($_POST['po_number']));
                break;

case 'calculate_conversion':
    if (!isset($_POST['product_id'], $_POST['from_unit'], $_POST['to_unit'], $_POST['quantity'])) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit;
    }
    
    $productId = (int)$_POST['product_id'];
    $fromUnit = (int)$_POST['from_unit'];
    $toUnit = (int)$_POST['to_unit'];
    $quantity = (float)$_POST['quantity'];
    
    error_log("calculate_conversion: product={$productId}, from={$fromUnit}, to={$toUnit}, qty={$quantity}");
    
    $factor = getConversionFactor($productId, $fromUnit, $toUnit);
    $converted_quantity = $quantity * $factor;
    
    error_log("Conversion result: {$quantity} × {$factor} = {$converted_quantity}");
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'conversion_factor' => $factor,
        'converted_quantity' => $converted_quantity,
        'debug' => [
            'product_id' => $productId,
            'from_unit_id' => $fromUnit,
            'to_unit_id' => $toUnit,
            'input_quantity' => $quantity,
            'factor_used' => $factor
        ]
    ]);
    break;

            case 'get_suppliers':
                $query = "SELECT supplier_id, supplier_name, contact_person, phone, email
                          FROM Suppliers WHERE is_active = 1 ORDER BY supplier_name";
                $stmt = $pdo->prepare($query);
                $stmt->execute();
                $suppliers = $stmt->fetchAll();
                ob_clean();
                echo json_encode(['success' => true, 'suppliers' => $suppliers]);
                break;

            case 'get_warehouse_locations':
                if (!isset($_POST['warehouse_id']) || empty($_POST['warehouse_id'])) {
                    ob_clean();
                    echo json_encode(['success' => false, 'message' => 'Warehouse ID is required']);
                    exit;
                }
                $locations = getWarehouseLocations($_POST['warehouse_id']);
                ob_clean();
                echo json_encode(['success' => true, 'locations' => $locations]);
                break;

case 'save_receipt_enhanced':
    try {
        // Log ข้อมูลที่ได้รับ
        error_log("Received POST data: " . print_r($_POST, true));
        
        $input = file_get_contents('php://input');
        error_log("Raw input: " . $input);
        
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON: ' . json_last_error_msg());
        }
        
        error_log("Decoded data: " . print_r($data, true));
        
        if (!isset($data['po_data']) || !isset($data['items_data'])) {
            throw new Exception('Missing required data. Received keys: ' . implode(', ', array_keys($data)));
        }
        
        $result = saveReceiptEnhanced($data);
        
        error_log("Save result: " . print_r($result, true));
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        error_log("Error in save_receipt_enhanced case: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
    break;

            default:
                echo json_encode(['error' => 'Unknown action: ' . $action]);
        }
    } catch (Throwable $e) {
        error_log("AJAX Error: " . $e->getMessage());
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

    ob_end_flush();
    exit;
}

// ===== HELPER FUNCTIONS =====
function saveDirectReceipt($data) {
    global $pdo;

    try {
        $pdo->beginTransaction();

        if (empty($data['receipt_number']) || empty($data['receipt_date'])) {
            throw new Exception('Missing required fields');
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new Exception('No items provided');
        }

        $validItems = [];
        $totalQuantity = 0;
        $estimatedValue = 0;

        foreach ($data['items'] as $item) {
            if (!empty($item['product_id']) && !empty($item['quantity']) && $item['quantity'] > 0) {
                $quantity = (float)$item['quantity'];
                $unitCost = !empty($item['estimated_unit_cost']) ? (float)$item['estimated_unit_cost'] : 0;
                $validItems[] = $item;
                $totalQuantity += $quantity;
                $estimatedValue += ($quantity * $unitCost);
            }
        }
        if (empty($validItems)) {
            throw new Exception('No valid items found');
        }
        $totalItems = count($validItems);

        $headerSql = "
            INSERT INTO Direct_Receipt_Header (
                receipt_number, receipt_date, supplier_id, receipt_reason, 
                total_items, total_quantity, estimated_value, status, 
                created_by, created_date, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'DRAFT', ?, GETDATE(), ?)
        ";
        $stmt = $pdo->prepare($headerSql);
        $stmt->execute([
            $data['receipt_number'], $data['receipt_date'], $data['supplier_id'],
            $data['receipt_reason'], $totalItems, $totalQuantity, $estimatedValue,
            $_SESSION['user_id'] ?? 1, $data['notes']
        ]);

        $directReceiptId = $pdo->lastInsertId();
        if (!$directReceiptId) throw new Exception('Failed to get direct receipt ID');

        $itemSql = "
            INSERT INTO Direct_Receipt_Items (
                direct_receipt_id, line_number, product_id, item_description,
                quantity, unit_id, estimated_unit_cost, actual_unit_cost,
                warehouse_id, location_id, supplier_lot_number,
                manufacturing_date, expiry_date, pallet_count
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $itemStmt = $pdo->prepare($itemSql);

        foreach ($validItems as $index => $item) {
            $productDesc = '';
            if (!empty($item['item_description'])) {
                $productDesc = $item['item_description'];
            } else if (!empty($item['product_id'])) {
                $descSql = "SELECT Name FROM Master_Products_ID WHERE id = ?";
                $descStmt = $pdo->prepare($descSql);
                $descStmt->execute([$item['product_id']]);
                $productDesc = $descStmt->fetchColumn() ?: 'Unknown Product';
            }

            $mfgDate = !empty($item['manufacturing_date']) ? $item['manufacturing_date'] : null;
            $expDate = !empty($item['expiry_date']) ? $item['expiry_date'] : null;

            $itemStmt->execute([
                $directReceiptId, $index + 1, !empty($item['product_id']) ? (int)$item['product_id'] : null,
                $productDesc, (float)$item['quantity'], !empty($item['unit_id']) ? (int)$item['unit_id'] : null,
                !empty($item['estimated_unit_cost']) ? (float)$item['estimated_unit_cost'] : null,
                !empty($item['actual_unit_cost']) ? (float)$item['actual_unit_cost'] : null,
                !empty($item['warehouse_id']) ? (int)$item['warehouse_id'] : null,
                !empty($item['location_id']) ? (int)$item['location_id'] : null,
                $item['supplier_lot_number'] ?? null, $mfgDate, $expDate,
                isset($item['pallet_count']) ? (int)$item['pallet_count'] : 0
            ]);
        }

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Direct receipt saved successfully',
            'direct_receipt_id' => $directReceiptId,
            'receipt_number' => $data['receipt_number'],
            'total_items' => $totalItems,
            'total_quantity' => $totalQuantity,
            'estimated_value' => $estimatedValue
        ];

    } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollback();
        error_log("Save Direct Receipt Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function getWarehouses() {
    global $pdo;
    try {
        $query = "SELECT warehouse_id, warehouse_code, warehouse_name, warehouse_name_th, warehouse_type, address
                  FROM Warehouses WHERE is_active = 1 ORDER BY warehouse_name";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting warehouses: " . $e->getMessage());
        return [];
    }
}

function getWarehouseLocations($warehouse_id) {
    global $pdo;
    try {
        $query = "SELECT location_id, warehouse_id, zone, location_code, location_type, is_active
                  FROM Warehouse_Locations WHERE warehouse_id = ? AND is_active = 1 
                  ORDER BY zone, location_code";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$warehouse_id]);
        $locations = $stmt->fetchAll();
        foreach ($locations as &$location) {
            $location['location_name_th'] = "Zone " . $location['zone'] . " - " . $location['location_code'];
            $location['zone_name'] = "Zone " . $location['zone'];
        }
        return $locations;
    } catch (PDOException $e) {
        error_log("Error getting warehouse locations: " . $e->getMessage());
        return [];
    }
}

function getPODataWithConversions($po_number) {
    global $pdo;
    try {
        // Header
        $stmt = $pdo->prepare("
            SELECT ph.po_id, ph.po_number, ph.po_date, ph.total_amount, ph.delivery_date, ph.notes, ph.supplier_id,
                   ISNULL(s.supplier_name, 'Unknown Supplier') as supplier_name
            FROM PO_Header ph
            LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
            WHERE ph.po_number = ?
        ");
        $stmt->execute([$po_number]);
        $po = $stmt->fetch();
        if (!$po) return ['success' => false, 'message' => 'PO not found'];

        // ✅ แก้ไข: ดึงข้อมูลให้ครบถ้วน
        $stmt = $pdo->prepare("
            SELECT 
                pi.po_item_id, pi.line_number, pi.product_id, pi.item_description,
                -- ✅ ต้องมีค่าเหล่านี้
                ISNULL(pi.quantity, 0) as quantity,
                ISNULL(pi.received_quantity, 0) as received_quantity,
                ISNULL(pi.pending_quantity, 0) as pending_quantity,
                ISNULL(pi.unit_price, 0) as unit_price,
                ISNULL(pi.total_price, 0) as total_price,
                ISNULL(mp.SSP_Code, 'N/A') as product_code,
                ISNULL(mp.Name, pi.item_description) as product_name,
                
                -- ✅ ข้อมูลหน่วย
                pi.purchase_unit_id,
                pi.stock_unit_id,
                ISNULL(pi.conversion_factor, 1) as conversion_factor,
                
                -- ✅ ชื่อหน่วยที่ชัดเจน
                COALESCE(u_purchase.unit_name, u_purchase.unit_name_th, u_purchase.unit_code, 'หน่วย') as purchase_unit_name,
                COALESCE(u_purchase.unit_symbol, u_purchase.unit_code, '') as purchase_unit_symbol,
                COALESCE(u_stock.unit_name, u_stock.unit_name_th, u_stock.unit_code, 'หน่วย') as stock_unit_name,
                COALESCE(u_stock.unit_symbol, u_stock.unit_code, '') as stock_unit_symbol,
                
                -- Paperboard info
                sp.W_mm, sp.L_mm, sp.gsm, sp.Weight_kg_per_sheet, sp.type_paperboard_TH, sp.brand
            FROM PO_Items pi
            LEFT JOIN Master_Products_ID mp ON pi.product_id = mp.id
            LEFT JOIN Units u_purchase ON pi.purchase_unit_id = u_purchase.unit_id
            LEFT JOIN Units u_stock ON pi.stock_unit_id = u_stock.unit_id
            LEFT JOIN Specific_Paperboard sp ON pi.product_id = sp.product_id
            WHERE pi.po_id = ?
            ORDER BY pi.line_number
        ");
        $stmt->execute([$po['po_id']]);
        $items = $stmt->fetchAll();

        // ✅ เพิ่ม debug log
        error_log("PO Items count: " . count($items));
        foreach ($items as $idx => $item) {
            error_log("Item {$idx}: quantity={$item['quantity']}, received={$item['received_quantity']}, pending={$item['pending_quantity']}, unit_id={$item['purchase_unit_id']}");
        }

        foreach ($items as &$it) {
            // ✅ คำนวณ pending ถ้ายังไม่มี
            if (!$it['pending_quantity'] || $it['pending_quantity'] <= 0) {
                $it['pending_quantity'] = max(0, $it['quantity'] - $it['received_quantity']);
            }
            
            // Paperboard calculations
            if ($it['W_mm'] && $it['L_mm'] && $it['gsm']) {
                $area_m2 = ($it['W_mm']/1000.0) * ($it['L_mm']/1000.0);
                $kg_per_sheet = $area_m2 * ($it['gsm']/1000.0);
                $sheets_per_kg = $kg_per_sheet > 0 ? (1/$kg_per_sheet) : 0;
                $it['paperboard_info'] = [
                    'W_mm' => $it['W_mm'],
                    'L_mm' => $it['L_mm'],
                    'gsm'  => $it['gsm'],
                    'Weight_kg_per_sheet' => number_format($kg_per_sheet, 6),
                    'sheets_per_kg'       => number_format($sheets_per_kg, 3),
                ];
            }
            
            if ($it['product_id']) {
                $it['available_units'] = getAvailableUnitsForProduct($it['product_id']);
                $recv = array_filter($it['available_units'], fn($u)=> $u['category']==='count' || $u['unit_type']==='stock');
                $it['receiving_units'] = $recv ?: $it['available_units'];
                $it['conversions'] = getProductUnitConversions($it['product_id']);
            } else {
                $it['available_units'] = $it['receiving_units'] = $it['conversions'] = [];
            }
        }

        return ['success' => true, 'po_data' => $po, 'items' => $items];
    } catch (PDOException $e) {
        error_log("Error getting PO data: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function getAvailableUnitsForProduct($product_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                uom.uom_id, uom.uom_id AS unit_id,
                uom.code AS unit_symbol, uom.name AS unit_name_th,
                uom.code, uom.name, uom.category, uom.base_uom_id,
                uom.to_base_factor, uom.from_base_factor,
                CASE WHEN uom.base_uom_id IS NULL THEN 1 ELSE 0 END as is_base_unit,
                CASE 
                    WHEN uom.category = 'weight' THEN 'purchase'
                    WHEN uom.category = 'count'  THEN 'stock'
                    ELSE 'general'
                END as unit_type,
                COALESCE(u.unit_id, 1) as mapped_unit_id
            FROM UNITS_OF_MEASURE uom
            LEFT JOIN Units u ON u.unit_code = uom.code AND u.is_active = 1
            WHERE uom.is_active = 1
              AND (
                    uom.uom_id IN (
                        SELECT from_uom_id FROM PRODUCT_UOM_CONVERSIONS WHERE product_id = ? AND is_active = 1
                        UNION
                        SELECT to_uom_id   FROM PRODUCT_UOM_CONVERSIONS WHERE product_id = ? AND is_active = 1
                    )
                    OR uom.base_uom_id IS NULL
                  )
            ORDER BY uom.category, uom.code
        ");
        $stmt->execute([$product_id, $product_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting available units: " . $e->getMessage());
        return [];
    }
}

function getProductUnitConversions($product_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT 
                puc.conversion_id, puc.product_id, puc.from_uom_id, puc.to_uom_id, puc.conversion_factor,
                from_uom.code as from_code, from_uom.name as from_name,
                to_uom.code as to_code, to_uom.name as to_name
            FROM PRODUCT_UOM_CONVERSIONS puc
            JOIN UNITS_OF_MEASURE from_uom ON puc.from_uom_id = from_uom.uom_id
            JOIN UNITS_OF_MEASURE to_uom   ON puc.to_uom_id   = to_uom.uom_id
            WHERE puc.product_id = ? AND puc.is_active = 1
            ORDER BY from_uom.code
        ");
        $stmt->execute([$product_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting conversions: " . $e->getMessage());
        return [];
    }
}

function getConversionFactor($product_id, $from_uom_id, $to_uom_id) {
    global $pdo;
    
    error_log("getConversionFactor called: product=$product_id, from=$from_uom_id, to=$to_uom_id");
    
    if ($from_uom_id == $to_uom_id) return 1.0;

    // 1. หา conversion จากตาราง PRODUCT_UOM_CONVERSIONS
    $q = $pdo->prepare("
        SELECT conversion_factor
        FROM PRODUCT_UOM_CONVERSIONS
        WHERE product_id = ? AND from_uom_id = ? AND to_uom_id = ? AND is_active = 1
    ");
    $q->execute([$product_id, $from_uom_id, $to_uom_id]);
    if ($row = $q->fetch()) {
        error_log("Found direct conversion: " . $row['conversion_factor']);
        return (float)$row['conversion_factor'];
    }

    // 2. ลองย้อนกลับ
    $q->execute([$product_id, $to_uom_id, $from_uom_id]);
    if ($row = $q->fetch()) {
        $factor = 1.0 / (float)$row['conversion_factor'];
        error_log("Found reverse conversion: 1/{$row['conversion_factor']} = $factor");
        return $factor;
    }

    // 3. ✅ สำหรับ Paperboard: คำนวณจาก GSM
    // ดึง code ของทั้งสองหน่วย
    $stmt = $pdo->prepare("
        SELECT uom_id, UPPER(code) AS code, UPPER(name) AS name
        FROM UNITS_OF_MEASURE
        WHERE uom_id IN (?, ?)
        UNION
        SELECT unit_id AS uom_id, UPPER(unit_code) AS code, UPPER(unit_name) AS name
        FROM Units
        WHERE unit_id IN (?, ?)
    ");
    $stmt->execute([$from_uom_id, $to_uom_id, $from_uom_id, $to_uom_id]);
    
    $codes = [];
    foreach ($stmt->fetchAll() as $r) {
        $code = trim($r['code'] ?? '');
        $name = trim($r['name'] ?? '');
        $codes[$r['uom_id']] = $code ?: $name;
    }
    
    $from_code = $codes[$from_uom_id] ?? '';
    $to_code = $codes[$to_uom_id] ?? '';
    
    error_log("Unit codes: from={$from_code}, to={$to_code}");

    // ตรวจสอบว่าเป็น KG <-> SHEET หรือไม่
    $from_is_kg = in_array($from_code, ['KG', 'กก.', 'กก', 'กิโลกรัม', 'KILOGRAM']);
    $to_is_sheet = in_array($to_code, ['SHEET', 'แผ่น', 'SHT', 'SH', 'ใบ']);
    
    $from_is_sheet = in_array($from_code, ['SHEET', 'แผ่น', 'SHT', 'SH', 'ใบ']);
    $to_is_kg = in_array($to_code, ['KG', 'กก.', 'กก', 'กิโลกรัม', 'KILOGRAM']);

    if (($from_is_kg && $to_is_sheet) || ($from_is_sheet && $to_is_kg)) {
        // ดึงข้อมูล paperboard
        $p = $pdo->prepare("SELECT W_mm, L_mm, gsm FROM Specific_Paperboard WHERE product_id = ?");
        $p->execute([$product_id]);
        $pb = $p->fetch();
        
        if ($pb && $pb['W_mm'] && $pb['L_mm'] && $pb['gsm']) {
            // คำนวณน้ำหนักต่อแผ่น
            $area_m2 = ($pb['W_mm'] / 1000.0) * ($pb['L_mm'] / 1000.0);
            $kg_per_sheet = $area_m2 * ($pb['gsm'] / 1000.0);
            
            if ($kg_per_sheet > 0) {
                $sheets_per_kg = 1.0 / $kg_per_sheet;
                
                error_log("Paperboard calculation: W={$pb['W_mm']}, L={$pb['L_mm']}, GSM={$pb['gsm']}");
                error_log("Area: {$area_m2} m², kg/sheet: {$kg_per_sheet}, sheets/kg: {$sheets_per_kg}");
                
                if ($from_is_kg && $to_is_sheet) {
                    error_log("Returning KG→SHEET: {$sheets_per_kg}");
                    return $sheets_per_kg;
                }
                if ($from_is_sheet && $to_is_kg) {
                    error_log("Returning SHEET→KG: {$kg_per_sheet}");
                    return $kg_per_sheet;
                }
            } else {
                error_log("Invalid kg_per_sheet: {$kg_per_sheet}");
            }
        } else {
            error_log("No paperboard data found or incomplete data");
        }
    }

    // 4. ลองใช้ base unit factors
    $q = $pdo->prepare("
        SELECT f.to_base_factor AS f_to_base, t.from_base_factor AS t_from_base
        FROM UNITS_OF_MEASURE f, UNITS_OF_MEASURE t
        WHERE f.uom_id = ? AND t.uom_id = ?
    ");
    $q->execute([$from_uom_id, $to_uom_id]);
    if ($r = $q->fetch()) {
        $factor = ((float)$r['f_to_base']) * ((float)$r['t_from_base']);
        error_log("Base unit conversion: {$factor}");
        return $factor;
    }
    
    error_log("No conversion found, returning 1.0");
    return 1.0;
}

function getUomCanonicalCode($uom_id) {
    global $pdo;
    if (!$uom_id) return '';
    $stmt = $pdo->prepare("
        SELECT TOP 1 
            UPPER(COALESCE(NULLIF(code,''), NULLIF(unit_symbol,''), unit_name, name)) AS code,
            UPPER(COALESCE(unit_name, name, '')) AS uname
        FROM (
            SELECT code, name, unit_symbol, NULL AS unit_name FROM UNITS_OF_MEASURE WHERE uom_id = ?
            UNION ALL
            SELECT unit_code AS code, unit_name, unit_symbol, unit_name AS unit_name FROM Units WHERE unit_id = ?
        ) t
    ");
    $stmt->execute([$uom_id, $uom_id]);
    $r = $stmt->fetch();
    if (!$r) return '';

    $code  = trim($r['code'] ?? '');
    $uname = trim($r['uname'] ?? '');

    $aliases_sheet = ['SHEET','SHT','SH','แผ่น','ใบ'];
    $aliases_kg    = ['KG','กก.','กก','กิโลกรัม','KGS'];

    if (in_array($code, $aliases_sheet, true) || in_array($uname, $aliases_sheet, true)) return 'SHEET';
    if (in_array($code, $aliases_kg, true)    || in_array($uname, $aliases_kg, true))    return 'KG';

    return $code ?: $uname;
}

function getPaperboardSheetWeight($product_id) {
    global $pdo;
    $p = $pdo->prepare("SELECT W_mm, L_mm, gsm FROM Specific_Paperboard WHERE product_id = ?");
    $p->execute([$product_id]);
    $pb = $p->fetch();
    if (!$pb || !$pb['W_mm'] || !$pb['L_mm'] || !$pb['gsm']) {
        return ['kg_per_sheet'=>null, 'sheets_per_kg'=>null];
    }
    $area_m2 = ($pb['W_mm']/1000.0) * ($pb['L_mm']/1000.0);
    $kg_per_sheet = $area_m2 * ($pb['gsm']/1000.0);
    if ($kg_per_sheet <= 0) return ['kg_per_sheet'=>null, 'sheets_per_kg'=>null];
    return ['kg_per_sheet'=>$kg_per_sheet, 'sheets_per_kg'=>1.0/$kg_per_sheet];
}

function saveReceiptEnhanced($data) {
    global $pdo;
    
    error_log("Starting saveReceiptEnhanced");
    
    try {
        if (!isset($data['po_data'])) {
            throw new Exception('Missing po_data');
        }
        if (!isset($data['items_data'])) {
            throw new Exception('Missing items_data');
        }

        $pdo->beginTransaction();
        error_log("Transaction started");
        
        $po_data = $data['po_data'];
        $items = $data['items_data'];
        
        error_log("PO Data: " . print_r($po_data, true));
        error_log("Items: " . print_r($items, true));
        
        // 1. ดึง po_id จาก PO_Header ถ้ามี po_number
        $po_id = null;
        if (!empty($po_data['po_id'])) {
            $po_id = $po_data['po_id'];
        } elseif (!empty($po_data['po_number'])) {
            $stmt = $pdo->prepare("SELECT po_id FROM PO_Header WHERE po_number = ?");
            $stmt->execute([$po_data['po_number']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $po_id = $row['po_id'];
            }
        }
        
        error_log("Resolved PO_ID: " . $po_id);
        
        // 2. Generate GR Number
        $gr_number = generateGRNumber($pdo);
        error_log("Generated GR Number: " . $gr_number);
        // ✅ เพิ่มส่วนนี้ - ดึง status_id สำหรับ RECEIVED
$stmt = $pdo->prepare("
    SELECT status_id 
    FROM Receiving_Status_Types 
    WHERE status_code = 'RECEIVED' AND is_active = 1
");
$stmt->execute();
$status_row = $stmt->fetch(PDO::FETCH_ASSOC);
$received_status_id = $status_row['status_id'] ?? null;

error_log("Received Status ID: " . $received_status_id);

// ถ้าไม่มี status ให้สร้างใหม่
if (!$received_status_id) {
    $stmt = $pdo->prepare("
        INSERT INTO Receiving_Status_Types (status_code, status_name, status_description, is_active)
        VALUES ('RECEIVED', 'รับเข้าแล้ว', 'รับสินค้าเข้าคลังเรียบร้อย', 1)
    ");
    $stmt->execute();
    $stmt->closeCursor();
    
    $stmt = $pdo->prepare("SELECT status_id FROM Receiving_Status_Types WHERE status_code = 'RECEIVED'");
    $stmt->execute();
    $status_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $received_status_id = $status_row['status_id'] ?? 1; // fallback เป็น 1
}

        // 3. ดึง received_by user_id จาก username
        $received_by_id = null;
        if (!empty($po_data['received_by'])) {
            $stmt = $pdo->prepare("SELECT user_id FROM Users WHERE username = ?");
            $stmt->execute([$po_data['received_by']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $received_by_id = $row['user_id'];
            }
        }
        
        // ถ้าไม่มีให้ใช้ค่า default
        if (!$received_by_id) {
            $received_by_id = 1; // หรือ user_id ของระบบ
        }
        
        // 4. Insert Goods_Receipt Header
$sql = "INSERT INTO Goods_Receipt (
            gr_number, po_id, receipt_date, warehouse_id,
            total_amount, status, received_by, notes,
            invoice_number, receipt_type, created_date
        ) VALUES (?, ?, ?, ?, ?, 'PENDING', ?, ?, ?, 'PO', GETDATE())";

error_log("SQL: " . $sql);

$stmt = $pdo->prepare($sql);

$params = [
    $gr_number,
    $po_id,
    $po_data['receipt_date'] ?? date('Y-m-d'),
    $po_data['warehouse_id'] ?? null,
    $po_data['total_amount'] ?? 0,
    $received_by_id,
    $po_data['notes'] ?? null,
    $po_data['invoice_no'] ?? null
];

error_log("Insert params: " . print_r($params, true));

if (!$stmt->execute($params)) {
    $errorInfo = $stmt->errorInfo();
    throw new Exception("Failed to insert GR header: " . print_r($errorInfo, true));
}

// ✅ ปิด statement ก่อน
$stmt->closeCursor();

// ✅ ดึง GR_ID โดยค้นหาจาก gr_number ที่เพิ่ง insert
$stmt = $pdo->prepare("SELECT gr_id FROM Goods_Receipt WHERE gr_number = ?");
$stmt->execute([$gr_number]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$gr_id = $row['gr_id'] ?? null;

error_log("GR_ID: " . $gr_id);

if (!$gr_id) {
    throw new Exception("Failed to get GR_ID for gr_number: " . $gr_number);
}
        
        // 5. Process each item
foreach ($items as $index => $item) {
    error_log("Processing item " . $index . ": " . print_r($item, true));
    
    // ✅ หา primary_location_id จาก locations array
    $primary_location_id = null;
    $max_qty = 0;
    
    if (isset($item['locations']) && is_array($item['locations'])) {
        foreach ($item['locations'] as $loc) {
            if (!empty($loc['location_id']) && $loc['quantity'] > $max_qty) {
                $primary_location_id = $loc['location_id'];
                $max_qty = $loc['quantity'];
            }
        }
    }
    
    // ✅ ถ้าไม่มี location ให้หา location แรกของคลัง
    if (!$primary_location_id && !empty($po_data['warehouse_id'])) {
        $stmt = $pdo->prepare("
            SELECT TOP 1 location_id 
            FROM Warehouse_Locations 
            WHERE warehouse_id = ? AND is_active = 1 
            ORDER BY zone, location_code
        ");
        $stmt->execute([$po_data['warehouse_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $primary_location_id = $row['location_id'];
        }
        $stmt->closeCursor();
    }
    
    error_log("Using location_id: " . $primary_location_id);
    
    // ✅ แก้ SQL - เพิ่ม supplier_lot_number, supplier_expiry_date, current_status_id
    $sql = "INSERT INTO Goods_Receipt_Items (
                gr_id, po_item_id, product_id, 
                quantity_ordered, quantity_received, quantity_accepted,
                received_unit_id, stock_unit_id, conversion_factor,
                unit_cost, total_cost, warehouse_id, location_id,
                batch_lot, supplier_lot_number, manufacturing_date, 
                expiry_date, supplier_expiry_date,
                received_condition, quality_status, current_status_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'good', 'PENDING', ?)";
    
    $stmt = $pdo->prepare($sql);
    
    // คำนวณ conversion factor ถ้าจำเป็น
    $conversion_factor = 1;
    $stock_unit_id = $item['unit_id'] ?? null;
    
    // ✅ แก้ไข itemParams - เพิ่มค่าที่ขาด
    $itemParams = [
        $gr_id,
        $item['po_item_id'] ?? null,
        $item['product_id'] ?? null,
        $item['ordered_qty'] ?? 0,
        $item['receive_qty'] ?? 0,
        $item['receive_qty'] ?? 0, // quantity_accepted เท่ากับ received
        $item['unit_id'] ?? null,  // received_unit_id
        $stock_unit_id,            // stock_unit_id
        $conversion_factor,
        $item['unit_price'] ?? 0,
        $item['total_price'] ?? 0,
        $po_data['warehouse_id'] ?? null,
        $primary_location_id,      // ✅ location_id
        $item['lot_number'] ?? null,           // batch_lot
        $item['lot_number'] ?? null,           // ✅ supplier_lot_number
        $item['mfg_date'] ?? null,             // manufacturing_date
        $item['exp_date'] ?? null,             // expiry_date
        $item['exp_date'] ?? null,             // ✅ supplier_expiry_date
        $received_status_id                    // ✅ current_status_id
    ];
    
    error_log("Item params: " . print_r($itemParams, true));
    
    if (!$stmt->execute($itemParams)) {
        $errorInfo = $stmt->errorInfo();
        throw new Exception("Failed to insert GR item " . $index . ": " . print_r($errorInfo, true));
    }
    
    // ปิด statement ก่อน
    $stmt->closeCursor();
    
    // หา gr_item_id ที่เพิ่ง insert
    $stmt = $pdo->prepare("
        SELECT TOP 1 gr_item_id 
        FROM Goods_Receipt_Items 
        WHERE gr_id = ? 
        ORDER BY gr_item_id DESC
    ");
    $stmt->execute([$gr_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $gr_item_id = $row['gr_item_id'] ?? null;
    
    error_log("GR_Item_ID: " . $gr_item_id);
    
    if (!$gr_item_id) {
        throw new Exception("Failed to get GR_Item_ID from insert result");
    }
    
    // ... ส่วนที่เหลือ (Update Inventory_Stock และ Stock_Movements) เหมือนเดิม
            
            // 6. Update Inventory_Stock for each location
            if (isset($item['locations']) && is_array($item['locations'])) {
                foreach ($item['locations'] as $locIndex => $loc) {
                    if (empty($loc['location_id']) || $loc['quantity'] <= 0) {
                        continue; // ข้าม location ที่ไม่มีข้อมูลหรือจำนวน 0
                    }
                    
                    error_log("Processing location " . $locIndex . ": " . print_r($loc, true));
                    
                    updateInventoryStock(
                        $pdo,
                        $item['product_id'],
                        $po_data['warehouse_id'],
                        $loc['location_id'],
                        $item['unit_id'],
                        $loc['quantity'],
                        $loc['lot_number'] ?? $item['lot_number'] ?? null,
                        $loc['mfg_date'] ?? $item['mfg_date'] ?? null,
                        $loc['exp_date'] ?? $item['exp_date'] ?? null,
                        $loc['pallet_count'] ?? 0
                    );
                    
                    // 7. Insert Stock_Movements
                    $sql = "INSERT INTO Stock_Movements (
                                product_id, warehouse_id, location_id, movement_type,
                                quantity, unit_id, reference_type, reference_id,
                                batch_lot, movement_date, created_by, notes,
                                quantity_pallet
                            ) VALUES (?, ?, ?, 'IN', ?, ?, 'GR', ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $pdo->prepare($sql);
                    
                    $movementParams = [
                        $item['product_id'],
                        $po_data['warehouse_id'],
                        $loc['location_id'],
                        $loc['quantity'],
                        $item['unit_id'],
                        $gr_item_id,
                        $loc['lot_number'] ?? $item['lot_number'] ?? null,
                        $po_data['receipt_date'] ?? date('Y-m-d'),
                        $received_by_id,
                        $loc['note'] ?? null,
                        $loc['pallet_count'] ?? 0
                    ];
                    
                    if (!$stmt->execute($movementParams)) {
                        $errorInfo = $stmt->errorInfo();
                        throw new Exception("Failed to insert stock movement: " . print_r($errorInfo, true));
                    }
                }
            }
            
            // 8. Update PO_Items received quantity
            if (isset($item['po_item_id']) && $item['po_item_id']) {
                $sql = "UPDATE PO_Items 
                        SET received_quantity = ISNULL(received_quantity, 0) + ?,
                            pending_quantity = CASE 
                                WHEN (ISNULL(received_quantity, 0) + ?) >= quantity 
                                THEN 0 
                                ELSE quantity - (ISNULL(received_quantity, 0) + ?)
                            END,
                            status = CASE 
                                WHEN (ISNULL(received_quantity, 0) + ?) >= quantity 
                                THEN 'RECEIVED' 
                                WHEN (ISNULL(received_quantity, 0) + ?) > 0 
                                THEN 'PARTIAL' 
                                ELSE 'PENDING' 
                            END
                        WHERE po_item_id = ?";
                
                $stmt = $pdo->prepare($sql);
                $receiveQty = $item['receive_qty'];
                if (!$stmt->execute([$receiveQty, $receiveQty, $receiveQty, $receiveQty, $receiveQty, $item['po_item_id']])) {
                    $errorInfo = $stmt->errorInfo();
                    throw new Exception("Failed to update PO item: " . print_r($errorInfo, true));
                }
            }
        }
        
        // 9. Update PO Status
        if ($po_id) {
            updatePOStatus($pdo, $po_id);
        }
        
        $pdo->commit();
        error_log("Transaction committed successfully");
        
        return [
            'success' => true, 
            'message' => 'บันทึกใบรับสินค้าเรียบร้อยแล้ว',
            'gr_number' => $gr_number,
            'gr_id' => $gr_id
        ];
        
    } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollback();
            error_log("Transaction rolled back");
        }
        
        $errorMsg = $e->getMessage();
        error_log("Error in saveReceiptEnhanced: " . $errorMsg);
        error_log("Stack trace: " . $e->getTraceAsString());
        
        return [
            'success' => false, 
            'message' => 'เกิดข้อผิดพลาด: ' . $errorMsg,
            'error_detail' => $errorMsg
        ];
    }
}

// Helper function: Generate GR Number
function generateGRNumber($pdo) {
    $prefix = 'GR';
    $date = date('Ymd');
    
    $stmt = $pdo->prepare("
        SELECT TOP 1 GR_Number 
        FROM Goods_Receipt 
        WHERE GR_Number LIKE ? 
        ORDER BY GR_Number DESC
    ");
    $stmt->execute([$prefix . $date . '%']);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($last) {
        $lastNum = intval(substr($last['GR_Number'], -4));
        $newNum = $lastNum + 1;
    } else {
        $newNum = 1;
    }
    
    return $prefix . $date . str_pad($newNum, 4, '0', STR_PAD_LEFT);
}

// Helper function: Update Inventory Stock
function updateInventoryStock($pdo, $product_id, $warehouse_id, $location_id, $unit_id, $quantity, $lot_number, $mfg_date, $exp_date, $pallet_count = 0) {
    // Check existing stock - ใช้ชื่อคอลัมน์ตาม schema
    $stmt = $pdo->prepare("
        SELECT inventory_id, current_stock, current_pallet
        FROM Inventory_Stock 
        WHERE product_id = ? 
        AND warehouse_id = ? 
        AND location_id = ?
    ");
    $stmt->execute([$product_id, $warehouse_id, $location_id]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stock) {
        // Update existing stock
        $stmt = $pdo->prepare("
            UPDATE Inventory_Stock 
            SET current_stock = ISNULL(current_stock, 0) + ?,
                available_stock = ISNULL(available_stock, 0) + ?,
                current_pallet = ISNULL(current_pallet, 0) + ?,
                available_pallet = ISNULL(available_pallet, 0) + ?,
                last_updated = GETDATE(),
                last_movement_date = GETDATE()
            WHERE inventory_id = ?
        ");
        $stmt->execute([$quantity, $quantity, $pallet_count, $pallet_count, $stock['inventory_id']]);
    } else {
        // Insert new stock
        $stmt = $pdo->prepare("
            INSERT INTO Inventory_Stock (
                product_id, warehouse_id, location_id,
                current_stock, available_stock,
                current_pallet, available_pallet,
                last_updated, last_movement_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
        ");
        $stmt->execute([
            $product_id, $warehouse_id, $location_id,
            $quantity, $quantity,
            $pallet_count, $pallet_count
        ]);
    }
}

// Helper function: Update PO Status - ใช้ po_id แทน po_number
function updatePOStatus($pdo, $po_id) {
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN ISNULL(received_quantity, 0) >= quantity THEN 1 ELSE 0 END) as completed_items,
            COUNT(*) as total_items,
            SUM(CASE WHEN ISNULL(received_quantity, 0) > 0 THEN 1 ELSE 0 END) as partial_items
        FROM PO_Items
        WHERE po_id = ?
    ");
    $stmt->execute([$po_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $status = 'PENDING';
    if ($result['completed_items'] == $result['total_items']) {
        $status = 'RECEIVED';
    } elseif ($result['partial_items'] > 0) {
        $status = 'PARTIAL';
    }
    
    $stmt = $pdo->prepare("
        UPDATE PO_Header 
        SET status = ? 
        WHERE po_id = ?
    ");
    $stmt->execute([$status, $po_id]);
}

// Preload dropdown data for HTML render
try {
    $Suppliers  = $pdo->query("SELECT supplier_id, supplier_name FROM Suppliers WHERE is_active = 1 ORDER BY supplier_name")->fetchAll();
    $Warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM Warehouses WHERE is_active = 1 OR is_active IS NULL ORDER BY warehouse_name")->fetchAll();
    $UnitsAll   = $pdo->query("SELECT unit_id, unit_code, unit_name, unit_symbol FROM Units WHERE is_active = 1 ORDER BY unit_name")->fetchAll();
} catch (Exception $e) {
    error_log("Error loading dropdown data: " . $e->getMessage());
    $Suppliers = $Warehouses = $UnitsAll = [];
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$receiptNumber = 'DR-' . date('Ymd-His');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>รับเข้าสินค้า - ระบบคลังสินค้า</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
:root{
  --primary-color:#8B4513;--secondary-color:#FF8C00;--accent-color:#A0522D;--success-color:#059669;--primary-gradient:linear-gradient(135deg,#8B4513,#A0522D)
}
body{background:linear-gradient(135deg,#F5DEB3 0%,#DEB887 50%,#D2B48C 100%);min-height:100vh;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;color:var(--primary-color)}
.header-section {
    background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
    color: white;
    padding: 1.5rem 0;
    margin-bottom: 2rem;
    box-shadow: 0 4px 15px rgba(139, 69, 19, 0.4);
    border-bottom: 3px solid #FF8C00;
}

.header-section .container-fluid {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-section h5 {
    font-size: 1.3rem;
    font-weight: 600;
    margin: 0;
    color: white;
}

.header-section small {
    font-size: 0.85rem;
    opacity: 0.9;
}

.btn-back-arrow {
    color: white !important;
    text-decoration: none;
    font-size: 1.5rem;
    padding: 0.5rem;
    margin-right: 1rem;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    filter: drop-shadow(2px 2px 4px rgba(0,0,0,0.5));
}

.btn-back-arrow:hover {
    transform: translateX(-3px);
    color: #FFE5CC !important;
}

.btn-back-arrow i {
    color: white !important;
}

.text-light {
    color: rgba(255, 255, 255, 0.9) !important;
}

.d-flex {
    display: flex;
}

.align-items-center {
    align-items: center;
}

.justify-content-between {
    justify-content: space-between;
}

.w-100 {
    width: 100%;
}

.mb-0 {
    margin-bottom: 0;
}

.me-2 {
    margin-right: 0.5rem;
}

.gap-2 {
    gap: 0.5rem;
}
.card {
    border: 2px solid rgba(139, 69, 19, 0.2);
    box-shadow: 0 4px 12px rgba(139, 69, 19, 0.15);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.95);
}

.card-header {
    border-top-left-radius: 10px;
    border-top-right-radius: 10px;
    background: var(--primary-gradient) !important;
    color: #fff !important;
    border: none;
    padding: 1rem 1.5rem;
    font-weight: 600;
    font-size: 1.1rem;
}

.card-body {
    padding: 1.5rem;
}
.form-label.upper{text-transform:uppercase;font-size:.82rem;letter-spacing:.02em;color:var(--primary-color);font-weight:bold}
.form-control,.form-select{border-radius:10px;border:2px solid rgba(139,69,19,.2);padding:12px 15px;transition:all .3s ease}
.form-control:focus,.form-select:focus{border-color:var(--primary-color);box-shadow:0 0 0 .2rem rgba(139,69,19,.15)}
.item-card{background:rgba(248,249,250,.9);border:2px solid rgba(139,69,19,.1);border-radius:15px;padding:20px;position:relative;margin-bottom:20px;transition:all .3s ease}
.item-card:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(139,69,19,.15)}
.btn-primary{background:var(--primary-gradient);border:none;border-radius:10px;padding:12px 20px;font-weight:bold;transition:all .3s ease}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(139,69,19,.3)}
.sticky-actions {
    position: sticky;
    bottom: 0;
    background: rgba(255, 255, 255, 0.98);
    padding: 1rem 1.5rem;
    box-shadow: 0 -4px 15px rgba(139, 69, 19, 0.1);
    border-radius: 0;
    border-top: 2px solid rgba(139, 69, 19, 0.2);
}
.loading{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);display:none;justify-content:center;align-items:center;z-index:9999;color:#fff}
.loading.show{display:flex}
.is-invalid{border-color:#dc2626!important;animation:shake .5s ease-in-out}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-5px)}75%{transform:translateX(5px)}}
.subnote{font-size:.82rem;color:#6b7280;margin-top:5px}
.table-responsive{border-radius:10px;overflow:hidden}
.table{margin-bottom:0}
.table thead{background:var(--primary-gradient);color:#fff}
.table tbody tr:hover{background-color:rgba(139,69,19,.05)}
.po-item-card{border:2px solid rgba(139,69,19,.1);border-radius:16px;padding:16px;margin-bottom:18px;background:#fff}
.po-item-head{display:flex;gap:16px;align-items:flex-start;justify-content:space-between}
.po-item-title{font-weight:700}
.po-item-meta small{color:#6b7280}
.lot-strip{background:#e6f4ff;border-radius:10px;padding:10px 12px;margin:10px 0 6px 0;color:#0b5ed7}
.lot-table .form-control,.lot-table .form-select{padding:.35rem .5rem}
.badge-note{font-size:.82rem;color:#6b7280}
.lot-warning-badge {animation: fadeIn 0.3s ease-in-out;border-left: 4px solid currentColor;}
@keyframes fadeIn {from { opacity: 0; transform: translateY(-10px); }to { opacity: 1; transform: translateY(0); }}
#lot_sum_0, #lot_sum_1, #lot_sum_2, #lot_sum_3, #lot_sum_4,
[id^="lot_sum_"] {transition: color 0.3s ease;}
#remaining_0, #remaining_1, #remaining_2, #remaining_3, #remaining_4,
#remaining_5, #remaining_6, #remaining_7, #remaining_8, #remaining_9 {
    font-size: 1.1rem;
    padding: 2px 8px;
    border-radius: 4px;
    display: inline-block;
    min-width: 60px;
    text-align: center;
}

.text-warning {
    background: #fef3c7;
}

.text-success {
    background: #d1fae5;
}

.text-danger {
    background: #fee2e2;
}
.btn-header {
    background: linear-gradient(135deg, #FF8C00, #FFA500);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s;
    box-shadow: 0 2px 8px rgba(255, 140, 0, 0.3);
    display: inline-block;
    white-space: nowrap;
}

.btn-header:hover {
    background: linear-gradient(135deg, #FFA500, #FF8C00);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255, 140, 0, 0.4);
}
</style>
</head>
<body>
<div class="loading" id="loadingOverlay">
  <div class="text-center">
    <i class="fas fa-spinner fa-spin fa-3x mb-3"></i>
    <div>กำลังโหลด...</div>
  </div>
</div>

<!-- Header -->
<div class="header-section">
    <div class="container-fluid" style="max-width: 98%;">
        <div class="d-flex justify-content-between align-items-center w-100">
            <div class="d-flex align-items-center">
                <a href="../dashboard.php" class="btn-back-arrow">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h5 class="mb-0">
                        <i class="fas fa-clipboard-check me-2"></i>รับเข้าสินค้าจาก PO (Goods Receipt - PO)
                    </h5>
                    <small class="text-light">ระบบจัดการรับเข้าสินค้าจาก Purchase Order</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="receiving_direct.php" class="btn-header">รับเข้าตรง (ไม่มี PO)</a>
                <a href="../../inventory/goods_receipt_list.php" class="btn-header">รายการรับเข้า</a>
                <span class="text-white">
                    <i class="fas fa-user-circle me-2"></i>
                    <?= h($_SESSION['full_name'] ?? 'System Administrator'); ?>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid py-4" style="max-width: 98%; padding: 0 2rem;">
  <!-- ===================== PO MODE ===================== -->
  <div id="poModeSection" style="display:<?= ($mode==="po"?"block":"none") ?>;">
    <!-- ค้นหา PO -->
    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-search me-2"></i>ค้นหา PO</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label upper">หมายเลข PO <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="text" class="form-control" id="poNumberInput" placeholder="PO-2024-0001">
              <button class="btn btn-primary" onclick="loadPOData()"><i class="fas fa-search me-1"></i>ค้นหา</button>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label upper">ข้อมูล PO</label>
            <div id="poInfoDisplay" class="alert alert-info mb-0">
              <i class="fas fa-info-circle me-2"></i>กรุณาค้นหา PO ที่ต้องการรับเข้า
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ข้อมูลการรับเข้า -->
    <div class="card mb-4" id="poReceiptInfo">
      <div class="card-header"><i class="fas fa-warehouse me-2"></i>ข้อมูลการรับเข้า</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label upper">คลังสินค้า <span class="text-danger">*</span></label>
            <select class="form-select" id="warehouseSelect" required>
              <option value="">-- เลือกคลังสินค้า --</option>
              <?php foreach ($Warehouses as $w): ?>
                <option value="<?= (int)$w['warehouse_id']; ?>"><?= h($w['warehouse_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label upper">วันที่รับเข้า <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="receiptDate" value="<?= h(date('Y-m-d')); ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label upper">เลขที่ใบแจ้งหนี้</label>
            <input type="text" class="form-control" id="invoiceNumber" placeholder="INV-XXXX">
          </div>
        </div>
      </div>
    </div>

    <!-- รายการสินค้า -->
    <div class="card">
      <div class="card-header"><i class="fas fa-boxes me-2"></i>รายการสินค้า</div>
      <div class="card-body">
        <div id="poItemsList">
          <div class="alert alert-warning text-center">
            <i class="fas fa-exclamation-triangle me-2"></i>กรุณาค้นหา PO เพื่อแสดงรายการสินค้า
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ปุ่มยืนยัน (PO) -->
  <div class="sticky-actions d-flex gap-3 justify-content-end mt-3">
    <button type="button" class="btn btn-outline-info" onclick="previewReceiptPO()">
      <i class="fas fa-eye me-1"></i>ตัวอย่าง
    </button>
    <button type="button" class="btn btn-success" onclick="submitReceiptEnhancedPO()">
      <i class="fas fa-check me-1"></i>ยืนยันการรับเข้า
    </button>
  </div>

  <script>
// ===== Global =====
const WAREHOUSES = <?= json_encode($Warehouses, JSON_UNESCAPED_UNICODE); ?>;
const UNITS_ALL  = <?= json_encode($UnitsAll, JSON_UNESCAPED_UNICODE); ?>;
let rowIndex = 0;
let cachedProducts = [];
let currentMode = '<?= $mode ?>';

// ===== Utils =====
function hEsc(str){return (str??'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function showLoading(){document.getElementById('loadingOverlay').classList.add('show');}
function hideLoading(){document.getElementById('loadingOverlay').classList.remove('show');}
function numberFormat(n){ const x=Number(n||0); return x.toLocaleString(undefined,{maximumFractionDigits:2}); }
function debounce(fn,ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; }

function callApi(action, payload={}){
  const body = new URLSearchParams({action, ...payload});
  return fetch(window.location.href, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
    body
  }).then(r=>r.json());
}

// ===== Location helpers (PO & Direct ใช้ร่วม) =====
function loadWarehouseLocationsForRows(warehouseId){
  if(!warehouseId) return;
  callApi('get_warehouse_locations', {warehouse_id: warehouseId})
    .then(data=>{
      window.warehouseLocations = data?.locations || [];
      // เติมเฉพาะ select ที่อยู่ในตารางล็อต
      document.querySelectorAll('.lot-location').forEach(sel=>fillLocationOptions(sel));
    })
    .catch(()=>{
      window.warehouseLocations=[];
      document.querySelectorAll('.lot-location').forEach(sel=>fillLocationOptions(sel));
    });
}
function fillLocationOptions(selectEl){
  if(!selectEl) return;
  const locs = window.warehouseLocations || [];
  selectEl.innerHTML = '<option value="">เลือกพื้นที่</option>';
  if (locs.length === 0){ selectEl.disabled = true; return; }
  const byZone = {};
  locs.forEach(l=>{
    const z = l.zone_name || 'ไม่ระบุโซน';
    (byZone[z] ||= []).push(l);
  });
  Object.keys(byZone).sort().forEach(zone=>{
    const og = document.createElement('optgroup'); og.label = zone;
    byZone[zone].forEach(l=>{
      const o = document.createElement('option');
      o.value = l.location_id;
      o.textContent = `${l.location_code} - ${l.location_name_th||''}`;
      og.appendChild(o);
    });
    selectEl.appendChild(og);
  });
  selectEl.disabled = false;
}

// ===== Conversion helpers (สำคัญ: KG ↔ SHEET) =====
function findUnitIdByCode(codeUpper){
  const u = UNITS_ALL.find(x => (x.unit_code||'').toUpperCase() === codeUpper);
  return u ? u.unit_id : null;
}
async function convertQtyAPI(productId, fromUnitId, toCodeUpper, qty){
  const toUnitId = findUnitIdByCode(toCodeUpper);
  if(!toUnitId) return null;
  const body = new URLSearchParams();
  body.append('action','calculate_conversion');
  body.append('product_id', productId);
  body.append('from_unit', fromUnitId);
  body.append('to_unit', toUnitId);
  body.append('quantity', qty);
  const r = await fetch(window.location.href, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body});
  const data = await r.json();
  return (data && data.success) ? Number(data.converted_quantity) : null;
}
async function convertQtyByIds(productId, fromUnitId, toUnitId, qty){
  console.log('convertQtyByIds:', {productId, fromUnitId, toUnitId, qty});
  
  if (fromUnitId === toUnitId) return qty;
  
  if (!productId || !fromUnitId || !toUnitId || !qty) {
    console.warn('Invalid conversion parameters');
    return null;
  }
  
  try {
    const body = new URLSearchParams();
    body.append('action','calculate_conversion');
    body.append('product_id', productId);
    body.append('from_unit', fromUnitId);
    body.append('to_unit', toUnitId);
    body.append('quantity', qty);
    
    const r = await fetch(window.location.href, {
      method:'POST', 
      headers:{'Content-Type':'application/x-www-form-urlencoded'}, 
      body
    });
    
    const data = await r.json();
    console.log('Conversion response:', data);
    
    if (data && data.success && data.converted_quantity !== undefined) {
      return Number(data.converted_quantity);
    } else {
      console.error('Conversion failed:', data);
      return null;
    }
  } catch(e) {
    console.error('Conversion API error:', e);
    return null;
  }
}

// ===== Mode Switch =====
function switchReceiptMode(mode){
  currentMode = mode;
  const poSection = document.getElementById('poModeSection');
  const directSection = document.getElementById('directModeSection');
  const btnPO = document.getElementById('btnPOMode');
  const btnDirect = document.getElementById('btnDirectMode');

  if(mode === 'po'){
    poSection.style.display = 'block';
    directSection.style.display = 'none';
    btnPO.classList.add('active'); btnDirect.classList.remove('active');
  }else{
    poSection.style.display = 'none';
    directSection.style.display = 'block';
    btnDirect.classList.add('active'); btnPO.classList.remove('active');
    if(!document.querySelector('.item-card')) addItemCard();
  }
}

function previewReceiptPO(){ alert('ตัวอย่างใบรับเข้า (PO) — ใส่ print/preview ภายหลังได้'); }

// ===== โหลดข้อมูล PO =====
async function loadPOData(){
  const poNumber = document.getElementById('poNumberInput').value;
  if(!poNumber){ alert('กรุณาใส่หมายเลข PO'); return; }
  showLoading();
  try{
    const data = await callApi('get_po_data_with_conversions', {po_number: poNumber});
    hideLoading();
    if (data && data.success){
      displayPOInfo(data.po_data);
      window.currentPOData = data.po_data;
      window.currentItems  = data.items;
      displayPOItemsAsCards(data.items);
    }else{
      alert('ไม่พบ PO หมายเลข: ' + poNumber);
    }
  }catch(err){
    hideLoading();
    alert('เกิดข้อผิดพลาด: ' + err.message);
  }
}
function displayPOInfo(po){
  const d = document.getElementById('poInfoDisplay');
  d.className = 'alert alert-success';
  d.innerHTML = `
    <div class="row">
      <div class="col-md-6"><strong>PO:</strong> ${hEsc(po.po_number)}<br><strong>ซัพพลายเออร์:</strong> ${hEsc(po.supplier_name)}</div>
      <div class="col-md-6"><strong>วันที่สั่งซื้อ:</strong> ${hEsc(po.po_date)}<br><strong>มูลค่ารวม:</strong> ${Number(po.total_amount||0).toLocaleString()} บาท</div>
    </div>`;
}

// ===== UI Helper (ตัวเลือกระดับเอกสาร) =====
function defaultRecvUnitCode(){
  const hasKG = UNITS_ALL.some(u => (u.unit_code||'').toUpperCase()==='KG');
  return hasKG ? 'KG' : (UNITS_ALL[0]?.unit_code||'').toUpperCase();
}
function uomOptionHtml(defaultCode){
  let html = '';
  for(const u of UNITS_ALL){
    const label = u.unit_symbol ? `${u.unit_name} (${u.unit_symbol})` : u.unit_name;
    const sel = ((u.unit_code||'').toUpperCase()===defaultCode) ? 'selected' : '';
    html += `<option value="${u.unit_id}" data-code="${(u.unit_code||'').toUpperCase()}" ${sel}>${hEsc(label)}</option>`;
  }
  return html;
}

// ===== ตารางล็อต =====
function renderLotRow(itemIdx, rowIdx){
  const condOpts = ['ปกติ','ชำรุด','รอQC'].map(v=>`<option>${v}</option>`).join('');
  return `
    <tr data-item="${itemIdx}" data-row="${rowIdx}">
      <td>
        <select class="form-select lot-location" name="lot_location_${itemIdx}_${rowIdx}" disabled>
          <option value="">เลือกพื้นที่</option>
        </select>
      </td>
      <td><input class="form-control" name="lot_no_${itemIdx}_${rowIdx}" placeholder="LOT-xxxx" value=""></td>
      <td><input class="form-control text-end lot-qty" name="lot_qty_${itemIdx}_${rowIdx}" value="0" step="0.0001" min="0"></td>
      <td><input class="form-control text-end lot-pallet" name="lot_pallet_${itemIdx}_${rowIdx}" value="0" min="0" step="1"></td>
      <td><input type="date" class="form-control" name="lot_mfg_${itemIdx}_${rowIdx}"></td>
      <td><input type="date" class="form-control" name="lot_exp_${itemIdx}_${rowIdx}"></td>
      <td><select class="form-select" name="lot_cond_${itemIdx}_${rowIdx}">${condOpts}</select></td>
      <td><input class="form-control" name="lot_note_${itemIdx}_${rowIdx}" placeholder="หมายเหตุ"></td>
      <td class="text-center">
        <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeLotRow(${itemIdx}, ${rowIdx})"><i class="fa fa-trash"></i></button>
      </td>
    </tr>
  `;
}
function addLotRow(itemIdx){
  const body = document.getElementById(`lot_body_${itemIdx}`);
  const next = body.querySelectorAll('tr').length;
  body.insertAdjacentHTML('beforeend', renderLotRow(itemIdx, next));
  hydrateLocationSelect(body.querySelector(`tr[data-row="${next}"] .lot-location`));
  wireLotRowEvents(itemIdx);
  recalcLotSum(itemIdx);
}
function removeLotRow(itemIdx,rowIdx){
  const row = document.querySelector(`tr[data-item="${itemIdx}"][data-row="${rowIdx}"]`);
  if(!row) return;
  const body = row.parentElement;
  row.remove();
  [...body.querySelectorAll('tr')].forEach((tr,i)=>tr.setAttribute('data-row', i));
  recalcLotSum(itemIdx);
}

// ===== แก้ไข recalcLotSum ให้ตรวจสอบและแจ้งเตือน =====
// ===== แก้ไข recalcLotSum ให้แสดงจำนวนเหลือ =====
function recalcLotSum(itemIdx){
  const body = document.getElementById(`lot_body_${itemIdx}`);
  if (!body) {
    console.warn(`Cannot find lot_body_${itemIdx}`);
    return;
  }
  
  let sum = 0, pallet = 0;
  
  // รวมจำนวนจากทุกแถว
  const qtyInputs = body.querySelectorAll('.lot-qty');
  const palletInputs = body.querySelectorAll('.lot-pallet');
  
  qtyInputs.forEach(i => {
    const val = parseFloat(i.value || '0');
    sum += val;
  });
  
  palletInputs.forEach(i => {
    const val = parseFloat(i.value || '0');
    pallet += val;
  });

  // อัปเดต footer
  const sumEl = document.getElementById(`lot_sum_${itemIdx}`);
  const palletSumEl = document.getElementById(`pallet_sum_${itemIdx}`);
  
  if (sumEl) {
    sumEl.textContent = Math.round(sum).toLocaleString('th-TH');
  }
  if (palletSumEl) {
    palletSumEl.textContent = Math.round(pallet).toLocaleString('th-TH');
  }

  // ตรวจสอบว่าผลรวมไม่เกินจำนวนที่รับ
  const qtyEl = document.querySelector(`[name="recv_qty_${itemIdx}"]`);
  const maxQty = parseFloat(qtyEl?.value || '0');
  
  // ✅ คำนวณจำนวนคงเหลือ
  const remaining = Math.round(maxQty - sum);
  
  // ✅ แสดงจำนวนคงเหลือ
  const remainingEl = document.getElementById(`remaining_${itemIdx}`);
  
  if (remainingEl) {
    if (remaining > 0) {
      remainingEl.textContent = Math.round(remaining).toLocaleString('th-TH');
      remainingEl.className = 'text-warning fw-bold';
    } else if (remaining === 0) {
      remainingEl.textContent = '0';
      remainingEl.className = 'text-success fw-bold';
    } else {
      remainingEl.textContent = Math.round(Math.abs(remaining)).toLocaleString('th-TH');
      remainingEl.className = 'text-danger fw-bold';
    }
  }
  
  // ตรวจสอบจำนวนเกิน
  if (sum > maxQty && maxQty > 0) {
    const diff = sum - maxQty;
    
    // แสดงเตือนใน footer
    if (sumEl) {
      sumEl.style.color = '#dc2626';
      sumEl.style.fontWeight = 'bold';
      sumEl.innerHTML = `${Math.round(sum).toLocaleString('th-TH')} <i class="fa fa-exclamation-triangle ms-1" title="เกินจำนวนที่รับ ${Math.round(diff)}"></i>`;
    }
    
    // แสดง badge แจ้งเตือน
    const card = document.querySelector(`.po-item-card[data-item="${itemIdx}"]`);
    let warningBadge = card?.querySelector('.lot-warning-badge');
    
    if (!warningBadge) {
      warningBadge = document.createElement('div');
      warningBadge.className = 'lot-warning-badge alert alert-danger py-2 px-3 mt-2 mb-0';
      warningBadge.innerHTML = `
        <i class="fa fa-exclamation-triangle me-2"></i>
        <strong>จำนวนรวมในพื้นที่เกิน!</strong> 
        รวม: <span class="fw-bold">${Math.round(sum)}</span> 
        | ต้องรับ: <span class="fw-bold">${Math.round(maxQty)}</span> 
        | เกิน: <span class="fw-bold text-danger">${Math.round(diff)}</span>
      `;
      const lotStrip = card?.querySelector('.lot-strip');
      if (lotStrip) {
        lotStrip.insertAdjacentElement('afterend', warningBadge);
      }
    } else {
      warningBadge.innerHTML = `
        <i class="fa fa-exclamation-triangle me-2"></i>
        <strong>จำนวนรวมในพื้นที่เกิน!</strong> 
        รวม: <span class="fw-bold">${Math.round(sum)}</span> 
        | ต้องรับ: <span class="fw-bold">${Math.round(maxQty)}</span> 
        | เกิน: <span class="fw-bold text-danger">${Math.round(diff)}</span>
      `;
      warningBadge.style.display = 'block';
    }
    
  } else if (sum < maxQty && maxQty > 0) {
    const remainingQty = maxQty - sum;
    
    // แสดงเตือนว่ายังขาด
    if (sumEl) {
      sumEl.style.color = '#f59e0b';
      sumEl.style.fontWeight = 'bold';
      sumEl.innerHTML = `${Math.round(sum).toLocaleString('th-TH')} <i class="fa fa-info-circle ms-1" title="ยังขาด ${Math.round(remainingQty)}"></i>`;
    }
    
    const card = document.querySelector(`.po-item-card[data-item="${itemIdx}"]`);
    let warningBadge = card?.querySelector('.lot-warning-badge');
    
    if (!warningBadge) {
      warningBadge = document.createElement('div');
      warningBadge.className = 'lot-warning-badge alert alert-warning py-2 px-3 mt-2 mb-0';
      warningBadge.innerHTML = `
        <i class="fa fa-info-circle me-2"></i>
        <strong>ยังกระจายไม่ครบ!</strong> 
        รวม: <span class="fw-bold">${Math.round(sum)}</span> 
        | ต้องรับ: <span class="fw-bold">${Math.round(maxQty)}</span> 
        | ยังเหลือ: <span class="fw-bold text-warning">${Math.round(remainingQty)}</span>
      `;
      const lotStrip = card?.querySelector('.lot-strip');
      if (lotStrip) {
        lotStrip.insertAdjacentElement('afterend', warningBadge);
      }
    } else {
      warningBadge.className = 'lot-warning-badge alert alert-warning py-2 px-3 mt-2 mb-0';
      warningBadge.innerHTML = `
        <i class="fa fa-info-circle me-2"></i>
        <strong>ยังกระจายไม่ครบ!</strong> 
        รวม: <span class="fw-bold">${Math.round(sum)}</span> 
        | ต้องรับ: <span class="fw-bold">${Math.round(maxQty)}</span> 
        | ยังเหลือ: <span class="fw-bold text-warning">${Math.round(remainingQty)}</span>
      `;
      warningBadge.style.display = 'block';
    }
    
  } else if (sum === maxQty && maxQty > 0) {
    // ครบพอดี
    if (sumEl) {
      sumEl.style.color = '#059669';
      sumEl.style.fontWeight = 'bold';
      sumEl.innerHTML = `${Math.round(sum).toLocaleString('th-TH')} <i class="fa fa-check-circle ms-1 text-success" title="ครบถ้วน"></i>`;
    }
    
    const card = document.querySelector(`.po-item-card[data-item="${itemIdx}"]`);
    const warningBadge = card?.querySelector('.lot-warning-badge');
    if (warningBadge) {
      warningBadge.style.display = 'none';
    }
  } else {
    // ยังไม่มีข้อมูล
    if (sumEl) {
      sumEl.style.color = '';
      sumEl.style.fontWeight = '';
    }
    
    const card = document.querySelector(`.po-item-card[data-item="${itemIdx}"]`);
    const warningBadge = card?.querySelector('.lot-warning-badge');
    if (warningBadge) {
      warningBadge.style.display = 'none';
    }
  }
  
  console.log(`Item ${itemIdx} - Lot sum: ${sum}, Max: ${maxQty}, Remaining: ${remaining}, Pallet: ${pallet}`);
}

function hydrateLocationSelect(selectEl){ fillLocationOptions(selectEl); }
function wireLotRowEvents(itemIdx){
  const body = document.getElementById(`lot_body_${itemIdx}`);
  body.querySelectorAll('.lot-qty,.lot-pallet').forEach(i=>{
    i.oninput = ()=> recalcLotSum(itemIdx);
  });
}

// ===== แสดงรายการสินค้าแบบการ์ด =====
// ===== แก้ไข displayPOItemsAsCards =====
function displayPOItemsAsCards(items){
  const container = document.getElementById('poItemsList');
  if(!Array.isArray(items) || items.length===0){
    container.innerHTML = '<div class="alert alert-warning">ไม่พบรายการสินค้าใน PO นี้</div>';
    return;
  }

  let html = '';
  items.forEach((it, idx)=>{
    // ✅ ดึงข้อมูลให้ครบถ้วนและชัดเจน
    const orderQty = parseFloat(it.quantity || 0);
    const receivedQty = parseFloat(it.received_quantity || 0);
    const pendingQty = parseFloat(it.pending_quantity || 0);
    
    // คำนวณจำนวนที่ควรรับ
    const needQty = pendingQty > 0 ? pendingQty : Math.max(0, orderQty - receivedQty);
    
    // ✅ Debug log
    console.log(`Item ${idx} - ${it.product_name}:`, {
      orderQty,
      receivedQty,
      pendingQty,
      needQty,
      purchase_unit_id: it.purchase_unit_id,
      purchase_unit_symbol: it.purchase_unit_symbol
    });
    
    // ถ้าไม่มีข้อมูลเลย ข้าม
    if (orderQty <= 0) {
      console.warn(`Item ${idx} has no order quantity`);
    }
    
    const purchaseUnitSymbol = it.purchase_unit_symbol || it.purchase_unit_name || it.stock_unit_symbol || it.stock_unit_name || 'หน่วย';
    const purchaseUnitId = parseInt(it.purchase_unit_id || it.stock_unit_id || 0);
    const defCode = defaultRecvUnitCode();
    
    // ✅ สร้างข้อความแสดงสถานะ
    let statusHtml = '';
    if (receivedQty > 0 && receivedQty < orderQty) {
      statusHtml = `<span class="badge bg-warning text-dark">รับบางส่วน</span>`;
    } else if (receivedQty >= orderQty) {
      statusHtml = `<span class="badge bg-success">รับครบแล้ว</span>`;
    } else {
      statusHtml = `<span class="badge bg-info">ยังไม่รับ</span>`;
    }
    
    html += `
      <div class="po-item-card" data-item="${idx}" data-purchase-unit-id="${purchaseUnitId}" data-order-qty="${needQty}" style="border:2px solid rgba(139,69,19,.1);border-radius:16px;padding:16px;margin-bottom:18px;background:#fff">
        <div class="po-item-head" style="display:flex;gap:16px;align-items:flex-start;justify-content:space-between">
          <div style="flex:1">
            <div class="d-flex align-items-center gap-2 mb-2">
              <div class="po-item-title" style="font-weight:700">${hEsc(it.product_name || it.item_description || '-')}</div>
              ${statusHtml}
            </div>
            <div class="po-item-meta">
              <small class="text-muted"><i class="fa fa-barcode me-1"></i>รหัส: ${hEsc(it.product_code||'-')}</small><br/>
              <small class="text-primary fw-bold">
                <i class="fa fa-shopping-cart me-1"></i>สั่งซื้อ: ${orderQty > 0 ? `<span class="fs-6">${numberFormat(orderQty)}</span> ${hEsc(purchaseUnitSymbol)}` : '<span class="text-danger">ไม่มีข้อมูล</span>'}
              </small><br/>
              ${receivedQty > 0 ? `<small class="text-success"><i class="fa fa-check-circle me-1"></i>รับแล้ว: ${numberFormat(receivedQty)} ${hEsc(purchaseUnitSymbol)}</small><br/>` : ''}
              ${needQty > 0 && needQty !== orderQty ? `<small class="text-warning"><i class="fa fa-clock me-1"></i>ค้างรับ: ${numberFormat(needQty)} ${hEsc(purchaseUnitSymbol)}</small><br/>` : ''}
              ${it.paperboard_info ? `<small class="text-muted"><i class="fa fa-ruler me-1"></i>ขนาด: ${it.W_mm}×${it.L_mm} mm | GSM: ${it.gsm} | น้ำหนัก: ${it.paperboard_info.Weight_kg_per_sheet} กก./แผ่น</small>` : ''}
            </div>
          </div>
          <div style="min-width:450px;background:#f8f9fa;padding:15px;border-radius:10px">
            <div class="row g-2 align-items-center mb-3">
              <div class="col-4 text-end"><small class="text-muted fw-bold">หน่วยรับ</small></div>
              <div class="col-8">
                <select class="form-select recv-unit" name="recv_unit_${idx}">
                  ${uomOptionHtml(defCode)}
                </select>
              </div>
            </div>
            <div class="row g-2 align-items-center">
              <div class="col-4 text-end"><small class="text-muted fw-bold">จำนวนเมื่อรับ</small></div>
              <div class="col-8">
                <input class="form-control text-end recv-qty fw-bold" name="recv_qty_${idx}" placeholder="0" step="1" min="0" style="font-size:1.2rem;color:#059669;background:white" />
                <small class="text-muted d-block mt-1"><i class="fa fa-info-circle me-1"></i>คนละหน่วยกับการสั่งซื้อ</small>
              </div>
            </div>
            <div class="mt-2 p-2" style="background:white;border-radius:8px;min-height:40px">
              <small class="badge-note d-block" id="conv_note_${idx}" style="font-size:.82rem;color:#059669;font-weight:500">
                <i class="fa fa-calculator me-1"></i>รอเลือกหน่วยรับ...
              </small>
            </div>
          </div>
        </div>

        <div class="lot-strip" style="background:#e6f4ff;border-radius:10px;padding:10px 12px;margin:12px 0 8px 0;color:#0b5ed7">
          <i class="fa fa-layer-group me-1"></i> <b>กระจายข้อมูลแต่ละพื้นที่</b> - แต่ละแถว = 1 พื้นที่ + 1 Lot
        </div>

        <div class="table-responsive">
          <table class="table table-bordered align-middle lot-table mb-2">
            <thead class="table-light">
              <tr>
                <th style="width:16%">ที่เก็บ/พื้นที่</th>
                <th style="width:14%">เลข Lot</th>
                <th style="width:10%" class="text-end">จำนวน</th>
                <th style="width:8%" class="text-end">Pallet</th>
                <th style="width:12%">วันผลิต</th>
                <th style="width:12%">วันหมดอายุ</th>
                <th style="width:10%">สภาพ</th>
                <th>หมายเหตุ</th>
                <th style="width:42px"></th>
              </tr>
            </thead>
            <tbody id="lot_body_${idx}">
              ${renderLotRow(idx, 0)}
            </tbody>
            <tfoot>
  <tr class="table-light">
    <td colspan="2" class="text-end"><b>รวม:</b></td>
    <td class="text-end"><b id="lot_sum_${idx}">0</b></td>
    <td class="text-end"><b id="pallet_sum_${idx}">0</b></td>
    <td colspan="5">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <small class="text-primary fw-bold">
            <i class="fa fa-clipboard-check me-1"></i>ต้องรับ: ${Math.round(needQty).toLocaleString()} ${hEsc(purchaseUnitSymbol)}
          </small>
        </div>
        <div>
          <small class="me-2">
            <i class="fa fa-arrow-right me-1 text-muted"></i>
            <span class="text-muted">คงเหลือ:</span>
            <span id="remaining_${idx}" class="fw-bold text-warning">
              ${Math.round(needQty).toLocaleString()}
            </span>
          </small>
        </div>
      </div>
    </td>
  </tr>
</tfoot>
          </table>
        </div>

        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-success btn-sm" onclick="addLotRow(${idx})">
            <i class="fa fa-plus me-1"></i>เพิ่มพื้นที่/Lot
          </button>
          <button type="button" class="btn btn-outline-primary btn-sm" onclick="autoFillLots(${idx})">
            <i class="fa fa-magic me-1"></i>เติมอัตโนมัติ
          </button>
        </div>
      </div>
    `;
  });

  container.innerHTML = html;

  const wid = document.getElementById('warehouseSelect')?.value || '';
  if (wid) loadWarehouseLocationsForRows(wid);

  items.forEach((it, idx)=> wireItemCardEvents(it, idx));

  document.getElementById('warehouseSelect')?.addEventListener('change', function(){
    if (this.value) loadWarehouseLocationsForRows(this.value);
  });
}

// ===== แก้ไข wireItemCardEvents ให้คำนวณอัตโนมัติเมื่อเลือกหน่วย =====
function wireItemCardEvents(item, idx){
  hydrateLocationSelect(document.querySelector(`#lot_body_${idx} .lot-location`));
  wireLotRowEvents(idx);
  recalcLotSum(idx);

  const card   = document.querySelector(`.po-item-card[data-item="${idx}"]`);
  const qtyEl  = document.querySelector(`[name="recv_qty_${idx}"]`);
  const unitEl = document.querySelector(`[name="recv_unit_${idx}"]`);
  const noteEl = document.getElementById(`conv_note_${idx}`);

  if (!card || !qtyEl || !unitEl || !noteEl) {
    console.error('Missing elements for item', idx);
    return;
  }

  // ดึงข้อมูลจาก data attributes
  const purchaseUnitId = parseInt(card.dataset.purchaseUnitId || '0');
  const orderQty = parseFloat(card.dataset.orderQty || '0');
  
  console.log(`Wire Item ${idx}:`, {
    product: item.product_name,
    orderQty,
    purchaseUnitId,
    paperboard: item.paperboard_info
  });

  // เก็บหน่วยเริ่มต้น
  card.dataset.lastUnitId = unitEl.value || '';

  // ฟังก์ชันคำนวณและแสดงการแปลงหน่วย
  async function updateConversion() {
    noteEl.innerHTML = '';
    const q = parseFloat(qtyEl.value || '0');
    const unitId = parseInt(unitEl.value || '0');
    
    if(!q || !unitId) {
      noteEl.innerHTML = '<i class="fa fa-info-circle me-1"></i>รอเลือกหน่วยรับ...';
      return;
    }
    
    const unitOpt = unitEl.options[unitEl.selectedIndex];
    const unitCode = (unitOpt?.dataset?.code || '').toUpperCase();
    const unitText = unitOpt?.text || '';
    const parts = [];
    
    try {
      // แปลงเป็น KG
      if (unitCode !== 'KG') {
        const toKG = await convertQtyAPI(item.product_id, unitId, 'KG', q);
        if(toKG !== null && toKG > 0) {
          parts.push(`<span class="text-info">≈ ${Number(toKG).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})} กก.</span>`);
        }
      }
      
      // แปลงเป็น SHEET
      if (unitCode !== 'SHEET') {
        const toSHEET = await convertQtyAPI(item.product_id, unitId, 'SHEET', q);
        if(toSHEET !== null && toSHEET > 0) {
          parts.push(`<span class="text-success">≈ ${Number(toSHEET).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})} แผ่น</span>`);
        }
      }
      
      // แสดงสูตรการคำนวณถ้าเป็น paperboard
      if (item.paperboard_info && unitCode === 'SHEET') {
        const kgPerSheet = parseFloat(item.paperboard_info.Weight_kg_per_sheet || 0);
        const sheetsPerKg = parseFloat(item.paperboard_info.sheets_per_kg || 0);
        if (kgPerSheet > 0 && sheetsPerKg > 0) {
          parts.push(`<br><small class="text-muted">📐 น้ำหนัก/แผ่น: ${kgPerSheet.toFixed(6)} kg | แผ่น/kg: ${sheetsPerKg.toFixed(3)}</small>`);
        }
      }
      
    } catch(e) {
      console.error('Conversion error:', e);
    }
    
    if (parts.length > 0) {
      noteEl.innerHTML = `<i class="fa fa-calculator me-1"></i><b>${q.toLocaleString('th-TH')} ${unitText}</b> = ${parts.join(' | ')}`;
    } else {
      noteEl.innerHTML = `<i class="fa fa-check-circle me-1 text-success"></i>${q.toLocaleString('th-TH')} ${unitText}`;
    }
  }

  // ฟังก์ชันซิงค์จำนวนไปล็อต
   function syncHeaderToLots(){
    const body = document.getElementById(`lot_body_${idx}`);
    if (!body) return;
    
    const sum = [...body.querySelectorAll('.lot-qty')].reduce((s,i)=> s+(parseFloat(i.value)||0), 0);
    const q = parseFloat(qtyEl.value || '0');
    
    if (q > 0 && sum === 0){
      const first = body.querySelector('.lot-qty');
      if (first){ 
        first.value = q.toFixed(4); 
        recalcLotSum(idx);
      }
    }
  }

  // เมื่อพิมพ์จำนวนเอง
  qtyEl.addEventListener('input', function() {
    const q = parseFloat(this.value || '0');
    if (q > 0) {
      updateConversion();
      syncHeaderToLots();
    } else {
      noteEl.innerHTML = '<i class="fa fa-info-circle me-1"></i>รอเลือกหน่วยรับ...';
    }
  });

  // ✅ เมื่อเปลี่ยนหน่วยรับ - คำนวณจำนวนอัตโนมัติทันที
  unitEl.addEventListener('change', async function() {
    const oldUnitId = parseInt(card.dataset.lastUnitId || '0');
    const newUnitId = parseInt(this.value || '0');
    
    console.log(`Item ${idx} - Unit changed from ${oldUnitId} to ${newUnitId}`);
    
    if (!newUnitId) { 
      card.dataset.lastUnitId = ''; 
      noteEl.innerHTML = '<i class="fa fa-info-circle me-1"></i>รอเลือกหน่วยรับ...';
      qtyEl.value = '';
      // ล้างล็อต
      document.querySelectorAll(`#lot_body_${idx} .lot-qty`).forEach(inp => inp.value = '0');
      recalcLotSum(idx);
      return; 
    }

    const rows = document.querySelectorAll(`#lot_body_${idx} tr`);
    const currentHeaderQty = parseFloat(qtyEl.value || '0');

    // ✅ กรณีที่ 1: เลือกหน่วยครั้งแรก หรือเปลี่ยนหน่วย - คำนวณจาก orderQty
    if (!oldUnitId || oldUnitId !== newUnitId) {
      if (purchaseUnitId && orderQty > 0) {
        console.log(`Converting ${orderQty} kg (unit ${purchaseUnitId}) to unit ${newUnitId}`);
        
        // แสดง loading
        noteEl.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>กำลังคำนวณ...';
        qtyEl.value = '...';
        
        try {
          const convertedQty = await convertQtyByIds(
            item.product_id, 
            purchaseUnitId, 
            newUnitId, 
            orderQty
          );
          
          console.log(`Conversion result: ${convertedQty}`);
          
          if (convertedQty !== null && convertedQty > 0) {
            // แสดงจำนวนที่แปลงได้
            qtyEl.value = Math.round(convertedQty);
            
            // เติมลงล็อตแรกทันที
            const firstLot = rows[0]?.querySelector('.lot-qty');
            if (firstLot) {
              firstLot.value = Math.round(convertedQty);
              recalcLotSum(idx);
            }
            
            // แสดงการแปลงหน่วย
            await updateConversion();
            
            // ✅ แสดงสูตรการคำนวณถ้าเป็น paperboard
            const unitOpt = this.options[this.selectedIndex];
            const unitCode = (unitOpt?.dataset?.code || '').toUpperCase();
            
            if (item.paperboard_info && unitCode === 'SHEET') {
              const sheetsPerKg = parseFloat(item.paperboard_info.sheets_per_kg || 0);
              const calculation = `${orderQty} kg × ${sheetsPerKg.toFixed(3)} แผ่น/kg = ${convertedQty.toFixed(2)} แผ่น`;
              noteEl.innerHTML += `<br><small class="text-primary"><i class="fa fa-info-circle me-1"></i>${calculation}</small>`;
            }
            
          } else {
            console.warn('Invalid conversion result:', convertedQty);
            qtyEl.value = '';
            noteEl.innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-triangle me-1"></i>ไม่สามารถแปลงหน่วยได้</span>';
          }
        } catch(e) {
          console.error('Auto conversion error:', e);
          qtyEl.value = '';
          noteEl.innerHTML = '<span class="text-danger"><i class="fa fa-times me-1"></i>เกิดข้อผิดพลาดในการแปลงหน่วย</span>';
        }
      } else {
        console.warn('Missing purchaseUnitId or orderQty:', {purchaseUnitId, orderQty});
        qtyEl.value = '';
        noteEl.innerHTML = '<span class="text-warning"><i class="fa fa-exclamation-triangle me-1"></i>ไม่มีข้อมูลการสั่งซื้อ</span>';
      }
    }
    // ✅ กรณีที่ 2: มีค่าอยู่แล้ว และเปลี่ยนหน่วย - แปลงค่าเดิม
    else if (oldUnitId && oldUnitId !== newUnitId && currentHeaderQty > 0) {
      console.log(`Converting existing ${currentHeaderQty} from unit ${oldUnitId} to ${newUnitId}`);
      
      noteEl.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>กำลังแปลงหน่วย...';
      
      try {
        // แปลงค่าใน header
        const convertedHeader = await convertQtyByIds(item.product_id, oldUnitId, newUnitId, currentHeaderQty);
if (convertedHeader !== null && convertedHeader > 0) {
    qtyEl.value = Math.round(convertedHeader); // ปัดเป็นจำนวนเต็ม
}

// แปลงค่าในล็อตทั้งหมด
for (const tr of rows) {
    const inp = tr.querySelector('.lot-qty');
    const v = parseFloat(inp?.value || '0');
    if (v > 0) {
        const converted = await convertQtyByIds(item.product_id, oldUnitId, newUnitId, v);
        if (converted !== null && converted > 0) {
            inp.value = Math.round(converted); // ปัดเป็นจำนวนเต็ม
        }
    }
}
        
        recalcLotSum(idx);
        await updateConversion();
        
      } catch(e) {
        console.error('Unit conversion error:', e);
        noteEl.innerHTML = '<span class="text-danger"><i class="fa fa-times me-1"></i>เกิดข้อผิดพลาดในการแปลงหน่วย</span>';
      }
    }

    // อัปเดตหน่วยล่าสุด
    card.dataset.lastUnitId = String(newUnitId);
  });

  // ✅ คำนวณครั้งแรกเมื่อโหลดการ์ด (auto-convert ทันที)
  // ✅ คำนวณครั้งแรกเมื่อโหลดการ์ด - เวอร์ชันบังคับอัปเดต
setTimeout(async () => {
  const initialUnitId = parseInt(unitEl.value || '0');
  
  if (initialUnitId && purchaseUnitId && orderQty > 0) {
    noteEl.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>กำลังคำนวณ...';
    
    try {
      const convertedQty = await convertQtyByIds(
        item.product_id, 
        purchaseUnitId, 
        initialUnitId, 
        orderQty
      );
      
      if (convertedQty !== null && convertedQty > 0) {
        // ✅ บังคับตั้งค่าหลายครั้งเพื่อให้แน่ใจ
        const finalValue = convertedQty.toFixed(4);
        
        qtyEl.value = finalValue;
        qtyEl.setAttribute('value', finalValue);
        
        // เติมล็อต
        const firstLot = document.querySelector(`#lot_body_${idx} .lot-qty`);
        if (firstLot) {
          firstLot.value = finalValue;
          firstLot.setAttribute('value', finalValue);
        }
        
        // รอแล้ว recalc
        await new Promise(resolve => setTimeout(resolve, 100));
        recalcLotSum(idx);
        
        // รอแล้วตรวจสอบอีกครั้ง
        await new Promise(resolve => setTimeout(resolve, 100));
        
        // ถ้ายังไม่ตรง ให้ตั้งอีกครั้ง
        if (qtyEl.value !== finalValue) {
          qtyEl.value = finalValue;
          console.log(`Force updated item ${idx} to ${finalValue}`);
        }
        
        await updateConversion();
        
        // แสดงสูตร
        const unitOpt = unitEl.options[unitEl.selectedIndex];
        const unitCode = (unitOpt?.dataset?.code || '').toUpperCase();
        
        if (item.paperboard_info && (unitCode === 'SHEET' || unitCode.includes('แผ่น'))) {
          const sheetsPerKg = parseFloat(item.paperboard_info.sheets_per_kg || 0);
          if (sheetsPerKg > 0) {
            const calculation = `${orderQty} kg × ${sheetsPerKg.toFixed(3)} = ${convertedQty.toFixed(2)} แผ่น`;
            noteEl.innerHTML += `<br><small class="text-primary fw-bold"><i class="fa fa-calculator me-1"></i>${calculation}</small>`;
          }
        }
        
      }
    } catch(e) {
      console.error('Conversion error:', e);
    }
  }
}, 500);
}
// ===== Payload รวบรวมข้อมูลจากการ์ด (PO Mode) =====
function collectItemPayload(idx, item){
  const card = document.querySelector(`.po-item-card[data-item="${idx}"]`);
  const qtyEl  = card.querySelector('.recv-qty');
  const unitEl = card.querySelector('.recv-unit');

  const lots = [];
  card.querySelectorAll('#lot_body_'+idx+' tr').forEach(tr=>{
    lots.push({
      location_id: tr.querySelector('.lot-location')?.value || null,
      lot: tr.querySelector(`[name^="lot_no_${idx}_"]`)?.value || '',
      qty: parseFloat(tr.querySelector('.lot-qty')?.value || '0') || 0,
      pallet: parseFloat(tr.querySelector('.lot-pallet')?.value || '0') || 0,
      mfg: tr.querySelector(`[name^="lot_mfg_${idx}_"]`)?.value || null,
      exp: tr.querySelector(`[name^="lot_exp_${idx}_"]`)?.value || null,
      condition: tr.querySelector(`[name^="lot_cond_${idx}_"]`)?.value || '',
      note: tr.querySelector(`[name^="lot_note_${idx}_"]`)?.value || ''
    });
  });

  return {
    po_item_id: item.po_item_id,
    product_id: item.product_id,
    recv_qty: parseFloat(qtyEl.value||'0')||0,
    recv_uom_id: parseInt(unitEl.value||'0')||null,
    lots
  };
}

// ===== บันทึกเอกสารรับเข้า (PO Mode) =====
// ===== เพิ่มฟังก์ชันตรวจสอบก่อนบันทึก =====
function validateReceiptData() {
  const errors = [];
  const warnings = [];
  
  // ตรวจสอบคลังสินค้า
  const warehouseId = document.getElementById('warehouseSelect')?.value;
  if (!warehouseId) {
    errors.push('กรุณาเลือกคลังสินค้า');
  }
  
  // ตรวจสอบแต่ละรายการ
  const itemCards = document.querySelectorAll('.po-item-card');
  itemCards.forEach((card, idx) => {
    const qtyEl = document.querySelector(`[name="recv_qty_${idx}"]`);
    const maxQty = parseFloat(qtyEl?.value || '0');
    
    if (maxQty <= 0) {
      warnings.push(`รายการที่ ${idx + 1}: ยังไม่ระบุจำนวนที่รับ`);
      return;
    }
    
    // ตรวจสอบผลรวมล็อต
    const lotInputs = document.querySelectorAll(`#lot_body_${idx} .lot-qty`);
    let lotSum = 0;
    let hasLocation = false;
    
    lotInputs.forEach(inp => {
      const val = parseFloat(inp.value || '0');
      lotSum += val;
      
      // ตรวจสอบว่ามีการเลือกพื้นที่หรือไม่
      const row = inp.closest('tr');
      const locationSelect = row?.querySelector('.lot-location');
      if (locationSelect && locationSelect.value && val > 0) {
        hasLocation = true;
      }
    });
    
    // ตรวจสอบจำนวนเกิน
    if (lotSum > maxQty) {
      const diff = lotSum - maxQty;
      errors.push(`รายการที่ ${idx + 1}: จำนวนรวมในพื้นที่ (${lotSum.toFixed(2)}) เกินจำนวนที่รับ (${maxQty.toFixed(2)}) อยู่ ${diff.toFixed(2)}`);
    }
    
    // ตรวจสอบจำนวนขาด
    if (lotSum < maxQty) {
      const diff = maxQty - lotSum;
      warnings.push(`รายการที่ ${idx + 1}: จำนวนรวมในพื้นที่ (${lotSum.toFixed(2)}) ยังขาดอีก ${diff.toFixed(2)}`);
    }
    
    // ตรวจสอบว่าเลือกพื้นที่หรือยัง
    if (lotSum > 0 && !hasLocation) {
      errors.push(`รายการที่ ${idx + 1}: กรุณาเลือกพื้นที่เก็บสินค้า`);
    }
  });
  
  return { errors, warnings };
}

// ===== แก้ไข submitReceiptEnhancedPO ให้ตรวจสอบก่อนบันทึก =====
function submitReceiptEnhancedPO(){
  // ตรวจสอบข้อมูล
  const validation = validateReceiptData();
  
  // แสดง errors
  if (validation.errors.length > 0) {
    const errorMsg = '<strong>ไม่สามารถบันทึกได้:</strong><br><ul class="mb-0 ps-3">' + 
                     validation.errors.map(e => `<li>${e}</li>`).join('') + 
                     '</ul>';
    
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-danger alert-dismissible fade show';
    alertDiv.innerHTML = `
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      ${errorMsg}
    `;
    
    const container = document.querySelector('.container');
    container.insertBefore(alertDiv, container.firstChild);
    
    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
    return;
  }
  
  // แสดง warnings และถามยืนยัน
  if (validation.warnings.length > 0) {
    const warningMsg = validation.warnings.map(w => `• ${w}`).join('\n');
    if (!confirm(`มีข้อควรระวัง:\n\n${warningMsg}\n\nต้องการดำเนินการต่อหรือไม่?`)) {
      return;
    }
  }
  
  // ดำเนินการบันทึกต่อ...
  if (!window.currentPOData || !Array.isArray(window.currentItems)) {
    alert('กรุณาค้นหาและเลือก PO ก่อน'); 
    return;
  }
  
  const warehouse_id = document.getElementById('warehouseSelect').value || null;
  if (!warehouse_id){ 
    alert('กรุณาเลือกคลังสินค้า'); 
    return; 
  }

  const items_data = window.currentItems.map((it, idx)=> collectItemPayload(idx, it));
  const notes = document.getElementById('generalNotes')?.value || '';
  const receipt_date = document.getElementById('receiptDate')?.value || '';
  const invoice_no = document.getElementById('invoiceNumber')?.value || '';

showLoading();

// ✅ สร้าง payload ที่ถูกต้อง
const payload = {
  po_data: {
    ...window.currentPOData,
    warehouse_id: warehouse_id,
    receipt_date: receipt_date,
    invoice_no: invoice_no,
    notes: notes,
    received_by: '<?php echo $_SESSION["username"] ?? "system"; ?>'
  },
  items_data: items_data.map(item => ({
    po_item_id: item.po_item_id,
    product_id: item.product_id,
    ordered_qty: item.recv_qty, // จำนวนที่สั่ง
    receive_qty: item.recv_qty,  // จำนวนที่รับ
    unit_id: item.recv_uom_id,
    unit_price: 0, // ถ้ามีข้อมูลราคาให้เพิ่มที่นี่
    total_price: 0,
    lot_number: item.lots[0]?.lot || null,
    mfg_date: item.lots[0]?.mfg || null,
    exp_date: item.lots[0]?.exp || null,
    locations: item.lots.map(lot => ({
      location_id: lot.location_id,
      quantity: lot.qty,
      lot_number: lot.lot,
      mfg_date: lot.mfg,
      exp_date: lot.exp,
      pallet_count: lot.pallet,
      condition: lot.condition,
      note: lot.note
    })).filter(loc => loc.quantity > 0) // เอาแต่ที่มีจำนวน
  }))
};

console.log('Sending payload:', payload);

// ✅ ส่งแบบ JSON
fetch('?ajax=save_receipt_enhanced', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json'
  },
  body: JSON.stringify(payload)
})
.then(response => response.json())
.then(res => {
  hideLoading();
  if (res?.success) {
    alert('✅ บันทึกสำเร็จ!\nเลขที่ใบรับ: ' + (res.gr_number || ''));
    // Reload หรือ redirect
    setTimeout(() => {
      window.location.reload();
    }, 1500);
  } else {
    console.error('Save failed:', res);
    alert('❌ บันทึกไม่สำเร็จ: ' + (res?.message || 'Unknown error'));
  }
})
.catch(err => {
  hideLoading();
  console.error('Save error:', err);
  alert('❌ เกิดข้อผิดพลาด: ' + err.message);
});
}

// ===============================================
// =============== DIRECT MODE ====================
// ===============================================
async function loadSupplierProducts(sid){
  const allProd = document.querySelectorAll('.product-select');
  allProd.forEach(sel=>{ sel.innerHTML='<option value="">กำลังโหลดสินค้า...</option>'; sel.disabled=true; });
  try{
    const r = await fetch('?ajax=get_products_by_supplier&supplier_id='+encodeURIComponent(sid));
    const data = await r.json();
    cachedProducts = Array.isArray(data)?data:[];
    allProd.forEach(sel=>{
      let html = `<option value="">-- เลือกสินค้า --</option>`;
      for(const p of cachedProducts){
        const code = p.product_code ?? ""; const def = p.default_unit_id ?? "";
        html += `<option value="${p.product_id}" data-code="${hEsc(code)}" data-default-unit="${def}">${hEsc(p.product_name)}</option>`;
      }
      sel.innerHTML = html; sel.disabled=false;
    });
  }catch(e){
    cachedProducts=[];
    allProd.forEach(sel=>{
      sel.innerHTML='<option value="">โหลดสินค้าไม่สำเร็จ</option>';
      sel.disabled=true;
    });
  }
}

function unitsOptionsHtml(units, defaultUnitId){
  let html = `<option value="">-- เลือกหน่วย --</option>`;
  for (const u of units){
    const id = (u.unit_id ?? u.uom_id);
    const selected = (defaultUnitId && Number(defaultUnitId) === Number(id)) ? 'selected' : '';
    const label =
      u.unit_symbol
        ? `${(u.name || u.unit_name || u.unit_name_th)} (${u.unit_symbol})`
        :  (u.name || u.unit_name || u.unit_name_th || '');
    html += `<option value="${id}" ${selected}>${hEsc(label)}</option>`;
  }
  return html;
}

function warehouseOptionsHtml(){
  let html = `<option value="">-- เลือกคลัง --</option>`;
  for(const w of WAREHOUSES){ html += `<option value="${w.warehouse_id}">${hEsc(w.warehouse_name)}</option>`; }
  return html;
}

function buildItemCard(idx){
  const wrap = document.createElement('div'); 
  wrap.className='item-card'; 
  wrap.setAttribute('data-row',idx);
  wrap.innerHTML = `
    <div class="row g-3">
      <div class="col-lg-4">
        <label class="form-label upper">รหัสสินค้า (พิมพ์/สแกน)</label>
        <div class="input-group">
          <input type="text" class="form-control product-code-input" name="items[${idx}][product_code]" placeholder="พิมพ์หรือสแกนรหัสสินค้า...">
          <button class="btn btn-outline-secondary code-search-btn" type="button"><i class="fas fa-search"></i></button>
        </div>
        <select class="form-select product-select mt-1" name="items[${idx}][product_id]" ${cachedProducts.length?'':'disabled'}>
          ${(()=>{ if(!cachedProducts.length) return `<option value="">-- เลือกผู้ขายก่อน --</option>`;
            let h=`<option value="">-- เลือกสินค้า --</option>`;
            for(const p of cachedProducts){ const code=p.product_code??""; const def=p.default_unit_id??"";
              h += `<option value="${p.product_id}" data-code="${hEsc(code)}" data-default-unit="${def}">${hEsc(p.product_name)}</option>`;}
            return h;
          })()}
        </select>
        <input type="hidden" name="items[${idx}][item_description]">
      </div>

      <div class="col-lg-3">
        <label class="form-label upper">คลัง</label>
        <select class="form-select warehouse-select" name="items[${idx}][warehouse_id]">${warehouseOptionsHtml()}</select>
        <label class="form-label upper mt-2">ตำแหน่ง</label>
        <select class="form-select location-select" name="items[${idx}][location_id]" disabled>
          <option value="">เลือก Location</option>
        </select>
      </div>

      <div class="col-lg-3">
        <label class="form-label upper">จำนวน</label>
        <input type="number" step="0.0001" min="0" class="form-control text-end" name="items[${idx}][quantity]" placeholder="0.00">
        <label class="form-label upper mt-2">หน่วย</label>
        <select class="form-select unit-select" name="items[${idx}][unit_id]">
          ${UNITS_ALL.map(u=>{
            const label = u.unit_symbol ? `${u.unit_name} (${u.unit_symbol})` : u.unit_name;
            return `<option value="${u.unit_id}">${hEsc(label)}</option>`;
          }).join('')}
        </select>
      </div>

      <div class="col-lg-2 d-flex align-items-start justify-content-end">
        <button type="button" class="btn btn-danger btn-sm" onclick="removeItemCard(${idx})"><i class="fas fa-trash"></i></button>
      </div>

      <div class="col-lg-4">
        <label class="form-label upper">Lot จากผู้ขาย</label>
        <input type="text" class="form-control" name="items[${idx}][supplier_lot_number]" placeholder="หมายเลข Lot">
      </div>
      <div class="col-lg-4">
        <label class="form-label upper">วันที่ผลิต</label>
        <input type="date" class="form-control" name="items[${idx}][manufacturing_date]">
      </div>
      <div class="col-lg-4">
        <label class="form-label upper">วันหมดอายุ</label>
        <input type="date" class="form-control" name="items[${idx}][expiry_date]">
      </div>
      <div class="col-lg-6">
        <label class="form-label upper">จำนวนพาเลท</label>
        <input type="number" min="0" class="form-control text-end" name="items[${idx}][pallet_count]" placeholder="0">
      </div>
      <div class="col-lg-6">
        <label class="form-label upper">ราคาต่อหน่วย (ประเมิน)</label>
        <input type="number" step="0.01" min="0" class="form-control text-end" name="items[${idx}][estimated_unit_cost]" placeholder="0.00">
      </div>
    </div>`;
  setTimeout(()=>wireItemEvents(idx),0);
  return wrap;
}

function wireItemEvents(idx){
  const card = document.querySelector('.item-card[data-row="'+idx+'"]'); 
  if(!card) return;

  const productSelect = card.querySelector('.product-select');
  const unitSelect    = card.querySelector('.unit-select');
  const descHidden    = card.querySelector(`input[name="items[${idx}][item_description]"]`);
  const codeInput     = card.querySelector('.product-code-input');
  const codeBtn       = card.querySelector('.code-search-btn');
  const warehouseSel  = card.querySelector('.warehouse-select');
  const locationSel   = card.querySelector('.location-select');

  if(productSelect){
    productSelect.addEventListener('change', async function(){
      const pid = Number(this.value || 0);
      const opt = this.options[this.selectedIndex];
      const code = (opt && opt.dataset) ? opt.dataset.code : '';

      if(codeInput) codeInput.value = code || '';
      if(descHidden) descHidden.value = pid ? opt.textContent.trim() : '';

      if(pid){
        try{
          const r = await fetch('?ajax=get_units_for_product&product_id='+pid);
          const data = await r.json();
          const units = Array.isArray(data.units)?data.units:[];
          const defUnit = data.default_unit_id || (opt && opt.dataset ? opt.dataset.defaultUnit : null);
          // ใช้ units สำหรับสินค้านั้น ๆ ถ้ามี
          unitSelect.innerHTML = units.length
            ? unitsOptionsHtml(units, defUnit)
            : unitSelect.innerHTML;
        }catch(e){ /* keep fallback */ }
      }
    });
  }

  if(warehouseSel && locationSel){
    warehouseSel.addEventListener('change', async function(){
      const wid = Number(this.value || 0);
      locationSel.innerHTML = '<option value="">เลือก Location</option>'; 
      locationSel.disabled = true;
      if(!wid) return;
      try{
        const r = await fetch('?ajax=get_locations_by_warehouse&warehouse_id='+wid);
        const data = await r.json();
        const byZone = {};
        for(const loc of data){
          const z = loc.zone_name || 'ไม่ระบุโซน';
          if(!byZone[z]) byZone[z]=[];
          byZone[z].push(loc);
        }
        Object.keys(byZone).sort().forEach(z=>{
          const og = document.createElement('optgroup'); og.label = z;
          byZone[z].forEach(l=>{
            const o = document.createElement('option'); o.value=l.location_id; o.textContent=l.location_code; og.appendChild(o);
          });
          locationSel.appendChild(og);
        });
        locationSel.disabled=false;
      }catch(e){ locationSel.disabled=true; }
    });
  }

  async function searchByCode(){
    if(!codeInput) return;
    const raw = codeInput.value ? codeInput.value.trim() : '';
    if(!raw){ codeInput.classList.add('is-invalid'); setTimeout(()=>codeInput.classList.remove('is-invalid'),1500); return; }

    try{
      const r = await fetch('?ajax=get_product_by_code&code='+encodeURIComponent(raw));
      const data = await r.json();
      if(!data || !data.found){ codeInput.classList.add('is-invalid'); setTimeout(()=>codeInput.classList.remove('is-invalid'),1500); return; }

      const sid = String(data.supplier_id || '');
      const supplierSelect = document.getElementById('supplierSelect');
      if(sid && supplierSelect && supplierSelect.value !== sid){
        supplierSelect.value = sid;
        await loadSupplierProducts(sid);
      }else if(sid && supplierSelect && !supplierSelect.value){
        supplierSelect.value = sid;
        await loadSupplierProducts(sid);
      }else if(supplierSelect && supplierSelect.value && cachedProducts.length===0){
        await loadSupplierProducts(supplierSelect.value);
      }

      if(productSelect){
        let html = `<option value="">-- เลือกสินค้า --</option>`;
        for(const p of cachedProducts){
          const code = p.product_code ?? ""; const def = p.default_unit_id ?? "";
          html += `<option value="${p.product_id}" data-code="${hEsc(code)}" data-default-unit="${def}">${hEsc(p.product_name)}</option>`;
        }
        productSelect.innerHTML = html;
        productSelect.value = String(data.product_id);
        productSelect.dispatchEvent(new Event('change'));
      }
      codeInput.value = data.product_code || raw;
    }catch(e){
      codeInput.classList.add('is-invalid'); setTimeout(()=>codeInput.classList.remove('is-invalid'),1500);
    }
  }
  if(codeBtn) codeBtn.addEventListener('click', searchByCode);
  if(codeInput){
    codeInput.addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); searchByCode(); }});
    codeInput.addEventListener('blur', ()=>{ if(codeInput.value && !productSelect.value) searchByCode(); });
  }
}


function addItemCard(){ 
  const idx = rowIndex++; 
  const card = buildItemCard(idx); 
  document.getElementById('itemsCards').appendChild(card); 
}
function removeItemCard(idx){
  const card = document.querySelector('.item-card[data-row="'+idx+'"]');
  const count = document.querySelectorAll('.item-card').length;
  if(!card) return;
  if(count<=1){
    card.querySelectorAll('input').forEach(i=>i.value='');
    card.querySelectorAll('select').forEach(s=>s.selectedIndex=0);
    return;
  }
  card.remove();
}
function cancelDirectReceipt(){ 
  if(confirm('ต้องการยกเลิกและล้างข้อมูลหรือไม่?')) window.location.reload(); 
}

async function saveDirectReceipt(){
  const receiptNumber = document.querySelector('input[name="receipt_number"]').value;
  const receiptDate   = document.querySelector('input[name="receipt_date"]').value;
  const supplierId    = document.querySelector('select[name="supplier_id"]').value;
  const receiptReason = document.querySelector('input[name="receipt_reason"]').value;
  const notes         = document.querySelector('textarea[name="notes"]').value;

  if(!receiptNumber || !receiptDate){ alert('กรุณากรอกเลขที่เอกสารและวันที่รับเข้า'); return; }

  const itemCards = document.querySelectorAll('.item-card');
  const items = []; let hasValid = false;

  itemCards.forEach(card=>{
    const productId = card.querySelector('select[name*="[product_id]"]').value;
    const quantity  = card.querySelector('input[name*="[quantity]"]').value;
    const unitId    = card.querySelector('select[name*="[unit_id]"]').value;
    const warehouseId = card.querySelector('select[name*="[warehouse_id]"]').value;
    const locationId  = card.querySelector('select[name*="[location_id]"]').value;
    const supplierLot = card.querySelector('input[name*="[supplier_lot_number]"]').value;
    const estimatedUnitCost = card.querySelector('input[name*="[estimated_unit_cost]"]').value;
    const manufacturingDate = card.querySelector('input[name*="[manufacturing_date]"]').value;
    const expiryDate = card.querySelector('input[name*="[expiry_date]"]').value;
    const itemDescription = card.querySelector('input[name*="[item_description]"]').value;
    const palletCount = card.querySelector('input[name*="[pallet_count]"]').value;

    if(productId && quantity && parseFloat(quantity)>0){
      hasValid = true;
      items.push({
        product_id: parseInt(productId),
        quantity: parseFloat(quantity),
        unit_id: unitId?parseInt(unitId):null,
        warehouse_id: warehouseId?parseInt(warehouseId):null,
        location_id: locationId?parseInt(locationId):null,
        supplier_lot_number: supplierLot || null,
        estimated_unit_cost: estimatedUnitCost?parseFloat(estimatedUnitCost):null,
        actual_unit_cost: estimatedUnitCost?parseFloat(estimatedUnitCost):null,
        manufacturing_date: manufacturingDate || null,
        expiry_date: expiryDate || null,
        item_description: itemDescription || null,
        pallet_count: palletCount?parseInt(palletCount):0
      });
    }
  });
  if(!hasValid){ alert('กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ และกรอกข้อมูลให้ครบถ้วน'); return; }

  const saveBtn = document.getElementById('btnSaveDraft'); 
  const old = saveBtn.innerHTML;
  saveBtn.disabled = true; 
  saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังบันทึก...';

  try{
    const formData = new URLSearchParams();
    formData.append('receipt_number', receiptNumber);
    formData.append('receipt_date', receiptDate);
    formData.append('supplier_id', supplierId || '');
    formData.append('receipt_reason', receiptReason || '');
    formData.append('notes', notes || '');
    items.forEach((it,i)=>{ Object.keys(it).forEach(k=>{ if(it[k]!==null && it[k]!==undefined) formData.append(`items[${i}][${k}]`, it[k]); }); });

    const res = await fetch(window.location.href+'?ajax=save_direct_receipt',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:formData
    });
    const ct = res.headers.get('content-type');
    if(!ct || !ct.includes('application/json')){
      const html = await res.text(); 
      console.error('Server returned non-JSON:', html);
      alert('เกิด PHP Error - ดู Console สำหรับรายละเอียด'); 
      return;
    }
    const result = await res.json();
    if(result.success){
      let msg = 'บันทึกข้อมูลสำเร็จ!\n\n';
      msg += 'เลขที่เอกสาร: ' + result.receipt_number + '\n';
      msg += 'ID: ' + result.direct_receipt_id + '\n';
      msg += 'จำนวนรายการ: ' + result.total_items + '\n';
      msg += 'จำนวนรวม: ' + result.total_quantity + '\n';
      if(result.estimated_value>0) msg += 'มูลค่าประเมิน: ' + result.estimated_value.toLocaleString() + ' บาท\n';
      alert(msg);
      if(confirm('ต้องการสร้างใบรับเข้าใหม่หรือไม่?\n\nตกลง = สร้างใหม่\nยกเลิก = กลับไปหน้ารายการ')) window.location.reload();
      else window.location.href = 'inventory_view.php';
    }else{
      alert('เกิดข้อผิดพลาด: ' + result.message);
    }
  }catch(e){
    console.error('Request failed:', e);
    alert('เกิดข้อผิดพลาด: ' + e.message);
  }finally{
    saveBtn.disabled=false; 
    saveBtn.innerHTML = old;
  }
}

// ===== Events & Init =====
document.getElementById('supplierSelect')?.addEventListener('change', async function(){
  const sid = this.value;
  if(!sid){
    cachedProducts=[];
    document.querySelectorAll('.product-select').forEach(sel=>{
      sel.innerHTML='<option value="">-- เลือกผู้ขายก่อน --</option>'; 
      sel.disabled=true; 
    });
    return;
  }
  await loadSupplierProducts(sid);
});
document.getElementById('btnAddCard')?.addEventListener('click', addItemCard);
document.getElementById('btnSaveDraft')?.addEventListener('click', saveDirectReceipt);
document.getElementById('poNumberInput')?.addEventListener('keypress', function(e){ 
  if(e.key==='Enter'){ e.preventDefault(); loadPOData(); }
});

document.addEventListener('DOMContentLoaded', function(){
  switchReceiptMode('po'); // default
  const rd = document.getElementById('receiptDate'); 
  if(rd && !rd.value){ rd.value = new Date().toISOString().split('T')[0]; }
  document.getElementById('warehouseSelect')?.addEventListener('change', function(){
    if(this.value) loadWarehouseLocationsForRows(this.value);
  });
});
</script>
</body>
</html>
