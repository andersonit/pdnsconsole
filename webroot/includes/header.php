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
 * PDNS Console - Header Include
 */

// Ensure we have required variables
if (!isset($branding)) {
    $branding = $settings->getBranding();
}

if (!isset($pageTitle)) {
    $pageTitle = 'Dashboard';
}

// Get theme info including dark mode
$themeInfo = $settings->getThemeInfo();
$bodyClasses = $themeInfo['effective_dark'] ? 'dark-mode' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle . ' - ' . $branding['site_name']); ?></title>
    <?php if (!empty($branding['site_favicon'])): ?>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($branding['site_favicon']); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars($branding['site_favicon']); ?>">
    <?php else: ?>
    <link rel="icon" type="image/svg+xml" href="/assets/img/pdns-favicon.svg">
    <link rel="shortcut icon" href="/assets/img/pdns-favicon.svg">
    <?php endif; ?>
    <?php 
    // Inject DNSSEC hold period meta if available
    try { $dnssecHold = $settings->get('dnssec_hold_period_days'); if ($dnssecHold) { echo '<meta name="dnssec-hold-days" content="'.htmlspecialchars($dnssecHold).'">'; } } catch (Exception $e) { /* ignore */ }
    ?>
    
    <!-- Bootstrap CSS Theme -->
    <link href="<?php echo $settings->getThemeUrl(); ?>" rel="stylesheet" id="theme-stylesheet" data-theme="<?php echo htmlspecialchars($settings->getCurrentTheme()); ?>">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" crossorigin="anonymous">
    
    <!-- Bootstrap Icons Fallback -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/fonts/bootstrap-icons.woff2" as="font" type="font/woff2" crossorigin="anonymous">
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/fonts/bootstrap-icons.woff" as="font" type="font/woff" crossorigin="anonymous">
    
    <!-- Custom CSS -->
    <link href="/assets/css/custom.css" rel="stylesheet">
    
    <!-- Theme System CSS -->
    <link href="/assets/css/theme-system.css" rel="stylesheet">
    
    <!-- CSRF Token for JavaScript -->
    <script>
        // Helper function for AJAX requests with CSRF protection
        function makeRequest(url, options = {}) {
            options.headers = options.headers || {};
            
            // Add CSRF token if available (can be implemented later)
            const csrfToken = document.querySelector('meta[name="csrf-token"]');
            if (csrfToken) {
                options.headers['X-CSRF-Token'] = csrfToken.getAttribute('content');
            }
            
            return fetch(url, options);
        }
        
        // Simple theme toggle (functional theme switcher)
        function toggleTheme() {
            showThemeSelector();
        }
        
        // Show theme selector modal
        function showThemeSelector() {
            // Get available themes from API
            fetch('api/theme.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showThemeModal(data.available_themes, data.current_theme);
                    } else {
                        alert('Error loading themes: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Theme API error:', error);
                    alert('Error loading themes. Please try again. Details: ' + error.message);
                });
        }
        
        // Show theme selection modal
        function showThemeModal(themes, currentTheme) {
            let themeOptions = '';
            for (const [key, name] of Object.entries(themes)) {
                const isSelected = key === currentTheme ? 'selected' : '';
                themeOptions += `<option value="${key}" ${isSelected}>${name}</option>`;
            }
            
            const modalHtml = `
                <div class="modal fade" id="themeModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-palette me-2"></i>Select Theme
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="themeSelect" class="form-label">Choose a Bootswatch theme:</label>
                                    <select class="form-select" id="themeSelect">
                                        ${themeOptions}
                                    </select>
                                </div>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Theme changes apply immediately and are saved automatically.
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" onclick="applyTheme()">Apply Theme</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal if present
            const existingModal = document.getElementById('themeModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add modal to page
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('themeModal'));
            modal.show();
            
            // Add preview functionality
            document.getElementById('themeSelect').addEventListener('change', function() {
                previewTheme(this.value);
            });
        }
        
        // Preview theme without saving
        function previewTheme(theme) {
            const themeUrl = theme === 'default' 
                ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'
                : `https://cdn.jsdelivr.net/npm/bootswatch@5.3.7/dist/${theme}/bootstrap.min.css`;
            
            // Update the Bootstrap CSS link
            const bootstrapLink = document.querySelector('link[href*="bootstrap"]');
            if (bootstrapLink) {
                bootstrapLink.href = themeUrl;
            }
        }
        
        // Apply and save theme
        function applyTheme() {
            const selectedTheme = document.getElementById('themeSelect').value;
            
            // Show loading state
            const applyButton = document.querySelector('#themeModal .btn-primary');
            const originalText = applyButton.textContent;
            applyButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Applying...';
            applyButton.disabled = true;
            
            // Save theme via API
            fetch('api/theme.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ theme: selectedTheme })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Theme applied successfully
                    const modal = bootstrap.Modal.getInstance(document.getElementById('themeModal'));
                    modal.hide();
                    
                    // Show success message
                    showAlert('success', `Theme changed to ${data.theme_name} successfully!`);
                    
                    // Update the page with new theme URL
                    previewTheme(selectedTheme);
                    
                } else {
                    throw new Error(data.error || 'Failed to apply theme');
                }
            })
            .catch(error => {
                console.error('Theme application error:', error);
                showAlert('danger', 'Error applying theme: ' + error.message);
                
                // Reset button
                applyButton.textContent = originalText;
                applyButton.disabled = false;
            });
        }
        
        // Show alert message
        function showAlert(type, message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show alert-notification`;
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);
            // Auto-remove THIS alert after 5 seconds
            setTimeout(() => {
                if (alertDiv) {
                    const bsAlert = new bootstrap.Alert(alertDiv);
                    bsAlert.close();
                }
            }, 5000);
        }
        
        // Add some interactive enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Debug Bootstrap Icons loading (temporarily disabled)
            // checkBootstrapIcons();
            
            // Add tooltips to buttons
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
        
        // Check if Bootstrap Icons are loading properly
        function checkBootstrapIcons() {
            // Create a test element to check Bootstrap Icons
            var testElement = document.createElement('i');
            testElement.className = 'bi bi-house';
            testElement.style.position = 'absolute';
            testElement.style.left = '-9999px';
            testElement.style.fontSize = '16px';
            document.body.appendChild(testElement);
            
            // Check if the icon is rendered after a delay
            setTimeout(function() {
                var computedStyle = window.getComputedStyle(testElement, ':before');
                var content = computedStyle.getPropertyValue('content');
                var fontFamily = computedStyle.getPropertyValue('font-family');
                
                console.log('Bootstrap Icons check:', {
                    content: content,
                    fontFamily: fontFamily,
                    element: testElement
                });
                
                if (!content || content === 'none' || content === '""') {
                    console.warn('Bootstrap Icons not rendering properly');
                    // Force reload Bootstrap Icons
                    reloadBootstrapIcons();
                } else {
                    console.log('Bootstrap Icons loaded successfully');
                }
                
                document.body.removeChild(testElement);
            }, 500);
        }
        
        // Force reload Bootstrap Icons
        function reloadBootstrapIcons() {
            console.log('Attempting to reload Bootstrap Icons...');
            
            // Remove existing Bootstrap Icons link
            var existingLink = document.querySelector('link[href*="bootstrap-icons"]');
            if (existingLink) {
                existingLink.remove();
            }
            
            // Add new Bootstrap Icons link with cache busting
            var newLink = document.createElement('link');
            newLink.rel = 'stylesheet';
            newLink.href = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css?v=' + Date.now();
            newLink.crossOrigin = 'anonymous';
            document.head.appendChild(newLink);
            
            console.log('Bootstrap Icons CSS reloaded');
        }
    </script>
    
    <!-- CSRF Token Meta Tag -->
    <?php 
    // Ensure CSRF token meta tag is always present after bootstrap
    $headerCsrf = isset($csrfToken) ? $csrfToken : (function_exists('csrf_token') ? csrf_token() : '');
    if (!empty($headerCsrf)): ?>
        <meta name="csrf-token" content="<?php echo htmlspecialchars($headerCsrf); ?>">
    <?php endif; ?>
</head>
<body<?php if ($bodyClasses): ?> class="<?php echo $bodyClasses; ?>"<?php endif; ?>>
    <!-- Top Navigation -->
    <nav class="navbar navbar-dark sticky-top bg-dark py-1 shadow navbar-main">
        <div class="container-fluid">
            <?php $brandHome = $user->isSuperAdmin($currentUser['id']) ? 'admin_dashboard' : 'zone_manage'; ?>
            <a class="navbar-brand d-flex align-items-center px-2" href="?page=<?php echo $brandHome; ?>">
                <?php if (!empty($branding['site_logo'])): ?>
                    <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" class="me-2 brand-logo">
                <?php endif; ?>
                <span class="text-truncate brand-text"><?php echo htmlspecialchars($branding['site_name']); ?></span>
            </a>
            
            <div class="dropdown">
                <a class="nav-link dropdown-toggle text-white px-3 navbar-dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                     <i class="bi bi-person me-1"></i>
                     <?php echo htmlspecialchars($currentUser['username']); ?>
                 </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header">
                        <i class="bi bi-person-circle me-2"></i>
                        <?php echo htmlspecialchars($currentUser['username']); ?>
                    </h6></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="?page=profile">
                        <i class="bi bi-person-gear me-2"></i>Profile Settings
                    </a></li>
                    <?php if ($user->isSuperAdmin($currentUser['id'])): ?>
                    <li><a class="dropdown-item" href="#" onclick="toggleTheme()">
                        <i class="bi bi-palette me-2"></i>Theme Selection
                    </a></li>
                    <?php endif; ?>
                    <?php if ($user->isSuperAdmin($currentUser['id'])): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header text-danger">
                        <i class="bi bi-award me-2"></i>
                        Super Admin
                    </h6></li>
                    <li><a class="dropdown-item" href="?page=admin_dashboard">
                        <i class="bi bi-speedometer2 me-2"></i>System Administration
                    </a></li>
                    <li><a class="dropdown-item" href="?page=admin_users">
                        <i class="bi bi-people me-2"></i>User Management
                    </a></li>
                    <li><a class="dropdown-item" href="?page=admin_tenants">
                        <i class="bi bi-building me-2"></i>Tenant Management
                    </a></li>
                    <li><a class="dropdown-item" href="?page=admin_settings">
                        <i class="bi bi-gear me-2"></i>Global Settings
                    </a></li>
                    <li><a class="dropdown-item" href="?page=admin_dns_settings">
                        <i class="bi bi-globe me-2"></i>DNS Settings
                    </a></li>
                    <li><a class="dropdown-item" href="?page=admin_email_settings">
                        <i class="bi bi-envelope me-2"></i>Email Settings
                    </a></li>
                    <li><a class="dropdown-item" href="?page=admin_license">
                        <i class="bi bi-shield-lock me-2"></i>License Management
                    </a></li>
                    <li><a class="dropdown-item" href="?page=admin_audit">
                        <i class="bi bi-clipboard-data me-2"></i>Audit Logs
                    </a></li>
                    <?php else: ?>
                    <?php 
                    // Check if user has tenant access (non-super admin with tenant assignments)
                    $userTenants = $user->getUserTenants($currentUser['id']);
                    if (!empty($userTenants)): 
                    ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="?page=admin_tenants">
                        <i class="bi bi-building-gear me-2"></i>Tenant Management
                    </a></li>
                    <?php endif; ?>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="?page=logout">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </a></li>
                </ul>
            </div>
        </div>
    </nav>
