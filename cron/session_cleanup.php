#!/usr/bin/env php
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
 * PDNS Console - Session Cleanup Script
 *
 * Purpose:
 *   Deterministic cleanup of expired or empty session rows in user_sessions,
 *   complementing opportunistic cleanup in DatabaseSessionHandler.
 *
 * Recommended cron frequency: every 15 minutes (adjust as needed).
 *
 * To schedule via cron, run this script periodically (e.g. every 15 minutes or hourly).
 * Example command target:
 *   php /path/to/app/cron/session_cleanup.php
 *
 * Notes:
 *   - Ensure /var/log/pdnsconsole exists & writable (or adjust path).
 *   - Script is idempotent; concurrent runs are safe.
 *   - Honors session.gc_maxlifetime; adjusts if unset.
 */

// Bootstrap application (adjust path if needed)
$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/webroot/classes/Database.php';

$start = microtime(true);
$deletedExpired = 0;
$deletedEmpty = 0;

try {
    $db = Database::getInstance();

    // Determine lifetimes
    $maxLifetime = (int) ini_get('session.gc_maxlifetime');
    if ($maxLifetime <= 0) {
        $maxLifetime = 1440; // fallback 24 min
    }
    $emptyGrace = 600; // 10 minutes

    $now = time();
    $expiredCutoff = date('Y-m-d H:i:s', $now - $maxLifetime);
    $emptyCutoff   = date('Y-m-d H:i:s', $now - $emptyGrace);

    // Delete expired sessions
    $deletedExpired = $db->execute(
        "DELETE FROM user_sessions WHERE last_activity < ? OR expires_at < NOW()",
        [$expiredCutoff]
    );

    // Delete empty / never-populated sessions older than grace period
    $deletedEmpty = $db->execute(
        "DELETE FROM user_sessions WHERE (session_data IS NULL OR session_data = '') AND last_activity < ?",
        [$emptyCutoff]
    );

    $durationMs = number_format((microtime(true) - $start) * 1000, 2);

    echo '[' . date('Y-m-d H:i:s') . "] Session cleanup complete | expired={$deletedExpired} empty={$deletedEmpty} duration_ms={$durationMs}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Session cleanup FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
