<?php
// receiving.php - Purchase Order Goods Receipt System with Unit Conversion
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Auth.php';

// Initialize authentication
$auth = new Auth();
$auth->requireLogin();
$auth->requireRole(['admin', 'editor']);

// Initialize database connection
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
} catch (PDOException $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    die("Database connection failed. Please check server configuration.");
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'get_po_data':
                if (!isset($_POST['po_number']) || empty($_POST['po_number'])) {
                    echo json_encode(['success' => false, 'message' => 'PO number is required']);
                    exit;
                }
                echo json_encode(getPOData($_POST['po_number']));
                break;
                
            case 'get_po_data_with_conversions':
                if (!isset($_POST['po_number']) || empty($_POST['po_number'])) {
                    echo json_encode(['success' => false, 'message' => 'PO number is required']);
                    exit;
                }
                echo json_encode(getPODataWithConversions($_POST['po_number']));
                break;
                
            case 'save_receipt':
                echo json_encode(saveReceipt($_POST));
                break;
                
            case 'save_receipt_with_conversion':
                echo json_encode(saveReceiptWithConversion($_POST));
                break;
                
            case 'save_draft':
                echo json_encode(saveDraft($_POST));
                break;
                
            case 'get_po_items':
                if (!isset($_POST['po_number']) || empty($_POST['po_number'])) {
                    echo json_encode(['success' => false, 'message' => 'PO number is required']);
                    exit;
                }
                echo json_encode(getPOItems($_POST['po_number']));
                break;
                
            case 'get_po_details':
                if (!isset($_POST['po_number']) || empty($_POST['po_number'])) {
                    echo json_encode(['success' => false, 'message' => 'PO number is required']);
                    exit;
                }
                echo json_encode(getPODetailData($_POST['po_number']));
                break;
                
            case 'get_available_units':
                if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Product ID is required']);
                    exit;
                }
                $units = getAvailableUnitsForProduct($_POST['product_id']);
                echo json_encode(['success' => true, 'units' => $units]);
                break;
                
            case 'get_uom_conversions':
                if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Product ID is required']);
                    exit;
                }
                $conversions = getProductUnitConversions($_POST['product_id']);
                echo json_encode(['success' => true, 'conversions' => $conversions]);
                break;
                
            case 'get_unit_options':
                if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Product ID is required']);
                    exit;
                }
                $options = getUnitOptions($_POST['product_id'], $_POST['current_uom_id'] ?? null);
                echo json_encode(['success' => true, 'options' => $options]);
                break;
                
            case 'calculate_conversion':
                if (!isset($_POST['product_id'], $_POST['from_unit'], $_POST['to_unit'], $_POST['quantity'])) {
                    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
                    exit;
                }
                
                $factor = getConversionFactor($_POST['product_id'], $_POST['from_unit'], $_POST['to_unit']);
                $converted_quantity = (float)$_POST['quantity'] * $factor;
                
                echo json_encode([
                    'success' => true,
                    'conversion_factor' => $factor,
                    'converted_quantity' => $converted_quantity
                ]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        error_log("AJAX Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    }
    exit;
}

// Get Purchase Orders for display
function getPurchaseOrders($search = '', $status = '', $date = '') {
    global $pdo;
    
    $query = "
        SELECT 
            ph.po_id,
            ph.po_number,
            ph.po_date,
            ph.total_amount,
            ph.status,
            ph.delivery_date,
            ISNULL(s.supplier_name, 'Unknown Supplier') as supplier_name,
            COUNT(pi.po_item_id) as total_items,
            ISNULL(SUM(pi.received_quantity), 0) as total_received,
            ISNULL(SUM(pi.quantity), 0) as total_ordered,
            STRING_AGG(
                CONCAT(
                    pi.item_description, ': ',
                    CAST(ISNULL(pi.received_quantity, 0) AS VARCHAR), '/',
                    CAST(pi.quantity AS VARCHAR), ' ',
                    ISNULL(u_purchase.unit_name_th, 'หน่วย')
                ),
                ' | '
            ) as items_detail,
            CASE 
                WHEN ISNULL(SUM(pi.quantity), 0) = 0 THEN 0
                ELSE ROUND((ISNULL(SUM(pi.received_quantity), 0) / SUM(pi.quantity)) * 100, 0)
            END as completion_percentage,
            CASE 
                WHEN ISNULL(SUM(pi.received_quantity), 0) = 0 THEN 'pending'
                WHEN ISNULL(SUM(
                    CASE 
                        WHEN pi.received_quantity >= pi.quantity THEN 1 
                        WHEN ABS((pi.received_quantity - pi.quantity) / pi.quantity) <= 0.03 THEN 1
                        ELSE 0 
                    END
                ), 0) = COUNT(pi.po_item_id) THEN 'complete'
                ELSE 'partial'
            END as receipt_status
        FROM PO_Header ph
        LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
        LEFT JOIN PO_Items pi ON ph.po_id = pi.po_id
        LEFT JOIN Units u_purchase ON pi.purchase_unit_id = u_purchase.unit_id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Add search filter
    if (!empty($search)) {
        $query .= " AND (ph.po_number LIKE ? OR ISNULL(s.supplier_name, '') LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    // Add date filter
    if (!empty($date)) {
        $query .= " AND CAST(ph.po_date AS DATE) = ?";
        $params[] = $date;
    }
    
    $query .= "
        GROUP BY ph.po_id, ph.po_number, ph.po_date, ph.total_amount, ph.status, 
                 ph.delivery_date, s.supplier_name
    ";
    
    // Add status filter after GROUP BY
    if (!empty($status)) {
        if ($status === 'pending') {
            $query .= " HAVING ISNULL(SUM(pi.received_quantity), 0) = 0";
        } elseif ($status === 'partial') {
            $query .= " HAVING ISNULL(SUM(pi.received_quantity), 0) > 0 AND ISNULL(SUM(pi.received_quantity), 0) < SUM(pi.quantity)";
        } elseif ($status === 'complete') {
            $query .= " HAVING ISNULL(SUM(pi.received_quantity), 0) >= SUM(pi.quantity)";
        }
    }
    
    $query .= " ORDER BY ph.po_date DESC, ph.po_number DESC";
    
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting PO data: " . $e->getMessage());
        return [];
    }
}

// Get specific PO data for modal
function getPOData($po_number) {
    global $pdo;
    
    try {
        // Get PO header data
        $query = "
            SELECT 
                ph.po_id,
                ph.po_number,
                ph.po_date,
                ph.total_amount,
                ph.delivery_date,
                ph.notes,
                ISNULL(s.supplier_name, 'Unknown Supplier') as supplier_name,
                ISNULL(u.full_name, 'System') as approved_by_name
            FROM PO_Header ph
            LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
            LEFT JOIN Users u ON ph.approved_by = u.user_id
            WHERE ph.po_number = ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$po_number]);
        $po_data = $stmt->fetch();
        
        if (!$po_data) {
            return ['success' => false, 'message' => 'PO not found'];
        }
        
        // Get PO items - ใช้ Units table (ตารางเดิม) ก่อน
        $items_query = "
            SELECT 
                pi.po_item_id,
                pi.line_number,
                pi.product_id,
                pi.item_description,
                ISNULL(pi.quantity, 0) as quantity,
                ISNULL(pi.received_quantity, 0) as received_quantity,
                ISNULL(pi.pending_quantity, 0) as pending_quantity,
                ISNULL(pi.unit_price, 0) as unit_price,
                ISNULL(pi.total_price, 0) as total_price,
                ISNULL(mp.SSP_Code, 'N/A') as product_code,
                ISNULL(mp.Name, pi.item_description) as product_name,
                ISNULL(u_purchase.unit_name_th, 'หน่วย') as purchase_unit,
                ISNULL(u_stock.unit_name_th, 'หน่วย') as stock_unit,
                ISNULL(pi.conversion_factor, 1) as conversion_factor,
                pi.purchase_unit_id,
                pi.stock_unit_id
            FROM PO_Items pi
            LEFT JOIN Master_Products_ID mp ON pi.product_id = mp.id
            LEFT JOIN Units u_purchase ON pi.purchase_unit_id = u_purchase.unit_id
            LEFT JOIN Units u_stock ON pi.stock_unit_id = u_stock.unit_id
            WHERE pi.po_id = ?
            ORDER BY pi.line_number
        ";
        
        $stmt = $pdo->prepare($items_query);
        $stmt->execute([$po_data['po_id']]);
        $items = $stmt->fetchAll();
        
        return [
            'success' => true,
            'po_data' => $po_data,
            'items' => $items
        ];
        
    } catch (PDOException $e) {
        error_log("Error getting PO data: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// Get unit conversion data for a product using UNITS_OF_MEASURE
function getProductUnitConversions($product_id) {
    global $pdo;
    
    try {
        $query = "
            SELECT 
                puc.conversion_id,
                puc.product_id,
                puc.from_uom_id,
                puc.to_uom_id,
                puc.conversion_factor,
                from_uom.code as from_code,
                from_uom.name as from_name,
                from_uom.category as from_category,
                to_uom.code as to_code,
                to_uom.name as to_name,
                to_uom.category as to_category,
                -- ระบุว่าเป็น base unit หรือไม่
                CASE WHEN from_uom.base_uom_id IS NULL THEN 1 ELSE 0 END as from_is_base,
                CASE WHEN to_uom.base_uom_id IS NULL THEN 1 ELSE 0 END as to_is_base
            FROM PRODUCT_UOM_CONVERSIONS puc
            INNER JOIN UNITS_OF_MEASURE from_uom ON puc.from_uom_id = from_uom.uom_id
            INNER JOIN UNITS_OF_MEASURE to_uom ON puc.to_uom_id = to_uom.uom_id
            WHERE puc.product_id = ? 
            AND puc.is_active = 1
            AND from_uom.is_active = 1
            AND to_uom.is_active = 1
            ORDER BY from_uom.category, from_uom.code
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$product_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting product UOM conversions: " . $e->getMessage());
        return [];
    }
}

function getStandardConversionFactor($product_id, $from_uom_id, $to_uom_id) {
    global $pdo;
    if ($from_uom_id == $to_uom_id) return 1.0;

    // 1) หา conversion แบบกำหนดต่อสินค้า
    $q = $pdo->prepare("
        SELECT conversion_factor
        FROM PRODUCT_UOM_CONVERSIONS
        WHERE product_id = ? AND from_uom_id = ? AND to_uom_id = ? AND is_active = 1
    ");
    $q->execute([$product_id, $from_uom_id, $to_uom_id]);
    if ($row = $q->fetch()) return (float)$row['conversion_factor'];

    // 2) เจอแบบย้อนทิศ
    $q->execute([$product_id, $to_uom_id, $from_uom_id]);
    if ($row = $q->fetch()) return 1.0 / (float)$row['conversion_factor'];

    // 3) fallback ผ่าน base unit ใน UNITS_OF_MEASURE
    $q = $pdo->prepare("
        SELECT f.to_base_factor AS f_to_base, t.from_base_factor AS t_from_base
        FROM UNITS_OF_MEASURE f, UNITS_OF_MEASURE t
        WHERE f.uom_id = ? AND t.uom_id = ?
    ");
    $q->execute([$from_uom_id, $to_uom_id]);
    if ($r = $q->fetch()) {
        return ((float)$r['f_to_base']) * ((float)$r['t_from_base']);
    }
    return 1.0;
}
// Get conversion factor between two units for a product
function getConversionFactor($product_id, $from_uom_id, $to_uom_id) {
    global $pdo;
    if ($from_uom_id == $to_uom_id) return 1.0;

    // แปลง uom_id -> code
    $stmt = $pdo->prepare("
        SELECT uom_id, UPPER(code) AS code
        FROM UNITS_OF_MEASURE
        WHERE uom_id IN (?, ?)
    ");
    $stmt->execute([$from_uom_id, $to_uom_id]);
    $codes = [];
    foreach ($stmt->fetchAll() as $r) $codes[$r['uom_id']] = $r['code'];
    $from_code = $codes[$from_uom_id] ?? '';
    $to_code   = $codes[$to_uom_id] ?? '';

    // เคสพิเศษ 'กระดาษ': kg/sheet
    $p = $pdo->prepare("
        SELECT W_mm, L_mm, gsm, Weight_kg_per_sheet
        FROM Specific_Paperboard
        WHERE product_id = ?
    ");
    $p->execute([$product_id]);
    if ($pb = $p->fetch()) {
        // คำนวณน้ำหนักต่อแผ่นใหม่
        if ($pb['W_mm'] && $pb['L_mm'] && $pb['gsm']) {
            // แปลงมม.เป็นเมตร และคำนวณ
            $area_m2 = ($pb['W_mm'] / 1000.0) * ($pb['L_mm'] / 1000.0);
            $kg_per_sheet = $area_m2 * ($pb['gsm'] / 1000.0);
            
            if ($kg_per_sheet > 0) {
                $sheets_per_kg = 1.0 / $kg_per_sheet;
                
                if ($from_code === 'KG' && $to_code === 'SHEET') {
                    return $sheets_per_kg; // kg -> sheet ได้ 7.175 แผ่น/กก.
                }
                if ($from_code === 'SHEET' && $to_code === 'KG') {
                    return $kg_per_sheet;  // sheet -> kg
                }
            }
        }
    }

    return getStandardConversionFactor($product_id, $from_uom_id, $to_uom_id);
}

// Enhanced getPOData with unit conversion support
function getPODataWithConversions($po_number) {
    global $pdo;
    
    try {
        // Get PO header data
        $query = "
            SELECT 
                ph.po_id,
                ph.po_number,
                ph.po_date,
                ph.total_amount,
                ph.delivery_date,
                ph.notes,
                ISNULL(s.supplier_name, 'Unknown Supplier') as supplier_name,
                ISNULL(u.full_name, 'System') as approved_by_name
            FROM PO_Header ph
            LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
            LEFT JOIN Users u ON ph.approved_by = u.user_id
            WHERE ph.po_number = ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$po_number]);
        $po_data = $stmt->fetch();
        
        if (!$po_data) {
            return ['success' => false, 'message' => 'PO not found'];
        }
        
        // Get PO items with UOM information - แก้ไขส่วนนี้
        $items_query = "
            SELECT 
                pi.po_item_id,
                pi.line_number,
                pi.product_id,
                pi.item_description,
                ISNULL(pi.quantity, 0) as quantity,
                ISNULL(pi.received_quantity, 0) as received_quantity,
                ISNULL(pi.pending_quantity, 0) as pending_quantity,
                ISNULL(pi.unit_price, 0) as unit_price,
                ISNULL(pi.total_price, 0) as total_price,
                ISNULL(mp.SSP_Code, 'N/A') as product_code,
                ISNULL(mp.Name, pi.item_description) as product_name,
                pi.purchase_unit_id,
                pi.stock_unit_id,
                ISNULL(pi.conversion_factor, 1) as conversion_factor,
                
                -- เพิ่มข้อมูลหน่วยชัดเจน
                COALESCE(u_purchase.unit_name, u_purchase.unit_name_th, 'หน่วย') as purchase_unit_name,
                COALESCE(u_purchase.unit_symbol, u_purchase.unit_code, '') as purchase_unit_symbol,
                COALESCE(u_stock.unit_name, u_stock.unit_name_th, 'หน่วย') as stock_unit_name,
                COALESCE(u_stock.unit_symbol, u_stock.unit_code, '') as stock_unit_symbol,
                
                -- ข้อมูลกระดาษ
                sp.W_mm, sp.L_mm, sp.gsm, sp.Weight_kg_per_sheet,
                sp.type_paperboard_TH, sp.brand
            FROM PO_Items pi
            LEFT JOIN Master_Products_ID mp ON pi.product_id = mp.id
            LEFT JOIN Units u_purchase ON pi.purchase_unit_id = u_purchase.unit_id
            LEFT JOIN Units u_stock ON pi.stock_unit_id = u_stock.unit_id
            LEFT JOIN Specific_Paperboard sp ON pi.product_id = sp.product_id
            WHERE pi.po_id = ?
            ORDER BY pi.line_number
        ";
        
        $stmt = $pdo->prepare($items_query);
        $stmt->execute([$po_data['po_id']]);
        $items = $stmt->fetchAll();
        
        // เพิ่มข้อมูลหน่วยที่ใช้ได้สำหรับแต่ละสินค้า
foreach ($items as &$item) {
    if ($item['W_mm'] && $item['L_mm'] && $item['gsm']) {
        // คำนวณใหม่จากขนาดจริง
        $area_m2 = ($item['W_mm'] / 1000.0) * ($item['L_mm'] / 1000.0);
        $kg_per_sheet = $area_m2 * ($item['gsm'] / 1000.0);
        $sheets_per_kg = $kg_per_sheet > 0 ? (1 / $kg_per_sheet) : 0;
        
        $item['paperboard_info'] = [
            'W_mm' => $item['W_mm'],
            'L_mm' => $item['L_mm'], 
            'gsm' => $item['gsm'],
            'Weight_kg_per_sheet' => number_format($kg_per_sheet, 6), // แสดง 6 ตำแหน่ง
            'sheets_per_kg' => number_format($sheets_per_kg, 3)        // แสดง 3 ตำแหน่ง
        ];
    }
            
            // เพิ่มข้อมูลหน่วยที่ใช้ได้
            if ($item['product_id']) {
                $item['available_units'] = getAvailableUnitsForProduct($item['product_id']);
                
                // กรองหน่วยสำหรับการรับเข้า
                $receiving_units = array_filter($item['available_units'], function($unit) {
                    return $unit['category'] === 'count' || $unit['unit_type'] === 'stock';
                });
                $item['receiving_units'] = $receiving_units ?: $item['available_units'];
                
                // ดึงการแปลงที่มีอยู่
                $item['conversions'] = getProductUnitConversions($item['product_id']);
            } else {
                $item['available_units'] = [];
                $item['receiving_units'] = [];
                $item['conversions'] = [];
            }
        }
        
        return [
            'success' => true,
            'po_data' => $po_data,
            'items' => $items
        ];
        
    } catch (PDOException $e) {
        error_log("Error getting PO data with conversions: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// Get all available units for a product (including base units)
function getAvailableUnitsForProduct($product_id) {
    global $pdo;

    try {
        $query = "
            SELECT DISTINCT
                uom.uom_id,
                uom.uom_id        AS unit_id,        -- <== เพิ่ม
                uom.code          AS unit_symbol,    -- <== เพิ่ม
                uom.name          AS unit_name_th,   -- <== เพิ่ม
                uom.code,
                uom.name,
                uom.category,
                uom.base_uom_id,
                uom.to_base_factor,
                uom.from_base_factor,
                CASE WHEN uom.base_uom_id IS NULL THEN 1 ELSE 0 END as is_base_unit,
                CASE 
                    WHEN uom.category = 'weight' THEN 'purchase'
                    WHEN uom.category = 'count'  THEN 'stock'
                    ELSE 'general'
                END as unit_type
            FROM UNITS_OF_MEASURE uom
            WHERE uom.is_active = 1
              AND (
                    uom.uom_id IN (
                        SELECT from_uom_id FROM PRODUCT_UOM_CONVERSIONS WHERE product_id = ? AND is_active = 1
                        UNION
                        SELECT to_uom_id   FROM PRODUCT_UOM_CONVERSIONS WHERE product_id = ? AND is_active = 1
                    )
                    OR uom.base_uom_id IS NULL
                  )
            ORDER BY uom.category, uom.code
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$product_id, $product_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting available units: " . $e->getMessage());
        return [];
    }
}


// Function to get unit options for dropdown
function getUnitOptions($product_id, $current_uom_id = null) {
    $units = getAvailableUnitsForProduct($product_id);
    $options = '';
    
    foreach ($units as $unit) {
        $selected = ($unit['uom_id'] == $current_uom_id) ? 'selected' : '';
        $options .= sprintf(
            '<option value="%d" data-category="%s" data-base-factor="%f" %s>%s (%s)</option>',
            $unit['uom_id'],
            $unit['category'],
            $unit['to_base_factor'] ?? 1.0,
            $selected,
            $unit['name'],
            $unit['code']
        );
    }
    
    return $options;
}

// Get PO Items only
function getPOItems($po_number) {
    global $pdo;
    
    try {
        // First get PO ID
        $po_query = "SELECT po_id FROM PO_Header WHERE po_number = ?";
        $stmt = $pdo->prepare($po_query);
        $stmt->execute([$po_number]);
        $po = $stmt->fetch();
        
        if (!$po) {
            return ['success' => false, 'message' => 'PO not found'];
        }
        
        // Get items
        $items_query = "
            SELECT 
                pi.po_item_id,
                pi.line_number,
                pi.product_id,
                pi.item_description,
                ISNULL(pi.quantity, 0) as quantity,
                ISNULL(pi.received_quantity, 0) as received_quantity,
                ISNULL(pi.unit_price, 0) as unit_price,
                ISNULL(mp.SSP_Code, 'N/A') as product_code,
                ISNULL(mp.Name, pi.item_description) as product_name,
                ISNULL(u_purchase.unit_name_th, 'หน่วย') as purchase_unit
            FROM PO_Items pi
            LEFT JOIN Master_Products_ID mp ON pi.product_id = mp.id
            LEFT JOIN Units u_purchase ON pi.purchase_unit_id = u_purchase.unit_id
            WHERE pi.po_id = ?
            ORDER BY pi.line_number
        ";
        
        $stmt = $pdo->prepare($items_query);
        $stmt->execute([$po['po_id']]);
        $items = $stmt->fetchAll();
        
        return [
            'success' => true,
            'items' => $items
        ];
        
    } catch (PDOException $e) {
        error_log("Error getting PO items: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error'];
    }
}

function getPODetailData($po_number) {
    global $pdo;
    
    try {
        // Query ดึงข้อมูล PO + Items + History ทั้งหมด
        $query = "
            SELECT 
                ph.*,
                s.supplier_name,
                s.contact_person,
                s.phone,
                s.email,
                u_created.full_name as created_by_name,
                u_approved.full_name as approved_by_name
            FROM PO_Header ph
            LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
            LEFT JOIN Users u_created ON ph.created_by = u_created.user_id
            LEFT JOIN Users u_approved ON ph.approved_by = u_approved.user_id
            WHERE ph.po_number = ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$po_number]);
        $po_data = $stmt->fetch();
        
        if (!$po_data) {
            return ['success' => false, 'message' => 'PO not found'];
        }
        
        // ดึงรายการสินค้า
        $items_query = "
            SELECT 
                pi.*,
                mp.SSP_Code,
                mp.Name as product_name,
                u_purchase.unit_name_th as purchase_unit,
                u_stock.unit_name_th as stock_unit
            FROM PO_Items pi
            LEFT JOIN Master_Products_ID mp ON pi.product_id = mp.id
            LEFT JOIN Units u_purchase ON pi.purchase_unit_id = u_purchase.unit_id
            LEFT JOIN Units u_stock ON pi.stock_unit_id = u_stock.unit_id
            WHERE pi.po_id = ?
            ORDER BY pi.line_number
        ";
        
        $stmt = $pdo->prepare($items_query);
        $stmt->execute([$po_data['po_id']]);
        $items = $stmt->fetchAll();
        
        // ดึงประวัติการรับเข้า
        $receipt_query = "
            SELECT 
                gr.gr_number,
                gr.receipt_date,
                gr.total_quantity,
                gr.total_amount,
                gr.status,
                u.full_name as received_by_name
            FROM Goods_Receipt gr
            LEFT JOIN Users u ON gr.received_by = u.user_id
            WHERE gr.po_id = ?
            ORDER BY gr.receipt_date DESC
        ";
        
        $stmt = $pdo->prepare($receipt_query);
        $stmt->execute([$po_data['po_id']]);
        $receipts = $stmt->fetchAll();
        
        return [
            'success' => true,
            'po_data' => $po_data,
            'items' => $items,
            'receipts' => $receipts
        ];
        
    } catch (PDOException $e) {
        error_log("Error getting PO details: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error'];
    }
}

// Save goods receipt (original version)
function saveReceipt($data) {
    global $pdo;
    
    try {
        // Validate required data
        if (!isset($data['po_data']) || !isset($data['items_data'])) {
            return ['success' => false, 'message' => 'Missing required data'];
        }
        
        $pdo->beginTransaction();
        
        // Generate GR Number
        $gr_number = generateGRNumber();
        
        $po_data = json_decode($data['po_data'], true);
        $items_data = json_decode($data['items_data'], true);
        
        if (!$po_data || !$items_data) {
            throw new Exception('Invalid JSON data');
        }
        
        $total_quantity = 0;
        $total_amount = 0;
        
        // Calculate totals
        foreach ($items_data as $item) {
            if (isset($item['received_quantity']) && $item['received_quantity'] > 0) {
                $total_quantity += $item['received_quantity'];
                $total_amount += $item['received_quantity'] * ($item['unit_price'] ?? 0);
            }
        }
        
        // Insert into Goods_Receipt
        $gr_query = "
            INSERT INTO Goods_Receipt (
                gr_number, po_id, receipt_date, warehouse_id, total_quantity, 
                total_amount, status, received_by, notes, created_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
        ";
        
        $stmt = $pdo->prepare($gr_query);
        $stmt->execute([
            $gr_number,
            $po_data['po_id'],
            date('Y-m-d'),
            1, // Default warehouse_id
            $total_quantity,
            $total_amount,
            'completed',
            $_SESSION['user_id'],
            $data['general_notes'] ?? ''
        ]);
        
        $gr_id = $pdo->lastInsertId();
        
        // Insert items and update PO quantities
        foreach ($items_data as $item) {
            if (isset($item['received_quantity']) && $item['received_quantity'] > 0) {
                // Insert GR Item
                $gr_item_query = "
                    INSERT INTO Goods_Receipt_Items (
                        gr_id, po_item_id, product_id, quantity_ordered, 
                        quantity_received, quantity_accepted, received_unit_id, 
                        stock_unit_id, conversion_factor, stock_quantity, 
                        unit_cost, total_cost, warehouse_id, quality_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";
                
                $conversion_factor = $item['conversion_factor'] ?? 1;
                $stock_quantity = $item['received_quantity'] * $conversion_factor;
                $unit_cost = $item['unit_price'] ?? 0;
                $total_cost = $item['received_quantity'] * $unit_cost;
                
                $stmt = $pdo->prepare($gr_item_query);
                $stmt->execute([
                    $gr_id,
                    $item['po_item_id'],
                    $item['product_id'],
                    $item['ordered_quantity'],
                    $item['received_quantity'],
                    $item['received_quantity'],
                    $item['purchase_unit_id'] ?? null,
                    $item['stock_unit_id'] ?? null,
                    $conversion_factor,
                    $stock_quantity,
                    $unit_cost,
                    $total_cost,
                    1, // Default warehouse
                    'good'
                ]);
                
                // Update PO Item received quantity
                $update_po_query = "
                    UPDATE PO_Items 
                    SET received_quantity = ISNULL(received_quantity, 0) + ?,
                        pending_quantity = quantity - (ISNULL(received_quantity, 0) + ?)
                    WHERE po_item_id = ?
                ";
                
                $stmt = $pdo->prepare($update_po_query);
                $stmt->execute([
                    $item['received_quantity'],
                    $item['received_quantity'],
                    $item['po_item_id']
                ]);
                
                // Update inventory stock
                if ($item['product_id']) {
                    updateInventoryStock($item['product_id'], 1, $stock_quantity, $unit_cost);
                    
                    // Create stock movement
                    createStockMovement([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => 1,
                        'movement_type' => 'IN',
                        'quantity' => $stock_quantity,
                        'unit_cost' => $unit_cost,
                        'reference_type' => 'GR',
                        'reference_id' => $gr_id,
                        'reference_number' => $gr_number,
                        'notes' => 'Goods Receipt from PO: ' . $po_data['po_number']
                    ]);
                }
            }
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Goods receipt saved successfully',
            'gr_number' => $gr_number,
            'gr_id' => $gr_id
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Error saving receipt: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error saving receipt: ' . $e->getMessage()
        ];
    }
}

// Enhanced save receipt with unit conversion
function saveReceiptWithConversion($data) {
    global $pdo;
    
    try {
        if (!isset($data['po_data']) || !isset($data['items_data'])) {
            return ['success' => false, 'message' => 'Missing required data'];
        }
        
        $pdo->beginTransaction();
        
        $gr_number = generateGRNumber();
        
        $po_data = json_decode($data['po_data'], true);
        $items_data = json_decode($data['items_data'], true);
        
        if (!$po_data || !$items_data) {
            throw new Exception('Invalid JSON data');
        }
        
        $total_quantity = 0;
        $total_amount = 0;
        
        // Calculate totals and validate conversions
        foreach ($items_data as $item) {
            if (isset($item['received_quantity']) && $item['received_quantity'] > 0) {
                // ตรวจสอบหน่วยที่ใช้รับ
                $receiving_unit_id = $item['receiving_unit_id'] ?? $item['purchase_unit_id'];
                $conversion_factor = 1;
                
                if ($item['product_id'] && $receiving_unit_id != $item['purchase_unit_id']) {
                    // คำนวณ conversion factor จาก receiving unit เป็น purchase unit
                    $conversion_factor = getConversionFactor(
                        $item['product_id'], 
                        $receiving_unit_id, 
                        $item['purchase_unit_id']
                    );
                }
                
                $purchase_quantity = $item['received_quantity'] * $conversion_factor;
                $total_quantity += $purchase_quantity;
                $total_amount += $purchase_quantity * ($item['unit_price'] ?? 0);
            }
        }
        
        // Insert into Goods_Receipt
        $gr_query = "
            INSERT INTO Goods_Receipt (
                gr_number, po_id, receipt_date, warehouse_id, total_quantity, 
                total_amount, status, received_by, notes, created_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
        ";
        
        $stmt = $pdo->prepare($gr_query);
        $stmt->execute([
            $gr_number,
            $po_data['po_id'],
            date('Y-m-d'),
            1,
            $total_quantity,
            $total_amount,
            'completed',
            $_SESSION['user_id'],
            $data['general_notes'] ?? ''
        ]);
        
        $gr_id = $pdo->lastInsertId();
        
        // Insert items with proper unit conversion
        foreach ($items_data as $item) {
            if (isset($item['received_quantity']) && $item['received_quantity'] > 0) {
                $receiving_unit_id = $item['receiving_unit_id'] ?? $item['purchase_unit_id'];
                $stock_unit_id = $item['stock_unit_id'] ?? $item['purchase_unit_id'];
                
                // คำนวณ conversion factors
                $purchase_conversion = 1;
                $stock_conversion = 1;
                
                if ($item['product_id']) {
                    if ($receiving_unit_id != $item['purchase_unit_id']) {
                        $purchase_conversion = getConversionFactor(
                            $item['product_id'], 
                            $receiving_unit_id, 
                            $item['purchase_unit_id']
                        );
                    }
                    
                    if ($receiving_unit_id != $stock_unit_id) {
                        $stock_conversion = getConversionFactor(
                            $item['product_id'], 
                            $receiving_unit_id, 
                            $stock_unit_id
                        );
                    }
                }
                
                $purchase_quantity = $item['received_quantity'] * $purchase_conversion;
                $stock_quantity = $item['received_quantity'] * $stock_conversion;
                $unit_cost = $item['unit_price'] ?? 0;
                $total_cost = $purchase_quantity * $unit_cost;
                
                // Verify unit IDs exist before insert
                if ($receiving_unit_id) {
                    $check_unit = $pdo->prepare("SELECT unit_id FROM Units WHERE unit_id = ?");
                    $check_unit->execute([$receiving_unit_id]);
                    if (!$check_unit->fetch()) {
                        $receiving_unit_id = $item['purchase_unit_id']; // Fallback to purchase unit if receiving unit not found
                    }
                } else {
                    $receiving_unit_id = $item['purchase_unit_id']; // Use purchase unit if no receiving unit specified
                }

                if ($stock_unit_id) {
                    $check_unit = $pdo->prepare("SELECT unit_id FROM Units WHERE unit_id = ?");
                    $check_unit->execute([$stock_unit_id]);
                    if (!$check_unit->fetch()) {
                        $stock_unit_id = $item['purchase_unit_id']; // Fallback to purchase unit if stock unit not found
                    }
                } else {
                    $stock_unit_id = $item['purchase_unit_id']; // Use purchase unit if no stock unit specified
                }

                // Insert GR Item
                $gr_item_query = "
                    INSERT INTO Goods_Receipt_Items (
                        gr_id, po_item_id, product_id, quantity_ordered, 
                        quantity_received, quantity_accepted, received_unit_id, 
                        stock_unit_id, conversion_factor, stock_quantity, 
                        unit_cost, total_cost, warehouse_id, quality_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";
                
                $stmt = $pdo->prepare($gr_item_query);
                $stmt->execute([
                    $gr_id,
                    $item['po_item_id'],
                    $item['product_id'],
                    $item['ordered_quantity'],
                    $purchase_quantity, // จำนวนใน purchase unit
                    $purchase_quantity,
                    $receiving_unit_id,
                    $stock_unit_id,
                    $stock_conversion,
                    $stock_quantity,
                    $unit_cost,
                    $total_cost,
                    1,
                    'good'
                ]);
                
                // Update PO Item
                $update_po_query = "
                    UPDATE PO_Items 
                    SET received_quantity = ISNULL(received_quantity, 0) + ?,
                        pending_quantity = quantity - (ISNULL(received_quantity, 0) + ?)
                    WHERE po_item_id = ?
                ";
                
                $stmt = $pdo->prepare($update_po_query);
                $stmt->execute([
                    $purchase_quantity,
                    $purchase_quantity,
                    $item['po_item_id']
                ]);
                
                // Update inventory และ stock movement
                if ($item['product_id']) {
                    updateInventoryStock($item['product_id'], 1, $stock_quantity, $unit_cost);
                    
                    createStockMovement([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => 1,
                        'movement_type' => 'IN',
                        'quantity' => $stock_quantity,
                        'unit_cost' => $unit_cost,
                        'reference_type' => 'GR',
                        'reference_id' => $gr_id,
                        'reference_number' => $gr_number,
                        'notes' => sprintf(
                            'Goods Receipt from PO: %s (Received: %s %s, Stock: %s %s)', 
                            $po_data['po_number'],
                            number_format($item['received_quantity'], 2),
                            $item['receiving_unit_name'] ?? '',
                            number_format($stock_quantity, 2),
                            $item['stock_unit_name'] ?? ''
                        )
                    ]);
                }
            }
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Goods receipt saved successfully with unit conversions',
            'gr_number' => $gr_number,
            'gr_id' => $gr_id
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Error saving receipt with conversion: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error saving receipt: ' . $e->getMessage()
        ];
    }
}

// Update inventory stock
function updateInventoryStock($product_id, $warehouse_id, $quantity, $unit_cost) {
    global $pdo;
    
    try {
        // Check if record exists
        $check_query = "SELECT inventory_id FROM Inventory_Stock WHERE product_id = ? AND warehouse_id = ?";
        $stmt = $pdo->prepare($check_query);
        $stmt->execute([$product_id, $warehouse_id]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            // Update existing record
            $update_query = "
                UPDATE Inventory_Stock 
                SET current_stock = current_stock + ?,
                    available_stock = available_stock + ?,
                    last_cost = ?,
                    last_updated = GETDATE(),
                    last_movement_date = GETDATE()
                WHERE product_id = ? AND warehouse_id = ?
            ";
            $stmt = $pdo->prepare($update_query);
            $stmt->execute([$quantity, $quantity, $unit_cost, $product_id, $warehouse_id]);
        } else {
            // Insert new record
            $insert_query = "
                INSERT INTO Inventory_Stock (
                    product_id, warehouse_id, current_stock, reserved_stock, available_stock,
                    min_stock_level, max_stock_level, reorder_point, average_cost, last_cost, 
                    last_updated, last_movement_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
            ";
            $stmt = $pdo->prepare($insert_query);
            $stmt->execute([
                $product_id, $warehouse_id, $quantity, 0, $quantity,
                0, 0, 0, $unit_cost, $unit_cost
            ]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error updating inventory: " . $e->getMessage());
        return false;
    }
}

// Create stock movement record
function createStockMovement($data) {
    global $pdo;
    
    try {
        $query = "
            INSERT INTO Stock_Movements (
                product_id, warehouse_id, location_id, movement_type, quantity,
                unit_id, unit_cost, total_cost, reference_type, reference_id,
                reference_number, movement_date, created_by, notes,
                stock_before, stock_after
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?, ?, ?, ?)
        ";
        
        // Get current stock for before/after tracking
        $stock_query = "SELECT ISNULL(current_stock, 0) as current_stock FROM Inventory_Stock WHERE product_id = ? AND warehouse_id = ?";
        $stmt = $pdo->prepare($stock_query);
        $stmt->execute([$data['product_id'], $data['warehouse_id']]);
        $stock_info = $stmt->fetch();
        $stock_before = $stock_info['current_stock'] ?? 0;
        $stock_after = $stock_before + $data['quantity'];
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['product_id'],
            $data['warehouse_id'],
            null, // location_id
            $data['movement_type'],
            $data['quantity'],
            null, // unit_id
            $data['unit_cost'],
            $data['quantity'] * $data['unit_cost'],
            $data['reference_type'],
            $data['reference_id'],
            $data['reference_number'],
            $_SESSION['user_id'],
            $data['notes'],
            $stock_before,
            $stock_after
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Error creating stock movement: " . $e->getMessage());
        return false;
    }
}

// Generate GR number
function generateGRNumber() {
    global $pdo;
    
    $year = date('Y');
    $month = date('m');
    
    try {
        $query = "
            SELECT TOP 1 gr_number 
            FROM Goods_Receipt 
            WHERE gr_number LIKE ?
            ORDER BY gr_number DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute(["GR-$year$month-%"]);
        $last_gr = $stmt->fetch();
        
        if ($last_gr) {
            $last_number = intval(substr($last_gr['gr_number'], -4));
            $new_number = $last_number + 1;
        } else {
            $new_number = 1;
        }
        
        return sprintf("GR-%s%s-%04d", $year, $month, $new_number);
        
    } catch (PDOException $e) {
        error_log("Error generating GR number: " . $e->getMessage());
        return "GR-" . $year . $month . "-" . date('His');
    }
}

// Save draft
function saveDraft($data) {
    global $pdo;
    
    try {
        if (!isset($data['po_data']) || !isset($data['items_data'])) {
            return ['success' => false, 'message' => 'Missing required data'];
        }
        
        $po_data = json_decode($data['po_data'], true);
        $items_data = json_decode($data['items_data'], true);
        
        if (!$po_data || !$items_data) {
            return ['success' => false, 'message' => 'Invalid JSON data'];
        }
        
        // For now, just return success - implement actual draft saving if needed
        return ['success' => true, 'message' => 'Draft saved successfully'];
        
    } catch (Exception $e) {
        error_log("Error saving draft: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error saving draft'];
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_filter = $_GET['date'] ?? '';

// Get PO data
$purchase_orders = getPurchaseOrders($search, $status_filter, $date_filter);

// Log page access
error_log("Receiving page accessed by user: " . ($_SESSION['username'] ?? 'unknown') . " (ID: " . ($_SESSION['user_id'] ?? 'unknown') . ")");
?>


<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รับเข้าสินค้า - จัดการการรับสินค้า Purchase Order</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #8B4513;
            --secondary-color: #FF8C00;
            --accent-color: #A0522D;
            --success-color: #059669;
            --warning-color: #d97706;
            --danger-color: #dc2626;
            --primary-gradient: linear-gradient(135deg, #8B4513, #A0522D);
        }

        body {
            background: linear-gradient(135deg, #F5DEB3 0%, #DEB887 50%, #D2B48C 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--primary-color);
        }
        
        .navbar {
            background: rgba(139, 69, 19, 0.9);
            box-shadow: 0 4px 20px rgba(139, 69, 19, 0.3);
        }
        
        .navbar-brand, .nav-link {
            color: white !important;
        }
        
        .nav-link:hover {
            color: #FFD700 !important;
        }
        
        .filter-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(139, 69, 19, 0.15);
            border: 1px solid rgba(139, 69, 19, 0.1);
        }
        
        .po-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 5px 15px rgba(139, 69, 19, 0.1);
            border: 1px solid rgba(139, 69, 19, 0.1);
            transition: all 0.3s ease;
        }
        
        .po-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(139, 69, 19, 0.2);
        }
        
        .po-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-align: center;
            min-width: 80px;
            display: inline-block;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-partial {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-complete {
            background: #d1fae5;
            color: #059669;
        }
        
        .progress {
            height: 6px;
            border-radius: 10px;
            background-color: #e5e7eb;
        }
        
        .progress-bar {
            border-radius: 10px;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid rgba(139, 69, 19, 0.2);
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(139, 69, 19, 0.25);
        }
        
        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            border-radius: 10px;
            padding: 12px 20px;
            font-weight: bold;
            color: white;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #A0522D, #8B4513);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(139, 69, 19, 0.3);
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #10b981);
            border: none;
            color: white;
        }
        
        .btn-outline-success {
            border-color: var(--success-color);
            color: var(--success-color);
        }
        
        .btn-outline-success:hover {
            background: var(--success-color);
            border-color: var(--success-color);
            color: white;
        }
        
        .po-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            margin-right: 15px;
        }
        
        .po-icon.pending {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }
        
        .po-icon.partial {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        }
        
        .po-icon.complete {
            background: linear-gradient(135deg, var(--success-color), #047857);
        }
        
        .receipt-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .date-badge {
            background: rgba(139, 69, 19, 0.1);
            color: var(--accent-color);
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 12px;
        }
        
        .container-fluid {
            max-width: 100%;
            padding: 0 15px;
        }
        
        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 20px;
        }
        
        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            color: var(--secondary-color);
        }
        
        .breadcrumb-item.active {
            color: var(--accent-color);
        }
        
        .breadcrumb-item + .breadcrumb-item::before {
            color: var(--accent-color);
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1050;
            display: none;
            backdrop-filter: blur(2px);
        }
        
        .modal-content-custom {
            position: relative;
            background: white;
            width: 95%;
            max-width: 1200px;
            height: 95vh;
            margin: 2.5vh auto;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: modalSlideIn 0.3s ease-out;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .modal-header-custom {
            background: var(--primary-gradient);
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        
        .modal-body-custom {
            flex: 1;
            overflow-y: auto;
            padding: 25px;
            background: linear-gradient(135deg, #F5DEB3 0%, #DEB887 50%, #D2B48C 100%);
        }
        
        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 50%;
            transition: background 0.3s ease;
        }
        
        .close-modal:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Receipt Form Styles */
        .receipt-header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(139, 69, 19, 0.15);
            border: 1px solid rgba(139, 69, 19, 0.1);
        }
        
        .item-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 5px 15px rgba(139, 69, 19, 0.1);
            border: 1px solid rgba(139, 69, 19, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .item-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(139, 69, 19, 0.2);
        }
        
        .item-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary-color), var(--secondary-color));
            opacity: 0.7;
        }
        
        .item-image {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px dashed #dee2e6;
            color: #6c757d;
            font-size: 20px;
        }
        
        .quantity-input {
            max-width: 120px;
            text-align: center;
        }
        
        .quantity-summary {
            background: rgba(139, 69, 19, 0.05);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .receiving-unit-select {
            border-radius: 8px;
            border: 1px solid #dee2e6;
            font-size: 0.875rem;
        }
        
        .receiving-unit-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(139, 69, 19, 0.25);
        }
        
        .conversion-info {
            margin-top: 10px;
        }
        
        .conversion-info .alert-info {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border: 1px solid #2196f3;
            color: #1565c0;
            border-radius: 8px;
            padding: 8px 12px;
        }
        
        .unit-conversion-indicator {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--warning-color);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
            display: none;
        }
        
        .item-card.has-conversion .unit-conversion-indicator {
            display: block;
        }
        
        .status-display .badge {
            min-width: 80px;
            padding: 6px 12px;
            font-size: 0.875rem;
        }

        @media (max-width: 768px) {
            .modal-content-custom {
                width: 98%;
                height: 98vh;
                margin: 1vh auto;
            }
            
            .item-card .row {
                flex-direction: column;
            }
            
            .quantity-input {
                max-width: 80px;
                margin: 0 auto;
            }
            
            .receiving-unit-select {
                margin-top: 8px;
            }
        }

        .loading {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 20px;
            border-radius: 10px;
            z-index: 9999;
        }

        .alert {
            border-radius: 10px;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .table-responsive {
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(139, 69, 19, 0.1);
        }

        /* Animation สำหรับการแสดงข้อมูลการแปลง */
        @keyframes slideInConversion {
            from {
                opacity: 0;
                transform: translateY(-10px);
                max-height: 0;
            }
            to {
                opacity: 1;
                transform: translateY(0);
                max-height: 100px;
            }
        }
        
        .conversion-info[style*="display: block"] {
            animation: slideInConversion 0.3s ease-out;
        }
        .item-card.has-conversion {
    border-left: 4px solid #059669;
    background: linear-gradient(90deg, rgba(5, 150, 105, 0.05) 0%, transparent 100%);
}

.conversion-info.auto-calculated {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    border: 1px solid #059669;
}

.conversion-info.auto-calculated .alert-info {
    background: transparent;
    border: none;
    color: #065f46;
}

.items-detail {
    font-size: 0.85rem;
    line-height: 1.4;
    max-height: 3.6em;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    color: #666;
    background: #f8f9fa;
    padding: 0.5rem;
    border-radius: 0.25rem;
    margin-top: 0.25rem;
}

.items-detail:hover {
    max-height: none;
    -webkit-line-clamp: unset;
    cursor: pointer;
    background: #e9ecef;
}

.items-detail:empty {
    display: none;
}
    </style>
</head>
<body>
    <!-- Loading overlay -->
    <div class="loading" id="loadingOverlay">
        <i class="fas fa-spinner fa-spin fa-2x"></i>
        <div class="mt-2">กำลังโหลด...</div>
    </div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <button class="btn btn-link text-white me-3" onclick="history.back()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div>
                    <h5 class="navbar-brand mb-0">
                        <i class="fas fa-clipboard-check me-2"></i>รับเข้าสินค้า
                    </h5>
                    <small class="text-light">จัดการการรับสินค้า Purchase Order</small>
                </div>
            </div>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-th-large me-1"></i> รายการรอรับ
                </a>
                <a class="nav-link" href="#">
                    <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt me-1"></i> ออกจากระบบ
                </a>
            </div>
        </div>
    </nav>

    <!-- Breadcrumb -->
    <div class="container-fluid mt-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard.php">หน้าหลัก</a></li>
                <li class="breadcrumb-item active">รับเข้าสินค้า</li>
            </ol>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="container-fluid">
        <!-- Filter Section -->
        <div class="filter-section">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">
                    <i class="fas fa-filter me-2"></i>ค้นหาและกรอง Purchase Order
                </h5>
                <button type="button" class="btn btn-outline-primary btn-sm" 
                        data-bs-toggle="modal" data-bs-target="#unitConversionHelpModal">
                    <i class="fas fa-question-circle me-1"></i>วิธีการแปลงหน่วย
                </button>
            </div>
            <form method="GET" action="">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">ค้นหา PO</label>
                        <div class="input-group">
                            <input type="text" class="form-control" name="search" 
                                   placeholder="ใส่หมายเลข PO หรือชื่อซัพพลายเออร์" 
                                   value="<?php echo htmlspecialchars($search); ?>" id="searchPO">
                            <button class="btn btn-outline-secondary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">สถานะ</label>
                        <select class="form-select" name="status" id="statusFilter">
                            <option value="">ทุกสถานะ</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>รอรับเข้า</option>
                            <option value="partial" <?php echo $status_filter === 'partial' ? 'selected' : ''; ?>>รับบางส่วน</option>
                            <option value="complete" <?php echo $status_filter === 'complete' ? 'selected' : ''; ?>>รับครบแล้ว</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">วันที่</label>
                        <input type="date" class="form-control" name="date" 
                               value="<?php echo htmlspecialchars($date_filter); ?>" id="dateFilter">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i>กรอง
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Purchase Order List -->
        <div class="po-list">
            <?php if (empty($purchase_orders)): ?>
                <div class="text-center py-5" id="emptyState">
                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">ไม่พบ Purchase Order</h5>
                    <p class="text-muted">ไม่มี PO ที่ตรงกับเงื่อนไขการค้นหา</p>
                </div>
            <?php else: ?>
                <?php foreach ($purchase_orders as $po): ?>
                    <div class="po-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="d-flex align-items-center">
                                <div class="po-icon <?php echo $po['receipt_status']; ?>">
                                    <i class="fas <?php 
                                        echo $po['receipt_status'] === 'pending' ? 'fa-clock' : 
                                            ($po['receipt_status'] === 'partial' ? 'fa-hourglass-half' : 'fa-check'); 
                                    ?>"></i>
                                </div>
                                <div>
                                    <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($po['po_number']); ?></h6>
                                    <small class="text-muted"><?php echo htmlspecialchars($po['supplier_name']); ?></small>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="po-status status-<?php echo $po['receipt_status']; ?> mb-2">
                                    <?php 
                                        echo $po['receipt_status'] === 'pending' ? 'รอรับเข้า' : 
                                            ($po['receipt_status'] === 'partial' ? 'รับบางส่วน' : 'รับครบแล้ว'); 
                                    ?>
                                </div>
                                <div class="date-badge">
                                    <?php echo formatDate($po['po_date'], 'd M Y'); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-3">
                                <small class="text-muted">จำนวนรายการ</small>
                                <div class="fw-bold"><?php echo number_format($po['total_items']); ?> รายการ</div>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">มูลค่ารวม</small>
                                <div class="fw-bold"><?php echo number_format($po['total_amount'], 2); ?> บาท</div>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted">รายละเอียดการรับเข้า</small>
                                <div class="small text-muted items-detail mt-1">
                                    <?php if (!empty($po['items_detail'])): ?>
                                        <?php echo htmlspecialchars($po['items_detail']); ?>
                                    <?php else: ?>
                                        ไม่มีข้อมูลการรับเข้า
                                    <?php endif; ?>
                                </div>
                                <div class="progress mt-2">
                                    <div class="progress-bar <?php 
                                        echo $po['receipt_status'] === 'pending' ? 'bg-warning' : 
                                            ($po['receipt_status'] === 'partial' ? 'bg-primary' : 'bg-success'); 
                                    ?>" style="width: <?php echo $po['completion_percentage']; ?>%"></div>
                                </div>
                                <small class="text-muted"><?php echo $po['completion_percentage']; ?>% เสร็จสิ้น</small>
                            </div>
                            <div class="col-md-3 text-end">
                                <div class="receipt-actions">
                                    <?php if ($po['receipt_status'] !== 'complete'): ?>
                                        <button class="btn btn-success btn-sm" 
                                                onclick="openReceiptModalWithConversion('<?php echo $po['po_number']; ?>')">
                                            <i class="fas fa-plus me-1"></i>รับเข้า
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-outline-success btn-sm" disabled>
                                            <i class="fas fa-check me-1"></i>เสร็จสิ้น
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-secondary btn-sm"
                                            onclick="viewPODetails('<?php echo $po['po_number']; ?>')">
                                        ดูรายละเอียด
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div class="modal-overlay" id="receiptModal">
        <div class="modal-content-custom">
            <div class="modal-header-custom">
                <div>
                    <h5 class="mb-0">
                        <i class="fas fa-clipboard-check me-2"></i>รับเข้าสินค้า
                        <span id="modalPONumber"></span>
                    </h5>
                    <small>บันทึกการรับเข้าสินค้า Purchase Order</small>
                </div>
                <button class="close-modal" onclick="closeReceiptModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="modal-body-custom">
                <!-- PO Header -->
                <div class="receipt-header">
                    <div class="row">
                        <div class="col-md-8">
                            <h6 class="fw-bold mb-3">
                                <i class="fas fa-file-invoice me-2"></i><span id="receiptPONumber"></span>
                                <span class="badge bg-warning ms-2">รอรับเข้า</span>
                            </h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>ซัพพลายเออร์:</strong> <span id="supplierName"></span></p>
                                    <p class="mb-2"><strong>วันที่สั่งซื้อ:</strong> <span id="poDate"></span></p>
                                    <p class="mb-2"><strong>จำนวนรายการ:</strong> <span id="totalItems"></span> รายการ</p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>มูลค่ารวม:</strong> <span id="totalAmount"></span> บาท</p>
                                    <p class="mb-2"><strong>ผู้อนุมัติ:</strong> <span id="approvedBy"></span></p>
                                    <p class="mb-2"><strong>วันที่รับเข้า:</strong> <span id="currentDate"></span></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="quantity-summary">
                                <h6 class="fw-bold mb-2">สรุปการรับเข้า</h6>
                                <div class="d-flex justify-content-between">
                                    <span>จำนวนที่สั่ง:</span>
                                    <span class="fw-bold" id="totalOrdered">0</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>จำนวนที่รับ:</span>
                                    <span class="fw-bold text-success" id="totalReceived">0</span>
                                </div>
                                <hr class="my-2">
                                <div class="d-flex justify-content-between">
                                    <span>เปอร์เซ็นต์:</span>
                                    <span class="fw-bold" id="percentage">0%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                        
                <!-- Items List -->
                <div id="itemsList">
                    <!-- Items will be loaded here via AJAX -->
                </div>

                <!-- General Notes -->
                <div class="item-card">
                    <h6 class="fw-bold mb-3"><i class="fas fa-sticky-note me-2"></i>หมายเหตุทั่วไป</h6>
                    <textarea class="form-control" rows="4" id="generalNotes" 
                              placeholder="หมายเหตุเพิ่มเติมเกี่ยวกับการรับเข้าสินค้าทั้งหมด"></textarea>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex justify-content-end gap-3 mb-4">
                    <button type="button" class="btn btn-outline-secondary" onclick="closeReceiptModal()">
                        <i class="fas fa-times me-2"></i>ยกเลิก
                    </button>
                    <button type="button" class="btn btn-outline-primary" onclick="saveDraft()">
                        <i class="fas fa-save me-2"></i>บันทึกร่าง
                    </button>
                    <button type="button" class="btn btn-success" onclick="submitReceiptWithConversion()">
                        <i class="fas fa-check me-2"></i>ยืนยันการรับเข้า
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentPOData = {};
let currentItems = [];

// Set current date
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('currentDate').textContent = new Date().toLocaleDateString('th-TH');
});

// Enhanced modal opening with conversion support
function openReceiptModalWithConversion(poNumber) {
    showLoading();
    
    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_po_data_with_conversions&po_number=' + encodeURIComponent(poNumber)
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        
        if (data.success) {
            currentPOData = data.po_data;
            currentItems = data.items;
            
            // Populate modal with data
            document.getElementById('modalPONumber').textContent = poNumber;
            document.getElementById('receiptPONumber').textContent = poNumber;
            document.getElementById('supplierName').textContent = data.po_data.supplier_name;
            document.getElementById('poDate').textContent = formatDate(data.po_data.po_date);
            document.getElementById('totalItems').textContent = data.items.length;
            document.getElementById('totalAmount').textContent = numberFormat(data.po_data.total_amount);
            document.getElementById('approvedBy').textContent = data.po_data.approved_by_name || 'ยังไม่อนุมัติ';
            
            // Load items with conversion support
            loadItemsWithConversion(data.items);
            
            // Show modal
            document.getElementById('receiptModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // Initialize summary
            updateSummaryWithConversion();
        } else {
            alert('เกิดข้อผิดพลาด: ' + data.message);
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Error:', error);
        alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
    });
}

// Enhanced loadItems function with unit conversion support
function loadItemsWithConversion(items) {
    const itemsList = document.getElementById('itemsList');
    itemsList.innerHTML = '';
    
    items.forEach((item, index) => {
        const itemCard = document.createElement('div');
        itemCard.className = 'item-card';
        
        // สร้าง options สำหรับหน่วยรับ
        let receivingUnitOptions = '';
        if (item.receiving_units && item.receiving_units.length > 0) {
            item.receiving_units.forEach(unit => {
                const selected = unit.unit_id == item.stock_unit_id ? 'selected' : '';
                receivingUnitOptions += `
                    <option value="${unit.unit_id}" 
                            data-conversion="${unit.conversion_factor}"
                            data-conversion-factor="${unit.conversion_factor}" 
                            data-symbol="${unit.unit_symbol}"
                            ${selected}>
                        ${unit.unit_name_th} (${unit.unit_symbol})
                    </option>
                `;
            });
        } else {
            receivingUnitOptions = `
                <option value="${item.purchase_unit_id}" 
                        data-conversion="1"
                        data-conversion-factor="1"
                        data-symbol="${item.purchase_unit_symbol}">
                    ${item.purchase_unit_name}
                </option>
            `;
        }

        // สร้างข้อมูลกระดาษ (ถ้ามี)
        let paperboardInfo = '';
        if (item.paperboard_info) {
            paperboardInfo = `
                <div class="paperboard-info mt-2">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        ขนาด: ${item.paperboard_info.W_mm}×${item.paperboard_info.L_mm} มม. |
                        GSM: ${item.paperboard_info.gsm} |
                        น้ำหนัก/แผ่น: ${item.paperboard_info.Weight_kg_per_sheet} กก. |
                        ${item.paperboard_info.sheets_per_kg} แผ่น/กก.
                    </small>
                </div>
            `;
        }
        
        itemCard.innerHTML = `
            <div class="unit-conversion-indicator">
                <i class="fas fa-exchange-alt"></i> แปลงหน่วย
            </div>
            
            <div class="row align-items-center">
                <div class="col-md-1">
                    <div class="item-image">
                        <i class="fas fa-box"></i>
                    </div>
                </div>
                <div class="col-md-3">
                    <h6 class="fw-bold mb-1">${item.product_name || item.item_description}</h6>
                    <small class="text-muted">รหัส: ${item.product_code || 'N/A'}</small>
                    ${paperboardInfo}
                </div>
                <div class="col-md-2 text-center">
                    <label class="form-label fw-bold">สั่งซื้อ</label>
                    <div class="fw-bold fs-6 text-primary">
                        ${numberFormat(item.quantity)} ${item.purchase_unit_name || 'หน่วย'}
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">จำนวนที่รับ</label>
                    <div class="input-group">
                        <input type="number" class="form-control quantity-input text-center" 
                               min="0" value="0" 
                               data-ordered="${item.quantity}" 
                               data-item-index="${index}"
                               data-product-id="${item.product_id}"
                               data-purchase-unit="${item.purchase_unit_id}"
                               onchange="updateQuantityWithConversion(this)"
                               oninput="updateQuantityWithConversion(this)">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">หน่วยรับ</label>
                    <select class="form-select receiving-unit-select" 
                            data-item-index="${index}"
                            onchange="updateReceivingUnit(this)">
                        ${receivingUnitOptions}
                    </select>
                </div>
                <div class="col-md-1 text-center">
                    <label class="form-label fw-bold">สถานะ</label>
                    <div class="status-display" id="status-${index}">
                        <span class="badge bg-secondary">ยังไม่รับ</span>
                    </div>
                </div>
                <div class="col-md-1 text-center">
                    <button type="button" class="btn btn-outline-primary btn-sm" 
                            onclick="fillMaxQuantityConverted(this)" title="เติมเต็มจำนวน">
                        <i class="fas fa-fill-drip"></i>
                    </button>
                </div>
            </div>
            
            <!-- แสดงข้อมูลการแปลงหน่วย -->
            <div class="row mt-2">
                <div class="col-md-12">
                    <div class="conversion-info" id="conversion-info-${index}" style="display: none;">
                        <div class="alert alert-info py-2">
                            <small>
                                <i class="fas fa-exchange-alt me-1"></i>
                                <span class="conversion-text"></span>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-md-12">
                    <label class="form-label">หมายเหตุ</label>
                    <input type="text" class="form-control item-notes" 
                           data-item-index="${index}"
                           placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)">
                </div>
            </div>
        `;
        
        itemsList.appendChild(itemCard);
    });
}

// Updated function to handle unit conversion when quantity changes
function updateQuantityWithConversion(input) {
    const itemIndex = input.dataset.itemIndex;
    const productId = input.dataset.productId;
    const purchaseUnitId = input.dataset.purchaseUnit;
    const receivedQuantity = parseFloat(input.value) || 0;
    
    const receivingUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
    const receivingUnitId = receivingUnitSelect.value;
    const conversionInfo = document.getElementById(`conversion-info-${itemIndex}`);
    const statusElement = document.getElementById(`status-${itemIndex}`);
    const itemCard = input.closest('.item-card');
    const orderedQuantity = parseFloat(input.dataset.ordered);
    
    // Calculate conversion if different units
    if (productId && receivingUnitId !== purchaseUnitId) {
        // คำนวณการแปลงและอัปเดตสถานะ
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=calculate_conversion&product_id=${productId}&from_unit=${receivingUnitId}&to_unit=${purchaseUnitId}&quantity=${receivedQuantity}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const convertedToPurchaseUnit = data.converted_quantity;
                
                // แสดงการแปลง
                calculateAndShowConversion(productId, purchaseUnitId, receivingUnitId, receivedQuantity, itemIndex);
                
                // อัปเดตสถานะด้วยค่าที่แปลงแล้ว
                updateItemStatus(statusElement, convertedToPurchaseUnit, orderedQuantity);
                
                itemCard.classList.add('has-conversion');
            } else {
                updateItemStatus(statusElement, receivedQuantity, orderedQuantity);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            updateItemStatus(statusElement, receivedQuantity, orderedQuantity);
        });
    } else {
        // Same unit, no conversion needed
        conversionInfo.style.display = 'none';
        itemCard.classList.remove('has-conversion');
        updateItemStatus(statusElement, receivedQuantity, orderedQuantity);
    }
    
    updateSummaryWithConversion();
}

// Function to update receiving unit - แปลงอัตโนมัติเมื่อเปลี่ยนหน่วย
function updateReceivingUnit(select) {
    const itemIndex = select.dataset.itemIndex;
    const quantityInput = document.querySelector(`input[data-item-index="${itemIndex}"]`);
    const item = currentItems[itemIndex];
    
    // คำนวณจำนวนที่ควรรับตามหน่วยใหม่
    if (item && item.product_id) {
        const orderedQuantity = parseFloat(item.quantity) || 0;
        const purchaseUnitId = item.purchase_unit_id;
        const receivingUnitId = select.value;
        
        if (receivingUnitId && receivingUnitId !== purchaseUnitId) {
            // แปลงจากหน่วยซื้อเป็นหน่วยรับ
            calculateConversionAndSetQuantity(
                item.product_id, 
                purchaseUnitId, 
                receivingUnitId, 
                orderedQuantity, 
                itemIndex
            );
        } else {
            // หน่วยเดียวกัน ใช้จำนวนเดิม
            quantityInput.value = orderedQuantity;
            updateQuantityWithConversion(quantityInput);
        }
    }
}

// คำนวณและตั้งค่าจำนวนอัตโนมัติ
function calculateConversionAndSetQuantity(productId, fromUnitId, toUnitId, quantity, itemIndex) {
    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=calculate_conversion&product_id=${productId}&from_unit=${fromUnitId}&to_unit=${toUnitId}&quantity=${quantity}`
    })
    .then(response => response.json())
    .then(data => {
        const quantityInput = document.querySelector(`input[data-item-index="${itemIndex}"]`);
        
        if (data.success) {
            // ตรวจสอบหน่วยปลายทาง ถ้าเป็นแผ่นให้ปัดเป็นจำนวนเต็ม
            const receivingUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
            const selectedOption = receivingUnitSelect.options[receivingUnitSelect.selectedIndex];
            const unitSymbol = selectedOption.dataset.symbol;
            
            let finalQuantity = data.converted_quantity;
            
            // หากหน่วยปลายทางเป็น Sheets ให้ปัดเป็นจำนวนเต็ม
            if (unitSymbol === 'SHEET' || unitSymbol === 'PCS' || 
                selectedOption.text.includes('แผ่น') || selectedOption.text.includes('Sheets')) {
                finalQuantity = Math.round(data.converted_quantity);
            }
            
            // ตั้งค่าจำนวนที่แปลงแล้ว
            quantityInput.value = finalQuantity;
            
            // อัปเดตการแสดงผล
            updateQuantityWithConversion(quantityInput);
            
            // แสดงข้อมูลการแปลง (แสดงทั้งค่าจริงและค่าที่ปัด)
            showAutoConversionInfo(itemIndex, quantity, data.converted_quantity, finalQuantity, fromUnitId, toUnitId);
        } else {
            quantityInput.value = quantity;
            updateQuantityWithConversion(quantityInput);
        }
    })
    .catch(error => {
        console.error('Error calculating conversion:', error);
        const quantityInput = document.querySelector(`input[data-item-index="${itemIndex}"]`);
        quantityInput.value = quantity;
        updateQuantityWithConversion(quantityInput);
    });
}

// แสดงข้อมูลการแปลงอัตโนมัติ
function showAutoConversionInfo(itemIndex, originalQuantity, exactConversion, finalQuantity, fromUnitId, toUnitId) {
    const conversionInfo = document.getElementById(`conversion-info-${itemIndex}`);
    const item = currentItems[itemIndex];
    
    if (conversionInfo && item) {
        const fromUnitText = item.purchase_unit_name || 'หน่วยเดิม';
        const receivingUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
        const toUnitText = receivingUnitSelect ? 
            receivingUnitSelect.options[receivingUnitSelect.selectedIndex].text : 'หน่วยใหม่';
        
        let conversionText = `
            <strong>การแปลงอัตโนมัติ:</strong><br>
            สั่งซื้อ: ${numberFormat(originalQuantity)} ${fromUnitText}<br>
        `;
        
        // แสดงการปัดเศษ (ถ้ามี)
        if (exactConversion !== finalQuantity) {
            conversionText += `
                คำนวณได้: ${numberFormat(exactConversion)} ${toUnitText}<br>
                รับเข้า: ${numberFormat(finalQuantity)} ${toUnitText} <span class="badge bg-info">ปัดเศษ</span>
            `;
        } else {
            conversionText += `รับเข้า: ${numberFormat(finalQuantity)} ${toUnitText}`;
        }
        
        conversionInfo.querySelector('.conversion-text').innerHTML = conversionText;
        conversionInfo.style.display = 'block';
        conversionInfo.classList.add('auto-calculated');
        
        const itemCard = document.querySelector(`input[data-item-index="${itemIndex}"]`).closest('.item-card');
        if (itemCard) {
            itemCard.classList.add('has-conversion');
        }
    }
}

// Calculate and display conversion information - ปรับปรุงแล้ว
function calculateAndShowConversion(productId, fromUnitId, toUnitId, quantity, itemIndex) {
    if (quantity === 0) {
        document.getElementById(`conversion-info-${itemIndex}`).style.display = 'none';
        return;
    }
    
    // Call backend to calculate conversion
    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=calculate_conversion&product_id=${productId}&from_unit=${fromUnitId}&to_unit=${toUnitId}&quantity=${quantity}`
    })
    .then(response => response.json())
    .then(data => {
        const conversionInfo = document.getElementById(`conversion-info-${itemIndex}`);
        
        if (data.success) {
            const fromUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
            const fromUnitText = fromUnitSelect.options[fromUnitSelect.selectedIndex].text;
            
            // Get purchase unit name from current items
            const purchaseUnitText = currentItems[itemIndex].purchase_unit_name;
            
            conversionInfo.querySelector('.conversion-text').innerHTML = `
                ${numberFormat(quantity)} ${fromUnitText} 
                = ${numberFormat(data.converted_quantity)} ${purchaseUnitText}
                (อัตราแปลง: 1:${data.conversion_factor})
            `;
            conversionInfo.style.display = 'block';
            conversionInfo.classList.remove('auto-calculated');
        } else {
            conversionInfo.style.display = 'none';
        }
    })
    .catch(error => {
        console.error('Error calculating conversion:', error);
        document.getElementById(`conversion-info-${itemIndex}`).style.display = 'none';
    });
}

// Get conversion factor from select element
function getConversionFactorFromSelect(select) {
    const selectedOption = select.options[select.selectedIndex];
    return parseFloat(selectedOption.dataset.conversion) || 1;
}

// Updated fill max quantity with conversion
function fillMaxQuantityConverted(button) {
    const row = button.closest('.item-card');
    const itemIndex = row.querySelector('input[type="number"]').dataset.itemIndex;
    const receivingUnitSelect = row.querySelector('select.receiving-unit-select');
    
    // เรียกใช้ updateReceivingUnit เพื่อคำนวณใหม่
    updateReceivingUnit(receivingUnitSelect);
}

// Updated summary calculation with unit conversion
    // Get conversion factor from select element
    function getConversionFactorFromSelect(select) {
        const selectedOption = select.options[select.selectedIndex];
        return parseFloat(selectedOption.dataset.conversionFactor) || 1;
    }

    function updateSummaryWithConversion() {
        const inputs = document.querySelectorAll('#receiptModal input[type="number"]');
        let totalOrdered = 0;
        let totalReceived = 0;
        
        inputs.forEach(input => {
            const itemIndex = input.dataset.itemIndex;
            const orderedQuantity = parseFloat(input.dataset.ordered) || 0;
            const receivedQuantity = parseFloat(input.value) || 0;
            
            // ต้องคำนวณจากหน่วยที่แปลงแล้ว
            const receivingUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
            const selectedOption = receivingUnitSelect.options[receivingUnitSelect.selectedIndex];
            const conversionFactor = parseFloat(selectedOption.dataset.conversionFactor) || 1;
            
            totalOrdered += orderedQuantity;
            totalReceived += (receivedQuantity * conversionFactor);
    });
    
    document.getElementById('totalOrdered').textContent = numberFormat(totalOrdered);
    document.getElementById('totalReceived').textContent = numberFormat(totalReceived);
    
    // คำนวณเปอร์เซ็นต์โดยใช้ค่าที่แปลงแล้ว
    let percentage = 0;
    if (totalOrdered > 0) {
        // คำนวณเปอร์เซ็นต์จากค่าที่แปลงแล้ว
        const ratio = totalReceived / totalOrdered;
        const actualPercentage = ratio * 100;
        
        // ถ้าอยู่ในช่วง 97-103% ให้แสดง 100%
        if (Math.abs(1 - ratio) <= 0.03) { // ใช้ relative difference แทน
            percentage = 100;
        } else {
            percentage = Math.min(Math.round(actualPercentage), 100); // จำกัดค่าสูงสุดที่ 100%
        }
    }
    
    document.getElementById('percentage').textContent = percentage + '%';
}

// Update item status based on quantities
function updateItemStatus(statusElement, receivedQuantity, orderedQuantity) {
    // คำนวณ tolerance 3% ของจำนวนที่สั่ง
    const tolerance = orderedQuantity * 0.03; // 3% ของจำนวนที่สั่ง
    const lowerBound = orderedQuantity - tolerance;
    const upperBound = orderedQuantity + tolerance;
    
    console.log(`Status calculation:`, {
        receivedQuantity,
        orderedQuantity,
        tolerance: tolerance,
        lowerBound: lowerBound,
        upperBound: upperBound,
        difference: receivedQuantity - orderedQuantity,
        differencePercent: ((receivedQuantity - orderedQuantity) / orderedQuantity * 100).toFixed(1) + '%'
    });
    
    if (receivedQuantity === 0) {
        statusElement.innerHTML = '<span class="badge bg-secondary">ยังไม่รับ</span>';
    } else if (receivedQuantity < lowerBound) {
        statusElement.innerHTML = '<span class="badge bg-warning">รับบางส่วน</span>';
    } else if (receivedQuantity >= lowerBound && receivedQuantity <= upperBound) {
        statusElement.innerHTML = '<span class="badge bg-success">รับครบ</span>';
    } else {
        statusElement.innerHTML = '<span class="badge bg-info">เกินจำนวน</span>';
    }
}

// Enhanced form data collection with unit conversion
function collectFormDataWithConversion() {
    const itemsData = [];
    const inputs = document.querySelectorAll('#receiptModal input[type="number"]');
    
    inputs.forEach(input => {
        const itemIndex = parseInt(input.dataset.itemIndex);
        const item = currentItems[itemIndex];
        const receivingUnitSelect = document.querySelector(`select[data-item-index="${itemIndex}"]`);
        const notesInput = document.querySelector(`input.item-notes[data-item-index="${itemIndex}"]`);
        
        const receivingUnitId = receivingUnitSelect ? receivingUnitSelect.value : item.purchase_unit_id;
        const receivedQuantity = parseFloat(input.value) || 0;
        
        if (receivedQuantity > 0) {
            itemsData.push({
                po_item_id: item.po_item_id,
                product_id: item.product_id,
                ordered_quantity: item.quantity,
                received_quantity: receivedQuantity,
                receiving_unit_id: receivingUnitId,
                unit_price: item.unit_price,
                purchase_unit_id: item.purchase_unit_id,
                stock_unit_id: item.stock_unit_id,
                conversion_factor: item.conversion_factor,
                receiving_unit_name: receivingUnitSelect ? 
                    receivingUnitSelect.options[receivingUnitSelect.selectedIndex].text : '',
                stock_unit_name: item.stock_unit_name,
                notes: notesInput ? notesInput.value : ''
            });
        }
    });
    
    return {
        po_data: JSON.stringify(currentPOData),
        items_data: JSON.stringify(itemsData),
        general_notes: document.getElementById('generalNotes').value
    };
}

// Enhanced submit receipt with conversion
function submitReceiptWithConversion() {
    // Validate form
    const inputs = document.querySelectorAll('#receiptModal input[type="number"]');
    let hasReceived = false;
    
    inputs.forEach(input => {
        if (parseFloat(input.value) > 0) {
            hasReceived = true;
        }
    });
    
    if (!hasReceived) {
        alert('กรุณาระบุจำนวนสินค้าที่รับเข้าอย่างน้อย 1 รายการ');
        return;
    }
    
    if (confirm('ยืนยันการบันทึกรับเข้าสินค้าหรือไม่?\n\nเมื่อยืนยันแล้วจะไม่สามารถแก้ไขได้')) {
        showLoading();
        
        const formData = collectFormDataWithConversion();
        formData.action = 'save_receipt_with_conversion';
        
        submitFormData(formData, function(response) {
            hideLoading();
            if (response.success) {
                alert('บันทึกการรับเข้าสินค้าเรียบร้อยแล้ว\nเลขที่ใบรับ: ' + response.gr_number);
                closeReceiptModal();
                location.reload();
            } else {
                alert('เกิดข้อผิดพลาด: ' + response.message);
            }
        });
    }
}

function closeReceiptModal() {
    document.getElementById('receiptModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    resetReceiptForm();
}

function resetReceiptForm() {
    // Reset all quantity inputs
    const inputs = document.querySelectorAll('#receiptModal input[type="number"]');
    inputs.forEach(input => {
        input.value = 0;
        updateQuantityWithConversion(input);
    });
    
    // Clear notes
    const textInputs = document.querySelectorAll('#receiptModal input[type="text"]');
    textInputs.forEach(input => input.value = '');
    
    // Clear textarea
    document.getElementById('generalNotes').value = '';
}

function saveDraft() {
    if (confirm('ต้องการบันทึกข้อมูลเป็นร่างหรือไม่?')) {
        showLoading();
        
        const formData = collectFormDataWithConversion();
        formData.action = 'save_draft';
        
        submitFormData(formData, function(response) {
            hideLoading();
            if (response.success) {
                alert('บันทึกข้อมูลร่างเรียบร้อยแล้ว');
            } else {
                alert('เกิดข้อผิดพลาด: ' + response.message);
            }
        });
    }
}

function submitFormData(formData, callback) {
    const form = new FormData();
    for (const key in formData) {
        form.append(key, formData[key]);
    }
    
    fetch('', {
        method: 'POST',
        body: form
    })
    .then(response => response.json())
    .then(callback)
    .catch(error => {
        hideLoading();
        console.error('Error:', error);
        alert('เกิดข้อผิดพลาดในการส่งข้อมูล');
    });
}

function viewPODetails(poNumber) {
    // ตัวเลือก 1: เปิดหน้าใหม่
    window.location.href = `po-details.php?po=${poNumber}`;
}

// Utility functions
function showLoading() {
    document.getElementById('loadingOverlay').style.display = 'block';
}

function hideLoading() {
    document.getElementById('loadingOverlay').style.display = 'none';
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('th-TH');
}

function numberFormat(number) {
    return new Intl.NumberFormat('th-TH').format(number);
}

// Close modal when clicking outside
document.getElementById('receiptModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeReceiptModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('receiptModal').style.display === 'block') {
        closeReceiptModal();
    }
});

// Auto-refresh every 5 minutes
setInterval(() => {
    console.log('Auto-refreshing PO status...');
}, 300000);
</script>
</body>
</html>