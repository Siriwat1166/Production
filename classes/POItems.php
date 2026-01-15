<?php
// production/classes/POItems.php
require_once __DIR__ . "/../config/database.php";

class POItems {
    private $conn;
    private $table_name = "PO_Items";
    private $product_units_table = "Product_Units";
    private $unit_conversions_table = "Unit_Conversions";
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * เพิ่มรายการสินค้าใน PO
     */
    public function addPOItem($po_id, $item_data) {
        try {
            // ตรวจสอบและแปลงหน่วย
            $conversion_data = $this->calculateUnitConversion(
                $item_data['product_id'],
                $item_data['quantity'],
                $item_data['purchase_unit_id'],
                $item_data['stock_unit_id'] ?? null
            );
            
            if (!$conversion_data['success']) {
                return $conversion_data;
            }
            
            // สร้าง line_number อัตโนมัติ
            $line_number = $this->getNextLineNumber($po_id);
            
            $query = "INSERT INTO {$this->table_name}
                     (po_id, line_number, product_id, item_type_id, item_description,
                      quantity, purchase_unit_id, stock_unit_id, conversion_factor,
                      stock_quantity, unit_price, total_price, discount_percent,
                      discount_amount, delivery_date, status, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute([
                $po_id,
                $line_number,
                $item_data['product_id'],
                $item_data['item_type_id'] ?? 1, // Default เป็น Material
                $item_data['item_description'] ?? '',
                $item_data['quantity'],
                $item_data['purchase_unit_id'],
                $conversion_data['stock_unit_id'],
                $conversion_data['conversion_factor'],
                $conversion_data['stock_quantity'],
                $item_data['unit_price'],
                $this->calculateTotalPrice($item_data),
                $item_data['discount_percent'] ?? 0,
                $item_data['discount_amount'] ?? 0,
                $item_data['delivery_date'] ?? null,
                $item_data['status'] ?? 'PENDING',
                $item_data['notes'] ?? ''
            ]);
            
            if ($result) {
                $po_item_id = $this->conn->lastInsertId();
                
                // อัพเดต PO Header totals
                $this->updatePOTotals($po_id);
                
                return [
                    'success' => true,
                    'po_item_id' => $po_item_id,
                    'line_number' => $line_number,
                    'conversion_data' => $conversion_data,
                    'message' => 'PO Item added successfully'
                ];
            }
            
            return ['success' => false, 'message' => 'Failed to add PO Item'];
            
        } catch (Exception $e) {
            error_log("Add PO Item Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * เพิ่มรายการหลายรายการพร้อมกัน
     */
    public function addMultiplePOItems($po_id, $items_data) {
        try {
            $this->conn->beginTransaction();
            
            $added_items = [];
            foreach ($items_data as $item_data) {
                $result = $this->addPOItem($po_id, $item_data);
                if (!$result['success']) {
                    throw new Exception("Failed to add item: " . $result['message']);
                }
                $added_items[] = $result;
            }
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'added_items' => $added_items,
                'items_count' => count($added_items),
                'message' => 'All PO Items added successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Add Multiple PO Items Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * แก้ไขรายการสินค้า
     */
    public function updatePOItem($po_item_id, $item_data) {
        try {
            // ดึงข้อมูลเดิม
            $current_item = $this->getPOItemById($po_item_id);
            if (!$current_item) {
                return ['success' => false, 'message' => 'PO Item not found'];
            }
            
            // คำนวณการแปลงหน่วยใหม่ (ถ้ามีการเปลี่ยน)
            if (isset($item_data['quantity']) || isset($item_data['purchase_unit_id'])) {
                $conversion_data = $this->calculateUnitConversion(
                    $current_item['product_id'],
                    $item_data['quantity'] ?? $current_item['quantity'],
                    $item_data['purchase_unit_id'] ?? $current_item['purchase_unit_id'],
                    $current_item['stock_unit_id']
                );
                
                if (!$conversion_data['success']) {
                    return $conversion_data;
                }
            }
            
            // สร้าง query แบบ dynamic
            $update_fields = [];
            $params = [];
            
            $allowed_fields = [
                'item_description', 'quantity', 'purchase_unit_id', 'unit_price',
                'discount_percent', 'discount_amount', 'delivery_date', 'status', 'notes'
            ];
            
            foreach ($allowed_fields as $field) {
                if (isset($item_data[$field])) {
                    $update_fields[] = "{$field} = ?";
                    $params[] = $item_data[$field];
                }
            }
            
            // เพิ่มข้อมูล conversion ถ้ามี
            if (isset($conversion_data)) {
                $update_fields[] = "stock_quantity = ?";
                $update_fields[] = "conversion_factor = ?";
                $params[] = $conversion_data['stock_quantity'];
                $params[] = $conversion_data['conversion_factor'];
            }
            
            // คำนวณ total_price ใหม่
            if (isset($item_data['quantity']) || isset($item_data['unit_price']) || 
                isset($item_data['discount_percent']) || isset($item_data['discount_amount'])) {
                
                $new_data = array_merge($current_item, $item_data);
                $update_fields[] = "total_price = ?";
                $params[] = $this->calculateTotalPrice($new_data);
            }
            
            if (empty($update_fields)) {
                return ['success' => false, 'message' => 'No fields to update'];
            }
            
            $params[] = $po_item_id;
            
            $query = "UPDATE {$this->table_name} 
                     SET " . implode(', ', $update_fields) . "
                     WHERE po_item_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute($params);
            
            if ($result) {
                // อัพเดต PO Header totals
                $this->updatePOTotals($current_item['po_id']);
                
                return [
                    'success' => true,
                    'message' => 'PO Item updated successfully',
                    'conversion_data' => $conversion_data ?? null
                ];
            }
            
            return ['success' => false, 'message' => 'Failed to update PO Item'];
            
        } catch (Exception $e) {
            error_log("Update PO Item Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * ลบรายการสินค้า
     */
    public function deletePOItem($po_item_id) {
        try {
            // ดึงข้อมูล PO ID ก่อนลบ
            $item = $this->getPOItemById($po_item_id);
            if (!$item) {
                return ['success' => false, 'message' => 'PO Item not found'];
            }
            
            $query = "DELETE FROM {$this->table_name} WHERE po_item_id = ?";
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute([$po_item_id]);
            
            if ($result) {
                // อัพเดต PO Header totals
                $this->updatePOTotals($item['po_id']);
                
                return [
                    'success' => true,
                    'message' => 'PO Item deleted successfully'
                ];
            }
            
            return ['success' => false, 'message' => 'Failed to delete PO Item'];
            
        } catch (Exception $e) {
            error_log("Delete PO Item Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * ดึงรายการสินค้าในหน่วยที่แตกต่างกัน
     */
    public function getPOItemsWithUnits($po_id, $target_unit_type = 'STOCK') {
        try {
            $query = "SELECT pi.*, 
                     p.Name as product_name, p.SSP_Code,
                     pu_purchase.unit_name as purchase_unit_name,
                     pu_purchase.unit_symbol as purchase_unit_symbol,
                     pu_stock.unit_name as stock_unit_name,
                     pu_stock.unit_symbol as stock_unit_symbol,
                     it.type_name as item_type_name,
                     -- คำนวณราคาต่อหน่วย Stock
                     CASE 
                         WHEN pi.conversion_factor > 0 AND pi.stock_quantity > 0
                         THEN pi.total_price / pi.stock_quantity
                         ELSE pi.unit_price
                     END as stock_unit_price,
                     -- คำนวณต้นทุนรวมค่า Freight (ถ้ามี)
                     pi.total_price + ISNULL(ca.allocation_amount, 0) as total_cost_with_freight,
                     ca.allocation_amount as allocated_freight_cost,
                     ca.allocation_percentage as freight_allocation_percent
                     
                     FROM {$this->table_name} pi
                     LEFT JOIN Master_Products_ID p ON pi.product_id = p.id
                     LEFT JOIN Units pu_purchase ON pi.purchase_unit_id = pu_purchase.unit_id
                     LEFT JOIN Units pu_stock ON pi.stock_unit_id = pu_stock.unit_id
                     LEFT JOIN PO_Item_Types it ON pi.item_type_id = it.item_type_id
                     LEFT JOIN Cost_Allocations ca ON pi.po_item_id = ca.target_item_id
                     WHERE pi.po_id = ?
                     ORDER BY pi.line_number";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$po_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // เพิ่มการแปลงหน่วยแบบ flexible
            foreach ($items as &$item) {
                $item['conversions'] = $this->getAvailableUnitConversions($item['product_id']);
                $item['cost_breakdown'] = [
                    'material_cost' => $item['total_price'],
                    'freight_cost' => $item['allocated_freight_cost'] ?? 0,
                    'total_cost' => $item['total_cost_with_freight']
                ];
            }
            
            return [
                'success' => true,
                'items' => $items,
                'items_count' => count($items)
            ];
            
        } catch (Exception $e) {
            error_log("Get PO Items With Units Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * คำนวณการแปลงหน่วย
     */
    public function calculateUnitConversion($product_id, $quantity, $from_unit_id, $to_unit_id = null) {
        try {
            // ถ้าไม่ระบุ to_unit ให้หาหน่วย stock default
            if (!$to_unit_id) {
                $to_unit_id = $this->getProductStockUnit($product_id);
                if (!$to_unit_id) {
                    return [
                        'success' => false,
                        'message' => 'No stock unit defined for this product'
                    ];
                }
            }
            
            // ถ้าหน่วยเดียวกัน
            if ($from_unit_id == $to_unit_id) {
                return [
                    'success' => true,
                    'stock_unit_id' => $to_unit_id,
                    'conversion_factor' => 1.0,
                    'stock_quantity' => $quantity,
                    'message' => 'Same unit, no conversion needed'
                ];
            }
            
            // หาอัตราการแปลง
            $conversion_factor = $this->getConversionFactor($product_id, $from_unit_id, $to_unit_id);
            
            if ($conversion_factor === false) {
                return [
                    'success' => false,
                    'message' => 'No conversion factor found between these units'
                ];
            }
            
            $stock_quantity = $quantity * $conversion_factor;
            
            return [
                'success' => true,
                'stock_unit_id' => $to_unit_id,
                'conversion_factor' => $conversion_factor,
                'stock_quantity' => $stock_quantity,
                'original_quantity' => $quantity,
                'from_unit_id' => $from_unit_id,
                'message' => 'Conversion calculated successfully'
            ];
            
        } catch (Exception $e) {
            error_log("Calculate Unit Conversion Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * ดึงหน่วยที่สามารถแปลงได้ทั้งหมดของสินค้า
     */
    public function getAvailableUnitConversions($product_id) {
        try {
            $query = "SELECT pu.unit_id, u.unit_name, u.unit_symbol, u.unit_type,
                     pu.conversion_factor, pu.unit_type as product_unit_type,
                     pu.is_base_unit, pu.is_purchase_unit, pu.is_stock_unit
                     FROM {$this->product_units_table} pu
                     INNER JOIN Units u ON pu.unit_id = u.unit_id
                     WHERE pu.product_id = ? AND pu.is_active = 1
                     ORDER BY pu.is_base_unit DESC, u.unit_name";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$product_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Get Available Unit Conversions Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * ดึงรายงานต้นทุนสินค้ารวมค่าขนส่ง
     */
    public function getCostReportWithFreight($po_id) {
        try {
            $query = "SELECT 
                     pi.po_item_id, pi.line_number, pi.item_description,
                     p.Name as product_name, p.SSP_Code,
                     
                     -- ข้อมูลปริมาณและหน่วย
                     pi.quantity as purchase_quantity,
                     pu_purchase.unit_name as purchase_unit,
                     pi.stock_quantity,
                     pu_stock.unit_name as stock_unit,
                     pi.conversion_factor,
                     
                     -- ข้อมูลราคา
                     pi.unit_price as purchase_unit_price,
                     pi.total_price as material_cost,
                     
                     -- ข้อมูลค่าขนส่งที่แบ่ง
                     ca.allocation_amount as allocated_freight,
                     ca.allocation_percentage as freight_percent,
                     ca.allocation_method,
                     ca.base_value,
                     
                     -- คำนวณต้นทุนรวม
                     pi.total_price + ISNULL(ca.allocation_amount, 0) as total_cost,
                     
                     -- คำนวณต้นทุนต่อหน่วย Stock
                     CASE 
                         WHEN pi.stock_quantity > 0 
                         THEN (pi.total_price + ISNULL(ca.allocation_amount, 0)) / pi.stock_quantity
                         ELSE 0
                     END as cost_per_stock_unit,
                     
                     -- คำนวณต้นทุนต่อหน่วย Purchase
                     CASE 
                         WHEN pi.quantity > 0 
                         THEN (pi.total_price + ISNULL(ca.allocation_amount, 0)) / pi.quantity
                         ELSE 0
                     END as cost_per_purchase_unit
                     
                     FROM {$this->table_name} pi
                     LEFT JOIN Master_Products_ID p ON pi.product_id = p.id
                     LEFT JOIN Units pu_purchase ON pi.purchase_unit_id = pu_purchase.unit_id
                     LEFT JOIN Units pu_stock ON pi.stock_unit_id = pu_stock.unit_id
                     LEFT JOIN Cost_Allocations ca ON pi.po_item_id = ca.target_item_id
                     WHERE pi.po_id = ?
                     ORDER BY pi.line_number";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$po_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // คำนวณสรุปรวม
            $summary = [
                'total_items' => count($items),
                'total_material_cost' => 0,
                'total_freight_allocated' => 0,
                'grand_total_cost' => 0
            ];
            
            foreach ($items as $item) {
                $summary['total_material_cost'] += $item['material_cost'];
                $summary['total_freight_allocated'] += $item['allocated_freight'] ?? 0;
                $summary['grand_total_cost'] += $item['total_cost'];
            }
            
            return [
                'success' => true,
                'items' => $items,
                'summary' => $summary
            ];
            
        } catch (Exception $e) {
            error_log("Get Cost Report With Freight Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // ======== Helper Methods ========
    
    private function getPOItemById($po_item_id) {
        $query = "SELECT * FROM {$this->table_name} WHERE po_item_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$po_item_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function getNextLineNumber($po_id) {
        $query = "SELECT ISNULL(MAX(line_number), 0) + 1 as next_line 
                  FROM {$this->table_name} WHERE po_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$po_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['next_line'];
    }
    
    private function calculateTotalPrice($item_data) {
        $quantity = $item_data['quantity'] ?? 0;
        $unit_price = $item_data['unit_price'] ?? 0;
        $discount_percent = $item_data['discount_percent'] ?? 0;
        $discount_amount = $item_data['discount_amount'] ?? 0;
        
        $subtotal = $quantity * $unit_price;
        $discount_from_percent = $subtotal * ($discount_percent / 100);
        $total_discount = $discount_from_percent + $discount_amount;
        
        return $subtotal - $total_discount;
    }
    
    private function getProductStockUnit($product_id) {
        $query = "SELECT unit_id FROM {$this->product_units_table} 
                  WHERE product_id = ? AND is_stock_unit = 1 AND is_active = 1
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$product_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['unit_id'] : null;
    }
    
    private function getConversionFactor($product_id, $from_unit_id, $to_unit_id) {
        // ลองหาการแปลงตรง
        $query = "SELECT conversion_factor FROM {$this->unit_conversions_table}
                  WHERE product_id = ? AND from_unit_id = ? AND to_unit_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$product_id, $from_unit_id, $to_unit_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result['conversion_factor'];
        }
        
        // ลองหาการแปลงย้อนกลับ
        $query = "SELECT 1.0 / conversion_factor as reverse_factor FROM {$this->unit_conversions_table}
                  WHERE product_id = ? AND from_unit_id = ? AND to_unit_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$product_id, $to_unit_id, $from_unit_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result['reverse_factor'];
        }
        
        // ถ้าไม่มีให้ลองหาจาก Product_Units (ใช้หน่วยฐานเป็นตัวกลาง)
        return $this->getConversionViaBaseUnit($product_id, $from_unit_id, $to_unit_id);
    }
    
    private function getConversionViaBaseUnit($product_id, $from_unit_id, $to_unit_id) {
        $query = "SELECT 
                  pu_from.conversion_factor as from_base_factor,
                  pu_to.conversion_factor as to_base_factor
                  FROM {$this->product_units_table} pu_from
                  CROSS JOIN {$this->product_units_table} pu_to
                  WHERE pu_from.product_id = ? AND pu_from.unit_id = ?
                  AND pu_to.product_id = ? AND pu_to.unit_id = ?
                  AND pu_from.is_active = 1 AND pu_to.is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$product_id, $from_unit_id, $product_id, $to_unit_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['to_base_factor'] != 0) {
            return $result['from_base_factor'] / $result['to_base_factor'];
        }
        
        return false;
    }
    
    private function updatePOTotals($po_id) {
        $query = "UPDATE PO_Header 
                  SET material_amount = (
                      SELECT ISNULL(SUM(total_price), 0) 
                      FROM {$this->table_name} pi
                      INNER JOIN PO_Item_Types pit ON pi.item_type_id = pit.item_type_id
                      WHERE pi.po_id = ? AND pit.cost_category = 'MATERIAL'
                  ),
                  service_amount = (
                      SELECT ISNULL(SUM(total_price), 0) 
                      FROM {$this->table_name} pi
                      INNER JOIN PO_Item_Types pit ON pi.item_type_id = pit.item_type_id
                      WHERE pi.po_id = ? AND pit.cost_category = 'SERVICE'
                  ),
                  total_amount = (
                      SELECT ISNULL(SUM(total_price), 0) 
                      FROM {$this->table_name} 
                      WHERE po_id = ?
                  )
                  WHERE po_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$po_id, $po_id, $po_id, $po_id]);
    }
}
?>