<?php
// pages/po/debug_freight.php - ตรวจสอบ Freight PO ที่มูลค่า 0
require_once "../../config/config.php";
require_once "../../classes/Auth.php";

$auth = new Auth();
$auth->requireLogin();

try {
    $pdo = new PDO("sqlsrv:server=" . DB_SERVER . ";Database=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h3>🔍 Debug Freight PO ปัญหามูลค่า 0</h3>";
    echo "<hr>";
    
    // 1. ตรวจสอบ Freight POs ที่มูลค่า 0
    echo "<h4>1. Freight POs ที่มูลค่า 0:</h4>";
    $stmt = $pdo->query("
        SELECT po_id, po_number, total_amount, material_amount, freight_amount, service_amount
        FROM PO_Header 
        WHERE po_number LIKE '%-F%' AND total_amount = 0
        ORDER BY po_id DESC
    ");
    $zero_freight_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>PO ID</th><th>PO Number</th><th>Total</th><th>Material</th><th>Freight</th><th>Service</th></tr>";
    foreach ($zero_freight_pos as $po) {
        echo "<tr>";
        echo "<td>{$po['po_id']}</td>";
        echo "<td>{$po['po_number']}</td>";
        echo "<td>{$po['total_amount']}</td>";
        echo "<td>{$po['material_amount']}</td>";
        echo "<td>{$po['freight_amount']}</td>";
        echo "<td>{$po['service_amount']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 2. ตรวจสอบ PO_Items ของ Freight POs
    echo "<h4>2. รายการ PO_Items ของ Freight POs:</h4>";
    $freight_po_ids = array_column($zero_freight_pos, 'po_id');
    
    if (!empty($freight_po_ids)) {
        $placeholders = str_repeat('?,', count($freight_po_ids) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT pi.po_id, ph.po_number, pi.line_number, pi.item_description, 
                   pi.quantity, pi.unit_price, pi.total_price
            FROM PO_Items pi
            JOIN PO_Header ph ON pi.po_id = ph.po_id
            WHERE pi.po_id IN ($placeholders)
            ORDER BY pi.po_id, pi.line_number
        ");
        $stmt->execute($freight_po_ids);
        $freight_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>PO Number</th><th>Line</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr>";
        foreach ($freight_items as $item) {
            echo "<tr>";
            echo "<td>{$item['po_number']}</td>";
            echo "<td>{$item['line_number']}</td>";
            echo "<td>{$item['item_description']}</td>";
            echo "<td>{$item['quantity']}</td>";
            echo "<td>{$item['unit_price']}</td>";
            echo "<td>{$item['total_price']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        if (empty($freight_items)) {
            echo "<p style='color: red;'><strong>❌ ไม่พบรายการ PO_Items ใน Freight POs!</strong></p>";
            echo "<p>นี่คือสาเหตุที่ Freight PO มีมูลค่า 0</p>";
        }
    }
    
    // 3. ตรวจสอบ PO Types
    echo "<h4>3. ตรวจสอบ PO Types:</h4>";
    $stmt = $pdo->query("SELECT * FROM PO_Types ORDER BY po_type_id");
    $po_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>Code</th><th>Name</th><th>Category</th><th>Active</th></tr>";
    foreach ($po_types as $type) {
        echo "<tr>";
        echo "<td>{$type['po_type_id']}</td>";
        echo "<td>{$type['type_code']}</td>";
        echo "<td>{$type['type_name']}</td>";
        echo "<td>" . ($type['cost_category'] ?? 'N/A') . "</td>";
        echo "<td>" . ($type['is_active'] ?? 'N/A') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 4. ตรวจสอบ Item Types
    echo "<h4>4. ตรวจสอบ PO Item Types:</h4>";
    $stmt = $pdo->query("SELECT * FROM PO_Item_Types ORDER BY item_type_id");
    $item_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>Code</th><th>Name</th><th>Active</th></tr>";
    foreach ($item_types as $type) {
        echo "<tr>";
        echo "<td>{$type['item_type_id']}</td>";
        echo "<td>{$type['type_code']}</td>";
        echo "<td>{$type['type_name']}</td>";
        echo "<td>" . ($type['is_active'] ?? 'N/A') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 5. แนะนำการแก้ไข
    echo "<h4>5. 🔧 การแก้ไขที่แนะนำ:</h4>";
    echo "<ol>";
    echo "<li><strong>ตรวจสอบการส่งข้อมูลฟอร์ม:</strong> ข้อมูลจากฟอร์ม Freight PO อาจไม่ถูกส่งมาให้ PHP</li>";
    echo "<li><strong>แก้ไข Logic การบันทึก:</strong> การบันทึก PO_Items สำหรับ Freight PO อาจมีปัญหา</li>";
    echo "<li><strong>เพิ่ม Debug Log:</strong> ใส่ log เพื่อดูว่าข้อมูลไหนหายไป</li>";
    echo "<li><strong>ตรวจสอบ JavaScript:</strong> การคำนวณใน Frontend อาจไม่ทำงาน</li>";
    echo "</ol>";
    
    // 6. Script สำหรับแก้ไขข้อมูล
    echo "<h4>6. 🛠️ แก้ไขข้อมูลที่มีอยู่:</h4>";
    echo "<p>หากต้องการแก้ไขข้อมูล Freight PO ที่มีอยู่:</p>";
    
    if (isset($_POST['fix_freight_po'])) {
        $fix_po_id = $_POST['fix_po_id'];
        $fix_amount = $_POST['fix_amount'];
        
        try {
            $pdo->beginTransaction();
            
            // อัปเดต PO Header
            $stmt = $pdo->prepare("
                UPDATE PO_Header 
                SET freight_amount = ?, total_amount = ? * 1.07, net_amount = ? * 1.07 
                WHERE po_id = ?
            ");
            $stmt->execute([$fix_amount, $fix_amount, $fix_amount, $fix_po_id]);
            
            // เพิ่ม PO_Items ถ้าไม่มี
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM PO_Items WHERE po_id = ?");
            $stmt->execute([$fix_po_id]);
            $item_count = $stmt->fetchColumn();
            
            if ($item_count == 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO PO_Items (po_id, line_number, item_type_id, item_description, 
                                        quantity, purchase_unit_id, unit_price, total_price, 
                                        status, pending_quantity)
                    VALUES (?, 1, 2, 'ค่าขนส่ง - แก้ไขข้อมูล', 1, 1, ?, ?, 'PENDING', 1)
                ");
                $stmt->execute([$fix_po_id, $fix_amount, $fix_amount]);
            }
            
            $pdo->commit();
            echo "<p style='color: green;'>✅ แก้ไข PO ID $fix_po_id สำเร็จ!</p>";
            
        } catch (Exception $e) {
            $pdo->rollback();
            echo "<p style='color: red;'>❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<form method='POST'>";
    echo "<select name='fix_po_id'>";
    foreach ($zero_freight_pos as $po) {
        echo "<option value='{$po['po_id']}'>{$po['po_number']}</option>";
    }
    echo "</select>";
    echo "<input type='number' name='fix_amount' placeholder='ยอดเงินที่ถูกต้อง' step='0.01' required>";
    echo "<button type='submit' name='fix_freight_po'>แก้ไขยอดเงิน</button>";
    echo "</form>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>เกิดข้อผิดพลาด: " . $e->getMessage() . "</p>";
}
?>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    table { margin: 10px 0; }
    th { background: #f0f0f0; padding: 8px; }
    td { padding: 6px; }
    h4 { color: #333; margin-top: 30px; }
</style>