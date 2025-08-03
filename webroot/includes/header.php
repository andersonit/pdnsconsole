<?php
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
    
    <!-- Bootstrap CSS Theme -->
    <link href="<?php echo $settings->getThemeUrl(); ?>" rel="stylesheet" id="theme-stylesheet" data-theme="<?php echo htmlspecialchars($settings->getCurrentTheme()); ?>">
    
    <!-- Font Awesome -->
        <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Font Awesome (fallback) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer">
    
    <!-- Fallback Font Awesome from different CDN -->
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.4.0/css/all.css" crossorigin="anonymous">
    
    <!-- Custom CSS -->
    <link href="assets/css/custom.css" rel="stylesheet">
    
    <!-- Theme System CSS -->
    <link href="assets/css/theme-system.css" rel="stylesheet">
    
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
            // Check if Font Awesome is loaded
            checkFontAwesome();
            
            // Add tooltips to buttons
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
        
        // Check if Font Awesome is loaded and provide fallbacks
        function checkFontAwesome() {
            // Create a test element to check if FA is loaded
            var testElement = document.createElement('i');
            testElement.className = 'fas fa-home';
            testElement.style.position = 'absolute';
            testElement.style.left = '-9999px';
            document.body.appendChild(testElement);
            
            // Check if the icon is rendered
            setTimeout(function() {
                var computedStyle = window.getComputedStyle(testElement, ':before');
                var content = computedStyle.getPropertyValue('content');
                
                if (!content || content === 'none' || content === '""') {
                    console.warn('Font Awesome not loaded, using fallbacks');
                    useFallbackIcons();
                } else {
                    console.log('Font Awesome loaded successfully');
                }
                
                document.body.removeChild(testElement);
            }, 100);
        }
        
        // Use fallback icons when Font Awesome fails to load
        function useFallbackIcons() {
            const iconMap = {
                'fa-user': '👤',
                'fa-user-circle': '👤',
                'fa-user-edit': '✏️',
                'fa-shield-alt': '🛡️',
                'fa-key': '🔑',
                'fa-palette': '🎨',
                'fa-crown': '👑',
                'fa-users': '👥',
                'fa-building': '🏢',
                'fa-cog': '⚙️',
                'fa-clipboard-list': '📋',
                'fa-sign-out-alt': '🚪',
                'fa-globe': '🌍',
                'fa-dns': '🌐',
                'fa-chart-bar': '📊',
                'fa-heartbeat': '💗',
                'fa-plus-circle': '➕',
                'fa-eye': '👁️',
                'fa-search': '🔍',
                'fa-plus': '➕',
                'fa-rocket': '🚀',
                'fa-check-circle': '✅',
                'fa-circle-notch': '⏳'
            };
            
            // Replace all Font Awesome icons with emoji fallbacks
            document.querySelectorAll('[class*="fa-"]').forEach(function(element) {
                const classes = element.className.split(' ');
                const faClass = classes.find(cls => cls.startsWith('fa-') && cls !== 'fas' && cls !== 'far' && cls !== 'fab');
                
                if (faClass && iconMap[faClass]) {
                    element.innerHTML = iconMap[faClass];
                    element.className = element.className.replace(/fa[srlbdt]?\s+fa-[\w-]+/g, '');
                    element.style.fontFamily = 'inherit';
                }
            });
        }
    </script>
    
    <!-- CSRF Token Meta Tag -->
    <?php if (isset($csrfToken)): ?>
    <meta name="csrf-token" content="<?php echo $csrfToken; ?>">
    <?php endif; ?>
</head>
<body<?php if ($bodyClasses): ?> class="<?php echo $bodyClasses; ?>"<?php endif; ?>>
    <!-- Top Navigation -->
    <nav class="navbar navbar-dark sticky-top bg-dark p-0 shadow">
        <div class="container-fluid">
            <a class="navbar-brand px-3" href="?page=dashboard">
                <?php if (!empty($branding['site_logo'])): ?>
                    <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" height="30" class="me-2">
                <?php endif; ?>
                <?php echo htmlspecialchars($branding['site_name']); ?>
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
                    <li><a class="dropdown-item" href="?page=profile&tab=security">
                        <i class="bi bi-shield-lock me-2"></i>Security & 2FA
                    </a></li>
                    <li><a class="dropdown-item" href="?page=profile&tab=password">
                        <i class="bi bi-key me-2"></i>Change Password
                    </a></li>
                    <li><a class="dropdown-item" href="#" onclick="toggleTheme()">
                        <i class="bi bi-palette me-2"></i>Theme Selection
                    </a></li>
                    <?php if ($user->isSuperAdmin($currentUser['id'])): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><h6 class="dropdown-header text-danger">
                        <i class="bi bi-award me-2"></i>
                        Super Admin
                    </h6></li>
                    <li><a class="dropdown-item" href="?page=admin_users">
                        <i class="bi bi-people me-2"></i>User Management
                    </a></li>
                    <li><a class="dropdown-item" href="?page=admin_tenants">
                        <i class="bi bi-building me-2"></i>Tenant Management
                    </a></li>
                    <li><a class="dropdown-item" href="?page=admin_settings">
                        <i class="bi bi-gear me-2"></i>Global Settings
                    </a></li>
                    <li><a class="dropdown-item" href="?page=admin_audit">
                        <i class="bi bi-clipboard-data me-2"></i>Audit Logs
                    </a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="?page=logout">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </a></li>
                </ul>
            </div>
        </div>
    </nav>
