<?php

/**
 * PDNS Console - System Administration Dashboard
 */

// Ensure only super admins can access this page
if (!$user->isSuperAdmin($currentUser['id'])) {
    header('Location: ?page=zone_manage');
    exit;
}

// Get dashboard statistics
$db = Database::getInstance();
$auditLog = new AuditLog();

// Count zones
$zonesCount = $db->fetch("SELECT COUNT(*) as count FROM domains");
$totalZones = $zonesCount['count'] ?? 0;

// Count users
$usersCount = $db->fetch("SELECT COUNT(*) as count FROM admin_users WHERE is_active = 1");
$totalUsers = $usersCount['count'] ?? 0;

// Count tenants
$tenantsCount = $db->fetch("SELECT COUNT(*) as count FROM tenants WHERE is_active = 1");
$totalTenants = $tenantsCount['count'] ?? 0;

// Count records
$recordsCount = $db->fetch("SELECT COUNT(*) as count FROM records");
$totalRecords = $recordsCount['count'] ?? 0;

// Get DNSSEC enabled zones count
$dnssecCount = $db->fetch(
    "SELECT COUNT(DISTINCT d.id) as count FROM domains d 
     JOIN domainmetadata dm ON d.id = dm.domain_id 
     WHERE dm.kind = 'NSEC3PARAM' OR dm.kind = 'PRESIGNED'"
);
$dnssecZones = $dnssecCount['count'] ?? 0;

// Get recent system activity (last 10 entries) using AuditLog helper
$recentActivity = $auditLog->getRecentActivity(10);

// Get system health info
$systemHealth = [
    'php_version' => PHP_VERSION,
    'memory_usage' => memory_get_usage(true),
    'memory_limit' => ini_get('memory_limit'),
    'uptime' => file_exists('/proc/uptime') ? file_get_contents('/proc/uptime') : null
];

