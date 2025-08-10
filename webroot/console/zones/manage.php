<?php
/**
 * PDNS Console - Zone Management (Main DNS Interface)
 */

$domain = new Domain();
$db = Database::getInstance();

// Get current user and determine access level
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

// Get pagination parameters (use system default records_per_page setting)
$page_num = isset($_GET['page_num']) ? max(1, intval($_GET['page_num'])) : 1;
$settingsDb = Database::getInstance();
$rppSetting = $settingsDb->fetch("SELECT setting_value FROM global_settings WHERE setting_key = 'records_per_page'");
$defaultLimit = intval($rppSetting['setting_value'] ?? 25);
if (!in_array($defaultLimit, [10,25,50,100])) { $defaultLimit = 25; }
// Allowed per-page options
$allowedLimits = [10,25,50,100];

// If user provided a limit, validate and store in session; else fall back to session then default
if (isset($_GET['limit'])) {
    $providedLimit = intval($_GET['limit']);
    if (in_array($providedLimit, $allowedLimits)) {
        $limit = $providedLimit;
        $_SESSION['zones_per_page'] = $limit; // persist selection
    } else {
        $limit = $defaultLimit;
    }
} else {
    if (isset($_SESSION['zones_per_page']) && in_array(intval($_SESSION['zones_per_page']), $allowedLimits)) {
        $limit = intval($_SESSION['zones_per_page']);
    } else {
        $limit = $defaultLimit;
    }
}
$offset = ($page_num - 1) * $limit;

