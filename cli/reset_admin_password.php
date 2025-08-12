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

// Reset admin password
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/webroot/classes/Database.php';
require_once __DIR__ . '/webroot/classes/Encryption.php';

$db = Database::getInstance();
$encryption = new Encryption();

$newPassword = 'admin123';
$hash = $encryption->hashPassword($newPassword);

$db->execute('UPDATE admin_users SET password_hash = ? WHERE username = ?', [$hash, 'admin']);
echo "Admin password updated to: $newPassword\n";
?>
