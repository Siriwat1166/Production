<?php
// dispatch_goods.php - หน้าจ่ายออกสินค้า (Multi-Lot Support)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    die("Database connection failed: " . $e->getMessage());
}

// ===== AJAX HANDLERS =====
if (isset($_GET['ajax']) || isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();

    try {
        $action = $_GET['ajax'] ?? $_POST['action'] ?? '';

        switch ($action) {
            case 'search_product':
                $code = trim($_GET['code'] ?? '');
                error_log("Searching for product: " . $code);
                
                if (!$code) {
                    echo json_encode(['found' => false, 'message' => 'กรุณาระบุรหัสสินค้า']);
                    exit;
                }

                try {
                    $stmt = $pdo->prepare("
                        SELECT TOP 1 
                            p.id AS product_id,
                            p.Name AS product_name,
                            p.SSP_Code AS product_code,
                            p.Unit_id AS unit_id,
                            u.unit_name,
                            u.unit_symbol
                        FROM Master_Products_ID p
                        LEFT JOIN Units u ON p.Unit_id = u.unit_id
                        WHERE p.is_active = 1 AND p.SSP_Code = ?
                    ");
                    $stmt->execute([$code]);
                    $product = $stmt->fetch();

                    if (!$product) {
                        echo json_encode(['found' => false, 'message' => 'ไม่พบสินค้า']);
                        exit;
                    }

                    echo json_encode(['found' => true, 'product' => $product]);
                } catch (Exception $e) {
                    error_log("Search error: " . $e->getMessage());
                    echo json_encode(['found' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
                }
                break;

            case 'get_warehouses_with_stock':
                $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
                if ($productId <= 0) {
                    echo json_encode([]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    SELECT 
    w.warehouse_id, 
    w.warehouse_name,
    -- ✅ จำนวนรวมจาก Inventory_Stock
    SUM(ist.current_stock) as total_stock,
    -- ✅ หน่วยที่ใช้งานจริง
    (SELECT TOP 1 ISNULL(u.unit_name_th, u.unit_name)
     FROM Stock_Movements sm2
     LEFT JOIN Units u ON sm2.unit_id = u.unit_id
     WHERE sm2.product_id = ist.product_id
       AND sm2.warehouse_id = ist.warehouse_id
     ORDER BY sm2.movement_date DESC
    ) as unit_name,
    -- ✅ คำนวณจำนวนกระป๋อง/แผ่นจาก SUM(received_package_qty)
    (SELECT SUM(CASE 
            WHEN sm3.movement_type = 'IN' THEN ISNULL(sm3.received_package_qty, 0)
            WHEN sm3.movement_type = 'OUT' THEN -ISNULL(sm3.received_package_qty, 0)
            ELSE 0 
        END)
     FROM Stock_Movements sm3
     WHERE sm3.product_id = ist.product_id
       AND sm3.warehouse_id = ist.warehouse_id
       AND sm3.received_package_qty IS NOT NULL
       AND sm3.received_package_qty != 0
    ) as total_pallet
FROM Inventory_Stock ist
INNER JOIN Warehouses w ON ist.warehouse_id = w.warehouse_id
WHERE ist.product_id = ? 
AND ist.available_stock > 0
AND (w.is_active = 1 OR w.is_active IS NULL)
GROUP BY w.warehouse_id, w.warehouse_name, ist.product_id, ist.warehouse_id
HAVING SUM(ist.current_stock) > 0
ORDER BY w.warehouse_name
                ");
                $stmt->execute([$productId]);
                echo json_encode($stmt->fetchAll());
                break;

            case 'get_machines':
                try {
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT 
                            machine_code,
                            machine_code as machine_name
                        FROM [Production].[dbo].[Machine_Destinations]
                        WHERE machine_code IS NOT NULL 
                        AND machine_code != ''
                        ORDER BY machine_code
                    ");
                    $stmt->execute();
                    $machines = $stmt->fetchAll();
                    
                    error_log("Found machines: " . count($machines));
                    echo json_encode($machines);
                } catch (Exception $e) {
                    error_log("Error in get_machines: " . $e->getMessage());
                    echo json_encode(['error' => $e->getMessage()]);
                }
                break;

            // ดึงรายการ Lot ที่ไม่ซ้ำกัน สำหรับ dropdown
            case 'get_unique_lots':
                $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
                $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
                
                if ($productId <= 0 || $warehouseId <= 0) {
                    echo json_encode([]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    WITH LatestLots AS (
                        SELECT DISTINCT
                            i.product_id,
                            i.warehouse_id,
                            i.location_id,
                            (SELECT TOP 1 sm.batch_lot 
                             FROM Stock_Movements sm 
                             WHERE sm.product_id = i.product_id 
                               AND sm.warehouse_id = i.warehouse_id 
                               AND sm.location_id = i.location_id
                               AND sm.batch_lot IS NOT NULL
                               AND sm.batch_lot != ''
                             ORDER BY sm.movement_date DESC
                            ) as batch_lot,
                            i.current_stock
                        FROM Inventory_Stock i
                        WHERE i.product_id = ? 
                          AND i.warehouse_id = ?
                          AND i.available_stock > 0
                    )
                    SELECT 
                        batch_lot,
                        COUNT(DISTINCT location_id) as location_count,
                        SUM(current_stock) as total_available_qty,
                        -- คำนวณ package qty จาก Stock_Movements
                        (SELECT SUM(CASE 
                                WHEN sm2.movement_type = 'IN' THEN ISNULL(sm2.received_package_qty, 0)
                                WHEN sm2.movement_type = 'OUT' THEN -ISNULL(sm2.received_package_qty, 0)
                                ELSE 0 
                            END)
                         FROM Stock_Movements sm2
                         WHERE sm2.product_id = LatestLots.product_id
                           AND sm2.warehouse_id = LatestLots.warehouse_id
                           AND sm2.batch_lot = LatestLots.batch_lot
                           AND sm2.received_package_qty IS NOT NULL
                           AND sm2.received_package_qty != 0
                        ) as total_available_pallet
                    FROM LatestLots
                    WHERE batch_lot IS NOT NULL
                    GROUP BY batch_lot, product_id, warehouse_id
                    HAVING SUM(current_stock) > 0
                    ORDER BY batch_lot DESC
                ");
                $stmt->execute([$productId, $warehouseId]);
                echo json_encode($stmt->fetchAll());
                break;

            // ดึง Location ตาม Lot ที่เลือก
            case 'get_locations_by_selected_lot':
                $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
                $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
                $batchLot = isset($_GET['batch_lot']) ? trim($_GET['batch_lot']) : '';
                
                if ($productId <= 0 || $warehouseId <= 0 || empty($batchLot)) {
                    echo json_encode([]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    SELECT 
                        i.location_id,
                        ISNULL(wl.location_code, 'LOC-' + CAST(i.location_id AS VARCHAR(10))) as location_code,
                        -- ✅ ดึง batch_lot จาก Stock_Movements
                        (SELECT TOP 1 sm2.batch_lot 
                         FROM Stock_Movements sm2 
                         WHERE sm2.product_id = i.product_id 
                           AND sm2.warehouse_id = i.warehouse_id 
                           AND sm2.location_id = i.location_id
                           AND sm2.batch_lot = ?
                         ORDER BY sm2.movement_date DESC
                        ) as batch_lot,
                        -- ✅ ดึง unit จาก Stock_Movements
                        (SELECT TOP 1 sm2.unit_id 
                         FROM Stock_Movements sm2 
                         WHERE sm2.product_id = i.product_id 
                           AND sm2.warehouse_id = i.warehouse_id 
                           AND sm2.location_id = i.location_id
                         ORDER BY sm2.movement_date DESC
                        ) as unit_id,
                        -- ✅ ดึง unit_name
                        (SELECT TOP 1 ISNULL(u.unit_name_th, u.unit_name)
                         FROM Stock_Movements sm2
                         LEFT JOIN Units u ON sm2.unit_id = u.unit_id
                         WHERE sm2.product_id = i.product_id
                           AND sm2.warehouse_id = i.warehouse_id
                           AND sm2.location_id = i.location_id
                         ORDER BY sm2.movement_date DESC
                        ) as unit_name,
                        -- ✅ ดึง unit_symbol
                        (SELECT TOP 1 u.unit_symbol
                         FROM Stock_Movements sm2
                         LEFT JOIN Units u ON sm2.unit_id = u.unit_id
                         WHERE sm2.product_id = i.product_id
                           AND sm2.warehouse_id = i.warehouse_id
                           AND sm2.location_id = i.location_id
                         ORDER BY sm2.movement_date DESC
                        ) as unit_symbol,
                        -- ✅ ใช้ current_stock จาก Inventory_Stock
                        i.current_stock as available_qty,
                        i.current_pallet as available_pallet,
                        -- ✅ คำนวณ available_package_qty จาก Stock_Movements
                        (SELECT SUM(CASE 
                                WHEN sm3.movement_type = 'IN' THEN ISNULL(sm3.received_package_qty, 0)
                                WHEN sm3.movement_type = 'OUT' THEN -ISNULL(sm3.received_package_qty, 0)
                                ELSE 0 
                            END)
                         FROM Stock_Movements sm3
                         WHERE sm3.product_id = i.product_id
                           AND sm3.warehouse_id = i.warehouse_id
                           AND sm3.location_id = i.location_id
                           AND sm3.batch_lot = ?
                           AND sm3.received_package_qty IS NOT NULL
                           AND sm3.received_package_qty != 0
                        ) as available_package_qty
                    FROM Inventory_Stock i
                    LEFT JOIN Warehouse_Locations wl ON i.location_id = wl.location_id
                    WHERE i.product_id = ? 
                    AND i.warehouse_id = ?
                    AND i.available_stock > 0
                    AND EXISTS (
                        SELECT 1 FROM Stock_Movements sm4
                        WHERE sm4.product_id = i.product_id
                          AND sm4.warehouse_id = i.warehouse_id
                          AND sm4.location_id = i.location_id
                          AND sm4.batch_lot = ?
                    )
                    ORDER BY wl.location_code
                ");
                $stmt->execute([$batchLot, $batchLot, $productId, $warehouseId, $batchLot]);
                echo json_encode($stmt->fetchAll());
                break;


            // ดึง Lot ทั้งหมดของสินค้า (ทุก Location ในคลังที่เลือก)
            case 'get_all_lots':
                $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
                $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
                
                if ($productId <= 0 || $warehouseId <= 0) {
                    echo json_encode([]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    SELECT 
                        i.location_id,
                        ISNULL(wl.location_code, 'LOC-' + CAST(i.location_id AS VARCHAR(10))) as location_code,
                        sm.batch_lot,
                        sm.unit_id,
                        u.unit_name,
                        u.unit_symbol,
                        SUM(CASE 
                            WHEN sm.movement_type = 'IN' THEN sm.quantity 
                            WHEN sm.movement_type = 'OUT' THEN -sm.quantity 
                            ELSE 0 
                        END) as available_qty,
                        SUM(CASE 
                            WHEN sm.movement_type = 'IN' THEN ISNULL(sm.quantity_pallet, 0)
                            WHEN sm.movement_type = 'OUT' THEN -ISNULL(sm.quantity_pallet, 0)
                            ELSE 0 
                        END) as available_pallet,
                        SUM(CASE 
                            WHEN sm.movement_type = 'IN' THEN ISNULL(sm.received_package_qty, 0)
                            WHEN sm.movement_type = 'OUT' THEN -ISNULL(sm.received_package_qty, 0)
                            ELSE 0 
                        END) as available_package_qty
                    FROM Inventory_Stock i
                    LEFT JOIN Warehouse_Locations wl ON i.location_id = wl.location_id
                    INNER JOIN Stock_Movements sm ON sm.product_id = i.product_id 
                        AND sm.warehouse_id = i.warehouse_id 
                        AND sm.location_id = i.location_id
                    LEFT JOIN Units u ON sm.unit_id = u.unit_id
                    WHERE i.product_id = ? 
                    AND i.warehouse_id = ?
                    AND i.available_stock > 0
                    AND sm.batch_lot IS NOT NULL 
                    AND sm.batch_lot != ''
                    GROUP BY i.location_id, wl.location_code, sm.batch_lot, sm.unit_id, u.unit_name, u.unit_symbol
                    HAVING SUM(CASE 
                        WHEN sm.movement_type = 'IN' THEN sm.quantity 
                        WHEN sm.movement_type = 'OUT' THEN -sm.quantity 
                        ELSE 0 
                    END) > 0
                    ORDER BY wl.location_code, sm.batch_lot DESC
                ");
                $stmt->execute([$productId, $warehouseId]);
                echo json_encode($stmt->fetchAll());
                break;

            case 'get_lots_by_location':
                $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
                $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
                $locationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
                
                if ($productId <= 0 || $warehouseId <= 0 || $locationId <= 0) {
                    echo json_encode([]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    SELECT DISTINCT
                        batch_lot,
                        SUM(CASE 
                            WHEN movement_type = 'IN' THEN quantity 
                            WHEN movement_type = 'OUT' THEN -quantity 
                            ELSE 0 
                        END) as remaining_qty
                    FROM Stock_Movements
                    WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
                    AND batch_lot IS NOT NULL AND batch_lot != ''
                    GROUP BY batch_lot
                    HAVING SUM(CASE 
                            WHEN movement_type = 'IN' THEN quantity 
                            WHEN movement_type = 'OUT' THEN -quantity 
                            ELSE 0 
                        END) > 0
                    ORDER BY batch_lot DESC
                ");
                $stmt->execute([$productId, $warehouseId, $locationId]);
                echo json_encode($stmt->fetchAll());
                break;

            case 'get_locations':
                $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
                $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
                
                if ($warehouseId <= 0 || $productId <= 0) {
                    echo json_encode([]);
                    exit;
                }

                try {
                    $stmt = $pdo->prepare("
                        SELECT 
                            i.location_id,
                            ISNULL(wl.location_code, 'LOC-' + CAST(i.location_id AS VARCHAR(10))) as location_code,
                            ISNULL(wl.zone, 0) as zone,
                            SUM(i.available_stock) as available_stock,
                            SUM(i.available_pallet) as available_pallet
                        FROM Inventory_Stock i
                        LEFT JOIN Warehouse_Locations wl ON i.location_id = wl.location_id
                        WHERE i.warehouse_id = ?
                        AND i.product_id = ?
                        AND i.available_stock > 0
                        GROUP BY i.location_id, wl.location_code, wl.zone
                        HAVING SUM(i.available_stock) > 0
                        ORDER BY wl.zone, wl.location_code
                    ");
                    $stmt->execute([$warehouseId, $productId]);
                    $locations = $stmt->fetchAll();
                    
                    error_log("Found locations: " . count($locations) . " for product_id: $productId, warehouse_id: $warehouseId");
                    
                    echo json_encode($locations);
                } catch (Exception $e) {
                    error_log("Error in get_locations: " . $e->getMessage());
                    echo json_encode(['error' => $e->getMessage()]);
                }
                break;

           // บันทึก Multi-Lot Dispatch
            case 'save_multi_dispatch':
                $body = file_get_contents('php://input');
                $data = json_decode($body, true);

                if (!is_array($data) || empty($data['items'])) {
                    echo json_encode(['success' => false, 'error' => 'ไม่มีรายการที่จะจ่ายออก']);
                    exit;
                }

                $pdo->beginTransaction();
                try {
                    $userId = $_SESSION['user_id'] ?? 1;
                    $commonData = $data['common'] ?? [];
                    $items = $data['items'];
                    $successCount = 0;

                    foreach ($items as $item) {
                        $productId = (int)$item['product_id'];
                        $warehouseId = (int)$item['warehouse_id'];
                        $locationId = (int)$item['location_id'];
                        $quantity = (int)$item['quantity'];
                        $quantityKg = (int)($item['quantity_kg'] ?? 0);
                        $palletCount = (int)($item['pallet_count'] ?? 0);
                        $lotSupplier = $item['lot_supplier'] ?? null;
                        
                        // ใช้ข้อมูลเฉพาะของแต่ละ Lot
                        $mpr = $item['mpr'] ?? '';
                        $lotMpr = $item['lot_mpr'] ?? '';
                        $requisition = $item['requisition'] ?? '';
                        $destinationLocation = $item['destination_location'] ?? '';
                        $paperboardType = $item['paperboard_type'] ?? '';
                        $machine = $item['machine'] ?? '';

                        if ($quantity <= 0) continue;

                        // ตรวจสอบสต็อก
                        $stmt = $pdo->prepare("
                            SELECT location_id, available_stock, available_pallet
                            FROM Inventory_Stock
                            WHERE product_id = ? AND warehouse_id = ? AND location_id = ? AND available_stock > 0
                        ");
                        $stmt->execute([$productId, $warehouseId, $locationId]);
                        $stock = $stmt->fetch();

                        if (!$stock) {
                            throw new Exception("ไม่พบสต็อกใน Location ID: $locationId");
                        }

                        if ((int)$stock['available_stock'] < $quantity) {
                            throw new Exception("สต็อกไม่เพียงพอสำหรับ Lot: $lotSupplier (ต้องการ $quantity, มี {$stock['available_stock']})");
                        }

                        // ลดสต็อก
                        $stmt = $pdo->prepare("
                            UPDATE Inventory_Stock
                            SET current_stock = current_stock - ?,
                                available_stock = available_stock - ?,
                                current_pallet = current_pallet - ?,
                                available_pallet = available_pallet - ?,
                                last_updated = GETDATE(),
                                last_movement_date = GETDATE()
                            WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
                        ");
                        $stmt->execute([
                            $quantity, $quantity, $palletCount, $palletCount,
                            $productId, $warehouseId, $locationId
                        ]);

                        // แปลงรูปแบบวันที่
                        $dispatchDate = date('Y-m-d H:i:s');
                        if (!empty($commonData['dispatch_date'])) {
                            $dispatchDate = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $commonData['dispatch_date'])));
                        }

                        // บันทึก Movement พร้อมคอลัมน์ใหม่
                        $notes = "Dispatch | MPR: $mpr | Lot MPR: $lotMpr | Req: $requisition | To: $destinationLocation | Type: $paperboardType | Machine: $machine";
                        
                        // ✅ เช็คว่าสินค้านี้มี received_package_qty หรือไม่
                        $checkPackageQty = $pdo->prepare("
                            SELECT TOP 1 received_package_qty, package_weight_per_unit
                            FROM Stock_Movements
                            WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
                              AND batch_lot = ?
                              AND received_package_qty IS NOT NULL
                              AND received_package_qty > 0
                            ORDER BY movement_date DESC
                        ");
                        $checkPackageQty->execute([$productId, $warehouseId, $locationId, $lotSupplier]);
                        $packageData = $checkPackageQty->fetch();
                        
                        $dispatchPackageQty = null;
                        $packageWeight = null;
                        
                        if ($packageData) {
                            // ✅ มี package data = หมึก/กระป๋อง
                            $packageWeight = $packageData['package_weight_per_unit'];
                            if ($packageWeight > 0) {
                                // คำนวณจำนวนกระป๋อง/แผ่น จากจำนวนที่จ่ายออก
                                $dispatchPackageQty = $quantity / $packageWeight;
                            }
                        }
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO Stock_Movements (
                                product_id, warehouse_id, location_id,
                                movement_type, quantity, unit_id,
                                reference_type, batch_lot,
                                movement_date, created_by, notes, 
                                quantity_pallet, quantity_kg,
                                destination_location, paperboard_type, machine_code,
                                received_package_qty, package_weight_per_unit
                            ) VALUES (?, ?, ?, 'OUT', ?, ?, 'DISPATCH', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $productId, $warehouseId, $locationId,
                            $quantity, $item['unit_id'] ?? null, $lotSupplier,
                            $dispatchDate, $userId, $notes,
                            $palletCount, $quantityKg,
                            $destinationLocation, $paperboardType, $machine,
                            $dispatchPackageQty, $packageWeight
                        ]);

                        $successCount++;
                    }

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => "จ่ายออกสำเร็จ $successCount รายการ"]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Multi Dispatch Error: " . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            // Single dispatch (เดิม) - เก็บไว้สำหรับ backward compatibility
            case 'save_dispatch':
                $body = file_get_contents('php://input');
                $data = json_decode($body, true);

                if (!is_array($data)) {
                    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
                    exit;
                }

                $pdo->beginTransaction();
                try {
                    if (empty($data['product_id']) || empty($data['quantity'])) {
                        throw new Exception('ข้อมูลไม่ครบถ้วน');
                    }

                    $productId = (int)$data['product_id'];
                    $warehouseId = (int)$data['warehouse_id'];
                    $quantity = (float)$data['quantity'];
                    $quantityKg = !empty($data['quantity_kg']) ? (float)$data['quantity_kg'] : 0;
                    $palletCount = !empty($data['pallet_count']) ? (int)$data['pallet_count'] : 0;
                    $lotSupplier = $data['lot_supplier'] ?? null;
                    $mpr = $data['mpr'] ?? null;
                    $lotMpr = $data['lot_mpr'] ?? null;
                    $requisition = $data['requisition'] ?? null;
                    $paperboardType = $data['paperboard_type'] ?? null;
                    $machine = $data['machine'] ?? null;

                    if (!empty($data['dispatch_date'])) {
                        $dispatchDate = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $data['dispatch_date'])));
                    } else {
                        $dispatchDate = date('Y-m-d H:i:s');
                    }

                    $destinationLocation = $data['destination_location'] ?? null;
                    $userId = $_SESSION['user_id'] ?? 1;

                    if ($quantity <= 0) throw new Exception('จำนวนต้องมากกว่า 0');

                    $locationId = (int)$data['location_id'];

                    $stmt = $pdo->prepare("
                        SELECT location_id, available_stock, available_pallet
                        FROM Inventory_Stock
                        WHERE product_id = ? AND warehouse_id = ? AND location_id = ? AND available_stock > 0
                    ");
                    $stmt->execute([$productId, $warehouseId, $locationId]);
                    $stock = $stmt->fetch();

                    if (!$stock) {
                        throw new Exception('ไม่พบสต็อกใน Location ที่เลือก');
                    }

                    if ((float)$stock['available_stock'] < $quantity) {
                        throw new Exception('สต็อกไม่เพียงพอ');
                    }

                    $stmt = $pdo->prepare("
                        UPDATE Inventory_Stock
                        SET current_stock = current_stock - ?,
                            available_stock = available_stock - ?,
                            current_pallet = current_pallet - ?,
                            available_pallet = available_pallet - ?,
                            last_updated = GETDATE(),
                            last_movement_date = GETDATE()
                        WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
                    ");
                    $stmt->execute([
                        $quantity, $quantity, $palletCount, $palletCount,
                        $productId, $warehouseId, $locationId
                    ]);

                    $notes = "Dispatch | MPR: $mpr | Lot MPR: $lotMpr | Req: $requisition | To: $destinationLocation | Type: $paperboardType | Machine: $machine";
                    
                    // ✅ เช็คว่าสินค้านี้มี received_package_qty หรือไม่
                    $checkPackageQty = $pdo->prepare("
                        SELECT TOP 1 received_package_qty, package_weight_per_unit
                        FROM Stock_Movements
                        WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
                          AND batch_lot = ?
                          AND received_package_qty IS NOT NULL
                          AND received_package_qty > 0
                        ORDER BY movement_date DESC
                    ");
                    $checkPackageQty->execute([$productId, $warehouseId, $locationId, $lotSupplier]);
                    $packageData = $checkPackageQty->fetch();
                    
                    $dispatchPackageQty = null;
                    $packageWeight = null;
                    
                    if ($packageData) {
                        // ✅ มี package data = หมึก/กระป๋อง/แผ่น
                        $packageWeight = $packageData['package_weight_per_unit'];
                        if ($packageWeight > 0) {
                            $dispatchPackageQty = $quantity / $packageWeight;
                        }
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO Stock_Movements (
                            product_id, warehouse_id, location_id,
                            movement_type, quantity, unit_id,
                            reference_type, batch_lot,
                            movement_date, created_by, notes, 
                            quantity_pallet, quantity_kg,
                            received_package_qty, package_weight_per_unit
                        ) VALUES (?, ?, ?, 'OUT', ?, ?, 'DISPATCH', ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $productId, $warehouseId, $locationId,
                        $quantity, $data['unit_id'] ?? null, $lotSupplier,
                        $dispatchDate, $userId, $notes,
                        $palletCount, $quantityKg,
                        $dispatchPackageQty, $packageWeight
                    ]);

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'จ่ายออกสินค้าสำเร็จ']);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Dispatch Error: " . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            default:
                echo json_encode(['error' => 'Unknown action']);
        }

    } catch (Exception $e) {
        error_log("AJAX Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

    ob_end_flush();
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จ่ายออกจากคลังสต็อก (Multi-Lot)</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #8B4513;
            --secondary-color: #FF8C00;
            --accent-color: #A0522D;
            --success-color: #059669;
            --danger-color: #dc2626;
            --primary-gradient: linear-gradient(135deg, #8B4513, #A0522D);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #F5DEB3 0%, #DEB887 50%, #D2B48C 100%);
            min-height: 100vh;
            color: var(--primary-color);
        }

        .container-fluid {
            max-width: 98%;
            padding: 0 2rem;
        }

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
            font-size: 1.8rem;
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

        .d-flex { display: flex; }
        .align-items-center { align-items: center; }
        .justify-content-between { justify-content: space-between; }
        .w-100 { width: 100%; }
        .mb-0 { margin-bottom: 0; }
        .me-2 { margin-right: 0.5rem; }
        .gap-2 { gap: 0.5rem; }

        .main-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(139, 69, 19, 0.15);
            border: 2px solid rgba(139, 69, 19, 0.2);
            width: 100%;
            margin-bottom: 1.5rem;
        }

        .product-info {
            background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
            border: 2px solid rgba(139, 69, 19, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            display: none;
        }

        .product-info.show { display: block; }

        .product-info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 12px;
            color: var(--accent-color);
            margin-bottom: 5px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .info-value {
            font-size: 16px;
            color: var(--primary-color);
            font-weight: 600;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 12px;
            color: var(--primary-color);
            margin-bottom: 6px;
            font-weight: 600;
        }

        .form-group input,
        .form-group select {
            padding: 10px 12px;
            border: 2px solid rgba(139, 69, 19, 0.2);
            border-radius: 10px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            background: white;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(139, 69, 19, 0.1);
        }

        .form-group input:disabled,
        .form-group select:disabled {
            background: #f5f5f5;
            color: #a0aec0;
            cursor: not-allowed;
        }

        .btn-search {
            background: var(--primary-gradient);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .btn-search:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(139, 69, 19, 0.3);
        }

        .btn-save {
            background: var(--primary-gradient);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(139, 69, 19, 0.3);
        }

        .btn-save:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
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
        }

        /* Lot Selection Table */
        .lot-section {
            margin-top: 20px;
            display: none;
        }

        .lot-section.show { display: block; }

        .lot-section h4 {
            color: var(--primary-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .lot-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .lot-table th {
            background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
            color: white;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
        }

        .lot-table td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(139, 69, 19, 0.15);
            font-size: 13px;
        }

        .lot-table tr:hover {
            background: #fff8f0;
        }

        .lot-table input[type="number"] {
            width: 100px;
            padding: 8px;
            border: 2px solid rgba(139, 69, 19, 0.2);
            border-radius: 6px;
            font-size: 13px;
        }

        .lot-table input[type="number"]:focus {
            border-color: var(--primary-color);
            outline: none;
        }

        .lot-table .stock-available {
            color: var(--success-color);
            font-weight: 600;
        }

        .lot-table .stock-warning {
            color: var(--secondary-color);
        }

        /* Selected Items Summary */
        .selected-summary {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border: 2px solid rgba(5, 150, 105, 0.3);
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            display: none;
        }

        .selected-summary.show { display: block; }

        .selected-summary h4 {
            color: var(--success-color);
            margin-bottom: 15px;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
        }

        .summary-table th {
            background: var(--success-color);
            color: white;
            padding: 10px;
            text-align: left;
            font-size: 12px;
        }

        .summary-table td {
            padding: 8px 10px;
            border-bottom: 1px solid rgba(5, 150, 105, 0.2);
            font-size: 13px;
        }

        .summary-total {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid rgba(5, 150, 105, 0.3);
            font-weight: 600;
            font-size: 16px;
        }

        .btn-remove {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 4px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
        }

        .btn-remove:hover {
            background: #b91c1c;
        }

        .loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loading.show { display: flex; }

        .loading-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .btn-add-lot {
            background: var(--success-color);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }

        .btn-add-lot:hover {
            background: #047857;
        }

        .btn-add-lot:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .quick-fill {
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .quick-fill button {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
        }

        .quick-fill button:hover {
            background: #e5e7eb;
        }

        @media (max-width: 1200px) {
            .form-grid { grid-template-columns: repeat(2, 1fr); }
            .product-info-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .product-info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="loading" id="loading">
        <div class="loading-content">
            <div class="spinner"></div>
            <div style="color: var(--primary-color); font-weight: 600;">กำลังโหลด...</div>
        </div>
    </div>

    <!-- Header -->
    <div class="header-section">
        <div class="container-fluid" style="max-width: 98%;">
            <div class="d-flex justify-content-between align-items-center w-100">
                <div class="d-flex align-items-center">
                    <a href="../pages/dashboard.php" class="btn-back-arrow">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div>
                        <h5 class="mb-0">
                            <i class="fas fa-truck-loading me-2"></i>จ่ายออกจากคลังสต็อก (Multi-Lot)
                        </h5>
                        <small style="color: rgba(255,255,255,0.9);">รองรับการจ่ายจากหลาย Lot พร้อมกัน</small>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="dispatch_list.php" class="btn-header">รายการจ่ายออก</a>
                    <span style="color: white;">
                        <i class="fas fa-user-circle me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'System Administrator'); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <div class="main-card">
            <!-- Product Search -->
            <div class="form-grid">
                <div class="form-group">
                    <label>🔍 รหัสสินค้า</label>
                    <div style="display: flex; gap: 8px;">
                        <input type="text" id="productCode" placeholder="กรอกรหัสสินค้า..." style="flex: 1;">
                        <button type="button" id="btnSearch" class="btn-search">ค้นหา</button>
                    </div>
                </div>
            </div>

            <!-- Product Info Display -->
            <div class="product-info" id="productInfo">
                <div class="product-info-grid">
                    <div class="info-item">
                        <span class="info-label">รหัสสินค้า</span>
                        <span class="info-value" id="infoCode">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">ชื่อสินค้า</span>
                        <span class="info-value" id="infoName">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">หน่วย</span>
                        <span class="info-value" id="infoUnit">-</span>
                    </div>
                </div>
            </div>

                        <!-- Common Info - เหลือแค่ Date & Time -->
            <div class="form-grid">
                <div class="form-group">
                    <label>🏭 WAREHOUSE</label>
                    <select id="warehouse" disabled>
                        <option value="">เลือก Warehouse</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>📦 เลือก LOT (SUPPLIER)</label>
                    <select id="lotSelector" disabled>
                        <option value="">-- เลือก Lot --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>📅 DATE & TIME</label>
                    <input type="datetime-local" id="dispatchDate">
                </div>
            </div>

            <!-- Lot Selection Section -->
            <div class="lot-section" id="lotSection">
                <h4><i class="fas fa-boxes"></i> Location ที่มี Lot นี้</h4>

                <div style="overflow-x: auto;">
                    <table class="lot-table">
                        <thead>
                            <tr>
                                <th style="min-width: 80px;">Location</th>
                                <th style="min-width: 110px;">สต็อกคงเหลือ</th>
                                <th style="min-width: 110px;">Pallet คงเหลือ</th>
                                <th style="min-width: 110px;">จำนวนจ่าย</th>
                                <th style="min-width: 90px;">Pallet จ่าย</th>
                                <th style="min-width: 80px;">KG</th>
                                <th style="min-width: 120px;">MPR</th>
                                <th style="min-width: 120px;">LOT MPR</th>
                                <th style="min-width: 130px;">REQUISITION</th>
                                <th style="min-width: 150px;">DESTINATION</th>
                                <th style="min-width: 140px;">PAPERBOARD TYPE</th>
                                <th style="min-width: 120px;">MACHINE</th>
                                <th style="min-width: 60px;">เพิ่ม</th>
                            </tr>
                        </thead>
                        <tbody id="lotTableBody">
                            <!-- Dynamic rows -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Selected Items Summary -->
            <div class="selected-summary" id="selectedSummary">
                <h4><i class="fas fa-check-circle"></i> รายการที่เลือกจ่าย</h4>
                <div style="overflow-x: auto;">
                    <table class="summary-table">
                        <thead>
                            <tr>
                                <th>Location</th>
                                <th>Lot</th>
                                <th>จำนวน</th>
                                <th>Pallet</th>
                                <th>KG</th>
                                <th>MPR</th>
                                <th>LOT MPR</th>
                                <th>REQ</th>
                                <th>DESTINATION</th>
                                <th>PAPERBOARD</th>
                                <th>MACHINE</th>
                                <th>ลบ</th>
                            </tr>
                        </thead>
                        <tbody id="summaryTableBody">
                        </tbody>
                    </table>
                </div>
                <div class="summary-total">
                    <span>รวมทั้งหมด:</span>
                    <span id="totalQuantity">0 ชิ้น | 0 Pallet | 0 KG</span>
                </div>
            </div>

            <!-- Save Button -->
            <div style="text-align: center; margin-top: 25px;">
                <button type="button" id="btnSaveDispatch" class="btn-save" disabled>
                    <i class="fas fa-save"></i> บันทึกการจ่ายออก
                </button>
            </div>
        </div>
    </div>

    <script>
    let currentProduct = null;
    let selectedItems = []; // รายการที่เลือกจ่าย
    let availableLots = []; // Lot ทั้งหมดที่มี

    function showLoading() { document.getElementById('loading').classList.add('show'); }
    function hideLoading() { document.getElementById('loading').classList.remove('show'); }

    // Search Product
    async function searchProduct() {
        const code = document.getElementById('productCode').value.trim();
        if (!code) {
            alert('กรุณาระบุรหัสสินค้า');
            return;
        }

        showLoading();
        try {
            const res = await fetch(`?ajax=search_product&code=${encodeURIComponent(code)}`);
            const data = await res.json();
            hideLoading();

            if (data.found) {
                currentProduct = data.product;
                document.getElementById('productInfo').classList.add('show');
                document.getElementById('infoCode').textContent = data.product.product_code || '-';
                document.getElementById('infoName').textContent = data.product.product_name || '-';
                document.getElementById('infoUnit').textContent = data.product.unit_name || 'แผ่น';

                // Clear previous selections
                selectedItems = [];
                updateSummary();

                // Load warehouses
                await loadWarehouses(data.product.product_id);
            } else {
                alert(data.message || 'ไม่พบสินค้า');
                document.getElementById('productInfo').classList.remove('show');
                document.getElementById('lotSection').classList.remove('show');
            }
        } catch (err) {
            hideLoading();
            console.error(err);
            alert('เกิดข้อผิดพลาด');
        }
    }

    // Load Warehouses
    async function loadWarehouses(productId) {
        const warehouseSelect = document.getElementById('warehouse');
        warehouseSelect.innerHTML = '<option value="">กำลังโหลด...</option>';

        try {
            const res = await fetch(`?ajax=get_warehouses_with_stock&product_id=${productId}`);
            const warehouses = await res.json();

            warehouseSelect.innerHTML = '<option value="">เลือก Warehouse</option>';
            warehouses.forEach(w => {
                const option = document.createElement('option');
                option.value = w.warehouse_id;
                const totalPallet = parseFloat(w.total_pallet || 0);
                const totalStock = parseFloat(w.total_stock || 0);
                const unitName = w.unit_name || 'หน่วย';

                // ✅ แสดงตามจำนวนที่มีจริง
                if (totalPallet > 0) {
                    // มี received_package_qty = แสดงเป็นกระป๋อง/แผ่น
                    option.textContent = `${w.warehouse_name} (${Math.round(totalPallet)} ${unitName})`;
                } else {
                    // ไม่มี received_package_qty = แสดงเป็น kg/หน่วยปกติ
                    option.textContent = `${w.warehouse_name} (${totalStock.toFixed(2)} ${unitName})`;
                }
                warehouseSelect.appendChild(option);
            });

            if (warehouses.length > 0) {
            const firstWarehouse = warehouses[0];
            const totalPallet = parseFloat(firstWarehouse.total_pallet || 0);
            const unitName = firstWarehouse.unit_name || 'หน่วย';
            
            // ✅ ใช้หน่วยจาก unit_name เสมอ
            document.getElementById('infoUnit').textContent = unitName;
        }

            warehouseSelect.disabled = false;
        } catch (err) {
            console.error(err);
            warehouseSelect.innerHTML = '<option value="">เกิดข้อผิดพลาด</option>';
        }
    }

    // Load All Lots for selected warehouse
    // Load Unique Lots for dropdown
    async function loadUniqueLots(warehouseId) {
        const lotSelector = document.getElementById('lotSelector');
        lotSelector.innerHTML = '<option value="">-- กำลังโหลด --</option>';
        lotSelector.disabled = true;
        
        if (!currentProduct || !warehouseId) {
            lotSelector.innerHTML = '<option value="">-- เลือก Lot --</option>';
            document.getElementById('lotSection').classList.remove('show');
            return;
        }

        try {
            const res = await fetch(`?ajax=get_unique_lots&product_id=${currentProduct.product_id}&warehouse_id=${warehouseId}`);
            const lots = await res.json();

            lotSelector.innerHTML = '<option value="">-- เลือก Lot --</option>';
            
            if (lots.length > 0) {
                lots.forEach(lot => {
                    const option = document.createElement('option');
                    option.value = lot.batch_lot;
                    const palletText = lot.total_available_pallet > 0 ? ` (${Math.round(lot.total_available_pallet)} กระป๋อง)` : '';
                    option.textContent = `${lot.batch_lot} - ${lot.location_count} location${palletText}`;
                    lotSelector.appendChild(option);
                });
                lotSelector.disabled = false;
            } else {
                alert('ไม่พบ Lot ในคลังนี้');
            }
        } catch (err) {
            console.error(err);
            alert('เกิดข้อผิดพลาดในการโหลด Lot');
        }
    }

    // Load Locations by Selected Lot
    async function loadLocationsByLot(batchLot) {
        if (!currentProduct || !document.getElementById('warehouse').value || !batchLot) {
            document.getElementById('lotSection').classList.remove('show');
            return;
        }

        const warehouseId = document.getElementById('warehouse').value;
        showLoading();
        
        try {
            const res = await fetch(`?ajax=get_locations_by_selected_lot&product_id=${currentProduct.product_id}&warehouse_id=${warehouseId}&batch_lot=${encodeURIComponent(batchLot)}`);
            const locations = await res.json();
            hideLoading();

            availableLots = locations;
            renderLotTable(locations);

            if (locations.length > 0) {
                document.getElementById('lotSection').classList.add('show');
            } else {
                document.getElementById('lotSection').classList.remove('show');
                alert('ไม่พบ Location ที่มี Lot นี้');
            }
        } catch (err) {
            hideLoading();
            console.error(err);
            alert('เกิดข้อผิดพลาดในการโหลด Location');
        }
    }

    // Render Lot Selection Table
    function renderLotTable(lots) {
        const tbody = document.getElementById('lotTableBody');
        tbody.innerHTML = '';

lots.forEach((lot, index) => {
            const availableQty = Math.floor(parseFloat(lot.available_qty || 0));
            const availablePallet = Math.floor(parseFloat(lot.available_pallet || 0));
            const availablePackageQty = Math.floor(parseFloat(lot.available_package_qty || 0));
            const unitName = lot.unit_symbol || lot.unit_name || 'ชิ้น';
            // ✅ ต้องมีส่วนนี้
let displayQty;
let maxQty;  // ← ต้องมี!
if (availablePackageQty > 0) {
    displayQty = `${availablePackageQty} กระป๋อง`;
    maxQty = availablePackageQty;  // ← ต้องมี!
} else {
    displayQty = `${availableQty} ${unitName}`;
    maxQty = availableQty;  // ← ต้องมี!
}
            const isAlreadySelected = selectedItems.some(
                item => item.location_id == lot.location_id && item.lot_supplier == lot.batch_lot
            );

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${lot.location_code}</td>
                <td class="stock-available">${displayQty}</td>
                <td class="stock-available">${availablePallet} Pallet</td>
                <td>
                    <div class="quick-fill">
                        <input type="number" id="qty_${index}" placeholder="0" step="1" max="${maxQty}" value="" style="width:90px;">
                        <button type="button" onclick="document.getElementById('qty_${index}').value='${maxQty}'" title="เต็มจำนวน">MAX</button>
                    </div>
                </td>
                <td>
                    <div class="quick-fill">
                        <input type="number" id="pallet_${index}" placeholder="0" step="1" max="${availablePallet}" value="" style="width:70px;">
                        <button type="button" onclick="document.getElementById('pallet_${index}').value='${availablePallet}'" title="เต็มจำนวน">MAX</button>
                    </div>
                </td>
                <td><input type="number" id="kg_${index}" placeholder="0" step="1" value="" style="width:70px;"></td>
                <td><input type="text" id="mpr_${index}" placeholder="MPR" style="width:110px; padding:6px; border:1px solid #ddd; border-radius:4px;"></td>
                <td><input type="text" id="lotmpr_${index}" placeholder="Lot MPR" style="width:110px; padding:6px; border:1px solid #ddd; border-radius:4px;"></td>
                <td><input type="text" id="req_${index}" placeholder="Requisition" style="width:120px; padding:6px; border:1px solid #ddd; border-radius:4px;"></td>
                <td><input type="text" id="dest_${index}" placeholder="Destination" style="width:140px; padding:6px; border:1px solid #ddd; border-radius:4px;"></td>
                <td><input type="text" id="paper_${index}" placeholder="Type" style="width:130px; padding:6px; border:1px solid #ddd; border-radius:4px;"></td>
                <td>
                    <select id="machine_${index}" style="width:110px; padding:6px; border:1px solid #ddd; border-radius:4px;">
                        <option value="">เลือก</option>
                        ${window.machineOptions || ''}
                    </select>
                </td>
                <td>
                    <button type="button" class="btn-add-lot" onclick="addToSelected(${index})" ${isAlreadySelected ? 'disabled' : ''}>
                        ${isAlreadySelected ? '✓' : '+ เพิ่ม'}
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    // Add item to selected list
    function addToSelected(index) {
        const lot = availableLots[index];
        const qtyInput = document.getElementById(`qty_${index}`);
        const palletInput = document.getElementById(`pallet_${index}`);
        const kgInput = document.getElementById(`kg_${index}`);
        const mprInput = document.getElementById(`mpr_${index}`);
        const lotMprInput = document.getElementById(`lotmpr_${index}`);
        const reqInput = document.getElementById(`req_${index}`);
        const destInput = document.getElementById(`dest_${index}`);
        const paperInput = document.getElementById(`paper_${index}`);
        const machineSelect = document.getElementById(`machine_${index}`);

        const quantity = parseInt(qtyInput.value) || 0;
        const pallet = parseInt(palletInput.value) || 0;
        const kg = parseInt(kgInput.value) || 0;
        const mpr = mprInput.value.trim();
        const lotMpr = lotMprInput.value.trim();
        const requisition = reqInput.value.trim();
        const destination = destInput.value.trim();
        const paperboardType = paperInput.value.trim();
        const machine = machineSelect.value;
        const unitName = lot.unit_symbol || lot.unit_name || 'ชิ้น';

        if (quantity <= 0) {
            alert('กรุณาระบุจำนวนที่ต้องการจ่าย');
            return;
        }

        const availableQty = Math.floor(parseFloat(lot.available_qty || 0));
        const availablePallet = Math.floor(parseFloat(lot.available_pallet || 0));

        if (quantity > availableQty) {
            alert(`จำนวนเกินสต็อกคงเหลือ (มี ${availableQty} ${unitName})`);
            return;
        }

        if (pallet > availablePallet) {
            alert(`จำนวน Pallet เกินที่มีอยู่ (มี ${availablePallet} Pallet)`);
            return;
        }

        // Check if already selected
        const existingIndex = selectedItems.findIndex(
            item => item.location_id == lot.location_id && item.lot_supplier == lot.batch_lot
        );

        if (existingIndex >= 0) {
            alert('Lot นี้ถูกเลือกแล้ว');
            return;
        }

        // Add to selected
        selectedItems.push({
            product_id: currentProduct.product_id,
            unit_id: lot.unit_id || currentProduct.unit_id,
            warehouse_id: parseInt(document.getElementById('warehouse').value),
            location_id: lot.location_id,
            location_code: lot.location_code,
            lot_supplier: lot.batch_lot,
            quantity: quantity,
            pallet_count: pallet,
            quantity_kg: kg,
            mpr: mpr,
            lot_mpr: lotMpr,
            requisition: requisition,
            destination_location: destination,
            paperboard_type: paperboardType,
            machine: machine,
            available_qty: availableQty,
            available_pallet: availablePallet,
            unit_name: unitName
        });

        // Clear inputs
        qtyInput.value = '';
        palletInput.value = '';
        kgInput.value = '';
        mprInput.value = '';
        lotMprInput.value = '';
        reqInput.value = '';
        destInput.value = '';
        paperInput.value = '';
        machineSelect.value = '';

        // Update UI
        renderLotTable(availableLots);
        updateSummary();
    }

    // Remove from selected
    function removeFromSelected(index) {
        selectedItems.splice(index, 1);
        renderLotTable(availableLots);
        updateSummary();
    }


    // Update Summary
   // Update Summary
    function updateSummary() {
        const summaryDiv = document.getElementById('selectedSummary');
        const tbody = document.getElementById('summaryTableBody');
        const btnSave = document.getElementById('btnSaveDispatch');

        if (selectedItems.length === 0) {
            summaryDiv.classList.remove('show');
            btnSave.disabled = true;
            return;
        }

        summaryDiv.classList.add('show');
        btnSave.disabled = false;

        tbody.innerHTML = '';
        let totalQty = 0, totalPallet = 0, totalKg = 0;

        selectedItems.forEach((item, index) => {
            totalQty += item.quantity;
            totalPallet += item.pallet_count;
            totalKg += item.quantity_kg;

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${item.location_code}</td>
                <td><strong>${item.lot_supplier}</strong></td>
                <td>${item.quantity} ${item.unit_name || 'ชิ้น'}</td>
                <td>${item.pallet_count}</td>
                <td>${item.quantity_kg}</td>
                <td>${item.mpr || '-'}</td>
                <td>${item.lot_mpr || '-'}</td>
                <td>${item.requisition || '-'}</td>
                <td>${item.destination_location || '-'}</td>
                <td>${item.paperboard_type || '-'}</td>
                <td>${item.machine || '-'}</td>
                <td><button class="btn-remove" onclick="removeFromSelected(${index})"><i class="fas fa-trash"></i></button></td>
            `;
            tbody.appendChild(tr);
        });

        const commonUnit = selectedItems[0]?.unit_name || 'ชิ้น';
        document.getElementById('totalQuantity').textContent = 
            `${totalQty} ${commonUnit} | ${totalPallet} Pallet | ${totalKg} KG`;
    }

   // Save Multi Dispatch
    async function saveDispatch() {
        if (selectedItems.length === 0) {
            alert('กรุณาเลือก Lot ที่ต้องการจ่าย');
            return;
        }

        if (!confirm(`ยืนยันการจ่ายออก ${selectedItems.length} รายการ?`)) return;

        const commonData = {
            dispatch_date: document.getElementById('dispatchDate').value || ''
        };

        showLoading();
        try {
            const res = await fetch('?ajax=save_multi_dispatch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    common: commonData,
                    items: selectedItems
                })
            });
            const result = await res.json();
            hideLoading();

            if (result.success) {
                alert('✅ ' + result.message);
                if (confirm('ต้องการจ่ายสินค้าต่อ?')) {
                    location.reload();
                } else {
                    window.location.href = 'dispatch_list.php';
                }
            } else {
                alert('❌ ' + (result.error || 'เกิดข้อผิดพลาด'));
            }
        } catch (err) {
            hideLoading();
            console.error(err);
            alert('เกิดข้อผิดพลาดขณะบันทึก');
        }
    }

// Load Machines
    async function loadMachines() {
        try {
            const res = await fetch('?ajax=get_machines');
            const machines = await res.json();
            
            // สร้าง options HTML
            let optionsHTML = '';
            machines.forEach(m => {
                optionsHTML += `<option value="${m.machine_code}">${m.machine_code}</option>`;
            });
            
            // เก็บไว้ใน global variable
            window.machineOptions = optionsHTML;
        } catch (err) {
            console.error('Error loading machines:', err);
        }
    }

    // Event Listeners
    document.getElementById('btnSearch').addEventListener('click', searchProduct);
    document.getElementById('productCode').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchProduct();
        }
    });

    // Warehouse change -> Load unique lots
    document.getElementById('warehouse').addEventListener('change', function() {
        const lotSelector = document.getElementById('lotSelector');
        const lotSection = document.getElementById('lotSection');
        
        if (this.value) {
            loadUniqueLots(this.value);
            lotSection.classList.remove('show');
            lotSelector.value = '';
        } else {
            lotSelector.innerHTML = '<option value="">-- เลือก Lot --</option>';
            lotSelector.disabled = true;
            lotSection.classList.remove('show');
        }
    });

    // Lot selector change -> Load locations
    document.getElementById('lotSelector').addEventListener('change', function() {
        if (this.value) {
            loadLocationsByLot(this.value);
        } else {
            document.getElementById('lotSection').classList.remove('show');
        }
    });

    document.getElementById('btnSaveDispatch').addEventListener('click', saveDispatch);

    // Initialize
    const now = new Date();
    const localDateTime = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    document.getElementById('dispatchDate').value = localDateTime;
    loadMachines();
    </script>
</body>
</html>