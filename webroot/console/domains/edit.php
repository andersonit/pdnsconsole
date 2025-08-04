<?php
/**
 * PDNS Console - Edit Domain
 */

// Get classes (currentUser is already set by index.php)
$user = new User();
$domain = new Domain();

// Check if user is super admin
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

// Get domain ID
$domainId = intval($_GET['id'] ?? 0);
if (empty($domainId)) {
    header('Location: ?page=domains');
    exit;
}

// Get user's tenants for non-super admin users
$userTenants = [];
if (!$isSuperAdmin) {
    $tenantData = $user->getUserTenants($currentUser['id']);
    $userTenants = array_column($tenantData, 'tenant_id');
    if (empty($userTenants)) {
        $error = 'No tenants assigned to your account. Please contact an administrator.';
    }
}

// Get domain info
$domainInfo = null;
try {
    $tenantId = null;
    if (!$isSuperAdmin && !empty($userTenants)) {
        $tenantId = $userTenants[0]; // Use first tenant for simplicity
    }
    
    $domainInfo = $domain->getDomainById($domainId, $tenantId);
    if (!$domainInfo) {
        $error = 'Domain not found or access denied.';
    }
} catch (Exception $e) {
    $error = 'Error loading domain: ' . $e->getMessage();
}

// Get all tenants for super admin
$allTenants = [];
if ($isSuperAdmin && $domainInfo) {
    $db = Database::getInstance();
    $allTenants = $db->fetchAll("SELECT id, name FROM tenants WHERE is_active = 1 ORDER BY name");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $domainInfo) {
    $domainType = $_POST['domain_type'] ?? $domainInfo['type'];
    $account = $_POST['account'] ?? $domainInfo['account'];
    $newTenantId = null;
    
    // Handle tenant assignment for super admin
    if ($isSuperAdmin && isset($_POST['tenant_id'])) {
        $newTenantId = intval($_POST['tenant_id']);
    }
    
    try {
        $updateData = [];
        
        // Update domain type if changed
        if ($domainType !== $domainInfo['type']) {
            $updateData['type'] = $domainType;
        }
        
        // Update account if changed
        if ($account !== $domainInfo['account']) {
            $updateData['account'] = $account;
        }
        
        // Update domain if there are changes
        if (!empty($updateData)) {
            $domain->updateDomain($domainId, $updateData, $tenantId);
        }
        
        // Handle tenant reassignment (super admin only)
        if ($isSuperAdmin && $newTenantId && $newTenantId != $domainInfo['tenant_id']) {
            $db = Database::getInstance();
            $db->beginTransaction();
            
            try {
                // Remove old tenant assignment
                $db->execute("DELETE FROM domain_tenants WHERE domain_id = ?", [$domainId]);
                
                // Add new tenant assignment
                $db->execute(
                    "INSERT INTO domain_tenants (domain_id, tenant_id) VALUES (?, ?)",
                    [$domainId, $newTenantId]
                );
                
                $db->commit();
                
                // Log the tenant change
                $db->execute(
                    "INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address) 
                     VALUES (?, 'domain_tenant_change', 'domain_tenants', ?, ?, ?, ?)",
                    [
                        $currentUser['user_id'],
                        $domainId,
                        json_encode(['old_tenant_id' => $domainInfo['tenant_id']]),
                        json_encode(['new_tenant_id' => $newTenantId]),
                        $_SERVER['REMOTE_ADDR'] ?? ''
                    ]
                );
                
            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }
        }
        
        $success = 'Domain updated successfully!';
        
        // Refresh domain info
        $domainInfo = $domain->getDomainById($domainId, $tenantId);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Page title
$pageTitle = 'Edit Domain' . ($domainInfo ? ' - ' . $domainInfo['name'] : '');
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <!-- Page Header -->
            <div class="mb-4">
                <div class="d-flex align-items-center mb-2">
                    <a href="?page=domains" class="btn btn-outline-secondary btn-sm me-3">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                    <h2 class="h4 mb-0">
                        <i class="bi bi-pencil me-2 text-primary"></i>
                        Edit Domain
                    </h2>
                </div>
                <?php if ($domainInfo): ?>
                    <p class="text-muted mb-0">
                        Modify settings for <strong><?php echo htmlspecialchars($domainInfo['name']); ?></strong>
                    </p>
                <?php endif; ?>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
                
                <?php if (!$domainInfo): ?>
                    <div class="text-center">
                        <a href="?page=domains" class="btn btn-primary">
                            <i class="bi bi-arrow-left me-1"></i>
                            Back to Domains
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if ($domainInfo): ?>
                <!-- Domain Edit Form -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-gear me-2"></i>
                            Domain Settings
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Domain Name</label>
                                        <input type="text" class="form-control" 
                                               value="<?php echo htmlspecialchars($domainInfo['name']); ?>" 
                                               readonly>
                                        <div class="form-text">Domain name cannot be changed after creation</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="domain_type" class="form-label">Domain Type</label>
                                        <select class="form-select" id="domain_type" name="domain_type">
                                            <?php
                                            $types = ['NATIVE' => 'Native', 'MASTER' => 'Master', 'SLAVE' => 'Slave'];
                                            foreach ($types as $value => $label):
                                            ?>
                                                <option value="<?php echo $value; ?>" 
                                                        <?php echo $domainInfo['type'] == $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="account" class="form-label">Account</label>
                                <input type="text" class="form-control" id="account" name="account" 
                                       value="<?php echo htmlspecialchars($domainInfo['account'] ?? ''); ?>"
                                       placeholder="Optional account identifier">
                                <div class="form-text">Used for organizing domains (optional)</div>
                            </div>

                            <?php if ($isSuperAdmin && !empty($allTenants)): ?>
                                <div class="mb-3">
                                    <label for="tenant_id" class="form-label">Assigned Tenant</label>
                                    <select class="form-select" id="tenant_id" name="tenant_id">
                                        <option value="">Unassigned</option>
                                        <?php foreach ($allTenants as $tenant): ?>
                                            <option value="<?php echo $tenant['id']; ?>" 
                                                    <?php echo $domainInfo['tenant_id'] == $tenant['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($tenant['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Assign domain to a tenant organization</div>
                                </div>
                            <?php endif; ?>

                            <div class="d-flex justify-content-between">
                                <a href="?page=domains" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left me-1"></i>
                                    Back to Domains
                                </a>
                                <div>
                                    <a href="?page=records&domain_id=<?php echo $domainInfo['id']; ?>" 
                                       class="btn btn-outline-primary me-2">
                                        <i class="bi bi-list-ul me-1"></i>
                                        Manage Records
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Update Domain
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Domain Information -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Domain Information
                                </h6>
                            </div>
                            <div class="card-body">
                                <dl class="row mb-0">
                                    <dt class="col-sm-4">Domain ID:</dt>
                                    <dd class="col-sm-8"><?php echo $domainInfo['id']; ?></dd>
                                    
                                    <dt class="col-sm-4">Type:</dt>
                                    <dd class="col-sm-8">
                                        <span class="badge bg-secondary">
                                            <?php echo htmlspecialchars($domainInfo['type']); ?>
                                        </span>
                                    </dd>
                                    
                                    <?php if ($domainInfo['tenant_name']): ?>
                                        <dt class="col-sm-4">Tenant:</dt>
                                        <dd class="col-sm-8">
                                            <span class="badge bg-primary">
                                                <?php echo htmlspecialchars($domainInfo['tenant_name']); ?>
                                            </span>
                                        </dd>
                                    <?php endif; ?>
                                </dl>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
                                    <i class="bi bi-activity me-2"></i>
                                    Quick Actions
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="?page=records&domain_id=<?php echo $domainInfo['id']; ?>" 
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-list-ul me-1"></i>
                                        Manage DNS Records
                                    </a>
                                    <a href="?page=records&domain_id=<?php echo $domainInfo['id']; ?>&action=add" 
                                       class="btn btn-outline-success btn-sm">
                                        <i class="bi bi-plus-circle me-1"></i>
                                        Add New Record
                                    </a>
                                    <?php if ($isSuperAdmin): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm" 
                                                onclick="confirmDelete(<?php echo $domainInfo['id']; ?>, '<?php echo htmlspecialchars($domainInfo['name'], ENT_QUOTES); ?>')">
                                            <i class="bi bi-trash me-1"></i>
                                            Delete Domain
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle text-danger me-2"></i>
                    Confirm Domain Deletion
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the domain <strong id="deleteDomainName"></strong>?</p>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>This action cannot be undone!</strong>
                    <br>All DNS records, DNSSEC keys, and associated data will be permanently deleted.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="?page=domain_delete" class="d-inline">
                    <input type="hidden" name="domain_id" id="deleteDomainId">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>
                        Delete Domain
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(domainId, domainName) {
    document.getElementById('deleteDomainId').value = domainId;
    document.getElementById('deleteDomainName').textContent = domainName;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
