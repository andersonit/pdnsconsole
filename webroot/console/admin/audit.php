<?php
/**
 * PDNS Console - System Audit Log
 */

// Get classes (currentUser is already set by index.php)
$user = new User();
$auditLog = new AuditLog();

// Check if user is super admin
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

// Only allow super admins to view audit logs
if (!$isSuperAdmin) {
    header('Location: ?page=dashboard');
    exit;
}

// Handle filters
$filters = [];
$filters['user_id'] = $_GET['user_id'] ?? '';
$filters['action'] = $_GET['action'] ?? '';
$filters['table_name'] = $_GET['table_name'] ?? '';
$filters['date_from'] = $_GET['date_from'] ?? '';
$filters['date_to'] = $_GET['date_to'] ?? '';
$filters['ip_address'] = $_GET['ip_address'] ?? '';
$filters['search'] = $_GET['search'] ?? '';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Get audit log data
$auditEntries = [];
$totalCount = 0;
$auditStats = [];

try {
    // Clean empty filters
    $cleanFilters = array_filter($filters, function($value) {
        return $value !== '';
    });
    
    $auditEntries = $auditLog->getAuditLog($cleanFilters, $limit, $offset);
    $totalCount = $auditLog->getAuditLogCount($cleanFilters);
    $auditStats = $auditLog->getAuditStats(null, 30); // Last 30 days stats
} catch (Exception $e) {
    $error = 'Error loading audit log: ' . $e->getMessage();
}

// Calculate pagination
$totalPages = ceil($totalCount / $limit);

// Get all users for filter dropdown
$allUsers = [];
try {
    $db = Database::getInstance();
    $allUsers = $db->fetchAll("SELECT id, username, email FROM admin_users ORDER BY username");
} catch (Exception $e) {
    // Ignore errors in user loading
}

// Get unique actions for filter
$allActions = [
    'DOMAIN_CREATE', 'DOMAIN_UPDATE', 'DOMAIN_DELETE',
    'RECORD_CREATE', 'RECORD_UPDATE', 'RECORD_DELETE', 'RECORD_BULK_CREATE',
    'USER_CREATE', 'USER_UPDATE', 'USER_DELETE', 'USER_LOGIN', 'USER_LOGOUT', 'USER_LOGIN_FAILED',
    'USER_PASSWORD_CHANGE', 'USER_MFA_ENABLE', 'USER_MFA_DISABLE', 'USER_MFA_RESET',
    'TENANT_CREATE', 'TENANT_UPDATE', 'TENANT_DELETE',
    'USER_TENANT_ASSIGN', 'USER_TENANT_REMOVE',
    'SETTING_UPDATE', 'THEME_CHANGE',
    'DNSSEC_ENABLE', 'DNSSEC_DISABLE', 'DNSSEC_KEY_GENERATE',
    'CUSTOM_TYPE_CREATE', 'CUSTOM_TYPE_UPDATE', 'CUSTOM_TYPE_DELETE'
];

$allTables = ['domains', 'records', 'admin_users', 'tenants', 'user_tenants', 'global_settings', 'cryptokeys', 'custom_record_types'];

