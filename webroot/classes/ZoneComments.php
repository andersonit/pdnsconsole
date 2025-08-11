<?php
/**
 * PDNS Console - ZoneComments Class
 *
 * Wraps PowerDNS native comments table for zone-level comments.
 * Conventional usage: store zone-wide notes with name set to the zone apex and type 'SOA' or a fixed pseudo-type 'INFO'.
 */
class ZoneComments {
    private $db;
    private $domain;
    private $auditLog;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->domain = new Domain();
        $this->auditLog = new AuditLog();
    }

    /** List all zone-level comments for a domain */
    public function getComment($domainId, $tenantId = null) {
        $d = $this->domain->getDomainById($domainId, $tenantId);
        if (!$d) { throw new Exception('Domain access denied'); }
        return $this->db->fetch("SELECT id, domain_id, name, type, comment, account, modified_at FROM comments WHERE domain_id = ? AND name = ? AND type = 'INFO' ORDER BY modified_at DESC, id DESC LIMIT 1", [$domainId, $d['name']]);
    }

    /** Add a zone-level comment */
    public function setComment($domainId, $userAccount, $text, $tenantId = null) {
        $d = $this->domain->getDomainById($domainId, $tenantId);
        if (!$d) { throw new Exception('Domain access denied'); }
        if (trim($text)==='') { throw new Exception('Comment required'); }
        if (mb_strlen($text) > 2000) { throw new Exception('Comment too long'); }
        $existing = $this->getComment($domainId, $tenantId);
        $now = time(); $account = substr($userAccount ?? 'user',0,40);
        if ($existing) {
            $this->db->execute("UPDATE comments SET comment = ?, modified_at = ?, account = ? WHERE id = ?", [$text,$now,$account,$existing['id']]);
            if (isset($_SESSION['user_id'])) { $this->auditLog->logCommentUpdated($_SESSION['user_id'], $existing['id'], $existing, ['comment'=>$text], ['scope'=>'zone']); }
            return $existing['id'];
        } else {
            $this->db->execute("INSERT INTO comments (domain_id, name, type, modified_at, account, comment) VALUES (?,?,?,?,?,?)", [$domainId, $d['name'], 'INFO', $now, $account, $text]);
            $id = $this->db->getConnection()->lastInsertId();
            if (isset($_SESSION['user_id'])) { $this->auditLog->logCommentCreated($_SESSION['user_id'], $id, ['domain_id'=>$domainId,'name'=>$d['name'],'type'=>'INFO','comment'=>$text], ['scope'=>'zone']); }
            return $id;
        }
    }

    /** Update a zone comment */
    public function updateComment($commentId, $userAccount, $newText) { return $this->setComment($commentId, $userAccount, $newText); }

    /** Delete */
    public function clearComment($domainId) {
        $existing = $this->getComment($domainId);
        if ($existing) {
            $this->db->execute("DELETE FROM comments WHERE id = ?", [$existing['id']]);
            if (isset($_SESSION['user_id'])) { $this->auditLog->logCommentDeleted($_SESSION['user_id'], $existing['id'], $existing, ['scope'=>'zone']); }
        }
        return true;
    }

    /** Latest zone comment */
    public function getLatest($domainId) {
        $d = $this->domain->getDomainById($domainId);
        if (!$d) { return null; }
        $row = $this->db->fetch("SELECT comment, modified_at FROM comments WHERE domain_id = ? AND name = ? AND type IN ('SOA','INFO') ORDER BY modified_at DESC, id DESC LIMIT 1", [$domainId, $d['name']]);
        return $row ?: null;
    }
}
?>
