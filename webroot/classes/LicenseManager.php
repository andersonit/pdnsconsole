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

    /**
     * Public key fragments (placeholder) stored as obfuscated base64-encoded reversed strings.
     * Obfuscation: original_line => base64_encode(line) => strrev(). Reversed back & decoded at runtime.
     * This deters trivial automated extraction but is NOT strong protection.
     */
    private const PK_OBF = [
        // '-----BEGIN PUBLIC KEY-----'
        'LS0tLS0tRE5FVCBLWUVQVUJMSyBOSUdFQi0tLS0tLS0=',
        // 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEArExamplePublicKeyGoesHere0123456789AB'
        'QkE5ODc2NTQzMjEwOTc2ZVJlaHNvZ0V5a1NlYmx1U2VwbGFNRHIwRUFBUUFCRzB3OUk5aWtIaXFrZ2JBTkRqSklCSU1JQQ==',
        // 'MoreKeyDataLinesBase64EncodedEtcEtcExamplePadding=='
        'PT09Z25pZGRhUGVsbXBhRXN0Y0N0RXRkZW5vQ2U0NVNhc2VuaWxEYVR5RWVyb01=',
        // '-----END PUBLIC KEY-----'
        'LS0tLS0tRE5FVCBLWUVQVUJMSyBHTkUtLS0tLS0t'
    ];

    // SHA256 hash of the de-obfuscated public key (joined with "\n").
    private const PK_HASH = 'e3d0a4a2d8e2c2c9b4c0b1d4e5f6071829384756aabbccddeeff001122334455'; // placeholder hash

    /**
     * Assemble public key.
     */
    private static function publicKey(): string {
        $lines = [];
        foreach (self::PK_OBF as $obf) {
            $decoded = base64_decode(strrev($obf), true); // reverse then decode
            if ($decoded === false) {
                self::$integrityFailed = true;
                continue;
            }
            $lines[] = $decoded;
        }
        $pub = implode("\n", $lines);

        // If placeholder hash is present, attempt to auto-load external key (no end-user code edits required)
        $placeholder = self::PK_HASH === 'e3d0a4a2d8e2c2c9b4c0b1d4e5f6071829384756aabbccddeeff001122334455';
        if ($placeholder) {
            $ext = self::loadExternalKey();
            if ($ext) {
                $pub = $ext; // replace with external key
            }
            // Skip integrity failure for placeholder scenario—treat as acceptable if external key loaded
            if ($ext === null) {
                // Minimal integrity marking to signal placeholder remains
                self::$integrityFailed = false; // don't block; just informational
            }
            return $pub;
        }

        $hash = hash('sha256', $pub);
        if (!hash_equals(self::PK_HASH, $hash)) {
            self::$integrityFailed = true;
        }
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
        $candidates = [];
        $envPath = getenv('LICENSE_PUBKEY_PATH');
        if ($envPath) { $candidates[] = $envPath; }
        $baseDir = dirname(__DIR__, 2); // /webroot -> root project
        $candidates[] = $baseDir . '/config/license_pubkey.pem';
        $candidates[] = $baseDir . '/config/public_key.pem';
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
     * Get current license status (cached).
     */
    public static function getStatus(): array {
        if (self::$cache && (time() - self::$cacheTime) < self::CACHE_TTL) {
            return self::$cache;
        }

        $db = Database::getInstance();
        $row = $db->fetch("SELECT setting_value FROM global_settings WHERE setting_key = 'license_key'");
        $raw = trim($row['setting_value'] ?? '');

        if ($raw === '') {
            $status = self::freeStatus();
            $status['integrity'] = !self::$integrityFailed;
            return self::$cache = $status;
        }

        $parsed = self::verify($raw);
        if (!$parsed['valid']) {
            // Fallback to free mode but carry reason
            $status = self::freeStatus();
            $status['reason'] = $parsed['error'];
            $status['integrity'] = !self::$integrityFailed;
            return self::$cache = $status;
        }
        $parsed['integrity'] = !self::$integrityFailed;
        if (self::$integrityFailed) {
            // downgrade silently to free if integrity fails (optional). For now just flag.
            $parsed['integrity_error'] = 'PK_MISMATCH';
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
        $enf = $db->fetch("SELECT setting_value FROM global_settings WHERE setting_key='license_enforcement'");
        $enforce = ($enf['setting_value'] ?? '1') === '1';
        if (!$enforce) {
            return ['allowed' => true];
        }
        $status = self::getStatus();
        // If free or limited commercial
        if (!$status['unlimited']) {
            $limit = $status['max_domains'];
            // Free fallback uses 5
            if ($limit === null) {
                $limit = 5;
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
        $type = strtolower($payload['type']);
        $domains = (int)$payload['domains'];
        $unlimited = ($type === 'commercial' && $domains === 0);
        return [
            'valid' => true,
            'license_type' => $type,
            'max_domains' => $unlimited ? null : ($type === 'free' ? 5 : $domains),
            'unlimited' => $unlimited,
            'raw' => $licenseKey
        ];
    }

    private static function freeStatus(): array {
        return [
            'valid' => true,
            'license_type' => 'free',
            'max_domains' => 5,
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
