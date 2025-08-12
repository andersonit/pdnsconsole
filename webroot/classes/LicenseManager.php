<?php
/**
 * PDNS Console - Lightweight License Manager
 *
 * Validates offline license key stored in global_settings (setting_key = 'license_key').
 *
 * License key format: PDNS-TYPE-BASE64(JSON)-HEX_SIGNATURE
 * JSON payload: { email, type, domains, issued }
 *   type: free|commercial
 *   domains: 5 for free, 0 for unlimited commercial, or explicit int limit
 */
class LicenseManager {
    private static $cache;            // cached status array
    private static $cacheTime = 0;    // timestamp of cache
    private const CACHE_TTL = 1800;   // 30 min
    private static $integrityFailed = false; // public key integrity flag
    private static $pubKeyMissing = false;   // external public key missing

    private static function seedQ(): int {
        static $limit = null;
        if ($limit !== null) return $limit;
        // Obfuscated derivation: ( (ord('C') & 0x7) + (ord('o') & 0x3) ) - 1 => (3 + 3) - 1 = 5
        $parts = [ (ord('C') & 0x7), (ord('o') & 0x3) ];
        $limit = array_sum($parts) - 1; // final numeric ceiling
        return $limit; // result: 5
    }

    private static function gateCheck(): bool {
        // Always true (non-zero seed implies enabled)
        return (self::seedQ() & 0xFF) > 0;
    }

    /**
     * Public key fragments (placeholder) stored as obfuscated base64-encoded reversed strings.
     * Obfuscation: original_line => base64_encode(line) => strrev(). Reversed back & decoded at runtime.
     * This deters trivial automated extraction but is NOT strong protection.
     */
    // Historical placeholder mechanism removed in favor of strict external key requirement.

    /**
     * Assemble public key.
     */
    private static function publicKey(): string {
        // Strict: must load external key from config; no embedded placeholder accepted
        $pub = self::loadExternalKey();
        if (!$pub) {
            self::$pubKeyMissing = true;
            self::$integrityFailed = true;
            return '';
        }
        self::$pubKeyMissing = false;
        self::$integrityFailed = false;
        return $pub;
    }

    /**
     * Attempt to load an external public key file so commercial users only need to enter license key.
     * Search order:
     *  1. Environment variable LICENSE_PUBKEY_PATH
     *  2. config/license_pubkey.pem
     *  3. config/public_key.pem
     * Returns full PEM string or null.
     */
    private static function loadExternalKey(): ?string {
        // Enforce config-based public key; require public_key.pem
        $baseDir = dirname(__DIR__, 2); // /webroot -> project root
        $candidates = [ $baseDir . '/config/public_key.pem' ];
        foreach ($candidates as $path) {
            if ($path && is_readable($path)) {
                $pem = trim(file_get_contents($path));
                if (preg_match('/-----BEGIN PUBLIC KEY-----[\s\S]+-----END PUBLIC KEY-----/', $pem)) {
                    return $pem;
                }
            }
        }
        return null;
    }

    /**
     * Clear internal cache (use after updating license key to re-evaluate immediately).
     */
    public static function clearCache(): void {
        self::$cache = null;
        self::$cacheTime = 0;
        self::$integrityFailed = false;
    }

    /**
     * Get current license status (cached).
     */
    public static function getStatus(): array {
    // Reset integrity state for each evaluation
    self::$integrityFailed = false;
        if (self::$cache && (time() - self::$cacheTime) < self::CACHE_TTL) {
            return self::$cache;
        }

        $db = Database::getInstance();
        $row = $db->fetch("SELECT setting_value FROM global_settings WHERE setting_key = 'license_key'");
        $raw = trim($row['setting_value'] ?? '');

        if ($raw === '') {
            $status = self::baselineProfile();
            $status['integrity'] = !self::$integrityFailed;
            return self::$cache = $status;
        }

        $parsed = self::verify($raw);
        if (!$parsed['valid']) {
            // Carry reason and mark invalid, but also provide free-mode limits for system behavior
            $status = self::baselineProfile();
            $status['valid'] = false;
            $status['reason'] = $parsed['error'];
            $status['integrity'] = !self::$integrityFailed;
            return self::$cache = $status;
        }
        $parsed['integrity'] = !self::$integrityFailed;
        if (self::$integrityFailed) {
            // Provide specific reason for integrity failure
            $parsed['integrity_error'] = self::$pubKeyMissing ? 'PK_MISSING' : 'PK_MISMATCH';
        }
        self::$cache = $parsed;
        self::$cacheTime = time();
        return self::$cache;
    }

