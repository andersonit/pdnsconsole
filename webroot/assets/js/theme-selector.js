/**
 * PDNS Console - Advanced Theme Selector Implementation
 * 
 * Features:
 * - Live theme preview without saving
 * - Search functionality
 * - Smooth transitions between themes
 * - Dark mode toggle independent of theme
 */

/**
 * Initialize theme selector functionality
 */
document.addEventListener('DOMContentLoaded', function() {
    // Check if the toggle theme button exists and attach event
    const themeToggleBtn = document.getElementById('theme-toggle-btn');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', showThemeSelector);
    }
    
    // Refresh button is now handled via event delegation
    console.log('Theme selector initialized');
});

/**
 * Show theme selector modal
 */
function showThemeSelector() {
    console.log('Opening theme selector...');
    // Get available themes from API
    fetch('api/theme.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Themes loaded successfully');
                // Ensure dark mode state is correctly set based on body class
                // This makes sure the toggle matches the current active state
                const isDarkMode = document.body.classList.contains('dark-mode');
                data.dark_mode = isDarkMode ? '1' : '0';
                renderThemeModal(data);
            } else {
                console.error('API returned error:', data.error);
                alert('Error loading themes: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Theme API error:', error);
            alert('Error loading themes. Please try again.');
        });
}

/**
 * Render the theme selection modal with all options
 */
