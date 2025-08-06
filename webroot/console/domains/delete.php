<?php
/**
 * PDNS Console - Delete Domain Handler
 */

// Get current user and tenant info
// Get classes (currentUser is already set by index.php)
$user = new User();
$domain = new Domain();

// Check if user is super admin
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

// Get user's tenants for non-super admin users
$userTenants = [];
if (!$isSuperAdmin) {
    $tenantData = $user->getUserTenants($currentUser['id']);
    $userTenants = array_column($tenantData, 'id');
}

$error = '';
$success = '';

// Handle domain deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['domain_id'])) {
    $domainId = intval($_POST['domain_id']);
    
    try {
        if (empty($domainId)) {
            throw new Exception('Invalid domain ID.');
        }
        
        // For non-super admin users, pass tenant restriction
        $tenantId = null;
        if (!$isSuperAdmin && !empty($userTenants)) {
            $tenantId = $userTenants[0]; // Use first tenant for simplicity
        }
        
        // Get domain info before deletion for logging
        $domainInfo = $domain->getDomainById($domainId, $tenantId);
        if (!$domainInfo) {
            throw new Exception('Domain not found or access denied.');
        }
        
        // Delete the domain
        $result = $domain->deleteDomain($domainId, $tenantId);
        
        if ($result) {
            // Log the deletion
            $db = Database::getInstance();
            $db->execute(
                "INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, ip_address) 
                 VALUES (?, 'domain_delete', 'domains', ?, ?, ?)",
                [
                    $currentUser['id'],
                    $domainId,
                    json_encode([
                        'domain_name' => $domainInfo['name'],
                        'domain_type' => $domainInfo['type'],
                        'tenant_id' => $domainInfo['tenant_id']
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]
            );
            
            $success = "Domain '{$domainInfo['name']}' has been successfully deleted.";
        } else {
            throw new Exception('Failed to delete domain.');
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
} else {
    $error = 'Invalid request method.';
}

?>

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
                        <i class="bi bi-trash me-2 text-danger"></i>
                        Delete Domain
                    </h2>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
                <div class="text-center">
                    <a href="?page=domains" class="btn btn-primary">
                        <i class="bi bi-arrow-left me-1"></i>
                        Back to Domains
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    <strong>Success:</strong> <?php echo htmlspecialchars($success); ?>
                </div>
                
                <div class="card">
                    <div class="card-body text-center">
                        <i class="bi bi-check-circle display-4 text-success mb-3"></i>
                        <h5>Domain Deleted Successfully</h5>
                        <p class="text-muted">
                            The domain and all associated records have been permanently removed from the system.
                        </p>
                        
                        <div class="mt-4">
                            <a href="?page=domains" class="btn btn-primary me-2">
                                <i class="bi bi-list-ul me-1"></i>
                                View All Domains
                            </a>
                            <a href="?page=domain_add" class="btn btn-outline-primary">
                                <i class="bi bi-plus-circle me-1"></i>
                                Add New Domain
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
