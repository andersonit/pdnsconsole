<?php
/**
 * PDNS Console - PowerDNS API endpoints (AJAX)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
// Explicitly include client (not autoloaded by composer)
require_once __DIR__ . '/../classes/PdnsApiClient.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$response = ['success' => false];

try {
    $client = null;
    // For test_connection, allow overriding connection params from POST (unsaved form values)
    try {
        if ($action === 'test_connection' && (isset($_POST['pdns_api_host']) || isset($_POST['pdns_api_key']) || isset($_POST['pdns_api_port']) || isset($_POST['pdns_api_server_id']))) {
            $host = trim((string)($_POST['pdns_api_host'] ?? ''));
            $port = trim((string)($_POST['pdns_api_port'] ?? '8081'));
            $serverId = trim((string)($_POST['pdns_api_server_id'] ?? 'localhost'));
            $keyPlain = (string)($_POST['pdns_api_key'] ?? '');
            if ($host === '' || $keyPlain === '') {
                echo json_encode(['success' => false, 'error' => 'Host and API key are required to test.']);
                exit;
            }
            $client = new PdnsApiClient($host, $port, null, $keyPlain);
            $client->setServerId($serverId ?: 'localhost');
            $client->setConnectTimeout(1);
            $client->setTimeout(2);
        } else {
            $client = new PdnsApiClient();
        }
    } catch (Exception $initEx) {
        if ($action === 'test_connection') {
            echo json_encode([
                'success' => false,
                'error' => 'PowerDNS API not configured: ' . $initEx->getMessage()
            ]);
            exit;
        }
        throw $initEx; // propagate for non test actions
    }
    $settings = new Settings();
    $audit = new AuditLog();
    $userId = $_SESSION['user_id'];

    switch ($action) {
        case 'test_connection':
            // Prefer a direct server info call for latency and version
            $t0 = microtime(true);
            try {
                $info = $client->getServerInfo();
                $t1 = microtime(true);
                if (is_array($info)) {
                    $response['success'] = true;
                    $response['message'] = 'Connection successful';
                    $response['latency_ms'] = (int)round(($t1 - $t0) * 1000);
                    $response['version'] = $info['version'] ?? ($info['daemon_version'] ?? null);
                } else {
                    $response['success'] = false;
                    $response['error'] = 'Unexpected response from server';
                }
            } catch (Exception $e) {
                $response['success'] = false;
                $response['error'] = $e->getMessage();
            }
            break;

    case 'dnssec_status':
            $zone = trim($_POST['zone'] ?? '');
            if (!$zone) { throw new Exception('Zone required'); }
            $data = $client->getZone($zone);
            $response['success'] = true;
            $response['data'] = [
                'dnssec' => $data['dnssec'] ?? false,
                'serial' => $data['serial'] ?? null,
        'nsec3param' => $data['nsec3param'] ?? null,
        'server_id' => $data['server_id'] ?? null
            ];
            break;

        case 'enable_dnssec':
            $zone = trim($_POST['zone'] ?? '');
            if (!$zone) { throw new Exception('Zone required'); }
            $client->enableDnssec($zone);
            $audit->logDNSSECEnabled($userId, null, ['zone' => $zone]);
            $response['success'] = true;
            break;

        case 'disable_dnssec':
            $zone = trim($_POST['zone'] ?? '');
            if (!$zone) { throw new Exception('Zone required'); }
            $client->disableDnssec($zone);
            $audit->logDNSSECDisabled($userId, null, ['zone' => $zone]);
            $response['success'] = true;
            break;

        case 'list_keys':
            $zone = trim($_POST['zone'] ?? '');
            if (!$zone) { throw new Exception('Zone required'); }
            $keys = $client->listKeys($zone);
            $response['success'] = true;
            $response['keys'] = $keys;
            $response['ds'] = $client->getDsRecords($zone);
            break;

    case 'create_key':
            $zone = trim($_POST['zone'] ?? '');
            if (!$zone) { throw new Exception('Zone required'); }
            $keytype = $_POST['keytype'] ?? 'zsK';
            $algo = $_POST['algorithm'] ?? 'RSASHA256';
            $bits = (int)($_POST['bits'] ?? 2048);
            $mode = $_POST['rollover_mode'] ?? 'add';
            $holdDays = (int)($_POST['hold_days'] ?? 7);
            $normType = in_array(strtolower($keytype), ['ksk','zsk','csk']) ? strtolower($keytype) : 'zsk';
            $payload = [ 'keytype' => $normType, 'active' => true, 'algorithm' => $algo ];
            if (stripos($algo, 'RSA') === 0) { $payload['bits'] = $bits; }
            $newKey = $client->createKey($zone, $payload);
            // Explicit audit event for key create
            $audit->logAction($userId, 'DNSSEC_KEY_CREATE', 'cryptokeys', $newKey['id'] ?? null, null, $newKey, null, ['zone' => $zone, 'mode' => $mode]);

            // Immediate replace: deactivate older active keys of same keytype+algorithm
        if ($mode === 'immediate') {
                try {
                    $existing = $client->listKeys($zone);
                    if (is_array($existing)) {
                        foreach ($existing as $k) {
                            if ($k['id'] != $newKey['id'] && ($k['keytype'] ?? '') === $newKey['keytype'] && ($k['algorithm'] ?? '') === $newKey['algorithm'] && !empty($k['active'])) {
                                $client->setKeyActive($zone, $k['id'], false);
                $audit->logAction($userId, 'DNSSEC_KEY_DEACTIVATE', 'cryptokeys', $k['id'], null, ['id'=>$k['id'],'active'=>false], null, ['zone' => $zone, 'reason' => 'immediate_replace']);
                            }
                        }
                    }
                } catch (Exception $eDeactivate) {
                    // Non-fatal
                }
            } elseif ($mode === 'timed') {
                // Record rollover start in domainmetadata so cron script can finalize later
                try {
                    $db = Database::getInstance();
                    // Determine domain_id for metadata (strip trailing dot both sides)
                    $dRow = $db->fetch("SELECT id FROM domains WHERE name = ? OR name = ?", [rtrim($zone,'.'), rtrim($zone,'.').'.']);
                    if ($dRow) {
                        $exists = $db->fetch("SELECT id FROM domainmetadata WHERE domain_id=? AND kind='PDNSCONSOLE-ROLLSTART'", [$dRow['id']]);
                        if ($exists) {
                            $db->execute("UPDATE domainmetadata SET content=NOW() WHERE id=?", [$exists['id']]);
                        } else {
                            $db->execute("INSERT INTO domainmetadata (domain_id, kind, content) VALUES (?,?,NOW())", [$dRow['id'], 'PDNSCONSOLE-ROLLSTART']);
                        }
            $audit->logAction($userId, 'DNSSEC_KEY_ROLLOVER_START', 'domains', $dRow['id'], null, null, null, ['zone' => $zone, 'hold_days_effective' => $holdDays]);
                    }
                } catch (Exception $eMeta) { /* ignore */ }
            }
            $response['success'] = true;
            $response['key'] = $newKey;
            break;

        case 'toggle_key':
            $zone = trim($_POST['zone'] ?? '');
            $keyId = $_POST['key_id'] ?? '';
            $active = ($_POST['active'] ?? '1') === '1';
            if (!$zone || !$keyId) { throw new Exception('Zone and key id required'); }
            $client->setKeyActive($zone, $keyId, $active);
            $response['success'] = true;
            $audit->logAction($userId, $active ? 'DNSSEC_KEY_ACTIVATE' : 'DNSSEC_KEY_DEACTIVATE', 'cryptokeys', $keyId, null, ['id'=>$keyId,'active'=>$active], null, ['zone'=>$zone, 'manual'=>true]);
            break;

        case 'delete_key':
            $zone = trim($_POST['zone'] ?? '');
            $keyId = $_POST['key_id'] ?? '';
            if (!$zone || !$keyId) { throw new Exception('Zone and key id required'); }
            $client->deleteKey($zone, $keyId);
            $response['success'] = true;
            $audit->logAction($userId, 'DNSSEC_KEY_DELETE', 'cryptokeys', $keyId, null, ['id'=>$keyId], null, ['zone'=>$zone, 'manual'=>true]);
            break;

        case 'rectify_zone':
            $zone = trim($_POST['zone'] ?? '');
            if (!$zone) { throw new Exception('Zone required'); }
            $client->rectifyZone($zone);
            $response['success'] = true;
            $audit->logAction($userId, 'DNSSEC_RECTIFY', 'domains', null, null, null, null, ['zone' => $zone]);
            break;

        case 'check_parent_ds':
            $zone = trim($_POST['zone'] ?? '');
            if (!$zone) { throw new Exception('Zone required'); }
            // Normalize zone (strip trailing dot for query)
            $qDomain = rtrim($zone, '.');
            // Query local DS from PowerDNS for comparison
            $localDs = [];
            try { $localDs = $client->getDsRecords($zone); } catch (Exception $eDs) { /* ignore */ }
            // Perform DNS query using Net_DNS2
            if (!class_exists('Net_DNS2_Resolver')) { throw new Exception('Net_DNS2 not installed'); }
            $resolver = new Net_DNS2_Resolver([
                'recurse' => true,
                'use_tcp' => false,
                'timeout' => 3.0,
            ]);
            $parentDs = [];
            $error = null;
            try {
                $resp = $resolver->query($qDomain, 'DS');
                if (!empty($resp->answer)) {
                    foreach ($resp->answer as $rr) {
                        if (strcasecmp($rr->qtype, 'DS') === 0 || $rr->type === 'DS') {
                            // Format: keytag algorithm digest_type digest
                            $parentDs[] = trim($rr->keytag . ' ' . $rr->algorithm . ' ' . $rr->digest_type . ' ' . strtoupper($rr->digest));
                        }
                    }
                }
            } catch (Exception $eQuery) {
                $error = $eQuery->getMessage();
            }
            // Compare sets (case-insensitive)
            $norm = function($arr){ $o=[]; foreach($arr as $d){ $o[strtoupper(preg_replace('/\s+/', ' ', trim($d)))] = true; } return $o; };
            $localNorm = $norm($localDs);
            $parentNorm = $norm($parentDs);
            $allKeys = array_unique(array_merge(array_keys($localNorm), array_keys($parentNorm)));
            sort($allKeys);
            $per = [];
            $publishedCount = 0; $missingCount = 0; $extraCount = 0;
            foreach($allKeys as $k){
                $inLocal = isset($localNorm[$k]);
                $inParent = isset($parentNorm[$k]);
                $status = $inLocal && $inParent ? 'published' : ($inLocal && !$inParent ? 'missing' : (!$inLocal && $inParent ? 'extra' : 'unknown'));
                if($status==='published') $publishedCount++; elseif($status==='missing') $missingCount++; elseif($status==='extra') $extraCount++;
                $per[] = ['ds'=>$k, 'status'=>$status];
            }
            $match = 'none';
            if ($publishedCount && $missingCount===0 && $extraCount===0 && count($localNorm)===count($parentNorm)) { $match='full'; }
            elseif ($publishedCount>0 || $extraCount>0 || $missingCount>0) { $match='partial'; }
            // Cache result in domainmetadata
            try {
                $db = Database::getInstance();
                $dRow = $db->fetch("SELECT id FROM domains WHERE name=? OR name=?", [$qDomain, $qDomain.'.']);
                if ($dRow) {
                    $checkedAtRaw = date('c');
                    $checkedAtDisplay = date('Y-m-d, H:i:s');
                    $payload = json_encode([
                        'checked_at' => $checkedAtRaw,
                        'checked_at_display' => $checkedAtDisplay,
                        'match' => $match,
                        'local_count' => count($localNorm),
                        'parent_count' => count($parentNorm),
                        'published' => $publishedCount,
                        'missing' => $missingCount,
                        'extra' => $extraCount,
                        'per' => $per
                    ], JSON_UNESCAPED_SLASHES);
                    $existing = $db->fetch("SELECT id FROM domainmetadata WHERE domain_id=? AND kind='PDNSCONSOLE-DSCHECK'", [$dRow['id']]);
                    if ($existing) { $db->execute("UPDATE domainmetadata SET content=? WHERE id=?", [$payload, $existing['id']]); }
                    else { $db->execute("INSERT INTO domainmetadata (domain_id, kind, content) VALUES (?,?,?)", [$dRow['id'], 'PDNSCONSOLE-DSCHECK', $payload]); }
                    $response['checked_at'] = $checkedAtRaw;
                    $response['checked_at_display'] = $checkedAtDisplay;
                } else {
                    $response['checked_at'] = date('c');
                    $response['checked_at_display'] = date('Y-m-d, H:i:s');
                }
            } catch (Exception $eCache) { /* ignore cache errors */ }
            $response['success'] = true;
            $response['parent_ds'] = $parentDs;
            $response['local_ds'] = $localDs;
            $response['comparison'] = [
                'match' => $match,
                'published' => $publishedCount,
                'missing' => $missingCount,
                'extra' => $extraCount,
                'details' => $per
            ];
            if ($error) { $response['warning'] = $error; }
            break;

        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    http_response_code(400);
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
