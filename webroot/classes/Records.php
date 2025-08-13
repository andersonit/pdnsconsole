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
            // Comprehensive IPv6 regex supporting compressed forms
            'pattern' => '/^(([0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4}|([0-9A-Fa-f]{1,4}:){1,7}:|([0-9A-Fa-f]{1,4}:){1,6}:[0-9A-Fa-f]{1,4}|([0-9A-Fa-f]{1,4}:){1,5}(:[0-9A-Fa-f]{1,4}){1,2}|([0-9A-Fa-f]{1,4}:){1,4}(:[0-9A-Fa-f]{1,4}){1,3}|([0-9A-Fa-f]{1,4}:){1,3}(:[0-9A-Fa-f]{1,4}){1,4}|([0-9A-Fa-f]{1,4}:){1,2}(:[0-9A-Fa-f]{1,4}){1,5}|[0-9A-Fa-f]{1,4}:((:[0-9A-Fa-f]{1,4}){1,6})|:((:[0-9A-Fa-f]{1,4}){1,7}|:))$/',
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
            // Priority is stored separately in the prio column; allow optional leading number for backward compatibility
            'pattern' => '/^(?:[0-9]+ )?[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.?$/',
            'example' => 'mail.example.com.',
            'description' => 'Specifies mail server hostname (priority set separately)'
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
            'description' => 'Delegates a subdomain to other name servers',
            'readonly' => true
        ],
        'PTR' => [
            'name' => 'PTR Record (Reverse DNS)',
            'pattern' => '/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.?$/',
            'example' => 'example.com.',
            'description' => 'Maps an IP address to a hostname (reverse DNS)'
        ],
        'SRV' => [
            'name' => 'SRV Record (Service)',
            // Priority is stored separately (prio column). Pattern allows optional leading priority for legacy entries
            'pattern' => '/^(?:[0-9]+ )?[0-9]+ [0-9]+ [a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.?$/',
            'example' => '5 443 server.example.com.',
            'description' => 'Defines service weight, port, and target host (priority set separately)'
        ],
        'SOA' => [
            'name' => 'SOA Record (Start of Authority)',
            'pattern' => '/^[^\s]+ [^\s]+ \d+ \d+ \d+ \d+ \d+$/',
            'example' => 'ns1.example.com. admin.example.com. 2023010101 3600 1800 604800 300',
            'description' => 'Defines authoritative information about a DNS zone',
            'readonly' => true
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
    public function getRecordsForDomain($domainId, $tenantId = null, $type = '', $search = '', $limit = 50, $offset = 0, $sortBy = 'name', $sortOrder = 'ASC') {
        // Verify domain access
        $domain = $this->domain->getDomainById($domainId, $tenantId);
        if (!$domain) {
            throw new Exception('Domain not found or access denied');
        }
        
        // Validate sort parameters
        $allowedSorts = ['name', 'type', 'content', 'ttl', 'prio'];
        $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'name';
        $sortOrder = in_array(strtoupper($sortOrder), ['ASC', 'DESC']) ? strtoupper($sortOrder) : 'ASC';
        
        $sql = "SELECT r.id, r.name, r.type, r.content, r.ttl, r.prio, r.auth
                FROM records r
                WHERE r.domain_id = ?";
        
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
        
        $sql .= " ORDER BY r.{$sortBy} {$sortOrder}";
        
        // Add secondary sorts for consistency
        if ($sortBy !== 'type') {
            $sql .= ", r.type ASC";
        }
        if ($sortBy !== 'name') {
            $sql .= ", r.name ASC";
        }
        if ($sortBy !== 'prio') {
            $sql .= ", r.prio ASC";
        }
        
        $sql .= " LIMIT ? OFFSET ?";
        
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
                WHERE r.domain_id = ?";
        
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
        
        // TXT semantic validation (SPF/DKIM/DMARC) before storage
        if (strtoupper($type) === 'TXT') {
            $this->validateTxtSemantics($name, $domain['name'], $content);
        }

        // Normalize TXT content to include quotes required by PowerDNS
        if (strtoupper($type) === 'TXT') {
            $content = $this->normalizeTxtContent($content);
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
        
        // TXT semantic validation (SPF/DKIM/DMARC) before storage
        if (strtoupper($type) === 'TXT') {
            $this->validateTxtSemantics($name, $domain['name'], $content);
        }

        // Normalize TXT content to include quotes required by PowerDNS
        if (strtoupper($type) === 'TXT') {
            $content = $this->normalizeTxtContent($content);
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
     * Ensure TXT record content is quoted per PowerDNS expectations.
     * - If already quoted, return as-is.
     * - Otherwise wrap entire content in double quotes.
     */
    private function normalizeTxtContent($content) {
        $trimmed = trim((string)$content);
        if ($trimmed === '') return '""';
        // If already as multiple quoted strings, assume caller provided valid formatting
        if ($trimmed[0] === '"' && substr($trimmed, -1) === '"' && preg_match('/"\s+"/', $trimmed)) {
            return $trimmed;
        }
        // If single quoted string, keep as-is unless it exceeds 255 inside
        if ($trimmed[0] === '"' && substr($trimmed, -1) === '"') {
            $inner = substr($trimmed, 1, -1);
            $len = strlen($inner);
            if ($len <= 255) return $trimmed;
            $parts = [];
            for ($i = 0; $i < $len; $i += 255) {
                $parts[] = '"' . substr($inner, $i, 255) . '"';
            }
            return implode(' ', $parts);
        }
        // Escape unescaped quotes
        $escaped = preg_replace('/(?<!\\\\)"/', '\\"', $trimmed);
        if ($escaped === null) { // safety fallback if PCRE fails
            $escaped = str_replace('"', '\\"', $trimmed);
        }
        $len = strlen($escaped);
        if ($len <= 255) return '"' . $escaped . '"';
        $parts = [];
        for ($i = 0; $i < $len; $i += 255) {
            $parts[] = '"' . substr($escaped, $i, 255) . '"';
        }
        return implode(' ', $parts);
    }

    /**
     * Validate semantic TXT subtypes (SPF/DKIM/DMARC). Throws Exception on invalid strict cases.
     */
    private function validateTxtSemantics($fqdnName, $zoneName, $content) {
        $raw = trim((string)$content);
        // If TXT provided in quoted form (possibly multiple segments), merge segments for semantic validation
        $c = $raw;
        if ($raw !== '') {
            if (preg_match_all('/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/', $raw, $m) && count($m[1]) > 0) {
                $segments = array_map('stripcslashes', $m[1]);
                $c = implode('', $segments);
            } else {
                // Trim single pair of quotes if present
                if ($raw[0] === '"' && substr($raw, -1) === '"') {
                    $c = stripcslashes(substr($raw, 1, -1));
                }
            }
            $c = trim($c);
        }
        // SPF
        if (preg_match('/^v=spf1(?:\s|$)/i', $c)) {
            $this->validateSpf($c);
        }
        // DMARC: must be at _dmarc.<zone> or a subdomain (_dmarc.sub.zone)
        if (preg_match('/^v=\s*DMARC1\s*;/i', $c)) {
            $this->validateDmarc($fqdnName, $zoneName, $c);
        }
        // DKIM: should be at <selector>._domainkey.<zone>
        if (preg_match('/^v=\s*DKIM1\s*;/i', $c)) {
            $this->validateDkim($fqdnName, $zoneName, $c);
        }
    }

    private function validateSpf($content) {
        // Basic SPF syntax check: starts with v=spf1 and tokens are valid
        $tokens = preg_split('/\s+/', trim($content));
        if (strtolower($tokens[0]) !== 'v=spf1') {
            throw new Exception('SPF must start with v=spf1');
        }
        $validMech = ['all','include','a','mx','ptr','ip4','ip6','exists','redirect','exp'];
        for ($i=1; $i<count($tokens); $i++) {
            $t = $tokens[$i]; if ($t==='') continue;
            // modifiers (redirect=, exp=)
            if (preg_match('/^(redirect|exp)=[^\s]+$/', $t)) continue;
            // mechanism with optional qualifier
            $q = substr($t,0,1);
            if (in_array($q, ['+','-','~','?'])) { $t = substr($t,1); }
            $parts = explode(':', $t, 2);
            $mech = strtolower($parts[0]); $arg = $parts[1] ?? '';
            if (!in_array($mech, $validMech, true)) {
                throw new Exception('Invalid SPF mechanism: '.$mech);
            }
            if ($mech === 'ip4') {
                if ($arg === '') {
                    throw new Exception('SPF ip4 requires an IPv4 address (optionally with /mask)');
                }
                if (strpos($arg, '/') !== false) {
                    [$ip, $mask] = explode('/', $arg, 2);
                    $ip = trim($ip); $mask = trim($mask);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                        throw new Exception('Invalid SPF ip4 address: '.$ip);
                    }
                    if ($mask === '' || !ctype_digit($mask) || (int)$mask < 0 || (int)$mask > 32) {
                        throw new Exception('Invalid SPF ip4 mask (0-32): '.$mask);
                    }
                } else {
                    if (filter_var($arg, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                        throw new Exception('Invalid SPF ip4 address: '.$arg);
                    }
                }
                continue;
            }
            if ($mech === 'ip6') {
                if ($arg === '') {
                    throw new Exception('SPF ip6 requires an IPv6 address (optionally with /mask)');
                }
                if (strpos($arg, '/') !== false) {
                    [$ip, $mask] = explode('/', $arg, 2);
                    $ip = trim($ip); $mask = trim($mask);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                        throw new Exception('Invalid SPF ip6 address: '.$ip);
                    }
                    if ($mask === '' || !ctype_digit($mask) || (int)$mask < 0 || (int)$mask > 128) {
                        throw new Exception('Invalid SPF ip6 mask (0-128): '.$mask);
                    }
                } else {
                    if (filter_var($arg, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                        throw new Exception('Invalid SPF ip6 address: '.$arg);
                    }
                }
                continue;
            }
            if (in_array($mech, ['include','exists','a','mx','ptr'], true) && $arg !== '') {
                // Allow SPF macros (e.g., %{i}); skip strict hostname validation when present
                if (str_contains($arg, '%{')) {
                    continue;
                }
                if (!$this->isValidHostname($arg)) {
                    throw new Exception('Invalid SPF hostname in '.$mech.': '.$arg);
                }
            }
        }
    }

    private function validateDmarc($fqdnName, $zoneName, $content) {
        // name should match _dmarc.<domain> or _dmarc.<sub>.<domain>
        if (!preg_match('/^_dmarc\./i', $fqdnName)) {
            throw new Exception('DMARC TXT must be at _dmarc.<domain>');
        }
        // Parse tags
        $tags = [];
        foreach (explode(';', $content) as $seg) {
            $seg = trim($seg); if ($seg==='') continue;
            if (stripos($seg, 'v=DMARC1') === 0) { $tags['v'] = 'DMARC1'; continue; }
            $kv = explode('=', $seg, 2);
            if (count($kv)===2) { $tags[strtolower(trim($kv[0]))] = trim($kv[1]); }
        }
        if (empty($tags['v']) || strtoupper($tags['v']) !== 'DMARC1') {
            throw new Exception('DMARC must include v=DMARC1');
        }
        if (empty($tags['p']) || !in_array(strtolower($tags['p']), ['none','quarantine','reject'], true)) {
            throw new Exception('DMARC requires p=none|quarantine|reject');
        }
        if (!empty($tags['pct']) && (!ctype_digit($tags['pct']) || (int)$tags['pct']<0 || (int)$tags['pct']>100)) {
            throw new Exception('DMARC pct must be 0-100');
        }
        if (!empty($tags['adkim']) && !in_array(strtolower($tags['adkim']), ['r','s'], true)) {
            throw new Exception('DMARC adkim must be r or s');
        }
        if (!empty($tags['aspf']) && !in_array(strtolower($tags['aspf']), ['r','s'], true)) {
            throw new Exception('DMARC aspf must be r or s');
        }
        foreach (['rua','ruf'] as $t) {
            if (!empty($tags[$t])) {
                $uris = array_map('trim', explode(',', $tags[$t]));
                foreach ($uris as $u) {
                    if (!preg_match('/^mailto:.+@.+\..+$/i', $u)) {
                        throw new Exception('DMARC '.$t.' must be mailto: URI');
                    }
                }
            }
        }
    }

    private function validateDkim($fqdnName, $zoneName, $content) {
        // Must be under _domainkey
        if (!preg_match('/\._domainkey\./i', $fqdnName)) {
            throw new Exception('DKIM TXT must be at <selector>._domainkey.<domain>');
        }
        // Require p=
        if (!preg_match('/(^|;)\s*p=([A-Za-z0-9\/+]+=*)/i', $content, $m)) {
            throw new Exception('DKIM record must include p=<base64 public key>');
        }
        // Basic base64 check
        $p = $m[2];
        if (!preg_match('/^[A-Za-z0-9\/+]+=*$/', $p)) {
            throw new Exception('DKIM p= contains invalid base64 characters');
        }
        if (preg_match('/(^|;)\s*k=([^;\s]+)/i', $content, $mk)) {
            $k = strtolower($mk[2]);
            if (!in_array($k, ['rsa','ed25519'], true)) {
                throw new Exception('DKIM k= must be rsa or ed25519');
            }
        }
    }

    private function isValidHostname($host) {
        return (bool)preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.?$/', $host);
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
        $content = trim($content);
        if ($type === 'TXT') {
            // Accept any non-empty TXT up to a reasonable limit (schema allows large); normalization will chunk to <=255 segments
            return $content !== '' && strlen($content) <= 64000;
        }
        // Use native validators for IP addresses for accuracy
        if ($type === 'A') {
            return filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }
        if ($type === 'AAAA') {
            return filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
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
        $name = trim((string)$name);
        $domainName = rtrim((string)$domainName, '.');
        if ($name === '' || $name === '@') {
            return $domainName;
        }
        // Remove trailing dot if user entered an absolute FQDN
        $nameNoDot = rtrim($name, '.');
        // If already fully qualified under this zone, keep as-is (without trailing dot)
        if ($nameNoDot === $domainName || str_ends_with($nameNoDot, '.' . $domainName)) {
            return $nameNoDot;
        }
        // Otherwise, treat as relative label(s) and append the zone
        return $nameNoDot . '.' . $domainName;
    }
    
    /**
     * Update domain serial number in SOA record
     */
    public function updateDomainSerial($domainId) {
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
