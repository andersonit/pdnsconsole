<?php
/**
 * PDNS Console Settings Class
 * 
 * Manages global settings including branding, DNS defaults, and system configuration
 */

class Settings {
    private $db;
    private static $cache = [];
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Get a setting value
     */
    public function get($key, $default = null) {
        // Check cache first
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        
        $result = $this->db->fetch(
            "SELECT setting_value FROM global_settings WHERE setting_key = ?",
            [$key]
        );
        
        $value = $result ? $result['setting_value'] : $default;
        
        // Cache the result
        self::$cache[$key] = $value;
        
        return $value;
    }
    
    /**
     * Set a setting value
     */
    public function set($key, $value, $description = null, $category = 'system') {
        $existing = $this->db->fetch(
            "SELECT id FROM global_settings WHERE setting_key = ?",
            [$key]
        );
        
        if ($existing) {
            // Update existing setting
            $this->db->execute(
                "UPDATE global_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?",
                [$value, $key]
            );
        } else {
            // Insert new setting
            $this->db->execute(
                "INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES (?, ?, ?, ?)",
                [$key, $value, $description, $category]
            );
        }
        
        // Update cache
        self::$cache[$key] = $value;
        
        return true;
    }
    
    /**
     * Get all settings by category
     */
    public function getByCategory($category) {
        return $this->db->fetchAll(
            "SELECT setting_key, setting_value, description FROM global_settings WHERE category = ? ORDER BY setting_key",
            [$category]
        );
    }
    
    /**
     * Get all settings
     */
    public function getAll() {
        return $this->db->fetchAll(
            "SELECT setting_key, setting_value, description, category FROM global_settings ORDER BY category, setting_key"
        );
    }
    
    /**
     * Update multiple settings at once
     */
    public function updateMultiple($settings) {
        $this->db->beginTransaction();
        
        try {
            foreach ($settings as $key => $value) {
                $this->set($key, $value);
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Delete a setting
     */
    public function delete($key) {
        $result = $this->db->execute(
            "DELETE FROM global_settings WHERE setting_key = ?",
            [$key]
        );
        
        // Remove from cache
        unset(self::$cache[$key]);
        
        return $result > 0;
    }
    
    /**
     * Get branding settings
     */
    public function getBranding() {
        return [
            'site_name' => $this->get('site_name', 'PDNS Console'),
            'site_logo' => $this->get('site_logo', '/assets/img/pdns_logo.png'),
            'company_name' => $this->get('company_name', 'PDNS Console'),
            'footer_text' => $this->get('footer_text', 'Powered by PDNS Console'),
            'theme_name' => $this->get('theme_name', 'default')
        ];
    }
    
    /**
     * Get DNS default settings
     */
    public function getDnsDefaults() {
        return [
            'primary_nameserver' => $this->get('primary_nameserver', 'dns1.example.com'),
            'secondary_nameserver' => $this->get('secondary_nameserver', 'dns2.example.com'),
            'soa_contact' => $this->get('soa_contact', 'admin.example.com'),
            'default_ttl' => (int)$this->get('default_ttl', 3600),
            'soa_refresh' => (int)$this->get('soa_refresh', 10800),
            'soa_retry' => (int)$this->get('soa_retry', 3600),
            'soa_expire' => (int)$this->get('soa_expire', 604800),
            'soa_minimum' => (int)$this->get('soa_minimum', 86400)
        ];
    }
    
    /**
     * Get security settings
     */
    public function getSecuritySettings() {
        return [
            'session_timeout' => (int)$this->get('session_timeout', 3600),
            'max_login_attempts' => (int)$this->get('max_login_attempts', 5)
        ];
    }
    
    /**
     * Get system settings
     */
    public function getSystemSettings() {
        return [
            'default_tenant_domains' => (int)$this->get('default_tenant_domains', 0),
            'records_per_page' => (int)$this->get('records_per_page', 25)
        ];
    }
    
    /**
     * Get licensing settings
     */
    public function getLicensingSettings() {
        return [
            'license_mode' => $this->get('license_mode', 'freemium'),
            'free_domain_limit' => (int)$this->get('free_domain_limit', 5),
            'commercial_license_price' => (int)$this->get('commercial_license_price', 50),
            'license_enforcement' => (bool)$this->get('license_enforcement', true),
            'trial_period_days' => (int)$this->get('trial_period_days', 30),
            'license_key_length' => (int)$this->get('license_key_length', 32)
        ];
    }
    
    /**
     * Get theme CSS URL based on current theme setting
     */
    public function getThemeUrl() {
        $theme = $this->get('theme_name', 'default');
        
        if ($theme === 'default') {
            return 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
        } else {
            return "https://cdn.jsdelivr.net/npm/bootswatch@5.3.7/dist/{$theme}/bootstrap.min.css";
        }
    }
    
    /**
     * Get available themes
     */
    public function getAvailableThemes() {
        return AVAILABLE_THEMES;
    }
    
    /**
     * Get current theme name
     */
    public function getCurrentTheme() {
        return $this->get('theme_name', 'default');
    }
    
    /**
     * Set theme
     */
    public function setTheme($theme) {
        if ($this->isValidTheme($theme)) {
            return $this->set('theme_name', $theme, 'Current Bootstrap theme', 'branding');
        }
        return false;
    }
    
    /**
     * Validate theme name
     */
    public function isValidTheme($theme) {
        return array_key_exists($theme, AVAILABLE_THEMES);
    }
    
    /**
     * Get current dark mode setting
     */
    public function isDarkMode() {
        return $this->get('dark_mode', '0') === '1';
    }
    
    /**
     * Set dark mode
     */
    public function setDarkMode($enabled) {
        $value = $enabled ? '1' : '0';
        return $this->set('dark_mode', $value, 'Enable dark mode overlay', 'branding');
    }
    
    /**
     * Check if current theme is naturally dark
     */
    public function isNaturallyDarkTheme($theme = null) {
        if ($theme === null) {
            $theme = $this->getCurrentTheme();
        }
        return in_array($theme, NATURALLY_DARK_THEMES);
    }
    
    /**
     * Get theme with dark mode information
     */
    public function getThemeInfo() {
        $theme = $this->getCurrentTheme();
        $isDarkMode = $this->isDarkMode();
        $isNaturallyDark = $this->isNaturallyDarkTheme($theme);
        
        return [
            'theme' => $theme,
            'theme_name' => AVAILABLE_THEMES[$theme],
            'dark_mode' => $isDarkMode,
            'naturally_dark' => $isNaturallyDark,
            'effective_dark' => $isDarkMode || $isNaturallyDark,
            'theme_url' => $this->getThemeUrl()
        ];
    }
    
    /**
     * Clear settings cache
     */
    public function clearCache() {
        self::$cache = [];
    }
}

?>
