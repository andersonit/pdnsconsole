<?php
/**
 * PDNS Console - DNS Records Management Class
 * 
 * Handles DNS record operations with tenant isolation and PowerDNS integration
 */

class Records {
    private $db;
    private $domain;
    private $auditLog;
    
    // Supported record types with validation patterns
    private $recordTypes = [
        'A' => [
            'name' => 'A Record (IPv4)',
            'pattern' => '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/',
            'example' => '192.168.1.1',
            'description' => 'Maps a hostname to an IPv4 address'
        ],
        'AAAA' => [
            'name' => 'AAAA Record (IPv6)',
            'pattern' => '/^(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$|^::1$|^::$/',
            'example' => '2001:db8::1',
            'description' => 'Maps a hostname to an IPv6 address'
        ],
        'CNAME' => [
            'name' => 'CNAME Record (Alias)',
            'pattern' => '/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.?$/',
            'example' => 'www.example.com.',
            'description' => 'Creates an alias pointing to another domain name'
        ],
        'MX' => [
            'name' => 'MX Record (Mail Exchange)',
            'pattern' => '/^[0-9]+ [a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.?$/',
            'example' => '10 mail.example.com.',
            'description' => 'Specifies mail servers for the domain'
        ],
        'TXT' => [
            'name' => 'TXT Record (Text)',
            'pattern' => '/^.{1,255}$/',
            'example' => 'v=spf1 include:_spf.google.com ~all',
            'description' => 'Stores arbitrary text data'
        ],
        'NS' => [
            'name' => 'NS Record (Name Server)',
            'pattern' => '/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.?$/',
            'example' => 'ns1.example.com.',
            'description' => 'Delegates a subdomain to other name servers'
        ],
        'PTR' => [
            'name' => 'PTR Record (Reverse DNS)',
            'pattern' => '/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.?$/',
            'example' => 'example.com.',
            'description' => 'Maps an IP address to a hostname (reverse DNS)'
        ],
        'SRV' => [
            'name' => 'SRV Record (Service)',
            'pattern' => '/^[0-9]+ [0-9]+ [0-9]+ [a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.?$/',
            'example' => '0 5 443 server.example.com.',
            'description' => 'Defines services available in the domain'
        ]
    ];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->domain = new Domain();
        $this->auditLog = new AuditLog();
    }
    
    /**
     * Get all available record types (standard + custom)
     */
    public function getAvailableRecordTypes($zoneType = null) {
        $availableTypes = $this->recordTypes;
        
        // Get custom record types from database
        $customTypes = $this->db->fetchAll("SELECT type_name, description, validation_pattern FROM custom_record_types WHERE is_active = 1");
        
        foreach ($customTypes as $customType) {
            $availableTypes[$customType['type_name']] = [
                'name' => $customType['type_name'] . ' Record',
                'pattern' => !empty($customType['validation_pattern']) ? '/' . $customType['validation_pattern'] . '/' : '/^.+$/',
                'example' => 'Varies by record type',
                'description' => $customType['description'],
                'custom' => true
            ];
        }
        
        // Filter by zone type if specified
        if ($zoneType) {
            return $this->filterRecordTypesByZone($availableTypes, $zoneType);
        }
        
        return $availableTypes;
    }
    
    /**
     * Filter record types based on zone type
     */
    private function filterRecordTypesByZone($recordTypes, $zoneType) {
        if ($zoneType === 'reverse') {
            // For reverse zones, only allow PTR records and some universal types
            $allowedForReverse = ['PTR', 'NS', 'SOA', 'TXT', 'CNAME'];
            return array_filter($recordTypes, function($type) use ($allowedForReverse) {
                return in_array($type, $allowedForReverse);
            }, ARRAY_FILTER_USE_KEY);
        } else {
            // For forward zones, exclude PTR records
            return array_filter($recordTypes, function($type) {
                return $type !== 'PTR';
            }, ARRAY_FILTER_USE_KEY);
        }
    }
    
    /**
     * Get record types for form dropdowns
     */
    public function getRecordTypesForForm($zoneType = null) {
        $types = $this->getAvailableRecordTypes($zoneType);
        $formTypes = [];
        
        foreach ($types as $type => $info) {
            $formTypes[$type] = $info['name'];
        }
        
        return $formTypes;
    }
    
    /**
     * Get records for a domain with tenant access check
     */
    public function getRecordsForDomain($domainId, $tenantId = null, $type = '', $search = '', $limit = 50, $offset = 0) {
        // Verify domain access
        $domain = $this->domain->getDomainById($domainId, $tenantId);
        if (!$domain) {
            throw new Exception('Domain not found or access denied');
        }
        
        $sql = "SELECT r.id, r.name, r.type, r.content, r.ttl, r.prio, r.auth
                FROM records r
                WHERE r.domain_id = ? AND r.type != 'SOA'";
        
        $params = [$domainId];
        
        if (!empty($type)) {
            $sql .= " AND r.type = ?";
            $params[] = $type;
        }
        
        if (!empty($search)) {
            $sql .= " AND (r.name LIKE ? OR r.content LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        
        $sql .= " ORDER BY r.type ASC, r.name ASC, r.prio ASC
                  LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get record count for a domain
     */
    public function getRecordCountForDomain($domainId, $tenantId = null, $type = '', $search = '') {
        // Verify domain access
        $domain = $this->domain->getDomainById($domainId, $tenantId);
        if (!$domain) {
            throw new Exception('Domain not found or access denied');
        }
        
        $sql = "SELECT COUNT(*) as count
                FROM records r
                WHERE r.domain_id = ? AND r.type != 'SOA'";
        
        $params = [$domainId];
        
        if (!empty($type)) {
            $sql .= " AND r.type = ?";
            $params[] = $type;
        }
        
        if (!empty($search)) {
            $sql .= " AND (r.name LIKE ? OR r.content LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        
        $result = $this->db->fetch($sql, $params);
        return $result['count'] ?? 0;
    }
    
    /**
     * Get a specific record by ID
     */
    public function getRecordById($recordId, $tenantId = null) {
        $sql = "SELECT r.*, d.name as domain_name
                FROM records r
                JOIN domains d ON r.domain_id = d.id";
        
        if ($tenantId !== null) {
            $sql .= " JOIN domain_tenants dt ON d.id = dt.domain_id
                      WHERE r.id = ? AND dt.tenant_id = ?";
            $params = [$recordId, $tenantId];
        } else {
            $sql .= " WHERE r.id = ?";
            $params = [$recordId];
        }
        
        return $this->db->fetch($sql, $params);
    }
    
    /**
     * Create a new DNS record
     */
    public function createRecord($domainId, $name, $type, $content, $ttl = 3600, $prio = 0, $tenantId = null) {
        // Verify domain access
        $domain = $this->domain->getDomainById($domainId, $tenantId);
        if (!$domain) {
            throw new Exception('Domain not found or access denied');
        }
        
        // Validate record type
        if (!isset($this->recordTypes[$type])) {
            throw new Exception('Invalid record type: ' . $type);
        }
        
        // Validate content format
        if (!$this->validateRecordContent($type, $content)) {
            throw new Exception('Invalid content format for ' . $type . ' record');
        }
        
        // Normalize name
        $name = $this->normalizeName($name, $domain['name']);
        
        // Validate name format
        if (!$this->validateRecordName($name)) {
            throw new Exception('Invalid record name format');
        }
        
        // Check for conflicts (CNAME records cannot coexist with other types)
        if ($type === 'CNAME') {
            $existing = $this->db->fetch(
                "SELECT COUNT(*) as count FROM records WHERE domain_id = ? AND name = ? AND type != 'CNAME'",
                [$domainId, $name]
            );
            if ($existing['count'] > 0) {
                throw new Exception('CNAME record cannot coexist with other record types for the same name');
            }
        } else {
            $existing = $this->db->fetch(
                "SELECT COUNT(*) as count FROM records WHERE domain_id = ? AND name = ? AND type = 'CNAME'",
                [$domainId, $name]
            );
            if ($existing['count'] > 0) {
                throw new Exception('Cannot create record when CNAME exists for the same name');
            }
        }
        
        // Insert record
        $this->db->execute(
            "INSERT INTO records (domain_id, name, type, content, ttl, prio, auth) 
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            [$domainId, $name, $type, $content, $ttl, $prio]
        );
        
        $recordId = $this->db->getConnection()->lastInsertId();
        
        // Update domain serial
        $this->updateDomainSerial($domainId);
        
        // Log record creation
        $recordData = [
            'name' => $name,
            'type' => $type,
            'content' => $content,
            'ttl' => $ttl,
            'prio' => $prio,
            'domain_id' => $domainId
        ];
        
        if (isset($_SESSION['user_id'])) {
            $this->auditLog->logRecordCreated($_SESSION['user_id'], $recordId, $recordData);
        }
        
        return $recordId;
    }
    
    /**
     * Update an existing DNS record
     */
    public function updateRecord($recordId, $name, $type, $content, $ttl = 3600, $prio = 0, $tenantId = null) {
        // Get existing record
        $record = $this->getRecordById($recordId, $tenantId);
        if (!$record) {
            throw new Exception('Record not found or access denied');
        }
        
        // Don't allow changing SOA or NS records for the domain root
        if (in_array($record['type'], ['SOA', 'NS']) && $record['name'] === $record['domain_name']) {
            throw new Exception('Cannot modify primary SOA or NS records');
        }
        
        // Validate record type
        if (!isset($this->recordTypes[$type])) {
            throw new Exception('Invalid record type: ' . $type);
        }
        
        // Validate content format
        if (!$this->validateRecordContent($type, $content)) {
            throw new Exception('Invalid content format for ' . $type . ' record');
        }
        
        // Get domain info
        $domain = $this->domain->getDomainById($record['domain_id'], $tenantId);
        
        // Normalize name
        $name = $this->normalizeName($name, $domain['name']);
        
        // Validate name format
        if (!$this->validateRecordName($name)) {
            throw new Exception('Invalid record name format');
        }
        
        // Check for conflicts if type or name changed
        if ($type !== $record['type'] || $name !== $record['name']) {
            if ($type === 'CNAME') {
                $existing = $this->db->fetch(
                    "SELECT COUNT(*) as count FROM records WHERE domain_id = ? AND name = ? AND type != 'CNAME' AND id != ?",
                    [$record['domain_id'], $name, $recordId]
                );
                if ($existing['count'] > 0) {
                    throw new Exception('CNAME record cannot coexist with other record types for the same name');
                }
            } else {
                $existing = $this->db->fetch(
                    "SELECT COUNT(*) as count FROM records WHERE domain_id = ? AND name = ? AND type = 'CNAME' AND id != ?",
                    [$record['domain_id'], $name, $recordId]
                );
                if ($existing['count'] > 0) {
                    throw new Exception('Cannot create record when CNAME exists for the same name');
                }
            }
        }
        
        // Update record
        $this->db->execute(
            "UPDATE records SET name = ?, type = ?, content = ?, ttl = ?, prio = ? 
             WHERE id = ?",
            [$name, $type, $content, $ttl, $prio, $recordId]
        );
        
        // Update domain serial
        $this->updateDomainSerial($record['domain_id']);
        
        return true;
    }
    
    /**
     * Delete a DNS record
     */
    public function deleteRecord($recordId, $tenantId = null) {
        // Get record info
        $record = $this->getRecordById($recordId, $tenantId);
        if (!$record) {
            throw new Exception('Record not found or access denied');
        }
        
        // Don't allow deleting SOA or primary NS records
        if (in_array($record['type'], ['SOA', 'NS']) && $record['name'] === $record['domain_name']) {
            throw new Exception('Cannot delete primary SOA or NS records');
        }
        
        // Delete record
        $this->db->execute("DELETE FROM records WHERE id = ?", [$recordId]);
        
        // Update domain serial
        $this->updateDomainSerial($record['domain_id']);
        
        return true;
    }
    
    /**
     * Get supported record types (for backward compatibility)
     */
    public function getSupportedRecordTypes($zoneType = null) {
        return $this->getAvailableRecordTypes($zoneType);
    }
    
    /**
     * Validate record content based on type
     */
    private function validateRecordContent($type, $content) {
        $availableTypes = $this->getAvailableRecordTypes();
        
        if (!isset($availableTypes[$type])) {
            return false;
        }
        
        $pattern = $availableTypes[$type]['pattern'];
        return preg_match($pattern, $content);
    }
    
    /**
     * Validate record name format
     */
    private function validateRecordName($name) {
        // Basic validation for DNS name
        if (empty($name) || strlen($name) > 253) {
            return false;
        }
        
        // Allow @ for root domain and wildcard *
        if ($name === '@' || $name === '*') {
            return true;
        }
        
        // Standard domain name validation
        return preg_match('/^(?:[a-zA-Z0-9*](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9*](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.?$/', $name);
    }
    
    /**
     * Normalize record name (convert @ to domain name, ensure proper format)
     */
    private function normalizeName($name, $domainName) {
        $name = trim($name);
        
        // Convert @ to domain name
        if ($name === '@' || empty($name)) {
            return $domainName;
        }
        
        // If name doesn't end with domain, append it
        if (!str_ends_with($name, '.' . $domainName) && $name !== $domainName) {
            $name = $name . '.' . $domainName;
        }
        
        return $name;
    }
    
    /**
     * Update domain serial number in SOA record
     */
    private function updateDomainSerial($domainId) {
        // Get current SOA record
        $soa = $this->db->fetch(
            "SELECT id, content FROM records WHERE domain_id = ? AND type = 'SOA'",
            [$domainId]
        );
        
        if ($soa) {
            $parts = explode(' ', $soa['content']);
            if (count($parts) >= 7) {
                // Update serial (YYYYMMDDNN format)
                $today = date('Ymd');
                $currentSerial = $parts[2];
                
                if (substr($currentSerial, 0, 8) === $today) {
                    // Same day, increment counter
                    $counter = intval(substr($currentSerial, 8)) + 1;
                    $newSerial = $today . sprintf('%02d', $counter);
                } else {
                    // New day, start with 01
                    $newSerial = $today . '01';
                }
                
                $parts[2] = $newSerial;
                $newContent = implode(' ', $parts);
                
                $this->db->execute(
                    "UPDATE records SET content = ? WHERE id = ?",
                    [$newContent, $soa['id']]
                );
            }
        }
    }
    
    /**
     * Get record statistics for domain
     */
    public function getRecordStats($domainId, $tenantId = null) {
        // Verify domain access
        $domain = $this->domain->getDomainById($domainId, $tenantId);
        if (!$domain) {
            throw new Exception('Domain not found or access denied');
        }
        
        $stats = $this->db->fetchAll(
            "SELECT type, COUNT(*) as count 
             FROM records 
             WHERE domain_id = ? 
             GROUP BY type 
             ORDER BY count DESC",
            [$domainId]
        );
        
        return $stats;
    }
}
?>
