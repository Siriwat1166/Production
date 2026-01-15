<?php
// production/classes/MaterialPOManager.php
// ไม่ต้อง require database.php เพราะมีใน config.php แล้ว

class MaterialPOManager {
    private $conn;
    
    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
        } catch (Exception $e) {
            error_log("MaterialPOManager Constructor Error: " . $e->getMessage());
            throw new Exception("Failed to initialize MaterialPOManager: " . $e->getMessage());
        }
    }
    
    /**
     * Search products by name, code, or description
     */
    public function searchProducts($search) {
        try {
            $sql = "
                SELECT TOP 20
                    p.id,
                    p.SSP_Code,
                    p.Name,
                    p.Name2,
                    p.status,
                    p.is_active,
                    g.name as group_name,
                    mt.type_name as material_type_name,
                    s.supplier_name
                FROM Master_Products_ID p
                LEFT JOIN Groups g ON p.group_id = g.id
                LEFT JOIN Material_Types mt ON p.material_type_id = mt.material_type_id
                LEFT JOIN Suppliers s ON p.supplier_id = s.supplier_id
                WHERE p.is_active = 1 
                AND (
                    p.SSP_Code LIKE ? OR 
                    p.Name LIKE ? OR 
                    p.Name2 LIKE ?
                )
                ORDER BY p.SSP_Code
            ";
            
            $searchTerm = '%' . $search . '%';
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $results,
                'total' => count($results)
            ];
            
        } catch (Exception $e) {
            error_log("MaterialPOManager::searchProducts Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * Get product details by ID
     */
    public function getProductDetails($productId) {
        try {
            $sql = "
                SELECT 
                    p.*,
                    g.name as group_name,
                    mt.type_name as material_type_name,
                    s.supplier_name,
                    s.supplier_code
                FROM Master_Products_ID p
                LEFT JOIN Groups g ON p.group_id = g.id
                LEFT JOIN Material_Types mt ON p.material_type_id = mt.material_type_id
                LEFT JOIN Suppliers s ON p.supplier_id = s.supplier_id
                WHERE p.id = ? AND p.is_active = 1
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$productId]);
            
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                return [
                    'success' => false,
                    'message' => 'Product not found or inactive'
                ];
            }
            
            // Get product units
            $product['units'] = $this->getProductUnits($productId);
            
            // Get latest purchase price if available
            $product['last_purchase_price'] = $this->getLastPurchasePrice($productId);
            
            return [
                'success' => true,
                'data' => $product
            ];
            
        } catch (Exception $e) {
            error_log("MaterialPOManager::getProductDetails Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get product units
     */
    public function getProductUnits($productId) {
        try {
            $sql = "
                SELECT 
                    pu.*,
                    u.unit_name,
                    u.unit_code,
                    u.unit_symbol
                FROM Product_Units pu
                LEFT JOIN Units u ON pu.unit_id = u.unit_id
                WHERE pu.product_id = ? AND pu.is_active = 1
                ORDER BY pu.is_base_unit DESC, pu.is_purchase_unit DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$productId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("MaterialPOManager::getProductUnits Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get last purchase price
     */
    public function getLastPurchasePrice($productId) {
        try {
            $sql = "
                SELECT TOP 1 pi.unit_price
                FROM PO_Items pi
                INNER JOIN PO_Header ph ON pi.po_id = ph.po_id
                WHERE pi.product_id = ? 
                AND ph.status IN ('APPROVED', 'COMPLETED')
                ORDER BY ph.po_date DESC, ph.po_id DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$productId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['unit_price'] : 0;
            
        } catch (Exception $e) {
            error_log("MaterialPOManager::getLastPurchasePrice Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get all units
     */
    public function getUnits() {
        try {
            $sql = "
                SELECT unit_id, unit_code, unit_name, unit_symbol, unit_type
                FROM Units 
                WHERE is_active = 1 
                ORDER BY unit_type, unit_name
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("MaterialPOManager::getUnits Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get PO Groups for supplier
     */
    public function getPOGroups($supplierId = null) {
        try {
            $sql = "
                SELECT group_id, group_code, group_name, description, 
                       total_material_amount, status, created_date
                FROM PO_Groups 
                WHERE status = 'ACTIVE'
            ";
            
            $params = [];
            if ($supplierId) {
                $sql .= " AND supplier_id = ?";
                $params[] = $supplierId;
            }
            
            $sql .= " ORDER BY created_date DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            return [
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (Exception $e) {
            error_log("MaterialPOManager::getPOGroups Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * Create Material PO
     */
    public function createMaterialPO($poData, $userId) {
        try {
            $this->conn->beginTransaction();
            
            // Generate PO Number if not provided
            if (empty($poData['po_number'])) {
                $poData['po_number'] = $this->generatePONumber('MATERIAL');
            }
            
            // Create or get PO Group
            $groupId = null;
            if (!empty($poData['po_group_id'])) {
                $groupId = $poData['po_group_id'];
            } elseif (!empty($poData['group_name'])) {
                $groupId = $this->createPOGroup([
                    'group_name' => $poData['group_name'],
                    'supplier_id' => $poData['supplier_id'],
                    'created_by' => $userId
                ]);
            }
            
            // Insert PO Header
            $sql = "
                INSERT INTO PO_Header (
                    po_number, po_date, supplier_id, po_type_id, 
                    material_amount, total_amount, net_amount, delivery_date, 
                    status, notes, created_by, created_date, 
                    po_group_id, po_category, is_material_po, currency, exchange_rate
                ) VALUES (
                    ?, ?, ?, 1, 
                    ?, ?, ?, ?, 
                    ?, ?, ?, GETDATE(), 
                    ?, 'MATERIAL', 1, 'THB', 1.0
                )
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $poData['po_number'],
                $poData['po_date'],
                $poData['supplier_id'],
                $poData['total_amount'],        // material_amount
                $poData['total_amount'],        // total_amount  
                $poData['total_amount'],        // net_amount (required field)
                $poData['delivery_date'],
                $poData['status'],
                $poData['notes'],
                $userId,
                $groupId
            ]);
            
            // Get PO ID using separate query
            $sql = "SELECT po_id FROM PO_Header WHERE po_number = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poData['po_number']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $poId = $result['po_id'];
            
            if (!$poId) {
                throw new Exception('Failed to get PO ID after insert');
            }
            
            // Insert PO Items
            $lineNumber = 1;
            foreach ($poData['items'] as $item) {
                $this->insertPOItem($poId, $lineNumber, $item);
                $lineNumber++;
            }
            
            // Update PO Group totals if exists
            if ($groupId) {
                $this->updatePOGroupTotals($groupId);
            }
            
            // Log audit trail
            $this->logAudit('PO_Header', $poId, 'CREATE', '', 
                           json_encode($poData), $userId);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'po_id' => $poId,
                'po_number' => $poData['po_number'],
                'message' => 'Material PO created successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("MaterialPOManager::createMaterialPO Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Insert PO Item with proper stock_quantity handling
     */
    private function insertPOItem($poId, $lineNumber, $item) {
        // Calculate stock quantity based on conversion factor
        $stockQuantity = $item['quantity']; // Default to purchase quantity
        $conversionFactor = 1.0; // Default conversion factor
        
        $sql = "
            INSERT INTO PO_Items (
                po_id, line_number, product_id, item_type_id,
                item_description, quantity, purchase_unit_id, stock_unit_id,
                conversion_factor, stock_quantity, unit_price, total_price, 
                discount_percent, discount_amount, delivery_date, status, notes
            ) VALUES (
                ?, ?, ?, 1,
                ?, ?, ?, ?,
                ?, ?, ?, ?, 
                ?, ?, (SELECT delivery_date FROM PO_Header WHERE po_id = ?), 'PENDING', ?
            )
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            $poId,
            $lineNumber,
            $item['product_id'],
            $item['product_name'],
            $item['quantity'],
            $item['purchase_unit_id'],
            $item['purchase_unit_id'], // Use same unit for stock_unit_id for now
            $conversionFactor,
            $stockQuantity,
            $item['unit_price'],
            $item['total_price'],
            $item['discount_percent'],
            $item['discount_amount'],
            $poId,
            $item['notes']
        ]);
    }
    
    /**
     * Create PO Group
     */
    private function createPOGroup($groupData) {
        $groupCode = 'GRP' . date('Ym') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        $sql = "
            INSERT INTO PO_Groups (
                group_code, group_name, supplier_id, 
                status, created_by, created_date
            ) VALUES (
                ?, ?, ?, 
                'ACTIVE', ?, GETDATE()
            )
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            $groupCode,
            $groupData['group_name'],
            $groupData['supplier_id'],
            $groupData['created_by']
        ]);
        
        // Get Group ID
        $sql = "SELECT SCOPE_IDENTITY() as group_id";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['group_id'];
    }
    
    /**
     * Update PO Group totals
     */
    private function updatePOGroupTotals($groupId) {
        $sql = "
            UPDATE PO_Groups 
            SET 
                total_material_amount = (
                    SELECT ISNULL(SUM(material_amount), 0) 
                    FROM PO_Header 
                    WHERE po_group_id = ? AND is_material_po = 1
                ),
                total_freight_amount = (
                    SELECT ISNULL(SUM(freight_amount), 0) 
                    FROM PO_Header 
                    WHERE po_group_id = ? AND is_freight_po = 1
                ),
                grand_total_amount = (
                    SELECT ISNULL(SUM(total_amount), 0) 
                    FROM PO_Header 
                    WHERE po_group_id = ?
                ),
                updated_date = GETDATE()
            WHERE group_id = ?
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$groupId, $groupId, $groupId, $groupId]);
    }
    
    /**
     * Generate PO Number
     */
    private function generatePONumber($type = 'MATERIAL') {
        try {
            $prefix = $type === 'MATERIAL' ? 'MAT' : 'FRT';
            $year = date('Y');
            $month = date('m');
            
            // Get last PO number for this type and period
            $sql = "
                SELECT TOP 1 po_number 
                FROM PO_Header 
                WHERE po_number LIKE ? 
                ORDER BY po_number DESC
            ";
            
            $pattern = $prefix . $year . $month . '%';
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$pattern]);
            
            $lastPO = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastPO) {
                $lastNumber = intval(substr($lastPO['po_number'], -4));
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }
            
            return $prefix . $year . $month . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
            
        } catch (Exception $e) {
            error_log("MaterialPOManager::generatePONumber Error: " . $e->getMessage());
            return $type . date('YmdHis');
        }
    }
    
    /**
     * Log audit trail
     */
    private function logAudit($tableName, $recordId, $action, $oldValues, $newValues, $userId) {
        try {
            $sql = "
                INSERT INTO Audit_Log (
                    table_name, record_id, action, old_values, new_values, 
                    changed_by, changed_date, ip_address, user_agent
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, GETDATE(), ?, ?
                )
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $tableName,
                $recordId,
                $action,
                $oldValues,
                $newValues,
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
        } catch (Exception $e) {
            error_log("MaterialPOManager::logAudit Error: " . $e->getMessage());
        }
    }
    
    /**
     * Check if PO number exists
     */
    public function checkPONumberExists($poNumber, $excludePoId = null) {
        try {
            $sql = "SELECT COUNT(*) as count FROM PO_Header WHERE po_number = ?";
            $params = [$poNumber];
            
            if ($excludePoId) {
                $sql .= " AND po_id != ?";
                $params[] = $excludePoId;
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] > 0;
            
        } catch (Exception $e) {
            error_log("MaterialPOManager::checkPONumberExists Error: " . $e->getMessage());
            return false;
        }
    }
    public function validatePOData($poData) {
        $errors = [];
        
        // Required fields
        if (empty($poData['supplier_id'])) {
            $errors[] = 'Supplier is required';
        }
        
        if (empty($poData['po_date'])) {
            $errors[] = 'PO Date is required';
        }
        
        if (empty($poData['delivery_date'])) {
            $errors[] = 'Delivery Date is required';
        }
        
        if (empty($poData['items']) || count($poData['items']) === 0) {
            $errors[] = 'At least one item is required';
        }
        
        // Validate items
        foreach ($poData['items'] as $index => $item) {
            if (empty($item['product_id'])) {
                $errors[] = "Item " . ($index + 1) . ": Product is required";
            }
            
            if (empty($item['quantity']) || $item['quantity'] <= 0) {
                $errors[] = "Item " . ($index + 1) . ": Quantity must be greater than 0";
            }
            
            if (empty($item['unit_price']) || $item['unit_price'] <= 0) {
                $errors[] = "Item " . ($index + 1) . ": Unit price must be greater than 0";
            }
        }
        
        return [
            'valid' => count($errors) === 0,
            'errors' => $errors
        ];
    }
}
?>