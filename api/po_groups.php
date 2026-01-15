<?php
// production/api/po_groups.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// เริ่ม session และโหลด config
session_start();
require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../classes/Auth.php";
require_once __DIR__ . "/../classes/POGroup.php";
require_once __DIR__ . "/../classes/POItems.php";
require_once __DIR__ . "/../classes/Material.php";

// ตรวจสอบการล็อกอิน
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'Authentication required',
        'error_code' => 'AUTH_REQUIRED'
    ]);
    exit();
}

// สร้าง objects
$poGroup = new POGroup();
$poItems = new POItems();
$material = new Material();

// ดึง path และ method
$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// ลบ base path
$base_path = '/production/api/po_groups.php';
$endpoint = str_replace($base_path, '', $path);
$endpoint = trim($endpoint, '/');

// แยก path segments
$segments = $endpoint ? explode('/', $endpoint) : [];

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($segments, $poGroup, $poItems, $material);
            break;
            
        case 'POST':
            handlePostRequest($segments, $poGroup, $poItems, $material);
            break;
            
        case 'PUT':
            handlePutRequest($segments, $poGroup, $poItems);
            break;
            
        case 'DELETE':
            handleDeleteRequest($segments, $poItems);
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'success' => false, 
                'message' => 'Method not allowed',
                'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE']
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Internal server error',
        'error' => DEBUG_MODE ? $e->getMessage() : 'An error occurred'
    ]);
}

/**
 * Handle GET requests
 */
function handleGetRequest($segments, $poGroup, $poItems, $material) {
    if (empty($segments[0])) {
        // GET /api/po_groups - ดึงรายการ PO Groups ทั้งหมด
        $filters = [];
        if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
        if (isset($_GET['supplier_id'])) $filters['supplier_id'] = intval($_GET['supplier_id']);
        if (isset($_GET['date_from'])) $filters['date_from'] = $_GET['date_from'];
        if (isset($_GET['date_to'])) $filters['date_to'] = $_GET['date_to'];
        if (isset($_GET['search'])) $filters['search'] = $_GET['search'];
        
        $groups = $poGroup->getAllPOGroups($filters);
        
        echo json_encode([
            'success' => true,
            'data' => $groups,
            'count' => count($groups),
            'filters_applied' => $filters
        ]);
        
    } elseif (is_numeric($segments[0])) {
        $group_id = intval($segments[0]);
        
        if (isset($segments[1])) {
            switch ($segments[1]) {
                case 'cost-report':
                    // GET /api/po_groups/{id}/cost-report
                    $group_details = $poGroup->getPOGroupDetails($group_id);
                    if (!$group_details['success']) {
                        http_response_code(404);
                        echo json_encode($group_details);
                        return;
                    }
                    
                    $material_po_id = null;
                    foreach ($group_details['pos'] as $po) {
                        if ($po['po_category'] === 'MATERIAL') {
                            $material_po_id = $po['po_id'];
                            break;
                        }
                    }
                    
                    if (!$material_po_id) {
                        echo json_encode([
                            'success' => true,
                            'items' => [],
                            'summary' => [
                                'total_items' => 0,
                                'total_material_cost' => 0,
                                'total_freight_allocated' => 0,
                                'grand_total_cost' => 0
                            ]
                        ]);
                        return;
                    }
                    
                    $cost_report = $poItems->getCostReportWithFreight($material_po_id);
                    echo json_encode($cost_report);
                    break;
                    
                case 'items':
                    // GET /api/po_groups/{id}/items
                    $group_details = $poGroup->getPOGroupDetails($group_id);
                    if (!$group_details['success']) {
                        http_response_code(404);
                        echo json_encode($group_details);
                        return;
                    }
                    
                    $material_po_id = null;
                    foreach ($group_details['pos'] as $po) {
                        if ($po['po_category'] === 'MATERIAL') {
                            $material_po_id = $po['po_id'];
                            break;
                        }
                    }
                    
                    if ($material_po_id) {
                        $items = $poItems->getPOItemsWithUnits($material_po_id);
                        echo json_encode($items);
                    } else {
                        echo json_encode([
                            'success' => true,
                            'items' => [],
                            'items_count' => 0
                        ]);
                    }
                    break;
                    
                default:
                    http_response_code(404);
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Endpoint not found'
                    ]);
                    break;
            }
        } else {
            // GET /api/po_groups/{id} - ดึงข้อมูล PO Group เฉพาะ
            $result = $poGroup->getPOGroupDetails($group_id);
            
            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode($result);
            }
        }
        
    } elseif ($segments[0] === 'lookup') {
        // GET /api/po_groups/lookup - ดึงข้อมูล lookup tables
        handleLookupRequest($segments, $material);
        
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'Endpoint not found'
        ]);
    }
}

