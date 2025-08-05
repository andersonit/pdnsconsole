-- =============================================================================
-- PDNS Console - PowerDNS Extension Migration Script
-- =============================================================================
-- This script extends an existing PowerDNS database to work with PDNS Console
-- It adds all the administrative tables and features while preserving existing
-- PowerDNS domains and records data
-- =============================================================================

-- Set SQL mode for compatibility
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Start transaction for safety
START TRANSACTION;

-- =============================================================================
-- PART 1: Check for existing PowerDNS tables
-- =============================================================================
-- This script assumes you have the basic PowerDNS tables:
-- domains, records, supermasters, comments, domainmetadata, cryptokeys, tsigkeys

-- =============================================================================
-- PART 2: Add zone_type column to domains table if it doesn't exist
-- =============================================================================

-- Check if zone_type column exists in domains table
SET @column_exists = 0;
SELECT COUNT(*) INTO @column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'domains' 
  AND COLUMN_NAME = 'zone_type';

-- Add zone_type column if it doesn't exist
SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE domains ADD COLUMN zone_type ENUM(''forward'', ''reverse'') NOT NULL DEFAULT ''forward'' AFTER type', 
    'SELECT "zone_type column already exists" as status');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- PART 3: Create PDNS Console Administrative Tables
-- =============================================================================

-- Admin users table
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'tenant_admin') NOT NULL DEFAULT 'tenant_admin',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Tenant organizations
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    contact_email VARCHAR(255),
    max_domains INT DEFAULT 0, -- 0 = unlimited
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- User-tenant relationships
CREATE TABLE IF NOT EXISTS user_tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tenant_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_tenant (user_id, tenant_id)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Domain-tenant relationships (extend existing domains table usage)
CREATE TABLE IF NOT EXISTS domain_tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    tenant_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_domain_tenant (domain_id, tenant_id)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Global settings (including white-labeling and DNS defaults)
CREATE TABLE IF NOT EXISTS global_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    description TEXT,
    category ENUM('branding', 'dns', 'security', 'system', 'licensing') DEFAULT 'system',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key),
    INDEX idx_category (category)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Dynamic DNS access tokens for API (ddclient compatible)
CREATE TABLE IF NOT EXISTS dynamic_dns_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    domain_id INT NOT NULL,
    tenant_id INT NOT NULL,
    allowed_records TEXT, -- JSON array of allowed record names/types (A, AAAA)
    is_active BOOLEAN DEFAULT TRUE,
    rate_limit_count INT DEFAULT 0,
    rate_limit_reset TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    last_used TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_domain_id (domain_id)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Two-Factor Authentication (encrypted storage)
CREATE TABLE IF NOT EXISTS user_mfa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    secret_encrypted TEXT NOT NULL, -- AES encrypted TOTP secret
    is_enabled BOOLEAN DEFAULT FALSE,
    backup_codes_encrypted TEXT, -- AES encrypted JSON array of backup codes
    last_used TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_mfa (user_id)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Database session management
CREATE TABLE IF NOT EXISTS user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Custom record types (admin configurable)
CREATE TABLE IF NOT EXISTS custom_record_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(10) NOT NULL UNIQUE,
    description TEXT,
    validation_pattern TEXT, -- Regex pattern for validation
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_name (type_name)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Audit log
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(100),
    record_id BIGINT,
    old_values TEXT,
    new_values TEXT,
    metadata TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_action (action)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- License management
CREATE TABLE IF NOT EXISTS licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(255) NOT NULL UNIQUE,
    license_type ENUM('free', 'commercial') NOT NULL DEFAULT 'free',
    max_domains INT DEFAULT 5, -- 5 for free, 0 for unlimited commercial
    contact_email VARCHAR(255),
    installation_fingerprint VARCHAR(64),
    activation_count INT DEFAULT 0,
    max_activations INT DEFAULT 3,
    license_data TEXT, -- Decoded license information (JSON)
    is_active BOOLEAN DEFAULT TRUE,
    last_validated TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL, -- NULL for lifetime licenses
    INDEX idx_license_key (license_key),
    INDEX idx_license_type (license_type),
    INDEX idx_fingerprint (installation_fingerprint)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- License purchases tracking
CREATE TABLE IF NOT EXISTS license_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NOT NULL,
    payment_method VARCHAR(50),
    payment_id VARCHAR(255), -- Payment processor transaction ID
    amount DECIMAL(10,2),
    currency VARCHAR(3) DEFAULT 'USD',
    purchase_email VARCHAR(255) NOT NULL,
    billing_name VARCHAR(255),
    billing_address TEXT,
    payment_status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    INDEX idx_payment_id (payment_id),
    INDEX idx_purchase_email (purchase_email)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- License usage tracking
