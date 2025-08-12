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

require_once __DIR__ . '/../webroot/includes/bootstrap.php';

if (!class_exists('LicenseManager')) {
    $lmPath = __DIR__ . '/../webroot/classes/LicenseManager.php';
    if (file_exists($lmPath)) {
        require_once $lmPath;
    }
}

if (!class_exists('LicenseManager')) {
    fwrite(STDERR, "LicenseManager not available.\n");
    exit(1);
}

$status = LicenseManager::getStatus();
$db = Database::getInstance();
$countRow = $db->fetch("SELECT COUNT(*) c FROM domains");
$used = (int)($countRow['c'] ?? 0);
$limitStr = $status['unlimited'] ? 'unlimited' : ($status['max_domains'] ?? '5');

echo "License Type: {$status['license_type']}\n";
// Provide serialization of key metrics
if (isset($status['max_domains'])) {
    echo "Max Domains: {$limitStr}\n";
}

echo "Domains Used: {$used}\n";
if (!empty($status['valid_until'])) {
    echo "Valid Until: {$status['valid_until']}\n";
}
if (!empty($status['error'])) {
    echo "Error: {$status['error']}\n";
}
exit(0);
