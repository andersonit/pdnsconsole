<?php
/**
 * PDNS Console
 * Copyright (c) 2025 Neowyze LLC
 *
 * Licensed under the Business Source License 1.0.
 * You may use this file in compliance with the license terms.
 *
 * License details: https://github.com/andersonit/pdnsconsole/blob/main/LICENSE.md
 */

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

    /**
     * Log maintenance mode toggle
     */
    public function logMaintenanceToggle($userId, $enabled, $previouslyEnabled) {
        $metadata = [
            'enabled' => (bool)$enabled,
            'previous' => (bool)$previouslyEnabled,
            'ip' => $this->getClientIP()
        ];
        return $this->logAction($userId, 'MAINTENANCE_TOGGLE', 'global_settings', null, ['enabled' => $previouslyEnabled ? '1' : '0'], ['enabled' => $enabled ? '1' : '0'], null, $metadata);
    }

    /**
     * Log CAPTCHA verification failure on login
     */
    public function logCaptchaFailure($ip, $provider, $reason = null, $usernameAttempt = null) {
        // Always resolve current client IP to avoid proxy IP leakage
        $resolvedIp = $this->getClientIP();
        $metadata = [
            'provider' => $provider,
            'reason' => $reason,
            'ip' => $resolvedIp,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'path' => $_SERVER['REQUEST_URI'] ?? ''
        ];
        return $this->logAction(null, 'CAPTCHA_FAILED', null, null, null, null, $resolvedIp, $metadata);
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
     * Comment-specific logging methods
     */
    public function logCommentCreated($userId, $commentId, $commentData, $metadata = []) {
        return $this->logAction($userId, 'COMMENT_CREATE', 'comments', $commentId, null, $commentData, null, $metadata);
    }

    public function logCommentUpdated($userId, $commentId, $oldData, $newData, $metadata = []) {
        return $this->logAction($userId, 'COMMENT_UPDATE', 'comments', $commentId, $oldData, $newData, null, $metadata);
    }

    public function logCommentDeleted($userId, $commentId, $commentData, $metadata = []) {
        return $this->logAction($userId, 'COMMENT_DELETE', 'comments', $commentId, $commentData, null, null, $metadata);
    }

    /**
     * Get audit log entries with filtering and pagination
     */
    public function getAuditLog($filters = [], $limit = 50, $offset = 0, $sort = 'created_at', $dir = 'desc') {
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

        // Sorting
        $allowedSort = [
            'created_at' => 'al.created_at',
            'action' => 'al.action',
            'table_name' => 'al.table_name',
            'record_id' => 'al.record_id',
            'ip_address' => 'al.ip_address',
            'user' => 'au.username'
        ];
        $sortKey = strtolower((string)$sort);
        $orderBy = $allowedSort[$sortKey] ?? 'al.created_at';
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $sql .= " ORDER BY $orderBy $dir LIMIT ? OFFSET ?";
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
     * Get client IP address honoring trusted proxies (HAProxy/Nginx) safely.
     * Uses TRUSTED_PROXIES env (comma-separated IP/CIDR) plus private/localhost defaults.
     */
    private function getClientIP() {
        // CLI invocations
        if (PHP_SAPI === 'cli' || defined('STDIN')) {
            return 'CLI';
        }

        $server = $_SERVER;
        $remote = $server['REMOTE_ADDR'] ?? '';
        $trustedCidrs = $this->getTrustedProxies();

        // Candidates from standard headers
        $candidates = [];
        if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $server['HTTP_X_FORWARDED_FOR']) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $candidates[] = $ip;
                }
            }
        }
        foreach (['HTTP_X_REAL_IP','HTTP_CF_CONNECTING_IP','HTTP_TRUE_CLIENT_IP','HTTP_CLIENT_IP'] as $key) {
            if (!empty($server[$key]) && filter_var($server[$key], FILTER_VALIDATE_IP)) {
                $candidates[] = $server[$key];
            }
        }

        $remoteIsTrusted = $remote && $this->ipIsTrusted($remote, $trustedCidrs);

        if ($remoteIsTrusted && $candidates) {
            // Prefer first public, non-trusted hop from XFF chain
            foreach ($candidates as $ip) {
                if (!$this->ipIsTrusted($ip, $trustedCidrs) && !$this->isPrivateIp($ip)) {
                    return $ip;
                }
            }
            // Fallback to first candidate
            return $candidates[0];
        }

        return $remote ?: '0.0.0.0';
    }

    private function isPrivateIp(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        $privCidrs = [
            '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8',
            '::1/128', 'fc00::/7', 'fe80::/10', '169.254.0.0/16', '100.64.0.0/10'
        ];
        foreach ($privCidrs as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) return true;
        }
        return false;
    }

    private function getTrustedProxies(): array {
        $env = getenv('TRUSTED_PROXIES') ?: '';
        $list = array_filter(array_map('trim', explode(',', $env)));
        $defaults = ['127.0.0.1/32','::1/128','10.0.0.0/8','172.16.0.0/12','192.168.0.0/16','100.64.0.0/10','169.254.0.0/16','fc00::/7','fe80::/10'];
        return array_unique(array_merge($defaults, $list));
    }

    private function ipIsTrusted(string $ip, array $trustedCidrs): bool {
        foreach ($trustedCidrs as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) return true;
        }
        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool {
        if (strpos($cidr, '/') === false) return $ip === $cidr;
        [$subnet, $mask] = explode('/', $cidr, 2);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = sprintf('%u', ip2long($ip));
            $subLong = sprintf('%u', ip2long($subnet));
            $mask = (int)$mask;
            $maskLong = $mask === 0 ? 0 : (~0 << (32 - $mask)) & 0xFFFFFFFF;
            return (($ipLong & $maskLong) === ($subLong & $maskLong));
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin  = inet_pton($ip);
            $subBin = inet_pton($subnet);
            $mask = (int)$mask;
            $bytes = intdiv($mask, 8);
            $bits  = $mask % 8;
            if ($bytes && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) return false;
            if ($bits) {
                $maskByte = chr((~0 << (8 - $bits)) & 0xFF);
                return ((ord($ipBin[$bytes]) & ord($maskByte)) === (ord($subBin[$bytes]) & ord($maskByte)));
            }
            return true;
        }
        return false;
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
            'DNSSEC_RECTIFY' => 'DNSSEC Zone Rectified',
            'CUSTOM_TYPE_CREATE' => 'Custom Record Type Created',
            'CUSTOM_TYPE_UPDATE' => 'Custom Record Type Updated',
            'CUSTOM_TYPE_DELETE' => 'Custom Record Type Deleted',
            'COMMENT_CREATE' => 'Comment Created',
            'COMMENT_UPDATE' => 'Comment Updated',
            'COMMENT_DELETE' => 'Comment Deleted',
            'MAINTENANCE_TOGGLE' => 'Maintenance Mode Toggled',
            'CAPTCHA_FAILED' => 'CAPTCHA Failed',
            // DDNS
            'DDNS_UPDATE' => 'Dynamic DNS Updated',
            'DDNS_AUTH_FAILED' => 'Dynamic DNS Auth Failed',
            'DDNS_RATE_LIMIT_HIT' => 'Dynamic DNS Rate Limited',
            'DDNS_RATE_LIMIT_EXCEEDED' => 'Dynamic DNS Throttled'
        ];

    // Token management (DDNS)
    $actionMap['DDNS_TOKEN_CREATE'] = 'DDNS Token Created';
    $actionMap['DDNS_TOKEN_DELETE'] = 'DDNS Token Deleted';
    $actionMap['DDNS_TOKEN_ENABLE'] = 'DDNS Token Enabled';
    $actionMap['DDNS_TOKEN_DISABLE'] = 'DDNS Token Disabled';

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
