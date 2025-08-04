<?php
/**
 * PDNS Console - Tenant Management (Super Admin Only)
 */

// Get required classes
$user = new User();
$settings = new Settings();

// Check if user is super admin
if (!$user->isSuperAdmin($currentUser['id'])) {
    header('Location: /?page=dashboard');
    exit;
}

$pageTitle = 'Tenant Management';
$branding = $settings->getBranding();
$db = Database::getInstance();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create_tenant':
                $name = trim($_POST['name'] ?? '');
                $contactEmail = trim($_POST['contact_email'] ?? '');
                $maxDomains = intval($_POST['max_domains'] ?? 0);
                
                if (empty($name)) {
                    throw new Exception('Tenant name is required.');
                }
                
                if (!empty($contactEmail) && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid contact email address.');
                }
                
                $db->execute(
                    "INSERT INTO tenants (name, contact_email, max_domains) VALUES (?, ?, ?)",
                    [$name, $contactEmail ?: null, $maxDomains]
                );
                
                $message = 'Tenant created successfully.';
                $messageType = 'success';
                break;
                
            case 'update_tenant':
                $tenantId = intval($_POST['tenant_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $contactEmail = trim($_POST['contact_email'] ?? '');
                $maxDomains = intval($_POST['max_domains'] ?? 0);
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                
                if (empty($name)) {
                    throw new Exception('Tenant name is required.');
                }
                
                if (!empty($contactEmail) && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid contact email address.');
                }
                
                $db->execute(
                    "UPDATE tenants SET name = ?, contact_email = ?, max_domains = ?, is_active = ? WHERE id = ?",
                    [$name, $contactEmail ?: null, $maxDomains, $isActive, $tenantId]
                );
                
                $message = 'Tenant updated successfully.';
                $messageType = 'success';
                break;
                
            case 'delete_tenant':
                $tenantId = intval($_POST['tenant_id'] ?? 0);
                
                // Check if tenant has domains
                $domainCount = $db->fetch(
                    "SELECT COUNT(*) as count FROM domain_tenants WHERE tenant_id = ?",
                    [$tenantId]
                )['count'] ?? 0;
                
                if ($domainCount > 0) {
                    throw new Exception('Cannot delete tenant with existing domains. Please transfer or delete domains first.');
                }
                
                // Check if tenant has users
                $userCount = $db->fetch(
                    "SELECT COUNT(*) as count FROM user_tenants WHERE tenant_id = ?",
                    [$tenantId]
                )['count'] ?? 0;
                
                if ($userCount > 0) {
                    throw new Exception('Cannot delete tenant with assigned users. Please reassign users first.');
                }
                
                $db->execute("DELETE FROM tenants WHERE id = ?", [$tenantId]);
                
                $message = 'Tenant deleted successfully.';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// Get all tenants with statistics
$tenants = $db->fetchAll(
    "SELECT t.*, 
            COUNT(DISTINCT dt.domain_id) as domain_count,
            COUNT(DISTINCT ut.user_id) as user_count
     FROM tenants t
     LEFT JOIN domain_tenants dt ON t.id = dt.tenant_id
     LEFT JOIN user_tenants ut ON t.id = ut.tenant_id
     GROUP BY t.id
     ORDER BY t.created_at DESC"
);

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="bi bi-building me-2"></i>
                        Tenant Management
                    </h1>
                    <p class="text-muted mb-0">Manage tenant organizations and domain limits</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#createTenantModal">
                        <i class="bi bi-building-add me-1"></i>
                        Create Tenant
                    </button>
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

    <!-- Tenants Grid -->
    <div class="row">
        <?php foreach ($tenants as $tenant): ?>
            <div class="col-xl-4 col-lg-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h6 class="card-title mb-1"><?php echo htmlspecialchars($tenant['name']); ?></h6>
                                <?php if ($tenant['contact_email']): ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($tenant['contact_email']); ?></small>
                                <?php endif; ?>
                            </div>
                            <span class="badge bg-<?php echo $tenant['is_active'] ? 'success' : 'secondary'; ?>">
                                <?php echo $tenant['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <div class="text-center">
                                    <div class="fs-4 fw-bold text-primary"><?php echo $tenant['domain_count']; ?></div>
                                    <small class="text-muted">Domains</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center">
                                    <div class="fs-4 fw-bold text-info"><?php echo $tenant['user_count']; ?></div>
                                    <small class="text-muted">Users</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <small class="text-muted">Domain Limit:</small>
                            <div class="fw-semibold">
                                <?php echo $tenant['max_domains'] == 0 ? 'Unlimited' : number_format($tenant['max_domains']); ?>
                            </div>
                            <?php if ($tenant['max_domains'] > 0): ?>
                                <div class="progress mt-2" style="height: 4px;">
                                    <?php 
                                    $percentage = min(($tenant['domain_count'] / $tenant['max_domains']) * 100, 100);
                                    ?>
                                    <div class="progress-bar <?php echo $percentage >= 80 ? 'bg-warning' : 'bg-success'; ?>" 
                                         style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="row g-2">
                            <div class="col">
                                <button type="button" class="btn btn-outline-primary btn-sm w-100"
                                        onclick="editTenant(<?php echo htmlspecialchars(json_encode($tenant)); ?>)">
                                    <i class="bi bi-pencil me-1"></i>
                                    Edit
                                </button>
                            </div>
                            <div class="col">
                                <button type="button" class="btn btn-outline-danger btn-sm w-100"
                                        onclick="deleteTenant(<?php echo $tenant['id']; ?>, '<?php echo htmlspecialchars($tenant['name']); ?>', <?php echo $tenant['domain_count']; ?>, <?php echo $tenant['user_count']; ?>)">
                                    <i class="bi bi-trash me-1"></i>
                                    Delete
                                </button>
                            </div>
                        </div>
                        
                        <div class="mt-3 pt-3 border-top">
                            <small class="text-muted">
                                Created: <?php echo date('M j, Y', strtotime($tenant['created_at'])); ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($tenants)): ?>
            <div class="col-12">
                <div class="text-center py-5">
                    <i class="bi bi-building fs-1 text-muted opacity-50 d-block mb-3"></i>
                    <h5 class="text-muted">No Tenants Found</h5>
                    <p class="text-muted">Create your first tenant organization to get started.</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTenantModal">
                        <i class="bi bi-building-add me-1"></i>
                        Create First Tenant
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create Tenant Modal -->
<div class="modal fade" id="createTenantModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_tenant">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Tenant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Tenant Name *</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="contact_email" class="form-label">Contact Email</label>
                        <input type="email" class="form-control" id="contact_email" name="contact_email">
                    </div>
                    <div class="mb-3">
                        <label for="max_domains" class="form-label">Domain Limit</label>
                        <input type="number" class="form-control" id="max_domains" name="max_domains" value="0" min="0">
                        <small class="text-muted">0 = Unlimited domains</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Tenant</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Tenant Modal -->
<div class="modal fade" id="editTenantModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_tenant">
                <input type="hidden" name="tenant_id" id="edit_tenant_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Tenant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Tenant Name *</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_contact_email" class="form-label">Contact Email</label>
                        <input type="email" class="form-control" id="edit_contact_email" name="contact_email">
                    </div>
                    <div class="mb-3">
                        <label for="edit_max_domains" class="form-label">Domain Limit</label>
                        <input type="number" class="form-control" id="edit_max_domains" name="max_domains" min="0">
                        <small class="text-muted">0 = Unlimited domains</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                            <label class="form-check-label" for="edit_is_active">
                                Active Tenant
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Tenant</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Tenant Modal -->
<div class="modal fade" id="deleteTenantModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="delete_tenant">
                <input type="hidden" name="tenant_id" id="delete_tenant_id">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Tenant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action cannot be undone!
                    </div>
                    <p>Are you sure you want to delete tenant: <strong id="delete_tenant_name"></strong>?</p>
                    <div id="delete_tenant_warnings"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="delete_tenant_btn">Delete Tenant</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editTenant(tenantData) {
    document.getElementById('edit_tenant_id').value = tenantData.id;
    document.getElementById('edit_name').value = tenantData.name;
    document.getElementById('edit_contact_email').value = tenantData.contact_email || '';
    document.getElementById('edit_max_domains').value = tenantData.max_domains;
    document.getElementById('edit_is_active').checked = tenantData.is_active == 1;
    
    new bootstrap.Modal(document.getElementById('editTenantModal')).show();
}

function deleteTenant(tenantId, tenantName, domainCount, userCount) {
    document.getElementById('delete_tenant_id').value = tenantId;
    document.getElementById('delete_tenant_name').textContent = tenantName;
    
    const warningsDiv = document.getElementById('delete_tenant_warnings');
    const deleteBtn = document.getElementById('delete_tenant_btn');
    
    let warnings = [];
    
    if (domainCount > 0) {
        warnings.push(`This tenant has ${domainCount} domain(s) assigned.`);
    }
    
    if (userCount > 0) {
        warnings.push(`This tenant has ${userCount} user(s) assigned.`);
    }
    
    if (warnings.length > 0) {
        warningsDiv.innerHTML = '<div class="alert alert-warning"><ul class="mb-0">' + 
            warnings.map(w => '<li>' + w + '</li>').join('') + 
            '</ul><p class="mb-0 mt-2"><strong>Please transfer or delete these items first.</strong></p></div>';
        deleteBtn.disabled = true;
    } else {
        warningsDiv.innerHTML = '';
        deleteBtn.disabled = false;
    }
    
    new bootstrap.Modal(document.getElementById('deleteTenantModal')).show();
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
