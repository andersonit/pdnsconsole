<?php
/**
 * PDNS Console - PowerDNS API Client
 * 
 * Lightweight wrapper around the PowerDNS Authoritative Server HTTP API.
 * Focused on endpoints needed for DNSSEC management and future dynamic features.
 */

class PdnsApiClient {
    private $host;
    private $port;
    private $apiKey; // decrypted key
    private $baseUrl;
    private $timeout = 10;
    private $connectTimeout = 5;
    private $serverId;
    private $lastResponseInfo = [];

    public function __construct($host = null, $port = null, $apiKeyEnc = null, $apiKeyPlain = null) {
        $settings = new Settings();
        $enc = new Encryption();

        $this->host = $host ?: $settings->get('pdns_api_host');
        $this->port = $port ?: $settings->get('pdns_api_port', '8081');
        $encKey = $apiKeyEnc ?: $settings->get('pdns_api_key_enc');
        $this->apiKey = $apiKeyPlain ?: ($encKey ? $enc->decrypt($encKey) : null);
    // PowerDNS server-id (from pdns.conf 'server-id' / API path segment). Default 'localhost'.
    $this->serverId = $settings->get('pdns_api_server_id', 'localhost') ?: 'localhost';

        if (!$this->host || !$this->apiKey) {
            throw new Exception('PowerDNS API not configured');
        }

        $scheme = preg_match('/^https?:/i', $this->host) ? '' : 'http://';
        $this->baseUrl = rtrim($scheme . $this->host, '/') . ':' . $this->port . '/api/v1';
    }

    public function setTimeout($seconds) { $this->timeout = max(1, (int)$seconds); }
    public function setConnectTimeout($seconds) { $this->connectTimeout = max(1, (int)$seconds); }
    public function setServerId($serverId) { $this->serverId = $serverId ?: 'localhost'; }
    public function getLastResponseInfo() { return $this->lastResponseInfo; }

    /**
     * Basic connectivity test (GET /servers)
     */
    public function testConnection() {
        try {
            $resp = $this->request('GET', '/servers');
            return is_array($resp) && !empty($resp);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get server info for the configured server-id (includes version)
     * @return array|null
     */
    public function getServerInfo() {
        try {
            $path = "/servers/{$this->serverId}";
            $resp = $this->request('GET', $path);
            return is_array($resp) ? $resp : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get zone details including DNSSEC status
     */
    public function getZone($zoneName) {
        return $this->request('GET', "/servers/{$this->serverId}/zones/" . urlencode($zoneName));
    }

    /**
     * List all zones (may be large on big installations)
     */
    public function listZones() {
        return $this->request('GET', "/servers/{$this->serverId}/zones");
    }

    /**
     * Enable DNSSEC on a zone (PATCH zone with dnssec: true)
     */
    public function enableDnssec($zoneName) {
        $path = "/servers/{$this->serverId}/zones/" . urlencode($zoneName);
        $payload = [ 'dnssec' => true ];
        try {
            return $this->request('PATCH', $path, $payload);
        } catch (Exception $e) {
            // PowerDNS versions prior to certain releases require a full PUT for dnssec toggle and reject PATCH with 422
            if (strpos($e->getMessage(), '422') !== false) {
                $zone = $this->getZone($zoneName);
                if (!is_array($zone)) { throw $e; }
                $putPayload = [
                    'name' => $zone['name'] ?? $zoneName,
                    'kind' => $zone['kind'] ?? 'Native',
                    'masters' => $zone['masters'] ?? [],
                    'nameservers' => $zone['nameservers'] ?? [],
                    'dnssec' => true
                ];
                // Include optional fields only if present to avoid 422 validation errors
                foreach (['account','soa_edit_api','soa_edit'] as $opt) {
                    if (isset($zone[$opt])) { $putPayload[$opt] = $zone[$opt]; }
                }
                return $this->request('PUT', $path, $putPayload);
            }
            throw $e;
        }
    }

    /**
     * Disable DNSSEC on a zone (PATCH zone with dnssec: false)
     */
    public function disableDnssec($zoneName) {
        $path = "/servers/{$this->serverId}/zones/" . urlencode($zoneName);
        $payload = [ 'dnssec' => false ];
        try {
            return $this->request('PATCH', $path, $payload);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '422') !== false) {
                $zone = $this->getZone($zoneName);
                if (!is_array($zone)) { throw $e; }
                $putPayload = [
                    'name' => $zone['name'] ?? $zoneName,
                    'kind' => $zone['kind'] ?? 'Native',
                    'masters' => $zone['masters'] ?? [],
                    'nameservers' => $zone['nameservers'] ?? [],
                    'dnssec' => false
                ];
                foreach (['account','soa_edit_api','soa_edit'] as $opt) {
                    if (isset($zone[$opt])) { $putPayload[$opt] = $zone[$opt]; }
                }
                return $this->request('PUT', $path, $putPayload);
            }
            throw $e;
        }
    }

    /**
     * List cryptographic keys for a zone
     */
    public function listKeys($zoneName) {
        return $this->request('GET', "/servers/{$this->serverId}/zones/" . urlencode($zoneName) . '/cryptokeys');
    }

    /**
     * Create a cryptokey (PowerDNS will generate)
     * $params example: [ 'keytype' => 'ksk', 'active' => true, 'bits' => 2048, 'algorithm' => 'RSASHA256' ]
     */
    public function createKey($zoneName, $params = []) {
        return $this->request('POST', "/servers/{$this->serverId}/zones/" . urlencode($zoneName) . '/cryptokeys', $params);
    }

    /**
     * Activate/Deactivate a key
     */
    public function setKeyActive($zoneName, $keyId, $active = true) {
        $payload = ['active' => (bool)$active];
        return $this->request('PUT', "/servers/{$this->serverId}/zones/" . urlencode($zoneName) . '/cryptokeys/' . $keyId, $payload);
    }

    /**
     * Delete a key
     */
    public function deleteKey($zoneName, $keyId) {
        return $this->request('DELETE', "/servers/{$this->serverId}/zones/" . urlencode($zoneName) . '/cryptokeys/' . $keyId);
    }

    /**
     * Get DS records for a key (PowerDNS returns ds field when listing keys)
     */
    public function getDsRecords($zoneName) {
        $keys = $this->listKeys($zoneName);
        $ds = [];
        if (is_array($keys)) {
            foreach ($keys as $k) {
                if (!empty($k['ds'])) {
                    foreach ((array)$k['ds'] as $dsItem) { $ds[] = $dsItem; }
                }
            }
        }
        return $ds;
    }

    /**
     * Rectify zone (POST /servers/:id/zones/:zone/rectify)
     */
    public function rectifyZone($zoneName) {
        return $this->request('PUT', "/servers/{$this->serverId}/zones/" . urlencode($zoneName) . '/rectify');
    }

    /**
     * Internal request helper
     */
    private function request($method, $path, $payload = null) {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json'
        ];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // Prefer IPv4 to avoid slow IPv6 fallbacks on misconfigured networks
        if (defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }

        if ($payload !== null) {
            $json = json_encode($payload);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);

        $this->lastResponseInfo = [
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'error' => $err
        ];

        if ($responseBody === false) {
            throw new Exception('HTTP request failed: ' . $err);
        }

        $decoded = null;
        if ($contentType && stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($responseBody, true);
        }

        if ($httpCode >= 400) {
            $msg = 'PowerDNS API error ' . $httpCode;
            if (is_array($decoded) && isset($decoded['error'])) { $msg .= ' - ' . $decoded['error']; }
            throw new Exception($msg);
        }

        return $decoded !== null ? $decoded : $responseBody;
    }
}
