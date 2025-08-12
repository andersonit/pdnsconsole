<?php
/**
 * PDNS Console - Branding Administration
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

$pageTitle = 'Branding Configuration';
$branding = $settings->getBranding();
$successMessage = '';
$errorMessage = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errorMessage = 'Security token mismatch. Please try again.';
    } else {
    if (isset($_POST['action']) && $_POST['action'] === 'update_branding') {
        // Only site_name, footer_text, and optional site_logo/site_favicon are managed here.
        $brandingData = [
            'site_name' => trim($_POST['site_name'] ?? ''),
            'footer_text' => trim($_POST['footer_text'] ?? '')
        ];
        // Handle logo upload
        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/assets/img/uploads/';
            
            // Create directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    $errorMessage = 'Failed to create upload directory. Please check file permissions.';
                }
            }
            
            // Check if directory is writable
            if (empty($errorMessage) && !is_writable($uploadDir)) {
                $errorMessage = 'Upload directory is not writable. Please check file permissions.';
            }
            
            if (empty($errorMessage)) {
                // Validate file type
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
                $fileType = $_FILES['site_logo']['type'];
                
                if (!in_array($fileType, $allowedTypes)) {
                    $errorMessage = 'Invalid file type. Please upload a PNG, JPG, GIF, or SVG image.';
                } else {
                    // Validate file size (max 5MB)
                    $maxSize = 5 * 1024 * 1024; // 5MB
                    if ($_FILES['site_logo']['size'] > $maxSize) {
                        $errorMessage = 'File too large. Maximum size is 5MB.';
                    } else {
                        $fileName = 'logo_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['site_logo']['name']));
                        $uploadPath = $uploadDir . $fileName;
                        
                        if (move_uploaded_file($_FILES['site_logo']['tmp_name'], $uploadPath)) {
                            $brandingData['site_logo'] = '/assets/img/uploads/' . $fileName;
                        } else {
                            $errorMessage = 'Failed to upload logo file. Please check file permissions.';
                        }
                    }
                }
            }
    } else if (!empty($_POST['current_logo'])) {
            $brandingData['site_logo'] = $_POST['current_logo'];
        }
        
        // Handle favicon upload
        if (empty($errorMessage) && isset($_FILES['site_favicon']) && $_FILES['site_favicon']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['site_favicon']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/assets/img/uploads/';
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true)) {
                        $errorMessage = 'Failed to create upload directory for favicon. Please check file permissions.';
                    }
                }
                if (empty($errorMessage) && !is_writable($uploadDir)) {
                    $errorMessage = 'Upload directory is not writable for favicon. Please check file permissions.';
                }
                if (empty($errorMessage)) {
                    // Validate favicon types (ICO/PNG/SVG)
                    $allowedFaviconTypes = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/svg+xml'];
                    $fileType = $_FILES['site_favicon']['type'];
                    if (!in_array($fileType, $allowedFaviconTypes)) {
                        $errorMessage = 'Invalid favicon type. Please upload a .ico, .png, or .svg file.';
                    } else {
                        // 1MB max for favicon
                        $maxSize = 1 * 1024 * 1024;
                        if ($_FILES['site_favicon']['size'] > $maxSize) {
                            $errorMessage = 'Favicon file too large. Maximum size is 1MB.';
                        } else {
                            $fileName = 'favicon_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['site_favicon']['name']));
                            $uploadPath = $uploadDir . $fileName;
                            if (move_uploaded_file($_FILES['site_favicon']['tmp_name'], $uploadPath)) {
                                $brandingData['site_favicon'] = '/assets/img/uploads/' . $fileName;
                            } else {
                                $errorMessage = 'Failed to upload favicon file. Please check file permissions.';
                            }
                        }
                    }
                }
            } else {
                $errorMessage = 'Error uploading favicon. Please try again.';
            }
        } else if (!empty($_POST['current_favicon'])) {
            // Keep existing favicon if not uploading a new one
            $brandingData['site_favicon'] = $_POST['current_favicon'];
        }
        
        if (empty($errorMessage)) {
            try {
                $updated = $settings->updateBranding($brandingData);
                if ($updated > 0) {
                    $successMessage = "Branding settings updated successfully! ($updated settings changed)";
                    
                    // Log the action
                    $audit = new AuditLog();
                    $audit->logAction($currentUser['id'], 'BRANDING_UPDATE', 'global_settings', null, null, $brandingData, null, [
                        'settings_updated' => $updated,
                        'settings' => array_keys($brandingData)
                    ]);
                    
                    // Refresh branding data
                    $branding = $settings->getBranding();
                } else {
                    $errorMessage = 'No changes were made to the branding settings.';
                }
            } catch (Exception $e) {
                $errorMessage = 'Error updating branding settings: ' . $e->getMessage();
            }
        }
    }
    }
}

// Theme is managed globally via the Theme menu/modal; no per-page selection here.

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid mt-4">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        renderBreadcrumb([
            ['label' => 'Branding']
        ], true);
    ?>
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3 mb-1">
                <i class="bi bi-palette me-2"></i>
                Branding Configuration
            </h1>
            <p class="text-muted mb-0">Customize your PDNS Console appearance and branding</p>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (!empty($successMessage)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>
            <?php echo htmlspecialchars($successMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($errorMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Branding Configuration Form -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-brush me-2"></i>
                        Branding Settings
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_branding">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="current_logo" value="<?php echo htmlspecialchars($branding['site_logo']); ?>">
                        <input type="hidden" name="current_favicon" value="<?php echo htmlspecialchars($branding['site_favicon']); ?>">
                        
                        <!-- Site Information -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Site Information
                                </h6>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label for="site_name" class="form-label">Site Name</label>
                                <input type="text" class="form-control" id="site_name" name="site_name" 
                                       value="<?php echo htmlspecialchars($branding['site_name']); ?>" 
                                       placeholder="PDNS Console">
                                <div class="form-text">Displayed in the browser title and header</div>
                            </div>
                        </div>

                        <!-- Logo Section -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-image me-1"></i>
                                    Logo Configuration
                                </h6>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="site_logo" class="form-label">Site Logo</label>
                                <input type="file" class="form-control" id="site_logo" name="site_logo" 
                                       accept="image/*">
                                <div class="form-text">Upload a new logo (PNG, JPG, SVG recommended)</div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Current Logo</label>
                                <div class="border rounded p-3 bg-light">
                                    <?php if (!empty($branding['site_logo']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $branding['site_logo'])): ?>
                                        <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" 
                                             alt="Current Logo" class="img-fluid" style="max-height: 60px;">
                                    <?php else: ?>
                                        <span class="text-muted">No logo currently set</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Favicon Section -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-star me-1"></i>
                                    Favicon
                                </h6>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="site_favicon" class="form-label">Site Favicon</label>
                                <input type="file" class="form-control" id="site_favicon" name="site_favicon"
                                       accept="image/x-icon,image/vnd.microsoft.icon,image/png,image/svg+xml">
                                <div class="form-text">Upload .ico, .png, or .svg (prefer 32x32 or 180x180 PNG)</div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Current Favicon</label>
                                <div class="border rounded p-3 bg-light d-flex align-items-center" style="height: 84px;">
                                    <?php if (!empty($branding['site_favicon']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $branding['site_favicon'])): ?>
                                        <img src="<?php echo htmlspecialchars($branding['site_favicon']); ?>" alt="Current Favicon" style="width: 32px; height: 32px;">
                                        <a href="<?php echo htmlspecialchars($branding['site_favicon']); ?>" class="ms-3 small" target="_blank" rel="noopener">View</a>
                                    <?php else: ?>
                                        <span class="text-muted">No favicon currently set</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

            <!-- Footer -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-palette2 me-1"></i>
                    Footer
                                </h6>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="footer_text" class="form-label">Footer Text</label>
                                <input type="text" class="form-control" id="footer_text" name="footer_text" 
                                       value="<?php echo htmlspecialchars($branding['footer_text']); ?>" 
                                       placeholder="Powered by PDNS Console">
                                <div class="form-text">Text displayed in the page footer</div>
                            </div>
                <!-- Theme selection removed from Branding page; use Theme menu (Super Admins). -->
                        </div>

                        <!-- Submit Button -->
                        <div class="row">
                            <div class="col-12">
                                <hr class="mb-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i>
                                    Update Branding Settings
                                </button>
                                <button type="reset" class="btn btn-outline-secondary ms-2">
                                    <i class="bi bi-arrow-clockwise me-1"></i>
                                    Reset Form
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Preview Card -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-eye me-2"></i>
                        Preview
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <h6>Current Settings</h6>
                        <ul class="list-unstyled small">
                            <li><strong>Site Name:</strong> <?php echo htmlspecialchars($branding['site_name']); ?></li>
                            <!-- Theme preview removed; theme managed via menu -->
                            <li><strong>Footer:</strong> <?php echo htmlspecialchars($branding['footer_text']); ?></li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-1"></i>
                        <small>Changes will be applied immediately after saving and may require a page refresh to see all effects.</small>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-transparent border-0">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-question-circle me-2"></i>
                        Help
                    </h5>
                </div>
                <div class="card-body">
                    <h6>Logo Guidelines</h6>
                    <ul class="small">
                        <li>Recommended size: 200x60 pixels</li>
                        <li>Formats: PNG, JPG, SVG</li>
                        <li>Keep file size under 500KB</li>
                        <li>Use transparent backgrounds for best results</li>
                    </ul>
                    
                    <!-- Theme notes removed; theme managed globally via menu -->
                </div>
            </div>
        </div>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
