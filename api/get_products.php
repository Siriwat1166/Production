<?php
// api/get_products.php
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
    
    $search = $_GET['search'] ?? '';
    $material_type = $_GET['material_type'] ?? '';
    $supplier_id = $_GET['supplier_id'] ?? '';
    
    // สร้าง query
    $query = "SELECT 
        mp.id,
        mp.SSP_Code,
        mp.Name,
        mp.Name2,
        mp.material_type_id,
        mp.group_id,
        mp.supplier_id,
        mp.Unit_id,
        mt.type_name as material_type_name,
        mt.type_code as material_type_code,
        g.name as group_name,
        s.supplier_name,
        s.supplier_code,
        u.unit_name,
        u.unit_name_th,
        u.unit_code
    FROM Master_Products_ID mp
    LEFT JOIN Material_Types mt ON mp.material_type_id = mt.material_type_id
    LEFT JOIN Groups g ON mp.group_id = g.id
    LEFT JOIN Suppliers s ON mp.supplier_id = s.supplier_id
    LEFT JOIN Units u ON mp.Unit_id = u.unit_id
    WHERE mp.is_active = 1";
    
    $params = [];
    
    // เพิ่มเงื่อนไขการค้นหา
    if (!empty($search)) {
        $query .= " AND (mp.SSP_Code LIKE ? OR mp.Name LIKE ? OR mp.Name2 LIKE ?)";
        $search_term = "%{$search}%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if (!empty($material_type)) {
        $query .= " AND mp.material_type_id = ?";
        $params[] = $material_type;
    }
    
    if (!empty($supplier_id)) {
        $query .= " AND mp.supplier_id = ?";
        $params[] = $supplier_id;
    }
    
    $query .= " ORDER BY mp.SSP_Code, mp.Name";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // เพิ่มข้อมูลสต็อกปัจจุบัน
    foreach ($products as &$product) {
        $query_stock = "SELECT 
            SUM(current_stock) as total_stock,
            SUM(available_stock) as total_available
        FROM Inventory_Stock
        WHERE product_id = ?";
        
        $stmt_stock = $db->prepare($query_stock);
        $stmt_stock->execute([$product['id']]);
        $stock = $stmt_stock->fetch(PDO::FETCH_ASSOC);
        
        $product['current_stock'] = $stock['total_stock'] ?? 0;
        $product['available_stock'] = $stock['total_available'] ?? 0;
        
        // ดึงข้อมูลกระดาษถ้าเป็นกระดาษ
        if ($product['material_type_id'] == 1) {
            $query_paper = "SELECT Weight_kg_per_sheet 
                           FROM Specific_Paperboard 
                           WHERE product_id = ? AND is_active = 1";
            $stmt_paper = $db->prepare($query_paper);
            $stmt_paper->execute([$product['id']]);
            $paper = $stmt_paper->fetch(PDO::FETCH_ASSOC);
            
            if ($paper) {
                $product['weight_per_sheet'] = floatval($paper['Weight_kg_per_sheet']);
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $products,
        'total_records' => count($products)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Get Products Error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>