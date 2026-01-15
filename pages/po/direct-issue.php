<?php
// direct-issue.php - หน้าจ่ายออกสินค้า (Updated)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

// === Bootstraps / Config ===
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Auth.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin', 'editor']);

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
    die("Database connection failed.");
}

// === AJAX endpoints ===
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $action = $_GET['ajax'];

        // 1) ค้นหาสินค้าที่มีสต็อก (Updated with Pallet info)
if ($action === 'search_products') {
    $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
    $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
    
    if (empty($searchTerm) && $warehouseId <= 0) {
        echo json_encode([]);
        exit;
    }   
    $sql = "
        SELECT DISTINCT
            p.id AS product_id,
            p.Name AS product_name,
            p.SSP_Code AS product_code,
            p.Unit_id AS default_unit_id,
            u.unit_name,
            u.unit_symbol,
            
            -- Stock Information
            ISNULL(i.current_stock, 0) AS current_stock,
            ISNULL(i.available_stock, 0) AS available_stock,
            ISNULL(i.reserved_stock, 0) AS reserved_stock,
            
            -- Pallet Information
            ISNULL(i.current_pallet, 0) AS current_pallet,
            ISNULL(i.available_pallet, 0) AS available_pallet,
            ISNULL(i.reserved_pallet, 0) AS reserved_pallet,
            
            -- Location Information
            i.warehouse_id,
            w.warehouse_name,
            w.warehouse_code,
            i.location_id,
            wl.location_code,
            wl.zone,
            wl.aisle,
            wl.rack,
            wl.shelf,
            
            -- Cost Information
            ISNULL(i.average_cost, 0) AS average_cost,
            ISNULL(i.last_cost, 0) AS last_cost,
            
            -- Last Update Information
            i.last_updated,
            i.last_movement_date,
            
            -- Lot Information (เพิ่มข้อมูล lot)
            lt.supplier_lot_number,
            lt.internal_batch_number,
            sm.batch_lot as movement_batch_lot
            
        FROM Master_Products_ID p
        LEFT JOIN Units u ON u.unit_id = p.Unit_id
        LEFT JOIN Inventory_Stock i ON i.product_id = p.id
        LEFT JOIN Warehouses w ON w.warehouse_id = i.warehouse_id  
        LEFT JOIN Warehouse_Locations wl ON wl.location_id = i.location_id
        
        -- เชื่อมต่อตาราง Lot_Batch_Tracking เพื่อค้นหา lot
        LEFT JOIN Lot_Batch_Tracking lt ON (
            lt.product_id = p.id 
            AND lt.warehouse_id = i.warehouse_id
            AND lt.quantity_remaining > 0
        )
        
        -- เชื่อมต่อ Stock_Movements เพื่อค้นหา batch_lot
        LEFT JOIN Stock_Movements sm ON (
            sm.product_id = p.id 
            AND sm.warehouse_id = i.warehouse_id
            AND sm.batch_lot IS NOT NULL
            AND sm.batch_lot != ''
        )
        
        WHERE p.is_active = 1 
            AND i.available_stock > 0
    ";
    
    $params = [];
    
    if (!empty($searchTerm)) {
        // ค้นหาใน product name, code, supplier lot, internal batch, หรือ batch lot
        $sql .= " AND (
            p.Name LIKE ? 
            OR p.SSP_Code LIKE ? 
            OR lt.supplier_lot_number LIKE ?
            OR lt.internal_batch_number LIKE ?
            OR sm.batch_lot LIKE ?
        )";
        $searchPattern = "%$searchTerm%";
        $params[] = $searchPattern; // product name
        $params[] = $searchPattern; // product code
        $params[] = $searchPattern; // supplier lot
        $params[] = $searchPattern; // internal batch
        $params[] = $searchPattern; // batch lot
    }
    
    if ($warehouseId > 0) {
        $sql .= " AND i.warehouse_id = ?";
        $params[] = $warehouseId;
    }
    
    $sql .= " ORDER BY p.Name, w.warehouse_name, wl.location_code";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    
    // เพิ่มข้อมูล lot ที่พบในผลลัพธ์
    foreach ($results as &$product) {
        $lotInfo = [];
        
        if (!empty($product['supplier_lot_number'])) {
            $lotInfo[] = "Supplier: " . $product['supplier_lot_number'];
        }
        if (!empty($product['internal_batch_number'])) {
            $lotInfo[] = "Internal: " . $product['internal_batch_number'];
        }
        if (!empty($product['movement_batch_lot'])) {
            $lotInfo[] = "Batch: " . $product['movement_batch_lot'];
        }
        
        $product['lot_info'] = implode(' | ', $lotInfo);
        
        // ลบฟิลด์ที่ไม่ต้องการส่งไปหน้าบ้าน
        unset($product['supplier_lot_number']);
        unset($product['internal_batch_number']); 
        unset($product['movement_batch_lot']);
    }
    
    echo json_encode($results);
    exit;
}

        // 2) โหลดหน่วยของสินค้า
        if ($action === 'get_units_for_product') {
            $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
            if ($productId <= 0) { 
                echo json_encode(['units' => [], 'default_unit_id' => null]); 
                exit; 
            }

            $def = $pdo->prepare("SELECT Unit_id FROM Master_Products_ID WHERE id = ?");
            $def->execute([$productId]);
            $default_unit_id = $def->fetchColumn();

            $q = $pdo->prepare("
                SELECT 
                    pu.unit_id,
                    u.unit_code,
                    u.unit_name,
                    u.unit_symbol,
                    pu.is_issue_unit
                FROM Product_Units pu
                JOIN Units u ON u.unit_id = pu.unit_id
                WHERE pu.product_id = ? AND pu.is_issue_unit = 1
                ORDER BY u.unit_name
            ");
            $q->execute([$productId]);
            $units = $q->fetchAll();

            if (!$units) {
                $q2 = $pdo->query("SELECT unit_id, unit_code, unit_name, unit_symbol FROM Units WHERE is_active = 1 ORDER BY unit_name");
                $units = $q2->fetchAll();
            }

            echo json_encode([
                'units' => $units,
                'default_unit_id' => $default_unit_id ? (int)$default_unit_id : null
            ]);
            exit;
        }

        // 3) โหลดข้อมูล Machine Destinations
        if ($action === 'get_machines') {
            $machines = $pdo->query("
                SELECT machine_code, machine_name, machine_type, department 
                FROM Machine_Destinations 
                WHERE is_active = 1 
                ORDER BY machine_type, machine_code
            ")->fetchAll();
            
            echo json_encode($machines);
            exit;
        }

        // 4) โหลดข้อมูล Lot From Supplier ตามสินค้า
        // 4) โหลดข้อมูล Lot From Supplier ตามสินค้า (แก้ไขแล้ว)
// 4) โหลดข้อมูล Lot From Supplier ตามสินค้า (DEBUG VERSION)
if ($action === 'get_lots_for_product') {
    $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
    $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
    
    error_log("Debug: กำลังโหลด lots สำหรับ product_id=$productId, warehouse_id=$warehouseId");
    
    if ($productId <= 0) {
        echo json_encode([]);
        exit;
    }
    
    try {
        // ก่อนอื่น ลองดูข้อมูลใน Stock_Movements
        $debugSql = "
            SELECT TOP 10
                sm.batch_lot,
                sm.movement_type,
                sm.quantity,
                sm.movement_date,
                sm.product_id,
                sm.warehouse_id
            FROM Stock_Movements sm
            WHERE sm.product_id = ?
            ORDER BY sm.movement_date DESC
        ";
        
        $debugStmt = $pdo->prepare($debugSql);
        $debugStmt->execute([$productId]);
        $debugResults = $debugStmt->fetchAll();
        
        error_log("Debug: พบ " . count($debugResults) . " records ใน Stock_Movements สำหรับ product_id=$productId");
        error_log("Debug: Sample data: " . json_encode(array_slice($debugResults, 0, 2)));
        
        // ตรวจสอบว่ามี batch_lot อะไรบ้าง
        $batchCheckSql = "
            SELECT DISTINCT 
                sm.batch_lot,
                COUNT(*) as count
            FROM Stock_Movements sm
            WHERE sm.product_id = ?
                AND sm.batch_lot IS NOT NULL
            GROUP BY sm.batch_lot
        ";
        
        $batchStmt = $pdo->prepare($batchCheckSql);
        $batchStmt->execute([$productId]);
        $batchResults = $batchStmt->fetchAll();
        
        error_log("Debug: พบ " . count($batchResults) . " distinct batch_lot:");
        foreach ($batchResults as $batch) {
            error_log("  - batch_lot: '" . $batch['batch_lot'] . "' (count: " . $batch['count'] . ")");
        }
        
        // ใช้ query หลักแบบง่าย
        $sql = "
            SELECT DISTINCT 
                COALESCE(sm.batch_lot, 'ไม่ระบุ') as batch_lot,
                COUNT(*) as movement_count,
                MAX(sm.movement_date) as last_movement_date,
                SUM(CASE WHEN sm.movement_type = 'RECEIPT' THEN sm.quantity ELSE 0 END) as total_received,
                SUM(CASE WHEN sm.movement_type = 'ISSUE' THEN sm.quantity ELSE 0 END) as total_issued
            FROM Stock_Movements sm
            WHERE sm.product_id = ? 
                AND sm.batch_lot IS NOT NULL 
                AND sm.batch_lot != ''
        ";
        
        $params = [$productId];
        
        if ($warehouseId > 0) {
            $sql .= " AND sm.warehouse_id = ?";
            $params[] = $warehouseId;
        }
        
        $sql .= " 
            GROUP BY sm.batch_lot
            ORDER BY MAX(sm.movement_date) DESC
        ";
        
        error_log("Debug: กำลังรัน SQL หลัก: " . $sql);
        error_log("Debug: พารามิเตอร์: " . json_encode($params));
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $lots = $stmt->fetchAll();
        
        error_log("Debug: ผลลัพธ์ query หลัก: พบ " . count($lots) . " lots");
        
        if (count($lots) > 0) {
            error_log("Debug: Sample lot data: " . json_encode($lots[0]));
        }
        
        // คำนวณจำนวนคงเหลือและเพิ่มข้อมูลเสริม
        foreach ($lots as &$lot) {
            $lot['quantity_remaining'] = $lot['total_received'] - abs($lot['total_issued']);
            
            // เพิ่มข้อมูล default
            $lot['lot_status'] = 'ACTIVE';
            $lot['supplier_lot_number'] = null;
            $lot['manufacturing_date'] = null;
            $lot['expiry_date'] = null;
            
            // จัดรูปแบบวันที่
            if (!empty($lot['last_movement_date'])) {
                $lot['last_movement_date_formatted'] = date('d/m/Y H:i', strtotime($lot['last_movement_date']));
            }
        }
        
        error_log("Debug: ส่งกลับ " . count($lots) . " lots ที่ประมวลผลแล้ว");
        
        // เพิ่ม header เพื่อให้แน่ใจว่าส่ง JSON
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($lots, JSON_UNESCAPED_UNICODE);
        exit;
        
    } catch (Exception $e) {
        error_log("ข้อผิดพลาดใน get_lots_for_product: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'error' => true,
            'message' => 'ไม่สามารถโหลดข้อมูล Lot ได้: ' . $e->getMessage(),
            'debug_info' => [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'error_details' => $e->getMessage()
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

        // 5) บันทึกการจ่ายออก (Updated)
        if ($action === 'save_direct_issue') {
            error_log("AJAX save_direct_issue called");
            error_log("POST data: " . print_r($_POST, true));
            
            $issueData = [
                'issue_number' => $_POST['issue_number'] ?? '',
                'issue_date' => $_POST['issue_date'] ?? '',
                'department' => $_POST['department'] ?? '',
                'requester' => $_POST['requester'] ?? '',
                'issue_reason' => $_POST['issue_reason'] ?? '',
                'notes' => $_POST['notes'] ?? '',
                'items' => $_POST['items'] ?? []
            ];
            
            $result = saveDirectIssue($issueData);
            echo json_encode($result);
            exit;
        }

        // 6) Test endpoint
        if ($action === 'test') {
            echo json_encode(['success' => true, 'message' => 'AJAX works!']);
            exit;
        }

        echo json_encode(['error' => 'Unknown action: ' . $action]);
        exit;

    } catch (Throwable $e) {
        error_log("AJAX Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// === Preload dropdowns ===
try {
    $Warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM Warehouses WHERE is_active = 1 OR is_active IS NULL ORDER BY warehouse_name")->fetchAll();
    $UnitsAll = $pdo->query("SELECT unit_id, unit_code, unit_name, unit_symbol FROM Units WHERE is_active = 1 ORDER BY unit_name")->fetchAll();
    $Machines = $pdo->query("SELECT machine_code, machine_name, machine_type, department FROM Machine_Destinations WHERE is_active = 1 ORDER BY machine_type, machine_code")->fetchAll();
    $StatusTypes = $pdo->query("SELECT status_code, status_name, status_name_th, status_color FROM Issue_Status_Types WHERE is_active = 1 ORDER BY sort_order")->fetchAll();
} catch (Exception $e) {
    error_log("Error loading dropdown data: " . $e->getMessage());
    $Warehouses = [];
    $UnitsAll = [];
    $Machines = [];
    $StatusTypes = [];
}

// === Functions ===
function saveDirectIssue($data) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Validate required data
        if (empty($data['issue_number']) || empty($data['issue_date'])) {
            throw new Exception('Missing required fields: issue_number or issue_date');
        }
        
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new Exception('No items provided');
        }
        
        // Filter และ validate items
        $validItems = [];
        $totalQuantity = 0;
        $estimatedValue = 0;
        
        foreach ($data['items'] as $item) {
            if (!empty($item['product_id']) && !empty($item['quantity']) && $item['quantity'] > 0) {
                $quantity = (float)$item['quantity'];
                $unitCost = !empty($item['unit_cost']) ? (float)$item['unit_cost'] : 0;
                
                // ตรวจสอบสต็อกเพียงพอ
                $checkStock = checkAvailableStock($item['product_id'], $item['warehouse_id'], $item['location_id'], $quantity);
                if (!$checkStock['sufficient']) {
                    throw new Exception("สต็อกไม่เพียงพอสำหรับสินค้า {$item['product_name']} (มีอยู่ {$checkStock['available']} ต้องการ {$quantity})");
                }
                
                $validItems[] = $item;
                $totalQuantity += $quantity;
                $estimatedValue += ($quantity * $unitCost);
            }
        }
        
        if (empty($validItems)) {
            throw new Exception('No valid items found');
        }
        
        $totalItems = count($validItems);
        
        // Insert Direct_Issue_Header
        $headerSql = "
            INSERT INTO Direct_Issue_Header (
                issue_number, issue_date, department, requester, issue_reason,
                total_items, total_quantity, estimated_value, status,
                created_by, created_date, notes
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED', ?, GETDATE(), ?
            )
        ";
        
        $stmt = $pdo->prepare($headerSql);
        $stmt->execute([
            $data['issue_number'],
            $data['issue_date'],
            $data['department'],
            $data['requester'],
            $data['issue_reason'],
            $totalItems,
            $totalQuantity,
            $estimatedValue,
            $_SESSION['user_id'] ?? 1,
            $data['notes']
        ]);
        
        $directIssueId = $pdo->lastInsertId();
        if (!$directIssueId) {
            throw new Exception('Failed to get direct issue ID');
        }
        
        // Insert Direct_Issue_Items (Updated with new fields)
        $itemSql = "
            INSERT INTO Direct_Issue_Items (
                direct_issue_id, line_number, product_id, item_description,
                quantity, unit_id, unit_cost, total_cost,
                warehouse_id, location_id, batch_lot, pallet_count,
                mpr_number, lot_mpr_number, withdrawal_request_number,
                receive_date, issue_date_actual, lot_from_supplier,
                item_status, machine_destination, item_notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $itemStmt = $pdo->prepare($itemSql);
        
        foreach ($validItems as $index => $item) {
            $unitCost = (float)($item['unit_cost'] ?? 0);
            $totalCost = $item['quantity'] * $unitCost;
            
            $itemStmt->execute([
                $directIssueId,
                $index + 1,
                (int)$item['product_id'],
                $item['product_name'] ?? '',
                (float)$item['quantity'],
                !empty($item['unit_id']) ? (int)$item['unit_id'] : null,
                $unitCost,
                $totalCost,
                !empty($item['warehouse_id']) ? (int)$item['warehouse_id'] : null,
                !empty($item['location_id']) ? (int)$item['location_id'] : null,
                $item['batch_lot'] ?? null,
                !empty($item['pallet_count']) ? (int)$item['pallet_count'] : 0,
                
                // New fields
                $item['mpr_number'] ?? null,
                $item['lot_mpr_number'] ?? null,
                $item['withdrawal_request_number'] ?? null,
                !empty($item['receive_date']) ? $item['receive_date'] : null,
                !empty($item['issue_date_actual']) ? $item['issue_date_actual'] : date('Y-m-d'),
                $item['lot_from_supplier'] ?? null,
                $item['item_status'] ?? 'COMPLETED',
                $item['machine_destination'] ?? null,
                $item['item_notes'] ?? null
            ]);
        }
        
        // Update inventory และ stock movements
        foreach ($validItems as $item) {
            if (!empty($item['product_id']) && !empty($item['warehouse_id']) && !empty($item['quantity'])) {
                // ลดสต็อก
                updateInventoryStock(
                    $item['product_id'],
                    $item['warehouse_id'],
                    $item['location_id'] ?? null,
                    -$item['quantity'], // ติดลบเพื่อลดสต็อก
                    $item['unit_cost'] ?? 0,
                    -(int)($item['pallet_count'] ?? 0) // ลดพาเลท
                );
                
                // บันทึก stock movement
                createStockMovement([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $item['warehouse_id'],
                    'location_id' => $item['location_id'] ?? null,
                    'movement_type' => 'ISSUE',
                    'quantity' => $item['quantity'],
                    'unit_id' => $item['unit_id'] ?? null,
                    'unit_cost' => $item['unit_cost'] ?? 0,
                    'pallet_count' => $item['pallet_count'] ?? 0,
                    'reference_type' => 'DIRECT_ISSUE',
                    'reference_id' => $directIssueId,
                    'reference_number' => $data['issue_number'],
                    'batch_lot' => $item['batch_lot'] ?? null,
                    'mpr_number' => $item['mpr_number'] ?? null,
                    'machine_destination' => $item['machine_destination'] ?? null
                ]);
            }
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Direct issue saved successfully',
            'direct_issue_id' => $directIssueId,
            'issue_number' => $data['issue_number'],
            'total_items' => $totalItems,
            'total_quantity' => $totalQuantity,
            'estimated_value' => $estimatedValue
        ];
        
    } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollback();
        }
        error_log("Save Direct Issue Error: " . $e->getMessage());
        
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

function checkAvailableStock($productId, $warehouseId, $locationId, $requestedQuantity) {
    global $pdo;
    
    try {
        $sql = "
            SELECT ISNULL(available_stock, 0) as available_stock
            FROM Inventory_Stock 
            WHERE product_id = ? AND warehouse_id = ? 
              AND (location_id = ? OR (location_id IS NULL AND ? IS NULL))
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$productId, $warehouseId, $locationId, $locationId]);
        $result = $stmt->fetch();
        
        $availableStock = $result ? $result['available_stock'] : 0;
        
        return [
            'sufficient' => $availableStock >= $requestedQuantity,
            'available' => $availableStock,
            'requested' => $requestedQuantity
        ];
        
    } catch (Exception $e) {
        error_log("Check Stock Error: " . $e->getMessage());
        return [
            'sufficient' => false,
            'available' => 0,
            'requested' => $requestedQuantity
        ];
    }
}

function updateInventoryStock($productId, $warehouseId, $locationId, $quantity, $unitCost = 0, $palletCount = 0) {
    global $pdo;
    
    try {
        $checkSql = "
            SELECT inventory_id, current_stock, available_stock, average_cost, 
                   current_pallet, available_pallet 
            FROM Inventory_Stock 
            WHERE product_id = ? AND warehouse_id = ? AND (location_id = ? OR (location_id IS NULL AND ? IS NULL))
        ";
        
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([$productId, $warehouseId, $locationId, $locationId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $updateSql = "
                UPDATE Inventory_Stock 
                SET current_stock = ISNULL(current_stock, 0) + ?,
                    available_stock = ISNULL(available_stock, 0) + ?,
                    current_pallet = ISNULL(current_pallet, 0) + ?,
                    available_pallet = ISNULL(available_pallet, 0) + ?,
                    last_updated = GETDATE(),
                    last_movement_date = GETDATE()
                WHERE inventory_id = ?
            ";
            
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute([
                $quantity, 
                $quantity, 
                $palletCount, 
                $palletCount,
                $existing['inventory_id']
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Update Inventory Stock Error: " . $e->getMessage());
        throw $e;
    }
}

function createStockMovement($data) {
    global $pdo;
    
    try {
        $sql = "
            INSERT INTO Stock_Movements (
                product_id, warehouse_id, location_id, movement_type, quantity,
                unit_id, unit_cost, total_cost, reference_type, reference_id, reference_number,
                movement_date, batch_lot, quantity_pallet, created_by, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?, ?, ?, ?)
        ";
        
        $unitCost = $data['unit_cost'] ?? 0;
        $totalCost = $data['quantity'] * $unitCost;
        
        $notes = 'Direct Issue - ' . ($data['reference_number'] ?? '');
        if (!empty($data['mpr_number'])) {
            $notes .= ' | MPR: ' . $data['mpr_number'];
        }
        if (!empty($data['machine_destination'])) {
            $notes .= ' | Machine: ' . $data['machine_destination'];
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['product_id'],
            $data['warehouse_id'],
            $data['location_id'],
            $data['movement_type'],
            $data['quantity'],
            $data['unit_id'],
            $unitCost,
            $totalCost,
            $data['reference_type'],
            $data['reference_id'],
            $data['reference_number'],
            $data['batch_lot'],
            $data['pallet_count'] ?? 0,
            $_SESSION['user_id'] ?? 1,
            $notes
        ]);
        
    } catch (Exception $e) {
        error_log("Create Stock Movement Error: " . $e->getMessage());
        throw $e;
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$issueNumber = 'DI-' . date('Ymd-His');
?>

<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Direct Issue - จ่ายออกสินค้า</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #8B4513;
            --secondary-color: #FF8C00;
            --accent-color: #A0522D;
            --success-color: #059669;
            --warning-color: #d97706;
            --danger-color: #dc2626;
            --primary-gradient: linear-gradient(135deg, #8B4513, #A0522D);
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

        .card {
            border: 0;
            box-shadow: 0 10px 30px rgba(139, 69, 19, 0.15);
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.95);
        }

        .card-header {
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
            background: var(--primary-gradient) !important;
            color: white !important;
            border: none;
            padding: 20px;
            font-weight: bold;
        }

        .card-body {
            padding: 25px;
        }

        .sticky-actions { 
            position: sticky; 
            bottom: 0; 
            background: rgba(255, 255, 255, 0.98);
            padding: 20px; 
            box-shadow: 0 -10px 30px rgba(139, 69, 19, 0.1);
            border-radius: 0 0 20px 20px;
        }

        .small-muted { 
            font-size: .85rem; 
            color: #6c757d; 
        }

        .badge-soft { 
            background: rgba(139, 69, 19, 0.1);
            color: var(--primary-color);
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 500;
        }

        .form-label.upper { 
            text-transform: uppercase; 
            font-size: .82rem; 
            letter-spacing: .02em; 
            color: var(--primary-color);
            font-weight: bold;
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid rgba(139, 69, 19, 0.2);
            padding: 12px 15px;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.15);
        }

        .item-card { 
            background: rgba(248, 249, 250, 0.9);
            border: 2px solid rgba(139, 69, 19, 0.1);
            border-radius: 15px;
            padding: 20px; 
            position: relative;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .item-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 69, 19, 0.15);
            border-color: rgba(139, 69, 19, 0.2);
        }

        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            border-radius: 10px;
            padding: 12px 20px;
            font-weight: bold;
            color: white;
            transition: all 0.3s ease;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #10b981);
            border: none;
            border-radius: 10px;
            padding: 12px 20px;
            font-weight: bold;
            color: white;
            transition: all 0.3s ease;
        }

        .btn-outline-secondary {
            border: 2px solid rgba(139, 69, 19, 0.3);
            color: var(--primary-color);
            border-radius: 10px;
            padding: 12px 20px;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .page-header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(139, 69, 19, 0.15);
        }

        .page-title {
            color: var(--primary-color);
            font-weight: bold;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stock-info {
            background: rgba(5, 150, 105, 0.1);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 0.875rem;
        }

        .stock-warning {
            background: rgba(217, 119, 6, 0.1);
            color: var(--warning-color);
        }

        .stock-danger {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger-color);
        }

        .product-search-result {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .product-search-result:hover {
            background: #f8f9fa;
            border-color: var(--primary-color);
        }

        .search-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .expanded-fields {
            background: rgba(255, 255, 255, 0.8);
            border-radius: 12px;
            padding: 15px;
            margin-top: 15px;
            border: 1px dashed rgba(139, 69, 19, 0.3);
        }

        .machine-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .machine-cutting { background: #e3f2fd; color: #1565c0; }
        .machine-printing { background: #f3e5f5; color: #7b1fa2; }
        .machine-converting { background: #e8f5e8; color: #388e3c; }
        .machine-other { background: #fff3e0; color: #f57c00; }

        .status-badge {
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <a href="inventory.php" class="btn btn-light btn-sm me-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h5 class="navbar-brand mb-0">
                        <i class="fas fa-sign-out-alt me-2"></i>จ่ายออกสินค้า (Direct Issue)
                    </h5>
                    <small class="text-light">จัดการการจ่ายออกสินค้าจากสต็อก</small>
                </div>
            </div>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-th-large me-1"></i> แดชบอร์ด
                </a>
                <a class="nav-link" href="#">
                    <i class="fas fa-user-circle me-1"></i> <?php echo h($_SESSION['full_name'] ?? 'ผู้ใช้งาน'); ?>
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <!-- Page Header -->
        <div class="page-header">
            <div class="d-flex align-items-center justify-content-between">
                <h3 class="page-title">
                    <i class="fas fa-sign-out-alt"></i>
                    จ่ายออกสินค้า (Direct Issue)
                </h3>
                <span class="badge badge-soft">
                    <i class="fas fa-minus-circle me-1"></i>การลดสต็อก
                </span>
            </div>
        </div>

        <!-- Main Form Card -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-edit me-2"></i>ข้อมูลการจ่ายออก
            </div>
            <div class="card-body">
                <form id="directIssueForm" autocomplete="off">
                    <div class="row g-4">
                        <div class="col-md-3">
                            <label class="form-label upper">เลขที่เอกสาร</label>
                            <input type="text" class="form-control" name="issue_number" value="<?php echo h($issueNumber); ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label upper">วันที่จ่ายออก</label>
                            <input type="date" class="form-control" name="issue_date" value="<?php echo h(date('Y-m-d')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label upper">แผนก/หน่วยงาน</label>
                            <input type="text" class="form-control" name="department" placeholder="แผนกที่ขอเบิก">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label upper">ผู้เบิก</label>
                            <input type="text" class="form-control" name="requester" placeholder="ชื่อผู้เบิกสินค้า">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label upper">เหตุผลการจ่ายออก</label>
                            <input type="text" class="form-control" name="issue_reason" placeholder="เช่น ใช้ในการผลิต, ซ่อมบำรุง, ทดแทนของเสีย">
                        </div>
                        <div class="col-12">
                            <label class="form-label upper">หมายเหตุ</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"></textarea>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Items Card -->
        <div class="card">
<!-- ย้ายไปในส่วน header ของ Items Card -->
<div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-list me-2"></i>รายการสินค้าที่จ่ายออก</span>
    <div class="d-flex align-items-center gap-2">
        <select class="form-select form-select-sm" id="warehouseFilter" style="width: 200px;">
            <option value="">-- กรองตามคลัง --</option>
            <?php foreach ($Warehouses as $w): ?>
                <option value="<?php echo (int)$w['warehouse_id']; ?>"><?php echo h($w['warehouse_name']); ?></option>
            <?php endforeach; ?>
        </select>
        
        <button type="button" class="btn btn-outline-primary btn-sm" id="btnSearchProduct">
            <i class="fas fa-search me-1"></i>ค้นหาสินค้า
        </button>
        <button type="button" class="btn btn-light btn-sm" id="btnAddCard">
            <i class="fas fa-plus me-1"></i>เพิ่มรายการ
        </button>
    </div>
</div>


            <div class="card-body">
                <!-- Product Search Modal -->
                <div class="modal fade" id="productSearchModal" tabindex="-1">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">ค้นหาสินค้าในสต็อก</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <!-- ในไฟล์ direct-issue.php ตรงส่วน Product Search Modal -->
<div class="modal-body">
    <div class="row g-3 mb-3">
        <div class="col-md-8">
            <input type="text" class="form-control" id="productSearchInput" 
                   placeholder="ค้นหาชื่อสินค้า, รหัสสินค้า, หรือเลข Lot...">
        </div>
        <div class="col-md-4">
            <select class="form-select" id="productSearchWarehouse">
                <option value="">-- ทุกคลัง --</option>
                <?php foreach ($Warehouses as $w): ?>
                    <option value="<?php echo (int)$w['warehouse_id']; ?>"><?php echo h($w['warehouse_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    
    <!-- เพิ่มคำแนะนำการค้นหา -->
    <div class="alert alert-info mb-3">
        <small>
            <i class="fas fa-info-circle me-1"></i>
            <strong>วิธีการค้นหา:</strong> สามารถค้นหาได้จาก ชื่อสินค้า, รหัสสินค้า, Supplier Lot Number, Internal Batch Number, หรือ Batch Lot
        </small>
    </div>
    
    <div id="productSearchResults" class="mt-3" style="max-height: 400px; overflow-y: auto;"></div>
</div>
                        </div>
                    </div>
                </div>

                <div id="itemsCards" class="d-flex flex-column"></div>
            </div>

            <div class="sticky-actions d-flex gap-3 justify-content-end">
                <a href="inventory.php" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i>ยกเลิก
                </a>
                <button type="button" class="btn btn-success" id="btnSaveIssue">
                    <i class="fas fa-save me-1"></i>บันทึกการจ่ายออก
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    /** ======= Bootstrap data from PHP ======= */
    const WAREHOUSES = <?php echo json_encode($Warehouses, JSON_UNESCAPED_UNICODE); ?>;
    const UNITS_ALL = <?php echo json_encode($UnitsAll, JSON_UNESCAPED_UNICODE); ?>;
    const MACHINES = <?php echo json_encode($Machines, JSON_UNESCAPED_UNICODE); ?>;
    const STATUS_TYPES = <?php echo json_encode($StatusTypes, JSON_UNESCAPED_UNICODE); ?>;

    /** ======= State ======= */
    let rowIndex = 0;
    let productSearchModal;

    /** ======= DOM ======= */
    const $itemsCards = document.getElementById('itemsCards');
    const $btnAddCard = document.getElementById('btnAddCard');
    const $btnSearchProduct = document.getElementById('btnSearchProduct');
    const $productSearchInput = document.getElementById('productSearchInput');
    const $productSearchWarehouse = document.getElementById('productSearchWarehouse');
    const $productSearchResults = document.getElementById('productSearchResults');

    /** ======= Helpers ======= */
    function escapeHtml(str) {
        return (str ?? '').toString()
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function unitsOptionsHtml(units, defaultUnitId) {
        let html = `<option value="">-- เลือกหน่วย --</option>`;
        for (const u of units) {
            const selected = (defaultUnitId && Number(defaultUnitId) === Number(u.unit_id)) ? 'selected' : '';
            const label = u.unit_symbol ? `${u.unit_name} (${u.unit_symbol})` : u.unit_name;
            html += `<option value="${u.unit_id}" ${selected}>${escapeHtml(label)}</option>`;
        }
        return html;
    }

    function machinesOptionsHtml(selectedMachine = '') {
        let html = `<option value="">-- เลือกเครื่อง --</option>`;
        for (const m of MACHINES) {
            const selected = selectedMachine === m.machine_code ? 'selected' : '';
            html += `<option value="${m.machine_code}" ${selected}>${escapeHtml(m.machine_code)} - ${escapeHtml(m.machine_name)}</option>`;
        }
        return html;
    }

    function statusOptionsHtml(selectedStatus = 'COMPLETED') {
        let html = `<option value="">-- เลือกสถานะ --</option>`;
        for (const s of STATUS_TYPES) {
            const selected = selectedStatus === s.status_code ? 'selected' : '';
            html += `<option value="${s.status_code}" ${selected}>${escapeHtml(s.status_name_th || s.status_name)}</option>`;
        }
        return html;
    }

    function getMachineClass(machineCode) {
        if (!machineCode) return 'machine-other';
        if (machineCode.startsWith('CUT')) return 'machine-cutting';
        if (machineCode.startsWith('PLT')) return 'machine-printing';
        if (machineCode.startsWith('CTR')) return 'machine-converting';
        return 'machine-other';
    }

    /** ======= Product Search ======= */
    // แทนที่ฟังก์ชัน searchProducts ในไฟล์ JavaScript

async function searchProducts() {
    const searchTerm = $productSearchInput.value.trim();
    const warehouseId = $productSearchWarehouse.value;
    
    if (!searchTerm && !warehouseId) {
        $productSearchResults.innerHTML = '<p class="text-muted">กรุณาป้อนคำค้นหาหรือเลือกคลัง<br><small>ค้นหาได้จาก: ชื่อสินค้า, รหัสสินค้า, เลข Lot</small></p>';
        return;
    }
    
    try {
        const params = new URLSearchParams();
        if (searchTerm) params.append('search', searchTerm);
        if (warehouseId) params.append('warehouse_id', warehouseId);
        
        const response = await fetch(`?ajax=search_products&${params}`);
        const products = await response.json();
        
        if (products.length === 0) {
            $productSearchResults.innerHTML = '<p class="text-muted">ไม่พบสินค้าที่มีสต็อก<br><small>ลองค้นหาด้วย: ชื่อสินค้า, รหัสสินค้า, หรือเลข Lot</small></p>';
            return;
        }
        
        let html = '';
        products.forEach(product => {
            const stockClass = product.available_stock <= 0 ? 'stock-danger' : 
                             product.available_stock <= 10 ? 'stock-warning' : '';
            
            html += `
                <div class="product-search-result" onclick="selectProduct(
                    ${product.product_id}, 
                    '${escapeHtml(product.product_name)}', 
                    ${product.available_stock}, 
                    '${escapeHtml(product.warehouse_name)}', 
                    ${product.warehouse_id}, 
                    ${product.location_id || 'null'}, 
                    '${escapeHtml(product.location_code || '')}', 
                    ${product.default_unit_id || 'null'},
                    ${product.available_pallet || 0},
                    ${product.average_cost || 0}
                )">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center mb-2">
                                <strong class="me-2">${escapeHtml(product.product_name)}</strong>
                                <span class="badge bg-${product.available_stock > 10 ? 'success' : product.available_stock > 0 ? 'warning' : 'danger'}">
                                    ${product.available_stock > 10 ? 'มีสต็อก' : product.available_stock > 0 ? 'สต็อกต่ำ' : 'หมดสต็อก'}
                                </span>
                            </div>
                            
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <small class="text-muted d-block">
                                        <i class="fas fa-barcode me-1"></i>
                                        รหัส: ${escapeHtml(product.product_code || 'N/A')}
                                    </small>
                                    <small class="text-muted d-block">
                                        <i class="fas fa-warehouse me-1"></i>
                                        ${escapeHtml(product.warehouse_name)}
                                    </small>
                                </div>
                                <div class="col-md-6">
                                    <small class="text-muted d-block">
                                        <i class="fas fa-map-marker-alt me-1"></i>
                                        ${escapeHtml(product.location_code || 'ไม่ระบุตำแหน่ง')}
                                    </small>
                                    ${product.last_updated ? `<small class="text-muted d-block">
                                        <i class="fas fa-clock me-1"></i>
                                        อัปเดต: ${new Date(product.last_updated).toLocaleDateString('th-TH')}
                                    </small>` : ''}
                                </div>
                            </div>
                            
                            <!-- แสดงข้อมูล Lot ที่พบ -->
                            ${product.lot_info ? `
                            <div class="mt-2">
                                <small class="text-primary">
                                    <i class="fas fa-tags me-1"></i>
                                    <strong>Lot:</strong> ${escapeHtml(product.lot_info)}
                                </small>
                            </div>` : ''}
                        </div>
                        
                        <div class="col-md-4 text-end">
                            <div class="mb-2">
                                <div class="fw-bold text-success">
                                    <i class="fas fa-boxes me-1"></i>
                                    สต็อก: ${parseFloat(product.available_stock).toLocaleString()} ${escapeHtml(product.unit_symbol || product.unit_name || '')}
                                </div>
                                <small class="text-muted">
                                    รวม: ${parseFloat(product.current_stock).toLocaleString()}
                                    ${product.reserved_stock && parseFloat(product.reserved_stock) > 0 ? 
                                        ` | จอง: ${parseFloat(product.reserved_stock).toLocaleString()}` : ''}
                                </small>
                            </div>
                            
                            ${parseFloat(product.available_pallet || 0) > 0 ? `
                            <div class="mb-2">
                                <div class="text-info">
                                    <i class="fas fa-pallet me-1"></i>
                                    พาเลท: ${parseFloat(product.available_pallet).toLocaleString()}
                                </div>
                            </div>` : ''}
                            
                            ${parseFloat(product.average_cost || 0) > 0 ? `
                            <div>
                                <small class="text-muted">
                                    <i class="fas fa-dollar-sign me-1"></i>
                                    ต้นทุน: ฿${parseFloat(product.average_cost).toLocaleString('th-TH', {minimumFractionDigits: 2})}
                                </small>
                            </div>` : ''}
                        </div>
                    </div>
                </div>
            `;
        });
        
        $productSearchResults.innerHTML = html;
        
    } catch (error) {
        console.error('Search error:', error);
        $productSearchResults.innerHTML = '<p class="text-danger">เกิดข้อผิดพลาดในการค้นหา</p>';
    }
}

    function selectProduct(productId, productName, availableStock, warehouseName, warehouseId, locationId, locationCode, defaultUnitId, availablePallet, averageCost) {
        const idx = rowIndex++;
        const card = buildItemCard(idx, {
            product_id: productId,
            product_name: productName,
            available_stock: availableStock,
            warehouse_name: warehouseName,
            warehouse_id: warehouseId,
            location_id: locationId,
            location_code: locationCode,
            default_unit_id: defaultUnitId,
            available_pallet: availablePallet || 0,
            average_cost: averageCost || 0
        });
        
        $itemsCards.appendChild(card);
        productSearchModal.hide();
        $productSearchInput.value = '';
        $productSearchResults.innerHTML = '';
    }

    /** ======= Build Item Card with New Fields ======= */
    function buildItemCard(idx, productData = null) {
        const wrap = document.createElement('div');
        wrap.className = 'item-card';
        wrap.setAttribute('data-row', idx);

        const productInfo = productData ? `
            <div class="col-12">
                <div class="alert alert-info mb-3">
                    <div class="row">
                        <div class="col-md-8">
                            <strong>${escapeHtml(productData.product_name)}</strong><br>
                            <small class="text-muted">
                                <i class="fas fa-warehouse me-1"></i>
                                คลัง: ${escapeHtml(productData.warehouse_name)} 
                                ${productData.location_code ? '- ' + productData.location_code : ''}
                            </small>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="text-success fw-bold">
                                <i class="fas fa-boxes me-1"></i>
                                สต็อก: ${parseFloat(productData.available_stock).toLocaleString()}
                            </div>
                            ${parseFloat(productData.available_pallet) > 0 ? 
                                `<div class="text-info">
                                    <i class="fas fa-pallet me-1"></i>
                                    พาเลท: ${parseFloat(productData.available_pallet).toLocaleString()}
                                </div>` 
                                : ''}
                            ${parseFloat(productData.average_cost) > 0 ? 
                                `<small class="text-muted">
                                    ต้นทุน: ฿${parseFloat(productData.average_cost).toFixed(2)}
                                </small>` 
                                : ''}
                        </div>
                    </div>
                </div>
            </div>
        ` : `
            <div class="col-lg-6">
                <label class="form-label upper">ชื่อสินค้า</label>
                <input type="text" class="form-control" name="items[${idx}][product_name]" placeholder="ใช้ปุ่มค้นหาสินค้า" readonly>
            </div>
        `;

        wrap.innerHTML = `
            <div class="row g-3">
                ${productInfo}
                
                <!-- Hidden fields -->
                <input type="hidden" name="items[${idx}][product_id]" value="${productData?.product_id || ''}">
                <input type="hidden" name="items[${idx}][warehouse_id]" value="${productData?.warehouse_id || ''}">
                <input type="hidden" name="items[${idx}][location_id]" value="${productData?.location_id || ''}">
                <input type="hidden" name="items[${idx}][available_stock]" value="${productData?.available_stock || ''}">
                <input type="hidden" name="items[${idx}][available_pallet]" value="${productData?.available_pallet || ''}">

                <!-- Basic Fields Row 1 -->
                <div class="col-lg-3">
                    <label class="form-label upper">จำนวนที่จ่าย <span class="text-danger">*</span></label>
                    <input type="number" step="0.0001" min="0.0001" max="${productData?.available_stock || ''}" 
                           class="form-control text-end" name="items[${idx}][quantity]" placeholder="0.00" 
                           onchange="validateQuantity(this, ${productData?.available_stock || 0})" required>
                    <small class="text-muted">สูงสุด: ${productData?.available_stock || 0}</small>
                </div>

                <div class="col-lg-2">
                    <label class="form-label upper">หน่วย</label>
                    <select class="form-select unit-select" name="items[${idx}][unit_id]">
                        ${unitsOptionsHtml(UNITS_ALL, productData?.default_unit_id)}
                    </select>
                </div>

                <div class="col-lg-2">
                    <label class="form-label upper">ราคาต่อหน่วย</label>
                    <input type="number" step="0.01" min="0" class="form-control text-end" 
                           name="items[${idx}][unit_cost]" placeholder="0.00" 
                           value="${productData?.average_cost ? parseFloat(productData.average_cost).toFixed(2) : ''}">
                </div>

                <div class="col-lg-2">
                    <label class="form-label upper">สถานะ</label>
                    <select class="form-select" name="items[${idx}][item_status]">
                        ${statusOptionsHtml('COMPLETED')}
                    </select>
                </div>

                <div class="col-lg-3 d-flex align-items-end">
                    <button type="button" class="btn btn-danger btn-sm w-100" onclick="removeItemCard(${idx})">
                        <i class="fas fa-trash"></i> ลบรายการ
                    </button>
                </div>

                <!-- MPR & Tracking Fields -->
                <div class="expanded-fields">
                    <h6 class="text-primary mb-3">
                        <i class="fas fa-file-alt me-2"></i>ข้อมูล MPR และการติดตาม
                    </h6>
                    <div class="row g-3">
                        <div class="col-lg-3">
                            <label class="form-label upper">เลข MPR</label>
                            <input type="text" class="form-control" name="items[${idx}][mpr_number]" placeholder="MPR-XXXXXX">
                        </div>

                        <div class="col-lg-3">
                            <label class="form-label upper">เลข LOT-MPR</label>
                            <input type="text" class="form-control" name="items[${idx}][lot_mpr_number]" placeholder="LOT-MPR-XXXXXX">
                        </div>

                        <div class="col-lg-3">
                            <label class="form-label upper">เลขที่ใบเบิก</label>
                            <input type="text" class="form-control" name="items[${idx}][withdrawal_request_number]" placeholder="WR-XXXXXX">
                        </div>

                        <div class="col-lg-3">
                            <label class="form-label upper">Lot From Supplier <i class="fas fa-info-circle text-info" title="เลือก Lot ที่ต้องการจ่าย"></i></label>
<select class="form-select lot-select" name="items[${idx}][lot_from_supplier]" 
       onchange="updateLotInfo(this, ${idx}); validateLotSelection(this, ${idx});">
    <option value="">-- เลือก Lot --</option>
</select>
                            <div id="lot-info-${idx}" class="mt-1 small text-muted"></div>
                        </div>

                        <div class="col-lg-4">
                            <label class="form-label upper">วันที่รับ</label>
                            <input type="date" class="form-control" name="items[${idx}][receive_date]">
                        </div>

                        <div class="col-lg-4">
                            <label class="form-label upper">วันที่จ่าย (จริง)</label>
                            <input type="date" class="form-control" name="items[${idx}][issue_date_actual]" value="${new Date().toISOString().split('T')[0]}">
                        </div>

                        <div class="col-lg-4">
                            <label class="form-label upper">จ่ายเครื่อง</label>
                            <select class="form-select" name="items[${idx}][machine_destination]" onchange="updateMachineBadge(this, ${idx})">
                                ${machinesOptionsHtml()}
                            </select>
                            <div id="machine-badge-${idx}" class="mt-1"></div>
                        </div>
                    </div>
                </div>

                <!-- Additional Fields -->
                <div class="expanded-fields">
                    <h6 class="text-success mb-3">
                        <i class="fas fa-warehouse me-2"></i>ข้อมูลพาเลทและ Batch
                    </h6>
                    <div class="row g-3">
                        <div class="col-lg-4">
                            <label class="form-label upper">Batch/Lot Internal</label>
                            <input type="text" class="form-control" name="items[${idx}][batch_lot]" placeholder="หมายเลข Batch/Lot ภายใน">
                        </div>

                        <div class="col-lg-4">
                            <label class="form-label upper">จำนวนพาเลท</label>
                            <input type="number" min="0" max="${productData?.available_pallet || ''}" 
                                   class="form-control text-end" name="items[${idx}][pallet_count]" placeholder="0"
                                   onchange="validatePalletCount(this, ${productData?.available_pallet || 0})">
                            <small class="text-muted">สูงสุด: ${productData?.available_pallet || 0} พาเลท</small>
                        </div>

                        <div class="col-lg-4">
                            <label class="form-label upper">หมายเหตุรายการ</label>
                            <input type="text" class="form-control" name="items[${idx}][item_notes]" placeholder="หมายเหตุสำหรับรายการนี้">
                        </div>
                    </div>
                </div>
            </div>
        `;

        setTimeout(() => wireItemEvents(idx), 0);
        return wrap;
    }



    function validateQuantity(input, maxStock) {
        const quantity = parseFloat(input.value || 0);
        if (quantity > maxStock) {
            alert(`จำนวนที่จ่ายเกินสต็อกที่มี (สูงสุด: ${maxStock})`);
            input.value = maxStock;
        }
    }

    function validatePalletCount(input, maxPallet) {
        const palletCount = parseInt(input.value || 0);
        if (palletCount > maxPallet) {
            alert(`จำนวนพาเลทเกินที่มีอยู่ (สูงสุด: ${maxPallet})`);
            input.value = maxPallet;
        }
    }

    function updateMachineBadge(select, idx) {
        const badgeDiv = document.getElementById(`machine-badge-${idx}`);
        const machineCode = select.value;
        
        if (machineCode) {
            const machine = MACHINES.find(m => m.machine_code === machineCode);
            const machineClass = getMachineClass(machineCode);
            badgeDiv.innerHTML = `
                <span class="machine-badge ${machineClass}">
                    ${machineCode} - ${machine ? machine.machine_type : 'OTHER'}
                </span>
            `;
        } else {
            badgeDiv.innerHTML = '';
        }
    }

    /** ======= Wire events for cards ======= */
    function wireItemEvents(idx) {
    const card = $itemsCards.querySelector('.item-card[data-row="'+idx+'"]');
    if (!card) {
        console.error('Card not found for index:', idx);
        return;
    }

        // Load units for product if needed
        const productId = card.querySelector('input[name*="[product_id]"]')?.value;
    const warehouseId = card.querySelector('input[name*="[warehouse_id]"]')?.value;
    const unitSelect = card.querySelector('.unit-select');
    const lotSelect = card.querySelector('.lot-select');
    
    console.log('Wiring events for card:', { idx, productId, warehouseId }); // Debug
    
    if (productId && unitSelect) {
        loadUnitsForProduct(productId, unitSelect);
    }
    
    if (productId && lotSelect) {
        // เพิ่ม delay เล็กน้อยเพื่อให้ DOM render เสร็จก่อน
        setTimeout(() => {
            loadLotsForProduct(productId, warehouseId, lotSelect);
        }, 100);
    }
}
function testLotLoading(productId, warehouseId) {
    console.log('Testing lot loading...', { productId, warehouseId });
    
    fetch(`?ajax=get_lots_for_product&product_id=${productId}${warehouseId ? '&warehouse_id=' + warehouseId : ''}`)
        .then(response => response.text())
        .then(text => {
            console.log('Raw response:', text);
            try {
                const json = JSON.parse(text);
                console.log('Parsed JSON:', json);
            } catch (e) {
                console.error('JSON parse error:', e);
            }
        })
        .catch(error => console.error('Fetch error:', error));
}
    async function loadUnitsForProduct(productId, unitSelect) {
        try {
            const response = await fetch(`?ajax=get_units_for_product&product_id=${productId}`);
            const data = await response.json();
            const units = Array.isArray(data.units) ? data.units : [];
            unitSelect.innerHTML = unitsOptionsHtml(units, data.default_unit_id);
        } catch (error) {
            console.error('Load units error:', error);
        }
    }

    // ปรับปรุงฟังก์ชัน loadLotsForProduct ให้จัดการ error ดีขึ้น
async function loadLotsForProduct(productId, warehouseId, lotSelect) {
    if (!productId) {
        lotSelect.innerHTML = '<option value="">-- เลือก Lot --</option>';
        return;
    }
    
    // แสดง loading state
    lotSelect.innerHTML = '<option value="">กำลังโหลด...</option>';
    lotSelect.disabled = true;
    
    try {
        const params = new URLSearchParams();
        params.append('product_id', productId);
        if (warehouseId) params.append('warehouse_id', warehouseId);
        
        console.log('🔍 กำลังโหลด lots สำหรับ:', { productId, warehouseId });
        
        const response = await fetch(`?ajax=get_lots_for_product&${params}`);
        
        // ตรวจสอบ HTTP status
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // ดึงข้อมูลดิบก่อนแปลง JSON
        const responseText = await response.text();
        console.log('📦 Raw response:', responseText.substring(0, 500) + (responseText.length > 500 ? '...' : ''));
        
        // ตรวจสอบว่าได้ JSON จริงหรือไม่
        if (!responseText.trim()) {
            throw new Error('ได้รับ response ว่างเปล่า');
        }
        
        if (!responseText.trim().startsWith('{') && !responseText.trim().startsWith('[')) {
            console.error('❌ Server returned non-JSON response:', responseText);
            throw new Error('Server ส่ง HTML แทน JSON - ตรวจสอบ PHP errors ใน Console Network tab');
        }
        
        let lots;
        try {
            lots = JSON.parse(responseText);
            console.log('✅ Parsed lots:', lots);
        } catch (parseError) {
            console.error('❌ JSON parse error:', parseError);
            console.error('Raw response that failed to parse:', responseText);
            throw new Error('ไม่สามารถแปลง JSON ได้: ' + parseError.message);
        }
        
        // Reset select และ enable กลับ
        lotSelect.disabled = false;
        lotSelect.innerHTML = '<option value="">-- เลือก Lot --</option>';
        
        // ตรวจสอบว่าเป็น error response หรือไม่
        if (lots.error) {
            console.error('❌ API returned error:', lots.message);
            const errorOption = document.createElement('option');
            errorOption.value = '';
            errorOption.textContent = '-- เกิดข้อผิดพลาด: ' + lots.message + ' --';
            errorOption.disabled = true;
            errorOption.style.color = '#dc3545';
            lotSelect.appendChild(errorOption);
            return;
        }
        
        if (!Array.isArray(lots)) {
            console.error('❌ API response is not an array:', lots);
            const option = document.createElement('option');
            option.value = '';
            option.textContent = '-- ข้อมูลไม่ถูกต้อง --';
            option.disabled = true;
            lotSelect.appendChild(option);
            return;
        }
        
        if (lots.length === 0) {
            console.warn('⚠️ No lots found');
            const option1 = document.createElement('option');
            option1.value = '';
            option1.textContent = '-- ไม่พบข้อมูล Lot --';
            option1.disabled = true;
            option1.style.color = '#6c757d';
            lotSelect.appendChild(option1);
            
            const option2 = document.createElement('option');
            option2.value = 'MANUAL';
            option2.textContent = '➕ เพิ่ม Lot ด้วยตนเอง';
            option2.style.fontStyle = 'italic';
            option2.style.color = '#0066cc';
            lotSelect.appendChild(option2);
            return;
        }
        
        console.log(`✨ Processing ${lots.length} lots`);
        
        // เพิ่ม options สำหรับแต่ละ lot
        lots.forEach((lot, index) => {
            const option = document.createElement('option');
            option.value = lot.batch_lot || `LOT_${index + 1}`;
            
            let label = lot.batch_lot || 'ไม่ระบุ';
            let warnings = [];
            let shouldWarn = false;
            
            // เพิ่มข้อมูล Supplier Lot
            if (lot.supplier_lot_number && lot.supplier_lot_number !== lot.batch_lot) {
                label += ` (${lot.supplier_lot_number})`;
            }
            
            // เพิ่มข้อมูลจำนวนคงเหลือ
            if (lot.quantity_remaining && parseFloat(lot.quantity_remaining) > 0) {
                label += ` - คงเหลือ: ${parseFloat(lot.quantity_remaining).toLocaleString()}`;
            }
            
            // เพิ่มข้อมูลวันหมดอายุ
            if (lot.expiry_date_formatted) {
                label += ` - หมดอายุ: ${lot.expiry_date_formatted}`;
            }
            
            // ตรวจสอบสถานะและกำหนดสี/คำเตือน
            let optionStyle = {};
            
            if (lot.expiry_date) {
                const expiryDate = new Date(lot.expiry_date);
                const now = new Date();
                const daysToExpiry = Math.ceil((expiryDate - now) / (1000 * 60 * 60 * 24));
                
                if (daysToExpiry < 0) {
                    optionStyle.color = '#dc3545';
                    optionStyle.backgroundColor = '#f8d7da';
                    optionStyle.fontWeight = 'bold';
                    warnings.push('หมดอายุแล้ว');
                    shouldWarn = true;
                } else if (daysToExpiry <= 30) {
                    optionStyle.color = '#fd7e14';
                    optionStyle.backgroundColor = '#fff3cd';
                    optionStyle.fontWeight = 'bold';
                    warnings.push('ใกล้หมดอายุ');
                    shouldWarn = true;
                }
            }
            
            // ตรวจสอบสถานะ Lot
            if (lot.lot_status === 'HOLD') {
                optionStyle.color = '#6c757d';
                optionStyle.backgroundColor = '#f8f9fa';
                optionStyle.fontStyle = 'italic';
                warnings.push('ระงับชั่วคราว');
                shouldWarn = true;
            } else if (lot.lot_status === 'BLOCKED') {
                optionStyle.color = '#dc3545';
                optionStyle.backgroundColor = '#f8d7da';
                optionStyle.fontStyle = 'italic';
                warnings.push('ถูกบล็อค');
                shouldWarn = true;
            }
            
            // เพิ่มคำเตือนในชื่อ
            if (warnings.length > 0) {
                label += ` [${warnings.join(', ')}]`;
            }
            
            option.textContent = label;
            
            // เก็บข้อมูล lot ไว้ใน data attribute
            option.setAttribute('data-lot-info', JSON.stringify(lot));
            option.setAttribute('data-has-warning', shouldWarn.toString());
            
            // ใส่ style
            Object.assign(option.style, optionStyle);
            
            lotSelect.appendChild(option);
        });
        
        // เพิ่มตัวเลือกสำหรับเพิ่ม Lot ใหม่
        const manualOption = document.createElement('option');
        manualOption.value = 'MANUAL';
        manualOption.textContent = '➕ เพิ่ม Lot ด้วยตนเอง';
        manualOption.style.fontStyle = 'italic';
        manualOption.style.color = '#0066cc';
        lotSelect.appendChild(manualOption);
        
        console.log(`✅ สำเร็จ: โหลด ${lots.length} lots แล้ว - ทุกตัวเลือกสามารถเลือกได้`);
        
    } catch (error) {
        console.error('❌ Load lots error:', error);
        lotSelect.disabled = false;
        lotSelect.innerHTML = `
            <option value="" disabled style="color: #dc3545;">-- เกิดข้อผิดพลาด: ${error.message} --</option>
            <option value="MANUAL" style="color: #0066cc; font-style: italic;">➕ เพิ่ม Lot ด้วยตนเอง</option>
        `;
        
        // แสดงข้อผิดพลาดในรูปแบบที่เข้าใจง่าย
        console.error('ข้อผิดพลาดสำหรับผู้ใช้:', `ไม่สามารถโหลดข้อมูล Lot ได้: ${error.message}`);
        
        // แจ้งเตือนผู้ใช้
        setTimeout(() => {
            alert(`ไม่สามารถโหลดข้อมูล Lot ได้\n\nข้อผิดพลาด: ${error.message}\n\nกรุณาตรวจสอบ Console หรือติดต่อผู้ดูแลระบบ`);
        }, 500);
    }
}
 // แก้ไขฟังก์ชัน updateLotInfo ให้แสดงคำเตือนที่ชัดเจนขึ้น
function updateLotInfo(select, idx) {
    const lotInfoDiv = document.getElementById(`lot-info-${idx}`);
    const selectedValue = select.value;
    const selectedOption = select.selectedOptions[0];
    
    if (!selectedValue) {
        lotInfoDiv.innerHTML = '';
        return;
    }
    
    // จัดการกรณี MANUAL
    if (selectedValue === 'MANUAL') {
        lotInfoDiv.innerHTML = `
            <div class="alert alert-info p-2 mt-1">
                <small>
                    <i class="fas fa-edit me-1"></i>
                    <strong>โหมดเพิ่ม Lot ด้วยตนเอง:</strong> 
                    กรุณากรอกข้อมูล Batch/Lot ในช่องด้านล่าง
                </small>
            </div>
        `;
        
        // Focus ไปที่ช่อง batch_lot
        const batchLotInput = document.querySelector(`input[name*="[${idx}]"][name*="batch_lot"]`);
        if (batchLotInput) {
            setTimeout(() => batchLotInput.focus(), 100);
        }
        return;
    }
    
    if (!selectedOption || !selectedOption.getAttribute('data-lot-info')) {
        lotInfoDiv.innerHTML = '';
        return;
    }
    
    try {
        const lotData = JSON.parse(selectedOption.getAttribute('data-lot-info'));
        const hasWarning = selectedOption.getAttribute('data-has-warning') === 'true';
        
        let infoHtml = '';
        
        // แสดงคำเตือนถ้ามี (แต่ไม่ป้องกันการเลือก)
        if (hasWarning) {
            let alertClass = 'alert-warning';
            let alertIcon = 'fa-exclamation-triangle';
            let alertTitle = 'คำเตือน';
            let alertMessage = 'กรุณาตรวจสอบความเหมาะสมก่อนการใช้งาน';
            
            // ตรวจสอบประเภทคำเตือนเพื่อแสดงข้อความที่เหมาะสม
            if (lotData.expiry_date) {
                const expiryDate = new Date(lotData.expiry_date);
                const now = new Date();
                const daysToExpiry = Math.ceil((expiryDate - now) / (1000 * 60 * 60 * 24));
                
                if (daysToExpiry < 0) {
                    alertClass = 'alert-danger';
                    alertIcon = 'fa-exclamation-circle';
                    alertTitle = '⚠️ สินค้าหมดอายุ';
                    alertMessage = `สินค้านี้หมดอายุไปแล้ว ${Math.abs(daysToExpiry)} วัน - ใช้ด้วยความระมัดระวัง`;
                } else if (daysToExpiry <= 30) {
                    alertClass = 'alert-warning';
                    alertIcon = 'fa-clock';
                    alertTitle = '⏰ ใกล้หมดอายุ';
                    alertMessage = `สินค้าจะหมดอายุในอีก ${daysToExpiry} วัน`;
                }
            }
            
            if (lotData.lot_status === 'BLOCKED') {
                alertClass = 'alert-danger';
                alertIcon = 'fa-lock';
                alertTitle = '🚫 Lot ถูกบล็อก';
                alertMessage = 'Lot นี้ถูกบล็อกการใช้งาน - ตรวจสอบก่อนใช้';
            } else if (lotData.lot_status === 'HOLD') {
                alertClass = 'alert-warning';
                alertIcon = 'fa-pause-circle';
                alertTitle = '⏸️ Lot ถูกระงับ';
                alertMessage = 'Lot นี้ถูกระงับการใช้งานชั่วคราว';
            }
            
            infoHtml += `
                <div class="alert ${alertClass} p-2 mt-1">
                    <small>
                        <i class="fas ${alertIcon} me-1"></i>
                        <strong>${alertTitle}:</strong><br>
                        ${alertMessage}
                    </small>
                </div>
            `;
        }
        
        // แสดงข้อมูล Lot
        infoHtml += '<div class="lot-details p-2 mt-1 bg-light border rounded">';
        
        if (lotData.supplier_lot_number && lotData.supplier_lot_number !== lotData.batch_lot) {
            infoHtml += `<div><i class="fas fa-truck text-primary me-1"></i><strong>Supplier Lot:</strong> ${lotData.supplier_lot_number}</div>`;
        }
        
        if (lotData.quantity_remaining) {
            const qtyColor = parseFloat(lotData.quantity_remaining) > 0 ? 'text-success' : 'text-danger';
            infoHtml += `<div><i class="fas fa-boxes ${qtyColor} me-1"></i><strong>คงเหลือ:</strong> ${parseFloat(lotData.quantity_remaining).toLocaleString()} หน่วย</div>`;
        }
        
        if (lotData.manufacturing_date_formatted) {
            infoHtml += `<div><i class="fas fa-calendar-alt text-info me-1"></i><strong>วันผลิต:</strong> ${lotData.manufacturing_date_formatted}</div>`;
        }
        
        if (lotData.expiry_date_formatted) {
            const expiryDate = new Date(lotData.expiry_date);
            const now = new Date();
            const daysToExpiry = Math.ceil((expiryDate - now) / (1000 * 60 * 60 * 24));
            
            let expiryClass = 'text-muted';
            let expiryIcon = 'fa-calendar-check';
            let statusText = '';
            
            if (daysToExpiry < 0) {
                expiryClass = 'text-danger';
                expiryIcon = 'fa-exclamation-triangle';
                statusText = ` (หมดอายุแล้ว ${Math.abs(daysToExpiry)} วัน)`;
            } else if (daysToExpiry <= 30) {
                expiryClass = 'text-warning';
                expiryIcon = 'fa-calendar-times';
                statusText = ` (อีก ${daysToExpiry} วัน)`;
            } else {
                statusText = ` (อีก ${daysToExpiry} วัน)`;
            }
            
            infoHtml += `<div><i class="fas ${expiryIcon} ${expiryClass} me-1"></i><strong>วันหมดอายุ:</strong> <span class="${expiryClass}">${lotData.expiry_date_formatted}${statusText}</span></div>`;
        }
        
        if (lotData.last_movement_date_formatted) {
            infoHtml += `<div><i class="fas fa-history text-muted me-1"></i><strong>เคลื่อนไหวล่าสุด:</strong> ${lotData.last_movement_date_formatted}</div>`;
        }
        
        if (lotData.lot_status && lotData.lot_status !== 'ACTIVE') {
            let statusClass = 'text-warning';
            let statusIcon = 'fa-pause';
            
            if (lotData.lot_status === 'BLOCKED') {
                statusClass = 'text-danger';
                statusIcon = 'fa-lock';
            } else if (lotData.lot_status === 'HOLD') {
                statusClass = 'text-warning';
                statusIcon = 'fa-hand-paper';
            }
            
            infoHtml += `<div><i class="fas ${statusIcon} ${statusClass} me-1"></i><strong>สถานะ:</strong> <span class="${statusClass}">${lotData.lot_status}</span></div>`;
        }
        
        infoHtml += '</div>';
        lotInfoDiv.innerHTML = infoHtml;
        
        // Auto-fill batch_lot field
        const batchLotInput = document.querySelector(`input[name*="[${idx}]"][name*="batch_lot"]`);
        if (batchLotInput && !batchLotInput.value) {
            batchLotInput.value = lotData.batch_lot || selectedValue;
        }
        
        // แสดง confirmation dialog สำหรับ Lot ที่มีปัญหา
        if (hasWarning) {
            setTimeout(() => {
                showLotWarningConfirmation(lotData, selectedValue);
            }, 500);
        }
        
    } catch (error) {
        console.error('Error parsing lot info:', error);
        lotInfoDiv.innerHTML = '<div class="text-muted small">ไม่สามารถแสดงข้อมูล Lot ได้</div>';
    }
}

function showLotWarningConfirmation(lotData, selectedValue) {
    let warningMessage = `คุณเลือก Lot: ${selectedValue}\n\n`;
    
    if (lotData.expiry_date) {
        const expiryDate = new Date(lotData.expiry_date);
        const now = new Date();
        const daysToExpiry = Math.ceil((expiryDate - now) / (1000 * 60 * 60 * 24));
        
        if (daysToExpiry < 0) {
            warningMessage += `⚠️ สินค้าหมดอายุแล้ว ${Math.abs(daysToExpiry)} วัน\n`;
        } else if (daysToExpiry <= 30) {
            warningMessage += `⏰ สินค้าจะหมดอายุในอีก ${daysToExpiry} วัน\n`;
        }
    }
    
    if (lotData.lot_status === 'HOLD') {
        warningMessage += '⏸️ Lot ถูกระงับการใช้งานชั่วคราว\n';
    } else if (lotData.lot_status === 'BLOCKED') {
        warningMessage += '🚫 Lot ถูกบล็อกการใช้งาน\n';
    }
    
    warningMessage += '\nโปรดตรวจสอบความเหมาะสมก่อนการใช้งาน';
    
    // แสดงคำเตือนแต่ไม่บังคับให้ยกเลิก
    alert(warningMessage);
}

function validateLotSelection(select, idx) {
    const selectedOption = select.selectedOptions[0];
    
    if (!selectedOption) return true;
    
    const hasWarning = selectedOption.getAttribute('data-has-warning') === 'true';
    
    if (hasWarning) {
        const lotData = JSON.parse(selectedOption.getAttribute('data-lot-info') || '{}');
        
        let warningMessage = 'คุณกำลังเลือก Lot ที่มีปัญหา:\n\n';
        
        if (lotData.expiry_date) {
            const expiryDate = new Date(lotData.expiry_date);
            const now = new Date();
            const daysToExpiry = Math.ceil((expiryDate - now) / (1000 * 60 * 60 * 24));
            
            if (daysToExpiry < 0) {
                warningMessage += `• สินค้าหมดอายุแล้ว ${Math.abs(daysToExpiry)} วัน\n`;
            } else if (daysToExpiry <= 30) {
                warningMessage += `• สินค้าจะหมดอายุในอีก ${daysToExpiry} วัน\n`;
            }
        }
        
        if (lotData.lot_status === 'HOLD') {
            warningMessage += '• Lot ถูกระงับการใช้งานชั่วคราว\n';
        } else if (lotData.lot_status === 'BLOCKED') {
            warningMessage += '• Lot ถูกบล็อกการใช้งาน\n';
        }
        
        warningMessage += '\nต้องการดำเนินการต่อหรือไม่?';
        
        return confirm(warningMessage);
    }
    
    return true;
}
    /** ======= Save Direct Issue (Updated) ======= */
    async function saveDirectIssue() {
        // Validate form
        const issueNumber = document.querySelector('input[name="issue_number"]').value;
        const issueDate = document.querySelector('input[name="issue_date"]').value;
        const department = document.querySelector('input[name="department"]').value;
        const requester = document.querySelector('input[name="requester"]').value;
        const issueReason = document.querySelector('input[name="issue_reason"]').value;
        const notes = document.querySelector('textarea[name="notes"]').value;

        if (!issueNumber || !issueDate) {
            alert('กรุณากรอกเลขที่เอกสารและวันที่จ่ายออก');
            return;
        }

        // Collect items with new fields
        const itemCards = document.querySelectorAll('.item-card');
        const items = [];
        let hasValidItems = false;

        itemCards.forEach((card, index) => {
            const productId = card.querySelector('input[name*="[product_id]"]').value;
            const productName = card.querySelector('input[name*="[product_name]"]')?.value || '';
            const quantity = card.querySelector('input[name*="[quantity]"]').value;
            const unitId = card.querySelector('select[name*="[unit_id]"]').value;
            const unitCost = card.querySelector('input[name*="[unit_cost]"]').value;
            const warehouseId = card.querySelector('input[name*="[warehouse_id]"]').value;
            const locationId = card.querySelector('input[name*="[location_id]"]').value;
            const batchLot = card.querySelector('input[name*="[batch_lot]"]').value;
            const palletCount = card.querySelector('input[name*="[pallet_count]"]').value;
            const itemNotes = card.querySelector('input[name*="[item_notes]"]').value;
            
            // New fields
            const mprNumber = card.querySelector('input[name*="[mpr_number]"]').value;
            const lotMprNumber = card.querySelector('input[name*="[lot_mpr_number]"]').value;
            const withdrawalRequestNumber = card.querySelector('input[name*="[withdrawal_request_number]"]').value;
            const receiveDate = card.querySelector('input[name*="[receive_date]"]').value;
            const issueDateActual = card.querySelector('input[name*="[issue_date_actual]"]').value;
            const lotFromSupplier = card.querySelector('select[name*="[lot_from_supplier]"]').value;
            const itemStatus = card.querySelector('select[name*="[item_status]"]').value;
            const machineDestination = card.querySelector('select[name*="[machine_destination]"]').value;

            if (productId && quantity && parseFloat(quantity) > 0) {
                hasValidItems = true;
                items.push({
                    product_id: parseInt(productId),
                    product_name: productName,
                    quantity: parseFloat(quantity),
                    unit_id: unitId ? parseInt(unitId) : null,
                    unit_cost: unitCost ? parseFloat(unitCost) : 0,
                    warehouse_id: warehouseId ? parseInt(warehouseId) : null,
                    location_id: locationId ? parseInt(locationId) : null,
                    batch_lot: batchLot || null,
                    pallet_count: palletCount ? parseInt(palletCount) : 0,
                    item_notes: itemNotes || null,
                    
                    // New fields
                    mpr_number: mprNumber || null,
                    lot_mpr_number: lotMprNumber || null,
                    withdrawal_request_number: withdrawalRequestNumber || null,
                    receive_date: receiveDate || null,
                    issue_date_actual: issueDateActual || null,
                    lot_from_supplier: lotFromSupplier || null,
                    item_status: itemStatus || 'COMPLETED',
                    machine_destination: machineDestination || null
                });
            }
        });

        if (!hasValidItems) {
            alert('กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ');
            return;
        }

        // Show loading
        const saveBtn = document.getElementById('btnSaveIssue');
        const originalText = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังบันทึก...';

        try {
            const formData = new URLSearchParams();
            formData.append('ajax', 'save_direct_issue');
            formData.append('issue_number', issueNumber);
            formData.append('issue_date', issueDate);
            formData.append('department', department);
            formData.append('requester', requester);
            formData.append('issue_reason', issueReason);
            formData.append('notes', notes);

            // Add items with all new fields
            items.forEach((item, index) => {
                Object.keys(item).forEach(key => {
                    if (item[key] !== null && item[key] !== undefined) {
                        formData.append(`items[${index}][${key}]`, item[key]);
                    }
                });
            });

            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            });

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const htmlText = await response.text();
                console.error('Server returned HTML:', htmlText.substring(0, 500));
                alert('เกิด PHP Error - ดู Console สำหรับรายละเอียด');
                return;
            }

            const result = await response.json();
            console.log('Save result:', result);

            if (result.success) {
                let successMessage = 'บันทึกการจ่ายออกสำเร็จ!\n\n';
                successMessage += 'เลขที่เอกสาร: ' + result.issue_number + '\n';
                successMessage += 'จำนวนรายการ: ' + result.total_items + '\n';
                successMessage += 'จำนวนรวม: ' + result.total_quantity + '\n';
                successMessage += 'มูลค่าประมาณ: ฿' + parseFloat(result.estimated_value).toLocaleString('th-TH', {minimumFractionDigits: 2});

                alert(successMessage);

                if (confirm('ต้องการสร้างใบจ่ายออกใหม่หรือไม่?\n\nตกลง = สร้างใหม่\nยกเลิก = กลับไปหน้าสต็อก')) {
                    window.location.reload();
                } else {
                    window.location.href = 'inventory.php';
                }
            } else {
                alert('เกิดข้อผิดพลาด: ' + result.message);
            }

        } catch (error) {
            console.error('Save error:', error);
            alert('เกิดข้อผิดพลาด: ' + error.message);
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalText;
        }
    }
/** ======= Actions ======= */
    function addItemCard() {
        const idx = rowIndex++;
        const card = buildItemCard(idx);
        $itemsCards.appendChild(card);
    }

    function removeItemCard(idx) {
        const card = $itemsCards.querySelector('.item-card[data-row="'+idx+'"]');
        if (card) {
            card.remove();
        }
    }
    /** ======= Initialize ======= */
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize modal
        productSearchModal = new bootstrap.Modal(document.getElementById('productSearchModal'));

        // Event listeners
        $btnSearchProduct.addEventListener('click', () => productSearchModal.show());
        $btnAddCard.addEventListener('click', addItemCard);
        document.getElementById('btnSaveIssue').addEventListener('click', saveDirectIssue);

        // Product search
        $productSearchInput.addEventListener('input', debounce(searchProducts, 300));
        $productSearchWarehouse.addEventListener('change', searchProducts);

        // Auto-sync warehouse filter
        document.getElementById('warehouseFilter').addEventListener('change', function() {
            $productSearchWarehouse.value = this.value;
        });
    });

    // Debounce function
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    </script>
</body>
</html>