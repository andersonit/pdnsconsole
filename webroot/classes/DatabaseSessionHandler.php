<?php
/**
 * Database Session Handler for PDNS Console
 * 
 * Implements PHP SessionHandlerInterface to store sessions in database
 */
class DatabaseSessionHandler implements SessionHandlerInterface {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function open($save_path, $session_name): bool {
        return true;
    }
    
    public function close(): bool {
        return true;
    }
    
    public function read($session_id): string {
        $session = $this->db->fetch(
            "SELECT session_data FROM user_sessions 
             WHERE session_id = ? AND (last_accessed IS NULL OR last_accessed > DATE_SUB(NOW(), INTERVAL 7200 SECOND))",
            [$session_id]
        );
        
        if ($session) {
            // Update last_accessed timestamp
            $this->db->execute(
                "UPDATE user_sessions SET last_accessed = NOW() WHERE session_id = ?",
                [$session_id]
            );
            
            return $session['session_data'];
        }
        return '';
    }
    
    public function write($session_id, $session_data): bool {
        return $this->db->execute(
            "REPLACE INTO user_sessions (session_id, session_data, last_accessed) VALUES (?, ?, NOW())",
            [$session_id, $session_data]
        );
    }
    
    public function destroy($session_id): bool {
        return $this->db->execute(
            "DELETE FROM user_sessions WHERE session_id = ?",
            [$session_id]
        );
    }
    
    public function gc($maxlifetime): int {
        $this->db->execute(
            "DELETE FROM user_sessions WHERE last_accessed < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$maxlifetime]
        );
        // Return 0 for now - could be improved to return actual count if needed
        return 0;
    }
}
?>
