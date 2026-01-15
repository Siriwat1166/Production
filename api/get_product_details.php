<?php
// api/get_product_details.php
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
    
    $product_id = $_GET['product_id'] ?? 0;
    
    if (empty($product_id)) {
        throw new Exception('ไม่พบรหัสสินค้า');
    }
    
    // ดึงข้อมูลสินค้าหลัก
    $query = "SELECT 
        mp.id as product_id,
        mp.SSP_Code,
        mp.Name,
        mp.Name2,
        mp.material_type_id,
        mp.group_id,
        mp.supplier_id,
        mp.Unit_id,
        mt.type_name as material_type_name,
        mt.type_code as material_type_code,
        s.supplier_name,
        u.unit_name,
        u.unit_name_th,
        u.unit_code
    FROM Master_Products_ID mp
    LEFT JOIN Material_Types mt ON mp.material_type_id = mt.material_type_id
    LEFT JOIN Suppliers s ON mp.supplier_id = s.supplier_id
    LEFT JOIN Units u ON mp.Unit_id = u.unit_id
    WHERE mp.id = ? AND mp.is_active = 1";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        throw new Exception('ไม่พบข้อมูลสินค้า');
    }
    
    // ดึงข้อมูลเฉพาะตามประเภทวัตถุดิบ
    $specific_data = null;
    
    // material_type_id = 1 คือ กระดาษ (Paperboard)
    if ($product['material_type_id'] == 1) {
        $query_paper = "SELECT 
            product_id,
            W_mm,
            L_mm,
            L_inch,
            W_inch,
            gsm,
            Caliper,
            brand,
            type_paperboard_TH,
            type_paperboard_EN,
            laminated1,
            laminated2,
            Certificated,
            Weight_kg_per_sheet,
            paper_subgroup_id,
            paper_subgroup,
            Brand2
        FROM Specific_Paperboard
        WHERE product_id = ? AND is_active = 1";
        
        $stmt_paper = $db->prepare($query_paper);
        $stmt_paper->execute([$product_id]);
        $paperboard = $stmt_paper->fetch(PDO::FETCH_ASSOC);
        
        if ($paperboard) {
            $specific_data = [
                'type' => 'paperboard',
                'data' => $paperboard
            ];
            
            // คำนวณข้อมูลเพิ่มเติมสำหรับกระดาษ
            $specific_data['calculations'] = [
                'weight_per_sheet_kg' => floatval($paperboard['Weight_kg_per_sheet']),
                'area_sqm' => ($paperboard['W_mm'] * $paperboard['L_mm']) / 1000000,
                'sheets_per_kg' => $paperboard['Weight_kg_per_sheet'] > 0 
                    ? (1 / floatval($paperboard['Weight_kg_per_sheet'])) 
                    : 0
            ];
        }
    }
    // material_type_id = 2 คือ หมึก (Ink)
    else if ($product['material_type_id'] == 2) {
        $query_ink = "SELECT * FROM Specific_Ink WHERE product_id = ? AND is_active = 1";
        $stmt_ink = $db->prepare($query_ink);
        $stmt_ink->execute([$product_id]);
        $ink = $stmt_ink->fetch(PDO::FETCH_ASSOC);
        
        if ($ink) {
            $specific_data = ['type' => 'ink', 'data' => $ink];
        }
    }
    // material_type_id = 3 คือ Coating
    else if ($product['material_type_id'] == 3) {
        $query_coating = "SELECT * FROM Specific_Coating WHERE product_id = ? AND is_active = 1";
        $stmt_coating = $db->prepare($query_coating);
        $stmt_coating->execute([$product_id]);
        $coating = $stmt_coating->fetch(PDO::FETCH_ASSOC);
        
        if ($coating) {
            $specific_data = ['type' => 'coating', 'data' => $coating];
        }
    }
    // เพิ่มประเภทอื่นๆ ตามต้องการ
    
    // ดึงข้อมูลหน่วยที่ใช้ได้กับสินค้านี้
    $query_units = "SELECT 
        pu.product_unit_id,
        pu.unit_id,
        pu.unit_type,
        pu.conversion_factor,
        pu.is_base_unit,
        pu.is_purchase_unit,
        pu.is_stock_unit,
        pu.is_issue_unit,
        u.unit_code,
        u.unit_name,
        u.unit_name_th,
        u.unit_symbol
    FROM Product_Units pu
    INNER JOIN Units u ON pu.unit_id = u.unit_id
    WHERE pu.product_id = ? AND pu.is_active = 1
    ORDER BY pu.is_base_unit DESC, pu.conversion_factor";
    
    $stmt_units = $db->prepare($query_units);
    $stmt_units->execute([$product_id]);
    $product_units = $stmt_units->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูล conversion rates
    $query_conversions = "SELECT 
        conversion_id,
        from_uom_id,
        to_uom_id,
        conversion_factor,
        u1.unit_name as from_unit_name,
        u1.unit_name_th as from_unit_name_th,
        u2.unit_name as to_unit_name,
        u2.unit_name_th as to_unit_name_th
    FROM PRODUCT_UOM_CONVERSIONS puc
    LEFT JOIN Units u1 ON puc.from_uom_id = u1.unit_id
    LEFT JOIN Units u2 ON puc.to_uom_id = u2.unit_id
    WHERE puc.product_id = ? AND puc.is_active = 1";
    
    $stmt_conv = $db->prepare($query_conversions);
    $stmt_conv->execute([$product_id]);
    $conversions = $stmt_conv->fetchAll(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูลสต็อกปัจจุบัน
    $query_stock = "SELECT 
        is_table.inventory_id,
        is_table.warehouse_id,
        is_table.current_stock,
        is_table.available_stock,
        is_table.reserved_stock,
        is_table.average_cost,
        w.warehouse_name,
        w.warehouse_name_th,
        w.warehouse_code
    FROM Inventory_Stock is_table
    INNER JOIN Warehouses w ON is_table.warehouse_id = w.warehouse_id
    WHERE is_table.product_id = ? AND w.is_active = 1";
    
    $stmt_stock = $db->prepare($query_stock);
    $stmt_stock->execute([$product_id]);
    $stock_levels = $stmt_stock->fetchAll(PDO::FETCH_ASSOC);
    
    // รวมข้อมูลทั้งหมด
    $response = [
        'success' => true,
        'data' => $product,
        'specific_data' => $specific_data,
        'product_units' => $product_units,
        'conversions' => $conversions,
        'stock_levels' => $stock_levels,
        'paperboard_data' => ($specific_data && $specific_data['type'] === 'paperboard') 
            ? $specific_data['data'] 
            : null
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Get Product Details Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>