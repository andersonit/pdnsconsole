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
$systemKeys = ['session_timeout', 'max_login_attempts', 'default_tenant_domains', 'records_per_page', 'timezone', 'max_upload_size', 'allowed_logo_types'];

foreach ($systemKeys as $key) {
    $setting = $db->fetch(
        "SELECT setting_value FROM global_settings WHERE setting_key = ?",
        [$key]
    );
    $systemSettings[$key] = $setting['setting_value'] ?? '';
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
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
