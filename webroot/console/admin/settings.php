<?php
/**
 * PDNS Console - DNS Settings Management (Super Admin Only)
 */

// Get required classes
$user = new User();
$settings = new Settings();

// Check if user is super admin
if (!$user->isSuperAdmin($currentUser['id'])) {
    header('Location: /?page=dashboard');
    exit;
}

$pageTitle = 'System Settings';
$branding = $settings->getBranding();
$db = Database::getInstance();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = 'Security token mismatch. Please try again.';
        $messageType = 'danger';
    } else {
    $action = $_POST['action'] ?? '';
    
    try {
        
    if ($action === 'update_system_settings') {
            $oldMaint = $settings->get('maintenance_mode', '0');
            $systemSettings = [
                'session_timeout' => intval($_POST['session_timeout'] ?? 3600),
                'max_login_attempts' => intval($_POST['max_login_attempts'] ?? 5),
                'default_tenant_domains' => intval($_POST['default_tenant_domains'] ?? 0),
                'records_per_page' => intval($_POST['records_per_page'] ?? 25),
                'timezone' => trim($_POST['timezone'] ?? 'UTC'),
                'max_upload_size' => intval($_POST['max_upload_size'] ?? 5242880),
                'dnssec_hold_period_days' => intval($_POST['dnssec_hold_period_days'] ?? 7),
                'allowed_logo_types' => trim($_POST['allowed_logo_types'] ?? 'image/jpeg,image/png,image/gif'),
                'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0'
            ];

            // Save CAPTCHA settings
            $captcha_provider = $_POST['captcha_provider'] ?? 'none';
            $recaptcha_site_key = trim($_POST['recaptcha_site_key'] ?? '');
            $recaptcha_secret_key = trim($_POST['recaptcha_secret_key'] ?? '');
            $turnstile_site_key = trim($_POST['turnstile_site_key'] ?? '');
            $turnstile_secret_key = trim($_POST['turnstile_secret_key'] ?? '');

            $db->execute("INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES ('captcha_provider', ?, 'Login CAPTCHA provider', 'security') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$captcha_provider]);
            $db->execute("INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES ('recaptcha_site_key', ?, 'Google reCAPTCHA site key', 'security') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$recaptcha_site_key]);
            $db->execute("INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES ('recaptcha_secret_key', ?, 'Google reCAPTCHA secret key', 'security') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$recaptcha_secret_key]);
            $db->execute("INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES ('turnstile_site_key', ?, 'Cloudflare Turnstile site key', 'security') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$turnstile_site_key]);
            $db->execute("INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES ('turnstile_secret_key', ?, 'Cloudflare Turnstile secret key', 'security') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$turnstile_secret_key]);

        // PowerDNS API optional settings
            $pdnsApiHost = trim($_POST['pdns_api_host'] ?? '');
            $pdnsApiPort = trim($_POST['pdns_api_port'] ?? '8081');
            $pdnsApiServerId = trim($_POST['pdns_api_server_id'] ?? 'localhost');
            $pdnsApiKey = $_POST['pdns_api_key'] ?? ''; // allow blank to keep existing
            
            // Validate values
            if ($systemSettings['session_timeout'] < 300) {
                throw new Exception('Session timeout must be at least 300 seconds (5 minutes).');
            }
            
            if ($systemSettings['max_login_attempts'] < 1) {
                throw new Exception('Max login attempts must be at least 1.');
            }
            
            if (!in_array($systemSettings['records_per_page'], [10, 25, 50, 100])) {
                throw new Exception('Records per page must be 10, 25, 50, or 100.');
            }
            
            // Validate timezone
            if (!in_array($systemSettings['timezone'], timezone_identifiers_list())) {
                throw new Exception('Invalid timezone selected.');
            }
            
            // Validate upload size (minimum 1MB, maximum 50MB)
            if ($systemSettings['max_upload_size'] < 1048576 || $systemSettings['max_upload_size'] > 52428800) {
                throw new Exception('Max upload size must be between 1MB and 50MB.');
            }
            
            // Validate allowed logo types
            $allowedTypes = explode(',', $systemSettings['allowed_logo_types']);
            $validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
            foreach ($allowedTypes as $type) {
                $type = trim($type);
                if (!in_array($type, $validTypes)) {
                    throw new Exception('Invalid file type: ' . $type . '. Allowed types: ' . implode(', ', $validTypes));
                }
            }
            
            // Validate PDNS API host (allow empty, IP, hostname)
            if ($pdnsApiHost !== '' && !filter_var($pdnsApiHost, FILTER_VALIDATE_IP) && !preg_match('/^[A-Za-z0-9.-]+$/', $pdnsApiHost)) {
                throw new Exception('Invalid PowerDNS API Host/IP.');
            }
            if ($pdnsApiPort !== '' && (!ctype_digit($pdnsApiPort) || (int)$pdnsApiPort < 1 || (int)$pdnsApiPort > 65535)) {
                throw new Exception('Invalid PowerDNS API Port.');
            }
            if ($pdnsApiServerId !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $pdnsApiServerId)) {
                throw new Exception('Invalid PowerDNS Server ID.');
            }

            // Validate DNSSEC hold period (1-60 days reasonable)
            if ($systemSettings['dnssec_hold_period_days'] < 1 || $systemSettings['dnssec_hold_period_days'] > 60) {
                throw new Exception('DNSSEC hold period must be between 1 and 60 days.');
            }

            // Update all system settings
            foreach ($systemSettings as $key => $value) {
                $db->execute(
                    "UPDATE global_settings SET setting_value = ? WHERE setting_key = ?",
                    [$value, $key]
                );
            }

            // Audit maintenance toggle explicitly if changed
            if (($oldMaint === '1' && $systemSettings['maintenance_mode'] === '0') || ($oldMaint === '0' && $systemSettings['maintenance_mode'] === '1')) {
                $audit = $audit ?? new AuditLog();
                if (isset($_SESSION['user_id'])) {
                    $audit->logMaintenanceToggle($_SESSION['user_id'], $systemSettings['maintenance_mode'] === '1', $oldMaint === '1');
                    $audit->logSettingUpdated($_SESSION['user_id'], 'maintenance_mode', $oldMaint, $systemSettings['maintenance_mode']);
                }
            }

            // Upsert PowerDNS API host/port (empty host clears both host and key)
            $audit = new AuditLog();
            $userIdForAudit = $_SESSION['user_id'] ?? null;
            $oldHost = $systemSettings['pdns_api_host'] ?? '';
            $oldPort = $systemSettings['pdns_api_port'] ?? '';
            $oldServerId = $systemSettings['pdns_api_server_id'] ?? '';

            if ($pdnsApiHost === '') {
                $db->execute("DELETE FROM global_settings WHERE setting_key IN ('pdns_api_host','pdns_api_port','pdns_api_server_id','pdns_api_key_enc')");
                if ($userIdForAudit && ($oldHost || $oldPort || $oldServerId)) {
                    $audit->logSettingUpdated($userIdForAudit, 'pdns_api_cleared', 'configured', 'removed');
                }
            } else {
                $db->execute("INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES ('pdns_api_host', ?, 'PowerDNS API host or IP', 'dns') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$pdnsApiHost]);
                $db->execute("INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES ('pdns_api_port', ?, 'PowerDNS API port', 'dns') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$pdnsApiPort]);
                $db->execute("INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES ('pdns_api_server_id', ?, 'PowerDNS server-id path segment', 'dns') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$pdnsApiServerId]);
                if (trim($pdnsApiKey) !== '') {
                    $enc = new Encryption();
                    $encKey = $enc->encrypt($pdnsApiKey);
                    $db->execute("INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES ('pdns_api_key_enc', ?, 'Encrypted PowerDNS API key', 'dns') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$encKey]);
                    if ($userIdForAudit) { $audit->logSettingUpdated($userIdForAudit, 'pdns_api_key_enc', '[hidden]', '[updated]'); }
                }
                if ($userIdForAudit) {
                    if ($oldHost !== $pdnsApiHost) { $audit->logSettingUpdated($userIdForAudit, 'pdns_api_host', $oldHost, $pdnsApiHost); }
                    if ($oldPort !== $pdnsApiPort) { $audit->logSettingUpdated($userIdForAudit, 'pdns_api_port', $oldPort, $pdnsApiPort); }
                    if ($oldServerId !== $pdnsApiServerId) { $audit->logSettingUpdated($userIdForAudit, 'pdns_api_server_id', $oldServerId, $pdnsApiServerId); }
                }
            }
            
            $message = 'System settings updated successfully.';
            $messageType = 'success';
        }
        
        

        if ($action === 'update_license_key') {
            $newKey = trim($_POST['license_key'] ?? '');
            $old = $db->fetch("SELECT setting_value FROM global_settings WHERE setting_key='license_key'");
            $oldVal = $old['setting_value'] ?? '';
            if ($newKey === '') {
                // Remove key (revert to free)
                $db->execute("DELETE FROM global_settings WHERE setting_key='license_key'");
                if (isset($_SESSION['user_id'])) {
                    (new AuditLog())->logSettingUpdated($_SESSION['user_id'], 'license_key', $oldVal, '(cleared)');
                }
                $message = 'License key removed. System is now in Free mode (5 domains).';
                $messageType = 'success';
            } else {
                // Upsert license key
                $exists = $oldVal !== '';
                if ($exists) {
                    $db->execute("UPDATE global_settings SET setting_value=? WHERE setting_key='license_key'", [$newKey]);
                } else {
                    $db->execute("INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES ('license_key', ?, 'Installed license key', 'licensing')", [$newKey]);
                }
                if (class_exists('LicenseManager')) {
                    // Force cache reset
                    // Simple approach: instantiate and call getStatus (which repopulates cache); static props will refresh next call
                    LicenseManager::getStatus();
                    $status = LicenseManager::getStatus();
                    if (!$status['valid']) {
                        $message = 'License key saved but invalid (code ' . htmlspecialchars($status['reason'] ?? 'unknown') . '). Operating in Free mode.';
                        $messageType = 'warning';
                    } else {
                        $message = 'License key validated and saved (' . ($status['license_type'] === 'commercial' ? ($status['unlimited'] ? 'Commercial Unlimited' : 'Commercial Limit: ' . ($status['max_domains'] ?? '')) : 'Free') . ').';
                        $messageType = 'success';
                    }
                } else {
                    $message = 'License key saved.';
                    $messageType = 'success';
                }
                if (isset($_SESSION['user_id'])) {
                    (new AuditLog())->logSettingUpdated($_SESSION['user_id'], 'license_key', $oldVal, $newKey);
                }
            }
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
    }
}

// Get current system settings
$systemSettings = [];
$systemKeys = ['session_timeout', 'max_login_attempts', 'default_tenant_domains', 'records_per_page', 'timezone', 'max_upload_size', 'allowed_logo_types', 'dnssec_hold_period_days', 'maintenance_mode', 'pdns_api_host', 'pdns_api_port', 'pdns_api_server_id', 'pdns_api_key_enc'];

foreach ($systemKeys as $key) {
    $setting = $db->fetch(
        "SELECT setting_value FROM global_settings WHERE setting_key = ?",
        [$key]
    );
    $systemSettings[$key] = $setting['setting_value'] ?? '';
}

// Fetch CAPTCHA settings with safe defaults
$captcha_provider = $settings->get('captcha_provider', 'none');
$recaptcha_site_key = $settings->get('recaptcha_site_key', '');
$recaptcha_secret_key = $settings->get('recaptcha_secret_key', '');
$turnstile_site_key = $settings->get('turnstile_site_key', '');
$turnstile_secret_key = $settings->get('turnstile_secret_key', '');

// Decrypt API key for masked display (never show full key)
$pdnsApiKeyMasked = '';
$pdnsApiKeyPlain = '';
if (!empty($systemSettings['pdns_api_key_enc'])) {
    try {
        $enc = new Encryption();
        $realKey = $enc->decrypt($systemSettings['pdns_api_key_enc']);
        $pdnsApiKeyPlain = $realKey;
        if (strlen($realKey) > 6) {
            $pdnsApiKeyMasked = substr($realKey, 0, 3) . str_repeat('*', max(3, strlen($realKey)-6)) . substr($realKey, -3);
        } else {
            $pdnsApiKeyMasked = str_repeat('*', strlen($realKey));
        }
    } catch (Exception $e) {
        $pdnsApiKeyMasked = '[decryption error]';
    }
}

// DNS & Email forms moved to dedicated pages

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid mt-4">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        renderBreadcrumb([
            ['label' => 'System Settings']
        ], true);
    ?>
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-1"><i class="bi bi-gear-fill me-2"></i>System Settings</h1>
            <p class="text-muted mb-0">Security, pagination and upload preferences</p>
        </div>
    </div>

    <!-- Messages -->
    <?php if (!empty($message)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- System Settings Only (DNS & Email moved to dedicated pages) -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-sliders me-2"></i>
                        System Settings
                    </h6>
                    <small class="text-muted">Security and display preferences</small>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_system_settings">

                        <div class="mb-3">
                            <label for="session_timeout" class="form-label">Session Timeout (seconds)</label>
                            <input type="number" class="form-control" id="session_timeout" name="session_timeout" value="<?php echo htmlspecialchars($systemSettings['session_timeout']); ?>" min="300" required>
                            <small class="text-muted">How long users stay logged in (minimum 300 seconds)</small>
                        </div>

                        <div class="mb-3">
                            <label for="default_tenant_domains" class="form-label">Default Tenant Domain Limit</label>
                            <input type="number" class="form-control" id="default_tenant_domains" name="default_tenant_domains" value="<?php echo htmlspecialchars($systemSettings['default_tenant_domains']); ?>" min="0" required>
                            <small class="text-muted">Default maximum domains per tenant (0 = unlimited)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="records_per_page" class="form-label">Default Records Per Page</label>
                            <select class="form-select" id="records_per_page" name="records_per_page" required>
                                <option value="10"<?php echo $systemSettings['records_per_page'] == '10' ? ' selected' : ''; ?>>10 records</option>
                                <option value="25"<?php echo $systemSettings['records_per_page'] == '25' ? ' selected' : ''; ?>>25 records</option>
                                <option value="50"<?php echo $systemSettings['records_per_page'] == '50' ? ' selected' : ''; ?>>50 records</option>
                                <option value="100"<?php echo $systemSettings['records_per_page'] == '100' ? ' selected' : ''; ?>>100 records</option>
                            </select>
                            <small class="text-muted">Number of records to display per page in tables</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="timezone" class="form-label">System Timezone</label>
                            <select class="form-select" id="timezone" name="timezone" required>
                                <optgroup label="Common Timezones">
                                    <option value="UTC"<?php echo $systemSettings['timezone'] === 'UTC' ? ' selected' : ''; ?>>UTC (Coordinated Universal Time)</option>
                                    <option value="America/New_York"<?php echo $systemSettings['timezone'] === 'America/New_York' ? ' selected' : ''; ?>>Eastern Time (US)</option>
                                    <option value="America/Chicago"<?php echo $systemSettings['timezone'] === 'America/Chicago' ? ' selected' : ''; ?>>Central Time (US)</option>
                                    <option value="America/Denver"<?php echo $systemSettings['timezone'] === 'America/Denver' ? ' selected' : ''; ?>>Mountain Time (US)</option>
                                    <option value="America/Los_Angeles"<?php echo $systemSettings['timezone'] === 'America/Los_Angeles' ? ' selected' : ''; ?>>Pacific Time (US)</option>
                                    <option value="Europe/London"<?php echo $systemSettings['timezone'] === 'Europe/London' ? ' selected' : ''; ?>>London (GMT/BST)</option>
                                    <option value="Europe/Paris"<?php echo $systemSettings['timezone'] === 'Europe/Paris' ? ' selected' : ''; ?>>Central European Time</option>
                                    <option value="Asia/Tokyo"<?php echo $systemSettings['timezone'] === 'Asia/Tokyo' ? ' selected' : ''; ?>>Japan Standard Time</option>
                                    <option value="Australia/Sydney"<?php echo $systemSettings['timezone'] === 'Australia/Sydney' ? ' selected' : ''; ?>>Australian Eastern Time</option>
                                </optgroup>
                                <optgroup label="All Timezones">
                                    <?php 
                                    $timezones = timezone_identifiers_list();
                                    $commonZones = ['UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Europe/London', 'Europe/Paris', 'Asia/Tokyo', 'Australia/Sydney'];
                                    foreach ($timezones as $tz) {
                                        if (!in_array($tz, $commonZones)) {
                                            $selected = $systemSettings['timezone'] === $tz ? ' selected' : '';
                                            echo "<option value=\"{$tz}\"{$selected}>{$tz}</option>";
                                        }
                                    }
                                    ?>
                                </optgroup>
                            </select>
                            <small class="text-muted">Timezone used for displaying dates and times</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="max_upload_size" class="form-label">Max Upload Size (bytes)</label>
                            <select class="form-select" id="max_upload_size" name="max_upload_size" required>
                                <option value="1048576"<?php echo $systemSettings['max_upload_size'] == '1048576' ? ' selected' : ''; ?>>1 MB</option>
                                <option value="2097152"<?php echo $systemSettings['max_upload_size'] == '2097152' ? ' selected' : ''; ?>>2 MB</option>
                                <option value="5242880"<?php echo $systemSettings['max_upload_size'] == '5242880' ? ' selected' : ''; ?>>5 MB</option>
                                <option value="10485760"<?php echo $systemSettings['max_upload_size'] == '10485760' ? ' selected' : ''; ?>>10 MB</option>
                                <option value="20971520"<?php echo $systemSettings['max_upload_size'] == '20971520' ? ' selected' : ''; ?>>20 MB</option>
                                <option value="52428800"<?php echo $systemSettings['max_upload_size'] == '52428800' ? ' selected' : ''; ?>>50 MB</option>
                            </select>
                            <small class="text-muted">Maximum file size for logo uploads</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="allowed_logo_types" class="form-label">Allowed Logo File Types</label>
                            <div class="row">
                                <div class="col-sm-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="type_jpeg" value="image/jpeg" <?php echo strpos($systemSettings['allowed_logo_types'], 'image/jpeg') !== false ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="type_jpeg">JPEG (.jpg, .jpeg)</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="type_png" value="image/png" <?php echo strpos($systemSettings['allowed_logo_types'], 'image/png') !== false ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="type_png">PNG (.png)</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="type_gif" value="image/gif" <?php echo strpos($systemSettings['allowed_logo_types'], 'image/gif') !== false ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="type_gif">GIF (.gif)</label>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="type_webp" value="image/webp" <?php echo strpos($systemSettings['allowed_logo_types'], 'image/webp') !== false ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="type_webp">WebP (.webp)</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="type_svg" value="image/svg+xml" <?php echo strpos($systemSettings['allowed_logo_types'], 'image/svg+xml') !== false ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="type_svg">SVG (.svg)</label>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" id="allowed_logo_types" name="allowed_logo_types" value="<?php echo htmlspecialchars($systemSettings['allowed_logo_types']); ?>">
                            <small class="text-muted">Select which file types are allowed for logo uploads</small>
                        </div>
                        <div class="mb-3">
                            <label for="dnssec_hold_period_days" class="form-label">DNSSEC Timed Rollover Hold (days)</label>
                            <input type="number" class="form-control" id="dnssec_hold_period_days" name="dnssec_hold_period_days" value="<?php echo htmlspecialchars($systemSettings['dnssec_hold_period_days'] ?: '7'); ?>" min="1" max="60">
                            <small class="text-muted">Days to keep both old and new keys active during a timed rollover before retiring the old key. Align with parent DS TTL.</small>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="maintenance_mode" name="maintenance_mode" value="1" <?php echo ($systemSettings['maintenance_mode'] ?? '0') === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="maintenance_mode">Maintenance Mode</label>
                            <div class="form-text">When enabled, only super administrators can log in. All other login attempts are blocked with a 503 response and a notice on the login page.</div>
                        </div>
                        <hr>
                        <h6 class="fw-semibold mb-3"><i class="bi bi-key me-1"></i>Login CAPTCHA / Human Verification</h6>
                        <div class="mb-3">
                            <label class="form-label">CAPTCHA Provider</label>
                            <select class="form-select" name="captcha_provider" id="captcha_provider">
                                <option value="none" <?php if ($captcha_provider === 'none') echo 'selected'; ?>>None</option>
                                <option value="turnstile" <?php if ($captcha_provider === 'turnstile') echo 'selected'; ?>>Cloudflare Turnstile</option>
                                <option value="recaptcha" <?php if ($captcha_provider === 'recaptcha') echo 'selected'; ?>>Google reCAPTCHA</option>
                            </select>
                        </div>
                        <div class="mb-3" id="recaptcha_keys" style="display: <?php echo ($captcha_provider === 'recaptcha') ? 'block' : 'none'; ?>;">
                            <label class="form-label">reCAPTCHA Site Key</label>
                            <input type="text" class="form-control" name="recaptcha_site_key" value="<?php echo htmlspecialchars($recaptcha_site_key); ?>">
                            <label class="form-label mt-2">reCAPTCHA Secret Key</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="recaptcha_secret_key" id="recaptcha_secret_key" value="<?php echo htmlspecialchars($recaptcha_secret_key); ?>">
                                <button class="btn btn-outline-secondary" type="button" id="toggleRecaptchaSecret"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-3" id="turnstile_keys" style="display: <?php echo ($captcha_provider === 'turnstile') ? 'block' : 'none'; ?>;">
                            <label class="form-label">Turnstile Site Key</label>
                            <input type="text" class="form-control" name="turnstile_site_key" value="<?php echo htmlspecialchars($turnstile_site_key); ?>">
                            <label class="form-label mt-2">Turnstile Secret Key</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="turnstile_secret_key" id="turnstile_secret_key" value="<?php echo htmlspecialchars($turnstile_secret_key); ?>">
                                <button class="btn btn-outline-secondary" type="button" id="toggleTurnstileSecret"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <script>
                        document.getElementById('captcha_provider').addEventListener('change', function() {
                            document.getElementById('recaptcha_keys').style.display = (this.value === 'recaptcha') ? 'block' : 'none';
                            document.getElementById('turnstile_keys').style.display = (this.value === 'turnstile') ? 'block' : 'none';
                        });

                        // Toggle show/hide for secret keys
                        function setupSecretToggle(inputId, btnId) {
                            var input = document.getElementById(inputId);
                            var btn = document.getElementById(btnId);
                            if (input && btn) {
                                btn.addEventListener('click', function() {
                                    if (input.type === 'password') {
                                        input.type = 'text';
                                        btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
                                    } else {
                                        input.type = 'password';
                                        btn.innerHTML = '<i class="bi bi-eye"></i>';
                                    }
                                });
                            }
                        }
                        setupSecretToggle('recaptcha_secret_key', 'toggleRecaptchaSecret');
                        setupSecretToggle('turnstile_secret_key', 'toggleTurnstileSecret');
                        </script>
                        
                        <div class="mb-4">
                            <hr>
                            <h6 class="fw-semibold mb-3"><i class="bi bi-link-45deg me-1"></i>PowerDNS API (Optional, but required for DNSSEC)</h6>
                            <div class="form-text mb-2">Used for DNSSEC key generation, rectification & signed zone operations. All settings in PowerDNS Server pdns.conf file.
                                Must enable 'api=yes', 'webserver=yes', then set 'api-key', 'webserver-address' and 'webserver-port'.
                                Use setting 'webserver-allow-from' to restrict access to IP of PDNS Console.</div>
                            <div class="mb-3">
                                <label for="pdns_api_host" class="form-label">API Hostname / IP</label>
                                <input type="text" class="form-control" id="pdns_api_host" name="pdns_api_host" placeholder="127.0.0.1 or pdns.internal" value="<?php echo htmlspecialchars($systemSettings['pdns_api_host'] ?? ''); ?>">
                                <small class="text-muted">Leave blank to disable API integration. Hostname or IP only.</small>
                            </div>
                            <div class="row g-3 align-items-end">
                                <div class="col-sm-3">
                                    <label for="pdns_api_port" class="form-label">Port</label>
                                    <input type="number" class="form-control" id="pdns_api_port" name="pdns_api_port" value="<?php echo htmlspecialchars($systemSettings['pdns_api_port'] ?: '8081'); ?>" min="1" max="65535">
                                </div>
                                <div class="col-sm-3">
                                    <label for="pdns_api_server_id" class="form-label">Server ID</label>
                                    <input type="text" class="form-control" id="pdns_api_server_id" name="pdns_api_server_id" value="<?php echo htmlspecialchars($systemSettings['pdns_api_server_id'] ?: 'localhost'); ?>" placeholder="localhost">
                                </div>
                                <div class="col-sm-4">
                                    <label for="pdns_api_key" class="form-label">API Key</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="pdns_api_key" name="pdns_api_key" placeholder="<?php echo $pdnsApiKeyMasked ? 'Stored: '.$pdnsApiKeyMasked : 'Enter API Key'; ?>" aria-describedby="pdnsKeyToggle" data-secret="<?php echo htmlspecialchars($pdnsApiKeyPlain); ?>" autocomplete="off">
                                        <button class="btn btn-outline-secondary" type="button" id="pdnsKeyToggle" aria-label="Show API key" data-state="hidden">
                                            <i class="bi bi-eye" id="pdnsKeyToggleIcon"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-sm-2 text-sm-start">
                                    <button type="button" class="btn btn-outline-primary w-100" id="btnTestPdns" style="margin-top:2px;">
                                        <i class="bi bi-plug"></i>
                                        <span class="d-none d-lg-inline"> Test</span>
                                    </button>
                                </div>
                            </div>
                            <div class="mt-2">
                                <span id="pdnsTestResult" class="small text-muted"></span>
                            </div>
                            <small class="text-muted d-block mt-2">Clearing hostname/IP removes all PDNS settings. Save settings before testing.</small>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-lg me-1"></i>
                                Update System Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
    </div>

    <!-- Summary removed (DNS & Email moved) -->
</div>

<script>
// Handle logo type checkboxes (DNS/Email scripts removed)
document.addEventListener('DOMContentLoaded', function() {
    const logoTypeCheckboxes = document.querySelectorAll('input[type="checkbox"][value^="image/"]');
    const hiddenLogoTypesInput = document.getElementById('allowed_logo_types');
    function updateLogoTypes() { hiddenLogoTypesInput.value = Array.from(logoTypeCheckboxes).filter(cb=>cb.checked).map(cb=>cb.value).join(','); }
    logoTypeCheckboxes.forEach(cb=>cb.addEventListener('change', updateLogoTypes));
    updateLogoTypes();

    const testBtn = document.getElementById('btnTestPdns');
    if (testBtn) {
        testBtn.addEventListener('click', function(){
            const host = document.getElementById('pdns_api_host').value.trim();
            const resultEl = document.getElementById('pdnsTestResult');
            resultEl.textContent = 'Testing...';
            resultEl.className = 'ms-2 small text-muted';
            if(!host){ resultEl.textContent='Host required'; resultEl.className='ms-2 small text-danger'; return; }
            const fd = new FormData();
            fd.append('action','test_connection');
            // Use absolute path to avoid relative directory issues
            fetch('/api/pdns.php', { method:'POST', body: fd, credentials:'same-origin' })
              .then(async r => {
                  const text = await r.text();
                  let j = null; let parseErr = null;
                  try { j = JSON.parse(text); } catch(e){ parseErr = e; }
                  if (!j) {
                     resultEl.textContent = 'Unexpected response (see console)';
                     resultEl.className='ms-2 small text-danger';
                     console.error('PDNS test raw response:', text, parseErr);
                     return;
                  }
                  if(j.success){
                      resultEl.textContent='Connection OK';
                      resultEl.className='ms-2 small text-success';
                  } else {
                      resultEl.textContent='Failed: '+(j.error||j.message||'Unknown error');
                      resultEl.className='ms-2 small text-danger';
                  }
              })
              .catch(e=>{ resultEl.textContent='Error: '+e; resultEl.className='ms-2 small text-danger'; });
        });
    }

    const keyToggleBtn = document.getElementById('pdnsKeyToggle');
    const keyInput = document.getElementById('pdns_api_key');
    const keyIcon = document.getElementById('pdnsKeyToggleIcon');
    if (keyToggleBtn && keyInput && keyIcon) {
        const originalPlaceholder = keyInput.getAttribute('placeholder');
        keyToggleBtn.addEventListener('click', function(){
            const hidden = keyInput.type === 'password';
            keyInput.type = hidden ? 'text' : 'password';
            keyToggleBtn.setAttribute('aria-label', hidden ? 'Hide API key' : 'Show API key');
            keyToggleBtn.dataset.state = hidden ? 'visible' : 'hidden';
            keyIcon.classList.toggle('bi-eye');
            keyIcon.classList.toggle('bi-eye-slash');
            if (hidden) {
                // Show real key
                const secret = keyInput.dataset.secret || '';
                if (secret) {
                    keyInput.value = secret;
                    keyInput.removeAttribute('placeholder');
                }
            } else {
                // Hide key: clear field (so we don't overwrite unless user re-enters) and restore placeholder
                if (keyInput.value === (keyInput.dataset.secret||'')) {
                    keyInput.value = '';
                }
                keyInput.setAttribute('placeholder', originalPlaceholder);
            }
        });
    }
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
