<?php
/**
 * PDNS Console
 * Copyright (c) 2025 Neowyze LLC
 *
 * Licensed under the Business Source License 1.0.
 * You may use this file in compliance with the license terms.
 *
 * License details: https://github.com/andersonit/pdnsconsole/blob/main/LICENSE.md
 */


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
                    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token($_POST['csrf_token'] ?? '')) {
                        $_SESSION['error'] = 'Security token mismatch. Delete aborted.';
                        header('Location: ?page=records&domain_id=' . $domainId);
                        exit;
                    }
                    include 'delete.php';
                    return;
        case 'comment':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                    $_SESSION['error'] = 'Security token mismatch.';
                    header('Location: ?page=records&domain_id=' . $domainId);
                    exit;
                }
                $recordId = intval($_POST['record_id'] ?? 0);
                $text = trim($_POST['comment'] ?? '');
                if ($recordId > 0) {
                    try {
                        $rc = new RecordComments();
                        if ($text === '') { $rc->clearComment($recordId, $currentUser['id']); }
                        else { $rc->setComment($recordId, $currentUser['id'], $currentUser['username'], $text); }
                        $_SESSION['success'] = 'Comment saved.';
                    } catch (Exception $e) {
                        $_SESSION['error'] = 'Comment error: ' . $e->getMessage();
                    }
                }
                header('Location: ?page=records&domain_id=' . $domainId);
                exit;
            }
            break;
        case 'bulk':
        case 'add_bulk':
            include 'add_bulk.php';
            return;
        case 'export':
            include 'export.php';
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

// Initialize pagination and filters (honor system default records_per_page setting)
$page = max(1, intval($_GET['p'] ?? 1));
// Fetch global default records per page (fall back to 25)
$db = Database::getInstance();
$rppSetting = $db->fetch("SELECT setting_value FROM global_settings WHERE setting_key = 'records_per_page'");
$defaultLimit = intval($rppSetting['setting_value'] ?? 25);
if (!in_array($defaultLimit, [10, 25, 50, 100])) {
    $defaultLimit = 25;
}
// Per-page persistence
$allowedLimits = [10,25,50,100];
if (isset($_GET['limit'])) {
    $providedLimit = intval($_GET['limit']);
    if (in_array($providedLimit, $allowedLimits)) {
        $limit = $providedLimit;
        $_SESSION['records_per_page'] = $limit; // persist user preference
    } else {
        $limit = $defaultLimit;
    }
} else {
    if (isset($_SESSION['records_per_page']) && in_array(intval($_SESSION['records_per_page']), $allowedLimits)) {
        $limit = intval($_SESSION['records_per_page']);
    } else {
        $limit = $defaultLimit;
    }
}
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
$dnssecEnabled = null; // will remain null if status cannot be determined
// Map of record_id => ['active'=>bool, 'count'=>int] for DDNS tokens
$ddnsMap = [];

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
        // Determine DNSSEC status using local database (count active cryptokeys like zones/manage.php)
        try {
            $db2 = Database::getInstance();
            $ckActive = $db2->fetch("SELECT COUNT(*) AS c FROM cryptokeys WHERE domain_id = ? AND active = 1", [$domainId]);
            $dnssecEnabled = ($ckActive['c'] ?? 0) > 0;
        } catch (Exception $eDnssecStatus) { $dnssecEnabled = null; }
        // Build DDNS map: for each record, whether any tokens exist and if any are active
        try {
            $ddnsRows = $db->fetchAll("SELECT record_id, MAX(is_active) AS is_active, COUNT(*) AS cnt FROM dynamic_dns_tokens WHERE domain_id = ? GROUP BY record_id", [$domainId]);
            foreach ($ddnsRows as $r) {
                $ddnsMap[(int)$r['record_id']] = [
                    'active' => ((int)($r['is_active'] ?? 0)) > 0,
                    'count' => (int)($r['cnt'] ?? 0)
                ];
            }
        } catch (Exception $eDdns) { /* table may not exist or no tokens; ignore */ }
    // New record comments integration
    $recordComments = new RecordComments();
    $commentCounts = $recordComments->getCountsForDomain($domainId);
    $latestComments = $recordComments->getLatestForDomain($domainId);
    } catch (Exception $e) {
        $error = 'Error loading records: ' . $e->getMessage();
    }
}

// Calculate pagination
$totalPages = ceil($totalRecords / $limit);