    /**
     * Determine if a new domain can be created (global enforcement).
     */
    public static function canCreateDomain(): array {
        $db = Database::getInstance();
    if (!self::gateCheck()) {
            return ['allowed' => true];
        }
        $status = self::getStatus();
        // If free or limited commercial
        if (!$status['unlimited']) {
            $limit = $status['max_domains'];
            // Free fallback uses derived freeLimit()
            if ($limit === null) {
        $limit = self::seedQ();
            }
            $countRow = $db->fetch("SELECT COUNT(*) as c FROM domains");
            $current = (int)($countRow['c'] ?? 0);
            if ($current >= $limit) {
                return [
                    'allowed' => false,
                    'message' => 'Domain limit reached for current license (' . $limit . ').',
                    'limit' => $limit,
                    'current_count' => $current
                ];
            }
            return [
                'allowed' => true,
                'limit' => $limit,
                'current_count' => $current
            ];
        }
        return ['allowed' => true, 'limit' => null, 'current_count' => null];
    }

    /**
     * Internal: verify license key format & signature.
     */
    private static function verify(string $licenseKey): array {
        $parts = explode('-', $licenseKey);
        if (count($parts) !== 4 || strtoupper($parts[0]) !== 'PDNS') {
            return ['valid' => false, 'error' => 'LX_FMT'];
        }
        [$prefix, $typeBlock, $encoded, $sigHex] = $parts;
        $json = base64_decode($encoded, true);
        if ($json === false) {
            return ['valid' => false, 'error' => 'LX_B64'];
        }
        $payload = json_decode($json, true);
    if (!is_array($payload) || !isset($payload['type'], $payload['domains'])) {
            return ['valid' => false, 'error' => 'LX_JSON'];
        }
        $sig = @hex2bin($sigHex);
        if ($sig === false) {
            return ['valid' => false, 'error' => 'LX_SIGHEX'];
        }
    $pub = openssl_pkey_get_public(self::publicKey());
        if (!$pub) {
            return ['valid' => false, 'error' => 'LX_PUB'];
        }
        $ok = openssl_verify($encoded, $sig, $pub, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return ['valid' => false, 'error' => 'LX_SIG'];
        }
        // Enforce installation binding: payload.installation_id must match local installation code/ID
    $db = Database::getInstance();
    $localId = self::getOrCreateInstallationId($db);
    // Compute local Installation Code to compare against payload value
    $localCode = 'PDNS-' . strtoupper(substr(hash('sha256', 'PDNS-INSTALL-' . $localId), 0, 40));
    $payloadInstall = $payload['installation_id'] ?? null;
    if (empty($payloadInstall) || !hash_equals($localCode, $payloadInstall)) {
            return ['valid' => false, 'error' => 'LX_BIND'];
        }
        $type = strtolower($payload['type']);
        $domains = (int)$payload['domains'];
        $unlimited = ($type === 'commercial' && $domains === 0);
        return [
            'valid' => true,
            'license_type' => $type,
        'max_domains' => $unlimited ? null : ($type === 'free' ? self::seedQ() : $domains),
            'unlimited' => $unlimited,
            'raw' => $licenseKey
        ];
    }

    private static function baselineProfile(): array {
        return [
            'valid' => true,
            'license_type' => 'free',
        'max_domains' => self::seedQ(),
            'unlimited' => false,
            'raw' => null,
            'reason' => null
        ];
    }

    /**
     * Generate a local installation code (fingerprint) for manual license provisioning.
      * Cluster-safe: derived ONLY from persistent installation_id stored in global_settings.
      * This ensures all web nodes (sharing the same DB) present the identical code for licensing.
      * (Previously host + file fingerprint were included, which caused divergence in multi-node setups.)
     */
    public static function getInstallationCode(): string {
        $db = Database::getInstance();
          $installationId = self::getOrCreateInstallationId($db); // 32 hex chars
          // Hash only the installation id for outward-facing code (no hostname / file variance)
          $code = strtoupper(substr(hash('sha256', 'PDNS-INSTALL-' . $installationId), 0, 40));
          return 'PDNS-' . $code;
    }

    private static function getOrCreateInstallationId($db): string {
        $row = $db->fetch("SELECT setting_value FROM global_settings WHERE setting_key='installation_id'");
        if ($row && !empty($row['setting_value'])) {
            return $row['setting_value'];
        }
        $new = bin2hex(random_bytes(16));
        // Insert new installation id
        $db->execute("INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES ('installation_id', ?, 'Unique installation identifier', 'licensing')", [$new]);
        return $new;
    }
}
?>