function renderThemeModal(themeData) {
    const currentTheme = themeData.current_theme;
    const isDarkMode = themeData.dark_mode === '1' || themeData.effective_dark;
    const availableThemes = themeData.available_themes || {};
    const naturallyDarkThemes = themeData.naturally_dark_themes || [];
    
    // Create theme options for select dropdown
    let themeOptions = '';
    Object.entries(availableThemes).forEach(([key, name]) => {
        const isDarkTheme = naturallyDarkThemes.includes(key);
        const isCurrentTheme = key === currentTheme;
        themeOptions += `<option value="${key}" data-is-dark="${isDarkTheme}" ${isCurrentTheme ? 'selected' : ''}>${name}</option>`;
    });
    
    // Create preview container with theme colors
    const previewColor = getThemeColor(currentTheme, 'primary');
    const textColor = isColorDark(previewColor) ? '#fff' : '#000';
    
    // Create modal HTML
    let modalHTML = `
        <div class="modal fade" id="themeModal" tabindex="-1" aria-labelledby="themeModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="themeModalLabel">
                            <i class="bi bi-palette me-2"></i>Choose a Theme
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Theme selection dropdown -->
                        <div class="mb-3">
                            <label for="themeSelect" class="form-label">Select a theme:</label>
                            <select class="form-select" id="themeSelect">
                                ${themeOptions}
                            </select>
                        </div>
                        
                        <!-- Theme preview -->
                        <div class="mb-3">
                            <label class="form-label">Preview:</label>
                            <div id="themePreview" class="p-3 rounded" style="background-color: ${previewColor}; color: ${textColor}; text-align: center;">
                                <h5 class="mb-1">Theme Preview</h5>
                                <p class="mb-0">This shows how the selected theme will look</p>
                            </div>
                        </div>
                        
                        <!-- Dark mode toggle -->
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="darkModeToggle" ${isDarkMode ? 'checked' : ''}>
                            <label class="form-check-label" for="darkModeToggle">Enable Dark Mode Overlay</label>
                        </div>
                        
                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle me-1"></i>
                            Dark Mode applies a color overlay that works with any theme. 
                            It's helpful when using light themes in dark environments.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="saveThemeBtn" data-bs-dismiss="modal">Save Theme</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove any existing modal with the same ID
    const existingModal = document.getElementById('themeModal');
    if (existingModal) {
        try {
            const bsExistingModal = bootstrap.Modal.getInstance(existingModal) || new bootstrap.Modal(existingModal);
            bsExistingModal.hide();
            bsExistingModal.dispose();
        } catch (e) { /* ignore */ }
        existingModal.remove();
    }
    // Remove any lingering backdrops and reset body state
    document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    
    // Inject modal into document
    const modalContainer = document.createElement('div');
    modalContainer.innerHTML = modalHTML;
    document.body.appendChild(modalContainer);
    
    // Initialize Bootstrap modal
    const themeModalEl = document.getElementById('themeModal');
    const themeModal = new bootstrap.Modal(themeModalEl);
    themeModalEl.addEventListener('hidden.bs.modal', () => {
        // Clean up to restore scrollbars
        setTimeout(() => {
            document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
        }, 0);
        // Dispose and remove the modal element to avoid duplicates
        try { themeModal.dispose(); } catch (e) { /* ignore */ }
        if (themeModalEl && themeModalEl.parentElement) {
            themeModalEl.parentElement.remove();
        }
    }, { once: true });
    themeModal.show();
    
    // Add event listeners
    setupThemeEvents(themeData);
    
    // When modal is shown, make sure everything is properly initialized
    document.getElementById('themeModal').addEventListener('shown.bs.modal', function() {
        // Reset save button
        const saveBtn = document.getElementById('saveThemeBtn');
        if (saveBtn) {
            saveBtn.innerHTML = 'Save Theme';
            saveBtn.disabled = false;
        }
        
        // Make sure theme preview is updated
        const selectedTheme = document.getElementById('themeSelect').value;
        updateThemePreview(selectedTheme);
    });
}

/**
 * Setup event listeners for simplified theme modal
 */
function setupThemeEvents(themeData) {
    // Theme selection dropdown
    const themeSelect = document.getElementById('themeSelect');
    const naturallyDarkThemes = themeData.naturally_dark_themes || [];
    
    if (themeSelect) {
        themeSelect.addEventListener('change', function() {
            const selectedTheme = this.value;
            console.log('Theme selected:', selectedTheme);
            
            // Update the preview color immediately 
            updateThemePreview(selectedTheme);
            
            // Apply the theme to the page
            previewTheme(selectedTheme, naturallyDarkThemes);
        });
    }
    
    // Dark mode toggle
    const darkModeToggle = document.getElementById('darkModeToggle');
    if (darkModeToggle) {
        darkModeToggle.addEventListener('change', function() {
            toggleDarkMode(this.checked);
        });
    }
    
    // Save theme button
    const saveThemeBtn = document.getElementById('saveThemeBtn');
    if (saveThemeBtn) {
        // Direct approach - use data-bs-dismiss for Bootstrap's built-in modal closing
        saveThemeBtn.setAttribute('data-bs-dismiss', 'modal');
        
        // Add event listener
        saveThemeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Save theme button clicked');
            const selectedTheme = document.getElementById('themeSelect').value;
            saveTheme(selectedTheme);
            
            // Let Bootstrap handle the modal closing
        });
    }
    
    // Initialize theme preview
    updateThemePreview(themeData.current_theme);
}

/**
 * Update the theme preview with colors from the selected theme
 */
function updateThemePreview(themeName) {
    const previewElement = document.getElementById('themePreview');
    if (!previewElement) return;
    
    // Get theme colors
    const primaryColor = getThemeColor(themeName, 'primary');
    const textColor = isColorDark(primaryColor) ? '#fff' : '#000';
    
    console.log('Updating theme preview for', themeName, 'with color', primaryColor);
    
    // Apply the colors
    previewElement.style.backgroundColor = primaryColor;
    previewElement.style.color = textColor;
    
    // Update text to show actual theme name
    const themeName1 = document.querySelector('#themeSelect option:checked')?.text || themeName;
    previewElement.querySelector('h5').textContent = themeName1 + ' Theme';
}

/**
 * Fully adopt a theme's styling, including Bootstrap variables
 */
function adoptThemeStyling(theme) {
    // Create a stylesheet that completely resets styling to let the theme take over
    const styleSheet = document.createElement('style');
    styleSheet.id = 'theme-adoption-styles';
    
    // This completely resets all styling to ensure the theme can be fully applied
    styleSheet.textContent = `
        /* Reset all custom styling for theme ${theme} */
        
        /* Force-remove all custom CSS overrides */
        /* Reset all card styling to browser defaults */
        .card {
            all: revert !important; 
        }
        
        /* Re-apply only the Bootstrap classes */
        .card {
            position: relative;
            display: flex;
            flex-direction: column;
            min-width: 0;
            word-wrap: break-word;
            background-clip: border-box;
        }
        
        /* Preserve Bootstrap button styling */
        .btn {
            /* Keep Bootstrap's button styling */
            display: inline-block;
            text-align: center;
            text-decoration: none;
            vertical-align: middle;
            cursor: pointer;
            user-select: none;
            /* Keep the padding and other styling */
            padding: var(--bs-btn-padding-y) var(--bs-btn-padding-x);
            border-radius: var(--bs-btn-border-radius);
        }
        
        /* Remove all hover effects that could interfere */
        .hover-lift:hover,
        .card:hover {
            all: revert !important;
            transform: none !important;
            box-shadow: none !important;
        }
    `;
    
    // Remove any existing theme adoption styles
    const existingStyles = document.getElementById('theme-adoption-styles');
    if (existingStyles) {
        existingStyles.remove();
    }
    
    // Add this stylesheet to the head with highest priority
    document.head.insertBefore(styleSheet, document.head.firstChild);
    
    // Also remove all inline styles from important elements
    setTimeout(() => {
        document.querySelectorAll('.card, .card-header, .card-body, .card-footer, .btn')
            .forEach(el => el.removeAttribute('style'));
    }, 0);
}

/**
 * Preview a theme without saving
 */
function previewTheme(theme, naturallyDarkThemes = []) {
    // Store the selected theme in a data attribute on body for reference
    document.body.setAttribute('data-current-theme', theme);
    
    const isDark = naturallyDarkThemes.includes(theme);
    
    // First, adopt the theme's styling - clears custom CSS
    adoptThemeStyling(theme);
    
    // Construct the theme URL
    let themeUrl;
    if (theme === 'default') {
        themeUrl = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
    } else {
        themeUrl = `https://cdn.jsdelivr.net/npm/bootswatch@5.3.7/dist/${theme.toLowerCase()}/bootstrap.min.css`;
    }
    
    console.log(`Changing theme to ${theme}, applying CSS from ${themeUrl}`);
    
    // Remove ALL existing Bootstrap stylesheets before adding the new one
    document.querySelectorAll('link[href*="bootstrap"]').forEach(link => {
        link.remove();
    });
    
    // Create a fresh theme stylesheet
    const themeStylesheet = document.createElement('link');
    themeStylesheet.id = 'theme-stylesheet';
    themeStylesheet.rel = 'stylesheet';
    themeStylesheet.href = themeUrl;
    document.head.appendChild(themeStylesheet);
    
    // Give the CSS time to load before applying overrides
    setTimeout(() => {
        // Toggle dark mode class based on theme's natural darkness or current state
        const darkModeToggle = document.getElementById('darkModeToggle');
        const bodyHasDarkMode = document.body.classList.contains('dark-mode');
        
        if (darkModeToggle) {
            // If the theme is naturally dark or if dark mode was already enabled, 
            // ensure both the toggle and the body class are synchronized
            if (isDark || bodyHasDarkMode) {
                darkModeToggle.checked = true;
                document.body.classList.add('dark-mode');
            } else {
                darkModeToggle.checked = false;
            }
        }
        
        // Update dashboard card colors - removes custom CSS
        updateDashboardCardColors(theme);
        
        // Remove inline styles only from card elements, not buttons
        document.querySelectorAll('.card, .card-header, .card-body, .card-footer, .bg-primary')
            .forEach(el => el.removeAttribute('style'));
        
        console.log(`Theme ${theme} fully applied with overrides`);
        
        // Reinitialize refresh button
        reinitRefreshButton();
    }, 300); // Longer delay to ensure CSS is fully loaded
}

