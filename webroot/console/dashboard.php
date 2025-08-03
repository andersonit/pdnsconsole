<?php
/**
 * PDNS Console - Dashboard
 */

// Get dashboard statistics
$db = Database::getInstance();

// Count domains (tenant-filtered for non-super admins)
if ($user->isSuperAdmin($currentUser['id'])) {
    $domainsCount = $db->fetch("SELECT COUNT(*) as count FROM domains");
    $totalDomains = $domainsCount['count'] ?? 0;
    
    $usersCount = $db->fetch("SELECT COUNT(*) as count FROM admin_users WHERE is_active = 1");
    $totalUsers = $usersCount['count'] ?? 0;
    
    $tenantsCount = $db->fetch("SELECT COUNT(*) as count FROM tenants WHERE is_active = 1");
    $totalTenants = $tenantsCount['count'] ?? 0;
} else {
    // For tenant admins, show only their domains
    $userTenants = $db->fetchAll(
        "SELECT tenant_id FROM user_tenants WHERE user_id = ?", 
        [$currentUser['id']]
    );
    $tenantIds = array_column($userTenants, 'tenant_id');
    
    if (!empty($tenantIds)) {
        $placeholders = str_repeat('?,', count($tenantIds) - 1) . '?';
        $domainsCount = $db->fetch(
            "SELECT COUNT(DISTINCT d.id) as count FROM domains d 
             JOIN domain_tenants dt ON d.id = dt.domain_id 
             WHERE dt.tenant_id IN ($placeholders)", 
            $tenantIds
        );
        $totalDomains = $domainsCount['count'] ?? 0;
    } else {
        $totalDomains = 0;
    }
    
    $totalUsers = null; // Hide for tenant admins
    $totalTenants = count($tenantIds);
}

// Count records
$recordsCount = $db->fetch("SELECT COUNT(*) as count FROM records");
$totalRecords = $recordsCount['count'] ?? 0;

// Get DNSSEC enabled domains count
$dnssecCount = $db->fetch(
    "SELECT COUNT(DISTINCT d.id) as count FROM domains d 
     JOIN domainmetadata dm ON d.id = dm.domain_id 
     WHERE dm.kind = 'NSEC3PARAM' OR dm.kind = 'PRESIGNED'"
);
$dnssecDomains = $dnssecCount['count'] ?? 0;

// Get recent domains
$recentDomains = $db->fetchAll(
    "SELECT d.id, d.name, d.type, dt.created_at 
     FROM domains d 
     LEFT JOIN domain_tenants dt ON d.id = dt.domain_id 
     ORDER BY d.id DESC 
     LIMIT 5"
);

// Page title
$pageTitle = 'Dashboard';
?>

