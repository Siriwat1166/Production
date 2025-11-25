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
        w.warehouse_name,
        SUM(i.current_stock) as total_stock,
        COUNT(DISTINCT i.location_id) as location_count
    FROM Inventory_Stock i
    INNER JOIN Warehouses w ON i.warehouse_id = w.warehouse_id
    WHERE i.product_id = ? 
    AND i.current_stock > 0
    AND (w.is_active = 1 OR w.is_active IS NULL)
    GROUP BY w.warehouse_id, w.warehouse_name
    HAVING SUM(i.current_stock) > 0
    ORDER BY w.warehouse_name
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
        SELECT warehouse_id, warehouse_name 
        FROM Warehouses 
        WHERE is_active = 1 OR is_active IS NULL 
        ORDER BY warehouse_name
    ");
    echo json_encode($stmt->fetchAll());
    break;
            case 'save_transfer':
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!is_array($data)) {
        echo json_encode(['success' => false, 'error' => 'Invalid payload']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        // Validate
        if (empty($data['product_id']) || empty($data['quantity'])) {
            throw new Exception('ข้อมูลไม่ครบถ้วน');
        }

        $productId = (int)$data['product_id'];
        $fromWarehouse = (int)$data['from_warehouse'];
        $fromLocation = (int)$data['from_location'];
        $toWarehouse = (int)$data['to_warehouse'];
        $toLocation = (int)$data['to_location'];
        $quantity = (float)$data['quantity'];
        $quantityKg = !empty($data['quantity_kg']) ? (float)$data['quantity_kg'] : 0;
        $palletCount = !empty($data['pallet_count']) ? (int)$data['pallet_count'] : 0;
        $lotNumber = $data['lot_number'] ?? null;
        $transferDate = $data['transfer_date'] ?? date('Y-m-d H:i:s');
        $userId = $_SESSION['user_id'] ?? 1;

        if ($quantity <= 0) throw new Exception('จำนวนต้องมากกว่า 0');

        // Debug
        error_log("Transfer: Product=$productId, From=$fromWarehouse-$fromLocation, To=$toWarehouse-$toLocation, Qty=$quantity, Pallet=$palletCount");

        // ตรวจสอบสต็อกต้นทาง
        $stmt = $pdo->prepare("
            SELECT current_stock, available_stock, current_pallet, available_pallet
            FROM Inventory_Stock
            WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
        ");
        $stmt->execute([$productId, $fromWarehouse, $fromLocation]);
        $fromStock = $stmt->fetch();

        if (!$fromStock) {
            throw new Exception('ไม่พบข้อมูลสต็อกต้นทาง');
        }

        if ((float)$fromStock['available_stock'] < $quantity) {
            throw new Exception('สต็อกไม่เพียงพอ (มี: '.$fromStock['available_stock'].' ต้องการ: '.$quantity.')');
        }

        if ($palletCount > 0 && (int)$fromStock['available_pallet'] < $palletCount) {
            throw new Exception('Pallet ไม่เพียงพอ (มี: '.$fromStock['available_pallet'].' ต้องการ: '.$palletCount.')');
        }

        // 1. ลดสต็อกต้นทาง
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
            $productId, $fromWarehouse, $fromLocation
        ]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('ไม่สามารถหักสต็อกต้นทางได้');
        }

        // 2. เพิ่มสต็อกปลายทาง
        $stmt = $pdo->prepare("
            SELECT inventory_id FROM Inventory_Stock
            WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
        ");
        $stmt->execute([$productId, $toWarehouse, $toLocation]);
        $toStock = $stmt->fetch();

        if ($toStock) {
            $stmt = $pdo->prepare("
                UPDATE Inventory_Stock
                SET current_stock = ISNULL(current_stock, 0) + ?,
                    available_stock = ISNULL(available_stock, 0) + ?,
                    current_pallet = ISNULL(current_pallet, 0) + ?,
                    available_pallet = ISNULL(available_pallet, 0) + ?,
                    last_updated = GETDATE(),
                    last_movement_date = GETDATE()
                WHERE product_id = ? AND warehouse_id = ? AND location_id = ?
            ");
            $stmt->execute([
                $quantity, $quantity, $palletCount, $palletCount,
                $productId, $toWarehouse, $toLocation
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO Inventory_Stock (
                    product_id, warehouse_id, location_id,
                    current_stock, available_stock,
                    current_pallet, available_pallet,
                    last_updated, last_movement_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
            ");
            $stmt->execute([
                $productId, $toWarehouse, $toLocation,
                $quantity, $quantity, $palletCount, $palletCount
            ]);
        }

        // 3. บันทึก Movement OUT
        $stmt = $pdo->prepare("
            INSERT INTO Stock_Movements (
                product_id, warehouse_id, location_id,
                movement_type, quantity, unit_id,
                reference_type, batch_lot,
                movement_date, created_by, notes, quantity_pallet
            ) VALUES (?, ?, ?, 'OUT', ?, ?, 'TRANSFER', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $productId, $fromWarehouse, $fromLocation,
            $quantity, $data['unit_id'] ?? null, $lotNumber,
            $transferDate, $userId, 'Transfer to Location', $palletCount
        ]);

        // 4. บันทึก Movement IN
        $stmt = $pdo->prepare("
            INSERT INTO Stock_Movements (
                product_id, warehouse_id, location_id,
                movement_type, quantity, unit_id,
                reference_type, batch_lot,
                movement_date, created_by, notes, quantity_pallet
            ) VALUES (?, ?, ?, 'IN', ?, ?, 'TRANSFER', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $productId, $toWarehouse, $toLocation,
            $quantity, $data['unit_id'] ?? null, $lotNumber,
            $transferDate, $userId, 'Transfer from Location', $palletCount
        ]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'ย้ายสินค้าสำเร็จ'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Transfer Error: " . $e->getMessage());
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

// Load master data
$warehouses = $pdo->query("
    SELECT warehouse_id, warehouse_name 
    FROM Warehouses 
    WHERE is_active = 1 OR is_active IS NULL 
    ORDER BY warehouse_name
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

      <!-- Arrow -->
      <div class="col-md-2">
        <div class="transfer-arrow">
          <i class="fas fa-arrow-right"></i>
        </div>
      </div>

      <!-- TO Section -->
      <div class="col-md-5">
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
            
            <div class="mb-3">
              <label class="form-label">Location ปลายทาง</label>
              <select class="form-select" id="toLocation" disabled required>
                <option value="">เลือก Location</option>
              </select>
            </div>
            
            <div id="toStockInfo" class="stock-info" style="display:none;">
              <div class="d-flex justify-content-between mb-2">
                <span><strong>สต็อกปัจจุบัน:</strong></span>
                <span id="toCurrentStock">0</span>
              </div>
              <div class="d-flex justify-content-between">
                <span><strong>Pallet:</strong></span>
                <span id="toCurrentPallet">0</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Quantity Section -->
    <div class="card">
      <div class="card-header">
        <i class="fas fa-boxes me-2"></i>จำนวนที่ย้าย
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">QUANTITY</label>
            <input type="number" class="form-control" id="quantity" step="0.01" min="0" placeholder="0.00">
          </div>
          <div class="col-md-3">
            <label class="form-label">QUANTITY (PALLET)</label>
            <input type="number" class="form-control" id="palletCount" min="0" placeholder="0">
          </div>
          <div class="col-md-3">
            <label class="form-label">QUANTITY (KG)</label>
            <input type="number" class="form-control" id="quantityKg" step="0.01" min="0" placeholder="0.00">
          </div>
          <div class="col-md-3">
            <label class="form-label">วันที่ย้าย</label>
            <input type="datetime-local" class="form-control" id="transferDate" value="<?= date('Y-m-d\TH:i'); ?>">
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
      option.textContent = `${w.warehouse_name} (สต็อก: ${parseFloat(w.total_stock).toFixed(2)})`;
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
  const codeEl = document.getElementById('productCode');
  const code = codeEl.value.trim();
  if (!code) {
    alert('กรุณาระบุรหัสสินค้า');
    codeEl.focus();
    return;
  }

  try {
    showLoading();
    const res = await fetch(`?ajax=search_product&code=${encodeURIComponent(code)}`);
    const result = await res.json();
    
    if (result.found) {
      currentProduct = result.product;
      document.getElementById('productName').value = result.product.product_name;
      
      // ✅ โหลดคลังที่มีสินค้านี้อยู่
      await loadWarehousesWithStock(result.product.product_id);
      
      document.getElementById('transferForm').style.display = 'block';
      codeEl.classList.remove('is-invalid');
      codeEl.classList.add('is-valid');
      setTimeout(() => codeEl.classList.remove('is-valid'), 1500);
    } else {
      currentProduct = null;
      document.getElementById('productName').value = '';
      alert(result.message || 'ไม่พบสินค้า');
      codeEl.classList.add('is-invalid');
      setTimeout(() => codeEl.classList.remove('is-invalid'), 2000);
    }
    
    hideLoading();
  } catch (err) {
    hideLoading();
    console.error('Search error:', err);
    alert('เกิดข้อผิดพลาดในการค้นหา');
  }
}


// --- Load Locations for a warehouse ---
async function loadLocations(warehouseId, targetSelect) {
  const select = document.getElementById(targetSelect);
  select.innerHTML = '<option value="">เลือก Location</option>';
  select.disabled = true;

  if (!warehouseId) return;

  try {
    // ตรวจสอบว่าเป็น FROM หรือ TO
    const isFrom = targetSelect === 'fromLocation';
    
    let url = `?ajax=get_locations_by_warehouse&warehouse_id=${warehouseId}`;
    
    // ถ้าเป็น FROM และมีสินค้าที่เลือกแล้ว ให้ส่ง product_id และ is_from=true
    if (isFrom && currentProduct) {
      url += `&product_id=${currentProduct.product_id}&is_from=true`;
    }
    
    const res = await fetch(url);
    const locations = await res.json();

    if (locations.length === 0) {
      select.innerHTML = '<option value="">ไม่มี Location ที่มีสินค้า</option>';
      select.disabled = true;
      return;
    }

    // group by zone
    const byZone = {};
    locations.forEach(loc => {
      const zone = loc.zone ? `Zone ${loc.zone}` : 'Zone -';
      if (!byZone[zone]) byZone[zone] = [];
      byZone[zone].push(loc);
    });

    Object.keys(byZone).sort().forEach(zone => {
      const optgroup = document.createElement('optgroup');
      optgroup.label = zone;
      byZone[zone].forEach(loc => {
        const option = document.createElement('option');
        option.value = loc.location_id;
        // ถ้าเป็น FROM แสดงจำนวนสต็อก
        if (isFrom && loc.current_stock !== undefined) {
          option.textContent = `${loc.location_code} (สต็อก: ${parseFloat(loc.current_stock).toFixed(2)})`;
        } else {
          option.textContent = loc.location_code;
        }
        optgroup.appendChild(option);
      });
      select.appendChild(optgroup);
    });

    select.disabled = false;
  } catch (err) {
    console.error('Error loading locations:', err);
    select.disabled = false;
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
      document.getElementById(prefix + 'CurrentStock').textContent = Number(result.stock.current_stock || 0).toFixed(2);
      document.getElementById(prefix + 'CurrentPallet').textContent = parseInt(result.stock.current_pallet || 0, 10);
      infoDiv.className = 'stock-info';
      infoDiv.style.display = 'block';
    } else {
      infoDiv.className = 'stock-info warning';
      infoDiv.style.display = 'block';
      document.getElementById(prefix + 'CurrentStock').textContent = '0.00';
      document.getElementById(prefix + 'CurrentPallet').textContent = '0';
    }
  } catch (err) {
    console.error('Error getting stock info:', err);
  }
}

// --- Save Transfer ---
async function saveTransfer() {
  if (!currentProduct) {
    alert('กรุณาเลือกสินค้า');
    return;
  }

  const fromWarehouse = document.getElementById('fromWarehouse').value;
  const fromLocation = document.getElementById('fromLocation').value;
  const toWarehouse = document.getElementById('toWarehouse').value;
  const toLocation = document.getElementById('toLocation').value;
  const quantity = document.getElementById('quantity').value;

  if (!fromWarehouse || !fromLocation || !toWarehouse || !toLocation || !quantity) {
    alert('กรุณากรอกข้อมูลให้ครบถ้วน');
    return;
  }

  if (parseFloat(quantity) <= 0) {
    alert('จำนวนต้องมากกว่า 0');
    return;
  }

  if (fromWarehouse === toWarehouse && fromLocation === toLocation) {
    alert('ไม่สามารถย้ายไปยังตำแหน่งเดิมได้');
    return;
  }

  if (!confirm('ยืนยันการย้ายสินค้า?')) return;

  const data = {
    product_id: currentProduct.product_id,
    unit_id: currentProduct.unit_id,
    from_warehouse: parseInt(fromWarehouse, 10),
    from_location: parseInt(fromLocation, 10),
    to_warehouse: parseInt(toWarehouse, 10),
    to_location: parseInt(toLocation, 10),
    quantity: parseFloat(quantity),
    quantity_kg: parseFloat(document.getElementById('quantityKg').value || 0),
    pallet_count: parseInt(document.getElementById('palletCount').value || 0, 10),
    lot_number: document.getElementById('lotNumber').value || null,
    transfer_date: (() => {
    const dt = document.getElementById('transferDate').value;
    if (!dt) return new Date().toISOString().slice(0, 19).replace('T', ' ');
    return new Date(dt).toISOString().slice(0, 19).replace('T', ' ');
})()
  };

  try {
    showLoading();
    const res = await fetch('?ajax=save_transfer', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    const result = await res.json();
    hideLoading();

    if (result.success) {
      alert('✅ ย้ายสินค้าสำเร็จ!');
      if (confirm('ต้องการย้ายสินค้าต่อ?')) {
        resetForm();
      } else {
        window.location.href = 'inventory_view.php';
      }
    } else {
      alert('การย้ายล้มเหลว: ' + (result.error || result.message || 'Unknown error'));
    }
  } catch (err) {
    hideLoading();
    console.error('Save transfer error:', err);
    alert('เกิดข้อผิดพลาดขณะบันทึก');
  }
}

// --- Reset Form ---
function resetForm() {
  currentProduct = null;
  document.getElementById('productCode').value = '';
  document.getElementById('productName').value = '';
  document.getElementById('transferForm').style.display = 'none';
  document.getElementById('fromWarehouse').value = '';
  document.getElementById('toWarehouse').value = '';
  document.getElementById('fromLocation').innerHTML = '<option value="">เลือก Location</option>';
  document.getElementById('toLocation').innerHTML = '<option value="">เลือก Location</option>';
  document.getElementById('fromLocation').disabled = true;
  document.getElementById('toLocation').disabled = true;
  document.getElementById('lotNumber').innerHTML = '<option value="">-- เลือก LOT (ถ้ามี) --</option>';
document.getElementById('lotNumber').disabled = true;
  document.getElementById('quantity').value = '';
  document.getElementById('palletCount').value = '';
  document.getElementById('quantityKg').value = '';
  // reset stock info
  document.getElementById('fromStockInfo').style.display = 'none';
  document.getElementById('toStockInfo').style.display = 'none';
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
  loadLocations(wId, 'toLocation');
  document.getElementById('toStockInfo').style.display = 'none';
});

// When from-location selected, fetch stock info and load lots
document.getElementById('fromLocation').addEventListener('change', function() {
  const locId = this.value;
  if (currentProduct && locId && document.getElementById('fromWarehouse').value) {
    getStockInfo(currentProduct.product_id, document.getElementById('fromWarehouse').value, locId, 'from');
    loadLots(currentProduct.product_id, document.getElementById('fromWarehouse').value, locId);
  } else {
    document.getElementById('fromStockInfo').style.display = 'none';
    document.getElementById('lotNumber').innerHTML = '<option value="">-- เลือก LOT (ถ้ามี) --</option>';
    document.getElementById('lotNumber').disabled = true;
  }
});

// When to-location selected, fetch stock info
document.getElementById('toLocation').addEventListener('change', function() {
  const locId = this.value;
  if (currentProduct && locId && document.getElementById('toWarehouse').value) {
    getStockInfo(currentProduct.product_id, document.getElementById('toWarehouse').value, locId, 'to');
  } else {
    document.getElementById('toStockInfo').style.display = 'none';
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
      option.textContent = `${lot.batch_lot} (${parseFloat(lot.remaining_qty).toFixed(2)} | ${parseInt(lot.remaining_pallet || 0)} Pallet)`;
      lotSelect.appendChild(option);
    });

    lotSelect.disabled = false;
  } catch (err) {
    console.error('Error loading lots:', err);
    lotSelect.disabled = false;
  }
}
document.getElementById('btnSaveTransfer').addEventListener('click', saveTransfer);
document.getElementById('btnReset').addEventListener('click', function(){
  if (confirm('ต้องการยกเลิกแบบฟอร์มหรือไม่?')) resetForm();
});
</script>

</body>
</html>
