<?php
// Ensure database instance is available before any license checks/output
if (!isset($db)) {
    $db = Database::getInstance();
}

// License near-limit warning (captured for later display inside page body)
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
 * PDNS Console - Add Zone
 */

// Get classes (currentUser is already set by index.php)
$user = new User();
$domain = new Domain();

// Check if user is super admin
$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);

// Get user's tenants
$userTenants = [];
$selectedTenantId = null;

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error)) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please try again.';
    } else {
    $zoneType = $_POST['zone_type'] ?? 'forward';
    $domainName = trim($_POST['domain_name'] ?? '');
    $subnet = trim($_POST['subnet'] ?? '');
    $domainType = $_POST['domain_type'] ?? 'NATIVE';
    $tenantId = intval($_POST['tenant_id'] ?? $selectedTenantId);
    
    try {
        // Validate zone type selection
        if (!in_array($zoneType, ['forward', 'reverse'])) {
            throw new Exception('Invalid zone type selected.');
        }
        
        // Validate inputs based on zone type
        if ($zoneType === 'forward') {
            if (empty($domainName)) {
                throw new Exception('Zone name is required for forward zones.');
            }
            $finalDomainName = $domainName;
        } else {
            // Reverse zone
            if (empty($subnet)) {
                throw new Exception('IP subnet is required for reverse zones.');
            }
            
            if (!$domain->isValidSubnet($subnet)) {
                throw new Exception('Invalid IP subnet format. Use CIDR notation (e.g., 192.168.1.0/24 or 2001:db8::/32).');
            }
            
            // Generate reverse zone name from subnet
            $finalDomainName = $domain->generateReverseZoneName($subnet);
        }
        
        if (empty($tenantId)) {
            throw new Exception('Tenant selection is required.');
        }
        
        // Verify tenant access
        if (!$isSuperAdmin) {
            $hasAccess = false;
            foreach ($userTenants as $tenant) {
                if ($tenant['tenant_id'] == $tenantId) {
                    $hasAccess = true;
                    break;
                }
            }
            if (!$hasAccess) {
                throw new Exception('Access denied to selected tenant.');
            }
        }
        
        // Create domain with zone type
        $newDomainId = $domain->createDomain($finalDomainName, $tenantId, $domainType, $zoneType, $subnet);
        
        $zoneTypeLabel = ucfirst($zoneType);
        $success = "$zoneTypeLabel zone '$finalDomainName' created successfully!";
        
        // Redirect after successful creation
        header("Location: ?page=records&domain_id=$newDomainId&created=1");
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
    }
}

// Page title
$pageTitle = 'Add DNS Zone';
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<style>
.zone-type-card {
    transition: all 0.2s ease;
    cursor: pointer;
}

.zone-type-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.zone-type-card .btn-check:checked + .btn {
    background-color: var(--bs-primary);
    border-color: var(--bs-primary);
    color: white;
}

.zone-type-card .btn-check:checked + .btn.btn-outline-success {
    background-color: var(--bs-success);
    border-color: var(--bs-success);
    color: white;
}

.zone-fields {
    transition: all 0.3s ease;
}

#reverse-zone-preview {
    min-height: 38px;
    display: flex;
    align-items: center;
}
</style>

