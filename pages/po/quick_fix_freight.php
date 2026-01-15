<?php
// pages/po/quick_fix_freight.php - แก้ไข Freight PO ที่มูลค่า 0
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();

try {
    $pdo = new PDO("sqlsrv:server=" . DB_SERVER . ";Database=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h3>🔧 Quick Fix Freight PO</h3>";
    echo "<hr>";
    
    if (isset($_POST['fix_all'])) {
        $pdo->beginTransaction();
        
        // หา Freight POs ที่มูลค่า 0
        $stmt = $pdo->query("
            SELECT po_id, po_number 
            FROM PO_Header 
            WHERE po_number LIKE '%-F%' AND total_amount = 0
        ");
        $zero_freight_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $fixed_count = 0;
        
        foreach ($zero_freight_pos as $po) {
            // กำหนดมูลค่าเริ่มต้น
            $freight_amount = 5000; // 5,000 บาท
            $tax_amount = $freight_amount * 0.07;
            $total_amount = $freight_amount + $tax_amount;
            
            // อัปเดต PO Header
            $stmt = $pdo->prepare("
                UPDATE PO_Header 
                SET freight_amount = ?, 
                    total_amount = ?, 
                    net_amount = ?,
                    tax_amount = ?
                WHERE po_id = ?
            ");
            $stmt->execute([$freight_amount, $total_amount, $total_amount, $tax_amount, $po['po_id']]);
            
            // ตรวจสอบว่ามี PO_Items หรือไม่
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM PO_Items WHERE po_id = ?");
            $stmt->execute([$po['po_id']]);
            $item_count = $stmt->fetchColumn();
            
            // ถ้าไม่มี PO_Items ให้เพิ่ม
            if ($item_count == 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO PO_Items (po_id, line_number, item_type_id, item_description, 
                                        quantity, purchase_unit_id, unit_price, total_price, 
                                        status, pending_quantity)
                    VALUES (?, 1, 2, ?, 1, 1, ?, ?, 'PENDING', 1)
                ");
                
                $description = "ค่าขนส่งทางเรือ - แก้ไขข้อมูล";
                $stmt->execute([$po['po_id'], $description, $freight_amount, $freight_amount]);
            }
            
            $fixed_count++;
            echo "<p>✅ แก้ไข {$po['po_number']} สำเร็จ (มูลค่า: " . number_format($total_amount, 2) . " บาท)</p>";
        }
        
        $pdo->commit();
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h4>🎉 แก้ไขเสร็จสิ้น!</h4>";
        echo "<p>แก้ไข Freight PO จำนวน <strong>$fixed_count</strong> รายการ</p>";
        echo "<p><a href='list.php' class='btn btn-primary'>ดูรายการ PO</a></p>";
        echo "</div>";
        
    } else {
        // แสดงรายการ PO ที่ต้องแก้ไข
        $stmt = $pdo->query("
            SELECT po_id, po_number, total_amount, created_date
            FROM PO_Header 
            WHERE po_number LIKE '%-F%' AND total_amount = 0
            ORDER BY created_date DESC
        ");
        $zero_freight_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h4>📋 Freight POs ที่มูลค่า 0:</h4>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 20px 0;'>";
        echo "<tr style='background: #f8f9fa;'>";
        echo "<th style='padding: 10px;'>PO Number</th>";
        echo "<th style='padding: 10px;'>Total Amount</th>";
        echo "<th style='padding: 10px;'>Created Date</th>";
        echo "</tr>";
        
        if (empty($zero_freight_pos)) {
            echo "<tr><td colspan='3' style='text-align: center; padding: 20px;'>ไม่มี Freight PO ที่มูลค่า 0</td></tr>";
        } else {
            foreach ($zero_freight_pos as $po) {
                echo "<tr>";
                echo "<td style='padding: 8px;'>{$po['po_number']}</td>";
                echo "<td style='padding: 8px; text-align: center;'>" . number_format($po['total_amount'], 2) . "</td>";
                echo "<td style='padding: 8px;'>{$po['created_date']}</td>";
                echo "</tr>";
            }
        }
        echo "</table>";
        
        if (!empty($zero_freight_pos)) {
            echo "<form method='POST' style='margin: 20px 0;'>";
            echo "<div style='background: #fff3cd; padding: 20px; border-radius: 8px; border: 1px solid #ffeaa7;'>";
            echo "<h5>🛠️ แก้ไขอัตโนมัติ</h5>";
            echo "<p>ระบบจะแก้ไข Freight POs ทั้งหมดด้วยการ:</p>";
            echo "<ul>";
            echo "<li>กำหนดมูลค่าเริ่มต้น 5,000 บาท</li>";
            echo "<li>คำนวณภาษี VAT 7% (350 บาท)</li>";
            echo "<li>ยอดรวมสุทธิ 5,350 บาท</li>";
            echo "<li>เพิ่มรายการ 'ค่าขนส่งทางเรือ' ใน PO_Items</li>";
            echo "</ul>";
            echo "<button type='submit' name='fix_all' class='btn btn-warning' ";
            echo "onclick='return confirm(\"ต้องการแก้ไข Freight POs ทั้งหมดหรือไม่?\")' ";
            echo "style='background: #ffc107; color: #000; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>";
            echo "🔧 แก้ไขทั้งหมด (" . count($zero_freight_pos) . " รายการ)";
            echo "</button>";
            echo "</div>";
            echo "</form>";
        }
    }
    
} catch (PDOException $e) {
    if (isset($pdo)) {
        $pdo->rollback();
    }
    echo "<p style='color: red;'>เกิดข้อผิดพลาด: " . $e->getMessage() . "</p>";
}
?>

<style>
    body { 
        font-family: Arial, sans-serif; 
        margin: 20px; 
        background: #f8f9fa;
    }
    h3, h4, h5 { 
        color: #333; 
    }
    table { 
        background: white; 
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    th { 
        background: #e9ecef; 
        font-weight: bold;
    }
    .btn {
        display: inline-block;
        text-decoration: none;
        padding: 8px 16px;
        border-radius: 4px;
        color: white;
        background: #007bff;
        border: none;
        cursor: pointer;
    }
    .btn-primary {
        background: #007bff;
    }
    .btn-warning {
        background: #ffc107;
        color: #000;
    }
</style>