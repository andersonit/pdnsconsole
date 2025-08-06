<?php
/**
 * PDNS Console - Edit DNS Record
 */

// Get current user and record info
// Get classes (currentUser is already set by index.php)
$user = new User();
$records = new Records();
$domain = new Domain();

// Check if user is super admin
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

// Get domain and record IDs
$domainId = intval($_GET['domain_id'] ?? 0);
$recordId = intval($_GET['id'] ?? 0);

if (empty($domainId) || empty($recordId)) {
    header('Location: ?page=domains');
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
        $error = 'Domain not found or access denied.';
    }
} catch (Exception $e) {
    $error = 'Error loading domain: ' . $e->getMessage();
}

// Get record info
$recordInfo = null;
if ($domainInfo) {
    try {
        $recordInfo = $records->getRecordById($recordId, $tenantId);
        if (!$recordInfo || $recordInfo['domain_id'] != $domainId) {
            $error = 'Record not found or access denied.';
        }
    } catch (Exception $e) {
        $error = 'Error loading record: ' . $e->getMessage();
    }
}

// Get zone type from domain info
$zoneType = $domainInfo['zone_type'] ?? 'forward';

// Get supported record types filtered by zone type
$supportedTypes = $records->getSupportedRecordTypes($zoneType);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $recordInfo) {
    $recordName = trim($_POST['record_name'] ?? '');
    $recordType = trim($_POST['record_type'] ?? '');
    $recordContent = trim($_POST['record_content'] ?? '');
    $recordTTL = intval($_POST['record_ttl'] ?? 3600);
    $recordPrio = intval($_POST['record_prio'] ?? 0);
    
    try {
        // Store old values for audit log
        $oldValues = [
            'name' => $recordInfo['name'],
            'type' => $recordInfo['type'],
            'content' => $recordInfo['content'],
            'ttl' => $recordInfo['ttl'],
            'prio' => $recordInfo['prio']
        ];
        
        $records->updateRecord(
            $recordId,
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
            "INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address) 
             VALUES (?, 'record_update', 'records', ?, ?, ?, ?)",
            [
                $currentUser['id'],
                $recordId,
                json_encode($oldValues),
                json_encode([
                    'name' => $recordName,
                    'type' => $recordType,
                    'content' => $recordContent,
                    'ttl' => $recordTTL,
                    'prio' => $recordPrio
                ]),
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]
        );
        
        $success = 'DNS record updated successfully!';
        
        // Refresh record info
        $recordInfo = $records->getRecordById($recordId, $tenantId);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Page title
$pageTitle = 'Edit DNS Record' . ($domainInfo ? ' - ' . $domainInfo['name'] : '');
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <!-- Page Header -->
            <div class="mb-4">
                <div class="d-flex align-items-center mb-2">
                    <a href="?page=records&domain_id=<?php echo $domainId; ?>" class="btn btn-outline-secondary btn-sm me-3">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                    <h2 class="h4 mb-0">
                        <i class="bi bi-pencil me-2 text-primary"></i>
                        Edit DNS Record
                    </h2>
                </div>
                <?php if ($domainInfo && $recordInfo): ?>
                    <p class="text-muted mb-0">
                        Editing <strong class="text-primary"><?php echo htmlspecialchars($recordInfo['type']); ?></strong> record 
                        for <strong><?php echo htmlspecialchars($domainInfo['name']); ?></strong>
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
                
                <?php if (!$recordInfo): ?>
                    <div class="text-center">
                        <a href="?page=records&domain_id=<?php echo $domainId; ?>" class="btn btn-primary">
                            <i class="bi bi-arrow-left me-1"></i>
                            Back to Records
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if ($recordInfo): ?>
                <!-- Edit Record Form -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-pencil me-2"></i>
                            Edit DNS Record
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (in_array($recordInfo['type'], ['SOA', 'NS']) && $recordInfo['name'] === $domainInfo['name']): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Protected Record:</strong> This is a primary SOA or NS record that cannot be modified through this interface.
                            </div>
                        <?php else: ?>
                            <form method="POST" id="editRecordForm">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="record_name" class="form-label">
                                                Record Name <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" id="record_name" name="record_name" 
                                                   value="<?php echo htmlspecialchars($recordInfo['name']); ?>" 
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
                                                <?php foreach ($supportedTypes as $type => $info): ?>
                                                    <option value="<?php echo $type; ?>" 
                                                            <?php echo $recordInfo['type'] === $type ? 'selected' : ''; ?>
                                                            data-pattern="<?php echo htmlspecialchars($info['pattern']); ?>"
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
                                           value="<?php echo htmlspecialchars($recordInfo['content']); ?>" 
                                           placeholder="Enter record content" required>
                                    <div class="form-text" id="content_help">
                                        Current record type format and validation information
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
                                                            <?php echo $recordInfo['ttl'] == $value ? 'selected' : ''; ?>>
                                                        <?php echo $label; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                
                                                <!-- Add current TTL if it's not in the standard options -->
                                                <?php if (!in_array($recordInfo['ttl'], array_keys($ttlOptions))): ?>
                                                    <option value="<?php echo $recordInfo['ttl']; ?>" selected>
                                                        Custom (<?php echo $recordInfo['ttl']; ?>)
                                                    </option>
                                                <?php endif; ?>
                                            </select>
                                            <div class="form-text">Time-to-live for DNS caching</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="record_prio" class="form-label">Priority</label>
                                            <input type="number" class="form-control" id="record_prio" name="record_prio" 
                                                   value="<?php echo intval($recordInfo['prio']); ?>" 
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
                                    <div>
                                        <button type="button" class="btn btn-outline-danger me-2" 
                                                onclick="confirmDelete(<?php echo $recordInfo['id']; ?>, '<?php echo htmlspecialchars($recordInfo['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($recordInfo['type'], ENT_QUOTES); ?>')">
                                            <i class="bi bi-trash me-1"></i>
                                            Delete Record
                                        </button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Update Record
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Record Information -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Record Information
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <dl class="row mb-0">
                                    <dt class="col-sm-4">Record ID:</dt>
                                    <dd class="col-sm-8"><?php echo $recordInfo['id']; ?></dd>
                                    
                                    <dt class="col-sm-4">Domain:</dt>
                                    <dd class="col-sm-8"><?php echo htmlspecialchars($recordInfo['domain_name']); ?></dd>
                                    
                                    <dt class="col-sm-4">Type:</dt>
                                    <dd class="col-sm-8">
                                        <span class="badge bg-secondary">
                                            <?php echo htmlspecialchars($recordInfo['type']); ?>
                                        </span>
                                    </dd>
                                </dl>
                            </div>
                            <div class="col-md-6">
                                <dl class="row mb-0">
                                    <dt class="col-sm-4">Status:</dt>
                                    <dd class="col-sm-8">
                                        <span class="badge bg-success">Active</span>
                                    </dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
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
            
            // Update content help text
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
            }
        }
    }
    
    // Update fields when record type changes
    recordTypeSelect.addEventListener('change', updateFormFields);
    
    // Initialize fields on page load
    updateFormFields();
    
    // Form validation
    document.getElementById('editRecordForm').addEventListener('submit', function(e) {
        const recordType = recordTypeSelect.value;
        const content = contentInput.value.trim();
        
        if (!recordType || !content) {
            e.preventDefault();
            alert('Please fill in all required fields.');
            return;
        }
        
        // Basic validation based on record type
        const selectedOption = recordTypeSelect.options[recordTypeSelect.selectedIndex];
        const pattern = selectedOption.getAttribute('data-pattern');
        
        if (pattern) {
            const regex = new RegExp(pattern);
            if (!regex.test(content)) {
                e.preventDefault();
                alert(`Invalid content format for ${recordType} record. Please check the example format.`);
                return;
            }
        }
    });
});

function confirmDelete(recordId, recordName, recordType) {
    document.getElementById('deleteRecordId').value = recordId;
    document.getElementById('deleteRecordName').textContent = recordName;
    document.getElementById('deleteRecordType').textContent = recordType;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
