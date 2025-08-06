<?php
/**
 * PDNS Console - Domain Management Class
 * 
 * Handles domain operations with tenant isolation and PowerDNS integration
 */

class Domain {
    private $db;
    private $settings;
    private $auditLog;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->settings = new Settings();
        $this->auditLog = new AuditLog();
    }
    
    /**
     * Get domains for a tenant with optional filtering
     */
    public function getDomainsForTenant($tenantId, $search = '', $zoneTypeFilter = '', $limit = 25, $offset = 0, $sortBy = 'name', $sortOrder = 'ASC') {
        // Validate sort parameters
        $allowedSorts = ['name', 'zone_type', 'record_count', 'domain_created'];
        $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'name';
        $sortOrder = in_array(strtoupper($sortOrder), ['ASC', 'DESC']) ? strtoupper($sortOrder) : 'ASC';
        
        $sql = "SELECT d.id, d.name, d.type, d.zone_type, d.account, 
                       COUNT(r.id) as record_count,
                       dt.created_at as domain_created,
                       (SELECT COUNT(*) FROM cryptokeys ck WHERE ck.domain_id = d.id AND ck.active = 1) as dnssec_enabled
                FROM domains d
                LEFT JOIN domain_tenants dt ON d.id = dt.domain_id
                LEFT JOIN records r ON d.id = r.domain_id
                WHERE dt.tenant_id = ?";
        
        $params = [$tenantId];
        
        if (!empty($search)) {
            $sql .= " AND d.name LIKE ?";
            $params[] = '%' . $search . '%';
        }
        
        if (!empty($zoneTypeFilter)) {
            $sql .= " AND d.zone_type = ?";
            $params[] = $zoneTypeFilter;
        }
        
        $sql .= " GROUP BY d.id, d.name, d.type, d.zone_type, d.account, dt.created_at
                  ORDER BY {$sortBy} {$sortOrder}";
        
        // Add secondary sorts for consistency
        if ($sortBy !== 'name') {
            $sql .= ", d.name ASC";
        }
        
        $sql .= " LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get total domain count for a tenant
     */
    public function getDomainCountForTenant($tenantId, $search = '', $zoneTypeFilter = '') {
        $sql = "SELECT COUNT(DISTINCT d.id) as count
                FROM domains d
                LEFT JOIN domain_tenants dt ON d.id = dt.domain_id
                WHERE dt.tenant_id = ?";
        
        $params = [$tenantId];
        
        if (!empty($search)) {
            $sql .= " AND d.name LIKE ?";
            $params[] = '%' . $search . '%';
        }
        
        if (!empty($zoneTypeFilter)) {
            $sql .= " AND d.zone_type = ?";
            $params[] = $zoneTypeFilter;
        }
        
        $result = $this->db->fetch($sql, $params);
        return $result['count'] ?? 0;
    }
    
    /**
     * Get all domains (super admin only)
     */
    public function getAllDomains($search = '', $zoneTypeFilter = '', $tenantFilter = '', $limit = 25, $offset = 0, $sortBy = 'name', $sortOrder = 'ASC') {
        // Validate sort parameters
        $allowedSorts = ['name', 'zone_type', 'tenant_name', 'record_count', 'domain_created'];
        $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'name';
        $sortOrder = in_array(strtoupper($sortOrder), ['ASC', 'DESC']) ? strtoupper($sortOrder) : 'ASC';
        
        $sql = "SELECT d.id, d.name, d.type, d.account, d.zone_type,
                       COUNT(r.id) as record_count,
                       t.name as tenant_name,
                       dt.created_at as domain_created,
                       (SELECT COUNT(*) FROM cryptokeys ck WHERE ck.domain_id = d.id AND ck.active = 1) as dnssec_enabled
                FROM domains d
                LEFT JOIN domain_tenants dt ON d.id = dt.domain_id
                LEFT JOIN tenants t ON dt.tenant_id = t.id
                LEFT JOIN records r ON d.id = r.domain_id";
        
        $params = [];
        $whereConditions = [];
        
        if (!empty($search)) {
            $whereConditions[] = "d.name LIKE ?";
            $params[] = '%' . $search . '%';
        }
        
        if (!empty($zoneTypeFilter)) {
            $whereConditions[] = "d.zone_type = ?";
            $params[] = $zoneTypeFilter;
        }
        
        if (!empty($tenantFilter)) {
            $whereConditions[] = "dt.tenant_id = ?";
            $params[] = $tenantFilter;
        }
        
        if (!empty($whereConditions)) {
            $sql .= " WHERE " . implode(" AND ", $whereConditions);
        }
        
        $sql .= " GROUP BY d.id, d.name, d.type, d.account, d.zone_type, t.name, dt.created_at
                  ORDER BY {$sortBy} {$sortOrder}";
        
        // Add secondary sorts for consistency
        if ($sortBy !== 'name') {
            $sql .= ", d.name ASC";
        }
        
        $sql .= " LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get total domain count for all domains (super admin only)
     */
    public function getAllDomainsCount($search = '', $zoneTypeFilter = '', $tenantFilter = '') {
        $sql = "SELECT COUNT(DISTINCT d.id) as count
                FROM domains d
                LEFT JOIN domain_tenants dt ON d.id = dt.domain_id
                LEFT JOIN tenants t ON dt.tenant_id = t.id";
        
        $params = [];
        $whereConditions = [];
        
        if (!empty($search)) {
            $whereConditions[] = "d.name LIKE ?";
            $params[] = '%' . $search . '%';
        }
        
        if (!empty($zoneTypeFilter)) {
            $whereConditions[] = "d.zone_type = ?";
            $params[] = $zoneTypeFilter;
        }
        
        if (!empty($tenantFilter)) {
            $whereConditions[] = "dt.tenant_id = ?";
            $params[] = $tenantFilter;
        }
        
        if (!empty($whereConditions)) {
            $sql .= " WHERE " . implode(" AND ", $whereConditions);
        }
        
        $result = $this->db->fetch($sql, $params);
        return $result['count'] ?? 0;
    }
    
    /**
     * Get domain by ID with tenant check
     */
    public function getDomainById($domainId, $tenantId = null) {
        if ($tenantId) {
            // Tenant-filtered query
            $sql = "SELECT d.*, dt.tenant_id, t.name as tenant_name
                    FROM domains d
                    LEFT JOIN domain_tenants dt ON d.id = dt.domain_id
                    LEFT JOIN tenants t ON dt.tenant_id = t.id
                    WHERE d.id = ? AND dt.tenant_id = ?";
            $params = [$domainId, $tenantId];
        } else {
            // Super admin query
            $sql = "SELECT d.*, dt.tenant_id, t.name as tenant_name
                    FROM domains d
                    LEFT JOIN domain_tenants dt ON d.id = dt.domain_id
                    LEFT JOIN tenants t ON dt.tenant_id = t.id
                    WHERE d.id = ?";
            $params = [$domainId];
        }
        
        return $this->db->fetch($sql, $params);
    }
    
    /**
     * Create a new domain with automatic SOA and NS records
     */
    public function createDomain($name, $tenantId, $type = 'NATIVE', $zoneType = 'forward', $subnet = null) {
        $this->db->beginTransaction();
        
        try {
            // Handle reverse zone creation
            if ($zoneType === 'reverse') {
                if (empty($subnet)) {
                    throw new Exception('Subnet is required for reverse zones');
                }
                
                $name = $this->generateReverseZoneName($subnet);
                if (!$name) {
                    throw new Exception('Invalid subnet format for reverse zone');
                }
            } else {
                // Validate forward domain name
                if (!$this->isValidDomainName($name)) {
                    throw new Exception('Invalid domain name format');
                }
            }
            
            // Check if domain already exists
            $existing = $this->db->fetch("SELECT id FROM domains WHERE name = ?", [$name]);
            if ($existing) {
                throw new Exception('Domain already exists');
            }
            
            // Check tenant domain limit (if not unlimited)
            if (!$this->canAddDomain($tenantId)) {
                throw new Exception('Domain limit exceeded for this tenant');
            }
            
            // Create domain record with zone type
            $this->db->execute(
                "INSERT INTO domains (name, type, zone_type, account) VALUES (?, ?, ?, ?)",
                [$name, $type, $zoneType, "tenant_$tenantId"]
            );
            
            $domainId = $this->db->getConnection()->lastInsertId();
            
            // Link domain to tenant
            $this->db->execute(
                "INSERT INTO domain_tenants (domain_id, tenant_id) VALUES (?, ?)",
                [$domainId, $tenantId]
            );
            
            // Create SOA record
            $this->createSOARecord($domainId, $name, $tenantId);
            
            // Create NS records (both forward and reverse zones need NS records)
            $this->createNSRecords($domainId, $name);
            
            $this->db->commit();
            
            // Log domain creation
            $domainData = [
                'name' => $name,
                'type' => $type,
                'zone_type' => $zoneType,
                'tenant_id' => $tenantId
            ];
            
            if (isset($_SESSION['user_id'])) {
                $this->auditLog->logDomainCreated($_SESSION['user_id'], $domainId, $domainData, [
                    'subnet' => $subnet
                ]);
            }
            
            return $domainId;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Delete domain and all associated records
     */
    public function deleteDomain($domainId, $tenantId = null) {
        $this->db->beginTransaction();
        
        try {
            // Verify domain exists and tenant has access
            $domain = $this->getDomainById($domainId, $tenantId);
            if (!$domain) {
                throw new Exception('Domain not found or access denied');
            }
            
            // Delete all records
            $this->db->execute("DELETE FROM records WHERE domain_id = ?", [$domainId]);
            
            // Delete crypto keys (DNSSEC)
            $this->db->execute("DELETE FROM cryptokeys WHERE domain_id = ?", [$domainId]);
            
            // Delete domain metadata
            $this->db->execute("DELETE FROM domainmetadata WHERE domain_id = ?", [$domainId]);
            
            // Delete comments
            $this->db->execute("DELETE FROM comments WHERE domain_id = ?", [$domainId]);
            
            // Delete domain-tenant relationship
            $this->db->execute("DELETE FROM domain_tenants WHERE domain_id = ?", [$domainId]);
            
            // Delete domain
            $this->db->execute("DELETE FROM domains WHERE id = ?", [$domainId]);
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Update domain settings
     */
    public function updateDomain($domainId, $data, $tenantId = null) {
        // Verify domain exists and tenant has access
        $domain = $this->getDomainById($domainId, $tenantId);
        if (!$domain) {
            throw new Exception('Domain not found or access denied');
        }
        
        $updateFields = [];
        $params = [];
        
        if (isset($data['type'])) {
            $updateFields[] = "type = ?";
            $params[] = $data['type'];
        }
        
        if (isset($data['account'])) {
            $updateFields[] = "account = ?";
            $params[] = $data['account'];
        }
        
        if (empty($updateFields)) {
            return true;
        }
        
        $params[] = $domainId;
        
        $sql = "UPDATE domains SET " . implode(', ', $updateFields) . " WHERE id = ?";
        
        return $this->db->execute($sql, $params);
    }
    
    /**
     * Check if tenant can add more domains
     */
    public function canAddDomain($tenantId) {
        // Get tenant info
        $tenant = $this->db->fetch("SELECT max_domains FROM tenants WHERE id = ?", [$tenantId]);
        if (!$tenant) {
            return false;
        }
        
        // 0 means unlimited
        if ($tenant['max_domains'] == 0) {
            return true;
        }
        
        // Check current domain count
        $currentCount = $this->getDomainCountForTenant($tenantId);
        
        return $currentCount < $tenant['max_domains'];
    }
    
    /**
     * Create SOA record for new domain
     */
    private function createSOARecord($domainId, $domainName, $tenantId = null) {
        // Get primary nameserver from nameserver table
        $nameserver = new Nameserver();
        $nameservers = $nameserver->getActiveNameservers();
        
        if (empty($nameservers)) {
            throw new Exception('No active nameservers configured.');
        }
        
        $primaryNS = $nameservers[0]['hostname'];
        $soaContact = $this->settings->get('soa_contact', 'admin.atmyip.com');
        
        // Check for tenant-specific SOA contact override
        if ($tenantId) {
            $tenantSOA = $this->db->fetch(
                "SELECT soa_contact_override FROM tenants WHERE id = ? AND soa_contact_override IS NOT NULL AND soa_contact_override != ''",
                [$tenantId]
            );
            
            if ($tenantSOA && !empty($tenantSOA['soa_contact_override'])) {
                $soaContact = $tenantSOA['soa_contact_override'];
            }
        }
        
        $soaRefresh = $this->settings->get('soa_refresh', '10800');
        $soaRetry = $this->settings->get('soa_retry', '3600');
        $soaExpire = $this->settings->get('soa_expire', '604800');
        $soaMinimum = $this->settings->get('soa_minimum', '86400');
        
        // Serial number (YYYYMMDD01 format)
        $serial = date('Ymd') . '01';
        
        $soaContent = sprintf('%s %s %s %s %s %s %s',
            $primaryNS,
            str_replace('@', '.', $soaContact),
            $serial,
            $soaRefresh,
            $soaRetry,
            $soaExpire,
            $soaMinimum
        );
        
        $this->db->execute(
            "INSERT INTO records (domain_id, name, type, content, ttl, auth) VALUES (?, ?, 'SOA', ?, 3600, 1)",
            [$domainId, $domainName, $soaContent]
        );
    }
    
    /**
     * Create NS records for new domain
     */
    private function createNSRecords($domainId, $domainName) {
        $nameserver = new Nameserver();
        $nameservers = $nameserver->getActiveNameservers();
        
        if (empty($nameservers)) {
            throw new Exception('No active nameservers configured.');
        }
        
        // Create NS records for all active nameservers
        foreach ($nameservers as $ns) {
            $this->db->execute(
                "INSERT INTO records (domain_id, name, type, content, ttl, auth) VALUES (?, ?, 'NS', ?, 3600, 1)",
                [$domainId, $domainName, $ns['hostname']]
            );
        }
    }
    
    /**
     * Validate domain name format
     */
    private function isValidDomainName($domain) {
        // Basic domain name validation
        if (empty($domain) || strlen($domain) > 253) {
            return false;
        }
        
        // Check for valid characters and format
        return preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $domain);
    }
    
    /**
     * Get domain statistics for dashboard
     */
    public function getDomainStats($tenantId = null) {
        if ($tenantId) {
            // Tenant-specific stats
            $domainCount = $this->getDomainCountForTenant($tenantId);
            
            $recordCount = $this->db->fetch(
                "SELECT COUNT(r.id) as count 
                 FROM records r
                 JOIN domain_tenants dt ON r.domain_id = dt.domain_id
                 WHERE dt.tenant_id = ?",
                [$tenantId]
            );
            
            $dnssecCount = $this->db->fetch(
                "SELECT COUNT(DISTINCT ck.domain_id) as count
                 FROM cryptokeys ck
                 JOIN domain_tenants dt ON ck.domain_id = dt.domain_id
                 WHERE dt.tenant_id = ? AND ck.active = 1",
                [$tenantId]
            );
            
        } else {
            // System-wide stats (super admin)
            $domainCount = $this->db->fetch("SELECT COUNT(*) as count FROM domains")['count'];
            $recordCount = $this->db->fetch("SELECT COUNT(*) as count FROM records");
            $dnssecCount = $this->db->fetch(
                "SELECT COUNT(DISTINCT domain_id) as count FROM cryptokeys WHERE active = 1"
            );
        }
        
        return [
            'domains' => $domainCount,
            'records' => $recordCount['count'] ?? 0,
            'dnssec_domains' => $dnssecCount['count'] ?? 0
        ];
    }
    
    /**
     * Generate reverse zone name from IP subnet
     */
    public function generateReverseZoneName($subnet) {
        // Remove any whitespace
        $subnet = trim($subnet);
        
        // Handle IPv4 subnets
        if (strpos($subnet, '.') !== false) {
            return $this->generateIPv4ReverseZone($subnet);
        }
        
        // Handle IPv6 subnets
        if (strpos($subnet, ':') !== false) {
            return $this->generateIPv6ReverseZone($subnet);
        }
        
        return false;
    }
    
    /**
     * Generate IPv4 reverse zone name
     */
    private function generateIPv4ReverseZone($subnet) {
        // Parse CIDR notation
        if (strpos($subnet, '/') !== false) {
            list($ip, $prefix) = explode('/', $subnet);
        } else {
            $ip = $subnet;
            $prefix = 24; // Default to /24
        }
        
        // Validate IP address
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        
        $parts = explode('.', $ip);
        $prefix = intval($prefix);
        
        // Generate reverse zone based on prefix
        switch (true) {
            case $prefix >= 24:
                // Class C: 192.168.1.0/24 -> 1.168.192.in-addr.arpa
                return $parts[2] . '.' . $parts[1] . '.' . $parts[0] . '.in-addr.arpa';
                
            case $prefix >= 16:
                // Class B: 192.168.0.0/16 -> 168.192.in-addr.arpa
                return $parts[1] . '.' . $parts[0] . '.in-addr.arpa';
                
            case $prefix >= 8:
                // Class A: 192.0.0.0/8 -> 192.in-addr.arpa
                return $parts[0] . '.in-addr.arpa';
                
            default:
                return false;
        }
    }
    
    /**
     * Generate IPv6 reverse zone name
     */
    private function generateIPv6ReverseZone($subnet) {
        // Parse CIDR notation
        if (strpos($subnet, '/') !== false) {
            list($ip, $prefix) = explode('/', $subnet);
        } else {
            $ip = $subnet;
            $prefix = 64; // Default to /64
        }
        
        // Validate IPv6 address
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }
        
        // Expand IPv6 address to full form
        $expanded = inet_pton($ip);
        $hex = bin2hex($expanded);
        
        $prefix = intval($prefix);
        $nibbles = intval($prefix / 4);
        
        // Generate reverse zone
        $reverse = '';
        for ($i = $nibbles - 1; $i >= 0; $i--) {
            $reverse .= $hex[$i] . '.';
        }
        
        return $reverse . 'ip6.arpa';
    }
    
    /**
     * Validate IP subnet format
     */
    public function isValidSubnet($subnet) {
        $subnet = trim($subnet);
        
        // Check for CIDR notation
        if (strpos($subnet, '/') === false) {
            // Add default prefix
            if (strpos($subnet, '.') !== false) {
                $subnet .= '/24'; // IPv4 default
            } else {
                $subnet .= '/64'; // IPv6 default
            }
        }
        
        list($ip, $prefix) = explode('/', $subnet);
        $prefix = intval($prefix);
        
        // Validate IPv4
        if (strpos($ip, '.') !== false) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return false;
            }
            return $prefix >= 8 && $prefix <= 32;
        }
        
        // Validate IPv6
        if (strpos($ip, ':') !== false) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return false;
            }
            return $prefix >= 16 && $prefix <= 128;
        }
        
        return false;
    }
    
    /**
     * Check if domain is a reverse zone
     */
    public function isReverseZone($domainName) {
        return (strpos($domainName, '.in-addr.arpa') !== false || 
                strpos($domainName, '.ip6.arpa') !== false);
    }
}
?>
