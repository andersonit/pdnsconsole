<?php
/**
 * PDNS Console - System Administration Dashboard
 * For Super Administrators Only
 */

// Get required classes
$user = new User();
$domain = new Domain();
$settings = new Settings();

// Check if user is super admin
if (!$user->isSuperAdmin($currentUser['id'])) {
    header('Location: /?page=dashboard');
    exit;
}

$pageTitle = 'System Administration';
$branding = $settings->getBranding();

// Get system statistics
$db = Database::getInstance();

// Total system counts
$totalDomains = $db->fetch("SELECT COUNT(*) as count FROM domains")['count'] ?? 0;
$totalRecords = $db->fetch("SELECT COUNT(*) as count FROM records WHERE type != 'SOA'")['count'] ?? 0;
$totalUsers = $db->fetch("SELECT COUNT(*) as count FROM admin_users WHERE is_active = 1")['count'] ?? 0;
$totalTenants = $db->fetch("SELECT COUNT(*) as count FROM tenants WHERE is_active = 1")['count'] ?? 0;

// License information
$currentLicense = $db->fetch("SELECT * FROM licenses WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1");
$licenseStatus = 'Free License';
$domainLimit = 5;
$usagePercentage = min(($totalDomains / 5) * 100, 100);

if ($currentLicense) {
    if ($currentLicense['license_type'] === 'commercial') {
        $licenseStatus = 'Commercial License';
        $domainLimit = 'Unlimited';
        $usagePercentage = 0;
    }
}

// Recent activity (last 10 actions)
$recentActivity = $db->fetchAll(
    "SELECT al.*, au.username 
     FROM audit_log al 
     LEFT JOIN admin_users au ON al.user_id = au.id 
     ORDER BY al.created_at DESC 
     LIMIT 10"
);

