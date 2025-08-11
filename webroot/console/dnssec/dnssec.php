<?php
/**
 * PDNS Console - DNSSEC Management (Placeholder now partially functional)
 */

$domainId = intval($_GET['domain_id'] ?? 0);
if (empty($domainId)) {
    header('Location: ?page=zone_manage');
    exit;
}

// Get domain info
$domain = new Domain();
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

$domainInfo = null;
try {
    if ($isSuperAdmin) {
        $domainInfo = $domain->getDomainById($domainId);
    } else {
        $tenantId = $currentUser['tenant_id'] ?? null;
        $domainInfo = $domain->getDomainById($domainId, $tenantId);
    }
    if (!$domainInfo) { $error = 'Zone not found or access denied.'; }
} catch (Exception $e) { $error = 'Error loading zone: ' . $e->getMessage(); }

$apiConfigured = false; $dnssecStatus = null; $keys = []; $dsRecords = [];
if (!isset($error) && $domainInfo) {
    try {
        $settings = new Settings();
        $encKey = $settings->get('pdns_api_key_enc');
        if ($settings->get('pdns_api_host') && $encKey) {
            $apiConfigured = true;
            require_once __DIR__ . '/../../classes/PdnsApiClient.php';
            $client = new PdnsApiClient();
            // Build candidate zone names to try (PowerDNS typically stores with trailing dot)
            $base = rtrim($domainInfo['name'], '.');
            $candidates = [ $base . '.', $base, strtolower($base) . '.', strtolower($base) ];
            $z = null; $usedName = null; $errorsTried = [];
            foreach (array_unique($candidates) as $cand) {
                try {
                    $z = $client->getZone($cand);
                    $usedName = $cand; break;
                } catch (Exception $eTry) {
                    $errorsTried[] = $cand . ' => ' . $eTry->getMessage();
                    // Specifically swallow 404 and keep trying alternatives
                    if (strpos($eTry->getMessage(), '404') === false) { // non 404 propagate
                        throw $eTry;
                    }
                }
            }
            if ($z) {
                $zoneName = $usedName; // for button data attributes later
                $dnssecStatus = [
                    'enabled' => (bool)($z['dnssec'] ?? false),
                    'serial' => $z['serial'] ?? null,
                ];
                if ($dnssecStatus['enabled']) {
                    try { $keys = $client->listKeys($zoneName) ?: []; } catch (Exception $eLK) { $errorsTried[] = 'listKeys: ' . $eLK->getMessage(); }
                    try { $dsRecords = $client->getDsRecords($zoneName); } catch (Exception $eDS) { $errorsTried[] = 'dsRecords: ' . $eDS->getMessage(); }
                }
            } else {
                // Provide detailed diagnostic if not found
                $dnssecStatus = ['error' => 'Zone not found via API after trying variants. Attempts: ' . implode('; ', $errorsTried)];
            }
        }
    } catch (Exception $e) {
        // Ignore API client construction errors; remain placeholder
    }
}

