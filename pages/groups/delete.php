<?php
// pages/groups/delete.php - ลบกลุ่มวัสดุ
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole('admin'); // เฉพาะ admin เท่านั้น

// ข้อมูลผู้ใช้
$user_id = $_SESSION['user_id'] ?? 'N/A';
$group_id = $_GET['id'] ?? '';

// ตรวจสอบ ID
if (empty($group_id)) {
    header("Location: index.php?message=" . urlencode("ไม่พบรหัสกลุ่มวัสดุ") . "&type=danger");
    exit;
}

try {
    if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
        require_once "../../config/database.php";
        $database = new Database();
        $conn = $database->getConnection();
        
        // ดึงข้อมูลกลุ่มก่อนลบ
        $stmt = $conn->prepare("SELECT id, name, is_active FROM Groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$group) {
            header("Location: index.php?message=" . urlencode("ไม่พบข้อมูลกลุ่มวัสดุ") . "&type=danger");
            exit;
        }
        
        // ตรวจสอบว่ากลุ่มนี้ยังใช้งานอยู่หรือไม่
        if ($group['is_active'] == 1) {
            header("Location: index.php?message=" . urlencode("ไม่สามารถลบกลุ่มวัสดุที่ยังใช้งานอยู่ได้ กรุณาปิดการใช้งานก่อน") . "&type=warning");
            exit;
        }
        
        // ตรวจสอบว่ามีวัสดุในกลุ่มนี้หรือไม่
        try {
            $materialCheckStmt = $conn->prepare("SELECT COUNT(*) as material_count FROM Master_Products_ID WHERE group_id = ?");
            $materialCheckStmt->execute([$group_id]);
            $materialCountResult = $materialCheckStmt->fetch();
            $materialCount = $materialCountResult['material_count'] ?? 0;
            
            if ($materialCount > 0) {
                header("Location: index.php?message=" . urlencode("ไม่สามารถลบกลุ่มวัสดุที่มีวัสดุ {$materialCount} รายการเชื่อมโยงอยู่") . "&type=warning");
                exit;
            }
        } catch (Exception $e) {
            // ถ้าไม่มีตาราง Master_Products_ID ให้ดำเนินการต่อ
        }
        
        // ลบกลุ่มวัสดุ
        $deleteStmt = $conn->prepare("DELETE FROM Groups WHERE id = ?");
        $deleteStmt->execute([$group_id]);
        
        // ตรวจสอบผลลัพธ์
        if ($deleteStmt->rowCount() > 0) {
            $message = "ลบกลุ่มวัสดุ '{$group['name']}' เรียบร้อยแล้ว";
            $message_type = "success";
            
            // บันทึก log (ถ้าต้องการ)
            error_log("Group deleted: ID={$group_id}, Name={$group['name']}, User={$user_id}");
        } else {
            $message = "ไม่สามารถลบกลุ่มวัสดุได้";
            $message_type = "danger";
        }
        
    } else {
        throw new Exception("ไม่สามารถเชื่อมต่อฐานข้อมูลได้");
    }
    
} catch (Exception $e) {
    $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
    $message_type = "danger";
}

// เปลี่ยนเส้นทางกลับไปหน้ารายการ
header("Location: index.php?message=" . urlencode($message) . "&type=" . $message_type);
exit;
?>