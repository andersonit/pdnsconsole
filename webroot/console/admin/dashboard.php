<?php
// PDNS Console - Super Admin Dashboard

// Setup and authorization
$user = new User();
$settings = new Settings();
if (!$user->isSuperAdmin($currentUser['id'])) {
    header('Location: /?page=dashboard');
    exit;
}

$pageTitle = 'System Administration';
$branding = $settings->getBranding();
$db = Database::getInstance();

// Stats
$totalZones = (int)($db->fetch("SELECT COUNT(*) c FROM domains")['c'] ?? 0);
$totalRecords = (int)($db->fetch("SELECT COUNT(*) c FROM records")['c'] ?? 0);
$totalUsers = (int)($db->fetch("SELECT COUNT(*) c FROM admin_users")['c'] ?? 0);
$totalTenants = (int)($db->fetch("SELECT COUNT(*) c FROM tenants")['c'] ?? 0);

// Health
$systemHealth = [
    'php_version' => PHP_VERSION,
    'memory_usage' => memory_get_usage(true),
];

// Recent activity
$auditLog = new AuditLog();
$recentActivity = $auditLog->getRecentActivity(10);

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid">
    <style>
        .dash-tile {
            min-height: 120px;
            border: 1px solid rgba(0, 0, 0, .075);
            border-radius: .5rem;
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            text-align: center;
            transition: all .12s ease-in-out
        }

        .dash-tile .icon-wrap {
            width: 48px;
            height: 48px;
            border-radius: .75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: .5rem
        }

        .dash-tile .title {
            font-weight: 600
        }

        .dash-tile small {
            color: #6c757d
        }

        /* Remove link underlines on admin tiles in all states */
        a.dash-tile,
        a.dash-tile:hover,
        a.dash-tile:focus,
        a.dash-tile:active,
        a.dash-tile:visited {
            text-decoration: none !important;
        }
        .dash-tile .title,
        .dash-tile small {
            text-decoration: none !important;
        }

        .dash-tile:hover {
            transform: translateY(-2px);
            text-decoration: none;
            box-shadow: 0 .25rem .75rem rgba(0, 0, 0, .05)
        }

        /* Make hero tile support stretched-link */
        .hero-tile { position: relative; }
    </style>

    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-gear me-2 text-primary"></i>System Administration</h1>
            <p class="text-muted mb-0"><i class="bi bi-award me-1 text-warning"></i>Super Administrator Dashboard - Manage all system resources</p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="refresh-btn"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                <button type="button" class="btn btn-sm btn-outline-info" id="theme-toggle-btn"><i class="bi bi-palette me-1"></i>Theme</button>
            </div>
        </div>
    </div>
    <section class="dns-hero mb-4">
        <div class="hero-inner d-flex justify-content-center">
            <div class="dash-tile dash-hero hero-tile shadow-sm">
                <a href="?page=zone_manage" class="stretched-link" aria-label="Go to DNS Management"></a>
                <div class="icon-wrap bg-primary bg-opacity-10"><i class="bi bi-diagram-3 text-primary fs-4"></i></div>
                <div class="title fs-5 mb-1">Manage DNS</div>
                <small class="mb-2">Zones, Records, DNSSEC, and Dynamic DNS</small>
                <button type="button" class="btn btn-primary mt-2" onclick="window.location.href='/console/zones/manage.php'">
                    <i class="bi bi-globe me-1"></i>Go to DNS Management
                </button>
            </div>
        </div>
    </section>
    <div class="row mb-4">
        <div class="col-xl-2 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded-3 p-3"><i class="bi bi-globe text-primary fs-4"></i></div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-1">DNS Zones</h6>
                            <h3 class="mb-0"><?php echo number_format($totalZones); ?></h3><small class="text-muted">Across all tenants</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-success bg-opacity-10 rounded-3 p-3"><i class="bi bi-card-list text-success fs-4"></i></div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-1">DNS Records</h6>
                            <h3 class="mb-0"><?php echo number_format($totalRecords); ?></h3><small class="text-muted">Active records</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-warning bg-opacity-10 rounded-3 p-3"><i class="bi bi-people text-warning fs-4"></i></div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-1">Active Users</h6>
                            <h3 class="mb-0"><?php echo number_format($totalUsers); ?></h3><small class="text-muted">System administrators</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-info bg-opacity-10 rounded-3 p-3"><i class="bi bi-building text-info fs-4"></i></div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-1">Tenants</h6>
                            <h3 class="mb-0"><?php echo number_format($totalTenants); ?></h3><small class="text-muted">Organizations</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-secondary bg-opacity-10 rounded-3 p-3"><i class="bi bi-shield-lock text-secondary fs-4"></i></div>
                        </div>
                        <div class="flex-grow-1 ms-3"><?php $defaultStatus = ['license_type' => 'free', 'max_domains' => 5, 'unlimited' => false, 'integrity' => true];
                                                        $licenseStatus = class_exists('LicenseManager') ? (LicenseManager::getStatus() ?: $defaultStatus) : $defaultStatus;
                                                        $countRow = $db->fetch("SELECT COUNT(*) c FROM domains");
                                                        $used = (int)($countRow['c'] ?? 0);
                                                        $limit = !empty($licenseStatus['unlimited']) ? null : (isset($licenseStatus['max_domains']) ? (int)$licenseStatus['max_domains'] : 5);
                                                        $percent = ($limit && $limit > 0) ? min(100, round(($used / $limit) * 100)) : 0;
                                                        $label = 'Free Tier';
                                                        if (!empty($licenseStatus['license_type']) && $licenseStatus['license_type'] === 'commercial') {
                                                            $label = !empty($licenseStatus['unlimited']) ? 'Commercial (Unlimited)' : 'Commercial (' . ($limit ?? '∞') . ')';
                                                        } ?><h6 class="card-title mb-1">License: <?php echo htmlspecialchars((string)$label); ?><?php if (isset($licenseStatus['integrity']) && !$licenseStatus['integrity']) {
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                echo ' <span class="badge bg-danger ms-1" title="Public key integrity check failed">INT</span>';
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            } ?></h6><?php if ($limit): ?><div class="progress mb-1" style="height:6px;">
                                    <div class="progress-bar <?php echo $percent >= 100 ? 'bg-danger' : ($percent >= 80 ? 'bg-warning' : 'bg-info'); ?>" role="progressbar" style="width: <?php echo $percent; ?>%" aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div><small class="text-muted"><?php echo $used; ?> / <?php echo $limit; ?> domains (<?php echo $percent; ?>%)</small><?php else: ?><small class="text-success">Unlimited domains</small><?php endif; ?><?php if (!empty($licenseStatus['license_type']) && $licenseStatus['license_type'] === 'free'): ?><div class="mt-2"><a class="btn btn-sm btn-outline-info" href="?page=admin_license"><i class="bi bi-arrow-up me-1"></i>Upgrade</a></div><?php endif; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h4 class="card-title mb-0"><i class="bi bi-gear-fill me-2"></i>Administration</h4><small class="text-muted">System configuration and maintenance</small>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6 col-md-4 col-xl-3"><a href="?page=admin_settings" class="dash-tile text-body">
                                <div class="icon-wrap bg-secondary bg-opacity-10"><i class="bi bi-gear text-secondary fs-5"></i></div>
                                <div class="title">Settings</div><small>System settings</small>
                            </a></div>
                        <div class="col-6 col-md-4 col-xl-3"><a href="?page=admin_dns_settings" class="dash-tile text-body">
                                <div class="icon-wrap bg-primary bg-opacity-10"><i class="bi bi-globe text-primary fs-5"></i></div>
                                <div class="title">DNS Settings</div><small>SOA & nameservers</small>
                            </a></div>
                        <div class="col-6 col-md-4 col-xl-3"><a href="?page=admin_branding" class="dash-tile text-body">
                                <div class="icon-wrap bg-info bg-opacity-10"><i class="bi bi-palette text-info fs-5"></i></div>
                                <div class="title">Branding</div><small>Themes & logos</small>
                            </a></div>
                        <div class="col-6 col-md-4 col-xl-3"><a href="?page=admin_system" class="dash-tile text-body">
                                <div class="icon-wrap bg-dark bg-opacity-10"><i class="bi bi-cpu text-dark fs-5"></i></div>
                                <div class="title">System Info</div><small>Health & maintenance</small>
                            </a></div>
                        <div class="col-6 col-md-4 col-xl-3"><a href="?page=admin_audit" class="dash-tile text-body">
                                <div class="icon-wrap bg-warning bg-opacity-10"><i class="bi bi-journal-text text-warning fs-5"></i></div>
                                <div class="title">Audit Logs</div><small>System activity</small>
                            </a></div>
                        <div class="col-6 col-md-4 col-xl-3"><a href="?page=admin_license" class="dash-tile text-body">
                                <div class="icon-wrap bg-danger bg-opacity-10"><i class="bi bi-shield-lock text-danger fs-5"></i></div>
                                <div class="title">License</div><small>Key & limits</small>
                            </a></div>
                        <div class="col-6 col-md-4 col-xl-3"><a href="?page=admin_users" class="dash-tile text-body">
                                <div class="icon-wrap bg-success bg-opacity-10"><i class="bi bi-people text-success fs-5"></i></div>
                                <div class="title">Users</div><small>Manage all users</small>
                            </a></div>
                        <div class="col-6 col-md-4 col-xl-3"><a href="?page=admin_tenants" class="dash-tile text-body">
                                <div class="icon-wrap bg-secondary bg-opacity-10"><i class="bi bi-building text-secondary fs-5"></i></div>
                                <div class="title">Tenants</div><small>Organizations</small>
                            </a></div>
                        <div class="col-6 col-md-4 col-xl-3"><a href="?page=admin_record_types" class="dash-tile text-body">
                                <div class="icon-wrap bg-primary bg-opacity-10"><i class="bi bi-list-columns text-primary fs-5"></i></div>
                                <div class="title">Record Types</div><small>Available types</small>
                            </a></div>
                        <div class="col-6 col-md-4 col-xl-3"><a href="?page=admin_email_settings" class="dash-tile text-body">
                                <div class="icon-wrap bg-info bg-opacity-10"><i class="bi bi-envelope text-info fs-5"></i></div>
                                <div class="title">Email Settings</div><small>SMTP config</small>
                            </a></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h6 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Recent System Activity</h6>
                </div>
                <div class="card-body"><?php if (!empty($recentActivity)): ?><div class="table-responsive">
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
                                <tbody><?php foreach ($recentActivity as $entry): ?><tr>
                                            <td><small class="text-muted d-block"><?php echo date('M j, Y H:i:s', strtotime($entry['created_at'])); ?></small></td>
                                            <td><?php if ($entry['username']): ?><div class="fw-semibold small"><?php echo htmlspecialchars($entry['username']); ?></div><?php if (!empty($entry['email'])): ?><small class="text-muted"><?php echo htmlspecialchars($entry['email']); ?></small><?php endif; ?><?php else: ?><span class="text-muted small">System</span><?php endif; ?></td>
                                            <td><span class="badge <?php echo $auditLog->getActionBadgeClass($entry['action']); ?>"><?php echo htmlspecialchars($auditLog->formatAction($entry['action'])); ?></span></td>
                                            <td><?php if ($entry['table_name']): ?><code class="small"><?php echo htmlspecialchars($entry['table_name']); ?></code><?php else: ?><span class="text-muted small">-</span><?php endif; ?></td>
                                            <?php 
                                                $dashDisplayRecordId = $entry['record_id'] ?? null;
                                                $dashMeta = [];
                                                if (!$dashDisplayRecordId && !empty($entry['metadata'])) {
                                                    $meta = json_decode($entry['metadata'], true);
                                                    if (is_array($meta)) {
                                                        $dashMeta = $meta;
                                                        $dashDisplayRecordId = $meta['record_id'] ?? ($meta['domain_id'] ?? null);
                                                    }
                                                } else if (!empty($entry['metadata'])) {
                                                    $tmp = json_decode($entry['metadata'], true);
                                                    if (is_array($tmp)) { $dashMeta = $tmp; }
                                                }

                                                // Build target URL similar to audit table
                                                $dashTargetUrl = null;
                                                $dashTable = $entry['table_name'] ?? '';
                                                if (!empty($dashDisplayRecordId)) {
                                                    if ($dashTable === 'domains') {
                                                        $dashTargetUrl = '?page=zone_edit&id=' . urlencode((string)$dashDisplayRecordId);
                                                    } elseif ($dashTable === 'records' && !empty($entry['record_id'])) {
                                                        $dashDomainId = $dashMeta['domain_id'] ?? null;
                                                        if (!$dashDomainId && isset($db)) {
                                                            try {
                                                                $row = $db->fetch("SELECT domain_id FROM records WHERE id = ?", [$entry['record_id']]);
                                                                $dashDomainId = $row['domain_id'] ?? null;
                                                            } catch (Exception $e) { /* ignore */ }
                                                        }
                                                        if ($dashDomainId) {
                                                            $dashTargetUrl = '?page=records&domain_id=' . urlencode((string)$dashDomainId) . '&action=edit&id=' . urlencode((string)$entry['record_id']);
                                                        } elseif (!empty($dashMeta['domain_id'])) {
                                                            $dashTargetUrl = '?page=records&domain_id=' . urlencode((string)$dashMeta['domain_id']);
                                                        }
                                                    } elseif ($dashTable === 'cryptokeys' && !empty($dashMeta['domain_id'])) {
                                                        $dashTargetUrl = '?page=dnssec&domain_id=' . urlencode((string)$dashMeta['domain_id']);
                                                    } elseif (!empty($dashMeta['domain_id'])) {
                                                        $dashTargetUrl = '?page=records&domain_id=' . urlencode((string)$dashMeta['domain_id']);
                                                    }
                                                }
                                            ?>
                                            <td>
                                                <?php if (!empty($dashDisplayRecordId)): ?>
                                                    <?php if ($dashTargetUrl): ?>
                                                        <a href="<?php echo $dashTargetUrl; ?>" class="text-decoration-none">
                                                            <code class="small"><?php echo htmlspecialchars($dashDisplayRecordId); ?></code>
                                                        </a>
                                                    <?php else: ?>
                                                        <code class="small"><?php echo htmlspecialchars($dashDisplayRecordId); ?></code>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><small class="text-muted"><?php echo htmlspecialchars($entry['ip_address']); ?></small></td>
                                            <td class="text-end"><?php if ($entry['old_values'] || $entry['new_values'] || $entry['metadata']): ?><button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#dashboardDetailsModal" data-action="<?php echo htmlspecialchars($entry['action'], ENT_QUOTES); ?>" data-old-values="<?php echo htmlspecialchars($entry['old_values'] ?? '', ENT_QUOTES); ?>" data-new-values="<?php echo htmlspecialchars($entry['new_values'] ?? '', ENT_QUOTES); ?>" data-metadata="<?php echo htmlspecialchars($entry['metadata'] ?? '', ENT_QUOTES); ?>"><i class="bi bi-eye"></i></button><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                                        </tr><?php endforeach; ?></tbody>
                            </table>
                        </div>
                        <div class="text-center mt-3"><a href="?page=admin_audit" class="btn btn-sm btn-outline-primary">View Full Audit Log</a></div><?php else: ?><div class="text-center text-muted py-4"><i class="bi bi-clock-history fs-1 d-block mb-2 opacity-50"></i>
                            <p class="mb-0">No recent activity</p>
                        </div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0"><i class="bi bi-heart-pulse me-2"></i>System Health</h6>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-success bg-opacity-10 rounded-3 p-2 me-3"><i class="bi bi-database-check text-success"></i></div>
                                <div>
                                    <div class="fw-semibold">Database</div><small class="text-success">Healthy</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-info bg-opacity-10 rounded-3 p-2 me-3"><i class="bi bi-server text-info"></i></div>
                                <div>
                                    <div class="fw-semibold">PHP Version</div><small class="text-muted"><?php echo $systemHealth['php_version']; ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-warning bg-opacity-10 rounded-3 p-2 me-3"><i class="bi bi-memory text-warning"></i></div>
                                <div>
                                    <div class="fw-semibold">Memory Usage</div><small class="text-muted"><?php echo round($systemHealth['memory_usage'] / 1024 / 1024, 1); ?>MB</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Refresh button
            const refreshBtn = document.getElementById('refresh-btn');
            if (refreshBtn) refreshBtn.addEventListener('click', () => window.location.reload());

            // Bind modal handler after DOM is ready so the element exists
            const modalEl = document.getElementById('dashboardDetailsModal');
            if (!modalEl) return;
            modalEl.addEventListener('show.bs.modal', function(event) {
                const trigger = event.relatedTarget;
                const button = trigger && trigger.closest ? trigger.closest('[data-bs-toggle="modal"]') : trigger;
                if (!button) return;
                const action = button.getAttribute('data-action');
                const oldValues = button.getAttribute('data-old-values');
                const newValues = button.getAttribute('data-new-values');
                const metadata = button.getAttribute('data-metadata');
                let html = '<h6>Action: <span class="badge bg-primary">' + (action || '') + '</span></h6>';
                const pretty = (data) => {
                    if (!data) return '';
                    try {
                        return JSON.stringify(JSON.parse(data), null, 2);
                    } catch {
                        return data;
                    }
                };
                const section = (title, data) => '<div class="mt-3"><h6>' + title + ':</h6><pre class="bg-light p-2 rounded small"><code>' + pretty(data) + '</code></pre></div>';
                if (oldValues) html += section('Previous Values', oldValues);
                if (newValues) html += section('New Values', newValues);
                if (metadata) html += section('Metadata', metadata);
                const el = document.getElementById('dashboard-details-content');
                if (el) el.innerHTML = html;
            });
        });
    </script>

    <!-- Audit Details Modal (Dashboard) -->
    <div class="modal fade" id="dashboardDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-file-text me-2"></i>Audit Entry Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="dashboard-details-content"></div>
                </div>
            </div>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>