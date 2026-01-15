<?php
// stock_movements_list.php - รายการเคลื่อนไหวสต็อกทั้งหมด
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
$auth->requireRole(['admin', 'editor', 'viewer']);

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
            case 'get_movements_list':
                $dateFrom = $_GET['date_from'] ?? '';
                $dateTo = $_GET['date_to'] ?? '';
                $warehouseId = $_GET['warehouse_id'] ?? '';
                $productCode = $_GET['product_code'] ?? '';
                $movementType = $_GET['movement_type'] ?? '';
                $referenceType = $_GET['reference_type'] ?? '';

                $sql = "
                    SELECT 
                        sm.movement_id,
                        sm.movement_date,
                        sm.movement_type,
                        sm.reference_type,
                        sm.reference_number,
                        mp.SSP_Code as product_code,
                        mp.Name as product_name,
                        w.warehouse_name,
                        wl.aisle as location_code,
                        sm.quantity,
                        sm.quantity_pallet,
                        sm.quantity_kg,
                        sm.batch_lot,
                        sm.notes,
                        u.unit_symbol,
                        ISNULL(usr.username, 'User ID: ' + CAST(sm.created_by AS VARCHAR(10))) as created_by_name
                    FROM Stock_Movements sm
                    JOIN Master_Products_ID mp ON sm.product_id = mp.id
                    JOIN Warehouses w ON sm.warehouse_id = w.warehouse_id
                    LEFT JOIN Warehouse_Locations wl ON sm.location_id = wl.location_id
                    LEFT JOIN Units u ON sm.unit_id = u.unit_id
                    LEFT JOIN dbo.Users usr ON sm.created_by = usr.user_id
                    WHERE 1=1
                ";

                $params = [];

                if ($dateFrom) {
                    $sql .= " AND CAST(sm.movement_date AS DATE) >= ?";
                    $params[] = $dateFrom;
                }

                if ($dateTo) {
                    $sql .= " AND CAST(sm.movement_date AS DATE) <= ?";
                    $params[] = $dateTo;
                }

                if ($warehouseId) {
                    $sql .= " AND sm.warehouse_id = ?";
                    $params[] = $warehouseId;
                }

                if ($productCode) {
                    $sql .= " AND mp.SSP_Code LIKE ?";
                    $params[] = '%' . $productCode . '%';
                }

                if ($movementType) {
                    $sql .= " AND sm.movement_type = ?";
                    $params[] = $movementType;
                }

                if ($referenceType) {
                    $sql .= " AND sm.reference_type = ?";
                    $params[] = $referenceType;
                }

                $sql .= " ORDER BY sm.movement_date DESC, sm.movement_id DESC";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll();

                echo json_encode(['success' => true, 'data' => $results]);
                break;

            case 'get_movement_detail':
                $movementId = (int)($_GET['movement_id'] ?? 0);
                
                if ($movementId <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
                    exit;
                }

                $stmt = $pdo->prepare("
                    SELECT 
                        sm.*,
                        mp.SSP_Code,
                        mp.Name as product_name,
                        w.warehouse_name,
                        wl.aisle as location_code,
                        u.unit_symbol,
                        ISNULL(usr.username, 'User ID: ' + CAST(sm.created_by AS VARCHAR(10))) as created_by_name
                    FROM Stock_Movements sm
                    JOIN Master_Products_ID mp ON sm.product_id = mp.id
                    JOIN Warehouses w ON sm.warehouse_id = w.warehouse_id
                    LEFT JOIN Warehouse_Locations wl ON sm.location_id = wl.location_id
                    LEFT JOIN Units u ON sm.unit_id = u.unit_id
                    LEFT JOIN dbo.Users usr ON sm.created_by = usr.user_id
                    WHERE sm.movement_id = ?
                ");
                $stmt->execute([$movementId]);
                $detail = $stmt->fetch();

                if ($detail) {
                    echo json_encode(['success' => true, 'data' => $detail]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'ไม่พบข้อมูล']);
                }
                break;

            case 'export_excel':
                // สำหรับ export ในอนาคต
                echo json_encode(['success' => false, 'error' => 'ฟีเจอร์นี้ยังไม่พร้อมใช้งาน']);
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
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการเคลื่อนไหวสต็อก</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
    :root {
        --primary-color: #8B4513;
        --secondary-color: #FF8C00;
        --accent-color: #A0522D;
        --success-color: #059669;
        --primary-gradient: linear-gradient(135deg, #8B4513, #A0522D);
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        background: linear-gradient(135deg, #F5DEB3 0%, #DEB887 50%, #D2B48C 100%);
        min-height: 100vh;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
        color: #FFE5CC !important;
        text-decoration: none;
        font-size: 1.5rem;
        padding: 0.5rem;
        margin-right: 1rem;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.4);
    }

    .btn-back-arrow:hover {
        transform: translateX(-3px);
        color: white !important;
    }

    .btn-back-arrow i {
        color: #FFE5CC !important;
    }

    .btn-back-arrow:hover i {
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

    /* Stats Cards */
    .stats-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .stat-box {
        background: linear-gradient(135deg, #ffffff 0%, rgba(245, 222, 179, 0.3) 100%);
        border-left: 4px solid #8B4513;
        padding: 1rem;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(139, 69, 19, 0.15);
        border: 2px solid rgba(139, 69, 19, 0.1);
        transition: all 0.3s ease;
    }

    .stat-box:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(139, 69, 19, 0.2);
    }

    .stat-value {
        font-size: 1.8rem;
        font-weight: 700;
        color: #8B4513;
    }

    .stat-label {
        font-size: 0.9rem;
        color: #6d4c41;
        margin-top: 0.25rem;
    }

    .filter-card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 10px rgba(139, 69, 19, 0.15);
        margin-bottom: 1.5rem;
        border: 2px solid rgba(139, 69, 19, 0.2);
        width: 100%;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 15px;
        margin-bottom: 15px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
    }

    .form-group label {
        color: #8B4513;
        font-weight: 600;
        margin-bottom: 6px;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-group input,
    .form-group select {
        padding: 0.5rem 1rem;
        border: 2px solid rgba(139, 69, 19, 0.2);
        border-radius: 10px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-size: 14px;
        transition: all 0.3s;
        background: white;
        color: #3e2723;
    }

    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #8B4513;
        background: #fffbf5;
        box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.15);
    }

    .form-group input::placeholder {
        color: #a1887f;
    }

    .btn-filter,
    .btn-clear {
        padding: 12px 30px;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(139, 69, 19, 0.3);
    }

    .btn-filter {
        background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
        color: white;
    }

    .btn-filter:hover {
        background: linear-gradient(135deg, #A0522D 0%, #8B4513 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(139, 69, 19, 0.4);
    }

    .btn-clear {
        background: linear-gradient(135deg, #FF8C00 0%, #FFA500 100%);
        color: white;
        margin-left: 10px;
    }

    .btn-clear:hover {
        background: linear-gradient(135deg, #FFA500 0%, #FF8C00 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(255, 140, 0, 0.4);
    }

    .table-card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 10px rgba(139, 69, 19, 0.15);
        border: 2px solid rgba(139, 69, 19, 0.2);
        width: 100%;
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1400px;
    }

    th {
        background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
        color: white;
        padding: 12px 8px;
        text-align: center;
        font-weight: 600;
        font-size: 13px;
        white-space: nowrap;
        border: 1px solid #6d4c41;
    }

    td {
        padding: 10px 8px;
        vertical-align: middle;
        border-bottom: 1px solid rgba(139, 69, 19, 0.1);
        font-size: 13px;
        color: #3e2723;
    }

    tr:hover td {
        background-color: rgba(255, 140, 0, 0.1);
    }

    tr:nth-child(even) td {
        background-color: rgba(245, 222, 179, 0.3);
    }

    tr:nth-child(even):hover td {
        background-color: rgba(255, 140, 0, 0.1);
    }

    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
    }

    .badge-in {
        background: rgba(5, 150, 105, 0.15);
        color: #059669;
        border: 1px solid rgba(5, 150, 105, 0.3);
    }

    .badge-out {
        background: rgba(220, 38, 38, 0.15);
        color: #dc2626;
        border: 1px solid rgba(220, 38, 38, 0.3);
    }

    .badge-transfer {
        background: rgba(59, 130, 246, 0.15);
        color: #3b82f6;
        border: 1px solid rgba(59, 130, 246, 0.3);
    }

    .badge-adjust {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
        border: 1px solid rgba(245, 158, 11, 0.3);
    }

    .btn-action {
        padding: 8px 14px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
        transition: all 0.3s;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        background: linear-gradient(135deg, #FF8C00 0%, #FFA500 100%);
        color: white;
    }

    .btn-action:hover {
        background: linear-gradient(135deg, #FFA500 0%, #FF8C00 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(255, 140, 0, 0.4);
    }

    /* Modal */
    .modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(139, 69, 19, 0.7);
        animation: fadeIn 0.3s;
        overflow-y: auto;
    }

    .modal.show {
        display: block;
    }

    .modal-content {
        background: white;
        border-radius: 16px;
        width: 90%;
        max-width: 1000px;
        margin: 50px auto;
        max-height: calc(100vh - 100px);
        overflow-y: auto;
        animation: slideDown 0.3s;
        box-shadow: 0 10px 40px rgba(139, 69, 19, 0.3);
        border: 2px solid rgba(139, 69, 19, 0.2);
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes slideDown {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .modal-header {
        background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
        color: white;
        padding: 25px 30px;
        border-radius: 16px 16px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 10;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }

    .modal-header h2 {
        font-size: 20px;
        font-weight: 600;
        margin: 0;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
    }

    .close-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s;
        line-height: 1;
        padding: 0;
    }

    .close-btn:hover {
        background: rgba(255,255,255,0.3);
        transform: rotate(90deg);
    }

    .modal-body {
        padding: 30px;
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
        margin-bottom: 20px;
    }

    .detail-item {
        padding: 12px;
        background: rgba(245, 222, 179, 0.3);
        border-radius: 8px;
        border-left: 3px solid #8B4513;
    }

    .detail-item label {
        font-size: 0.85rem;
        color: #A0522D;
        display: block;
        margin-bottom: 4px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .detail-item .value {
        font-size: 14px;
        color: #3e2723;
        font-weight: 600;
    }

    .loading {
        text-align: center;
        padding: 40px;
        color: #A0522D;
    }

    .no-data {
        text-align: center;
        padding: 40px;
        color: #8B4513;
    }

    @media (max-width: 1200px) {
        .filter-grid {
            grid-template-columns: repeat(3, 1fr);
        }
        .stats-row {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .filter-grid,
        .detail-grid,
        .stats-row {
            grid-template-columns: 1fr;
        }
    }
</style>
</head>
<body>
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
                            <i class="fas fa-exchange-alt me-2"></i>รายการเคลื่อนไหวสต็อก (Stock Movements)
                        </h5>
                        <small class="text-light">ติดตามการเคลื่อนไหวสต็อกทั้งหมดในระบบ</small>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-white">
                        <i class="fas fa-user-circle me-2"></i>
                        <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'System Administrator'); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
        <!-- Summary Cards -->
<div class="stats-row" id="statsRow">
    <div class="stat-box" style="border-left-color: #059669;">
        <div class="stat-value" style="color: #059669;" id="sumIn">0</div>
        <div class="stat-label">รับเข้า (IN)</div>
    </div>
    <div class="stat-box" style="border-left-color: #dc2626;">
        <div class="stat-value" style="color: #dc2626;" id="sumOut">0</div>
        <div class="stat-label">จ่ายออก (OUT)</div>
    </div>
    <div class="stat-box" style="border-left-color: #3b82f6;">
        <div class="stat-value" style="color: #3b82f6;" id="sumTransfer">0</div>
        <div class="stat-label">โอนย้าย (TRANSFER)</div>
    </div>
    <div class="stat-box" style="border-left-color: #f59e0b;">
        <div class="stat-value" style="color: #f59e0b;" id="sumAdjustment">0</div>
        <div class="stat-label">ปรับปรุง (ADJUSTMENT)</div>
    </div>
    <div class="stat-box">
        <div class="stat-value" id="sumTotal">0</div>
        <div class="stat-label">ทั้งหมด</div>
    </div>
</div>

        <!-- Filter Section -->
        <div class="filter-card">
            <div class="filter-grid">
                <div class="form-group">
                    <label>วันที่เริ่มต้น</label>
                    <input type="date" id="dateFrom" value="<?php echo date('Y-m-01'); ?>">
                </div>
                <div class="form-group">
                    <label>วันที่สิ้นสุด</label>
                    <input type="date" id="dateTo" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>คลังสินค้า</label>
                    <select id="warehouseFilter">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?php echo $wh['warehouse_id']; ?>">
                                <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>รหัสสินค้า</label>
                    <input type="text" id="productCodeFilter" placeholder="ค้นหารหัสสินค้า...">
                </div>
                <div class="form-group">
                    <label>ประเภทการเคลื่อนไหว</label>
                    <select id="movementTypeFilter">
                        <option value="">ทั้งหมด</option>
                        <option value="IN">รับเข้า (IN)</option>
                        <option value="OUT">จ่ายออก (OUT)</option>
                        <option value="TRANSFER">โอนย้าย (TRANSFER)</option>
                        <option value="ADJUST">ปรับยอด (ADJUST)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>อ้างอิง</label>
                    <select id="referenceTypeFilter">
                        <option value="">ทั้งหมด</option>
                        <option value="GOODS_RECEIPT">รับจาก PO</option>
                        <option value="DIRECT_RECEIPT">รับโดยตรง</option>
                        <option value="DISPATCH">จ่ายออก</option>
                        <option value="TRANSFER">โอนย้าย</option>
                        <option value="ADJUSTMENT">ปรับยอด</option>
                        <option value="CANCEL_RECEIPT">ยกเลิกการรับ</option>
                        <option value="CANCEL_DISPATCH">ยกเลิกการจ่าย</option>
                    </select>
                </div>
            </div>
            <div>
                <button class="btn-filter" onclick="loadData()">🔍 ค้นหา</button>
                <button class="btn-clear" onclick="clearFilter()">🔄 ล้างตัวกรอง</button>
            </div>
        </div>

        <!-- Table Section -->
        <div class="table-card">
            <div id="tableContainer">
                <div class="loading">🔄 กำลังโหลดข้อมูล...</div>
            </div>
        </div>
    </div>

    <!-- View Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>รายละเอียดการเคลื่อนไหว</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="loading">กำลังโหลด...</div>
            </div>
        </div>
    </div>
</div>
    <script>
let currentData = [];

document.addEventListener('DOMContentLoaded', function() {
    loadData();
});

async function loadData() {
    const dateFrom = document.getElementById('dateFrom')?.value || '<?php echo date('Y-m-01'); ?>';
    const dateTo = document.getElementById('dateTo')?.value || '<?php echo date('Y-m-d'); ?>';
    const warehouseId = document.getElementById('warehouseFilter')?.value || '';
    const productCode = document.getElementById('productCodeFilter')?.value || '';
    const movementType = document.getElementById('movementTypeFilter')?.value || '';
    const referenceType = document.getElementById('referenceTypeFilter')?.value || '';

    const tableContainer = document.getElementById('tableContainer');
    if (tableContainer) {
        tableContainer.innerHTML = '<div class="loading">🔄 กำลังโหลดข้อมูล...</div>';
    }

    const params = new URLSearchParams({
        date_from: dateFrom,
        date_to: dateTo,
        warehouse_id: warehouseId,
        product_code: productCode,
        movement_type: movementType,
        reference_type: referenceType
    });

    try {
        const res = await fetch(`?ajax=get_movements_list&${params}`);
        const result = await res.json();

        if (result.success) {
            currentData = result.data || [];
            renderTable(currentData);
            updateSummary(currentData);
        } else {
            if (tableContainer) {
                tableContainer.innerHTML = 
                    '<div class="no-data">❌ เกิดข้อผิดพลาด: ' + (result.error || 'Unknown') + '</div>';
            }
        }
    } catch (err) {
        console.error('Load error:', err);
        if (tableContainer) {
            tableContainer.innerHTML = 
                '<div class="no-data">❌ เกิดข้อผิดพลาด: ' + err.message + '</div>';
        }
    }
}

function updateSummary(data) {
    const summary = {
        IN: 0,
        OUT: 0,
        TRANSFER: 0,
        ADJUSTMENT: 0,
        total: (data || []).length
    };

    if (data && Array.isArray(data)) {
        data.forEach(row => {
            const moveType = (row.movement_type || '').toUpperCase().trim();
            const refType = (row.reference_type || '').toUpperCase().trim();
            
            // ✅ ถ้า reference_type เป็น TRANSFER, TRANSFER_IN, หรือ TRANSFER_OUT → นับเป็น TRANSFER
            if (refType.startsWith('TRANSFER')) {
                summary.TRANSFER++;
            } 
            // ✅ ถ้า reference_type = DISPATCH → นับเป็น OUT
            else if (refType === 'DISPATCH' || refType === 'GOODS_RECEIPT') {
                if (moveType === 'IN') summary.IN++;
                else if (moveType === 'OUT') summary.OUT++;
            }
            // ✅ ถ้าเป็น ADJUSTMENT → นับเป็น ADJUSTMENT
            else if (refType === 'ADJUSTMENT' || refType === 'ADJ') {
                summary.ADJUSTMENT++;
            }
            // ✅ Default: นับตาม movement_type
            else {
                if (moveType === 'IN') summary.IN++;
                else if (moveType === 'OUT') summary.OUT++;
            }
        });
    }

    console.log('Summary:', summary);

    const sumInEl = document.getElementById('sumIn');
    const sumOutEl = document.getElementById('sumOut');
    const sumTransferEl = document.getElementById('sumTransfer');
    const sumTotalEl = document.getElementById('sumTotal');
    const sumAdjustmentEl = document.getElementById('sumAdjustment');

    if (sumInEl) sumInEl.textContent = summary.IN;
    if (sumOutEl) sumOutEl.textContent = summary.OUT;
    if (sumTransferEl) sumTransferEl.textContent = summary.TRANSFER;
    if (sumTotalEl) sumTotalEl.textContent = summary.total;
    if (sumAdjustmentEl) sumAdjustmentEl.textContent = summary.ADJUSTMENT;
}

function renderTable(data) {
    const tableContainer = document.getElementById('tableContainer');
    
    if (!tableContainer) return;
    
    if (!data || data.length === 0) {
        tableContainer.innerHTML = '<div class="no-data">📭 ไม่พบข้อมูล</div>';
        return;
    }

    let html = `
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th style="width: 120px;">วันที่</th>
                    <th style="width: 100px;">ประเภท</th>
                    <th style="width: 120px;">อ้างอิง</th>
                    <th style="width: 120px;">เลขที่อ้างอิง</th>
                    <th style="width: 120px;">รหัสสินค้า</th>
                    <th>ชื่อสินค้า</th>
                    <th style="width: 120px;">คลัง</th>
                    <th style="width: 100px;">Location</th>
                    <th style="width: 100px;">จำนวน</th>
                    <th style="width: 100px;">LOT</th>
                    <th style="width: 120px;">ผู้บันทึก</th>
                    <th style="width: 80px;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
    `;

    data.forEach((row, index) => {
        // Movement Type Badge
        let movementBadge = '';
        if (row.movement_type === 'IN') {
            movementBadge = '<span class="badge badge-in">รับเข้า</span>';
        } else if (row.movement_type === 'OUT') {
            movementBadge = '<span class="badge badge-out">จ่ายออก</span>';
        } else if (row.movement_type === 'TRANSFER') {
            movementBadge = '<span class="badge badge-transfer">โอนย้าย</span>';
        } else {
            movementBadge = '<span class="badge badge-adjust">ปรับยอด</span>';
        }

        // Reference Type Badge
        let refBadge = '';
        switch(row.reference_type) {
            case 'GOODS_RECEIPT':
                refBadge = '<span class="badge badge-receipt">รับจาก PO</span>';
                break;
            case 'DIRECT_RECEIPT':
                refBadge = '<span class="badge badge-direct">รับโดยตรง</span>';
                break;
            case 'DISPATCH':
                refBadge = '<span class="badge badge-dispatch">จ่ายออก</span>';
                break;
            case 'TRANSFER':
                refBadge = '<span class="badge badge-transfer">โอนย้าย</span>';
                break;
            default:
                refBadge = '<span class="badge badge-adjust">' + (row.reference_type || '-') + '</span>';
        }

        html += `
            <tr>
                <td style="text-align: center;">${index + 1}</td>
                <td>${formatDateTime(row.movement_date)}</td>
                <td style="text-align: center;">${movementBadge}</td>
                <td style="text-align: center;">${refBadge}</td>
                <td>${row.reference_number || '-'}</td>
                <td><strong>${row.product_code || '-'}</strong></td>
                <td><small>${row.product_name || '-'}</small></td>
                <td>${row.warehouse_name || '-'}</td>
                <td>${row.location_code || '-'}</td>
                <td style="text-align: right;"><strong>${formatNumber(row.quantity)}</strong> ${row.unit_symbol || ''}</td>
                <td><small>${row.batch_lot || '-'}</small></td>
                <td>${row.created_by_name || '-'}</td>
                <td style="text-align: center;">
                    <button class="btn-action" onclick="viewDetail(${row.movement_id})">ดู</button>
                </td>
            </tr>
        `;
    });

    html += `
            </tbody>
        </table>
    `;

    tableContainer.innerHTML = html;
}

async function viewDetail(movementId) {
    const modalBody = document.getElementById('modalBody');
    const modal = document.getElementById('viewModal');
    
    if (modalBody) {
        modalBody.innerHTML = '<div class="loading">กำลังโหลด...</div>';
    }
    if (modal) {
        modal.classList.add('show');
    }

    try {
        const res = await fetch(`?ajax=get_movement_detail&movement_id=${movementId}`);
        const result = await res.json();

        if (result.success && result.data) {
            const data = result.data;
            
            let html = `
                <div class="detail-grid">
                    <div class="detail-item">
                        <label>Movement ID</label>
                        <div class="value">${data.movement_id || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <label>วันที่</label>
                        <div class="value">${formatDateTime(data.movement_date)}</div>
                    </div>
                    <div class="detail-item">
                        <label>ประเภทการเคลื่อนไหว</label>
                        <div class="value">${data.movement_type || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <label>อ้างอิง</label>
                        <div class="value">${data.reference_type || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <label>เลขที่อ้างอิง</label>
                        <div class="value">${data.reference_number || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <label>Reference ID</label>
                        <div class="value">${data.reference_id || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <label>รหัสสินค้า</label>
                        <div class="value">${data.SSP_Code || '-'}</div>
                    </div>
                    <div class="detail-item" style="grid-column: 1 / -1;">
                        <label>ชื่อสินค้า</label>
                        <div class="value">${data.product_name || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <label>คลังสินค้า</label>
                        <div class="value">${data.warehouse_name || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <label>Location</label>
                        <div class="value">${data.location_code || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <label>จำนวน</label>
                        <div class="value">${formatNumber(data.quantity)} ${data.unit_symbol || ''}</div>
                    </div>
                    <div class="detail-item">
                        <label>Pallet/Sheets</label>
                        <div class="value">${data.quantity_pallet || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <label>น้ำหนัก (KG)</label>
                        <div class="value">${data.quantity_kg ? formatNumber(data.quantity_kg) : '-'}</div>
                    </div>
                    <div class="detail-item">
                        <label>LOT/Batch</label>
                        <div class="value">${data.batch_lot || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <label>ผู้บันทึก</label>
                        <div class="value">${data.created_by_name || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <label>บันทึกเมื่อ</label>
                        <div class="value">${formatDateTime(data.movement_date)}</div>
                    </div>
                    ${data.notes ? `
                    <div class="detail-item" style="grid-column: 1 / -1;">
                        <label>หมายเหตุ</label>
                        <div class="value">${data.notes}</div>
                    </div>
                    ` : ''}
                </div>
            `;

            if (modalBody) {
                modalBody.innerHTML = html;
            }
        } else {
            if (modalBody) {
                modalBody.innerHTML = '<div class="no-data">ไม่พบข้อมูล</div>';
            }
        }
    } catch (err) {
        console.error('View error:', err);
        if (modalBody) {
            modalBody.innerHTML = '<div class="no-data">⚠️ เกิดข้อผิดพลาด</div>';
        }
    }
}

function closeModal() {
    const modal = document.getElementById('viewModal');
    if (modal) {
        modal.classList.remove('show');
    }
}

function clearFilter() {
    const dateFromEl = document.getElementById('dateFrom');
    const dateToEl = document.getElementById('dateTo');
    const warehouseFilterEl = document.getElementById('warehouseFilter');
    const productCodeFilterEl = document.getElementById('productCodeFilter');
    const movementTypeFilterEl = document.getElementById('movementTypeFilter');
    const referenceTypeFilterEl = document.getElementById('referenceTypeFilter');

    if (dateFromEl) dateFromEl.value = '<?php echo date('Y-m-01'); ?>';
    if (dateToEl) dateToEl.value = '<?php echo date('Y-m-d'); ?>';
    if (warehouseFilterEl) warehouseFilterEl.value = '';
    if (productCodeFilterEl) productCodeFilterEl.value = '';
    if (movementTypeFilterEl) movementTypeFilterEl.value = '';
    if (referenceTypeFilterEl) referenceTypeFilterEl.value = '';
    
    loadData();
}
function formatNumber(num) {
    const number = Math.round(parseFloat(num || 0));
    return number.toLocaleString('en-US');
}
// ใหม่ - แสดงรูปแบบ dd/MM/yyyy (ไม่มีเวลา)
function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    try {
        const date = new Date(dateStr);
        if (isNaN(date.getTime())) return '-';
        
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        
        return `${day}/${month}/${year}`;
    } catch (e) {
        return dateStr;
    }
}

window.onclick = function(event) {
    const modal = document.getElementById('viewModal');
    if (event.target == modal) {
        closeModal();
    }
}
</script>
</body>
</html>