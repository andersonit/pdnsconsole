<?php
/**
 * PDNS Console - Dynamic DNS Management (Token Management UI)
 */

$domainId = intval($_GET['domain_id'] ?? 0);
if (empty($domainId)) { header('Location: ?page=zone_manage'); exit; }

// Get domain info
$domain = new Domain();
$db = Database::getInstance();
$audit = new AuditLog();
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

$domainInfo = null; $error = null; $success = null;
try {
    if ($isSuperAdmin) { $domainInfo = $domain->getDomainById($domainId); }
    else { $tenantId = $currentUser['tenant_id'] ?? null; $domainInfo = $domain->getDomainById($domainId, $tenantId); }
    if (!$domainInfo) { $error = 'Zone not found or access denied.'; }
} catch (Exception $e) { $error = 'Error loading zone: ' . $e->getMessage(); }

// Handle actions
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create') {
                $recordId = intval($_POST['record_id'] ?? 0);
                $secret = trim((string)($_POST['secret'] ?? ''));
                $active = isset($_POST['is_active']) ? 1 : 0;
                $expiresAt = trim((string)($_POST['expires_at'] ?? ''));

                // Validate record belongs to domain and is A/AAAA
                $rec = $db->fetch("SELECT id, name, type FROM records WHERE id=? AND domain_id=? AND type IN ('A','AAAA')", [$recordId, $domainId]);
                if (!$rec) { throw new Exception('Invalid record selected'); }

                // Generate unique token
                $token = null; $tries = 0;
                do {
                    $token = bin2hex(random_bytes(24)); // 48 hex chars
                    $exists = $db->fetch("SELECT id FROM dynamic_dns_tokens WHERE token=?", [$token]);
                    $tries++;
                } while ($exists && $tries < 3);
                if ($exists) { throw new Exception('Could not generate unique token, try again'); }

                $secretHash = $secret !== '' ? password_hash($secret, PASSWORD_DEFAULT) : null;
                $tenantId = $domainInfo['tenant_id'];
                $expiresDb = $expiresAt !== '' ? date('Y-m-d H:i:s', strtotime($expiresAt)) : null;

                $db->execute(
                    "INSERT INTO dynamic_dns_tokens (token, secret_hash, record_id, domain_id, tenant_id, is_active, expires_at, created_at) VALUES (?,?,?,?,?,?,?,NOW())",
                    [$token, $secretHash, $recordId, $domainId, $tenantId, $active, $expiresDb]
                );
                $newId = $db->getConnection()->lastInsertId();
                $audit->logAction($currentUser['id'], 'DDNS_TOKEN_CREATE', 'dynamic_dns_tokens', (int)$newId, null, ['token' => substr($token,0,6) . '…'], null, ['record_id' => $recordId, 'domain_id' => $domainId]);
                $success = 'Token created successfully';
            } elseif ($action === 'toggle') {
                $id = intval($_POST['id'] ?? 0);
                $row = $db->fetch("SELECT id, is_active FROM dynamic_dns_tokens WHERE id=? AND domain_id=?", [$id, $domainId]);
                if (!$row) { throw new Exception('Token not found'); }
                $newActive = $row['is_active'] ? 0 : 1;
                $db->execute("UPDATE dynamic_dns_tokens SET is_active=? WHERE id=?", [$newActive, $id]);
                $audit->logAction($currentUser['id'], $newActive ? 'DDNS_TOKEN_ENABLE' : 'DDNS_TOKEN_DISABLE', 'dynamic_dns_tokens', $id, ['is_active' => $row['is_active']], ['is_active' => $newActive], null, ['domain_id'=>$domainId]);
                $success = $newActive ? 'Token enabled' : 'Token disabled';
            } elseif ($action === 'delete') {
                $id = intval($_POST['id'] ?? 0);
                $row = $db->fetch("SELECT id FROM dynamic_dns_tokens WHERE id=? AND domain_id=?", [$id, $domainId]);
                if (!$row) { throw new Exception('Token not found'); }
                $db->execute("DELETE FROM dynamic_dns_tokens WHERE id=?", [$id]);
                $audit->logAction($currentUser['id'], 'DDNS_TOKEN_DELETE', 'dynamic_dns_tokens', $id, null, null, null, ['domain_id'=>$domainId]);
                $success = 'Token deleted';
            }
        } catch (Exception $ex) {
            $error = $ex->getMessage();
        }
    }
}

