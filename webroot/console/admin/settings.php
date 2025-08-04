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

$pageTitle = 'DNS Settings';
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
                'primary_nameserver' => trim($_POST['primary_nameserver'] ?? ''),
                'secondary_nameserver' => trim($_POST['secondary_nameserver'] ?? ''),
                'soa_contact' => trim($_POST['soa_contact'] ?? ''),
                'default_ttl' => intval($_POST['default_ttl'] ?? 3600),
                'soa_refresh' => intval($_POST['soa_refresh'] ?? 10800),
                'soa_retry' => intval($_POST['soa_retry'] ?? 3600),
                'soa_expire' => intval($_POST['soa_expire'] ?? 604800),
                'soa_minimum' => intval($_POST['soa_minimum'] ?? 86400)
            ];
            
            // Validate required fields
            if (empty($dnsSettings['primary_nameserver'])) {
                throw new Exception('Primary nameserver is required.');
            }
            
            if (empty($dnsSettings['secondary_nameserver'])) {
                throw new Exception('Secondary nameserver is required.');
            }
            
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
                'records_per_page' => intval($_POST['records_per_page'] ?? 25)
            ];
            
            // Validate values
            if ($systemSettings['session_timeout'] < 300) {
                throw new Exception('Session timeout must be at least 300 seconds (5 minutes).');
            }
            
            if ($systemSettings['max_login_attempts'] < 1) {
                throw new Exception('Max login attempts must be at least 1.');
            }
            
            if ($systemSettings['records_per_page'] < 5 || $systemSettings['records_per_page'] > 100) {
                throw new Exception('Records per page must be between 5 and 100.');
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
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// Get current DNS settings
$dnsSettings = [];
$dnsKeys = ['primary_nameserver', 'secondary_nameserver', 'soa_contact', 'default_ttl', 
           'soa_refresh', 'soa_retry', 'soa_expire', 'soa_minimum'];

foreach ($dnsKeys as $key) {
    $setting = $db->fetch(
        "SELECT setting_value FROM global_settings WHERE setting_key = ?",
        [$key]
    );
    $dnsSettings[$key] = $setting['setting_value'] ?? '';
}

// Get current system settings
$systemSettings = [];
$systemKeys = ['session_timeout', 'max_login_attempts', 'default_tenant_domains', 'records_per_page'];

foreach ($systemKeys as $key) {
    $setting = $db->fetch(
        "SELECT setting_value FROM global_settings WHERE setting_key = ?",
        [$key]
    );
    $systemSettings[$key] = $setting['setting_value'] ?? '';
}

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
                        DNS Settings
                    </h1>
                    <p class="text-muted mb-0">Configure global DNS defaults and system settings</p>
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
                        
                        <div class="mb-3">
                            <label for="soa_contact" class="form-label">SOA Contact Email *</label>
                            <input type="text" class="form-control" id="soa_contact" name="soa_contact" 
                                   value="<?php echo htmlspecialchars($dnsSettings['soa_contact']); ?>" required>
                            <small class="text-muted">Contact email for SOA records (admin.example.com format)</small>
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
                            <label for="records_per_page" class="form-label">Records Per Page</label>
                            <input type="number" class="form-control" id="records_per_page" name="records_per_page" 
                                   value="<?php echo htmlspecialchars($systemSettings['records_per_page']); ?>" min="5" max="100" required>
                            <small class="text-muted">Number of records to display per page (5-100)</small>
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
                        <div class="col-md-6">
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
                        <div class="col-md-6">
                            <h6 class="text-muted mb-3">System Settings</h6>
                            <dl class="row">
                                <dt class="col-sm-6">Session Timeout:</dt>
                                <dd class="col-sm-6"><?php echo number_format($systemSettings['session_timeout']); ?> seconds</dd>
                                
                                <dt class="col-sm-6">Max Login Attempts:</dt>
                                <dd class="col-sm-6"><?php echo $systemSettings['max_login_attempts']; ?></dd>
                                
                                <dt class="col-sm-6">Default Domain Limit:</dt>
                                <dd class="col-sm-6"><?php echo $systemSettings['default_tenant_domains'] == 0 ? 'Unlimited' : number_format($systemSettings['default_tenant_domains']); ?></dd>
                                
                                <dt class="col-sm-6">Records Per Page:</dt>
                                <dd class="col-sm-6"><?php echo $systemSettings['records_per_page']; ?></dd>
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

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
