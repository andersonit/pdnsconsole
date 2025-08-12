<?php
/**
 * PDNS Console - Dynamic DNS API (ddclient compatible)
 *
 * Auth: token (+ optional secret) via HTTP Basic Auth
 * Path: /api/dynamic_dns.php
 * Methods: GET (query), POST (update)
 *
 * Security model:
 * - Each token is bound to a single records.id (A or AAAA) and domain_id (1:1 mapping)
 * - Only that record may be read/updated by the token
 * - Rate limiting: 3 requests per 3 minutes; if exceeded, throttle 10 minutes (429)
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Content negotiation: default JSON; ddclient prefers text/plain dyndns2-style
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$fmtParam = $_GET['format'] ?? $_POST['format'] ?? '';
$wantsPlain = stripos($ua, 'ddclient') !== false || stripos($accept, 'text/plain') !== false || $fmtParam === 'plain';
header('Content-Type: ' . ($wantsPlain ? 'text/plain' : 'application/json')); 

$db = Database::getInstance();
$audit = new AuditLog();

// Helper to send JSON response and exit
function ddns_resp($code, $payload, $plain = false) {
    http_response_code($code);
    if ($plain) {
        // Map payload to dyndns2 response tokens where possible
        $msg = '';
        if (!$payload['success']) {
            $err = strtolower((string)($payload['error'] ?? ''));
            if (strpos($err, 'unauthorized') !== false || strpos($err, 'forbidden') !== false) {
                $msg = 'badauth';
            } elseif (strpos($err, 'too many') !== false || strpos($err, 'rate') !== false) {
                $msg = 'abuse';
            } elseif (strpos($err, 'method') !== false) {
                $msg = 'badagent';
            } elseif (strpos($err, 'invalid ip') !== false) {
                $msg = 'numhost';
            } else {
                $msg = '911'; // server error
            }
        } else {
            $msg = ($payload['message'] ?? '') === 'unchanged' ? 'nochg' : 'good';
            if (!empty($payload['content'])) { $msg .= ' ' . $payload['content']; }
        }
        echo $msg;
    } else {
        echo json_encode($payload);
    }
    exit;
}

// Extract Basic auth credentials (token as username; optional secret as password)
$authUser = $_SERVER['PHP_AUTH_USER'] ?? '';
$authPass = $_SERVER['PHP_AUTH_PW'] ?? '';
$token = trim((string)$authUser);
$secret = (string)$authPass;

if ($token === '') {
    header('WWW-Authenticate: Basic realm="PDNS Console DDNS"');
    ddns_resp(401, ['success' => false, 'error' => 'Unauthorized'], $wantsPlain);
}

try {
    // Look up token
    $row = $db->fetch("SELECT t.*, r.type, r.name, r.content AS current_content, d.name AS domain_name
                       FROM dynamic_dns_tokens t
                       JOIN records r ON r.id = t.record_id
                       JOIN domains d ON d.id = t.domain_id
                       WHERE t.token = ?", [$token]);
    if (!$row || intval($row['is_active']) !== 1) {
        ddns_resp(403, ['success' => false, 'error' => 'Invalid or inactive token'], $wantsPlain);
    }
    // Expiry check
    if (!empty($row['expires_at'])) {
        $exp = new DateTimeImmutable($row['expires_at']);
        if (new DateTimeImmutable('now') > $exp) {
            ddns_resp(403, ['success' => false, 'error' => 'Token expired'], $wantsPlain);
        }
    }
    // Optional secret check
    if (!empty($row['secret_hash'])) {
        if ($secret === '' || !password_verify($secret, $row['secret_hash'])) {
            // Audit failed attempt (no user_id)
            $audit->logAction(null, 'DDNS_AUTH_FAILED', 'dynamic_dns_tokens', $row['id'], null, null, $_SERVER['REMOTE_ADDR'] ?? null, ['reason' => 'bad_secret']);
            ddns_resp(403, ['success' => false, 'error' => 'Forbidden'], $wantsPlain);
        }
    }

    // Enforce record type
    $allowedTypes = ['A','AAAA'];
    if (!in_array(strtoupper($row['type']), $allowedTypes, true)) {
        ddns_resp(400, ['success' => false, 'error' => 'Record type not supported for DDNS'], $wantsPlain);
    }

    // Rate limiting
    $now = new DateTimeImmutable('now');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!empty($row['throttle_until'])) {
        $until = new DateTimeImmutable($row['throttle_until']);
        if ($now < $until) {
            $retry = $until->getTimestamp() - $now->getTimestamp();
            header('Retry-After: ' . max(1, $retry));
            $audit->logAction(null, 'DDNS_RATE_LIMIT_HIT', 'dynamic_dns_tokens', $row['id'], null, null, $clientIp, ['until' => $until->format(DateTime::ATOM)]);
            ddns_resp(429, ['success' => false, 'error' => 'Too Many Requests'], $wantsPlain);
        }
    }

    // Sliding window: default 3 minutes
    $windowSeconds = 180; $burst = 3; $throttleSeconds = 600;
    $windowCount = (int)($row['window_count'] ?? 0);
    $resetAt = !empty($row['window_reset_at']) ? new DateTimeImmutable($row['window_reset_at']) : null;
    if (!$resetAt || $now >= $resetAt) {
        $windowCount = 0;
        $resetAt = $now->modify('+' . $windowSeconds . ' seconds');
    }
    $windowCount++;
    if ($windowCount > $burst) {
        $throttleUntil = $now->modify('+' . $throttleSeconds . ' seconds');
        $db->execute("UPDATE dynamic_dns_tokens SET window_count=?, window_reset_at=?, throttle_until=?, last_ip=?, last_used=NOW() WHERE id=?",
            [$windowCount, $resetAt->format('Y-m-d H:i:s'), $throttleUntil->format('Y-m-d H:i:s'), $clientIp, $row['id']]);
        $audit->logAction(null, 'DDNS_RATE_LIMIT_EXCEEDED', 'dynamic_dns_tokens', $row['id'], null, null, $clientIp, ['burst' => $burst]);
    header('Retry-After: ' . $throttleSeconds);
    ddns_resp(429, ['success' => false, 'error' => 'Too Many Requests'], $wantsPlain);
    } else {
        $db->execute("UPDATE dynamic_dns_tokens SET window_count=?, window_reset_at=?, last_ip=?, last_used=NOW() WHERE id=?",
            [$windowCount, $resetAt->format('Y-m-d H:i:s'), $clientIp, $row['id']]);
    }

    // Operations
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        // ddclient may use GET for updates: detect presence of ip/myip/hostname
        $hasUpdateParam = isset($_GET['ip']) || isset($_GET['myip']) || isset($_GET['hostname']) || isset($_GET['host']);
        if (!$hasUpdateParam) {
            ddns_resp(200, [
                'success' => true,
                'record_id' => (int)$row['record_id'],
                'domain' => $row['domain_name'],
                'name' => $row['name'],
                'type' => $row['type'],
                'content' => $row['current_content']
            ], $wantsPlain);
        }
        // Fall through to update flow below with $_GET params
        $_POST = array_merge($_POST, $_GET);
    }

    if ($method !== 'POST') {
        ddns_resp(405, ['success' => false, 'error' => 'Method not allowed'], $wantsPlain);
    }

    // Optional hostname check (when provided by client)
    $hostParam = trim((string)($_POST['hostname'] ?? $_POST['host'] ?? ''));
    if ($hostParam !== '') {
        $norm = function($h){ return rtrim(strtolower($h), '.'); };
        if ($norm($hostParam) !== $norm($row['name'])) {
            ddns_resp(400, ['success' => false, 'error' => 'Host mismatch for token'], $wantsPlain);
        }
    }

    // Get new IP from client (prefer explicit params, else remote address)
    $newIp = trim((string)($_POST['ip'] ?? $_POST['myip'] ?? ''));
    if ($newIp === '') {
        $newIp = $clientIp ?? '';
    }

    // Validate IP by type
    $isA = strtoupper($row['type']) === 'A';
    $ipValid = $isA ? filter_var($newIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) : filter_var($newIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    if (!$ipValid) {
        ddns_resp(400, ['success' => false, 'error' => 'Invalid IP for ' . $row['type']], $wantsPlain);
    }

    // No change short-circuit
    if ($newIp === $row['current_content']) {
        ddns_resp(200, ['success' => true, 'message' => 'unchanged', 'content' => $row['current_content']], $wantsPlain);
    }

    // Update record content
    $db->execute("UPDATE records SET content=? WHERE id=?", [$newIp, $row['record_id']]);

    // Bump SOA serial
    require_once __DIR__ . '/../classes/Records.php';
    $rec = new Records();
    $rec->updateDomainSerial((int)$row['domain_id']);

    // Audit success
    $audit->logAction(null, 'DDNS_UPDATE', 'records', (int)$row['record_id'], ['content' => $row['current_content']], ['content' => $newIp], $clientIp, ['domain_id' => (int)$row['domain_id']]);

    ddns_resp(200, ['success' => true, 'message' => 'updated', 'content' => $newIp], $wantsPlain);
} catch (Exception $e) {
    ddns_resp(400, ['success' => false, 'error' => $e->getMessage()], $wantsPlain);
}

?>
