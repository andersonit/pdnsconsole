<?php
/**
 * PDNS Console - Main Entry Point
 * 
 * Handles routing, authentication, and loads the appropriate page
 */

// Load bootstrap (includes session handling)
require_once __DIR__ . '/includes/bootstrap.php';

// Initialize core objects
try {
    $settings = new Settings();
    $user = new User();
} catch (Exception $e) {
    die("System initialization failed. Please check your configuration.");
}

// Get current page from URL parameter
$page = $_GET['page'] ?? 'dashboard';
$action = $_GET['action'] ?? 'index';

// Define public pages that don't require authentication
$publicPages = ['login', 'logout', 'forgot_password'];

// Check authentication using PHP sessions
if (!in_array($page, $publicPages) && empty($_SESSION['user_id'])) {
    header('Location: ?page=login');
    exit;
}

// Get current user data if logged in
$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $currentUser = $user->getById($_SESSION['user_id']);
    if (!$currentUser) {
        // User not found - clear session and redirect to login
        $_SESSION = array();
        session_destroy();
        header('Location: ?page=login');
        exit;
    }
}

// Get branding settings
$branding = $settings->getBranding();

// Handle logout
if ($page === 'logout') {
    // Destroy all session data
    $_SESSION = array();
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
    
    // Redirect to login
    header('Location: ?page=login');
    exit;
}

// Handle login page
if ($page === 'login') {
    if (!empty($_SESSION['user_id'])) {
        // Role-based redirect for already logged in users
        if ($user->isSuperAdmin($_SESSION['user_id'])) {
            header('Location: ?page=admin_dashboard');
        } else {
            header('Location: ?page=zone_manage');
        }
        exit;
    }
    
    // Clear temp MFA session if requested
    if (isset($_GET['clear_mfa'])) {
        unset($_SESSION['temp_user_id']);
        unset($_SESSION['temp_user_data']);
        header('Location: ?page=login');
        exit;
    }
    
    $loginError = '';
    $loginSuccess = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF protection for login (including MFA step)
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            $loginError = 'Security token mismatch. Please reload the page and try again.';
        } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $mfaCode = trim($_POST['mfa_code'] ?? '');
        $backupCode = trim($_POST['backup_code'] ?? '');
        
        // Check if this is MFA verification step
        if (isset($_SESSION['temp_user_id']) && (!empty($mfaCode) || !empty($backupCode))) {
            // MFA verification step - use temp session data
            $userId = $_SESSION['temp_user_id'];
            $tempUserData = $_SESSION['temp_user_data'];
            $mfa = new MFA();
            
            $mfaValid = false;
            
            if (!empty($mfaCode)) {
                $mfaValid = $mfa->verifyCode($userId, $mfaCode);
            } elseif (!empty($backupCode)) {
                $mfaValid = $mfa->verifyBackupCode($userId, $backupCode);
            }
            
            if ($mfaValid) {
                // MFA verification successful
                // Regenerate session ID to prevent fixation and ensure fresh cookie
                if (function_exists('session_regenerate_id')) {
                    @session_regenerate_id(true);
                }
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $tempUserData['username'];
                $_SESSION['email'] = $tempUserData['email'];
                
                // Clear temp session data
                unset($_SESSION['temp_user_id']);
                unset($_SESSION['temp_user_data']);
                
                // Update last login
                $user->updateLastLogin($userId);
                
                // Role-based redirect
                if ($user->isSuperAdmin($userId)) {
                    header('Location: ?page=admin_dashboard');
                } else {
                    header('Location: ?page=zone_manage');
                }
                exit;
            } else {
                $loginError = 'Invalid 2FA code. Please try again.';
                // Keep temp session for retry
            }
        } elseif (!empty($username) && !empty($password)) {
            // Initial login step
            $authResult = $user->authenticate($username, $password);
            
            if ($authResult['success']) {
                $userId = $authResult['user']['id'];
                $mfa = new MFA();
                
                // Check if user has MFA enabled
                if ($mfa->isEnabled($userId)) {
                    // User has MFA enabled - set up MFA step
                    $_SESSION['temp_user_id'] = $userId;
                    $_SESSION['temp_user_data'] = $authResult['user'];
                    $loginSuccess = 'Password correct. Please enter your 2FA code.';
                } else {
                    // No MFA - direct login
                    if (function_exists('session_regenerate_id')) {
                        @session_regenerate_id(true);
                    }
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['username'] = $authResult['user']['username'];
                    $_SESSION['email'] = $authResult['user']['email'];
                    
                    // Update last login
                    $user->updateLastLogin($userId);
                    
                    // Role-based redirect
                    if ($user->isSuperAdmin($userId)) {
                        header('Location: ?page=admin_dashboard');
                    } else {
                        header('Location: ?page=zone_manage');
                    }
                    exit;
                }
            } else {
                $loginError = $authResult['error'];
                // Clear any temp session data on password failure
                unset($_SESSION['temp_user_id']);
                unset($_SESSION['temp_user_data']);
            }
        } else {
            if (!$loginError) { // Only set if not already CSRF error
                $loginError = 'Please enter username and password';
            }
        }
        }
    }
    
    include __DIR__ . '/console/login.php';
    exit;
}

