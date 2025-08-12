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

/**
 * PDNS Console - Create Super Admin User
 * 
 * Command-line script to create the first super admin user
 * Usage: php create_admin.php
 */

// Load configuration and classes
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../webroot/classes/Database.php';
require_once __DIR__ . '/../webroot/classes/Encryption.php';
require_once __DIR__ . '/../webroot/classes/Settings.php';
require_once __DIR__ . '/../webroot/classes/User.php';

echo "PDNS Console - Create Super Admin User\n";
echo "=====================================\n\n";

try {
    $user = new User();
    
    // Get user input
    echo "Enter username: ";
    $username = trim(fgets(STDIN));
    
    echo "Enter email: ";
    $email = trim(fgets(STDIN));
    
    echo "Enter password: ";
    // Hide password input
    system('stty -echo');
    $password = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";
    
    echo "Confirm password: ";
    system('stty -echo');
    $confirmPassword = trim(fgets(STDIN));
    system('stty echo');
    echo "\n\n";
    
    // Validate input
    if (empty($username) || empty($email) || empty($password)) {
        echo "Error: All fields are required.\n";
        exit(1);
    }
    
    if ($password !== $confirmPassword) {
        echo "Error: Passwords do not match.\n";
        exit(1);
    }
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        echo "Error: Password must be at least " . PASSWORD_MIN_LENGTH . " characters.\n";
        exit(1);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "Error: Invalid email format.\n";
        exit(1);
    }
    
    // Create super admin user
    $result = $user->create($username, $email, $password, 'super_admin');
    
    if ($result['success']) {
        echo "Success: Super admin user created successfully!\n";
        echo "User ID: " . $result['user_id'] . "\n";
        echo "Username: $username\n";
        echo "Email: $email\n";
        echo "Role: super_admin\n\n";
        echo "You can now login to the PDNS Console with these credentials.\n";
    } else {
        echo "Error: " . $result['error'] . "\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

?>