/**
 * Handle lookup requests
 */
function handleLookupRequest($segments, $material) {
    if (isset($segments[1])) {
        switch ($segments[1]) {
            case 'suppliers':
                $data = $material->getSuppliers();
                break;
                
            case 'products':
                $search = $_GET['search'] ?? '';
                $data = $search ? $material->searchProductsForPO($search) : $material->getAllProducts(['is_active' => 1]);
                break;
                
            case 'units':
                $data = $material->getAllUnits();
                break;
                
            case 'material-types':
                $data = $material->getMaterialTypes();
                break;
                
            case 'groups':
                $data = $material->getGroups();
                break;
                
            default:
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Lookup type not found']);
                return;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $data,
            'count' => count($data)
        ]);
    } else {
        // ดึงข้อมูล lookup ทั้งหมด
        echo json_encode([
            'success' => true,
            'data' => [
                'suppliers' => $material->getSuppliers(),
                'units' => $material->getAllUnits(),
                'material_types' => $material->getMaterialTypes(),
                'groups' => $material->getGroups()
            ]
        ]);
    }
}

/**
 * Handle POST requests
 */
function handlePostRequest($segments, $poGroup, $poItems, $material) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Invalid JSON input'
        ]);
        return;
    }
    
    if (empty($segments[0])) {
        // POST /api/po_groups - สร้าง PO Group ใหม่
        $input['created_by'] = $_SESSION['user_id'];
        $result = $poGroup->createPOGroup($input);
        
        if ($result['success']) {
            http_response_code(201);
        } else {
            http_response_code(400);
        }
        echo json_encode($result);
        
    } elseif (is_numeric($segments[0])) {
        $group_id = intval($segments[0]);
        
        if (isset($segments[1])) {
            switch ($segments[1]) {
                case 'complete-order':
                    // POST /api/po_groups/{id}/complete-order
                    handleCompleteOrderCreation($group_id, $input, $poGroup, $poItems);
                    break;
                    
                case 'calculate-freight':
                    // POST /api/po_groups/{id}/calculate-freight
                    $allocation_method = $input['allocation_method'] ?? 'VALUE';
                    $result = $poGroup->calculateFreightAllocation($group_id, $allocation_method);
                    
                    if ($result['success']) {
                        http_response_code(200);
                    } else {
                        http_response_code(400);
                    }
                    echo json_encode($result);
                    break;
                    
                case 'items':
                    // POST /api/po_groups/{id}/items
                    handleAddItems($group_id, $input, $poGroup, $poItems);
                    break;
                    
                default:
                    http_response_code(404);
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Endpoint not found'
                    ]);
                    break;
            }
        }
        
    } elseif ($segments[0] === 'unit-conversion') {
        // POST /api/po_groups/unit-conversion
        if (!isset($input['product_id']) || !isset($input['quantity']) || !isset($input['from_unit_id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false, 
                'message' => 'Missing required fields: product_id, quantity, from_unit_id'
            ]);
            return;
        }
        
        $result = $poItems->calculateUnitConversion(
            $input['product_id'],
            $input['quantity'],
            $input['from_unit_id'],
            $input['to_unit_id'] ?? null
        );
        
        echo json_encode($result);
        
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'Endpoint not found'
        ]);
    }
}

/**
 * Handle complete order creation
 */
function handleCompleteOrderCreation($group_id, $input, $poGroup, $poItems) {
    // ตรวจสอบข้อมูลที่จำเป็น
    if (!isset($input['material_data']) || !isset($input['items_data'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Missing required fields: material_data, items_data'
        ]);
        return;
    }
    
    if (empty($input['items_data']) || !is_array($input['items_data'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'items_data must be a non-empty array'
        ]);
        return;
    }
    
    $input['material_data']['created_by'] = $_SESSION['user_id'];
    if (isset($input['freight_data'])) {
        $input['freight_data']['created_by'] = $_SESSION['user_id'];
    }
    
    // สร้าง POs
    $pos_result = $poGroup->createLinkedPOs(
        $group_id,
        $input['material_data'],
        $input['freight_data'] ?? null
    );
    
    if (!$pos_result['success']) {
        http_response_code(400);
        echo json_encode($pos_result);
        return;
    }
    
    // เพิ่มรายการสินค้า
    $items_result = $poItems->addMultiplePOItems(
        $pos_result['material_po_id'],
        $input['items_data']
    );
    
    if (!$items_result['success']) {
        http_response_code(400);
        echo json_encode($items_result);
        return;
    }
    
    // คำนวณการแบ่งค่าขนส่ง (ถ้ามี)
    $allocation_result = null;
    if ($pos_result['freight_po_id'] && isset($input['allocation_method'])) {
        $allocation_result = $poGroup->calculateFreightAllocation(
            $group_id,
            $input['allocation_method']
        );
    }
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'pos_result' => $pos_result,
        'items_result' => $items_result,
        'allocation_result' => $allocation_result,
        'message' => 'Complete order created successfully'
    ]);
}

