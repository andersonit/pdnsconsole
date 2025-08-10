<?php
// Copy to config.php and adjust credentials. Do NOT commit real secrets.
return [
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=license_admin;charset=utf8mb4',
        'user' => 'license_user',
        'pass' => 'change_me_secure',
    ],
    // Absolute or relative path to RSA private key used for signing licenses
    'private_key_path' => __DIR__ . '/private.pem',
    // Default domain limits for quick-select (label => limit)
    'quick_limits' => [
        'Free (5)' => 5,
        'Pro (100)' => 100,
        'Business (Unlimited)' => 0
    ],
];