/**
 * Helper function to properly close the theme modal
 */
function closeThemeModal() {
    console.log('Attempting to close theme modal');
    
    // Try the Bootstrap way first
    try {
        const modal = document.getElementById('themeModal');
        if (modal) {
            const bsModal = bootstrap.Modal.getInstance(modal);
            if (bsModal) {
                bsModal.hide();
                return true;
            }
        }
    } catch (e) {
        console.error('Error closing modal with Bootstrap:', e);
    }
    
    // Fallback to manual DOM manipulation
    try {
        const modal = document.getElementById('themeModal');
        if (modal) {
            modal.classList.remove('show');
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) backdrop.remove();
            return true;
        }
    } catch (e) {
        console.error('Error closing modal manually:', e);
    }
    
    return false;
}

/**
 * Save theme preference to database
 */
function saveTheme(theme) {
    console.log('Saving theme:', theme);
    const darkModeEnabled = document.getElementById('darkModeToggle').checked;
    
    // Store in sessionStorage for page refreshes
    sessionStorage.setItem('pdns_current_theme', theme);
    sessionStorage.setItem('pdns_dark_mode', darkModeEnabled ? '1' : '0');
    
    // First apply the theme immediately - full application
    adoptThemeStyling(theme);
    previewTheme(theme, []); // We don't know naturally dark themes here, but it's ok
    
    // The modal will close automatically because of data-bs-dismiss="modal"
    
    // Make the API request to save settings
    fetch('api/theme.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            theme: theme,
            dark_mode: darkModeEnabled
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            showToast('Theme updated successfully!');
        } else {
            console.error('Theme save error:', data.error);
            alert('Error saving theme: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Theme save error:', error);
        alert('Error saving theme. Please try again.');
    });
}

