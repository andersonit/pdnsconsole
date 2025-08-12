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
 * PDNS Console - Nameserver Management Class
 * 
 * Handles nameserver operations and automatic NS record updates
 */

class Nameserver {
    private $db;
    private $auditLog;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->auditLog = new AuditLog();
    }
    
    /**
     * Get all active nameservers ordered by priority
     */
    public function getActiveNameservers() {
        return $this->db->fetchAll(
            "SELECT * FROM nameservers WHERE is_active = 1 ORDER BY priority ASC"
        );
    }
    
    /**
     * Get all nameservers (active and inactive)
     */
    public function getAllNameservers() {
        return $this->db->fetchAll(
            "SELECT * FROM nameservers ORDER BY priority ASC"
        );
    }
    
    /**
     * Add a new nameserver
     */
    public function addNameserver($hostname, $priority = null) {
        // Validate hostname
        if (empty($hostname)) {
            throw new Exception('Hostname is required.');
        }
        
        if (!filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new Exception('Invalid hostname format.');
        }
        
        // Check if hostname already exists
        $existing = $this->db->fetch(
            "SELECT id FROM nameservers WHERE hostname = ?",
            [$hostname]
        );
        
        if ($existing) {
            throw new Exception('Nameserver already exists.');
        }
        
        // Auto-assign priority if not provided
        if ($priority === null) {
            $maxPriority = $this->db->fetch(
                "SELECT MAX(priority) as max_priority FROM nameservers"
            );
            $priority = ($maxPriority['max_priority'] ?? 0) + 1;
        }
        
        $this->db->execute(
            "INSERT INTO nameservers (hostname, priority, is_active) VALUES (?, ?, 1)",
            [$hostname, $priority]
        );
        
        $nameserverId = $this->db->getConnection()->lastInsertId();
        
        // Update all domain NS records
        $this->updateAllDomainNSRecords();
        
        // Log the action
        $this->auditLog->logAction(
            $_SESSION['user_id'] ?? 0,
            'CREATE',
            'nameservers',
            $nameserverId,
            null,
            ['hostname' => $hostname, 'priority' => $priority],
            null,
            ['action_type' => 'nameserver_add']
        );
        
        return $nameserverId;
    }
    
    /**
     * Update nameserver
     */
    public function updateNameserver($id, $hostname, $priority, $isActive = true) {
        // Get existing nameserver
        $existing = $this->db->fetch(
            "SELECT * FROM nameservers WHERE id = ?",
            [$id]
        );
        
        if (!$existing) {
            throw new Exception('Nameserver not found.');
        }
        
        // Validate hostname
        if (empty($hostname)) {
            throw new Exception('Hostname is required.');
        }
        
        if (!filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new Exception('Invalid hostname format.');
        }
        
        // Check if hostname already exists (excluding current record)
        $duplicate = $this->db->fetch(
            "SELECT id FROM nameservers WHERE hostname = ? AND id != ?",
            [$hostname, $id]
        );
        
        if ($duplicate) {
            throw new Exception('Nameserver hostname already exists.');
        }
        
        $this->db->execute(
            "UPDATE nameservers SET hostname = ?, priority = ?, is_active = ? WHERE id = ?",
            [$hostname, $priority, $isActive ? 1 : 0, $id]
        );
        
        // Update all domain NS records
        $this->updateAllDomainNSRecords();
        
        // Log the action
        $this->auditLog->logAction(
            $_SESSION['user_id'] ?? 0,
            'UPDATE',
            'nameservers',
            $id,
            [
                'hostname' => $existing['hostname'],
                'priority' => $existing['priority'],
                'is_active' => $existing['is_active']
            ],
            [
                'hostname' => $hostname,
                'priority' => $priority,
                'is_active' => $isActive ? 1 : 0
            ],
            null,
            ['action_type' => 'nameserver_update']
        );
    }
    
    /**
     * Delete nameserver
     */
    public function deleteNameserver($id) {
        // Get existing nameserver
        $existing = $this->db->fetch(
            "SELECT * FROM nameservers WHERE id = ?",
            [$id]
        );
        
        if (!$existing) {
            throw new Exception('Nameserver not found.');
        }
        
        // Ensure we don't delete all nameservers
        $activeCount = $this->db->fetch(
            "SELECT COUNT(*) as count FROM nameservers WHERE is_active = 1 AND id != ?",
            [$id]
        );
        
        if ($activeCount['count'] < 1) {
            throw new Exception('Cannot delete the last active nameserver.');
        }
        
        $this->db->execute("DELETE FROM nameservers WHERE id = ?", [$id]);
        
        // Update all domain NS records
        $this->updateAllDomainNSRecords();
        
        // Log the action
        $this->auditLog->logAction(
            $_SESSION['user_id'] ?? 0,
            'DELETE',
            'nameservers',
            $id,
            ['hostname' => $existing['hostname'], 'priority' => $existing['priority']],
            null,
            null,
            ['action_type' => 'nameserver_delete']
        );
    }
    
    /**
     * Update NS records for all domains when nameservers change
     */
    public function updateAllDomainNSRecords() {
        // Get all active nameservers
        $nameservers = $this->getActiveNameservers();
        
        if (empty($nameservers)) {
            throw new Exception('No active nameservers configured.');
        }
        
        // Get all domains
        $domains = $this->db->fetchAll(
            "SELECT id, name FROM domains WHERE type = 'NATIVE' OR type = 'MASTER'"
        );
        
        foreach ($domains as $domain) {
            $this->updateDomainNSRecords($domain['id'], $domain['name'], $nameservers);
        }
    }
    
    /**
     * Update NS records for a specific domain
     */
    public function updateDomainNSRecords($domainId, $domainName, $nameservers = null) {
        if ($nameservers === null) {
            $nameservers = $this->getActiveNameservers();
        }
        
        // Delete existing NS records for this domain
        $this->db->execute(
            "DELETE FROM records WHERE domain_id = ? AND type = 'NS'",
            [$domainId]
        );
        
        // Add new NS records for each nameserver
        foreach ($nameservers as $ns) {
            $this->db->execute(
                "INSERT INTO records (domain_id, name, type, content, ttl, auth) VALUES (?, ?, 'NS', ?, 3600, 1)",
                [$domainId, $domainName, $ns['hostname']]
            );
        }
    }
    
    /**
     * Bulk update nameservers from settings form
     */
    public function bulkUpdateFromSettings($nameserverData) {
        $this->db->beginTransaction();
        
        try {
            // Get current nameservers
            $current = $this->getAllNameservers();
            $currentHostnames = array_column($current, 'hostname');
            
            $newHostnames = [];
            $priority = 1;
            
            // Process submitted nameservers
            foreach ($nameserverData as $hostname) {
                $hostname = trim($hostname);
                if (empty($hostname)) {
                    continue;
                }
                
                $newHostnames[] = $hostname;
                
                // Check if this nameserver already exists
                $existing = $this->db->fetch(
                    "SELECT id FROM nameservers WHERE hostname = ?",
                    [$hostname]
                );
                
                if ($existing) {
                    // Update existing nameserver
                    $this->db->execute(
                        "UPDATE nameservers SET priority = ?, is_active = 1 WHERE hostname = ?",
                        [$priority, $hostname]
                    );
                } else {
                    // Add new nameserver
                    $this->db->execute(
                        "INSERT INTO nameservers (hostname, priority, is_active) VALUES (?, ?, 1)",
                        [$hostname, $priority]
                    );
                }
                
                $priority++;
            }
            
            // Deactivate nameservers that are no longer in the list
            foreach ($currentHostnames as $hostname) {
                if (!in_array($hostname, $newHostnames)) {
                    $this->db->execute(
                        "UPDATE nameservers SET is_active = 0 WHERE hostname = ?",
                        [$hostname]
                    );
                }
            }
            
            // Ensure we have at least one active nameserver
            $activeCount = $this->db->fetch(
                "SELECT COUNT(*) as count FROM nameservers WHERE is_active = 1"
            );
            
            if ($activeCount['count'] < 1) {
                throw new Exception('At least one nameserver must be active.');
            }
            
            $this->db->commit();
            
            // Update all domain NS records
            $this->updateAllDomainNSRecords();
            
            // Log the action
            $this->auditLog->logAction(
                $_SESSION['user_id'] ?? 0,
                'UPDATE',
                'nameservers',
                null,
                ['nameservers' => $currentHostnames],
                ['nameservers' => $newHostnames],
                null,
                ['action_type' => 'nameservers_bulk_update']
            );
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
