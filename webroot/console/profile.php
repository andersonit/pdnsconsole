<?php
/**
 * PDNS Console - User Profile & Security Settings
 * 
 * Comprehensive user profile management with MFA functionality
 * Allows users to update profile info, change passwords, and manage 2FA
 */

// Get classes (currentUser is already set by index.php)
$user = new User();
$mfa = new MFA();
$auditLog = new AuditLog();

// Check if user is logged in
if (!isset($currentUser['id'])) {
    header('Location: ?page=login');
    exit;
}

$userId = $currentUser['id'];
$success = '';
$error = '';
$mfaSetupData = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_profile':
                // Update user profile information
                $username = trim($_POST['username']);
                $email = trim($_POST['email']);
                
                if (empty($username) || empty($email)) {
                    throw new Exception('Username and email are required');
                }
                
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Please enter a valid email address');
                }
                
                // Check if username/email already exists (excluding current user)
                if ($user->usernameExists($username, $userId)) {
                    throw new Exception('Username already exists');
                }
                
                if ($user->emailExists($email, $userId)) {
                    throw new Exception('Email already exists');
                }
                
                // Update user
                if ($user->updateProfile($userId, $username, $email)) {
                    // Update session data
                    $_SESSION['user']['username'] = $username;
                    $_SESSION['user']['email'] = $email;
                    $currentUser['username'] = $username;
                    $currentUser['email'] = $email;
                    
                    $success = 'Profile updated successfully';
                } else {
                    throw new Exception('Failed to update profile');
                }
                break;
                
            case 'change_password':
                // Change user password
                $currentPassword = $_POST['current_password'];
                $newPassword = $_POST['new_password'];
                $confirmPassword = $_POST['confirm_password'];
                
                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    throw new Exception('All password fields are required');
                }
                
                if ($newPassword !== $confirmPassword) {
                    throw new Exception('New passwords do not match');
                }
                
                if (strlen($newPassword) < 8) {
                    throw new Exception('Password must be at least 8 characters long');
                }
                
                // Verify current password
                if (!$user->verifyPassword($userId, $currentPassword)) {
                    throw new Exception('Current password is incorrect');
                }
                
                // Change password
                if ($user->changePasswordDirect($userId, $newPassword)) {
                    $auditLog->logPasswordChanged($userId, $userId);
                    $success = 'Password changed successfully';
                } else {
                    throw new Exception('Failed to change password');
                }
                break;
                
            case 'setup_mfa':
                // Initialize MFA setup
                $mfaSetupData = $mfa->generateNewSecret($userId);
                $mfaSetupData['qr_code'] = $mfa->getQRCodeUrl($userId, $mfaSetupData['secret']);
                break;
                
            case 'verify_mfa':
                // Verify and enable MFA
                $code = trim($_POST['mfa_code']);
                
                if (empty($code)) {
                    throw new Exception('Please enter the verification code');
                }
                
                $result = $mfa->verifyAndEnable($userId, $code);
                if ($result && isset($result['backup_codes'])) {
                    $auditLog->logMFAEnabled($userId, $userId);
                    $success = '2FA has been enabled successfully!';
                    $newBackupCodes = $result['backup_codes']; // Show backup codes after successful verification
                } else {
                    throw new Exception('Invalid verification code. Please try again.');
                }
                break;
                
            case 'disable_mfa':
                // Disable MFA
                $password = $_POST['password'];
                
                if (empty($password)) {
                    throw new Exception('Password is required to disable 2FA');
                }
                
                if (!$user->verifyPassword($userId, $password)) {
                    throw new Exception('Incorrect password');
                }
                
                if ($mfa->disable($userId)) {
                    $auditLog->logMFADisabled($userId, $userId);
                    $success = '2FA has been disabled successfully';
                } else {
                    throw new Exception('Failed to disable 2FA');
                }
                break;
                
            case 'regenerate_backup_codes':
                // Regenerate backup codes
                if (!$mfa->isEnabled($userId)) {
                    throw new Exception('2FA is not enabled');
                }
                
                $password = $_POST['password'];
                
                if (empty($password)) {
                    throw new Exception('Password is required to regenerate backup codes');
                }
                
                if (!$user->verifyPassword($userId, $password)) {
                    throw new Exception('Incorrect password');
                }
                
                $newBackupCodes = $mfa->regenerateBackupCodes($userId);
                $success = 'New backup codes generated successfully';
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        
        // If MFA setup failed, get the setup data again for display
        if ($action === 'setup_mfa' && !empty($error)) {
            try {
                $mfaSetupData = $mfa->generateNewSecret($userId);
                $mfaSetupData['qr_code'] = $mfa->getQRCodeUrl($userId, $mfaSetupData['secret']);
            } catch (Exception $setupError) {
                $error .= ' Setup error: ' . $setupError->getMessage();
            }
        }
    }
}

