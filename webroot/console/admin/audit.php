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

// Sorting & Pagination (avoid routing param collision: use 'p' for page num)
$sort = $_GET['sort'] ?? 'created_at';
$dir = strtolower($_GET['dir'] ?? 'desc');
$allowedSort = ['created_at','action','table_name','record_id','ip_address','user'];
if (!in_array($sort, $allowedSort, true)) { $sort = 'created_at'; }
if (!in_array($dir, ['asc','desc'], true)) { $dir = 'desc'; }

$pageNum = max(1, intval($_GET['p'] ?? 1));
$perPage = intval($_GET['per_page'] ?? 50);
if ($perPage < 10) $perPage = 10; if ($perPage > 200) $perPage = 200;
$limit = $perPage;
$offset = ($pageNum - 1) * $limit;

// Get audit log data
$auditEntries = [];
$totalCount = 0;
$auditStats = [];

try {
    // Clean empty filters
    $cleanFilters = array_filter($filters, function($value) {
        return $value !== '';
    });
    
    $auditEntries = $auditLog->getAuditLog($cleanFilters, $limit, $offset, $sort, $dir);
    $totalCount = $auditLog->getAuditLogCount($cleanFilters);
    $auditStats = $auditLog->getAuditStats(null, 30); // Last 30 days stats
} catch (Exception $e) {
    $error = 'Error loading audit log: ' . $e->getMessage();
}

// Calculate pagination
$totalPages = max(1, (int)ceil($totalCount / $limit));

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

<?php
// Render pagination controls while preserving current query params (using 'p' page param)
if (!function_exists('render_audit_pagination')) {
    function render_audit_pagination(array $params, int $current, int $total, bool $withJump = false) {
        // Always preserve the route
        $params['page'] = 'admin_audit';

        $html = '<nav><ul class="pagination pagination-sm mb-0">';

        // Prev
        if ($current > 1) {
            $params['p'] = $current - 1;
            $html .= '<li class="page-item"><a class="page-link" href="?' . http_build_query($params) . '"><i class="bi bi-chevron-left"></i></a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-left"></i></span></li>';
        }

        // Windowed pages around current
        $start = max(1, $current - 2);
        $end = min($total, $current + 2);
        if ($start > 1) {
            $params['p'] = 1;
            $html .= '<li class="page-item"><a class="page-link" href="?' . http_build_query($params) . '">1</a></li>';
            if ($start > 2) { $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
        }
        for ($i = $start; $i <= $end; $i++) {
            $params['p'] = $i;
            $active = $i === $current ? ' active' : '';
            $html .= '<li class="page-item' . $active . '"><a class="page-link" href="?' . http_build_query($params) . '">' . $i . '</a></li>';
        }
        if ($end < $total) {
            if ($end < $total - 1) { $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
            $params['p'] = $total;
            $html .= '<li class="page-item"><a class="page-link" href="?' . http_build_query($params) . '">' . $total . '</a></li>';
        }

        // Next
        if ($current < $total) {
            $params['p'] = $current + 1;
            $html .= '<li class="page-item"><a class="page-link" href="?' . http_build_query($params) . '"><i class="bi bi-chevron-right"></i></a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-right"></i></span></li>';
        }

        $html .= '</ul>';

        if ($withJump) {
            // Small page jump form
            $html .= '<form class="ms-2 d-inline" method="GET" style="display:inline-flex; align-items:center; gap:.25rem;">';
            foreach ($params as $k => $v) {
                if ($k === 'p') continue; // will be replaced by input
                $html .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string)$v) . '">';
            }
            $html .= '<input type="number" name="p" min="1" max="' . (int)$total . '" value="' . (int)$current . '" class="form-control form-control-sm" style="width:5rem">';
            $html .= '<button type="submit" class="btn btn-sm btn-outline-secondary">Go</button>';
            $html .= '</form>';
        }

        $html .= '</nav>';
        return $html;
    }
}
?>

