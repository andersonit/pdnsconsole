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

$apiConfigured = false; $dnssecStatus = null; $keys = []; $dsRecords = []; $keyLifecycle = []; $keyActionLock = [];
// Default zone name (prefer trailing dot) for actions even if zone fetch fails
$zoneName = null;
// Parent DS cache structures
$parentDsSummary = null; $parentDsDetails = [];
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
            // Use the first candidate as a sensible default for action buttons
            $zoneName = $candidates[0];
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
                    // Lifecycle / rollover status computation
                    try {
                        $db = Database::getInstance();
                        $metaRows = $db->fetchAll("SELECT kind, content FROM domainmetadata dm JOIN domains d ON dm.domain_id=d.id WHERE d.id=?", [$domainInfo['id']]);
                        $meta = [];
                        foreach($metaRows as $m){ $meta[$m['kind']] = $m['content']; }
                        $rollStart = $meta['PDNSCONSOLE-ROLLSTART'] ?? null;
                        // Load cached parent DS comparison if present
                        if (isset($meta['PDNSCONSOLE-DSCHECK'])) {
                            $cache = json_decode($meta['PDNSCONSOLE-DSCHECK'], true);
                            if (is_array($cache)) {
                                $parentDsSummary = $cache;
                                foreach (($cache['per'] ?? []) as $row) {
                                    if (!empty($row['ds'])) {
                                        $parentDsDetails[strtoupper(preg_replace('/\s+/', ' ', trim($row['ds'])))] = $row['status'] ?? 'unknown';
                                    }
                                }
                            }
                        }
                        $perDomainHold = isset($meta['PDNSCONSOLE-HOLD']) ? (int)$meta['PDNSCONSOLE-HOLD'] : null;
                        $settingsObj = new Settings();
                        $globalHold = (int)($settingsObj->get('dnssec_hold_period_days') ?: 7);
                        $holdDaysEff = $perDomainHold ?: $globalHold;
                        $grace = (int)(getenv('DELETION_GRACE_DAYS') ?: 7);
                        // Group keys by keytype|algorithm to identify newest vs old
                        $groups = [];
                        foreach($keys as $k){
                            $grp = strtolower(($k['keytype'] ?? '').'|'.($k['algorithm'] ?? ''));
                            if(!isset($groups[$grp])) $groups[$grp]=[];
                            $groups[$grp][] = $k;
                        }
                        foreach($groups as &$g){ usort($g, fn($a,$b)=> ($a['id']??0) <=> ($b['id']??0)); }
                        $now = new DateTimeImmutable('now');
                        $rollElapsedDays = null; $rollRemaining = null;
                        if($rollStart){
                            try { $rsDT = new DateTimeImmutable($rollStart); $rollElapsedDays = (int)$rsDT->diff($now)->format('%a'); $rollRemaining = max(0, $holdDaysEff - $rollElapsedDays); } catch(Exception $e){}
                        }
                        foreach($keys as $k){
                            $id = $k['id'];
                            $active = !empty($k['active']);
                            $grp = strtolower(($k['keytype'] ?? '').'|'.($k['algorithm'] ?? ''));
                            $list = $groups[$grp] ?? [];
                            $newestId = $list ? end($list)['id'] : null;
                            $status = '—';
                            // Deactivation marker for deletion countdown
                            $deactMarkerKind = 'PDNSCONSOLE-OLDKEY-'.$id.'-DEACTIVATED';
                            if(isset($meta[$deactMarkerKind])){
                                try { $dDT = new DateTimeImmutable($meta[$deactMarkerKind]); $age = (int)$dDT->diff($now)->format('%a'); $remain = max(0, $grace - $age); $status = $remain>0 ? '<span class="badge bg-secondary">Inactive</span><br><small>Delete in '.$remain.'d</small>' : '<span class="badge bg-secondary">Inactive</span><br><small>Eligible deletion</small>'; }
                                catch(Exception $e){ $status = '<span class="badge bg-secondary">Inactive</span>'; }
                            } elseif($rollStart && count(array_filter($list, fn($x)=>!empty($x['active'])))>1){
                                if($active){
                                    if($id == $newestId){
                                        // New key during timed rollover
                                        if($rollRemaining !== null && $rollRemaining>0) $status = '<span class="badge bg-info text-dark">Rollover</span><br><small>Old retires in '.$rollRemaining.'d</small>'; else $status = '<span class="badge bg-info text-dark">Rollover</span><br><small>Completion pending</small>';
                                    } else {
                                        if($rollRemaining !== null && $rollRemaining>0) $status = '<span class="badge bg-warning text-dark">Pending Retire</span><br><small>'.$rollRemaining.'d left</small>'; else $status = '<span class="badge bg-warning text-dark">Retire Soon</span>';
                                        // Lock manual actions on old key during automated timed rollover
                                        $keyActionLock[$id] = true;
                                    }
                                }
                            } else {
                                // Active single key or add-mode extras
                                if($active){
                                    $status = '<span class="badge bg-success">Active</span>';
                                    if(count(array_filter($list, fn($x)=>!empty($x['active'])))>1){ $status .= '<br><small>Multiple active</small>'; }
                                }
                            }
                            $keyLifecycle[$id] = $status;
                        }
                    } catch(Exception $eMeta) { /* ignore lifecycle computation errors */ }
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
                <div class="card-header <?php echo ($dnssecStatus && ($dnssecStatus['enabled'] ?? false)) ? 'bg-success text-white' : 'bg-secondary text-white'; ?>" style="--bs-text-opacity:1;">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-shield-lock me-2"></i>
                        DNSSEC Status
                    </h5>
                </div>
                <div class="card-body">
                    <div id="dnssecMessages" class="mb-2"></div>
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
                        <div class="alert alert-warning py-2 mb-2">Zone lookup issue: <?php echo htmlspecialchars($dnssecStatus['error']); ?></div>
                        <?php if (!empty($zoneName)): ?>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <button class="btn btn-success btn-sm" id="btnEnableDnssec" data-zone="<?php echo htmlspecialchars($zoneName); ?>">Enable DNSSEC</button>
                                <button class="btn btn-outline-secondary btn-sm" id="btnFixTxtQuotes" data-zone="<?php echo htmlspecialchars($zoneName); ?>" title="Quote any unquoted TXT records and rectify the zone">Fix TXT quotes</button>
                                <small class="text-muted">If the zone has malformed TXT records, fix them then retry enabling.</small>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($apiConfigured): ?>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <button class="btn btn-success btn-sm" id="btnEnableDnssec" data-zone="<?php echo htmlspecialchars($zoneName ?: rtrim(($domainInfo['name'] ?? ''), '.') . '.'); ?>">Enable DNSSEC</button>
                                <button class="btn btn-outline-secondary btn-sm" id="btnFixTxtQuotes" data-zone="<?php echo htmlspecialchars($zoneName ?: rtrim(($domainInfo['name'] ?? ''), '.') . '.'); ?>" title="Quote any unquoted TXT records and rectify the zone">Fix TXT quotes</button>
                                <small class="text-muted">Zone status unavailable; if TXT content is malformed, fix it then retry enabling.</small>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-3">PowerDNS API not fully configured.</p>
                            <a href="?page=admin_settings" class="btn btn-outline-secondary btn-sm">Configure API</a>
                        <?php endif; ?>
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
                        <!-- Key Generation Modal -->
                        <div class="modal fade" id="modalGenerateKey" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="bi bi-key me-2"></i>Generate DNSSEC Key</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <form id="formGenerateKey">
                                            <div class="mb-3">
                                                <label class="form-label">Key Type</label>
                                                <select class="form-select form-select-sm" name="keytype" id="keytypeSelect">
                                                    <option value="csk" selected>CSK (Combined Signing Key)</option>
                                                    <option value="ksk">KSK (Key Signing Key)</option>
                                                    <option value="zsk">ZSK (Zone Signing Key)</option>
                                                </select>
                                                <div class="form-text small">CSK is recommended (single key for signing & DS).</div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Algorithm</label>
                                                <select class="form-select form-select-sm" name="algorithm" id="algSelect">
                                                    <option value="ECDSAP256SHA256" selected>ECDSAP256SHA256 (Recommended)</option>
                                                    <option value="ED25519">ED25519 (Fast, compact)</option>
                                                    <option value="RSASHA256">RSASHA256</option>
                                                    <option value="RSASHA512">RSASHA512</option>
                                                </select>
                                            </div>
                                            <div class="mb-3" id="bitsGroup" style="display:none;">
                                                <label class="form-label">Key Size (bits)</label>
                                                <input type="number" class="form-control form-control-sm" name="bits" id="bitsInput" value="2048" min="1024" step="256">
                                                <div class="form-text small">Used only for RSA algorithms.</div>
                                            </div>
                                                                    <div class="mb-2">
                                                                        <label class="form-label mb-1">Rollover Mode</label>
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="radio" name="rollover_mode" id="rollAdd" value="add" checked>
                                                                            <label class="form-check-label" for="rollAdd">Add Additional Key (no removal)</label>
                                                                        </div>
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="radio" name="rollover_mode" id="rollImmediate" value="immediate">
                                                                            <label class="form-check-label" for="rollImmediate">Immediate Replace (create new then deactivate old)</label>
                                                                        </div>
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="radio" name="rollover_mode" id="rollTimed" value="timed">
                                                                            <label class="form-check-label" for="rollTimed">Timed Rollover (keep both until hold period expires)</label>
                                                                        </div>
                                                                        <div class="form-text small">Timed rollover defers old key retirement; cron script completes after configured hold period.</div>
                                                                        <div class="alert alert-warning py-1 px-2 small mt-2" id="timedInfo" style="display:none;">
                                                                            Old and new keys will coexist. Ensure parent DS updated. Hold: <span id="timedHoldDays">--</span> days.
                                                                        </div>
                                                                    </div>
                                        </form>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                        <button type="button" class="btn btn-primary btn-sm" id="btnSubmitGenerate">Generate</button>
                                    </div>
                                </div>
                            </div>
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
                            <th>Parent <span class="text-muted" data-bs-toggle="tooltip" data-bs-title="Current publication status of this key's DS records at the parent (registrar/registry), based on last query."><i class="bi bi-info-circle"></i></span></th>
                            <th>Lifecycle</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="dnssecKeysBody">
                        <?php if (empty($keys)): ?>
                            <tr>
                                <td colspan="6" class="text-muted">No keys found.</td>
                            </tr>
                        <?php else: foreach ($keys as $k): $locked = !empty($keyActionLock[$k['id']]); ?>
                            <tr data-key-id="<?php echo htmlspecialchars($k['id']); ?>" data-active="<?php echo !empty($k['active']) ? '1':'0'; ?>" data-keytype="<?php echo htmlspecialchars(strtoupper($k['keytype'] ?? '')); ?>" data-alg="<?php echo htmlspecialchars($k['algorithm'] ?? ''); ?>" data-locked="<?php echo $locked ? '1':'0'; ?>" data-dslist="<?php echo htmlspecialchars(!empty($k['ds']) ? implode('|', (array)$k['ds']) : ''); ?>">
                                <td><?php echo htmlspecialchars($k['id']); ?></td>
                                <td><?php echo htmlspecialchars(strtoupper($k['keytype'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($k['algorithm'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($k['bits'] ?? ''); ?></td>
                                <td><?php echo !empty($k['active']) ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td>
                                <td class="small" style="min-width:95px;">
                                    <?php
                                    $parentBadge = '—';
                                    $lastCheck = $parentDsSummary['checked_at'] ?? null;
                                    $lastCheckDisplay = null;
                                    if ($lastCheck) {
                                        try { $dtObj = new DateTime($lastCheck); $lastCheckDisplay = $dtObj->format('Y-m-d, H:i:s'); } catch(Exception $e) { $lastCheckDisplay = $lastCheck; }
                                    }
                                    $tt = $lastCheckDisplay ? ' data-bs-toggle="tooltip" data-bs-title="Last check: '.htmlspecialchars($lastCheckDisplay).'"' : ' data-bs-toggle="tooltip" data-bs-title="Last check: not yet run"';
                                    if (!empty($k['ds']) && $parentDsDetails) {
                                        $dsLines = (array)$k['ds'];
                                        $hasPublished = false; $allMissing = true; $partial = false;
                                        foreach ($dsLines as $dsl) {
                                            $norm = strtoupper(preg_replace('/\s+/', ' ', trim($dsl)));
                                            if (isset($parentDsDetails[$norm])) {
                                                $st = $parentDsDetails[$norm];
                                                if ($st === 'published') { $hasPublished = true; }
                                                if ($st !== 'missing') { $allMissing = false; }
                                                if ($st === 'extra' || $st === 'partial') { $partial = true; }
                                            } else {
                                                $partial = true; $allMissing = false; // unknown piece
                                            }
                                        }
                                        if ($hasPublished && !$partial && !$allMissing) { $parentBadge = '<span class="badge bg-success"'.$tt.'>Published</span>'; }
                                        elseif ($hasPublished && $partial) { $parentBadge = '<span class="badge bg-warning text-dark"'.$tt.'>Partial</span>'; }
                                        elseif ($allMissing) { $parentBadge = '<span class="badge bg-danger"'.$tt.'>Missing</span>'; }
                                        else { $parentBadge = '<span class="badge bg-secondary"'.$tt.'>Unknown</span>'; }
                                    } elseif (!empty($k['ds'])) {
                                        $parentBadge = '<span class="badge bg-secondary"'.$tt.'>Unknown</span>';
                                    }
                                    echo $parentBadge;
                                    ?>
                                </td>
                                <td class="small" style="min-width:120px;"><?php echo $keyLifecycle[$k['id']] ?? '—'; ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-secondary btnToggleKey" data-active="<?php echo !empty($k['active']) ? '1':'0'; ?>" data-zone="<?php echo htmlspecialchars($zoneName); ?>" data-key-id="<?php echo htmlspecialchars($k['id']); ?>" title="Activate / Deactivate Key" <?php echo $locked ? 'disabled data-bs-toggle="tooltip" data-bs-title="Locked: Timed rollover in progress"':''; ?>>
                                            <i class="bi bi-toggle-<?php echo !empty($k['active']) ? 'on text-success':'off'; ?>"></i>
                                        </button>
                                        <button class="btn btn-outline-danger btnDeleteKey" data-zone="<?php echo htmlspecialchars($zoneName); ?>" data-key-id="<?php echo htmlspecialchars($k['id']); ?>" title="Delete Key Permanently" <?php echo $locked ? 'disabled data-bs-toggle="tooltip" data-bs-title="Locked: Timed rollover in progress"':''; ?>>
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Deactivate Key Modal -->
            <div class="modal fade" id="modalDeactivateKey" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Deactivate DNSSEC Key</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div id="deactivateKeyDetails" class="small mb-2"></div>
                            <div class="alert alert-warning small mb-2" id="deactivateLastActive" style="display:none;">
                                This is the ONLY active key for the zone. Deactivating it without another active key can break DNSSEC validation and cause resolvers to return SERVFAIL. Recommended action:
                                <ol class="mb-0 ps-3">
                                    <li>Generate a new key using Timed Rollover (preferred) or Immediate Replace.</li>
                                    <li>Wait for the rollover process (or hold period) to complete before deactivating the old key.</li>
                                </ol>
                            </div>
                            <p class="small mb-1">Deactivation keeps the key record but stops it from signing. DS records at the parent referencing only a now-inactive key may cause validation failures if no replacement DS has been published.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-warning btn-sm" id="btnConfirmDeactivate">Deactivate Key</button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Delete Key Modal -->
            <div class="modal fade" id="modalDeleteKey" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-trash me-2 text-danger"></i>Delete DNSSEC Key</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div id="deleteKeyDetails" class="small mb-2"></div>
                            <div class="alert alert-danger small">
                                <strong>High Risk:</strong> Deleting a key removes it permanently. If this key's DS record is still published at the parent and no replacement DS exists, validating resolvers will fail lookups for this zone (SERVFAIL).
                            </div>
                            <div class="alert alert-info small mb-2">
                                <strong>Recommended Safer Workflow:</strong>
                                <ol class="mb-0 ps-3">
                                    <li>Generate a new key with <em>Timed Rollover</em> (or Immediate Replace if urgent).</li>
                                    <li>Publish new DS at the parent (if KSK/CSK) and allow propagation.</li>
                                    <li>Let the automated rollover deactivate & later delete the old key.</li>
                                </ol>
                            </div>
                            <p class="small mb-0">Proceed only if you are certain the key is unused or has been safely replaced.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger btn-sm" id="btnConfirmDelete">Delete Key</button>
                        </div>
                    </div>
                </div>
            </div>
            <hr>
            <h6 class="d-flex align-items-center flex-wrap">DS Records (Submit to Parent)
                <button class="btn btn-outline-secondary btn-sm ms-2" id="btnCopyDs" title="Copy all DS records" <?php if (empty($dsRecords)) echo 'disabled'; ?>><i class="bi bi-clipboard"></i></button>
                <button class="btn btn-sm btn-outline-danger ms-2" id="btnCheckParentDs" title="Query current DS at parent/registry"><i class="bi bi-search me-1"></i>Query Registrar</button>
                <?php
                $globalLastCheckDisp = null;
                if (!empty($parentDsSummary['checked_at_display'])) {
                    $globalLastCheckDisp = $parentDsSummary['checked_at_display'];
                } elseif (!empty($parentDsSummary['checked_at'])) {
                    try { $dtTmp = new DateTime($parentDsSummary['checked_at']); $globalLastCheckDisp = $dtTmp->format('Y-m-d, H:i:s'); } catch(Exception $e) { $globalLastCheckDisp = $parentDsSummary['checked_at']; }
                }
                ?>
                <span id="parentDsLastCheckDisplay" class="ms-3 small text-muted"><?php echo $globalLastCheckDisp ? 'Last check: '.htmlspecialchars($globalLastCheckDisp) : ''; ?></span>
            </h6>
            <pre class="small bg-light p-2" id="dsOutput"><?php echo !empty($dsRecords) ? htmlspecialchars(implode("\n", $dsRecords)) : 'No DS records yet. If you just enabled DNSSEC or generated a new key, PowerDNS may still be computing DS data.'; ?></pre>
            <div class="alert alert-info alert-static small mb-2" id="dsNextStepAlert">
                <strong>Next Step:</strong> Log into your domain registrar (parent zone) and publish each DS record above. After publishing, allow for parent TTL propagation before deactivating or deleting old DNSSEC keys. Typical parent TTLs range 1–24h.
            </div>
            <div id="parentDsResult" class="small mb-3" style="display:none;">
                <div class="border rounded p-2" id="parentDsInner"></div>
            </div>
            <div class="row g-2 small">
                <div class="col-md-4">
                    <div class="border rounded p-2 h-100">
                        <strong>Checklist</strong>
                        <ul class="ps-3 mb-0">
                            <li>Submit DS at registrar</li>
                            <li>Wait for parent TTL</li>
                            <li>Verify with dig +dnssec</li>
                            <li>Allow rollover completion</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-2 h-100" id="rolloverStatusBox">
                        <strong>Rollover Status</strong>
                        <div id="rolloverStatusContent" class="mt-1">
                            <!-- Filled by JS if timed rollover active -->
                            <span class="text-muted">No active timed rollover.</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-2 h-100">
                        <strong>Validation Tips</strong>
                        <ul class="ps-3 mb-0">
                            <li><code>dig +dnssec yourzone.tld DS</code></li>
                            <li><code>dig +multi yourzone.tld DNSKEY</code></li>
                            <li>Use online DS checkers</li>
                        </ul>
                    </div>
                </div>
            </div>
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
    const apiEndpoint = '/api/pdns.php';
    const msgBox = document.getElementById('dnssecMessages');
    function showMessage(type, text){
        if(!msgBox) return; msgBox.innerHTML = '<div class="alert alert-'+type+' py-2 mb-0">'+text.replace(/</g,'&lt;')+'</div>';
    }
    function setBusy(btn, busy, label){
        if(!btn) return; if(busy){ btn.dataset.origHtml = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>'+(label||'Working...'); } else { btn.disabled = false; if(btn.dataset.origHtml) btn.innerHTML = btn.dataset.origHtml; }
    }
    async function post(action, data, btn, reload=true){
        try {
            setBusy(btn, true);
            const fd = new FormData();
            fd.append('action', action);
            Object.keys(data||{}).forEach(k=>fd.append(k, data[k]));
            const resp = await fetch(apiEndpoint, { method: 'POST', credentials: 'same-origin', body: fd });
            const raw = await resp.text();
            let json; try { json = JSON.parse(raw); } catch(e){ console.error('Non-JSON response', raw); showMessage('danger','Invalid API response'); setBusy(btn,false); return; }
            if(!resp.ok || json.error){ showMessage('danger', json.error || ('HTTP '+resp.status)); setBusy(btn,false); return; }
            showMessage('success', json.message || 'Success');
            if(reload){ setTimeout(()=>location.reload(), 800); } else { setBusy(btn,false); }
        } catch(err){ console.error(err); showMessage('danger', 'Request failed: '+err.message); setBusy(btn,false); }
    }
    const zone = document.getElementById('btnEnableDnssec')?.dataset.zone || document.getElementById('btnDisableDnssec')?.dataset.zone || '';
    // DS copy
    const btnCopyDs = document.getElementById('btnCopyDs');
    const btnCheckParent = document.getElementById('btnCheckParentDs');
    const btnFixTxt = document.getElementById('btnFixTxtQuotes');
    let parentDsLastCheck = <?php echo isset($parentDsSummary['checked_at']) ? '"'.htmlspecialchars($parentDsSummary['checked_at']).'"' : 'null'; ?>;
    let parentDsLastCheckDisplay = <?php echo isset($parentDsSummary['checked_at_display']) ? '"'.htmlspecialchars($parentDsSummary['checked_at_display']).'"' : 'null'; ?>;
    function fmtTs(ts){ if(!ts) return ''; return ts; } // server already formatted
    function updateParentColumn(details){
        if(!Array.isArray(details)) return;
        const map = {};
        details.forEach(d=>{ if(d.ds) map[d.ds.toUpperCase().replace(/\s+/g,' ')]=d.status; });
        document.querySelectorAll('#dnssecKeysBody tr').forEach(r=>{
            const dsAttr = r.dataset.dslist || '';
            const parentCell = r.querySelector('td:nth-child(7)'); // Adjusted after removing DS column
            if(!parentCell) return;
            if(!dsAttr){ parentCell.innerHTML='—'; return; }
            const lines = dsAttr.split('|');
            let hasPublished=false, allMissing=true, partial=false;
            lines.forEach(line=>{
                if(!line) return; const norm=line.toUpperCase().replace(/\s+/g,' ');
                const st=map[norm];
                if(st==='published') hasPublished=true;
                if(st && st!=='missing') allMissing=false;
                if(!st || (st!=='published' && st!=='missing')) partial=true;
            });
            const tt = parentDsLastCheckDisplay ? 'Last check: '+parentDsLastCheckDisplay : (parentDsLastCheck ? 'Last check: '+parentDsLastCheck : 'Last check pending');
            if(hasPublished && !partial && !allMissing){ parentCell.innerHTML='<span class="badge bg-success" data-bs-toggle="tooltip" data-bs-title="DS published at parent (full match). '+tt+'">Published</span>'; }
            else if(hasPublished && partial){ parentCell.innerHTML='<span class="badge bg-warning text-dark" data-bs-toggle="tooltip" data-bs-title="Some DS published; verify all lines. '+tt+'">Partial</span>'; }
            else if(allMissing){ parentCell.innerHTML='<span class="badge bg-danger" data-bs-toggle="tooltip" data-bs-title="No DS found at parent. '+tt+'">Missing</span>'; }
            else { parentCell.innerHTML='<span class="badge bg-secondary" data-bs-toggle="tooltip" data-bs-title="Status unknown; run registrar query. '+tt+'">Unknown</span>'; }
        });
        if(window.bootstrap){
            document.querySelectorAll('#dnssecKeysBody [data-bs-toggle="tooltip"]').forEach(el=>{ const inst=bootstrap.Tooltip.getInstance(el); if(inst) inst.dispose(); });
            document.querySelectorAll('#dnssecKeysBody [data-bs-toggle="tooltip"]').forEach(el=>{ new bootstrap.Tooltip(el); });
        }
    }
    btnCopyDs?.addEventListener('click', ()=>{
        const dsText = document.getElementById('dsOutput')?.innerText || '';
        if(!dsText) return showMessage('warning','No DS records to copy');
        navigator.clipboard.writeText(dsText).then(()=> showMessage('success','DS records copied to clipboard')).catch(()=> showMessage('danger','Copy failed'));
    });
    btnFixTxt?.addEventListener('click', async ()=>{
        if(!zone) return showMessage('danger','Zone missing');
        const old = btnFixTxt.innerHTML; btnFixTxt.disabled = true; btnFixTxt.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        try {
            const fd = new FormData(); fd.append('action','fix_txt_quotes'); fd.append('zone', zone);
            const resp = await fetch(apiEndpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
            const raw = await resp.text(); let json; try { json = JSON.parse(raw); } catch(e){
                const snippet = raw ? raw.toString().slice(0,200).replace(/</g,'&lt;').replace(/\n/g,' ') : '';
                showMessage('danger','Invalid response from fix'+(snippet?': '+snippet:''));
                return;
            }
            if(!resp.ok || json.error){ showMessage('danger', json.error || 'Fix failed'); return; }
            showMessage('success', json.message || ('Fixed TXT quotes. Updated '+(json.updated||0)+' records.'));
            setTimeout(()=>location.reload(), 800);
        } catch(err){ showMessage('danger','Fix failed: '+err.message); }
        finally { btnFixTxt.disabled=false; btnFixTxt.innerHTML = old; }
    });
    btnCheckParent?.addEventListener('click', async ()=>{
        if(!zone) return showMessage('danger','Zone missing');
        btnCheckParent.disabled = true; const oldHtml = btnCheckParent.innerHTML; btnCheckParent.innerHTML='<span class="spinner-border spinner-border-sm"></span>';
        try {
            const fd = new FormData(); fd.append('action','check_parent_ds'); fd.append('zone', zone);
            const resp = await fetch(apiEndpoint, { method:'POST', body: fd, credentials:'same-origin' });
            const raw = await resp.text(); let json; try { json = JSON.parse(raw); } catch(e){ showMessage('danger','Bad parent DS response'); return; }
            if(!resp.ok || json.error){ showMessage('danger', json.error || 'Parent DS check failed'); return; }
            const box = document.getElementById('parentDsResult'); const inner = document.getElementById('parentDsInner');
            const cmp = json.comparison || {}; const details = cmp.details || []; const warn = json.warning;
            let badgeClass = 'secondary', badgeText='No Match';
            if(cmp.match==='full'){ badgeClass='success'; badgeText='Full Match'; }
            else if(cmp.match==='partial'){ badgeClass='warning'; badgeText='Partial'; }
            else if(cmp.match==='none'){ badgeClass='danger'; badgeText='None'; }
            let html = '<div class="d-flex align-items-center mb-1">Parent DS Status: <span class="badge bg-'+badgeClass+' ms-2">'+badgeText+'</span></div>';
            html += '<div class="mb-1">Published: '+(cmp.published||0)+' | Missing: '+(cmp.missing||0)+' | Extra: '+(cmp.extra||0)+'</div>';
            html += '<div class="table-responsive"><table class="table table-bordered table-sm mb-0"><thead><tr><th>DS</th><th>Status</th></tr></thead><tbody>';
            details.forEach(d=>{ const st=d.status; let cls='secondary'; if(st==='published') cls='success'; else if(st==='missing') cls='danger'; else if(st==='extra') cls='warning'; html+='<tr><td class="small">'+d.ds.replace(/</g,'&lt;')+'</td><td><span class="badge bg-'+cls+'">'+st+'</span></td></tr>'; });
            if(details.length===0) html+='<tr><td colspan="2" class="text-muted">No DS records at parent.</td></tr>';
            html+='</tbody></table></div>';
            parentDsLastCheck = json.checked_at || parentDsLastCheck;
            parentDsLastCheckDisplay = json.checked_at_display || parentDsLastCheckDisplay || parentDsLastCheck;
            const checkedAtFmt = parentDsLastCheckDisplay;
            html+='<div class="text-muted mt-1">Checked: '+checkedAtFmt+'</div>';
            const lastCheckSpan = document.getElementById('parentDsLastCheckDisplay');
            if(lastCheckSpan){ lastCheckSpan.textContent = 'Last check: '+checkedAtFmt; }
            if(warn) html+='<div class="alert alert-warning py-1 mt-2 mb-0 small">Lookup warning: '+warn.replace(/</g,'&lt;')+'</div>';
            inner.innerHTML = html; box.style.display='block';
            updateParentColumn(details);
            showMessage('success','Parent DS check completed');
        } catch(err){ showMessage('danger','Parent DS check failed: '+err.message); }
        finally { btnCheckParent.disabled=false; btnCheckParent.innerHTML=oldHtml; }
    });
    // Auto-refresh parent DS if cached timestamp >30m
    (function(){
        const cachedLine = document.querySelector('#parentDsInner .text-muted');
        if(!cachedLine) return; // nothing cached
        const m = cachedLine.textContent.match(/Cached: (.+)$/);
        if(!m) return;
        const dt = new Date(m[1]);
        if(isNaN(dt.getTime())) return;
        const ageMin = (Date.now() - dt.getTime())/60000;
        if(ageMin > 30 && btnCheckParent){ btnCheckParent.click(); }
    })();
    // Rollover status summary (derive from lifecycle column badges)
    function updateRolloverSummary(){
        const box = document.getElementById('rolloverStatusContent'); if(!box) return;
        const rows = [...document.querySelectorAll('#dnssecKeysBody tr')];
        const pending = rows.filter(r=>/Pending Retire|Retire Soon/.test(r.querySelector('td:last-child')?.innerText||''));
        const rollover = rows.filter(r=>/Rollover/.test(r.querySelector('td:last-child')?.innerText||''));
        if(pending.length===0 && rollover.length===0){ box.innerHTML='<span class="text-muted">No active timed rollover.</span>'; return; }
        // Extract remaining days from text pattern 'in Xd' or 'Xd left'
        let days=[];
        rows.forEach(r=>{ const txt=r.querySelector('td:last-child')?.innerText||''; const m=txt.match(/(\d+)d/); if(m) days.push(parseInt(m[1],10)); });
        const min=Math.min.apply(null, days);
        box.innerHTML = '<div class="small">Timed rollover in progress.<br><strong>Estimated completion:</strong> ~'+(min===Infinity?'?':min+'d')+' until old key deactivate.<br><em>Automated cleanup follows grace period.</em></div>';
    }
    updateRolloverSummary();
    const btnEnable = document.getElementById('btnEnableDnssec');
    const btnDisable = document.getElementById('btnDisableDnssec');
    const btnRectify = document.getElementById('btnRectify');
    const btnGenerate = document.getElementById('btnGenerateKey');
    const modalEl = document.getElementById('modalGenerateKey');
    let modalInstance = null;
    function ensureModal(){ if(modalEl && !modalInstance){ modalInstance = new bootstrap.Modal(modalEl); } return modalInstance; }
    const algSelect = document.getElementById('algSelect');
    const bitsGroup = document.getElementById('bitsGroup');
    const bitsInput = document.getElementById('bitsInput');
    const keytypeSelect = document.getElementById('keytypeSelect');
    const rollRadios = document.querySelectorAll('input[name="rollover_mode"]');
    const timedInfo = document.getElementById('timedInfo');
    const timedHoldSpan = document.getElementById('timedHoldDays');
    let holdDays = parseInt(document.querySelector('meta[name="dnssec-hold-days"]')?.getAttribute('content')||'7',10);
    if(timedHoldSpan) timedHoldSpan.textContent = holdDays;
    rollRadios.forEach(r=>r.addEventListener('change',()=>{ timedInfo.style.display = document.getElementById('rollTimed').checked ? 'block':'none'; }));
    algSelect?.addEventListener('change', ()=>{ const v=algSelect.value; if(v.startsWith('RSA')) { bitsGroup.style.display='block'; } else { bitsGroup.style.display='none'; } });
    btnEnable?.addEventListener('click', e=>{ e.preventDefault(); if(!zone) return showMessage('danger','Zone missing'); post('enable_dnssec',{ zone }, btnEnable); });
    btnDisable?.addEventListener('click', e=>{ e.preventDefault(); if(!zone) return showMessage('danger','Zone missing'); if(confirm('Disable DNSSEC for this zone?')) post('disable_dnssec',{ zone }, btnDisable); });
    btnRectify?.addEventListener('click', e=>{ e.preventDefault(); if(!zone) return showMessage('danger','Zone missing'); post('rectify_zone',{ zone }, btnRectify, false); });
    btnGenerate?.addEventListener('click', e=>{ e.preventDefault(); if(!zone) return showMessage('danger','Zone missing'); ensureModal()?.show(); });
    document.getElementById('btnSubmitGenerate')?.addEventListener('click', e=>{
        e.preventDefault(); if(!zone) return showMessage('danger','Zone missing');
        const algorithm = algSelect.value;
        const keytype = keytypeSelect.value;
        const bits = algorithm.startsWith('RSA') ? (parseInt(bitsInput.value,10)||2048) : undefined;
        const mode = document.querySelector('input[name="rollover_mode"]:checked')?.value || 'add';
        const payload = { zone, keytype, algorithm, rollover_mode: mode, hold_days: holdDays };
        if(bits) payload.bits = bits;
        const btn = e.target;
        post('create_key', payload, btn, true);
        ensureModal()?.hide();
    });
    // Enhanced key toggle & delete with safety modals
    let pendingDeactivateId = null; let pendingDeleteId = null;
    const modalDeactivate = document.getElementById('modalDeactivateKey');
    const modalDelete = document.getElementById('modalDeleteKey');
    let modalDeactivateInstance = null, modalDeleteInstance = null;
    function ensureDeactivate(){ if(modalDeactivate && !modalDeactivateInstance){ modalDeactivateInstance = new bootstrap.Modal(modalDeactivate);} return modalDeactivateInstance; }
    function ensureDelete(){ if(modalDelete && !modalDeleteInstance){ modalDeleteInstance = new bootstrap.Modal(modalDelete);} return modalDeleteInstance; }
    function countActiveKeys(){ return [...document.querySelectorAll('#dnssecKeysBody tr')].filter(r=>r.dataset.active==='1').length; }
    document.querySelectorAll('.btnToggleKey').forEach(btn=>btn.addEventListener('click', e=>{ e.preventDefault(); if(!zone) return showMessage('danger','Zone missing'); if(btn.closest('tr')?.dataset.locked==='1') return; const id=btn.dataset.keyId; const currentlyActive = btn.dataset.active==='1'; if(currentlyActive){ // deactivating
                const row = btn.closest('tr');
                const onlyOne = countActiveKeys() === 1;
                pendingDeactivateId = id;
                document.getElementById('deactivateKeyDetails').innerHTML = 'Key <code>'+id+'</code> ('+row.dataset.keytype+' / '+row.dataset.alg+') will be set inactive.';
                document.getElementById('deactivateLastActive').style.display = onlyOne ? 'block':'none';
                ensureDeactivate().show();
            } else { // activating
                post('toggle_key',{ zone, key_id:id, active: '1' }, btn);
            }}));
    document.getElementById('btnConfirmDeactivate')?.addEventListener('click', e=>{ if(!pendingDeactivateId) return; const fakeBtn = document.querySelector('.btnToggleKey[data-key-id="'+pendingDeactivateId+'"]'); post('toggle_key',{ zone, key_id: pendingDeactivateId, active: '0' }, fakeBtn); ensureDeactivate().hide(); pendingDeactivateId=null; });
    document.querySelectorAll('.btnDeleteKey').forEach(btn=>btn.addEventListener('click', e=>{ e.preventDefault(); if(!zone) return showMessage('danger','Zone missing'); if(btn.closest('tr')?.dataset.locked==='1') return; const id=btn.dataset.keyId; const row = btn.closest('tr'); pendingDeleteId = id; const ds=row.dataset.ds||'0'; document.getElementById('deleteKeyDetails').innerHTML = 'Deleting key <code>'+id+'</code> ('+row.dataset.keytype+' / '+row.dataset.alg+')'+(ds!=='0' ? ' with <strong>'+ds+'</strong> DS entr'+(ds==='1'?'y':'ies')+' at parent.' : '.'); ensureDelete().show(); }));
    // Initialize tooltips for locked action buttons
    if(window.bootstrap){ document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el=>{ new bootstrap.Tooltip(el); }); }
    document.getElementById('btnConfirmDelete')?.addEventListener('click', e=>{ if(!pendingDeleteId) return; const btn = document.querySelector('.btnDeleteKey[data-key-id="'+pendingDeleteId+'"]'); post('delete_key',{ zone, key_id: pendingDeleteId }, btn); ensureDelete().hide(); pendingDeleteId=null; });
})();
</script>
<?php endif; ?>

<script>
// Defensive: ensure DNSSEC Status header retains its contextual background after theme scripts
document.addEventListener('DOMContentLoaded', function(){
    var hdr = document.querySelector('.card .card-header.bg-success, .card .card-header.bg-secondary');
    if (!hdr) return;
    // If a later stylesheet stripped the bg-* class effect, explicitly set computed color
    if (hdr.classList.contains('bg-success')) hdr.style.backgroundColor = getComputedStyle(document.documentElement).getPropertyValue('--bs-success');
    if (hdr.classList.contains('bg-secondary')) hdr.style.backgroundColor = getComputedStyle(document.documentElement).getPropertyValue('--bs-secondary');
    hdr.style.color = '#fff';
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
