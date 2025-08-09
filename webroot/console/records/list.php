<?php
/**
 * PDNS Console - DNS Records Management
 */

// Get classes (currentUser is already set by index.php)
$user = new User();
$records = new Records();
$domain = new Domain();

// Check if user is super admin
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

// Get domain ID
$domainId = intval($_GET['domain_id'] ?? 0);
if (empty($domainId)) {
    header('Location: ?page=zone_manage');
    exit;
}

// Handle actions (add, edit, delete, bulk)
$action = $_GET['action'] ?? '';
if (!empty($action)) {
    switch ($action) {
        case 'add':
            include 'add.php';
            return;
        case 'edit':
            include 'edit.php';
            return;
        case 'delete':
            include 'delete.php';
            return;
        case 'bulk':
        case 'add_bulk':
            include 'add_bulk.php';
            return;
    }
}

// Get user's tenants for non-super admin users
$userTenants = [];
$tenantId = null;
if (!$isSuperAdmin) {
    $tenantData = $user->getUserTenants($currentUser['id']);
    $userTenants = array_column($tenantData, 'id');
    if (empty($userTenants)) {
        $error = 'No tenants assigned to your account. Please contact an administrator.';
    } else {
        $tenantId = $userTenants[0]; // Use first tenant for access check
    }
}

// Get domain info
$domainInfo = null;
try {
    $domainInfo = $domain->getDomainById($domainId, $tenantId);
    if (!$domainInfo) {
        $error = 'Domain not found or access denied.';
    }
} catch (Exception $e) {
    $error = 'Error loading domain: ' . $e->getMessage();
}

// Initialize pagination and filters
$page = max(1, intval($_GET['p'] ?? 1));
$limit = intval($_GET['limit'] ?? 25);
$limit = in_array($limit, [10, 25, 50, 100]) ? $limit : 25; // Validate limit
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
// Default sorting: alphabetically by name
$sortBy = trim($_GET['sort'] ?? 'name');
$sortOrder = strtoupper(trim($_GET['order'] ?? 'ASC'));
$sortOrder = in_array($sortOrder, ['ASC', 'DESC']) ? $sortOrder : 'ASC';

// Get records and stats
$recordsList = [];
$totalRecords = 0;
$recordStats = [];

// Check for session messages
$sessionSuccess = $_SESSION['success'] ?? '';
$sessionError = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

if ($domainInfo) {
    try {
        $recordsList = $records->getRecordsForDomain(
            $domainId, 
            $tenantId, 
            $typeFilter, 
            $search, 
            $limit, 
            $offset,
            $sortBy,
            $sortOrder
        );
        
        $totalRecords = $records->getRecordCountForDomain($domainId, $tenantId, $typeFilter, $search);
        $recordStats = $records->getRecordStats($domainId, $tenantId);
        
    } catch (Exception $e) {
        $error = 'Error loading records: ' . $e->getMessage();
    }
}

// Calculate pagination
$totalPages = ceil($totalRecords / $limit);

// Helper function for pagination URLs
function buildPaginationUrl($domainId, $pageNum, $search, $typeFilter, $sortBy, $sortOrder, $limit) {
    $params = [
        'page' => 'records',
        'domain_id' => $domainId,
        'p' => $pageNum,
        'search' => $search,
        'type' => $typeFilter,
        'sort' => $sortBy,
        'order' => $sortOrder,
        'limit' => $limit
    ];
    return '?' . http_build_query(array_filter($params));
}

// Get zone type from domain info
$zoneType = $domainInfo['zone_type'] ?? 'forward';

// Get supported record types for filter (filtered by zone type)
$supportedTypes = $records->getSupportedRecordTypes($zoneType);

