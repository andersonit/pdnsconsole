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
                
                <div class="text-center mt-3">
                    <a href="#" class="text-decoration-none" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">
                        <i class="bi bi-key me-1"></i>
                        Forgot your password?
                    </a>
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
                <div id="mfa_toggle_container" class="text-center mb-3">
                    <a href="#" id="show_backup_code" class="small text-decoration-none">
                        <i class="bi bi-shield-lock me-1"></i>Use a backup code instead
                    </a>
                </div>

                <div id="backup_code_container" class="d-none">
                    <div class="form-floating mb-3">
                        <input type="text" 
                               class="form-control" 
                               id="backup_code" 
                               name="backup_code" 
                               placeholder="12345678"
                               autocomplete="off">
                        <label for="backup_code">Backup Code</label>
                    </div>
                    <div class="text-center mb-3">
                        <a href="#" id="show_mfa_code" class="small text-decoration-none">
                            <i class="bi bi-arrow-left me-1"></i>Use authenticator app instead
                        </a>
                    </div>
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
    
    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="forgotPasswordModalLabel">
                        <i class="bi bi-key me-2"></i>
                        Reset Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="forgotPasswordForm">
                    <div class="modal-body">
                        <p class="text-muted">Enter your email address and we'll send you a link to reset your password.</p>
                        
                        <div id="forgotPasswordError" class="alert alert-danger d-none" role="alert"></div>
                        <div id="forgotPasswordSuccess" class="alert alert-success d-none" role="alert"></div>
                        
                        <div class="form-floating">
                            <input type="email" 
                                   class="form-control" 
                                   id="resetEmail" 
                                   name="reset_email" 
                                   placeholder="name@example.com"
                                   required>
                            <label for="resetEmail">Email Address</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="sendResetBtn">
                            <i class="bi bi-envelope me-1"></i>
                            Send Reset Link
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <script>
        // Forgot password form handling
        document.getElementById('forgotPasswordForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const email = document.getElementById('resetEmail').value;
            const btn = document.getElementById('sendResetBtn');
            const errorDiv = document.getElementById('forgotPasswordError');
            const successDiv = document.getElementById('forgotPasswordSuccess');
            
            // Hide previous messages
            errorDiv.classList.add('d-none');
            successDiv.classList.add('d-none');
            
            // Disable button and show loading
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';
            
            // Send AJAX request
            fetch('?page=forgot_password', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'reset_email=' + encodeURIComponent(email)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    successDiv.textContent = data.message;
                    successDiv.classList.remove('d-none');
                    document.getElementById('resetEmail').value = '';
                    
                    // Auto-close modal after 3 seconds
                    setTimeout(() => {
                        bootstrap.Modal.getInstance(document.getElementById('forgotPasswordModal')).hide();
                    }, 3000);
                } else {
                    errorDiv.textContent = data.error;
                    errorDiv.classList.remove('d-none');
                }
            })
            .catch(error => {
                errorDiv.textContent = 'An error occurred. Please try again.';
                errorDiv.classList.remove('d-none');
            })
            .finally(() => {
                // Re-enable button
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-envelope me-1"></i>Send Reset Link';
            });
        });
        
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
        
        // Backup code toggle & formatting
        const backupContainer = document.getElementById('backup_code_container');
        const showBackupLink = document.getElementById('show_backup_code');
        const showMfaLink = document.getElementById('show_mfa_code');
        let backupCodeInput = null;

        if (showBackupLink && backupContainer) {
            showBackupLink.addEventListener('click', function(e) {
                e.preventDefault();
                backupContainer.classList.remove('d-none');
                document.getElementById('mfa_toggle_container').classList.add('d-none');
                if (mfaCodeInput) {
                    mfaCodeInput.value = '';
                    mfaCodeInput.closest('.form-floating').classList.add('d-none');
                }
                backupCodeInput = document.getElementById('backup_code');
                backupCodeInput.focus();
            });
        }

        if (showMfaLink && backupContainer) {
            showMfaLink.addEventListener('click', function(e) {
                e.preventDefault();
                backupContainer.classList.add('d-none');
                document.getElementById('mfa_toggle_container').classList.remove('d-none');
                if (mfaCodeInput) {
                    mfaCodeInput.closest('.form-floating').classList.remove('d-none');
                    mfaCodeInput.focus();
                }
                if (backupCodeInput) backupCodeInput.value = '';
            });
        }

        // Formatting for backup code when visible
        document.addEventListener('input', function(e) {
            if (e.target && e.target.id === 'backup_code') {
                e.target.value = e.target.value.replace(/[^0-9]/g, '');
            }
        });
    </script>
</body>
</html>
