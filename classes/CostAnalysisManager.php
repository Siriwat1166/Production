<?php
// production/classes/CostAnalysisManager.php - Flexible Version

class CostAnalysisManager {
    private $conn;
    
    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
        } catch (Exception $e) {
            error_log("CostAnalysisManager Constructor Error: " . $e->getMessage());
            throw new Exception("Failed to initialize CostAnalysisManager: " . $e->getMessage());
        }
    }
    
    /**
     * Get comprehensive cost analysis
     */
    public function getCostAnalysis($filters = []) {
        try {
            $analysisType = $filters['analysis_type'] ?? 'detailed';
            
            // Debug: Log the filters being used
            error_log("CostAnalysisManager::getCostAnalysis - Filters: " . json_encode($filters));
            
            // Get summary data
            $summary = $this->getSummaryData($filters);
            error_log("CostAnalysisManager::getCostAnalysis - Summary: " . json_encode($summary));
            
            // Get detailed analysis
            $details = $this->getDetailedAnalysis($filters);
            error_log("CostAnalysisManager::getCostAnalysis - Details count: " . count($details));
            
            $result = [
                'summary' => $summary,
                'details' => $details
            ];
            
            // Add specific analysis based on type
            switch ($analysisType) {
                case 'detailed':
                    $result['item_breakdown'] = $this->getItemLevelBreakdown($filters);
                    break;
                    
                case 'trends':
                    $result['trends'] = $this->getTrendAnalysis($filters);
                    break;
                    
                case 'comparison':
                    $result['comparison'] = $this->getCostComparison($filters);
                    break;
            }
            
            return [
                'success' => true,
                'data' => $result
            ];
            
        } catch (Exception $e) {
            error_log("CostAnalysisManager::getCostAnalysis Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get summary statistics - แสดงข้อมูลทั้งหมด ไม่ว่าจะมี group หรือไม่
     */
    private function getSummaryData($filters) {
        $sql = "
            SELECT 
                COUNT(DISTINCT CASE WHEN po.po_group_id IS NOT NULL THEN po.po_group_id ELSE po.po_id END) as total_groups,
                COUNT(DISTINCT CASE WHEN po.is_material_po = 1 THEN po.po_id END) as total_material_pos,
                COUNT(DISTINCT CASE WHEN po.is_freight_po = 1 THEN po.po_id END) as total_freight_pos,
                SUM(CASE WHEN po.is_material_po = 1 THEN ISNULL(po.total_amount, 0) ELSE 0 END) as total_material,
                SUM(CASE WHEN po.is_freight_po = 1 THEN ISNULL(po.total_amount, 0) ELSE 0 END) as total_freight,
                SUM(ISNULL(po.total_amount, 0)) as grand_total
            FROM PO_Header po
            LEFT JOIN PO_Groups pg ON po.po_group_id = pg.group_id
            LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
            WHERE 1=1
        ";
        
        $params = [];
        $sql .= $this->buildWhereClause($filters, $params, 'po');
        
        error_log("getSummaryData SQL: " . $sql);
        error_log("getSummaryData Params: " . json_encode($params));
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("getSummaryData Result: " . json_encode($result));
        
        return $result;
    }
    
    /**
     * Get detailed analysis - รองรับ PO ที่ไม่มี group
     */
    private function getDetailedAnalysis($filters) {
        // แสดง PO ทั้งหมด โดยจัดกลุ่มตาม group_id หรือ individual PO
        $sql = "
            SELECT 
                CASE 
                    WHEN pg.group_id IS NOT NULL THEN pg.group_id 
                    ELSE -po.po_id  -- ใช้ค่าลบเพื่อแยกจาก group_id จริง
                END as virtual_group_id,
                CASE 
                    WHEN pg.group_name IS NOT NULL THEN pg.group_name 
                    ELSE CONCAT('Individual PO: ', po.po_number)
                END as group_name,
                ISNULL(pg.group_code, po.po_number) as group_code,
                ISNULL(s.supplier_name, 'Unknown Supplier') as supplier_name,
                ISNULL(s.supplier_id, 0) as supplier_id,
                po.po_id as material_po_id,
                po.po_number as material_po_number,
                CASE WHEN po.is_material_po = 1 THEN ISNULL(po.total_amount, 0) ELSE 0 END as material_cost,
                frt_po.po_id as freight_po_id,
                frt_po.po_number as freight_po_number,
                CASE WHEN frt_po.is_freight_po = 1 THEN ISNULL(frt_po.total_amount, 0) ELSE 0 END as freight_cost,
                (CASE WHEN po.is_material_po = 1 THEN ISNULL(po.total_amount, 0) ELSE 0 END + 
                 CASE WHEN frt_po.is_freight_po = 1 THEN ISNULL(frt_po.total_amount, 0) ELSE 0 END) as total_cost,
                po.po_date,
                po.status,
                COUNT(pi.po_item_id) as item_count
            FROM PO_Header po
            LEFT JOIN PO_Groups pg ON po.po_group_id = pg.group_id
            LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
            LEFT JOIN PO_Items pi ON po.po_id = pi.po_id
            LEFT JOIN PO_Header frt_po ON (
                (po.po_group_id IS NOT NULL AND pg.group_id = frt_po.po_group_id AND frt_po.is_freight_po = 1)
                OR (po.po_group_id IS NULL AND po.supplier_id = frt_po.supplier_id AND frt_po.is_freight_po = 1 AND frt_po.po_id != po.po_id)
            )
            WHERE po.is_material_po = 1 OR po.is_freight_po = 1
        ";
        
        $params = [];
        $sql .= $this->buildWhereClause($filters, $params, 'po');
        
        $sql .= "
            GROUP BY 
                pg.group_id, pg.group_name, pg.group_code, s.supplier_name, s.supplier_id,
                po.po_id, po.po_number, po.total_amount, po.po_date, po.status, po.is_material_po,
                frt_po.po_id, frt_po.po_number, frt_po.total_amount, frt_po.is_freight_po
            ORDER BY po.po_date DESC
        ";
        
        error_log("getDetailedAnalysis SQL: " . $sql);
        error_log("getDetailedAnalysis Params: " . json_encode($params));
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("getDetailedAnalysis Result count: " . count($result));
        
        return $result;
    }
    
    /**
     * Get item-level cost breakdown
     */
    private function getItemLevelBreakdown($filters) {
        $sql = "
            SELECT 
                CASE 
                    WHEN pg.group_name IS NOT NULL THEN pg.group_name 
                    ELSE CONCAT('Individual PO: ', po.po_number)
                END as group_name,
                pi.product_id,
                ISNULL(p.SSP_Code, 'N/A') as product_code,
                ISNULL(p.Name, 'Unknown Product') as product_name,
                ISNULL(pi.quantity, 0) as quantity,
                ISNULL(pi.unit_price, 0) as unit_price,
                ISNULL(pi.total_price, 0) as material_cost,
                ISNULL(ca.allocation_amount, 0) as allocated_freight,
                (ISNULL(pi.total_price, 0) + ISNULL(ca.allocation_amount, 0)) as total_cost_per_item,
                ISNULL(pu.unit_name, 'Unit') as unit_name
            FROM PO_Items pi
            INNER JOIN PO_Header po ON pi.po_id = po.po_id AND po.is_material_po = 1
            LEFT JOIN PO_Groups pg ON po.po_group_id = pg.group_id
            LEFT JOIN Master_Products_ID p ON pi.product_id = p.id
            LEFT JOIN Units pu ON pi.purchase_unit_id = pu.unit_id
            LEFT JOIN Cost_Allocations ca ON pi.po_item_id = ca.target_item_id
            WHERE 1=1
        ";
        
        $params = [];
        $sql .= $this->buildWhereClause($filters, $params, 'po');
        
        $sql .= " ORDER BY pg.group_name, po.po_number, p.SSP_Code";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by PO Group or Individual PO
        $grouped = [];
        foreach ($items as $item) {
            $groupName = $item['group_name'] ?: 'No Group';
            if (!isset($grouped[$groupName])) {
                $grouped[$groupName] = [
                    'group_name' => $groupName,
                    'items' => []
                ];
            }
            $grouped[$groupName]['items'][] = $item;
        }
        
        return array_values($grouped);
    }
    
    /**
     * Get trend analysis over time
     */
    private function getTrendAnalysis($filters) {
        $sql = "
            SELECT 
                FORMAT(po.po_date, 'yyyy-MM') as period,
                SUM(CASE WHEN po.is_material_po = 1 THEN ISNULL(po.total_amount, 0) ELSE 0 END) as material_cost,
                SUM(CASE WHEN po.is_freight_po = 1 THEN ISNULL(po.total_amount, 0) ELSE 0 END) as freight_cost,
                SUM(ISNULL(po.total_amount, 0)) as total_cost,
                COUNT(DISTINCT CASE WHEN po.is_material_po = 1 THEN po.po_id END) as material_pos,
                COUNT(DISTINCT CASE WHEN po.is_freight_po = 1 THEN po.po_id END) as freight_pos
            FROM PO_Header po
            LEFT JOIN Suppliers s ON po.supplier_id = s.supplier_id
            WHERE po.status IN ('APPROVED', 'COMPLETED')
        ";
        
        $params = [];
        $sql .= $this->buildWhereClause($filters, $params, 'po');
        
        $sql .= "
            GROUP BY FORMAT(po.po_date, 'yyyy-MM')
            ORDER BY period DESC
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get cost comparison analysis
     */
    private function getCostComparison($filters) {
        $sql = "
            SELECT 
                s.supplier_name,
                COUNT(DISTINCT CASE 
                    WHEN po.po_group_id IS NOT NULL THEN po.po_group_id 
                    ELSE po.po_id 
                END) as group_count,
                AVG(CASE 
                    WHEN mat_cost.material_amount > 0 
                    THEN (frt_cost.freight_amount / mat_cost.material_amount) * 100 
                    ELSE 0 
                END) as avg_freight_percentage,
                SUM(ISNULL(mat_cost.material_amount, 0)) as total_material,
                SUM(ISNULL(frt_cost.freight_amount, 0)) as total_freight
            FROM Suppliers s
            INNER JOIN PO_Header po ON s.supplier_id = po.supplier_id
            LEFT JOIN (
                SELECT supplier_id, SUM(total_amount) as material_amount
                FROM PO_Header 
                WHERE is_material_po = 1
                GROUP BY supplier_id
            ) mat_cost ON s.supplier_id = mat_cost.supplier_id
            LEFT JOIN (
                SELECT supplier_id, SUM(total_amount) as freight_amount
                FROM PO_Header 
                WHERE is_freight_po = 1
                GROUP BY supplier_id
            ) frt_cost ON s.supplier_id = frt_cost.supplier_id
            WHERE 1=1
        ";
        
        $params = [];
        $sql .= $this->buildWhereClause($filters, $params, 'po');
        
        $sql .= "
            GROUP BY s.supplier_id, s.supplier_name, mat_cost.material_amount, frt_cost.freight_amount
            HAVING COUNT(DISTINCT CASE 
                WHEN po.po_group_id IS NOT NULL THEN po.po_group_id 
                ELSE po.po_id 
            END) > 0
            ORDER BY avg_freight_percentage DESC
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get PO Groups for filter dropdown - รวม PO ที่ไม่มี group
     */
    public function getPOGroups() {
        try {
            $sql = "
                SELECT DISTINCT 
                    pg.group_id, 
                    pg.group_name, 
                    pg.group_code,
                    COUNT(po.po_id) as po_count
                FROM PO_Groups pg
                LEFT JOIN PO_Header po ON pg.group_id = po.po_group_id
                WHERE pg.group_id IS NOT NULL
                GROUP BY pg.group_id, pg.group_name, pg.group_code
                
                UNION ALL
                
                SELECT 
                    0 as group_id,
                    'POs without Groups' as group_name,
                    'NO_GROUP' as group_code,
                    COUNT(*) as po_count
                FROM PO_Header
                WHERE po_group_id IS NULL
                HAVING COUNT(*) > 0
                
                ORDER BY group_name
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("getPOGroups Result: " . json_encode($result));
            
            return $result;
            
        } catch (Exception $e) {
            error_log("CostAnalysisManager::getPOGroups Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Export cost analysis to CSV
     */
    public function exportCostAnalysis($filters) {
        try {
            $analysis = $this->getDetailedAnalysis($filters);
            
            if (empty($analysis)) {
                return [
                    'success' => false,
                    'message' => 'No data to export'
                ];
            }
            
            $headers = [
                'Group/PO',
                'Supplier',
                'Material PO',
                'Material Cost (THB)',
                'Freight PO',
                'Freight Cost (THB)',
                'Total Cost (THB)',
                'Freight Percentage (%)',
                'Item Count',
                'PO Date',
                'Status'
            ];
            
            $csvData = implode(',', $headers) . "\n";
            
            foreach ($analysis as $row) {
                $freightPercentage = $row['material_cost'] > 0 ? 
                    round(($row['freight_cost'] / $row['material_cost']) * 100, 2) : 0;
                    
                $csvRow = [
                    '"' . ($row['group_name'] ?: 'No Group') . '"',
                    '"' . $row['supplier_name'] . '"',
                    '"' . $row['material_po_number'] . '"',
                    number_format($row['material_cost'], 2),
                    '"' . ($row['freight_po_number'] ?: 'No Freight PO') . '"',
                    number_format($row['freight_cost'], 2),
                    number_format($row['total_cost'], 2),
                    $freightPercentage,
                    $row['item_count'],
                    '"' . $row['po_date'] . '"',
                    '"' . $row['status'] . '"'
                ];
                
                $csvData .= implode(',', $csvRow) . "\n";
            }
            
            return [
                'success' => true,
                'csv_data' => $csvData,
                'filename' => 'Cost_Analysis_' . date('Y-m-d_H-i-s') . '.csv'
            ];
            
        } catch (Exception $e) {
            error_log("CostAnalysisManager::exportCostAnalysis Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Build WHERE clause for filters
     */
    private function buildWhereClause($filters, &$params, $tableAlias = '') {
        $where = '';
        $prefix = $tableAlias ? $tableAlias . '.' : '';
        
        if (!empty($filters['date_from'])) {
            $where .= " AND {$prefix}po_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where .= " AND {$prefix}po_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['supplier_id'])) {
            if ($tableAlias === 'mat_po') {
                $where .= " AND mat_po.supplier_id = ?";
            } elseif ($tableAlias === 'po') {
                $where .= " AND po.supplier_id = ?";
            } else {
                $where .= " AND s.supplier_id = ?";
            }
            $params[] = $filters['supplier_id'];
        }
        
        if (!empty($filters['po_group_id'])) {
            if ($filters['po_group_id'] == '0') {
                // Special case: POs without groups
                if ($tableAlias === 'mat_po') {
                    $where .= " AND mat_po.po_group_id IS NULL";
                } elseif ($tableAlias === 'po') {
                    $where .= " AND po.po_group_id IS NULL";
                } else {
                    $where .= " AND pg.group_id IS NULL";
                }
            } else {
                // Normal group filtering
                if ($tableAlias === 'mat_po') {
                    $where .= " AND mat_po.po_group_id = ?";
                } elseif ($tableAlias === 'po') {
                    $where .= " AND po.po_group_id = ?";
                } else {
                    $where .= " AND pg.group_id = ?";
                }
                $params[] = $filters['po_group_id'];
            }
        }
        
        return $where;
    }
    
    /**
     * Debug method to check data availability
     */
    public function debugDataAvailability() {
        try {
            // Check PO_Header
            $sql = "SELECT 
                COUNT(*) as total_pos,
                COUNT(CASE WHEN is_material_po = 1 THEN 1 END) as material_pos,
                COUNT(CASE WHEN is_freight_po = 1 THEN 1 END) as freight_pos,
                COUNT(CASE WHEN po_group_id IS NOT NULL THEN 1 END) as pos_with_groups,
                COUNT(CASE WHEN po_group_id IS NULL THEN 1 END) as pos_without_groups
                FROM PO_Header";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $poStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check PO_Groups
            $sql = "SELECT COUNT(*) as total_groups FROM PO_Groups";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $groupStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check Suppliers
            $sql = "SELECT COUNT(*) as total_suppliers FROM Suppliers";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $supplierStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'po_stats' => $poStats,
                'group_stats' => $groupStats,
                'supplier_stats' => $supplierStats
            ];
            
        } catch (Exception $e) {
            error_log("CostAnalysisManager::debugDataAvailability Error: " . $e->getMessage());
            return [];
        }
    }
    
    // เพิ่ม methods อื่นๆ ที่จำเป็น (getFreightEfficiencyMetrics, getCostSavingsOpportunities)
    public function getFreightEfficiencyMetrics($filters = []) {
        try {
            $sql = "
                SELECT 
                    AVG(CASE 
                        WHEN ISNULL(mat_po.total_amount, 0) > 0 
                        THEN (ISNULL(frt_po.total_amount, 0) / mat_po.total_amount) * 100 
                        ELSE 0 
                    END) as avg_freight_percentage,
                    MIN(CASE 
                        WHEN ISNULL(mat_po.total_amount, 0) > 0 
                        THEN (ISNULL(frt_po.total_amount, 0) / mat_po.total_amount) * 100 
                        ELSE 0 
                    END) as min_freight_percentage,
                    MAX(CASE 
                        WHEN ISNULL(mat_po.total_amount, 0) > 0 
                        THEN (ISNULL(frt_po.total_amount, 0) / mat_po.total_amount) * 100 
                        ELSE 0 
                    END) as max_freight_percentage,
                    COUNT(*) as total_po_pairs
                FROM PO_Header mat_po
                LEFT JOIN PO_Header frt_po ON (
                    (mat_po.po_group_id IS NOT NULL AND mat_po.po_group_id = frt_po.po_group_id)
                    OR (mat_po.po_group_id IS NULL AND mat_po.supplier_id = frt_po.supplier_id)
                )
                WHERE mat_po.is_material_po = 1 
                AND frt_po.is_freight_po = 1
                AND ISNULL(mat_po.total_amount, 0) > 0
            ";
            
            $params = [];
            $sql .= $this->buildWhereClause($filters, $params, 'mat_po');
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("CostAnalysisManager::getFreightEfficiencyMetrics Error: " . $e->getMessage());
            return [
                'avg_freight_percentage' => 0,
                'min_freight_percentage' => 0,
                'max_freight_percentage' => 0,
                'total_po_pairs' => 0
            ];
        }
    }
    
    public function getCostSavingsOpportunities($filters = []) {
        try {
            $sql = "
                SELECT 
                    CASE 
                        WHEN pg.group_name IS NOT NULL THEN pg.group_name 
                        ELSE CONCAT('Individual PO: ', mat_po.po_number)
                    END as group_name,
                    s.supplier_name,
                    mat_po.po_number as material_po,
                    ISNULL(mat_po.total_amount, 0) as material_cost,
                    ISNULL(frt_po.total_amount, 0) as freight_cost,
                    ROUND((ISNULL(frt_po.total_amount, 0) / NULLIF(mat_po.total_amount, 0)) * 100, 2) as freight_percentage,
                    'High Freight Cost' as opportunity_type,
                    'Consider negotiating freight rates or finding alternative shipping methods' as recommendation
                FROM PO_Header mat_po
                LEFT JOIN PO_Groups pg ON mat_po.po_group_id = pg.group_id
                LEFT JOIN PO_Header frt_po ON (
                    (mat_po.po_group_id IS NOT NULL AND pg.group_id = frt_po.po_group_id AND frt_po.is_freight_po = 1)
                    OR (mat_po.po_group_id IS NULL AND mat_po.supplier_id = frt_po.supplier_id AND frt_po.is_freight_po = 1)
                )
                INNER JOIN Suppliers s ON mat_po.supplier_id = s.supplier_id
                WHERE mat_po.is_material_po = 1
                AND ISNULL(mat_po.total_amount, 0) > 0 
                AND (ISNULL(frt_po.total_amount, 0) / NULLIF(mat_po.total_amount, 0)) > 0.25
            ";
            
            $params = [];
            $sql .= $this->buildWhereClause($filters, $params, 'mat_po');
            $sql .= " ORDER BY freight_percentage DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("CostAnalysisManager::getCostSavingsOpportunities Error: " . $e->getMessage());
            return [];
        }
    }
}
?>