// System health checks
$systemHealth = [
    'database' => 'healthy',
    'sessions' => $db->fetch("SELECT COUNT(*) as count FROM user_sessions WHERE last_accessed > DATE_SUB(NOW(), INTERVAL 1 HOUR)")['count'] ?? 0,
    'expired_sessions' => $db->fetch("SELECT COUNT(*) as count FROM user_sessions WHERE last_accessed <= DATE_SUB(NOW(), INTERVAL 1 HOUR)")['count'] ?? 0
];

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid mt-4">
    <!-- System Administration Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="bi bi-gear-fill me-2"></i>
                        System Administration
                    </h1>
                    <p class="text-muted mb-0">Manage the entire PDNS Console system</p>
                </div>
                <div>
                    <a href="/?page=dashboard" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>
                        Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- System Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded-3 p-3" style="background-color: rgba(13, 110, 253, 0.1) !important;">
                                <i class="bi bi-hdd-stack text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-1">Total Domains</h6>
                            <h3 class="mb-0"><?php echo number_format($totalDomains); ?></h3>
                            <small class="text-muted">Across all tenants</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-success bg-opacity-10 rounded-3 p-3">
                                <i class="bi bi-list text-success fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-1">DNS Records</h6>
                            <h3 class="mb-0"><?php echo number_format($totalRecords); ?></h3>
                            <small class="text-muted">Active records</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-info bg-opacity-10 rounded-3 p-3">
                                <i class="bi bi-person text-info fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-1">Active Users</h6>
                            <h3 class="mb-0"><?php echo number_format($totalUsers); ?></h3>
                            <small class="text-muted">System administrators</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-warning bg-opacity-10 rounded-3 p-3">
                                <i class="bi bi-house text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-1">Tenants</h6>
                            <h3 class="mb-0"><?php echo number_format($totalTenants); ?></h3>
                            <small class="text-muted">Organizations</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- License Status Card -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="card-title mb-2">
                                <i class="bi bi-shield-check me-2"></i>
                                License Status
                            </h6>
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge bg-<?php echo $currentLicense && $currentLicense['license_type'] === 'commercial' ? 'success' : 'warning'; ?> me-2">
                                    <?php echo htmlspecialchars($licenseStatus); ?>
                                </span>
                                <?php if ($domainLimit !== 'Unlimited'): ?>
                                    <small class="text-muted">
                                        <?php echo $totalDomains; ?> / <?php echo $domainLimit; ?> domains used
                                    </small>
                                <?php else: ?>
                                    <small class="text-success">Unlimited domains</small>
                                <?php endif; ?>
                            </div>
                            <?php if ($domainLimit !== 'Unlimited'): ?>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar <?php echo $usagePercentage >= 80 ? 'bg-warning' : 'bg-success'; ?>" 
                                         style="width: <?php echo $usagePercentage; ?>%"></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <a href="?page=admin_licenses" class="btn btn-outline-primary">
                                <i class="bi bi-gear me-1"></i>
                                Manage License
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Administration Sections -->
    <div class="row">
        <!-- User & Tenant Management -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-people-fill me-2"></i>
                        User & Tenant Management
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <a href="?page=admin_users" class="btn btn-outline-primary w-100 py-3">
                                <i class="bi bi-person-gear d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">Users</div>
                                <small class="text-muted">Manage admin users</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="?page=admin_tenants" class="btn btn-outline-primary w-100 py-3">
                                <i class="bi bi-building d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">Tenants</div>
                                <small class="text-muted">Manage organizations</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- DNS & System Configuration -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-gear-fill me-2"></i>
                        DNS & System Configuration
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <a href="?page=admin_settings" class="btn btn-outline-success w-100 py-3">
                                <i class="bi bi-dns d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">DNS Settings</div>
                                <small class="text-muted">Nameservers & SOA</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="?page=admin_branding" class="btn btn-outline-success w-100 py-3">
                                <i class="bi bi-palette d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">Branding</div>
                                <small class="text-muted">Themes & logos</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="?page=admin_record_types" class="btn btn-outline-success w-100 py-3">
                                <i class="bi bi-list-columns d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">Record Types</div>
                                <small class="text-muted">Custom DNS types</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="?page=admin_system" class="btn btn-outline-success w-100 py-3">
                                <i class="bi bi-cpu d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">System Info</div>
                                <small class="text-muted">Health & maintenance</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monitoring & Audit Tools -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-activity me-2"></i>
                        Monitoring & Audit
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <a href="?page=admin_audit" class="btn btn-outline-info w-100 py-3">
                                <i class="bi bi-journal-text d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">Audit Logs</div>
                                <small class="text-muted">System activity</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <div class="btn btn-outline-secondary w-100 py-3 disabled">
                                <i class="bi bi-graph-up d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">Analytics</div>
                                <small class="text-muted">Coming soon</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-clock-history me-2"></i>
                        Recent System Activity
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($recentActivity)): ?>
                        <div class="activity-feed">
                            <?php foreach (array_slice($recentActivity, 0, 5) as $activity): ?>
                                <div class="d-flex mb-3">
                                    <div class="flex-shrink-0">
                                        <div class="bg-light rounded-circle p-2">
                                            <i class="bi bi-dot fs-6"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <div class="fw-semibold mb-1">
                                            <?php echo htmlspecialchars($activity['action']); ?>
                                        </div>
                                        <div class="text-muted small">
                                            <?php echo htmlspecialchars($activity['username'] ?? 'System'); ?> • 
                                            <?php echo date('M j, g:i A', strtotime($activity['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center">
                            <a href="?page=admin_audit" class="btn btn-sm btn-outline-primary">
                                View All Activity
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-clock-history fs-1 d-block mb-2 opacity-50"></i>
                            <p class="mb-0">No recent activity</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- System Health Status -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-heart-pulse me-2"></i>
                        System Health
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-success bg-opacity-10 rounded-3 p-2 me-3">
                                    <i class="bi bi-database-check text-success"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">Database</div>
                                    <small class="text-success">Healthy</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-info bg-opacity-10 rounded-3 p-2 me-3">
                                    <i class="bi bi-person-check text-info"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">Active Sessions</div>
                                    <small class="text-muted"><?php echo $systemHealth['sessions']; ?> users online</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-warning bg-opacity-10 rounded-3 p-2 me-3">
                                    <i class="bi bi-clock text-warning"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">Expired Sessions</div>
                                    <small class="text-muted"><?php echo $systemHealth['expired_sessions']; ?> pending cleanup</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
