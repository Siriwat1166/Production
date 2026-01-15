<?php
// classes/Material.php
require_once __DIR__ . '/../config/database.php';

class Material {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function getAllMaterials($page = 1, $search = '', $group_filter = '') {
        $offset = ($page - 1) * RECORDS_PER_PAGE;
        $limit = RECORDS_PER_PAGE;
        
        $where_clause = "WHERE m.is_active = 1";
        $params = [];
        
        if (!empty($search)) {
            $where_clause .= " AND (m.Name LIKE ? OR m.SSP_Code LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        
        if (!empty($group_filter)) {
            $where_clause .= " AND m.group_id = ?";
            $params[] = $group_filter;
        }
        
        $query = "SELECT m.*, g.name as group_name, s.supplier_name 
                  FROM Master_Products_ID m
                  LEFT JOIN Groups g ON m.group_id = g.id
                  LEFT JOIN Suppliers s ON m.supplier_id = s.supplier_id
                  $where_clause
                  ORDER BY m.created_date DESC
                  OFFSET ? ROWS
                  FETCH NEXT ? ROWS ONLY";
        
        $params[] = $offset;
        $params[] = $limit;
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get materials error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getTotalCount($search = '', $group_filter = '') {
        $where_clause = "WHERE m.is_active = 1";
        $params = [];
        
        if (!empty($search)) {
            $where_clause .= " AND (m.Name LIKE ? OR m.SSP_Code LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        
        if (!empty($group_filter)) {
            $where_clause .= " AND m.group_id = ?";
            $params[] = $group_filter;
        }
        
        $query = "SELECT COUNT(*) FROM Master_Products_ID m $where_clause";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("Get total count error: " . $e->getMessage());
            return 0;
        }
    }
    
    public function getMaterialById($id) {
        $query = "SELECT m.*, g.name as group_name, s.supplier_name, s.supplier_code
                  FROM Master_Products_ID m
                  LEFT JOIN Groups g ON m.group_id = g.id
                  LEFT JOIN Suppliers s ON m.supplier_id = s.supplier_id
                  WHERE m.id = ?";
        
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get material by ID error: " . $e->getMessage());
            return null;
        }
    }
    
    public function addMaterial($data) {
        try {
            $this->conn->beginTransaction();
            
            // Generate SSP Code
            $ssp_code = $this->generateSSPCode($data['material_type_id'], $data['group_id'], $data['supplier_id']);
            
            if (!$ssp_code) {
                throw new Exception("Failed to generate SSP Code");
            }
            
            // Get run number from SSP Code
            $run_number = intval(substr($ssp_code, -6));
            
            // Insert into Master_Products_ID
            $query = "INSERT INTO Master_Products_ID 
                      (SSP_Code, Name, Name2, group_id, material_type_id, supplier_id, run_number, Unit_id, created_by, updated_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $ssp_code,
                $data['name'],
                $data['name2'],
                $data['group_id'],
                $data['material_type_id'],
                $data['supplier_id'],
                $run_number,
                1, // Default unit_id
                $data['created_by'],
                $data['updated_by']
            ]);
            
            $product_id = $this->conn->lastInsertId();
            
            // Insert into specific table based on group
            $this->insertSpecificData($product_id, $data);
            
            $this->conn->commit();
            return ['success' => true, 'ssp_code' => $ssp_code, 'product_id' => $product_id];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Add material error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function insertSpecificData($product_id, $data) {
        // Get group name to determine which specific table to use
        $group_query = "SELECT name FROM Groups WHERE id = ?";
        $stmt = $this->conn->prepare($group_query);
        $stmt->execute([$data['group_id']]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$group) return;
        
        $group_name = strtolower($group['name']);
        
        switch ($group_name) {
            case 'paperboard':
                $this->insertPaperboardData($product_id, $data);
                break;
            case 'ink':
                $this->insertInkData($product_id, $data);
                break;
            case 'coating':
                $this->insertCoatingData($product_id, $data);
                break;
            case 'adhesive':
                $this->insertAdhesiveData($product_id, $data);
                break;
            case 'film':
                $this->insertFilmData($product_id, $data);
                break;
            case 'foil':
                $this->insertFoilData($product_id, $data);
                break;
            case 'plate':
                $this->insertPlateData($product_id, $data);
                break;
            case 'corrugated box':
                $this->insertCorrugatedBoxData($product_id, $data);
                break;
        }
    }
    
    private function insertPaperboardData($product_id, $data) {
        $query = "INSERT INTO Specific_Paperboard 
                  (product_id, W_mm, L_mm, gsm, Caliper, brand, type_paperboard_TH, type_paperboard_EN, 
                   laminated1, laminated2, Certificated, created_by, updated_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $product_id,
            $data['w_mm'] ?? null,
            $data['l_mm'] ?? null,
            $data['gsm'] ?? null,
            $data['caliper'] ?? null,
            $data['brand'] ?? null,
            $data['type_paperboard_th'] ?? null,
            $data['type_paperboard_en'] ?? null,
            $data['laminated1'] ?? null,
            $data['laminated2'] ?? null,
            $data['certificated'] ?? null,
            $data['created_by'],
            $data['updated_by']
        ]);
    }
    
