<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

// เพิ่มบรรทัดนี้ก่อน new Auth()
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
                if (!$code) {
                    echo json_encode(['found' => false, 'message' => 'กรุณาระบุรหัสสินค้า']);
                    exit;
                }

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

                echo json_encode([
                    'found' => true,
                    'product' => $product
                ]);
                break;
                
            case 'get_warehouses_with_stock':
                $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
                if ($productId <= 0) {
                    echo json_encode([]);
                    exit;
                }

$stmt = $pdo->prepare("
    SELECT DISTINCT 
        w.warehouse_id, 
        w.warehouse_name_th as warehouse_name,
        SUM(i.current_stock) as total_stock,
        COUNT(DISTINCT i.location_id) as location_count
    FROM Inventory_Stock i
    INNER JOIN Warehouses w ON i.warehouse_id = w.warehouse_id
    WHERE i.product_id = ? 
    AND i.current_stock > 0
    AND (w.is_active = 1 OR w.is_active IS NULL)
    GROUP BY w.warehouse_id, w.warehouse_name_th
    HAVING SUM(i.current_stock) > 0
    ORDER BY w.warehouse_name_th
");
                $stmt->execute([$productId]);
                $warehouses = $stmt->fetchAll();

                echo json_encode($warehouses);
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
                    SELECT 
                        batch_lot,
                        SUM(CASE 
                            WHEN movement_type = 'IN' THEN quantity 
                            WHEN movement_type = 'OUT' THEN -quantity 
                            ELSE 0 
                        END) as remaining_qty,
                        SUM(CASE 
                            WHEN movement_type = 'IN' THEN ISNULL(quantity_pallet, 0)
                            WHEN movement_type = 'OUT' THEN -ISNULL(quantity_pallet, 0)
                            ELSE 0 
                        END) as remaining_pallet
                    FROM Stock_Movements
                    WHERE product_id = ? 
                    AND warehouse_id = ?
                    AND location_id = ?
                    AND batch_lot IS NOT NULL
                    AND batch_lot != ''
                    GROUP BY batch_lot
                    HAVING SUM(CASE 
                            WHEN movement_type = 'IN' THEN quantity 
                            WHEN movement_type = 'OUT' THEN -quantity 
                            ELSE 0 
                        END) > 0
                    ORDER BY batch_lot DESC
                ");
                $stmt->execute([$productId, $warehouseId, $locationId]);
                $lots = $stmt->fetchAll();
                
                echo json_encode($lots);
                break;
                
            case 'get_stock_info':
                $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
                $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
                $locationId = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
                
                if ($productId <= 0 || $warehouseId <= 0 || $locationId <= 0) {
                    echo json_encode(['found' => false]);
                    exit;
                }

                $stmt = $pdo->prepare("
                    SELECT 
                        current_stock,
                        available_stock,
                        current_pallet,
                        available_pallet
                    FROM Inventory_Stock
                    WHERE product_id = ? 
                    AND warehouse_id = ? 
                    AND location_id = ?
                ");
                $stmt->execute([$productId, $warehouseId, $locationId]);
                $stock = $stmt->fetch();

                if ($stock) {
                    echo json_encode([
                        'found' => true,
                        'stock' => $stock
                    ]);
                } else {
                    echo json_encode(['found' => false]);
                }
                break;
                
            case 'get_locations_by_warehouse':
                $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : 0;
                $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
                $isFrom = isset($_GET['is_from']) && $_GET['is_from'] === 'true';
                
                if ($warehouseId <= 0) {
                    echo json_encode([]);
                    exit;
                }

                // ถ้าเป็น FROM และมี product_id = แสดงเฉพาะ location ที่มีสต็อก
                if ($isFrom && $productId > 0) {
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT 
                            wl.location_id, 
                            wl.location_code, 
                            wl.zone,
                            i.current_stock,
                            i.available_stock
                        FROM Warehouse_Locations wl
                        INNER JOIN Inventory_Stock i ON wl.location_id = i.location_id
                        WHERE wl.warehouse_id = ? 
                        AND i.product_id = ?
                        AND i.current_stock > 0
                        AND (wl.is_active = 1 OR wl.is_active IS NULL)
                        ORDER BY wl.zone, wl.location_code
                    ");
                    $stmt->execute([$warehouseId, $productId]);
                } else {
                    // ถ้าเป็น TO = แสดงทุก location
                    $stmt = $pdo->prepare("
                        SELECT location_id, location_code, zone
                        FROM Warehouse_Locations
                        WHERE warehouse_id = ? AND (is_active = 1 OR is_active IS NULL)
                        ORDER BY zone, location_code
                    ");
                    $stmt->execute([$warehouseId]);
                }
                
                $locations = $stmt->fetchAll();
                echo json_encode($locations);
                break;
                
case 'get_all_warehouses':
    $stmt = $pdo->query("
        SELECT warehouse_id, warehouse_name_th as warehouse_name 
        FROM Warehouses 
        WHERE is_active = 1 OR is_active IS NULL 
        ORDER BY warehouse_name_th
    ");
    echo json_encode($stmt->fetchAll());
    break;
                
            case 'save_transfer':
                $json = file_get_contents('php://input');
                $input = json_decode($json, true);

                $productId = (int)($input['product_id'] ?? 0);
                $unitId = (int)($input['unit_id'] ?? 0);
                $fromWarehouseId = (int)($input['from_warehouse'] ?? 0);
                $fromLocationId = (int)($input['from_location'] ?? 0);
                $toWarehouseId = (int)($input['to_warehouse'] ?? 0);
                $destinations = $input['destinations'] ?? [];
                $lotNumber = $input['lot_number'] ?? null;
                $transferDate = $input['transfer_date'] ?? date('Y-m-d H:i:s');

                if ($productId <= 0 || $fromWarehouseId <= 0 || $fromLocationId <= 0 || $toWarehouseId <= 0) {
                    echo json_encode(['success' => false, 'error' => 'ข้อมูลไม่ครบถ้วน']);
                    exit;
                }

                if (empty($destinations) || !is_array($destinations)) {
                    echo json_encode(['success' => false, 'error' => 'กรุณาระบุพื้นที่ปลายทางอย่างน้อย 1 รายการ']);
                    exit;
                }

                $totalQuantity = 0;
                $totalPallet = 0;
                foreach ($destinations as $dest) {
                    $qty = floatval($dest['quantity'] ?? 0);
                    $pallet = intval($dest['pallet_count'] ?? 0);
                    
                    if ($qty <= 0) {
                        echo json_encode(['success' => false, 'error' => 'จำนวนต้องมากกว่า 0 ทุกรายการ']);
                        exit;
                    }
                    
                    $totalQuantity += $qty;
                    $totalPallet += $pallet;
                }

                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
                        SELECT current_stock, available_stock, current_pallet, available_pallet
                        FROM Inventory_Stock
                        WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
                    ");
                    $stmt->execute([$productId, $fromWarehouseId, $fromLocationId]);
                    $fromStock = $stmt->fetch();

                    if (!$fromStock) {
                        throw new Exception('ไม่พบข้อมูล Stock ต้นทาง');
                    }

                    if ($fromStock['current_stock'] < $totalQuantity) {
                        throw new Exception("Stock ไม่เพียงพอ: ต้องการ {$totalQuantity}, มีอยู่ {$fromStock['current_stock']}");
                    }

                    $stmtOut = $pdo->prepare("
                        INSERT INTO Stock_Movements (
                            product_id, unit_id, warehouse_id, location_id, 
                            movement_type, quantity, quantity_pallet, batch_lot, 
                            movement_date, reference_type, created_by
                        ) VALUES (?, ?, ?, ?, 'OUT', ?, ?, ?, ?, 'TRANSFER_OUT', ?)
                    ");
                    $stmtOut->execute([
                        $productId, $unitId, $fromWarehouseId, $fromLocationId,
                        $totalQuantity, $totalPallet, $lotNumber, $transferDate,
                        $_SESSION['user_id'] ?? 'system'
                    ]);
                    $movementIdOut = $pdo->lastInsertId();

                    $stmt = $pdo->prepare("
                        UPDATE Inventory_Stock
                        SET current_stock = current_stock - ?,
                            available_stock = available_stock - ?,
                            current_pallet = current_pallet - ?,
                            available_pallet = available_pallet - ?,
                            last_updated = GETDATE()
                        WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
                    ");
                    $stmt->execute([
                        $totalQuantity, $totalQuantity, $totalPallet, $totalPallet,
                        $productId, $fromWarehouseId, $fromLocationId
                    ]);

foreach ($destinations as $dest) {
    $toLocationId = (int)$dest['location_id'];
    $qty = floatval($dest['quantity']);
    $kg = floatval($dest['quantity_kg'] ?? 0);
    $pallet = intval($dest['pallet_count']);
    $destTransferDate = $dest['transfer_date'] ?? $transferDate;

    $stmtIn = $pdo->prepare("
        INSERT INTO Stock_Movements (
            product_id, unit_id, warehouse_id, location_id, 
            movement_type, quantity, quantity_kg, quantity_pallet, batch_lot, 
            movement_date, reference_type, reference_id, created_by
        ) VALUES (?, ?, ?, ?, 'IN', ?, ?, ?, ?, ?, 'TRANSFER_IN', ?, ?)
    ");
    $stmtIn->execute([
        $productId, $unitId, $toWarehouseId, $toLocationId,
        $qty, $kg, $pallet, $lotNumber, $destTransferDate, $movementIdOut,
        $_SESSION['user_id'] ?? 'system'
    ]);

    // ตรวจสอบว่ามี stock อยู่แล้วหรือไม่
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM Inventory_Stock
        WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
    ");
    $stmt->execute([$productId, $toWarehouseId, $toLocationId]);
    $result = $stmt->fetch();
    $existingStock = $result['count'] > 0;

    if ($existingStock) {
        // มี stock อยู่แล้ว -> UPDATE
        $stmt = $pdo->prepare("
            UPDATE Inventory_Stock
            SET current_stock = current_stock + ?,
                available_stock = available_stock + ?,
                current_pallet = current_pallet + ?,
                available_pallet = available_pallet + ?,
                last_updated = GETDATE()
            WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
        ");
        $stmt->execute([
            $qty, $qty, $pallet, $pallet,
            $productId, $toWarehouseId, $toLocationId
        ]);
    } else {
        // ไม่มี stock -> INSERT
        $stmt = $pdo->prepare("
            INSERT INTO Inventory_Stock (
                product_id, warehouse_id, location_id,
                current_stock, available_stock, current_pallet, available_pallet,
                last_updated
            ) VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE())
        ");
        $stmt->execute([
            $productId, $toWarehouseId, $toLocationId,
            $qty, $qty, $pallet, $pallet
        ]);
    }
} // ✅ ปิด foreach

                    $pdo->commit();

                    echo json_encode([
                        'success' => true,
                        'message' => "ย้ายสินค้าสำเร็จ: {$totalQuantity} ชิ้นไป " . count($destinations) . " พื้นที่",
                        'total_quantity' => $totalQuantity,
                        'destinations_count' => count($destinations)
                    ]);

                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                exit;
        } // ✅ ปิด switch case 'save_transfer'
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
} // ✅ ปิด if (isset($_GET['ajax']))

// Load master data
$warehouses = $pdo->query("
    SELECT warehouse_id, warehouse_name_th as warehouse_name 
    FROM Warehouses 
    WHERE is_active = 1 OR is_active IS NULL 
    ORDER BY warehouse_name_th
")->fetchAll();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ย้ายพื้นที่สินค้า - Warehouse Transfer</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
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
.card {
    border: 2px solid rgba(139, 69, 19, 0.2);
    box-shadow: 0 4px 12px rgba(139, 69, 19, 0.15);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.95);
    margin-bottom: 1.5rem;
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

.card-header.bg-danger {
    background: linear-gradient(135deg, #dc3545, #c82333) !important;
}

.card-header.bg-success {
    background: linear-gradient(135deg, #059669, #047857) !important;
}

.card-body {
    padding: 1.5rem;
}
.form-label {
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.5px;
    color: var(--primary-color);
    font-weight: 600;
    margin-bottom: 8px;
}
.form-control,.form-select{border-radius:10px;border:2px solid rgba(139,69,19,.2);padding:12px 15px;transition:all .3s ease}
.form-control:focus,.form-select:focus{border-color:var(--primary-color);box-shadow:0 0 0 .2rem rgba(139,69,19,.15)}
.btn-primary{background:var(--primary-gradient);border:none;border-radius:10px;padding:12px 30px;font-weight:bold;transition:all .3s ease}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(139,69,19,.3)}
.btn-danger{background:linear-gradient(135deg,#dc3545,#c82333);border:none;border-radius:10px;padding:12px 30px;font-weight:bold}
.transfer-arrow {
    text-align: center;
    padding: 20px;
    font-size: 3rem;
    color: var(--primary-color);
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
}
.stock-info{background:#e8f5e9;border:2px solid #4caf50;border-radius:10px;padding:15px;margin-top:10px}
.stock-info.warning{background:#fff3cd;border-color:#ffc107}
.stock-info.error{background:#f8d7da;border-color:#dc3545}
.loading{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);display:none;justify-content:center;align-items:center;z-index:9999;color:#fff}
.loading.show{display:flex}
.destination-item {
  border: 1px solid #ddd;
  padding: 12px;
  margin-bottom: 10px;
  border-radius: 4px;
  background: #fefefe;
  position: relative;
}
.destination-item .remove-btn {
  position: absolute;
  top: 8px;
  right: 8px;
  background: #dc3545;
  color: white;
  border: none;
  border-radius: 3px;
  padding: 4px 8px;
  cursor: pointer;
  font-size: 12px;
}
.destination-item .remove-btn:hover {
  background: #c82333;
}
.destination-row {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
  gap: 10px;
  align-items: start;
}
.stock-info-inline {
  font-size: 12px;
  color: #666;
  margin-top: 4px;
}
.destination-item .form-select,
.destination-item .form-control {
  font-size: 13px;
  padding: 8px 10px;
}
/* Select2 Custom Styling */
.select2-container--bootstrap-5 .select2-selection {
  border: 2px solid rgba(139, 69, 19, 0.2) !important;
  border-radius: 10px !important;
  padding: 8px 12px !important;
  min-height: 48px !important;
}

.select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
  padding-left: 0 !important;
  line-height: 30px !important;
}

.select2-container--bootstrap-5.select2-container--focus .select2-selection {
  border-color: var(--primary-color) !important;
  box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.15) !important;
}

.select2-container--bootstrap-5 .select2-dropdown {
  border: 2px solid rgba(139, 69, 19, 0.2) !important;
  border-radius: 10px !important;
}

.select2-container--bootstrap-5 .select2-results__option--highlighted {
  background-color: var(--primary-color) !important;
  color: white !important;
}

.select2-container--bootstrap-5 .select2-search__field {
  border: 2px solid rgba(139, 69, 19, 0.2) !important;
  border-radius: 8px !important;
  padding: 8px 12px !important;
}

.select2-container--bootstrap-5 .select2-search__field:focus {
  border-color: var(--primary-color) !important;
  outline: none !important;
}

.destination-item .select2-container {
  width: 100% !important;
}
</style>
</head>
<body>

<div class="loading" id="loadingOverlay" aria-hidden="true">
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
                <a href="/PD/production/pages/dashboard.php" class="btn-back-arrow">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h5 class="mb-0">
                        <i class="fas fa-exchange-alt me-2"></i>ย้ายพื้นที่สินค้า (Transfer Location)
                    </h5>
                    <small class="text-light">โอนย้ายสินค้าระหว่างคลังและพื้นที่</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="../inventory/inventory_view.php" class="btn-header">สินค้าคงคลัง</a>
                <a href="../inventory/stock_movements_list.php" class="btn-header">ประวัติการย้าย</a>
                <span class="text-white">
                    <i class="fas fa-user-circle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'System Administrator'); ?>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid py-4" style="max-width: 98%; padding: 0 2rem;">
  
  <!-- Product Search Section -->
  <div class="card">
    <div class="card-header">
      <i class="fas fa-search me-2"></i>ค้นหาสินค้า
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">รหัสสินค้า</label>
          <div class="input-group">
            <input type="text" class="form-control" id="productCode" placeholder="สแกนหรือพิมพ์รหัส..." autocomplete="off">
            <button class="btn btn-primary" type="button" id="btnSearchProduct">
              <i class="fas fa-search"></i> ค้นหา
            </button>
          </div>
        </div>
        <div class="col-md-8">
          <label class="form-label">ชื่อสินค้า</label>
          <input type="text" class="form-control" id="productName" readonly>
        </div>
      </div>
    </div>
  </div>

  <!-- Transfer Form -->
  <div id="transferForm" style="display:none;">
    <div class="row">
      
      <!-- FROM Section -->
      <div class="col-md-5">
        <div class="card">
          <div class="card-header bg-danger">
            <i class="fas fa-sign-out-alt me-2"></i>WAREHOUSE จาก (ต้นทาง)
          </div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label">คลังสินค้า</label>
              <select class="form-select" id="fromWarehouse" required>
                <option value="">-- เลือกคลัง --</option>
                <?php foreach ($warehouses as $w): ?>
                  <option value="<?= $w['warehouse_id']; ?>"><?= h($w['warehouse_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="mb-3">
              <label class="form-label">Location ต้นทาง</label>
              <select class="form-select" id="fromLocation" disabled required>
                <option value="">เลือก Location</option>
              </select>
            </div>
            
<div class="mb-3">
  <label class="form-label">LOT FROM SUPPLIER</label>
  <select class="form-select" id="lotNumber" disabled>
    <option value="">-- เลือก LOT (ถ้ามี) --</option>
  </select>
</div>
            
            <div id="fromStockInfo" class="stock-info" style="display:none;">
              <div class="d-flex justify-content-between mb-2">
                <span><strong>สต็อกปัจจุบัน:</strong></span>
                <span id="fromCurrentStock">-</span>
              </div>
              <div class="d-flex justify-content-between">
                <span><strong>Pallet:</strong></span>
                <span id="fromCurrentPallet">-</span>
              </div>
            </div>
          </div>
        </div>
      </div>

<!-- TO Section - ขยายเต็ม -->
      <div class="col-md-7">
        <div class="card">
          <div class="card-header bg-success">
            <i class="fas fa-sign-in-alt me-2"></i>WAREHOUSE ไป (ปลายทาง)
          </div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label">คลังสินค้า</label>
              <select class="form-select" id="toWarehouse" required>
                <option value="">-- เลือกคลัง --</option>
                <?php foreach ($warehouses as $w): ?>
                  <option value="<?= $w['warehouse_id']; ?>"><?= h($w['warehouse_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <!-- เปลี่ยนเป็นระบบหลาย Location -->
            <div id="toLocationSection" style="display:none;">
              <div class="mb-3">
                <label class="form-label">พื้นที่ปลายทาง</label>
                <button type="button" class="btn btn-sm btn-primary ms-2" onclick="addDestinationLocation()">
                  <i class="fas fa-plus"></i> เพิ่มพื้นที่ปลายทาง
                </button>
              </div>
              
              <div id="destinationLocationsContainer">
                <!-- Destination locations จะถูกเพิ่มที่นี่ -->
              </div>
              
              <div class="alert alert-info" id="transferSummary" style="display:none; margin-top:15px;">
                <strong><i class="fas fa-info-circle"></i> สรุปการย้าย:</strong>
                <div style="margin-top:8px;">
                  <span>📦 จำนวนรวม: <strong><span id="totalTransferQty">0.00</span></strong> ชิ้น</span> |
                  <span>⚖️ น้ำหนักรวม: <strong><span id="totalTransferKg">0.00</span></strong> KG</span> |
                  <span>📋 Pallet รวม: <strong><span id="totalTransferPallet">0</span></strong></span> |
                  <span>📍 จำนวนพื้นที่: <strong><span id="totalDestinations">0</span></strong></span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- Action Buttons -->
    <div class="card">
      <div class="card-body">
        <div class="d-flex gap-3 justify-content-end">
          <button type="button" class="btn btn-outline-secondary" id="btnReset">
            <i class="fas fa-times me-1"></i>ยกเลิก
          </button>
          <button type="button" class="btn btn-primary" id="btnSaveTransfer">
            <i class="fas fa-check me-1"></i>ยืนยันการย้าย
          </button>
        </div>
      </div>
    </div>
  </div>
</div>
</div>

<script>
let currentProduct = null;
let destinationCounter = 0;
let availableToLocations = [];
function formatNumber(num) {
  return Math.round(num).toLocaleString('en-US');
}
function showLoading() { document.getElementById('loadingOverlay').classList.add('show'); }
function hideLoading() { document.getElementById('loadingOverlay').classList.remove('show'); }

// --- Load Warehouses that have stock for this product ---
async function loadWarehousesWithStock(productId) {
  const fromWarehouseSelect = document.getElementById('fromWarehouse');
  const toWarehouseSelect = document.getElementById('toWarehouse');
  
  // Reset dropdowns
  fromWarehouseSelect.innerHTML = '<option value="">-- เลือกคลัง --</option>';
  toWarehouseSelect.innerHTML = '<option value="">-- เลือกคลัง --</option>';
  
  try {
    const res = await fetch(`?ajax=get_warehouses_with_stock&product_id=${productId}`);
    const warehouses = await res.json();
    
    if (warehouses.length === 0) {
      fromWarehouseSelect.innerHTML = '<option value="">ไม่มีสต็อกในคลังใดๆ</option>';
      fromWarehouseSelect.disabled = true;
      alert('⚠️ ไม่พบสต็อกของสินค้านี้ในระบบ');
      return;
    }
    
    // ✅ Populate FROM warehouse (เฉพาะที่มีสินค้า)
    warehouses.forEach(w => {
      const option = document.createElement('option');
      option.value = w.warehouse_id;
      option.textContent = `${w.warehouse_name} (สต็อก: ${formatNumber(w.total_stock)})`;
      fromWarehouseSelect.appendChild(option);
    });
    fromWarehouseSelect.disabled = false;
    
    // ✅ Populate TO warehouse (ทุกคลัง - รวมที่ไม่มีสินค้า)
    const resAll = await fetch('?ajax=get_all_warehouses');
    const allWarehouses = await resAll.json();
    allWarehouses.forEach(w => {
      const option = document.createElement('option');
      option.value = w.warehouse_id;
      option.textContent = w.warehouse_name;
      toWarehouseSelect.appendChild(option);
    });
    toWarehouseSelect.disabled = false;
    
  } catch (err) {
    console.error('Error loading warehouses:', err);
    fromWarehouseSelect.disabled = false;
    toWarehouseSelect.disabled = false;
  }
}
// --- Search Product ---
async function searchProduct() {
  const code = document.getElementById('productCode').value.trim();
  if (!code) {
    alert('กรุณาระบุรหัสสินค้า');
    return;
  }

  // ตรวจสอบข้อมูลค้าง
  if (currentProduct) {
    const destItems = document.querySelectorAll('.destination-item');
    let hasData = false;
    
    destItems.forEach(item => {
      const id = item.id.split('-')[1];
      const locationId = document.getElementById(`destLoc-${id}`)?.value;
      const qty = parseFloat(document.getElementById(`destQty-${id}`)?.value || 0);
      
      if (locationId || qty > 0) {
        hasData = true;
      }
    });
    
    const fromWarehouse = document.getElementById('fromWarehouse').value;
    const fromLocation = document.getElementById('fromLocation').value;
    
    if (hasData || fromWarehouse || fromLocation) {
      if (!confirm('⚠️ มีข้อมูลที่ยังไม่ได้บันทึก!\n\nต้องการค้นหาสินค้าใหม่และล้างข้อมูลเดิมหรือไม่?')) {
        return;
      }
    }
  }

  try {
    showLoading();
    const res = await fetch(`?ajax=search_product&code=${encodeURIComponent(code)}`);
    const data = await res.json();
    hideLoading();

    if (!data.found) {
      alert(data.message || 'ไม่พบสินค้า');
      return;
    }

    // ✅ ล้างฟอร์มแต่เก็บรหัสสินค้าไว้
    resetForm(true);

    currentProduct = data.product;
    document.getElementById('productName').value = currentProduct.product_name;
    document.getElementById('transferForm').style.display = 'block';

    await loadWarehousesWithStock(currentProduct.product_id);

  } catch (err) {
    hideLoading();
    console.error('Search error:', err);
    alert('เกิดข้อผิดพลาดในการค้นหา');
  }
}


// --- Load Locations for a warehouse ---
async function loadLocations(warehouseId, selectId) {
  const select = document.getElementById(selectId);
  
  if (selectId === 'fromLocation') {
    select.innerHTML = '<option value="">กำลังโหลด...</option>';
    select.disabled = true;
  }

  if (!warehouseId || !currentProduct) {
    if (selectId === 'fromLocation') {
      select.innerHTML = '<option value="">เลือก Location</option>';
    }
    return;
  }

  const isFrom = selectId === 'fromLocation';
  
  try {
    const res = await fetch(`?ajax=get_locations_by_warehouse&warehouse_id=${warehouseId}&product_id=${currentProduct.product_id}&is_from=${isFrom}`);
    const locations = await res.json();

    if (selectId === 'fromLocation') {
  // FROM location - ใช้ Select2 พร้อมค้นหา
  select.innerHTML = '<option value="">เลือก Location</option>';
  locations.forEach(loc => {
    const opt = document.createElement('option');
    opt.value = loc.location_id;
    opt.textContent = `${loc.location_code} ${loc.zone ? '(' + loc.zone + ')' : ''} - Stock: ${formatNumber(loc.current_stock || 0)}`;
    select.appendChild(opt);
  });
  select.disabled = false;
  
  // ✅ เริ่มใช้ Select2
  $(select).select2({
    theme: 'bootstrap-5',
    width: '100%',
    placeholder: 'ค้นหา Location...',
    allowClear: true,
    language: {
      noResults: function() {
        return "ไม่พบข้อมูล";
      },
      searching: function() {
        return "กำลังค้นหา...";
      }
    }
  });
} else {
      // TO warehouse - เก็บ locations ไว้สำหรับ dynamic destinations
      availableToLocations = locations;
      document.getElementById('toLocationSection').style.display = 'block';
      
      // Clear existing destinations
      document.getElementById('destinationLocationsContainer').innerHTML = '';
      destinationCounter = 0;
      
      // เพิ่ม destination แรกอัตโนมัติ
      addDestinationLocation();
    }
  } catch (err) {
    console.error('Error loading locations:', err);
    if (selectId === 'fromLocation') {
      select.innerHTML = '<option value="">เกิดข้อผิดพลาด</option>';
      select.disabled = false;
    }
  }
}

// --- Get Stock Info for product@location ---
async function getStockInfo(productId, warehouseId, locationId, prefix) {
  if (!productId || !warehouseId || !locationId) return;

  try {
    const res = await fetch(`?ajax=get_stock_info&product_id=${productId}&warehouse_id=${warehouseId}&location_id=${locationId}`);
    const result = await res.json();
    const infoDiv = document.getElementById(prefix + 'StockInfo');

    if (result.found) {
  const stockEl = document.getElementById(prefix + 'CurrentStock');
  const palletEl = document.getElementById(prefix + 'CurrentPallet');
  
  // ✅ เก็บค่าจริงไว้ใน data-value
  stockEl.setAttribute('data-value', result.stock.current_stock || 0);
  palletEl.setAttribute('data-value', result.stock.current_pallet || 0);
  
  // แสดงผลด้วย format
  stockEl.textContent = formatNumber(result.stock.current_stock || 0);
  palletEl.textContent = formatNumber(result.stock.current_pallet || 0);
  
  infoDiv.className = 'stock-info';
  infoDiv.style.display = 'block';
} else {
      infoDiv.className = 'stock-info warning';
      infoDiv.style.display = 'block';
      document.getElementById(prefix + 'CurrentStock').textContent = '0';
      document.getElementById(prefix + 'CurrentPallet').textContent = '0';
    }
  } catch (err) {
    console.error('Error getting stock info:', err);
  }
}
// Function: เพิ่มพื้นที่ปลายทาง
function addDestinationLocation() {
  if (availableToLocations.length === 0) {
    alert('กรุณาเลือกคลังปลายทางก่อน');
    return;
  }
  
  destinationCounter++;
  const container = document.getElementById('destinationLocationsContainer');
  
  const itemDiv = document.createElement('div');
  itemDiv.className = 'destination-item';
  itemDiv.id = `dest-${destinationCounter}`;
  
  let locationOptions = '<option value="">เลือกพื้นที่</option>';
  availableToLocations.forEach(loc => {
    locationOptions += `<option value="${loc.location_id}">${loc.location_code} ${loc.zone ? '(' + loc.zone + ')' : ''}</option>`;
  });
  
  // ใช้วันที่ปัจจุบันเป็นค่าเริ่มต้น
  const now = new Date();
  const dateTimeLocal = now.getFullYear() + '-' + 
    String(now.getMonth() + 1).padStart(2, '0') + '-' + 
    String(now.getDate()).padStart(2, '0') + 'T' + 
    String(now.getHours()).padStart(2, '0') + ':' + 
    String(now.getMinutes()).padStart(2, '0');
  
  itemDiv.innerHTML = `
  <button type="button" class="remove-btn" onclick="removeDestination(${destinationCounter})">✕</button>
  <div class="destination-row">
    <div>
      <label style="font-size:12px;">Location</label>
<select class="dest-location form-select select2-destination" id="destLoc-${destinationCounter}" onchange="onDestLocationChange(${destinationCounter})" required>
  ${locationOptions}
</select>
      <div class="stock-info-inline" id="destStock-${destinationCounter}"></div>
    </div>
    <div>
      <label style="font-size:12px;">จำนวน*</label>
      <input type="number" step="1" min="1" class="dest-qty form-control" id="destQty-${destinationCounter}" 
       oninput="updateTransferSummary()" required placeholder="0">
    </div>
    <div>
      <label style="font-size:12px;">KG</label>
<input type="number" step="1" min="0" class="dest-kg form-control" id="destKg-${destinationCounter}" 
       oninput="updateTransferSummary()" placeholder="0">
    </div>
    <div>
      <label style="font-size:12px;">Pallet</label>
      <input type="number" step="1" min="0" class="dest-pallet form-control" id="destPallet-${destinationCounter}" 
             oninput="updateTransferSummary()" placeholder="0">
    </div>
    <div>
      <label style="font-size:12px;">วันที่ย้าย</label>
      <input type="datetime-local" class="dest-date form-control" id="destDate-${destinationCounter}" 
             value="${dateTimeLocal}">
    </div>
  </div>
`;
  
container.appendChild(itemDiv);

// ✅ เริ่มใช้ Select2 สำหรับ destination ที่เพิ่งสร้าง
$(`#destLoc-${destinationCounter}`).select2({
  theme: 'bootstrap-5',
  width: '100%',
  placeholder: 'ค้นหา Location...',
  allowClear: true,
  dropdownParent: $(`#dest-${destinationCounter}`), // ให้ dropdown อยู่ใน parent
  language: {
    noResults: function() {
      return "ไม่พบข้อมูล";
    },
    searching: function() {
      return "กำลังค้นหา...";
    }
  }
});

updateTransferSummary();
}

// Function: ลบพื้นที่ปลายทาง
function removeDestination(id) {
  const item = document.getElementById(`dest-${id}`);
  if (item) {
    item.remove();
    updateTransferSummary();
  }
}

// Function: เมื่อเลือก destination location
async function onDestLocationChange(id) {
  const locationSelect = document.getElementById(`destLoc-${id}`);
  const stockDiv = document.getElementById(`destStock-${id}`);
  
  if (!locationSelect.value || !currentProduct) {
    stockDiv.innerHTML = '';
    return;
  }
  
  const warehouseId = document.getElementById('toWarehouse').value;
  
  try {
    const res = await fetch(`?ajax=get_stock_info&product_id=${currentProduct.product_id}&warehouse_id=${warehouseId}&location_id=${locationSelect.value}`);
    const result = await res.json();
    
    if (result.found) {
      stockDiv.innerHTML = `📦 Stock ปัจจุบัน: <strong>${formatNumber(result.stock.current_stock)}</strong> | Pallet: <strong>${formatNumber(result.stock.current_pallet || 0)}</strong>`;
      stockDiv.style.color = '#28a745';
    } else {
      stockDiv.innerHTML = `📦 Stock ปัจจุบัน: <strong>0</strong> (พื้นที่ว่าง)`;
      stockDiv.style.color = '#6c757d';
    }
  } catch (err) {
    console.error('Error getting stock info:', err);
    stockDiv.innerHTML = '';
  }
}

// Function: อัพเดทสรุปการย้าย
function updateTransferSummary() {
  const qtyInputs = document.querySelectorAll('.dest-qty');
  const kgInputs = document.querySelectorAll('.dest-kg');
  const palletInputs = document.querySelectorAll('.dest-pallet');
  
  let totalQty = 0;
  let totalKg = 0;
  let totalPallet = 0;
  let validDestinations = 0;
  
  qtyInputs.forEach((input, index) => {
    const qty = parseFloat(input.value) || 0;
    const kg = parseFloat(kgInputs[index]?.value || 0);
    const pallet = parseInt(palletInputs[index]?.value || 0);
    
    if (qty > 0) {
      totalQty += qty;
      totalKg += kg;
      totalPallet += pallet;
      validDestinations++;
    }
  });
  
  document.getElementById('totalTransferQty').textContent = formatNumber(totalQty);
document.getElementById('totalTransferKg').textContent = formatNumber(totalKg);
document.getElementById('totalTransferPallet').textContent = formatNumber(totalPallet);
document.getElementById('totalDestinations').textContent = validDestinations;
  
  const summaryDiv = document.getElementById('transferSummary');
  if (validDestinations > 0) {
    summaryDiv.style.display = 'block';
  } else {
    summaryDiv.style.display = 'none';
  }
}
// --- Save Transfer ---
async function saveTransfer() {
  console.log('saveTransfer called'); // Debug
  
  if (!currentProduct) {
    alert('กรุณาเลือกสินค้า');
    return;
  }

  const fromWarehouseEl = document.getElementById('fromWarehouse');
  const fromLocationEl = document.getElementById('fromLocation');
  const toWarehouseEl = document.getElementById('toWarehouse');
  const lotNumberEl = document.getElementById('lotNumber');

  // ตรวจสอบว่า element มีอยู่จริง
  if (!fromWarehouseEl || !fromLocationEl || !toWarehouseEl) {
    console.error('Missing elements:', {
      fromWarehouse: fromWarehouseEl,
      fromLocation: fromLocationEl,
      toWarehouse: toWarehouseEl
    });
    alert('เกิดข้อผิดพลาด: ไม่พบฟอร์มที่จำเป็น');
    return;
  }

  const fromWarehouse = fromWarehouseEl.value;
  const fromLocation = fromLocationEl.value;
  const toWarehouse = toWarehouseEl.value;
  const lotNumber = lotNumberEl ? lotNumberEl.value || null : null;

  console.log('Form values:', { fromWarehouse, fromLocation, toWarehouse, lotNumber }); // Debug

  if (!fromWarehouse || !fromLocation || !toWarehouse) {
    alert('กรุณาเลือกคลังและพื้นที่ต้นทางและปลายทาง');
    return;
  }

  // รวบรวมข้อมูล destinations
  const destinations = [];
  const destItems = document.querySelectorAll('.destination-item');
  
  console.log('Found destination items:', destItems.length); // Debug
  
  try {
    destItems.forEach(item => {
      const id = item.id.split('-')[1];
      console.log('Processing destination:', id); // Debug
      
      const locationEl = document.getElementById(`destLoc-${id}`);
      const qtyEl = document.getElementById(`destQty-${id}`);
      const kgEl = document.getElementById(`destKg-${id}`);
      const palletEl = document.getElementById(`destPallet-${id}`);
      const dateEl = document.getElementById(`destDate-${id}`);
      
      // ตรวจสอบว่า element มีอยู่จริง
      if (!locationEl || !qtyEl) {
        console.error(`Missing elements for destination ${id}`);
        return;
      }
      
      const locationId = locationEl.value;
      const qty = parseFloat(qtyEl.value || 0);
      const kg = kgEl ? parseFloat(kgEl.value || 0) : 0;
      const pallet = palletEl ? parseInt(palletEl.value || 0) : 0;
      const transferDate = dateEl ? dateEl.value || null : null;
      
      console.log(`Destination ${id}:`, { locationId, qty, kg, pallet, transferDate }); // Debug
      
      if (locationId && qty > 0) {
        // ตรวจสอบไม่ให้ย้ายไปยังตำแหน่งเดิม
        if (fromWarehouse === toWarehouse && fromLocation === locationId) {
          const locationText = locationEl.selectedOptions[0]?.text || locationId;
          alert(`ไม่สามารถย้ายไปยังตำแหน่งเดิมได้: ${locationText}`);
          throw new Error('Same location');
        }
        
        destinations.push({
          location_id: parseInt(locationId),
          quantity: qty,
          quantity_kg: kg,
          pallet_count: pallet,
          transfer_date: transferDate ? new Date(transferDate).toISOString().slice(0, 19).replace('T', ' ') : null
        });
      }
    });
  } catch (err) {
    console.error('Error collecting destinations:', err);
    return;
  }

  if (destinations.length === 0) {
    alert('กรุณาระบุพื้นที่ปลายทางและจำนวนอย่างน้อย 1 รายการ');
    return;
  }

  console.log('Collected destinations:', destinations); // Debug

  // คำนวณจำนวนรวม
  const totalQty = destinations.reduce((sum, d) => sum + d.quantity, 0);
  const totalKg = destinations.reduce((sum, d) => sum + d.quantity_kg, 0);
  const totalPallet = destinations.reduce((sum, d) => sum + d.pallet_count, 0);

  console.log('Totals:', { totalQty, totalKg, totalPallet }); // Debug


// ตรวจสอบ stock - ✅ อ่านจาก data-value แทน textContent
const fromStockEl = document.getElementById('fromCurrentStock');
const fromStockQty = fromStockEl ? parseFloat(fromStockEl.getAttribute('data-value') || 0) : 0;

console.log('From stock (raw value):', fromStockQty); // Debug
  
  if (totalQty > fromStockQty) {
    alert(`จำนวนรวมที่ต้องการย้าย (${formatNumber(totalQty)}) มากกว่า Stock ต้นทาง (${formatNumber(fromStockQty)})`);
    return;
  }

  if (!confirm(`ยืนยันการย้ายสินค้าไป ${destinations.length} พื้นที่\n\n📦 จำนวนรวม: ${formatNumber(totalQty)} ชิ้น\n⚖️ น้ำหนักรวม: ${formatNumber(totalKg)} KG\n📋 Pallet รวม: ${formatNumber(totalPallet)}`)) {
    return;
  }

  const data = {
    product_id: currentProduct.product_id,
    unit_id: currentProduct.unit_id,
    from_warehouse: parseInt(fromWarehouse),
    from_location: parseInt(fromLocation),
    to_warehouse: parseInt(toWarehouse),
    destinations: destinations,
    lot_number: lotNumber,
    transfer_date: new Date().toISOString().slice(0, 19).replace('T', ' ')
  };

  console.log('Sending data:', data); // Debug

  try {
    showLoading();
    const res = await fetch('?ajax=save_transfer', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    
    console.log('Response status:', res.status); // Debug
    
    const contentType = res.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      const text = await res.text();
      console.error('Response is not JSON:', text);
      hideLoading();
      alert('เกิดข้อผิดพลาด: Server ตอบกลับไม่ถูกต้อง');
      return;
    }
    
    const result = await res.json();
    console.log('Response:', result); // Debug
    hideLoading();

    if (result.success) {
      alert(`✅ ย้ายสินค้าสำเร็จ!\n- ย้ายไป ${destinations.length} พื้นที่\n- จำนวนรวม: ${formatNumber(totalQty)} ชิ้น`);
      if (confirm('ต้องการย้ายสินค้าต่อ?')) {
        resetForm(false);
      } else {
        window.location.href = 'inventory_view.php';
      }
    } else {
      alert('การย้ายล้มเหลว: ' + (result.error || result.message || 'Unknown error'));
    }
  } catch (err) {
    hideLoading();
    console.error('Save transfer error:', err);
    alert('เกิดข้อผิดพลาดขณะบันทึก: ' + err.message);
  }
}

// --- Reset Form ---
function resetForm(keepProductCode = false) {
  // เก็บรหัสสินค้าไว้ถ้าต้องการ
  const productCode = keepProductCode ? document.getElementById('productCode').value : '';
  // ✅ ทำลาย Select2 ก่อน reset
  if ($('#fromLocation').hasClass('select2-hidden-accessible')) {
    $('#fromLocation').select2('destroy');
  }
  
  // ทำลาย Select2 ของ destination ทั้งหมด
  $('.select2-destination').each(function() {
    if ($(this).hasClass('select2-hidden-accessible')) {
      $(this).select2('destroy');
    }
  });
  currentProduct = null;
  document.getElementById('productCode').value = productCode; // กรอกกลับถ้า keepProductCode = true
  document.getElementById('productName').value = '';
  document.getElementById('transferForm').style.display = 'none';
  document.getElementById('fromWarehouse').value = '';
  document.getElementById('toWarehouse').value = '';
  document.getElementById('fromLocation').innerHTML = '<option value="">เลือก Location</option>';
  document.getElementById('fromLocation').disabled = true;
  
  // Reset destination locations
  document.getElementById('toLocationSection').style.display = 'none';
  document.getElementById('destinationLocationsContainer').innerHTML = '';
  availableToLocations = [];
  destinationCounter = 0;
  
  document.getElementById('lotNumber').innerHTML = '<option value="">-- เลือก LOT (ถ้ามี) --</option>';
  document.getElementById('lotNumber').disabled = true;
  
  // reset stock info
  document.getElementById('fromStockInfo').style.display = 'none';
  document.getElementById('transferSummary').style.display = 'none';
}

// --- Event bindings ---
document.getElementById('btnSearchProduct').addEventListener('click', searchProduct);
document.getElementById('productCode').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    searchProduct();
  }
});

// When from-warehouse changes, load locations and clear stock info
document.getElementById('fromWarehouse').addEventListener('change', function() {
  const wId = this.value;
  loadLocations(wId, 'fromLocation');
  document.getElementById('fromStockInfo').style.display = 'none';
  document.getElementById('lotNumber').innerHTML = '<option value="">-- เลือก LOT (ถ้ามี) --</option>';
  document.getElementById('lotNumber').disabled = true;
});

// When to-warehouse changes
document.getElementById('toWarehouse').addEventListener('change', function() {
  const wId = this.value;
  if (wId && currentProduct) {
    loadLocations(wId, 'toLocation');
  } else {
    document.getElementById('toLocationSection').style.display = 'none';
    document.getElementById('destinationLocationsContainer').innerHTML = '';
    availableToLocations = [];
  }
});


// When from-location selected, fetch stock info and load lots
$(document).on('change', '#fromLocation', function() {
  const locId = this.value;
  const fromWarehouse = document.getElementById('fromWarehouse').value;
  
  if (currentProduct && locId && fromWarehouse) {
    getStockInfo(currentProduct.product_id, fromWarehouse, locId, 'from');
    loadLots(currentProduct.product_id, fromWarehouse, locId);
  } else {
    document.getElementById('fromStockInfo').style.display = 'none';
    document.getElementById('lotNumber').innerHTML = '<option value="">-- เลือก LOT (ถ้ามี) --</option>';
    document.getElementById('lotNumber').disabled = true;
  }
});



// When LOT is selected, update display info
document.getElementById('lotNumber').addEventListener('change', function() {
  const selectedOption = this.options[this.selectedIndex];
  if (selectedOption && selectedOption.value) {
    const qty = selectedOption.dataset.qty || '0';
    const pallet = selectedOption.dataset.pallet || '0';
    
    // อัพเดทข้อมูลแสดงผล (ถ้าต้องการ)
    console.log('Selected LOT:', selectedOption.value);
    console.log('Available Qty:', qty);
    console.log('Available Pallet:', pallet);
  }
});
// --- Load LOT numbers for selected location ---
async function loadLots(productId, warehouseId, locationId) {
  const lotSelect = document.getElementById('lotNumber');
  lotSelect.innerHTML = '<option value="">-- เลือก LOT (ถ้ามี) --</option>';
  lotSelect.disabled = true;

  if (!productId || !warehouseId || !locationId) return;

  try {
    const res = await fetch(`?ajax=get_lots_by_location&product_id=${productId}&warehouse_id=${warehouseId}&location_id=${locationId}`);
    const lots = await res.json();

    if (lots.length === 0) {
      lotSelect.innerHTML = '<option value="">-- ไม่มี LOT --</option>';
      lotSelect.disabled = true;
      return;
    }

    lots.forEach(lot => {
      const option = document.createElement('option');
      option.value = lot.batch_lot;
      option.dataset.qty = lot.remaining_qty;
      option.dataset.pallet = lot.remaining_pallet || 0;
      option.textContent = `${lot.batch_lot} (${formatNumber(lot.remaining_qty)} | ${formatNumber(lot.remaining_pallet || 0)} Pallet)`;
      lotSelect.appendChild(option);
    });

    lotSelect.disabled = false;
  } catch (err) {
    console.error('Error loading lots:', err);
    lotSelect.disabled = false;
  }
}
document.getElementById('btnSaveTransfer').addEventListener('click', function() {
  console.log('Button clicked!'); // Debug
  saveTransfer();
});
document.getElementById('btnReset').addEventListener('click', function(){
  if (confirm('ต้องการยกเลิกแบบฟอร์มหรือไม่?')) resetForm(false); // ล้างรหัสสินค้าด้วย
});
</script>

</body>
</html>