// Handle forgot password
if ($page === 'forgot_password') {
    include __DIR__ . '/console/forgot_password.php';
    exit;
}

// From here, user is authenticated - load the requested page
$pageFile = '';

// Map pages to files
$pageRoutes = [
    'dashboard' => 'console/dashboard.php',
    'zone_manage' => 'console/zones/manage.php',
    'zones' => 'console/zones/list.php',
    'zone_add' => 'console/zones/add.php',
    'zone_bulk_add' => 'console/zones/add_bulk.php', 
    'zone_edit' => 'console/zones/edit.php',
    'zone_delete' => 'console/zones/delete.php',
    'zone_dnssec' => 'console/zones/dnssec.php',
    'zone_ddns' => 'console/zones/ddns.php',
    'records' => 'console/records/list.php',
    'record_add' => 'console/records/add.php',
    'record_bulk' => 'console/records/add_bulk.php',
    'record_edit' => 'console/records/edit.php',
    'record_delete' => 'console/records/delete.php',
    'record_import' => 'console/records/import.php',
    'record_export' => 'console/records/export.php',
    'records_import' => 'console/records/import.php',
    'profile' => 'console/profile.php',
    
    // Admin pages (super admin only)
    'admin_dashboard' => 'console/admin/dashboard.php',
    'admin_users' => 'console/admin/users.php',
    'admin_tenants' => 'console/admin/tenants.php',
    'admin_settings' => 'console/admin/settings.php',
    'admin_dns_settings' => 'console/admin/dns_settings.php',
    'admin_email_settings' => 'console/admin/email_settings.php',
    'admin_branding' => 'console/admin/branding.php',
    'admin_system' => 'console/admin/system.php',
    'admin_record_types' => 'console/admin/record_types.php',
    'admin_audit' => 'console/admin/audit.php',
    'admin_license' => 'console/admin/license.php',
];

// Check if page exists
if (isset($pageRoutes[$page])) {
    $pageFile = __DIR__ . '/' . $pageRoutes[$page];
} else {
    // Default to role-based landing page
    if ($user->isSuperAdmin($currentUser['id'])) {
        $pageFile = __DIR__ . '/console/admin/dashboard.php';
        $page = 'admin_dashboard';
    } else {
        $pageFile = __DIR__ . '/console/zones/manage.php';
        $page = 'zone_manage';
    }
}

// Check admin permissions for admin pages
if (strpos($page, 'admin_') === 0 && !$user->isSuperAdmin($currentUser['id'])) {
    // Allow tenant admins to access tenant management
    if ($page === 'admin_tenants') {
        $userTenants = $user->getUserTenants($currentUser['id']);
        if (empty($userTenants)) {
            header('HTTP/1.0 403 Forbidden');
            die('Access denied. No tenant assignments found.');
        }
    } else {
        header('HTTP/1.0 403 Forbidden');
        die('Access denied. Super admin privileges required.');
    }
}

// Check if file exists
if (!file_exists($pageFile)) {
    header('HTTP/1.0 404 Not Found');
    die('Page not found.');
}

// Include the page
include $pageFile;

?>
