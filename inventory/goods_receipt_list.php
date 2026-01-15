<?php
// goods_receipt_list.php - รายการรับเข้าสินค้า (Brown Theme)
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
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_GET['ajax'];
    
    try {
        switch ($action) {
            case 'get_receipts':
                $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
                $dateTo = $_GET['date_to'] ?? date('Y-m-d');
                $warehouseId = !empty($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
                $productCode = $_GET['product_code'] ?? '';
                $supplier = $_GET['supplier'] ?? '';
                
                $sql = "
                    SELECT 
                        gr.gr_id,
                        gr.gr_number,
                        gr.receipt_date,
                        gr.po_id,
                        gr.invoice_number,
                        gr.notes,
                        gr.created_date,
                        w.warehouse_name,
                        po.po_number,
                        COALESCE(
    s.supplier_name,                    -- จาก PO (ถ้ามี)
    product_supplier.product_supplier_name  -- จาก Product (ถ้าไม่มี PO)
) as supplier_name,
                        usr.username as received_by,
                        STUFF((SELECT DISTINCT ', ' + mp.SSP_Code
                               FROM Goods_Receipt_Items gri2 
                               JOIN Master_Products_ID mp ON gri2.product_id = mp.id 
                               WHERE gri2.gr_id = gr.gr_id
                               FOR XML PATH('')), 1, 2, '') as product_codes,
                        STUFF((SELECT DISTINCT ', ' + mp.Name
                               FROM Goods_Receipt_Items gri2 
                               JOIN Master_Products_ID mp ON gri2.product_id = mp.id 
                               WHERE gri2.gr_id = gr.gr_id
                               FOR XML PATH('')), 1, 2, '') as product_names,
                        STUFF((SELECT DISTINCT ', ' + gri2.batch_lot
                               FROM Goods_Receipt_Items gri2 
                               WHERE gri2.gr_id = gr.gr_id 
                               AND gri2.batch_lot IS NOT NULL 
                               AND gri2.batch_lot != ''
                               FOR XML PATH('')), 1, 2, '') as lot_numbers,
                        STUFF((SELECT DISTINCT ', ' + wl.location_code
                               FROM Goods_Receipt_Items gri2
                               JOIN Warehouse_Locations wl ON gri2.location_id = wl.location_id
                               WHERE gri2.gr_id = gr.gr_id
                               FOR XML PATH('')), 1, 2, '') as locations,
-- น้ำหนักรวม
(SELECT SUM(gri2.quantity_received) 
 FROM Goods_Receipt_Items gri2 
 WHERE gri2.gr_id = gr.gr_id) as total_weight,  -- เปลี่ยนชื่อ

-- จำนวนกระป๋องรวม
(SELECT SUM(ISNULL(sm2.received_package_qty, 0)) 
 FROM Goods_Receipt_Items gri2
 LEFT JOIN Stock_Movements sm2 ON sm2.reference_type = 'GR' 
     AND sm2.reference_id = gri2.gr_item_id
     AND sm2.movement_type = 'IN'
 WHERE gri2.gr_id = gr.gr_id) as total_packages,  -- เพิ่มใหม่

-- เช็คว่ามีกระป๋องหรือไม่
(SELECT CASE 
    WHEN COUNT(CASE WHEN sm2.received_package_qty IS NOT NULL 
                    AND sm2.received_package_qty > 0 
                    THEN 1 END) > 0 
    THEN 1 ELSE 0 END
 FROM Goods_Receipt_Items gri2
 LEFT JOIN Stock_Movements sm2 ON sm2.reference_type = 'GR' 
     AND sm2.reference_id = gri2.gr_item_id
     AND sm2.movement_type = 'IN'
 WHERE gri2.gr_id = gr.gr_id) as has_packages,

-- ✅ ใช้ stock_unit_id
(SELECT TOP 1 ISNULL(u.unit_name_th, u.unit_name)
 FROM Goods_Receipt_Items gri2
 LEFT JOIN Units u ON gri2.stock_unit_id = u.unit_id
 WHERE gri2.gr_id = gr.gr_id) as unit_symbol

                    FROM Goods_Receipt gr
                    JOIN Warehouses w ON gr.warehouse_id = w.warehouse_id
                    LEFT JOIN PO_Header po ON gr.po_id = po.po_id
                    LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
                    -- ดึง Supplier จาก Product (เหมือน inventory_view.php)
                    LEFT JOIN (
                        SELECT DISTINCT
                            gri_sub.gr_id,
                            sup_sub.supplier_name as product_supplier_name
                        FROM Goods_Receipt_Items gri_sub
                        JOIN Master_Products_ID mp_sub ON gri_sub.product_id = mp_sub.id
                        LEFT JOIN Suppliers sup_sub ON mp_sub.supplier_id = sup_sub.supplier_id
                        WHERE gri_sub.gr_item_id = (
                            SELECT MIN(gr_item_id) 
                            FROM Goods_Receipt_Items 
                            WHERE gr_id = gri_sub.gr_id
                        )
                    ) product_supplier ON gr.gr_id = product_supplier.gr_id
                    LEFT JOIN dbo.Users usr ON gr.received_by = usr.user_id
                    WHERE CAST(gr.receipt_date AS DATE) BETWEEN ? AND ?
                ";
                $params = [$dateFrom, $dateTo];
                
                if ($warehouseId) {
                    $sql .= " AND gr.warehouse_id = ?";
                    $params[] = $warehouseId;
                }
                
                if (!empty($productCode)) {
                    $sql .= " AND EXISTS (
                        SELECT 1 FROM Goods_Receipt_Items gri
                        JOIN Master_Products_ID mp ON gri.product_id = mp.id
                        WHERE gri.gr_id = gr.gr_id AND mp.SSP_Code LIKE ?
                    )";
                    $params[] = "%{$productCode}%";
                }
                
                if (!empty($supplier)) {
                    $sql .= " AND s.supplier_name LIKE ?";
                    $params[] = "%{$supplier}%";
                }
                
                $sql .= " ORDER BY gr.receipt_date DESC, gr.gr_number DESC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $receipts = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'data' => $receipts]);
                break;
                
            case 'get_receipt_detail':
                $receiptId = (int)$_GET['receipt_id'];
                
                // Get header
                $stmt = $pdo->prepare("
                    SELECT 
                        gr.gr_id,
                        gr.gr_number,
                        gr.receipt_date,
                        gr.invoice_number,
                        gr.notes,
                        gr.created_date,
                        w.warehouse_name,
                        po.po_number,
                        s.supplier_name,
                        usr.username as received_by
                    FROM Goods_Receipt gr
                    JOIN Warehouses w ON gr.warehouse_id = w.warehouse_id
                    LEFT JOIN PO_Header po ON gr.po_id = po.po_id
                    LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
                    LEFT JOIN dbo.Users usr ON gr.received_by = usr.user_id
                    WHERE gr.gr_id = ?
                ");
                $stmt->execute([$receiptId]);
                $header = $stmt->fetch();
                
                // Get items
                $stmt = $pdo->prepare("
    SELECT 
        gri.gr_item_id,
        gri.quantity_received,
        ISNULL(gri.quantity_pallet, 0) as quantity_pallet,
        gri.batch_lot,
        mp.SSP_Code,
        mp.Name as product_name,
        ISNULL(u.unit_name_th, u.unit_name) as unit_symbol,
        wl.location_code,
        sm.received_package_qty,
        sm.package_weight_per_unit
    FROM Goods_Receipt_Items gri
    JOIN Master_Products_ID mp ON gri.product_id = mp.id
    LEFT JOIN Units u ON gri.stock_unit_id = u.unit_id
    LEFT JOIN Warehouse_Locations wl ON gri.location_id = wl.location_id
    LEFT JOIN Stock_Movements sm ON sm.reference_type = 'GR' 
        AND sm.reference_id = gri.gr_item_id
        AND sm.movement_type = 'IN'
    WHERE gri.gr_id = ?
");
                $stmt->execute([$receiptId]);
                $items = $stmt->fetchAll();
                
                echo json_encode([
                    'success' => true, 
                    'header' => $header, 
                    'items' => $items
                ]);
                break;
                
            default:
                echo json_encode(['error' => 'Unknown action']);
        }
        
    } catch (Exception $e) {
        error_log("AJAX Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit;
}

// Load warehouses for filter
$stmt = $pdo->query("
    SELECT warehouse_id, warehouse_name
    FROM Warehouses
    WHERE is_active = 1 OR is_active IS NULL
    ORDER BY warehouse_name
");
$warehouses = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายการรับเข้าสินค้า</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg, #F5DEB3 0%, #DEB887 50%, #D2B48C 100%);
    min-height: 100vh;
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
    color: white;
    font-size: 1.3rem;
    font-weight: 600;
    margin: 0;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
}

.header-section small {
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.85rem;
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


.filter-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 4px 12px rgba(139, 69, 19, 0.15);
    margin-bottom: 20px;
    border: 2px solid rgba(139, 69, 19, 0.2);
    width: 100%;
}

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
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
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-group input,
    .form-group select {
        padding: 12px;
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
        box-shadow: 0 0 0 3px rgba(139, 69, 19, 0.1);
    }

    .btn-group {
        display: flex;
        gap: 10px;
    }

    .btn-search,
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

    .btn-search {
        background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
        color: white;
    }

    .btn-search:hover {
        background: linear-gradient(135deg, #A0522D 0%, #8B4513 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(139, 69, 19, 0.4);
    }

    .btn-clear {
        background: linear-gradient(135deg, #FF8C00 0%, #FFA500 100%);
        color: white;
    }

    .btn-clear:hover {
        background: linear-gradient(135deg, #FFA500 0%, #FF8C00 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(255, 140, 0, 0.4);
    }

.table-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 4px 12px rgba(139, 69, 19, 0.15);
    overflow-x: auto;
    border: 2px solid rgba(139, 69, 19, 0.2);
    width: 100%;
}

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1200px;
    }

    th {
        background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
        color: white;
        padding: 14px 12px;
        text-align: left;
        font-weight: 600;
        font-size: 13px;
        white-space: nowrap;
        text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
        border: 1px solid #6d4c41;
    }

    th:first-child {
        border-radius: 8px 0 0 0;
    }

    th:last-child {
        border-radius: 0 8px 0 0;
    }

    td {
        padding: 12px;
        border-bottom: 1px solid rgba(139, 69, 19, 0.1);
        font-size: 13px;
        background: white;
        color: #3e2723;
    }

    tr:hover td {
        background: rgba(255, 140, 0, 0.1);
    }

    tr:nth-child(even) td {
        background: rgba(245, 222, 179, 0.3);
    }

    tr:nth-child(even):hover td {
        background: rgba(255, 140, 0, 0.15);
    }

    tr:last-child td {
        border-bottom: none;
    }

    tr:last-child td:first-child {
        border-radius: 0 0 0 8px;
    }

    tr:last-child td:last-child {
        border-radius: 0 0 8px 0;
    }

    .btn-action {
        padding: 8px 16px;
        min-width: 60px;
        display: inline-block;
        text-align: center;
        white-space: nowrap;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
        margin-right: 5px;
        transition: all 0.3s;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    .btn-view {
        background: linear-gradient(135deg, #FF8C00 0%, #FFA500 100%);
        color: white;
    }

    .btn-view:hover {
        background: linear-gradient(135deg, #FFA500 0%, #FF8C00 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(255, 140, 0, 0.4);
    }

    .btn-edit {
        background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
        color: white;
    }

    .btn-edit:hover {
        background: linear-gradient(135deg, #A0522D 0%, #8B4513 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(139, 69, 19, 0.4);
    }

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
        display: flex;
        align-items: center;
        gap: 10px;
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

    .detail-section {
        margin-bottom: 25px;
    }

    .detail-section h3 {
        color: #8B4513;
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid rgba(139, 69, 19, 0.2);
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }

    .detail-item {
        display: flex;
        gap: 10px;
    }

    .detail-label {
        color: #A0522D;
        font-weight: 600;
        min-width: 130px;
    }

    .detail-value {
        color: #3e2723;
        font-weight: 600;
    }

    .items-table-container {
        overflow-x: auto;
        max-height: 400px;
        overflow-y: auto;
        border: 2px solid rgba(139, 69, 19, 0.2);
        border-radius: 12px;
        margin-top: 15px;
    }

    .items-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 800px;
    }

    .items-table thead {
        position: sticky;
        top: 0;
        z-index: 5;
    }

    .items-table th {
        background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
        color: white;
        padding: 12px;
        font-size: 13px;
        font-weight: 600;
        text-align: left;
        border: 1px solid #6d4c41;
        white-space: nowrap;
        text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
    }

    .items-table td {
        padding: 12px;
        font-size: 13px;
        border-bottom: 1px solid rgba(139, 69, 19, 0.1);
        background: white;
        color: #3e2723;
    }

    .items-table tbody tr:hover td {
        background: rgba(255, 140, 0, 0.1);
    }

    .items-table tr:last-child td {
        border-bottom: none;
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
    }

    @media (max-width: 768px) {
        .filter-grid,
        .detail-grid {
            grid-template-columns: 1fr;
        }
        
        .header {
            flex-direction: column;
            gap: 15px;
        }
        
        .header h1 {
            font-size: 20px;
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
    <i class="fas fa-clipboard-list me-2"></i>รายการรับเข้าสินค้า (Goods Receipt List)
</h5>
                    <small class="text-light">ระบบจัดการรายการรับเข้าสินค้าทั้งหมด</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="../pages/PO/receiving_direct.php" class="btn-header">+ รับเข้าใหม่</a>
                <a href="../pages/PO/receiving_po.php" class="btn-header">+ รับเข้าใหม่ PO</a>
                <span class="text-white">
                    <i class="fas fa-user-circle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'System Administrator'); ?>
                </span>
            </div>
        </div>
        </div>
    </div>

    <div class="container-fluid">
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
                    <label>ชื่อผู้ขาย</label>
                    <input type="text" id="supplierFilter" placeholder="ค้นหาผู้ขาย...">
                </div>
            </div>
            <div class="btn-group">
                <button class="btn-search" onclick="loadData()">🔍 ค้นหา</button>
                <button class="btn-clear" onclick="clearFilter()">🔄 ล้างค่า</button>
            </div>
        </div>

        <!-- Table Section -->
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th style="width: 140px;">เลขที่รับเข้า</th>
                        <th style="width: 100px;">วันที่รับ</th>
                        <th style="width: 120px;">รหัสสินค้า</th>
                        <th style="width: 200px;">ชื่อสินค้า</th>
                        <th style="width: 120px;">ผู้ขาย</th>
                        <th style="width: 120px;">คลังสินค้า</th>
                        <th style="width: 100px;">Location</th>
                        <th style="width: 100px;">จำนวน</th>
                        <th style="width: 200px;">LOT</th>
                        <th style="width: 100px;">ผู้รับ</th>
                        <th style="width: 150px;">จัดการ</th>
                    </tr>
                </thead>
                <tbody id="dataTable">
                    <tr>
                        <td colspan="12" class="loading">กำลังโหลดข้อมูล...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
    <!-- View Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📋 รายละเอียดการรับเข้าสินค้า</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="loading">กำลังโหลด...</div>
            </div>
        </div>
    </div>

    <script>
    // Load data on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadData();
    });

    async function loadData() {
        const dateFrom = document.getElementById('dateFrom').value;
        const dateTo = document.getElementById('dateTo').value;
        const warehouseId = document.getElementById('warehouseFilter').value;
        const productCode = document.getElementById('productCodeFilter').value;
        const supplier = document.getElementById('supplierFilter').value;

        try {
            const params = new URLSearchParams({
                date_from: dateFrom,
                date_to: dateTo,
                warehouse_id: warehouseId,
                product_code: productCode,
                supplier: supplier
            });

            const res = await fetch(`?ajax=get_receipts&${params}`);
            const result = await res.json();

            if (result.success) {
                displayData(result.data);
            } else {
                alert('เกิดข้อผิดพลาด: ' + (result.error || 'Unknown error'));
            }
        } catch (err) {
            console.error('Load error:', err);
            document.getElementById('dataTable').innerHTML = 
                '<tr><td colspan="12" class="no-data">⚠️ เกิดข้อผิดพลาดในการโหลดข้อมูล</td></tr>';
        }
    }

    function displayData(data) {
        const tbody = document.getElementById('dataTable');
        
        if (!data || data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="12" class="no-data">ไม่พบข้อมูล</td></tr>';
            return;
        }

        let html = '';
        data.forEach((item, index) => {
            html += `
                <tr>
                    <td>${index + 1}</td>
                    <td><strong>${item.gr_number || '-'}</strong></td>
                    <td>${formatDate(item.receipt_date)}</td>
                    <td><small>${truncate(item.product_codes, 15)}</small></td>
                    <td><small>${truncate(item.product_names, 30)}</small></td>
                    <td>${item.supplier_name || '-'}</td>
                    <td>${item.warehouse_name}</td>
                    <td>${item.locations || '-'}</td>
                    <td>${
    item.has_packages && item.total_packages > 0
        ? `<strong>${parseFloat(item.total_packages).toLocaleString()}</strong> 
           <small class="text-muted">กระป๋อง</small><br>
           <small style="color: #666;">(${parseFloat(item.total_weight || 0)
              .toLocaleString()} kg รวม)</small>`
        : `<strong>${parseFloat(item.total_weight || 0).toLocaleString()}</strong> 
           <small class="text-muted">${item.unit_symbol || 'kg'}</small>`
}</td>
                    <td><small>${item.lot_numbers || '-'}</small></td>
                    <td>${item.received_by || '-'}</td>
                    <td>
                        <button class="btn-action btn-view" onclick="viewReceipt(${item.gr_id})">ดู</button>
                        <button class="btn-action btn-edit" onclick="editReceipt(${item.gr_id})">แก้ไข</button>
                    </td>
                </tr>
            `;
        });
        
        tbody.innerHTML = html;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleDateString('th-TH', { 
            day: '2-digit', 
            month: '2-digit',
            year: 'numeric'
        });
    }

    function truncate(str, maxLength) {
        if (!str) return '-';
        return str.length > maxLength ? str.substring(0, maxLength) + '...' : str;
    }

    async function viewReceipt(receiptId) {
        document.getElementById('modalBody').innerHTML = '<div class="loading">กำลังโหลด...</div>';
        document.getElementById('viewModal').classList.add('show');

        try {
            const res = await fetch(`?ajax=get_receipt_detail&receipt_id=${receiptId}`);
            const result = await res.json();

            if (result.success) {
                const h = result.header;
                const items = result.items;

                let itemsHtml = '';
                items.forEach(item => {
    // เตรียมข้อมูลแสดงจำนวนกระป๋อง
    const packageQty = item.received_package_qty 
        ? parseFloat(item.received_package_qty).toLocaleString() 
        : '-';
    
    const weightPerPackage = item.package_weight_per_unit 
        ? parseFloat(item.package_weight_per_unit).toFixed(2) 
        : '-';
    
    // ถ้ามีกระป๋อง แสดงจำนวนกระป๋อง, ไม่มีแสดงน้ำหนัก
    const quantityDisplay = item.received_package_qty 
        ? `<strong>${packageQty}</strong> <small class="text-muted">กระป๋อง</small><br>
           <small style="color: #666;">(${parseFloat(item.quantity_received).toLocaleString()} kg รวม)</small>`
        : `${parseFloat(item.quantity_received).toLocaleString()} ${item.unit_symbol || ''}`;
    
    itemsHtml += `
        <tr>
            <td><strong>${item.SSP_Code}</strong></td>
            <td>${item.product_name}</td>
            <td>${quantityDisplay}</td>
            <td style="text-align: center; font-weight: 600;">${packageQty}</td>
            <td style="text-align: center; font-weight: 600;">${weightPerPackage}</td>
            <td>${parseFloat(item.quantity_pallet || 0).toLocaleString()}</td>
            <td>${item.batch_lot || '-'}</td>
            <td>${item.location_code || '-'}</td>
        </tr>
    `;
});

                const html = `
                    <div class="detail-section">
                        <h3>ข้อมูลทั่วไป</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">เลขที่รับเข้า:</span>
                                <span class="detail-value">${h.gr_number}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">วันที่รับ:</span>
                                <span class="detail-value">${formatDate(h.receipt_date)}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">PO Number:</span>
                                <span class="detail-value">${h.po_number || '-'}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Invoice Number:</span>
                                <span class="detail-value">${h.invoice_number || '-'}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">คลังสินค้า:</span>
                                <span class="detail-value">${h.warehouse_name}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">ผู้ขาย:</span>
                                <span class="detail-value">${h.supplier_name || '-'}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">ผู้รับเข้า:</span>
                                <span class="detail-value">${h.received_by || '-'}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">วันที่สร้าง:</span>
                                <span class="detail-value">${formatDateTime(h.created_date)}</span>
                            </div>
                        </div>
                        ${h.notes ? `
                        <div style="margin-top: 15px;">
                            <span class="detail-label">หมายเหตุ:</span>
                            <div style="margin-top: 5px; padding: 10px; background: #FFF8F0; border-radius: 6px; border-left: 4px solid #D2691E;">
                                ${h.notes}
                            </div>
                        </div>
                        ` : ''}
                    </div>

                    <div class="detail-section">
                        <h3>รายการสินค้า</h3>
                        <div class="items-table-container">
                            <table class="items-table">
                                <thead>
                                    <tr>
                                        <th>รหัสสินค้า</th>
                                        <th>ชื่อสินค้า</th>
                                        <th>จำนวน</th>
                                        <th style="text-align: center;">กระป๋อง</th>
                                        <th style="text-align: center;">kg/กระป๋อง</th>
                                        <th>Pallet</th>
                                        <th>LOT</th>
                                        <th>Location</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsHtml}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;

                document.getElementById('modalBody').innerHTML = html;
                document.getElementById('viewModal').classList.add('show');
            } else {
                alert('ไม่พบข้อมูล');
            }
        } catch (err) {
            console.error('View error:', err);
            alert('เกิดข้อผิดพลาด');
        }
    }

    function editReceipt(receiptId) {
        window.location.href = 'goods_receipt_edit.php?id=' + receiptId;
    }

    function closeModal() {
        document.getElementById('viewModal').classList.remove('show');
    }

    function clearFilter() {
        document.getElementById('dateFrom').value = '<?php echo date('Y-m-01'); ?>';
        document.getElementById('dateTo').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('warehouseFilter').value = '';
        document.getElementById('productCodeFilter').value = '';
        document.getElementById('supplierFilter').value = '';
        loadData();
    }

    function formatDateTime(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleString('th-TH', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
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