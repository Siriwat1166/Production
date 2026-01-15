<?php
// direct-receiving.php – Enhanced version with improved database structure
// เพิ่มการแสดง error
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
    // ตั้ง header ก่อน output อะไรก็ตาม
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $action = $_GET['ajax'];

        // 1) โหลดสินค้าเฉพาะผู้ขายที่เลือก
        if ($action === 'get_products_by_supplier') {
            $supplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
            if ($supplierId <= 0) { 
                echo json_encode([]); 
                exit; 
            }

            $stmt = $pdo->prepare("
                SELECT 
                    id        AS product_id,
                    Name      AS product_name,
                    Unit_id   AS default_unit_id,
                    SSP_Code  AS product_code
                FROM Master_Products_ID
                WHERE is_active = 1 AND supplier_id = :sid
                ORDER BY Name
            ");
            $stmt->execute(['sid' => $supplierId]);
            echo json_encode($stmt->fetchAll());
            exit;
        }

        // 2) โหลดหน่วยของสินค้า
        if ($action === 'get_units_for_product') {
            $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
            if ($productId <= 0) { 
                echo json_encode(['units' => [], 'default_unit_id' => null]); 
                exit; 
            }

            $def = $pdo->prepare("SELECT Unit_id FROM Master_Products_ID WHERE id = :pid");
            $def->execute(['pid' => $productId]);
            $default_unit_id = $def->fetchColumn();

            $q = $pdo->prepare("
                SELECT 
                    pu.unit_id,
                    u.unit_code,
                    u.unit_name,
                    u.unit_symbol,
                    pu.is_purchase_unit,
                    pu.is_stock_unit,
                    pu.is_issue_unit
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

            echo json_encode([
                'units' => $units,
                'default_unit_id' => $default_unit_id ? (int)$default_unit_id : null
            ]);
            exit;
        }

        // 3) โหลด Location ตามคลัง
        if ($action === 'get_locations_by_warehouse') {
            $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
            if ($warehouseId <= 0) { 
                echo json_encode([]); 
                exit; 
            }

            $stmt = $pdo->prepare("
                SELECT location_id, location_code
                FROM Warehouse_Locations
                WHERE warehouse_id = :wid AND (is_active = 1 OR is_active IS NULL)
                ORDER BY location_code
            ");
            $stmt->execute(['wid' => $warehouseId]);
            echo json_encode($stmt->fetchAll());
            exit;
        }

        // 4) ค้นหาสินค้าจาก "รหัสสินค้า (SSP_Code)"
        if ($action === 'get_product_by_code') {
            $code = isset($_GET['code']) ? trim($_GET['code']) : '';
            if ($code === '') { 
                echo json_encode(['found' => false]); 
                exit; 
            }

            $stmt = $pdo->prepare("
                SELECT TOP 1
                    id         AS product_id,
                    Name       AS product_name,
                    Unit_id    AS default_unit_id,
                    SSP_Code   AS product_code,
                    supplier_id
                FROM Master_Products_ID
                WHERE is_active = 1 AND SSP_Code = :code
                ORDER BY id DESC
            ");
            $stmt->execute(['code' => $code]);
            $row = $stmt->fetch();

            if (!$row) { 
                echo json_encode(['found' => false]); 
                exit; 
            }

            // เอาชื่อ supplier กลับไปด้วย
            $supName = null;
            if (!empty($row['supplier_id'])) {
                $s = $pdo->prepare("SELECT supplier_name FROM Suppliers WHERE supplier_id = :sid");
                $s->execute(['sid' => (int)$row['supplier_id']]);
                $supName = $s->fetchColumn();
            }

            echo json_encode([
                'found' => true,
                'product_id'      => (int)$row['product_id'],
                'product_name'    => $row['product_name'],
                'default_unit_id' => $row['default_unit_id'] ? (int)$row['default_unit_id'] : null,
                'product_code'    => $row['product_code'],
                'supplier_id'     => $row['supplier_id'] ? (int)$row['supplier_id'] : null,
                'supplier_name'   => $supName
            ]);
            exit;
        }

        // 5) บันทึก Direct Receipt
        if ($action === 'save_direct_receipt') {
            error_log("AJAX save_direct_receipt called");
            error_log("POST data: " . print_r($_POST, true));
            
            $receiptData = [
                'receipt_number' => $_POST['receipt_number'] ?? '',
                'receipt_date' => $_POST['receipt_date'] ?? '',
                'supplier_id' => !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null,
                'receipt_reason' => $_POST['receipt_reason'] ?? '',
                'notes' => $_POST['notes'] ?? '',
                'items' => $_POST['items'] ?? []
            ];
            
            error_log("Processed receipt data: " . print_r($receiptData, true));
            
            $result = saveDirectReceipt($receiptData);
            
            error_log("Save result: " . print_r($result, true));
            
            echo json_encode($result);
            exit;
        }

        // 6) Test endpoint
        if ($action === 'test') {
            echo json_encode(['success' => true, 'message' => 'AJAX works!']);
            exit;
        }

        // ถ้าไม่ตรง action ไหน
        echo json_encode(['error' => 'Unknown action: ' . $action]);
        exit;

    } catch (Throwable $e) {
        error_log("AJAX Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error: ' . $e->getMessage(),
            'details' => $e->getTraceAsString()
        ]);
        exit;
    }
}

// === Preload dropdowns for page render ===
try {
    $Suppliers  = $pdo->query("SELECT supplier_id, supplier_name FROM Suppliers WHERE is_active = 1 ORDER BY supplier_name")->fetchAll();
    $Warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM Warehouses WHERE is_active = 1 OR is_active IS NULL ORDER BY warehouse_name")->fetchAll();
    $UnitsAll   = $pdo->query("SELECT unit_id, unit_code, unit_name, unit_symbol FROM Units WHERE is_active = 1 ORDER BY unit_name")->fetchAll();
} catch (Exception $e) {
    error_log("Error loading dropdown data: " . $e->getMessage());
    $Suppliers = [];
    $Warehouses = [];
    $UnitsAll = [];
}

// === Enhanced Functions for Database Operations ===
function saveDirectReceipt($data) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Validate required data
        if (empty($data['receipt_number']) || empty($data['receipt_date'])) {
            throw new Exception('Missing required fields: receipt_number or receipt_date');
        }
        
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new Exception('No items provided');
        }
        
        // Filter out empty items and calculate totals
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
        
        // Validate each item before processing
        foreach ($validItems as $item) {
            // ตรวจสอบว่า product_id มีอยู่จริง
            $checkProduct = $pdo->prepare("SELECT id FROM Master_Products_ID WHERE id = ? AND is_active = 1");
            $checkProduct->execute([$item['product_id']]);
            if (!$checkProduct->fetch()) {
                throw new Exception("Product ID {$item['product_id']} not found or inactive");
            }
            
            // ตรวจสอบว่า warehouse_id มีอยู่จริง
            if (!empty($item['warehouse_id'])) {
                $checkWarehouse = $pdo->prepare("SELECT warehouse_id FROM Warehouses WHERE warehouse_id = ?");
                $checkWarehouse->execute([$item['warehouse_id']]);
                if (!$checkWarehouse->fetch()) {
                    throw new Exception("Warehouse ID {$item['warehouse_id']} not found");
                }
            }
            
            // ตรวจสอบ unit_id ถ้ามี
            if (!empty($item['unit_id'])) {
                $checkUnit = $pdo->prepare("SELECT unit_id FROM Units WHERE unit_id = ? AND is_active = 1");
                $checkUnit->execute([$item['unit_id']]);
                if (!$checkUnit->fetch()) {
                    throw new Exception("Unit ID {$item['unit_id']} not found");
                }
            }
        }
        
        $totalItems = count($validItems);
        
        // Insert Direct_Receipt_Header - เฉพาะคอลัมน์ที่มีในฐานข้อมูล
        $headerSql = "
            INSERT INTO Direct_Receipt_Header (
                receipt_number, receipt_date, supplier_id, receipt_reason, 
                total_items, total_quantity, estimated_value, status, 
                created_by, created_date, notes
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, 'DRAFT', ?, GETDATE(), ?
            )
        ";
        
        $stmt = $pdo->prepare($headerSql);
        $stmt->execute([
            $data['receipt_number'],
            $data['receipt_date'],
            $data['supplier_id'],
            $data['receipt_reason'],
            $totalItems,
            $totalQuantity,
            $estimatedValue,
            $_SESSION['user_id'] ?? 1,
            $data['notes']
        ]);
        
        // Get the inserted ID
        $directReceiptId = $pdo->lastInsertId();
        if (!$directReceiptId) {
            throw new Exception('Failed to get direct receipt ID');
        }
        
        // Insert Direct_Receipt_Items - เฉพาะคอลัมน์ที่มีในฐานข้อมูล
        $itemSql = "
            INSERT INTO Direct_Receipt_Items (
                direct_receipt_id, line_number, product_id, item_description,
                quantity, unit_id, estimated_unit_cost, actual_unit_cost,
                warehouse_id, location_id, supplier_lot_number,
                manufacturing_date, expiry_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $itemStmt = $pdo->prepare($itemSql);
        
        foreach ($validItems as $index => $item) {
            // Get product description
            $productDesc = '';
            if (!empty($item['item_description'])) {
                $productDesc = $item['item_description'];
            } else if (!empty($item['product_id'])) {
                $descSql = "SELECT Name FROM Master_Products_ID WHERE id = ?";
                $descStmt = $pdo->prepare($descSql);
                $descStmt->execute([$item['product_id']]);
                $productDesc = $descStmt->fetchColumn() ?: 'Unknown Product';
            }
            
            // Convert dates
            $mfgDate = !empty($item['manufacturing_date']) ? $item['manufacturing_date'] : null;
            $expDate = !empty($item['expiry_date']) ? $item['expiry_date'] : null;
            
            $itemStmt->execute([
                $directReceiptId,
                $index + 1, // line_number
                !empty($item['product_id']) ? (int)$item['product_id'] : null,
                $productDesc,
                (float)$item['quantity'],
                !empty($item['unit_id']) ? (int)$item['unit_id'] : null,
                !empty($item['estimated_unit_cost']) ? (float)$item['estimated_unit_cost'] : null,
                !empty($item['actual_unit_cost']) ? (float)$item['actual_unit_cost'] : null,
                !empty($item['warehouse_id']) ? (int)$item['warehouse_id'] : null,
                !empty($item['location_id']) ? (int)$item['location_id'] : null,
                $item['supplier_lot_number'] ?? null,
                $mfgDate,
                $expDate
            ]);
        }
        
        // Update inventory และ stock movements
foreach ($validItems as $item) {
    if (!empty($item['product_id']) && !empty($item['warehouse_id']) && !empty($item['quantity'])) {
        updateInventoryStock(
            $item['product_id'],
            $item['warehouse_id'],
            $item['location_id'] ?? null,
            $item['quantity'],
            $item['estimated_unit_cost'] ?? 0,
            $item['pallet_count'] ?? 0  // ← แก้ไข: เพิ่ม 0 และปิดวงเล็บ
        );
        
        createStockMovement([
            'product_id' => $item['product_id'],
            'warehouse_id' => $item['warehouse_id'],
            'location_id' => $item['location_id'] ?? null,
            'movement_type' => 'RECEIPT',
            'quantity' => $item['quantity'],
            'unit_id' => $item['unit_id'] ?? null,
            'unit_cost' => $item['estimated_unit_cost'] ?? 0,
            'pallet_count' => $item['pallet_count'] ?? 0,  // ← เพิ่ม: pallet_count
            'reference_type' => 'DIRECT_RECEIPT',
            'reference_id' => $directReceiptId,
            'reference_number' => $data['receipt_number'],
            'batch_lot' => $item['supplier_lot_number'] ?? null
        ]);
        
        // Create lot tracking if supplier lot number provided
        if (!empty($item['supplier_lot_number'])) {
            createLotBatchTracking([
                'product_id' => $item['product_id'],
                'supplier_id' => $data['supplier_id'],
                'supplier_lot_number' => $item['supplier_lot_number'],
                'quantity_received' => $item['quantity'],
                'manufacturing_date' => $mfgDate,
                'expiry_date' => $expDate,
                'warehouse_id' => $item['warehouse_id'],
                'location_id' => $item['location_id'] ?? null,
                'unit_id' => $item['unit_id'] ?? null
            ]);
        }
    }
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
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollback();
        }
        error_log("Save Direct Receipt Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        return [
            'success' => false,
            'message' => $e->getMessage()
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
            // คำนวณ average cost ใหม่
            $currentValue = $existing['current_stock'] * $existing['average_cost'];
            $newValue = $quantity * $unitCost;
            $totalQuantity = $existing['current_stock'] + $quantity;
            $newAverageCost = $totalQuantity > 0 ? ($currentValue + $newValue) / $totalQuantity : $unitCost;
            
            $updateSql = "
                UPDATE Inventory_Stock 
                SET current_stock = ISNULL(current_stock, 0) + ?,
                    available_stock = ISNULL(available_stock, 0) + ?,
                    current_pallet = ISNULL(current_pallet, 0) + ?,
                    available_pallet = ISNULL(available_pallet, 0) + ?,
                    average_cost = ?,
                    last_cost = ?,
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
                $newAverageCost, 
                $unitCost, 
                $existing['inventory_id']
            ]);
        } else {
            $insertSql = "
                INSERT INTO Inventory_Stock (
                    product_id, warehouse_id, location_id, current_stock, 
                    available_stock, current_pallet, available_pallet,
                    average_cost, last_cost, last_updated, last_movement_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
            ";
            
            $stmt = $pdo->prepare($insertSql);
            $stmt->execute([
                $productId, $warehouseId, $locationId, 
                $quantity, $quantity, 
                $palletCount, $palletCount,
                $unitCost, $unitCost
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
            $data['pallet_count'] ?? 0,  // เพิ่ม quantity_pallet
            $_SESSION['user_id'] ?? 1,
            'Direct Receipt - ' . ($data['reference_number'] ?? '')
        ]);
        
    } catch (Exception $e) {
        error_log("Create Stock Movement Error: " . $e->getMessage());
        throw $e;
    }
}