    private function insertInkData($product_id, $data) {
        $query = "INSERT INTO Specific_Ink 
                  (product_id, ink_type, Color, Ink_Group, Side, created_by, updated_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $product_id,
            $data['ink_type'] ?? null,
            $data['color'] ?? null,
            $data['ink_group'] ?? null,
            $data['side'] ?? null,
            $data['created_by'],
            $data['updated_by']
        ]);
    }
    
    private function insertCoatingData($product_id, $data) {
        $query = "INSERT INTO Specific_Coating 
                  (product_id, Coating_based, type, effect, Thickness, created_by, updated_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $product_id,
            $data['coating_based'] ?? null,
            $data['coating_type'] ?? null,
            $data['coating_effect'] ?? null,
            $data['thickness'] ?? null,
            $data['created_by'],
            $data['updated_by']
        ]);
    }
    
    private function insertAdhesiveData($product_id, $data) {
        $query = "INSERT INTO Specific_Adhesive 
                  (product_id, Adhesive_type, Apply_on, Application, created_by, updated_by)
                  VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $product_id,
            $data['adhesive_type'] ?? null,
            $data['apply_on'] ?? null,
            $data['application'] ?? null,
            $data['created_by'],
            $data['updated_by']
        ]);
    }
    
    private function insertFilmData($product_id, $data) {
        $query = "INSERT INTO Specific_Film 
                  (product_id, Film_type, Film_code, Film_effect, Thickness, created_by, updated_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $product_id,
            $data['film_type'] ?? null,
            $data['film_code'] ?? null,
            $data['film_effect'] ?? null,
            $data['film_thickness'] ?? null,
            $data['created_by'],
            $data['updated_by']
        ]);
    }
    
    private function insertFoilData($product_id, $data) {
        $query = "INSERT INTO Specific_Foil 
                  (product_id, Foil_Code, Color, W_mm, L_m, m2, Effect, created_by, updated_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $product_id,
            $data['foil_code'] ?? null,
            $data['foil_color'] ?? null,
            $data['foil_w_mm'] ?? null,
            $data['foil_l_m'] ?? null,
            $data['foil_m2'] ?? null,
            $data['foil_effect'] ?? null,
            $data['created_by'],
            $data['updated_by']
        ]);
    }
    
    private function insertPlateData($product_id, $data) {
        $query = "INSERT INTO Specific_Plate 
                  (product_id, Brand_plate, W_mm, Length_mm, Thickness_mm, created_by, updated_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $product_id,
            $data['brand_plate'] ?? null,
            $data['plate_w_mm'] ?? null,
            $data['plate_length_mm'] ?? null,
            $data['plate_thickness_mm'] ?? null,
            $data['created_by'],
            $data['updated_by']
        ]);
    }
    
    private function insertCorrugatedBoxData($product_id, $data) {
        $query = "INSERT INTO Specific_Corrugated_box 
                  (product_id, Case_Number, W_Outer_mm, L_Outer_mm, H_Outer_mm, W_Inner_mm, L_Inner_mm, H_Inner_mm,
                   weight_kg_per_box, type_flute, Layer, Liner, Flute, Liner2, Flute2, Liner3, Logo, created_by, updated_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            $product_id,
            $data['case_number'] ?? null,
            $data['w_outer_mm'] ?? null,
            $data['l_outer_mm'] ?? null,
            $data['h_outer_mm'] ?? null,
            $data['w_inner_mm'] ?? null,
            $data['l_inner_mm'] ?? null,
            $data['h_inner_mm'] ?? null,
            $data['weight_kg_per_box'] ?? null,
            $data['type_flute'] ?? null,
            $data['layer'] ?? null,
            $data['liner'] ?? null,
            $data['flute'] ?? null,
            $data['liner2'] ?? null,
            $data['flute2'] ?? null,
            $data['liner3'] ?? null,
            $data['logo'] ?? null,
            $data['created_by'],
            $data['updated_by']
        ]);
    }
    
    private function generateSSPCode($material_type_id, $group_id, $supplier_id) {
        try {
            // Call SQL Server function to generate SSP Code
            $query = "SELECT dbo.GenerateSSPCode(?, ?, ?) as ssp_code";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$material_type_id, $group_id, $supplier_id]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['ssp_code'] ?? null;
        } catch (Exception $e) {
            error_log("Generate SSP Code error: " . $e->getMessage());
            return null;
        }
    }
    
    public function getGroups() {
        $query = "SELECT * FROM Groups WHERE is_active = 1 ORDER BY name";
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get groups error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getSuppliers() {
        $query = "SELECT * FROM Suppliers WHERE is_active = 1 ORDER BY supplier_name";
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get suppliers error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getMaterialTypes() {
        $query = "SELECT * FROM Material_Types WHERE is_active = 1 ORDER BY type_name";
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get material types error: " . $e->getMessage());
            return [];
        }
    }
}
?>