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
 * PDNS Console - Add DNS Record
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

// Pre-fill type if specified in URL
$preSelectedType = $_GET['type'] ?? '';
if (!empty($preSelectedType) && !isset($supportedTypes[$preSelectedType])) {
    $preSelectedType = '';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $domainInfo) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please retry.';
    } else {
    $recordName = trim($_POST['record_name'] ?? '');
    $recordType = trim($_POST['record_type'] ?? '');
    $recordContent = trim($_POST['record_content'] ?? '');
    $recordTTL = intval($_POST['record_ttl'] ?? 3600);
    $recordPrio = intval($_POST['record_prio'] ?? 0);
    
    try {
        $recordId = $records->createRecord(
            $domainId,
            $recordName,
            $recordType,
            $recordContent,
            $recordTTL,
            $recordPrio,
            $tenantId
        );
        
        // Log the action
        $db = Database::getInstance();
                $db->execute(
            "INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address) 
             VALUES (?, 'record_create', 'records', ?, ?, ?)",
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
        
        $success = 'DNS record created successfully!';
        
        // Clear form data after success
        $recordName = '';
        $recordType = '';
        $recordContent = '';
        $recordTTL = 3600;
        $recordPrio = 0;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
    }
}

// Page title
$pageTitle = 'Add DNS Record' . ($domainInfo ? ' - ' . $domainInfo['name'] : '');
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="container-fluid py-4">
    <?php if ($domainInfo): ?>
        <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
            renderBreadcrumb([
                ['label' => 'Zones', 'url' => '?page=zone_manage' . ($domainInfo ? '&tenant_id=' . urlencode($domainInfo['tenant_id'] ?? '') : '')],
                ['label' => 'Records: ' . $domainInfo['name'], 'url' => '?page=records&domain_id=' . $domainId],
                ['label' => 'Add Record']
            ], $isSuperAdmin);
        ?>
    <?php endif; ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <!-- Page Header -->
            <div class="mb-4">
                <div class="d-flex align-items-center mb-2">
                    <h2 class="h4 mb-0">
                        <i class="bi bi-plus-circle me-2 text-success"></i>
                        Add DNS Record
                    </h2>
                </div>
                <?php if ($domainInfo): ?>
                    <p class="text-muted mb-0">
                        Adding record to <strong><?php echo htmlspecialchars($domainInfo['name']); ?></strong>
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
                        <a href="?page=zones" class="btn btn-primary">
                            <i class="bi bi-arrow-left me-1"></i>
                            Back to Zones
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
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($domainInfo): ?>
                <!-- Add Record Form -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-plus-circle me-2"></i>
                            New DNS Record
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="addRecordForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="record_name" class="form-label">
                                            Record Name <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" class="form-control" id="record_name" name="record_name" 
                                               value="<?php echo htmlspecialchars($recordName ?? ''); ?>" 
                                               placeholder="@ or subdomain" required>
                                        <div class="form-text">
                                            Use "@" for root domain, or enter subdomain (e.g., "www", "mail")
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="record_type" class="form-label">
                                            Record Type <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="record_type" name="record_type" required>
                                            <option value="">Select Record Type</option>
                                            <?php foreach ($supportedTypes as $type => $info): ?>
                                                <option value="<?php echo $type; ?>" 
                                                        <?php echo ($preSelectedType === $type || ($recordType ?? '') === $type) ? 'selected' : ''; ?>
                                                        data-pattern="<?php echo htmlspecialchars($info['pattern']); ?>"
                                                        data-regex-safe="<?php echo htmlspecialchars(trim($info['pattern'],'/')); ?>"
                                                        data-example="<?php echo htmlspecialchars($info['example']); ?>"
                                                        data-description="<?php echo htmlspecialchars($info['description']); ?>">
                                                    <?php echo $info['name']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="record_content" class="form-label">
                                    Record Content <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="record_content" name="record_content" 
                                       value="<?php echo htmlspecialchars($recordContent ?? ''); ?>" 
                                       placeholder="Enter record content" required>
                                <div class="form-text" id="content_help">
                                    Select a record type to see format requirements and examples
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="record_ttl" class="form-label">TTL (seconds)</label>
                                        <select class="form-select" id="record_ttl" name="record_ttl">
                                            <?php
                                            $ttlOptions = [
                                                300 => '5 minutes (300)',
                                                600 => '10 minutes (600)',
                                                1800 => '30 minutes (1800)',
                                                3600 => '1 hour (3600)',
                                                7200 => '2 hours (7200)',
                                                14400 => '4 hours (14400)',
                                                43200 => '12 hours (43200)',
                                                86400 => '1 day (86400)',
                                                172800 => '2 days (172800)',
                                                604800 => '1 week (604800)'
                                            ];
                                            foreach ($ttlOptions as $value => $label):
                                            ?>
                                                <option value="<?php echo $value; ?>" 
                                                        <?php echo ($recordTTL ?? 3600) == $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Time-to-live for DNS caching</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="record_prio" class="form-label">Priority</label>
                                        <input type="number" class="form-control" id="record_prio" name="record_prio" 
                                               value="<?php echo intval($recordPrio ?? 0); ?>" 
                                               min="0" max="65535" placeholder="0">
                                        <div class="form-text" id="priority_help">
                                            Used for MX and SRV records (lower = higher priority)
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="?page=records&domain_id=<?php echo $domainId; ?>" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left me-1"></i>
                                    Cancel
                                </a>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-plus-circle me-1"></i>
                                    Create Record
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Record Type Information -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Record Type Guide
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach (array_slice($supportedTypes, 0, 4, true) as $type => $info): ?>
                                <div class="col-md-6 mb-3">
                                    <h6 class="text-primary"><?php echo $type; ?> Record</h6>
                                    <p class="small mb-1"><?php echo htmlspecialchars($info['description']); ?></p>
                                    <code class="small"><?php echo htmlspecialchars($info['example']); ?></code>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (count($supportedTypes) > 4): ?>
                            <details class="mt-3">
                                <summary class="btn btn-outline-primary btn-sm">Show More Record Types</summary>
                                <div class="row mt-3">
                                    <?php foreach (array_slice($supportedTypes, 4, null, true) as $type => $info): ?>
                                        <div class="col-md-6 mb-3">
                                            <h6 class="text-primary"><?php echo $type; ?> Record</h6>
                                            <p class="small mb-1"><?php echo htmlspecialchars($info['description']); ?></p>
                                            <code class="small"><?php echo htmlspecialchars($info['example']); ?></code>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const recordTypeSelect = document.getElementById('record_type');
    const contentInput = document.getElementById('record_content');
    const contentHelp = document.getElementById('content_help');
    const priorityInput = document.getElementById('record_prio');
    const priorityHelp = document.getElementById('priority_help');
    
    function updateFormFields() {
        const selectedOption = recordTypeSelect.options[recordTypeSelect.selectedIndex];
        
        if (selectedOption.value) {
            const example = selectedOption.getAttribute('data-example');
            const description = selectedOption.getAttribute('data-description');
            const type = selectedOption.value;
            
            // Update content placeholder and help text
            contentInput.placeholder = example;
            contentHelp.innerHTML = `<strong>${type}:</strong> ${description}<br><strong>Example:</strong> <code>${example}</code>`;
            
            // Show/hide priority field based on record type
            if (type === 'MX' || type === 'SRV') {
                priorityInput.parentElement.style.display = 'block';
                priorityHelp.textContent = type === 'MX' ? 
                    'Mail server priority (lower = higher priority)' : 
                    'Service priority (lower = higher priority)';
                priorityInput.required = true;
            } else {
                priorityInput.parentElement.style.display = 'none';
                priorityInput.required = false;
                priorityInput.value = 0;
            }
        } else {
            contentInput.placeholder = 'Enter record content';
            contentHelp.textContent = 'Select a record type to see format requirements and examples';
            priorityInput.parentElement.style.display = 'none';
            priorityInput.required = false;
        }
    }
    
    // Update fields when record type changes
    recordTypeSelect.addEventListener('change', updateFormFields);
    
    // Initialize fields on page load
    updateFormFields();
    
    // Form validation
    document.getElementById('addRecordForm').addEventListener('submit', function(e) {
        const recordType = recordTypeSelect.value;
        const content = contentInput.value.trim();
        
        if (!recordType || !content) {
            e.preventDefault();
            alert('Please fill in all required fields.');
            return;
        }
        
        // Basic validation based on record type
        const selectedOption = recordTypeSelect.options[recordTypeSelect.selectedIndex];
    const pattern = selectedOption.getAttribute('data-regex-safe');
        
        if (pattern) {
            let contentForValidation = content;
            if (recordType === 'MX') {
                contentForValidation = contentForValidation.replace(/^([0-9]+)\s+/, '');
            } else if (recordType === 'SRV') {
                const prioVal = document.getElementById('record_prio').value.trim();
                const srvParts = contentForValidation.split(/\s+/);
                if (srvParts.length === 4 && /^\d+$/.test(srvParts[0]) && srvParts[0] === prioVal) {
                    srvParts.shift();
                    contentForValidation = srvParts.join(' ');
                }
            }
            let anchored = pattern;
            if (anchored.includes('|')) {
                anchored = anchored.split('|').map(p => { p = p.replace(/^\^/, '').replace(/\$$/, ''); return '^(?:' + p + ')$'; }).join('|');
                anchored = '(?:' + anchored + ')';
            } else {
                if (!anchored.startsWith('^')) anchored = '^' + anchored;
                if (!anchored.endsWith('$')) anchored = anchored + '$';
            }
            let pass = true;
            if (recordType === 'A') {
                pass = /^((25[0-5]|2[0-4]\d|1?\d?\d)(\.|$)){4}$/.test(contentForValidation);
            } else if (recordType === 'AAAA') {
                pass = /^[0-9A-Fa-f:]+$/.test(contentForValidation) && contentForValidation.includes(':');
            }
            if (pass) {
                const regex = new RegExp(anchored);
                pass = regex.test(contentForValidation);
            }
            if (!pass) {
                console.warn('Add Record validation failed (client-side)', {recordType, pattern: anchored, original: content, normalized: contentForValidation});
                // Instead of blocking submission outright, rely on server-side validation.
                // Comment out the next 4 lines to restore hard blocking behavior.
                // e.preventDefault();
                // alert(`Invalid content format for ${recordType} record. Please check the example format.`);
                // return;
            }
        }
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
