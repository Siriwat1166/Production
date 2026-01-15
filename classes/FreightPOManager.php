<?php
// production/classes/FreightPOManager.php
// ไม่ต้อง require database.php เพราะมีใน config.php แล้ว

class FreightPOManager {
    private $conn;
    
    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
        } catch (Exception $e) {
            error_log("FreightPOManager Constructor Error: " . $e->getMessage());
            throw new Exception("Failed to initialize FreightPOManager: " . $e->getMessage());
        }
    }
    
    /**
     * Get available Material POs for linking
     */
    public function getAvailableMaterialPOs($supplierId = null) {
        try {
            $sql = "
                SELECT 
                    po.po_id,
                    po.po_number,
                    po.po_date,
                    po.total_amount,
                    po.status,
                    s.supplier_name,
                    s.supplier_id,
                    COUNT(pi.po_item_id) as item_count,
                    SUM(ISNULL(pi.stock_quantity, pi.quantity)) as total_weight
                FROM PO_Header po
                LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
                LEFT JOIN PO_Items pi ON po.po_id = pi.po_id
                WHERE po.is_material_po = 1 
                AND po.status IN ('APPROVED', 'PENDING', 'DRAFT')
            ";
            
            $params = [];
            if ($supplierId) {
                $sql .= " AND po.supplier_id = ?";
                $params[] = $supplierId;
            }
            
            $sql .= " 
                GROUP BY po.po_id, po.po_number, po.po_date, po.total_amount, po.status, s.supplier_name, s.supplier_id
                ORDER BY po.po_date DESC, po.po_number DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $results,
                'total' => count($results)
            ];
            
        } catch (Exception $e) {
            error_log("FreightPOManager::getAvailableMaterialPOs Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * Search Material PO by PO Number
     */
    public function searchMaterialPOByNumber($poNumber) {
        try {
            $sql = "
                SELECT 
                    po.po_id,
                    po.po_number,
                    po.po_date,
                    po.total_amount,
                    po.status,
                    s.supplier_name,
                    s.supplier_id,
                    COUNT(pi.po_item_id) as item_count,
                    SUM(ISNULL(pi.stock_quantity, pi.quantity)) as total_weight
                FROM PO_Header po
                LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
                LEFT JOIN PO_Items pi ON po.po_id = pi.po_id
                WHERE po.is_material_po = 1 
                AND po.status IN ('APPROVED', 'PENDING', 'DRAFT')
                AND po.po_number LIKE ?
                GROUP BY po.po_id, po.po_number, po.po_date, po.total_amount, po.status, s.supplier_name, s.supplier_id
                ORDER BY po.po_date DESC, po.po_number DESC
            ";
            
            $searchTerm = '%' . $poNumber . '%';
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$searchTerm]);
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $results,
                'total' => count($results)
            ];
            
        } catch (Exception $e) {
            error_log("FreightPOManager::searchMaterialPOByNumber Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * Get Material PO details with items
     */
    public function getMaterialPODetails($materialPoId) {
        try {
            // Get PO Header
            $sql = "
                SELECT 
                    po.*,
                    s.supplier_name,
                    s.supplier_code,
                    s.supplier_id
                FROM PO_Header po
                LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
                WHERE po.po_id = ? AND po.is_material_po = 1
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$materialPoId]);
            
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$po) {
                return [
                    'success' => false,
                    'message' => 'Material PO not found'
                ];
            }
            
            // Debug log
            error_log("Material PO Data: " . json_encode($po));
            
            // Get PO Items
            $itemsSql = "
                SELECT 
                    pi.*,
                    p.Name as product_name,
                    p.SSP_Code as product_code,
                    pu.unit_name as purchase_unit_name,
                    su.unit_name as stock_unit_name,
                    ISNULL(pi.stock_quantity, pi.quantity) as stock_quantity
                FROM PO_Items pi
                LEFT JOIN Master_Products_ID p ON pi.product_id = p.id
                LEFT JOIN Units pu ON pi.purchase_unit_id = pu.unit_id
                LEFT JOIN Units su ON pi.stock_unit_id = su.unit_id
                WHERE pi.po_id = ?
                ORDER BY pi.line_number
            ";
            
            $stmt = $this->conn->prepare($itemsSql);
            $stmt->execute([$materialPoId]);
            
            $po['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $po
            ];
            
        } catch (Exception $e) {
            error_log("FreightPOManager::getMaterialPODetails Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Calculate freight allocation based on method
     */
    public function calculateAllocation($materialPoId, $freightAmount, $allocationMethod = 'WEIGHT') {
        try {
            // Get Material PO items
            $materialPO = $this->getMaterialPODetails($materialPoId);
            
            if (!$materialPO['success']) {
                return $materialPO;
            }
            
            $items = $materialPO['data']['items'];
            $allocatedItems = [];
            
            // Calculate allocation base values
            $totalBase = 0;
            foreach ($items as $item) {
                switch ($allocationMethod) {
                    case 'WEIGHT':
                        $baseValue = floatval($item['stock_quantity'] ?? $item['quantity']);
                        break;
                    case 'VALUE':
                        $baseValue = floatval($item['total_price']);
                        break;
                    case 'QUANTITY':
                        $baseValue = floatval($item['quantity']);
                        break;
                    default:
                        $baseValue = floatval($item['stock_quantity'] ?? $item['quantity']);
                }
                $totalBase += $baseValue;
            }
            
            if ($totalBase == 0) {
                return [
                    'success' => false,
                    'message' => 'Cannot calculate allocation: total base value is zero'
                ];
            }
            
            // Calculate allocation for each item
            $totalAllocated = 0;
            foreach ($items as $index => $item) {
                switch ($allocationMethod) {
                    case 'WEIGHT':
                        $baseValue = floatval($item['stock_quantity'] ?? $item['quantity']);
                        break;
                    case 'VALUE':
                        $baseValue = floatval($item['total_price']);
                        break;
                    case 'QUANTITY':
                        $baseValue = floatval($item['quantity']);
                        break;
                    default:
                        $baseValue = floatval($item['stock_quantity'] ?? $item['quantity']);
                }
                
                $percentage = ($baseValue / $totalBase) * 100;
                $allocatedAmount = ($baseValue / $totalBase) * $freightAmount;
                
                // For the last item, adjust for rounding differences
                if ($index == count($items) - 1) {
                    $allocatedAmount = $freightAmount - $totalAllocated;
                }
                
                $allocatedItems[] = [
                    'po_item_id' => $item['po_item_id'],
                    'product_id' => $item['product_id'],
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'original_cost' => floatval($item['total_price']),
                    'base_weight' => floatval($item['stock_quantity'] ?? $item['quantity']),
                    'base_value' => floatval($item['total_price']),
                    'base_quantity' => floatval($item['quantity']),
                    'allocation_percentage' => $percentage,
                    'allocated_amount' => $allocatedAmount
                ];
                
                $totalAllocated += $allocatedAmount;
            }
            
            $methodNames = [
                'WEIGHT' => 'Weight-based Allocation',
                'VALUE' => 'Value-based Allocation', 
                'QUANTITY' => 'Quantity-based Allocation',
                'MANUAL' => 'Manual Allocation'
            ];
            
            return [
                'success' => true,
                'data' => [
                    'method' => $allocationMethod,
                    'method_name' => $methodNames[$allocationMethod] ?? 'Unknown Method',
                    'total_amount' => $freightAmount,
                    'total_base' => $totalBase,
                    'items' => $allocatedItems
                ]
            ];
            
        } catch (Exception $e) {
            error_log("FreightPOManager::calculateAllocation Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create Freight PO
     */
    /**
     * Create Freight PO
     */
    public function createFreightPO($poData, $userId) {
        try {
            $this->conn->beginTransaction();
            
            // Validate Freight PO data
            $validation = $this->validateFreightPOData($poData);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => 'Validation failed: ' . implode(', ', $validation['errors'])
                ];
            }
            
            // Convert numeric fields
            $supplierId = intval($poData['supplier_id']);
            $totalAmount = floatval($poData['total_amount']);
            $materialPoId = !empty($poData['material_po_id']) ? intval($poData['material_po_id']) : null;
            
            // Validate required fields after conversion
            if ($supplierId <= 0) {
                return [
                    'success' => false,
                    'message' => 'Invalid supplier ID. Please select a Material PO first.'
                ];
            }
            
            if ($totalAmount <= 0) {
                return [
                    'success' => false,
                    'message' => 'Total amount must be greater than 0'
                ];
            }
            
            // Generate PO Number if not provided
            if (empty($poData['po_number'])) {
                $poData['po_number'] = $this->generatePONumber('FREIGHT');
            }
            
            // Get Material PO Group ID if linking
            $groupId = null;
            if ($materialPoId) {
                $groupId = $this->getMaterialPOGroupId($materialPoId);
            }
            
            // Insert Freight PO Header
            $sql = "
                INSERT INTO PO_Header (
                    po_number, po_date, supplier_id, po_type_id,
                    freight_amount, total_amount, net_amount, status, notes,
                    created_by, created_date, po_group_id, po_category,
                    is_freight_po, material_po_id, cost_allocation_method,
                    currency, exchange_rate
                ) VALUES (
                    ?, ?, ?, 2,
                    ?, ?, ?, ?, ?,
                    ?, GETDATE(), ?, 'FREIGHT',
                    1, ?, ?,
                    'THB', 1.0
                )
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $poData['po_number'],
                $poData['po_date'],
                $supplierId,                    // converted to int
                $totalAmount,                   // converted to float
                $totalAmount,                   // converted to float
                $totalAmount,                   // converted to float
                $poData['status'],
                $poData['notes'],
                $userId,
                $groupId,
                $materialPoId,                  // converted to int or null
                $poData['allocation_method']
            ]);
            
            // Get Freight PO ID using PO number
            $sql = "SELECT po_id FROM PO_Header WHERE po_number = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poData['po_number']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $freightPoId = $result['po_id'];
            
            if (!$freightPoId) {
                throw new Exception('Failed to get Freight PO ID after insert');
            }
            
            // Insert Freight Charges as PO Items
            $lineNumber = 1;
            foreach ($poData['freight_charges'] as $charge) {
                $this->insertFreightItem($freightPoId, $lineNumber, $charge);
                $lineNumber++;
            }
            
            // Update Material PO with Freight PO link
            if ($materialPoId) {
                $this->linkMaterialPOToFreight($materialPoId, $freightPoId);
            }
            
            // Create Cost Allocations
            if (!empty($poData['allocation_data']) && $materialPoId) {
                $this->createCostAllocations($freightPoId, $materialPoId, $poData['allocation_data'], $userId);
            }
            
            // Update PO Group totals if exists
            if ($groupId) {
                $this->updatePOGroupTotals($groupId);
            }
            
            // Log audit trail
            $this->logAudit('PO_Header', $freightPoId, 'CREATE', '', 
                           json_encode($poData), $userId);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'po_id' => $freightPoId,
                'po_number' => $poData['po_number'],
                'message' => 'Freight PO created successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("FreightPOManager::createFreightPO Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Insert Freight Item (Charge) with proper data type conversion
     */
    private function insertFreightItem($poId, $lineNumber, $charge) {
        $sql = "
            INSERT INTO PO_Items (
                po_id, line_number, item_type_id, item_description,
                quantity, purchase_unit_id, unit_price, total_price, 
                status, notes, delivery_date
            ) VALUES (
                ?, ?, 2, ?,
                1, 1, ?, ?, 
                'PENDING', ?, (SELECT delivery_date FROM PO_Header WHERE po_id = ?)
            )
        ";
        
        $description = $charge['type'] . ': ' . $charge['description'];
        if (!empty($charge['code'])) {
            $description = $charge['code'] . ' - ' . $description;
        }
        
        // Convert numeric values
        $unitPrice = floatval($charge['amount']);
        $totalPrice = floatval($charge['amount']);
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            intval($poId),
            intval($lineNumber),
            $description,
            $unitPrice,
            $totalPrice,
            $charge['notes'] ?? '',
            intval($poId)
        ]);
    }
    
    /**
     * Link Material PO to Freight PO
     */
    private function linkMaterialPOToFreight($materialPoId, $freightPoId) {
        $sql = "
            UPDATE PO_Header 
            SET freight_po_id = ?, updated_by = ?, updated_date = GETDATE()
            WHERE po_id = ?
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$freightPoId, $_SESSION['user_id'], $materialPoId]);
    }
    
    /**
     * Create Cost Allocations
     */
    private function createCostAllocations($freightPoId, $materialPoId, $allocationData, $userId) {
        foreach ($allocationData['items'] as $item) {
            $sql = "
                INSERT INTO Cost_Allocations (
                    source_po_id, source_item_id, target_po_id, target_item_id,
                    allocation_amount, allocation_percentage, allocation_method,
                    weight_ratio, value_ratio, allocation_date, created_by
                ) VALUES (
                    ?, NULL, ?, ?,
                    ?, ?, ?,
                    ?, ?, GETDATE(), ?
                )
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $freightPoId,
                $materialPoId,
                $item['po_item_id'],
                $item['allocated_amount'],
                $item['allocation_percentage'],
                $allocationData['method'],
                $item['base_weight'],
                $item['base_value'],
                $userId
            ]);
        }
    }
    
    /**
     * Get Material PO Group ID
     */
    private function getMaterialPOGroupId($materialPoId) {
        try {
            $sql = "SELECT po_group_id FROM PO_Header WHERE po_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$materialPoId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['po_group_id'] : null;
            
        } catch (Exception $e) {
            error_log("FreightPOManager::getMaterialPOGroupId Error: " . $e->getMessage());
            return null;
        }
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
    private function generatePONumber($type = 'FREIGHT') {
        try {
            $prefix = 'FRT';
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
            error_log("FreightPOManager::generatePONumber Error: " . $e->getMessage());
            return 'FRT' . date('YmdHis');
        }
    }
    
    /**
     * Get Freight Types
     */
    public function getFreightTypes() {
        return [
            'success' => true,
            'data' => [
                ['code' => 'FREIGHT', 'name' => 'Freight Cost'],
                ['code' => 'STORAGE', 'name' => 'Storage Cost'],
                ['code' => 'CUSTOMS', 'name' => 'Custom Fee'],
                ['code' => 'SERVICE', 'name' => 'Service Fee'],
                ['code' => 'TRANSPORT', 'name' => 'Transportation'],
                ['code' => 'INSURANCE', 'name' => 'Insurance'],
                ['code' => 'HANDLING', 'name' => 'Handling Fee'],
                ['code' => 'OTHER', 'name' => 'Other']
            ]
        ];
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
            error_log("FreightPOManager::logAudit Error: " . $e->getMessage());
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
            error_log("FreightPOManager::checkPONumberExists Error: " . $e->getMessage());
            return false;
        }
    }
    public function validateFreightPOData($poData) {
        $errors = [];
        
        // Required fields
        if (empty($poData['supplier_id'])) {
            $errors[] = 'Freight supplier is required';
        }
        
        if (empty($poData['po_date'])) {
            $errors[] = 'PO Date is required';
        }
        
        if (empty($poData['transport_mode'])) {
            $errors[] = 'Transport mode is required';
        }
        
        if (empty($poData['freight_charges']) || count($poData['freight_charges']) === 0) {
            $errors[] = 'At least one freight charge is required';
        }
        
        // Validate freight charges
        foreach ($poData['freight_charges'] as $index => $charge) {
            if (empty($charge['type'])) {
                $errors[] = "Charge " . ($index + 1) . ": Charge type is required";
            }
            
            if (empty($charge['description'])) {
                $errors[] = "Charge " . ($index + 1) . ": Description is required";
            }
            
            if (empty($charge['amount']) || $charge['amount'] <= 0) {
                $errors[] = "Charge " . ($index + 1) . ": Amount must be greater than 0";
            }
        }
        
        return [
            'valid' => count($errors) === 0,
            'errors' => $errors
        ];
    }
    
    /**
     * Get Freight PO Summary with allocation details
     */
    public function getFreightPOSummary($freightPoId) {
        try {
            // Get Freight PO details
            $sql = "
                SELECT 
                    po.*,
                    s.supplier_name,
                    mat_po.po_number as material_po_number,
                    mat_po.total_amount as material_po_amount
                FROM PO_Header po
                LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
                LEFT JOIN PO_Header mat_po ON po.material_po_id = mat_po.po_id
                WHERE po.po_id = ? AND po.is_freight_po = 1
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$freightPoId]);
            
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$po) {
                return [
                    'success' => false,
                    'message' => 'Freight PO not found'
                ];
            }
            
            // Get freight charges
            $chargesSql = "
                SELECT * FROM PO_Items 
                WHERE po_id = ? 
                ORDER BY line_number
            ";
            
            $stmt = $this->conn->prepare($chargesSql);
            $stmt->execute([$freightPoId]);
            $po['charges'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get cost allocations
            $allocationsSql = "
                SELECT 
                    ca.*,
                    pi.product_id,
                    p.SSP_Code as product_code,
                    p.Name as product_name
                FROM Cost_Allocations ca
                LEFT JOIN PO_Items pi ON ca.target_item_id = pi.po_item_id
                LEFT JOIN Master_Products_ID p ON pi.product_id = p.id
                WHERE ca.source_po_id = ?
                ORDER BY ca.allocation_percentage DESC
            ";
            
            $stmt = $this->conn->prepare($allocationsSql);
            $stmt->execute([$freightPoId]);
            $po['allocations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $po
            ];
            
        } catch (Exception $e) {
            error_log("FreightPOManager::getFreightPOSummary Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update freight charge
     */
    public function updateFreightCharge($itemId, $chargeData, $userId) {
        try {
            $sql = "
                UPDATE PO_Items 
                SET 
                    item_description = ?,
                    unit_price = ?,
                    total_price = ?,
                    notes = ?
                WHERE po_item_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $chargeData['description'],
                $chargeData['amount'],
                $chargeData['amount'],
                $chargeData['notes'],
                $itemId
            ]);
            
            // Log audit trail
            $this->logAudit('PO_Items', $itemId, 'UPDATE', '', 
                           json_encode($chargeData), $userId);
            
            return [
                'success' => true,
                'message' => 'Freight charge updated successfully'
            ];
            
        } catch (Exception $e) {
            error_log("FreightPOManager::updateFreightCharge Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete freight charge
     */
    public function deleteFreightCharge($itemId, $userId) {
        try {
            $this->conn->beginTransaction();
            
            // Get PO ID first
            $sql = "SELECT po_id FROM PO_Items WHERE po_item_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$itemId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                throw new Exception('Freight charge not found');
            }
            
            $poId = $result['po_id'];
            
            // Delete related cost allocations
            $sql = "DELETE FROM Cost_Allocations WHERE source_po_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            
            // Delete the freight charge
            $sql = "DELETE FROM PO_Items WHERE po_item_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$itemId]);
            
            // Update PO total
            $this->updatePOTotal($poId);
            
            // Log audit trail
            $this->logAudit('PO_Items', $itemId, 'DELETE', '', '', $userId);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Freight charge deleted successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("FreightPOManager::deleteFreightCharge Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update PO total amount
     */
    private function updatePOTotal($poId) {
        $sql = "
            UPDATE PO_Header 
            SET 
                total_amount = (SELECT ISNULL(SUM(total_price), 0) FROM PO_Items WHERE po_id = ?),
                freight_amount = (SELECT ISNULL(SUM(total_price), 0) FROM PO_Items WHERE po_id = ?)
            WHERE po_id = ?
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$poId, $poId, $poId]);
    }
    
    /**
     * Get freight statistics
     */
    public function getFreightStatistics($dateFrom = null, $dateTo = null) {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_freight_pos,
                    SUM(total_amount) as total_freight_cost,
                    AVG(total_amount) as avg_freight_cost,
                    COUNT(DISTINCT supplier_id) as unique_suppliers,
                    COUNT(CASE WHEN material_po_id IS NOT NULL THEN 1 END) as linked_pos
                FROM PO_Header 
                WHERE is_freight_po = 1
            ";
            
            $params = [];
            
            if ($dateFrom) {
                $sql .= " AND po_date >= ?";
                $params[] = $dateFrom;
            }
            
            if ($dateTo) {
                $sql .= " AND po_date <= ?";
                $params[] = $dateTo;
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $stats
            ];
            
        } catch (Exception $e) {
            error_log("FreightPOManager::getFreightStatistics Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
?>