function createLotBatchTracking($data) {
    global $pdo;
    
    try {
        // Check if lot already exists
        $checkSql = "
            SELECT lot_track_id FROM Lot_Batch_Tracking 
            WHERE supplier_lot_number = ? AND product_id = ?
        ";
        
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([$data['supplier_lot_number'], $data['product_id']]);
        $existingLot = $stmt->fetch();
        
        if ($existingLot) {
            // Update existing lot
            $updateSql = "
                UPDATE Lot_Batch_Tracking 
                SET quantity_received = quantity_received + ?,
                    quantity_remaining = quantity_remaining + ?,
                    last_updated = GETDATE()
                WHERE lot_track_id = ?
            ";
            
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute([
                $data['quantity_received'],
                $data['quantity_received'],
                $_SESSION['user_id'] ?? 1,
                $existingLot['lot_track_id']
            ]);
        } else {
            // Create new lot record
        $insertSql = "
                INSERT INTO Lot_Batch_Tracking (
                    product_id, supplier_id, supplier_lot_number, 
                    manufacturing_date, expiry_date, lot_status,
                    quantity_received, quantity_remaining, unit_id,
                    warehouse_id, location_id, quality_status,
                    pallet_count,
                    received_date, created_by, created_date
                ) VALUES (?, ?, ?, ?, ?, 'ACTIVE', ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?, GETDATE())
            ";

            
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute([
                $data['product_id'],
                $data['supplier_id'] ?? null,
                $data['supplier_lot_number'],
                $data['manufacturing_date'],
                $data['expiry_date'],
                $data['quantity_received'],
                $data['quantity_received'],
                $data['unit_id'] ?? null,
                $data['warehouse_id'],
                $data['location_id'],
                'APPROVE',   // quality_status
                $data['pallet_count'] ?? 0, // ✅ จำนวนพาเลท
                $_SESSION['user_id'] ?? 1
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Create Lot Batch Tracking Error: " . $e->getMessage());
        throw $e;
    }
}

// Helper
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$receiptNumber = 'DR-' . date('Ymd-His');
?>


<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Direct Receipt - รับเข้าไม่มี PO</title>
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

        .table > :not(caption) > * > * { 
            vertical-align: middle; 
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

        @media (min-width: 1400px){ 
            .container { 
                max-width: 96vw; 
            } 
        }

        /* Form styling to match inventory_view.php */
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

        /* Item card styling */
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

        .item-card .subnote { 
            font-size: .82rem; 
            color: #6b7280; 
            margin-top: 5px;
        }

        .item-card .remove-btn { 
            position: absolute; 
            right: 15px; 
            bottom: 15px; 
            background: var(--danger-color);
            border: none;
            border-radius: 8px;
            padding: 8px 12px;
            color: white;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }

        .item-card .remove-btn:hover {
            background: #b91c1c;
            transform: scale(1.05);
        }

        /* Button styling */
        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            border-radius: 10px;
            padding: 12px 20px;
            font-weight: bold;
            color: white;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(139, 69, 19, 0.3);
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

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(5, 150, 105, 0.3);
        }

        .btn-outline-secondary {
            border: 2px solid rgba(139, 69, 19, 0.3);
            color: var(--primary-color);
            border-radius: 10px;
            padding: 12px 20px;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .btn-outline-secondary:hover {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        /* Header styling */
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

        /* Input group styling */
        .input-group .btn-outline-secondary {
            border-left: none;
            border-color: rgba(139, 69, 19, 0.2);
        }

        /* Feedback เมื่อไม่พบรหัส */
        .is-invalid { 
            border-color: var(--danger-color) !important; 
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        /* Loading and status indicators */
        .loading-indicator {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(139, 69, 19, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Navigation back button */
        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            border-radius: 10px;
            padding: 8px 12px;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
        }

        /* Enhanced fields styling */
        .enhanced-fields {
            background: rgba(139, 69, 19, 0.05);
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
        }

        .enhanced-fields .form-label {
            color: var(--accent-color);
        }

        /* Date input styling */
        input[type="date"] {
            position: relative;
        }

        /* Quality status badge */
        .quality-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 600;
        }

        .quality-approved { background: #d1fae5; color: #065f46; }
        .quality-inspecting { background: #fef3c7; color: #92400e; }
        .quality-rejected { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <a href="/PD/production/inventory/inventory_view.php" class="btn back-btn me-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h5 class="navbar-brand mb-0">
                        <i class="fas fa-truck-loading me-2"></i>รับเข้าสินค้าโดยตรง (ไม่มี PO)
                    </h5>
                    <small class="text-light">จัดการรับเข้าสินค้าที่ไม่ผ่านใบสั่งซื้อ</small>
                </div>
            </div>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-th-large me-1"></i> รายการรอรับ
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
                    <i class="fas fa-box-open"></i>
                    รับเข้าสินค้าโดยตรง (ไม่มี PO)
                </h3>
                <span class="badge badge-soft">
                    <i class="fas fa-file-alt me-1"></i>เอกสารชั่วคราว
                </span>
            </div>
        </div>

        <!-- Main Form Card -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-edit me-2"></i>ข้อมูลใบรับเข้า
            </div>
            <div class="card-body">
                <form id="directReceiptForm" autocomplete="off">
                    <div class="row g-4">
                        <div class="col-md-3">
                            <label class="form-label upper">เลขที่เอกสาร</label>
                            <input type="text" class="form-control" name="receipt_number" value="<?php echo h($receiptNumber); ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label upper">วันที่รับเข้า</label>
                            <input type="date" class="form-control" name="receipt_date" value="<?php echo h(date('Y-m-d')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label upper">ผู้ขาย</label>
                            <select class="form-select" name="supplier_id" id="supplierSelect" required>
                                <option value="">-- เลือกผู้ขาย --</option>
                                <?php foreach ($Suppliers as $s): ?>
                                    <option value="<?php echo (int)$s['supplier_id']; ?>"><?php echo h($s['supplier_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="small-muted mt-1">เลือกผู้ขาย หรือ "ป้อนรหัสสินค้า" ในรายการเพื่อให้ผู้ขายถูกเลือกอัตโนมัติ</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label upper">เหตุผลการรับเข้า</label>
                            <input type="text" class="form-control" name="receipt_reason" placeholder="เช่น รับตัวอย่าง, รับคืน, วัสดุสิ้นเปลืองเข้าคลัง ฯลฯ">
                        </div>
                        <div class="col-12">
                            <label class="form-label upper">หมายเหตุ</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"></textarea>
                        </div>
                    </div>

                    <!-- Enhanced Transport Information -->
                    <div class="enhanced-fields">
                        <h6 class="mb-3"><i class="fas fa-truck me-2"></i>ข้อมูลการขนส่ง (เพิ่มเติม)</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label upper">บริษัทขนส่ง</label>
                                <input type="text" class="form-control" name="transport_company" placeholder="ชื่อบริษัทขนส่ง">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label upper">ชื่อคนขับ</label>
                                <input type="text" class="form-control" name="driver_name" placeholder="ชื่อผู้ขับขี่">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label upper">ทะเบียนรถ</label>
                                <input type="text" class="form-control" name="vehicle_number" placeholder="หมายเลขทะเบียน">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Items Card -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-list me-2"></i>รายการสินค้า</span>
                <button type="button" class="btn btn-light btn-sm" id="btnAddCard">
                    <i class="fas fa-plus me-1"></i>เพิ่มรายการ
                </button>
            </div>

            <div class="card-body">
                <div id="itemsCards" class="d-flex flex-column"></div>
            </div>

            <div class="sticky-actions d-flex gap-3 justify-content-end">
                <a href="receiving.php" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i>ยกเลิก
                </a>
                <button type="button" class="btn btn-success" id="btnSaveDraft">
                    <i class="fas fa-save me-1"></i>บันทึกใบรับเข้า
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/** ======= Bootstrap data from PHP ======= */
const WAREHOUSES = <?php echo json_encode($Warehouses, JSON_UNESCAPED_UNICODE); ?>;
const UNITS_ALL  = <?php echo json_encode($UnitsAll,    JSON_UNESCAPED_UNICODE); ?>;

/** ======= State ======= */
let rowIndex = 0;
let cachedProducts = [];      // set after supplier selection
let supplierIdCurrent = null;

/** ======= DOM ======= */
const $itemsCards = document.getElementById('itemsCards');
const $btnAddCard = document.getElementById('btnAddCard');
const $supplier   = document.getElementById('supplierSelect');

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
function warehouseOptionsHtml() {
  let html = `<option value="">-- เลือกคลัง --</option>`;
  for (const w of WAREHOUSES) html += `<option value="${w.warehouse_id}">${escapeHtml(w.warehouse_name)}</option>`;
  return html;
}
function productOptionsHtml() {
  if (!cachedProducts.length) return `<option value="">-- เลือกผู้ขายก่อน --</option>`;
  let html = `<option value="">-- เลือกสินค้า --</option>`;
  for (const p of cachedProducts) {
    const code = p.product_code ?? "";
    const defU = p.default_unit_id ?? "";
    html += `<option value="${p.product_id}" data-code="${escapeHtml(code)}" data-default-unit="${defU}">${escapeHtml(p.product_name)}</option>`;
  }
  return html;
}

/** ======= Supplier Loader (used by both manual change & code-search) ======= */
async function loadSupplierProducts(sid){
  const allProd = $itemsCards.querySelectorAll('.product-select');
  for (var i=0;i<allProd.length;i++){
    allProd[i].innerHTML = '<option value="">กำลังโหลดสินค้า...</option>';
    allProd[i].disabled = true;
  }
  try{
    const r = await fetch('?ajax=get_products_by_supplier&supplier_id='+encodeURIComponent(sid));
    const data = await r.json();
    cachedProducts = Array.isArray(data) ? data : [];
    for (var k=0;k<allProd.length;k++){
      allProd[k].innerHTML = productOptionsHtml();
      allProd[k].disabled = false;
    }
  }catch(e){
    cachedProducts = [];
    for (var m=0;m<allProd.length;m++){
      allProd[m].innerHTML = '<option value="">โหลดสินค้าไม่สำเร็จ</option>';
      allProd[m].disabled = true;
    }
  }
}

/** ======= Build one item card with enhanced fields ======= */
function buildItemCard(idx){
    const wrap = document.createElement('div');
    wrap.className = 'item-card';
    wrap.setAttribute('data-row', idx);

    wrap.innerHTML = `
        <div class="row g-3">
            <!-- Product Code Search -->
            <div class="col-lg-4">
                <label class="form-label upper">รหัสสินค้า (พิมพ์/สแกน)</label>
                <div class="input-group">
                    <input type="text" class="form-control product-code-input" name="items[${idx}][product_code]" placeholder="พิมพ์หรือสแกนรหัสสินค้า...">
                    <button class="btn btn-outline-secondary code-search-btn" type="button">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <div class="subnote">หรือเลือกจากชื่อสินค้า</div>
                <select class="form-select product-select mt-1" name="items[${idx}][product_id]" ${cachedProducts.length ? '' : 'disabled'}>
                    ${productOptionsHtml()}
                </select>
                <input type="hidden" name="items[${idx}][item_description]">
            </div>

            <!-- Warehouse & Location -->
            <div class="col-lg-3">
                <label class="form-label upper">คลัง</label>
                <select class="form-select warehouse-select" name="items[${idx}][warehouse_id]">
                    ${warehouseOptionsHtml()}
                </select>
                <label class="form-label upper mt-2">ตำแหน่ง</label>
                <select class="form-select location-select" name="items[${idx}][location_id]" disabled>
                    <option value="">เลือก Location</option>
                </select>
            </div>

            <!-- Quantity & Unit -->
            <div class="col-lg-3">
                <label class="form-label upper">จำนวน</label>
                <input type="number" step="0.0001" min="0" class="form-control text-end" name="items[${idx}][quantity]" placeholder="0.00">
                <label class="form-label upper mt-2">หน่วย</label>
                <select class="form-select unit-select" name="items[${idx}][unit_id]">
                    ${unitsOptionsHtml(UNITS_ALL, null)}
                </select>
            </div>

            <!-- Remove Button -->
            <div class="col-lg-2 d-flex align-items-start justify-content-end">
                <button type="button" class="btn btn-danger btn-sm remove-btn" onclick="removeItemCard(${idx})">
                    <i class="fas fa-trash"></i>
                </button>
            </div>

            <!-- Enhanced Fields Row 2 -->
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
<!-- Pallet Count -->
<div class="col-lg-6">
    <label class="form-label upper">จำนวนพาเลท</label>
    <input type="number" min="0" class="form-control text-end" name="items[${idx}][pallet_count]" placeholder="0">
</div>

<!-- Cost Field -->
<div class="col-lg-6">
    <label class="form-label upper">ราคาต่อหน่วย (ประเมิน)</label>
    <input type="number" step="0.01" min="0" class="form-control text-end" name="items[${idx}][estimated_unit_cost]" placeholder="0.00">
</div>

        </div>
    `;

    setTimeout(function(){ wireItemEvents(idx); }, 0);
    return wrap;
}

/** ======= Wire events for one card ======= */
function wireItemEvents(idx){
  const card = $itemsCards.querySelector('.item-card[data-row="'+idx+'"]');
  if (!card) return;

  const productSelect   = card.querySelector('.product-select');
  const unitSelect      = card.querySelector('.unit-select');
  const descHidden      = card.querySelector('input[name="items['+idx+'][item_description]"]');
  const codeInput       = card.querySelector('.product-code-input');
  const codeBtn         = card.querySelector('.code-search-btn');
  const warehouseSelect = card.querySelector('.warehouse-select');
  const locationSelect  = card.querySelector('.location-select');

  // เลือกสินค้าจากชื่อ → ซิงค์รหัส + หน่วย + คำอธิบาย
  if (productSelect){
    productSelect.addEventListener('change', async function(){
      const pid = Number(this.value || 0);
      const opt = this.options[this.selectedIndex];
      const code = (opt && opt.dataset) ? opt.dataset.code : '';

      if (codeInput) codeInput.value = code || '';
      if (descHidden) descHidden.value = pid ? opt.textContent.trim() : '';

      if (pid){
        try{
          const r = await fetch('?ajax=get_units_for_product&product_id='+pid);
          const data = await r.json();
          const units = Array.isArray(data.units) ? data.units : [];
          const defUnit = data.default_unit_id || (opt && opt.dataset ? opt.dataset.defaultUnit : null);
          unitSelect.innerHTML = unitsOptionsHtml(units, defUnit);
        }catch(e){
          unitSelect.innerHTML = unitsOptionsHtml(UNITS_ALL, null);
        }
      } else {
        unitSelect.innerHTML = unitsOptionsHtml(UNITS_ALL, null);
      }
    });
  }

  // คลัง → โหลด Location
  if (warehouseSelect && locationSelect){
    warehouseSelect.addEventListener('change', async function(){
      const wid = Number(this.value || 0);
      locationSelect.innerHTML = '<option value="">เลือก Location</option>';
      locationSelect.disabled = true;
      if (!wid) return;
      try{
        const r = await fetch('?ajax=get_locations_by_warehouse&warehouse_id='+wid);
        const data = await r.json();
        for (var i=0;i<data.length;i++){
          var loc = data[i];
          var o = document.createElement('option');
          o.value = loc.location_id;
          o.textContent = loc.location_code;
          locationSelect.appendChild(o);
        }
        locationSelect.disabled = false;
      }catch(e){
        locationSelect.disabled = true;
      }
    });
  }

  // ค้นหาด้วย "รหัสสินค้า" → ตั้ง Supplier อัตโนมัติ + เลือกสินค้า
  async function searchByCode(){
    if (!codeInput) return;
    const raw = codeInput.value ? codeInput.value.trim() : '';
    if (!raw){ 
      codeInput.classList.add('is-invalid'); 
      setTimeout(()=>codeInput.classList.remove('is-invalid'), 1500); 
      return; 
    }

    try{
      const r = await fetch('?ajax=get_product_by_code&code='+encodeURIComponent(raw));
      const data = await r.json();
      if (!data || !data.found){
        codeInput.classList.add('is-invalid');
        setTimeout(()=>codeInput.classList.remove('is-invalid'), 1500);
        return;
      }

      // 1) ตั้ง Supplier header ถ้ายังไม่ตรง
      const sid = String(data.supplier_id || '');
      if (sid && $supplier.value !== sid){
        $supplier.value = sid;              // เซ็ตค่าลง header
        await loadSupplierProducts(sid);    // โหลดสินค้าเฉพาะผู้ขายนั้น
      } else if (sid && !$supplier.value){
        $supplier.value = sid;
        await loadSupplierProducts(sid);
      } else if ($supplier.value) {
        // supplier เดิม = เดิม → อาจยังไม่ได้โหลดสินค้า (กดดีเพิ่งเข้า)
        if (cachedProducts.length === 0) await loadSupplierProducts($supplier.value);
      }

      // 2) เลือกสินค้าให้การ์ดนี้ + เติมหน่วย/คำอธิบาย
      if (productSelect){
        productSelect.innerHTML = productOptionsHtml();
        productSelect.value = String(data.product_id);
        // trigger change programmatically
        productSelect.dispatchEvent(new Event('change'));
      }
      // sync code (canonical) อีกครั้ง
      codeInput.value = data.product_code || raw;

    }catch(e){
      codeInput.classList.add('is-invalid');
      setTimeout(()=>codeInput.classList.remove('is-invalid'), 1500);
    }
  }

  if (codeBtn) codeBtn.addEventListener('click', searchByCode);
  if (codeInput){
    codeInput.addEventListener('keydown', function(ev){
      if (ev.key === 'Enter'){ ev.preventDefault(); searchByCode(); }
    });
    codeInput.addEventListener('blur', function(){
      if (this.value && !productSelect.value) { searchByCode(); }
    });
  }
}

/** ======= Actions: add/remove ======= */
function addItemCard(){
  const idx = rowIndex++;
  const card = buildItemCard(idx);
  $itemsCards.appendChild(card);
}
function removeItemCard(idx){
  const card = $itemsCards.querySelector('.item-card[data-row="'+idx+'"]');
  const count = $itemsCards.querySelectorAll('.item-card').length;
  if (!card) return;

  if (count <= 1){
    var inputs = card.querySelectorAll('input');
    for (var i=0;i<inputs.length;i++) inputs[i].value = '';
    var selects = card.querySelectorAll('select');
    for (var j=0;j<selects.length;j++) selects[j].selectedIndex = 0;
    return;
  }
  card.remove();
}

/** ======= Supplier change => load products (manual change) ======= */
$supplier.addEventListener('change', async function(){
  const sid = this.value;
  supplierIdCurrent = sid || null;
  if (!sid){
    cachedProducts = [];
    // รีเซ็ต dropdown สินค้าทุกการ์ด
    const sels = $itemsCards.querySelectorAll('.product-select');
    for (var i=0;i<sels.length;i++){
      sels[i].innerHTML = '<option value="">-- เลือกผู้ขายก่อน --</option>';
      sels[i].disabled = true;
    }
    return;
  }
  await loadSupplierProducts(sid);
});

/** ======= Init ======= */
$btnAddCard.addEventListener('click', addItemCard);

// สร้างการ์ดแรกให้แน่นอน
document.addEventListener('DOMContentLoaded', function(){
  if (!$itemsCards.querySelector('.item-card')) addItemCard();
});
setTimeout(function(){
  if (!$itemsCards.querySelector('.item-card')) addItemCard();
}, 0);

// เปลี่ยนปุ่มบันทึกให้ทำงานจริง
var saveBtn = document.getElementById('btnSaveDraft');
if (saveBtn) {
    // Enable button
    saveBtn.disabled = false;
    saveBtn.addEventListener('click', function() {
        saveDirectReceipt();
    });
}

/** ======= Enhanced Save Direct Receipt Function ======= */
async function saveDirectReceipt() {
    // Validate form - Header fields
    const receiptNumber = document.querySelector('input[name="receipt_number"]').value;
    const receiptDate = document.querySelector('input[name="receipt_date"]').value;
    const supplierId = document.querySelector('select[name="supplier_id"]').value;
    const receiptReason = document.querySelector('input[name="receipt_reason"]').value;
    const notes = document.querySelector('textarea[name="notes"]').value;
    
    if (!receiptNumber || !receiptDate) {
        alert('กรุณากรอกเลขที่เอกสารและวันที่รับเข้า');
        return;
    }
    
    // Collect items data - เฉพาะฟิลด์ที่มีในฐานข้อมูล
    const itemCards = document.querySelectorAll('.item-card');
    const items = [];
    let hasValidItems = false;
    
    itemCards.forEach((card, index) => {
        const productId = card.querySelector('select[name*="[product_id]"]').value;
        const quantity = card.querySelector('input[name*="[quantity]"]').value;
        const unitId = card.querySelector('select[name*="[unit_id]"]').value;
        const warehouseId = card.querySelector('select[name*="[warehouse_id]"]').value;
        const locationId = card.querySelector('select[name*="[location_id]"]').value;
        const supplierLot = card.querySelector('input[name*="[supplier_lot_number]"]').value;
        const estimatedUnitCost = card.querySelector('input[name*="[estimated_unit_cost]"]').value;
        const manufacturingDate = card.querySelector('input[name*="[manufacturing_date]"]').value;
        const expiryDate = card.querySelector('input[name*="[expiry_date]"]').value;
        const itemDescription = card.querySelector('input[name*="[item_description]"]').value;
        const palletCount = card.querySelector('input[name*="[pallet_count]"]').value;
        
        if (productId && quantity && parseFloat(quantity) > 0) {
            hasValidItems = true;
    items.push({
        product_id: parseInt(productId),
        quantity: parseFloat(quantity),
        unit_id: unitId ? parseInt(unitId) : null,
        warehouse_id: warehouseId ? parseInt(warehouseId) : null,
        location_id: locationId ? parseInt(locationId) : null,
        supplier_lot_number: supplierLot || null,
        estimated_unit_cost: estimatedUnitCost ? parseFloat(estimatedUnitCost) : null,
        actual_unit_cost: estimatedUnitCost ? parseFloat(estimatedUnitCost) : null,
        manufacturing_date: manufacturingDate || null,
        expiry_date: expiryDate || null,
        item_description: itemDescription || null,
        pallet_count: palletCount ? parseInt(palletCount) : 0

    });
        }
    });
    
    if (!hasValidItems) {
        alert('กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ และกรอกข้อมูลให้ครบถ้วน');
        return;
    }
    
    // Show loading
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังบันทึก...';
    
    try {
        const formData = new URLSearchParams();
        formData.append('ajax', 'save_direct_receipt');
        formData.append('receipt_number', receiptNumber);
        formData.append('receipt_date', receiptDate);
        formData.append('supplier_id', supplierId || '');
        formData.append('receipt_reason', receiptReason || '');
        formData.append('notes', notes || '');
        
        // Add items data
        items.forEach((item, index) => {
            Object.keys(item).forEach(key => {
                if (item[key] !== null && item[key] !== undefined) {
                    formData.append(`items[${index}][${key}]`, item[key]);
                }
            });
        });
        
        console.log('Sending request to:', window.location.href + '?ajax=save_direct_receipt');
        console.log('Form data:', Object.fromEntries(formData));
        
        const response = await fetch(window.location.href + '?ajax=save_direct_receipt', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData
        });
        
        console.log('Response status:', response.status);
        console.log('Response headers:', Object.fromEntries(response.headers));
        
        // Check if response is JSON
        const contentType = response.headers.get('content-type');
        console.log('Content-Type:', contentType);
        
        if (!contentType || !contentType.includes('application/json')) {
            const htmlText = await response.text();
            console.error('Server returned HTML instead of JSON:', htmlText);
            
            // Try to find PHP error message
            const errorMatch = htmlText.match(/Fatal error: (.*?) in/i) || 
                              htmlText.match(/Parse error: (.*?) in/i) ||
                              htmlText.match(/<b>(.*?)<\/b>: (.*?) in <b>/i);
                              
            if (errorMatch) {
                alert('PHP Error: ' + (errorMatch[1] || errorMatch[2]));
            } else {
                alert('เกิด PHP Error - ดู Console สำหรับรายละเอียด');
            }
            return;
        }
        
        const result = await response.json();
        console.log('JSON result:', result);
        
        if (result.success) {
            // Success message
            let successMessage = 'บันทึกข้อมูลสำเร็จ!\n\n';
            successMessage += 'เลขที่เอกสาร: ' + result.receipt_number + '\n';
            successMessage += 'ID: ' + result.direct_receipt_id + '\n';
            successMessage += 'จำนวนรายการ: ' + result.total_items + '\n';
            successMessage += 'จำนวนรวม: ' + result.total_quantity + '\n';
            if (result.estimated_value > 0) {
                successMessage += 'มูลค่าประเมิน: ' + result.estimated_value.toLocaleString() + ' บาท\n';
            }
            
            alert(successMessage);
            
            // Ask user what to do next
            if (confirm('ต้องการสร้างใบรับเข้าใหม่หรือไม่?\n\nตกลง = สร้างใหม่\nยกเลิก = กลับไปหน้ารายการ')) {
                window.location.reload();
            } else {
                window.location.href = 'inventory_view.php';
            }
        } else {
            alert('เกิดข้อผิดพลาด: ' + result.message);
        }
        
    } catch (error) {
        console.error('Request failed:', error);
        alert('เกิดข้อผิดพลาด: ' + error.message);
    } finally {
        // Restore button
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    }
}

/** ======= Auto-save functionality (optional) ======= */
let autoSaveTimer;
function triggerAutoSave() {
    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(() => {
        // Auto-save logic here if needed
        console.log('Auto-save triggered');
    }, 120000); // 2 minutes
}

// Trigger auto-save on form changes
document.addEventListener('input', triggerAutoSave);
document.addEventListener('change', triggerAutoSave);

/** ======= Enhanced validation ======= */
function validateForm() {
    let isValid = true;
    const errors = [];
    
    // Check header fields
    const receiptNumber = document.querySelector('input[name="receipt_number"]').value;
    const receiptDate = document.querySelector('input[name="receipt_date"]').value;
    
    if (!receiptNumber) {
        errors.push('กรุณากรอกเลขที่เอกสาร');
        isValid = false;
    }
    
    if (!receiptDate) {
        errors.push('กรุณาเลือกวันที่รับเข้า');
        isValid = false;
    }
    
    // Check items
    const itemCards = document.querySelectorAll('.item-card');
    let hasValidItems = false;
    
    itemCards.forEach((card, index) => {
        const productId = card.querySelector('select[name*="[product_id]"]').value;
        const quantity = card.querySelector('input[name*="[quantity]"]').value;
        const warehouseId = card.querySelector('select[name*="[warehouse_id]"]').value;
        
        if (productId && quantity && parseFloat(quantity) > 0 && warehouseId) {
            hasValidItems = true;
        }
    });
    
    if (!hasValidItems) {
        errors.push('กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ พร้อมระบุคลังและจำนวน');
        isValid = false;
    }
    
    return { isValid, errors };
}

/** ======= Real-time validation indicators ======= */
function showValidationFeedback() {
    const validation = validateForm();
    const saveBtn = document.getElementById('btnSaveDraft');
    
    if (validation.isValid) {
        saveBtn.classList.remove('btn-outline-success');
        saveBtn.classList.add('btn-success');
        saveBtn.disabled = false;
    } else {
        saveBtn.classList.remove('btn-success');
        saveBtn.classList.add('btn-outline-success');
        saveBtn.title = validation.errors.join('\n');
    }
}

// Update validation on form changes
document.addEventListener('input', showValidationFeedback);
document.addEventListener('change', showValidationFeedback);

// Initial validation check
setTimeout(showValidationFeedback, 1000);
</script>
</body>
</html>