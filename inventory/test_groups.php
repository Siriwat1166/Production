<?php
// test_groups.php - ทดสอบการดึงข้อมูล Groups
require_once __DIR__ . '/../config/config.php';

try {
    $pdo = new PDO(
        "sqlsrv:Server=" . DB_SERVER . ";Database=" . DB_NAME,
        DB_USERNAME,
        DB_PASSWORD,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        )
    );
    
    echo "<h2>ทดสอบการดึงข้อมูล Groups</h2>";
    
    // 1. ตรวจสอบตาราง Groups
    echo "<h3>1. ข้อมูลในตาราง Groups</h3>";
    $stmt = $pdo->query("SELECT TOP 20 * FROM Groups ORDER BY name");
    $groups = $stmt->fetchAll();
    
    if (empty($groups)) {
        echo "⚠️ ตารางว่างเปล่า<br>";
    } else {
        echo "✅ พบข้อมูล " . count($groups) . " รายการ<br><br>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr>";
        foreach (array_keys($groups[0]) as $column) {
            echo "<th>$column</th>";
        }
        echo "</tr>";
        foreach ($groups as $row) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value ?? '-') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table><br>";
    }
    
    // 2. ตรวจสอบโครงสร้าง Master_Products_ID
    echo "<h3>2. คอลัมน์ใน Master_Products_ID ที่อาจเชื่อมกับ Groups</h3>";
    $stmt = $pdo->query("SELECT TOP 1 * FROM Master_Products_ID");
    $sample = $stmt->fetch();
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Column Name</th><th>Sample Value</th></tr>";
    foreach ($sample as $col => $val) {
        $highlight = '';
        if (stripos($col, 'group') !== false || stripos($col, 'type') !== false || stripos($col, 'material') !== false) {
            $highlight = ' style="background-color: yellow;"';
        }
        echo "<tr$highlight><td><strong>$col</strong></td><td>" . htmlspecialchars($val ?? 'NULL') . "</td></tr>";
    }
    echo "</table><br>";
    
    // 3. ลองเชื่อมกับ Groups ด้วยคอลัมน์ที่เป็นไปได้
    echo "<h3>3. ทดสอบ JOIN กับ Groups</h3>";
    $possible_columns = ['material_type_id', 'group_id', 'type_id', 'material_group_id', 'product_type_id'];
    
    foreach ($possible_columns as $col) {
        if (array_key_exists($col, $sample)) {
            echo "<h4>ทดสอบคอลัมน์: $col</h4>";
            try {
                $stmt = $pdo->query("
                    SELECT DISTINCT 
                        g.id, 
                        g.name,
                        COUNT(mp.id) as product_count
                    FROM Groups g
                    INNER JOIN Master_Products_ID mp ON g.id = mp.$col
                    GROUP BY g.id, g.name
                    ORDER BY g.name
                ");
                $result = $stmt->fetchAll();
                
                if (empty($result)) {
                    echo "⚠️ JOIN สำเร็จแต่ไม่มีข้อมูล<br>";
                } else {
                    echo "✅ <strong>พบข้อมูล " . count($result) . " กลุ่ม</strong><br><br>";
                    echo "<table border='1' cellpadding='5'>";
                    echo "<tr><th>ID</th><th>Name</th><th>Product Count</th></tr>";
                    foreach ($result as $row) {
                        echo "<tr>";
                        echo "<td>{$row['id']}</td>";
                        echo "<td><strong>{$row['name']}</strong></td>";
                        echo "<td>{$row['product_count']}</td>";
                        echo "</tr>";
                    }
                    echo "</table><br>";
                    
                    echo "<div style='background: #d4edda; padding: 10px; border: 1px solid #c3e6cb;'>";
                    echo "🎯 <strong>ใช้คอลัมน์นี้: mp.$col</strong><br>";
                    echo "SQL ที่ควรใช้:<br>";
                    echo "<code>LEFT JOIN Groups g ON mp.$col = g.id</code>";
                    echo "</div><br>";
                }
            } catch (PDOException $e) {
                echo "❌ Error: " . $e->getMessage() . "<br>";
            }
        }
    }
    
} catch (PDOException $e) {
    echo "❌ Database Connection Error: " . $e->getMessage();
}
?>