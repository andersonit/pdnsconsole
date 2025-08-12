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
 * PDNS Console - Comments Management Class
 *
 * Provides CRUD operations for PowerDNS 'comments' table entries.
 * PowerDNS associates comments with (domain_id, name, type); there is no direct record_id FK.
 */
class Comments {
    private $db;
    private $domain;
    private $records;
    private $auditLog;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->domain = new Domain();
        $this->records = new Records();
        $this->auditLog = new AuditLog();
    }

    /**
     * Get all comments for a specific record (by domain, name, type)
     */
    public function getCommentsForRecord($domainId, $name, $type, $tenantId = null) {
        // Access check via domain
        $domain = $this->domain->getDomainById($domainId, $tenantId);
        if (!$domain) { throw new Exception('Domain access denied'); }
        return $this->db->fetchAll(
            "SELECT id, domain_id, name, type, modified_at, account, comment FROM comments WHERE domain_id = ? AND name = ? AND type = ? ORDER BY modified_at DESC, id DESC",
            [$domainId, $name, $type]
        );
    }

    /**
     * Get comment counts for all records in a domain (returns ["name|type" => count])
     */
    public function getCommentCountsForDomain($domainId) {
        $rows = $this->db->fetchAll(
            "SELECT name, type, COUNT(*) as cnt FROM comments WHERE domain_id = ? GROUP BY name, type",
            [$domainId]
        );
        $out = [];
        foreach ($rows as $r) { $out[$r['name'] . '|' . $r['type']] = (int)$r['cnt']; }
        return $out;
    }

    /**
     * Add a comment for a record.
     */
    public function addComment($domainId, $recordId, $commentText, $userAccount, $tenantId = null) {
        $record = $this->records->getRecordById($recordId, $tenantId);
        if (!$record || (int)$record['domain_id'] !== (int)$domainId) { throw new Exception('Record not found'); }
        $name = $record['name'];
        $type = $record['type'];
        $account = substr($userAccount ?? 'user', 0, 40);
        $now = time();
        $this->db->execute(
            "INSERT INTO comments (domain_id, name, type, modified_at, account, comment) VALUES (?, ?, ?, ?, ?, ?)",
            [$domainId, $name, $type, $now, $account, $commentText]
        );
        $id = $this->db->getConnection()->lastInsertId();
        if (isset($_SESSION['user_id'])) {
            $this->auditLog->logCommentCreated($_SESSION['user_id'], $id, [
                'domain_id' => $domainId,
                'name' => $name,
                'type' => $type,
                'comment' => $commentText
            ]);
        }
        return $id;
    }

    /**
     * Update a comment (only text; updates modified_at and account)
     */
    public function updateComment($commentId, $newText, $userAccount) {
        $existing = $this->db->fetch("SELECT * FROM comments WHERE id = ?", [$commentId]);
        if (!$existing) { throw new Exception('Comment not found'); }
        $account = substr($userAccount ?? 'user', 0, 40);
        $now = time();
        $this->db->execute("UPDATE comments SET comment = ?, modified_at = ?, account = ? WHERE id = ?", [$newText, $now, $account, $commentId]);
        if (isset($_SESSION['user_id'])) {
            $this->auditLog->logCommentUpdated($_SESSION['user_id'], $commentId, $existing, [ 'comment' => $newText ]);
        }
        return true;
    }

    /**
     * Delete a comment
     */
    public function deleteComment($commentId) {
        $existing = $this->db->fetch("SELECT * FROM comments WHERE id = ?", [$commentId]);
        if (!$existing) { throw new Exception('Comment not found'); }
        $this->db->execute("DELETE FROM comments WHERE id = ?", [$commentId]);
        if (isset($_SESSION['user_id'])) {
            $this->auditLog->logCommentDeleted($_SESSION['user_id'], $commentId, $existing);
        }
        return true;
    }

    /**
     * Get latest comment per (name,type) for a domain
     */
    public function getLatestCommentsForDomain($domainId) {
        $rows = $this->db->fetchAll(
            "SELECT c.name, c.type, c.comment, c.modified_at FROM comments c
             INNER JOIN (
               SELECT name, type, MAX(modified_at) AS max_mod
               FROM comments WHERE domain_id = ? GROUP BY name, type
             ) lm ON c.name = lm.name AND c.type = lm.type AND c.modified_at = lm.max_mod
             WHERE c.domain_id = ?",
            [$domainId, $domainId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['name'].'|'.$r['type']] = [
                'comment' => $r['comment'],
                'modified_at' => (int)$r['modified_at']
            ];
        }
        return $out;
    }
}
?>
