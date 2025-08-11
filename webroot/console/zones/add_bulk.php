<?php
// Ensure database instance is available before any license checks/output
if (!isset($db)) {
    $db = Database::getInstance();
}

// License near-limit warning (capture for later display inside page body)
$licenseBannerHtml = '';
if (class_exists('LicenseManager')) {
    $ls = LicenseManager::getStatus();
    if (!$ls['unlimited']) {
        $row = $db->fetch("SELECT COUNT(*) c FROM domains");
        $used = (int)($row['c'] ?? 0);
        $limit = (int)$ls['max_domains'];
        if ($used >= $limit) {
            $licenseBannerHtml = '<div class="alert alert-danger d-flex align-items-center" role="alert"><i class="bi bi-exclamation-triangle me-2"></i><div>You have reached your domain limit ('.htmlspecialchars($limit).'). Upgrade your license to add more domains.</div><a class="btn btn-sm btn-light ms-auto" href="?page=admin_license">Upgrade</a></div>';
        } else {
            $percent = ($limit>0)?($used/$limit*100):0;
            if ($percent >= 80) {
                $licenseBannerHtml = '<div class="alert alert-warning d-flex align-items-center" role="alert"><i class="bi bi-exclamation-circle me-2"></i><div>You are nearing your domain limit: '.htmlspecialchars($used).' / '.htmlspecialchars($limit).' ('.round($percent).'%). Consider upgrading.</div><a class="btn btn-sm btn-outline-dark ms-auto" href="?page=admin_license">Upgrade</a></div>';
            }
        }
    }
}
/**
 * PDNS Console - Bulk Add Domains
 */

// Get classes (currentUser is already set by index.php)
$user = new User();
$domain = new Domain();

// Check if user is super admin
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

// Get user's tenants
$userTenants = [];
$selectedTenantId = null;
$error = null; // Initialize error variable

if (!$isSuperAdmin) {
    $tenantData = $user->getUserTenants($currentUser['id']);
    $userTenants = $tenantData;
    if (empty($userTenants)) {
        $error = 'No tenants assigned to your account. Please contact an administrator.';
    } else {
        $selectedTenantId = $userTenants[0]['tenant_id']; // Default to first tenant
    }
} else {
    // Super admin can see all tenants
    $db = Database::getInstance();
    $userTenants = $db->fetchAll("SELECT id as tenant_id, name FROM tenants WHERE is_active = 1 ORDER BY name");
}

// Handle form submission
$results = [];
$errors = [];
$successCount = 0;
$totalCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please try again.';
    } else {
    $domainType = $_POST['domain_type'] ?? 'NATIVE';
    $zoneType = $_POST['zone_type'] ?? 'forward';
    $tenantId = null;
    
    // Determine tenant ID
    if ($isSuperAdmin) {
        $tenantId = intval($_POST['tenant_id'] ?? 0);
        if (empty($tenantId)) {
            $error = 'Please select a tenant for the domains.';
        }
    } else {
        $tenantId = $selectedTenantId;
    }
    
    $domainNames = $_POST['domain_names'] ?? '';
    
    if (!$error && !empty($domainNames) && $tenantId) {
        // Process domain names
        $domains = array_filter(array_map('trim', explode("\n", $domainNames)));
        
        // Remove duplicates and empty entries
        $domains = array_unique(array_filter($domains));
        $totalCount = count($domains);
        
        if (empty($domains)) {
            $error = 'Please enter at least one domain name.';
        } else {
            $db = Database::getInstance();
            
            foreach ($domains as $domainName) {
                $domainName = strtolower(trim($domainName));
                
                // Skip empty domain names
                if (empty($domainName)) {
                    continue;
                }
                
                try {
                    // Validate domain name format
                    if ($zoneType === 'forward') {
                        // Require at least one dot and proper domain format
                        if (!strpos($domainName, '.') || !preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+$/i', $domainName)) {
                            throw new Exception("Invalid domain name format: $domainName (must be like example.com)");
                        }
                        
                        // Create the domain
                        $domainId = $domain->createDomain($domainName, $tenantId, $domainType, $zoneType);
                    } else {
                        // For reverse zones, pass the subnet as both name and subnet parameter
                        // The Domain class will generate the proper reverse zone name
                        $domainId = $domain->createDomain($domainName, $tenantId, $domainType, $zoneType, $domainName);
                    }
                    
                    // Get the actual domain name that was created (important for reverse zones)
                    $createdDomain = $db->fetch("SELECT name FROM domains WHERE id = ?", [$domainId]);
                    $actualDomainName = $createdDomain ? $createdDomain['name'] : $domainName;
                    
                    $results[] = [
                        'domain' => $domainName,
                        'actual_domain' => $actualDomainName,
                        'status' => 'success',
                        'message' => 'Domain created successfully' . ($actualDomainName !== $domainName ? " as $actualDomainName" : ''),
                        'id' => $domainId
                    ];
                    $successCount++;
                    
                    // Log the action
                    $db->execute(
                        "INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address) 
                         VALUES (?, 'domain_create_bulk', 'domains', ?, ?, ?)",
                        [
                            $currentUser['id'],
                            $domainId,
                            json_encode([
                                'domain_name' => $domainName,
                                'domain_type' => $domainType,
                                'zone_type' => $zoneType,
                                'tenant_id' => $tenantId,
                                'actual_domain_name' => $actualDomainName
                            ]),
                            $_SERVER['REMOTE_ADDR'] ?? ''
                        ]
                    );
                    
                } catch (Exception $e) {
                    $results[] = [
                        'domain' => $domainName,
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ];
                    $errors[] = "Failed to create domain '$domainName': " . $e->getMessage();
                }
            }
            
            if ($successCount > 0) {
                $success = "Successfully created $successCount out of $totalCount domains.";
            }
        }
    }
    }
}

