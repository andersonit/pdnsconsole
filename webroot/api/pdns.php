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
    try {
        $client = new PdnsApiClient();
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
            $ok = $client->testConnection();
            $response['success'] = $ok;
            $response['message'] = $ok ? 'Connection successful' : 'Connection failed';
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
            $payload = [ 'keytype' => strtolower($keytype) === 'ksk' ? 'ksk' : 'zsk', 'active' => true, 'algorithm' => $algo, 'bits' => $bits ];
            $newKey = $client->createKey($zone, $payload);
            $audit->logDNSSECKeyGenerated($userId, null, $newKey, ['zone' => $zone]);
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
            $audit->logDNSSECKeyGenerated($userId, null, ['id' => $keyId, 'active' => $active], ['zone' => $zone, 'action' => 'toggle']);
            break;

        case 'delete_key':
            $zone = trim($_POST['zone'] ?? '');
            $keyId = $_POST['key_id'] ?? '';
            if (!$zone || !$keyId) { throw new Exception('Zone and key id required'); }
            $client->deleteKey($zone, $keyId);
            $response['success'] = true;
            $audit->logDNSSECKeyGenerated($userId, null, ['id' => $keyId], ['zone' => $zone, 'action' => 'delete']);
            break;

        case 'rectify_zone':
            $zone = trim($_POST['zone'] ?? '');
            if (!$zone) { throw new Exception('Zone required'); }
            $client->rectifyZone($zone);
            $response['success'] = true;
            $audit->logAction($userId, 'DNSSEC_RECTIFY', 'domains', null, null, null, null, ['zone' => $zone]);
            break;

        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    http_response_code(400);
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
