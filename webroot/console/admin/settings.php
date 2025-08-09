<?php
/**
 * PDNS Console - DNS Settings Management (Super Admin Only)
 */

// Get required classes
$user = new User();
$settings = new Settings();
$nameserver = new Nameserver();

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
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_dns_settings') {
            $dnsSettings = [
                'soa_contact' => trim($_POST['soa_contact'] ?? ''),
                'default_ttl' => intval($_POST['default_ttl'] ?? 3600),
                'soa_refresh' => intval($_POST['soa_refresh'] ?? 10800),
                'soa_retry' => intval($_POST['soa_retry'] ?? 3600),
                'soa_expire' => intval($_POST['soa_expire'] ?? 604800),
                'soa_minimum' => intval($_POST['soa_minimum'] ?? 86400)
            ];
            
            // Handle nameservers with the new Nameserver class
            $nameservers = [];
            
            // Get primary and secondary nameservers
            $primary = trim($_POST['primary_nameserver'] ?? '');
            $secondary = trim($_POST['secondary_nameserver'] ?? '');
            
            if (!empty($primary)) {
                $nameservers[] = $primary;
            }
            if (!empty($secondary)) {
                $nameservers[] = $secondary;
            }
            
            // Get additional nameservers
            for ($i = 3; $i <= 10; $i++) {
                $ns = trim($_POST["nameserver_{$i}"] ?? '');
                if (!empty($ns)) {
                    $nameservers[] = $ns;
                }
            }
            
            // Validate we have at least one nameserver
            if (empty($nameservers)) {
                throw new Exception('At least one nameserver is required.');
            }
            
            // Update nameservers using the new class
            $nameserver->bulkUpdateFromSettings($nameservers);
            
            // Update primary/secondary nameserver settings for backward compatibility
            $dnsSettings['primary_nameserver'] = $nameservers[0] ?? '';
            $dnsSettings['secondary_nameserver'] = $nameservers[1] ?? '';
            
            // Validate and normalize SOA contact email
            if (!empty($dnsSettings['soa_contact'])) {
                // If it contains @, convert to dot format
                if (strpos($dnsSettings['soa_contact'], '@') !== false) {
                    $dnsSettings['soa_contact'] = str_replace('@', '.', $dnsSettings['soa_contact']);
                }
                
                // Validate format (should be like admin.example.com)
                if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $dnsSettings['soa_contact'])) {
                    throw new Exception('SOA contact must be in format: admin.example.com (or admin@example.com which will be converted)');
                }
            }
            
            // Validate required fields
            if (empty($dnsSettings['soa_contact'])) {
                throw new Exception('SOA contact email is required.');
            }
            
            // Validate TTL values
            if ($dnsSettings['default_ttl'] < 60) {
                throw new Exception('Default TTL must be at least 60 seconds.');
            }
            
            if ($dnsSettings['soa_refresh'] < 60) {
                throw new Exception('SOA refresh must be at least 60 seconds.');
            }
            
            if ($dnsSettings['soa_retry'] < 60) {
                throw new Exception('SOA retry must be at least 60 seconds.');
            }
            
            if ($dnsSettings['soa_expire'] < 3600) {
                throw new Exception('SOA expire must be at least 3600 seconds (1 hour).');
            }
            
            if ($dnsSettings['soa_minimum'] < 60) {
                throw new Exception('SOA minimum must be at least 60 seconds.');
            }
            
            // Update all DNS settings
            foreach ($dnsSettings as $key => $value) {
                $db->execute(
                    "UPDATE global_settings SET setting_value = ? WHERE setting_key = ?",
                    [$value, $key]
                );
            }
            
            $message = 'DNS settings updated successfully.';
            $messageType = 'success';
        }
        
        if ($action === 'update_system_settings') {
            $systemSettings = [
                'session_timeout' => intval($_POST['session_timeout'] ?? 3600),
                'max_login_attempts' => intval($_POST['max_login_attempts'] ?? 5),
                'default_tenant_domains' => intval($_POST['default_tenant_domains'] ?? 0),
                'records_per_page' => intval($_POST['records_per_page'] ?? 25),
                'timezone' => trim($_POST['timezone'] ?? 'UTC'),
                'max_upload_size' => intval($_POST['max_upload_size'] ?? 5242880),
                'allowed_logo_types' => trim($_POST['allowed_logo_types'] ?? 'image/jpeg,image/png,image/gif')
            ];
            
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
            
            // Update all system settings
            foreach ($systemSettings as $key => $value) {
                $db->execute(
                    "UPDATE global_settings SET setting_value = ? WHERE setting_key = ?",
                    [$value, $key]
                );
            }
            
            $message = 'System settings updated successfully.';
            $messageType = 'success';
        }
        
        if ($action === 'update_email_settings') {
            $emailSettings = [
                'smtp_host' => trim($_POST['smtp_host'] ?? ''),
                'smtp_port' => intval($_POST['smtp_port'] ?? 587),
                'smtp_secure' => trim($_POST['smtp_secure'] ?? 'starttls'),
                'smtp_username' => trim($_POST['smtp_username'] ?? ''),
                'smtp_password' => trim($_POST['smtp_password'] ?? ''),
                'smtp_from_email' => trim($_POST['smtp_from_email'] ?? ''),
                'smtp_from_name' => trim($_POST['smtp_from_name'] ?? '')
            ];
            
            // Validate required fields
            if (empty($emailSettings['smtp_host'])) {
                throw new Exception('SMTP host is required.');
            }
            
            if ($emailSettings['smtp_port'] < 1 || $emailSettings['smtp_port'] > 65535) {
                throw new Exception('SMTP port must be between 1 and 65535.');
            }
            
            if (!in_array($emailSettings['smtp_secure'], ['starttls', 'tls', 'ssl', ''])) {
                throw new Exception('Invalid SMTP security type.');
            }
            
            if (empty($emailSettings['smtp_from_email']) || !filter_var($emailSettings['smtp_from_email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Valid from email address is required.');
            }
            
            if (empty($emailSettings['smtp_from_name'])) {
                throw new Exception('From name is required.');
            }
            
            // Update all email settings using the Settings class
            $result = $settings->updateEmailSettings($emailSettings);
            
            if ($result) {
                $message = 'Email settings updated successfully.';
                $messageType = 'success';
            } else {
                throw new Exception('Failed to update email settings.');
            }
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// Get current DNS settings
$dnsSettings = [];
$dnsKeys = ['soa_contact', 'default_ttl', 'soa_refresh', 'soa_retry', 'soa_expire', 'soa_minimum'];

foreach ($dnsKeys as $key) {
    $setting = $db->fetch(
        "SELECT setting_value FROM global_settings WHERE setting_key = ?",
        [$key]
    );
    $dnsSettings[$key] = $setting['setting_value'] ?? '';
}

// Get nameservers from the new table
$nameservers = $nameserver->getActiveNameservers();
$dnsSettings['primary_nameserver'] = $nameservers[0]['hostname'] ?? '';
$dnsSettings['secondary_nameserver'] = $nameservers[1]['hostname'] ?? '';

// Parse additional nameservers (beyond first 2)
$additionalNameservers = [];
for ($i = 2; $i < count($nameservers); $i++) {
    $additionalNameservers[] = $nameservers[$i]['hostname'];
}

// Get current system settings
$systemSettings = [];
$systemKeys = ['session_timeout', 'max_login_attempts', 'default_tenant_domains', 'records_per_page', 'timezone', 'max_upload_size', 'allowed_logo_types'];

foreach ($systemKeys as $key) {
    $setting = $db->fetch(
        "SELECT setting_value FROM global_settings WHERE setting_key = ?",
        [$key]
    );
    $systemSettings[$key] = $setting['setting_value'] ?? '';
}

// Get current email settings
$emailSettings = $settings->getEmailSettings();

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="bi bi-gear-fill me-2"></i>
                        System Settings
                    </h1>
                    <p class="text-muted mb-0">Configure global DNS defaults, email settings, and system preferences</p>
                </div>
                <div>
                    <a href="?page=admin_dashboard" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>
                        Back to Admin
                    </a>
                </div>
            </div>
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
        <!-- DNS Configuration -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-dns me-2"></i>
                        DNS Configuration
                    </h6>
                    <small class="text-muted">Default nameservers and SOA settings for new domains</small>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_dns_settings">
                        
                        <div class="mb-3">
                            <label for="primary_nameserver" class="form-label">Primary Nameserver *</label>
                            <input type="text" class="form-control" id="primary_nameserver" name="primary_nameserver" 
                                   value="<?php echo htmlspecialchars($dnsSettings['primary_nameserver']); ?>" required>
                            <small class="text-muted">Primary NS record for new domains</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="secondary_nameserver" class="form-label">Secondary Nameserver *</label>
                            <input type="text" class="form-control" id="secondary_nameserver" name="secondary_nameserver" 
                                   value="<?php echo htmlspecialchars($dnsSettings['secondary_nameserver']); ?>" required>
                            <small class="text-muted">Secondary NS record for new domains</small>
                        </div>
                        
                        <!-- Additional Nameservers -->
                        <div class="mb-3">
                            <label class="form-label">Additional Nameservers (Optional)</label>
                            <div id="additional-nameservers">
                                <?php for ($i = 3; $i <= 10; $i++): ?>
                                    <div class="input-group mb-2 additional-ns-group" <?php echo ($i > 3 && !isset($additionalNameservers[$i-3])) ? 'style="display:none;"' : ''; ?>>
                                        <span class="input-group-text">NS <?php echo $i; ?></span>
                                        <input type="text" class="form-control" name="nameserver_<?php echo $i; ?>" 
                                               value="<?php echo htmlspecialchars($additionalNameservers[$i-3] ?? ''); ?>" 
                                               placeholder="ns<?php echo $i; ?>.example.com">
                                        <?php if ($i > 3): ?>
                                            <button type="button" class="btn btn-outline-danger remove-ns-btn">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="add-nameserver-btn">
                                <i class="bi bi-plus-circle me-1"></i>Add Nameserver
                            </button>
                            <small class="text-muted d-block mt-1">You can add up to 8 additional nameservers</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="soa_contact" class="form-label">SOA Contact Email *</label>
                            <input type="text" class="form-control" id="soa_contact" name="soa_contact" 
                                   value="<?php echo htmlspecialchars($dnsSettings['soa_contact']); ?>" required>
                            <small class="text-muted">
                                Contact email for SOA records. Enter as admin@example.com or directly as admin.example.com
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="default_ttl" class="form-label">Default TTL (seconds)</label>
                            <input type="number" class="form-control" id="default_ttl" name="default_ttl" 
                                   value="<?php echo htmlspecialchars($dnsSettings['default_ttl']); ?>" min="60" required>
                            <small class="text-muted">Default time-to-live for new records</small>
                        </div>
                        
                        <h6 class="mb-3 mt-4">SOA Record Timers</h6>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="soa_refresh" class="form-label">Refresh (seconds)</label>
                                <input type="number" class="form-control" id="soa_refresh" name="soa_refresh" 
                                       value="<?php echo htmlspecialchars($dnsSettings['soa_refresh']); ?>" min="60" required>
                                <small class="text-muted">How often slaves check for updates</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="soa_retry" class="form-label">Retry (seconds)</label>
                                <input type="number" class="form-control" id="soa_retry" name="soa_retry" 
                                       value="<?php echo htmlspecialchars($dnsSettings['soa_retry']); ?>" min="60" required>
                                <small class="text-muted">Retry interval if refresh fails</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="soa_expire" class="form-label">Expire (seconds)</label>
                                <input type="number" class="form-control" id="soa_expire" name="soa_expire" 
                                       value="<?php echo htmlspecialchars($dnsSettings['soa_expire']); ?>" min="3600" required>
                                <small class="text-muted">When slaves stop serving zone</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="soa_minimum" class="form-label">Minimum TTL (seconds)</label>
                                <input type="number" class="form-control" id="soa_minimum" name="soa_minimum" 
                                       value="<?php echo htmlspecialchars($dnsSettings['soa_minimum']); ?>" min="60" required>
                                <small class="text-muted">Minimum TTL for negative responses</small>
                            </div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>
                                Update DNS Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- System Settings -->
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
                        <input type="hidden" name="action" value="update_system_settings">
                        
                        <div class="mb-3">
                            <label for="session_timeout" class="form-label">Session Timeout (seconds)</label>
                            <input type="number" class="form-control" id="session_timeout" name="session_timeout" 
                                   value="<?php echo htmlspecialchars($systemSettings['session_timeout']); ?>" min="300" required>
                            <small class="text-muted">How long users stay logged in (minimum 300 seconds)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="max_login_attempts" class="form-label">Max Login Attempts</label>
                            <input type="number" class="form-control" id="max_login_attempts" name="max_login_attempts" 
                                   value="<?php echo htmlspecialchars($systemSettings['max_login_attempts']); ?>" min="1" max="20" required>
                            <small class="text-muted">Failed attempts before account lockout</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="default_tenant_domains" class="form-label">Default Tenant Domain Limit</label>
                            <input type="number" class="form-control" id="default_tenant_domains" name="default_tenant_domains" 
                                   value="<?php echo htmlspecialchars($systemSettings['default_tenant_domains']); ?>" min="0" required>
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
        
        <!-- Email Settings -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-envelope me-2"></i>
                        Email Settings
                    </h6>
                    <small class="text-muted">SMTP configuration for password reset and system emails</small>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_email_settings">
                        
                        <div class="mb-3">
                            <label for="smtp_host" class="form-label">SMTP Host *</label>
                            <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                   value="<?php echo htmlspecialchars($emailSettings['smtp_host']); ?>" required>
                            <small class="text-muted">SMTP server hostname (e.g., smtp.gmail.com)</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="smtp_port" class="form-label">SMTP Port *</label>
                                    <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                           value="<?php echo htmlspecialchars($emailSettings['smtp_port']); ?>" min="1" max="65535" required>
                                    <small class="text-muted">587 (TLS) or 465 (SSL)</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="smtp_secure" class="form-label">Security *</label>
                                    <select class="form-select" id="smtp_secure" name="smtp_secure" required>
                                        <option value="starttls"<?php echo $emailSettings['smtp_secure'] === 'starttls' ? ' selected' : ''; ?>>STARTTLS (recommended)</option>
                                        <option value="tls"<?php echo $emailSettings['smtp_secure'] === 'tls' ? ' selected' : ''; ?>>TLS</option>
                                        <option value="ssl"<?php echo $emailSettings['smtp_secure'] === 'ssl' ? ' selected' : ''; ?>>SSL</option>
                                        <option value=""<?php echo $emailSettings['smtp_secure'] === '' ? ' selected' : ''; ?>>None</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="smtp_username" class="form-label">SMTP Username</label>
                            <input type="text" class="form-control" id="smtp_username" name="smtp_username" 
                                   value="<?php echo htmlspecialchars($emailSettings['smtp_username']); ?>">
                            <small class="text-muted">Leave blank if no authentication required</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="smtp_password" class="form-label">SMTP Password</label>
                            <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                                   value="<?php echo htmlspecialchars($emailSettings['smtp_password']); ?>">
                            <small class="text-muted">Leave blank to keep current password</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="smtp_from_email" class="form-label">From Email Address *</label>
                            <input type="email" class="form-control" id="smtp_from_email" name="smtp_from_email" 
                                   value="<?php echo htmlspecialchars($emailSettings['smtp_from_email']); ?>" required>
                            <small class="text-muted">Email address that system emails will be sent from</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="smtp_from_name" class="form-label">From Name *</label>
                            <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" 
                                   value="<?php echo htmlspecialchars($emailSettings['smtp_from_name']); ?>" required>
                            <small class="text-muted">Name that will appear in the "From" field</small>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-lg me-1"></i>
                                Update Email Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Current Settings Summary -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        Current Configuration Summary
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <h6 class="text-muted mb-3">DNS Configuration</h6>
                            <dl class="row">
                                <dt class="col-sm-5">Primary NS:</dt>
                                <dd class="col-sm-7"><code><?php echo htmlspecialchars($dnsSettings['primary_nameserver']); ?></code></dd>
                                
                                <dt class="col-sm-5">Secondary NS:</dt>
                                <dd class="col-sm-7"><code><?php echo htmlspecialchars($dnsSettings['secondary_nameserver']); ?></code></dd>
                                
                                <dt class="col-sm-5">SOA Contact:</dt>
                                <dd class="col-sm-7"><code><?php echo htmlspecialchars($dnsSettings['soa_contact']); ?></code></dd>
                                
                                <dt class="col-sm-5">Default TTL:</dt>
                                <dd class="col-sm-7"><?php echo number_format($dnsSettings['default_ttl']); ?> seconds</dd>
                            </dl>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted mb-3">System Settings</h6>
                            <dl class="row">
                                <dt class="col-sm-6">Session Timeout:</dt>
                                <dd class="col-sm-6"><?php echo number_format($systemSettings['session_timeout']); ?> seconds</dd>
                                
                                <dt class="col-sm-6">Max Login Attempts:</dt>
                                <dd class="col-sm-6"><?php echo $systemSettings['max_login_attempts']; ?></dd>
                                
                                <dt class="col-sm-6">Default Records Per Page:</dt>
                                <dd class="col-sm-6"><?php echo $systemSettings['records_per_page']; ?></dd>
                                
                                <dt class="col-sm-6">Timezone:</dt>
                                <dd class="col-sm-6"><?php echo htmlspecialchars($systemSettings['timezone']); ?></dd>
                                
                                <dt class="col-sm-6">Max Upload Size:</dt>
                                <dd class="col-sm-6"><?php echo round($systemSettings['max_upload_size'] / 1048576, 1); ?> MB</dd>
                                
                                <dt class="col-sm-6">Domain Limit:</dt>
                                <dd class="col-sm-6"><?php echo $systemSettings['default_tenant_domains'] == 0 ? 'Unlimited' : number_format($systemSettings['default_tenant_domains']); ?></dd>
                            </dl>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted mb-3">Email Settings</h6>
                            <dl class="row">
                                <dt class="col-sm-6">SMTP Host:</dt>
                                <dd class="col-sm-6"><code><?php echo htmlspecialchars($emailSettings['smtp_host']); ?></code></dd>
                                
                                <dt class="col-sm-6">SMTP Port:</dt>
                                <dd class="col-sm-6"><?php echo $emailSettings['smtp_port']; ?></dd>
                                
                                <dt class="col-sm-6">Security:</dt>
                                <dd class="col-sm-6"><?php echo ucfirst($emailSettings['smtp_secure'] ?: 'None'); ?></dd>
                                
                                <dt class="col-sm-6">From Email:</dt>
                                <dd class="col-sm-6"><code><?php echo htmlspecialchars($emailSettings['smtp_from_email']); ?></code></dd>
                            </dl>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note:</strong> These settings will be applied to all new domains and affect the entire system. 
                        Existing domains will keep their current configuration unless manually updated.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const addBtn = document.getElementById('add-nameserver-btn');
    const nsContainer = document.getElementById('additional-nameservers');
    
    addBtn.addEventListener('click', function() {
        const hiddenGroups = nsContainer.querySelectorAll('.additional-ns-group[style*="display:none"], .additional-ns-group[style*="display: none"]');
        if (hiddenGroups.length > 0) {
            hiddenGroups[0].style.display = 'flex';
            hiddenGroups[0].querySelector('input').focus();
            
            // Hide add button if all nameservers are visible
            if (hiddenGroups.length === 1) {
                addBtn.style.display = 'none';
            }
        }
    });
    
    // Handle remove buttons
    nsContainer.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-ns-btn') || e.target.parentNode.classList.contains('remove-ns-btn')) {
            const group = e.target.closest('.additional-ns-group');
            group.querySelector('input').value = '';
            group.style.display = 'none';
            
            // Show add button
            addBtn.style.display = 'inline-block';
        }
    });
    
    // SOA email format conversion
    const soaContactInput = document.getElementById('soa_contact');
    soaContactInput.addEventListener('blur', function() {
        let value = this.value.trim();
        if (value && value.includes('@')) {
            this.value = value.replace('@', '.');
            // Show a brief notification
            const small = this.parentNode.querySelector('small');
            const originalText = small.textContent;
            small.textContent = 'Email format converted to DNS format (@ → .)';
            small.style.color = '#198754';
            setTimeout(() => {
                small.textContent = originalText;
                small.style.color = '';
            }, 3000);
        }
    });
    
    // Handle logo type checkboxes
    const logoTypeCheckboxes = document.querySelectorAll('input[type="checkbox"][value^="image/"]');
    const hiddenLogoTypesInput = document.getElementById('allowed_logo_types');
    
    function updateLogoTypes() {
        const selectedTypes = Array.from(logoTypeCheckboxes)
            .filter(cb => cb.checked)
            .map(cb => cb.value);
        hiddenLogoTypesInput.value = selectedTypes.join(',');
    }
    
    logoTypeCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateLogoTypes);
    });
    
    // Initialize the hidden field on page load
    updateLogoTypes();
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