<div class="container-fluid py-4">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        renderBreadcrumb([
            ['label' => 'Audit Log']
        ], true);
    ?>
    <!-- Page Header -->
    <div class="mb-4">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h2 class="h4 mb-0">
                <i class="bi bi-file-text me-2 text-info"></i>
                System Audit Log
            </h2>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#statsModal">
                    <i class="bi bi-graph-up me-1"></i>
                    Statistics
                </button>
            </div>
        </div>
        <p class="text-muted mb-0">Comprehensive system activity log for compliance and security monitoring</p>
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
                <div class="col-md-6">
                    <input type="text" class="form-control" name="search" 
                           placeholder="Search by action, table, username, or email..." 
                           value="<?php echo htmlspecialchars($filters['search']); ?>">
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="per_page">
                        <?php foreach ([25,50,100,150,200] as $opt): ?>
                            <option value="<?php echo $opt; ?>" <?php echo $perPage==$opt?'selected':''; ?>>Show <?php echo $opt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search me-1"></i>
                        Search
                    </button>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-secondary w-100" data-bs-toggle="modal" data-bs-target="#filtersModal">
                        <i class="bi bi-funnel me-1"></i>
                        Filters
                    </button>
                </div>
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                <input type="hidden" name="dir" value="<?php echo htmlspecialchars($dir); ?>">
                <input type="hidden" name="p" value="1">
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
            <!-- Top Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="card-subtitle px-3 pt-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted small">
                            Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $limit, $totalCount)); ?> 
                            of <?php echo number_format($totalCount); ?> entries
                        </div>
                        <?php echo render_audit_pagination($_GET, $pageNum, $totalPages); ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <?php 
                            // Helper to build sort URLs with icon and neutral text style
                            function audit_sort_link($label,$key,$curSort,$curDir){
                                $params = array_merge($_GET, ['sort'=>$key, 'dir'=>($curSort===$key && $curDir==='asc')?'desc':'asc']);
                                $url = '?' . http_build_query($params);
                                $icon = '';
                                if ($curSort === $key) {
                                    $icon = $curDir==='asc' 
                                        ? '<i class="bi bi-caret-up-fill ms-1"></i>' 
                                        : '<i class="bi bi-caret-down-fill ms-1"></i>';
                                }
                                return '<a href="'.$url.'" class="text-body text-decoration-none">'.$label.$icon.'</a>';
                            }
                            ?>
                            <th><?php echo audit_sort_link('Timestamp','created_at',$sort,$dir); ?></th>
                            <th><?php echo audit_sort_link('User','user',$sort,$dir); ?></th>
                            <th><?php echo audit_sort_link('Action','action',$sort,$dir); ?></th>
                            <th><?php echo audit_sort_link('Table','table_name',$sort,$dir); ?></th>
                            <th><?php echo audit_sort_link('Record ID','record_id',$sort,$dir); ?></th>
                            <th><?php echo audit_sort_link('IP Address','ip_address',$sort,$dir); ?></th>
                            <th class="text-end">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                            // Prepare DB for optional lookups (e.g., record -> domain mapping)
                            $auditDb = null;
                            try { $auditDb = Database::getInstance(); } catch (Exception $e) { $auditDb = null; }
                        ?>
                        <?php foreach ($auditEntries as $entry): ?>
                            <?php 
                              $displayRecordId = $entry['record_id'] ?? null;
                              if (!$displayRecordId && !empty($entry['metadata'])) {
                                  $meta = json_decode($entry['metadata'], true);
                                  if (is_array($meta)) {
                                      $displayRecordId = $meta['record_id'] ?? ($meta['domain_id'] ?? null);
                                  }
                              }
                            ?>
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
                                    <?php if (!empty($displayRecordId)): ?>
                                        <?php 
                                            $targetUrl = null;
                                            $table = $entry['table_name'] ?? '';
                                            $metaArr = [];
                                            if (!empty($entry['metadata'])) {
                                                $tmp = json_decode($entry['metadata'], true);
                                                if (is_array($tmp)) { $metaArr = $tmp; }
                                            }
                                            if ($table === 'domains') {
                                                $targetUrl = '?page=zone_edit&id=' . urlencode((string)$displayRecordId);
                                            } elseif ($table === 'records' && !empty($entry['record_id'])) {
                                                $domainId = $metaArr['domain_id'] ?? null;
                                                if (!$domainId && $auditDb) {
                                                    try {
                                                        $row = $auditDb->fetch("SELECT domain_id FROM records WHERE id = ?", [$entry['record_id']]);
                                                        $domainId = $row['domain_id'] ?? null;
                                                    } catch (Exception $e) { /* ignore */ }
                                                }
                                                if ($domainId) {
                                                    $targetUrl = '?page=records&domain_id=' . urlencode((string)$domainId) . '&action=edit&id=' . urlencode((string)$entry['record_id']);
                                                } elseif (!empty($metaArr['domain_id'])) {
                                                    $targetUrl = '?page=records&domain_id=' . urlencode((string)$metaArr['domain_id']);
                                                }
                                            } elseif ($table === 'cryptokeys' && !empty($metaArr['domain_id'])) {
                                                $targetUrl = '?page=dnssec&domain_id=' . urlencode((string)$metaArr['domain_id']);
                                            } elseif (!empty($metaArr['domain_id'])) {
                                                // Generic fallback to the domain's records list if domain_id present
                                                $targetUrl = '?page=records&domain_id=' . urlencode((string)$metaArr['domain_id']);
                                            }
                                        ?>
                                        <?php if ($targetUrl): ?>
                                            <a href="<?php echo $targetUrl; ?>" class="text-decoration-none">
                                                <code class="small"><?php echo htmlspecialchars($displayRecordId); ?></code>
                                            </a>
                                        <?php else: ?>
                                            <code class="small"><?php echo htmlspecialchars($displayRecordId); ?></code>
                                        <?php endif; ?>
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

            <!-- Bottom Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted">
                            Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $limit, $totalCount)); ?> 
                            of <?php echo number_format($totalCount); ?> entries
                        </div>
                        <?php echo render_audit_pagination($_GET, $pageNum, $totalPages); ?>
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
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                <input type="hidden" name="dir" value="<?php echo htmlspecialchars($dir); ?>">
                <input type="hidden" name="per_page" value="<?php echo htmlspecialchars((string)$perPage); ?>">
                <input type="hidden" name="p" value="1">
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
                <style>
                    /* Inline stat tile styles (scoped to modal) */
                    #statsModal .stat-tile { border-radius:.65rem; padding:1rem .75rem; text-align:center; font-weight:500; position:relative; overflow:hidden; }
                    #statsModal .stat-tile h4 { font-weight:600; }
                    #statsModal .stat-tile.primary { background: var(--bs-primary)!important; color:#fff; }
                    #statsModal .stat-tile.info { background: var(--bs-info)!important; color:#fff; }
                    #statsModal .stat-tile.success { background: var(--bs-success)!important; color:#fff; }
                    #statsModal .stat-tile.warning { background: var(--bs-warning)!important; color:#212529!important; }
                    #statsModal .stat-tile.danger { background: var(--bs-danger)!important; color:#fff; }
                    #statsModal .stat-tile.secondary { background: var(--bs-secondary)!important; color:#fff; }
                    #statsModal .stat-tile::after { content:""; position:absolute; inset:0; background:rgba(255,255,255,.05); opacity:0; transition:opacity .2s; }
                    #statsModal .stat-tile:hover::after { opacity:1; }
                </style>
                <?php if (!empty($auditStats)): ?>
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="stat-tile primary shadow-sm">
                                <h4 class="mb-1"><?php echo number_format($auditStats['total_actions']); ?></h4>
                                <small>Total Actions</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-tile info shadow-sm">
                                <h4 class="mb-1"><?php echo number_format($auditStats['active_users']); ?></h4>
                                <small>Active Users</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-tile success shadow-sm">
                                <h4 class="mb-1"><?php echo number_format($auditStats['creates']); ?></h4>
                                <small>Creates</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-tile warning shadow-sm">
                                <h4 class="mb-1"><?php echo number_format($auditStats['updates']); ?></h4>
                                <small>Updates</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-tile danger shadow-sm">
                                <h4 class="mb-1"><?php echo number_format($auditStats['deletes']); ?></h4>
                                <small>Deletes</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-tile secondary shadow-sm">
                                <h4 class="mb-1"><?php echo number_format($auditStats['logins']); ?></h4>
                                <small>Logins</small>
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
