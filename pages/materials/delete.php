<?php
// pages/materials/delete.php - แก้ไขให้ลบเฉพาะ SSP_Code_Generator ที่เกี่ยวข้องกับวัสดุนี้เท่านั้น
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin']);

$material_id = $_POST['material_id'] ?? 0;

if (!$material_id) {
    $_SESSION['error_message'] = "ไม่พบข้อมูลวัสดุที่ต้องการลบ";
    header("Location: list.php");
    exit;
}

try {
    $pdo = new PDO("sqlsrv:server=" . DB_SERVER . ";Database=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->beginTransaction();
    
    // ดึงข้อมูลวัสดุก่อนลบ
    $stmt = $pdo->prepare("SELECT SSP_Code, Name, material_type_id, group_id, supplier_id FROM Master_Products_ID WHERE id = ?");
    $stmt->execute([$material_id]);
    $material = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$material) {
        throw new Exception("ไม่พบข้อมูลวัสดุ ID: $material_id");
    }
    
    // ตรวจสอบการใช้งานในระบบอื่น
    $usage_checks = [
        'PO_Items' => "SELECT COUNT(*) as count FROM PO_Items WHERE product_id = ?",
        'Stock_Movements' => "SELECT COUNT(*) as count FROM Stock_Movements WHERE product_id = ?", 
        'Inventory_Stock' => "SELECT COUNT(*) as count FROM Inventory_Stock WHERE product_id = ?"
    ];
    
    $has_usage = false;
    $usage_details = [];
    
    foreach ($usage_checks as $table => $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$material_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                $has_usage = true;
                $usage_details[] = "$table: {$result['count']} รายการ";
            }
        } catch (PDOException $e) {
            continue;
        }
    }
    
    if ($has_usage) {
        throw new Exception("ไม่สามารถลบวัสดุ {$material['SSP_Code']} ได้ เนื่องจากมีการใช้งานในระบบ:\n" . implode("\n", $usage_details));
    }
    
    // ลบข้อมูลจากตารางที่เกี่ยวข้อง
    $related_tables = [
        'Specific_Adhesive' => 'product_id',
        'Specific_Coating' => 'product_id', 
        'Specific_Corrugated_box' => 'product_id',
        'Specific_Film' => 'product_id',
        'Specific_Foil' => 'product_id',
        'Specific_Ink' => 'product_id',
        'Specific_Paperboard' => 'product_id',
        'Specific_Plate' => 'product_id',
        'Product_Units' => 'product_id',
        'Unit_Conversions' => 'product_id'
    ];
    
    foreach ($related_tables as $table => $column) {
        try {
            $stmt = $pdo->prepare("DELETE FROM $table WHERE $column = ?");
            $stmt->execute([$material_id]);
        } catch (PDOException $e) {
            continue;
        }
    }
    
    // *** ลบข้อมูล SSP_Code_Generator อย่างระมัดระวัง - เฉพาะที่สัมพันธ์กับวัสดุนี้เท่านั้น ***
    $ssp_deleted_count = 0;
    try {
        error_log("Starting careful SSP_Code_Generator deletion for material: " . json_encode($material));
        
        // ตรวจสอบว่าก่อนลบควรลบข้อมูลใน SSP_Code_Generator หรือไม่
        // หลักการ: ลบเฉพาะ record ที่มี combination ตรงกับวัสดุนี้เท่านั้น
        // และต้องตรวจสอบให้แน่ใจว่าไม่มีวัสดุอื่นใช้ combination เดียวกัน
        
        if (!empty($material['material_type_id']) && !empty($material['group_id']) && !empty($material['supplier_id'])) {
            
            // ตรวจสอบว่ามีวัสดุอื่นที่ใช้ combination เดียวกันหรือไม่
            $check_other_materials = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM Master_Products_ID 
                WHERE material_type_id = ? 
                  AND group_id = ? 
                  AND supplier_id = ? 
                  AND id != ?
                  AND is_active = 1
            ");
            $check_other_materials->execute([
                $material['material_type_id'], 
                $material['group_id'], 
                $material['supplier_id'], 
                $material_id
            ]);
            
            $other_materials_count = $check_other_materials->fetch()['count'];
            
            if ($other_materials_count == 0) {
                // ไม่มีวัสดุอื่นใช้ combination นี้ จึงสามารถลบได้
                $delete_sql = "DELETE FROM SSP_Code_Generator 
                              WHERE material_type_id = ? 
                                AND group_id = ? 
                                AND supplier_id = ?";
                
                // ตรวจสอบก่อนลบ
                $check_sql = "SELECT * FROM SSP_Code_Generator 
                             WHERE material_type_id = ? 
                               AND group_id = ? 
                               AND supplier_id = ?";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([$material['material_type_id'], $material['group_id'], $material['supplier_id']]);
                $records_to_delete = $check_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($records_to_delete)) {
                    error_log("Safe to delete " . count($records_to_delete) . " SSP_Code_Generator records (no other materials use this combination): " . json_encode($records_to_delete));
                    
                    // ลบข้อมูล
                    $delete_stmt = $pdo->prepare($delete_sql);
                    $delete_stmt->execute([$material['material_type_id'], $material['group_id'], $material['supplier_id']]);
                    $ssp_deleted_count = $delete_stmt->rowCount();
                    
                    error_log("Successfully deleted $ssp_deleted_count SSP_Code_Generator records");
                } else {
                    error_log("No matching SSP_Code_Generator records found for deletion");
                }
            } else {
                error_log("Cannot delete SSP_Code_Generator records - $other_materials_count other materials still use this combination (material_type: {$material['material_type_id']}, group: {$material['group_id']}, supplier: {$material['supplier_id']})");
            }
        } else {
            error_log("Cannot delete SSP_Code_Generator - missing required IDs (material_type_id: {$material['material_type_id']}, group_id: {$material['group_id']}, supplier_id: {$material['supplier_id']})");
        }
        
    } catch (PDOException $e) {
        error_log("SSP_Code_Generator deletion error: " . $e->getMessage());
        // ไม่ throw error เพื่อให้การลบข้อมูลหลักดำเนินต่อไป
    }
    
    // ลบข้อมูลหลัก
    $stmt = $pdo->prepare("DELETE FROM Master_Products_ID WHERE id = ?");
    $stmt->execute([$material_id]);
    
    // บันทึก Audit Log
    try {
        $stmt = $pdo->prepare("INSERT INTO Audit_Log (table_name, record_id, action, old_values, changed_by, changed_date) 
                               VALUES ('Master_Products_ID', ?, 'DELETE', ?, ?, GETDATE())");
        $old_values = json_encode($material);
        $stmt->execute([$material_id, $old_values, $_SESSION['user_id']]);
    } catch (PDOException $e) {
        // ถ้าไม่มีตาราง Audit_Log ก็ข้ามไป
    }
    
    $pdo->commit();
    
    $success_msg = "ลบวัสดุ {$material['SSP_Code']} - {$material['Name']} สำเร็จแล้ว";
    if ($ssp_deleted_count > 0) {
        $success_msg .= " (รวมข้อมูล SSP_Code_Generator $ssp_deleted_count รายการ)";
    } else {
        $success_msg .= " (ข้อมูล SSP_Code_Generator ยังคงอยู่เพราะมีวัสดุอื่นใช้ร่วมกัน)";
    }
    $_SESSION['success_message'] = $success_msg;
    
} catch (Exception $e) {
    $pdo->rollback();
    $_SESSION['error_message'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
    error_log("Material Delete Error: " . $e->getMessage());
}

header("Location: list.php");
exit;
?>