$pageTitle = 'System Administration';
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid">
    <!-- Welcome Section -->
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-gear me-2 text-primary"></i>
                System Administration
            </h1>
            <p class="text-muted mb-0">
                <i class="bi bi-award me-1 text-warning"></i>
                Super Administrator Dashboard - Manage all system resources
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

    <!-- Statistics Overview -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded-3 p-3">
                                <i class="bi bi-globe text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-1">DNS Zones</h6>
                            <h3 class="mb-0"><?php echo number_format($totalZones); ?></h3>
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
                                <i class="bi bi-card-list text-success fs-4"></i>
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
                            <div class="bg-warning bg-opacity-10 rounded-3 p-3">
                                <i class="bi bi-people text-warning fs-4"></i>
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
                            <div class="bg-info bg-opacity-10 rounded-3 p-3">
                                <i class="bi bi-building text-info fs-4"></i>
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

    <!-- Administration Sections - 2x2 Layout -->
    <div class="row">
        <!-- User & Tenant Management -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-people-fill me-2"></i>
                        DNS & Tenant Management
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <a href="?page=zone_manage" class="btn btn-outline-info w-100 py-3 d-flex flex-column justify-content-center" style="min-height: 80px;">
                                <i class="bi bi-globe d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">DNS Management</div>
                                <small class="text-muted">Manage zones and records</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="?page=admin_users" class="btn btn-outline-info w-100 py-3 d-flex flex-column justify-content-center" style="min-height: 120px;">
                                <i class="bi bi-person-gear d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">Users</div>
                                <small class="text-muted">Manage all users</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="?page=admin_tenants" class="btn btn-outline-info w-100 py-3 d-flex flex-column justify-content-center" style="min-height: 120px;">
                                <i class="bi bi-building d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">Tenants</div>
                                <small class="text-muted">Manage organizations</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="?page=admin_record_types" class="btn btn-outline-info w-100 py-3 d-flex flex-column justify-content-center" style="min-height: 120px;">
                                <i class="bi bi-list-columns d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">Record Types</div>
                                <small class="text-muted">Manage Available Record types</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Configuration -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-gear-fill me-2"></i>
                        System Configuration
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <a href="?page=admin_settings" class="btn btn-outline-success w-100 py-3 d-flex flex-column justify-content-center" style="min-height: 120px;">
                                <i class="bi bi-gear d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">Settings</div>
                                <small class="text-muted">System settings</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="?page=admin_branding" class="btn btn-outline-success w-100 py-3 d-flex flex-column justify-content-center" style="min-height: 120px;">
                                <i class="bi bi-palette d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">Branding</div>
                                <small class="text-muted">Themes & logos</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="?page=admin_system" class="btn btn-outline-success w-100 py-3 d-flex flex-column justify-content-center" style="min-height: 120px;">
                                <i class="bi bi-cpu d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">System Info</div>
                                <small class="text-muted">Health & maintenance</small>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="?page=admin_audit" class="btn btn-outline-info w-100 py-3 d-flex flex-column justify-content-center" style="min-height: 120px;">
                                <i class="bi bi-journal-text d-block fs-4 mb-2"></i>
                                <div class="fw-semibold">Audit Logs</div>
                                <small class="text-muted">System activity</small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="col-lg-12 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-clock-history me-2"></i>
                        Recent System Activity
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($recentActivity)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Table</th>
                                        <th>Record ID</th>
                                        <th>IP</th>
                                        <th class="text-end">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentActivity as $entry): ?>
                                        <tr>
                                            <td>
                                                <small class="text-muted d-block">
                                                    <?php echo date('M j, Y H:i:s', strtotime($entry['created_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($entry['username']): ?>
                                                    <div class="fw-semibold small">
                                                        <?php echo htmlspecialchars($entry['username']); ?>
                                                    </div>
                                                    <?php if (!empty($entry['email'])): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars($entry['email']); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted small">System</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $auditLog->getActionBadgeClass($entry['action']); ?>">
                                                    <?php echo htmlspecialchars($auditLog->formatAction($entry['action'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($entry['table_name']): ?>
                                                    <code class="small"><?php echo htmlspecialchars($entry['table_name']); ?></code>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($entry['record_id']): ?>
                                                    <code class="small"><?php echo htmlspecialchars($entry['record_id']); ?></code>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo htmlspecialchars($entry['ip_address']); ?></small>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($entry['old_values'] || $entry['new_values'] || $entry['metadata']): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-info" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#dashboardDetailsModal" 
                                                            data-action="<?php echo htmlspecialchars($entry['action']); ?>"
                                                            data-old-values="<?php echo htmlspecialchars($entry['old_values'] ?? ''); ?>"
                                                            data-new-values="<?php echo htmlspecialchars($entry['new_values'] ?? ''); ?>"
                                                            data-metadata="<?php echo htmlspecialchars($entry['metadata'] ?? ''); ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-end mt-3">
                            <a href="?page=admin_audit" class="btn btn-sm btn-outline-primary">
                                View Full Audit Log
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
            <div class="card border-0 shadow-sm mb-4">
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
                                    <i class="bi bi-server text-info"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">PHP Version</div>
                                    <small class="text-muted"><?php echo $systemHealth['php_version']; ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-warning bg-opacity-10 rounded-3 p-2 me-3">
                                    <i class="bi bi-memory text-warning"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">Memory Usage</div>
                                    <small class="text-muted"><?php echo round($systemHealth['memory_usage'] / 1024 / 1024, 1); ?>MB</small>
                                </div>
                            </div>
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
        // Add hover effects
        const cards = document.querySelectorAll('.hover-lift');
        cards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
                this.style.transition = 'transform 0.2s ease-in-out';
            });
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });

        // Refresh functionality
        document.getElementById('refresh-btn')?.addEventListener('click', function() {
            window.location.reload();
        });
    });
</script>

<!-- Audit Details Modal (Dashboard) -->
<div class="modal fade" id="dashboardDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-text me-2"></i>
                    Audit Entry Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="dashboard-details-content"></div>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('dashboardDetailsModal')?.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    if (!button) return;
    const action = button.getAttribute('data-action');
    const oldValues = button.getAttribute('data-old-values');
    const newValues = button.getAttribute('data-new-values');
    const metadata = button.getAttribute('data-metadata');
    let html = '<h6>Action: <span class="badge bg-primary">' + (action || '') + '</span></h6>';
    const section = (title, data) => '<div class="mt-3"><h6>' + title + ':</h6><pre class="bg-light p-2 rounded small"><code>' + data + '</code></pre></div>';
    if (oldValues) html += section('Previous Values', oldValues);
    if (newValues) html += section('New Values', newValues);
    if (metadata) html += section('Metadata', metadata);
    document.getElementById('dashboard-details-content').innerHTML = html;
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>