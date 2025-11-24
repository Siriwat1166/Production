<?php
// test_db.php - วางไฟล์นี้ในโฟลเดอร์เดียวกับ dashboard.php
echo "<h2>🔧 ทดสอบการเชื่อมต่อฐานข้อมูล</h2>";

// เรียกใช้ config
require_once "config/config.php";

echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
echo "<h3>📋 ข้อมูล Config</h3>";
echo "<strong>DB_SERVER:</strong> " . (defined('DB_SERVER') ? DB_SERVER : 'ไม่ได้กำหนด') . "<br>";
echo "<strong>DB_NAME:</strong> " . (defined('DB_NAME') ? DB_NAME : 'ไม่ได้กำหนด') . "<br>";
echo "<strong>DB_USERNAME:</strong> " . (defined('DB_USERNAME') ? DB_USERNAME : 'ไม่ได้กำหนด') . "<br>";
echo "<strong>DB_PASSWORD:</strong> " . (defined('DB_PASSWORD') ? '***กำหนดแล้ว***' : 'ไม่ได้กำหนด') . "<br>";
echo "</div>";

// ทดสอบการเชื่อมต่อด้วย config constants
if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
    echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
    echo "<h3>🔌 ทดสอบการเชื่อมต่อด้วย Constants</h3>";
    
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
        
        // ทดสอบคำสั่ง SQL
        $stmt = $pdo->query("SELECT @@VERSION as version, DB_NAME() as database_name, SYSTEM_USER as current_user");
        $info = $stmt->fetch();
        
        echo "<span style='color: green;'>✅ <strong>เชื่อมต่อสำเร็จ!</strong></span><br>";
        echo "<strong>Database:</strong> " . $info['database_name'] . "<br>";
        echo "<strong>User:</strong> " . $info['current_user'] . "<br>";
        echo "<strong>SQL Server Version:</strong> " . substr($info['version'], 0, 100) . "...<br>";
        
        // ทดสอบตาราง Suppliers
        try {
            $supplierStmt = $pdo->query("SELECT COUNT(*) as supplier_count FROM Suppliers");
            $supplierCount = $supplierStmt->fetch();
            echo "<strong>จำนวน Suppliers:</strong> " . $supplierCount['supplier_count'] . " รายการ<br>";
            
            // แสดงข้อมูล Suppliers 3 รายการแรก
            $supplierListStmt = $pdo->query("SELECT TOP 3 supplier_id, supplier_code, supplier_name FROM Suppliers ORDER BY supplier_id");
            $suppliers = $supplierListStmt->fetchAll();
            
            if ($suppliers) {
                echo "<strong>ตัวอย่าง Suppliers:</strong><br>";
                echo "<ul>";
                foreach ($suppliers as $supplier) {
                    echo "<li>" . $supplier['supplier_code'] . " - " . $supplier['supplier_name'] . "</li>";
                }
                echo "</ul>";
            }
            
        } catch (Exception $e) {
            echo "<span style='color: orange;'>⚠️ ไม่พบตาราง Suppliers หรือไม่มีข้อมูล: " . $e->getMessage() . "</span><br>";
        }
        
    } catch (PDOException $e) {
        echo "<span style='color: red;'>❌ <strong>เชื่อมต่อไม่สำเร็จ:</strong> " . $e->getMessage() . "</span><br>";
    }
    
    echo "</div>";
} else {
    echo "<div style='background: #ffebee; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
    echo "<span style='color: red;'>❌ <strong>ไม่พบ Database Constants ใน config.php</strong></span>";
    echo "</div>";
}

// ทดสอบการเชื่อมต่อด้วย Database class
echo "<div style='background: #f3e5f5; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
echo "<h3>🏗️ ทดสอบการเชื่อมต่อด้วย Database Class</h3>";

try {
    require_once "config/database.php";
    $database = new Database();
    $conn = $database->getConnection();
    
    if ($conn) {
        echo "<span style='color: green;'>✅ <strong>Database Class เชื่อมต่อสำเร็จ!</strong></span><br>";
        
        // ทดสอบข้อมูลตัวอย่าง
        $sampleData = $database->checkSampleData();
        if ($sampleData['status']) {
            echo "<strong>ข้อมูลในตาราง:</strong><br>";
            foreach ($sampleData['data'] as $table => $count) {
                echo "- {$table}: {$count} รายการ<br>";
            }
        }
        
    } else {
        echo "<span style='color: red;'>❌ Database Class เชื่อมต่อไม่สำเร็จ</span><br>";
    }
    
} catch (Exception $e) {
    echo "<span style='color: red;'>❌ <strong>Database Class Error:</strong> " . $e->getMessage() . "</span><br>";
}

echo "</div>";

echo "<div style='background: #e8f5e8; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
echo "<h3>🎯 แนวทางแก้ไข</h3>";
echo "<ol>";
echo "<li><strong>อัปเดตไฟล์ config/config.php</strong> ให้มี DB_SERVER, DB_NAME, DB_USERNAME, DB_PASSWORD</li>";
echo "<li><strong>ตรวจสอบการเชื่อมต่อ SQL Server</strong> ที่ 192.168.2.3\\SQLEXPRESS</li>";
echo "<li><strong>ตรวจสอบ Username/Password</strong> PD02/Pass1234</li>";
echo "<li><strong>ตรวจสอบฐานข้อมูล Production</strong> ว่ามีตาราง Suppliers หรือไม่</li>";
echo "</ol>";
echo "</div>";

echo "<p><a href='pages/suppliers/' style='background: #ff9a56; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>🔄 ลองใช้งาน Suppliers อีกครั้ง</a></p>";
?>