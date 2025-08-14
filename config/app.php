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

// == PDNS Console Application Configuration ==

// Environment
define('PDNS_ENV', 'development'); // development, production

// Application Settings
define('APP_NAME', 'PDNS Console');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'https://dev.pdnsconsole.com'); // Update for your installation

// Demo Mode (hide sensitive settings like CAPTCHA keys on public demo hosts)
// Set DEMO_RESTRICTIONS_ENABLED to false to temporarily disable masking on demo hosts
define('DEMO_RESTRICTIONS_ENABLED', true);
// Hosts where demo restrictions apply (match by HTTP_HOST without port)
define('DEMO_HOSTNAMES', [
    'demo.pdnsconsole.com'
]);

// Security Settings
define('CSRF_TOKEN_NAME', 'csrf_token');
define('SESSION_NAME', 'pdns_session');
define('PASSWORD_MIN_LENGTH', 8);

// Encryption Settings
define('ENCRYPTION_METHOD', 'AES-256-CBC');
define('ENCRYPTION_KEY', '23caf988963acc2a051b253498e7016b'); // Generated secure key


// API Settings
define('API_RATE_LIMIT_REQUESTS', 3);
define('API_RATE_LIMIT_WINDOW', 180); // 3 minutes
define('API_RATE_LIMIT_THROTTLE', 600); // 10 minutes

// Paths (relative to webroot)
define('ASSETS_PATH', '/assets');
define('UPLOAD_PATH', '/uploads');

// Bootswatch Themes Available
define('AVAILABLE_THEMES', [
    'default' => 'Default Bootstrap',
    'cerulean' => 'Cerulean',
    'cosmo' => 'Cosmo',
    'cyborg' => 'Cyborg',
    'darkly' => 'Darkly',
    'flatly' => 'Flatly',
    'journal' => 'Journal',
    'litera' => 'Litera',
    'lumen' => 'Lumen',
    'lux' => 'Lux',
    'materia' => 'Materia',
    'minty' => 'Minty',
    'morph' => 'Morph',
    'pulse' => 'Pulse',
    'quartz' => 'Quartz',
    'sandstone' => 'Sandstone',
    'simplex' => 'Simplex',
    'sketchy' => 'Sketchy',
    'slate' => 'Slate',
    'solar' => 'Solar',
    'spacelab' => 'Spacelab',
    'superhero' => 'Superhero',
    'united' => 'United',
    'vapor' => 'Vapor',
    'yeti' => 'Yeti',
    'zephyr' => 'Zephyr'
]);

// Dark themes that are naturally dark
define('NATURALLY_DARK_THEMES', [
    'cyborg', 'darkly', 'slate', 'solar', 'superhero', 'vapor', 'quartz'
]);

// Theme categories for better organization
define('THEME_CATEGORIES', [
    'light' => [
        'default' => 'Default Bootstrap',
        'cerulean' => 'Cerulean',
        'cosmo' => 'Cosmo',
        'flatly' => 'Flatly',
        'journal' => 'Journal',
        'litera' => 'Litera',
        'lumen' => 'Lumen',
        'lux' => 'Lux',
        'materia' => 'Materia',
        'minty' => 'Minty',
        'morph' => 'Morph',
        'pulse' => 'Pulse',
        'sandstone' => 'Sandstone',
        'simplex' => 'Simplex',
        'sketchy' => 'Sketchy',
        'spacelab' => 'Spacelab',
        'united' => 'United',
        'yeti' => 'Yeti',
        'zephyr' => 'Zephyr'
    ],
    'dark' => [
        'cyborg' => 'Cyborg',
        'darkly' => 'Darkly',
        'quartz' => 'Quartz',
        'slate' => 'Slate',
        'solar' => 'Solar',
        'superhero' => 'Superhero',
        'vapor' => 'Vapor'
    ]
]);

// Error Reporting
if (PDNS_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Timezone is now stored in global_settings (timezone); bootstrap or runtime code should set date_default_timezone_set dynamically after loading settings.

?>