// Get search and filter parameters
$search = trim($_GET['search'] ?? '');
$zoneTypeFilter = $_GET['zone_type'] ?? '';
$tenantFilter = $_GET['tenant_filter'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'name';
$sortOrder = $_GET['sort_order'] ?? 'ASC';

// Whitelist sortable columns (must match Domain class + multi-tenant query capabilities)
$allowedZoneSorts = ['name','zone_type','record_count','domain_created','type','dnssec_enabled','tenant_name'];
if (!in_array($sortBy, $allowedZoneSorts)) { $sortBy = 'name'; }
$sortOrder = strtoupper($sortOrder);
if (!in_array($sortOrder, ['ASC','DESC'])) { $sortOrder = 'ASC'; }

// Get tenant information
$selectedTenantId = null;
$userTenants = [];

if ($isSuperAdmin) {
    // Super admins can see all tenants
    $allTenants = $db->fetchAll("SELECT id, name FROM tenants WHERE is_active = 1 ORDER BY name");
    $userTenants = $allTenants;
    $selectedTenantId = $tenantFilter ? intval($tenantFilter) : null;
} else {
    // Regular users can only see their assigned tenants
    $userTenants = $db->fetchAll(
        "SELECT t.id AS tenant_id, t.name 
         FROM tenants t 
         JOIN user_tenants ut ON t.id = ut.tenant_id 
         WHERE ut.user_id = ? AND t.is_active = 1 
         ORDER BY t.name", 
        [$currentUser['id']]
    );
    
    if (count($userTenants) === 1) {
        // User has access to only one tenant
        $selectedTenantId = $userTenants[0]['tenant_id'];
    } else {
        // User has access to multiple tenants
        $selectedTenantId = $tenantFilter ? intval($tenantFilter) : null;
    }
}

// Get zones data
$domains = [];
$totalCount = 0;

try {
    if ($isSuperAdmin) {
        // Super admins see all zones
        $domains = $domain->getAllDomains($search, $zoneTypeFilter, $selectedTenantId, $limit, $offset, $sortBy, $sortOrder);
        $totalCount = $domain->getAllDomainsCount($search, $zoneTypeFilter, $selectedTenantId);
    } else {
        // Regular users see only their tenant zones
        if ($selectedTenantId) {
            $domains = $domain->getDomainsForTenant($selectedTenantId, $search, $zoneTypeFilter, $limit, $offset, $sortBy, $sortOrder);
            $totalCount = $domain->getDomainCountForTenant($selectedTenantId, $search, $zoneTypeFilter);
        } else {
            // Multi-tenant user with no specific tenant selected
            $tenantIds = array_column($userTenants, 'tenant_id');
            if (!empty($tenantIds)) {
                $placeholders = str_repeat('?,', count($tenantIds) - 1) . '?';
                $searchClause = '';
                $params = $tenantIds;
                
                if (!empty($search)) {
                    $searchClause = " AND d.name LIKE ?";
                    $params[] = "%$search%";
                }
                
                $zoneTypeClause = '';
                if (!empty($zoneTypeFilter)) {
                    $zoneTypeClause = " AND dm.content = ?";
                    $params[] = $zoneTypeFilter;
                }
                
                // Build safe ORDER BY mapping (avoid direct interpolation of user input)
                $orderColumnMap = [
                    'name' => 'd.name',
                    'zone_type' => 'zone_type', // alias below
                    'record_count' => 'record_count', // subquery alias
                    'domain_created' => 'dt.created_at',
                    'type' => 'd.type',
                    'dnssec_enabled' => 'dnssec_enabled',
                    'tenant_name' => 't.name'
                ];
                if (!array_key_exists($sortBy, $orderColumnMap)) { $sortBy = 'name'; }
                $orderExpr = $orderColumnMap[$sortBy] . ' ' . $sortOrder;
                if ($sortBy !== 'name') {
                    $orderExpr .= ', d.name ASC'; // stable secondary sort
                }
                $domains = $db->fetchAll(
                    "SELECT DISTINCT d.id, d.name, d.type, d.account, t.name as tenant_name, 
                            COALESCE(dm.content, 'forward') as zone_type,
                            (SELECT COUNT(*) FROM records r WHERE r.domain_id = d.id) as record_count,
                            dt.created_at as domain_created,
                            (SELECT COUNT(*) FROM cryptokeys ck WHERE ck.domain_id = d.id AND ck.active = 1) as dnssec_enabled
                     FROM domains d 
                     JOIN domain_tenants dt ON d.id = dt.domain_id 
                     LEFT JOIN tenants t ON dt.tenant_id = t.id
                     LEFT JOIN domainmetadata dm ON d.id = dm.domain_id AND dm.kind = 'zone-type'
                     WHERE dt.tenant_id IN ($placeholders) $searchClause $zoneTypeClause
                     ORDER BY $orderExpr
                     LIMIT ? OFFSET ?",
                    array_merge($params, [$limit, $offset])
                );
                
                $countResult = $db->fetch(
                    "SELECT COUNT(DISTINCT d.id) as count 
                     FROM domains d 
                     JOIN domain_tenants dt ON d.id = dt.domain_id 
                     LEFT JOIN domainmetadata dm ON d.id = dm.domain_id AND dm.kind = 'zone-type'
                     WHERE dt.tenant_id IN ($placeholders) $searchClause $zoneTypeClause",
                    $params
                );
                $totalCount = $countResult['count'] ?? 0;
            }
        }
    }
} catch (Exception $e) {
    $error = 'Error loading zones: ' . $e->getMessage();
}


// Calculate pagination
$totalPages = ceil($totalCount / $limit);
$startRecord = $offset + 1;
$endRecord = min($offset + $limit, $totalCount);

// Shared base query string for pagination & sorting (replaces removed buildZonesPaginationUrl())
$paginationParams = [
    'page' => 'zone_manage',
    'search' => $search,
    'zone_type' => $zoneTypeFilter,
    'tenant_filter' => $tenantFilter,
    'sort_by' => $sortBy,
    'sort_order' => $sortOrder,
    'limit' => $limit
];
$paginationBase = http_build_query(array_filter($paginationParams));

$pageTitle = 'Zone Management';
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';
?>

<div class="container-fluid mt-4">
    <!-- Breadcrumb Navigation -->
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        renderBreadcrumb([
            ['label' => 'Zones']
        ], $isSuperAdmin, ['class' => 'mb-4']);
    ?>

        <?php // Removed top pagination block; will embed miniature controls in table header ?>

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="bi bi-globe me-2"></i>
                        Zone Management
                    </h1>
                    <p class="text-muted mb-2">Manage DNS zones, records, DNSSEC, and Dynamic DNS</p>
                </div>
                <div class="btn-group">
                    <a href="?page=zone_add" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>
                        Add Zone
                    </a>
                    <a href="?page=zone_bulk_add" class="btn btn-outline-primary">
                        <i class="bi bi-layers me-1"></i>
                        Bulk Add
                    </a>
                    <a href="?page=records_import" class="btn btn-outline-secondary">
                        <i class="bi bi-upload me-1"></i>
                        Import CSV
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Search and Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="page" value="zone_manage">
                
                <div class="col-md-4">
                    <label for="search" class="form-label">Search Zones</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" 
                               class="form-control" 
                               id="search" 
                               name="search" 
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search zones..." 
                               autocomplete="off">
                    </div>
                </div>

                <div class="col-md-2">
                    <label for="zone_type" class="form-label">Zone Type</label>
                    <select class="form-select" id="zone_type" name="zone_type">
                        <option value="">All Types</option>
                        <option value="forward" <?php echo $zoneTypeFilter === 'forward' ? 'selected' : ''; ?>>Forward</option>
                        <option value="reverse" <?php echo $zoneTypeFilter === 'reverse' ? 'selected' : ''; ?>>Reverse</option>
                    </select>
                </div>

                <?php if ($isSuperAdmin || count($userTenants) > 1): ?>
                <div class="col-md-3">
                    <label for="tenant_filter" class="form-label">Tenant</label>
                    <select class="form-select" id="tenant_filter" name="tenant_filter">
                        <option value="">All Tenants</option>
                        <?php foreach ($userTenants as $tenant): ?>
                            <?php $tenantId = $isSuperAdmin ? $tenant['id'] : $tenant['tenant_id']; ?>
                            <?php $tenantName = $isSuperAdmin ? $tenant['name'] : $tenant['name']; ?>
                            <option value="<?php echo $tenantId; ?>" 
                                    <?php echo $tenantFilter == $tenantId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tenantName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-auto d-flex align-items-end">
                    <div class="btn-group">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-funnel me-1"></i>
                            Filter
                        </button>
                        <a href="?page=zone_manage" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-1"></i>
                            Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Zone List -->
    <div class="card">
        <div class="card-header py-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex flex-column">
                    <h5 class="card-title mb-0 d-flex align-items-center">
                        <i class="bi bi-list-ul me-2"></i>
                        Zones
                        <?php if ($totalCount > 0): ?>
                            <span class="badge bg-secondary ms-2"><?php echo number_format($totalCount); ?></span>
                        <?php endif; ?>
                    </h5>
                    <?php if ($totalCount > 0): ?>
                        <small class="text-muted mb-0"><?php echo formatCountRange($startRecord, $endRecord, $totalCount, 'zones'); ?></small>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <?php
                        $zoneBaseParams = [
                            'page' => 'zone_manage',
                            'search' => $search,
                            'zone_type' => $zoneTypeFilter,
                            'tenant_filter' => $tenantFilter,
                            'sort_by' => $sortBy,
                            'sort_order' => $sortOrder,
                            'limit' => $limit
                        ];
                        renderPerPageForm([
                            'base_params' => $zoneBaseParams,
                            'page_param' => 'page_num',
                            'limit' => $limit,
                            'limit_options' => [10,25,50,100]
                        ]);
                        renderPaginationNav([
                            'current' => $page_num,
                            'total_pages' => $totalPages,
                            'page_param' => 'page_num',
                            'base_params' => $zoneBaseParams
                        ]);
                    ?>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($domains)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-globe display-4 text-muted mb-3"></i>
                    <h5 class="text-muted">No zones found</h5>
                    <p class="text-muted mb-4">
                        <?php if (!empty($search) || !empty($zoneTypeFilter) || !empty($tenantFilter)): ?>
                            No zones match your search criteria.
                        <?php else: ?>
                            Get started by adding your first zone.
                        <?php endif; ?>
                    </p>
                    
                    <div class="d-inline-flex gap-2">
                        <a href="?page=zone_add" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>
                            Add Your First Zone
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <?php
                                    // Helper to build sortable header links
                                    function renderZoneHeader($label, $column, $currentSort, $currentOrder, $baseQuery, $pageNum) {
                                        $nextOrder = ($currentSort === $column && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
                                        $icon = '';
                                        if ($currentSort === $column) {
                                            $icon = ' <i class="bi bi-chevron-' . ($currentOrder === 'ASC' ? 'up' : 'down') . '"></i>';
                                        }
                                        $url = '?' . $baseQuery . '&page_num=' . $pageNum . '&sort_by=' . urlencode($column) . '&sort_order=' . $nextOrder;
                                        echo '<a href="' . htmlspecialchars($url) . '" class="text-decoration-none text-dark">' . htmlspecialchars($label) . $icon . '</a>';
                                    }
                                ?>
                                <th><?php renderZoneHeader('Zone Name','name',$sortBy,$sortOrder,$paginationBase,$page_num); ?></th>
                                <th><?php renderZoneHeader('Zone Type','zone_type',$sortBy,$sortOrder,$paginationBase,$page_num); ?></th>
                                <th><?php renderZoneHeader('Records','record_count',$sortBy,$sortOrder,$paginationBase,$page_num); ?></th>
                                <th><?php renderZoneHeader('Type','type',$sortBy,$sortOrder,$paginationBase,$page_num); ?></th>
                                <?php if ($isSuperAdmin || count($userTenants) > 1): ?>
                                <th><?php renderZoneHeader('Tenant','tenant_name',$sortBy,$sortOrder,$paginationBase,$page_num); ?></th>
                                <?php endif; ?>
                                <th><?php renderZoneHeader('DNSSEC','dnssec_enabled',$sortBy,$sortOrder,$paginationBase,$page_num); ?></th>
                                <th><?php renderZoneHeader('Created','domain_created',$sortBy,$sortOrder,$paginationBase,$page_num); ?></th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($domains as $domainRow): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($domainRow['name']); ?></strong>
                                        <?php if ($domainRow['account']): ?>
                                            <br>
                                            <small class="text-muted">Account: <?php echo htmlspecialchars($domainRow['account']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $zoneType = $domainRow['zone_type'] ?? 'forward';
                                        $badgeClass = $zoneType === 'reverse' ? 'bg-warning' : 'bg-info';
                                        $zoneTypeLabel = ucfirst($zoneType);
                                        ?>
                                        <span class="badge <?php echo $badgeClass; ?>">
                                            <?php echo $zoneTypeLabel; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo number_format($domainRow['record_count']); ?> records
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo htmlspecialchars($domainRow['type']); ?>
                                        </span>
                                    </td>
                                    <?php if ($isSuperAdmin || count($userTenants) > 1): ?>
                                    <td>
                                        <?php if ($domainRow['tenant_name']): ?>
                                            <span class="badge bg-light text-dark">
                                                <?php echo htmlspecialchars($domainRow['tenant_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
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
                                            <span class="badge bg-secondary">
                                                <i class="bi bi-shield-x me-1"></i>
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
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a href="?page=records&domain_id=<?php echo $domainRow['id']; ?>" 
                                               class="btn btn-outline-primary" title="Manage Records">
                                                <i class="bi bi-list-ul"></i>
                                                Records
                                            </a>
                                            <a href="?page=zone_dnssec&domain_id=<?php echo $domainRow['id']; ?>" 
                                               class="btn btn-outline-success" title="Manage DNSSEC">
                                                <i class="bi bi-shield-lock"></i>
                                                DNSSEC
                                            </a>
                                            <a href="?page=zone_ddns&domain_id=<?php echo $domainRow['id']; ?>" 
                                               class="btn btn-outline-info" title="Manage Dynamic DNS">
                                                <i class="bi bi-arrow-repeat"></i>
                                                DDNS
                                            </a>
                                            <a href="?page=zone_edit&id=<?php echo $domainRow['id']; ?>" 
                                               class="btn btn-outline-secondary" title="Edit Zone">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <small class="text-muted mb-0"><?php echo formatCountRange($startRecord, $endRecord, $totalCount, 'zones'); ?></small>
                        <?php
                            renderPaginationNav([
                                'current' => $page_num,
                                'total_pages' => $totalPages,
                                'page_param' => 'page_num',
                                'base_params' => $zoneBaseParams
                            ]);
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