// Get current user info
$userInfo = $user->getUserById($userId);
$mfaStatus = $mfa->getUserMFAStatus($userId);
$isMfaEnabled = $mfa->isEnabled($userId);
$backupCodesCount = $isMfaEnabled ? $mfa->getBackupCodesCount($userId) : 0;

// Get recent user activity
$recentActivity = $auditLog->getUserActivity($userId, 30);

// Page title
$pageTitle = 'User Profile';
?>

<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="container-fluid py-4">
    <?php
        // Breadcrumb: if super admin prepend System Administration link (handled by helper)
        // Decide root link/label for non-super admin (tenant admin) -> Zones, else just Profile under System Administration
        $isSuper = $user->isSuperAdmin($currentUser['id']);
        include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        if ($isSuper) {
            // Single item breadcrumb; helper will prepend System Administration automatically
            renderBreadcrumb([
                ['label' => 'Profile']
            ], true);
        } else {
            // No system admin prefix; show Zones -> Profile for tenant / regular users
            renderBreadcrumb([
                ['label' => 'Zones', 'url' => '?page=zone_manage'],
                ['label' => 'Profile']
            ], false, ['prependSystemAdmin' => false]);
        }
    ?>
    <!-- Page Header -->
    <div class="mb-4">
        <h1 class="h4 mb-1"><i class="bi bi-person-circle me-2 text-primary"></i>User Profile</h1>
        <p class="text-muted mb-0">Manage your account settings, security options, and profile information</p>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($newBackupCodes)): ?>
        <div class="alert alert-success">
            <h5><i class="bi bi-shield-check me-2"></i>2FA Enabled Successfully!</h5>
            <p>Your backup codes are ready. Click the button below to view and save them.</p>
            <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#backupCodesModal">
                <i class="bi bi-key me-1"></i>
                View Backup Codes
            </button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Profile Information -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-person me-2"></i>
                        Profile Information
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?php echo htmlspecialchars($userInfo['username']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($userInfo['email']); ?>" required>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-1"></i>
                                    Update Profile
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Password Change -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-key me-2"></i>
                        Change Password
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                            </div>
                            <div class="col-md-6">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" 
                                       minlength="8" required>
                                <div class="form-text">Minimum 8 characters</div>
                            </div>
                            <div class="col-md-6">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                       minlength="8" required>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-warning">
                                    <i class="bi bi-shield-lock me-1"></i>
                                    Change Password
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Two-Factor Authentication -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-shield-check me-2"></i>
                        Two-Factor Authentication
                    </h5>
                    <?php if ($isMfaEnabled): ?>
                        <span class="badge bg-success">Enabled</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Disabled</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$isMfaEnabled): ?>
                        <?php if (!$mfaSetupData): ?>
                            <!-- MFA Setup Introduction -->
                            <div class="mb-3">
                                <p class="text-muted">
                                    Two-factor authentication adds an extra layer of security to your account. 
                                    You'll need an authenticator app like Google Authenticator, Authy, or Microsoft Authenticator.
                                </p>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="setup_mfa">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-shield-plus me-1"></i>
                                    Set Up 2FA
                                </button>
                            </form>
                        <?php else: ?>
                            <!-- MFA Setup Process -->
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>1. Scan QR Code</h6>
                                    <p class="text-muted small">Scan this QR code with your authenticator app:</p>
                                    <div class="text-center mb-3">
                                        <img src="<?php echo $mfaSetupData['qr_code']; ?>" alt="2FA QR Code" class="img-fluid" style="max-width: 200px;">
                                    </div>
                                    <p class="text-muted small">
                                        <strong>Manual Entry:</strong><br>
                                        <code><?php echo $mfaSetupData['secret']; ?></code>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <h6>2. Verify Setup</h6>
                                    <p class="text-muted small">Enter the 6-digit code from your authenticator app to verify and enable 2FA:</p>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="verify_mfa">
                                        <div class="mb-3">
                                            <label for="mfa_code" class="form-label">Verification Code:</label>
                                            <input type="text" class="form-control" id="mfa_code" name="mfa_code" 
                                                   pattern="[0-9]{6}" maxlength="6" required>
                                        </div>
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-check-lg me-1"></i>
                                            Verify & Enable 2FA
                                        </button>
                                    </form>
                                    <div class="mt-3">
                                        <small class="text-muted">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Your backup codes will be shown after successful verification.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- MFA Enabled - Management Options -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center mb-3">
                                    <i class="bi bi-shield-check text-success me-2 fs-4"></i>
                                    <div>
                                        <strong>2FA is Active</strong><br>
                                        <small class="text-muted">
                                            Last used: <?php echo $mfaStatus['last_used'] ? date('M j, Y H:i', strtotime($mfaStatus['last_used'])) : 'Never'; ?>
                                        </small>
                                    </div>
                                </div>
                                <p class="text-muted">
                                    Backup codes remaining: <strong><?php echo $backupCodesCount; ?></strong>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#regenerateCodesModal">
                                        <i class="bi bi-arrow-clockwise me-1"></i>
                                        Generate New Backup Codes
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#disableMfaModal">
                                        <i class="bi bi-shield-x me-1"></i>
                                        Disable 2FA
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Account Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        Account Information
                    </h6>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <small class="text-muted">Role:</small><br>
                        <span class="badge bg-<?php echo $userInfo['role'] === 'super_admin' ? 'danger' : 'primary'; ?>">
                            <?php echo ucwords(str_replace('_', ' ', $userInfo['role'])); ?>
                        </span>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted">Account Created:</small><br>
                        <?php echo date('M j, Y', strtotime($userInfo['created_at'])); ?>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted">Last Login:</small><br>
                        <?php echo $userInfo['last_login'] ? date('M j, Y H:i', strtotime($userInfo['last_login'])) : 'Never'; ?>
                    </div>
                    <div>
                        <small class="text-muted">Status:</small><br>
                        <span class="badge bg-<?php echo $userInfo['is_active'] ? 'success' : 'danger'; ?>">
                            <?php echo $userInfo['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-activity me-2"></i>
                        Activity Summary (30 days)
                    </h6>
                </div>
                <div class="card-body">
                    <?php if ($recentActivity): ?>
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="fs-4 text-primary"><?php echo number_format($recentActivity['total_actions']); ?></div>
                                <small class="text-muted">Total Actions</small>
                            </div>
                            <div class="col-6">
                                <div class="fs-4 text-success"><?php echo number_format($recentActivity['creates']); ?></div>
                                <small class="text-muted">Created</small>
                            </div>
                            <div class="col-6 mt-2">
                                <div class="fs-4 text-warning"><?php echo number_format($recentActivity['updates']); ?></div>
                                <small class="text-muted">Updated</small>
                            </div>
                            <div class="col-6 mt-2">
                                <div class="fs-4 text-danger"><?php echo number_format($recentActivity['deletes']); ?></div>
                                <small class="text-muted">Deleted</small>
                            </div>
                        </div>
                        <?php if ($recentActivity['last_activity']): ?>
                            <hr>
                            <small class="text-muted">
                                Last activity: <?php echo date('M j, Y H:i', strtotime($recentActivity['last_activity'])); ?>
                            </small>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted text-center">No recent activity</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Disable MFA Modal -->
<div class="modal fade" id="disableMfaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="bi bi-shield-x me-2"></i>
                    Disable Two-Factor Authentication
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="disable_mfa">
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> Disabling 2FA will make your account less secure.
                    </div>
                    <div class="mb-3">
                        <label for="disable_password" class="form-label">Enter your password to confirm:</label>
                        <input type="password" class="form-control" id="disable_password" name="password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Disable 2FA</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Backup Codes Modal -->
<?php if (isset($newBackupCodes)): ?>
<div class="modal fade" id="backupCodesModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-shield-exclamation me-2"></i>
                    Your 2FA Backup Codes
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Important:</strong> Save these backup codes in a secure location. Each code can only be used once to access your account if you lose your authenticator device.
                </div>
                
                <div class="row g-2 mb-3" id="backupCodesList">
                    <?php foreach ($newBackupCodes as $index => $code): ?>
                        <div class="col-md-6">
                            <div class="bg-light p-2 rounded text-center">
                                <code><?php echo $code; ?></code>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-primary" onclick="copyAllCodes()">
                        <i class="bi bi-clipboard-check me-1"></i>
                        Copy All Codes
                    </button>
                    <button type="button" class="btn btn-success" onclick="downloadCodes()">
                        <i class="bi bi-download me-1"></i>
                        Download as Text File
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">I've Saved My Codes</button>
            </div>
        </div>
    </div>
</div>

<script>
// Backup codes array for JavaScript functions
const backupCodes = <?php echo json_encode($newBackupCodes); ?>;
</script>
<?php endif; ?>

<!-- Regenerate Backup Codes Modal -->
<div class="modal fade" id="regenerateCodesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-warning">
                    <i class="bi bi-arrow-clockwise me-2"></i>
                    Generate New Backup Codes
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="regenerate_backup_codes">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        This will replace your existing backup codes. Make sure to save the new ones.
                    </div>
                    <div class="mb-3">
                        <label for="regenerate_password" class="form-label">Enter your password to confirm:</label>
                        <input type="password" class="form-control" id="regenerate_password" name="password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Generate New Codes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Copy all backup codes to clipboard
function copyAllCodes() {
    const allCodes = backupCodes.join('\n');
    const textWithHeader = "PDNS Console 2FA Backup Codes\n" + 
                          "Generated: " + new Date().toLocaleString() + "\n" +
                          "Account: <?php echo htmlspecialchars($currentUser['username']); ?>\n\n" +
                          allCodes + "\n\n" +
                          "Keep these codes secure and use them only when you cannot access your authenticator app.";
    
    navigator.clipboard.writeText(textWithHeader).then(function() {
        const button = event.target;
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="bi bi-check-lg me-1"></i>Copied!';
        button.classList.remove('btn-primary');
        button.classList.add('btn-success');
        setTimeout(() => {
            button.innerHTML = originalText;
            button.classList.remove('btn-success');
            button.classList.add('btn-primary');
        }, 3000);
    });
}

// Download backup codes as text file
function downloadCodes() {
    const allCodes = backupCodes.join('\n');
    const content = "PDNS Console 2FA Backup Codes\n" + 
                   "Generated: " + new Date().toLocaleString() + "\n" +
                   "Account: <?php echo htmlspecialchars($currentUser['username']); ?>\n\n" +
                   allCodes + "\n\n" +
                   "Keep these codes secure and use them only when you cannot access your authenticator app.";
    
    const blob = new Blob([content], { type: 'text/plain' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'pdns-console-backup-codes-' + new Date().toISOString().split('T')[0] + '.txt';
    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
    document.body.removeChild(a);
}

// Auto-show backup codes modal if it exists
if (document.getElementById('backupCodesModal')) {
    const modal = new bootstrap.Modal(document.getElementById('backupCodesModal'));
    modal.show();
}

// Password confirmation validation
document.getElementById('confirm_password').addEventListener('input', function() {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = this.value;
    
    if (confirmPassword && newPassword !== confirmPassword) {
        this.setCustomValidity('Passwords do not match');
    } else {
        this.setCustomValidity('');
    }
});

// MFA code input formatting
const mfaCodeInput = document.getElementById('mfa_code');
if (mfaCodeInput) {
    mfaCodeInput.addEventListener('input', function() {
        // Remove non-digits
        this.value = this.value.replace(/[^0-9]/g, '');
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
