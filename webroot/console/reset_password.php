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
 * PDNS Console - Password Reset Page
 */

require_once '../includes/bootstrap.php';

// Check if token is provided
$token = $_GET['token'] ?? '';
$step = $_GET['step'] ?? 'verify';

$user = new User();
$error = '';
$success = '';

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    $resetToken = $_POST['token'];
    
    if (empty($newPassword) || empty($confirmPassword)) {
        $error = 'Please fill in all fields';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match';
    } elseif (strlen($newPassword) < 12) {
        $error = 'Password must be at least 12 characters long';
    } elseif (!preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])/', $newPassword)) {
        $error = 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one symbol';
    } else {
        $result = $user->resetPasswordWithToken($resetToken, $newPassword);
        if ($result['success']) {
            $success = $result['message'];
            $step = 'complete';
        } else {
            $error = $result['error'];
        }
    }
}

// Validate token if we're in verify step
$tokenValid = false;
$tokenUser = null;
if (!empty($token) && $step === 'verify') {
    $tokenResult = $user->validatePasswordResetToken($token);
    if ($tokenResult['success']) {
        $tokenValid = true;
        $tokenUser = $tokenResult['user'];
    } else {
        $error = $tokenResult['error'];
    }
}

// Get theme and branding info
$settings = new Settings();
$branding = $settings->getBranding();
$themeInfo = $settings->getThemeInfo();
$bodyClasses = 'login-page';
if ($themeInfo['effective_dark']) {
    $bodyClasses .= ' dark-mode';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="<?php echo $settings->getThemeUrl(); ?>" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="../assets/css/custom.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="<?php echo $bodyClasses; ?>">
    <div class="login-container">
        <div class="login-header">
            <?php if (!empty($branding['site_logo'])): ?>
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" class="img-fluid">
            <?php endif; ?>
            <h2><?php echo htmlspecialchars($branding['site_name']); ?></h2>
            <p>Password Reset</p>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success" role="alert">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($step === 'complete'): ?>
            <!-- Password reset complete -->
            <div class="text-center">
                <div class="mb-4">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                </div>
                <h4 class="mb-3">Password Reset Complete</h4>
                <p class="text-muted mb-4">Your password has been successfully reset. You can now log in with your new password.</p>
                <a href="../index.php?page=login" class="btn btn-primary">
                    <i class="bi bi-box-arrow-in-right me-2"></i>
                    Go to Login
                </a>
            </div>
            
        <?php elseif ($tokenValid): ?>
            <!-- Reset password form -->
            <div class="text-center mb-4">
                <i class="bi bi-key text-primary" style="font-size: 2rem;"></i>
                <h4 class="mt-2">Reset Your Password</h4>
                <p class="text-muted">Hello <?php echo htmlspecialchars($tokenUser['username']); ?>, please enter your new password below.</p>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                
                <div class="form-floating mb-3">
                    <input type="password" 
                           class="form-control" 
                           id="new_password" 
                           name="new_password" 
                           placeholder="New Password"
                           minlength="12"
                           required>
                    <label for="new_password">New Password</label>
                </div>
                
                <div class="form-floating mb-3">
                    <input type="password" 
                           class="form-control" 
                           id="confirm_password" 
                           name="confirm_password" 
                           placeholder="Confirm Password"
                           minlength="12"
                           required>
                    <label for="confirm_password">Confirm Password</label>
                </div>
                
                <div class="mb-3">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Password must be at least 12 characters long and include uppercase, lowercase, number, and symbol.
                    </small>
                </div>
                
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>
                        Reset Password
                    </button>
                </div>
            </form>
            
        <?php else: ?>
            <!-- Invalid or missing token -->
            <div class="text-center">
                <div class="mb-4">
                    <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i>
                </div>
                <h4 class="mb-3">Invalid Reset Link</h4>
                <p class="text-muted mb-4">
                    The password reset link is invalid or has expired. 
                    Please request a new password reset link.
                </p>
                <a href="../index.php?page=login" class="btn btn-primary">
                    <i class="bi bi-arrow-left me-2"></i>
                    Back to Login
                </a>
            </div>
        <?php endif; ?>
        
        <div class="footer-text">
            <?php echo htmlspecialchars($branding['footer_text']); ?>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Password confirmation validation
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        
        if (newPassword && confirmPassword) {
            function validatePasswords() {
                if (confirmPassword.value && newPassword.value !== confirmPassword.value) {
                    confirmPassword.setCustomValidity('Passwords do not match');
                } else {
                    confirmPassword.setCustomValidity('');
                }
            }
            
            newPassword.addEventListener('input', validatePasswords);
            confirmPassword.addEventListener('input', validatePasswords);
        }
    </script>
</body>
</html>
