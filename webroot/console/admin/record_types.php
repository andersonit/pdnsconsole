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
 * PDNS Console - Custom Record Types Management
 * For Super Administrators Only
 */

// Get required classes
$user = new User();
$settings = new Settings();

// Check if user is super admin
if (!$user->isSuperAdmin($currentUser['id'])) {
    header('Location: /?page=dashboard');
    exit;
}

$pageTitle = 'Record Types Management';
$branding = $settings->getBranding();

// Get database instance
$db = Database::getInstance();

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $statusMessage = ['type' => 'danger', 'message' => 'Security token mismatch. Please retry.'];
    } else {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_type') {
        $typeId = (int)$_POST['type_id'];
        $isActive = $_POST['is_active'] === '1' ? 1 : 0;
        
        if ($db->execute("UPDATE custom_record_types SET is_active = ? WHERE id = ?", [$isActive, $typeId])) {
            $statusMessage = ['type' => 'success', 'message' => 'Record type status updated successfully.'];
        } else {
            $statusMessage = ['type' => 'danger', 'message' => 'Failed to update record type status.'];
        }
    }
    
    if ($action === 'add_type') {
        $typeName = strtoupper(trim($_POST['type_name']));
        $description = trim($_POST['description']);
        $validationPattern = trim($_POST['validation_pattern']);
        
        // Validate input
        if (empty($typeName) || !preg_match('/^[A-Z0-9]+$/', $typeName)) {
            $statusMessage = ['type' => 'danger', 'message' => 'Invalid record type name. Use only uppercase letters and numbers.'];
        } else {
            // Check if type already exists
            $existing = $db->fetch("SELECT id FROM custom_record_types WHERE type_name = ?", [$typeName]);
            if ($existing) {
                $statusMessage = ['type' => 'danger', 'message' => 'Record type already exists.'];
            } else {
                if ($db->insert("INSERT INTO custom_record_types (type_name, description, validation_pattern, is_active) VALUES (?, ?, ?, 1)", [$typeName, $description, $validationPattern])) {
                    $statusMessage = ['type' => 'success', 'message' => 'Record type added successfully.'];
                } else {
                    $statusMessage = ['type' => 'danger', 'message' => 'Failed to add record type.'];
                }
            }
        }
    }
    
    if ($action === 'update_type') {
        $typeId = (int)$_POST['type_id'];
        $description = trim($_POST['description']);
        $validationPattern = trim($_POST['validation_pattern']);
        
        if ($db->execute("UPDATE custom_record_types SET description = ?, validation_pattern = ? WHERE id = ?", [$description, $validationPattern, $typeId])) {
            $statusMessage = ['type' => 'success', 'message' => 'Record type updated successfully.'];
        } else {
            $statusMessage = ['type' => 'danger', 'message' => 'Failed to update record type.'];
        }
    }
    
    if ($action === 'delete_type') {
        $typeId = (int)$_POST['type_id'];
        
        // Check if type is being used
        $usage = $db->fetch("SELECT COUNT(*) as count FROM records WHERE type = (SELECT type_name FROM custom_record_types WHERE id = ?)", [$typeId]);
        if ($usage['count'] > 0) {
            $statusMessage = ['type' => 'danger', 'message' => 'Cannot delete record type: it is currently being used by ' . $usage['count'] . ' records.'];
        } else {
            if ($db->execute("DELETE FROM custom_record_types WHERE id = ?", [$typeId])) {
                $statusMessage = ['type' => 'success', 'message' => 'Record type deleted successfully.'];
            } else {
                $statusMessage = ['type' => 'danger', 'message' => 'Failed to delete record type.'];
            }
        }
    }
}
}

// Get all custom record types
$customTypes = $db->fetchAll("SELECT * FROM custom_record_types ORDER BY type_name");

// Standard PowerDNS record types that are always available
$standardTypes = [
    'A' => ['description' => 'IPv4 Address', 'required' => true],
    'AAAA' => ['description' => 'IPv6 Address', 'required' => true],
    'CNAME' => ['description' => 'Canonical Name', 'required' => true],
    'MX' => ['description' => 'Mail Exchange', 'required' => true],
    'TXT' => ['description' => 'Text Record', 'required' => true],
    'SRV' => ['description' => 'Service Record', 'required' => true],
    'NS' => ['description' => 'Name Server', 'required' => true],
    'SOA' => ['description' => 'Start of Authority', 'required' => true],
    'PTR' => ['description' => 'Pointer Record', 'required' => true]
];