// Page title
$pageTitle = 'DNS Records' . ($domainInfo ? ' - ' . $domainInfo['name'] : '');
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="mb-4">
        <div class="d-flex align-items-center mb-2">
            <a href="?page=zone_manage" class="btn btn-outline-secondary btn-sm me-3">
                <i class="bi bi-arrow-left"></i>
            </a>
            <h2 class="h4 mb-0">
                <i class="bi bi-list-ul me-2 text-primary"></i>
                DNS Records
            </h2>
        </div>
        <?php if ($domainInfo): ?>
            <p class="text-muted mb-0">
                Managing records for <strong><?php echo htmlspecialchars($domainInfo['name']); ?></strong>
                <?php if ($domainInfo['tenant_name']): ?>
                    <span class="badge bg-primary ms-2"><?php echo htmlspecialchars($domainInfo['tenant_name']); ?></span>
                <?php endif; ?>
            </p>
            <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                System records (SOA, NS) are highlighted and protected from modification
            </small>
        <?php endif; ?>
    </div>

                <?php if (!empty($sessionSuccess)): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($sessionSuccess); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($sessionError)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($sessionError); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
        
        <?php if (!$domainInfo): ?>
            <div class="text-center">
                <a href="?page=zone_manage" class="btn btn-primary">
                    <i class="bi bi-arrow-left me-1"></i>
                    Back to Zone Management
                </a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($domainInfo): ?>
        <!-- Record Statistics -->
        <?php if (!empty($recordStats)): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">
                                <i class="bi bi-bar-chart me-2"></i>
                                Record Statistics
                            </h6>
                            <div class="row">
                                <?php foreach ($recordStats as $stat): ?>
                                    <div class="col-auto">
                                        <div class="d-flex align-items-center">
                                            <span class="badge bg-secondary me-2"><?php echo htmlspecialchars($stat['type']); ?></span>
                                            <span class="fw-bold"><?php echo $stat['count']; ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Search and Filter Bar -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="records">
                    <input type="hidden" name="domain_id" value="<?php echo $domainId; ?>">
                    
                    <div class="col-md-4">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search records...">
                    </div>
                    
                    <div class="col-md-3">
                        <label for="type" class="form-label">Record Type</label>
                        <select class="form-select" id="type" name="type">
                            <option value="">All Types</option>
                            <?php foreach ($supportedTypes as $type => $info): ?>
                                <option value="<?php echo $type; ?>" 
                                        <?php echo $typeFilter === $type ? 'selected' : ''; ?>>
                                    <?php echo $type; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="limit" class="form-label">Per Page</label>
                        <select class="form-select" id="limit" name="limit">
                            <option value="10" <?php echo $limit === 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $limit === 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search me-1"></i>
                            Search
                        </button>
                        <a href="?page=records&domain_id=<?php echo $domainId; ?>" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-clockwise me-1"></i>
                            Reset
                        </a>
                        <div class="btn-group">
                            <a href="?page=records&domain_id=<?php echo $domainId; ?>&action=add" 
                               class="btn btn-success">
                                <i class="bi bi-plus-circle me-1"></i>
                                Add Record
                            </a>
                            <button type="button" class="btn btn-success dropdown-toggle dropdown-toggle-split" 
                                    data-bs-toggle="dropdown">
                                <span class="visually-hidden">Toggle Dropdown</span>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a class="dropdown-item" href="?page=records&domain_id=<?php echo $domainId; ?>&action=add">
                                        <i class="bi bi-plus-circle me-2"></i>Add Single Record
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="?page=records&domain_id=<?php echo $domainId; ?>&action=bulk">
                                        <i class="bi bi-plus-square me-2"></i>Bulk Add Records
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Records Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    DNS Records 
                    <?php if ($totalRecords > 0): ?>
                        <span class="badge bg-secondary"><?php echo $totalRecords; ?></span>
                    <?php endif; ?>
                </h5>
                
                <?php if ($totalRecords > 0): ?>
                    <small class="text-muted">
                        Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $totalRecords); ?> 
                        of <?php echo $totalRecords; ?> records
                    </small>
                <?php endif; ?>
            </div>
            
            <?php if (empty($recordsList)): ?>
                <div class="card-body text-center py-5">
                    <i class="bi bi-inbox display-1 text-muted"></i>
                    <h5 class="mt-3">No DNS Records Found</h5>
                    <p class="text-muted mb-4">
                        <?php if (!empty($search) || !empty($typeFilter)): ?>
                            No records match your search criteria.
                        <?php else: ?>
                            This domain doesn't have any DNS records yet.
                        <?php endif; ?>
                    </p>
                    <a href="?page=records&domain_id=<?php echo $domainId; ?>&action=add" 
                       class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>
                        Add First Record
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>
                                    <a href="?page=records&domain_id=<?php echo $domainId; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($typeFilter); ?>&sort=name&order=<?php echo $sortBy === 'name' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'; ?>&limit=<?php echo $limit; ?>" 
                                       class="text-decoration-none text-dark">
                                        Name 
                                        <?php if ($sortBy === 'name'): ?>
                                            <i class="bi bi-chevron-<?php echo $sortOrder === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?page=records&domain_id=<?php echo $domainId; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($typeFilter); ?>&sort=type&order=<?php echo $sortBy === 'type' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'; ?>&limit=<?php echo $limit; ?>" 
                                       class="text-decoration-none text-dark">
                                        Type 
                                        <?php if ($sortBy === 'type'): ?>
                                            <i class="bi bi-chevron-<?php echo $sortOrder === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?page=records&domain_id=<?php echo $domainId; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($typeFilter); ?>&sort=content&order=<?php echo $sortBy === 'content' && $sortOrder === 'ASC' ? 'DESC' : 'ASC'; ?>&limit=<?php echo $limit; ?>" 
                                       class="text-decoration-none text-dark">
                                        Content 
                                        <?php if ($sortBy === 'content'): ?>
                                            <i class="bi bi-chevron-<?php echo $sortOrder === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>TTL</th>
                                <th>Priority</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recordsList as $record): ?>
                                <?php $isSystemRecord = in_array($record['type'], ['SOA', 'NS']); ?>
                                <tr>
                                    <td>
                                        <div class="fw-medium text-break">
                                            <?php echo htmlspecialchars($record['name']); ?>
                                            <?php if ($isSystemRecord): ?>
                                                <small class="text-muted d-block">
                                                    <i class="bi bi-lock me-1"></i>System Record
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $isSystemRecord ? 'bg-secondary' : 'bg-secondary'; ?>">
                                            <?php echo htmlspecialchars($record['type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="text-break" style="max-width: 300px;">
                                            <code class="small">
                                                <?php echo htmlspecialchars($record['content']); ?>
                                            </code>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-muted">
                                            <?php echo number_format($record['ttl']); ?>s
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($record['prio'] > 0): ?>
                                            <span class="badge bg-info">
                                                <?php echo $record['prio']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($isSystemRecord): ?>
                                                <div title="Change in System Settings" 
                                                     data-bs-toggle="tooltip" 
                                                     data-bs-placement="top"
                                                     style="display: inline-block;">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled>
                                                        <i class="bi bi-lock"></i>
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <a href="?page=records&domain_id=<?php echo $domainId; ?>&action=edit&id=<?php echo $record['id']; ?>" 
                                                   class="btn btn-outline-primary btn-sm" 
                                                   title="Edit Record"
                                                   data-bs-toggle="tooltip" 
                                                   data-bs-placement="top">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-danger btn-sm" 
                                                        onclick="confirmDelete(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($record['type'], ENT_QUOTES); ?>')"
                                                        title="Delete Record"
                                                        data-bs-toggle="tooltip" 
                                                        data-bs-placement="top">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="card-footer">
                        <nav aria-label="Records pagination">
                            <ul class="pagination justify-content-center mb-0">
                                <!-- Previous button -->
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl($domainId, $page - 1, $search, $typeFilter, $sortBy, $sortOrder, $limit); ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                
                                if ($startPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo buildPaginationUrl($domainId, 1, $search, $typeFilter, $sortBy, $sortOrder, $limit); ?>">1</a>
                                    </li>
                                    <?php if ($startPage > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif;
                                endif;
                                
                                for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo buildPaginationUrl($domainId, $i, $search, $typeFilter, $sortBy, $sortOrder, $limit); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor;
                                
                                if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo buildPaginationUrl($domainId, $totalPages, $search, $typeFilter, $sortBy, $sortOrder, $limit); ?>"><?php echo $totalPages; ?></a>
                                    </li>
                                <?php endif; ?>
                                
                                <!-- Next button -->
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl($domainId, $page + 1, $search, $typeFilter, $sortBy, $sortOrder, $limit); ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="mt-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">
                        <i class="bi bi-lightning me-2"></i>
                        Quick Actions
                    </h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="d-grid gap-2">
                                <a href="?page=records&domain_id=<?php echo $domainId; ?>&action=add&type=A" 
                                   class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-plus-circle me-1"></i>
                                    Add A Record
                                </a>
                                <a href="?page=records&domain_id=<?php echo $domainId; ?>&action=add&type=CNAME" 
                                   class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-plus-circle me-1"></i>
                                    Add CNAME Record
                                </a>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-grid gap-2">
                                <a href="?page=records&domain_id=<?php echo $domainId; ?>&action=add&type=MX" 
                                   class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-plus-circle me-1"></i>
                                    Add MX Record
                                </a>
                                <a href="?page=records&domain_id=<?php echo $domainId; ?>&action=bulk" 
                                   class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-plus-square me-1"></i>
                                    Bulk Add Records
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle text-danger me-2"></i>
                    Confirm Record Deletion
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this DNS record?</p>
                <div class="alert alert-info">
                    <strong>Record:</strong> <span id="deleteRecordName"></span><br>
                    <strong>Type:</strong> <span id="deleteRecordType"></span>
                </div>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>This action cannot be undone!</strong> The record will be permanently deleted.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="?page=records&domain_id=<?php echo $domainId; ?>&action=delete" class="d-inline">
                    <input type="hidden" name="record_id" id="deleteRecordId">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>
                        Delete Record
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(recordId, recordName, recordType) {
    document.getElementById('deleteRecordId').value = recordId;
    document.getElementById('deleteRecordName').textContent = recordName;
    document.getElementById('deleteRecordType').textContent = recordType;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
