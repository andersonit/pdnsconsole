<?php
/**
 * PDNS Console Bootstrap
 * 
 * Initialize database session handler and start sessions
 */

// Mark that PDNS Console is properly loaded
define('PDNS_CONSOLE_LOADED', true);

// Load configuration and core classes
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // Composer autoload
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/DatabaseSessionHandler.php';

// Set up database session handler
$sessionHandler = new DatabaseSessionHandler();
session_set_save_handler($sessionHandler, true);

// Configure session settings
ini_set('session.cookie_lifetime', 0); // Session cookie expires when browser closes
// Only force secure cookies when request is actually HTTPS; prevents dev login loops on HTTP
$isHttps = (
	(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
	(isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
	(!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
);
ini_set('session.cookie_secure', $isHttps ? '1' : '0');
ini_set('session.cookie_httponly', 1); // No JavaScript access
ini_set('session.cookie_samesite', 'Strict'); // CSRF protection (change to Lax if external POST callbacks required)
ini_set('session.gc_maxlifetime', 7200); // 2 hours
ini_set('session.use_strict_mode', 1);

// Start the session
session_start();

// CSRF token management (simple session-based implementation)
if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token() {
	return $_SESSION['csrf_token'] ?? '';
}

function verify_csrf_token($token) {
	if (!isset($_SESSION['csrf_token']) || !is_string($token)) {
		return false;
	}
	return hash_equals($_SESSION['csrf_token'], $token);
}

// Initialize other core classes
require_once __DIR__ . '/../classes/Encryption.php';
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../classes/AuditLog.php';
require_once __DIR__ . '/../classes/MFA.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Domain.php';
require_once __DIR__ . '/../classes/Records.php';
require_once __DIR__ . '/../classes/Nameserver.php';
require_once __DIR__ . '/../classes/Email.php';
// Removed legacy Comments.php (multi-comment implementation)
require_once __DIR__ . '/../classes/RecordComments.php';
require_once __DIR__ . '/../classes/ZoneComments.php';
// Licensing (loaded late so it can use other helpers if needed)
require_once __DIR__ . '/../classes/LicenseManager.php';

// Apply dynamic timezone from settings (fallback to UTC)
try {
	$settingsObj = new Settings();
	$tz = $settingsObj->get('timezone', 'UTC');
	if ($tz && in_array($tz, timezone_identifiers_list())) {
		date_default_timezone_set($tz);
	} else {
		date_default_timezone_set('UTC');
	}
} catch (Exception $e) {
	// Fallback silently
	date_default_timezone_set('UTC');
}
?>
