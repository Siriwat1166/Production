<?php
// goods_receipt_edit.php - แก้ไขรายการรับเข้าสินค้า (ป้องกันการแก้ไขเมื่อมีการจ่ายออก)
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

// Get receipt ID from URL
$receiptId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($receiptId <= 0) {
    die("Invalid receipt ID");
}

// ===== AJAX HANDLERS =====
if (isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();

    try {
        $action = $_POST['action'];

        switch ($action) {
            case 'update_receipt':
                $grId = (int)$_POST['gr_id'];
                $receiptDate = $_POST['receipt_date'];
                $invoiceNumber = $_POST['invoice_number'];
                $notes = $_POST['notes'];
                $warehouseId = !empty($_POST['warehouse_id']) ? (int)$_POST['warehouse_id'] : null;
                
                // Parse items
                $items = json_decode($_POST['items'], true);

                if (!$items || count($items) === 0) {
                    throw new Exception('ไม่มีรายการสินค้า');
                }

                $pdo->beginTransaction();
                try {
                    // ✅ เช็คว่ามีการจ่ายออกหรือไม่ (CRITICAL CHECK)
                    $checkStmt = $pdo->prepare("
                        SELECT 
                            COUNT(*) as dispatched_count,
                            SUM(ist.current_stock - ist.available_stock) as total_dispatched
                        FROM Goods_Receipt_Items gri
                        JOIN Inventory_Stock ist ON 
                            gri.product_id = ist.product_id 
                            AND gri.warehouse_id = ist.warehouse_id
                            AND gri.location_id = ist.location_id
                        WHERE gri.gr_id = ? 
                        AND ist.current_stock > ist.available_stock
                    ");
                    $checkStmt->execute([$grId]);
                    $dispatchCheck = $checkStmt->fetch();
                    
                    // ❌ ถ้ามีการจ่ายออก → ห้ามแก้ไข
                    if ($dispatchCheck['dispatched_count'] > 0) {
                        throw new Exception(
                            'ไม่สามารถแก้ไขได้! มีสินค้าถูกจ่ายออกไปแล้ว ' . 
                            number_format($dispatchCheck['total_dispatched']) . ' หน่วย<br>' .
                            'กรุณาใช้ฟีเจอร์ Stock Adjustment แทน'
                        );
                    }
                    
                    // ตรวจสอบจำนวนที่แก้ไขว่าน้อยกว่าที่จ่ายไปหรือไม่
                    foreach ($items as $item) {
                        $newQty = floatval($item['quantity_received'] ?? 0);
                        
                        // เช็คว่า quantity ใหม่มากกว่าที่จ่ายออกไปหรือไม่
                        $stockCheck = $pdo->prepare("
                            SELECT 
                                ist.current_stock,
                                ist.available_stock,
                                (ist.current_stock - ist.available_stock) as dispatched_qty,
                                mp.SSP_Code,
                                mp.Name as product_name
                            FROM Inventory_Stock ist
                            JOIN Goods_Receipt_Items gri ON 
                                gri.product_id = ist.product_id
                                AND gri.warehouse_id = ist.warehouse_id
                                AND gri.location_id = ist.location_id
                            JOIN Master_Products_ID mp ON ist.product_id = mp.id
                            WHERE gri.gr_item_id = ?
                        ");
                        $stockCheck->execute([$item['gr_item_id']]);
                        $stock = $stockCheck->fetch();
                        
                        if ($stock && $newQty < $stock['dispatched_qty']) {
                            throw new Exception(
                                'สินค้า ' . $stock['SSP_Code'] . ' - ' . $stock['product_name'] . '<br>' .
                                'จำนวนใหม่ (' . number_format($newQty) . ') ' .
                                'น้อยกว่าจำนวนที่จ่ายออกไปแล้ว (' . 
                                number_format($stock['dispatched_qty']) . ')'
                            );
                        }
                    }
                    
                    $userId = $_SESSION['user_id'];
                    
                    // Update Goods_Receipt header
                    $updateFields = [];
                    $updateParams = [];
                    
                    $updateFields[] = "receipt_date = ?";
                    $updateParams[] = $receiptDate;
                    
                    $updateFields[] = "invoice_number = ?";
                    $updateParams[] = $invoiceNumber;
                    
                    $updateFields[] = "notes = ?";
                    $updateParams[] = $notes;
                    
                    // ✅ อนุญาตให้แก้ไข Warehouse ถ้ายังไม่จ่ายออก
                    if ($warehouseId !== null) {
                        $updateFields[] = "warehouse_id = ?";
                        $updateParams[] = $warehouseId;
                    }
                    
                    $updateParams[] = $grId;
                    
                    $stmt = $pdo->prepare("
                        UPDATE Goods_Receipt
                        SET " . implode(", ", $updateFields) . "
                        WHERE gr_id = ?
                    ");
                    $stmt->execute($updateParams);
                    
                    // Update each item
                    foreach ($items as $itemData) {
                        $grItemId = (int)$itemData['gr_item_id'];
                        $newQty = floatval($itemData['quantity_received']);
                        $newPallet = !empty($itemData['quantity_pallet']) ? floatval($itemData['quantity_pallet']) : null;
                        $batchLot = $itemData['batch_lot'];
                        $locationId = (int)$itemData['location_id'];
                        $itemWarehouseId = $warehouseId ?? $itemData['warehouse_id']; // ใช้ warehouse ใหม่ถ้ามี
                        
                        // Get old values
                        $stmt = $pdo->prepare("
                            SELECT quantity_received, quantity_pallet, location_id, product_id, warehouse_id
                            FROM Goods_Receipt_Items
                            WHERE gr_item_id = ?
                        ");
                        $stmt->execute([$grItemId]);
                        $oldData = $stmt->fetch();
                        
                        $oldQty = $oldData['quantity_received'];
                        $oldPallet = $oldData['quantity_pallet'];
                        $oldLocationId = $oldData['location_id'];
                        $oldWarehouseId = $oldData['warehouse_id'];
                        
                        // Update item (เพิ่ม warehouse_id)
                        $stmt = $pdo->prepare("
                            UPDATE Goods_Receipt_Items
                            SET quantity_received = ?,
                                quantity_pallet = ?,
                                batch_lot = ?,
                                location_id = ?,
                                warehouse_id = ?,
                                updated_by = ?,
                                updated_at = GETDATE()
                            WHERE gr_item_id = ?
                        ");
                        $stmt->execute([
                            $newQty,
                            $newPallet,
                            $batchLot,
                            $locationId,
                            $itemWarehouseId,
                            $userId,
                            $grItemId
                        ]);
                        
                        // Calculate differences
                        $qtyDiff = $newQty - $oldQty;
                        $palletDiff = ($newPallet ?? 0) - ($oldPallet ?? 0);
                        $locationChanged = ($locationId != $oldLocationId);
                        
                        // Update Inventory_Stock
                        if ($locationChanged) {
                            // ถ้าเปลี่ยน location: ลดสต็อกเก่า เพิ่มสต็อกใหม่
                            
                            // ลดสต็อกจาก location เดิม
                            $stmt = $pdo->prepare("
                                UPDATE Inventory_Stock
                                SET current_stock = current_stock - ?,
                                    available_stock = available_stock - ?,
                                    quantity_pallet = ISNULL(quantity_pallet, 0) - ?,
                                    updated_at = GETDATE()
                                WHERE product_id = ?
                                AND warehouse_id = ?
                                AND location_id = ?
                            ");
                            $stmt->execute([
                                $oldQty,
                                $oldQty,
                                $oldPallet ?? 0,
                                $itemData['product_id'],
                                $oldData['warehouse_id'],
                                $oldLocationId
                            ]);
                            
                            // เพิ่มสต็อกไปยัง location ใหม่
                            $stmt = $pdo->prepare("
                                MERGE INTO Inventory_Stock AS target
                                USING (SELECT ? AS product_id, ? AS warehouse_id, ? AS location_id) AS source
                                ON target.product_id = source.product_id
                                   AND target.warehouse_id = source.warehouse_id
                                   AND target.location_id = source.location_id
                                WHEN MATCHED THEN
                                    UPDATE SET
                                        current_stock = target.current_stock + ?,
                                        available_stock = target.available_stock + ?,
                                        quantity_pallet = ISNULL(target.quantity_pallet, 0) + ?,
                                        updated_at = GETDATE()
                                WHEN NOT MATCHED THEN
                                    INSERT (product_id, warehouse_id, location_id, current_stock, available_stock, quantity_pallet, lot_number, quality_status, created_at, updated_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 'accepted', GETDATE(), GETDATE());
                            ");
                            $stmt->execute([
                                $itemData['product_id'],
                                $itemWarehouseId,
                                $locationId,
                                $newQty,
                                $newQty,
                                $newPallet ?? 0,
                                $itemData['product_id'],
                                $itemWarehouseId,
                                $locationId,
                                $newQty,
                                $newQty,
                                $newPallet ?? 0,
                                $batchLot
                            ]);
                            
                            // บันทึก Movement สำหรับ location change
                            $stmt = $pdo->prepare("
                                INSERT INTO Stock_Movements (
                                    product_id, warehouse_id, location_id, 
                                    movement_type, quantity, quantity_pallet, lot_number,
                                    reference_type, reference_id, 
                                    created_by, notes, movement_date
                                ) VALUES (?, ?, ?, 'TRANSFER', ?, ?, ?, 'ADJUSTMENT', ?, ?, ?, GETDATE())
                            ");
                            $stmt->execute([
                                $itemData['product_id'],
                                $itemWarehouseId,
                                $locationId,
                                $newQty,
                                $newPallet,
                                $batchLot,
                                'ADJ-GR-' . $grId,
                                $userId,
                                'แก้ไขรายการรับเข้า GR ID: ' . $grId . ' | ย้าย location จาก ' . $oldLocationId . ' ไป ' . $locationId
                            ]);
                            
                        } else if ($qtyDiff != 0 || $palletDiff != 0) {
                            // ถ้าไม่เปลี่ยน location: ปรับเฉพาะจำนวน
                            $stmt = $pdo->prepare("
                                UPDATE Inventory_Stock
                                SET current_stock = current_stock + ?,
                                    available_stock = available_stock + ?,
                                    quantity_pallet = ISNULL(quantity_pallet, 0) + ?,
                                    updated_at = GETDATE()
                                WHERE product_id = ?
                                AND warehouse_id = ?
                                AND location_id = ?
                            ");
                            $stmt->execute([
                                $qtyDiff,
                                $qtyDiff,
                                $palletDiff,
                                $itemData['product_id'],
                                $itemWarehouseId,
                                $locationId
                            ]);
                            
                            // บันทึก Movement สำหรับ adjustment
                            if ($qtyDiff != 0) {
                                $stmt = $pdo->prepare("
                                    INSERT INTO Stock_Movements (
                                        product_id, warehouse_id, location_id, 
                                        movement_type, quantity, quantity_pallet, lot_number,
                                        reference_type, reference_id, 
                                        created_by, notes, movement_date
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ADJUSTMENT', ?, ?, ?, GETDATE())
                                ");
                                $movementType = $qtyDiff > 0 ? 'IN' : 'OUT';
                                $notes = 'แก้ไขรายการรับเข้า GR ID: ' . $grId;
                                if ($qtyDiff != 0) $notes .= ' | Qty: ' . $oldQty . '→' . $newQty;
                                if ($palletDiff != 0) $notes .= ' | Pallet: ' . ($oldPallet ?? 0) . '→' . ($newPallet ?? 0);
                                
                                $stmt->execute([
                                    $itemData['product_id'],
                                    $itemWarehouseId,
                                    $locationId,
                                    $movementType,
                                    abs($qtyDiff),
                                    abs($palletDiff),
                                    $batchLot,
                                    'ADJ-GR-' . $grId,
                                    $userId,
                                    $notes
                                ]);
                            }
                        }
                    }
                    
                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'อัปเดตข้อมูลสำเร็จ']);
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
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

// ===== PAGE LOAD - เช็คการจ่ายออกก่อนแสดงฟอร์ม =====

// Load receipt header
$stmt = $pdo->prepare("
    SELECT 
        gr.gr_id,
        gr.gr_number,
        gr.po_id,
        gr.warehouse_id,
        gr.receipt_date,
        gr.invoice_number,
        gr.notes,
        gr.created_date,
        w.warehouse_name,
        po.po_number,
        s.supplier_name
    FROM Goods_Receipt gr
    JOIN Warehouses w ON gr.warehouse_id = w.warehouse_id
    LEFT JOIN PO_Header po ON gr.po_id = po.po_id
    LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
    WHERE gr.gr_id = ?
");
$stmt->execute([$receiptId]);
$receipt = $stmt->fetch();

if (!$receipt) {
    die("ไม่พบรายการรับเข้านี้");
}

// Get LOT numbers from items
$lotStmt = $pdo->prepare("
    SELECT DISTINCT batch_lot 
    FROM Goods_Receipt_Items 
    WHERE gr_id = ? AND batch_lot IS NOT NULL AND batch_lot != ''
");
$lotStmt->execute([$receiptId]);
$lots = $lotStmt->fetchAll(PDO::FETCH_COLUMN);
$receipt['lot_numbers'] = implode(', ', $lots);

// Get Location codes from items
$locStmt = $pdo->prepare("
    SELECT DISTINCT wl.location_code
    FROM Goods_Receipt_Items gri
    JOIN Warehouse_Locations wl ON gri.location_id = wl.location_id
    WHERE gri.gr_id = ?
");
$locStmt->execute([$receiptId]);
$locations = $locStmt->fetchAll(PDO::FETCH_COLUMN);
$receipt['locations'] = implode(', ', $locations);

// ✅ เช็คว่ามีการจ่ายออกหรือไม่ (สำหรับกำหนด disabled)
$dispatchCheckStmt = $pdo->prepare("
    SELECT COUNT(*) as has_dispatch
    FROM Goods_Receipt_Items gri
    JOIN Inventory_Stock ist ON 
        gri.product_id = ist.product_id 
        AND gri.warehouse_id = ist.warehouse_id
        AND gri.location_id = ist.location_id
    WHERE gri.gr_id = ? 
    AND ist.current_stock > ist.available_stock
");
$dispatchCheckStmt->execute([$receiptId]);
$hasDispatch = $dispatchCheckStmt->fetchColumn() > 0;

// Load items
$stmt = $pdo->prepare("
    SELECT 
        gri.gr_item_id,
        gri.product_id,
        gri.warehouse_id,
        gri.quantity_received,
        gri.stock_quantity,
        gri.quantity_pallet,
        gri.batch_lot,
        gri.location_id,
        mp.SSP_Code,
        mp.Name as product_name,
        u.unit_symbol,
        wl.location_code,
        ist.current_stock,
        ist.available_stock
    FROM Goods_Receipt_Items gri
    JOIN Master_Products_ID mp ON gri.product_id = mp.id
    LEFT JOIN Units u ON mp.Unit_id = u.unit_id
    LEFT JOIN Warehouse_Locations wl ON gri.location_id = wl.location_id
    LEFT JOIN Inventory_Stock ist ON 
        gri.product_id = ist.product_id
        AND gri.warehouse_id = ist.warehouse_id
        AND gri.location_id = ist.location_id
    WHERE gri.gr_id = ?
");
$stmt->execute([$receiptId]);
$items = $stmt->fetchAll();

// ✅ Load Stock Movements - กรองเฉพาะ LOT ที่ตรงกับรายการรับเข้านี้
$movementStmt = $pdo->prepare("
    SELECT DISTINCT
        sm.movement_id,
        sm.movement_date,
        sm.movement_type,
        sm.quantity,
        sm.quantity_pallet,
        sm.reference_type,
        sm.reference_number,
        sm.notes,
        sm.batch_lot as lot_number,
        mp.SSP_Code,
        mp.Name as product_name,
        wl.location_code,
        u.unit_symbol,
        usr.username as created_by_name
    FROM Stock_Movements sm
    JOIN Master_Products_ID mp ON sm.product_id = mp.id
    LEFT JOIN Warehouse_Locations wl ON sm.location_id = wl.location_id
    LEFT JOIN Units u ON mp.Unit_id = u.unit_id
    LEFT JOIN dbo.Users usr ON sm.created_by = usr.user_id
    WHERE EXISTS (
        SELECT 1 
        FROM Goods_Receipt_Items gri
        WHERE gri.gr_id = ?
        AND sm.product_id = gri.product_id
        AND sm.warehouse_id = ?
        AND sm.location_id = gri.location_id
        AND (
            sm.batch_lot = gri.batch_lot 
            OR (sm.batch_lot IS NULL AND gri.batch_lot IS NULL)
        )
    )
    AND CAST(sm.movement_date AS DATE) >= CAST(? AS DATE)
    ORDER BY sm.movement_date DESC
");
$movementStmt->execute([$receiptId, $receipt['warehouse_id'], $receipt['receipt_date']]);
$movements = $movementStmt->fetchAll();

// ✅ เช็คว่ามีการจ่ายออกหรือไม่ - ตรวจสอบจาก Stock_Movements โดยตรง
$stmt = $pdo->prepare("
    SELECT 
        gri.gr_item_id,
        gri.product_id,
        gri.quantity_received,
        mp.SSP_Code,
        mp.Name as product_name,
        wl.location_code,
        u.unit_symbol,
        
        -- คำนวณจำนวนที่จ่ายออกจาก Stock_Movements
        ISNULL((
            SELECT SUM(sm.quantity)
            FROM Stock_Movements sm
            WHERE sm.product_id = gri.product_id
              AND sm.warehouse_id = gri.warehouse_id
              AND sm.location_id = gri.location_id
              AND sm.movement_type = 'OUT'
              AND (sm.batch_lot = gri.batch_lot OR (sm.batch_lot IS NULL AND gri.batch_lot IS NULL))
              AND sm.movement_date >= ?
        ), 0) as dispatched_qty,
        
        -- ตรวจสอบจาก Inventory_Stock เพิ่มเติม
        ISNULL((ist.current_stock - ist.available_stock), 0) as stock_dispatched
        
    FROM Goods_Receipt_Items gri
    JOIN Master_Products_ID mp ON gri.product_id = mp.id
    LEFT JOIN Units u ON mp.Unit_id = u.unit_id
    LEFT JOIN Warehouse_Locations wl ON gri.location_id = wl.location_id
    LEFT JOIN Inventory_Stock ist ON 
        gri.product_id = ist.product_id
        AND gri.warehouse_id = ist.warehouse_id
        AND gri.location_id = ist.location_id
    WHERE gri.gr_id = ?
");
$stmt->execute([$receipt['receipt_date'], $receiptId]);
$checkItems = $stmt->fetchAll();

// กรองเฉพาะรายการที่มีการจ่ายออก
$dispatchedItems = [];
$debugInfo = []; // สำหรับ debug

foreach ($checkItems as $item) {
    $totalDispatched = max(floatval($item['dispatched_qty']), floatval($item['stock_dispatched']));
    
    // เก็บข้อมูล debug ทุกรายการ
    $debugInfo[] = [
        'SSP_Code' => $item['SSP_Code'],
        'product_name' => $item['product_name'],
        'quantity_received' => $item['quantity_received'],
        'dispatched_qty' => $item['dispatched_qty'],
        'stock_dispatched' => $item['stock_dispatched'],
        'total_dispatched' => $totalDispatched
    ];
    
    if ($totalDispatched > 0) {
        $dispatchedItems[] = [
            'SSP_Code' => $item['SSP_Code'],
            'product_name' => $item['product_name'],
            'quantity_received' => $item['quantity_received'],
            'dispatched_qty' => $totalDispatched,
            'location_code' => $item['location_code'] ?? '-',
            'unit_symbol' => $item['unit_symbol'] ?? ''
        ];
    }
}

// 🔍 DEBUG MODE - แสดงเฉพาะเมื่อมี ?debug=1 ใน URL
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    echo "<!-- DEBUG INFO:\n";
    echo "Receipt ID: " . $receiptId . "\n";
    echo "Receipt Date: " . $receipt['receipt_date'] . "\n";
    echo "Total Items Checked: " . count($checkItems) . "\n";
    echo "Dispatched Items Found: " . count($dispatchedItems) . "\n\n";
    echo "All Items Detail:\n";
    foreach ($debugInfo as $idx => $info) {
        echo "  [" . ($idx + 1) . "] " . $info['SSP_Code'] . " - " . $info['product_name'] . "\n";
        echo "      Received: " . $info['quantity_received'] . "\n";
        echo "      Dispatched (Movement): " . $info['dispatched_qty'] . "\n";
        echo "      Dispatched (Stock): " . $info['stock_dispatched'] . "\n";
        echo "      Total Dispatched: " . $info['total_dispatched'] . "\n";
        echo "      Has Dispatch: " . ($info['total_dispatched'] > 0 ? 'YES' : 'NO') . "\n\n";
    }
    echo "-->\n";
}

// ❌ ถ้ามีการจ่ายออก → แสดงหน้าบล็อก
if (count($dispatchedItems) > 0) {
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>🚫 ไม่สามารถแก้ไขได้</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Sarabun', 'Poppins', sans-serif;
        background: linear-gradient(to bottom, #D4A574 0%, #C9A068 50%, #BF9B5E 100%);
        min-height: 100vh;
        padding: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .error-container {
        max-width: 900px;
        width: 100%;
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 32px rgba(107, 68, 35, 0.25);
        padding: 40px;
        animation: slideUp 0.4s ease;
        border: 2px solid #C9A068;
    }
    
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .error-header {
        text-align: center;
        margin-bottom: 30px;
    }
    
    .error-icon {
        font-size: 80px;
        margin-bottom: 20px;
    }
    
    .error-title {
        color: #8B4513;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 10px;
    }
    
    .error-subtitle {
        color: #6B4423;
        font-size: 16px;
        line-height: 1.6;
    }
    
    .warning-box {
        background: linear-gradient(135deg, #FFF8DC 0%, #FFE4B5 100%);
        border-left: 5px solid #D2691E;
        padding: 25px;
        border-radius: 10px;
        margin: 30px 0;
        box-shadow: 0 2px 8px rgba(210, 105, 30, 0.2);
    }
    
    .warning-box h3 {
        color: #8B4513;
        font-size: 18px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 600;
    }
    
    .warning-box p {
        color: #6B4423;
        line-height: 1.8;
        font-size: 15px;
    }
    
    .warning-box ul {
        color: #6B4423;
        line-height: 1.8;
    }
    
    .receipt-info {
        background: linear-gradient(135deg, #F5E6D3 0%, #E8D5C4 100%);
        padding: 20px;
        border-radius: 10px;
        margin: 20px 0;
        border: 1px solid #C9A068;
    }
    
    .receipt-info h3 {
        color: #5D4037;
        font-size: 17px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
    }
    
    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    
    .info-item {
        display: flex;
        gap: 10px;
    }
    
    .info-label {
        color: #6B4423;
        font-weight: 500;
        min-width: 120px;
        font-size: 14px;
    }
    
    .info-value {
        color: #5D4037;
        font-weight: 600;
        font-size: 14px;
    }
    
    .item-list {
        background: #fff;
        border: 2px solid #C9A068;
        border-radius: 10px;
        overflow: hidden;
        margin: 25px 0;
        box-shadow: 0 2px 8px rgba(139, 90, 60, 0.15);
    }
    
    .item-list-header {
        background: linear-gradient(135deg, #8B5A3C 0%, #A0694F 100%);
        color: white;
        padding: 14px 20px;
        font-weight: 600;
        font-size: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .item-list table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .item-list th {
        background: linear-gradient(135deg, #5D4037 0%, #6D4C41 100%);
        color: white;
        padding: 12px 15px;
        text-align: left;
        font-weight: 600;
        font-size: 13px;
        border-bottom: 2px solid #8B5A3C;
    }
    
    .item-list td {
        padding: 13px 15px;
        border-bottom: 1px solid #E0E0E0;
        color: #424242;
        font-size: 14px;
    }
    
    .item-list tr:last-child td {
        border-bottom: none;
    }
    
    .item-list tr:hover {
        background: #FFF8F0;
    }
    
    .dispatched-badge {
        background: linear-gradient(135deg, #DC3545 0%, #C82333 100%);
        color: white;
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        display: inline-block;
        box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
    }
    
    .solution-box {
        background: linear-gradient(135deg, #E3F2FD 0%, #BBDEFB 100%);
        border-left: 5px solid #2196F3;
        padding: 25px;
        border-radius: 10px;
        margin-top: 30px;
        box-shadow: 0 2px 8px rgba(33, 150, 243, 0.2);
    }
    
    .solution-box h3 {
        color: #1565C0;
        font-size: 18px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 600;
    }
    
    .solution-box ul {
        color: #1976D2;
        line-height: 2;
        padding-left: 20px;
        font-size: 14px;
    }
    
    .solution-box li {
        margin-bottom: 8px;
    }
    
    .solution-box strong {
        color: #0D47A1;
        font-weight: 600;
    }
    
    .btn-back {
        display: inline-block;
        margin-top: 30px;
        padding: 13px 35px;
        background: linear-gradient(135deg, #8B5A3C 0%, #A0694F 100%);
        color: white;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 15px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(139, 90, 60, 0.3);
        text-align: center;
        width: 100%;
        border: none;
    }
    
    .btn-back:hover {
        background: linear-gradient(135deg, #A0694F 0%, #8B5A3C 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(139, 90, 60, 0.4);
    }
</style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-header">
                <div class="error-icon">🚫</div>
                <h1 class="error-title">ไม่สามารถแก้ไขรายการนี้ได้</h1>
                <p class="error-subtitle">
                    มีสินค้าในรายการนี้ถูกจ่ายออกไปใช้งานแล้ว<br>
                    การแก้ไขอาจทำให้ข้อมูลสต็อกและประวัติการเคลื่อนไหวไม่ถูกต้อง
                </p>
            </div>
            
            <div class="warning-box">
                <h3>⚠️ เหตุผล</h3>
                <p>
                    เมื่อสินค้าถูกจ่ายออกไปแล้ว หมายความว่ามีการใช้งานจริงและมีการบันทึกประวัติการเคลื่อนไหวสต็อก (Stock Movement) แล้ว 
                    การแก้ไขจำนวนรับเข้าย้อนหลังจะทำให้เกิดความขัดแย้งในข้อมูล เช่น:
                </p>
                <ul style="margin-top: 15px; padding-left: 25px; line-height: 2;">
                    <li>สต็อกปัจจุบันอาจติดลบ</li>
                    <li>ประวัติการเคลื่อนไหวไม่สอดคล้องกัน</li>
                    <li>รายงาน Audit Trail จะไม่ถูกต้อง</li>
                </ul>
            </div>
            
            <div class="receipt-info">
                <h3>📋 ข้อมูลรายการรับเข้า</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">เลขที่รับเข้า:</span>
                        <span class="info-value"><?php echo htmlspecialchars($receipt['gr_number']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">วันที่รับ:</span>
                        <span class="info-value"><?php echo date('d/m/Y', strtotime($receipt['receipt_date'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">คลังสินค้า:</span>
                        <span class="info-value"><?php echo htmlspecialchars($receipt['warehouse_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">PO Number:</span>
                        <span class="info-value"><?php echo htmlspecialchars($receipt['po_number'] ?? '-'); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="item-list">
                <div class="item-list-header">
                    🔴 รายการสินค้าที่ถูกจ่ายออกแล้ว (<?php echo count($dispatchedItems); ?> รายการ)
                </div>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th style="width: 130px;">รหัสสินค้า</th>
                            <th>ชื่อสินค้า</th>
                            <th style="width: 120px;">รับเข้า</th>
                            <th style="width: 120px;">จ่ายออก</th>
                            <th style="width: 100px;">Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; ?>
                        <?php foreach ($dispatchedItems as $item): ?>
                        <tr>
                            <td style="text-align: center; font-weight: 600;"><?php echo $no++; ?></td>
                            <td><strong style="color: #2b6cb0;"><?php echo htmlspecialchars($item['SSP_Code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td style="text-align: right;">
                                <strong><?php echo number_format($item['quantity_received'], 2); ?></strong>
                                <?php echo htmlspecialchars($item['unit_symbol']); ?>
                            </td>
                            <td style="text-align: right;">
                                <span class="dispatched-badge">
                                    <?php echo number_format($item['dispatched_qty'], 2); ?> 
                                    <?php echo htmlspecialchars($item['unit_symbol']); ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <strong><?php echo htmlspecialchars($item['location_code']); ?></strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="solution-box">
                <h3>💡 ทางเลือกสำหรับคุณ</h3>
                <ul>
                    <li>ใช้ฟีเจอร์ <strong>Stock Adjustment</strong> เพื่อปรับสต็อกแบบมีเหตุผลและบันทึกประวัติ</li>
                    <li>ยกเลิกรายการรับเข้านี้และสร้างรายการใหม่ (ถ้าจำเป็นจริงๆ)</li>
                    <li>ติดต่อผู้ดูแลระบบเพื่อขอคำปรึกษาและความช่วยเหลือ</li>
                </ul>
            </div>
            
            <a href="goods_receipt_list.php" class="btn-back">
                ← กลับสู่รายการรับเข้าสินค้า
            </a>
        </div>
    </body>
    </html>
    <?php
    exit; // หยุดการทำงานทันที - ไม่แสดงฟอร์มแก้ไข
}

// ถ้าไม่มีการจ่ายออก → แสดงฟอร์มแก้ไขปกติ

// Load locations
$stmt = $pdo->prepare("
    SELECT location_id, location_code
    FROM Warehouse_Locations
    WHERE warehouse_id = ? AND (is_active = 1 OR is_active IS NULL)
    ORDER BY location_code
");
$stmt->execute([$receipt['warehouse_id']]);
$locations = $stmt->fetchAll();

// Load all warehouses (if not dispatched)
if (!$hasDispatch) {
    $warehouseStmt = $pdo->query("
        SELECT warehouse_id, warehouse_name
        FROM Warehouses
        WHERE is_active = 1 OR is_active IS NULL
        ORDER BY warehouse_name
    ");
    $allWarehouses = $warehouseStmt->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขรายการรับเข้า - <?php echo htmlspecialchars($receipt['gr_number']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Sarabun', 'Poppins', sans-serif;
            background: linear-gradient(to bottom, #E8D5C4, #F5E6D3);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            background: linear-gradient(135deg, #8B5A3C 0%, #6B4423 100%);
            padding: 18px 30px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(107, 68, 35, 0.3);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid #D2691E;
        }

        .header h1 {
            color: white;
            font-size: 22px;
            font-weight: 600;
        }

        .btn-back {
            background: linear-gradient(135deg, #D2691E 0%, #CD853F 100%);
            color: white;
            border: none;
            padding: 9px 18px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 2px 6px rgba(210, 105, 30, 0.3);
            transition: all 0.3s;
        }

        .btn-back:hover {
            background: linear-gradient(135deg, #CD853F 0%, #D2691E 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(210, 105, 30, 0.4);
        }

        .form-card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(139, 90, 60, 0.15);
            margin-bottom: 20px;
            border: 1px solid #D4A574;
        }

        .section-title {
            font-size: 17px;
            font-weight: 600;
            color: #5D4037;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #C9A068;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 18px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            color: #5D4037;
            font-weight: 500;
            margin-bottom: 6px;
            font-size: 14px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid #D4A574;
            border-radius: 6px;
            font-family: 'Sarabun', 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #8B5A3C;
            box-shadow: 0 0 0 3px rgba(139, 90, 60, 0.1);
        }

        .form-group input:disabled,
        .form-group textarea:disabled,
        .form-group select:disabled {
            background-color: #F5F5F5;
            color: #999;
            cursor: not-allowed;
            border-color: #DDD;
            opacity: 0.6;
        }

        .table-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 8px;
            border: 1px solid #D4A574;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            font-size: 14px;
        }

        th {
            background: linear-gradient(135deg, #5D4037 0%, #6D4C41 100%);
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
            font-size: 14px;
            border-bottom: 2px solid #8B5A3C;
        }

        td {
            padding: 10px 12px;
            border-bottom: 1px solid #E0E0E0;
            color: #424242;
        }

        tbody tr:hover {
            background: #FFF8F0;
        }

        td input,
        td select {
            width: 100%;
            padding: 7px 9px;
            border: 1.5px solid #D4A574;
            border-radius: 4px;
            font-size: 13px;
            transition: all 0.3s;
        }

        td input:focus,
        td select:focus {
            outline: none;
            border-color: #8B5A3C;
            box-shadow: 0 0 0 2px rgba(139, 90, 60, 0.1);
        }

        td input:disabled {
            background: #F5F5F5;
            color: #999;
            cursor: not-allowed;
            border-color: #DDD;
        }

        td input.changed {
            border-color: #D2691E;
            background: #FFF8F0;
            box-shadow: 0 0 0 2px rgba(210, 105, 30, 0.15);
        }

        .btn-primary {
            background: linear-gradient(135deg, #2E8B57 0%, #228B22 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            margin-top: 20px;
            box-shadow: 0 2px 8px rgba(34, 139, 34, 0.3);
            transition: all 0.3s;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #228B22 0%, #2E8B57 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(34, 139, 34, 0.4);
        }

        .btn-primary:disabled {
            background: linear-gradient(135deg, #9E9E9E 0%, #757575 100%);
            cursor: not-allowed;
            opacity: 0.6;
            transform: none;
        }

        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.6;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        .alert-warning {
            background: linear-gradient(135deg, #FFF8DC 0%, #FFE4B5 100%);
            color: #8B4513;
            border-left: 4px solid #D2691E;
        }

        .alert-info {
            background: linear-gradient(135deg, #E3F2FD 0%, #BBDEFB 100%);
            color: #1565C0;
            border-left: 4px solid #2196F3;
        }

        .alert-success {
            background: linear-gradient(135deg, #E8F5E9 0%, #C8E6C9 100%);
            color: #2E7D32;
            border-left: 4px solid #4CAF50;
        }

        .alert-error {
            background: linear-gradient(135deg, #FFEBEE 0%, #FFCDD2 100%);
            color: #C62828;
            border-left: 4px solid #F44336;
        }

        /* เพิ่ม style สำหรับ readonly fields */
        .readonly-field {
            background: repeating-linear-gradient(
                45deg,
                #f0f0f0,
                #f0f0f0 10px,
                #e0e0e0 10px,
                #e0e0e0 20px
            ) !important;
            cursor: not-allowed !important;
        }

        /* Style สำหรับ label required */
        label.required::after,
        label[required]::after {
            content: " *";
            color: #DC3545;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                gap: 10px;
                padding: 15px 20px;
            }
            
            .header h1 {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✏️ แก้ไขรายการรับเข้า #<?php echo htmlspecialchars($receipt['gr_number']); ?></h1>
            <a href="goods_receipt_list.php" class="btn-back">← กลับ</a>
        </div>

        <div class="alert alert-warning">
            ⚠️ <strong>คำเตือน:</strong> การแก้ไขจำนวนจะส่งผลต่อสต็อกในระบบ และจะบันทึกเป็น Stock Movement ประเภท ADJUSTMENT
        </div>

        <div class="alert alert-info">
            💡 <strong>หมายเหตุ:</strong> หากเปลี่ยนจำนวน ระบบจะปรับสต็อกอัตโนมัติ
        </div>

        <form id="editForm">
            <input type="hidden" name="gr_id" value="<?php echo $receipt['gr_id']; ?>">

            <!-- Header Info -->
            <div class="form-card">
                <div class="section-title">ข้อมูลทั่วไป</div>
                
                <?php if ($hasDispatch): ?>
                <div class="alert alert-warning">
                    ⚠️ <strong>มีการจ่ายออกแล้ว:</strong> ไม่สามารถแก้ไข LOT, Location, และคลังสินค้าได้
                </div>
                <?php endif; ?>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>เลขที่ใบรับ</label>
                        <input type="text" value="<?php echo htmlspecialchars($receipt['gr_number']); ?>" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label>PO Number</label>
                        <input type="text" value="<?php echo htmlspecialchars($receipt['po_number'] ?? '-'); ?>" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label>LOT Number</label>
                        <input type="text" value="<?php echo htmlspecialchars($receipt['lot_numbers'] ?? '-'); ?>" disabled title="LOT แก้ไขในตารางรายการสินค้า">
                    </div>
                    
                    <div class="form-group">
                        <label>Location</label>
                        <input type="text" value="<?php echo htmlspecialchars($receipt['locations'] ?? '-'); ?>" disabled title="Location แก้ไขในตารางรายการสินค้า">
                    </div>
                    
                    <div class="form-group">
                        <label>ผู้ขาย</label>
                        <input type="text" value="<?php echo htmlspecialchars($receipt['supplier_name'] ?? '-'); ?>" disabled title="ผู้ขายจะเปลี่ยนตาม PO ที่เลือก">
                    </div>
                    
                    <div class="form-group">
                        <label>คลังสินค้า</label>
                        <?php if ($hasDispatch): ?>
                            <input type="text" value="<?php echo htmlspecialchars($receipt['warehouse_name']); ?>" disabled>
                        <?php else: ?>
                            <select name="warehouse_id" id="warehouseSelect">
                                <?php foreach ($allWarehouses as $wh): ?>
                                <option value="<?php echo $wh['warehouse_id']; ?>" 
                                        <?php echo ($wh['warehouse_id'] == $receipt['warehouse_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>วันที่รับ <span style="color: red;">*</span></label>
                        <input type="date" name="receipt_date" value="<?php echo date('Y-m-d', strtotime($receipt['receipt_date'])); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Invoice Number</label>
                        <input type="text" name="invoice_number" value="<?php echo htmlspecialchars($receipt['invoice_number'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>หมายเหตุ</label>
                        <textarea name="notes" rows="3"><?php echo htmlspecialchars($receipt['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Items Table -->
            <div class="form-card">
                <div class="section-title">รายการสินค้า</div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 100px;">รหัสสินค้า</th>
                                <th>ชื่อสินค้า</th>
                                <th style="width: 120px;">จำนวนเดิม</th>
                                <th style="width: 120px;">จำนวนใหม่ <span style="color: #fef3c7;">*</span></th>
                                <th style="width: 100px;">Pallet เดิม</th>
                                <th style="width: 100px;">Pallet ใหม่</th>
                                <th style="width: 150px;">LOT</th>
                                <th style="width: 150px;">Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($item['SSP_Code']); ?></strong>
                                    <input type="hidden" class="item-id" value="<?php echo $item['gr_item_id']; ?>">
                                    <input type="hidden" class="product-id" value="<?php echo $item['product_id']; ?>">
                                    <input type="hidden" class="warehouse-id" value="<?php echo $item['warehouse_id']; ?>">
                                </td>
                                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                <td>
                                    <input type="number" class="old-quantity" value="<?php echo $item['quantity_received']; ?>" disabled>
                                </td>
                                <td>
                                    <input type="number" class="new-quantity" step="0.01" min="0" 
                                           value="<?php echo $item['quantity_received']; ?>" required>
                                </td>
                                <td>
                                    <input type="number" class="old-pallet" value="<?php echo $item['quantity_pallet'] ?? ''; ?>" disabled>
                                </td>
                                <td>
                                    <input type="number" class="new-pallet" step="0.01" min="0" 
                                           value="<?php echo $item['quantity_pallet'] ?? ''; ?>">
                                </td>
                                <td>
                                    <input type="text" class="batch-lot" value="<?php echo htmlspecialchars($item['batch_lot'] ?? ''); ?>">
                                </td>
                                <td>
                                    <select class="location-id">
                                        <?php foreach ($locations as $loc): ?>
                                        <option value="<?php echo $loc['location_id']; ?>" 
                                                <?php echo ($loc['location_id'] == $item['location_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($loc['location_code']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" class="btn-primary">💾 บันทึกการแก้ไข</button>
            </div>
        </form>

        <!-- Stock Movements History -->
        <?php if (count($movements) > 0): ?>
        <div class="form-card" style="margin-top: 20px;">
            <div class="section-title">📊 ประวัติการเคลื่อนไหวสต็อก</div>
            <div style="overflow-x: auto;">
                <table style="font-size: 13px;">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th style="width: 140px;">วันที่-เวลา</th>
                            <th style="width: 100px;">ประเภท</th>
                            <th style="width: 120px;">รหัสสินค้า</th>
                            <th>ชื่อสินค้า</th>
                            <th style="width: 100px;">Location</th>
                            <th style="width: 120px;">LOT</th>
                            <th style="width: 100px;">จำนวน</th>
                            <th style="width: 80px;">Pallet</th>
                            <th style="width: 100px;">ผู้ทำรายการ</th>
                            <th>หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $index => $mov): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($mov['movement_date'])); ?></td>
                            <td>
                                <?php
                                $typeColor = '#48bb78'; // IN = เขียว
                                $typeName = 'รับเข้า';
                                if ($mov['movement_type'] === 'OUT') {
                                    $typeColor = '#e53e3e'; // OUT = แดง
                                    $typeName = 'จ่ายออก';
                                } elseif ($mov['movement_type'] === 'TRANSFER') {
                                    $typeColor = '#4299e1'; // TRANSFER = น้ำเงิน
                                    $typeName = 'ย้าย';
                                } elseif ($mov['movement_type'] === 'ADJUSTMENT') {
                                    $typeColor = '#ed8936'; // ADJUSTMENT = ส้ม
                                    $typeName = 'ปรับปรุง';
                                }
                                ?>
                                <span style="background: <?php echo $typeColor; ?>; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 500;">
                                    <?php echo $typeName; ?>
                                </span>
                            </td>
                            <td><strong><?php echo htmlspecialchars($mov['SSP_Code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($mov['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($mov['location_code'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($mov['lot_number'] ?? '-'); ?></td>
                            <td><?php echo number_format($mov['quantity'], 2); ?> <?php echo htmlspecialchars($mov['unit_symbol'] ?? ''); ?></td>
                            <td><?php echo $mov['quantity_pallet'] ? number_format($mov['quantity_pallet'], 2) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($mov['created_by_name'] ?? '-'); ?></td>
                            <td style="font-size: 12px;"><?php echo htmlspecialchars($mov['notes'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div id="alertContainer"></div>
    </div>

    <script>
        // Highlight changed fields
        document.querySelectorAll('.new-quantity, .new-pallet').forEach(input => {
            const oldInput = input.closest('tr').querySelector(
                input.classList.contains('new-quantity') ? '.old-quantity' : '.old-pallet'
            );
            
            input.addEventListener('input', function() {
                const oldVal = parseFloat(oldInput.value) || 0;
                const newVal = parseFloat(this.value) || 0;
                
                if (oldVal !== newVal) {
                    this.classList.add('changed');
                } else {
                    this.classList.remove('changed');
                }
            });
        });

        // Form submission
        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const items = [];
            
            document.querySelectorAll('tbody tr').forEach(row => {
                items.push({
                    gr_item_id: row.querySelector('.item-id').value,
                    product_id: row.querySelector('.product-id').value,
                    warehouse_id: row.querySelector('.warehouse-id').value,
                    quantity_received: row.querySelector('.new-quantity').value,
                    quantity_pallet: row.querySelector('.new-pallet').value || null,
                    batch_lot: row.querySelector('.batch-lot').value,
                    location_id: row.querySelector('.location-id').value
                });
            });
            
            formData.append('action', 'update_receipt');
            formData.append('items', JSON.stringify(items));
            
            try {
                const response = await fetch('goods_receipt_edit.php?id=<?php echo $receiptId; ?>', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('success', result.message);
                    setTimeout(() => {
                        window.location.href = 'goods_receipt_list.php';
                    }, 1500);
                } else {
                    showAlert('error', result.error);
                }
            } catch (error) {
                showAlert('error', 'เกิดข้อผิดพลาด: ' + error.message);
            }
        });

        function showAlert(type, message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = message;
            
            const container = document.getElementById('alertContainer');
            container.innerHTML = '';
            container.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }
    </script>
</body>
</html>