// Page title
$pageTitle = 'System Audit Log';

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="mb-4">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="d-flex align-items-center">
                <a href="?page=admin_dashboard" class="btn btn-outline-secondary btn-sm me-3">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <h2 class="h4 mb-0">
                    <i class="bi bi-file-text me-2 text-info"></i>
                    System Audit Log
                </h2>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#filtersModal">
                    <i class="bi bi-funnel me-1"></i>
                    Filters
                </button>
                <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#statsModal">
                    <i class="bi bi-graph-up me-1"></i>
                    Statistics
                </button>
            </div>
        </div>
        <p class="text-muted mb-0">
            Comprehensive system activity log for compliance and security monitoring
        </p>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Active Filters Display -->
    <?php if (!empty(array_filter($filters))): ?>
        <div class="card mb-4">
            <div class="card-body py-2">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <small class="text-muted me-2">Active Filters:</small>
                    <?php foreach ($filters as $key => $value): ?>
                        <?php if (!empty($value)): ?>
                            <span class="badge bg-secondary">
                                <?php 
                                echo ucfirst(str_replace('_', ' ', $key)) . ': ' . htmlspecialchars($value);
                                ?>
                                <a href="<?php echo '?' . http_build_query(array_merge($_GET, [$key => ''])); ?>" class="text-white ms-1">
                                    <i class="bi bi-x-circle"></i>
                                </a>
                            </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <a href="?page=admin_audit" class="btn btn-sm btn-outline-secondary ms-2">
                        <i class="bi bi-x-circle me-1"></i>
                        Clear All
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Quick Search -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="admin_audit">
                <?php foreach ($filters as $key => $value): ?>
                    <?php if ($key !== 'search' && !empty($value)): ?>
                        <input type="hidden" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($value); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="col-md-10">
                    <input type="text" class="form-control" name="search" 
                           placeholder="Search by action, table, username, or email..." 
                           value="<?php echo htmlspecialchars($filters['search']); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search me-1"></i>
                        Search
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Audit Log Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">
                <i class="bi bi-list-ul me-2"></i>
                Audit Entries
                <?php if ($totalCount > 0): ?>
                    <span class="badge bg-info ms-2"><?php echo number_format($totalCount); ?></span>
                <?php endif; ?>
            </h5>
        </div>
        
        <?php if (!empty($auditEntries)): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Table</th>
                            <th>Record ID</th>
                            <th>IP Address</th>
                            <th class="text-end">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auditEntries as $entry): ?>
                            <tr>
                                <td>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y H:i:s', strtotime($entry['created_at'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($entry['username']): ?>
                                        <div>
                                            <strong><?php echo htmlspecialchars($entry['username']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($entry['email']); ?></small>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">System</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo $auditLog->getActionBadgeClass($entry['action']); ?>">
                                        <?php echo $auditLog->formatAction($entry['action']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($entry['table_name']): ?>
                                        <code class="small"><?php echo htmlspecialchars($entry['table_name']); ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($entry['record_id']): ?>
                                        <code class="small"><?php echo htmlspecialchars($entry['record_id']); ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?php echo htmlspecialchars($entry['ip_address']); ?></small>
                                </td>
                                <td class="text-end">
                                    <?php if ($entry['old_values'] || $entry['new_values'] || $entry['metadata']): ?>
                                        <button type="button" class="btn btn-sm btn-outline-info" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#detailsModal" 
                                                data-entry-id="<?php echo $entry['id']; ?>"
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

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted">
                            Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $limit, $totalCount)); ?> 
                            of <?php echo number_format($totalCount); ?> entries
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo '?' . http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                
                                for ($i = $startPage; $i <= $endPage; $i++):
                                ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo '?' . http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo '?' . http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="card-body text-center py-5">
                <i class="bi bi-file-text display-1 text-muted mb-3"></i>
                <h5>No Audit Entries Found</h5>
                <p class="text-muted">
                    <?php if (!empty(array_filter($filters))): ?>
                        No entries match your current filters. Try adjusting your search criteria.
                    <?php else: ?>
                        No audit log entries have been recorded yet.
                    <?php endif; ?>
                </p>
                <?php if (!empty(array_filter($filters))): ?>
                    <a href="?page=admin_audit" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-clockwise me-1"></i>
                        Clear Filters
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Filters Modal -->
<div class="modal fade" id="filtersModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-funnel me-2"></i>
                    Advanced Filters
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="GET">
                <input type="hidden" name="page" value="admin_audit">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="filter_user_id" class="form-label">User</label>
                            <select class="form-select" name="user_id" id="filter_user_id">
                                <option value="">All Users</option>
                                <?php foreach ($allUsers as $userOption): ?>
                                    <option value="<?php echo $userOption['id']; ?>" 
                                            <?php echo $filters['user_id'] == $userOption['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($userOption['username'] . ' (' . $userOption['email'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="filter_action" class="form-label">Action</label>
                            <select class="form-select" name="action" id="filter_action">
                                <option value="">All Actions</option>
                                <?php foreach ($allActions as $action): ?>
                                    <option value="<?php echo $action; ?>" 
                                            <?php echo $filters['action'] == $action ? 'selected' : ''; ?>>
                                        <?php echo $auditLog->formatAction($action); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="filter_table_name" class="form-label">Table</label>
                            <select class="form-select" name="table_name" id="filter_table_name">
                                <option value="">All Tables</option>
                                <?php foreach ($allTables as $table): ?>
                                    <option value="<?php echo $table; ?>" 
                                            <?php echo $filters['table_name'] == $table ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($table); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="filter_ip_address" class="form-label">IP Address</label>
                            <input type="text" class="form-control" name="ip_address" id="filter_ip_address"
                                   value="<?php echo htmlspecialchars($filters['ip_address']); ?>"
                                   placeholder="192.168.1.1">
                        </div>
                        <div class="col-md-6">
                            <label for="filter_date_from" class="form-label">Date From</label>
                            <input type="date" class="form-control" name="date_from" id="filter_date_from"
                                   value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="filter_date_to" class="form-label">Date To</label>
                            <input type="date" class="form-control" name="date_to" id="filter_date_to"
                                   value="<?php echo htmlspecialchars($filters['date_to']); ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="?page=admin_audit" class="btn btn-outline-warning">Clear Filters</a>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Statistics Modal -->
<div class="modal fade" id="statsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-graph-up me-2"></i>
                    Audit Statistics (Last 30 Days)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (!empty($auditStats)): ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card bg-primary text-white">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo number_format($auditStats['total_actions']); ?></h4>
                                    <small>Total Actions</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-info text-white">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo number_format($auditStats['active_users']); ?></h4>
                                    <small>Active Users</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo number_format($auditStats['creates']); ?></h4>
                                    <small>Creates</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-warning text-dark">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo number_format($auditStats['updates']); ?></h4>
                                    <small>Updates</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-danger text-white">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo number_format($auditStats['deletes']); ?></h4>
                                    <small>Deletes</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-secondary text-white">
                                <div class="card-body text-center">
                                    <h4 class="mb-1"><?php echo number_format($auditStats['logins']); ?></h4>
                                    <small>Logins</small>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center">No statistics available.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
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
                <div id="details-content">
                    <!-- Content will be populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Handle details modal
document.getElementById('detailsModal').addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    const action = button.getAttribute('data-action');
    const oldValues = button.getAttribute('data-old-values');
    const newValues = button.getAttribute('data-new-values');
    const metadata = button.getAttribute('data-metadata');
    
    let content = '<h6>Action: <span class="badge bg-primary">' + action + '</span></h6>';
    
    if (oldValues) {
        content += '<div class="mt-3"><h6>Previous Values:</h6>';
        content += '<pre class="bg-light p-2 rounded"><code>' + oldValues + '</code></pre></div>';
    }
    
    if (newValues) {
        content += '<div class="mt-3"><h6>New Values:</h6>';
        content += '<pre class="bg-light p-2 rounded"><code>' + newValues + '</code></pre></div>';
    }
    
    if (metadata) {
        content += '<div class="mt-3"><h6>Metadata:</h6>';
        content += '<pre class="bg-light p-2 rounded"><code>' + metadata + '</code></pre></div>';
    }
    
    if (!oldValues && !newValues && !metadata) {
        content += '<p class="text-muted">No additional details available for this entry.</p>';
    }
    
    document.getElementById('details-content').innerHTML = content;
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
