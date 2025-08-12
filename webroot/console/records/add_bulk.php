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
 * PDNS Console - Bulk Add DNS Records
 */

// Get current user and domain info
// Get classes (currentUser is already set by index.php)
$user = new User();
$records = new Records();
$domain = new Domain();

// Check if user is super admin
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

// Get domain ID
$domainId = intval($_GET['domain_id'] ?? 0);
if (empty($domainId)) {
    header('Location: ?page=zones');
    exit;
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
        $error = 'Zone not found or access denied.';
    }
} catch (Exception $e) {
    $error = 'Error loading zone: ' . $e->getMessage();
}

// Get zone type from domain info
$zoneType = $domainInfo['zone_type'] ?? 'forward';

// Get supported record types filtered by zone type
$supportedTypes = $records->getSupportedRecordTypes($zoneType);

// Handle form submission
$createdRecords = [];
$failedRecords = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $domainInfo && isset($_POST['records'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please retry.';
    } else {
    $recordsData = $_POST['records'];
    $db = Database::getInstance();
    
    foreach ($recordsData as $index => $recordData) {
        // Skip empty rows
        if (empty($recordData['name']) && empty($recordData['type']) && empty($recordData['content'])) {
            continue;
        }
        
        $recordName = trim($recordData['name'] ?? '');
        $recordType = trim($recordData['type'] ?? '');
        $recordContent = trim($recordData['content'] ?? '');
        $recordTTL = intval($recordData['ttl'] ?? 3600);
        $recordPrio = intval($recordData['prio'] ?? 0);
        
        try {
            // Validate required fields
            if (empty($recordName) || empty($recordType) || empty($recordContent)) {
                throw new Exception('Missing required fields');
            }
            
            $recordId = $records->createRecord(
                $domainId,
                $recordName,
                $recordType,
                $recordContent,
                $recordTTL,
                $recordPrio,
                $tenantId
            );
            
            $createdRecords[] = [
                'index' => $index + 1,
                'name' => $recordName,
                'type' => $recordType,
                'content' => $recordContent,
                'ttl' => $recordTTL,
                'prio' => $recordPrio,
                'id' => $recordId
            ];
            
            // Log the action
            $db->execute(
                "INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address) 
                 VALUES (?, 'record_bulk_create', 'records', ?, ?, ?)",
                [
                    $currentUser['id'],
                    $recordId,
                    json_encode([
                        'domain_id' => $domainId,
                        'name' => $recordName,
                        'type' => $recordType,
                        'content' => $recordContent,
                        'ttl' => $recordTTL,
                        'prio' => $recordPrio
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]
            );
            
        } catch (Exception $e) {
            $failedRecords[] = [
                'index' => $index + 1,
                'name' => $recordName,
                'type' => $recordType,
                'content' => $recordContent,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Set success/error messages
    if (!empty($createdRecords)) {
        $successCount = count($createdRecords);
        $success = "$successCount DNS record" . ($successCount > 1 ? 's' : '') . " created successfully!";
    }
    
    if (!empty($failedRecords)) {
        $failedCount = count($failedRecords);
        $bulkError = "$failedCount record" . ($failedCount > 1 ? 's' : '') . " failed to create. See details below.";
    }
    }
}

// Page title
$pageTitle = 'Bulk Add DNS Records' . ($domainInfo ? ' - ' . $domainInfo['name'] : '');
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="container-fluid py-4">
    <?php if ($domainInfo): ?>
        <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
            renderBreadcrumb([
                ['label' => 'Zones', 'url' => '?page=zone_manage' . ($domainInfo ? '&tenant_id=' . urlencode($domainInfo['tenant_id'] ?? '') : '')],
                ['label' => 'Records: ' . $domainInfo['name'], 'url' => '?page=records&domain_id=' . $domainId],
                ['label' => 'Bulk Add']
            ], $isSuperAdmin);
        ?>
    <?php endif; ?>
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <!-- Page Header -->
            <div class="mb-4">
                <h2 class="h4 mb-2">
                    <i class="bi bi-plus-square me-2 text-success"></i>
                    Bulk Add DNS Records
                </h2>
                <?php if ($domainInfo): ?>
                    <p class="text-muted mb-0">
                        Adding multiple records to <strong><?php echo htmlspecialchars($domainInfo['name']); ?></strong>
                        <?php if ($domainInfo['tenant_name']): ?>
                            <span class="badge bg-primary ms-2"><?php echo htmlspecialchars($domainInfo['tenant_name']); ?></span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
                
                <?php if (!$domainInfo): ?>
                    <div class="text-center">
                        <a href="?page=zone_manage" class="btn btn-primary">
                            Return to Zones
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <div class="mt-2">
                        <a href="?page=records&domain_id=<?php echo $domainId; ?>" class="btn btn-success btn-sm">
                            <i class="bi bi-list-ul me-1"></i>
                            View All Records
                        </a>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="clearForm()">
                            <i class="bi bi-plus-circle me-1"></i>
                            Add More Records
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($bulkError)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($bulkError); ?>
                </div>
            <?php endif; ?>

            <?php if ($domainInfo): ?>
                <!-- Results Summary -->
                <?php if (!empty($createdRecords) || !empty($failedRecords)): ?>
                    <div class="row mb-4">
                        <?php if (!empty($createdRecords)): ?>
                            <div class="col-md-6">
                                <div class="card border-success">
                                    <div class="card-header bg-success text-white">
                                        <h6 class="card-title mb-0">
                                            <i class="bi bi-check-circle me-2"></i>
                                            Successfully Created (<?php echo count($createdRecords); ?>)
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <?php foreach ($createdRecords as $record): ?>
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($record['name']); ?></strong>
                                                    <span class="badge bg-secondary ms-2"><?php echo $record['type']; ?></span>
                                                </div>
                                                <small class="text-muted">Row <?php echo $record['index']; ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($failedRecords)): ?>
                            <div class="col-md-6">
                                <div class="card border-danger">
                                    <div class="card-header bg-danger text-white">
                                        <h6 class="card-title mb-0">
                                            <i class="bi bi-x-circle me-2"></i>
                                            Failed to Create (<?php echo count($failedRecords); ?>)
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <?php foreach ($failedRecords as $record): ?>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($record['name']); ?></strong>
                                                        <span class="badge bg-secondary ms-2"><?php echo $record['type']; ?></span>
                                                    </div>
                                                    <small class="text-muted">Row <?php echo $record['index']; ?></small>
                                                </div>
                                                <small class="text-danger"><?php echo htmlspecialchars($record['error']); ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Bulk Add Form -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-plus-square me-2"></i>
                            Add Multiple DNS Records
                        </h5>
                        <div>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addRow()">
                                <i class="bi bi-plus-circle me-1"></i>
                                Add Row
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearForm()">
                                <i class="bi bi-arrow-clockwise me-1"></i>
                                Clear All
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="bulkAddForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="recordsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 25%;">Name <span class="text-danger">*</span></th>
                                            <th style="width: 15%;">Type <span class="text-danger">*</span></th>
                                            <th style="width: 35%;">Content <span class="text-danger">*</span></th>
                                            <th style="width: 15%;">TTL</th>
                                            <th style="width: 8%;">Priority</th>
                                            <th style="width: 2%;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="recordsTableBody">
                                        <!-- Initial rows will be added by JavaScript -->
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="d-flex justify-content-between mt-3">
                                <a href="?page=records&domain_id=<?php echo $domainId; ?>" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left me-1"></i>
                                    Cancel
                                </a>
                                <div>
                                    <button type="button" class="btn btn-outline-primary me-2" onclick="addRow()">
                                        <i class="bi bi-plus-circle me-1"></i>
                                        Add Another Row
                                    </button>
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Create All Records
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Quick Add Templates -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-lightning me-2"></i>
                            Quick Templates
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-2" 
                                        onclick="addTemplate('web')">
                                    <i class="bi bi-globe me-1"></i>
                                    Web Server
                                </button>
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-2" 
                                        onclick="addTemplate('mail')">
                                    <i class="bi bi-envelope me-1"></i>
                                    Mail Server
                                </button>
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-2" 
                                        onclick="addTemplate('subdomains')">
                                    <i class="bi bi-diagram-3 me-1"></i>
                                    Subdomains
                                </button>
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-2" 
                                        onclick="addTemplate('txt')">
                                    <i class="bi bi-card-text me-1"></i>
                                    TXT Records
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
let rowCounter = 0;
const supportedTypes = <?php echo json_encode($supportedTypes); ?>;

// TTL options
const ttlOptions = {
    300: '5 min',
    600: '10 min', 
    1800: '30 min',
    3600: '1 hour',
    7200: '2 hours',
    14400: '4 hours',
    43200: '12 hours',
    86400: '1 day'
};

function createRow(name = '', type = '', content = '', ttl = 3600, prio = 0) {
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <input type="text" class="form-control form-control-sm" 
                   name="records[${rowCounter}][name]" 
                   value="${name}" 
                   placeholder="@ or subdomain">
        </td>
        <td>
            <select class="form-select form-select-sm" 
                    name="records[${rowCounter}][type]" 
                    onchange="updateRowHelp(this)">
                <option value="">Type</option>
                ${Object.keys(supportedTypes).map(t => 
                    `<option value="${t}" ${type === t ? 'selected' : ''}>${t}</option>`
                ).join('')}
            </select>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" 
                   name="records[${rowCounter}][content]" 
                   value="${content}" 
                   placeholder="Record content">
        </td>
        <td>
            <select class="form-select form-select-sm" name="records[${rowCounter}][ttl]">
                ${Object.entries(ttlOptions).map(([value, label]) => 
                    `<option value="${value}" ${ttl == value ? 'selected' : ''}>${label}</option>`
                ).join('')}
            </select>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" 
                   name="records[${rowCounter}][prio]" 
                   value="${prio}" 
                   min="0" max="65535" 
                   placeholder="0">
        </td>
        <td>
            <button type="button" class="btn btn-outline-danger btn-sm" 
                    onclick="removeRow(this)" title="Remove Row">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    
    rowCounter++;
    return row;
}

function addRow() {
    const tbody = document.getElementById('recordsTableBody');
    tbody.appendChild(createRow());
}

function removeRow(button) {
    const row = button.closest('tr');
    row.remove();
}

function clearForm() {
    const tbody = document.getElementById('recordsTableBody');
    tbody.innerHTML = '';
    rowCounter = 0;
    
    // Add 3 empty rows
    for (let i = 0; i < 3; i++) {
        addRow();
    }
}

function updateRowHelp(select) {
    const type = select.value;
    const row = select.closest('tr');
    const contentInput = row.querySelector('input[name*="[content]"]');
    
    if (type && supportedTypes[type]) {
        contentInput.placeholder = supportedTypes[type].example;
        contentInput.title = supportedTypes[type].description;
    } else {
        contentInput.placeholder = 'Record content';
        contentInput.title = '';
    }
}

function addTemplate(templateType) {
    const tbody = document.getElementById('recordsTableBody');
    
    const templates = {
        web: [
            ['@', 'A', '192.168.1.100', 3600, 0],
            ['www', 'A', '192.168.1.100', 3600, 0],
            ['ftp', 'CNAME', 'www.<?php echo htmlspecialchars($domainInfo['name'] ?? 'example.com'); ?>.', 3600, 0]
        ],
        mail: [
            ['@', 'MX', 'mail.<?php echo htmlspecialchars($domainInfo['name'] ?? 'example.com'); ?>.', 3600, 10],
            ['mail', 'A', '192.168.1.110', 3600, 0],
            ['@', 'TXT', 'v=spf1 mx -all', 3600, 0]
        ],
        subdomains: [
            ['api', 'A', '192.168.1.120', 3600, 0],
            ['cdn', 'A', '192.168.1.130', 3600, 0],
            ['admin', 'A', '192.168.1.140', 3600, 0]
        ],
        txt: [
            ['@', 'TXT', 'v=spf1 mx -all', 3600, 0],
            ['_dmarc', 'TXT', 'v=DMARC1; p=quarantine; rua=mailto:dmarc@<?php echo htmlspecialchars($domainInfo['name'] ?? 'example.com'); ?>', 3600, 0],
            ['google._domainkey', 'TXT', 'v=DKIM1; k=rsa; p=YOUR_PUBLIC_KEY_HERE', 3600, 0]
        ]
    };
    
    if (templates[templateType]) {
        templates[templateType].forEach(record => {
            tbody.appendChild(createRow(...record));
        });
    }
}

// Initialize form with 3 empty rows
document.addEventListener('DOMContentLoaded', function() {
    clearForm();
});

// Form validation
document.getElementById('bulkAddForm').addEventListener('submit', function(e) {
    const rows = document.querySelectorAll('#recordsTableBody tr');
    let hasValidRow = false;
    
    rows.forEach(row => {
        const name = row.querySelector('input[name*="[name]"]').value.trim();
        const type = row.querySelector('select[name*="[type]"]').value;
        const content = row.querySelector('input[name*="[content]"]').value.trim();
        
        if (name && type && content) {
            hasValidRow = true;
        }
    });
    
    if (!hasValidRow) {
        e.preventDefault();
        alert('Please fill in at least one complete record (name, type, and content).');
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
