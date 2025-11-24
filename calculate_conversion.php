<?php
// api/calculate_conversion.php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // รับข้อมูลจาก POST
    $product_id = $_POST['product_id'] ?? 0;
    $from_unit_id = $_POST['from_unit_id'] ?? 0;
    $to_unit_id = $_POST['to_unit_id'] ?? 0;
    $quantity = floatval($_POST['quantity'] ?? 0);
    
    if (empty($product_id) || empty($from_unit_id) || empty($to_unit_id)) {
        throw new Exception('Missing required parameters');
    }
    
    // ถ้าหน่วยเดียวกัน ไม่ต้องแปลง
    if ($from_unit_id == $to_unit_id) {
        echo json_encode([
            'success' => true,
            'conversion_factor' => 1.0,
            'converted_quantity' => $quantity,
            'from_unit' => $from_unit_id,
            'to_unit' => $to_unit_id,
            'formula' => 'หน่วยเดียวกัน ไม่ต้องแปลง'
        ]);
        exit();
    }
    
    // ดึงข้อมูล conversion factor
    $factor = getConversionFactor($db, $product_id, $from_unit_id, $to_unit_id);
    
    // คำนวณจำนวนที่แปลงแล้ว
    $converted_quantity = $quantity * $factor;
    
    // ดึงชื่อหน่วย
    $query_units = "SELECT unit_id, unit_name, unit_name_th, unit_code 
                    FROM Units 
                    WHERE unit_id IN (?, ?)";
    $stmt = $db->prepare($query_units);
    $stmt->execute([$from_unit_id, $to_unit_id]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $unit_names = [];
    foreach ($units as $unit) {
        $unit_names[$unit['unit_id']] = $unit['unit_name_th'] ?: $unit['unit_name'];
    }
    
    $from_unit_name = $unit_names[$from_unit_id] ?? 'Unknown';
    $to_unit_name = $unit_names[$to_unit_id] ?? 'Unknown';
    
    echo json_encode([
        'success' => true,
        'conversion_factor' => $factor,
        'converted_quantity' => round($converted_quantity, 4),
        'from_unit' => $from_unit_id,
        'to_unit' => $to_unit_id,
        'from_unit_name' => $from_unit_name,
        'to_unit_name' => $to_unit_name,
        'formula' => sprintf('%s %s × %s = %s %s', 
            number_format($quantity, 2),
            $from_unit_name,
            $factor,
            number_format($converted_quantity, 2),
            $to_unit_name
        )
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Calculate Conversion Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * ฟังก์ชันหา Conversion Factor สำหรับการแปลงหน่วย
 */
function getConversionFactor($db, $product_id, $from_unit_id, $to_unit_id) {
    // 1. ลองหา conversion ตรงจาก PRODUCT_UOM_CONVERSIONS
    $query = "SELECT conversion_factor 
              FROM PRODUCT_UOM_CONVERSIONS 
              WHERE product_id = ? 
              AND from_uom_id = ? 
              AND to_uom_id = ? 
              AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->execute([$product_id, $from_unit_id, $to_unit_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        return floatval($result['conversion_factor']);
    }
    
    // 2. ลองหา conversion ย้อนกลับ (to → from แล้วกลับค่า)
    $query = "SELECT conversion_factor 
              FROM PRODUCT_UOM_CONVERSIONS 
              WHERE product_id = ? 
              AND from_uom_id = ? 
              AND to_uom_id = ? 
              AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->execute([$product_id, $to_unit_id, $from_unit_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['conversion_factor'] != 0) {
        return 1 / floatval($result['conversion_factor']);
    }
    
    // 3. ลองหาผ่าน Product_Units (หน่วยหลักของสินค้า)
    $query = "SELECT conversion_factor, unit_id 
              FROM Product_Units 
              WHERE product_id = ? 
              AND unit_id IN (?, ?) 
              AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->execute([$product_id, $from_unit_id, $to_unit_id]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $factors = [];
    foreach ($units as $unit) {
        $factors[$unit['unit_id']] = floatval($unit['conversion_factor']);
    }
    
    if (count($factors) == 2) {
        // คำนวณจากหน่วยฐาน
        $from_to_base = $factors[$from_unit_id];
        $to_to_base = $factors[$to_unit_id];
        
        if ($to_to_base != 0) {
            return $from_to_base / $to_to_base;
        }
    }
    
    // 4. สำหรับกระดาษ - ใช้ข้อมูลจาก Specific_Paperboard
    $query_product = "SELECT material_type_id FROM Master_Products_ID WHERE id = ?";
    $stmt = $db->prepare($query_product);
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product && $product['material_type_id'] == 1) {
        // เป็นกระดาษ
        $query_paper = "SELECT W_mm, L_mm, gsm, Weight_kg_per_sheet 
                        FROM Specific_Paperboard 
                        WHERE product_id = ? AND is_active = 1";
        $stmt = $db->prepare($query_paper);
        $stmt->execute([$product_id]);
        $paper = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($paper) {
            // ตรวจสอบว่าหน่วยไหนเป็น kg และหน่วยไหนเป็นแผ่น
            $query_unit_names = "SELECT unit_id, unit_name, unit_name_th, unit_code 
                                FROM Units 
                                WHERE unit_id IN (?, ?)";
            $stmt = $db->prepare($query_unit_names);
            $stmt->execute([$from_unit_id, $to_unit_id]);
            $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $unit_info = [];
            foreach ($units as $unit) {
                $name = strtolower($unit['unit_name_th'] ?: $unit['unit_name']);
                $is_kg = (strpos($name, 'กก') !== false || strpos($name, 'kg') !== false || strpos($name, 'กิโลกรัม') !== false);
                $is_sheet = (strpos($name, 'แผ่น') !== false || strpos($name, 'sheet') !== false);
                
                $unit_info[$unit['unit_id']] = [
                    'is_kg' => $is_kg,
                    'is_sheet' => $is_sheet
                ];
            }
            
            // คำนวณน้ำหนักต่อแผ่น
            if (!empty($paper['Weight_kg_per_sheet'])) {
                $weight_per_sheet = floatval($paper['Weight_kg_per_sheet']);
            } else {
                $w_m = $paper['W_mm'] / 1000;
                $l_m = $paper['L_mm'] / 1000;
                $area_m2 = $w_m * $l_m;
                $weight_per_sheet = $area_m2 * ($paper['gsm'] / 1000);
            }
            
            // kg → แผ่น
            if ($unit_info[$from_unit_id]['is_kg'] && $unit_info[$to_unit_id]['is_sheet']) {
                return 1 / $weight_per_sheet; // แผ่น/kg
            }
            
            // แผ่น → kg
            if ($unit_info[$from_unit_id]['is_sheet'] && $unit_info[$to_unit_id]['is_kg']) {
                return $weight_per_sheet; // kg/แผ่น
            }
        }
    }
    
    // 5. ถ้าไม่เจอ conversion ใดๆ ให้ return 1 (ไม่แปลง)
    return 1.0;
}
?>