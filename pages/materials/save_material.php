<?php
// production/pages/materials/save_material.php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../classes/Auth.php';

// ตรวจสอบ authentication
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['editor', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
   header('Location: add.php');
   exit;
}

try {
   $database = new Database();
   $conn = $database->getConnection();
   
   // รับข้อมูลจากฟอร์ม
   $material_type_id = $_POST['material_type_id'] ?? null;
   $group_id = $_POST['group_id'] ?? null; // ใช้ group_id ตามโครงสร้างเดิม
   $supplier_id = $_POST['supplier_id'] ?? null;
   $unit_id = $_POST['unit_id'] ?? null;
   $name = trim($_POST['name'] ?? '');
   $name2 = trim($_POST['name2'] ?? '');
   
   // Validation
   if (!$material_type_id || !$group_id || !$supplier_id || !$unit_id || !$name) {
       throw new Exception('กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน');
   }
   
   $conn->beginTransaction();
   
   // สร้าง SSP Code
   $ssp_code = $_POST['ssp_code'] ?? null;
if (!$ssp_code) {
    throw new Exception('ไม่พบ SSP Code');
}
   
// Insert Master_Products_ID - เพิ่ม main_group_id และ sub_group_id
$insertProduct = "INSERT INTO Master_Products_ID 
                  (SSP_Code, Name, Name2, group_id, main_group_id, sub_group_id, 
                   material_type_id, supplier_id, run_number, Unit_id, status, 
                   is_active, created_by, created_date) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, GETDATE())";

                $main_group_id = null;
                $sub_group_id = null;
   
// ถ้าเป็นกลุ่มหลัก (255-262) 
if ($group_id >= 255 && $group_id <= 262) {
    $main_group_id = $group_id;
    // ถ้าเป็นกระดาษและมี paper_subgroup_id
    if ($group_id == 255 && !empty($_POST['paper_subgroup_id'])) {
        $sub_group_id = $_POST['paper_subgroup_id'];
    }
}
// ถ้าเป็นกลุ่มย่อย (501, 551, 801-804)
elseif (in_array($group_id, [501, 551, 801, 802, 803, 804])) {
    $main_group_id = 255; // Paperboard
    $sub_group_id = $group_id;
}

$stmt = $conn->prepare($insertProduct);
$result = $stmt->execute([
    $ssp_code,  // ใช้ ssp_code จาก POST แทน
    $name,
    $name2,
    $group_id,
    $main_group_id,
    $sub_group_id,
    $material_type_id,
    $supplier_id,
    substr($ssp_code, -5), // ดึง run number จาก SSP Code
    $unit_id,
    $_SESSION['user_id']
]);
   
   if (!$result) {
       throw new Exception('ไม่สามารถบันทึกข้อมูลหลักได้');
   }
   
   // ดึง product_id ที่เพิ่งสร้าง - SQL Server method
$getIdQuery = "SELECT id FROM Master_Products_ID WHERE SSP_Code = ?";
$getIdStmt = $conn->prepare($getIdQuery);
$getIdStmt->execute([$ssp_code]);
$row = $getIdStmt->fetch(PDO::FETCH_ASSOC);
   
   if (!$row) {
       throw new Exception('ไม่สามารถดึง Product ID ได้');
   }
   
   $product_id = $row['id'];
   
   // Insert specific data based on material type
   if ($group_id == '255') { // Paperboard
       insertPaperboardData($conn, $product_id, $_POST);
   } elseif ($group_id == '256') { // Ink
       insertInkData($conn, $product_id, $_POST);
   }
   
   $conn->commit();
   
   // Redirect with success message
   $_SESSION['success_message'] = "เพิ่มข้อมูล Material สำเร็จ! SSP Code: " . $ssp_code;
   header('Location: list.php');
   exit;
   
} catch (Exception $e) {
   if (isset($conn)) {
       $conn->rollBack();
   }
   
   error_log("Save Material Error: " . $e->getMessage());
   error_log("POST Data: " . print_r($_POST, true));
   
   $_SESSION['error_message'] = $e->getMessage();
   header('Location: add.php');
   exit;
}

function insertPaperboardData($conn, $product_id, $data) {
   $insert = "INSERT INTO Specific_Paperboard 
              (product_id, W_mm, L_mm, L_inch, W_inch, gsm, Caliper, brand, brand2,
               type_paperboard_TH, type_paperboard_EN, laminated1, laminated2, 
               Certificated, Weight_kg_per_sheet, paper_subgroup_id, is_active, 
               created_by, created_date) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, GETDATE())";
   
   $stmt = $conn->prepare($insert);
   $result = $stmt->execute([
       $product_id,
       !empty($data['w_mm']) ? (float)$data['w_mm'] : null,
       !empty($data['l_mm']) ? (float)$data['l_mm'] : null,
       !empty($data['l_inch']) ? (float)$data['l_inch'] : null,
       !empty($data['w_inch']) ? (float)$data['w_inch'] : null,
       !empty($data['gsm']) ? (int)$data['gsm'] : null,
       !empty($data['caliper']) ? (int)$data['caliper'] : null,
       !empty($data['brand']) ? trim($data['brand']) : null,
       !empty($data['brand2']) ? trim($data['brand2']) : null, // เพิ่มบรรทัดนี้
       !empty($data['type_paperboard_th']) ? trim($data['type_paperboard_th']) : null,
       !empty($data['type_paperboard_en']) ? trim($data['type_paperboard_en']) : null,
       !empty($data['laminated1']) ? trim($data['laminated1']) : null,
       !empty($data['laminated2']) ? trim($data['laminated2']) : null,
       !empty($data['certificated']) ? trim($data['certificated']) : null,
       !empty($data['weight_kg_per_sheet']) ? (float)$data['weight_kg_per_sheet'] : null,
       !empty($data['paper_subgroup_id']) ? (int)$data['paper_subgroup_id'] : null,
       $_SESSION['user_id']
   ]);
   
   if (!$result) {
       throw new Exception('ไม่สามารถบันทึกข้อมูลกระดาษได้');
   }
}

function insertInkData($conn, $product_id, $data) {
   $insert = "INSERT INTO Specific_Ink 
              (product_id, ink_type, Color, Ink_Group, Brand_paperboard, 
               type_paperboard, gsm, Side, laminated1, laminated2, 
               Coating1, Coating2, is_active, created_by, created_date) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, GETDATE())";
   
   $stmt = $conn->prepare($insert);
   $result = $stmt->execute([
       $product_id,
       !empty($data['ink_type']) ? trim($data['ink_type']) : null,
       !empty($data['color']) ? trim($data['color']) : null,
       !empty($data['ink_group']) ? trim($data['ink_group']) : null,
       !empty($data['brand_paperboard']) ? trim($data['brand_paperboard']) : null,
       !empty($data['type_paperboard']) ? trim($data['type_paperboard']) : null,
       !empty($data['gsm']) ? (int)$data['gsm'] : null,
       !empty($data['side']) ? trim($data['side']) : null,
       !empty($data['laminated1']) ? trim($data['laminated1']) : null,
       !empty($data['laminated2']) ? trim($data['laminated2']) : null,
       !empty($data['coating1']) ? trim($data['coating1']) : null,
       !empty($data['coating2']) ? trim($data['coating2']) : null,
       $_SESSION['user_id']
   ]);
   
   if (!$result) {
       throw new Exception('ไม่สามารถบันทึกข้อมูลหมึกได้');
   }
}
?>