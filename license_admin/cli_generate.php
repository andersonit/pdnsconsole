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

// Simple CLI license generator (private use only)
// Usage: php cli_generate.php --email customer@example.com --type commercial --domains 100 --key /path/to/private.pem [--install INSTALLATION_ID] [--db "mysql:host=localhost;dbname=license_admin"] [--dbuser user] [--dbpass pass]

$options = getopt('', ['email:', 'type:', 'domains:', 'key:', 'issued::', 'install::', 'db::', 'dbuser::', 'dbpass::']);
$required = ['email','type','domains','key'];
foreach ($required as $req) {
    if (empty($options[$req])) {
        fwrite(STDERR, "Missing --$req\n");
        exit(1);
    }
}
$type = strtolower($options['type']);
if (!in_array($type, ['commercial','free'])) {
    fwrite(STDERR, "--type must be commercial|free\n");
    exit(1);
}
$domains = (int)$options['domains'];
if ($domains < 0) { fwrite(STDERR, "--domains must be >=0 (0=unlimited)\n"); exit(1);}    
$privPath = $options['key'];
if (!is_readable($privPath)) { fwrite(STDERR, "Private key not readable: $privPath\n"); exit(1);}    
$issued = $options['issued'] ?? date('Y-m-d');
$install = $options['install'] ?? null;
$payload = [
    'email' => $options['email'],
    'type' => $type,
    'domains' => $domains,
    'issued' => $issued,
];
if ($install) { $payload['installation_id'] = $install; }
$json = json_encode($payload, JSON_UNESCAPED_SLASHES);
$segment = base64_encode($json);
$priv = openssl_pkey_get_private(file_get_contents($privPath));
if (!$priv) { fwrite(STDERR, "Failed to load private key\n"); exit(1);}    
if (!openssl_sign($segment, $sig, $priv, OPENSSL_ALGO_SHA256)) {
    fwrite(STDERR, "Signing failed\n");
    exit(1);
}
$hexSig = bin2hex($sig);
$key = 'PDNS-' . strtoupper($type) . '-' . $segment . '-' . $hexSig;

// Optional DB persistence
if (!empty($options['db'])) {
    $dsn = $options['db'];
    $dbUser = $options['dbuser'] ?? null;
    $dbPass = $options['dbpass'] ?? null;
    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Upsert customer
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE email = ?');
        $stmt->execute([$options['email']]);
        $cid = $stmt->fetchColumn();
        if (!$cid) {
            $ins = $pdo->prepare('INSERT INTO customers (email, name) VALUES (?, ?)');
            $ins->execute([$options['email'], $options['email']]);
            $cid = $pdo->lastInsertId();
        }
        // Insert license record
        $insL = $pdo->prepare('INSERT INTO licenses (customer_id, installation_id, license_key, domain_limit, type, issued) VALUES (?,?,?,?,?,?)');
        $insL->execute([$cid, $install, $key, $domains, $type, $issued]);
        $lid = $pdo->lastInsertId();
        $evt = $pdo->prepare('INSERT INTO license_events (license_id, event_type, detail) VALUES (?,?,?)');
        $evt->execute([$lid, 'ISSUED', json_encode(['domains'=>$domains,'install'=>$install])]);
    } catch (Exception $e) {
        fwrite(STDERR, "DB write failed: " . $e->getMessage() . "\n");
    }
}

// Output license key last so CLI capture unaffected by warnings
fwrite(STDOUT, $key . "\n");