// Get domain types
$domainTypes = [
    'NATIVE' => 'Native (PowerDNS manages the domain)',
    'MASTER' => 'Master (PowerDNS is authoritative)',
    'SLAVE' => 'Slave (PowerDNS replicates from master)'
];

// Page setup
$pageTitle = 'Bulk Add Zones';
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid mt-4">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        renderBreadcrumb([
            ['label' => 'Zones', 'url' => '?page=zone_manage'],
            ['label' => 'Bulk Add Zones']
        ], $isSuperAdmin);
    ?>
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <?php if (!empty($licenseBannerHtml)): ?>
                <div class="mb-3"><?= $licenseBannerHtml; ?></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1">
                        <i class="bi bi-layers me-2"></i>
                        Bulk Add Zones
                    </h1>
                    <p class="text-muted mb-0">Add multiple zones at once</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if (!empty($error)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($success)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-warning">
                    <h6><i class="bi bi-exclamation-triangle me-2"></i>Some domains failed to create:</h6>
                    <ul class="mb-0">
                        <?php foreach (array_slice($errors, 0, 10) as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                        <?php if (count($errors) > 10): ?>
                            <li><em>... and <?php echo count($errors) - 10; ?> more errors</em></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!isset($error) || !empty($userTenants)): ?>
        <div class="row">
            <!-- Bulk Add Form -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-plus-circle me-2"></i>
                            Domain Configuration
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="bulkDomainForm">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <!-- Zone Type Selection -->
                            <div class="mb-4">
                                <label class="form-label">
                                    Zone Type <span class="text-danger">*</span>
                                </label>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="zone_type" id="forward_zone" 
                                                   value="forward" <?php echo (!isset($_POST['zone_type']) || $_POST['zone_type'] === 'forward') ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="forward_zone">
                                                <strong>Forward Zone</strong>
                                                <div class="text-muted small">Regular domain names (e.g., example.com)</div>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="zone_type" id="reverse_zone" 
                                                   value="reverse" <?php echo (isset($_POST['zone_type']) && $_POST['zone_type'] === 'reverse') ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="reverse_zone">
                                                <strong>Reverse Zone</strong>
                                                <div class="text-muted small">IP networks for PTR records (e.g., 192.168.1.0/24)</div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Domain Type Selection -->
                            <div class="mb-3">
                                <label for="domain_type" class="form-label">
                                    Domain Type <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="domain_type" name="domain_type" required>
                                    <?php foreach ($domainTypes as $type => $description): ?>
                                        <option value="<?php echo $type; ?>" 
                                                <?php echo (isset($_POST['domain_type']) && $_POST['domain_type'] === $type) || (!isset($_POST['domain_type']) && $type === 'NATIVE') ? 'selected' : ''; ?>>
                                            <?php echo $description; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Tenant Selection (Super Admin only) -->
                            <?php if ($isSuperAdmin && count($userTenants) > 0): ?>
                                <div class="mb-3">
                                    <label for="tenant_id" class="form-label">
                                        Assign to Tenant <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="tenant_id" name="tenant_id" required>
                                        <option value="">Select a tenant...</option>
                                        <?php foreach ($userTenants as $tenant): ?>
                                            <option value="<?php echo $tenant['tenant_id']; ?>" 
                                                    <?php echo (isset($_POST['tenant_id']) && $_POST['tenant_id'] == $tenant['tenant_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($tenant['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php elseif (!$isSuperAdmin): ?>
                                <input type="hidden" name="tenant_id" value="<?php echo $selectedTenantId; ?>">
                                <div class="mb-3">
                                    <label class="form-label">Tenant</label>
                                    <div class="form-control-plaintext">
                                        <?php echo htmlspecialchars($userTenants[0]['name'] ?? 'Default Tenant'); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Domain Names Input -->
                            <div class="mb-4">
                                <label for="domain_names" class="form-label">
                                    <span id="domain-label">Zone Names</span> <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control" id="domain_names" name="domain_names" rows="10" 
                                          placeholder="Enter one zone per line..."
                                          style="font-family: monospace;" required><?php echo htmlspecialchars($_POST['domain_names'] ?? ''); ?></textarea>
                                <div class="form-text">
                                    <div id="zone-help">
                                        <div id="forward-help">
                                            <strong>Forward zones:</strong> Enter zone names, one per line (e.g., example.com, test.org)
                                        </div>
                                        <div id="reverse-help" style="display: none;">
                                            <strong>Reverse zones:</strong> Enter IP networks or individual IPs, one per line:<br>
                                            • Network format: 192.168.1.0/24, 10.0.0.0/16<br>
                                            • IPv6 format: 2001:db8::/32
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="?page=zones" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left me-1"></i>
                                    Back to Zones
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-layers me-1"></i>
                                    Create Zones
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Help and Tips -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-lightbulb me-2"></i>
                            Tips & Examples
                        </h6>
                    </div>
                    <div class="card-body">
                        <div id="tips-content">
                            <div id="forward-tips">
                                <h6>Forward Zone Examples:</h6>
                                <div class="bg-light p-2 rounded mb-3">
                                    <code>
                                        example.com<br>
                                        test.org<br>
                                        subdomain.example.com<br>
                                        my-domain.net
                                    </code>
                                </div>
                                <p class="small text-muted">
                                    Each zone will automatically get SOA and NS records based on your global DNS settings.
                                </p>
                            </div>
                            <div id="reverse-tips" style="display: none;">
                                <h6>Reverse Zone Examples:</h6>
                                <div class="bg-light p-2 rounded mb-3">
                                    <code>
                                        192.168.1.0/24<br>
                                        10.0.0.0/16<br>
                                        172.16.0.0/12<br>
                                        2001:db8::/32
                                    </code>
                                </div>
                                <p class="small text-muted">
                                    Reverse zones are used for PTR records (reverse DNS lookups).
                                </p>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <h6>Automatic Record Creation:</h6>
                        <ul class="small text-muted">
                            <li><strong>SOA Record:</strong> Start of Authority with default values</li>
                            <li><strong>NS Records:</strong> Nameserver records from global settings</li>
                        </ul>
                    </div>
                </div>

                <?php if (!empty($results)): ?>
                <div class="card mt-3">
                    <div class="card-header">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-list-check me-2"></i>
                            Results Summary
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Total:</span>
                                <span><?php echo $totalCount; ?></span>
                            </div>
                            <div class="d-flex justify-content-between text-success">
                                <span>Success:</span>
                                <span><?php echo $successCount; ?></span>
                            </div>
                            <div class="d-flex justify-content-between text-danger">
                                <span>Failed:</span>
                                <span><?php echo $totalCount - $successCount; ?></span>
                            </div>
                        </div>
                        
                        <?php if ($successCount > 0): ?>
                            <a href="?page=domains" class="btn btn-outline-primary btn-sm w-100">
                                <i class="bi bi-list-ul me-1"></i>
                                View Created Domains
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($results)): ?>
        <!-- Detailed Results -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-table me-2"></i>
                            Detailed Results
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Input</th>
                                        <th>Created Domain</th>
                                        <th>Status</th>
                                        <th>Message</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $result): ?>
                                        <tr class="<?php echo $result['status'] === 'success' ? 'table-success' : 'table-danger'; ?>">
                                            <td>
                                                <code><?php echo htmlspecialchars($result['domain']); ?></code>
                                            </td>
                                            <td>
                                                <?php if ($result['status'] === 'success'): ?>
                                                    <code><?php echo htmlspecialchars($result['actual_domain'] ?? $result['domain']); ?></code>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($result['status'] === 'success'): ?>
                                                    <i class="bi bi-check-circle text-success"></i> Success
                                                <?php else: ?>
                                                    <i class="bi bi-x-circle text-danger"></i> Failed
                                                <?php endif; ?>
                                            </td>
                                            <td class="small">
                                                <?php echo htmlspecialchars($result['message']); ?>
                                            </td>
                                            <td>
                                                <?php if ($result['status'] === 'success'): ?>
                                                    <a href="?page=domain_edit&id=<?php echo $result['id']; ?>" 
                                                       class="btn btn-outline-primary btn-sm">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="?page=records&domain_id=<?php echo $result['id']; ?>" 
                                                       class="btn btn-outline-info btn-sm">
                                                        <i class="bi bi-list-ul"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const forwardRadio = document.getElementById('forward_zone');
    const reverseRadio = document.getElementById('reverse_zone');
    const domainNamesTextarea = document.getElementById('domain_names');
    const domainLabel = document.getElementById('domain-label');
    const forwardHelp = document.getElementById('forward-help');
    const reverseHelp = document.getElementById('reverse-help');
    const forwardTips = document.getElementById('forward-tips');
    const reverseTips = document.getElementById('reverse-tips');
    const form = document.getElementById('bulkDomainForm');

    function updateInterface() {
        if (reverseRadio.checked) {
            domainLabel.textContent = 'IP Networks/Subnets';
            domainNamesTextarea.placeholder = 'Enter one IP network per line...\n192.168.1.0/24\n10.0.0.0/16\n2001:db8::/32';
            forwardHelp.style.display = 'none';
            reverseHelp.style.display = 'block';
            forwardTips.style.display = 'none';
            reverseTips.style.display = 'block';
        } else {
            domainLabel.textContent = 'Domain Names';
            domainNamesTextarea.placeholder = 'Enter one domain per line...\nexample.com\ntest.org\nmy-domain.net';
            forwardHelp.style.display = 'block';
            reverseHelp.style.display = 'none';
            forwardTips.style.display = 'block';
            reverseTips.style.display = 'none';
        }
    }

    function validateDomains() {
        const domains = domainNamesTextarea.value.split('\n')
            .map(d => d.trim())
            .filter(d => d.length > 0);
        
        const isReverse = reverseRadio.checked;
        const errors = [];
        const duplicates = [];
        const seen = new Set();
        
        domains.forEach((domain, index) => {
            const lineNum = index + 1;
            
            // Check for duplicates
            if (seen.has(domain.toLowerCase())) {
                duplicates.push(`Line ${lineNum}: Duplicate domain "${domain}"`);
            } else {
                seen.add(domain.toLowerCase());
            }
            
            // Validate format
            if (isReverse) {
                // Basic validation for IP/CIDR format
                if (!domain.match(/^(\d{1,3}\.){1,3}\d{1,3}(\/\d{1,2})?$/) && 
                    !domain.match(/^([0-9a-fA-F]{0,4}:){1,7}[0-9a-fA-F]{0,4}(\/\d{1,3})?$/)) {
                    errors.push(`Line ${lineNum}: Invalid IP/subnet format "${domain}"`);
                }
            } else {
                // Basic domain name validation - must contain at least one dot
                if (!domain.includes('.') || !domain.match(/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)+$/)) {
                    errors.push(`Line ${lineNum}: Invalid domain format "${domain}" (must be like example.com)`);
                }
            }
        });
        
        return { errors: errors.concat(duplicates), count: domains.length };
    }

    function showValidationFeedback() {
        const validation = validateDomains();
        let feedbackDiv = document.getElementById('validation-feedback');
        
        if (!feedbackDiv) {
            feedbackDiv = document.createElement('div');
            feedbackDiv.id = 'validation-feedback';
            feedbackDiv.className = 'mt-2';
            domainNamesTextarea.parentNode.appendChild(feedbackDiv);
        }
        
        if (validation.errors.length > 0) {
            feedbackDiv.innerHTML = `
                <div class="alert alert-warning alert-sm">
                    <strong>Validation Issues Found:</strong>
                    <ul class="mb-0 mt-1">
                        ${validation.errors.slice(0, 5).map(error => `<li>${error}</li>`).join('')}
                        ${validation.errors.length > 5 ? `<li><em>... and ${validation.errors.length - 5} more issues</em></li>` : ''}
                    </ul>
                </div>
            `;
        } else if (validation.count > 0) {
            feedbackDiv.innerHTML = `
                <div class="alert alert-success alert-sm">
                    <i class="bi bi-check-circle me-1"></i>
                    Ready to create ${validation.count} domain${validation.count !== 1 ? 's' : ''}
                </div>
            `;
        } else {
            feedbackDiv.innerHTML = '';
        }
    }

    forwardRadio.addEventListener('change', updateInterface);
    reverseRadio.addEventListener('change', updateInterface);
    domainNamesTextarea.addEventListener('input', showValidationFeedback);
    
    // Form submission validation
    form.addEventListener('submit', function(e) {
        const validation = validateDomains();
        if (validation.errors.length > 0) {
            e.preventDefault();
            alert('Please fix the validation errors before submitting the form.');
            showValidationFeedback();
        }
    });
    
    // Initial setup
    updateInterface();
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