/**
 * Handle adding items to existing PO
 */
function handleAddItems($group_id, $input, $poGroup, $poItems) {
    // หา Material PO ID
    $group_details = $poGroup->getPOGroupDetails($group_id);
    if (!$group_details['success']) {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'PO Group not found'
        ]);
        return;
    }
    
    $material_po_id = null;
    foreach ($group_details['pos'] as $po) {
        if ($po['po_category'] === 'MATERIAL') {
            $material_po_id = $po['po_id'];
            break;
        }
    }
    
    if (!$material_po_id) {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'No Material PO found in this group'
        ]);
        return;
    }
    
    // เพิ่มรายการสินค้า
    if (isset($input['items']) && is_array($input['items'])) {
        $result = $poItems->addMultiplePOItems($material_po_id, $input['items']);
    } else {
        $result = $poItems->addPOItem($material_po_id, $input);
    }
    
    if ($result['success']) {
        http_response_code(201);
    } else {
        http_response_code(400);
    }
    echo json_encode($result);
}

/**
 * Handle PUT requests
 */
function handlePutRequest($segments, $poGroup, $poItems) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Invalid JSON input'
        ]);
        return;
    }
    
    if (isset($segments[0]) && $segments[0] === 'items' && isset($segments[1]) && is_numeric($segments[1])) {
        // PUT /api/po_groups/items/{id} - แก้ไขรายการสินค้า
        $po_item_id = intval($segments[1]);
        $result = $poItems->updatePOItem($po_item_id, $input);
        
        if ($result['success']) {
            http_response_code(200);
        } else {
            http_response_code(400);
        }
        echo json_encode($result);
        
    } elseif (isset($segments[0]) && is_numeric($segments[0])) {
        // PUT /api/po_groups/{id} - อัพเดต PO Group (สำหรับอนาคต)
        http_response_code(501);
        echo json_encode([
            'success' => false, 
            'message' => 'PO Group update not implemented yet'
        ]);
        
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'Endpoint not found'
        ]);
    }
}

/**
 * Handle DELETE requests
 */
function handleDeleteRequest($segments, $poItems) {
    if (isset($segments[0]) && $segments[0] === 'items' && isset($segments[1]) && is_numeric($segments[1])) {
        // DELETE /api/po_groups/items/{id} - ลบรายการสินค้า
        $po_item_id = intval($segments[1]);
        $result = $poItems->deletePOItem($po_item_id);
        
        if ($result['success']) {
            http_response_code(200);
        } else {
            http_response_code(400);
        }
        echo json_encode($result);
        
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'Endpoint not found'
        ]);
    }
}

