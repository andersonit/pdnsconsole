<?php
/**
 * PDNS Console - Login Page
 */

// Generate CSRF token for this page load
$csrfToken = bin2hex(random_bytes(32));

// Check if we're in MFA step (temp user session exists)
$requiresMFA = isset($_SESSION['temp_user_id']);
$tempUser = $_SESSION['temp_user_data'] ?? null;

// Get theme info including dark mode
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
    <title>Login - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="<?php echo $settings->getThemeUrl(); ?>" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="assets/css/custom.css" rel="stylesheet">
</head>
<body class="<?php echo $bodyClasses; ?>">
    <div class="login-container">
        <div class="login-header">
            <?php if (!empty($branding['site_logo'])): ?>
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" class="img-fluid">
            <?php endif; ?>
            <h2><?php echo htmlspecialchars($branding['site_name']); ?></h2>
            <p>DNS Management Console</p>
        </div>
        
        <?php if (!empty($loginError)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($loginError); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($loginSuccess)): ?>
            <div class="alert alert-success" role="alert">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($loginSuccess); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="?page=login">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <?php if (!$requiresMFA): ?>
                <!-- Standard login form -->
                <div class="form-floating">
                    <input type="text" 
                           class="form-control" 
                           id="username" 
                           name="username" 
                           placeholder="Username or Email"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           required>
                    <label for="username">Username or Email</label>
                </div>
                
                <div class="form-floating">
                    <input type="password" 
                           class="form-control" 
                           id="password" 
                           name="password" 
                           placeholder="Password"
                           required>
                    <label for="password">Password</label>
                </div>
                
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-login">
                        <i class="bi bi-box-arrow-in-right me-2"></i>
                        Sign In
                    </button>
                </div>
            <?php else: ?>
                <!-- MFA verification form -->
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($tempUser['username']); ?>">
                
                <div class="alert alert-info">
                    <i class="bi bi-shield-check me-2"></i>
                    Welcome <strong><?php echo htmlspecialchars($tempUser['username']); ?></strong>! 
                    Please enter your 2FA code to complete login.
                </div>
                
                <div class="form-floating mb-3">
                    <input type="text" 
                           class="form-control" 
                           id="mfa_code" 
                           name="mfa_code" 
                           placeholder="000000"
                           pattern="[0-9]{6}"
                           maxlength="6"
                           autocomplete="one-time-code">
                    <label for="mfa_code">2FA Code</label>
                </div>
                
                <div class="text-center mb-3">
                    <small class="text-muted">Or use a backup code</small>
                </div>
                
                <div class="form-floating mb-3">
                    <input type="text" 
                           class="form-control" 
                           id="backup_code" 
                           name="backup_code" 
                           placeholder="12345678">
                    <label for="backup_code">Backup Code (optional)</label>
                </div>
                
                <div class="row g-2">
                    <div class="col">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-shield-check me-1"></i>
                            Verify
                        </button>
                    </div>
                    <div class="col">
                        <a href="?page=login&clear_mfa=1" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-arrow-left me-1"></i>
                            Back
                        </a>
                    </div>
                </div>
                
                <div class="mt-3 text-center">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Lost your authenticator device? Contact your system administrator for assistance.
                    </small>
                </div>
            <?php endif; ?>
        </form>
        
        <div class="footer-text">
            <?php echo htmlspecialchars($branding['footer_text']); ?>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <script>
        // MFA code formatting
        const mfaCodeInput = document.getElementById('mfa_code');
        if (mfaCodeInput) {
            mfaCodeInput.addEventListener('input', function() {
                // Remove non-digits
                this.value = this.value.replace(/[^0-9]/g, '');
            });
            
            // Auto-focus MFA input if it exists
            mfaCodeInput.focus();
        }
        
        // Backup code formatting
        const backupCodeInput = document.getElementById('backup_code');
        if (backupCodeInput) {
            backupCodeInput.addEventListener('input', function() {
                // Remove non-digits
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }
        
        // Disable MFA input when backup code is entered and vice versa
        if (mfaCodeInput && backupCodeInput) {
            mfaCodeInput.addEventListener('input', function() {
                if (this.value.length > 0) {
                    backupCodeInput.disabled = true;
                    backupCodeInput.value = '';
                } else {
                    backupCodeInput.disabled = false;
                }
            });
            
            backupCodeInput.addEventListener('input', function() {
                if (this.value.length > 0) {
                    mfaCodeInput.disabled = true;
                    mfaCodeInput.value = '';
                } else {
                    mfaCodeInput.disabled = false;
                }
            });
        }
    </script>
</body>
</html>
