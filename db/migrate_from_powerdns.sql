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
    soa_contact_override VARCHAR(255) NULL,
    max_domains INT DEFAULT 0, -- 0 = unlimited
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Nameservers management table
CREATE TABLE IF NOT EXISTS nameservers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hostname VARCHAR(255) NOT NULL,
    priority INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_hostname (hostname),
    INDEX idx_priority_active (priority, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    category ENUM('branding', 'dns', 'security', 'system', 'licensing', 'email') DEFAULT 'system',
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

-- Per-record single comment table
CREATE TABLE IF NOT EXISTS record_comments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    record_id BIGINT NOT NULL,
    domain_id INT NOT NULL,
    user_id INT NULL,
    username VARCHAR(100) NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_record (record_id),
    INDEX idx_record_id (record_id),
    INDEX idx_domain_record (domain_id, record_id),
    CONSTRAINT fk_record_comments_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_record_comments_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    CONSTRAINT fk_record_comments_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =============================================================================
-- PART 4: Handle Existing Installations - Migrate Old Settings
-- =============================================================================

-- Add soa_contact_override column to existing tenants table if it doesn't exist
SET @soa_column_exists = 0;
SELECT COUNT(*) INTO @soa_column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'tenants' 
  AND COLUMN_NAME = 'soa_contact_override';

SET @sql = IF(@soa_column_exists = 0, 
    'ALTER TABLE tenants ADD COLUMN soa_contact_override VARCHAR(255) NULL COMMENT ''Optional SOA contact override for this tenant (format: admin.example.com)'' AFTER contact_email', 
    'SELECT "soa_contact_override column already exists" as status');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Migrate existing nameserver settings from global_settings to nameservers table
INSERT IGNORE INTO nameservers (hostname, priority, is_active)
SELECT setting_value, 1, 1 FROM global_settings WHERE setting_key = 'primary_nameserver' AND setting_value IS NOT NULL AND setting_value != ''
UNION ALL
SELECT setting_value, 2, 1 FROM global_settings WHERE setting_key = 'secondary_nameserver' AND setting_value IS NOT NULL AND setting_value != '';

-- Remove old nameserver settings from global_settings
DELETE FROM global_settings WHERE setting_key IN ('primary_nameserver', 'secondary_nameserver', 'additional_nameservers');

-- =============================================================================
-- PART 5: Populate Initial Configuration Data
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
('license_key_length', '32', 'Length of generated license keys', 'licensing'),

-- Email/SMTP settings
('smtp_host', 'smtp.example.com', 'SMTP server hostname', 'email'),
('smtp_port', '587', 'SMTP server port (587 for TLS, 465 for SSL, 25 for no encryption)', 'email'),
('smtp_secure', 'starttls', 'SMTP encryption type (tls, ssl, or empty for none)', 'email'),
('smtp_username', '', 'SMTP authentication username', 'email'),
('smtp_password', '', 'SMTP authentication password (encrypted)', 'email'),
('smtp_from_email', 'noreply@example.com', 'From email address for system emails', 'email'),
('smtp_from_name', 'PDNS Console', 'From name for system emails', 'email'),

-- File upload settings
('max_upload_size', '5242880', 'Maximum file upload size in bytes (5MB)', 'system'),
('allowed_logo_types', 'image/jpeg,image/png,image/gif', 'Allowed logo file MIME types', 'system'),

-- Pagination settings
('default_records_per_page', '25', 'Default number of records per page', 'system'),
('max_records_per_page', '100', 'Maximum number of records per page', 'system'),

-- System settings
('timezone', 'UTC', 'System timezone', 'system');

-- Insert custom record types
INSERT IGNORE INTO custom_record_types (type_name, description, validation_pattern, is_active) VALUES
('NAPTR', 'Naming Authority Pointer', '^[0-9]+ [0-9]+ "[^"]*" "[^"]*" "[^"]*" .+$', true),
('CAA', 'Certification Authority Authorization', '^[0-9]+ [a-zA-Z]+ "[^"]*"$', true),
('TLSA', 'Transport Layer Security Authentication', '^[0-3] [0-1] [0-2] [0-9a-fA-F]+$', true);

-- Insert initial nameservers data
INSERT IGNORE INTO nameservers (hostname, priority, is_active) VALUES
('ns1.yourdomain.com', 1, 1),
('ns2.yourdomain.com', 2, 1);

-- =============================================================================
-- PART 6: Create Default Admin User (optional - uncomment to use)
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
-- 2. Update DNS settings via the web interface Settings page:
--    - Update nameservers in the nameservers table (replaces old global_settings)
--    - Configure SOA contact and timing settings
-- 3. Create tenants and assign your existing domains to them
-- 4. Test the PDNS Console web interface
-- 5. Configure your PowerDNS to read from this database
-- 6. Verify nameserver changes propagate to all domains automatically

-- =============================================================================
-- VERIFICATION QUERIES (Run these manually after migration)
-- =============================================================================

-- Check all tables exist:
-- SHOW TABLES;

-- Verify zone_type was added to domains:
-- DESCRIBE domains;

-- Check global settings were populated:
-- SELECT COUNT(*) FROM global_settings;

-- Verify nameservers table was created and populated:
-- SELECT * FROM nameservers ORDER BY priority;

-- Check tenants table has soa_contact_override column:
-- DESCRIBE tenants;

-- Verify your existing data is intact:
-- SELECT COUNT(*) FROM domains;
-- SELECT COUNT(*) FROM records;

-- =============================================================================
-- PART 5: Password Reset Functionality
-- =============================================================================

-- Password reset tokens table
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Notes:
-- 1. This script is safe to run multiple times (uses IF NOT EXISTS and IGNORE)
-- 2. All existing PowerDNS data is preserved
-- 3. Adds zone_type column to domains table for forward/reverse zone support
-- 4. Creates all administrative tables needed for PDNS Console
-- 5. Migrates old nameserver settings from global_settings to nameservers table
-- 6. Adds soa_contact_override column to tenants table for tenant-specific SOA contacts
-- 7. Adds password reset tokens table for secure password recovery
-- 8. Populates default configuration - update nameservers via web interface
-- 9. Automatic nameserver changes will propagate to all domains
-- =============================================================================
