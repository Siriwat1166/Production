<?php
// pages/po/view.php - หน้าดูรายละเอียด Purchase Order
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();

// Helper function for input sanitization
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

// เชื่อมต่อฐานข้อมูล
try {
    require_once "../../config/database.php";
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

// ตรวจสอบ PO ID
$po_id = intval($_GET['id'] ?? 0);
if (!$po_id) {
    header("Location: list.php");
    exit();
}

$po_data = null;
$po_items = [];
$linked_po_data = null;
$message = '';
$message_type = '';

try {
    // ดึงข้อมูล PO Header
    $sql = "
        SELECT ph.po_id, ph.po_number, ph.po_date, ph.supplier_id, ph.total_amount,
               ph.material_amount, ph.freight_amount, ph.service_amount,
               ph.status, ph.is_material_po, ph.is_freight_po, ph.po_category,
               ph.created_date, ph.notes, ph.linked_po_id, ph.currency, ph.exchange_rate,
               ph.delivery_date, ph.payment_terms, ph.created_by, ph.updated_by, ph.updated_date,
               s.supplier_name, s.supplier_code, s.contact_person, s.email, s.phone, s.address,
               u.full_name as created_by_name,
               u2.full_name as updated_by_name,
               linked_po.po_number as linked_po_number
        FROM PO_Header ph
        LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
        LEFT JOIN Users u ON ph.created_by = u.user_id
        LEFT JOIN Users u2 ON ph.updated_by = u2.user_id
        LEFT JOIN PO_Header linked_po ON ph.linked_po_id = linked_po.po_id
        WHERE ph.po_id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$po_id]);
    $po_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$po_data) {
        throw new Exception("ไม่พบข้อมูล PO ที่ระบุ");
    }
    
    // ดึงข้อมูล PO Items
    $items_sql = "
        SELECT pi.po_item_id, pi.line_number, pi.product_id, pi.item_description,
               pi.quantity, pi.purchase_unit_id, pi.stock_unit_id, pi.conversion_factor,
               pi.stock_quantity, pi.unit_price, pi.total_price, pi.discount_percent,
               pi.discount_amount, pi.delivery_date, pi.status as item_status,
               pi.received_quantity, pi.pending_quantity, pi.notes as item_notes,
               pi.package_qty, pi.weight_per_package,
               p.SSP_Code, p.Name as product_name, p.Name2 as product_name_en,
               pu.unit_name as purchase_unit_name, pu.unit_symbol as purchase_unit_symbol,
               su.unit_name as stock_unit_name, su.unit_symbol as stock_unit_symbol,
               g.name as group_name, mt.type_name as material_type_name
        FROM PO_Items pi
        LEFT JOIN Master_Products_ID p ON pi.product_id = p.id
        LEFT JOIN Units pu ON pi.purchase_unit_id = pu.unit_id
        LEFT JOIN Units su ON pi.stock_unit_id = su.unit_id
        LEFT JOIN Groups g ON p.group_id = g.id
        LEFT JOIN Material_Types mt ON p.material_type_id = mt.material_type_id
        WHERE pi.po_id = ?
        ORDER BY pi.line_number
    ";
    
    $stmt = $conn->prepare($items_sql);
    $stmt->execute([$po_id]);
    $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูล Linked PO ถ้ามี
    if (!empty($po_data['linked_po_id'])) {
        $linked_sql = "
            SELECT ph.po_number, ph.po_date, ph.total_amount, ph.status, s.supplier_name
            FROM PO_Header ph
            LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
            WHERE ph.po_id = ?
        ";
        
        $stmt = $conn->prepare($linked_sql);
        $stmt->execute([$po_data['linked_po_id']]);
        $linked_po_data = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Error loading PO details: " . $e->getMessage());
    $message = "เกิดข้อผิดพลาดในการโหลดข้อมูล: " . $e->getMessage();
    $message_type = "danger";
} catch (Exception $e) {
    error_log("General error loading PO details: " . $e->getMessage());
    $message = $e->getMessage();
    $message_type = "danger";
}

// Status Colors และ Labels
$status_colors = [
    'Draft' => 'warning',
    'Approved' => 'success',
    'Partial' => 'info',
    'Completed' => 'primary',
    'Cancelled' => 'danger'
];

