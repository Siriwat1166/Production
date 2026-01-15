<?php
// classes/AuditLogger.php - Comprehensive audit logging system
class AuditLogger {
    private $conn;
    private $user_id;
    private $ip_address;
    private $user_agent;
    
    public function __construct($database_connection, $user_id = null) {
        $this->conn = $database_connection;
        $this->user_id = $user_id ?? $_SESSION['user_id'] ?? null;
        $this->ip_address = $this->getRealIpAddress();
        $this->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
    
    /**
     * Log PO creation
     */
    public function logPOCreation($po_id, $po_data) {
        return $this->log([
            'table_name' => 'PO_Header',
            'record_id' => $po_id,
            'action' => 'CREATE',
            'old_values' => null,
            'new_values' => json_encode($po_data, JSON_UNESCAPED_UNICODE),
            'description' => "สร้าง PO ใหม่: {$po_data['po_number']}"
        ]);
    }
    
    /**
     * Log PO updates
     */
    public function logPOUpdate($po_id, $old_data, $new_data, $changed_fields = []) {
        $changes = $this->generateChangeDescription($old_data, $new_data, $changed_fields);
        
        return $this->log([
            'table_name' => 'PO_Header',
            'record_id' => $po_id,
            'action' => 'UPDATE',
            'old_values' => json_encode($old_data, JSON_UNESCAPED_UNICODE),
            'new_values' => json_encode($new_data, JSON_UNESCAPED_UNICODE),
            'description' => "แก้ไข PO: {$new_data['po_number']} - {$changes}"
        ]);
    }
    
    /**
     * Log PO deletion
     */
    public function logPODeletion($po_id, $po_data) {
        return $this->log([
            'table_name' => 'PO_Header',
            'record_id' => $po_id,
            'action' => 'DELETE',
            'old_values' => json_encode($po_data, JSON_UNESCAPED_UNICODE),
            'new_values' => null,
            'description' => "ลบ PO: {$po_data['po_number']}"
        ]);
    }
    
    /**
     * Log PO status changes
     */
    public function logPOStatusChange($po_id, $po_number, $old_status, $new_status, $reason = '') {
        $description = "เปลี่ยนสถานะ PO: {$po_number} จาก '{$old_status}' เป็น '{$new_status}'";
        if (!empty($reason)) {
            $description .= " - เหตุผล: {$reason}";
        }
        
        return $this->log([
            'table_name' => 'PO_Header',
            'record_id' => $po_id,
            'action' => 'STATUS_CHANGE',
            'old_values' => json_encode(['status' => $old_status], JSON_UNESCAPED_UNICODE),
            'new_values' => json_encode(['status' => $new_status], JSON_UNESCAPED_UNICODE),
            'description' => $description
        ]);
    }
    
    /**
     * Log PO approval
     */
    public function logPOApproval($po_id, $po_number, $approved_by, $approval_notes = '') {
        return $this->log([
            'table_name' => 'PO_Header',
            'record_id' => $po_id,
            'action' => 'APPROVE',
            'old_values' => null,
            'new_values' => json_encode([
                'approved_by' => $approved_by,
                'approval_date' => date('Y-m-d H:i:s'),
                'notes' => $approval_notes
            ], JSON_UNESCAPED_UNICODE),
            'description' => "อนุมัติ PO: {$po_number}" . (!empty($approval_notes) ? " - หมายเหตุ: {$approval_notes}" : '')
        ]);
    }
    
    /**
     * Log PO item changes
     */
    public function logPOItemChange($po_item_id, $po_id, $action, $old_data = null, $new_data = null) {
        $descriptions = [
            'CREATE' => 'เพิ่มรายการสินค้า',
            'UPDATE' => 'แก้ไขรายการสินค้า',
            'DELETE' => 'ลบรายการสินค้า'
        ];
        
        return $this->log([
            'table_name' => 'PO_Items',
            'record_id' => $po_item_id,
            'action' => $action,
            'old_values' => $old_data ? json_encode($old_data, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => $new_data ? json_encode($new_data, JSON_UNESCAPED_UNICODE) : null,
            'description' => $descriptions[$action] ?? $action,
            'related_record_type' => 'PO_Header',
            'related_record_id' => $po_id
        ]);
    }
    
    /**
     * Log user login/logout
     */
    public function logUserActivity($action, $username, $additional_data = []) {
        $descriptions = [
            'LOGIN' => 'เข้าสู่ระบบ',
            'LOGOUT' => 'ออกจากระบบ',
            'LOGIN_FAILED' => 'เข้าสู่ระบบไม่สำเร็จ',
            'PASSWORD_CHANGE' => 'เปลี่ยนรหัสผ่าน',
            'PROFILE_UPDATE' => 'แก้ไขโปรไฟล์'
        ];
        
        return $this->log([
            'table_name' => 'Users',
            'record_id' => $this->user_id,
            'action' => $action,
            'old_values' => null,
            'new_values' => json_encode(array_merge(['username' => $username], $additional_data), JSON_UNESCAPED_UNICODE),
            'description' => ($descriptions[$action] ?? $action) . " - {$username}"
        ]);
    }
    
    /**
     * Log system access and security events
     */
    public function logSecurityEvent($event_type, $description, $severity = 'INFO') {
        return $this->log([
            'table_name' => 'System',
            'record_id' => null,
            'action' => $event_type,
            'old_values' => null,
            'new_values' => json_encode([
                'severity' => $severity,
                'event_type' => $event_type,
                'user_agent' => $this->user_agent,
                'ip_address' => $this->ip_address
            ], JSON_UNESCAPED_UNICODE),
            'description' => $description
        ]);
    }
    
    /**
     * Log export activities
     */
    public function logExport($export_type, $filters = [], $record_count = 0) {
        return $this->log([
            'table_name' => 'System',
            'record_id' => null,
            'action' => 'EXPORT',
            'old_values' => null,
            'new_values' => json_encode([
                'export_type' => $export_type,
                'filters' => $filters,
                'record_count' => $record_count,
                'export_time' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE),
            'description' => "Export {$export_type} - {$record_count} รายการ"
        ]);
    }
    
    /**
     * Main logging method
     */
    private function log($data) {
        try {
            $sql = "
                INSERT INTO Audit_Log 
                (table_name, record_id, action, old_values, new_values, 
                 changed_by, changed_date, ip_address, user_agent, description,
                 related_record_type, related_record_id) 
                VALUES (?, ?, ?, ?, ?, ?, GETDATE(), ?, ?, ?, ?, ?)
            ";
            
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                $data['table_name'],
                $data['record_id'],
                $data['action'],
                $data['old_values'],
                $data['new_values'],
                $this->user_id,
                $this->ip_address,
                $this->user_agent,
                $data['description'] ?? '',
                $data['related_record_type'] ?? null,
                $data['related_record_id'] ?? null
            ]);
            
            if ($result) {
                $log_id = $this->conn->lastInsertId();
                error_log("Audit log created: ID {$log_id} - {$data['action']} on {$data['table_name']}");
                return $log_id;
            }
            
            return false;
            
        } catch (PDOException $e) {
            error_log("Audit logging failed: " . $e->getMessage());
            error_log("Audit data: " . json_encode($data));
            return false;
        }
    }
    
    /**
     * Get audit logs for a specific record
     */
    public function getAuditLogs($table_name, $record_id, $limit = 50) {
        try {
            $sql = "
                SELECT TOP (?) 
                    al.log_id, al.action, al.old_values, al.new_values,
                    al.changed_date, al.ip_address, al.description,
                    u.full_name as changed_by_name, u.username
                FROM Audit_Log al
                LEFT JOIN Users u ON al.changed_by = u.user_id
                WHERE al.table_name = ? AND al.record_id = ?
                ORDER BY al.changed_date DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$limit, $table_name, $record_id]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process logs to make them more readable
            foreach ($logs as &$log) {
                $log['changes'] = $this->formatChanges($log['old_values'], $log['new_values']);
                $log['formatted_date'] = date('d/m/Y H:i:s', strtotime($log['changed_date']));
                $log['time_ago'] = $this->timeAgo($log['changed_date']);
            }
            
            return $logs;
            
        } catch (PDOException $e) {
            error_log("Error retrieving audit logs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get audit logs summary for dashboard
     */
    public function getAuditSummary($days = 30) {
        try {
            $sql = "
                SELECT 
                    action,
                    table_name,
                    COUNT(*) as count,
                    COUNT(DISTINCT changed_by) as unique_users,
                    MAX(changed_date) as last_occurrence
                FROM Audit_Log 
                WHERE changed_date >= DATEADD(day, -?, GETDATE())
                GROUP BY action, table_name
                ORDER BY count DESC, last_occurrence DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$days]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error retrieving audit summary: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get user activity report
     */
    public function getUserActivity($user_id = null, $days = 7) {
        try {
            $sql = "
                SELECT 
                    al.action, al.table_name, al.description,
                    al.changed_date, al.ip_address,
                    u.full_name, u.username
                FROM Audit_Log al
                LEFT JOIN Users u ON al.changed_by = u.user_id
                WHERE al.changed_date >= DATEADD(day, -?, GETDATE())
            ";
            
            $params = [$days];
            
            if ($user_id) {
                $sql .= " AND al.changed_by = ?";
                $params[] = $user_id;
            }
            
            $sql .= " ORDER BY al.changed_date DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error retrieving user activity: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Helper methods
     */
    private function generateChangeDescription($old_data, $new_data, $changed_fields = []) {
        if (empty($changed_fields)) {
            return 'มีการเปลี่ยนแปลงข้อมูล';
        }
        
        $changes = [];
        $field_labels = [
            'po_number' => 'เลขที่ PO',
            'supplier_id' => 'Supplier',
            'total_amount' => 'มูลค่ารวม',
            'status' => 'สถานะ',
            'notes' => 'หมายเหตุ',
            'po_date' => 'วันที่'
        ];
        
        foreach ($changed_fields as $field) {
            $label = $field_labels[$field] ?? $field;
            $old_value = $old_data[$field] ?? 'ไม่มี';
            $new_value = $new_data[$field] ?? 'ไม่มี';
            
            $changes[] = "{$label}: '{$old_value}' → '{$new_value}'";
        }
        
        return implode(', ', $changes);
    }
    
    private function formatChanges($old_values_json, $new_values_json) {
        $old_data = json_decode($old_values_json, true);
        $new_data = json_decode($new_values_json, true);
        
        if (!$old_data && !$new_data) {
            return '';
        }
        
        $changes = [];
        
        if ($old_data && $new_data) {
            // Compare old and new data
            foreach ($new_data as $key => $new_value) {
                $old_value = $old_data[$key] ?? null;
                if ($old_value !== $new_value) {
                    $changes[] = "{$key}: '{$old_value}' → '{$new_value}'";
                }
            }
        } elseif ($new_data) {
            // New data only (creation)
            foreach ($new_data as $key => $value) {
                if (!empty($value)) {
                    $changes[] = "{$key}: {$value}";
                }
            }
        } elseif ($old_data) {
            // Old data only (deletion)
            foreach ($old_data as $key => $value) {
                if (!empty($value)) {
                    $changes[] = "{$key}: {$value} (ถูกลบ)";
                }
            }
        }
        
        return implode('<br>', $changes);
    }
    
    private function getRealIpAddress() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    
    private function timeAgo($datetime) {
        $time = time() - strtotime($datetime);
        
        if ($time < 60) return 'เมื่อสักครู่';
        if ($time < 3600) return floor($time/60) . ' นาทีที่แล้ว';
        if ($time < 86400) return floor($time/3600) . ' ชั่วโมงที่แล้ว';
        if ($time < 2592000) return floor($time/86400) . ' วันที่แล้ว';
        if ($time < 31536000) return floor($time/2592000) . ' เดือนที่แล้ว';
        
        return floor($time/31536000) . ' ปีที่แล้ว';
    }
    
    /**
     * Cleanup old audit logs
     */
    public function cleanupOldLogs($days = 365) {
        try {
            $sql = "DELETE FROM Audit_Log WHERE changed_date < DATEADD(day, -?, GETDATE())";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$days]);
            
            $deleted_count = $stmt->rowCount();
            error_log("Cleaned up {$deleted_count} old audit log entries older than {$days} days");
            
            return $deleted_count;
            
        } catch (PDOException $e) {
            error_log("Error cleaning up audit logs: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Export audit logs to CSV
     */
    public function exportAuditLogs($filters = [], $filename = null) {
        try {
            if (!$filename) {
                $filename = 'audit_log_' . date('Y-m-d_H-i-s') . '.csv';
            }
            
            // Build WHERE clause
            $where_conditions = [];
            $params = [];
            
            if (!empty($filters['table_name'])) {
                $where_conditions[] = "al.table_name = ?";
                $params[] = $filters['table_name'];
            }
            
            if (!empty($filters['action'])) {
                $where_conditions[] = "al.action = ?";
                $params[] = $filters['action'];
            }
            
            if (!empty($filters['user_id'])) {
                $where_conditions[] = "al.changed_by = ?";
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['date_from'])) {
                $where_conditions[] = "al.changed_date >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $where_conditions[] = "al.changed_date <= ?";
                $params[] = $filters['date_to'];
            }
            
            $where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);
            
            $sql = "
                SELECT 
                    al.log_id, al.table_name, al.record_id, al.action,
                    al.description, al.changed_date, al.ip_address,
                    u.full_name as changed_by_name, u.username
                FROM Audit_Log al
                LEFT JOIN Users u ON al.changed_by = u.user_id
                {$where_clause}
                ORDER BY al.changed_date DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Set headers for CSV download
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // Add BOM for UTF-8
            echo "\xEF\xBB\xBF";
            
            $output = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($output, [
                'Log ID', 'Table', 'Record ID', 'Action', 'Description',
                'Changed Date', 'Changed By', 'Username', 'IP Address'
            ]);
            
            // CSV data
            foreach ($logs as $log) {
                fputcsv($output, [
                    $log['log_id'],
                    $log['table_name'],
                    $log['record_id'],
                    $log['action'],
                    $log['description'],
                    $log['changed_date'],
                    $log['changed_by_name'],
                    $log['username'],
                    $log['ip_address']
                ]);
            }
            
            fclose($output);
            
            // Log the export action
            $this->logExport('audit_log', $filters, count($logs));
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error exporting audit logs: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get audit statistics
     */
    public function getAuditStats($days = 30) {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_logs,
                    COUNT(DISTINCT table_name) as tables_affected,
                    COUNT(DISTINCT changed_by) as active_users,
                    COUNT(CASE WHEN action = 'CREATE' THEN 1 END) as create_count,
                    COUNT(CASE WHEN action = 'UPDATE' THEN 1 END) as update_count,
                    COUNT(CASE WHEN action = 'DELETE' THEN 1 END) as delete_count,
                    MIN(changed_date) as first_log,
                    MAX(changed_date) as last_log
                FROM Audit_Log 
                WHERE changed_date >= DATEADD(day, -?, GETDATE())
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$days]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get top actions
            $sql = "
                SELECT TOP 5 action, COUNT(*) as count
                FROM Audit_Log 
                WHERE changed_date >= DATEADD(day, -?, GETDATE())
                GROUP BY action
                ORDER BY count DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$days]);
            $top_actions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get top users
            $sql = "
                SELECT TOP 5 
                    u.full_name, u.username, COUNT(*) as count
                FROM Audit_Log al
                LEFT JOIN Users u ON al.changed_by = u.user_id
                WHERE al.changed_date >= DATEADD(day, -?, GETDATE())
                    AND u.user_id IS NOT NULL
                GROUP BY u.user_id, u.full_name, u.username
                ORDER BY count DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$days]);
            $top_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'summary' => $stats,
                'top_actions' => $top_actions,
                'top_users' => $top_users
            ];
            
        } catch (PDOException $e) {
            error_log("Error retrieving audit stats: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Search audit logs with advanced filters
     */
    public function searchAuditLogs($search_params, $limit = 100, $offset = 0) {
        try {
            $where_conditions = [];
            $params = [];
            
            // Text search
            if (!empty($search_params['search'])) {
                $where_conditions[] = "(al.description LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
                $search_term = "%{$search_params['search']}%";
                $params = array_merge($params, [$search_term, $search_term, $search_term]);
            }
            
            // Table filter
            if (!empty($search_params['table_name'])) {
                $where_conditions[] = "al.table_name = ?";
                $params[] = $search_params['table_name'];
            }
            
            // Action filter
            if (!empty($search_params['action'])) {
                $where_conditions[] = "al.action = ?";
                $params[] = $search_params['action'];
            }
            
            // User filter
            if (!empty($search_params['user_id'])) {
                $where_conditions[] = "al.changed_by = ?";
                $params[] = $search_params['user_id'];
            }
            
            // Date range
            if (!empty($search_params['date_from'])) {
                $where_conditions[] = "al.changed_date >= ?";
                $params[] = $search_params['date_from'];
            }
            
            if (!empty($search_params['date_to'])) {
                $where_conditions[] = "al.changed_date <= ?";
                $params[] = $search_params['date_to'];
            }
            
            // IP filter
            if (!empty($search_params['ip_address'])) {
                $where_conditions[] = "al.ip_address LIKE ?";
                $params[] = "%{$search_params['ip_address']}%";
            }
            
            $where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);
            
            // Count total records
            $count_sql = "
                SELECT COUNT(*) as total
                FROM Audit_Log al
                LEFT JOIN Users u ON al.changed_by = u.user_id
                {$where_clause}
            ";
            
            $stmt = $this->conn->prepare($count_sql);
            $stmt->execute($params);
            $total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get paginated results
            $sql = "
                SELECT 
                    al.log_id, al.table_name, al.record_id, al.action,
                    al.old_values, al.new_values, al.description,
                    al.changed_date, al.ip_address, al.user_agent,
                    u.full_name as changed_by_name, u.username,
                    u.role as user_role
                FROM Audit_Log al
                LEFT JOIN Users u ON al.changed_by = u.user_id
                {$where_clause}
                ORDER BY al.changed_date DESC
                OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
            ";
            
            $params[] = $offset;
            $params[] = $limit;
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format the results
            foreach ($logs as &$log) {
                $log['changes'] = $this->formatChanges($log['old_values'], $log['new_values']);
                $log['formatted_date'] = date('d/m/Y H:i:s', strtotime($log['changed_date']));
                $log['time_ago'] = $this->timeAgo($log['changed_date']);
            }
            
            return [
                'logs' => $logs,
                'total_records' => $total_records,
                'current_page' => floor($offset / $limit) + 1,
                'total_pages' => ceil($total_records / $limit)
            ];
            
        } catch (PDOException $e) {
            error_log("Error searching audit logs: " . $e->getMessage());
            return [
                'logs' => [],
                'total_records' => 0,
                'current_page' => 1,
                'total_pages' => 0
            ];
        }
    }
    
    /**
     * Get suspicious activities
     */
    public function getSuspiciousActivities($days = 7) {
        try {
            $suspicious_activities = [];
            
            // Multiple failed logins from same IP
            $sql = "
                SELECT ip_address, COUNT(*) as attempts
                FROM Audit_Log 
                WHERE action = 'LOGIN_FAILED' 
                    AND changed_date >= DATEADD(day, -?, GETDATE())
                GROUP BY ip_address
                HAVING COUNT(*) >= 5
                ORDER BY attempts DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$days]);
            $failed_logins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($failed_logins)) {
                $suspicious_activities['multiple_failed_logins'] = $failed_logins;
            }
            
            // Unusual deletion activities
            $sql = "
                SELECT 
                    u.full_name, u.username, 
                    COUNT(*) as delete_count,
                    COUNT(DISTINCT table_name) as tables_affected
                FROM Audit_Log al
                LEFT JOIN Users u ON al.changed_by = u.user_id
                WHERE al.action = 'DELETE' 
                    AND al.changed_date >= DATEADD(day, -?, GETDATE())
                GROUP BY u.user_id, u.full_name, u.username
                HAVING COUNT(*) >= 10
                ORDER BY delete_count DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$days]);
            $unusual_deletions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($unusual_deletions)) {
                $suspicious_activities['unusual_deletions'] = $unusual_deletions;
            }
            
            // After-hours activities
            $sql = "
                SELECT 
                    u.full_name, u.username, 
                    COUNT(*) as after_hours_count,
                    MIN(al.changed_date) as first_activity,
                    MAX(al.changed_date) as last_activity
                FROM Audit_Log al
                LEFT JOIN Users u ON al.changed_by = u.user_id
                WHERE al.changed_date >= DATEADD(day, -?, GETDATE())
                    AND (DATEPART(hour, al.changed_date) < 6 OR DATEPART(hour, al.changed_date) > 22)
                    AND al.action NOT IN ('LOGIN', 'LOGOUT')
                GROUP BY u.user_id, u.full_name, u.username
                HAVING COUNT(*) >= 5
                ORDER BY after_hours_count DESC
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$days]);
            $after_hours = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($after_hours)) {
                $suspicious_activities['after_hours_activities'] = $after_hours;
            }
            
            return $suspicious_activities;
            
        } catch (PDOException $e) {
            error_log("Error getting suspicious activities: " . $e->getMessage());
            return [];
        }
    }
}