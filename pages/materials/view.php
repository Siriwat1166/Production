<?php
// pages/materials/view.php - หน้าดูรายละเอียดวัสดุ (ส่วนที่แก้ไข)
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();

// เชื่อมต่อฐานข้อมูล
try {
    require_once "../../config/database.php";
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ตรวจสอบ ID
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($product_id <= 0) {
    header("Location: list.php");
    exit;
}

// Constants for group IDs
// Paperboard มีหลาย subgroups
define('GROUP_PAPERBOARD_501', 501);  // กระดาษเคลือบ 1 หน้า
define('GROUP_PAPERBOARD_551', 551);  // กระดาษเคลือบ 2 หน้า
define('GROUP_PAPERBOARD_801', 801);  // กระดาษบรรจุภัณฑ์ 801
define('GROUP_PAPERBOARD_802', 802);  // กระดาษบรรจุภัณฑ์ 802
define('GROUP_PAPERBOARD_803', 803);  // กระดาษบรรจุภัณฑ์ 803
define('GROUP_PAPERBOARD_804', 804);  // กระดาษบรรจุภัณฑ์ 804

// Constants for group IDs
define('GROUP_PAPERBOARD', 551);
define('GROUP_INK', 553);
define('GROUP_COATING', 557);
define('GROUP_ADHESIVE', 556);
define('GROUP_FILM', 714);
define('GROUP_CORRUGATED', 260);
define('GROUP_FOIL', 555);
define('GROUP_PLATE', 262);

$PAPERBOARD_GROUPS = [501, 551, 801, 802, 803, 804];

$material = null;
$specific_data = null;
$group_id = null;

try {
    // ดึงข้อมูลหลัก
$stmt = $conn->prepare("
    SELECT 
        mp.*,
        mt.type_name,
        mt.type_code,
        g.name AS group_name,
        s.supplier_name,
        s.supplier_code,
        un.unit_code    AS unit_code,
        un.unit_name    AS unit_name,
        un.unit_name_th AS unit_name_th,
        un.unit_symbol  AS unit_symbol,
        u1.full_name AS created_by_name,
        u2.full_name AS updated_by_name
    FROM Master_Products_ID mp
    LEFT JOIN Material_Types mt ON mp.material_type_id = mt.material_type_id
    LEFT JOIN Groups g         ON mp.group_id = g.id
    LEFT JOIN Suppliers s      ON mp.supplier_id = s.supplier_id
    LEFT JOIN Units un         ON mp.Unit_id = un.unit_id          -- <<< เพิ่มบรรทัดนี้
    LEFT JOIN Users u1         ON mp.created_by = u1.user_id
    LEFT JOIN Users u2         ON mp.updated_by = u2.user_id
    WHERE mp.id = ? AND mp.is_active = 1
");

    
    $stmt->execute([$product_id]);
    $material = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$material) {
        throw new Exception("ไม่พบข้อมูลวัสดุหรือถูกลบแล้ว");
    }
    
    $group_id = (int)$material['group_id'];
    
    // ดึงข้อมูลเฉพาะตาม group_id
switch ($group_id) {
    case GROUP_PAPERBOARD:
        // ✅ แก้ไข: ใช้ตาราง Groups แทน Paper_Subgroups ที่ไม่มีอยู่
        $stmt = $conn->prepare("
            SELECT sp.*, 
                   g.name as subgroup_name,
                   g.description as subgroup_description
            FROM Specific_Paperboard sp
            LEFT JOIN Groups g ON sp.paper_subgroup_id = g.id
            WHERE sp.product_id = ? AND sp.is_active = 1
        ");
            break;
        case GROUP_INK:
            $stmt = $conn->prepare("SELECT * FROM Specific_Ink WHERE product_id = ? AND is_active = 1");
            break;
        case GROUP_COATING:
            $stmt = $conn->prepare("SELECT * FROM Specific_Coating WHERE product_id = ? AND is_active = 1");
            break;
        case GROUP_ADHESIVE:
            $stmt = $conn->prepare("SELECT * FROM Specific_Adhesive WHERE product_id = ? AND is_active = 1");
            break;
        case GROUP_FILM:
            $stmt = $conn->prepare("SELECT * FROM Specific_Film WHERE product_id = ? AND is_active = 1");
            break;
        case GROUP_CORRUGATED:
            $stmt = $conn->prepare("SELECT * FROM Specific_Corrugated_box WHERE product_id = ? AND is_active = 1");
            break;
        case GROUP_FOIL:
            $stmt = $conn->prepare("SELECT * FROM Specific_Foil WHERE product_id = ? AND is_active = 1");
            break;
        case GROUP_PLATE:
            $stmt = $conn->prepare("SELECT * FROM Specific_Plate WHERE product_id = ? AND is_active = 1");
            break;
        default:
            $stmt = null;
            break;
    }
    
    if ($stmt) {
        $stmt->execute([$product_id]);
        $specific_data = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    error_log("Error loading material: " . $e->getMessage());
    $error_message = $e->getMessage();
}

// Helper function สำหรับแสดงกลุ่มย่อยกระดาษ
function displayPaperSubgroup($specific_data) {
    // ตรวจสอบทั้ง paper_subgroup_id และ paper_subgroup
    $subgroup_id = $specific_data['paper_subgroup_id'] ?? null;
    $subgroup_name = $specific_data['subgroup_name'] ?? $specific_data['paper_subgroup'] ?? null;
    $subgroup_desc = $specific_data['subgroup_description'] ?? null;
    
    if (empty($subgroup_id) && empty($subgroup_name)) {
        return '<span class="text-muted">ไม่ได้ระบุกลุ่มย่อย</span>';
    }
    
    $output = '';
    
    // แสดงชื่อกลุ่มย่อย
    if (!empty($subgroup_name)) {
        $output .= '<div class="fw-bold">' . sanitizeOutput($subgroup_name) . '</div>';
    }
    
    // แสดงคำอธิบาย (ถ้ามี)
    if (!empty($subgroup_desc)) {
        $output .= '<small class="text-muted">' . sanitizeOutput($subgroup_desc) . '</small>';
    }
    
    // แสดงรหัสกลุ่มย่อย
    if (!empty($subgroup_id)) {
        $output .= ' <span class="badge bg-secondary">' . str_pad($subgroup_id, 3, '0', STR_PAD_LEFT) . '</span>';
    }
    
    return $output ?: '<span class="text-muted">-</span>';
}
function displayPaperSubgroupSimple($specific_data) {
    $subgroup_id = $specific_data['paper_subgroup_id'] ?? null;
    $subgroup_text = $specific_data['paper_subgroup'] ?? null;
    
    if (empty($subgroup_id) && empty($subgroup_text)) {
        return '<span class="text-muted">ไม่ได้ระบุกลุ่มย่อย</span>';
    }
    
    $output = '';
    
    // แสดงข้อความจากฟิลด์ paper_subgroup
    if (!empty($subgroup_text)) {
        $output .= sanitizeOutput($subgroup_text);
    }
    
    // แสดงรหัส ID
    if (!empty($subgroup_id)) {
        $output .= ' <span class="badge bg-info">' . str_pad($subgroup_id, 3, '0', STR_PAD_LEFT) . '</span>';
    }
    
    // ถ้าไม่มีข้อมูลใดๆ แต่มี ID
    if (empty($output) && !empty($subgroup_id)) {
        // แปลง ID เป็นชื่อกลุ่มย่อยตามที่เห็นในตาราง Groups
        $subgroup_names = [
            501 => 'กระดาษเคลือบ 1 หน้า',
            551 => 'กระดาษเคลือบ 2 หน้า', 
            801 => 'กระดาษบรรจุภัณฑ์ 801',
            802 => 'กระดาษบรรจุภัณฑ์ 802',
            803 => 'กระดาษบรรจุภัณฑ์ 803',
            804 => 'กระดาษบรรจุภัณฑ์ 804'
        ];
        
        $name = $subgroup_names[$subgroup_id] ?? 'กลุ่มย่อย ' . $subgroup_id;
        $output = $name . ' <span class="badge bg-info">' . str_pad($subgroup_id, 3, '0', STR_PAD_LEFT) . '</span>';
    }
    
    return $output ?: '<span class="text-muted">-</span>';
}

// Helper functions อื่นๆ (เหมือนเดิม)
function sanitizeOutput($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatValue($value, $unit = '', $decimals = 2) {
    if ($value === null || $value === '') {
        return '<span class="text-muted">-</span>';
    }
    
    if (is_numeric($value)) {
        return number_format((float)$value, $decimals) . ($unit ? ' ' . $unit : '');
    }
    
    return sanitizeOutput($value) . ($unit ? ' ' . $unit : '');
}

function displayDate($date) {
    if (empty($date)) {
        return '<span class="text-muted">-</span>';
    }
    return date('d/m/Y H:i:s', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดวัสดุ - <?= sanitizeOutput(APP_NAME ?? 'Material Management') ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
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
            --primary-gradient-dark: linear-gradient(135deg, #A0522D, #8B4513);
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
        
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(139, 69, 19, 0.15);
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(139, 69, 19, 0.1);
            margin-bottom: 25px;
        }
        
        .card-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 18px 18px 0 0 !important;
            border-bottom: none;
        }
        
        .btn-primary, .btn-warning, .btn-danger, .btn-success,
        .btn-outline-primary, .btn-outline-secondary, .btn-outline-info, 
        .btn-outline-success, .btn-outline-warning, .btn-outline-danger {
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            border: none;
        }
        
        .btn-primary:hover {
            background: var(--primary-gradient-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(139, 69, 19, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #10b981);
            border: none;
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #10b981, var(--success-color));
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(5, 150, 105, 0.3);
        }
        
        .btn-outline-primary {
            border-color: var(--primary-color);
            color: var(--primary-color);
            padding: 8px 20px;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #f59e0b);
            border: none;
            color: white;
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #f59e0b, var(--warning-color));
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(217, 119, 6, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #ef4444);
            border: none;
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #ef4444, var(--danger-color));
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(220, 38, 38, 0.3);
        }

        .btn-outline-secondary {
            border-color: var(--accent-color);
            color: var(--accent-color);
            padding: 8px 20px;
        }

        .btn-outline-secondary:hover {
            background: linear-gradient(135deg, var(--accent-color), var(--primary-color));
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
        }

        .btn-outline-info {
            border-color: #0ea5e9;
            color: #0ea5e9;
            padding: 8px 20px;
        }

        .btn-outline-info:hover {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
        }

        .btn-outline-success {
            border-color: var(--success-color);
            color: var(--success-color);
            padding: 8px 20px;
        }

        .btn-outline-success:hover {
            background: linear-gradient(135deg, var(--success-color), #10b981);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
        }

        .btn-outline-warning {
            border-color: var(--warning-color);
            color: var(--warning-color);
            padding: 8px 20px;
        }

        .btn-outline-warning:hover {
            background: linear-gradient(135deg, var(--warning-color), #f59e0b);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
        }

        .btn-outline-danger {
            border-color: var(--danger-color);
            color: var(--danger-color);
            padding: 8px 20px;
        }

        .btn-outline-danger:hover {
            background: linear-gradient(135deg, var(--danger-color), #ef4444);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
        }
        
        .ssp-code {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: var(--primary-color);
            font-size: 1.5rem;
            letter-spacing: 2px;
            text-align: center;
            padding: 15px;
            background: linear-gradient(135deg, #F5DEB3, #DEB887);
            border: 2px dashed var(--accent-color);
            border-radius: 15px;
            margin: 20px 0;
        }
        
        .badge {
            border-radius: 10px;
            font-size: 0.9em;
            padding: 8px 15px;
        }
        
        .badge-active {
            background: linear-gradient(45deg, var(--success-color), #10b981);
        }
        
        .badge-inactive {
            background: linear-gradient(45deg, #6c757d, #8d9499);
        }
        
        .info-table th {
            background: rgba(139, 69, 19, 0.1);
            color: var(--primary-color);
            font-weight: bold;
            border: none;
            padding: 15px;
            width: 40%;
            vertical-align: middle;
        }
        
        .info-table td {
            padding: 15px;
            border: none;
            border-bottom: 1px solid rgba(139, 69, 19, 0.1);
        }
        
        .info-table tr:last-child td,
        .info-table tr:last-child th {
            border-bottom: none;
        }
        
        .section-title {
            color: var(--primary-color);
            border-bottom: 3px solid var(--accent-color);
            padding-bottom: 10px;
            margin-bottom: 25px;
            font-weight: bold;
        }
        
        .alert {
            border-radius: 15px;
            border: none;
        }

        .alert-info {
            background: rgba(139, 69, 19, 0.1);
            color: var(--primary-color);
            border-left: 4px solid var(--accent-color);
        }
        
        .action-buttons {
            position: sticky;
            top: 20px;
            z-index: 1000;
        }
        
        .action-buttons .card {
            margin-bottom: 0;
        }
        
        .calculated-badge {
            background: linear-gradient(45deg, #0ea5e9, #0284c7);
            color: white;
            font-size: 0.8em;
            margin-left: 10px;
        }
        
        .print-hidden {
            display: block;
        }
        
        @media print {
            .print-hidden {
                display: none !important;
            }
            
            body {
                background: white !important;
            }
            
            .card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                margin-bottom: 20px !important;
            }
            
            .navbar {
                display: none !important;
            }
            
            .action-buttons {
                display: none !important;
            }
        }
        
        .highlight-value {
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .meta-info {
            background: rgba(245, 222, 179, 0.8);
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
        }
        
        .meta-info small {
            color: var(--accent-color);
        }

        .text-primary {
            color: var(--primary-color) !important;
        }

        .text-muted {
            color: var(--accent-color) !important;
        }

        .navbar-brand, .nav-link {
            color: white !important;
        }

        .nav-link:hover {
            color: #FFD700 !important;
            transform: translateY(-2px);
            transition: all 0.3s ease;
        }

        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 40px rgba(139, 69, 19, 0.3);
        }

        .modal-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 20px 20px 0 0;
            border-bottom: none;
        }

        .modal-footer {
            border-top: 1px solid rgba(139, 69, 19, 0.1);
            border-radius: 0 0 20px 20px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark print-hidden">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="../dashboard.php">
                <i class="fas fa-boxes me-2"></i><?= sanitizeOutput(APP_NAME ?? 'Material Management') ?>
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="list.php">
                    <i class="fas fa-list me-1"></i> รายการวัสดุ
                </a>
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container-fluid mt-4">
        <?php if (isset($error_message)): ?>
        <!-- Error Message -->
        <div class="card">
            <div class="card-body text-center">
                <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                <h4>ไม่พบข้อมูล</h4>
                <p class="text-muted"><?= sanitizeOutput($error_message) ?></p>
                <a href="list.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-2"></i>กลับสู่รายการ
                </a>
            </div>
        </div>
        <?php else: ?>
        
        <div class="row">
            <!-- Action Buttons (Sticky) -->
            <div class="col-lg-3 print-hidden">
                <div class="action-buttons">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-cogs me-2"></i>การจัดการ
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="list.php" class="btn btn-outline-primary">
                                    <i class="fas fa-arrow-left me-2"></i>กลับสู่รายการ
                                </a>
                                
                                <?php if ($auth->hasRole('editor')): ?>
                                <a href="edit.php?id=<?= $product_id ?>" class="btn btn-warning">
                                    <i class="fas fa-edit me-2"></i>แก้ไข
                                </a>
                                
                                <button type="button" class="btn btn-danger" 
                                        onclick="confirmDelete(<?= $product_id ?>, '<?= sanitizeOutput($material['SSP_Code']) ?>')">
                                    <i class="fas fa-trash me-2"></i>ลบ
                                </button>
                                
                                <hr>
                                
                                <button type="button" class="btn btn-outline-secondary" onclick="duplicateMaterial()">
                                    <i class="fas fa-copy me-2"></i>ทำสำเนา
                                </button>
                                <?php endif; ?>
                                
                                <button type="button" class="btn btn-outline-info" onclick="printMaterial()">
                                    <i class="fas fa-print me-2"></i>พิมพ์
                                </button>
                                
                                <button type="button" class="btn btn-outline-success" onclick="exportMaterial()">
                                    <i class="fas fa-download me-2"></i>Export
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Info -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <i class="fas fa-info-circle me-2"></i>ข้อมูลด่วน
                            </h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>สถานะ:</strong></td>
                                    <td>
                                        <span class="badge <?= $material['status'] == 1 ? 'badge-active' : 'badge-inactive' ?>">
                                            <?= $material['status'] == 1 ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>ประเภท:</strong></td>
                                    <td><?= sanitizeOutput($material['type_name']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>กลุ่ม:</strong></td>
                                    <td><?= sanitizeOutput($material['group_name']) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Run Number:</strong></td>
                                    <td><?= sanitizeOutput($material['run_number']) ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-9">
                <!-- Header Card -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-eye me-2"></i>รายละเอียดวัสดุ
                        </h4>
                    </div>
                    <div class="card-body">
                        <!-- SSP Code Display -->
                        <div class="ssp-code">
                            <?= sanitizeOutput($material['SSP_Code']) ?>
                        </div>
                        
                        <!-- Material Names -->
                        <div class="text-center">
                            <h3 class="text-primary mb-2"><?= sanitizeOutput($material['Name']) ?></h3>
                            <?php if (!empty($material['Name2'])): ?>
                            <h5 class="text-muted"><?= sanitizeOutput($material['Name2']) ?></h5>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Basic Information -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-info-circle me-2"></i>ข้อมูลพื้นฐาน
                        </h5>
                    </div>
                    <div class="card-body">
                        <table class="table info-table">
                            <tr>
                                <th>SSP Code</th>
                                <td><span class="highlight-value"><?= sanitizeOutput($material['SSP_Code']) ?></span></td>
                            </tr>
                            <tr>
                                <th>ชื่อวัสดุ (ไทย)</th>
                                <td><?= sanitizeOutput($material['Name']) ?></td>
                            </tr>
                            <?php if (!empty($material['Name2'])): ?>
                            <tr>
                                <th>ชื่อวัสดุ (อังกฤษ)</th>
                                <td><?= sanitizeOutput($material['Name2']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>ประเภทวัสดุ</th>
                                <td>
                                    <?= sanitizeOutput($material['type_name']) ?>
                                    <span class="badge bg-secondary"><?= sanitizeOutput($material['type_code']) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th>กลุ่ม</th>
                                <td>
                                    <?= sanitizeOutput($material['group_name']) ?>
                                    <span class="badge bg-info"><?= str_pad($material['group_id'], 3, '0', STR_PAD_LEFT) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th>ซัพพลายเออร์</th>
                                <td>
                                    <?= sanitizeOutput($material['supplier_name']) ?>
                                    <span class="badge bg-warning text-dark"><?= sanitizeOutput($material['supplier_code']) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th>Run Number</th>
                                <td><span class="highlight-value"><?= sanitizeOutput($material['run_number']) ?></span></td>
                            </tr>
                            <tr>
    <th>หน่วย</th>
    <td>
        <?php
        if (!empty($material['unit_name_th']) || !empty($material['unit_name']) || !empty($material['unit_code'])) {
            $unitTH = $material['unit_name_th'] ?: $material['unit_name'] ?: $material['unit_code'];
            $unitSym = $material['unit_symbol'] ?: $material['unit_code'];
            
            // แสดง badge เฉพาะเมื่อ symbol/code ต่างจากชื่อหน่วย
            if ($unitSym && $unitSym !== $unitTH) {
                echo sanitizeOutput($unitTH) . ' <span class="badge bg-secondary">' . sanitizeOutput($unitSym) . '</span>';
            } else {
                echo '<span class="badge bg-secondary">' . sanitizeOutput($unitTH) . '</span>';
            }
        } else {
            echo '<span class="text-muted">-</span>';
        }
        ?>
    </td>
</tr>

                            <tr>
                                <th>สถานะ</th>
                                <td>
                                    <span class="badge <?= $material['status'] == 1 ? 'badge-active' : 'badge-inactive' ?>">
                                        <?= $material['status'] == 1 ? 'ใช้งาน' : 'ไม่ใช้งาน' ?>
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Specific Data based on Group -->
                <?php if ($specific_data): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-clipboard-list me-2"></i>
                            ข้อมูลจำเพาะ - <?= sanitizeOutput($material['group_name']) ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php switch ($group_id): 
                            case GROUP_PAPERBOARD: ?>
                            <!-- Paperboard Data -->
                            <h6 class="section-title">ข้อมูลกระดาษ</h6>
                            <table class="table info-table">
                                <tr>
                                    <th>ประเภทกระดาษ (ไทย)</th>
                                    <td><?= formatValue($specific_data['type_paperboard_TH']) ?></td>
                                </tr>
                                <tr>
                                    <th>ประเภทกระดาษ (อังกฤษ)</th>
                                    <td><?= formatValue($specific_data['type_paperboard_EN']) ?></td>
                                </tr>
                                <tr>
                                    <th>แบรนด์</th>
                                    <td><?= formatValue($specific_data['brand']) ?></td>
                                </tr>
                                <tr>
                                    <th>GSM</th>
                                    <td>
                                        <?= formatValue($specific_data['gsm'], 'g/m²', 0) ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Caliper</th>
                                    <td><?= formatValue($specific_data['Caliper'], 'μm', 0) ?></td>
                                </tr>

<tr>
    <th>กลุ่มย่อยกระดาษ</th>
    <td><?= displayPaperSubgroup($specific_data) ?></td>
</tr>
                            </table>
                            
                            <h6 class="section-title">ขนาด</h6>
                            <table class="table info-table">
                                <tr>
                                    <th>ความกว้าง (มม.)</th>
                                    <td><?= formatValue($specific_data['W_mm'], 'mm') ?></td>
                                </tr>
                                <tr>
                                    <th>ความยาว (มม.)</th>
                                    <td><?= formatValue($specific_data['L_mm'], 'mm') ?></td>
                                </tr>
                                <tr>
                                    <th>ความกว้าง (นิ้ว)</th>
                                    <td>
                                        <?= formatValue($specific_data['W_inch'], 'inch', 4) ?>
                                        <span class="calculated-badge badge">คำนวณ</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>ความยาว (นิ้ว)</th>
                                    <td>
                                        <?= formatValue($specific_data['L_inch'], 'inch', 4) ?>
                                        <span class="calculated-badge badge">คำนวณ</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>น้ำหนักต่อแผ่น</th>
                                    <td>
                                        <?= formatValue($specific_data['Weight_kg_per_sheet'], 'kg', 4) ?>
                                        <span class="calculated-badge badge">คำนวณ</span>
                                    </td>
                                </tr>
                            </table>
                            
                            <h6 class="section-title">การเคลือบผิว</h6>
                            <table class="table info-table">
                                <tr>
                                    <th>Laminated 1</th>
                                    <td><?= formatValue($specific_data['laminated1']) ?></td>
                                </tr>
                                <tr>
                                    <th>Laminated 2</th>
                                    <td><?= formatValue($specific_data['laminated2']) ?></td>
                                </tr>
                                <tr>
                                    <th>การรับรอง</th>
                                    <td><?= formatValue($specific_data['Certificated']) ?></td>
                                </tr>
                            </table>
                            
                            <?php break;
                            
                        case GROUP_INK: ?>
                            <!-- Ink Data -->
                            <h6 class="section-title">ข้อมูลหมึกพิมพ์</h6>
                            <table class="table info-table">
                                <tr>
                                    <th>ประเภทหมึก</th>
                                    <td><?= formatValue($specific_data['ink_type']) ?></td>
                                </tr>
                                <tr>
                                    <th>สี</th>
                                    <td><?= formatValue($specific_data['Color']) ?></td>
                                </tr>
                                <tr>
                                    <th>กลุ่มหมึก</th>
                                    <td><?= formatValue($specific_data['Ink_Group']) ?></td>
                                </tr>
                                <tr>
                                    <th>ด้านที่พิมพ์</th>
                                    <td><?= formatValue($specific_data['Side']) ?></td>
                                </tr>
                            </table>
                            
                            <h6 class="section-title">ข้อมูลกระดาษที่ใช้</h6>
                            <table class="table info-table">
                                <tr>
                                    <th>แบรนด์กระดาษ</th>
                                    <td><?= formatValue($specific_data['Brand_paperboard']) ?></td>
                                </tr>
                                <tr>
                                    <th>ประเภทกระดาษ</th>
                                    <td><?= formatValue($specific_data['type_paperboard']) ?></td>
                                </tr>
                                <tr>
                                    <th>GSM</th>
                                    <td><?= formatValue($specific_data['gsm'], 'g/m²', 0) ?></td>
                                </tr>
                            </table>
                            
                            <h6 class="section-title">การเคลือบผิว</h6>
                            <table class="table info-table">
                                <tr>
                                    <th>Laminated 1</th>
                                    <td><?= formatValue($specific_data['laminated1']) ?></td>
                                </tr>
                                <tr>
                                    <th>Laminated 2</th>
                                    <td><?= formatValue($specific_data['laminated2']) ?></td>
                                </tr>
                                <tr>
                                    <th>Coating 1</th>
                                    <td><?= formatValue($specific_data['Coating1']) ?></td>
                                </tr>
                                <tr>
                                    <th>Coating 2</th>
                                    <td><?= formatValue($specific_data['Coating2']) ?></td>
                                </tr>
                            </table>
                            
                            <?php break;
                            
                        case GROUP_COATING: ?>
                            <!-- Coating Data -->
                            <table class="table info-table">
                                <tr>
                                    <th>ประเภทเคลือบ</th>
                                    <td><?= formatValue($specific_data['Coating_based']) ?></td>
                                </tr>
                                <tr>
                                    <th>ชนิด</th>
                                    <td><?= formatValue($specific_data['type']) ?></td>
                                </tr>
                                <tr>
                                    <th>เอฟเฟกต์</th>
                                    <td><?= formatValue($specific_data['effect']) ?></td>
                                </tr>
                                <tr>
                                    <th>ความหนา</th>
                                    <td><?= formatValue($specific_data['Thickness'], 'μm') ?></td>
                                </tr>
                            </table>
                            
                            <?php break;
                            
case GROUP_ADHESIVE: ?>
    <!-- Adhesive Data -->
    <h6 class="section-title">ข้อมูลกาว</h6>
    <table class="table info-table">
        <tr>
            <th>รหัสกาว</th>
            <td><?= formatValue($specific_data['Adhesive_Code']) ?></td>
        </tr>
        <tr>
            <th>ประเภทกาว</th>
            <td><?= formatValue($specific_data['Adhesive_type']) ?></td>
        </tr>
        <tr>
            <th>ใช้กับวัสดุ</th>
            <td><?= formatValue($specific_data['Apply_on']) ?></td>
        </tr>
        <tr>
            <th>การใช้งาน</th>
            <td><?= formatValue($specific_data['Application']) ?></td>
        </tr>
    </table>
    
    <?php break;
                            
                        case GROUP_FILM: ?>
                            <!-- Film Data -->
                            <table class="table info-table">
                                <tr>
                                    <th>ประเภทฟิล์ม</th>
                                    <td><?= formatValue($specific_data['Film_type']) ?></td>
                                </tr>
                                <tr>
                                    <th>รหัสฟิล์ม</th>
                                    <td><?= formatValue($specific_data['Film_code']) ?></td>
                                </tr>
                                <tr>
                                    <th>เอฟเฟกต์</th>
                                    <td><?= formatValue($specific_data['Film_effect']) ?></td>
                                </tr>
                                <tr>
                                    <th>ความหนา</th>
                                    <td><?= formatValue($specific_data['Thickness'], 'μm') ?></td>
                                </tr>
                            </table>
                            
                            <?php break;
                            
                        case GROUP_CORRUGATED: ?>
                            <!-- Corrugated Data -->
                            <h6 class="section-title">ข้อมูลกล่อง</h6>
                            <table class="table info-table">
                                <tr>
                                    <th>เลขที่กล่อง</th>
                                    <td><?= formatValue($specific_data['Case_Number']) ?></td>
                                </tr>
                                <tr>
                                    <th>น้ำหนักต่อกล่อง</th>
                                    <td><?= formatValue($specific_data['weight_kg_per_box'], 'kg', 3) ?></td>
                                </tr>
                                <tr>
                                    <th>ประเภทฟลูท</th>
                                    <td><?= formatValue($specific_data['type_flute']) ?></td>
                                </tr>
                                <tr>
                                    <th>จำนวนชั้น</th>
                                    <td><?= formatValue($specific_data['Layer'], 'ชั้น', 0) ?></td>
                                </tr>
                                <tr>
                                    <th>โลโก้</th>
                                    <td><?= formatValue($specific_data['Logo']) ?></td>
                                </tr>
                            </table>
                            
                            <h6 class="section-title">ขนาดภายนอก</h6>
                            <table class="table info-table">
                                <tr>
                                    <th>ความกว้าง</th>
                                    <td><?= formatValue($specific_data['W_Outer_mm'], 'mm') ?></td>
                                </tr>
                                <tr>
                                    <th>ความยาว</th>
                                    <td><?= formatValue($specific_data['L_Outer_mm'], 'mm') ?></td>
                                </tr>
                                <tr>
                                    <th>ความสูง</th>
                                    <td><?= formatValue($specific_data['H_Outer_mm'], 'mm') ?></td>
                                </tr>
                            </table>
                            
                            <h6 class="section-title">ขนาดภายใน</h6>
                            <table class="table info-table">
                                <tr>
                                    <th>ความกว้าง</th>
                                    <td><?= formatValue($specific_data['W_Inner_mm'], 'mm') ?></td>
                                </tr>
                                <tr>
                                    <th>ความยาว</th>
                                    <td><?= formatValue($specific_data['L_Inner_mm'], 'mm') ?></td>
                                </tr>
                                <tr>
                                    <th>ความสูง</th>
                                    <td><?= formatValue($specific_data['H_Inner_mm'], 'mm') ?></td>
                                </tr>
                            </table>
                            
                            <h6 class="section-title">ชั้นวัสดุ</h6>
                            <table class="table info-table">
                                <tr>
                                    <th>Liner 1</th>
                                    <td><?= formatValue($specific_data['Liner']) ?></td>
                                </tr>
                                <tr>
                                    <th>Flute 1</th>
                                    <td><?= formatValue($specific_data['Flute']) ?></td>
                                </tr>
                                <tr>
                                    <th>Liner 2</th>
                                    <td><?= formatValue($specific_data['Liner2']) ?></td>
                                </tr>
                                <tr>
                                    <th>Flute 2</th>
                                    <td><?= formatValue($specific_data['Flute2']) ?></td>
                                </tr>
                                <tr>
                                    <th>Liner 3</th>
                                    <td><?= formatValue($specific_data['Liner3']) ?></td>
                                </tr>
                            </table>
                            
                            <?php break;
                            
                        case GROUP_FOIL: ?>
                            <!-- Foil Data -->
                            <h6 class="section-title">ข้อมูลฟอยล์</h6>
                            <table class="table info-table">
                                <tr>
                                    <th>รหัสฟอยล์</th>
                                    <td><?= formatValue($specific_data['Foil_Code']) ?></td>
                                </tr>
                                <tr>
                                    <th>สี</th>
                                    <td><?= formatValue($specific_data['Color']) ?></td>
                                </tr>
                                <tr>
                                    <th>เอฟเฟกต์</th>
                                    <td><?= formatValue($specific_data['Effect']) ?></td>
                                </tr>
                            </table>
                            
                            <h6 class="section-title">ขนาด</h6>
                            <table class="table info-table">
                                <tr>
                                    <th>ความกว้าง</th>
                                    <td><?= formatValue($specific_data['W_mm'], 'mm') ?></td>
                                </tr>
                                <tr>
                                    <th>ความยาว</th>
                                    <td><?= formatValue($specific_data['L_m'], 'm') ?></td>
                                </tr>
                                <tr>
                                    <th>พื้นที่</th>
                                    <td>
                                        <?= formatValue($specific_data['m2'], 'm²') ?>
                                        <span class="calculated-badge badge">คำนวณ</span>
                                    </td>
                                </tr>
                            </table>
                            
                            <?php break;
                            
                        case GROUP_PLATE: ?>
                            <!-- Plate Data -->
                            <table class="table info-table">
                                <tr>
                                    <th>แบรนด์แผ่นพิมพ์</th>
                                    <td><?= formatValue($specific_data['Brand_plate']) ?></td>
                                </tr>
                                <tr>
                                    <th>ความกว้าง</th>
                                    <td><?= formatValue($specific_data['W_mm'], 'mm') ?></td>
                                </tr>
                                <tr>
                                    <th>ความยาว</th>
                                    <td><?= formatValue($specific_data['Length_mm'], 'mm') ?></td>
                                </tr>
                                <tr>
                                    <th>ความหนา</th>
                                    <td><?= formatValue($specific_data['Thickness_mm'], 'mm') ?></td>
                                </tr>
                            </table>
                            
                            <?php break;
                            
                        default: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                ไม่มีข้อมูลจำเพาะสำหรับกลุ่มนี้
                            </div>
                        <?php endswitch; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Metadata -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-clock me-2"></i>ข้อมูลระบบ
                        </h5>
                    </div>
                    <div class="card-body">
                        <table class="table info-table">
                            <tr>
                                <th>วันที่สร้าง</th>
                                <td>
                                    <?= displayDate($material['created_date']) ?>
                                    <?php if (!empty($material['created_by_name'])): ?>
                                    <br><small class="text-muted">โดย: <?= sanitizeOutput($material['created_by_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($material['updated_date'])): ?>
                            <tr>
                                <th>วันที่อัปเดตล่าสุด</th>
                                <td>
                                    <?= displayDate($material['updated_date']) ?>
                                    <?php if (!empty($material['updated_by_name'])): ?>
                                    <br><small class="text-muted">โดย: <?= sanitizeOutput($material['updated_by_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">ยืนยันการลบ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center">
                        <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                        <p>คุณต้องการลบวัสดุ <strong id="deleteItemName"></strong> ใช่หรือไม่?</p>
                        <p class="text-muted">การลบนี้จะเปลี่ยนสถานะเป็น "ไม่ใช้งาน" และสามารถกู้คืนได้</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <a id="confirmDeleteBtn" href="#" class="btn btn-danger">ยืนยันการลบ</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Export ข้อมูลวัสดุ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>เลือกรูปแบบการ Export:</p>
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-danger" onclick="exportToPDF()">
                            <i class="fas fa-file-pdf me-2"></i>PDF - รายละเอียดเต็ม
                        </button>
                        <button type="button" class="btn btn-outline-success" onclick="exportToExcel()">
                            <i class="fas fa-file-excel me-2"></i>Excel - ข้อมูลตาราง
                        </button>
                        <button type="button" class="btn btn-outline-info" onclick="exportToCSV()">
                            <i class="fas fa-file-csv me-2"></i>CSV - ข้อมูลพื้นฐาน
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Delete confirmation
        function confirmDelete(productId, sspCode) {
            document.getElementById('deleteItemName').textContent = sspCode;
            document.getElementById('confirmDeleteBtn').href = `delete.php?id=${productId}`;
            
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }

        // Print function
        function printMaterial() {
            window.print();
        }

        // Export functions
        function exportMaterial() {
            const modal = new bootstrap.Modal(document.getElementById('exportModal'));
            modal.show();
        }

        function exportToPDF() {
            window.location.href = `export.php?id=<?= $product_id ?>&format=pdf`;
        }

        function exportToExcel() {
            window.location.href = `export.php?id=<?= $product_id ?>&format=excel`;
        }

        function exportToCSV() {
            window.location.href = `export.php?id=<?= $product_id ?>&format=csv`;
        }

        // Duplicate material
        function duplicateMaterial() {
            if (confirm('คุณต้องการทำสำเนาวัสดุนี้ใช่หรือไม่?')) {
                window.location.href = `add.php?duplicate=<?= $product_id ?>`;
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P for print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printMaterial();
            }
            
            // Ctrl+E for edit (if has permission)
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                <?php if ($auth->hasRole('editor')): ?>
                window.location.href = 'edit.php?id=<?= $product_id ?>';
                <?php endif; ?>
            }
            
            // Escape to go back
            if (e.key === 'Escape') {
                window.location.href = 'list.php';
            }
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            console.log('✅ Material View initialized successfully');
            console.log('📦 Material ID:', <?= $product_id ?>);
            console.log('🏷️ SSP Code:', <?= json_encode($material['SSP_Code'] ?? '') ?>);
            console.log('📊 Group ID:', <?= $group_id ?? 'null' ?>);
        });

        // Add loading states for actions
        function showLoadingState(button, text = 'กำลังดำเนินการ...') {
            const originalText = button.innerHTML;
            button.disabled = true;
            button.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i>${text}`;
            
            return function() {
                button.disabled = false;
                button.innerHTML = originalText;
            };
        }

        // Enhanced error handling
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.error);
        });

        // Back button enhancement
        if (document.referrer.includes('list.php')) {
            const backButtons = document.querySelectorAll('a[href="list.php"]');
            backButtons.forEach(button => {
                button.href = document.referrer;
            });
        }

        // Auto-save scroll position
        window.addEventListener('beforeunload', function() {
            sessionStorage.setItem('scrollPosition', window.pageYOffset);
        });

        window.addEventListener('load', function() {
            const scrollPosition = sessionStorage.getItem('scrollPosition');
            if (scrollPosition) {
                window.scrollTo(0, parseInt(scrollPosition));
                sessionStorage.removeItem('scrollPosition');
            }
        });
    </script>
</body>
</html>