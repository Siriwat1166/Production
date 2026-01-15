<?php
// pages/po/test_freight_post.php - ทดสอบการส่งข้อมูล Freight PO
require_once "../../config/config.php";

echo "<h3>🧪 ทดสอบการส่งข้อมูล Freight PO</h3>";
echo "<hr>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h4>📦 ข้อมูลที่ได้รับ:</h4>";
    echo "<pre>" . print_r($_POST, true) . "</pre>";
    
    echo "<h4>🔍 ตรวจสอบ Freight Items:</h4>";
    $freight_items_found = 0;
    
    for ($i = 1; $i <= 10; $i++) {
        $cost_type = $_POST["cost_type_$i"] ?? '';
        $cost_description = $_POST["cost_description_$i"] ?? '';
        $quantity = floatval($_POST["quantity_$i"] ?? 0);
        $unit_price = floatval($_POST["unit_price_$i"] ?? 0);
        
        if (!empty($cost_type) || $quantity > 0 || $unit_price > 0) {
            echo "<p><strong>Row $i:</strong></p>";
            echo "<ul>";
            echo "<li>Cost Type: '$cost_type'</li>";
            echo "<li>Description: '$cost_description'</li>";
            echo "<li>Quantity: $quantity</li>";
            echo "<li>Unit Price: $unit_price</li>";
            echo "<li>Total: " . ($quantity * $unit_price) . "</li>";
            echo "</ul>";
            
            if (!empty($cost_type) && $quantity > 0 && $unit_price > 0) {
                $freight_items_found++;
            }
        }
    }
    
    echo "<div style='background: " . ($freight_items_found > 0 ? '#d4edda' : '#f8d7da') . "; padding: 15px; margin: 20px 0; border-radius: 8px;'>";
    echo "<h5>" . ($freight_items_found > 0 ? '✅' : '❌') . " สรุป:</h5>";
    echo "<p>พบ Freight Items ที่ครบถ้วน: <strong>$freight_items_found</strong> รายการ</p>";
    echo "</div>";
    
} else {
    echo "<p>ยังไม่มีการส่งข้อมูล</p>";
}
?>

<form method="POST" style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
    <h4>🚀 ทดสอบส่งข้อมูล Freight:</h4>
    
    <div style="margin-bottom: 15px;">
        <label>Cost Type 1:</label>
        <input type="text" name="cost_type_1" value="ค่าขนส่ง" style="width: 100%; padding: 8px;">
    </div>
    
    <div style="margin-bottom: 15px;">
        <label>Description 1:</label>
        <input type="text" name="cost_description_1" value="ค่าขนส่งทางเรือ" style="width: 100%; padding: 8px;">
    </div>
    
    <div style="margin-bottom: 15px;">
        <label>Quantity 1:</label>
        <input type="number" name="quantity_1" value="1" style="width: 100%; padding: 8px;">
    </div>
    
    <div style="margin-bottom: 15px;">
        <label>Unit Price 1:</label>
        <input type="number" name="unit_price_1" value="5000" style="width: 100%; padding: 8px;">
    </div>
    
    <button type="submit" style="background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px;">
        ทดสอบส่งข้อมูล
    </button>
</form>

<div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0;">
    <h5>📋 วิธีใช้:</h5>
    <ol>
        <li>กรอกข้อมูลในฟอร์มด้านบน</li>
        <li>กดปุ่ม "ทดสอบส่งข้อมูล"</li>
        <li>ดูผลลัพธ์ว่าข้อมูลส่งมาถูกต้องหรือไม่</li>
        <li>ถ้าข้อมูลถูกต้อง แสดงว่าปัญหาอยู่ที่ SQL</li>
        <li>ถ้าข้อมูลไม่ถูกต้อง แสดงว่าปัญหาอยู่ที่ JavaScript</li>
    </ol>
</div>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    label { display: block; font-weight: bold; margin-bottom: 5px; }
    h4, h5 { color: #333; }
</style>