$pageTitle = 'DNSSEC Management';
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>
<div class="container-fluid mt-4">
    <!-- Breadcrumb Navigation -->
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        $crumbs = [
            ['label' => 'Zones', 'url' => '?page=zone_manage']
        ];
        if ($domainInfo) {
            $crumbs[] = ['label' => $domainInfo['name'], 'url' => '?page=records&domain_id=' . $domainId];
        }
        $crumbs[] = ['label' => 'DNSSEC'];
        renderBreadcrumb($crumbs, $isSuperAdmin, ['class' => 'mb-4']);
    ?>

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="bi bi-shield-lock me-2 text-success"></i>
                        DNSSEC Management
                    </h1>
                    <?php if ($domainInfo): ?>
                        <p class="text-muted mb-0">Configure DNSSEC for <strong><?php echo htmlspecialchars($domainInfo['name']); ?></strong></p>
                    <?php endif; ?>
                </div>
                <!-- Removed redundant back button (breadcrumb provides navigation) -->
            </div>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
        
        <?php if (!$domainInfo): ?>
            <div class="text-center">
                <a href="?page=zone_manage" class="btn btn-primary">
                    Return to Zones
                </a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($domainInfo): ?>
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header <?php echo ($dnssecStatus && ($dnssecStatus['enabled'] ?? false)) ? 'bg-success text-white' : 'bg-secondary text-white'; ?>">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-shield-lock me-2"></i>
                        DNSSEC Status
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($apiConfigured && $dnssecStatus && empty($dnssecStatus['error'])): ?>
                        <div class="d-flex align-items-center mb-3">
                            <div class="me-3">
                                <span class="badge <?php echo $dnssecStatus['enabled'] ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                    <?php echo $dnssecStatus['enabled'] ? 'Enabled' : 'Disabled'; ?>
                                </span>
                            </div>
                            <div>
                                <h6 class="mb-0">Serial: <?php echo htmlspecialchars($dnssecStatus['serial'] ?? '—'); ?></h6>
                                <small class="text-muted">Live status from PowerDNS API</small>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if ($dnssecStatus['enabled']): ?>
                                <button class="btn btn-outline-danger btn-sm" id="btnDisableDnssec" data-zone="<?php echo htmlspecialchars($zoneName); ?>">Disable DNSSEC</button>
                                <button class="btn btn-outline-primary btn-sm" id="btnRectify" data-zone="<?php echo htmlspecialchars($zoneName); ?>">Rectify Zone</button>
                            <?php else: ?>
                                <button class="btn btn-success btn-sm" id="btnEnableDnssec" data-zone="<?php echo htmlspecialchars($zoneName); ?>">Enable DNSSEC</button>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($apiConfigured && $dnssecStatus && !empty($dnssecStatus['error'])): ?>
                        <div class="alert alert-danger py-2">API Error: <?php echo htmlspecialchars($dnssecStatus['error']); ?></div>
                    <?php else: ?>
                        <p class="text-muted mb-3">PowerDNS API not fully configured or DNSSEC disabled.</p>
                        <a href="?page=admin_settings" class="btn btn-outline-secondary btn-sm">Configure API</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        About DNSSEC
                    </h5>
                </div>
                <div class="card-body">
                    <p class="card-text">DNS Security Extensions (DNSSEC) provides authentication of DNS data, preventing spoofing and ensuring integrity.</p>
                    <ul class="list-unstyled mb-0">
                        <li><i class="bi bi-check-circle text-success me-2"></i>Authenticates responses</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>Prevents cache poisoning</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i>Supports chain of trust</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <?php if ($apiConfigured && $dnssecStatus && ($dnssecStatus['enabled'] ?? false)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="bi bi-key me-2"></i>
                DNSSEC Keys
            </h5>
        </div>
        <div class="card-body">
            <div class="d-flex justify-content-between mb-3">
                <h6 class="mb-0">Active Keys</h6>
                <button class="btn btn-sm btn-outline-success" id="btnGenerateKey" data-zone="<?php echo htmlspecialchars($zoneName); ?>">
                    <i class="bi bi-plus-circle me-1"></i>
                    Generate Key
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Algorithm</th>
                            <th>Bits</th>
                            <th>Active</th>
                            <th>DS</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="dnssecKeysBody">
                        <?php if (empty($keys)): ?>
                            <tr>
                                <td colspan="7" class="text-muted">No keys found.</td>
                            </tr>
                        <?php else: foreach ($keys as $k): ?>
                            <tr data-key-id="<?php echo htmlspecialchars($k['id']); ?>">
                                <td><?php echo htmlspecialchars($k['id']); ?></td>
                                <td><?php echo htmlspecialchars(strtoupper($k['keytype'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($k['algorithm'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($k['bits'] ?? ''); ?></td>
                                <td><?php echo !empty($k['active']) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                                <td><?php echo !empty($k['ds']) ? count($k['ds']) : '0'; ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-secondary btnToggleKey" data-active="<?php echo !empty($k['active']) ? '1':'0'; ?>" data-zone="<?php echo htmlspecialchars($zoneName); ?>" data-key-id="<?php echo htmlspecialchars($k['id']); ?>" title="Toggle Active">
                                            <i class="bi bi-toggle-<?php echo !empty($k['active']) ? 'on text-success':'off'; ?>"></i>
                                        </button>
                                        <button class="btn btn-outline-danger btnDeleteKey" data-zone="<?php echo htmlspecialchars($zoneName); ?>" data-key-id="<?php echo htmlspecialchars($k['id']); ?>" title="Delete Key">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($dsRecords)): ?>
                <hr>
                <h6>DS Records (Submit to Parent)</h6>
                <pre class="small bg-light p-2"><?php echo htmlspecialchars(implode("\n", $dsRecords)); ?></pre>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-footer text-center">
            <div class="btn-group">
                <a href="?page=records&domain_id=<?php echo $domainId; ?>" class="btn btn-outline-primary">
                    <i class="bi bi-list-ul me-1"></i>
                    Manage Records
                </a>
                <a href="?page=zone_ddns&domain_id=<?php echo $domainId; ?>" class="btn btn-outline-info">
                    <i class="bi bi-arrow-repeat me-1"></i>
                    Dynamic DNS
                </a>
                <a href="?page=zone_edit&id=<?php echo $domainId; ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-pencil me-1"></i>
                    Zone Settings
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($apiConfigured): ?>
<script>
(function(){
    function post(action, data, cb){
        const fd = new FormData();
        fd.append('action', action);
        Object.keys(data||{}).forEach(k=>fd.append(k, data[k]));
        fetch('api/pdns.php', { method: 'POST', credentials: 'same-origin', body: fd })
            .then(r=>r.json()).then(j=>cb(null,j)).catch(e=>cb(e));
    }
    const zone = document.querySelector('[id^=btnEnableDnssec]')?.dataset.zone || document.querySelector('[id^=btnDisableDnssec]')?.dataset.zone;
    function reload(){ location.reload(); }
    document.getElementById('btnEnableDnssec')?.addEventListener('click', e=>{ e.preventDefault(); post('enable_dnssec', { zone }, ()=>reload()); });
    document.getElementById('btnDisableDnssec')?.addEventListener('click', e=>{ e.preventDefault(); if(confirm('Disable DNSSEC?')) post('disable_dnssec', { zone }, ()=>reload()); });
    document.getElementById('btnRectify')?.addEventListener('click', e=>{ e.preventDefault(); post('rectify_zone', { zone }, ()=>alert('Rectify requested')); });
    document.getElementById('btnGenerateKey')?.addEventListener('click', e=>{ e.preventDefault(); post('create_key', { zone, keytype: 'zsk', bits: 2048, algorithm: 'RSASHA256' }, ()=>reload()); });
    document.querySelectorAll('.btnToggleKey').forEach(btn=>btn.addEventListener('click', e=>{e.preventDefault(); const id=btn.dataset.keyId; const active=btn.dataset.active==='1'? '0':'1'; post('toggle_key',{zone,key_id:id,active},()=>reload()); }));
    document.querySelectorAll('.btnDeleteKey').forEach(btn=>btn.addEventListener('click', e=>{e.preventDefault(); if(confirm('Delete key?')){ post('delete_key',{zone,key_id:btn.dataset.keyId},()=>reload()); }}));
})();
</script>
<?php endif; ?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