$pageTitle = 'Dynamic DNS Management';
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid mt-4">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        $crumbs = [['label' => 'Zones', 'url' => '?page=zone_manage']];
        if ($domainInfo) { $crumbs[] = ['label' => $domainInfo['name'], 'url' => '?page=records&domain_id=' . $domainId]; }
        $crumbs[] = ['label' => 'Dynamic DNS'];
        renderBreadcrumb($crumbs, $isSuperAdmin, ['class' => 'mb-4']);
    ?>

    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-0"><i class="bi bi-arrow-repeat me-2 text-info"></i>Dynamic DNS Management</h1>
                <?php if ($domainInfo): ?><p class="text-muted mb-0">For zone <strong><?php echo htmlspecialchars($domainInfo['name']); ?></strong></p><?php endif; ?>
            </div>
            <div class="btn-group">
                <a href="?page=records&domain_id=<?php echo $domainId; ?>" class="btn btn-outline-primary"><i class="bi bi-list-ul me-1"></i>Manage Records</a>
                <a href="?page=zone_dnssec&domain_id=<?php echo $domainId; ?>" class="btn btn-outline-success"><i class="bi bi-shield-lock me-1"></i>DNSSEC</a>
                <a href="?page=zone_edit&id=<?php echo $domainId; ?>" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Zone Settings</a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($domainInfo): ?>
        <?php
            // Records available for DDNS
            $records = $db->fetchAll("SELECT id, name, type, content FROM records WHERE domain_id=? AND type IN ('A','AAAA') ORDER BY name, type", [$domainId]);
            // Current tokens
            $tokens = $db->fetchAll("SELECT t.*, r.name as rec_name, r.type as rec_type, r.content as rec_content
                                     FROM dynamic_dns_tokens t JOIN records r ON r.id = t.record_id
                                     WHERE t.domain_id=? ORDER BY t.created_at DESC", [$domainId]);
        ?>
        <div class="row">
            <div class="col-lg-5">
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0"><i class="bi bi-key me-2"></i>Create Token</h5>
                    </div>
                    <form method="post" class="card-body">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label for="record_id" class="form-label">Bind to Record</label>
                            <select id="record_id" name="record_id" class="form-select" required>
                                <option value="">Select an A/AAAA record…</option>
                                <?php foreach ($records as $r): ?>
                                    <option value="<?php echo (int)$r['id']; ?>">
                                        <?php echo htmlspecialchars($r['name']); ?> (<?php echo $r['type']; ?> → <?php echo htmlspecialchars($r['content']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Each token is bound 1:1 to a single A/AAAA record.</div>
                        </div>
                        <div class="mb-3">
                            <label for="secret" class="form-label">Optional Secret</label>
                            <input type="text" id="secret" name="secret" class="form-control" placeholder="Leave blank for token-only auth">
                            <div class="form-text">If set, client must use this as the Basic Auth password.</div>
                        </div>
                        <div class="mb-3">
                            <label for="expires_at" class="form-label">Expiration (optional)</label>
                            <input type="datetime-local" id="expires_at" name="expires_at" class="form-control">
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success text-light"><i class="bi bi-plus-circle me-1"></i>Create Token</button>
                        </div>
                    </form>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="bi bi-info-circle me-2"></i>ddclient Example</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Use this configuration on your client. Replace values with your token and optional secret.</p>
                        <pre class="bg-light p-3 rounded"><code>protocol=dyndns2
server=<?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'your-pdns-console'); ?>
login=YOUR_TOKEN
password=YOUR_SECRET_OR_EMPTY
<?php echo htmlspecialchars($domainInfo['name']); ?></code></pre>
                        <p class="text-muted mb-0">Endpoint: <code>/api/dynamic_dns.php</code> (default for dyndns2 with ddclient)</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><i class="bi bi-shield-check me-2"></i>Tokens</h5>
                        <span class="badge bg-secondary"><?php echo count($tokens); ?> total</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Token</th>
                                    <th>Record</th>
                                    <th>Status</th>
                                    <th>Last Used</th>
                                    <th>Last IP</th>
                                    <th>Throttle</th>
                                    <th>Expires</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$tokens): ?>
                                    <tr><td colspan="8" class="text-center text-muted py-4">No tokens yet.</td></tr>
                                <?php else: foreach ($tokens as $t): ?>
                                    <?php $masked = substr($t['token'], 0, 6) . '…' . substr($t['token'], -4); ?>
                                    <tr>
                                        <td>
                                            <a href="#" class="text-decoration-none view-token" data-token="<?php echo htmlspecialchars($t['token']); ?>" title="View token">
                                                <code><?php echo htmlspecialchars($masked); ?></code>
                                            </a>
                                            <?php if (!empty($t['secret_hash'])): ?><span class="badge bg-secondary ms-2">secret</span><?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($t['rec_name']); ?> <span class="badge bg-light text-dark border ms-1"><?php echo $t['rec_type']; ?></span></div>
                                            <small class="text-muted">Current: <?php echo htmlspecialchars($t['rec_content']); ?></small>
                                        </td>
                                        <td>
                                            <?php if ((int)$t['is_active'] === 1): ?><span class="badge bg-success">Active</span><?php else: ?><span class="badge bg-secondary">Disabled</span><?php endif; ?>
                                        </td>
                                        <td><small class="text-muted"><?php echo $t['last_used'] ? htmlspecialchars($t['last_used']) : '—'; ?></small></td>
                                        <td><small class="text-muted"><?php echo $t['last_ip'] ? htmlspecialchars($t['last_ip']) : '—'; ?></small></td>
                                        <td>
                                            <?php if (!empty($t['throttle_until']) && strtotime($t['throttle_until']) > time()): ?>
                                                <span class="badge bg-warning text-dark">Until <?php echo htmlspecialchars($t['throttle_until']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small class="text-muted"><?php echo $t['expires_at'] ? htmlspecialchars($t['expires_at']) : '—'; ?></small></td>
                                        <td class="text-end">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                                <button class="btn btn-sm <?php echo $t['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success'; ?>" title="<?php echo $t['is_active'] ? 'Disable' : 'Enable'; ?>">
                                                    <i class="bi <?php echo $t['is_active'] ? 'bi-pause' : 'bi-play'; ?>"></i>
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this token? This action cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer">
                        <small class="text-muted">Rate limit: 3 requests / 3 minutes; throttle 10 minutes when exceeded.</small>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>

<!-- Token Modal -->
<div class="modal fade" id="tokenModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key me-2"></i>API Token</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label for="tokenModalValue" class="form-label">Use this token with Basic Auth username</label>
                <div class="input-group mb-2">
                    <input type="text" class="form-control" id="tokenModalValue" readonly>
                    <button class="btn btn-outline-secondary" type="button" id="copyTokenBtn">
                        <i class="bi bi-clipboard"></i>
                        <span class="ms-1">Copy</span>
                    </button>
                </div>
                <small class="text-muted">Keep this token secret. Anyone with this token can update the bound record.</small>
            </div>
        </div>
    </div>
 </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('tokenModal');
    const tokenInput = document.getElementById('tokenModalValue');
    const copyBtn = document.getElementById('copyTokenBtn');
    let modalInstance = null;
    try { if (window.bootstrap && modalEl) { modalInstance = new bootstrap.Modal(modalEl); } } catch (e) { /* noop */ }

    document.querySelectorAll('.view-token').forEach(function(el) {
        el.addEventListener('click', function(ev) {
            ev.preventDefault();
            const tok = this.getAttribute('data-token') || '';
            tokenInput.value = tok;
            if (modalInstance) { modalInstance.show(); }
            else { modalEl.classList.add('show'); modalEl.style.display = 'block'; }
        });
    });

    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            const txt = tokenInput.value;
            if (!txt) return;
            navigator.clipboard.writeText(txt).then(function() {
                const icon = copyBtn.querySelector('i');
                const label = copyBtn.querySelector('span');
                if (icon) icon.className = 'bi bi-clipboard-check';
                if (label) label.textContent = 'Copied';
                setTimeout(function() {
                    if (icon) icon.className = 'bi bi-clipboard';
                    if (label) label.textContent = 'Copy';
                }, 1500);
            });
        });
    }
});
</script>
