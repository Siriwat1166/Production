<?php
// pages/materials/edit.php - หน้าแก้ไขวัสดุ (Fixed version)
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole('editor');

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

// ตรวจสอบ ID
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($product_id <= 0) {
    header("Location: list.php");
    exit;
}

// Constants for group IDs
define('GROUP_PAPERBOARD', 255);
define('GROUP_INK', 256);
define('GROUP_COATING', 257);
define('GROUP_ADHESIVE', 258);
define('GROUP_FILM', 259);
define('GROUP_CORRUGATED', 260);
define('GROUP_FOIL', 261);
define('GROUP_PLATE', 262);
define('GROUP_PAPER_501', 501);
define('GROUP_PAPER_551', 551);
define('GROUP_PAPER_801', 801);
define('GROUP_PAPER_802', 802);
define('GROUP_PAPER_803', 803);
define('GROUP_PAPER_804', 804);

// ข้อความแจ้ง
$message = '';
$message_type = '';

// ตัวแปรสำหรับเก็บข้อมูล
$material = null;
$specific_data = null;
$group_id = null;

// Validation helpers
function validateRequired($value, $fieldName) {
    if (trim((string)$value) === '') {
        throw new Exception("กรุณากรอก{$fieldName}");
    }
    return $value;
}

function validateNumeric($value, $min, $max, $fieldName) {
    if (!is_numeric($value) || $value < $min || $value > $max) {
        throw new Exception("{$fieldName} ต้องอยู่ระหว่าง {$min}-{$max}");
    }
    return (float)$value;
}

