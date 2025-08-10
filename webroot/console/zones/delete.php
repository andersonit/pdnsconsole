<?php
/**
 * PDNS Console - Delete Zone Handler
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
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please go back and retry.';
    } else {
    $domainId = intval($_POST['domain_id']);
    $userProvidedConfirm = isset($_POST['confirm_value']) ? trim($_POST['confirm_value']) : '';
    
    try {
        if (empty($domainId)) {
            throw new Exception('Invalid zone ID.');
        }
        
        // For non-super admin users, pass tenant restriction
        $tenantId = null;
        if (!$isSuperAdmin && !empty($userTenants)) {
            $tenantId = $userTenants[0]; // Use first tenant for simplicity
        }
        
        // Get domain info before deletion for logging
        $domainInfo = $domain->getDomainById($domainId, $tenantId);
        if (!$domainInfo) {
            throw new Exception('Zone not found or access denied.');
        }
        
        // Enforce typed confirmation based on zone type
        $zoneType = $domainInfo['zone_type'] ?? 'forward';
        $expected = ($zoneType === 'reverse') ? 'confirm' : $domainInfo['name'];
        $valid = false;
        if ($zoneType === 'reverse') {
            $valid = (strtolower($userProvidedConfirm) === 'confirm');
        } else {
            // Domain names are case-insensitive; require exact characters ignoring case
            $valid = (strcasecmp($userProvidedConfirm, $expected) === 0);
        }
        if (!$valid) {
            throw new Exception('Confirmation text did not match the required value.');
        }

        // Delete the domain after confirmation passes
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
    }
} else {
    $error = 'Invalid request method.';
}

?>

<div class="container-fluid py-4">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        renderBreadcrumb([
            ['label' => 'Zones', 'url' => '?page=zone_manage'],
            ['label' => 'Delete Zone']
        ], $isSuperAdmin);
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <!-- Page Header -->
            <div class="mb-4">
                <h2 class="h4 mb-2">
                    <i class="bi bi-trash me-2 text-danger"></i>
                    Delete Zone
                </h2>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
                <div class="text-center">
                    <a href="?page=zone_manage" class="btn btn-primary">Return to Zones</a>
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
                        <h5>Zone Deleted Successfully</h5>
                        <p class="text-muted mb-2">
                            The zone, its DNS records, DNSSEC data, metadata, comments, and tenant association have been removed.
                        </p>
                        <p class="text-muted small mb-0">
                            If this zone was referenced by external services or Dynamic DNS clients, update those configurations accordingly.
                        </p>
                        
                        <div class="mt-4">
                            <a href="?page=zone_manage" class="btn btn-primary me-2">
                                <i class="bi bi-list-ul me-1"></i>
                                View All Zones
                            </a>
                            <a href="?page=zone_add" class="btn btn-outline-primary">
                                <i class="bi bi-plus-circle me-1"></i>
                                Add New Zone
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
