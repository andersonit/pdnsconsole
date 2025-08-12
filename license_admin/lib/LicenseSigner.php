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
 * Private portal license signer.
 * Not distributed. Uses RSA private key to sign license payloads.
 */
class LicenseSigner {
    private $privateKeyPath;

    public function __construct(string $privateKeyPath) {
        $this->privateKeyPath = $privateKeyPath;
    }

    public function sign(array $payload): string {
        if (!is_readable($this->privateKeyPath)) {
            throw new RuntimeException('Private key not readable: ' . $this->privateKeyPath);
        }
        $type = strtolower($payload['type'] ?? 'commercial');
        if (!in_array($type, ['commercial','free'])) {
            throw new InvalidArgumentException('Invalid type');
        }
        if (!isset($payload['domains'])) {
            throw new InvalidArgumentException('domains field required');
        }
        if (!isset($payload['email'])) {
            throw new InvalidArgumentException('email field required');
        }
        if (!isset($payload['issued'])) {
            $payload['issued'] = date('Y-m-d');
        }
        // Keep payload minimal and deterministic ordering
        $ordered = [
            'email' => $payload['email'],
            'type' => $type,
            'domains' => (int)$payload['domains'],
            'issued' => $payload['issued'],
        ];
        if (!empty($payload['installation_id'])) {
            $ordered['installation_id'] = $payload['installation_id'];
        }
        $json = json_encode($ordered, JSON_UNESCAPED_SLASHES);
        $segment = base64_encode($json);
        $priv = openssl_pkey_get_private(file_get_contents($this->privateKeyPath));
        if (!$priv) {
            throw new RuntimeException('Failed to load private key');
        }
        if (!openssl_sign($segment, $sig, $priv, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing failed');
        }
        $hex = bin2hex($sig);
        return 'PDNS-' . strtoupper($type) . '-' . $segment . '-' . $hex;
    }
}
