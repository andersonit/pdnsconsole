<?php
/**
 * PDNS Console - DNSSEC Management (Placeholder)
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
    
    if (!$domainInfo) {
        $error = 'Zone not found or access denied.';
    }
} catch (Exception $e) {
    $error = 'Error loading zone: ' . $e->getMessage();
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
        <!-- DNSSEC Status Card -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-shield-lock me-2"></i>
                            DNSSEC Status
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="status-indicator me-3">
                                <div class="bg-warning rounded-circle" style="width: 12px; height: 12px;"></div>
                            </div>
                            <div>
                                <h6 class="mb-0">Not Configured</h6>
                                <small class="text-muted">DNSSEC is not enabled for this zone</small>
                            </div>
                        </div>
                        
                        <div class="d-grid">
                            <button class="btn btn-success disabled" disabled>
                                <i class="bi bi-gear me-1"></i>
                                Enable DNSSEC (Coming Soon)
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            What is DNSSEC?
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text">
                            DNS Security Extensions (DNSSEC) provides authentication of DNS data, 
                            authenticated denial of existence, and data integrity.
                        </p>
                        <ul class="list-unstyled">
                            <li><i class="bi bi-check-circle text-success me-2"></i>Prevents DNS spoofing</li>
                            <li><i class="bi bi-check-circle text-success me-2"></i>Ensures data integrity</li>
                            <li><i class="bi bi-check-circle text-success me-2"></i>Authenticates DNS responses</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Placeholder Content -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-tools me-2"></i>
                    DNSSEC Configuration
                </h5>
            </div>
            <div class="card-body">
                <div class="text-center py-5">
                    <i class="bi bi-tools display-4 text-muted mb-3"></i>
                    <h5 class="text-muted">DNSSEC Management Coming Soon</h5>
                    <p class="text-muted mb-4">
                        Full DNSSEC key management and configuration will be available in the next update.
                    </p>
                    
                    <div class="row justify-content-center">
                        <div class="col-md-8">
                            <div class="list-group">
                                <div class="list-group-item d-flex align-items-center">
                                    <i class="bi bi-key me-3 text-primary"></i>
                                    <div>
                                        <h6 class="mb-1">Key Generation</h6>
                                        <small class="text-muted">Automatic KSK and ZSK generation</small>
                                    </div>
                                </div>
                                <div class="list-group-item d-flex align-items-center">
                                    <i class="bi bi-arrow-repeat me-3 text-primary"></i>
                                    <div>
                                        <h6 class="mb-1">Key Rotation</h6>
                                        <small class="text-muted">Automated key rollover scheduling</small>
                                    </div>
                                </div>
                                <div class="list-group-item d-flex align-items-center">
                                    <i class="bi bi-file-earmark-text me-3 text-primary"></i>
                                    <div>
                                        <h6 class="mb-1">DS Records</h6>
                                        <small class="text-muted">Parent zone delegation signer records</small>
                                    </div>
                                </div>
                                <div class="list-group-item d-flex align-items-center">
                                    <i class="bi bi-graph-up me-3 text-primary"></i>
                                    <div>
                                        <h6 class="mb-1">Validation Testing</h6>
                                        <small class="text-muted">DNSSEC chain validation tools</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
