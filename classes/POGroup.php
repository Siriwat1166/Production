<?php
// production/classes/POGroup.php
require_once __DIR__ . "/../config/database.php";

class POGroup {
    private $conn;
    private $table_name = "PO_Groups";
    private $po_header_table = "PO_Header";
    private $cost_allocation_table = "Cost_Allocations";
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * สร้าง PO Group ใหม่
     */
    public function createPOGroup($data) {
        try {
            // สร้าง Group Code อัตโนมัติ
            $group_code = $this->generateGroupCode();
            
            $query = "INSERT INTO {$this->table_name} 
                     (group_code, group_name, description, supplier_id, 
                      total_material_amount, total_freight_amount, total_service_amount,
                      status, created_by, created_date) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
            
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute([
                $group_code,
                $data['group_name'] ?? '',
                $data['description'] ?? '',
                $data['supplier_id'] ?? null,
                $data['total_material_amount'] ?? 0,
                $data['total_freight_amount'] ?? 0,
                $data['total_service_amount'] ?? 0,
                $data['status'] ?? 'DRAFT',
                $data['created_by']
            ]);
            
            if ($result) {
                $group_id = $this->conn->lastInsertId();
                return [
                    'success' => true,
                    'group_id' => $group_id,
                    'group_code' => $group_code,
                    'message' => 'PO Group created successfully'
                ];
            }
            
            return ['success' => false, 'message' => 'Failed to create PO Group'];
            
        } catch (Exception $e) {
            error_log("Create PO Group Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * สร้าง Material PO และ Freight PO พร้อมกัน
     */
    public function createLinkedPOs($group_id, $material_data, $freight_data = null) {
        try {
            $this->conn->beginTransaction();
            
            // สร้าง Material PO
            $material_po_id = $this->createSinglePO($group_id, 'MATERIAL', $material_data);
            if (!$material_po_id) {
                throw new Exception("Failed to create Material PO");
            }
            
            $freight_po_id = null;
            if ($freight_data && $freight_data['freight_amount'] > 0) {
                // สร้าง Freight PO
                $freight_po_id = $this->createSinglePO($group_id, 'FREIGHT', $freight_data);
                if (!$freight_po_id) {
                    throw new Exception("Failed to create Freight PO");
                }
                
                // Link POs together
                $this->linkPOs($material_po_id, $freight_po_id);
            }
            
            // อัพเดต Group totals
            $this->updateGroupTotals($group_id);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'material_po_id' => $material_po_id,
                'freight_po_id' => $freight_po_id,
                'message' => 'Linked POs created successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Create Linked POs Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * สร้าง PO เดี่ยว
     */
    private function createSinglePO($group_id, $category, $data) {
        try {
            // สร้าง PO Number
            $po_number = $this->generatePONumber($category);
            
            $query = "INSERT INTO {$this->po_header_table} 
                     (po_number, po_date, supplier_id, po_type_id, po_group_id, po_category,
                      material_amount, freight_amount, service_amount, total_amount,
                      tax_amount, net_amount, currency, exchange_rate, delivery_date,
                      payment_terms, status, notes, created_by, created_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
            
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute([
                $po_number,
                $data['po_date'] ?? date('Y-m-d'),
                $data['supplier_id'],
                $data['po_type_id'] ?? 1,
                $group_id,
                $category,
                $data['material_amount'] ?? 0,
                $data['freight_amount'] ?? 0,
                $data['service_amount'] ?? 0,
                $data['total_amount'] ?? 0,
                $data['tax_amount'] ?? 0,
                $data['net_amount'] ?? 0,
                $data['currency'] ?? 'THB',
                $data['exchange_rate'] ?? 1,
                $data['delivery_date'] ?? null,
                $data['payment_terms'] ?? '',
                $data['status'] ?? 'DRAFT',
                $data['notes'] ?? '',
                $data['created_by']
            ]);
            
            if ($result) {
                return $this->conn->lastInsertId();
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Create Single PO Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * เชื่อม PO Material และ Freight เข้าด้วยกัน
     */
    private function linkPOs($material_po_id, $freight_po_id) {
        try {
            // อัพเดต Material PO ให้อ้างอิงไปยัง Freight PO
            $query1 = "UPDATE {$this->po_header_table} 
                      SET linked_po_id = ? 
                      WHERE po_id = ?";
            $stmt1 = $this->conn->prepare($query1);
            $stmt1->execute([$freight_po_id, $material_po_id]);
            
            // อัพเดต Freight PO ให้อ้างอิงไปยัง Material PO
            $query2 = "UPDATE {$this->po_header_table} 
                      SET linked_po_id = ? 
                      WHERE po_id = ?";
            $stmt2 = $this->conn->prepare($query2);
            $stmt2->execute([$material_po_id, $freight_po_id]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Link POs Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * คำนวณและสร้างการแบ่งค่า Freight
     */
    public function calculateFreightAllocation($group_id, $allocation_method = 'WEIGHT') {
        try {
            $this->conn->beginTransaction();
            
            // ดึงข้อมูล Freight รวม
            $freight_total = $this->getGroupFreightTotal($group_id);
            if ($freight_total <= 0) {
                throw new Exception("No freight amount to allocate");
            }
            
            // ดึงรายการ Material Items
            $material_items = $this->getMaterialItems($group_id);
            if (empty($material_items)) {
                throw new Exception("No material items found for allocation");
            }
            
            // คำนวณฐานการแบ่ง
            $total_base = $this->calculateAllocationBase($material_items, $allocation_method);
            if ($total_base <= 0) {
                throw new Exception("Invalid allocation base value");
            }
            
            // ล้างการแบ่งเก่า
            $this->clearExistingAllocations($group_id);
            
            // สร้างการแบ่งใหม่
            $freight_po_id = $this->getFreightPOId($group_id);
            
            foreach ($material_items as $item) {
                $base_value = $this->getItemBaseValue($item, $allocation_method);
                $allocation_percentage = ($base_value / $total_base) * 100;
                $allocation_amount = ($freight_total * $allocation_percentage) / 100;
                
                $this->insertCostAllocation([
                    'po_group_id' => $group_id,
                    'source_po_id' => $freight_po_id,
                    'target_po_id' => $item['po_id'],
                    'target_item_id' => $item['po_item_id'],
                    'allocation_amount' => $allocation_amount,
                    'allocation_percentage' => $allocation_percentage,
                    'allocation_method' => $allocation_method,
                    'freight_allocation_base' => $allocation_method,
                    'base_value' => $base_value,
                    'created_by' => $_SESSION['user_id'] ?? 1
                ]);
            }
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Freight allocation calculated successfully',
                'total_freight' => $freight_total,
                'allocation_method' => $allocation_method,
                'items_count' => count($material_items)
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Calculate Freight Allocation Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * ดึงข้อมูล PO Group พร้อมรายละเอียด
     */
    public function getPOGroupDetails($group_id) {
        try {
            // ข้อมูลหลักของ Group
            $query_group = "SELECT pg.*, s.supplier_name, s.supplier_code,
                           u.full_name as created_by_name
                           FROM {$this->table_name} pg
                           LEFT JOIN Suppliers s ON pg.supplier_id = s.supplier_id  
                           LEFT JOIN Users u ON pg.created_by = u.user_id
                           WHERE pg.group_id = ?";
            
            $stmt = $this->conn->prepare($query_group);
            $stmt->execute([$group_id]);
            $group_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$group_data) {
                return ['success' => false, 'message' => 'PO Group not found'];
            }
            
            // ข้อมูล POs ในกลุ่ม
            $query_pos = "SELECT po_id, po_number, po_category, material_amount, 
                         freight_amount, service_amount, total_amount, status,
                         linked_po_id, delivery_date
                         FROM {$this->po_header_table}
                         WHERE po_group_id = ?
                         ORDER BY po_category, po_number";
            
            $stmt = $this->conn->prepare($query_pos);
            $stmt->execute([$group_id]);
            $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // ข้อมูลการแบ่งค่าใช้จ่าย
            $allocations = $this->getCostAllocations($group_id);
            
            return [
                'success' => true,
                'group_data' => $group_data,
                'pos' => $pos,
                'allocations' => $allocations
            ];
            
        } catch (Exception $e) {
            error_log("Get PO Group Details Error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * ดึงรายการ PO Groups
     */
    public function getAllPOGroups($filters = []) {
        try {
            $where_conditions = [];
            $params = [];
            
            // Filter by status
            if (!empty($filters['status'])) {
                $where_conditions[] = "pg.status = ?";
                $params[] = $filters['status'];
            }
            
            // Filter by supplier
            if (!empty($filters['supplier_id'])) {
                $where_conditions[] = "pg.supplier_id = ?";
                $params[] = $filters['supplier_id'];
            }
            
            // Filter by date range
            if (!empty($filters['date_from'])) {
                $where_conditions[] = "pg.created_date >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $where_conditions[] = "pg.created_date <= ?";
                $params[] = $filters['date_to'] . ' 23:59:59';
            }
            
            $where_clause = '';
            if (!empty($where_conditions)) {
                $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
            }
            
            $query = "SELECT pg.group_id, pg.group_code, pg.group_name, 
                     pg.supplier_id, s.supplier_name,
                     pg.total_material_amount, pg.total_freight_amount, 
                     pg.grand_total_amount, pg.status, pg.created_date,
                     COUNT(ph.po_id) as po_count
                     FROM {$this->table_name} pg
                     LEFT JOIN Suppliers s ON pg.supplier_id = s.supplier_id
                     LEFT JOIN {$this->po_header_table} ph ON pg.group_id = ph.po_group_id
                     {$where_clause}
                     GROUP BY pg.group_id, pg.group_code, pg.group_name, 
                             pg.supplier_id, s.supplier_name,
                             pg.total_material_amount, pg.total_freight_amount,
                             pg.grand_total_amount, pg.status, pg.created_date
                     ORDER BY pg.created_date DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Get All PO Groups Error: " . $e->getMessage());
            return [];
        }
    }
    
    // ======== Helper Methods ========
    
    private function generateGroupCode() {
        $year = date('Y');
        $query = "SELECT COUNT(*) + 1 as next_number 
                  FROM {$this->table_name} 
                  WHERE YEAR(created_date) = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$year]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return "ORD" . $year . sprintf("%04d", $result['next_number']);
    }
    
    private function generatePONumber($category) {
        $prefix = ($category === 'FREIGHT') ? 'FRT' : 'PO';
        $year = date('Y');
        
        $query = "SELECT COUNT(*) + 1 as next_number 
                  FROM {$this->po_header_table} 
                  WHERE po_category = ? AND YEAR(created_date) = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$category, $year]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $prefix . $year . sprintf("%05d", $result['next_number']);
    }
    
    private function updateGroupTotals($group_id) {
        $query = "UPDATE {$this->table_name} 
                  SET total_material_amount = (
                      SELECT ISNULL(SUM(material_amount + service_amount), 0) 
                      FROM {$this->po_header_table} 
                      WHERE po_group_id = ? AND po_category = 'MATERIAL'
                  ),
                  total_freight_amount = (
                      SELECT ISNULL(SUM(freight_amount), 0) 
                      FROM {$this->po_header_table} 
                      WHERE po_group_id = ? AND po_category = 'FREIGHT'
                  ),
                  grand_total_amount = (
                      SELECT ISNULL(SUM(total_amount), 0) 
                      FROM {$this->po_header_table} 
                      WHERE po_group_id = ?
                  )
                  WHERE group_id = ?";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$group_id, $group_id, $group_id, $group_id]);
    }
    
    private function getGroupFreightTotal($group_id) {
        $query = "SELECT ISNULL(SUM(freight_amount), 0) as total
                  FROM {$this->po_header_table}
                  WHERE po_group_id = ? AND po_category = 'FREIGHT'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$group_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'];
    }
    
    private function getMaterialItems($group_id) {
        $query = "SELECT pi.po_item_id, pi.po_id, pi.product_id, pi.quantity, 
                  pi.unit_price, pi.total_price, pi.purchase_unit_id,
                  p.Name as product_name, p.SSP_Code,
                  u.unit_name, u.unit_symbol
                  FROM PO_Items pi
                  INNER JOIN {$this->po_header_table} ph ON pi.po_id = ph.po_id
                  LEFT JOIN Master_Products_ID p ON pi.product_id = p.id
                  LEFT JOIN Units u ON pi.purchase_unit_id = u.unit_id
                  WHERE ph.po_group_id = ? AND ph.po_category = 'MATERIAL'
                  ORDER BY pi.po_item_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$group_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function calculateAllocationBase($items, $method) {
        $total = 0;
        foreach ($items as $item) {
            $total += $this->getItemBaseValue($item, $method);
        }
        return $total;
    }
    
    private function getItemBaseValue($item, $method) {
        switch ($method) {
            case 'VALUE':
                return $item['total_price'];
            case 'QUANTITY':
                return $item['quantity'];
            case 'WEIGHT':
                // ต้องเพิ่มข้อมูลน้ำหนักใน Product หรือใช้ default
                return $item['quantity'] * 1; // 1 kg per unit as default
            default:
                return $item['quantity'];
        }
    }
    
    private function getFreightPOId($group_id) {
        $query = "SELECT po_id FROM {$this->po_header_table} 
                  WHERE po_group_id = ? AND po_category = 'FREIGHT'
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$group_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['po_id'] : null;
    }
    
    private function clearExistingAllocations($group_id) {
        $query = "DELETE FROM {$this->cost_allocation_table} 
                  WHERE po_group_id = ?";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([$group_id]);
    }
    
    private function insertCostAllocation($data) {
        $query = "INSERT INTO {$this->cost_allocation_table}
                  (po_group_id, source_po_id, target_po_id, target_item_id,
                   allocation_amount, allocation_percentage, allocation_method,
                   freight_allocation_base, base_value, allocation_date, created_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            $data['po_group_id'],
            $data['source_po_id'],
            $data['target_po_id'],
            $data['target_item_id'],
            $data['allocation_amount'],
            $data['allocation_percentage'],
            $data['allocation_method'],
            $data['freight_allocation_base'],
            $data['base_value'],
            $data['created_by']
        ]);
    }
    
    private function getCostAllocations($group_id) {
        $query = "SELECT ca.*, 
                  ph_source.po_number as source_po_number,
                  ph_target.po_number as target_po_number,
                  pi.item_description, pi.quantity, pi.unit_price,
                  p.Name as product_name, p.SSP_Code
                  FROM {$this->cost_allocation_table} ca
                  LEFT JOIN {$this->po_header_table} ph_source ON ca.source_po_id = ph_source.po_id
                  LEFT JOIN {$this->po_header_table} ph_target ON ca.target_po_id = ph_target.po_id
                  LEFT JOIN PO_Items pi ON ca.target_item_id = pi.po_item_id
                  LEFT JOIN Master_Products_ID p ON pi.product_id = p.id
                  WHERE ca.po_group_id = ?
                  ORDER BY ca.allocation_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$group_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>