<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="container-fluid">
    <!-- Welcome Section -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-speedometer2 me-2 text-primary"></i>
                Welcome back, <?php echo htmlspecialchars($currentUser['username']); ?>!
            </h1>
            <p class="text-muted mb-0">
                <?php if ($user->isSuperAdmin($currentUser['id'])): ?>
                    <i class="bi bi-award me-1 text-warning"></i>
                    Super Administrator Dashboard - Manage all system resources
                <?php else: ?>
                    <i class="bi bi-person-badge me-1 text-info"></i>
                    DNS Management Dashboard - Manage your domains and records
                <?php endif; ?>
            </p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="refresh-btn">
                    <i class="bi bi-arrow-clockwise me-1"></i>
                    Refresh
                </button>
                <button type="button" class="btn btn-sm btn-outline-info" id="theme-toggle-btn">
                    <i class="bi bi-palette me-1"></i>
                    Theme
                </button>
            </div>
        </div>
    </div>

    <!-- Main Navigation Cards -->
    <div class="row mb-4 justify-content-center">
        <!-- DNS Management Section -->
        <div class="col-xl-8 col-lg-10">
            <h3 class="h4 mb-4 text-center">
                <i class="bi bi-diagram-3 me-2 text-primary"></i>
                DNS Management
            </h3>
            <div class="row justify-content-center">
                <!-- Domain Management Card -->
                <div class="col-md-6 mb-4">
                    <div class="card h-100 shadow-sm border-0 hover-lift">
                        <div class="card-header bg-primary text-white">
                            <div class="d-flex align-items-center justify-content-center">
                                <i class="bi bi-globe2 fs-4 me-2"></i>
                                <h5 class="card-title mb-0">Domains</h5>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="card-text text-muted text-center">Manage your DNS domains and domain settings.</p>
                            <div class="row g-2">
                                <div class="col-12">
                                    <a href="?page=domains" class="btn btn-outline-primary btn-sm w-100">
                                        <i class="bi bi-list-ul me-1"></i> View All Domains
                                    </a>
                                </div>
                                <div class="col-6">
                                    <a href="?page=domain_add" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-plus-circle me-1"></i> Add Domain
                                    </a>
                                </div>
                                <div class="col-6">
                                    <a href="?page=domain_import" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-upload me-1"></i> Import
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-light text-center">
                            <small class="text-muted">
                                <i class="bi bi-bar-chart me-1"></i>
                                <?php echo number_format($totalDomains); ?> domains managed
                            </small>
                        </div>
                    </div>
                </div>

                <!-- DNS Records Card -->
                <div class="col-md-6 mb-4">
                    <div class="card h-100 shadow-sm border-0 hover-lift">
                        <div class="card-header bg-primary text-white">
                            <div class="d-flex align-items-center justify-content-center">
                                <i class="bi bi-card-list fs-4 me-2"></i>
                                <h5 class="card-title mb-0">DNS Records</h5>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="card-text text-muted text-center">Manage A, AAAA, CNAME, MX, TXT and other DNS records.</p>
                            <div class="row g-2">
                                <div class="col-12">
                                    <a href="?page=records" class="btn btn-outline-primary btn-sm w-100">
                                        <i class="bi bi-search me-1"></i> Browse Records
                                    </a>
                                </div>
                                <div class="col-6">
                                    <a href="?page=record_add" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-plus-circle me-1"></i> Add Record
                                    </a>
                                </div>
                                <div class="col-6">
                                    <a href="?page=record_bulk" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-layers me-1"></i> Bulk Add
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-light text-center">
                            <small class="text-muted">
                                <i class="bi bi-database me-1"></i>
                                <?php echo number_format($totalRecords); ?> records total
                            </small>
                        </div>
                    </div>
                </div>

                <!-- DNSSEC Card -->
                <div class="col-md-6 mb-4">
                    <div class="card h-100 shadow-sm border-0 hover-lift">
                        <div class="card-header bg-primary text-white">
                            <div class="d-flex align-items-center justify-content-center">
                                <i class="bi bi-shield-lock fs-4 me-2"></i>
                                <h5 class="card-title mb-0">DNSSEC</h5>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="card-text text-muted text-center">Secure your domains with DNSSEC key management.</p>
                            <div class="row g-2">
                                <div class="col-12">
                                    <a href="?page=dnssec_manage" class="btn btn-outline-primary btn-sm w-100">
                                        <i class="bi bi-key me-1"></i> Manage Keys
                                    </a>
                                </div>
                                <div class="col-6">
                                    <a href="?page=dnssec_keys" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-gear me-1"></i> Key Setup
                                    </a>
                                </div>
                                <div class="col-6">
                                    <span class="btn btn-secondary btn-sm w-100 disabled">
                                        <i class="bi bi-clock me-1"></i> Phase 4
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-light text-center">
                            <small class="text-muted">
                                <i class="bi bi-shield-check me-1"></i>
                                <?php echo number_format($dnssecDomains); ?> domains secured
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Dynamic DNS Card -->
                <div class="col-md-6 mb-4">
                    <div class="card h-100 shadow-sm border-0 hover-lift">
                        <div class="card-header bg-primary text-white">
                            <div class="d-flex align-items-center justify-content-center">
                                <i class="bi bi-arrow-repeat fs-4 me-2"></i>
                                <h5 class="card-title mb-0">Dynamic DNS</h5>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="card-text text-muted text-center">API tokens for dynamic IP updates (ddclient compatible).</p>
                            <div class="row g-2">
                                <div class="col-12">
                                    <a href="?page=dynamic_dns_tokens" class="btn btn-outline-primary btn-sm w-100">
                                        <i class="bi bi-key me-1"></i> Manage Tokens
                                    </a>
                                </div>
                                <div class="col-6">
                                    <a href="?page=dynamic_dns_logs" class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-clock-history me-1"></i> View Logs
                                    </a>
                                </div>
                                <div class="col-6">
                                    <span class="btn btn-secondary btn-sm w-100 disabled">
                                        <i class="bi bi-clock me-1"></i> Phase 3
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-light text-center">
                            <small class="text-muted">
                                <i class="bi bi-robot me-1"></i>
                                ddclient compatible API
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Initialize dashboard
    document.addEventListener('DOMContentLoaded', function() {
        // Add phase announcement
        setTimeout(() => {
            showPhaseAnnouncement();
        }, 1000);
    });
    
    // Phase announcement
    function showPhaseAnnouncement() {
        const banner = document.querySelector('.phase-banner');
        if (banner) {
            banner.style.opacity = '0.8';
            setTimeout(() => {
                banner.innerHTML = `
                    <div class="d-flex align-items-center">
                        <div class="status-indicator me-3"></div>
                        <div>
                            <h6 class="mb-0 text-white">
                                <i class="bi bi-rocket me-2"></i>Phase 1 Complete - Ready for DNS Management!
                            </h6>
                            <small class="text-light">Authentication ✓ | Dashboard ✓ | Sessions ✓ | Next: Domain & Record Management</small>
                        </div>
                    </div>
                `;
                banner.style.opacity = '1';
            }, 300);
        }
    }
    
    // Quick action handlers
    function quickAction(action) {
        const actions = {
            'add-domain': 'Domain management will be available in Phase 2!\n\nFeatures coming:\n• Domain creation & validation\n• Bulk domain import\n• DNS zone templates\n• DNSSEC management',
            'view-zones': 'DNS zone management will be available in Phase 2!\n\nFeatures coming:\n• Zone file viewer & editor\n• Record management interface\n• Zone validation tools\n• Import/Export capabilities',
            'dns-query': 'DNS query tools will be available in Phase 2!\n\nFeatures coming:\n• Real-time DNS lookups\n• Query history & analytics\n• Performance metrics\n• Debugging tools',
            'user-management': window.location.href.includes('console') ? 
                'Enhanced user management coming in Phase 2!\n\nNew features:\n• Advanced role management\n• Tenant isolation controls\n• Activity monitoring\n• API key management' :
                'Redirecting to user management...'
        };
        
        if (actions[action]) {
            if (action === 'user-management' && !actions[action].includes('Enhanced')) {
                // This would redirect to user management - placeholder for now
                alert('User management interface will be enhanced in Phase 2!');
            } else {
                alert(actions[action]);
            }
        }
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
