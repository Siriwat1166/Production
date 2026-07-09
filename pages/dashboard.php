<?php
// pages/dashboard.php - หน้าหลักของระบบ (แยกเป็น 3 หมวดหมู่)
require_once "../config/config.php";
require_once "../classes/Auth.php";
require_once "../classes/Material.php";

$auth = new Auth();
$auth->requireLogin();

// ข้อมูลผู้ใช้
$user_id = $_SESSION['user_id'] ?? 'N/A';
$username = $_SESSION['username'] ?? 'guest';
$full_name = $_SESSION['full_name'] ?? 'Guest User';
$role = $_SESSION['role'] ?? 'viewer';

$current_time = date('Y-m-d H:i:s');

// ข้อมูลเริ่มต้น
$total_inventory_items = 0;
$low_stock_items = 0;
$total_materials = 0;
$total_types = 0;
$total_users = 0;
$total_pos = 0;
$db_error = null;
$db_connected = false;

// เชื่อมต่อฐานข้อมูล
try {
    if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
        $pdo = new PDO("sqlsrv:server=" . DB_SERVER . ";Database=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db_connected = true;

        $materialManager = new Material();

        // ดึงสถิติวัสดุ
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total_materials FROM dbo.Master_Products_ID WHERE is_active = 1");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $total_materials = $result['total_materials'] ?? 0;
        } catch (Exception $e) {
            $total_materials = 245;
        }

        // ดึงจำนวนประเภทวัสดุ
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total_types FROM dbo.Material_Types WHERE is_active = 1");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $total_types = $result['total_types'] ?? 0;
        } catch (Exception $e) {
            $total_types = 12;
        }

        // ดึงข้อมูลผู้ใช้
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM dbo.Users WHERE is_active = 1");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $total_users = $result['total_users'] ?? 0;
        } catch (Exception $e) {
            $total_users = 5;
        }

        // ดึงข้อมูล PO Header
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total_pos FROM dbo.PO_Header");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $total_pos = $result['total_pos'] ?? 0;
        } catch (Exception $e) {
            $total_pos = 15;
        }

        // ดึงข้อมูล Inventory
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total_inventory FROM dbo.Inventory WHERE quantity > 0");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $total_inventory_items = $result['total_inventory'] ?? 0;
            
            $stmt = $pdo->query("SELECT COUNT(*) as low_stock FROM dbo.Inventory WHERE quantity <= minimum_stock");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $low_stock_items = $result['low_stock'] ?? 0;
        } catch (Exception $e) {
            $total_inventory_items = 180;
            $low_stock_items = 15;
        }
    }
} catch (PDOException $e) {
    $db_error = "ไม่สามารถเชื่อมต่อฐานข้อมูลได้: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - ระบบจัดการคลังสินค้า</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-orange: #FF8C00;
            --primary-brown: #8B4513;
            --primary-green: #228B22;
            --bg-cream: #FFF8DC;
            --bg-beige: #F5DEB3;
        }
        
        body {
    background: linear-gradient(135deg, #FFF8DC 0%, #FAEBD7 100%);
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    padding-bottom: 2rem;
}
        
.dashboard-header {
    background: linear-gradient(135deg, var(--primary-orange) 0%, var(--primary-brown) 100%);
    color: white;
    padding: 1rem 0;
    box-shadow: 0 4px 20px rgba(139, 69, 19, 0.3);
    margin-bottom: 1.5rem;
}

.welcome-section h1 {
    font-size: 1.3rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.welcome-section p {
    font-size: 0.85rem;
}
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .welcome-section h1 {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        /* Category Sections */
.category-section {
    background: white;
    border-radius: 20px;
    padding: 0;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    margin-bottom: 1rem;
    transition: all 0.3s ease;
}
        
        .category-section:hover {
            box-shadow: 0 6px 30px rgba(0, 0, 0, 0.12);
        }
        
.category-header {
    padding: 1rem 1.5rem;
    color: white;
    font-size: 1.1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.category-header i {
    font-size: 1.2rem;
    margin-right: 0.5rem;
}

.category-subtitle {
    font-size: 0.8rem;
    font-weight: 400;
    opacity: 0.95;
}
        
        /* Category Colors */
        .master-data-header {
            background: linear-gradient(135deg, #FF8C00 0%, #FFA500 100%);
        }
        
        .po-header {
            background: linear-gradient(135deg, #8B4513 0%, #A0522D 100%);
        }
        
        .inventory-header {
            background: linear-gradient(135deg, #228B22 0%, #32CD32 100%);
        }
        
.category-body {
    padding: 1rem;
}
        
        /* Menu Grid Layout */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
        }
        
        .menu-grid-2rows {
            grid-template-columns: repeat(4, 1fr);
        }
        
        @media (max-width: 1400px) {
            .menu-grid-2rows {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 992px) {
            .menu-grid-2rows {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 576px) {
            .menu-grid,
            .menu-grid-2rows {
                grid-template-columns: 1fr;
            }
        }
        
        /* Menu Buttons */
        .menu-btn {
            display: flex;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-radius: 12px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            background: rgba(0, 0, 0, 0.02);
            position: relative;
            overflow: hidden;
            height: 100%;
        }
        
        .menu-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }
        
        .menu-btn:hover::before {
            left: 100%;
        }
        
        .menu-btn:hover {
            transform: translateY(-5px);
            border-color: currentColor;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }
        
        /* Menu Button Colors */
        .master-data-section .menu-btn {
            background: rgba(255, 140, 0, 0.05);
        }
        
        .master-data-section .menu-btn:hover {
            background: rgba(255, 140, 0, 0.1);
            border-color: #FF8C00;
        }
        
        .po-section .menu-btn {
            background: rgba(139, 69, 19, 0.05);
        }
        
        .po-section .menu-btn:hover {
            background: rgba(139, 69, 19, 0.1);
            border-color: #8B4513;
        }
        
        .inventory-section .menu-btn {
            background: rgba(34, 139, 34, 0.05);
        }
        
        .inventory-section .menu-btn:hover {
            background: rgba(34, 139, 34, 0.1);
            border-color: #228B22;
        }
        
        .menu-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .master-data-section .menu-icon {
            background: rgba(255, 140, 0, 0.15);
            color: #FF8C00;
        }
        
        .po-section .menu-icon {
            background: rgba(139, 69, 19, 0.15);
            color: #8B4513;
        }
        
        .inventory-section .menu-icon {
            background: rgba(34, 139, 34, 0.15);
            color: #228B22;
        }
        
        .menu-content {
            flex: 1;
            min-width: 0;
        }
        
        .menu-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.25rem;
            color: #333;
            word-wrap: break-word;
        }
        
        .menu-desc {
            font-size: 0.85rem;
            color: #A0522D;
            margin: 0;
            line-height: 1.4;
            word-wrap: break-word;
        }
        
        /* Activity Feed */
        .activity-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin-top: 2rem;
        }
        
        .activity-header {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .activity-header i {
            color: var(--primary-orange);
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-left: 3px solid var(--primary-orange);
            background: rgba(255, 140, 0, 0.03);
            border-radius: 8px;
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .activity-item:hover {
            background: rgba(255, 140, 0, 0.08);
            transform: translateX(5px);
        }
        
        .activity-icon-wrapper {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 140, 0, 0.15);
            color: var(--primary-orange);
            flex-shrink: 0;
        }
        
        .activity-details {
            flex: 1;
        }
        
        .activity-text {
            font-weight: 500;
            color: #333;
            margin-bottom: 0.25rem;
        }
        
        .activity-time {
            font-size: 0.85rem;
            color: #999;
        }
        
        @media (max-width: 768px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="dashboard-header">
        <div class="container">
            <div class="header-content">
                <div class="welcome-section">
                    <h1><i class="fas fa-home me-2"></i>ยินดีต้อนรับ, <?= htmlspecialchars($full_name) ?></h1>
                    <p class="mb-0">
                        <i class="fas fa-user-tag me-2"></i><?= htmlspecialchars($role) ?> | 
                        <i class="fas fa-clock ms-3 me-2"></i><?= date('d/m/Y H:i', strtotime($current_time)) ?> น.
                    </p>
                </div>
<div>
    <a href="../logout.php" class="btn btn-light btn-sm">
        <i class="fas fa-sign-out-alt me-2"></i>ออกจากระบบ
    </a>
</div>
            </div>
        </div>
    </div>

    <div class="container">
        
    <!-- 1. โหมดจัดวัสดุ (Master Data) -->
        <div class="category-section master-data-section mb-4">
            <div class="category-header master-data-header">
                <div>
                    <i class="fas fa-database"></i>
                    โหมดจัดวัสดุ
                </div>
                <div class="category-subtitle">จัดการข้อมูลเบื้องต้นทุกประเภท</div>
            </div>
            <div class="category-body">
                <div class="menu-grid">
                    <?php if ($auth->hasRole(['editor', 'admin'])): ?>
                    <a href="materials/add.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-plus"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">เพิ่มวัสดุ</div>
                            <div class="menu-desc">เพิ่มข้อมูลวัสดุเข้าระบบ</div>
                        </div>
                    </a>
                    
                    <a href="materials/list.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-list"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">รายการวัสดุ</div>
                            <div class="menu-desc">ดูและจัดการรายการวัสดุ</div>
                        </div>
                    </a>
                    
                    <a href="suppliers/" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">ซัพพลายเออร์</div>
                            <div class="menu-desc">จัดการข้อมูลผู้จำหน่าย</div>
                        </div>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 2. โหมดจัด PO (Purchase Order) -->
        <div class="category-section po-section mb-4">
            <div class="category-header po-header">
                <div>
                    <i class="fas fa-file-invoice"></i>
                    โหมดจัด PO
                </div>
                <div class="category-subtitle">จัดการ Purchase Order</div>
            </div>
            <div class="category-body">
                <div class="menu-grid">
                    <?php if ($auth->hasRole(['editor', 'admin'])): ?>
                    <a href="po/create.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-plus"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">เปิด PO</div>
                            <div class="menu-desc">สร้าง Purchase Order ใหม่</div>
                        </div>
                    </a>
                    <?php endif; ?>
                    
                    <a href="po/list.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-list"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">รายการ PO</div>
                            <div class="menu-desc">ดูและจัดการรายการ PO ทั้งหมด</div>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        <!-- 3. โหมดคลังสินค้า (Inventory) -->
        <div class="category-section inventory-section mb-4">
            <div class="category-header inventory-header">
                <div>
                    <i class="fas fa-warehouse"></i>
                    โหมดคลังสินค้า
                </div>
                <div class="category-subtitle">จัดการสินค้าคงคลัง</div>
            </div>
            <div class="category-body">
                <div class="menu-grid menu-grid-2rows">
                    <a href="PO/receiving_po.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-truck-loading"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">รับเข้าสินค้าจาก PO</div>
                            <div class="menu-desc">รับสินค้าเข้าจาก Purchase Order</div>
                        </div>
                    </a>
                    
                    <a href="PO/receiving_direct.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-arrow-down"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">รับเข้าตรง (ไม่มี PO)</div>
                            <div class="menu-desc">รับสินค้าเข้าคลังโดยตรง (ไม่มี PO)</div>
                        </div>
                    </a>
                    
                    <a href="/PD/production/inventory/dispatch_goods.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-arrow-up"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">จ่ายออก</div>
                            <div class="menu-desc">เบิกสินค้าออกจากคลัง</div>
                        </div>
                    </a>
                    
                    <a href="/PD/production/inventory/transfer_location.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">ย้ายพื้นที่</div>
                            <div class="menu-desc">จัดการการเคลื่อนย้ายสินค้า</div>
                        </div>
                    </a>
                    
                    <a href="/PD/production/inventory/goods_receipt_list.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-warehouse"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">รายการรับเข้า</div>
                            <div class="menu-desc">ดูรายการเบิกสินค้าออกจากคลัง</div>
                        </div>
                    </a>
                    
                    <a href="/PD/production/inventory/dispatch_list.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-truck-loading"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">รายการจ่ายออก</div>
                            <div class="menu-desc">ดูรายการเบิกสินค้าออกจากคลัง</div>
                        </div>
                    </a>
                    
                    <a href="/PD/production/inventory/stock_movements_list.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">รายการเคลื่อนไหวสต็อก</div>
                            <div class="menu-desc">ดูรายการเบิกสินค้าออกจากคลัง</div>
                        </div>
                    </a>
                    
                    <a href="/PD/production/inventory/inventory_view.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">สินค้าคงคลัง</div>
                            <div class="menu-desc">ดูและจัดการสต็อกสินค้า</div>
                        </div>
                    </a>

                    <a href="inventory_dashboard.php" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">Inventory Dashboard</div>
                            <div class="menu-desc">ภาพรวมระบบรับเข้า-จ่ายออกสต็อก</div>
                        </div>
                    </a>

                    <a href="http://localhost:5000" target="_blank" class="menu-btn">
                        <div class="menu-icon">
                            <i class="fas fa-print"></i>
                        </div>
                        <div class="menu-content">
                            <div class="menu-title">PPF Ink Analyzer</div>
                            <div class="menu-desc">วิเคราะห์ Heidelberg ink key zones</div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>