$status_labels = [
    'Draft' => 'แบบร่าง',
    'Approved' => 'อนุมัติแล้ว',
    'Partial' => 'บางส่วน',
    'Completed' => 'เสร็จสิ้น',
    'Cancelled' => 'ยกเลิก'
];

$item_status_colors = [
    'Open' => 'primary',
    'Partial' => 'warning',
    'Completed' => 'success',
    'Cancelled' => 'danger'
];

$item_status_labels = [
    'Open' => 'เปิด',
    'Partial' => 'บางส่วน',
    'Completed' => 'เสร็จสิ้น',
    'Cancelled' => 'ยกเลิก'
];
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ดูรายละเอียด PO <?= htmlspecialchars($po_data['po_number'] ?? '') ?> - <?= htmlspecialchars(APP_NAME ?? 'Material Management') ?></title>
    
    <!-- Security Headers -->
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #ff9a56 0%, #ffb347 50%, #ffd700 100%);
            --primary-gradient-dark: linear-gradient(135deg, #ff7f50 0%, #ff9a56 100%);
        }

        body {
            background: linear-gradient(135deg, #fff8f0 0%, #ffe4d1 50%, #fff3e0 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar {
            background: var(--primary-gradient);
            box-shadow: 0 4px 20px rgba(255, 154, 86, 0.3);
        }
        
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(255, 154, 86, 0.15);
            background: white;
            border: 2px solid #ffe4d1;
        }
        
        .card-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 18px 18px 0 0 !important;
            border-bottom: none;
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: var(--primary-gradient-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 154, 86, 0.3);
        }
        
        .btn-outline-primary {
            border: 2px solid #ff9a56;
            color: #ff7f50;
            border-radius: 10px;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: #ff9a56;
        }
        
        .alert {
            border-radius: 15px;
            border: none;
        }
        
        .table {
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .table thead th {
            background: linear-gradient(135deg, #34495e 0%, #2c3e50 100%);
            color: white;
            border: none;
            font-weight: 600;
            padding: 15px 12px;
        }
        
        .table td {
            padding: 12px;
            vertical-align: middle;
            border-color: #ffe4d1;
        }
        
        .info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            margin-bottom: 20px;
        }
        
        .info-item {
            padding: 15px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: bold;
            opacity: 0.9;
            font-size: 0.9em;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 1.1em;
        }
        
        .po-number {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 1.2em;
            letter-spacing: 1px;
        }
        
        .status-badge {
            font-size: 1em;
            padding: 8px 15px;
            border-radius: 25px;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
        }
        
        .summary-amount {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .summary-label {
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.5s ease;
        }
        
        .btn-sm {
            padding: 8px 15px;
            font-size: 0.9em;
            border-radius: 8px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .linked-po-card {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            border-radius: 15px;
            padding: 15px;
        }
        
        .print-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            margin: 20px 0;
        }
        
        @media print {
            body * {
                visibility: hidden;
            }
            .print-section, .print-section * {
                visibility: visible;
            }
            .print-section {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            .no-print {
                display: none !important;
            }
        }
        
        .item-details {
            font-size: 0.9em;
            color: #666;
        }
        
        .progress-bar {
            height: 8px;
            border-radius: 4px;
        }
        
        .product-code {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #007bff;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark no-print">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="../dashboard.php">
                <i class="fas fa-eye me-2"></i><?= htmlspecialchars(APP_NAME ?? 'Material Management') ?>
            </a>
            
            <div class="navbar-nav ms-auto">
                <?php if ($po_data && $auth->hasRole(['editor', 'admin']) && $po_data['status'] === 'Draft'): ?>
                <a class="nav-link" href="edit.php?id=<?= $po_id ?>">
                    <i class="fas fa-edit me-1"></i> แก้ไข
                </a>
                <?php endif; ?>
                <a class="nav-link" href="list.php">
                    <i class="fas fa-list me-1"></i> รายการ PO
                </a>
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-arrow-left me-1"></i> กลับสู่หน้าหลัก
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container-fluid mt-4" style="padding-top: 20px;">
        <!-- Alert Messages -->
        <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show fade-in-up no-print" role="alert">
            <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($po_data): ?>
        
        <!-- Header Actions -->
        <div class="row no-print">
            <div class="col-12">
                <div class="card mb-4 fade-in-up">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0">
                                <i class="fas fa-file-invoice me-2"></i>รายละเอียด Purchase Order
                            </h4>
                            <div class="action-buttons">
                                <button onclick="window.print()" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-print me-1"></i>พิมพ์
                                </button>
                                <?php if ($auth->hasRole(['editor', 'admin']) && $po_data['status'] === 'Draft'): ?>
                                <a href="edit.php?id=<?= $po_id ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-edit me-1"></i>แก้ไข
                                </a>
                                <?php endif; ?>
                                <a href="list.php" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-list me-1"></i>กลับไปรายการ
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Print Section -->
        <div class="print-section">
            <!-- PO Header Information -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card fade-in-up">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>ข้อมูล Purchase Order
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">เลขที่ PO</div>
                                        <div class="info-value po-number"><?= htmlspecialchars($po_data['po_number']) ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">วันที่</div>
                                        <div class="info-value"><?= date('d/m/Y', strtotime($po_data['po_date'])) ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">ประเภท PO</div>
                                        <div class="info-value">
                                            <?php if ($po_data['is_material_po']): ?>
                                            <span class="badge bg-primary">
                                                <i class="fas fa-box me-1"></i>วัตถุดิบ
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($po_data['is_freight_po']): ?>
                                            <span class="badge bg-info">
                                                <i class="fas fa-truck me-1"></i>ค่าขนส่ง
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">สถานะ</div>
                                        <div class="info-value">
                                            <?php
                                            $status_color = $status_colors[$po_data['status']] ?? 'secondary';
                                            $status_label = $status_labels[$po_data['status']] ?? $po_data['status'];
                                            ?>
                                            <span class="badge bg-<?= $status_color ?> status-badge"><?= $status_label ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">สกุลเงิน</div>
                                        <div class="info-value"><?= htmlspecialchars($po_data['currency'] ?? 'THB') ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">อัตราแลกเปลี่ยน</div>
                                        <div class="info-value"><?= number_format($po_data['exchange_rate'] ?? 1, 4) ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($po_data['delivery_date'])): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">วันที่จัดส่ง</div>
                                        <div class="info-value"><?= date('d/m/Y', strtotime($po_data['delivery_date'])) ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">เงื่อนไขการชำระเงิน</div>
                                        <div class="info-value"><?= htmlspecialchars($po_data['payment_terms'] ?? '-') ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($po_data['notes'])): ?>
                            <div class="row">
                                <div class="col-12">
                                    <div class="info-item">
                                        <div class="info-label">หมายเหตุ</div>
                                        <div class="info-value"><?= nl2br(htmlspecialchars($po_data['notes'])) ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Summary -->
                <div class="col-md-4">
                    <div class="summary-card fade-in-up">
                        <div class="summary-amount">฿<?= number_format($po_data['total_amount'], 2) ?></div>
                        <div class="summary-label">มูลค่ารวมทั้งหมด</div>
                        
                        <?php if ($po_data['material_amount'] > 0): ?>
                        <hr style="border-color: rgba(255,255,255,0.3);">
                        <div class="row text-start">
                            <div class="col-6">วัตถุดิบ:</div>
                            <div class="col-6">฿<?= number_format($po_data['material_amount'], 2) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($po_data['freight_amount'] > 0): ?>
                        <div class="row text-start">
                            <div class="col-6">ค่าขนส่ง:</div>
                            <div class="col-6">฿<?= number_format($po_data['freight_amount'], 2) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($po_data['service_amount'] > 0): ?>
                        <div class="row text-start">
                            <div class="col-6">ค่าบริการ:</div>
                            <div class="col-6">฿<?= number_format($po_data['service_amount'], 2) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Supplier Information -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card fade-in-up">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-building me-2"></i>ข้อมูล Supplier
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="info-item">
                                <div class="info-label">ชื่อบริษัท</div>
                                <div class="info-value"><?= htmlspecialchars($po_data['supplier_name']) ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">รหัส Supplier</div>
                                <div class="info-value"><?= htmlspecialchars($po_data['supplier_code']) ?></div>
                            </div>
                            <?php if (!empty($po_data['contact_person'])): ?>
                            <div class="info-item">
                                <div class="info-label">ผู้ติดต่อ</div>
                                <div class="info-value"><?= htmlspecialchars($po_data['contact_person']) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($po_data['email'])): ?>
                            <div class="info-item">
                                <div class="info-label">อีเมล</div>
                                <div class="info-value">
                                    <a href="mailto:<?= htmlspecialchars($po_data['email']) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($po_data['email']) ?>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($po_data['phone'])): ?>
                            <div class="info-item">
                                <div class="info-label">โทรศัพท์</div>
                                <div class="info-value">
                                    <a href="tel:<?= htmlspecialchars($po_data['phone']) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($po_data['phone']) ?>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($po_data['address'])): ?>
                            <div class="info-item">
                                <div class="info-label">ที่อยู่</div>
                                <div class="info-value"><?= nl2br(htmlspecialchars($po_data['address'])) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- System Information -->
                <div class="col-md-6">
                    <div class="card fade-in-up">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-cog me-2"></i>ข้อมูลระบบ
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="info-item">
                                <div class="info-label">สร้างโดย</div>
                                <div class="info-value"><?= htmlspecialchars($po_data['created_by_name'] ?? 'N/A') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">วันที่สร้าง</div>
                                <div class="info-value"><?= date('d/m/Y H:i:s', strtotime($po_data['created_date'])) ?></div>
                            </div>
                            <?php if (!empty($po_data['updated_by_name'])): ?>
                            <div class="info-item">
                                <div class="info-label">แก้ไขล่าสุดโดย</div>
                                <div class="info-value"><?= htmlspecialchars($po_data['updated_by_name']) ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">วันที่แก้ไขล่าสุด</div>
                                <div class="info-value"><?= date('d/m/Y H:i:s', strtotime($po_data['updated_date'])) ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Linked PO Information -->
                            <?php if ($linked_po_data): ?>
                            <div class="info-item">
                                <div class="info-label">เชื่อมโยงกับ PO</div>
                                <div class="info-value">
                                    <a href="view.php?id=<?= $po_data['linked_po_id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($linked_po_data['po_number']) ?>
                                    </a>
                                    <br>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($linked_po_data['supplier_name']) ?> | 
                                        ฿<?= number_format($linked_po_data['total_amount'], 2) ?>
                                    </small>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PO Items -->
            <div class="row">
                <div class="col-12">
                    <div class="card fade-in-up">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>รายการสินค้า/ค่าใช้จ่าย
                                <span class="badge bg-light text-dark ms-2"><?= count($po_items) ?> รายการ</span>
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($po_items)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">ไม่มีรายการสินค้า</h5>
                                <p class="text-muted">PO นี้ยังไม่มีรายการสินค้าหรือค่าใช้จ่าย</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th width="4%">#</th>
                                            <th width="20%">รายการ</th>
                                            <th width="8%">ซื้อ</th>
                                            <th width="8%">หน่วย</th>
                                            <th width="8%">คลัง</th>
                                            <th width="8%">อัตรา</th>
                                            <th width="10%">ราคา/หน่วย</th>
                                            <th width="10%">รวม</th>
                                            <th width="8%">สถานะ</th>
                                            <th width="16%">รายละเอียด</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($po_items as $index => $item): ?>
                                        <tr>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?= $item['line_number'] ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($item['product_id'])): ?>
                                                <!-- Material Item -->
                                                <div class="product-code" style="font-size: 0.85em; color: #666;"><?= htmlspecialchars($item['SSP_Code']) ?></div>
                                                <div class="fw-bold"><?= htmlspecialchars($item['product_name']) ?></div>
                                                <?php if (!empty($item['product_name_en'])): ?>
                                                <div class="item-details" style="font-size: 0.8em;"><?= htmlspecialchars($item['product_name_en']) ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($item['group_name']) || !empty($item['material_type_name'])): ?>
                                                <div class="item-details" style="margin-top: 4px;">
                                                    <?php if (!empty($item['group_name'])): ?>
                                                    <span class="badge bg-info me-1" style="font-size: 0.75em;"><?= htmlspecialchars($item['group_name']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['material_type_name'])): ?>
                                                    <span class="badge bg-warning" style="font-size: 0.75em;"><?= htmlspecialchars($item['material_type_name']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>
                                                <?php else: ?>
                                                <!-- Freight/Service Item -->
                                                <div class="fw-bold"><?= htmlspecialchars($item['item_description']) ?></div>
                                                <div class="item-details" style="font-size: 0.8em;">
                                                    <i class="fas fa-truck me-1"></i>ค่าใช้จ่าย/บริการ
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                                                                        <td class="text-end">
                                                <?php if (!empty($item['package_qty']) && !empty($item['weight_per_package'])): ?>
                                                <!-- แสดงแบบมี package -->
                                                <div class="fw-bold text-primary"><?= number_format($item['package_qty'], 0) ?></div>
                                                <small class="d-block text-muted" style="font-size: 0.75em;">
                                                    <i class="fas fa-box"></i> กระป๋อง: <?= number_format($item['package_qty'], 0) ?>
                                                </small>
                                                <small class="d-block text-muted" style="font-size: 0.75em;">
                                                    <i class="fas fa-weight"></i> น้ำหนัก/กระป๋อง: <?= number_format($item['weight_per_package'], 2) ?> kg
                                                </small>
                                                <small class="d-block text-info" style="font-size: 0.75em;">
                                                    <i class="fas fa-calculator"></i> รวม: <?= number_format($item['quantity'], 2) ?> kg
                                                </small>
                                                <?php else: ?>
                                                <!-- แสดงแบบปกติ (ไม่มี package) -->
                                                <div><?= number_format($item['quantity'], 2) ?></div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($item['received_quantity']) && $item['received_quantity'] > 0): ?>
                                                <small class="d-block text-success mt-1">
                                                    <i class="fas fa-check"></i> รับแล้ว: <?= number_format($item['received_quantity'], 2) ?>
                                                </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($item['purchase_unit_name'])): ?>
                                                <div style="font-size: 0.9em;"><?= htmlspecialchars($item['purchase_unit_name']) ?></div>
                                                <?php if (!empty($item['purchase_unit_symbol'])): ?>
                                                <small class="text-muted">(<?= htmlspecialchars($item['purchase_unit_symbol']) ?>)</small>
                                                <?php endif; ?>
                                                <?php else: ?>
                                                <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-size: 0.9em;">
                                                    <?php if (!empty($item['stock_unit_name'])): ?>
                                                    <?= htmlspecialchars($item['stock_unit_name']) ?>
                                                    <?php if (!empty($item['stock_unit_symbol'])): ?>
                                                    <small class="text-muted">(<?= htmlspecialchars($item['stock_unit_symbol']) ?>)</small>
                                                    <?php endif; ?>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($item['stock_quantity']) && $item['stock_quantity'] != $item['quantity']): ?>
                                                <small class="d-block text-muted">
                                                    คลัง: <?= number_format($item['stock_quantity'], 2) ?>
                                                </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if (!empty($item['conversion_factor']) && $item['conversion_factor'] != 1): ?>
                                                <div title="อัตราแปลงหน่วย">
                                                    <small class="badge bg-info">×<?= number_format($item['conversion_factor'], 2) ?></small>
                                                </div>
                                                <?php else: ?>
                                                <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-bold">฿<?= number_format($item['unit_price'], 2) ?></div>
                                                <?php if (!empty($item['discount_percent']) && $item['discount_percent'] > 0): ?>
                                                <small class="d-block text-danger">
                                                    <i class="fas fa-percent"></i><?= number_format($item['discount_percent'], 1) ?>%
                                                </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-bold">฿<?= number_format($item['total_price'], 2) ?></div>
                                                <?php if (!empty($item['discount_amount']) && $item['discount_amount'] > 0): ?>
                                                <small class="d-block text-danger">
                                                    -฿<?= number_format($item['discount_amount'], 2) ?>
                                                </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $item_status_color = $item_status_colors[$item['item_status']] ?? 'secondary';
                                                $item_status_label = $item_status_labels[$item['item_status']] ?? $item['item_status'];
                                                ?>
                                                <span class="badge bg-<?= $item_status_color ?>" style="font-size: 0.8em;"><?= $item_status_label ?></span>
                                                
                                                <?php if (!empty($item['received_quantity']) && !empty($item['quantity'])): ?>
                                                <?php 
                                                $progress_percent = ($item['received_quantity'] / $item['quantity']) * 100; 
                                                $progress_color = $progress_percent >= 100 ? 'success' : ($progress_percent >= 50 ? 'warning' : 'info');
                                                ?>
                                                <div class="mt-1" style="width: 60px;">
                                                    <div class="progress" style="height: 5px;">
                                                        <div class="progress-bar bg-<?= $progress_color ?>" 
                                                             style="width: <?= min(100, $progress_percent) ?>%"></div>
                                                    </div>
                                                    <small class="text-muted" style="font-size: 0.75em;"><?= number_format($progress_percent, 0) ?>%</small>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-size: 0.85em;">
                                                    <?php if (!empty($item['item_notes'])): ?>
                                                    <div class="mb-1">
                                                        <i class="fas fa-sticky-note text-warning"></i>
                                                        <small><?= nl2br(htmlspecialchars(substr($item['item_notes'], 0, 50))) ?><?= strlen($item['item_notes']) > 50 ? '...' : '' ?></small>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($item['delivery_date'])): ?>
                                                    <div class="mb-1">
                                                        <i class="fas fa-calendar text-info"></i>
                                                        <small><?= date('d/m/y', strtotime($item['delivery_date'])) ?></small>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($item['received_quantity'] > 0): ?>
                                                    <div class="mb-1">
                                                        <i class="fas fa-check-circle text-success"></i>
                                                        <small>รับ: <?= number_format($item['received_quantity'], 2) ?></small>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($item['pending_quantity']) && $item['pending_quantity'] > 0): ?>
                                                    <div class="mb-1">
                                                        <i class="fas fa-hourglass text-warning"></i>
                                                        <small>คงเหลือ: <?= number_format($item['pending_quantity'], 2) ?></small>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <!-- แสดงข้อมูลหมึก/บรรจุภัณฑ์ -->
                                                    <?php if (!empty($item['package_qty']) || !empty($item['weight_per_package'])): ?>
                                                    <div class="mt-2 pt-2 border-top">
                                                        <div class="mb-1">
                                                            <i class="fas fa-cube text-primary"></i>
                                                            <small>
                                                                <?php if (!empty($item['package_qty'])): ?>
                                                                กระป๋อง: <strong><?= number_format($item['package_qty'], 0) ?></strong>
                                                                <?php else: ?>
                                                                กระป๋อง: -
                                                                <?php endif; ?>
                                                            </small>
                                                        </div>
                                                        <div class="mb-1">
                                                            <i class="fas fa-weights text-danger"></i>
                                                            <small>
                                                                <?php if (!empty($item['weight_per_package'])): ?>
                                                                น้ำ/กระป๋อง: <strong><?= number_format($item['weight_per_package'], 2) ?> kg</strong>
                                                                <?php else: ?>
                                                                น้ำ/กระป๋อง: -
                                                                <?php endif; ?>
                                                            </small>
                                                        </div>
                                                        <div>
                                                            <i class="fas fa-check-double text-success"></i>
                                                            <small>
                                                                รวม: <strong><?= number_format($item['quantity'], 2) ?> kg</strong>
                                                                <?php if (!empty($item['package_qty']) && !empty($item['weight_per_package'])): ?>
                                                                <br><small class="text-muted">(<?= number_format($item['package_qty'] * $item['weight_per_package'], 2) ?> kg)</small>
                                                                <?php endif; ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-active">
                                            <td colspan="8" class="text-end fw-bold">รวมทั้งหมด:</td>
                                            <td colspan="2" class="text-end fw-bold">฿<?= number_format($po_data['total_amount'], 2) ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Information -->
            <?php if ($po_data['is_freight_po'] && $linked_po_data): ?>
            <div class="row mt-4">
                <div class="col-12">
                    <div class="linked-po-card fade-in-up">
                        <h6 class="mb-3">
                            <i class="fas fa-link me-2"></i>PO วัตถุดิบที่เชื่อมโยง
                        </h6>
                        <div class="row">
                            <div class="col-md-3">
                                <strong>เลขที่ PO:</strong><br>
                                <a href="view.php?id=<?= $po_data['linked_po_id'] ?>" class="text-white text-decoration-none">
                                    <?= htmlspecialchars($linked_po_data['po_number']) ?>
                                </a>
                            </div>
                            <div class="col-md-3">
                                <strong>Supplier:</strong><br>
                                <?= htmlspecialchars($linked_po_data['supplier_name']) ?>
                            </div>
                            <div class="col-md-3">
                                <strong>วันที่:</strong><br>
                                <?= date('d/m/Y', strtotime($linked_po_data['po_date'])) ?>
                            </div>
                            <div class="col-md-3">
                                <strong>มูลค่า:</strong><br>
                                ฿<?= number_format($linked_po_data['total_amount'], 2) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Footer Actions (no-print) -->
        <div class="row mt-4 no-print">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-cogs me-2"></i>การดำเนินการ</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button onclick="window.print()" class="btn btn-outline-primary">
                                <i class="fas fa-print me-2"></i>พิมพ์เอกสาร
                            </button>
                            <?php if ($auth->hasRole(['editor', 'admin']) && $po_data['status'] === 'Draft'): ?>
                            <a href="edit.php?id=<?= $po_id ?>" class="btn btn-primary">
                                <i class="fas fa-edit me-2"></i>แก้ไข PO
                            </a>
                            <?php endif; ?>
                            <a href="list.php" class="btn btn-outline-secondary">
                                <i class="fas fa-list me-2"></i>กลับไปรายการ PO
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>สรุปข้อมูล</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="h6 mb-1 text-primary"><?= count($po_items) ?></div>
                                    <small>รายการ</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="h6 mb-1 text-success">฿<?= number_format($po_data['total_amount'], 0) ?></div>
                                    <small>มูลค่ารวม</small>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="h6 mb-1 text-info"><?= htmlspecialchars($po_data['currency'] ?? 'THB') ?></div>
                                    <small>สกุลเงิน</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center p-2 bg-light rounded">
                                    <div class="h6 mb-1 text-warning"><?= number_format($po_data['exchange_rate'] ?? 1, 2) ?></div>
                                    <small>อัตราแลกเปลี่ยน</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- Error State -->
        <div class="row">
            <div class="col-12">
                <div class="card fade-in-up">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                        <h4 class="text-muted">ไม่พบข้อมูล PO</h4>
                        <p class="text-muted">ไม่สามารถโหลดข้อมูล Purchase Order ได้ หรือ PO ที่ระบุไม่มีอยู่ในระบบ</p>
                        <a href="list.php" class="btn btn-primary">
                            <i class="fas fa-list me-2"></i>กลับไปรายการ PO
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Footer spacing -->
        <div class="pb-4"></div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Helper Functions
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show fade-in-up`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const container = document.querySelector('.container-fluid');
            container.insertBefore(alertDiv, container.children[1]);
            
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Print Functionality
        function printPO() {
            window.print();
        }

        // Enhanced Print Styles
        window.addEventListener('beforeprint', function() {
            document.body.classList.add('printing');
        });

        window.addEventListener('afterprint', function() {
            document.body.classList.remove('printing');
        });

        // Keyboard Shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P = Print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            
            // Ctrl+E = Edit (if available)
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                const editLink = document.querySelector('a[href*="edit.php"]');
                if (editLink) {
                    e.preventDefault();
                    window.location.href = editLink.href;
                }
            }
            
            // ESC = Back to list
            if (e.key === 'Escape') {
                window.location.href = 'list.php';
            }
        });

        // Copy PO Number to Clipboard
        document.addEventListener('DOMContentLoaded', function() {
            const poNumber = document.querySelector('.po-number');
            if (poNumber) {
                poNumber.style.cursor = 'pointer';
                poNumber.title = 'คลิกเพื่อคัดลอกเลขที่ PO';
                
                poNumber.addEventListener('click', function() {
                    navigator.clipboard.writeText(this.textContent).then(function() {
                        showAlert('คัดลอกเลขที่ PO แล้ว: ' + poNumber.textContent, 'success');
                    }).catch(function() {
                        showAlert('ไม่สามารถคัดลอกได้', 'warning');
                    });
                });
            }
        });

        // Enhanced tooltips for status badges
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap tooltips if needed
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            console.log('✅ PO View Page initialized successfully');
        });

        // Auto-refresh for development (remove in production)
        <?php if (defined('DEBUG_MODE') && DEBUG_MODE === true): ?>
        console.log('🔍 Debug Mode: PO View Page');
        console.log('PO ID: <?= $po_id ?>');
        console.log('PO Data: <?= json_encode($po_data) ?>');
        console.log('Items Count: <?= count($po_items) ?>');
        <?php endif; ?>
    </script>
</body>
</html>