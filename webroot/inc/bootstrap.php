<?php
/**
 * PDNS Console Bootstrap
 * 
 * Initialize database session handler and start sessions
 */

// Load configuration and core classes
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/DatabaseSessionHandler.php';

// Set up database session handler
$sessionHandler = new DatabaseSessionHandler();
session_set_save_handler($sessionHandler, true);

// Configure session settings
ini_set('session.cookie_lifetime', 0); // Session cookie expires when browser closes
ini_set('session.cookie_secure', 1);   // HTTPS only
ini_set('session.cookie_httponly', 1); // No JavaScript access
ini_set('session.cookie_samesite', 'Strict'); // CSRF protection
ini_set('session.gc_maxlifetime', 7200); // 2 hours
ini_set('session.use_strict_mode', 1);

// Start the session
session_start();

// Initialize other core classes
require_once __DIR__ . '/../classes/Encryption.php';
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../classes/User.php';
?>
