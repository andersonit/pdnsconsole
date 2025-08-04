<?php
/**
 * PDNS Console - Audit Log Management
 * 
 * Comprehensive system-wide logging for all domain, record, user, tenant, and settings changes.
 * Provides detailed audit trail for compliance and security monitoring.
 */

class AuditLog {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Log an action to the audit trail
     * 
     * @param int $userId User performing the action
     * @param string $action Action performed (CREATE, UPDATE, DELETE, LOGIN, etc.)
     * @param string $tableName Database table affected
     * @param int|null $recordId ID of the affected record
     * @param array|null $oldValues Previous values (for updates/deletes)
     * @param array|null $newValues New values (for creates/updates)
     * @param string|null $ipAddress IP address of the user
     * @param array $metadata Additional metadata about the action
     */
    public function logAction($userId, $action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null, $ipAddress = null, $metadata = []) {
        try {
            // Get IP address if not provided
            if ($ipAddress === null) {
                $ipAddress = $this->getClientIP();
            }

            // Prepare values for storage
            $oldValuesJson = $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null;
            $newValuesJson = $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;
            $metadataJson = !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;

            // Insert audit log entry
            $sql = "INSERT INTO audit_log 
                    (user_id, action, table_name, record_id, old_values, new_values, ip_address, metadata, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            return $this->db->insert($sql, [
                $userId,
                $action,
                $tableName,
                $recordId,
                $oldValuesJson,
                $newValuesJson,
                $ipAddress,
                $metadataJson
            ]);
        } catch (Exception $e) {
            // Don't let audit logging failures break the application
            error_log("Audit log failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Domain-specific logging methods
     */
    public function logDomainCreated($userId, $domainId, $domainData, $metadata = []) {
        return $this->logAction($userId, 'DOMAIN_CREATE', 'domains', $domainId, null, $domainData, null, $metadata);
    }

    public function logDomainUpdated($userId, $domainId, $oldData, $newData, $metadata = []) {
        return $this->logAction($userId, 'DOMAIN_UPDATE', 'domains', $domainId, $oldData, $newData, null, $metadata);
    }

    public function logDomainDeleted($userId, $domainId, $domainData, $metadata = []) {
        return $this->logAction($userId, 'DOMAIN_DELETE', 'domains', $domainId, $domainData, null, null, $metadata);
    }

    /**
     * DNS Record-specific logging methods
     */
    public function logRecordCreated($userId, $recordId, $recordData, $metadata = []) {
        return $this->logAction($userId, 'RECORD_CREATE', 'records', $recordId, null, $recordData, null, $metadata);
    }

    public function logRecordUpdated($userId, $recordId, $oldData, $newData, $metadata = []) {
        return $this->logAction($userId, 'RECORD_UPDATE', 'records', $recordId, $oldData, $newData, null, $metadata);
    }

    public function logRecordDeleted($userId, $recordId, $recordData, $metadata = []) {
        return $this->logAction($userId, 'RECORD_DELETE', 'records', $recordId, $recordData, null, null, $metadata);
    }

    public function logBulkRecordCreate($userId, $domainId, $recordsCreated, $recordsFailed, $metadata = []) {
        $metadata['domain_id'] = $domainId;
        $metadata['records_created'] = count($recordsCreated);
        $metadata['records_failed'] = count($recordsFailed);
        return $this->logAction($userId, 'RECORD_BULK_CREATE', 'records', null, null, $recordsCreated, null, $metadata);
    }

    /**
     * User-specific logging methods
     */
    public function logUserCreated($userId, $targetUserId, $userData, $metadata = []) {
        return $this->logAction($userId, 'USER_CREATE', 'admin_users', $targetUserId, null, $userData, null, $metadata);
    }

    public function logUserUpdated($userId, $targetUserId, $oldData, $newData, $metadata = []) {
        return $this->logAction($userId, 'USER_UPDATE', 'admin_users', $targetUserId, $oldData, $newData, null, $metadata);
    }

    public function logUserDeleted($userId, $targetUserId, $userData, $metadata = []) {
        return $this->logAction($userId, 'USER_DELETE', 'admin_users', $targetUserId, $userData, null, null, $metadata);
    }

    public function logUserLogin($userId, $metadata = []) {
        $metadata['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        return $this->logAction($userId, 'USER_LOGIN', null, null, null, null, null, $metadata);
    }

    public function logUserLogout($userId, $metadata = []) {
        return $this->logAction($userId, 'USER_LOGOUT', null, null, null, null, null, $metadata);
    }

    public function logUserLoginFailed($username, $metadata = []) {
        $metadata['username'] = $username;
        $metadata['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        return $this->logAction(null, 'USER_LOGIN_FAILED', null, null, null, null, null, $metadata);
    }

    public function logPasswordChanged($userId, $targetUserId, $metadata = []) {
        return $this->logAction($userId, 'USER_PASSWORD_CHANGE', 'admin_users', $targetUserId, null, null, null, $metadata);
    }

    public function logMFAEnabled($userId, $targetUserId, $metadata = []) {
        return $this->logAction($userId, 'USER_MFA_ENABLE', 'user_mfa', $targetUserId, null, null, null, $metadata);
    }

    public function logMFADisabled($userId, $targetUserId, $metadata = []) {
        return $this->logAction($userId, 'USER_MFA_DISABLE', 'user_mfa', $targetUserId, null, null, null, $metadata);
    }

    public function logMFAReset($userId, $targetUserId, $metadata = []) {
        return $this->logAction($userId, 'USER_MFA_RESET', 'user_mfa', $targetUserId, null, null, null, $metadata);
    }

    /**
     * Tenant-specific logging methods
     */
    public function logTenantCreated($userId, $tenantId, $tenantData, $metadata = []) {
        return $this->logAction($userId, 'TENANT_CREATE', 'tenants', $tenantId, null, $tenantData, null, $metadata);
    }

    public function logTenantUpdated($userId, $tenantId, $oldData, $newData, $metadata = []) {
        return $this->logAction($userId, 'TENANT_UPDATE', 'tenants', $tenantId, $oldData, $newData, null, $metadata);
    }

    public function logTenantDeleted($userId, $tenantId, $tenantData, $metadata = []) {
        return $this->logAction($userId, 'TENANT_DELETE', 'tenants', $tenantId, $tenantData, null, null, $metadata);
    }

    public function logUserTenantAssignment($userId, $targetUserId, $tenantId, $metadata = []) {
        $metadata['tenant_id'] = $tenantId;
        return $this->logAction($userId, 'USER_TENANT_ASSIGN', 'user_tenants', null, null, null, null, $metadata);
    }

    public function logUserTenantRemoval($userId, $targetUserId, $tenantId, $metadata = []) {
        $metadata['tenant_id'] = $tenantId;
        return $this->logAction($userId, 'USER_TENANT_REMOVE', 'user_tenants', null, null, null, null, $metadata);
    }

    /**
     * Settings-specific logging methods
     */
    public function logSettingUpdated($userId, $settingKey, $oldValue, $newValue, $metadata = []) {
        $metadata['setting_key'] = $settingKey;
        return $this->logAction($userId, 'SETTING_UPDATE', 'global_settings', null, 
                               ['value' => $oldValue], ['value' => $newValue], null, $metadata);
    }

    public function logThemeChanged($userId, $oldTheme, $newTheme, $metadata = []) {
        $metadata['old_theme'] = $oldTheme;
        $metadata['new_theme'] = $newTheme;
        return $this->logAction($userId, 'THEME_CHANGE', 'global_settings', null, null, null, null, $metadata);
    }

    /**
     * DNSSEC-specific logging methods
     */
    public function logDNSSECEnabled($userId, $domainId, $metadata = []) {
        return $this->logAction($userId, 'DNSSEC_ENABLE', 'domains', $domainId, null, null, null, $metadata);
    }

    public function logDNSSECDisabled($userId, $domainId, $metadata = []) {
        return $this->logAction($userId, 'DNSSEC_DISABLE', 'domains', $domainId, null, null, null, $metadata);
    }

    public function logDNSSECKeyGenerated($userId, $domainId, $keyData, $metadata = []) {
        return $this->logAction($userId, 'DNSSEC_KEY_GENERATE', 'cryptokeys', null, null, $keyData, null, $metadata);
    }

    /**
     * Custom Record Type logging methods
     */
    public function logCustomRecordTypeCreated($userId, $typeId, $typeData, $metadata = []) {
        return $this->logAction($userId, 'CUSTOM_TYPE_CREATE', 'custom_record_types', $typeId, null, $typeData, null, $metadata);
    }

    public function logCustomRecordTypeUpdated($userId, $typeId, $oldData, $newData, $metadata = []) {
        return $this->logAction($userId, 'CUSTOM_TYPE_UPDATE', 'custom_record_types', $typeId, $oldData, $newData, null, $metadata);
    }

    public function logCustomRecordTypeDeleted($userId, $typeId, $typeData, $metadata = []) {
        return $this->logAction($userId, 'CUSTOM_TYPE_DELETE', 'custom_record_types', $typeId, $typeData, null, null, $metadata);
    }

    /**
     * Get audit log entries with filtering and pagination
     */
    public function getAuditLog($filters = [], $limit = 50, $offset = 0) {
        $sql = "SELECT al.*, au.username, au.email
                FROM audit_log al
                LEFT JOIN admin_users au ON al.user_id = au.id
                WHERE 1=1";
        
        $params = [];

        // Apply filters
        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND al.action = ?";
            $params[] = $filters['action'];
        }

        if (!empty($filters['table_name'])) {
            $sql .= " AND al.table_name = ?";
            $params[] = $filters['table_name'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND al.created_at >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND al.created_at <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['ip_address'])) {
            $sql .= " AND al.ip_address = ?";
            $params[] = $filters['ip_address'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (al.action LIKE ? OR al.table_name LIKE ? OR au.username LIKE ? OR au.email LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get audit log entry count for pagination
     */
    public function getAuditLogCount($filters = []) {
        $sql = "SELECT COUNT(*) as count
                FROM audit_log al
                LEFT JOIN admin_users au ON al.user_id = au.id
                WHERE 1=1";
        
        $params = [];

        // Apply same filters as getAuditLog
        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND al.action = ?";
            $params[] = $filters['action'];
        }

        if (!empty($filters['table_name'])) {
            $sql .= " AND al.table_name = ?";
            $params[] = $filters['table_name'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND al.created_at >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND al.created_at <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['ip_address'])) {
            $sql .= " AND al.ip_address = ?";
            $params[] = $filters['ip_address'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (al.action LIKE ? OR al.table_name LIKE ? OR au.username LIKE ? OR au.email LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $result = $this->db->fetch($sql, $params);
        return $result['count'];
    }

    /**
     * Get recent activity for dashboard
     */
    public function getRecentActivity($limit = 10, $tenantId = null) {
        $sql = "SELECT al.*, au.username, au.email
                FROM audit_log al
                LEFT JOIN admin_users au ON al.user_id = au.id";
        
        $params = [];

        // Filter by tenant if specified
        if ($tenantId !== null) {
            $sql .= " LEFT JOIN domain_tenants dt ON al.table_name = 'domains' AND al.record_id = dt.domain_id
                      WHERE (al.table_name != 'domains' OR dt.tenant_id = ?)";
            $params[] = $tenantId;
        }

        $sql .= " ORDER BY al.created_at DESC LIMIT ?";
        $params[] = $limit;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get audit statistics for dashboard
     */
    public function getAuditStats($tenantId = null, $days = 30) {
        $sql = "SELECT 
                    COUNT(*) as total_actions,
                    COUNT(DISTINCT user_id) as active_users,
                    COUNT(CASE WHEN action LIKE '%CREATE%' THEN 1 END) as creates,
                    COUNT(CASE WHEN action LIKE '%UPDATE%' THEN 1 END) as updates,
                    COUNT(CASE WHEN action LIKE '%DELETE%' THEN 1 END) as deletes,
                    COUNT(CASE WHEN action = 'USER_LOGIN' THEN 1 END) as logins
                FROM audit_log al";
        
        $params = [];

        if ($tenantId !== null) {
            $sql .= " LEFT JOIN domain_tenants dt ON al.table_name = 'domains' AND al.record_id = dt.domain_id
                      WHERE (al.table_name != 'domains' OR dt.tenant_id = ?) 
                      AND al.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params[] = $tenantId;
            $params[] = $days;
        } else {
            $sql .= " WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params[] = $days;
        }

        return $this->db->fetch($sql, $params);
    }

    /**
     * Get user activity summary
     */
    public function getUserActivity($userId, $days = 30) {
        $sql = "SELECT 
                    COUNT(*) as total_actions,
                    COUNT(CASE WHEN action LIKE '%CREATE%' THEN 1 END) as creates,
                    COUNT(CASE WHEN action LIKE '%UPDATE%' THEN 1 END) as updates,
                    COUNT(CASE WHEN action LIKE '%DELETE%' THEN 1 END) as deletes,
                    MAX(created_at) as last_activity
                FROM audit_log 
                WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        
        return $this->db->fetch($sql, [$userId, $days]);
    }

    /**
     * Cleanup old audit log entries
     */
    public function cleanupOldLogs($retentionDays = 365) {
        $sql = "DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
        return $this->db->execute($sql, [$retentionDays]);
    }

    /**
     * Get client IP address
     */
    private function getClientIP() {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
                   'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, 
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }

    /**
     * Format action for display
     */
    public function formatAction($action) {
        $actionMap = [
            'DOMAIN_CREATE' => 'Domain Created',
            'DOMAIN_UPDATE' => 'Domain Updated',
            'DOMAIN_DELETE' => 'Domain Deleted',
            'RECORD_CREATE' => 'Record Created',
            'RECORD_UPDATE' => 'Record Updated',
            'RECORD_DELETE' => 'Record Deleted',
            'RECORD_BULK_CREATE' => 'Bulk Records Created',
            'USER_CREATE' => 'User Created',
            'USER_UPDATE' => 'User Updated',
            'USER_DELETE' => 'User Deleted',
            'USER_LOGIN' => 'User Login',
            'USER_LOGOUT' => 'User Logout',
            'USER_LOGIN_FAILED' => 'Login Failed',
            'USER_PASSWORD_CHANGE' => 'Password Changed',
            'USER_MFA_ENABLE' => '2FA Enabled',
            'USER_MFA_DISABLE' => '2FA Disabled',
            'USER_MFA_RESET' => '2FA Reset',
            'TENANT_CREATE' => 'Tenant Created',
            'TENANT_UPDATE' => 'Tenant Updated',
            'TENANT_DELETE' => 'Tenant Deleted',
            'USER_TENANT_ASSIGN' => 'User Assigned to Tenant',
            'USER_TENANT_REMOVE' => 'User Removed from Tenant',
            'SETTING_UPDATE' => 'Setting Updated',
            'THEME_CHANGE' => 'Theme Changed',
            'DNSSEC_ENABLE' => 'DNSSEC Enabled',
            'DNSSEC_DISABLE' => 'DNSSEC Disabled',
            'DNSSEC_KEY_GENERATE' => 'DNSSEC Key Generated',
            'CUSTOM_TYPE_CREATE' => 'Custom Record Type Created',
            'CUSTOM_TYPE_UPDATE' => 'Custom Record Type Updated',
            'CUSTOM_TYPE_DELETE' => 'Custom Record Type Deleted'
        ];

        return $actionMap[$action] ?? $action;
    }

    /**
     * Get action badge class for styling
     */
    public function getActionBadgeClass($action) {
        if (strpos($action, 'CREATE') !== false || strpos($action, 'ENABLE') !== false) {
            return 'bg-success';
        } elseif (strpos($action, 'UPDATE') !== false || strpos($action, 'CHANGE') !== false) {
            return 'bg-warning';
        } elseif (strpos($action, 'DELETE') !== false || strpos($action, 'DISABLE') !== false) {
            return 'bg-danger';
        } elseif (strpos($action, 'LOGIN') !== false) {
            return 'bg-info';
        } else {
            return 'bg-secondary';
        }
    }
}
?>
