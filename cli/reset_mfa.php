<?php
/**
 * PDNS Console - CLI MFA Reset Script
 * 
 * Command-line script for super admins to reset Two-Factor Authentication
 * Usage: php reset_mfa.php <username>
 */

// Include configuration and classes
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../webroot/classes/Database.php';
require_once __DIR__ . '/../webroot/classes/User.php';
require_once __DIR__ . '/../webroot/classes/Encryption.php';

// Check if script is run from command line
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

// Check for username argument
if ($argc < 2) {
    echo "Usage: php reset_mfa.php <username>\n";
    echo "Example: php reset_mfa.php admin\n";
    exit(1);
}

$username = $argv[1];

try {
    // Initialize database and user class
    $db = Database::getInstance();
    $user = new User();
    
    // Find user by username
    $userData = $db->fetch(
        "SELECT id, username, email, role FROM admin_users WHERE username = ? AND is_active = 1",
        [$username]
    );
    
    if (!$userData) {
        echo "Error: User '$username' not found or is inactive.\n";
        exit(1);
    }
    
    echo "Found user: {$userData['username']} ({$userData['email']}) - Role: {$userData['role']}\n";
    
    // Check if user has MFA enabled
    $mfaData = $db->fetch(
        "SELECT id, is_enabled FROM user_mfa WHERE user_id = ?",
        [$userData['id']]
    );
    
    if (!$mfaData) {
        echo "User does not have MFA configured.\n";
        exit(0);
    }
    
    if (!$mfaData['is_enabled']) {
        echo "User's MFA is already disabled.\n";
        exit(0);
    }
    
    // Confirm reset
    echo "WARNING: This will disable Two-Factor Authentication for user '{$userData['username']}'.\n";
    echo "The user will need to set up MFA again if they want to re-enable it.\n";
    echo "Continue? (y/N): ";
    
    $handle = fopen("php://stdin", "r");
    $confirmation = trim(fgets($handle));
    fclose($handle);
    
    if (strtolower($confirmation) !== 'y' && strtolower($confirmation) !== 'yes') {
        echo "MFA reset cancelled.\n";
        exit(0);
    }
    
    // Reset MFA
    $resetResult = $db->execute(
        "UPDATE user_mfa SET is_enabled = 0, secret_encrypted = '', backup_codes_encrypted = '' WHERE user_id = ?",
        [$userData['id']]
    );
    
    if ($resetResult) {
        echo "SUCCESS: MFA has been disabled for user '{$userData['username']}'.\n";
        echo "The user can now log in without Two-Factor Authentication.\n";
        
        // Log the action
        $db->execute(
            "INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address) 
             VALUES (NULL, 'mfa_reset_cli', 'user_mfa', ?, ?, ?)",
            [
                $userData['id'],
                json_encode(['reset_by' => 'CLI script', 'target_user' => $userData['username']]),
                'CLI'
            ]
        );
        
        echo "Action has been logged to audit trail.\n";
    } else {
        echo "ERROR: Failed to reset MFA. Please check database connection.\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
