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
$publicPages = ['login', 'logout'];

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
        header('Location: ?page=dashboard');
        exit;
    }
    
    $loginError = '';
    $loginSuccess = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (!empty($username) && !empty($password)) {
            $authResult = $user->authenticate($username, $password);
            
            if ($authResult['success']) {
                // Set session variables
                $_SESSION['user_id'] = $authResult['user']['id'];
                $_SESSION['username'] = $authResult['user']['username'];
                $_SESSION['email'] = $authResult['user']['email'];
                
                // Redirect to dashboard (no token needed)
                header('Location: ?page=dashboard');
                exit;
            } else {
                $loginError = $authResult['error'];
            }
        } else {
            $loginError = 'Please enter username and password';
        }
    }
    
    include __DIR__ . '/console/login.php';
    exit;
}

// From here, user is authenticated - load the requested page
$pageFile = '';

// Map pages to files
$pageRoutes = [
    'dashboard' => 'console/dashboard.php',
    'domains' => 'console/domains/list.php',
    'domain_add' => 'console/domains/add.php',
    'domain_edit' => 'console/domains/edit.php',
    'domain_delete' => 'console/domains/delete.php',
    'records' => 'console/records/list.php',
    'record_add' => 'console/records/add.php',
    'record_bulk' => 'console/records/add_bulk.php',
    'record_edit' => 'console/records/edit.php',
    'record_delete' => 'console/records/delete.php',
    'record_import' => 'console/records/import.php',
    'record_export' => 'console/records/export.php',
    'profile' => 'console/profile.php',
    
    // Admin pages (super admin only)
    'admin_users' => 'console/admin/users.php',
    'admin_tenants' => 'console/admin/tenants.php',
    'admin_settings' => 'console/admin/settings.php',
    'admin_record_types' => 'console/admin/record_types.php',
    'admin_audit' => 'console/admin/audit.php',
];

// Check if page exists
if (isset($pageRoutes[$page])) {
    $pageFile = __DIR__ . '/' . $pageRoutes[$page];
} else {
    // Default to dashboard
    $pageFile = __DIR__ . '/console/dashboard.php';
    $page = 'dashboard';
}

// Check admin permissions for admin pages
if (strpos($page, 'admin_') === 0 && !$user->isSuperAdmin($currentUser['id'])) {
    header('HTTP/1.0 403 Forbidden');
    die('Access denied. Super admin privileges required.');
}

// Check if file exists
if (!file_exists($pageFile)) {
    header('HTTP/1.0 404 Not Found');
    die('Page not found.');
}

// Include the page
include $pageFile;

?>