CREATE TABLE IF NOT EXISTS license_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NOT NULL,
    tenant_id INT,
    domain_count INT DEFAULT 0,
    last_checked TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    usage_date DATE DEFAULT (CURRENT_DATE),
    FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
    UNIQUE KEY unique_license_date (license_id, usage_date),
    INDEX idx_license_usage (license_id, usage_date)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- =============================================================================
-- PART 4: Populate Initial Configuration Data
-- =============================================================================

-- Insert global settings
INSERT IGNORE INTO global_settings (setting_key, setting_value, description, category) VALUES
-- Branding settings
('site_name', 'PDNS Console', 'Site name displayed in header and titles', 'branding'),
('site_logo', '/assets/img/pdns_logo.png', 'Path to site logo image', 'branding'),
('company_name', 'PDNS Console', 'Company name for branding', 'branding'),
('footer_text', 'Powered by PDNS Console', 'Footer text displayed on all pages', 'branding'),
('theme_name', 'default', 'Bootstrap theme name (default or bootswatch theme)', 'branding'),

-- DNS settings
('primary_nameserver', 'ns1.yourdomain.com', 'Primary nameserver for new domains', 'dns'),
('secondary_nameserver', 'ns2.yourdomain.com', 'Secondary nameserver for new domains', 'dns'),
('soa_contact', 'admin.yourdomain.com', 'SOA contact email for new domains', 'dns'),
('default_ttl', '3600', 'Default TTL for new records', 'dns'),
('soa_refresh', '10800', 'SOA refresh interval (seconds)', 'dns'),
('soa_retry', '3600', 'SOA retry interval (seconds)', 'dns'),
('soa_expire', '604800', 'SOA expire interval (seconds)', 'dns'),
('soa_minimum', '86400', 'SOA minimum TTL (seconds)', 'dns'),

-- System settings
('session_timeout', '3600', 'Session timeout in seconds', 'security'),
('max_login_attempts', '5', 'Maximum failed login attempts before lockout', 'security'),
('default_tenant_domains', '0', 'Default maximum domains per tenant (0=unlimited)', 'system'),
('records_per_page', '25', 'Number of records to display per page', 'system'),

-- License settings
('license_mode', 'freemium', 'License mode: freemium or commercial', 'licensing'),
('free_domain_limit', '5', 'Maximum domains allowed on free license', 'licensing'),
('commercial_license_price', '50', 'Price for commercial license in USD', 'licensing'),
('license_enforcement', '1', 'Enable/disable license enforcement (1=enabled, 0=disabled)', 'licensing'),
('trial_period_days', '30', 'Trial period for commercial features in days', 'licensing'),
('license_key_length', '32', 'Length of generated license keys', 'licensing');

-- Insert custom record types
INSERT IGNORE INTO custom_record_types (type_name, description, validation_pattern, is_active) VALUES
('NAPTR', 'Naming Authority Pointer', '^[0-9]+ [0-9]+ "[^"]*" "[^"]*" "[^"]*" .+$', true),
('CAA', 'Certification Authority Authorization', '^[0-9]+ [a-zA-Z]+ "[^"]*"$', true),
('TLSA', 'Transport Layer Security Authentication', '^[0-3] [0-1] [0-2] [0-9a-fA-F]+$', true);

-- =============================================================================
-- PART 5: Create Default Admin User (optional - uncomment to use)
-- =============================================================================
-- IMPORTANT: Uncomment these lines and change the password after running the script!

-- INSERT IGNORE INTO admin_users (username, email, password_hash, role, is_active) VALUES
-- ('admin', 'admin@localhost', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', 1);
-- 
-- Default password is 'password' - CHANGE THIS IMMEDIATELY!
-- Use the reset_admin_password.php script or create_admin.php CLI script

-- =============================================================================
-- Migration Complete
-- =============================================================================

COMMIT;

-- =============================================================================
-- POST-MIGRATION STEPS
-- =============================================================================
-- 1. Create your first admin user using cli/create_admin.php
-- 2. Update DNS settings in global_settings table with your nameservers
-- 3. Create tenants and assign your existing domains to them
-- 4. Test the PDNS Console web interface
-- 5. Configure your PowerDNS to read from this database

-- =============================================================================
-- VERIFICATION QUERIES (Run these manually after migration)
-- =============================================================================

-- Check all tables exist:
-- SHOW TABLES;

-- Verify zone_type was added to domains:
-- DESCRIBE domains;

-- Check global settings were populated:
-- SELECT COUNT(*) FROM global_settings;

-- Verify your existing data is intact:
-- SELECT COUNT(*) FROM domains;
-- SELECT COUNT(*) FROM records;

-- =============================================================================
-- Notes:
-- 1. This script is safe to run multiple times (uses IF NOT EXISTS and IGNORE)
-- 2. All existing PowerDNS data is preserved
-- 3. Adds zone_type column to domains table for forward/reverse zone support
-- 4. Creates all administrative tables needed for PDNS Console
-- 5. Populates default configuration - update DNS settings to match your setup
-- =============================================================================
