<?php
/**
 * Database Session Handler for PDNS Console
 * 
 * Implements PHP SessionHandlerInterface to store sessions in database
 */
class DatabaseSessionHandler implements SessionHandlerInterface {
    private $db;
    private $maxLifetime;
    private $emptySessionGrace; // seconds
    private $minEmptyRetention; // seconds safeguard window for new sessions
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->maxLifetime = (int) ini_get('session.gc_maxlifetime') ?: 1440; // default 24 min
        // How long we tolerate empty session rows before culling (user never stored anything)
    $this->emptySessionGrace = 1800; // 30 minutes before culling long-lived empty shells
    $this->minEmptyRetention = 300;  // Always retain empty sessions for at least 5 minutes
    }
    
    public function open($save_path, $session_name): bool {
        // Opportunistic lightweight cleanup (very low probability to avoid load)
    // Avoid aggressive cleanup at open; rely mostly on gc/cron & write-phase
        return true;
    }
    
    public function close(): bool {
        return true;
    }
    
    public function read($session_id): string {
        // Use database-side interval to avoid PHP/DB timezone drift issues
        $lifetime = (int)$this->maxLifetime;
        $sql = "SELECT session_data FROM user_sessions WHERE session_id = ? AND last_accessed > (NOW() - INTERVAL $lifetime SECOND)";
        $session = $this->db->fetch($sql, [$session_id]);
        if ($session) {
            $this->db->execute(
                "UPDATE user_sessions SET last_accessed = NOW() WHERE session_id = ?",
                [$session_id]
            );
            $data = $session['session_data'] ?? '';
            return $data;
        }
        return '';
    }
    
    public function write($session_id, $session_data): bool {
        // If session data is empty, we still persist briefly so concurrent early writes can attach,
        // but we rely on cleanup to purge long-lived empty shells.
        $result = $this->db->execute(
            "REPLACE INTO user_sessions (session_id, session_data, last_accessed) VALUES (?, ?, NOW())",
            [$session_id, $session_data]
        );
        // Opportunistic cleanup with slightly higher probability on write
        // Light probabilistic cleanup (reduced frequency)
        if (random_int(1, 1000) === 1) { // 0.1%
            $this->cleanupEmptySessions();
        }
        return (bool)$result;
    }
    
    public function destroy($session_id): bool {
        return $this->db->execute(
            "DELETE FROM user_sessions WHERE session_id = ?",
            [$session_id]
        );
    }
    
    public function gc($maxlifetime): int {
        // Use provided $maxlifetime (PHP passes ini value) but fall back to internal if zero
        $lifetime = $maxlifetime ?: $this->maxLifetime;
        $expiredCutoff = date('Y-m-d H:i:s', time() - $lifetime);
        $expired = $this->db->execute(
            "DELETE FROM user_sessions WHERE last_accessed < ?",
            [$expiredCutoff]
        );
        // Also clear stale empty sessions older than grace (defense in depth)
        $emptiesCutoff = date('Y-m-d H:i:s', time() - $this->emptySessionGrace);
        $empties = $this->db->execute(
            "DELETE FROM user_sessions WHERE (session_data IS NULL OR session_data = '') AND last_accessed < ?",
            [$emptiesCutoff]
        );
        return $expired + $empties;
    }

    /**
     * Targeted removal of empty / abandoned session shells
     */
    private function cleanupEmptySessions(): void {
    $now = time();
    $cutoff = date('Y-m-d H:i:s', $now - $this->emptySessionGrace);
    $recentProtectionCutoff = date('Y-m-d H:i:s', $now - $this->minEmptyRetention);
        try {
            $this->db->execute(
        "DELETE FROM user_sessions WHERE (session_data IS NULL OR session_data = '') AND last_accessed < ? AND last_accessed < ?",
        [$cutoff, $recentProtectionCutoff]
            );
        } catch (Exception $e) {
            // Suppress errors here; not critical path
        }
    }
}
?>
