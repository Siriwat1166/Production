<?php
// receiving.php - Direct Mode with Inventory Update & Pallet Support
// ✅ เพิ่ม: อัปเดต Inventory_Stock และ Stock_Movements เหมือน PO Mode

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin', 'editor']);

$mode = 'direct';

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

// ===== HELPER FUNCTIONS =====

// ✅ ฟังก์ชันอัปเดต Inventory_Stock (เหมือน PO Mode)
function updateInventoryStock($pdo, $product_id, $warehouse_id, $location_id, $unit_id, $quantity, $lot_number, $mfg_date, $exp_date, $pallet_count = null, $movement_date = null, $package_qty = null) {
    
    // 🔥 DEBUG LOG - เพิ่มเพื่อดูว่า function ถูกเรียกหรือไม่
    error_log("========================================");
    error_log("🔍 updateInventoryStock CALLED");
    error_log("Product ID: {$product_id}");
    error_log("Quantity (weight): {$quantity}");
    error_log("Package Qty: " . ($package_qty ?? 'NULL'));
    
    $stmt = $pdo->prepare("
        SELECT inventory_id, current_stock, current_pallet
        FROM Inventory_Stock 
        WHERE product_id = ? 
        AND warehouse_id = ? 
        AND location_id = ?
    ");
    $stmt->execute([$product_id, $warehouse_id, $location_id]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 🔥 เลือกค่าที่จะบันทึก
    if ($package_qty !== null && $package_qty > 0) {
        // ถ้ามีจำนวนกระป๋อง → ใช้จำนวนกระป๋อง
        $stockQuantity = $package_qty;
        error_log("✅ USING PACKAGE: {$package_qty} cans");
    } else {
        // ถ้าไม่มี → ใช้น้ำหนัก/จำนวนปกติ
        $stockQuantity = $quantity;
        error_log("ℹ️ USING WEIGHT/QTY: {$quantity}");
    }
    
    error_log("💾 Will save to current_stock: {$stockQuantity}");
    
    if ($stock) {
        // Update existing
        error_log("📝 Updating existing stock (current: {$stock['current_stock']})");
        
        $stmt = $pdo->prepare("
            UPDATE Inventory_Stock 
            SET current_stock = ISNULL(current_stock, 0) + ?,
                available_stock = ISNULL(available_stock, 0) + ?,
                current_pallet = ISNULL(current_pallet, 0) + ?,
                available_pallet = ISNULL(available_pallet, 0) + ?,
                last_updated = GETDATE(),
                last_movement_date = ?
            WHERE inventory_id = ?
        ");
        
        $result = $stmt->execute([
            $stockQuantity,
            $stockQuantity,
            $pallet_count,
            $pallet_count,
            $movement_date,
            $stock['inventory_id']
        ]);
        
        if ($result) {
            $newTotal = $stock['current_stock'] + $stockQuantity;
            error_log("✅ UPDATE SUCCESS: product_id={$product_id}, +{$stockQuantity}, new_total={$newTotal}");
        } else {
            error_log("❌ UPDATE FAILED!");
        }
        
    } else {
        // Insert new
        error_log("📝 Creating new inventory record");
        
        $stmt = $pdo->prepare("
            INSERT INTO Inventory_Stock (
                product_id, warehouse_id, location_id,
                current_stock, available_stock,
                current_pallet, available_pallet,
                last_updated, last_movement_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE(), ?)
        ");
        
        $result = $stmt->execute([
            $product_id,
            $warehouse_id,
            $location_id,
            $stockQuantity,
            $stockQuantity,
            $pallet_count,
            $pallet_count,
            $movement_date
        ]);
        
        if ($result) {
            error_log("✅ INSERT SUCCESS: product_id={$product_id}, quantity={$stockQuantity}");
        } else {
            error_log("❌ INSERT FAILED!");
        }
    }
    
    error_log("========================================");
}

// ✅ ฟังก์ชันบันทึก Direct Receipt → ใช้ Goods_Receipt เหมือน PO Mode
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
        $totalAmount = 0;

        foreach ($data['items'] as $item) {
            if (!empty($item['product_id']) && !empty($item['quantity']) && $item['quantity'] > 0) {
                $quantity = (float)$item['quantity'];
                $validItems[] = $item;
                $totalQuantity += $quantity;
                // คำนวณมูลค่ารวม (ถ้ามี)
                if (!empty($item['unit_cost'])) {
                    $totalAmount += $quantity * (float)$item['unit_cost'];
                }
            }
        }
        
        if (empty($validItems)) {
            throw new Exception('No valid items found');
        }

        // 1. Insert Goods_Receipt Header (ไม่มี PO)
        $stmt = $pdo->prepare("
            INSERT INTO Goods_Receipt (
                gr_number, po_id, receipt_date, warehouse_id,
                total_amount, status, received_by, notes,
                invoice_number, receipt_type, created_date
            ) VALUES (?, NULL, ?, ?, ?, 'COMPLETED', ?, ?, NULL, 'DIRECT', GETDATE())
        ");
        
        $received_by_id = $_SESSION['user_id'] ?? 1;
        
        $stmt->execute([
            $data['receipt_number'],                    // 1. gr_number
            $data['receipt_date'],                      // 2. receipt_date
            !empty($data['warehouse_id']) ? (int)$data['warehouse_id'] : null, // 3. warehouse_id
            $totalAmount,                               // 4. total_amount
            $received_by_id,                            // 5. received_by
            $data['notes'] ?? ''                        // 6. notes
        ]);

        $stmt = $pdo->query("SELECT @@IDENTITY AS id");
        $row = $stmt->fetch();
        $grId = $row['id'];
        
        if (!$grId) {
            throw new Exception('Failed to get GR ID');
        }

        error_log("GR ID created: " . $grId);

        // ===== แก้ไขส่วนนี้ในไฟล์ receiving_direct.php =====

// 2. Insert Items + Update Inventory + Record Movements
$itemSql = "
    INSERT INTO Goods_Receipt_Items (
        gr_id, product_id,
        quantity_ordered, quantity_received, quantity_accepted,
        received_unit_id, stock_unit_id, conversion_factor, quantity_pallet,
        unit_cost, total_cost, warehouse_id, location_id,
        batch_lot, supplier_lot_number, manufacturing_date,
        expiry_date, supplier_expiry_date,
        received_condition, quality_status, current_status_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'good', 'accepted', 1)
";
$itemStmt = $pdo->prepare($itemSql);

foreach ($validItems as $index => $item) {
    $quantity = (float)$item['quantity'];
    $unitCost = !empty($item['unit_cost']) ? (float)$item['unit_cost'] : 0;
    $totalCost = $quantity * $unitCost;
    
    $mfgDate = !empty($item['manufacturing_date']) ? $item['manufacturing_date'] : null;
    $expDate = !empty($item['expiry_date']) ? $item['expiry_date'] : null;
    $unitId = !empty($item['unit_id']) ? (int)$item['unit_id'] : null;
    $palletCount = !empty($item['pallet_count']) ? (int)$item['pallet_count'] : null;

    // ✅ INSERT Goods_Receipt_Items - ส่ง 17 ค่าตามลำดับ
    $itemStmt->execute([
        $grId,                                                          // 1. gr_id
        (int)$item['product_id'],                                       // 2. product_id
        $quantity,                                                      // 3. quantity_ordered
        $quantity,                                                      // 4. quantity_received
        $quantity,                                                      // 5. quantity_accepted
        $unitId,                                                   // 6. received_unit_id
        $unitId,                                                        // 7. stock_unit_id
        $palletCount,                                                        // 8. quantity_pallet
        $unitCost,                                                      // 9. unit_cost
        $totalCost,                                                     // 10. total_cost
        !empty($item['warehouse_id']) ? (int)$item['warehouse_id'] : null, // 11. warehouse_id
        !empty($item['location_id']) ? (int)$item['location_id'] : null,   // 12. location_id
        $item['supplier_lot_number'] ?? null,                          // 13. batch_lot
        $item['supplier_lot_number'] ?? null,                          // 14. supplier_lot_number
        $mfgDate,                                                      // 15. manufacturing_date
        $expDate,                                                      // 16. expiry_date
        $expDate                                                       // 17. supplier_expiry_date
    ]);

    // ดึง ID ของ item ที่เพิ่งบันทึก
    $itemIdStmt = $pdo->query("SELECT @@IDENTITY AS id");
    $itemIdRow = $itemIdStmt->fetch();
    $grItemId = $itemIdRow['id'];

    // ✅ Update Inventory_Stock
    if (!empty($item['warehouse_id']) && !empty($item['location_id'])) {
        // ✅ เช็คว่ามีสต็อกอยู่แล้วหรือไม่และหน่วยตรงกันหรือไม่
        // ดึงหน่วยล่าสุดที่ใช้จาก Goods_Receipt_Items
        $checkStmt = $pdo->prepare("
    SELECT TOP 1
        gri.stock_unit_id as unit_id,
        u.unit_name_th,
        u.unit_name,
        ist.current_stock
    FROM Inventory_Stock ist
    INNER JOIN Goods_Receipt_Items gri ON ist.product_id = gri.product_id 
        AND ist.warehouse_id = gri.warehouse_id 
        AND ist.location_id = gri.location_id
    LEFT JOIN Units u ON gri.stock_unit_id = u.unit_id
    WHERE ist.product_id = ? 
    AND ist.warehouse_id = ? 
    AND ist.location_id = ?
    AND ist.current_stock > 0
    ORDER BY gri.gr_item_id DESC
");
        $checkStmt->execute([
            $item['product_id'],
            $item['warehouse_id'],
            $item['location_id']
        ]);
        $existingStock = $checkStmt->fetch();

        // ถ้ามีของเหลืออยู่และหน่วยไม่ตรงกัน → ให้ error
        if ($existingStock && $existingStock['current_stock'] > 0) {
            $existingUnitId = $existingStock['unit_id'];
            $newUnitId = $item['unit_id'] ?? null;
            
            if ($existingUnitId != $newUnitId && $existingUnitId !== null && $newUnitId !== null) {
                // ดึงชื่อหน่วยใหม่
                $newUnitStmt = $pdo->prepare("SELECT unit_name_th, unit_name FROM Units WHERE unit_id = ?");
                $newUnitStmt->execute([$newUnitId]);
                $newUnit = $newUnitStmt->fetch();
                
                $existingUnitName = $existingStock['unit_name_th'] ?: $existingStock['unit_name'];
                $newUnitName = $newUnit ? ($newUnit['unit_name_th'] ?: $newUnit['unit_name']) : 'ไม่ระบุ';
                
                // ดึงชื่อสินค้า
                $productStmt = $pdo->prepare("SELECT SSP_Code, Name FROM Master_Products_ID WHERE id = ?");
                $productStmt->execute([$item['product_id']]);
                $product = $productStmt->fetch();
                $productName = $product ? "{$product['SSP_Code']} - {$product['Name']}" : 'สินค้า';
                
                throw new Exception(
                    "❌ ไม่สามารถรับเข้าได้!\n\n" .
                    "สินค้า: {$productName}\n" .
                    "มีของเหลืออยู่แล้ว {$existingStock['current_stock']} หน่วย '{$existingUnitName}'\n\n" .
                    "คุณกำลังพยายามรับเข้าในหน่วย '{$newUnitName}' ซึ่งไม่ตรงกัน\n\n" .
                    "⚠️ กรุณา:\n" .
                    "1. เปลี่ยนหน่วยรับเข้าเป็น '{$existingUnitName}' หรือ\n" .
                    "2. โอนของเก่าออกให้หมดก่อน หรือ\n" .
                    "3. เลือก Location อื่นสำหรับหน่วย '{$newUnitName}'"
                );
            }
        }

// 🔥 ดึงข้อมูล Package
$packageQty = !empty($item['received_package_qty']) ? (float)$item['received_package_qty'] : null;

updateInventoryStock(
    $pdo,
    $item['product_id'],
    $item['warehouse_id'],
    $item['location_id'],
    $item['unit_id'] ?? null,
    $quantity,
    $item['supplier_lot_number'] ?? null,
    $mfgDate,
    $expDate,
    $palletCount,
    $data['receipt_date'],
    $packageQty  // 🔥 เพิ่มพารามิเตอร์
);

        // ✅ Insert Stock_Movements
$movementStmt = $pdo->prepare("
    INSERT INTO Stock_Movements (
        product_id, warehouse_id, location_id, movement_type,
        quantity, unit_id, reference_type, reference_id,
        batch_lot, movement_date, created_by, notes,
        quantity_pallet, received_package_qty, package_weight_per_unit
    ) VALUES (?, ?, ?, 'IN', ?, ?, 'GR', ?, ?, ?, ?, ?, ?, ?, ?)
");

$receivePackageQty = !empty($item['received_package_qty']) ? (float)$item['received_package_qty'] : null;
$weightPerPackage = !empty($item['weight_per_package']) ? (float)$item['weight_per_package'] : null;

$movementStmt->execute([
    $item['product_id'],
    $item['warehouse_id'],
    $item['location_id'],
    $quantity,
    $item['unit_id'] ?? null,
    $grItemId,
    $item['supplier_lot_number'] ?? null,
    $data['receipt_date'],
    $received_by_id,
    $item['notes'] ?? null,
    $item['pallet_count'] ?? 0,
    $receivePackageQty,
    $weightPerPackage
]);
    }
}

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Receipt saved and inventory updated successfully',
            'gr_id' => $grId,
            'gr_number' => $data['receipt_number'],
            'total_items' => count($validItems),
            'total_quantity' => $totalQuantity,
            'total_amount' => $totalAmount
        ];

    } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollback();
        }
        error_log("Save Receipt Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return [
            'success' => false, 
            'message' => $e->getMessage()
        ];
    }
}

// ===== AJAX HANDLERS =====
if (isset($_GET['ajax']) || isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();
    
    try {
        $action = $_GET['ajax'] ?? $_POST['action'] ?? '';
        
        switch ($action) {
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
                    SELECT u.unit_id, u.unit_code, u.unit_name, u.unit_symbol
                    FROM Units u
                    WHERE u.is_active = 1
                    ORDER BY u.unit_name
                ");
                $q->execute();
                $units = $q->fetchAll();

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
                $items = [];
                
                if (isset($_POST['items']) && is_string($_POST['items'])) {
                    $items = json_decode($_POST['items'], true);
                } else if (isset($_POST['items']) && is_array($_POST['items'])) {
                    $items = $_POST['items'];
                }
                
                error_log("Received items: " . print_r($items, true));
                
                // ✅ ดึง warehouse_id จากรายการแรก (ถ้ามี) หรือจาก POST
                $warehouseId = null;
                if (!empty($_POST['warehouse_id'])) {
                    $warehouseId = (int)$_POST['warehouse_id'];
                } else if (!empty($items[0]['warehouse_id'])) {
                    $warehouseId = (int)$items[0]['warehouse_id'];
                }
                
                $receiptData = [
                    'receipt_number' => $_POST['receipt_number'] ?? '',
                    'receipt_date' => $_POST['receipt_date'] ?? '',
                    'supplier_id' => !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null,
                    'warehouse_id' => $warehouseId,
                    'receipt_reason' => $_POST['receipt_reason'] ?? '',
                    'notes' => $_POST['notes'] ?? '',
                    'items' => $items
                ];
                
                $result = saveDirectReceipt($receiptData);
                echo json_encode($result);
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

// Load master data
try {
    $Suppliers  = $pdo->query("SELECT supplier_id, supplier_name FROM Suppliers WHERE is_active = 1 ORDER BY supplier_name")->fetchAll();
    
    // ✅ ดึงคลัง Main และ Hold (รองรับทั้ง case-insensitive)
    $warehousesRaw = $pdo->query("
        SELECT warehouse_id, warehouse_name 
        FROM Warehouses 
        WHERE (is_active = 1 OR is_active IS NULL) 
          AND (
              LOWER(warehouse_name) = 'main' 
              OR LOWER(warehouse_name) = 'hold'
              OR warehouse_name LIKE '%Main%'
              OR warehouse_name LIKE '%Hold%'
          )
        ORDER BY 
            CASE 
                WHEN LOWER(warehouse_name) = 'main' OR warehouse_name LIKE '%Main%' THEN 1 
                WHEN LOWER(warehouse_name) = 'hold' OR warehouse_name LIKE '%Hold%' THEN 2 
                ELSE 3
            END
    ")->fetchAll();
    
    // แปลงชื่อเป็นภาษาไทย
    $Warehouses = [];
    foreach($warehousesRaw as $w) {
        $name_lower = strtolower($w['warehouse_name']);
        $thai_name = $w['warehouse_name']; // ค่า default
        
        if ($name_lower === 'main' || stripos($w['warehouse_name'], 'Main') !== false) {
            $thai_name = 'คลังหลัก';
        } elseif ($name_lower === 'hold' || stripos($w['warehouse_name'], 'Hold') !== false) {
            $thai_name = 'คลังHold';
        }
        
        $Warehouses[] = [
            'warehouse_id' => $w['warehouse_id'],
            'warehouse_name' => $thai_name,
            'warehouse_name_en' => $w['warehouse_name']
        ];
    }
    
    // ✅ Debug: log จำนวนคลังที่พบ
    error_log("Found " . count($Warehouses) . " warehouses: " . json_encode($Warehouses));
    
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
<title>รับเข้าสินค้าโดยตรง (ไม่มี PO) - บันทึกลง Goods_Receipt</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<style>
:root{--primary-color:#8B4513;--primary-gradient:linear-gradient(135deg,#8B4513,#A0522D)}
body{background:linear-gradient(135deg,#F5DEB3 0%,#DEB887 50%,#D2B48C 100%);min-height:100vh;font-family:'Segoe UI',sans-serif;color:var(--primary-color)}
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
.card{border:0;box-shadow:0 10px 30px rgba(139,69,19,.15);border-radius:20px;background:rgba(255,255,255,.95)}
.card-header{border-top-left-radius:20px;border-top-right-radius:20px;background:var(--primary-gradient)!important;color:#fff!important;border:none;padding:20px;font-weight:bold}
.card-body{padding:25px}
.form-label.upper{text-transform:uppercase;font-size:.82rem;letter-spacing:.02em;color:var(--primary-color);font-weight:bold}
.form-control,.form-select{border-radius:10px;border:2px solid rgba(139,69,19,.2);padding:12px 15px;transition:all .3s ease}
.form-control:focus,.form-select:focus{border-color:var(--primary-color);box-shadow:0 0 0 .2rem rgba(139,69,19,.15)}
.item-card{background:rgba(248,249,250,.9);border:2px solid rgba(139,69,19,.1);border-radius:15px;padding:20px;margin-bottom:20px;transition:all .3s ease}
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
.badge-success{background:#059669;color:white;padding:4px 8px;border-radius:6px;font-size:11px}
.is-invalid{border-color:#dc3545!important;animation:shake .3s ease-in-out}
.is-valid{border-color:#28a745!important;background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%2328a745' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");background-repeat:no-repeat;background-position:right calc(.375em + .1875rem) center;background-size:calc(.75em + .375rem) calc(.75em + .375rem)}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-5px)}75%{transform:translateX(5px)}}
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
                        <i class="fas fa-arrow-down me-2"></i>รับเข้าสินค้าโดยตรง (Direct Receipt)
                    </h5>
                    <small class="text-light">รับเข้าสินค้าโดยไม่มี PO พร้อมอัปเดตสต็อกอัตโนมัติ</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="receiving_po.php" class="btn-header">รับเข้าจาก PO</a>
                <a href="../../inventory/goods_receipt_list.php" class="btn-header">รายการรับเข้า</a>
                <span class="text-white">
                    <i class="fas fa-user-circle me-2"></i>
                    <?= htmlspecialchars($_SESSION['full_name'] ?? 'System Administrator'); ?>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid py-4" style="max-width: 98%; padding: 0 2rem;">
  <!-- <div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>
    <strong>รับเข้าแบบไม่มี PO:</strong> 
    บันทึกลง <code>Goods_Receipt</code> (PO_Number = NULL) → 
    อัปเดต <code>Inventory_Stock</code> → 
    บันทึก <code>Stock_Movements</code>
    <span class="badge-success ms-2">เหมือน PO Mode ทุกอย่าง</span>
  </div>-->

  <div id="directModeSection">
    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-edit me-2"></i>ข้อมูลใบรับเข้า</div>
      <div class="card-body">
        <div class="row g-4">
          <div class="col-md-3">
            <label class="form-label upper">เลขที่เอกสาร</label>
            <input type="text" class="form-control" name="receipt_number" value="<?= h($receiptNumber); ?>" readonly>
          </div>
          <div class="col-md-3">
            <label class="form-label upper">วันที่รับเข้า</label>
            <input type="date" class="form-control" name="receipt_date" value="<?= h(date('Y-m-d')); ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label upper">ผู้ขาย <span class="text-danger">*</span></label>
            <select class="form-select" name="supplier_id" id="supplierSelect" required>
              <option value="">-- เลือกผู้ขาย --</option>
              <?php foreach ($Suppliers as $s): ?>
                <option value="<?= (int)$s['supplier_id']; ?>"><?= h($s['supplier_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-list me-2"></i>รายการสินค้า</span>
        <button type="button" class="btn btn-light btn-sm" id="btnAddCard">
          <i class="fas fa-plus me-1"></i>เพิ่มรายการ
        </button>
      </div>
      <div class="card-body">
        <div id="itemsCards"></div>
      </div>
      <div class="sticky-actions d-flex gap-3 justify-content-end">
        <button type="button" class="btn btn-outline-secondary" onclick="if(confirm('ยกเลิก?'))window.location.reload()">
          <i class="fas fa-times me-1"></i>ยกเลิก
        </button>
        <button type="button" class="btn btn-success" id="btnSave">
          <i class="fas fa-save me-1"></i>บันทึกและอัปเดตสต็อก
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const WAREHOUSES = <?= json_encode($Warehouses, JSON_UNESCAPED_UNICODE); ?>;
const UNITS_ALL  = <?= json_encode($UnitsAll, JSON_UNESCAPED_UNICODE); ?>;
console.log("📦 WAREHOUSES loaded:", WAREHOUSES);
console.log("📦 WAREHOUSES count:", WAREHOUSES.length);
let rowIndex = 0;
let cachedProducts = [];

function hEsc(s){return (s??'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function showLoading(){document.getElementById('loadingOverlay').classList.add('show');}
function hideLoading(){document.getElementById('loadingOverlay').classList.remove('show');}

async function loadSupplierProducts(sid){
  const allProd = document.querySelectorAll('.product-select');
  allProd.forEach(sel=>{ sel.innerHTML='<option value="">กำลังโหลด...</option>'; sel.disabled=true; });
  try{
    const r = await fetch('?ajax=get_products_by_supplier&supplier_id='+encodeURIComponent(sid));
    const data = await r.json();
    cachedProducts = Array.isArray(data)?data:[];
    allProd.forEach(sel=>{
      let html = `<option value="">-- เลือกสินค้า --</option>`;
      for(const p of cachedProducts){
        html += `<option value="${p.product_id}" data-code="${hEsc(p.product_code)}" data-default-unit="${p.default_unit_id}">${hEsc(p.product_name)}</option>`;
      }
      sel.innerHTML = html; sel.disabled=false;
    });
  }catch(e){
    cachedProducts=[];
  }
}

function warehouseOptionsHtml(){
  let html = `<option value="">-- เลือกคลัง --</option>`;
  for(const w of WAREHOUSES) html += `<option value="${w.warehouse_id}">${hEsc(w.warehouse_name)}</option>`;
  console.log("🏪 Building warehouse options, count:", WAREHOUSES.length);
  return html;
}

function buildItemCard(idx){
  const wrap = document.createElement('div'); 
  wrap.className='item-card'; 
  wrap.setAttribute('data-row',idx);
  wrap.innerHTML = `
    <div class="row g-3">
      <div class="col-lg-4">
        <label class="form-label upper">รหัสสินค้า / ค้นหา</label>
        <div class="input-group mb-2">
          <input type="text" class="form-control product-code-input" 
                 placeholder="พิมพ์หรือสแกนรหัสสินค้า..." 
                 data-row="${idx}">
          <button class="btn btn-outline-primary code-search-btn" type="button" data-row="${idx}">
            <i class="fas fa-search"></i>
          </button>
        </div>
        <select class="form-select product-select" name="items[${idx}][product_id]" ${cachedProducts.length?'':'disabled'}>
          ${cachedProducts.length?'<option value="">-- เลือกสินค้า --</option>':'<option value="">-- เลือกผู้ขายก่อน --</option>'}
          ${cachedProducts.map(p=>`<option value="${p.product_id}" data-code="${hEsc(p.product_code)}">${hEsc(p.product_name)}</option>`).join('')}
        </select>
        <input type="hidden" name="items[${idx}][item_description]">
      </div>

      <div class="col-lg-2">
        <label class="form-label upper">คลัง <span class="text-danger">*</span></label>
        <select class="form-select warehouse-select" name="items[${idx}][warehouse_id]" required>${warehouseOptionsHtml()}</select>
      </div>

      <div class="col-lg-2">
        <label class="form-label upper">ตำแหน่ง <span class="text-danger">*</span></label>
        <select class="form-select location-select" name="items[${idx}][location_id]" disabled required>
          <option value="">เลือก Location</option>
        </select>
      </div>

      <div class="col-lg-3">
  <!-- 🔥 ฟอร์ม Package (แสดงเมื่อกดปุ่มเปิด) -->
  <div class="package-mode-section" style="display:none;">
    <div class="alert alert-info py-1 px-2 mb-2">
      <small><i class="fas fa-box me-1"></i>รับเข้าเป็นกระป๋อง</small>
    </div>
    
    <!-- 🔥 เปลี่ยนลำดับ: จำนวนกระป๋องก่อน -->
    <label class="form-label upper"><i class="fas fa-box me-1"></i>จำนวนกระป๋อง</label>
    <input type="number" step="1" min="0" 
           class="form-control text-end package-qty-input mb-2" 
           name="items[${idx}][received_package_qty]" 
           placeholder="0">
    
    <label class="form-label upper"><i class="fas fa-weight-hanging me-1"></i>น้ำหนัก/กระป๋อง (kg)</label>
    <input type="number" step="0.01" min="0" 
           class="form-control text-end weight-per-package mb-2" 
           name="items[${idx}][weight_per_package]"
           placeholder="เช่น 10.00">
    
    <label class="form-label upper"><i class="fas fa-calculator me-1"></i>น้ำหนักรวม (kg)</label>
    <input type="number" step="0.01" min="0" 
           class="form-control text-end quantity-calculated fw-bold" 
           name="items[${idx}][quantity]" 
           placeholder="0.00" 
           readonly 
           style="background:#e8f5e9;color:#059669">
  </div>

  <!-- 🔥 ฟอร์มปกติ -->
  <div class="normal-mode-section">
    <label class="form-label upper">จำนวน</label>
    <input type="number" step="0.0001" min="0" 
           class="form-control text-end normal-qty-input" 
           name="items[${idx}][quantity]" 
           placeholder="0.00">
  </div>
  
  <!-- 🔥 ปุ่มสลับโหมด -->
  <div class="mt-2">
    <button type="button" class="btn btn-sm btn-outline-primary w-100 toggle-package-btn" data-row="${idx}">
      <i class="fas fa-box me-1"></i>เปลี่ยนเป็นกระป๋อง
    </button>
  </div>
</div>

<div class="col-lg-2">
  <label class="form-label upper">หน่วย</label>
  <select class="form-select unit-select" name="items[${idx}][unit_id]">
    ${UNITS_ALL.map(u=>{
      const label = u.unit_symbol ? `${u.unit_name} (${u.unit_symbol})` : u.unit_name;
      return `<option value="${u.unit_id}">${hEsc(label)}</option>`;
    }).join('')}
  </select>
</div>

      <div class="col-lg-3">
        <label class="form-label upper">Lot Number</label>
        <input type="text" class="form-control" name="items[${idx}][supplier_lot_number]" placeholder="LOT-xxxx">
      </div>
      <div class="col-lg-2">
        <label class="form-label upper">Pallet 🆕</label>
        <input type="number" min="0" class="form-control text-end" name="items[${idx}][pallet_count]" placeholder="0">
      </div>
      <div class="col-lg-2">
        <label class="form-label upper">วันผลิต</label>
        <input type="date" class="form-control" name="items[${idx}][manufacturing_date]">
      </div>
      <div class="col-lg-2">
        <label class="form-label upper">วันหมดอายุ</label>
        <input type="date" class="form-control" name="items[${idx}][expiry_date]">
      </div>
      <div class="col-lg-1 d-flex align-items-end">
        <button type="button" class="btn btn-danger w-100" onclick="removeItemCard(${idx})">
          <i class="fas fa-trash"></i>
        </button>
      </div>
    </div>`;
  setTimeout(()=>wireItemEvents(idx),0);
  return wrap;
}
function setupPackageCalculation(card) {
  const packageQtyInput = card.querySelector('.package-qty-input');
  const calculatedQtyInput = card.querySelector('.quantity-calculated');
  const weightPerPackageInput = card.querySelector('.weight-per-package');
  
  if (!packageQtyInput || !calculatedQtyInput || !weightPerPackageInput) {
    console.warn('⚠️ Package calculation setup failed - missing elements');
    return;
  }
  
  // ฟังก์ชันคำนวณ
  function calculate() {
    const packageQty = parseFloat(packageQtyInput.value) || 0;
    const weightPerPackage = parseFloat(weightPerPackageInput.value) || 0;
    const totalWeight = packageQty * weightPerPackage;
    
    calculatedQtyInput.value = totalWeight.toFixed(2);
    
    if (packageQty > 0 && weightPerPackage > 0) {
      calculatedQtyInput.style.background = '#c8e6c9';
      calculatedQtyInput.style.borderColor = '#4caf50';
      calculatedQtyInput.style.color = '#059669';
    } else {
      calculatedQtyInput.style.background = '#e8f5e9';
      calculatedQtyInput.style.borderColor = '#ccc';
      calculatedQtyInput.style.color = '#666';
    }
    
    console.log(`📦 คำนวณ: ${packageQty} กระป๋อง × ${weightPerPackage} kg = ${totalWeight.toFixed(2)} kg`);
  }
  
  // 🔥 ใช้ event delegation แทน clone (แก้ปัญหาคำนวณไม่ทำงาน)
  packageQtyInput.addEventListener('input', calculate);
  weightPerPackageInput.addEventListener('input', calculate);
  packageQtyInput.addEventListener('change', calculate);
  weightPerPackageInput.addEventListener('change', calculate);
  
  // 🔥 คำนวณทันทีถ้ามีค่าอยู่แล้ว
  if(packageQtyInput.value || weightPerPackageInput.value) {
    calculate();
  }
  
  console.log('✅ Package calculation setup complete');
}

function wireItemEvents(idx){
  const card = document.querySelector('.item-card[data-row="'+idx+'"]'); 
  if(!card) return;

  const productSelect = card.querySelector('.product-select');
  const descHidden = card.querySelector(`input[name="items[${idx}][item_description]"]`);
  const warehouseSel = card.querySelector('.warehouse-select');
  const locationSel = card.querySelector('.location-select');
  const codeInput = card.querySelector('.product-code-input');
  const codeBtn = card.querySelector('.code-search-btn');
  const toggleBtn = card.querySelector('.toggle-package-btn');
  const packageSection = card.querySelector('.package-mode-section');
  const normalSection = card.querySelector('.normal-mode-section');
  let isPackageMode = false;

  // 🔥 ฟังก์ชันสลับโหมด
function switchToPackageMode() {
  isPackageMode = true;
  packageSection.style.display = 'block';
  normalSection.style.display = 'none';
  toggleBtn.innerHTML = '<i class="fas fa-list me-1"></i>เปลี่ยนเป็นปกติ';
  toggleBtn.classList.remove('btn-outline-primary');
  toggleBtn.classList.add('btn-outline-secondary');
  
  // 🔥 เปลี่ยนหน่วยเป็น CAN (กระป๋อง) - unit_id = 7
  const unitSelect = card.querySelector('.unit-select');
  if(unitSelect) {
    const CAN_UNIT_ID = '7';  // กระป๋อง
    
    // ตรวจสอบว่ามี option นี้หรือไม่
    const hasCan = Array.from(unitSelect.options).some(opt => opt.value === CAN_UNIT_ID);
    
    if(hasCan) {
      unitSelect.value = CAN_UNIT_ID;
      console.log('✅ เปลี่ยนหน่วยเป็น CAN (กระป๋อง) - unit_id: 7');
    } else {
      console.warn('⚠️ ไม่พบหน่วย CAN (unit_id: 7) ใน dropdown');
    }
  }
  
  // Setup calculation
  setupPackageCalculation(card);
  
  // ล้างค่าฟอร์มปกติ
  const normalQtyInput = card.querySelector('.normal-qty-input');
  if(normalQtyInput) normalQtyInput.value = '';
  
  console.log('📦 สลับเป็น Package Mode');
}

  function switchToNormalMode() {
    isPackageMode = false;
    packageSection.style.display = 'none';
    normalSection.style.display = 'block';
    toggleBtn.innerHTML = '<i class="fas fa-box me-1"></i>เปลี่ยนเป็นกระป๋อง';
    toggleBtn.classList.remove('btn-outline-secondary');
    toggleBtn.classList.add('btn-outline-primary');
    const weightInput = card.querySelector('.weight-per-package');
    const packageQtyInput = card.querySelector('.package-qty-input');
    const calculatedInput = card.querySelector('.quantity-calculated');
    if(weightInput) weightInput.value = '';
    if(packageQtyInput) packageQtyInput.value = '';
    if(calculatedInput) calculatedInput.value = '';
  }

  // Product select change
  if(productSelect){
    productSelect.addEventListener('change', function(){
      const opt = this.options[this.selectedIndex];
      if(descHidden) descHidden.value = opt.textContent.trim();
      
      // อัปเดตรหัสสินค้าในช่องค้นหา
      if(codeInput && opt.dataset.code) {
        codeInput.value = opt.dataset.code;
        
        // 🔥 ตรวจสอบว่าเป็นหมึกหรือไม่ (รหัสขึ้นต้นด้วย 25531)
        const productCode = opt.dataset.code || '';
        if(productCode.startsWith('25531')) {
          switchToPackageMode();
          console.log('🔥 Auto switch to Package Mode: ' + productCode);
        }
      }
    });
  }

  // ✅ Product code search function
  async function searchByProductCode() {
    const code = codeInput.value.trim();
    
    if(!code) {
      codeInput.classList.add('is-invalid');
      setTimeout(() => codeInput.classList.remove('is-invalid'), 1500);
      return;
    }

    try {
      showLoading();
      const response = await fetch('?ajax=get_product_by_code&code=' + encodeURIComponent(code));
      const result = await response.json();
      
      hideLoading();
      
      if(result.found) {
        // ✅ ตั้งค่า supplier ถ้ายังไม่ได้เลือก
        const supplierSelect = document.getElementById('supplierSelect');
        if(result.supplier_id && supplierSelect) {
          if(!supplierSelect.value || supplierSelect.value !== String(result.supplier_id)) {
            supplierSelect.value = result.supplier_id;
            await loadSupplierProducts(result.supplier_id);
          }
        }
        
        // ✅ เลือกสินค้าใน dropdown
        if(productSelect) {
          await new Promise(resolve => setTimeout(resolve, 300));
          
          productSelect.value = result.product_id;
          productSelect.dispatchEvent(new Event('change'));
        }
        
        // 🔥 ตรวจสอบว่าเป็นหมึกหรือไม่
        if(result.product_code && result.product_code.startsWith('25531')) {
          switchToPackageMode();
          console.log('🔥 Auto switch to Package Mode: ' + result.product_code);
        }
        
        // ✅ แสดงข้อความสำเร็จ
        codeInput.classList.remove('is-invalid');
        codeInput.classList.add('is-valid');
        setTimeout(() => codeInput.classList.remove('is-valid'), 2000);
        
        // ✅ Focus ไปที่ช่องคลัง
        if(warehouseSel) warehouseSel.focus();
        
      } else {
        codeInput.classList.add('is-invalid');
        setTimeout(() => codeInput.classList.remove('is-invalid'), 2000);
        alert('❌ ไม่พบสินค้ารหัส: ' + code);
      }
      
    } catch(error) {
      hideLoading();
      console.error('Search error:', error);
      alert('เกิดข้อผิดพลาดในการค้นหา');
    }
  }

  // ✅ Event: Click search button
  if(codeBtn) {
    codeBtn.addEventListener('click', searchByProductCode);
  }

  // ✅ Event: Enter key on code input
  if(codeInput) {
    codeInput.addEventListener('keypress', function(e) {
      if(e.key === 'Enter') {
        e.preventDefault();
        searchByProductCode();
      }
    });
    
    codeInput.addEventListener('blur', function() {
      if(this.value.trim() && !productSelect.value) {
        searchByProductCode();
      }
    });
  }

  // Warehouse change
  if(warehouseSel && locationSel){
    warehouseSel.addEventListener('change', async function(){
      const wid = Number(this.value || 0);
      // ทำลาย Select2 instance เก่า (ถ้ามี)
      if($(locationSel).hasClass('select2-hidden-accessible')) $(locationSel).select2('destroy');
      locationSel.innerHTML = '<option value="">เลือก Location</option>'; 
      locationSel.disabled = true;
      if(!wid) return;
      try{
        const r = await fetch('?ajax=get_locations_by_warehouse&warehouse_id='+wid);
        const data = await r.json();
        const byZone = {};
        for(const loc of data){
          const z = loc.zone_name || 'Zone';
          if(!byZone[z]) byZone[z]=[];
          byZone[z].push(loc);
        }
        Object.keys(byZone).sort().forEach(z=>{
          const og = document.createElement('optgroup'); og.label = z;
          byZone[z].forEach(l=>{
            const o = document.createElement('option'); 
            o.value=l.location_id; 
            o.textContent=l.location_code; 
            og.appendChild(o);
          });
          locationSel.appendChild(og);
        });
        locationSel.disabled=false;
        // ✅ เปิดใช้งาน Select2 สำหรับ location dropdown
        $(locationSel).select2({
          theme: 'bootstrap-5',
          placeholder: 'พิมพ์เพื่อค้นหาตำแหน่ง...',
          allowClear: true,
          width: '100%'
        });
      }catch(e){ }
    });
  }

  // 🔥 Toggle Package Mode (Manual)
  if(toggleBtn) {
    toggleBtn.addEventListener('click', function() {
      if(isPackageMode) {
        switchToNormalMode();
      } else {
        switchToPackageMode();
      }
    });
  }
}

function addItemCard(){ 
  const idx = rowIndex++; 
  const card = buildItemCard(idx); 
  document.getElementById('itemsCards').appendChild(card); 
}

function removeItemCard(idx){
  const card = document.querySelector('.item-card[data-row="'+idx+'"]');
  if(!card) return;
  const count = document.querySelectorAll('.item-card').length;
  if(count<=1){
    card.querySelectorAll('input').forEach(i=>i.value='');
    card.querySelectorAll('select').forEach(s=>s.selectedIndex=0);
    return;
  }
  card.remove();
}

async function saveDirectReceipt(){
  const receiptNumber = document.querySelector('input[name="receipt_number"]').value;
  const receiptDate = document.querySelector('input[name="receipt_date"]').value;
  const supplierId = document.querySelector('select[name="supplier_id"]').value;

  if(!receiptNumber || !receiptDate){ 
    alert('กรุณากรอกข้อมูลให้ครบถ้วน'); 
    return; 
  }

  if(!supplierId){
    alert('กรุณาเลือกผู้ขาย');
    return;
  }

  const itemCards = document.querySelectorAll('.item-card');
  const items = []; 
  let hasValid = false;

  itemCards.forEach(card=>{
  const productId = card.querySelector('select[name*="[product_id]"]').value;
  const quantity = card.querySelector('input[name*="[quantity]"]').value;
  const unitId = card.querySelector('select[name*="[unit_id]"]').value;
  const warehouseId = card.querySelector('select[name*="[warehouse_id]"]').value;
  const locationId = card.querySelector('select[name*="[location_id]"]').value;
  const supplierLot = card.querySelector('input[name*="[supplier_lot_number]"]').value;
  const palletCount = card.querySelector('input[name*="[pallet_count]"]').value;
  const mfgDate = card.querySelector('input[name*="[manufacturing_date]"]').value;
  const expDate = card.querySelector('input[name*="[expiry_date]"]').value;
  const itemDesc = card.querySelector('input[name*="[item_description]"]').value;
  
  // 🔥 เพิ่มการดึงข้อมูลกระป๋อง
  const receivedPackageQty = card.querySelector('input[name*="[received_package_qty]"]')?.value;
  const weightPerPackage = card.querySelector('input[name*="[weight_per_package]"]')?.value;

  if(productId && quantity && parseFloat(quantity)>0 && warehouseId && locationId){
    hasValid = true;
    items.push({
      product_id: parseInt(productId),
      quantity: parseFloat(quantity),
      unit_id: unitId?parseInt(unitId):null,
      warehouse_id: parseInt(warehouseId),
      location_id: parseInt(locationId),
      supplier_lot_number: supplierLot || null,
      pallet_count: palletCount?parseInt(palletCount):0,
      manufacturing_date: mfgDate || null,
      expiry_date: expDate || null,
      item_description: itemDesc || null,
      // 🔥 เพิ่มข้อมูลกระป๋อง
      received_package_qty: receivedPackageQty ? parseFloat(receivedPackageQty) : null,
      weight_per_package: weightPerPackage ? parseFloat(weightPerPackage) : null
    });
  }
});
  
  if(!hasValid){ 
    alert('กรุณาเพิ่มรายการสินค้าและระบุคลัง+ตำแหน่งให้ครบ'); 
    return; 
  }

  const saveBtn = document.getElementById('btnSave'); 
  const old = saveBtn.innerHTML;
  saveBtn.disabled = true; 
  saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังบันทึกและอัปเดตสต็อก...';

  try{
    const formData = new URLSearchParams();
    formData.append('receipt_number', receiptNumber);
    formData.append('receipt_date', receiptDate);
    formData.append('supplier_id', supplierId);
    formData.append('items', JSON.stringify(items));

    const res = await fetch('?ajax=save_direct_receipt',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:formData
    });
    
    const ct = res.headers.get('content-type');
    if(!ct || !ct.includes('application/json')){
      const html = await res.text(); 
      console.error('Error:', html);
      alert('เกิด Error - ดู Console'); 
      return;
    }
    
    const result = await res.json();
    
    if(result.success){
      alert(`✅ บันทึกสำเร็จ!\n\nเลขที่: ${result.gr_number}\nGR ID: ${result.gr_id}\nจำนวนรายการ: ${result.total_items}\nจำนวนรวม: ${result.total_quantity}\n\n✓ อัปเดต Inventory_Stock แล้ว\n✓ บันทึก Stock_Movements แล้ว`);
      
      if(confirm('สร้างใบรับเข้าใหม่?')) {
        window.location.reload();
      } else {
        window.location.href = 'inventory_view.php';
      }
    }else{
      alert('❌ เกิดข้อผิดพลาด: ' + result.message);
    }
  }catch(e){
    console.error(e);
    alert('เกิดข้อผิดพลาด: ' + e.message);
  }finally{
    saveBtn.disabled=false; 
    saveBtn.innerHTML = old;
  }
}

document.getElementById('supplierSelect')?.addEventListener('change', function(){
  if(this.value) loadSupplierProducts(this.value);
});

document.getElementById('btnAddCard')?.addEventListener('click', addItemCard);
document.getElementById('btnSave')?.addEventListener('click', saveDirectReceipt);

document.addEventListener('DOMContentLoaded', function(){
  if(!document.querySelector('.item-card')) addItemCard();
});
</script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
</body>
</html>