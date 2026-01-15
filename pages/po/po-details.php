<?php
// po-details.php - Purchase Order Details Page
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Auth.php';

// Initialize authentication
$auth = new Auth();
$auth->requireLogin();

// Get PO number from URL
$po_number = $_GET['po'] ?? '';
if (empty($po_number)) {
    header('Location: receiving.php');
    exit;
}

// Initialize database connection
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
    die("Database connection failed. Please check server configuration.");
}

// ฟังก์ชันดึงข้อมูล PO แบบละเอียด
function getPODetailData($po_number) {
    global $pdo;
    
    try {
        // Query ดึงข้อมูล PO Header
        $query = "
            SELECT 
                ph.*,
                ISNULL(s.supplier_name, 'Unknown Supplier') as supplier_name,
                ISNULL(s.contact_person, '') as contact_person,
                ISNULL(s.phone, '') as phone,
                ISNULL(s.email, '') as email,
                ISNULL(s.address, '') as address,
                ISNULL(u_created.full_name, 'System') as created_by_name,
                ISNULL(u_approved.full_name, 'ยังไม่อนุมัติ') as approved_by_name
            FROM PO_Header ph
            LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
            LEFT JOIN Users u_created ON ph.created_by = u_created.user_id
            LEFT JOIN Users u_approved ON ph.approved_by = u_approved.user_id
            WHERE ph.po_number = ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$po_number]);
        $po_data = $stmt->fetch();
        
        if (!$po_data) {
            return null;
        }
        
        // ดึงรายการสินค้า
        $items_query = "
            SELECT 
                pi.*,
                ISNULL(mp.SSP_Code, 'N/A') as SSP_Code,
                ISNULL(mp.Name, pi.item_description) as product_name,
                ISNULL(u_purchase.unit_name_th, 'หน่วย') as purchase_unit,
                ISNULL(u_stock.unit_name_th, 'หน่วย') as stock_unit,
                ISNULL(pi.quantity, 0) as quantity,
                ISNULL(gri.quantity_received, 0) as received_quantity_kg,
                ISNULL(gri.stock_quantity, 0) as received_quantity_sheet,
                ISNULL(u_received.unit_name_th, u_purchase.unit_name_th) as received_unit
            FROM PO_Items pi
            LEFT JOIN Master_Products_ID mp ON pi.product_id = mp.id
            LEFT JOIN Units u_purchase ON pi.purchase_unit_id = u_purchase.unit_id
            LEFT JOIN Units u_stock ON pi.stock_unit_id = u_stock.unit_id
            LEFT JOIN Goods_Receipt_Items gri ON pi.po_item_id = gri.po_item_id
            LEFT JOIN Units u_received ON gri.received_unit_id = u_received.unit_id
            WHERE pi.po_id = ?
            ORDER BY pi.line_number
        ";
        
        $stmt = $pdo->prepare($items_query);
        $stmt->execute([$po_data['po_id']]);
        $items = $stmt->fetchAll();
        
        // ดึงประวัติการรับเข้า
        $receipt_query = "
            SELECT 
                gr.gr_number,
                gr.receipt_date,
                gr.total_quantity,
                gr.total_amount,
                gr.status,
                gr.notes,
                ISNULL(u.full_name, 'System') as received_by_name
            FROM Goods_Receipt gr
            LEFT JOIN Users u ON gr.received_by = u.user_id
            WHERE gr.po_id = ?
            ORDER BY gr.receipt_date DESC
        ";
        
        $stmt = $pdo->prepare($receipt_query);
        $stmt->execute([$po_data['po_id']]);
        $receipts = $stmt->fetchAll();
        
        return [
            'po_data' => $po_data,
            'items' => $items,
            'receipts' => $receipts
        ];
        
    } catch (PDOException $e) {
        error_log("Error getting PO details: " . $e->getMessage());
        return null;
    }
}

// ดึงข้อมูล PO
$po_details = getPODetailData($po_number);

if (!$po_details) {
    echo "<div class='alert alert-danger'>ไม่พบข้อมูล PO หมายเลข: " . htmlspecialchars($po_number) . "</div>";
    exit;
}

$po_data = $po_details['po_data'];
$items = $po_details['items'];
$receipts = $po_details['receipts'];

// คำนวณสถิติ
$total_items = count($items);
$total_ordered = array_sum(array_column($items, 'quantity'));
$total_received = array_sum(array_column($items, 'received_quantity'));
$completion_percentage = $total_ordered > 0 ? round(($total_received / $total_ordered) * 100, 0) : 0;

