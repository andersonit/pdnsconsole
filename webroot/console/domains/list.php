<?php
/**
 * PDNS Console - Domain Management
 */

// Get classes (currentUser is already set by index.php)
$user = new User();
$domain = new Domain();

// Check if user is super admin
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

// Get user's tenants
$userTenants = [];
if (!$isSuperAdmin) {
    $tenantData = $user->getUserTenants($currentUser['id']);
    $userTenants = array_column($tenantData, 'tenant_id');
    if (empty($userTenants)) {
        $error = 'No tenants assigned to your account. Please contact an administrator.';
    }
}

// Handle search and pagination
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

// Get domains based on user role
$domains = [];
$totalCount = 0;

try {
    if ($isSuperAdmin) {
        $domains = $domain->getAllDomains($search, $limit, $offset);
        $db = Database::getInstance();
        $totalCount = $db->fetch(
            "SELECT COUNT(DISTINCT d.id) as count FROM domains d" . 
            (!empty($search) ? " WHERE d.name LIKE ?" : ""),
            !empty($search) ? ['%' . $search . '%'] : []
        )['count'];
    } else if (!empty($userTenants)) {
        // For simplicity, use first tenant if user has multiple
        $tenantId = $userTenants[0];
        $domains = $domain->getDomainsForTenant($tenantId, $search, $limit, $offset);
        $totalCount = $domain->getDomainCountForTenant($tenantId, $search);
    }
} catch (Exception $e) {
    $error = 'Error loading domains: ' . $e->getMessage();
}

// Calculate pagination
$totalPages = ceil($totalCount / $limit);
$showPagination = $totalPages > 1;

// Page title
$pageTitle = 'Domain Management';
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="h4 mb-1">
                        <i class="bi bi-globe2 me-2 text-primary"></i>
                        Domain Management
                    </h2>
                    <p class="text-muted mb-0">
                        Manage your DNS domains and settings
                        <?php if (!$isSuperAdmin && isset($tenantId)): ?>
                            <?php
                            $db = Database::getInstance();
                            $tenantInfo = $db->fetch("SELECT name, max_domains FROM tenants WHERE id = ?", [$tenantId]);
                            if ($tenantInfo) {
                                $domainLimit = $tenantInfo['max_domains'] == 0 ? 'Unlimited' : $tenantInfo['max_domains'];
                                echo "• {$totalCount} / {$domainLimit} domains";
                            }
                            ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <a href="?page=domain_add" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>
                        Add Domain
                    </a>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Search and Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <input type="hidden" name="page" value="domains">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" name="search" class="form-control" 
                                       placeholder="Search domains..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <button type="submit" class="btn btn-outline-primary me-2">
                                <i class="bi bi-search me-1"></i>
                                Search
                            </button>
                            <a href="?page=domains" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise me-1"></i>
                                Clear
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Domain List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul me-2"></i>
                        Domains (<?php echo number_format($totalCount); ?>)
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($domains)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-globe2 display-4 text-muted mb-3"></i>
                            <h5 class="text-muted">No domains found</h5>
                            <p class="text-muted">
                                <?php if (!empty($search)): ?>
                                    No domains match your search criteria.
                                <?php else: ?>
                                    Get started by adding your first domain.
                                <?php endif; ?>
                            </p>
                            <?php if (empty($search)): ?>
                                <a href="?page=domain_add" class="btn btn-primary">
                                    <i class="bi bi-plus-circle me-1"></i>
                                    Add Your First Domain
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Domain Name</th>
                                        <th>Zone Type</th>
                                        <th>Records</th>
                                        <th>Type</th>
                                        <?php if ($isSuperAdmin): ?>
                                            <th>Tenant</th>
                                        <?php endif; ?>
                                        <th>DNSSEC</th>
                                        <th>Created</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($domains as $domainRow): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($domainRow['name']); ?></strong>
                                                <?php if ($domainRow['account']): ?>
                                                    <br><small class="text-muted">
                                                        Account: <?php echo htmlspecialchars($domainRow['account']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $zoneType = $domainRow['zone_type'] ?? 'forward';
                                                $zoneTypeClass = $zoneType === 'reverse' ? 'bg-success' : 'bg-primary';
                                                $zoneTypeIcon = $zoneType === 'reverse' ? 'arrow-clockwise' : 'globe2';
                                                ?>
                                                <span class="badge <?php echo $zoneTypeClass; ?>">
                                                    <i class="bi bi-<?php echo $zoneTypeIcon; ?> me-1"></i>
                                                    <?php echo ucfirst($zoneType); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo number_format($domainRow['record_count']); ?> records
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo htmlspecialchars($domainRow['type']); ?>
                                                </span>
                                            </td>
                                            <?php if ($isSuperAdmin): ?>
                                                <td>
                                                    <?php if ($domainRow['tenant_name']): ?>
                                                        <span class="badge bg-primary">
                                                            <?php echo htmlspecialchars($domainRow['tenant_name']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Unassigned</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                            <td>
                                                <?php if ($domainRow['dnssec_enabled'] > 0): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-shield-check me-1"></i>
                                                        Enabled
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark">
                                                        <i class="bi bi-shield me-1"></i>
                                                        Disabled
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($domainRow['domain_created']): ?>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, Y', strtotime($domainRow['domain_created'])); ?>
                                                    </small>
                                                <?php else: ?>
                                                    <small class="text-muted">-</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="?page=records&domain_id=<?php echo $domainRow['id']; ?>" 
                                                       class="btn btn-outline-primary" title="Manage Records">
                                                        <i class="bi bi-list-ul"></i>
                                                        <span class="d-none d-md-inline ms-1">Records</span>
                                                    </a>
                                                    <a href="?page=domain_edit&id=<?php echo $domainRow['id']; ?>" 
                                                       class="btn btn-outline-secondary" title="Edit Domain">
                                                        <i class="bi bi-pencil"></i>
                                                        <span class="d-none d-md-inline ms-1">Edit</span>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($showPagination): ?>
                    <div class="card-footer">
                        <nav aria-label="Domain pagination">
                            <ul class="pagination pagination-sm mb-0 justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=domains&search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">
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
                                        <a class="page-link" href="?page=domains&search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=domains&search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
