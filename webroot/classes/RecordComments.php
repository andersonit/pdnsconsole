<?php
/**
 * PDNS Console - RecordComments Class
 *
 * Handles CRUD for the new record_comments table (per DNS record comments).
 */
class RecordComments {
    private $db;
    private $records;
    private $auditLog;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->records = new Records();
        $this->auditLog = new AuditLog();
    }

    /** Get comments for a specific record (new table) */
    public function getCommentForRecord($recordId, $tenantId = null) {
        $record = $this->records->getRecordById($recordId, $tenantId);
        if (!$record) { throw new Exception('Record not found'); }
        return $this->db->fetch("SELECT rc.id, rc.record_id, rc.domain_id, rc.comment, rc.username, rc.user_id, UNIX_TIMESTAMP(rc.created_at) as created_ts, UNIX_TIMESTAMP(rc.updated_at) as updated_ts FROM record_comments rc WHERE rc.record_id = ? LIMIT 1", [$recordId]);
    }

    /** Add comment */
    public function setComment($recordId, $userId, $username, $text, $tenantId = null) {
        if (trim($text) === '') { throw new Exception('Comment required'); }
        if (mb_strlen($text) > 2000) { throw new Exception('Comment too long'); }
        $record = $this->records->getRecordById($recordId, $tenantId);
        if (!$record) { throw new Exception('Record not found'); }
        $existing = $this->getCommentForRecord($recordId, $tenantId);
        if ($existing) {
            $this->db->execute("UPDATE record_comments SET comment = ?, username = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$text, substr($username,0,100), $existing['id']]);
            if ($userId) { $this->auditLog->logCommentUpdated($userId, $existing['id'], $existing, ['comment'=>$text], ['scope'=>'record']); }
            return $existing['id'];
        } else {
            $this->db->execute("INSERT INTO record_comments (record_id, domain_id, user_id, username, comment) VALUES (?,?,?,?,?)", [$recordId, $record['domain_id'], $userId, substr($username,0,100), $text]);
            $id = $this->db->getConnection()->lastInsertId();
            if ($userId) { $this->auditLog->logCommentCreated($userId, $id, ['record_id'=>$recordId,'domain_id'=>$record['domain_id'],'comment'=>$text], ['scope'=>'record']); }
            return $id;
        }
    }

    /** Update comment */
    // Kept for compatibility if needed
    public function updateComment($commentId, $userId, $username, $text) { return $this->setComment($commentId, $userId, $username, $text); }

    /** Delete comment */
    public function clearComment($recordId, $userId = null) {
        $existing = $this->getCommentForRecord($recordId);
        if ($existing) {
            $this->db->execute("DELETE FROM record_comments WHERE id = ?", [$existing['id']]);
            if ($userId) { $this->auditLog->logCommentDeleted($userId, $existing['id'], $existing, ['scope'=>'record']); }
        }
        return true;
    }

    /** Comment counts keyed by record_id */
    public function getCountsForDomain($domainId) {
        // Since only one comment per record, a simple map of record_id=>1
        $rows = $this->db->fetchAll("SELECT record_id FROM record_comments WHERE domain_id = ?", [$domainId]);
        $out = [];
        foreach ($rows as $r) { $out[(int)$r['record_id']] = 1; }
        return $out;
    }

    /** Latest comment per record */
    public function getLatestForDomain($domainId) { // now just fetch single per record
        $rows = $this->db->fetchAll("SELECT record_id, comment, UNIX_TIMESTAMP(updated_at) as updated_ts FROM record_comments WHERE domain_id = ?", [$domainId]);
        $out = [];
        foreach ($rows as $r) { $out[(int)$r['record_id']] = ['comment'=>$r['comment'],'updated_ts'=>(int)$r['updated_ts']]; }
        return $out;
    }
}
?>
