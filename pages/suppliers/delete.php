<?php
// pages/suppliers/delete.php - ลบซัพพลายเออร์
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();
$auth->requireRole('admin'); // เฉพาะ admin เท่านั้น

// ข้อมูลผู้ใช้
$user_id = $_SESSION['user_id'] ?? 'N/A';
$supplier_id = $_GET['id'] ?? '';

// ตรวจสอบ ID
if (empty($supplier_id) || !is_numeric($supplier_id)) {
    header("Location: index.php?message=" . urlencode("ไม่พบรหัสซัพพลายเออร์") . "&type=danger");
    exit;
}

try {
    if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
        require_once "../../config/database.php";
        $database = new Database();
        $conn = $database->getConnection();
        
        // ดึงข้อมูลซัพพลายเออร์ก่อนลบ
        $stmt = $conn->prepare("SELECT supplier_id, supplier_code, supplier_name, is_active FROM Suppliers WHERE supplier_id = ?");
        $stmt->execute([$supplier_id]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$supplier) {
            header("Location: index.php?message=" . urlencode("ไม่พบข้อมูลซัพพลายเออร์") . "&type=danger");
            exit;
        }
        
        // ตรวจสอบว่าซัพพลายเออร์นี้ยังใช้งานอยู่หรือไม่
        if ($supplier['is_active'] == 1) {
            header("Location: index.php?message=" . urlencode("ไม่สามารถลบซัพพลายเออร์ที่ยังใช้งานอยู่ได้ กรุณาปิดการใช้งานก่อน") . "&type=warning");
            exit;
        }
        
        // ตรวจสอบว่ามีวัสดุที่ใช้ซัพพลายเออร์นี้หรือไม่
        try {
            $materialCheckStmt = $conn->prepare("SELECT COUNT(*) as material_count FROM Master_Products_ID WHERE supplier_id = ?");
            $materialCheckStmt->execute([$supplier_id]);
            $materialCountResult = $materialCheckStmt->fetch();
            $materialCount = $materialCountResult['material_count'] ?? 0;
            
            if ($materialCount > 0) {
                header("Location: index.php?message=" . urlencode("ไม่สามารถลบซัพพลายเออร์ที่มีวัสดุ {$materialCount} รายการเชื่อมโยงอยู่") . "&type=warning");
                exit;
            }
        } catch (Exception $e) {
            // ถ้าไม่มีตาราง Master_Products_ID ให้ดำเนินการต่อ
        }
        
        // ลบซัพพลายเออร์
        $deleteStmt = $conn->prepare("DELETE FROM Suppliers WHERE supplier_id = ?");
        $deleteStmt->execute([$supplier_id]);
        
        // ตรวจสอบผลลัพธ์
        if ($deleteStmt->rowCount() > 0) {
            $message = "ลบซัพพลายเออร์ '{$supplier['supplier_name']}' เรียบร้อยแล้ว";
            $message_type = "success";
            
            // บันทึก log (ถ้าต้องการ)
            error_log("Supplier deleted: ID={$supplier_id}, Code={$supplier['supplier_code']}, Name={$supplier['supplier_name']}, User={$user_id}");
        } else {
            $message = "ไม่สามารถลบซัพพลายเออร์ได้";
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