// Helper function for pagination URLs
function buildPaginationUrl($domainId, $pageNum, $search, $typeFilter, $sortBy, $sortOrder, $limit)
{
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
<?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php'; ?>

<?php
// Passive license integrity notice (non-blocking)
if (class_exists('LicenseManager')) {
    $lxStatus = LicenseManager::getStatus();
    if (isset($lxStatus['integrity']) && !$lxStatus['integrity']) {
        echo '<div class="alert alert-warning small py-2 mb-3"><i class="bi bi-shield-exclamation me-1"></i> License signature key integrity check failed. System operating in fallback mode.</div>';
    }
}
?>

<div class="container-fluid py-4">
    <!-- Breadcrumb -->
    <?php if ($domainInfo): ?>
        <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        renderBreadcrumb([
            ['label' => 'Zones', 'url' => '?page=zone_manage' . ($domainInfo ? '&tenant_id=' . urlencode($domainInfo['tenant_id'] ?? '') : '')],
            ['label' => 'Records: ' . $domainInfo['name']]
        ], $isSuperAdmin);
        ?>
    <?php endif; ?>
    <!-- Page Header -->
    <div class="mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start mb-2 gap-2">
            <div class="d-flex align-items-center">
                <h2 class="h4 mb-0">
                    <i class="bi bi-list-ul me-2 text-primary"></i>
                    DNS Records
                </h2>
            </div>
            <?php if ($domainInfo): ?>
                <!-- DNSSEC/DDNS buttons moved to card header below -->
            <?php endif; ?>
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
                            <h6 class="card-title d-flex align-items-center flex-wrap gap-2">
                                <span><i class="bi bi-bar-chart me-2"></i>Record Statistics</span>
                                <?php if ($dnssecEnabled !== null): ?>
                                    <a href="?page=zone_dnssec&domain_id=<?php echo $domainId; ?>" class="text-decoration-none" data-bs-toggle="tooltip" data-bs-title="Manage DNSSEC for this zone">
                                        <span class="badge <?php echo $dnssecEnabled ? 'bg-success' : 'bg-secondary'; ?>">
                                            <i class="bi <?php echo $dnssecEnabled ? 'bi-shield-check' : 'bi-shield-x'; ?> me-1"></i>
                                            <?php echo $dnssecEnabled ? 'DNSSEC Enabled' : 'DNSSEC Disabled'; ?>
                                        </span>
                                    </a>
                                <?php endif; ?>
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
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search records..." autocomplete="off">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="type" class="form-label">Record Type</label>
                        <select class="form-select" id="type" name="type">
                            <option value="">All Types</option>
                            <?php foreach ($supportedTypes as $type => $info): ?>
                                <option value="<?php echo $type; ?>" <?php echo $typeFilter === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search me-1"></i>Search
                        </button>
                        <a href="?page=records&domain_id=<?php echo $domainId; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise me-1"></i>Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Records Table -->
        <div class="card">
            <div class="card-header py-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex flex-column">
                        <h5 class="card-title mb-0 d-flex align-items-center flex-wrap gap-2">
                            <i class="bi bi-list-ul me-2"></i>
                            DNS Records
                            <?php if ($totalRecords > 0): ?><span class="badge bg-secondary ms-2"><?php echo $totalRecords; ?></span><?php endif; ?>
                            <!-- Moved DNSSEC and DDNS to the left of header after title -->
                            <a href="?page=zone_dnssec&domain_id=<?php echo $domainId; ?>" class="btn btn-outline-success btn-sm ms-2" data-bs-toggle="tooltip" data-bs-placement="top" title="Manage DNSSEC for this zone">
                                <i class="bi bi-shield-lock me-1"></i> DNSSEC
                            </a>
                            <a href="?page=zone_ddns&domain_id=<?php echo $domainId; ?>" class="btn btn-outline-info btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Configure Dynamic DNS">
                                <i class="bi bi-arrow-repeat me-1"></i> DDNS
                            </a>
                        </h5>
                        <?php if ($totalRecords > 0): ?><small class="text-muted mb-0"><?php echo formatCountRange($offset + 1, min($offset + $limit, $totalRecords), $totalRecords, 'records'); ?></small><?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <a href="?page=records&domain_id=<?php echo $domainId; ?>&action=add" class="btn btn-primary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Add a new DNS record">
                            <i class="bi bi-plus-circle me-1"></i> Add New Record
                        </a>
                        <a href="?page=records&domain_id=<?php echo $domainId; ?>&action=bulk" class="btn btn-outline-success btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Add multiple records at once">
                            <i class="bi bi-plus-square me-1"></i> Add Bulk
                        </a>
                        <a href="?page=records&domain_id=<?php echo $domainId; ?>&action=export" class="btn btn-outline-secondary btn-sm" data-bs-toggle="tooltip" data-bs-placement="top" title="Export records to CSV">
                            <i class="bi bi-download me-1"></i> Export
                        </a>
                        <?php
                            $recordBaseParams = [
                                'page' => 'records',
                                'domain_id' => $domainId,
                                'search' => $search,
                                'type' => $typeFilter,
                                'sort' => $sortBy,
                                'order' => $sortOrder,
                                'limit' => $limit
                            ];
                            renderPerPageForm([
                                'base_params' => $recordBaseParams,
                                'page_param' => 'p',
                                'limit' => $limit,
                                'limit_options' => [10,25,50,100]
                            ]);
                            renderPaginationNav([
                                'current' => $page,
                                'total_pages' => $totalPages,
                                'page_param' => 'p',
                                'base_params' => $recordBaseParams
                            ]);
                        ?>
                    </div>
                </div>
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
                                <th>DDNS</th>
                                <th>Comments</th>
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
                                        <?php
                                        $ddns = $ddnsMap[$record['id']] ?? null;
                                        $isEligible = in_array(strtoupper($record['type']), ['A','AAAA']);
                                        if ($ddns && $isEligible) {
                                            $active = !empty($ddns['active']);
                                            $label = $active ? 'Active' : 'Configured';
                                            $cls = $active ? 'bg-primary text-light' : 'bg-secondary';
                                            echo '<a href="?page=zone_ddns&domain_id=' . $domainId . '" class="badge ' . $cls . '" data-bs-toggle="tooltip" data-bs-title="Manage Dynamic DNS tokens">' . $label . '</a>';
                                        } elseif ($isEligible) {
                                            echo '<a href="?page=zone_ddns&domain_id=' . $domainId . '" class="text-decoration-none small">—</a>';
                                        } else {
                                            echo '<span class="text-muted">—</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php $latest = $latestComments[$record['id']]['comment'] ?? ''; $has = $latest!==''; ?>
                                        <button type="button" class="btn <?php echo $has ? 'btn-outline-info' : 'btn-outline-secondary'; ?> btn-sm record-comment-btn"
                                            data-record-id="<?php echo $record['id']; ?>"
                                            data-comment="<?php echo htmlspecialchars($latest); ?>"
                                            data-bs-toggle="popover" data-bs-trigger="hover focus"
                                            data-bs-placement="top" data-bs-html="true" title="Comment"
                                            data-bs-content="<?php echo htmlspecialchars($has ? nl2br(htmlentities(mb_strimwidth($latest,0,400,'…'))) : '<em>No comment</em>'); ?>"
                                            onclick="openRecordCommentModal(<?php echo $record['id']; ?>)">
                                            <i class="bi bi-chat-dots"></i>
                                        </button>
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
                                                    onclick="openRecordDeleteModal(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($record['type'], ENT_QUOTES); ?>')"
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
                                <!-- Single Record Comment Modal -->
                                                <div class="modal fade" id="recordCommentModal" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title"><i class="bi bi-chat-dots me-2"></i>Record Comment</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                                <form method="POST" action="?page=records&domain_id=<?php echo $domainId; ?>&action=comment" id="recordCommentForm">
                                                                    <textarea name="comment" id="recordCommentText" class="form-control mb-2" rows="4" maxlength="2000" placeholder="Enter comment (leave blank to clear)"></textarea>
                                                                    <input type="hidden" name="record_id" id="recordCommentRecordId" value="">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                                                    <div class="d-flex justify-content-between">
                                                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearRecordCommentForm()"><i class="bi bi-x-circle me-1"></i>Clear</button>
                                                                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save</button>
                                                                    </div>
                                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <small class="text-muted mb-0"><?php echo formatCountRange($offset + 1, min($offset + $limit, $totalRecords), $totalRecords, 'records'); ?></small>
                        <?php
                            renderPaginationNav([
                                'current' => $page,
                                'total_pages' => $totalPages,
                                'page_param' => 'p',
                                'base_params' => $recordBaseParams
                            ]);
                        ?>
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
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
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
    function openRecordDeleteModal(recordId, recordName, recordType) {
        const modalEl = document.getElementById('deleteModal');
        // Reset form values each time
        document.getElementById('deleteRecordId').value = recordId;
        document.getElementById('deleteRecordName').textContent = recordName;
        document.getElementById('deleteRecordType').textContent = recordType;

        // Dispose any existing modal instance to avoid stale state
        const existing = bootstrap.Modal.getInstance(modalEl);
        if (existing) {
            existing.hide();
            // Allow Bootstrap internal cleanup
            setTimeout(() => {
                const again = new bootstrap.Modal(modalEl);
                again.show();
            }, 10);
        } else {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        }
    }

function openRecordCommentModal(recordId){
    const btn = document.querySelector('.record-comment-btn[data-record-id="'+recordId+'"]');
    document.getElementById('recordCommentRecordId').value = recordId;
    document.getElementById('recordCommentText').value = btn ? (btn.getAttribute('data-comment')||'') : '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('recordCommentModal')).show();
}
function clearRecordCommentForm(){
    document.getElementById('recordCommentText').value='';
}
function escapeHtml(str){ return str.replace(/[&<>'"]/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;","'":"&#39;","\"":"&quot;"}[c])); }

    // Initialize tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
        document.addEventListener('DOMContentLoaded', function(){
            // Initialize all popovers (record comment buttons have data-bs-toggle="popover")
            document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
                    new bootstrap.Popover(el);
            });
        });
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>