/* 
=== API Documentation ===

Base URL: /production/api/po_groups.php

== Authentication ==
- ต้องมี valid session
- ส่ง session cookie ในทุก request

== PO Groups Management ==

GET /api/po_groups
- ดึงรายการ PO Groups ทั้งหมด
- Query Parameters:
  - status: DRAFT|APPROVED|COMPLETED
  - supplier_id: (int) กรองตาม supplier
  - date_from: YYYY-MM-DD
  - date_to: YYYY-MM-DD  
  - search: ค้นหาใน group_code, group_name
- Response: { success: true, data: [...], count: number }

GET /api/po_groups/{id}
- ดึงข้อมูล PO Group เฉพาะพร้อมรายละเอียด
- Response: { success: true, group_data: {...}, pos: [...], allocations: [...] }

POST /api/po_groups
- สร้าง PO Group ใหม่
- Body: { group_name, description, supplier_id }
- Response: { success: true, group_id: number, group_code: string }

== Complete Order Creation ==

POST /api/po_groups/{id}/complete-order
- สร้างใบสั่งซื้อครบชุด (Material PO + Freight PO + Items)
- Body: {
    material_data: {
      supplier_id: (int),
      po_date: "YYYY-MM-DD",
      delivery_date: "YYYY-MM-DD",
      payment_terms: "Net 30"
    },
    freight_data: {
      freight_amount: (decimal)
    },
    items_data: [
      {
        product_id: (int),
        quantity: (decimal),
        purchase_unit_id: (int),
        unit_price: (decimal),
        item_description: "optional"
      }
    ],
    allocation_method: "VALUE|WEIGHT|QUANTITY"
  }
- Response: { success: true, pos_result: {...}, items_result: {...}, allocation_result: {...} }

== Freight Allocation ==

POST /api/po_groups/{id}/calculate-freight
- คำนวณการแบ่งค่าขนส่งใหม่
- Body: { allocation_method: "VALUE|WEIGHT|QUANTITY" }
- Response: { success: true, total_freight: number, allocation_method: string, items_count: number }

== Items Management ==

GET /api/po_groups/{id}/items
- ดึงรายการสินค้าใน Material PO
- Response: { success: true, items: [...], items_count: number }

POST /api/po_groups/{id}/items
- เพิ่มรายการสินค้าใน Material PO
- Body (single): { product_id, quantity, purchase_unit_id, unit_price, ... }
- Body (multiple): { items: [{ product_id, quantity, ... }] }
- Response: { success: true, po_item_id: number, ... }

PUT /api/po_groups/items/{id}
- แก้ไขรายการสินค้า
- Body: { quantity?, unit_price?, discount_percent?, ... }
- Response: { success: true, message: string }

DELETE /api/po_groups/items/{id}
- ลบรายการสินค้า
- Response: { success: true, message: string }

== Reports ==

GET /api/po_groups/{id}/cost-report
- ดึงรายงานต้นทุนรวมค่าขนส่ง
- Response: { 
    success: true, 
    items: [{ 
      material_cost, allocated_freight, total_cost, 
      cost_per_stock_unit, cost_per_purchase_unit, ... 
    }],
    summary: { total_material_cost, total_freight_allocated, grand_total_cost }
  }

== Lookup Data ==

GET /api/po_groups/lookup/suppliers
- ดึงรายการ suppliers
- Response: { success: true, data: [...] }

GET /api/po_groups/lookup/products?search=term
- ค้นหาสินค้าสำหรับ PO
- Response: { success: true, data: [...] }

GET /api/po_groups/lookup/units
- ดึงรายการหน่วยทั้งหมด
- Response: { success: true, data: [...] }

GET /api/po_groups/lookup
- ดึงข้อมูล lookup ทั้งหมด
- Response: { success: true, data: { suppliers: [...], units: [...], ... } }

== Utilities ==

POST /api/po_groups/unit-conversion
- คำนวณการแปลงหน่วย
- Body: { product_id, quantity, from_unit_id, to_unit_id? }
- Response: { 
    success: true, 
    stock_unit_id, conversion_factor, stock_quantity, 
    original_quantity, from_unit_id 
  }

== Error Responses ==

400 Bad Request: { success: false, message: "Error description" }
401 Unauthorized: { success: false, message: "Authentication required" }
404 Not Found: { success: false, message: "Resource not found" }
500 Internal Server Error: { success: false, message: "Internal server error" }

== Usage Examples ==

// JavaScript/fetch examples:

// 1. สร้างใบสั่งซื้อครบชุด
fetch('/production/api/po_groups.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    group_name: 'การสั่งซื้อเดือนกุมภาพันธ์',
    description: 'วัตถุดิบสำหรับโปรเจค XYZ',
    supplier_id: 1
  })
})
.then(response => response.json())
.then(data => {
  if (data.success) {
    // สร้าง complete order
    return fetch(`/production/api/po_groups.php/${data.group_id}/complete-order`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        material_data: {
          supplier_id: 1,
          po_date: '2025-01-30',
          delivery_date: '2025-02-15',
          payment_terms: 'Net 30'
        },
        freight_data: {
          freight_amount: 5000
        },
        items_data: [
          {
            product_id: 1,
            quantity: 100,
            purchase_unit_id: 1,
            unit_price: 120,
            item_description: 'กระดาษ A4 80 แกรม'
          }
        ],
        allocation_method: 'VALUE'
      })
    });
  }
})
.then(response => response.json())
.then(data => console.log('Order created:', data));

// 2. ดึงรายงานต้นทุน
fetch('/production/api/po_groups.php/1/cost-report')
  .then(response => response.json())
  .then(data => {
    console.log('Cost report:', data.items);
    console.log('Summary:', data.summary);
  });

// 3. ค้นหาสินค้า
fetch('/production/api/po_groups.php/lookup/products?search=กระดาษ')
  .then(response => response.json())
  .then(data => console.log('Products:', data.data));

// 4. คำนวณการแปลงหน่วย
fetch('/production/api/po_groups.php/unit-conversion', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    product_id: 1,
    quantity: 100,
    from_unit_id: 1, // รีม
    to_unit_id: 2    // แผ่น
  })
})
.then(response => response.json())
.then(data => console.log('Conversion:', data));

==========================
*/
?>