<?php
// production/classes/POManager.php
// ไม่ต้อง require database.php เพราะมีใน config.php แล้ว

class POManager {
    private $conn;
    
    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
        } catch (Exception $e) {
            error_log("POManager Constructor Error: " . $e->getMessage());
            throw new Exception("Failed to initialize POManager: " . $e->getMessage());
        }
    }
    
    /**
     * Get PO list with filters
     */
    public function getPOList($filters = []) {
        try {
            $sql = "
                SELECT 
                    po.po_id,
                    po.po_number,
                    po.po_date,
                    po.po_category,
                    po.total_amount,
                    po.status,
                    po.po_group_id,
                    po.freight_po_id,
                    po.material_po_id,
                    po.linked_po_id,
                    po.is_freight_po,
                    po.is_material_po,
                    s.supplier_name,
                    s.supplier_code,
                    pg.group_name,
                    pg.group_code,
                    linked_po.po_number as linked_po_number,
                    freight_po.po_number as freight_po_number,
                    material_po.po_number as material_po_number,
                    po.created_date,
                    po.updated_date,
                    u1.full_name as created_by_name,
                    u2.full_name as updated_by_name
                FROM PO_Header po
                LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
                LEFT JOIN PO_Groups pg ON po.po_group_id = pg.group_id
                LEFT JOIN PO_Header linked_po ON po.linked_po_id = linked_po.po_id
                LEFT JOIN PO_Header freight_po ON po.freight_po_id = freight_po.po_id
                LEFT JOIN PO_Header material_po ON po.material_po_id = material_po.po_id
                LEFT JOIN Users u1 ON po.created_by = u1.user_id
                LEFT JOIN Users u2 ON po.updated_by = u2.user_id
                WHERE 1=1
            ";
            
            $params = [];
            
            // Apply filters
            if (!empty($filters['po_type'])) {
                if ($filters['po_type'] === 'MATERIAL') {
                    $sql .= " AND (po.is_material_po = 1 OR po.po_category = 'MATERIAL')";
                } elseif ($filters['po_type'] === 'FREIGHT') {
                    $sql .= " AND (po.is_freight_po = 1 OR po.po_category = 'FREIGHT')";
                } elseif ($filters['po_type'] === 'COMBINED') {
                    $sql .= " AND po.po_category = 'COMBINED'";
                }
            }
            
            if (!empty($filters['status'])) {
                $sql .= " AND po.status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['supplier_id'])) {
                $sql .= " AND po.supplier_id = ?";
                $params[] = $filters['supplier_id'];
            }
            
            if (!empty($filters['date_from'])) {
                $sql .= " AND po.po_date >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $sql .= " AND po.po_date <= ?";
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['search'])) {
                $sql .= " AND (po.po_number LIKE ? OR s.supplier_name LIKE ? OR ISNULL(pg.group_name, '') LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            // Order by latest first
            $sql .= " ORDER BY po.po_date DESC, po.po_id DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process each PO to add calculated fields
            foreach ($result as &$po) {
                $po['po_type'] = $this->determinePOType($po);
                $po['item_count'] = $this->getPOItemCount($po['po_id']);
                $po['total_quantity'] = $this->getPOTotalQuantity($po['po_id']);
            }
            
            return [
                'success' => true,
                'data' => $result,
                'total' => count($result)
            ];
            
        } catch (Exception $e) {
            error_log("POManager::getPOList Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * Get single PO details with enhanced data for cost analysis
     */
    public function getPODetails($poId) {
        try {
            $sql = "
                SELECT 
                    po.*,
                    s.supplier_name,
                    s.supplier_code,
                    s.contact_person,
                    s.email,
                    s.phone,
                    s.address,
                    pg.group_name,
                    pg.group_code,
                    pg.description as group_description,
                    linked_po.po_number as linked_po_number,
                    freight_po.po_number as freight_po_number,
                    material_po.po_number as material_po_number,
                    u1.full_name as created_by_name,
                    u2.full_name as updated_by_name,
                    u3.full_name as approved_by_name
                FROM PO_Header po
                LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
                LEFT JOIN PO_Groups pg ON po.po_group_id = pg.group_id
                LEFT JOIN PO_Header linked_po ON po.linked_po_id = linked_po.po_id
                LEFT JOIN PO_Header freight_po ON po.freight_po_id = freight_po.po_id
                LEFT JOIN PO_Header material_po ON po.material_po_id = material_po.po_id
                LEFT JOIN Users u1 ON po.created_by = u1.user_id
                LEFT JOIN Users u2 ON po.updated_by = u2.user_id
                LEFT JOIN Users u3 ON po.approved_by = u3.user_id
                WHERE po.po_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$po) {
                return [
                    'success' => false,
                    'message' => 'PO not found'
                ];
            }
            
            // Get PO Items with enhanced details
            $po['items'] = $this->getPOItems($poId);
            
            // Get cost allocations if exists
            $po['allocations'] = $this->getCostAllocations($poId);
            
            // Get freight charges if this is a freight PO
            if ($po['is_freight_po'] || $po['po_category'] === 'FREIGHT') {
                $po['freight_charges'] = $this->getFreightCharges($poId);
            }
            
            return [
                'success' => true,
                'data' => $po
            ];
            
        } catch (Exception $e) {
            error_log("POManager::getPODetails Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get PO Items with enhanced product information
     */
    public function getPOItems($poId) {
        try {
            $sql = "
                SELECT 
                    pi.*,
                    p.Name as product_name,
                    p.SSP_Code as product_code,
                    it.type_name as item_type_name,
                    it.cost_category,
                    it.affects_inventory,
                    pu.unit_name as purchase_unit_name,
                    su.unit_name as stock_unit_name,
                    CASE 
                        WHEN pi.item_description IS NOT NULL AND pi.item_description != '' 
                        THEN pi.item_description 
                        ELSE p.Name 
                    END as display_name
                FROM PO_Items pi
                LEFT JOIN Master_Products_ID p ON pi.product_id = p.id
                LEFT JOIN PO_Item_Types it ON pi.item_type_id = it.item_type_id
                LEFT JOIN Units pu ON pi.purchase_unit_id = pu.unit_id
                LEFT JOIN Units su ON pi.stock_unit_id = su.unit_id
                WHERE pi.po_id = ?
                ORDER BY pi.line_number
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("POManager::getPOItems Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get Freight Charges for Freight PO
     */
    public function getFreightCharges($poId) {
        try {
            // For freight PO, items represent freight charges
            $sql = "
                SELECT 
                    pi.item_description as charge_type,
                    pi.total_price as amount,
                    pi.notes as description,
                    pi.line_number
                FROM PO_Items pi
                WHERE pi.po_id = ?
                ORDER BY pi.line_number
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("POManager::getFreightCharges Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get Cost Allocations
     */
    public function getCostAllocations($poId) {
        try {
            $sql = "
                SELECT 
                    ca.*,
                    spo.po_number as source_po_number,
                    tpo.po_number as target_po_number,
                    u.full_name as created_by_name
                FROM Cost_Allocations ca
                LEFT JOIN PO_Header spo ON ca.source_po_id = spo.po_id
                LEFT JOIN PO_Header tpo ON ca.target_po_id = tpo.po_id
                LEFT JOIN Users u ON ca.created_by = u.user_id
                WHERE ca.source_po_id = ? OR ca.target_po_id = ?
                ORDER BY ca.allocation_date DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId, $poId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("POManager::getCostAllocations Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get suppliers for dropdown
     */
    public function getSuppliers() {
        try {
            $sql = "SELECT supplier_id, supplier_code, supplier_name 
                    FROM Suppliers 
                    WHERE is_active = 1 
                    ORDER BY supplier_name";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("POManager::getSuppliers Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get PO types for dropdown
     */
    public function getPOTypes() {
        try {
            $sql = "SELECT po_type_id, type_code, type_name, type_name_th, cost_category 
                    FROM PO_Types 
                    WHERE is_active = 1 
                    ORDER BY type_name";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("POManager::getPOTypes Error: " . $e->getMessage());
            // Return static data if table query fails
            return [
                ['po_type_id' => 1, 'type_code' => 'MAT', 'type_name' => 'Material', 'cost_category' => 'MATERIAL'],
                ['po_type_id' => 2, 'type_code' => 'FRT', 'type_name' => 'Freight', 'cost_category' => 'FREIGHT'],
                ['po_type_id' => 3, 'type_code' => 'SRV', 'type_name' => 'Service', 'cost_category' => 'SERVICE']
            ];
        }
    }
    
    /**
     * Get linked Freight PO for Material PO
     */
    public function getLinkedFreightPO($materialPoId) {
        try {
            $sql = "
                SELECT po_id 
                FROM PO_Header 
                WHERE material_po_id = ? 
                AND (is_freight_po = 1 OR po_category = 'FREIGHT')
                AND status != 'CANCELLED'
                ORDER BY created_date DESC
                LIMIT 1
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$materialPoId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['po_id'] : null;
            
        } catch (Exception $e) {
            error_log("POManager::getLinkedFreightPO Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Determine PO Type based on flags and category
     */
    private function determinePOType($po) {
        if (!empty($po['po_category'])) {
            return $po['po_category'];
        }
        
        if ($po['is_material_po'] && $po['is_freight_po']) {
            return 'COMBINED';
        } elseif ($po['is_material_po']) {
            return 'MATERIAL';
        } elseif ($po['is_freight_po']) {
            return 'FREIGHT';
        }
        
        return 'UNKNOWN';
    }
    
    /**
     * Get PO item count
     */
    private function getPOItemCount($poId) {
        try {
            $sql = "SELECT COUNT(*) as count FROM PO_Items WHERE po_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] ?? 0;
        } catch (Exception $e) {
            error_log("POManager::getPOItemCount Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get PO total quantity
     */
    private function getPOTotalQuantity($poId) {
        try {
            $sql = "SELECT SUM(ISNULL(stock_quantity, 0)) as total FROM PO_Items WHERE po_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['total'] ?? 0;
        } catch (Exception $e) {
            error_log("POManager::getPOTotalQuantity Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Test database connection
     */
    public function testConnection() {
        try {
            $sql = "SELECT COUNT(*) as count FROM PO_Header";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'message' => 'Database connection successful',
                'po_count' => $result['count']
            ];
            
        } catch (Exception $e) {
            error_log("POManager::testConnection Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Export PO data to Excel format
     */
    public function exportPOData($filters = []) {
        try {
            $poList = $this->getPOList($filters);
            
            if (!$poList['success']) {
                return $poList;
            }
            
            $headers = [
                'PO Number', 'Type', 'Date', 'Supplier', 'Total Amount', 
                'Status', 'Linked PO', 'Group', 'Items', 'Total Qty', 'Created Date'
            ];
            
            $data = [];
            foreach ($poList['data'] as $po) {
                $data[] = [
                    $po['po_number'],
                    $po['po_type'],
                    $po['po_date'],
                    $po['supplier_name'] ?? '',
                    $po['total_amount'],
                    $po['status'],
                    $po['linked_po_number'] ?? '',
                    $po['group_name'] ?? '',
                    $po['item_count'],
                    $po['total_quantity'],
                    $po['created_date']
                ];
            }
            
            return [
                'success' => true,
                'headers' => $headers,
                'data' => $data,
                'filename' => 'PO_List_' . date('Y-m-d_H-i-s') . '.csv'
            ];
            
        } catch (Exception $e) {
            error_log("POManager::exportPOData Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
?>