function sanitizeInput($value) {
    return htmlspecialchars(trim($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function sanitizeOutput($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// ฟังก์ชันอัปเดตข้อมูลเฉพาะตาม group

// ✅ Paperboard Update Function - รองรับ paper_subgroup
function updatePaperboardData($conn, $product_id, $data, $user_id) {
    $gsm = validateNumeric($data['gsm'] ?? null, 50, 1000, 'GSM');

    $w_mm = ($data['w_mm'] ?? '') !== '' ? validateNumeric($data['w_mm'], 10, 5000, 'ความกว้าง') : null;
    $l_mm = ($data['l_mm'] ?? '') !== '' ? validateNumeric($data['l_mm'], 10, 5000, 'ความยาว') : null;
    $caliper = ($data['caliper'] ?? '') !== '' ? validateNumeric($data['caliper'], 50, 2000, 'Caliper') : null;
    $paper_subgroup = !empty($data['paper_subgroup']) ? (int)$data['paper_subgroup'] : null;

    $w_inch = $w_mm ? $w_mm / 25.4 : null;
    $l_inch = $l_mm ? $l_mm / 25.4 : null;
    $weight_kg_per_sheet = ($w_mm && $l_mm && $gsm) ? ($w_mm * $l_mm * $gsm) / 1000000 : null;

    $stmt = $conn->prepare("
        UPDATE Specific_Paperboard SET
            paper_subgroup_id = ?, W_mm = ?, L_mm = ?, L_inch = ?, W_inch = ?, gsm = ?, Caliper = ?, brand = ?,
            type_paperboard_TH = ?, type_paperboard_EN = ?, laminated1 = ?, laminated2 = ?,
            Certificated = ?, Weight_kg_per_sheet = ?, updated_by = ?, updated_date = GETDATE()
        WHERE product_id = ?
    ");
    $stmt->execute([
        $paper_subgroup, $w_mm, $l_mm, $l_inch, $w_inch, $gsm, $caliper,
        sanitizeInput($data['brand'] ?? ''),
        sanitizeInput($data['type_paperboard_th'] ?? ''),
        sanitizeInput($data['type_paperboard_en'] ?? ''),
        sanitizeInput($data['laminated1'] ?? ''),
        sanitizeInput($data['laminated2'] ?? ''),
        sanitizeInput($data['certificated'] ?? ''),
        $weight_kg_per_sheet, $user_id, $product_id
    ]);
}

function updateInkData($conn, $product_id, $data, $user_id) {
    $ink_type  = validateRequired(sanitizeInput($data['ink_type'] ?? ''), 'ประเภทหมึก');
    $ink_color = validateRequired(sanitizeInput($data['ink_color'] ?? ''), 'สี');
    $ink_group = validateRequired(sanitizeInput($data['ink_group'] ?? ''), 'กลุ่มหมึก');
    $ink_side  = validateRequired(sanitizeInput($data['ink_side'] ?? ''), 'ด้านที่พิมพ์');

    $gsm_ink = ($data['gsm_ink'] ?? '') !== '' ? validateNumeric($data['gsm_ink'], 50, 1000, 'GSM') : null;

    $stmt = $conn->prepare("
        UPDATE Specific_Ink SET
            ink_type = ?, Color = ?, Ink_Group = ?, Brand_paperboard = ?, type_paperboard = ?, gsm = ?, Side = ?,
            laminated1 = ?, laminated2 = ?, Coating1 = ?, Coating2 = ?, updated_by = ?, updated_date = GETDATE()
        WHERE product_id = ?
    ");
    $stmt->execute([
        $ink_type, $ink_color, $ink_group,
        sanitizeInput($data['brand_paperboard'] ?? ''),
        sanitizeInput($data['type_paperboard_ink'] ?? ''),
        $gsm_ink, $ink_side,
        sanitizeInput($data['laminated1_ink'] ?? ''),
        sanitizeInput($data['laminated2_ink'] ?? ''),
        sanitizeInput($data['coating1_ink'] ?? ''),
        sanitizeInput($data['coating2_ink'] ?? ''),
        $user_id, $product_id
    ]);
}

function updateCoatingData($conn, $product_id, $data, $user_id) {
    $coating_based = validateRequired(sanitizeInput($data['coating_based'] ?? ''), 'ประเภทเคลือบ');
    $coating_type  = validateRequired(sanitizeInput($data['coating_type'] ?? ''), 'ชนิด');
    $coating_effect= validateRequired(sanitizeInput($data['coating_effect'] ?? ''), 'เอฟเฟกต์');

    $coating_thickness = ($data['coating_thickness'] ?? '') !== '' ? validateNumeric($data['coating_thickness'], 0.1, 999.9, 'ความหนา') : null;

    $stmt = $conn->prepare("
        UPDATE Specific_Coating SET
            Coating_based = ?, type = ?, effect = ?, Thickness = ?, updated_by = ?, updated_date = GETDATE()
        WHERE product_id = ?
    ");
    $stmt->execute([$coating_based, $coating_type, $coating_effect, $coating_thickness, $user_id, $product_id]);
}

function updateAdhesiveData($conn, $product_id, $data, $user_id) {
    $adhesive_type = validateRequired(sanitizeInput($data['adhesive_type'] ?? ''), 'ประเภทกาว');
    $apply_on      = validateRequired(sanitizeInput($data['apply_on'] ?? ''), 'ใช้กับวัสดุ');
    $adhesive_application = validateRequired(sanitizeInput($data['adhesive_application'] ?? ''), 'การใช้งาน');

    $stmt = $conn->prepare("
        UPDATE Specific_Adhesive SET
            Adhesive_type = ?, Apply_on = ?, Application = ?, updated_by = ?, updated_date = GETDATE()
        WHERE product_id = ?
    ");
    $stmt->execute([$adhesive_type, $apply_on, $adhesive_application, $user_id, $product_id]);
}

function updateFilmData($conn, $product_id, $data, $user_id) {
    $film_type = validateRequired(sanitizeInput($data['film_type'] ?? ''), 'ประเภทฟิล์ม');
    $film_code = validateRequired(sanitizeInput($data['film_code'] ?? ''), 'รหัสฟิล์ม');
    $film_effect = validateRequired(sanitizeInput($data['film_effect'] ?? ''), 'เอฟเฟกต์');
    $film_thickness = validateNumeric($data['film_thickness'] ?? null, 0.1, 999.9, 'ความหนา');

    $stmt = $conn->prepare("
        UPDATE Specific_Film SET
            Film_type = ?, Film_code = ?, Film_effect = ?, Thickness = ?, updated_by = ?, updated_date = GETDATE()
        WHERE product_id = ?
    ");
    $stmt->execute([$film_type, $film_code, $film_effect, $film_thickness, $user_id, $product_id]);
}

function updateCorrugatedData($conn, $product_id, $data, $user_id) {
    $case_number = validateRequired(sanitizeInput($data['case_number'] ?? ''), 'เลขที่กล่อง');
    $w_outer_mm  = validateNumeric($data['w_outer_mm'] ?? null, 10, 5000, 'ความกว้างภายนอก');
    $l_outer_mm  = validateNumeric($data['l_outer_mm'] ?? null, 10, 5000, 'ความยาวภายนอก');
    $h_outer_mm  = validateNumeric($data['h_outer_mm'] ?? null, 10, 5000, 'ความสูงภายนอก');
    $type_flute  = validateRequired(sanitizeInput($data['type_flute'] ?? ''), 'ประเภทฟลูท');
    $corrugated_layer = validateNumeric($data['corrugated_layer'] ?? null, 3, 7, 'จำนวนชั้น');

    $w_inner_mm = ($data['w_inner_mm'] ?? '') !== '' ? validateNumeric($data['w_inner_mm'], 10, 5000, 'ความกว้างภายใน') : null;
    $l_inner_mm = ($data['l_inner_mm'] ?? '') !== '' ? validateNumeric($data['l_inner_mm'], 10, 5000, 'ความยาวภายใน') : null;
    $h_inner_mm = ($data['h_inner_mm'] ?? '') !== '' ? validateNumeric($data['h_inner_mm'], 10, 5000, 'ความสูงภายใน') : null;
    $weight_kg_per_box = ($data['weight_kg_per_box'] ?? '') !== '' ? validateNumeric($data['weight_kg_per_box'], 0.001, 999.999, 'น้ำหนัก') : null;

    $stmt = $conn->prepare("
        UPDATE Specific_Corrugated_box SET
            Case_Number = ?, W_Outer_mm = ?, L_Outer_mm = ?, H_Outer_mm = ?, W_Inner_mm = ?, L_Inner_mm = ?, H_Inner_mm = ?,
            weight_kg_per_box = ?, type_flute = ?, Layer = ?, Liner = ?, Flute = ?, Liner2 = ?, Flute2 = ?, Liner3 = ?, Logo = ?,
            updated_by = ?, updated_date = GETDATE()
        WHERE product_id = ?
    ");
    $stmt->execute([
        $case_number, $w_outer_mm, $l_outer_mm, $h_outer_mm, $w_inner_mm, $l_inner_mm, $h_inner_mm,
        $weight_kg_per_box, $type_flute, $corrugated_layer,
        sanitizeInput($data['liner1'] ?? ''), sanitizeInput($data['flute1'] ?? ''),
        sanitizeInput($data['liner2'] ?? ''), sanitizeInput($data['flute2'] ?? ''),
        sanitizeInput($data['liner3'] ?? ''), sanitizeInput($data['corrugated_logo'] ?? ''),
        $user_id, $product_id
    ]);
}

function updateFoilData($conn, $product_id, $data, $user_id) {
    $foil_code = validateRequired(sanitizeInput($data['foil_code'] ?? ''), 'รหัสฟอยล์');
    $foil_color = validateRequired(sanitizeInput($data['foil_color'] ?? ''), 'สี');
    $foil_w_mm = validateNumeric($data['foil_w_mm'] ?? null, 10, 5000, 'ความกว้าง');
    $foil_l_m  = validateNumeric($data['foil_l_m'] ?? null, 0.1, 1000, 'ความยาว');
    $foil_effect = validateRequired(sanitizeInput($data['foil_effect'] ?? ''), 'เอฟเฟกต์');
    $foil_m2 = ($foil_w_mm * $foil_l_m) / 1000;

    $stmt = $conn->prepare("
        UPDATE Specific_Foil SET
            Foil_Code = ?, Color = ?, W_mm = ?, L_m = ?, m2 = ?, Effect = ?, updated_by = ?, updated_date = GETDATE()
        WHERE product_id = ?
    ");
    $stmt->execute([$foil_code, $foil_color, $foil_w_mm, $foil_l_m, $foil_m2, $foil_effect, $user_id, $product_id]);
}

function updatePlateData($conn, $product_id, $data, $user_id) {
    $brand_plate = validateRequired(sanitizeInput($data['brand_plate'] ?? ''), 'แบรนด์แผ่นพิมพ์');
    $plate_w_mm  = validateNumeric($data['plate_w_mm'] ?? null, 10, 5000, 'ความกว้าง');
    $plate_length_mm = validateNumeric($data['plate_length_mm'] ?? null, 10, 5000, 'ความยาว');
    $plate_thickness_mm = validateNumeric($data['plate_thickness_mm'] ?? null, 0.01, 5.0, 'ความหนา');

    $final_brand = $brand_plate === 'Other' ? sanitizeInput($data['other_brand_plate'] ?? '') : $brand_plate;
    if ($brand_plate === 'Other' && $final_brand === '') {
        throw new Exception("กรุณาระบุแบรนด์แผ่นพิมพ์");
    }

    $stmt = $conn->prepare("
        UPDATE Specific_Plate SET
            Brand_plate = ?, W_mm = ?, Length_mm = ?, Thickness_mm = ?, updated_by = ?, updated_date = GETDATE()
        WHERE product_id = ?
    ");
    $stmt->execute([$final_brand, $plate_w_mm, $plate_length_mm, $plate_thickness_mm, $user_id, $product_id]);
}
// โหลดข้อมูลปัจจุบัน
try {
    $stmt = $conn->prepare("
        SELECT 
            mp.*, mt.type_name, mt.type_code, g.name AS group_name,
            s.supplier_name, s.supplier_code
        FROM Master_Products_ID mp
        LEFT JOIN Material_Types mt ON mp.material_type_id = mt.material_type_id
        LEFT JOIN Groups g ON mp.group_id = g.id
        LEFT JOIN Suppliers s ON mp.supplier_id = s.supplier_id
        WHERE mp.id = ? AND mp.is_active = 1
    ");
    $stmt->execute([$product_id]);
    $material = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$material) throw new Exception("ไม่พบข้อมูลวัสดุหรือถูกลบแล้ว");
    $group_id = (int)$material['group_id'];

    $paperGroups = [GROUP_PAPERBOARD, GROUP_PAPER_501, GROUP_PAPER_551, GROUP_PAPER_801, GROUP_PAPER_802, GROUP_PAPER_803, GROUP_PAPER_804];

    // โหลดข้อมูลเฉพาะตาม group
    if (in_array($group_id, $paperGroups)) {
        $stmt = $conn->prepare("SELECT *, paper_subgroup_id FROM Specific_Paperboard WHERE product_id = ? AND is_active = 1");
        $stmt->execute([$product_id]);
        $specific_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // ✅ Debug และกำหนด display_subgroup
        error_log("Paperboard Data Debug - product_id: $product_id, paper_subgroup_id: " . ($specific_data['paper_subgroup_id'] ?? 'NULL'));
        
        // ✅ ถ้ามี paper_subgroup_id ให้ใช้ค่านั้นแทน group_id ในการแสดงผล
        if (!empty($specific_data['paper_subgroup_id'])) {
            $display_subgroup = $specific_data['paper_subgroup_id'];
            error_log("Using paper_subgroup_id: $display_subgroup instead of group_id: $group_id");
        } else {
            $display_subgroup = $group_id;
        }
    } else {
        // โหลดข้อมูลสำหรับ group อื่นๆ
        switch ($group_id) {
            case GROUP_INK:
                $stmt = $conn->prepare("SELECT * FROM Specific_Ink WHERE product_id = ? AND is_active = 1"); break;
            case GROUP_COATING:
                $stmt = $conn->prepare("SELECT * FROM Specific_Coating WHERE product_id = ? AND is_active = 1"); break;
            case GROUP_ADHESIVE:
                $stmt = $conn->prepare("SELECT * FROM Specific_Adhesive WHERE product_id = ? AND is_active = 1"); break;
            case GROUP_FILM:
                $stmt = $conn->prepare("SELECT * FROM Specific_Film WHERE product_id = ? AND is_active = 1"); break;
            case GROUP_CORRUGATED:
                $stmt = $conn->prepare("SELECT * FROM Specific_Corrugated_box WHERE product_id = ? AND is_active = 1"); break;
            case GROUP_FOIL:
                $stmt = $conn->prepare("SELECT * FROM Specific_Foil WHERE product_id = ? AND is_active = 1"); break;
            case GROUP_PLATE:
                $stmt = $conn->prepare("SELECT * FROM Specific_Plate WHERE product_id = ? AND is_active = 1"); break;
            default:
                $stmt = null;
        }
        
        if ($stmt) {
            $stmt->execute([$product_id]);
            $specific_data = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        $display_subgroup = $group_id; // สำหรับ non-paper groups
    }
    
} catch (Exception $e) {
    error_log("Error loading material for edit: " . $e->getMessage());
    $message = $e->getMessage();
    $message_type = "danger";
}
// ✅ ประมวลผลการส่งฟอร์ม - รองรับ paper subgroup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $material) {
    try {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new Exception("Invalid CSRF token");
        }
        $conn->beginTransaction();

        $name   = validateRequired(sanitizeInput($_POST['name'] ?? ''), 'ชื่อวัสดุ');
        $name2  = sanitizeInput($_POST['name2'] ?? '');
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 0;
        $unit_id = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;

        // ✅ จัดการ paper_subgroup - กำหนด group_id ใหม่
        $original_group_id = (int)$material['group_id'];
        $new_group_id = $original_group_id;
        
        // ถ้าเป็น Paperboard หรือ Paper groups และมีการเลือก subgroup
        $paperMainGroups = [GROUP_PAPERBOARD, GROUP_PAPER_501, GROUP_PAPER_551, GROUP_PAPER_801, GROUP_PAPER_802, GROUP_PAPER_803, GROUP_PAPER_804];
        if (in_array($original_group_id, $paperMainGroups) && !empty($_POST['paper_subgroup'])) {
            $selected_subgroup = (int)$_POST['paper_subgroup'];
            $valid_subgroups = [GROUP_PAPERBOARD, GROUP_PAPER_501, GROUP_PAPER_551, GROUP_PAPER_801, GROUP_PAPER_802, GROUP_PAPER_803, GROUP_PAPER_804];
            
            if (in_array($selected_subgroup, $valid_subgroups, true)) {
                $new_group_id = $selected_subgroup;
            }
        }

        // อัปเดต Master_Products_ID
        if ($unit_id) {
            $stmt = $conn->prepare("
                UPDATE Master_Products_ID SET
                    Name = ?, Name2 = ?, Unit_id = ?, group_id = ?, status = ?, updated_by = ?, updated_date = GETDATE()
                WHERE id = ?
            ");
            $stmt->execute([$name, $name2, $unit_id, $new_group_id, $status, $_SESSION['user_id'], $product_id]);
        } else {
            $stmt = $conn->prepare("
                UPDATE Master_Products_ID SET
                    Name = ?, Name2 = ?, group_id = ?, status = ?, updated_by = ?, updated_date = GETDATE()
                WHERE id = ?
            ");
            $stmt->execute([$name, $name2, $new_group_id, $status, $_SESSION['user_id'], $product_id]);
        }

        // อัปเดตข้อมูลเฉพาะตาม group
        if ($specific_data) {
            $paperGroups = [GROUP_PAPERBOARD, GROUP_PAPER_501, GROUP_PAPER_551, GROUP_PAPER_801, GROUP_PAPER_802, GROUP_PAPER_803, GROUP_PAPER_804];
            if (in_array($new_group_id, $paperGroups, true)) {
                updatePaperboardData($conn, $product_id, $_POST, $_SESSION['user_id']);
            } else {
                switch ($new_group_id) {
                    case GROUP_INK:        updateInkData($conn, $product_id, $_POST, $_SESSION['user_id']); break;
                    case GROUP_COATING:    updateCoatingData($conn, $product_id, $_POST, $_SESSION['user_id']); break;
                    case GROUP_ADHESIVE:   updateAdhesiveData($conn, $product_id, $_POST, $_SESSION['user_id']); break;
                    case GROUP_FILM:       updateFilmData($conn, $product_id, $_POST, $_SESSION['user_id']); break;
                    case GROUP_CORRUGATED: updateCorrugatedData($conn, $product_id, $_POST, $_SESSION['user_id']); break;
                    case GROUP_FOIL:       updateFoilData($conn, $product_id, $_POST, $_SESSION['user_id']); break;
                    case GROUP_PLATE:      updatePlateData($conn, $product_id, $_POST, $_SESSION['user_id']); break;
                }
            }
        }

        error_log("Material updated successfully - SSP Code: {$material['SSP_Code']}, User: {$_SESSION['user_id']}");
        $conn->commit();
        header("Location: view.php?id={$product_id}&success=" . urlencode("แก้ไขข้อมูลวัสดุเรียบร้อยแล้ว"));
        exit;
    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollback();
        error_log("Database error in edit material: " . $e->getMessage());
        $message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
        $message_type = "danger";
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollback();
        error_log("General error in edit material: " . $e->getMessage());
        $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $message_type = "danger";
    }
}

// ดึงข้อมูลสำหรับ dropdown
try {
    $stmt = $conn->prepare("SELECT material_type_id, type_name, type_code FROM Material_Types WHERE is_active = 1 ORDER BY type_name");
    $stmt->execute();
    $material_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT id, name FROM Groups WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT supplier_id, supplier_code, supplier_name FROM Suppliers WHERE is_active = 1 ORDER BY supplier_name");
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT unit_id, unit_code, unit_name, unit_name_th, unit_symbol FROM Units WHERE is_active = 1 ORDER BY unit_name");
    $stmt->execute();
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error loading dropdown data: " . $e->getMessage());
    $material_types = $groups = $suppliers = $units = [];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>แก้ไขวัสดุ - <?= sanitizeOutput(APP_NAME ?? 'Material Management') ?></title>
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
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid rgba(139, 69, 19, 0.2);
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(139, 69, 19, 0.25);
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: bold;
            transition: all 0.3s ease;
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-gradient-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(139, 69, 19, 0.3);
            color: white;
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, var(--accent-color), var(--primary-color));
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: bold;
            transition: all 0.3s ease;
            color: white;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(139, 69, 19, 0.3);
            color: white;
        }

        .btn-outline-primary {
            border-color: var(--primary-color);
            color: var(--primary-color);
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
        }

        .btn-outline-secondary {
            border-color: var(--accent-color);
            color: var(--accent-color);
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .btn-outline-secondary:hover {
            background: linear-gradient(135deg, var(--accent-color), var(--primary-color));
            border-color: transparent;
            color: white;
            transform: translateY(-2px);
        }
        
        .alert {
            border-radius: 15px;
            border: none;
            padding: 20px;
            margin-bottom: 25px;
        }

        .alert-success {
            background: rgba(5, 150, 105, 0.1);
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-danger {
            background: rgba(220, 38, 38, 0.1);
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .alert-info {
            background: rgba(139, 69, 19, 0.1);
            color: var(--primary-color);
            border-left: 4px solid var(--accent-color);
        }

        .alert-warning {
            background: rgba(217, 119, 6, 0.1);
            color: #856404;
            border-left: 4px solid var(--warning-color);
        }
        
        .form-label {
            color: var(--primary-color);
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .required {
            color: var(--danger-color);
        }
        
        .section-title {
            color: var(--primary-color);
            border-bottom: 3px solid var(--accent-color);
            padding-bottom: 10px;
            margin-bottom: 25px;
            font-weight: bold;
        }
        
        .calculated-field {
            background-color: rgba(139, 69, 19, 0.05);
            border-style: dashed !important;
            border-color: var(--accent-color) !important;
        }
        
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: var(--danger-color);
            box-shadow: 0 0 0 0.2rem rgba(220, 38, 38, 0.25);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .container-fluid {
            max-width: 100%;
            padding: 0 15px;
            width: 100%;
            margin: 0;
        }

        .navbar-brand, .nav-link {
            color: white !important;
        }

        .nav-link:hover {
            color: #FFD700 !important;
            transform: translateY(-2px);
            transition: all 0.3s ease;
        }

        .text-primary {
            color: var(--primary-color) !important;
        }

        .text-muted {
            color: var(--accent-color) !important;
        }

        .text-danger {
            color: var(--danger-color) !important;
        }

        .bg-light {
            background: rgba(245, 222, 179, 0.3) !important;
        }

        .border {
            border-color: rgba(139, 69, 19, 0.2) !important;
        }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="../dashboard.php">
      <i class="fas fa-boxes me-2"></i><?= sanitizeOutput(APP_NAME ?? 'Material Management') ?>
    </a>
    <div class="navbar-nav ms-auto">
      <a class="nav-link" href="view.php?id=<?= (int)$product_id ?>"><i class="fas fa-eye me-1"></i> ดูรายละเอียด</a>
      <a class="nav-link" href="list.php"><i class="fas fa-list me-1"></i> รายการวัสดุ</a>
    </div>
  </div>
</nav>

<div class="container-fluid mt-4">
<?php if (!$material): ?>
  <div class="card"><div class="card-body text-center">
    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
    <h4>ไม่พบข้อมูล</h4>
    <p class="text-muted"><?= sanitizeOutput($message) ?></p>
    <a href="list.php" class="btn btn-primary"><i class="fas fa-arrow-left me-2"></i>กลับสู่รายการ</a>
  </div></div>
<?php else: ?>

  <div class="row justify-content-center">
    <div class="col-lg-10">
      <!-- Header Card -->
      <div class="card mb-4">
        <div class="card-header"><h4 class="mb-0"><i class="fas fa-edit me-2"></i>แก้ไขวัสดุ</h4></div>
        <div class="card-body">
          <div class="p-3 border rounded-3 bg-light">
            <div class="row">
              <div class="col-md-6">
                <p><strong>SSP Code:</strong> <span class="text-primary"><?= sanitizeOutput($material['SSP_Code']) ?></span></p>
                <p><strong>ประเภท:</strong> <?= sanitizeOutput($material['type_name']) ?></p>
                <p><strong>กลุ่ม:</strong> <?= sanitizeOutput($material['group_name']) ?></p>
              </div>
              <div class="col-md-6">
                <p><strong>ซัพพลายเออร์:</strong> <?= sanitizeOutput($material['supplier_name']) ?></p>
                <p><strong>Run Number:</strong> <?= sanitizeOutput($material['run_number']) ?></p>
                <p class="text-muted"><i class="fas fa-info-circle me-1"></i>ข้อมูลบางส่วนล็อคเพราะเกี่ยวข้องกับ SSP Code</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Alert Messages -->
      <?php if ($message): ?>
      <div class="alert alert-<?= sanitizeOutput($message_type) ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
        <?= sanitizeOutput($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
      <?php endif; ?>
<!-- Main Form Start -->
      <form method="POST" id="editForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= sanitizeOutput($_SESSION['csrf_token']) ?>">

        <!-- ข้อมูลพื้นฐาน -->
        <div class="card">
          <div class="card-header"><h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>ข้อมูลพื้นฐาน</h5></div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">ชื่อวัสดุ (ไทย) <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" value="<?= sanitizeOutput($material['Name']) ?>" required maxlength="255">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">ชื่อวัสดุ (อังกฤษ)</label>
                <input type="text" class="form-control" name="name2" value="<?= sanitizeOutput($material['Name2']) ?>" maxlength="255">
              </div>
            </div>

<?php 
$paperGroups = [GROUP_PAPERBOARD, GROUP_PAPER_501, GROUP_PAPER_551, GROUP_PAPER_801, GROUP_PAPER_802, GROUP_PAPER_803, GROUP_PAPER_804];
$isPaperGroup = in_array($group_id, $paperGroups);
$display_subgroup = isset($display_subgroup) ? $display_subgroup : $group_id;
?>
            
            <!-- แก้ไขส่วน HTML ที่ผิด (บรรทัดประมาณ 590-620) -->

            <div class="row">
              <?php if ($isPaperGroup): ?>
              <!-- ✅ Paper Subgroup Dropdown - แสดงสำหรับทุก paper group -->
              <div class="col-md-4 mb-3">
                <label class="form-label">กลุ่มย่อยกระดาษ</label>
                <select class="form-select" id="paper_subgroup" name="paper_subgroup">
                  <option value="255" <?= ($display_subgroup == 255) ? 'selected' : '' ?>>Paperboard (255)</option>
                  <option value="501" <?= ($display_subgroup == 501) ? 'selected' : '' ?>>กระดาษขาว (501)</option>
                  <option value="551" <?= ($display_subgroup == 551) ? 'selected' : '' ?>>กระดาษ (551)</option>
                  <option value="801" <?= ($display_subgroup == 801) ? 'selected' : '' ?>>กระดาษ 801</option>
                  <option value="802" <?= ($display_subgroup == 802) ? 'selected' : '' ?>>กระดาษ 802</option>
                  <option value="803" <?= ($display_subgroup == 803) ? 'selected' : '' ?>>กระดาษ 803</option>
                  <option value="804" <?= ($display_subgroup == 804) ? 'selected' : '' ?>>กระดาษ 804</option>
                </select>
                <small class="text-muted">เลือกประเภทกระดาษย่อยสำหรับกลุ่ม Paperboard</small>
              </div>
              <?php endif; ?>
              
              <div class="col-md-<?= $isPaperGroup ? '4' : '6' ?> mb-3">
                <label class="form-label">หน่วยหลัก</label>
                <select class="form-select" name="unit_id">
                  <option value="">เลือกหน่วย</option>
                  <?php foreach ($units as $u): ?>
                  <option value="<?= (int)$u['unit_id'] ?>" <?= ($material['Unit_id'] == $u['unit_id']) ? 'selected' : '' ?>>
                    <?= sanitizeOutput($u['unit_name_th']) ?> (<?= sanitizeOutput($u['unit_symbol']) ?>)
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              
              <div class="col-md-<?= $isPaperGroup ? '4' : '6' ?> mb-3">
                <label class="form-label">สถานะ</label>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="status" name="status" value="1" <?= $material['status'] == 1 ? 'checked' : '' ?>>
                  <label class="form-check-label" for="status">ใช้งาน</label>
                </div>
              </div>
            </div>
          </div>
        </div>
<!-- Material-Specific Sections -->
<?php if ($specific_data): ?>
  <?php 
  // กำหนดกลุ่มกระดาษ
  $paperGroups = [GROUP_PAPERBOARD, GROUP_PAPER_501, GROUP_PAPER_551, GROUP_PAPER_801, GROUP_PAPER_802, GROUP_PAPER_803, GROUP_PAPER_804];
  
  if (in_array($group_id, $paperGroups)): 
  ?>
  <!-- Paperboard Section -->
  <div class="card mt-4">
    <div class="card-header"><h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>ข้อมูลจำเพาะ - Paperboard/กระดาษ</h5></div>
    <div class="card-body">
      <!-- ประเภทกระดาษ -->
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">ประเภทกระดาษ (ไทย)</label>
          <input type="text" class="form-control" name="type_paperboard_th" value="<?= sanitizeOutput($specific_data['type_paperboard_TH']) ?>" maxlength="100">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">ประเภทกระดาษ (อังกฤษ)</label>
          <input type="text" class="form-control" name="type_paperboard_en" value="<?= sanitizeOutput($specific_data['type_paperboard_EN']) ?>" maxlength="100">
        </div>
      </div>

      <!-- แบรนด์และคุณสมบัติ -->
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">แบรนด์</label>
          <input type="text" class="form-control" name="brand" value="<?= sanitizeOutput($specific_data['brand']) ?>" maxlength="100">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">GSM <span class="text-danger">*</span></label>
          <input type="number" class="form-control" id="gsm" name="gsm" value="<?= sanitizeOutput($specific_data['gsm']) ?>" min="50" max="1000" required>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Caliper</label>
          <input type="number" class="form-control" id="caliper" name="caliper" value="<?= sanitizeOutput($specific_data['Caliper']) ?>" min="50" max="2000" step="1">
        </div>
      </div>

      <!-- ขนาดกระดาษ -->
      <h6 class="section-title mt-3">ขนาดกระดาษ</h6>
      <div class="row">
        <div class="col-md-3 mb-3">
          <label class="form-label">ความกว้าง (มม.)</label>
          <input type="number" step="0.01" class="form-control" id="w_mm" name="w_mm" value="<?= sanitizeOutput($specific_data['W_mm']) ?>" min="10" max="5000">
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">ความยาว (มม.)</label>
          <input type="number" step="0.01" class="form-control" id="l_mm" name="l_mm" value="<?= sanitizeOutput($specific_data['L_mm']) ?>" min="10" max="5000">
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">ความกว้าง (นิ้ว)</label>
          <input type="number" step="0.01" class="form-control calculated-field" id="w_inch" name="w_inch" value="<?= sanitizeOutput($specific_data['W_inch']) ?>" readonly>
        </div>
        <div class="col-md-3 mb-3">
          <label class="form-label">ความยาว (นิ้ว)</label>
          <input type="number" step="0.01" class="form-control calculated-field" id="l_inch" name="l_inch" value="<?= sanitizeOutput($specific_data['L_inch']) ?>" readonly>
        </div>
      </div>

      <!-- การเคลือบผิว -->
      <h6 class="section-title">การเคลือบผิว / การรับรอง</h6>
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">Laminated 1</label>
          <select class="form-select" id="laminated1" name="laminated1">
            <option value="">ไม่มี</option>
            <?php foreach (['Matt','Gloss','Soft Touch','Velvet'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($specific_data['laminated1'] == $opt) ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">Laminated 2</label>
          <select class="form-select" id="laminated2" name="laminated2">
            <option value="">ไม่มี</option>
            <?php foreach (['Matt','Gloss','Soft Touch','Velvet'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($specific_data['laminated2'] == $opt) ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">การรับรอง</label>
          <select class="form-select" id="certificated" name="certificated">
            <option value="">ไม่มี</option>
            <?php foreach (['FSC','PEFC','ISO 14001','FDA'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($specific_data['Certificated'] == $opt) ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- น้ำหนัก -->
      <h6 class="section-title">น้ำหนัก - คำนวดอัตโนมัติ</h6>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">น้ำหนักต่อแผ่น (กก.)</label>
          <input type="number" step="0.0001" class="form-control calculated-field" id="weight_kg_per_sheet" name="weight_kg_per_sheet" value="<?= sanitizeOutput($specific_data['Weight_kg_per_sheet']) ?>" readonly>
          <small class="text-muted">คำนวดจาก: (W_mm × L_mm × GSM) ÷ 1,000,000</small>
        </div>
      </div>
    </div>
  </div>

  <?php elseif ($group_id == GROUP_INK): ?>
  <!-- Ink Section -->
  <div class="card mt-4">
    <div class="card-header"><h5 class="mb-0"><i class="fas fa-paint-brush me-2"></i>ข้อมูลจำเพาะ - หมึกพิมพ์</h5></div>
    <div class="card-body">
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">ประเภทหมึก <span class="text-danger">*</span></label>
          <select class="form-select" id="ink_type" name="ink_type" required>
            <option value="">เลือกประเภท</option>
            <?php foreach (['UV','Water-based','Solvent','Oil-based','Eco-solvent','Latex'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($specific_data['ink_type'] == $opt) ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">สี <span class="text-danger">*</span></label>
          <select class="form-select" id="ink_color" name="ink_color" required>
            <option value="">เลือกสี</option>
            <?php foreach (['CMYK','Cyan','Magenta','Yellow','Black','White','Pantone','Metallic','Fluorescent'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($specific_data['Color'] == $opt) ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">กลุ่มหมึก <span class="text-danger">*</span></label>
          <select class="form-select" id="ink_group" name="ink_group" required>
            <option value="">เลือกกลุ่ม</option>
            <?php foreach (['Process','Spot Color','Special Effect','Security','Food Safe'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($specific_data['Ink_Group'] == $opt) ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">ด้านที่พิมพ์ <span class="text-danger">*</span></label>
          <select class="form-select" id="ink_side" name="ink_side" required>
            <option value="">เลือกด้าน</option>
            <?php foreach (['1 Side','2 Sides','Front Only','Back Only'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($specific_data['Side'] == $opt) ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">GSM</label>
          <input type="number" class="form-control" id="gsm_ink" name="gsm_ink" value="<?= sanitizeOutput($specific_data['gsm']) ?>" min="50" max="1000">
        </div>
      </div>
    </div>
  </div>

  <?php elseif ($group_id == GROUP_FOIL): ?>
  <!-- Foil Section -->
  <div class="card mt-4">
    <div class="card-header"><h5 class="mb-0"><i class="fas fa-certificate me-2"></i>ข้อมูลจำเพาะ - ฟอยล์</h5></div>
    <div class="card-body">
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">รหัสฟอยล์ <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="foil_code" name="foil_code" value="<?= sanitizeOutput($specific_data['Foil_Code']) ?>" required maxlength="50">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">สี <span class="text-danger">*</span></label>
          <select class="form-select" id="foil_color" name="foil_color" required>
            <option value="">เลือกสี</option>
            <?php foreach (['Gold','Silver','Black','White','Red','Blue','Green','Purple','Rose Gold','Holographic'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($specific_data['Color'] == $opt) ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      
      <h6 class="section-title mt-3">ขนาดฟอยล์</h6>
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">ความกว้าง (มม.) <span class="text-danger">*</span></label>
          <input type="number" step="0.01" class="form-control" id="foil_w_mm" name="foil_w_mm" value="<?= sanitizeOutput($specific_data['W_mm']) ?>" required min="10" max="5000">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">ความยาว (เมตร) <span class="text-danger">*</span></label>
          <input type="number" step="0.01" class="form-control" id="foil_l_m" name="foil_l_m" value="<?= sanitizeOutput($specific_data['L_m']) ?>" required min="0.1" max="1000">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">พื้นที่ (ตร.ม.)</label>
          <input type="number" step="0.01" class="form-control calculated-field" id="foil_m2" name="foil_m2" value="<?= sanitizeOutput($specific_data['m2']) ?>" readonly>
          <small class="text-muted">คำนวดอัตโนมัติ</small>
        </div>
      </div>
      
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">เอฟเฟกต์ <span class="text-danger">*</span></label>
          <select class="form-select" id="foil_effect" name="foil_effect" required>
            <option value="">เลือกเอฟเฟกต์</option>
            <?php foreach (['Shiny','Matte','Brushed','Holographic','Rainbow','Pattern','Metallic'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($specific_data['Effect'] == $opt) ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <?php elseif ($group_id == GROUP_PLATE): ?>
  <!-- Plate Section -->
  <div class="card mt-4">
    <div class="card-header"><h5 class="mb-0"><i class="fas fa-square me-2"></i>ข้อมูลจำเพาะ - แผ่นพิมพ์</h5></div>
    <div class="card-body">
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">แบรนด์แผ่นพิมพ์ <span class="text-danger">*</span></label>
          <select class="form-select" id="brand_plate" name="brand_plate" required>
            <option value="">เลือกแบรนด์</option>
            <?php foreach (['Agfa','Kodak','Fujifilm','Presstek','Huaguang','Lucky','Toray','Other'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($specific_data['Brand_plate'] == $opt || ($opt == 'Other' && !in_array($specific_data['Brand_plate'], ['Agfa','Kodak','Fujifilm','Presstek','Huanguang','Lucky','Toray']))) ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 mb-3" id="otherBrandGroup" style="display:<?= (!in_array($specific_data['Brand_plate'], ['Agfa','Kodak','Fujifilm','Presstek','Huaguang','Lucky','Toray']) && !empty($specific_data['Brand_plate'])) ? 'block' : 'none' ?>;">
          <label class="form-label">ระบุแบรนด์อื่นๆ</label>
          <input type="text" class="form-control" id="other_brand_plate" name="other_brand_plate" value="<?= (!in_array($specific_data['Brand_plate'], ['Agfa','Kodak','Fujifilm','Presstek','Huaguang','Lucky','Toray'])) ? sanitizeOutput($specific_data['Brand_plate']) : '' ?>" maxlength="100">
        </div>
      </div>
      
      <h6 class="section-title mt-3">ขนาดแผ่นพิมพ์</h6>
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label">ความกว้าง (มม.) <span class="text-danger">*</span></label>
          <input type="number" step="0.01" class="form-control" id="plate_w_mm" name="plate_w_mm" value="<?= sanitizeOutput($specific_data['W_mm']) ?>" required min="10" max="5000">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">ความยาว (มม.) <span class="text-danger">*</span></label>
          <input type="number" step="0.01" class="form-control" id="plate_length_mm" name="plate_length_mm" value="<?= sanitizeOutput($specific_data['Length_mm']) ?>" required min="10" max="5000">
        </div>
        <div class="col-md-4 mb-3">
          <label class="form-label">ความหนา (มม.) <span class="text-danger">*</span></label>
          <input type="number" step="0.01" class="form-control" id="plate_thickness_mm" name="plate_thickness_mm" value="<?= sanitizeOutput($specific_data['Thickness_mm']) ?>" required min="0.01" max="5.0">
        </div>
      </div>
    </div>
  </div>

  <?php else: ?>
  <!-- No Specific Data or Other Material Types -->
  <div class="card mt-4">
    <div class="card-body">
      <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>ไม่มีข้อมูลจำเพาะสำหรับกลุ่มนี้หรือยังไม่รองรับการแก้ไข
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php else: ?>
  <!-- No Specific Data Available -->
  <div class="card mt-4">
    <div class="card-body">
      <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>ไม่พบข้อมูลจำเพาะสำหรับรายการนี้
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Submit Buttons -->
<div class="card mt-4">
  <div class="card-body d-flex justify-content-between">
    <div>
      <a href="view.php?id=<?= (int)$product_id ?>" class="btn btn-secondary"><i class="fas fa-times me-2"></i>ยกเลิก</a>
      <a href="list.php" class="btn btn-outline-secondary ms-2"><i class="fas fa-arrow-left me-2"></i>กลับสู่รายการ</a>
    </div>
    <div>
      <button type="button" class="btn btn-outline-primary me-2" onclick="resetForm()"><i class="fas fa-redo me-2"></i>รีเซ็ต</button>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>บันทึกการแก้ไข</button>
    </div>
  </div>
</div>
      </form>
    </div>
  </div>

<?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Group constants
const GROUP_PAPERBOARD = 255, GROUP_INK=256, GROUP_COATING=257, GROUP_ADHESIVE=258, GROUP_FILM=259,
      GROUP_CORRUGATED=260, GROUP_FOIL=261, GROUP_PLATE=262, GROUP_PAPER_501=501, GROUP_PAPER_551=551,
      GROUP_PAPER_801=801, GROUP_PAPER_802=802, GROUP_PAPER_803=803, GROUP_PAPER_804=804;

function sanitizeNumber(v,min=0,max=999999){const n=parseFloat(v);return (isNaN(n)||n<min||n>max)?null:n;}

// ✅ Main calculation functions
function calculateInches(){
  const wMm = sanitizeNumber(document.getElementById('w_mm')?.value,0,5000)||0;
  const lMm = sanitizeNumber(document.getElementById('l_mm')?.value,0,5000)||0;
  const wInch = wMm/25.4, lInch = lMm/25.4;
  const wIn = document.getElementById('w_inch'), lIn = document.getElementById('l_inch');
  if(wIn) wIn.value = wMm? wInch.toFixed(4):'';
  if(lIn) lIn.value = lMm? lInch.toFixed(4):'';
  calculateWeight();
}

function calculateWeight(){
  const wMm = sanitizeNumber(document.getElementById('w_mm')?.value,0,5000)||0;
  const lMm = sanitizeNumber(document.getElementById('l_mm')?.value,0,5000)||0;
  const gsm = sanitizeNumber(document.getElementById('gsm')?.value,50,1000)||0;
  const out = document.getElementById('weight_kg_per_sheet');
  if(out){
    if(wMm>0 && lMm>0 && gsm>0){
      const weight = (wMm*lMm*gsm)/1000000;
      out.value = weight.toFixed(4);
    } else {
      out.value = '';
    }
  }
}

function calculateFoilArea(){
  const wMm = sanitizeNumber(document.getElementById('foil_w_mm')?.value,0,5000)||0;
  const lM  = sanitizeNumber(document.getElementById('foil_l_m')?.value,0,1000)||0;
  const out = document.getElementById('foil_m2');
  if(out){
    if(wMm>0 && lM>0){ out.value = ((wMm*lM)/1000).toFixed(2); } else { out.value=''; }
  }
}

function handlePlateBrand(){
  const sel = document.getElementById('brand_plate');
  const grp = document.getElementById('otherBrandGroup');
  const fld = document.getElementById('other_brand_plate');
  if(!sel||!grp) return;
  if(sel.value==='Other'){ grp.style.display='block'; if(fld) fld.required=true; }
  else { grp.style.display='none'; if(fld) fld.required=false; }
}

// ✅ Paper subgroup change handler
function handlePaperSubgroupChange() {
  const subgroupSelect = document.getElementById('paper_subgroup');
  if (!subgroupSelect) return;
  
  const selectedValue = subgroupSelect.value;
  const selectedOption = subgroupSelect.options[subgroupSelect.selectedIndex];
  const subgroupName = selectedOption.text;
  
  if (selectedValue) {
    console.log(`✅ Paper Subgroup Selected: ${subgroupName} (${selectedValue})`);
    showAlert(`เลือก ${subgroupName} - การเปลี่ยนแปลงจะมีผลเมื่อบันทึกข้อมูล`, 'info');
  }
}

function showAlert(message, type = 'info') {
  const existingAlerts = document.querySelectorAll('.alert:not(.alert-dismissible)');
  existingAlerts.forEach(alert => alert.remove());
  
  const alertDiv = document.createElement('div');
  alertDiv.className = `alert alert-${type} alert-dismissible fade show mt-3`;
  alertDiv.innerHTML = `
    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
    ${message}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  `;
  
  const container = document.querySelector('.container .row .col-lg-10');
  if (container && container.children.length > 2) {
    container.insertBefore(alertDiv, container.children[2]);
  }
  
  setTimeout(() => {
    if (alertDiv.parentNode) {
      alertDiv.remove();
    }
  }, 5000);
}

// Form validation
function validateForm(){
  const required = document.querySelectorAll('input[required], select[required]');
  let ok=true, first=null;
  required.forEach(f=>{
    if(!f.value.trim()){ f.classList.add('is-invalid'); ok=false; if(!first) first=f; }
    else f.classList.remove('is-invalid');
  });
  
  const brandSel = document.getElementById('brand_plate');
  const otherFld = document.getElementById('other_brand_plate');
  if(brandSel && brandSel.value==='Other' && otherFld && !otherFld.value.trim()){
    otherFld.classList.add('is-invalid'); ok=false; if(!first) first=otherFld;
  }
  
  if(!ok && first){ 
    first.focus(); 
    first.scrollIntoView({behavior:'smooth',block:'center'}); 
    showAlert('กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน', 'danger');
  }
  return ok;
}

function resetForm(){ 
  if(confirm('ต้องการรีเซ็ตฟอร์มกลับเป็นค่าเดิมหรือไม่?')) location.reload(); 
}

// ✅ Initialize on DOM ready
document.addEventListener('DOMContentLoaded', ()=>{
  const groupId = <?= (int)$group_id ?>;
  const paperboardGroups = [GROUP_PAPERBOARD, GROUP_PAPER_501, GROUP_PAPER_551, GROUP_PAPER_801, GROUP_PAPER_802, GROUP_PAPER_803, GROUP_PAPER_804];

  // Setup event listeners based on group type
  if(paperboardGroups.includes(groupId)){
    // Paperboard calculations
    ['w_mm','l_mm','gsm'].forEach(id=>{
      const el = document.getElementById(id);
      if(!el) return;
      el.addEventListener('input', id==='gsm'? calculateWeight: calculateInches);
    });
    
    // Paper subgroup change handler
    const paperSubgroupSelect = document.getElementById('paper_subgroup');
    if (paperSubgroupSelect) {
      paperSubgroupSelect.addEventListener('change', handlePaperSubgroupChange);
    }
    
    // Initial calculation
    calculateInches();
  }

  if(groupId===GROUP_FOIL){
    // Foil area calculation
    ['foil_w_mm','foil_l_m'].forEach(id=>{
      const el = document.getElementById(id);
      if(el) el.addEventListener('input', calculateFoilArea);
    });
    calculateFoilArea();
  }

  if(groupId===GROUP_PLATE){
    // Plate brand handling
    const sel = document.getElementById('brand_plate');
    if(sel){ sel.addEventListener('change', handlePlateBrand); handlePlateBrand(); }
  }

  // Form submission
  document.getElementById('editForm')?.addEventListener('submit', function(e){
    if(!validateForm()){ e.preventDefault(); return; }
    
    const btn = this.querySelector('button[type="submit"]');
    const txt = btn.innerHTML;
    btn.disabled = true; 
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>กำลังบันทึก...';
    
    setTimeout(()=>{ btn.disabled=false; btn.innerHTML=txt; }, 10000);
  });

  // Real-time validation
  document.querySelectorAll('input,select').forEach(f=>{
    f.addEventListener('blur', ()=>{ 
      if(f.hasAttribute('required') && !f.value.trim()) f.classList.add('is-invalid');
    });
    f.addEventListener('input', ()=>{ 
      if(f.classList.contains('is-invalid') && f.value.trim()) f.classList.remove('is-invalid'); 
    });
  });

  // Keyboard shortcuts
  document.addEventListener('keydown', (e)=>{
    if(e.ctrlKey && e.key==='s'){ 
      e.preventDefault(); 
      if(validateForm()) document.getElementById('editForm').submit(); 
    }
    if(e.key==='Escape'){ 
      window.location.href = 'view.php?id=<?= (int)$product_id ?>'; 
    }
  });

  console.log('✅ Edit form initialized for group:', groupId);
});
</script>
</body>
</html>