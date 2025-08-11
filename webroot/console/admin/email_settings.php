<?php
/**
 * PDNS Console - Email Settings (Super Admin Only)
 */

$user = new User();
$settings = new Settings();

if (!$user->isSuperAdmin($currentUser['id'])) {
    header('Location: /?page=dashboard');
    exit;
}

$pageTitle = 'Email Settings';
$branding = $settings->getBranding();
$db = Database::getInstance();
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = 'Security token mismatch. Please try again.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'update_email_settings') {
            try {
                $emailSettings = [
                    'smtp_host' => trim($_POST['smtp_host'] ?? ''),
                    'smtp_port' => intval($_POST['smtp_port'] ?? 587),
                    'smtp_secure' => trim($_POST['smtp_secure'] ?? 'starttls'),
                    'smtp_username' => trim($_POST['smtp_username'] ?? ''),
                    'smtp_password' => trim($_POST['smtp_password'] ?? ''),
                    'smtp_from_email' => trim($_POST['smtp_from_email'] ?? ''),
                    'smtp_from_name' => trim($_POST['smtp_from_name'] ?? '')
                ];

                if (empty($emailSettings['smtp_host'])) throw new Exception('SMTP host is required.');
                if ($emailSettings['smtp_port'] < 1 || $emailSettings['smtp_port'] > 65535) throw new Exception('SMTP port must be between 1 and 65535.');
                if (!in_array($emailSettings['smtp_secure'], ['starttls', 'tls', 'ssl', ''])) throw new Exception('Invalid SMTP security type.');
                if (empty($emailSettings['smtp_from_email']) || !filter_var($emailSettings['smtp_from_email'], FILTER_VALIDATE_EMAIL)) throw new Exception('Valid from email address is required.');
                if (empty($emailSettings['smtp_from_name'])) throw new Exception('From name is required.');

                $result = $settings->updateEmailSettings($emailSettings);
                if ($result) {
                    $message = 'Email settings updated successfully.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Failed to update email settings.');
                }
            } catch (Exception $e) {
                $message = $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

$emailSettings = $settings->getEmailSettings();

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>
<div class="container-fluid mt-4">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        renderBreadcrumb([[ 'label' => 'Email Settings' ]], true);
    ?>
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-1"><i class="bi bi-envelope me-2"></i>Email Settings</h1>
            <p class="text-muted mb-0">SMTP configuration for password reset and system emails</p>
        </div>
    </div>
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show"><span><?php echo htmlspecialchars($message); ?></span><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <div class="row">
        <div class="col-lg-7 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0"><h6 class="mb-0">SMTP Configuration</h6><small class="text-muted">Connection & identity</small></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_email_settings">
                        <div class="mb-3">
                            <label class="form-label" for="smtp_host">SMTP Host *</label>
                            <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?php echo htmlspecialchars($emailSettings['smtp_host']); ?>" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="smtp_port">SMTP Port *</label>
                                <input type="number" class="form-control" id="smtp_port" name="smtp_port" value="<?php echo htmlspecialchars($emailSettings['smtp_port']); ?>" min="1" max="65535" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="smtp_secure">Security *</label>
                                <select class="form-select" id="smtp_secure" name="smtp_secure" required>
                                    <option value="starttls"<?php echo $emailSettings['smtp_secure'] === 'starttls' ? ' selected' : ''; ?>>STARTTLS (recommended)</option>
                                    <option value="tls"<?php echo $emailSettings['smtp_secure'] === 'tls' ? ' selected' : ''; ?>>TLS</option>
                                    <option value="ssl"<?php echo $emailSettings['smtp_secure'] === 'ssl' ? ' selected' : ''; ?>>SSL</option>
                                    <option value=""<?php echo $emailSettings['smtp_secure'] === '' ? ' selected' : ''; ?>>None</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="smtp_username">SMTP Username</label>
                            <input type="text" class="form-control" id="smtp_username" name="smtp_username" value="<?php echo htmlspecialchars($emailSettings['smtp_username']); ?>">
                            <small class="text-muted">Leave blank if no authentication required</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="smtp_password">SMTP Password</label>
                            <input type="password" class="form-control" id="smtp_password" name="smtp_password" value="<?php echo htmlspecialchars($emailSettings['smtp_password']); ?>">
                            <small class="text-muted">Leave blank to keep current password</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="smtp_from_email">From Email Address *</label>
                            <input type="email" class="form-control" id="smtp_from_email" name="smtp_from_email" value="<?php echo htmlspecialchars($emailSettings['smtp_from_email']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="smtp_from_name">From Name *</label>
                            <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" value="<?php echo htmlspecialchars($emailSettings['smtp_from_name']); ?>" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Update Email Settings</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
