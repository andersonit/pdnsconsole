<?php
/**
 * PDNS Console - DNS Configuration (Super Admin Only)
 */

$user = new User();
$settings = new Settings();
$nameserver = new Nameserver();

if (!$user->isSuperAdmin($currentUser['id'])) {
    header('Location: /?page=dashboard');
    exit;
}

$pageTitle = 'DNS Settings';
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
        if ($action === 'update_dns_settings') {
            try {
                $dnsSettings = [
                    'soa_contact' => trim($_POST['soa_contact'] ?? ''),
                    'default_ttl' => intval($_POST['default_ttl'] ?? 3600),
                    'soa_refresh' => intval($_POST['soa_refresh'] ?? 10800),
                    'soa_retry' => intval($_POST['soa_retry'] ?? 3600),
                    'soa_expire' => intval($_POST['soa_expire'] ?? 604800),
                    'soa_minimum' => intval($_POST['soa_minimum'] ?? 86400)
                ];

                // Nameservers
                $nameservers = [];
                $primary = trim($_POST['primary_nameserver'] ?? '');
                $secondary = trim($_POST['secondary_nameserver'] ?? '');
                if ($primary) $nameservers[] = $primary;
                if ($secondary) $nameservers[] = $secondary;
                for ($i = 3; $i <= 10; $i++) {
                    $ns = trim($_POST["nameserver_{$i}"] ?? '');
                    if ($ns) $nameservers[] = $ns;
                }
                if (empty($nameservers)) {
                    throw new Exception('At least one nameserver is required.');
                }
                $nameserver->bulkUpdateFromSettings($nameservers);
                $dnsSettings['primary_nameserver'] = $nameservers[0] ?? '';
                $dnsSettings['secondary_nameserver'] = $nameservers[1] ?? '';

                if (!empty($dnsSettings['soa_contact'])) {
                    if (strpos($dnsSettings['soa_contact'], '@') !== false) {
                        $dnsSettings['soa_contact'] = str_replace('@', '.', $dnsSettings['soa_contact']);
                    }
                    if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $dnsSettings['soa_contact'])) {
                        throw new Exception('SOA contact must be in format: admin.example.com (or admin@example.com which will be converted)');
                    }
                }
                if (empty($dnsSettings['soa_contact'])) {
                    throw new Exception('SOA contact email is required.');
                }
                if ($dnsSettings['default_ttl'] < 60) throw new Exception('Default TTL must be at least 60 seconds.');
                if ($dnsSettings['soa_refresh'] < 60) throw new Exception('SOA refresh must be at least 60 seconds.');
                if ($dnsSettings['soa_retry'] < 60) throw new Exception('SOA retry must be at least 60 seconds.');
                if ($dnsSettings['soa_expire'] < 3600) throw new Exception('SOA expire must be at least 3600 seconds (1 hour).');
                if ($dnsSettings['soa_minimum'] < 60) throw new Exception('SOA minimum must be at least 60 seconds.');

                foreach ($dnsSettings as $key => $value) {
                    $db->execute("UPDATE global_settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
                }
                $message = 'DNS settings updated successfully.';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// Current DNS settings
$dnsSettings = [];
$dnsKeys = ['soa_contact', 'default_ttl', 'soa_refresh', 'soa_retry', 'soa_expire', 'soa_minimum'];
foreach ($dnsKeys as $key) {
    $row = $db->fetch("SELECT setting_value FROM global_settings WHERE setting_key = ?", [$key]);
    $dnsSettings[$key] = $row['setting_value'] ?? '';
}
$nameservers = $nameserver->getActiveNameservers();
$dnsSettings['primary_nameserver'] = $nameservers[0]['hostname'] ?? '';
$dnsSettings['secondary_nameserver'] = $nameservers[1]['hostname'] ?? '';
$additionalNameservers = [];
for ($i = 2; $i < count($nameservers); $i++) { $additionalNameservers[] = $nameservers[$i]['hostname']; }

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>
<div class="container-fluid mt-4">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        renderBreadcrumb([[ 'label' => 'DNS Settings' ]], true);
    ?>
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-1"><i class="bi bi-globe me-2"></i>DNS Settings</h1>
            <p class="text-muted mb-0">Default nameservers and SOA parameters for new zones</p>
        </div>
    </div>
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show"><span><?php echo htmlspecialchars($message); ?></span><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <div class="row">
        <div class="col-lg-7 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">DNS Configuration</h6>
                    <small class="text-muted">Nameservers & SOA defaults</small>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_dns_settings">
                        <div class="mb-3">
                            <label class="form-label" for="primary_nameserver">Primary Nameserver *</label>
                            <input type="text" class="form-control" id="primary_nameserver" name="primary_nameserver" value="<?php echo htmlspecialchars($dnsSettings['primary_nameserver']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="secondary_nameserver">Secondary Nameserver *</label>
                            <input type="text" class="form-control" id="secondary_nameserver" name="secondary_nameserver" value="<?php echo htmlspecialchars($dnsSettings['secondary_nameserver']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Additional Nameservers</label>
                            <div id="additional-nameservers">
                                <?php for ($i = 3; $i <= 10; $i++): ?>
                                    <div class="input-group mb-2 additional-ns-group" <?php echo ($i > 3 && !isset($additionalNameservers[$i-3])) ? 'style="display:none;"' : ''; ?>>
                                        <span class="input-group-text">NS <?php echo $i; ?></span>
                                        <input type="text" class="form-control" name="nameserver_<?php echo $i; ?>" value="<?php echo htmlspecialchars($additionalNameservers[$i-3] ?? ''); ?>" placeholder="ns<?php echo $i; ?>.example.com">
                                        <?php if ($i > 3): ?>
                                            <button type="button" class="btn btn-outline-danger remove-ns-btn"><i class="bi bi-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="add-nameserver-btn"><i class="bi bi-plus-circle me-1"></i>Add Nameserver</button>
                            <small class="text-muted d-block mt-1">Up to 8 additional nameservers</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="soa_contact">SOA Contact Email *</label>
                            <input type="text" class="form-control" id="soa_contact" name="soa_contact" value="<?php echo htmlspecialchars($dnsSettings['soa_contact']); ?>" required>
                            <small class="text-muted">Use admin@example.com (converted to admin.example.com)</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="default_ttl">Default TTL (seconds)</label>
                            <input type="number" class="form-control" id="default_ttl" name="default_ttl" value="<?php echo htmlspecialchars($dnsSettings['default_ttl']); ?>" min="60" required>
                        </div>
                        <h6 class="mt-4 mb-3">SOA Timers</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="soa_refresh">Refresh</label>
                                <input type="number" class="form-control" id="soa_refresh" name="soa_refresh" value="<?php echo htmlspecialchars($dnsSettings['soa_refresh']); ?>" min="60" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="soa_retry">Retry</label>
                                <input type="number" class="form-control" id="soa_retry" name="soa_retry" value="<?php echo htmlspecialchars($dnsSettings['soa_retry']); ?>" min="60" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="soa_expire">Expire</label>
                                <input type="number" class="form-control" id="soa_expire" name="soa_expire" value="<?php echo htmlspecialchars($dnsSettings['soa_expire']); ?>" min="3600" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="soa_minimum">Minimum TTL</label>
                                <input type="number" class="form-control" id="soa_minimum" name="soa_minimum" value="<?php echo htmlspecialchars($dnsSettings['soa_minimum']); ?>" min="60" required>
                            </div>
                        </div>
                        <div class="d-grid">
                            <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Update DNS Settings</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0"><h6 class="mb-0">Current Summary</h6></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Primary NS:</dt><dd class="col-sm-7"><code><?php echo htmlspecialchars($dnsSettings['primary_nameserver']); ?></code></dd>
                        <dt class="col-sm-5">Secondary NS:</dt><dd class="col-sm-7"><code><?php echo htmlspecialchars($dnsSettings['secondary_nameserver']); ?></code></dd>
                        <dt class="col-sm-5">SOA Contact:</dt><dd class="col-sm-7"><code><?php echo htmlspecialchars($dnsSettings['soa_contact']); ?></code></dd>
                        <dt class="col-sm-5">Default TTL:</dt><dd class="col-sm-7"><?php echo number_format($dnsSettings['default_ttl']); ?> s</dd>
                        <dt class="col-sm-5">SOA Refresh:</dt><dd class="col-sm-7"><?php echo number_format($dnsSettings['soa_refresh']); ?> s</dd>
                        <dt class="col-sm-5">SOA Retry:</dt><dd class="col-sm-7"><?php echo number_format($dnsSettings['soa_retry']); ?> s</dd>
                        <dt class="col-sm-5">SOA Expire:</dt><dd class="col-sm-7"><?php echo number_format($dnsSettings['soa_expire']); ?> s</dd>
                        <dt class="col-sm-5">SOA Minimum:</dt><dd class="col-sm-7"><?php echo number_format($dnsSettings['soa_minimum']); ?> s</dd>
                    </dl>
                    <div class="alert alert-info alert-static mt-3 mb-0 small"><i class="bi bi-info-circle me-1"></i>Applied to newly created zones only.</div>
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
        if (hiddenGroups.length) {
            hiddenGroups[0].style.display = 'flex';
            hiddenGroups[0].querySelector('input').focus();
            if (hiddenGroups.length === 1) addBtn.style.display = 'none';
        }
    });
    nsContainer.addEventListener('click', function(e){
        if (e.target.closest('.remove-ns-btn')) {
            const grp = e.target.closest('.additional-ns-group');
            grp.querySelector('input').value='';
            grp.style.display='none';
            addBtn.style.display='inline-block';
        }
    });
    const soaInput = document.getElementById('soa_contact');
    soaInput.addEventListener('blur', function(){
        let v=this.value.trim();
        if (v && v.includes('@')) {
            this.value=v.replace('@','.');
        }
    });
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