<div class="container-fluid py-4">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        renderBreadcrumb([
            ['label' => 'Zones', 'url' => '?page=zone_manage'],
            ['label' => 'Add Zone']
        ], $isSuperAdmin);
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <?php if (!empty($licenseBannerHtml)): ?>
                <div class="mb-3"><?= $licenseBannerHtml; ?></div>
            <?php endif; ?>
            <!-- Page Header -->
            <div class="mb-4">
                <h2 class="h4 mb-2">
                    <i class="bi bi-plus-circle me-2 text-primary"></i>
                    Add New DNS Zone
                </h2>
                <p class="text-muted mb-0">Create a new forward DNS zone or reverse DNS zone with automatic record generation</p>
            </div>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if (!isset($error) || !empty($userTenants)): ?>
                <!-- Add Domain Form -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-globe2 me-2"></i>
                            DNS Zone Configuration
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="zoneForm">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <!-- Zone Type Selection -->
                            <div class="mb-4">
                                <label class="form-label">
                                    Zone Type <span class="text-danger">*</span>
                                </label>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="card h-100 zone-type-card" data-zone-type="forward">
                                            <div class="card-body text-center">
                                                <input type="radio" class="btn-check" name="zone_type" id="zone_forward" value="forward" 
                                                       <?php echo (!isset($_POST['zone_type']) || $_POST['zone_type'] === 'forward') ? 'checked' : ''; ?>>
                                                <label class="btn btn-outline-primary w-100" for="zone_forward">
                                                    <i class="bi bi-globe2 fs-4 d-block mb-2"></i>
                                                    <strong>Forward Zone</strong>
                                                    <div class="small text-muted mt-1">
                                                        Standard domain (e.g., example.com)
                                                    </div>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card h-100 zone-type-card" data-zone-type="reverse">
                                            <div class="card-body text-center">
                                                <input type="radio" class="btn-check" name="zone_type" id="zone_reverse" value="reverse"
                                                       <?php echo (isset($_POST['zone_type']) && $_POST['zone_type'] === 'reverse') ? 'checked' : ''; ?>>
                                                <label class="btn btn-outline-success w-100" for="zone_reverse">
                                                    <i class="bi bi-arrow-clockwise fs-4 d-block mb-2"></i>
                                                    <strong>Reverse Zone</strong>
                                                    <div class="small text-muted mt-1">
                                                        IP to hostname lookup
                                                    </div>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Forward Zone Fields -->
                            <div id="forward-fields" class="zone-fields">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="mb-3">
                                            <label for="domain_name" class="form-label">
                                                Zone Name <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" 
                                                   class="form-control form-control-lg" 
                                                   id="domain_name" 
                                                   name="domain_name" 
                                                   placeholder="example.com"
                                                   value="<?php echo htmlspecialchars($_POST['domain_name'] ?? ''); ?>">
                                            <div class="form-text">
                                                Enter the zone name without protocol (e.g., example.com, subdomain.example.com)
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="domain_type" class="form-label">Zone Type</label>
                                            <select class="form-select" id="domain_type" name="domain_type">
                                                <?php
                                                $types = ['NATIVE' => 'Native', 'MASTER' => 'Master', 'SLAVE' => 'Slave'];
                                                $selectedType = $_POST['domain_type'] ?? 'NATIVE';
                                                foreach ($types as $value => $label):
                                                ?>
                                                    <option value="<?php echo $value; ?>" <?php echo $selectedType == $value ? 'selected' : ''; ?>>
                                                        <?php echo $label; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Reverse Zone Fields -->
                            <div id="reverse-fields" class="zone-fields" style="display: none;">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="mb-3">
                                            <label for="subnet" class="form-label">
                                                IP Subnet <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" 
                                                   class="form-control form-control-lg" 
                                                   id="subnet" 
                                                   name="subnet" 
                                                   placeholder="192.168.1.0/24 or 2001:db8::/32"
                                                   value="<?php echo htmlspecialchars($_POST['subnet'] ?? ''); ?>">
                                            <div class="form-text">
                                                Enter the IP subnet in CIDR notation for reverse DNS lookup
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Generated Zone Name</label>
                                            <div id="reverse-zone-preview" class="form-control-plaintext bg-light border rounded p-2">
                                                <em class="text-muted">Enter subnet to see zone name</em>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Subnet Examples -->
                                <div class="alert alert-info">
                                    <h6><i class="bi bi-lightbulb me-1"></i> Subnet Examples:</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>IPv4:</strong>
                                            <ul class="mb-0 small">
                                                <li><code>192.168.1.0/24</code> → 1.168.192.in-addr.arpa</li>
                                                <li><code>10.0.0.0/8</code> → 10.in-addr.arpa</li>
                                                <li><code>172.16.0.0/16</code> → 16.172.in-addr.arpa</li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>IPv6:</strong>
                                            <ul class="mb-0 small">
                                                <li><code>2001:db8::/32</code> → 8.b.d.0.1.0.0.2.ip6.arpa</li>
                                                <li><code>fe80::/16</code> → 0.8.e.f.ip6.arpa</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if (count($userTenants) > 1 || $isSuperAdmin): ?>
                                <div class="mb-3">
                                    <label for="tenant_id" class="form-label">
                                        <?php echo $isSuperAdmin ? 'Assign to Tenant' : 'Tenant'; ?>
                                        <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="tenant_id" name="tenant_id" required>
                                        <?php if ($isSuperAdmin): ?>
                                            <option value="">Select a tenant...</option>
                                        <?php endif; ?>
                                        <?php foreach ($userTenants as $tenant): ?>
                                            <?php
                                            $tenantId = $tenant['tenant_id'] ?? $tenant['id'];
                                            $tenantName = $tenant['name'];
                                            $isSelected = (isset($_POST['tenant_id']) && $_POST['tenant_id'] == $tenantId) || 
                                                         (!isset($_POST['tenant_id']) && $tenantId == $selectedTenantId);
                                            ?>
                                            <option value="<?php echo $tenantId; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($tenantName); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="tenant_id" value="<?php echo $selectedTenantId; ?>">
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-12">
                                    <div class="alert alert-info alert-static">
                                        <i class="bi bi-info-circle me-2"></i>
                                        <strong>Automatic Record Creation:</strong>
                                        <ul class="mb-0 mt-2">
                                            <li><strong>SOA Record:</strong> Start of Authority record with default values</li>
                                            <li id="ns-record-info"><strong>NS Records:</strong> Nameserver records from global settings</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="?page=zones" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left me-1"></i>
                                    Back to Zones
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-plus-circle me-1"></i>
                                    <span id="create-btn-text">Create Zone</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- DNS Settings Info -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-gear me-2"></i>
                            Current DNS Settings
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php
                        $settings = new Settings();
                        $primaryNS = $settings->get('primary_nameserver', 'dns1.atmyip.com');
                        $secondaryNS = $settings->get('secondary_nameserver', 'dns2.atmyip.com');
                        $soaContact = $settings->get('soa_contact', 'admin.atmyip.com');
                        ?>
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Primary Nameserver:</strong><br>
                                <code><?php echo htmlspecialchars($primaryNS); ?></code>
                            </div>
                            <div class="col-md-4">
                                <strong>Secondary Nameserver:</strong><br>
                                <code><?php echo htmlspecialchars($secondaryNS); ?></code>
                            </div>
                            <div class="col-md-4">
                                <strong>SOA Contact:</strong><br>
                                <code><?php echo htmlspecialchars($soaContact); ?></code>
                            </div>
                        </div>
                        <?php if ($isSuperAdmin): ?>
                            <div class="mt-3">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    These values can be changed in 
                                    <a href="?page=admin_settings">Global Settings</a>.
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Zone type switching functionality
document.addEventListener('DOMContentLoaded', function() {
    const forwardRadio = document.getElementById('zone_forward');
    const reverseRadio = document.getElementById('zone_reverse');
    const forwardFields = document.getElementById('forward-fields');
    const reverseFields = document.getElementById('reverse-fields');
    const domainNameInput = document.getElementById('domain_name');
    const subnetInput = document.getElementById('subnet');
    const createBtnText = document.getElementById('create-btn-text');
    const nsRecordInfo = document.getElementById('ns-record-info');

    // Function to switch between zone types
    function switchZoneType() {
        if (forwardRadio.checked) {
            forwardFields.style.display = 'block';
            reverseFields.style.display = 'none';
            domainNameInput.required = true;
            subnetInput.required = false;
            createBtnText.textContent = 'Create Forward Zone';
            nsRecordInfo.innerHTML = '<strong>NS Records:</strong> Nameserver records from global settings';
        } else if (reverseRadio.checked) {
            forwardFields.style.display = 'none';
            reverseFields.style.display = 'block';
            domainNameInput.required = false;
            subnetInput.required = true;
            createBtnText.textContent = 'Create Reverse Zone';
            nsRecordInfo.innerHTML = '<strong>NS Records:</strong> Nameserver records from global settings (for reverse delegation)';
        }
    }

    // Initial setup
    switchZoneType();

    // Event listeners for radio buttons
    forwardRadio.addEventListener('change', switchZoneType);
    reverseRadio.addEventListener('change', switchZoneType);

    // Domain name validation for forward zones
    domainNameInput.addEventListener('input', function() {
        if (!forwardRadio.checked) return;
        
        const domainName = this.value.toLowerCase();
        const isValid = /^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/.test(domainName);
        
        if (domainName && !isValid) {
            this.classList.add('is-invalid');
            if (!document.getElementById('domain-error')) {
                const error = document.createElement('div');
                error.id = 'domain-error';
                error.className = 'invalid-feedback';
                error.textContent = 'Please enter a valid domain name';
                this.parentNode.appendChild(error);
            }
        } else {
            this.classList.remove('is-invalid');
            const error = document.getElementById('domain-error');
            if (error) {
                error.remove();
            }
        }
    });

    // Subnet validation and reverse zone name preview
    subnetInput.addEventListener('input', function() {
        if (!reverseRadio.checked) return;
        
        const subnet = this.value.trim();
        const preview = document.getElementById('reverse-zone-preview');
        
        if (!subnet) {
            preview.innerHTML = '<em class="text-muted">Enter subnet to see zone name</em>';
            this.classList.remove('is-valid', 'is-invalid');
            return;
        }

        // Basic subnet validation
        const ipv4Regex = /^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/;
        const ipv6Regex = /^([0-9a-fA-F]{0,4}:){1,7}[0-9a-fA-F]{0,4}\/\d{1,3}$/;
        
        let isValid = false;
        let zoneName = '';
        
        if (ipv4Regex.test(subnet)) {
            // IPv4 validation and preview generation
            const parts = subnet.split('/');
            const ip = parts[0];
            const cidr = parseInt(parts[1]);
            
            if (cidr >= 8 && cidr <= 30) {
                const octets = ip.split('.').map(n => parseInt(n));
                if (octets.every(n => n >= 0 && n <= 255)) {
                    isValid = true;
                    
                    // Generate reverse zone name based on CIDR
                    if (cidr >= 24) {
                        zoneName = `${octets[2]}.${octets[1]}.${octets[0]}.in-addr.arpa`;
                    } else if (cidr >= 16) {
                        zoneName = `${octets[1]}.${octets[0]}.in-addr.arpa`;
                    } else if (cidr >= 8) {
                        zoneName = `${octets[0]}.in-addr.arpa`;
                    }
                }
            }
        } else if (ipv6Regex.test(subnet)) {
            // IPv6 basic validation
            const parts = subnet.split('/');
            const cidr = parseInt(parts[1]);
            
            if (cidr >= 16 && cidr <= 128 && cidr % 4 === 0) {
                isValid = true;
                zoneName = 'Generated IPv6 reverse zone (simplified preview)';
            }
        }
        
        if (isValid) {
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
            preview.innerHTML = `<code>${zoneName}</code>`;
            
            // Remove any existing error
            const error = document.getElementById('subnet-error');
            if (error) {
                error.remove();
            }
        } else {
            this.classList.remove('is-valid');
            this.classList.add('is-invalid');
            preview.innerHTML = '<em class="text-danger">Invalid subnet format</em>';
            
            if (!document.getElementById('subnet-error')) {
                const error = document.createElement('div');
                error.id = 'subnet-error';
                error.className = 'invalid-feedback';
                error.textContent = 'Please enter a valid IP subnet in CIDR notation (e.g., 192.168.1.0/24)';
                this.parentNode.appendChild(error);
            }
        }
    });

    // Form validation before submit
    document.getElementById('zoneForm').addEventListener('submit', function(e) {
        const isForward = forwardRadio.checked;
        
        if (isForward && !domainNameInput.value.trim()) {
            e.preventDefault();
            domainNameInput.focus();
            alert('Please enter a domain name for the forward zone.');
            return false;
        }
        
        if (!isForward && !subnetInput.value.trim()) {
            e.preventDefault();
            subnetInput.focus();
            alert('Please enter an IP subnet for the reverse zone.');
            return false;
        }
        
        if (!isForward && subnetInput.classList.contains('is-invalid')) {
            e.preventDefault();
            subnetInput.focus();
            alert('Please enter a valid IP subnet in CIDR notation.');
            return false;
        }
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