// กำหนดสถานะ
if ($total_received == 0) {
    $receipt_status = 'pending';
    $status_text = 'รอรับเข้า';
    $status_class = 'status-pending';
} elseif ($total_received < $total_ordered) {
    $receipt_status = 'partial';
    $status_text = 'รับบางส่วน';
    $status_class = 'status-partial';
} else {
    $receipt_status = 'complete';
    $status_text = 'รับครบแล้ว';
    $status_class = 'status-complete';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียด PO - <?php echo htmlspecialchars($po_number); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
        
        .nav-link:hover {
            color: #FFD700 !important;
        }
        
        .detail-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(139, 69, 19, 0.15);
            border: 1px solid rgba(139, 69, 19, 0.1);
        }
        
        .po-status {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            display: inline-block;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-partial {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-complete {
            background: #d1fae5;
            color: #059669;
        }
        
        .progress {
            height: 8px;
            border-radius: 10px;
            background-color: #e5e7eb;
        }
        
        .progress-bar {
            border-radius: 10px;
        }
        
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
            background: linear-gradient(135deg, #A0522D, #8B4513);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(139, 69, 19, 0.3);
            color: white;
        }
        
        .table {
            background: rgba(255, 255, 255, 0.8);
        }
        
        .table th {
            background: var(--primary-color);
            color: white;
            border: none;
            font-weight: bold;
        }
        
        .table td {
            border-color: rgba(139, 69, 19, 0.1);
            vertical-align: middle;
        }
        
        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 20px;
        }
        
        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            color: var(--secondary-color);
        }
        
        .breadcrumb-item.active {
            color: var(--accent-color);
        }
        
        .breadcrumb-item + .breadcrumb-item::before {
            color: var(--accent-color);
        }

        .info-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(139, 69, 19, 0.1);
            border: 1px solid rgba(139, 69, 19, 0.1);
        }

        .stats-card {
            text-align: center;
            padding: 20px;
            border-radius: 15px;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 5px 15px rgba(139, 69, 19, 0.1);
        }

        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary-color);
        }

        .stats-label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <button class="btn btn-link text-white me-3" onclick="history.back()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div>
                    <h5 class="navbar-brand mb-0">
                        <i class="fas fa-file-invoice me-2"></i>รายละเอียด PO
                    </h5>
                    <small class="text-light">ข้อมูลรายละเอียด Purchase Order</small>
                </div>
            </div>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="receiving.php">
                    <i class="fas fa-clipboard-check me-1"></i> รับเข้าสินค้า
                </a>
                <a class="nav-link" href="#">
                    <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                </a>
                <a class="nav-link" href="../../logout.php">
                    <i class="fas fa-sign-out-alt me-1"></i> ออกจากระบบ
                </a>
            </div>
        </div>
    </nav>

    <!-- Breadcrumb -->
    <div class="container-fluid mt-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../../index.php">หน้าหลัก</a></li>
                <li class="breadcrumb-item"><a href="#">คลังสินค้า</a></li>
                <li class="breadcrumb-item"><a href="receiving.php">รับเข้าสินค้า</a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($po_number); ?></li>
            </ol>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="container-fluid">
        <!-- PO Header -->
        <div class="detail-section">
            <div class="row">
                <div class="col-md-8">
                    <h4 class="fw-bold mb-3">
                        <i class="fas fa-file-invoice me-2"></i><?php echo htmlspecialchars($po_number); ?>
                        <span class="po-status <?php echo $status_class; ?> ms-3"><?php echo $status_text; ?></span>
                    </h4>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-card">
                                <h6 class="fw-bold mb-3 text-primary">ข้อมูล PO</h6>
                                <p class="mb-2"><strong>วันที่สั่งซื้อ:</strong> <?php echo formatDate($po_data['po_date']); ?></p>
                                <p class="mb-2"><strong>วันที่ส่งมอบ:</strong> <?php echo formatDate($po_data['delivery_date']); ?></p>
                                <p class="mb-2"><strong>สถานะ:</strong> <?php echo htmlspecialchars($po_data['status']); ?></p>
                                <p class="mb-2"><strong>ผู้สร้าง:</strong> <?php echo htmlspecialchars($po_data['created_by_name']); ?></p>
                                <p class="mb-0"><strong>ผู้อนุมัติ:</strong> <?php echo htmlspecialchars($po_data['approved_by_name']); ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-card">
                                <h6 class="fw-bold mb-3 text-success">ข้อมูลซัพพลายเออร์</h6>
                                <p class="mb-2"><strong>ชื่อบริษัท:</strong> <?php echo htmlspecialchars($po_data['supplier_name']); ?></p>
                                <?php if (!empty($po_data['contact_person'])): ?>
                                <p class="mb-2"><strong>ผู้ติดต่อ:</strong> <?php echo htmlspecialchars($po_data['contact_person']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($po_data['phone'])): ?>
                                <p class="mb-2"><strong>เบอร์โทร:</strong> <?php echo htmlspecialchars($po_data['phone']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($po_data['email'])): ?>
                                <p class="mb-0"><strong>อีเมล:</strong> <?php echo htmlspecialchars($po_data['email']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="stats-card">
                                <div class="stats-number"><?php echo number_format($total_items); ?></div>
                                <div class="stats-label">รายการ</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stats-card">
                                <div class="stats-number"><?php echo $completion_percentage; ?>%</div>
                                <div class="stats-label">ความคืบหน้า</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="stats-card">
                                <div class="stats-number text-success"><?php echo number_format($po_data['total_amount'], 2); ?></div>
                                <div class="stats-label">มูลค่ารวม (บาท)</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Progress Section -->
        <div class="detail-section">
            <h6 class="fw-bold mb-3">
                <i class="fas fa-chart-line me-2"></i>ความคืบหน้าการรับเข้า
            </h6>
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar <?php 
                            echo $receipt_status === 'pending' ? 'bg-warning' : 
                                ($receipt_status === 'partial' ? 'bg-primary' : 'bg-success'); 
                        ?>" style="width: <?php echo $completion_percentage; ?>%"></div>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <span class="fw-bold"><?php echo number_format($total_received); ?> / <?php echo number_format($total_ordered); ?> รายการ</span>
                </div>
            </div>
        </div>

        <!-- Items List -->
        <div class="detail-section">
            <h6 class="fw-bold mb-3">
                <i class="fas fa-list me-2"></i>รายการสินค้า
            </h6>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th width="5%">ลำดับ</th>
                            <th width="15%">รหัสสินค้า</th>
                            <th width="25%">รายละเอียด</th>
                            <th width="10%">หน่วยสั่งซื้อ</th>
                            <th width="10%">จำนวนสั่ง</th>
                            <th width="15%">รับแล้ว (หน่วยคลัง)</th>
                            <th width="10%">ราคา/หน่วย</th>
                            <th width="10%">รวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $index => $item): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($item['SSP_Code']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                <?php if (!empty($item['item_description']) && $item['item_description'] != $item['product_name']): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($item['item_description']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($item['purchase_unit']); ?></td>
                            <td><?php echo number_format($item['quantity']); ?></td>
                            <td>
                                <span class="<?php echo $item['received_quantity_sheet'] > 0 ? 'text-success fw-bold' : 'text-muted'; ?>">
                                    <?php echo number_format($item['received_quantity_sheet'], 0); ?> แผ่น
                                    <?php if ($item['received_unit'] === 'กิโลกรัม'): ?>
                                        <small class="text-muted d-block">
                                            (<?php echo number_format($item['received_quantity_kg'], 4); ?> กิโลกรัม)
                                        </small>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td><?php echo number_format($item['unit_price'], 2); ?></td>
                            <td><?php echo number_format($item['total_price'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-secondary">
                            <th colspan="4">รวมทั้งหมด</th>
                            <th><?php echo number_format($total_ordered); ?></th>
                            <th><?php echo number_format($total_received); ?></th>
                            <th></th>
                            <th><?php echo number_format($po_data['total_amount'], 2); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Receipt History -->
        <?php if (!empty($receipts)): ?>
        <div class="detail-section">
            <h6 class="fw-bold mb-3">
                <i class="fas fa-history me-2"></i>ประวัติการรับเข้า
            </h6>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>เลขที่ใบรับ</th>
                            <th>วันที่รับ</th>
                            <th>จำนวน</th>
                            <th>มูลค่า (บาท)</th>
                            <th>ผู้รับ</th>
                            <th>สถานะ</th>
                            <th>หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($receipts as $receipt): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($receipt['gr_number']); ?></strong></td>
                            <td><?php echo formatDate($receipt['receipt_date']); ?></td>
                            <td><?php echo number_format($receipt['total_quantity']); ?></td>
                            <td><?php echo number_format($receipt['total_amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($receipt['received_by_name']); ?></td>
                            <td>
                                <span class="badge bg-success"><?php echo htmlspecialchars($receipt['status']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($receipt['notes'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="detail-section text-center">
            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
            <h6 class="text-muted">ยังไม่มีประวัติการรับเข้า</h6>
            <p class="text-muted">PO นี้ยังไม่เคยมีการรับเข้าสินค้า</p>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="detail-section">
            <div class="d-flex justify-content-between">
                <button class="btn btn-outline-secondary" onclick="history.back()">
                    <i class="fas fa-arrow-left me-2"></i>ย้อนกลับ
                </button>
                
                <?php if ($receipt_status !== 'complete'): ?>
                <button class="btn btn-success" onclick="window.location.href='receiving.php?search=<?php echo urlencode($po_number); ?>'">
                    <i class="fas fa-plus me-2"></i>รับเข้าสินค้า
                </button>
                <?php else: ?>
                <button class="btn btn-outline-success" disabled>
                    <i class="fas fa-check me-2"></i>รับเข้าครบแล้ว
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>