// Available PowerDNS record types for addition
$availableTypes = [
    'AFSDB' => ['description' => 'Andrew File System Database', 'pattern' => '^[0-9]+ [a-zA-Z0-9.-]+$'],
    'ALIAS' => ['description' => 'CNAME-like for zone apex', 'pattern' => '^[a-zA-Z0-9.-]+$'],
    'APL' => ['description' => 'Address Prefix List', 'pattern' => '^[0-9!].*$'],
    'CAA' => ['description' => 'Certification Authority Authorization', 'pattern' => '^[0-9]+ [a-zA-Z]+ "[^"]*"$'],
    'CERT' => ['description' => 'Certificate Record', 'pattern' => '^[0-9]+ [0-9]+ [0-9]+ [A-Za-z0-9+/=]+$'],
    'CDNSKEY' => ['description' => 'Child DNSKEY', 'pattern' => '^[0-9]+ [0-9]+ [0-9]+ [A-Za-z0-9+/=]+$'],
    'CDS' => ['description' => 'Child DS', 'pattern' => '^[0-9]+ [0-9]+ [0-9]+ [A-Fa-f0-9]+$'],
    'CSYNC' => ['description' => 'Child-to-Parent Synchronization', 'pattern' => '^[0-9]+ [0-9]+ [0-9]+.*$'],
    'DNAME' => ['description' => 'Delegation Name', 'pattern' => '^[a-zA-Z0-9.-]+$'],
    'HINFO' => ['description' => 'Hardware Information', 'pattern' => '^[a-zA-Z0-9]+ [a-zA-Z0-9]+$'],
    'HTTPS' => ['description' => 'HTTPS Service Binding', 'pattern' => '^[0-9]+ [a-zA-Z0-9.-]+.*$'],
    'KEY' => ['description' => 'Key Record (obsolete)', 'pattern' => '^[0-9]+ [0-9]+ [0-9]+ [A-Za-z0-9+/=]+$'],
    'LOC' => ['description' => 'Location Information', 'pattern' => '^[0-9]+ [0-9]+ [0-9.]+ [NS] [0-9]+ [0-9]+ [0-9.]+ [EW] [0-9.]+m [0-9.]+m [0-9.]+m [0-9.]+m$'],
    'NAPTR' => ['description' => 'Naming Authority Pointer', 'pattern' => '^[0-9]+ [0-9]+ "[^"]*" "[^"]*" "[^"]*" [a-zA-Z0-9.-]*$'],
    'OPENPGPKEY' => ['description' => 'OpenPGP Key', 'pattern' => '^[A-Za-z0-9+/=]+$'],
    'RP' => ['description' => 'Responsible Person', 'pattern' => '^[a-zA-Z0-9.-]+ [a-zA-Z0-9.-]+$'],
    'SPF' => ['description' => 'Sender Policy Framework', 'pattern' => '^v=spf1.*$'],
    'SSHFP' => ['description' => 'SSH Key Fingerprint', 'pattern' => '^[0-9]+ [0-9]+ [A-Fa-f0-9]+$'],
    'SVCB' => ['description' => 'Service Binding', 'pattern' => '^[0-9]+ [a-zA-Z0-9.-]+.*$'],
    'TLSA' => ['description' => 'TLS Authentication', 'pattern' => '^[0-3] [0-1] [0-2] [A-Fa-f0-9]+$'],
    'SMIMEA' => ['description' => 'S/MIME Certificate', 'pattern' => '^[0-3] [0-1] [0-2] [A-Fa-f0-9]+$'],
    'URI' => ['description' => 'Uniform Resource Identifier', 'pattern' => '^[0-9]+ [0-9]+ "[^"]*"$'],
    'ZONEMD' => ['description' => 'Zone Message Digest', 'pattern' => '^[0-9]+ [0-9]+ [0-9]+ [A-Fa-f0-9]+$'],
    // Additional rarely used types
    'DHCID' => ['description' => 'DHCP Identifier', 'pattern' => '^[A-Za-z0-9+/=]+$'],
    'DLV' => ['description' => 'DNSSEC Lookaside Validation', 'pattern' => '^[0-9]+ [0-9]+ [0-9]+ [A-Fa-f0-9]+$'],
    'EUI48' => ['description' => '48-bit Extended Unique Identifier', 'pattern' => '^[A-Fa-f0-9]{2}-[A-Fa-f0-9]{2}-[A-Fa-f0-9]{2}-[A-Fa-f0-9]{2}-[A-Fa-f0-9]{2}-[A-Fa-f0-9]{2}$'],
    'EUI64' => ['description' => '64-bit Extended Unique Identifier', 'pattern' => '^[A-Fa-f0-9]{2}-[A-Fa-f0-9]{2}-[A-Fa-f0-9]{2}-[A-Fa-f0-9]{2}-[A-Fa-f0-9]{2}-[A-Fa-f0-9]{2}-[A-Fa-f0-9]{2}-[A-Fa-f0-9]{2}$'],
    'IPSECKEY' => ['description' => 'IPsec Key', 'pattern' => '^[0-9]+ [0-9]+ [0-9]+ [a-zA-Z0-9.-]+ [A-Za-z0-9+/=]*$'],
    'KX' => ['description' => 'Key Exchange', 'pattern' => '^[0-9]+ [a-zA-Z0-9.-]+$'],
    'L32' => ['description' => 'Locator 32-bit', 'pattern' => '^[0-9]+ [0-9.]+$'],
    'L64' => ['description' => 'Locator 64-bit', 'pattern' => '^[0-9]+ [A-Fa-f0-9:]+$'],
    'LP' => ['description' => 'Locator Pointer', 'pattern' => '^[0-9]+ [a-zA-Z0-9.-]+$'],
    'MINFO' => ['description' => 'Mailbox Information', 'pattern' => '^[a-zA-Z0-9.-]+ [a-zA-Z0-9.-]+$'],
    'MR' => ['description' => 'Mail Rename', 'pattern' => '^[a-zA-Z0-9.-]+$'],
    'NID' => ['description' => 'Node Identifier', 'pattern' => '^[0-9]+ [A-Fa-f0-9:]+$'],
    'RKEY' => ['description' => 'Resource Key', 'pattern' => '^[0-9]+ [0-9]+ [A-Za-z0-9+/=]+$']
];

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid mt-4">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        renderBreadcrumb([
            ['label' => 'Record Types']
        ], true);
    ?>
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="bi bi-list-columns me-2"></i>
                        Record Types Management
                    </h1>
                    <p class="text-muted mb-0">Configure available DNS record types for the system</p>
                </div>
                <div>
                    <a href="?page=admin_dashboard" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>
                        Back to Admin Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Messages -->
    <?php if (isset($statusMessage)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-<?php echo $statusMessage['type']; ?> alert-dismissible fade show">
                    <?php echo htmlspecialchars($statusMessage['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Record Types Overview -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded-3 p-3">
                                <i class="bi bi-check-square text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-1">Standard Types</h6>
                            <h3 class="mb-0"><?php echo count($standardTypes); ?></h3>
                            <small class="text-muted">Always available</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-success bg-opacity-10 rounded-3 p-3">
                                <i class="bi bi-plus-square text-success fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-1">Custom Types</h6>
                            <h3 class="mb-0"><?php echo count(array_filter($customTypes, fn($t) => $t['is_active'])); ?></h3>
                            <small class="text-muted">Currently enabled</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-info bg-opacity-10 rounded-3 p-3">
                                <i class="bi bi-collection text-info fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="card-title mb-1">Available Types</h6>
                            <h3 class="mb-0"><?php echo count($availableTypes); ?></h3>
                            <small class="text-muted">PowerDNS supported</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Standard Record Types -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-shield-check me-2"></i>
                        Standard Record Types
                    </h6>
                    <small class="text-muted">Core DNS record types (always available)</small>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($standardTypes as $type => $info): ?>
                                <tr>
                                    <td><code><?php echo $type; ?></code></td>
                                    <td><?php echo htmlspecialchars($info['description']); ?></td>
                                    <td>
                                        <span class="badge bg-success">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Always Enabled
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Custom Record Types -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-0">
                            <i class="bi bi-gear-wide-connected me-2"></i>
                            Custom Record Types
                        </h6>
                        <small class="text-muted">Additional PowerDNS record types</small>
                    </div>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTypeModal">
                        <i class="bi bi-plus me-1"></i>
                        Add Type
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($customTypes)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-plus-circle fs-1 d-block mb-2 opacity-50"></i>
                            <p class="mb-0">No custom record types configured</p>
                            <small>Click "Add Type" to enable additional PowerDNS record types</small>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customTypes as $type): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($type['type_name']); ?></code></td>
                                        <td><?php echo htmlspecialchars($type['description']); ?></td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="toggle_type">
                                                <input type="hidden" name="type_id" value="<?php echo $type['id']; ?>">
                                                <input type="hidden" name="is_active" value="<?php echo $type['is_active'] ? '0' : '1'; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <button type="submit" class="btn btn-sm <?php echo $type['is_active'] ? 'btn-success' : 'btn-outline-secondary'; ?>">
                                                    <i class="bi bi-<?php echo $type['is_active'] ? 'check-circle' : 'circle'; ?> me-1"></i>
                                                    <?php echo $type['is_active'] ? 'Enabled' : 'Disabled'; ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary" onclick="editType(<?php echo htmlspecialchars(json_encode($type)); ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn btn-outline-danger" onclick="deleteType(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars($type['type_name']); ?>')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Record Type Modal -->
<div class="modal fade" id="addTypeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_type">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>
                        Add Custom Record Type
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="type_name" class="form-label">Record Type</label>
                        <select class="form-select" name="type_name" id="type_name" required onchange="updateTypeInfo(this.value)">
                            <option value="">Select a record type...</option>
                            <?php foreach ($availableTypes as $type => $info): ?>
                                <?php
                                // Check if type is already added
                                $exists = false;
                                foreach ($customTypes as $custom) {
                                    if ($custom['type_name'] === $type) {
                                        $exists = true;
                                        break;
                                    }
                                }
                                if (!$exists):
                                ?>
                                <option value="<?php echo $type; ?>" data-description="<?php echo htmlspecialchars($info['description']); ?>" data-pattern="<?php echo htmlspecialchars($info['pattern']); ?>">
                                    <?php echo $type; ?> - <?php echo htmlspecialchars($info['description']); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" id="description" required>
                        <div class="form-text">Brief description of what this record type is used for</div>
                    </div>
                    <div class="mb-3">
                        <label for="validation_pattern" class="form-label">Validation Pattern (Regex)</label>
                        <input type="text" class="form-control" name="validation_pattern" id="validation_pattern">
                        <div class="form-text">Regular expression pattern to validate record content (optional)</div>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note:</strong> Adding a custom record type will make it available in the DNS records interface. 
                        Ensure your PowerDNS installation supports the selected record type.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Record Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Record Type Modal -->
<div class="modal fade" id="editTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editTypeForm">
                <input type="hidden" name="action" value="update_type">
                <input type="hidden" name="type_id" id="edit_type_id">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i>
                        Edit Record Type
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Record Type</label>
                        <input type="text" class="form-control" id="edit_type_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" id="edit_description" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_validation_pattern" class="form-label">Validation Pattern (Regex)</label>
                        <input type="text" class="form-control" name="validation_pattern" id="edit_validation_pattern">
                        <div class="form-text">Regular expression pattern to validate record content</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Record Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="deleteTypeForm">
                <input type="hidden" name="action" value="delete_type">
                <input type="hidden" name="type_id" id="delete_type_id">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Delete Record Type
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the record type <strong id="delete_type_name"></strong>?</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-warning me-2"></i>
                        This action cannot be undone. The record type will no longer be available for new DNS records.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Record Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Update type info when selecting from dropdown
function updateTypeInfo(selectedType) {
    const option = document.querySelector(`#type_name option[value="${selectedType}"]`);
    if (option) {
        document.getElementById('description').value = option.dataset.description || '';
        document.getElementById('validation_pattern').value = option.dataset.pattern || '';
    }
}

// Edit record type
function editType(type) {
    document.getElementById('edit_type_id').value = type.id;
    document.getElementById('edit_type_name').value = type.type_name;
    document.getElementById('edit_description').value = type.description;
    document.getElementById('edit_validation_pattern').value = type.validation_pattern || '';
    
    new bootstrap.Modal(document.getElementById('editTypeModal')).show();
}

// Delete record type
function deleteType(typeId, typeName) {
    document.getElementById('delete_type_id').value = typeId;
    document.getElementById('delete_type_name').textContent = typeName;
    
    new bootstrap.Modal(document.getElementById('deleteTypeModal')).show();
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
