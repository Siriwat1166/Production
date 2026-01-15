<?php
// inventory_view.php - แสดงข้อมูลสต็อกแบบตาราง
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';

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
    error_log("Database Connection Error: " . $e->getMessage());
    die("Database connection failed.");
}

// Handle AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_inventory') {
    header('Content-Type: application/json');
    
    try {
        $filters = [
            'search' => $_POST['search'] ?? '',
            'stock_filter' => $_POST['stock_filter'] ?? 'all',
            'warehouse' => $_POST['warehouse'] ?? '',
            'material_type' => $_POST['material_type'] ?? ''
        ];
        
        error_log("Inventory View - Filters: " . print_r($filters, true));
        
        $result = getInventoryTableData($filters);
        
        error_log("Inventory View - Result: " . ($result['success'] ? 'Success' : 'Failed'));
        
        echo json_encode($result);
    } catch (Exception $e) {
        error_log("AJAX Error in inventory_view: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }
    exit;
}

function getInventoryTableData($filters) {
    global $pdo;
    
    try {
        // Query จาก Inventory_Stock - ดึง Brand จาก Specific_Paperboard และ Suppliers
$query = "
SELECT 
    ISNULL(
        CONVERT(VARCHAR, 
            (SELECT TOP 1 gr.receipt_date 
             FROM Goods_Receipt_Items gri
             JOIN Goods_Receipt gr ON gri.gr_id = gr.gr_id
             WHERE gri.product_id = ist.product_id
               AND gr.warehouse_id = ist.warehouse_id
             ORDER BY gr.receipt_date ASC), 
        23), 
    '-') as วันที่รับเข้า,
    ISNULL(wl.aisle, '-') as พื้นที่,
    -- 🔥 แก้ไข: ดึง Brand จาก Specific_Paperboard ก่อน ถ้าไม่มีให้ใช้ Supplier
    ISNULL(
        COALESCE(
            sp.brand,                    -- กระดาษใช้ brand จาก Specific_Paperboard
            sup.supplier_name            -- หมึก/อื่นๆ ใช้ชื่อ Supplier
        ),
    '-') as Brand,
    ISNULL(mp.SSP_Code, '-') as [SSP Code],
    ISNULL(mp.Name, '-') as [Paperboard Name],
    ISNULL((SELECT TOP 1 batch_lot FROM Stock_Movements sm 
            WHERE sm.product_id = ist.product_id 
            AND sm.warehouse_id = ist.warehouse_id 
            AND sm.location_id = ist.location_id
            AND sm.batch_lot IS NOT NULL
            ORDER BY sm.movement_date DESC), '-') as [Lot From Supplier],
    ISNULL(ist.current_pallet, 0) as [คงเหลือพาเลท],
    -- ✅ แก้ไข: ถ้ามี received_package_qty (กระป๋อง) ให้แสดงจำนวนกระป๋อง ถ้าไม่มีให้แสดง current_stock (กิโล)
    ISNULL(
        COALESCE(
            (SELECT SUM(CASE 
                WHEN sm.movement_type = 'IN' THEN ISNULL(sm.received_package_qty, 0)
                WHEN sm.movement_type = 'OUT' THEN -ISNULL(sm.received_package_qty, 0)
                ELSE 0 
            END)
            FROM Stock_Movements sm
            WHERE sm.product_id = ist.product_id
              AND sm.warehouse_id = ist.warehouse_id
              AND sm.location_id = ist.location_id
              AND sm.received_package_qty IS NOT NULL
              AND sm.received_package_qty != 0
            ),
            ist.current_stock  -- ถ้าไม่มี received_package_qty ให้ใช้ current_stock
        ), 
    0) as [ควบคุมอัตราสต็อก],
    -- ✅ แก้ไข: แสดงหน่วยที่ถูกต้อง (กระป๋อง หรือ กิโล)
    ISNULL(
        CASE 
            WHEN (SELECT COUNT(*) FROM Stock_Movements sm 
                  WHERE sm.product_id = ist.product_id 
                    AND sm.warehouse_id = ist.warehouse_id 
                    AND sm.location_id = ist.location_id
                    AND sm.received_package_qty IS NOT NULL 
                    AND sm.received_package_qty != 0) > 0 
            THEN 'กระป๋อง'
            ELSE (SELECT TOP 1 ISNULL(u2.unit_name_th, u2.unit_name)
                  FROM Goods_Receipt_Items gri2
                  LEFT JOIN Units u2 ON gri2.stock_unit_id = u2.unit_id
                  WHERE gri2.product_id = ist.product_id
                    AND gri2.warehouse_id = ist.warehouse_id
                    AND gri2.location_id = ist.location_id
                  ORDER BY gri2.gr_item_id DESC)
        END,
    '-') as Unit,
    ISNULL(w.warehouse_name_th, ISNULL(w.warehouse_name, '-')) as Warehouse,
    mp.id as product_id,
    ist.warehouse_id,
    ist.location_id
FROM Inventory_Stock ist
INNER JOIN Warehouses w ON ist.warehouse_id = w.warehouse_id
LEFT JOIN Warehouse_Locations wl ON ist.location_id = wl.location_id
LEFT JOIN Master_Products_ID mp ON ist.product_id = mp.id
LEFT JOIN Groups g ON mp.group_id = g.id
LEFT JOIN Specific_Paperboard sp ON mp.id = sp.product_id
LEFT JOIN Suppliers sup ON mp.supplier_id = sup.supplier_id
WHERE ist.current_stock > 0
";
        
        $params = [];
        
        // Search filter
        if (!empty($filters['search'])) {
            $query .= " AND (
                mp.SSP_Code LIKE ? OR
                mp.Name LIKE ? OR
                sp.brand LIKE ?
            )";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Stock level filter
        if (!empty($filters['stock_filter']) && $filters['stock_filter'] !== 'all') {
            switch ($filters['stock_filter']) {
                case 'high':
                    $query .= " AND ist.current_stock >= 100000";
                    break;
                case 'medium':
                    $query .= " AND ist.current_stock >= 50000 AND ist.current_stock < 100000";
                    break;
                case 'low':
                    $query .= " AND ist.current_stock < 50000";
                    break;
            }
        }
        
        // Material Type filter
        if (!empty($filters['material_type'])) {
            $query .= " AND mp.group_id = ?";
            $params[] = $filters['material_type'];
        }
        
        // Warehouse filter
        if (!empty($filters['warehouse'])) {
            $query .= " AND ist.warehouse_id = ?";
            $params[] = $filters['warehouse'];
        }
        
        $query .= " ORDER BY ist.last_movement_date DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        return [
            'success' => true,
            'data' => $data,
            'total' => count($data)
        ];
        
    } catch (PDOException $e) {
        error_log("Error getting inventory data: " . $e->getMessage());
        error_log("SQL Query: " . $query);
        error_log("Parameters: " . print_r($params, true));
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// Get warehouses for filter
function getWarehouses() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT warehouse_id, warehouse_name_th, warehouse_name FROM Warehouses WHERE is_active = 1 ORDER BY warehouse_name");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// Get material types for filter
function getMaterialTypes() {
    global $pdo;
    try {
        // ดึงจากตาราง Groups ด้วย group_id
        $stmt = $pdo->query("
            SELECT DISTINCT 
                g.id as material_type_id, 
                g.name as type_name
            FROM Groups g
            INNER JOIN Master_Products_ID mp ON g.id = mp.group_id
            WHERE g.id IS NOT NULL
            ORDER BY g.name
        ");
        $result = $stmt->fetchAll();
        error_log("Material Types (Groups via group_id) loaded: " . count($result));
        if (!empty($result)) {
            error_log("Sample: " . print_r($result[0], true));
        }
        return $result;
    } catch (PDOException $e) {
        error_log("Error loading Material Types from Groups: " . $e->getMessage());
        return [];
    }
}

$warehouses = getWarehouses();
$material_types = getMaterialTypes();

// Debug - แสดงใน HTML comment
echo "<!-- Material Types Count: " . count($material_types) . " -->\n";
if (!empty($material_types)) {
    echo "<!-- First Material Type: " . htmlspecialchars(json_encode($material_types[0])) . " -->\n";
}


// Debug
error_log("Warehouses count: " . count($warehouses));
error_log("Material Types count: " . count($material_types));
if (!empty($material_types)) {
    error_log("First Material Type: " . print_r($material_types[0], true));
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สินค้าคงคลัง - มุมมองตาราง</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
    :root {
        --primary-color: #8B4513;
        --secondary-color: #FF8C00;
        --accent-color: #A0522D;
        --success-color: #059669;
        --primary-gradient: linear-gradient(135deg, #8B4513, #A0522D);
    }
    
    body {
        background: linear-gradient(135deg, #F5DEB3 0%, #DEB887 50%, #D2B48C 100%);
        min-height: 100vh;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        color: var(--primary-color);
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

.header-section .btn-link {
    color: white;
    text-decoration: none;
    font-size: 1.2rem;
    padding: 0.5rem;
    transition: all 0.3s ease;
}

.header-section .btn-link:hover {
    transform: translateX(-3px);
    opacity: 0.8;
}

.text-light {
    color: rgba(255, 255, 255, 0.9) !important;
} 
    .filter-card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 10px rgba(139, 69, 19, 0.15);
        margin-bottom: 1.5rem;
        border: 2px solid rgba(139, 69, 19, 0.2);
    }
    
    .filter-title {
        font-weight: 600;
        color: #8B4513;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        text-transform: uppercase;
        font-size: 0.95rem;
        letter-spacing: 0.5px;
    }
    
    .table-container {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 10px rgba(139, 69, 19, 0.15);
        border: 2px solid rgba(139, 69, 19, 0.2);
        width: 100%;
    }
    
    .dataTables_wrapper {
        font-size: 14px;
        color: #3e2723;
        width: 100%;
    }
    
    table.dataTable {
        background: white;
        color: #3e2723;
        width: 100% !important;
    }
    
/* ใหม่ - ให้คอลัมน์พื้นที่ขึ้นบรรทัดใหม่ได้ */
table.dataTable thead th {
    background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
    color: white;
    font-weight: 600;
    border: 1px solid #6d4c41;
    padding: 12px 8px;
    font-size: 13px;
    text-align: center;
    white-space: normal; /* เปลี่ยนจาก nowrap */
    line-height: 1.3;
}
/* เพิ่ม CSS นี้ - ขยายความกว้างคอลัมน์พื้นที่ */
table.dataTable thead th:nth-child(2),
table.dataTable tbody td:nth-child(2) {
    min-width: 120px;
    max-width: 180px;
    white-space: normal;
    word-wrap: break-word;
}    
    table.dataTable tbody td {
        padding: 10px 8px;
        vertical-align: middle;
        border-bottom: 1px solid rgba(139, 69, 19, 0.1);
        font-size: 13px;
        color: #3e2723;
    }
    
    table.dataTable tbody tr:hover {
        background-color: rgba(255, 140, 0, 0.1);
    }
    
    table.dataTable tbody tr:nth-child(even) {
        background-color: rgba(245, 222, 179, 0.3);
    }
    /* Table Footer Summary */
table.dataTable tfoot th {
    background: linear-gradient(135deg, #8B5A3C 0%, #A0694F 100%);
    color: white;
    font-weight: 700;
    padding: 14px 12px;
    border-top: 3px solid #FF8C00;
    font-size: 14px;
}

table.dataTable tfoot th.text-end {
    text-align: right;
}
    /* Highlight ตัวเลขที่สำคัญ */
    .stock-high {
        background: rgba(5, 150, 105, 0.15);
        color: #059669;
        font-weight: 600;
        padding: 4px 8px;
        border-radius: 4px;
        border: 1px solid rgba(5, 150, 105, 0.3);
    }
    
    .stock-medium {
        background: rgba(255, 140, 0, 0.15);
        color: #FF8C00;
        font-weight: 600;
        padding: 4px 8px;
        border-radius: 4px;
        border: 1px solid rgba(255, 140, 0, 0.3);
    }
    
    .stock-low {
        background: rgba(220, 38, 38, 0.15);
        color: #dc2626;
        font-weight: 600;
        padding: 4px 8px;
        border-radius: 4px;
        border: 1px solid rgba(220, 38, 38, 0.3);
    }
    
    .filter-btn-group {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    .filter-badge {
        padding: 0.5rem 1rem;
        border-radius: 20px;
        border: 2px solid rgba(139, 69, 19, 0.3);
        background: white;
        color: #8B4513;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 14px;
    }
    
    .filter-badge:hover {
        border-color: #8B4513;
        background: rgba(139, 69, 19, 0.05);
    }
    
    .filter-badge.active {
        background: linear-gradient(135deg, #8B4513, #A0522D);
        color: white;
        border-color: #8B4513;
    }
    
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
    
    .loading-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(139, 69, 19, 0.7);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    }
    
    .loading-spinner {
        background: white;
        padding: 2rem;
        border-radius: 12px;
        text-align: center;
        border: 2px solid #FF8C00;
    }
    
    /* Form Controls */
    .form-control, .form-select {
        background: white;
        border: 2px solid rgba(139, 69, 19, 0.2);
        color: #3e2723;
        border-radius: 10px;
        padding: 0.5rem 1rem;
        transition: all 0.3s ease;
    }
    
    .form-control:focus, .form-select:focus {
        background: #fffbf5;
        border-color: #8B4513;
        color: #3e2723;
        box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.15);
    }
    
    .form-control::placeholder {
        color: #a1887f;
    }
    
    .form-label {
        color: #6d4c41;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
    }
    
    /* Buttons */
    .btn-success {
        background: linear-gradient(135deg, #8B4513, #A0522D);
        border: none;
        border-radius: 10px;
        padding: 12px 20px;
        font-weight: bold;
        transition: all 0.3s ease;
        color: white;
    }
    
    .btn-success:hover {
        background: linear-gradient(135deg, #A0522D, #8B4513);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(139, 69, 19, 0.3);
    }
    
    .btn-secondary {
        background: linear-gradient(135deg, #A0522D, #8B4513);
        border: none;
        color: white;
    }
    
    .btn-secondary:hover {
        background: linear-gradient(135deg, #8B4513, #A0522D);
    }
    
    .btn-info {
        background: linear-gradient(135deg, #FF8C00, #FFA500);
        border: none;
        color: white;
    }
    
    .btn-info:hover {
        background: linear-gradient(135deg, #FFA500, #FF8C00);
    }
    
    .btn-light {
        background: white;
        border: 2px solid rgba(139, 69, 19, 0.3);
        color: #8B4513;
    }
    
    .btn-light:hover {
        background: rgba(245, 222, 179, 0.3);
        border-color: #8B4513;
        color: #6d4c41;
    }
    
    /* DataTables custom styling */
    .dataTables_wrapper .dataTables_filter input {
        background: white;
        border: 2px solid rgba(139, 69, 19, 0.2);
        color: #3e2723;
        border-radius: 10px;
        padding: 0.5rem 1rem;
    }
    
    .dataTables_wrapper .dataTables_filter input:focus {
        border-color: #8B4513;
        box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.15);
    }
    
    .dataTables_wrapper .dataTables_length select {
        background: white;
        border: 2px solid rgba(139, 69, 19, 0.2);
        color: #3e2723;
        border-radius: 10px;
        padding: 0.5rem;
    }
    
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
        color: #6d4c41;
    }
    
    .page-item.active .page-link {
        background-color: #8B4513;
        border-color: #8B4513;
    }
    
    .page-link {
        background-color: white;
        border-color: rgba(139, 69, 19, 0.2);
        color: #8B4513;
    }
    
    .page-link:hover {
        background-color: rgba(255, 140, 0, 0.1);
        border-color: #8B4513;
        color: #8B4513;
    }
    
    .page-item.disabled .page-link {
        background-color: rgba(245, 222, 179, 0.3);
        border-color: rgba(139, 69, 19, 0.1);
        color: #a1887f;
    }
    
    .text-muted {
        color: #8d6e63 !important;
    }
    
    /* Spinner */
    .spinner-border {
        color: #FF8C00;
    }
    
    /* Alert */
    .alert-info {
        background: rgba(255, 140, 0, 0.1);
        border-color: #FF8C00;
        color: #8B4513;
    }
    
    .alert-warning {
        background: #fef3c7;
        border-color: #f59e0b;
        color: #92400e;
    }
    
    /* Full Width */
    .container-fluid {
        max-width: 98%;
        padding: 0 2rem;
    }
</style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner-border text-success" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 mb-0">กำลังโหลดข้อมูล...</p>
        </div>
    </div>

<!-- Header -->
<div class="header-section">
    <div class="container-fluid" style="max-width: 98%;">
        <div class="d-flex justify-content-between align-items-center w-100">
            <div class="d-flex align-items-center">
                <a href="/PD/production/pages/dashboard.php" class="btn btn-link text-white me-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h5 class="mb-0">
                        <i class="fas fa-warehouse me-2"></i>สินค้าคงคลัง
                    </h5>
                    <small class="text-light">มุมมองตาราง - แสดงรายละเอียดสต็อกทั้งหมด</small>
                </div>
            </div>
            <div class="text-end">
                <span class="text-white">
                    <i class="fas fa-user-circle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'System Administrator'); ?>
                </span>
            </div>
        </div>
    </div>
</div>

    <div class="container-fluid">
        <!-- Statistics -->
        <div class="stats-row" id="statsContainer">
            <div class="stat-box">
                <div class="stat-value" id="totalItems">0</div>
                <div class="stat-label">รายการทั้งหมด</div>
            </div>
            <div class="stat-box" style="border-left-color: #FF8C00;">
                <div class="stat-value" style="color: #FF8C00;" id="highStock">0</div>
                <div class="stat-label">สต็อกสูง (≥100K)</div>
            </div>
            <div class="stat-box" style="border-left-color: #F57C00;">
                <div class="stat-value" style="color: #F57C00;" id="mediumStock">0</div>
                <div class="stat-label">สต็อกปานกลาง (50K-100K)</div>
            </div>
            <div class="stat-box" style="border-left-color: #DC143C;">
                <div class="stat-value" style="color: #DC143C;" id="lowStock">0</div>
                <div class="stat-label">สต็อกต่ำ (<50K)</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <div class="filter-title">
                <i class="fas fa-filter"></i>
                ตัวกรองข้อมูล
            </div>
            
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">ค้นหา</label>
                    <input type="text" class="form-control" id="searchInput" 
                           placeholder="ค้นหา SSP Code, ชื่อสินค้า, Brand, Lot...">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">คลังสินค้า</label>
                    <select class="form-select" id="warehouseFilter">
                        <option value="">ทุกคลัง</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= $wh['warehouse_id'] ?>">
                                <?= htmlspecialchars($wh['warehouse_name_th'] ?? $wh['warehouse_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">
                        <i class="fas fa-layer-group me-1"></i>กลุ่มสินค้า 
                        <small class="text-muted">(<?= count($material_types) ?>)</small>
                    </label>
                    <select class="form-select" id="materialTypeFilter">
                        <option value="">-- ทั้งหมด --</option>
                        <?php 
                        if (!empty($material_types)) {
                            foreach ($material_types as $mt): 
                                $type_id = $mt['material_type_id'] ?? '';
                                $type_name = $mt['type_name'] ?? 'ไม่ระบุชื่อ';
                        ?>
                            <option value="<?= htmlspecialchars($type_id) ?>">
                                <?= htmlspecialchars($type_name) ?>
                            </option>
                        <?php 
                            endforeach;
                        } else {
                            echo '<option value="" disabled>-- ไม่มีข้อมูล --</option>';
                        }
                        ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">ระดับสต็อก</label>
                    <div class="filter-btn-group">
                        <span class="filter-badge active" data-filter="all" onclick="setStockFilter('all')">
                            <i class="fas fa-list me-1"></i>ทั้งหมด
                        </span>
                        <span class="filter-badge" data-filter="high" onclick="setStockFilter('high')">
                            <i class="fas fa-arrow-up me-1"></i>สูง
                        </span>
                        <span class="filter-badge" data-filter="medium" onclick="setStockFilter('medium')">
                            <i class="fas fa-minus me-1"></i>กลาง
                        </span>
                        <span class="filter-badge" data-filter="low" onclick="setStockFilter('low')">
                            <i class="fas fa-arrow-down me-1"></i>ต่ำ
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="mt-3">
                <button class="btn btn-success" onclick="loadData()">
                    <i class="fas fa-search me-2"></i>ค้นหา
                </button>
                <button class="btn btn-secondary" onclick="clearFilters()">
                    <i class="fas fa-redo me-2"></i>ล้างตัวกรอง
                </button>
                <button class="btn btn-info" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-2"></i>ส่งออก Excel
                </button>
            </div>
        </div>

        <!-- Data Table -->
        <div class="table-container">
            <table id="inventoryTable" class="table table-hover" style="width:100%">
    <thead>
        <tr>
            <th>วันที่รับเข้า</th>
            <th>พื้นที่</th>
            <th>Brand</th>
            <th>SSP Code</th>
            <th>Paperboard Name</th>
            <th>Lot From Supplier</th>
            <th>คงเหลือ<br>พาเลท</th>
            <th>ควบคุม<br>อัตราสต็อก</th>
            <th>Unit</th>
            <th>Warehouse</th>
        </tr>
    </thead>
    <tbody id="tableBody">
        <tr>
            <td colspan="10" class="text-center py-5">
                <i class="fas fa-search fa-3x text-muted mb-3"></i>
                <p>กดปุ่มค้นหาเพื่อแสดงข้อมูล</p>
            </td>
        </tr>
    </tbody>
    <tfoot>
        <tr style="background: linear-gradient(135deg, #8B5A3C 0%, #A0694F 100%); color: white; font-weight: 700;">
            <th colspan="6" class="text-end" style="padding: 12px;">รวมทั้งหมด:</th>
            <th class="text-end" id="totalPallet">0</th>
            <th class="text-end" id="totalStock">0</th>
            <th colspan="2"></th>
        </tr>
    </tfoot>
</table>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <script>
        let dataTable = null;
        let currentStockFilter = 'all';
        let currentData = [];

        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        function setStockFilter(filter) {
            currentStockFilter = filter;
            
            // Update badge styles
            document.querySelectorAll('.filter-badge').forEach(badge => {
                badge.classList.remove('active');
            });
            document.querySelector(`[data-filter="${filter}"]`).classList.add('active');
        }

        function formatDate(dateString) {
            if (!dateString || dateString === '-') return '-';
            
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return '-';
            
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            
            return `${day}/${month}/${year}`;
        }

        function formatNumber(num) {
            return Math.round(num).toLocaleString('th-TH');
        }

        function getStockClass(stock) {
            if (stock >= 100000) return 'stock-high';
            if (stock >= 50000) return 'stock-medium';
            return 'stock-low';
        }
function updateFooterTotals(pallet, stock) {
    const totalPallet = document.getElementById('totalPallet');
    const totalStock = document.getElementById('totalStock');
    
    if (totalPallet) totalPallet.textContent = formatNumber(pallet);
    if (totalStock) totalStock.textContent = formatNumber(stock);
}
        async function loadData() {
    showLoading();
    
    try {
        const formData = new FormData();
        formData.append('action', 'get_inventory');
        formData.append('search', document.getElementById('searchInput')?.value || '');
        formData.append('stock_filter', currentStockFilter);
        formData.append('warehouse', document.getElementById('warehouseFilter')?.value || '');
        formData.append('material_type', document.getElementById('materialTypeFilter')?.value || '');
        
        console.log('Loading data with filters:', {
            search: document.getElementById('searchInput')?.value || '',
            stock_filter: currentStockFilter,
            warehouse: document.getElementById('warehouseFilter')?.value || '',
            material_type: document.getElementById('materialTypeFilter')?.value || ''
        });
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        console.log('Server response:', result);
        
        if (result.success) {
            currentData = result.data;
            displayData(result.data);
            updateStatistics(result.data);
            console.log('Data loaded successfully:', result.data.length, 'items');
        } else {
            console.error('Server error:', result.message);
            alert('เกิดข้อผิดพลาด: ' + (result.message || 'ไม่สามารถโหลดข้อมูลได้'));
            
            // Show error in table
            const tableBody = document.getElementById('tableBody');
            if (tableBody) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="10" class="text-center py-5 text-danger">
                            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                            <p><strong>เกิดข้อผิดพลาด:</strong></p>
                            <p>${result.message || 'ไม่สามารถโหลดข้อมูลได้'}</p>
                        </td>
                    </tr>
                `;
            }
        }
        
    } catch (error) {
        console.error('Error loading data:', error);
        alert('เกิดข้อผิดพลาดในการโหลดข้อมูล: ' + error.message);
        
        // Show error in table
        const tableBody = document.getElementById('tableBody');
        if (tableBody) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="10" class="text-center py-5 text-danger">
                        <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                        <p><strong>เกิดข้อผิดพลาด:</strong></p>
                        <p>${error.message}</p>
                    </td>
                </tr>
            `;
        }
    } finally {
        hideLoading();
    }
}
        

        function displayData(data) {
    // Destroy existing DataTable
    if (dataTable) {
        dataTable.destroy();
    }
    
    const tbody = document.getElementById('tableBody');
    
    if (!tbody) {
        console.error('tableBody element not found!');
        return;
    }
    
    if (!data || data.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="10" class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p>ไม่พบข้อมูล</p>
                </td>
            </tr>
        `;
        updateFooterTotals(0, 0);
        return;
    }
    
    // คำนวณยอดรวม
    let totalPallet = 0;
    let totalStock = 0;
    
    let html = '';
    data.forEach(item => {
        const palletVal = parseFloat(item['คงเหลือพาเลท'] || 0);
        const stockVal = parseFloat(item['ควบคุมอัตราสต็อก'] || 0);
        
        // เพิ่ม class สี highlight ตามจำนวนคงเหลือ
        const stockClass = getStockClass(stockVal);
        
        totalPallet += palletVal;
        totalStock += stockVal;
        
        html += `
            <tr>
                <td>${formatDate(item['วันที่รับเข้า'])}</td>
                <td>${item['พื้นที่'] || '-'}</td>
                <td>${item['Brand'] || '-'}</td>
                <td><strong>${item['SSP Code'] || '-'}</strong></td>
                <td>${item['Paperboard Name'] || '-'}</td>
                <td>${item['Lot From Supplier'] || '-'}</td>
                <td class="text-end">${formatNumber(palletVal)}</td>
                <td class="text-end"><span class="${stockClass}">${formatNumber(stockVal)}</span></td>
                <td class="text-center">${item['Unit'] || '-'}</td>
                <td>${item['Warehouse'] || '-'}</td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
    
    // อัปเดตยอดรวมใน footer
    updateFooterTotals(totalPallet, totalStock);
    
    // Initialize DataTable
    try {
        dataTable = $('#inventoryTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json'
            },
            columnDefs: [
                { targets: [6, 7], className: 'text-end' },
                { targets: [8], className: 'text-center' }
            ],
            footerCallback: function(row, data, start, end, display) {
                // แสดงยอดรวมทั้งหมด
                const col6 = this.api().column(6).footer();
                const col7 = this.api().column(7).footer();
                
                if (col6) $(col6).html(formatNumber(totalPallet));
                if (col7) $(col7).html(formatNumber(totalStock));
            }
        });
    } catch (error) {
        console.error('Error initializing DataTable:', error);
    }
}

function updateStatistics(data) {
    let high = 0, medium = 0, low = 0;
    
    data.forEach(item => {
        const stock = parseFloat(item['ควบคุมอัตราสต็อก'] || 0);
        if (stock >= 100000) high++;
        else if (stock >= 50000) medium++;
        else low++;
    });
    
    const totalItems = document.getElementById('totalItems');
    const highStockEl = document.getElementById('highStock');
    const mediumStockEl = document.getElementById('mediumStock');
    const lowStockEl = document.getElementById('lowStock');
    
    if (totalItems) totalItems.textContent = formatNumber(data.length);
    if (highStockEl) highStockEl.textContent = formatNumber(high);
    if (mediumStockEl) mediumStockEl.textContent = formatNumber(medium);
    if (lowStockEl) lowStockEl.textContent = formatNumber(low);
}

        function clearFilters() {
            const searchInput = document.getElementById('searchInput');
            const warehouseFilter = document.getElementById('warehouseFilter');
            const materialTypeFilter = document.getElementById('materialTypeFilter');
            
            if (searchInput) searchInput.value = '';
            if (warehouseFilter) warehouseFilter.value = '';
            if (materialTypeFilter) materialTypeFilter.value = '';
            
            // Reset stock filter
            currentStockFilter = 'all';
            document.querySelectorAll('.filter-badge').forEach(badge => {
                badge.classList.remove('active');
            });
            const allBadge = document.querySelector('[data-filter="all"]');
            if (allBadge) allBadge.classList.add('active');
            
            console.log('Filters cleared - loading all data');
            
            // โหลดข้อมูลใหม่ทันที
            loadData();
        }

        function exportToExcel() {
            if (!currentData || currentData.length === 0) {
                alert('ไม่มีข้อมูลสำหรับส่งออก');
                return;
            }
            
            const ws = XLSX.utils.json_to_sheet(currentData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Inventory");
            
            const filename = `inventory_${new Date().toISOString().split('T')[0]}.xlsx`;
            XLSX.writeFile(wb, filename);
        }

        // Load data on enter key
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                loadData();
            }
        });

        // Auto load data on page load
        window.addEventListener('load', function() {
            loadData();
        });
    </script>
</body>
</html>