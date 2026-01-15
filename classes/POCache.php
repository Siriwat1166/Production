<?php
// classes/POCache.php - Cache management for PO system
class POCache {
    private static $cache = [];
    private static $cache_timeout = 300; // 5 minutes
    
    /**
     * Get cached suppliers with timeout
     */
    public static function getSuppliers($conn) {
        $cache_key = 'suppliers';
        
        if (self::isCacheValid($cache_key)) {
            return self::$cache[$cache_key]['data'];
        }
        
        try {
            $stmt = $conn->prepare("
                SELECT supplier_id, supplier_code, supplier_name, contact_person, email, phone
                FROM Suppliers 
                WHERE is_active = 1 
                ORDER BY supplier_name
            ");
            $stmt->execute();
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            self::setCache($cache_key, $suppliers);
            return $suppliers;
            
        } catch (PDOException $e) {
            error_log("Error loading suppliers cache: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get cached PO types
     */
    public static function getPOTypes($conn) {
        $cache_key = 'po_types';
        
        if (self::isCacheValid($cache_key)) {
            return self::$cache[$cache_key]['data'];
        }
        
        try {
            $stmt = $conn->prepare("
                SELECT po_type_id, type_code, type_name, type_name_th, description
                FROM PO_Types 
                WHERE is_active = 1 
                ORDER BY type_name
            ");
            $stmt->execute();
            $po_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            self::setCache($cache_key, $po_types);
            return $po_types;
            
        } catch (PDOException $e) {
            error_log("Error loading PO types cache: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get cached units
     */
    public static function getUnits($conn) {
        $cache_key = 'units';
        
        if (self::isCacheValid($cache_key)) {
            return self::$cache[$cache_key]['data'];
        }
        
        try {
            $stmt = $conn->prepare("
                SELECT unit_id, unit_code, unit_name, unit_name_th, unit_symbol, unit_type
                FROM Units 
                WHERE is_active = 1 
                ORDER BY unit_name
            ");
            $stmt->execute();
            $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            self::setCache($cache_key, $units);
            return $units;
            
        } catch (PDOException $e) {
            error_log("Error loading units cache: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get cached products for dropdown
     */
    public static function getProducts($conn, $limit = 1000) {
        $cache_key = "products_limit_{$limit}";
        
        if (self::isCacheValid($cache_key)) {
            return self::$cache[$cache_key]['data'];
        }
        
        try {
            $stmt = $conn->prepare("
                SELECT TOP (?) 
                    p.id, p.SSP_Code, p.Name, p.Name2, 
                    u.unit_name, u.unit_symbol,
                    g.name as group_name, 
                    mt.type_name as material_type_name
                FROM Master_Products_ID p 
                LEFT JOIN Units u ON p.Unit_id = u.unit_id 
                LEFT JOIN Groups g ON p.group_id = g.id
                LEFT JOIN Material_Types mt ON p.material_type_id = mt.material_type_id
                WHERE p.is_active = 1 AND p.status = 1 
                ORDER BY p.SSP_Code, p.Name
            ");
            $stmt->execute([$limit]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            self::setCache($cache_key, $products);
            return $products;
            
        } catch (PDOException $e) {
            error_log("Error loading products cache: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get user information with cache
     */
    public static function getUsers($conn) {
        $cache_key = 'users';
        
        if (self::isCacheValid($cache_key)) {
            return self::$cache[$cache_key]['data'];
        }
        
        try {
            $stmt = $conn->prepare("
                SELECT user_id, username, full_name, role, department, is_active
                FROM Users 
                WHERE is_active = 1 
                ORDER BY full_name
            ");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            self::setCache($cache_key, $users);
            return $users;
            
        } catch (PDOException $e) {
            error_log("Error loading users cache: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get PO statistics with cache
     */
    public static function getPOStats($conn, $filters = []) {
        $cache_key = 'po_stats_' . md5(serialize($filters));
        
        if (self::isCacheValid($cache_key, 60)) { // Cache for 1 minute for stats
            return self::$cache[$cache_key]['data'];
        }
        
        try {
            // Build WHERE clause based on filters
            $where_conditions = [];
            $params = [];
            
            if (!empty($filters['status'])) {
                $where_conditions[] = "ph.status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['type'])) {
                if ($filters['type'] === 'material') {
                    $where_conditions[] = "ph.is_material_po = 1";
                } elseif ($filters['type'] === 'freight') {
                    $where_conditions[] = "ph.is_freight_po = 1";
                }
            }
            
            if (!empty($filters['supplier_id'])) {
                $where_conditions[] = "ph.supplier_id = ?";
                $params[] = $filters['supplier_id'];
            }
            
            if (!empty($filters['date_from'])) {
                $where_conditions[] = "ph.po_date >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $where_conditions[] = "ph.po_date <= ?";
                $params[] = $filters['date_to'];
            }
            
            $where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);
            
            $sql = "
                SELECT 
                    COUNT(*) as total_pos,
                    COUNT(CASE WHEN ph.status = 'Draft' THEN 1 END) as draft_count,
                    COUNT(CASE WHEN ph.status = 'Approved' THEN 1 END) as approved_count,
                    COUNT(CASE WHEN ph.status = 'Completed' THEN 1 END) as completed_count,
                    COUNT(CASE WHEN ph.status = 'Cancelled' THEN 1 END) as cancelled_count,
                    COUNT(CASE WHEN ph.is_material_po = 1 THEN 1 END) as material_count,
                    COUNT(CASE WHEN ph.is_freight_po = 1 THEN 1 END) as freight_count,
                    ISNULL(SUM(ph.total_amount), 0) as total_value,
                    ISNULL(AVG(ph.total_amount), 0) as avg_value,
                    ISNULL(MAX(ph.total_amount), 0) as max_value,
                    ISNULL(MIN(ph.total_amount), 0) as min_value,
                    COUNT(CASE WHEN ph.created_date >= DATEADD(day, -30, GETDATE()) THEN 1 END) as last_30_days,
                    COUNT(CASE WHEN ph.created_date >= DATEADD(day, -7, GETDATE()) THEN 1 END) as last_7_days
                FROM PO_Header ph
                LEFT JOIN Suppliers s ON ph.supplier_id = s.supplier_id
                $where_clause
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            self::setCache($cache_key, $stats, 60);
            return $stats;
            
        } catch (PDOException $e) {
            error_log("Error loading PO stats cache: " . $e->getMessage());
            return [
                'total_pos' => 0, 'draft_count' => 0, 'approved_count' => 0, 'completed_count' => 0,
                'cancelled_count' => 0, 'material_count' => 0, 'freight_count' => 0, 'total_value' => 0,
                'avg_value' => 0, 'max_value' => 0, 'min_value' => 0, 'last_30_days' => 0, 'last_7_days' => 0
            ];
        }
    }
    
    /**
     * Search products with cache
     */
    public static function searchProducts($conn, $search_term, $limit = 50) {
        $cache_key = "product_search_" . md5($search_term . "_" . $limit);
        
        if (self::isCacheValid($cache_key, 180)) { // Cache search for 3 minutes
            return self::$cache[$cache_key]['data'];
        }
        
        try {
            $search_param = "%{$search_term}%";
            
            $stmt = $conn->prepare("
                SELECT TOP (?)
                    p.id, p.SSP_Code, p.Name, p.Name2,
                    u.unit_name, u.unit_symbol,
                    g.name as group_name,
                    mt.type_name as material_type_name,
                    s.supplier_name
                FROM Master_Products_ID p 
                LEFT JOIN Units u ON p.Unit_id = u.unit_id 
                LEFT JOIN Groups g ON p.group_id = g.id
                LEFT JOIN Material_Types mt ON p.material_type_id = mt.material_type_id
                LEFT JOIN Suppliers s ON p.supplier_id = s.supplier_id
                WHERE p.is_active = 1 AND p.status = 1 
                    AND (p.SSP_Code LIKE ? OR p.Name LIKE ? OR p.Name2 LIKE ?)
                ORDER BY 
                    CASE 
                        WHEN p.SSP_Code LIKE ? THEN 1
                        WHEN p.Name LIKE ? THEN 2
                        ELSE 3
                    END,
                    p.SSP_Code, p.Name
            ");
            
            $search_start = "{$search_term}%";
            $stmt->execute([
                $limit, $search_param, $search_param, $search_param,
                $search_start, $search_start
            ]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            self::setCache($cache_key, $products, 180);
            return $products;
            
        } catch (PDOException $e) {
            error_log("Error searching products: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recent PO numbers for auto-suggestion
     */
    public static function getRecentPONumbers($conn, $prefix = '', $limit = 10) {
        $cache_key = "recent_po_numbers_" . md5($prefix . "_" . $limit);
        
        if (self::isCacheValid($cache_key, 300)) { // Cache for 5 minutes
            return self::$cache[$cache_key]['data'];
        }
        
        try {
            $sql = "
                SELECT TOP (?) po_number, po_date, supplier_id, total_amount
                FROM PO_Header 
                WHERE 1=1
            ";
            
            $params = [$limit];
            
            if (!empty($prefix)) {
                $sql .= " AND po_number LIKE ?";
                $params[] = "{$prefix}%";
            }
            
            $sql .= " ORDER BY created_date DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $po_numbers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            self::setCache($cache_key, $po_numbers, 300);
            return $po_numbers;
            
        } catch (PDOException $e) {
            error_log("Error loading recent PO numbers: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Cache management methods
     */
    private static function isCacheValid($key, $custom_timeout = null) {
        if (!isset(self::$cache[$key])) {
            return false;
        }
        
        $timeout = $custom_timeout ?? self::$cache_timeout;
        $age = time() - self::$cache[$key]['timestamp'];
        
        return $age < $timeout;
    }
    
    private static function setCache($key, $data, $custom_timeout = null) {
        self::$cache[$key] = [
            'data' => $data,
            'timestamp' => time(),
            'timeout' => $custom_timeout ?? self::$cache_timeout
        ];
        
        // Cleanup old cache entries (simple memory management)
        self::cleanupCache();
    }
    
    private static function cleanupCache() {
        $current_time = time();
        
        foreach (self::$cache as $key => $cache_item) {
            $age = $current_time - $cache_item['timestamp'];
            if ($age > $cache_item['timeout']) {
                unset(self::$cache[$key]);
            }
        }
        
        // If too many cache entries, remove oldest
        if (count(self::$cache) > 50) {
            $oldest_keys = array_keys(self::$cache);
            $oldest_keys = array_slice($oldest_keys, 0, 10);
            
            foreach ($oldest_keys as $key) {
                unset(self::$cache[$key]);
            }
        }
    }
    
    /**
     * Clear specific cache or all cache
     */
    public static function clearCache($key = null) {
        if ($key === null) {
            self::$cache = [];
            error_log("All PO cache cleared");
        } elseif (isset(self::$cache[$key])) {
            unset(self::$cache[$key]);
            error_log("Cache cleared for key: {$key}");
        }
    }
    
    /**
     * Get cache statistics
     */
    public static function getCacheStats() {
        $total_entries = count(self::$cache);
        $total_size = 0;
        $expired_count = 0;
        $current_time = time();
        
        foreach (self::$cache as $key => $cache_item) {
            $total_size += strlen(serialize($cache_item['data']));
            
            $age = $current_time - $cache_item['timestamp'];
            if ($age > $cache_item['timeout']) {
                $expired_count++;
            }
        }
        
        return [
            'total_entries' => $total_entries,
            'expired_entries' => $expired_count,
            'total_size_bytes' => $total_size,
            'total_size_mb' => round($total_size / 1024 / 1024, 2),
            'cache_hit_ratio' => self::getCacheHitRatio()
        ];
    }
    
    private static $cache_hits = 0;
    private static $cache_misses = 0;
    
    private static function getCacheHitRatio() {
        $total = self::$cache_hits + self::$cache_misses;
        return $total > 0 ? round((self::$cache_hits / $total) * 100, 2) : 0;
    }
    
    /**
     * Preload commonly used cache data
     */
    public static function preloadCache($conn) {
        try {
            // Preload essential data
            self::getSuppliers($conn);
            self::getPOTypes($conn);
            self::getUnits($conn);
            self::getUsers($conn);
            
            // Preload recent stats
            self::getPOStats($conn);
            
            error_log("PO Cache preloaded successfully");
            return true;
            
        } catch (Exception $e) {
            error_log("Error preloading PO cache: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Warm up cache for specific data
     */
    public static function warmupProductCache($conn, $popular_products = []) {
        try {
            // Load popular products
            if (!empty($popular_products)) {
                $placeholders = str_repeat('?,', count($popular_products) - 1) . '?';
                
                $stmt = $conn->prepare("
                    SELECT p.id, p.SSP_Code, p.Name, p.Name2,
                           u.unit_name, u.unit_symbol,
                           g.name as group_name,
                           mt.type_name as material_type_name
                    FROM Master_Products_ID p 
                    LEFT JOIN Units u ON p.Unit_id = u.unit_id 
                    LEFT JOIN Groups g ON p.group_id = g.id
                    LEFT JOIN Material_Types mt ON p.material_type_id = mt.material_type_id
                    WHERE p.id IN ($placeholders) AND p.is_active = 1
                ");
                
                $stmt->execute($popular_products);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Cache popular products
                self::setCache('popular_products', $products, 1800); // 30 minutes
            }
            
            // Preload some common search terms
            $common_searches = ['A', 'B', 'C', 'D', 'E'];
            foreach ($common_searches as $term) {
                self::searchProducts($conn, $term, 20);
            }
            
            error_log("Product cache warmed up successfully");
            return true;
            
        } catch (Exception $e) {
            error_log("Error warming up product cache: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Debug method to view current cache state
     */
    public static function debugCache() {
        if (!DEBUG_MODE) return null;
        
        $debug_info = [];
        
        foreach (self::$cache as $key => $cache_item) {
            $debug_info[$key] = [
                'timestamp' => date('Y-m-d H:i:s', $cache_item['timestamp']),
                'age_seconds' => time() - $cache_item['timestamp'],
                'timeout' => $cache_item['timeout'],
                'data_type' => gettype($cache_item['data']),
                'data_count' => is_array($cache_item['data']) ? count($cache_item['data']) : 'N/A',
                'size_bytes' => strlen(serialize($cache_item['data']))
            ];
        }
        
        return $debug_info;
    }
}