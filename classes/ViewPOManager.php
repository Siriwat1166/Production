<?php
// production/classes/ViewPOManager.php

class ViewPOManager {
    private $conn;
    
    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
        } catch (Exception $e) {
            error_log("ViewPOManager Constructor Error: " . $e->getMessage());
            throw new Exception("Failed to initialize ViewPOManager: " . $e->getMessage());
        }
    }
    
    /**
     * Get full PO details with all related information
     */
    public function getFullPODetails($poId) {
        try {
            // Get PO Header with supplier and user info
            $sql = "
                SELECT 
                    po.*,
                    s.supplier_name,
                    s.supplier_code,
                    s.contact_person,
                    s.email as supplier_email,
                    s.phone as supplier_phone,
                    s.address as supplier_address,
                    pg.group_name,
                    pg.group_code,
                    pg.description as group_description,
                    u1.full_name as created_by_name,
                    u2.full_name as updated_by_name,
                    u3.full_name as approved_by_name
                FROM PO_Header po
                LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
                LEFT JOIN PO_Groups pg ON po.po_group_id = pg.group_id
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
            
            // Get PO Items
            $po['items'] = $this->getPOItems($poId);
            
            // Get linked POs
            $po['linked_pos'] = $this->getLinkedPOs($poId);
            
            // Get cost allocations (for Freight PO)
            if ($po['po_category'] === 'FREIGHT') {
                $po['allocations'] = $this->getCostAllocations($poId);
            }
            
            // Calculate totals
            $po['total_quantity'] = $this->calculateTotalQuantity($po['items']);
            $po['freight_po_number'] = $this->getFreightPONumber($poId);
            $po['material_po_number'] = $this->getMaterialPONumber($poId);
            
            return [
                'success' => true,
                'data' => $po
            ];
            
        } catch (Exception $e) {
            error_log("ViewPOManager::getFullPODetails Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get PO Items with product details
     */
    private function getPOItems($poId) {
        try {
            $sql = "
                SELECT 
                    pi.*,
                    p.Name as product_name,
                    p.SSP_Code,
                    it.type_name as item_type_name,
                    it.cost_category,
                    pu.unit_name as purchase_unit_name,
                    su.unit_name as stock_unit_name
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
            error_log("ViewPOManager::getPOItems Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get linked POs (Material <-> Freight)
     */
    private function getLinkedPOs($poId) {
        try {
            $linkedPOs = [];
            
            // Get current PO info first
            $currentPOSql = "
                SELECT po_category, material_po_id, freight_po_id, linked_po_id
                FROM PO_Header 
                WHERE po_id = ?
            ";
            
            $stmt = $this->conn->prepare($currentPOSql);
            $stmt->execute([$poId]);
            $currentPO = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$currentPO) return [];
            
            // Build conditions for linked POs
            $conditions = [];
            $params = [];
            
            if ($currentPO['material_po_id']) {
                $conditions[] = "po.po_id = ?";
                $params[] = $currentPO['material_po_id'];
            }
            
            if ($currentPO['freight_po_id']) {
                $conditions[] = "po.po_id = ?";
                $params[] = $currentPO['freight_po_id'];
            }
            
            if ($currentPO['linked_po_id']) {
                $conditions[] = "po.po_id = ?";
                $params[] = $currentPO['linked_po_id'];
            }
            
            // Also find POs that link to this one
            $conditions[] = "po.material_po_id = ?";
            $params[] = $poId;
            
            $conditions[] = "po.freight_po_id = ?";
            $params[] = $poId;
            
            $conditions[] = "po.linked_po_id = ?";
            $params[] = $poId;
            
            if (empty($conditions)) return [];
            
            $sql = "
                SELECT DISTINCT
                    po.po_id,
                    po.po_number,
                    po.po_category,
                    po.total_amount,
                    po.status,
                    s.supplier_name
                FROM PO_Header po
                LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
                WHERE po.po_id != ? AND (" . implode(' OR ', $conditions) . ")
                ORDER BY po.po_date DESC
            ";
            
            // Add the current PO ID as first parameter
            array_unshift($params, $poId);
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("ViewPOManager::getLinkedPOs Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get cost allocations for Freight PO
     */
    private function getCostAllocations($poId) {
        try {
            $sql = "
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
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("ViewPOManager::getCostAllocations Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get Freight PO number for Material PO
     */
    private function getFreightPONumber($poId) {
        try {
            $sql = "
                SELECT po.po_number
                FROM PO_Header po
                WHERE po.material_po_id = ? OR po.freight_po_id = 
                    (SELECT freight_po_id FROM PO_Header WHERE po_id = ?)
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId, $poId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['po_number'] : null;
            
        } catch (Exception $e) {
            error_log("ViewPOManager::getFreightPONumber Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get Material PO number for Freight PO
     */
    private function getMaterialPONumber($poId) {
        try {
            $sql = "
                SELECT po.po_number
                FROM PO_Header po
                WHERE po.po_id = (SELECT material_po_id FROM PO_Header WHERE po_id = ?)
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['po_number'] : null;
            
        } catch (Exception $e) {
            error_log("ViewPOManager::getMaterialPONumber Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Calculate total quantity for Material PO
     */
    private function calculateTotalQuantity($items) {
        $total = 0;
        foreach ($items as $item) {
            $total += floatval($item['stock_quantity'] ?? $item['quantity'] ?? 0);
        }
        return $total;
    }
    
    /**
     * Update PO status
     */
    public function updatePOStatus($poId, $newStatus, $userId) {
        try {
            $this->conn->beginTransaction();
            
            // Get current status for audit
            $sql = "SELECT status FROM PO_Header WHERE po_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            $currentPO = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$currentPO) {
                throw new Exception('PO not found');
            }
            
            $oldStatus = $currentPO['status'];
            
            // Update status
            $sql = "
                UPDATE PO_Header 
                SET status = ?, 
                    updated_by = ?, 
                    updated_date = GETDATE()";
            
            // Add approval info if status is APPROVED
            if ($newStatus === 'APPROVED') {
                $sql .= ", approved_by = ?, approval_date = GETDATE()";
            }
            
            $sql .= " WHERE po_id = ?";
            
            $stmt = $this->conn->prepare($sql);
            
            if ($newStatus === 'APPROVED') {
                $stmt->execute([$newStatus, $userId, $userId, $poId]);
            } else {
                $stmt->execute([$newStatus, $userId, $poId]);
            }
            
            // Log audit trail
            $this->logAudit('PO_Header', $poId, 'STATUS_CHANGE', 
                           $oldStatus, $newStatus, $userId);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'PO status updated successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("ViewPOManager::updatePOStatus Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Add note to PO
     */
    public function addPONote($poId, $note, $userId) {
        try {
            // Get current notes
            $sql = "SELECT notes FROM PO_Header WHERE po_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            $currentPO = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$currentPO) {
                return [
                    'success' => false,
                    'message' => 'PO not found'
                ];
            }
            
            // Append new note with timestamp
            $timestamp = date('Y-m-d H:i:s');
            $newNote = "[$timestamp] $note";
            
            $updatedNotes = $currentPO['notes'] ? 
                $currentPO['notes'] . "\n" . $newNote : 
                $newNote;
            
            // Update notes
            $sql = "
                UPDATE PO_Header 
                SET notes = ?, updated_by = ?, updated_date = GETDATE()
                WHERE po_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$updatedNotes, $userId, $poId]);
            
            // Log audit trail
            $this->logAudit('PO_Header', $poId, 'NOTE_ADDED', 
                           '', $note, $userId);
            
            return [
                'success' => true,
                'message' => 'Note added successfully'
            ];
            
        } catch (Exception $e) {
            error_log("ViewPOManager::addPONote Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get PO summary statistics
     */
    public function getPOSummaryStats($poId) {
        try {
            $sql = "
                SELECT 
                    COUNT(pi.po_item_id) as item_count,
                    SUM(ISNULL(pi.quantity, 0)) as total_quantity,
                    SUM(ISNULL(pi.total_price, 0)) as total_amount,
                    AVG(ISNULL(pi.unit_price, 0)) as avg_unit_price
                FROM PO_Items pi
                WHERE pi.po_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("ViewPOManager::getPOSummaryStats Error: " . $e->getMessage());
            return [
                'item_count' => 0,
                'total_quantity' => 0,
                'total_amount' => 0,
                'avg_unit_price' => 0
            ];
        }
    }
    
    /**
     * Check if PO can be edited
     */
    public function canEditPO($poId, $userId) {
        try {
            $sql = "
                SELECT status, created_by
                FROM PO_Header 
                WHERE po_id = ?
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            
            $po = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$po) {
                return false;
            }
            
            // Can edit if status is DRAFT or PENDING
            // Admin can edit any PO, others can only edit their own
            return in_array($po['status'], ['DRAFT', 'PENDING']) && 
                   ($po['created_by'] == $userId || $_SESSION['role'] === 'admin');
                   
        } catch (Exception $e) {
            error_log("ViewPOManager::canEditPO Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get PO approval workflow
     */
    public function getPOApprovalWorkflow($poId) {
        try {
            $sql = "
                SELECT 
                    al.*,
                    u.full_name as user_name
                FROM Audit_Log al
                LEFT JOIN Users u ON al.changed_by = u.user_id
                WHERE al.table_name = 'PO_Header' 
                AND al.record_id = ?
                AND al.action IN ('CREATE', 'STATUS_CHANGE', 'APPROVE', 'REJECT')
                ORDER BY al.changed_date ASC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$poId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("ViewPOManager::getPOApprovalWorkflow Error: " . $e->getMessage());
            return [];
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
            error_log("ViewPOManager::logAudit Error: " . $e->getMessage());
        }
    }
}
?>