/**
 * Toggle dark mode overlay
 */
function toggleDarkMode(enabled) {
    document.body.classList.toggle('dark-mode', enabled);
    
    // Ensure dark mode toggle is synchronized with body class
    const darkModeToggle = document.getElementById('darkModeToggle');
    if (darkModeToggle) {
        darkModeToggle.checked = enabled;
    }
    
    // No need to save here - we'll save when the user selects a theme
}

/**
 * Show success toast that auto-closes after 2 seconds
 */
function showToast(message) {
    // Create toast element
    const toastContainer = document.createElement('div');
    toastContainer.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    toastContainer.innerHTML = `
        <div class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="2000" data-bs-autohide="true">
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;
    document.body.appendChild(toastContainer);
    
    // Show toast with autohide after 2 seconds
    const toastElement = toastContainer.querySelector('.toast');
    const toast = new bootstrap.Toast(toastElement, {
        autohide: true,
        delay: 2000
    });
    toast.show();
    
    // Remove after it's hidden
    toastElement.addEventListener('hidden.bs.toast', function() {
        toastContainer.remove();
    });
}

/**
 * Theme color definitions
 * These colors match the primary and secondary colors of each Bootswatch theme
 */
const THEME_COLORS = {
    'default': { primary: '#0d6efd', secondary: '#6c757d' },
    'cerulean': { primary: '#2FA4E7', secondary: '#e9ecef' },
    'cosmo': { primary: '#2780E3', secondary: '#373a3c' },
    'cyborg': { primary: '#2A9FD6', secondary: '#555' },
    'darkly': { primary: '#375a7f', secondary: '#444' },
    'flatly': { primary: '#2C3E50', secondary: '#95a5a6' },
    'journal': { primary: '#EB6864', secondary: '#aaa' },
    'litera': { primary: '#4582EC', secondary: '#868e96' },
    'lumen': { primary: '#158CBA', secondary: '#f0f0f0' },
    'lux': { primary: '#1a1a1a', secondary: '#919aa1' },
    'materia': { primary: '#2196F3', secondary: '#666' },
    'minty': { primary: '#78C2AD', secondary: '#F3969A' },
    'morph': { primary: '#2c3e50', secondary: '#95a5a6' },
    'pulse': { primary: '#593196', secondary: '#A991D4' },
    'quartz': { primary: '#008cba', secondary: '#ddd' },
    'sandstone': { primary: '#325D88', secondary: '#8E8C84' },
    'simplex': { primary: '#D9230F', secondary: '#777' },
    'sketchy': { primary: '#333', secondary: '#555' },
    'slate': { primary: '#3A3F44', secondary: '#7A8288' },
    'solar': { primary: '#B58900', secondary: '#839496' },
    'spacelab': { primary: '#446E9B', secondary: '#999' },
    'superhero': { primary: '#DF691A', secondary: '#4E5D6C' },
    'united': { primary: '#E95420', secondary: '#AEA79F' },
    'vapor': { primary: '#6f42c1', secondary: '#e83e8c' },
    'yeti': { primary: '#008cba', secondary: '#ebebeb' },
    'zephyr': { primary: '#2196F3', secondary: '#6c757d' }
};

/**
 * Get default theme color if the specific theme color is not defined
 */
function getThemeColor(themeName, colorType = 'primary') {
    const defaultColors = {
        primary: '#0d6efd',
        secondary: '#6c757d'
    };
    
    // If we have the theme color defined, return it
    if (THEME_COLORS[themeName] && THEME_COLORS[themeName][colorType]) {
        return THEME_COLORS[themeName][colorType];
    }
    
    // Otherwise return the default color
    return defaultColors[colorType];
}

// Removed initThemePreviews function as we're using a simpler approach

/**
 * Helper function to determine if a color is dark (for text contrast)
 */
function isColorDark(color) {
    // Convert hex to RGB
    let r, g, b;
    if (color.startsWith('#')) {
        const hex = color.substring(1);
        r = parseInt(hex.substr(0, 2), 16);
        g = parseInt(hex.substr(2, 2), 16);
        b = parseInt(hex.substr(4, 2), 16);
    } else if (color.startsWith('rgb')) {
        const rgbValues = color.match(/\d+/g);
        if (rgbValues && rgbValues.length >= 3) {
            r = parseInt(rgbValues[0]);
            g = parseInt(rgbValues[1]);
            b = parseInt(rgbValues[2]);
        } else {
            return true; // Default to dark if parsing fails
        }
    } else {
        return true; // Default to dark for unknown formats
    }
    
    // Calculate perceived brightness (YIQ formula)
    const yiq = ((r * 299) + (g * 587) + (b * 114)) / 1000;
    return yiq < 128; // < 128 is considered dark
}

// Handle refresh button - this preserves the theme settings including dark mode
document.addEventListener('click', function(event) {
    // Check if the clicked element is the refresh button or a child of it
    const refreshBtn = event.target.closest('#refresh-btn');
    if (refreshBtn) {
        event.preventDefault();
        console.log('Refresh button clicked, preserving theme settings...');
        
        // Save current state before reloading
        const isDarkMode = document.body.classList.contains('dark-mode');
        const currentTheme = document.body.getAttribute('data-current-theme') || 
                            document.getElementById('theme-stylesheet')?.getAttribute('data-theme');
        
        // Store theme state in sessionStorage to survive the reload
        if (currentTheme) {
            sessionStorage.setItem('pdns_current_theme', currentTheme);
        }
        sessionStorage.setItem('pdns_dark_mode', isDarkMode ? '1' : '0');
        
        // Force reload the page
        window.location.reload();
    }
});

/**
 * Update dashboard card colors to match the theme's primary color
 */
function updateDashboardCardColors(themeName) {
    console.log('Fully adopting theme:', themeName);
    
    // Remove any custom theme override styles that might exist
    const existingStylesheet = document.getElementById('theme-override-styles');
    if (existingStylesheet) {
        existingStylesheet.remove();
    }
    
    // Create a new stylesheet with our overrides to completely neutralize any custom styles
    const styleSheet = document.createElement('style');
    styleSheet.id = 'theme-override-styles';
    
    // This approach completely removes custom styling to let Bootswatch themes fully control appearance
    styleSheet.textContent = `
        /* Complete theme adoption for ${themeName} */
        
        /* ===== RESET ALL CUSTOM STYLES ===== */
        /* Card reset - remove all custom styling */
        .card {
            box-shadow: none !important;
            border-color: var(--bs-card-border-color, var(--bs-border-color)) !important;
            background-color: var(--bs-card-bg, var(--bs-body-bg)) !important;
        }
        
        /* Remove all hover effects */
        .hover-lift:hover,
        .card:hover {
            transform: none !important;
            box-shadow: none !important;
            border-color: var(--bs-card-border-color, var(--bs-border-color)) !important;
        }
        
        /* Let theme control card headers completely */
        .card-header {
            background-color: var(--bs-card-cap-bg, var(--bs-tertiary-bg)) !important;
        }
        
        /* Ensure bg-primary uses theme primary color */
        .bg-primary, 
        .btn-primary,
        .card-header.bg-primary {
            background-color: var(--bs-primary) !important;
            border-color: var(--bs-primary) !important;
        }
        
        /* Buttons should keep Bootstrap styling but use theme colors */
        /* Reset card footer styles */
        .card-footer {
            background-color: var(--bs-card-cap-bg, var(--bs-tertiary-bg)) !important;
        }
        
        /* Make sure all outline buttons follow theme */
        .btn-outline-primary {
            color: var(--bs-primary) !important;
            border-color: var(--bs-primary) !important;
            background-color: transparent !important;
        }
        
        /* Let buttons use theme's border radius */
        .card, .card-header, .card-footer {
            border-radius: unset !important;
        }
        
        /* Remove any !important styles from various elements */
        [style*="!important"] {
            background-color: unset;
            color: unset;
            border-color: unset;
            border-radius: unset;
        }
    `;
    
    // Add the stylesheet to the document head
    document.head.appendChild(styleSheet);
    
    // Create a script that removes inline styles on important elements
    const script = document.createElement('script');
    script.textContent = `
        // Remove all inline styles from cards but preserve button styling
        document.querySelectorAll('.card, .card-header, .card-footer')
            .forEach(el => el.removeAttribute('style'));
    `;
    document.head.appendChild(script);
    
    // Force a refresh of computed styles
    void document.documentElement.offsetHeight;
}

/**
 * Get theme-specific style properties
 * Different themes have different styles (like rounded corners, shadows, etc.)
 */
function getThemeSpecificStyles(themeName) {
    const themeStyles = {
        'default': {
            btnBorderRadius: '0.375rem',
            cardBorderRadius: '0.375rem'
        },
        'cerulean': {
            btnBorderRadius: '0.25rem',
            cardBorderRadius: '0.25rem'
        },
        'cosmo': {
            btnBorderRadius: '0.375rem',
            cardBorderRadius: '0.25rem'
        },
        'cyborg': {
            btnBorderRadius: '0',
            cardBorderRadius: '0'
        },
        'darkly': {
            btnBorderRadius: '0.375rem',
            cardBorderRadius: '0.25rem'
        },
        'flatly': {
            btnBorderRadius: '0.375rem',
            cardBorderRadius: '0.25rem'
        },
        'journal': {
            btnBorderRadius: '0.25rem',
            cardBorderRadius: '0.25rem'
        },
        'litera': {
            btnBorderRadius: '0.5rem',
            cardBorderRadius: '0.5rem'
        },
        'lumen': {
            btnBorderRadius: '0.4rem',
            cardBorderRadius: '0.4rem'
        },
        'lux': {
            btnBorderRadius: '0',
            cardBorderRadius: '0'
        },
        'materia': {
            btnBorderRadius: '0.125rem',
            cardBorderRadius: '0.125rem'
        },
        'minty': {
            btnBorderRadius: '0.5rem',
            cardBorderRadius: '0.5rem'
        },
        'morph': {
            btnBorderRadius: '50rem',
            cardBorderRadius: '0.25rem'
        },
        'pulse': {
            btnBorderRadius: '0.25rem',
            cardBorderRadius: '0.25rem'
        },
        'quartz': {
            btnBorderRadius: '0',
            cardBorderRadius: '0'
        },
        'sandstone': {
            btnBorderRadius: '0.25rem',
            cardBorderRadius: '0.25rem'
        },
        'simplex': {
            btnBorderRadius: '0.25rem',
            cardBorderRadius: '0.25rem'
        },
        'sketchy': {
            btnBorderRadius: '255px 25px 225px 25px/25px 225px 25px 255px',
            cardBorderRadius: '255px 25px 225px 25px/25px 225px 25px 255px'
        },
        'slate': {
            btnBorderRadius: '0.25rem',
            cardBorderRadius: '0.25rem'
        },
        'solar': {
            btnBorderRadius: '0.25rem',
            cardBorderRadius: '0.25rem'
        },
        'spacelab': {
            btnBorderRadius: '0.25rem',
            cardBorderRadius: '0.25rem'
        },
        'superhero': {
            btnBorderRadius: '0',
            cardBorderRadius: '0'
        },
        'united': {
            btnBorderRadius: '0.25rem',
            cardBorderRadius: '0.25rem'
        },
        'vapor': {
            btnBorderRadius: '0',
            cardBorderRadius: '0'
        },
        'yeti': {
            btnBorderRadius: '0.25rem',
            cardBorderRadius: '0.25rem'
        },
        'zephyr': {
            btnBorderRadius: '0.375rem',
            cardBorderRadius: '0.25rem'
        }
    };
    
    return themeStyles[themeName] || themeStyles['default'];
}

// Function to restore theme state after page load/refresh
function restoreThemeState() {
    // First reset all custom styling
    resetAllCustomStyling();
    
    const savedTheme = sessionStorage.getItem('pdns_current_theme');
    const savedDarkMode = sessionStorage.getItem('pdns_dark_mode');
    
    if (savedTheme) {
        console.log('Restoring saved theme:', savedTheme);
        
        // Remove any existing theme stylesheets
        document.querySelectorAll('link[href*="bootstrap"]').forEach(link => {
            if (link.id !== 'theme-stylesheet') {
                link.remove();
            }
        });
        
        // Apply the theme with a fresh approach
        previewTheme(savedTheme, []);
        
        // Extra application after a delay to ensure it takes effect
        setTimeout(() => {
            updateDashboardCardColors(savedTheme);
            console.log('Re-applied theme colors after delay');
        }, 500);
    }
    
    // Restore dark mode if it was enabled
    if (savedDarkMode === '1') {
        console.log('Restoring dark mode');
        document.body.classList.add('dark-mode');
        
        // Also update any visible dark mode toggle
        const darkModeToggle = document.getElementById('darkModeToggle');
        if (darkModeToggle) {
            darkModeToggle.checked = true;
        }
    }
}

/**
 * Function to reinitialize refresh button
 */
function reinitRefreshButton() {
    const refreshBtn = document.getElementById('refresh-btn');
    if (refreshBtn) {
        console.log('Reinitializing refresh button');
        
        // Remove any existing event listener
        refreshBtn.replaceWith(refreshBtn.cloneNode(true));
        
        // Get new reference to the refresh button
        const newRefreshBtn = document.getElementById('refresh-btn');
        
        // Add event listener
        newRefreshBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Refresh button clicked, preserving theme settings...');
            
            // Save current state before reloading
            const isDarkMode = document.body.classList.contains('dark-mode');
            const currentTheme = document.body.getAttribute('data-current-theme') || 
                                document.getElementById('theme-stylesheet')?.getAttribute('data-theme');
            
            // Store theme state in sessionStorage to survive the reload
            if (currentTheme) {
                sessionStorage.setItem('pdns_current_theme', currentTheme);
            }
            sessionStorage.setItem('pdns_dark_mode', isDarkMode ? '1' : '0');
            
            // Force reload the page
            window.location.reload();
        });
    }
}

/**
 * Reset all custom styles to let theme control everything
 */
function resetAllCustomStyling() {
    // 1. Remove any existing custom overrides
    ['custom-css-override-fix', 'theme-override-styles', 'theme-adoption-styles'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.remove();
    });
    
    // 2. Create the strongest possible CSS reset
    const resetStylesheet = document.createElement('style');
    resetStylesheet.id = 'complete-theme-reset';
    
    resetStylesheet.textContent = `
        /* Complete reset of all custom styling */
        
        /* Reset all Bootstrap components to their defaults */
        .card, .card-header, .card-body, .card-footer {
            box-shadow: none !important;
            transition: none !important;
            transform: none !important;
        }
        
        /* Preserve button styling */
        .btn {
            transition: none !important;
            transform: none !important;
        }
        
        /* Remove all custom border and background colors */
        .card {
            border-color: var(--bs-card-border-color) !important;
            background-color: var(--bs-card-bg) !important;
        }
        
        /* Explicitly set card headers with bg-primary to use theme primary color */
        .card-header.bg-primary {
            background-color: var(--bs-primary) !important;
        }
        
        /* Fix buttons to use theme colors */
        .btn-primary {
            background-color: var(--bs-primary) !important;
            border-color: var(--bs-primary) !important;
        }
        
        /* Disable all hover effects */
        .card:hover, .btn:hover, .hover-lift:hover {
            transform: none !important;
            box-shadow: none !important;
        }
    `;
    
    // Insert at the very beginning of head for highest priority
    document.head.insertBefore(resetStylesheet, document.head.firstChild);
    
    // 3. Remove all inline styles from Bootstrap elements
    document.querySelectorAll('.card, .card-header, .card-footer, .btn, .bg-primary')
        .forEach(el => el.removeAttribute('style'));
        
    console.log('All custom styling has been reset');
}

// Initial setup
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing theme system');
    
    // Reset all custom CSS to ensure theme controls appearance
    resetAllCustomStyling();
    
    // Restore theme state from sessionStorage if available
    restoreThemeState();
    
    // Initialize refresh button
    reinitRefreshButton();
});
