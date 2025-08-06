-- =============================================================================
-- PDNS Console - Complete Database Schema
-- =============================================================================
-- This file contains both the original PowerDNS MySQL backend schema
-- and the PDNS Console administrative extensions
-- =============================================================================

-- =============================================================================
-- PART 1: PowerDNS Core Tables (REQUIRED for PowerDNS operation)
-- =============================================================================

-- Domains table - stores DNS zones
CREATE TABLE domains (
  id                    INT AUTO_INCREMENT,
  name                  VARCHAR(255) NOT NULL,
  master                VARCHAR(128) DEFAULT NULL,
  last_check            INT DEFAULT NULL,
  type                  VARCHAR(8) NOT NULL,
  zone_type             ENUM('forward', 'reverse') NOT NULL DEFAULT 'forward',
  notified_serial       INT UNSIGNED DEFAULT NULL,
  account               VARCHAR(40) CHARACTER SET 'utf8' DEFAULT NULL,
  options               VARCHAR(64000) DEFAULT NULL,
  catalog               VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE UNIQUE INDEX name_index ON domains(name);
CREATE INDEX catalog_idx ON domains(catalog);

-- Records table - stores DNS records
CREATE TABLE records (
  id                    BIGINT AUTO_INCREMENT,
  domain_id             INT DEFAULT NULL,
  name                  VARCHAR(255) DEFAULT NULL,
  type                  VARCHAR(10) DEFAULT NULL,
  content               VARCHAR(64000) DEFAULT NULL,
  ttl                   INT DEFAULT NULL,
  prio                  INT DEFAULT NULL,
  disabled              TINYINT(1) DEFAULT 0,
  ordername             VARCHAR(255) BINARY DEFAULT NULL,
  auth                  TINYINT(1) DEFAULT 1,
  PRIMARY KEY (id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE INDEX nametype_index ON records(name,type);
CREATE INDEX domain_id ON records(domain_id);
CREATE INDEX ordername ON records (ordername);

-- Supermasters table - for automatic provisioning
CREATE TABLE supermasters (
  ip                    VARCHAR(64) NOT NULL,
  nameserver            VARCHAR(255) NOT NULL,
  account               VARCHAR(40) CHARACTER SET 'utf8' NOT NULL,
  PRIMARY KEY (ip, nameserver)
) Engine=InnoDB CHARACTER SET 'latin1';

-- Comments table - for record comments
CREATE TABLE comments (
  id                    INT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  name                  VARCHAR(255) NOT NULL,
  type                  VARCHAR(10) NOT NULL,
  modified_at           INT NOT NULL,
  account               VARCHAR(40) CHARACTER SET 'utf8' DEFAULT NULL,
  comment               TEXT CHARACTER SET 'utf8' NOT NULL,
  PRIMARY KEY (id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE INDEX comments_name_type_idx ON comments (name, type);
CREATE INDEX comments_order_idx ON comments (domain_id, modified_at);

-- Domain metadata table - for domain-specific metadata
CREATE TABLE domainmetadata (
  id                    INT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  kind                  VARCHAR(32),
  content               TEXT,
  PRIMARY KEY (id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE INDEX domainmetadata_idx ON domainmetadata (domain_id, kind);

-- Cryptographic keys table - for DNSSEC
CREATE TABLE cryptokeys (
  id                    INT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  flags                 INT NOT NULL,
  active                BOOL,
  published             BOOL DEFAULT 1,
  content               TEXT,
  PRIMARY KEY(id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE INDEX domainidindex ON cryptokeys(domain_id);

-- TSIG keys table - for transaction signatures
CREATE TABLE tsigkeys (
  id                    INT AUTO_INCREMENT,
  name                  VARCHAR(255),
  algorithm             VARCHAR(50),
  secret                VARCHAR(255),
  PRIMARY KEY (id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE UNIQUE INDEX namealgoindex ON tsigkeys(name, algorithm);

-- =============================================================================
-- PART 2: PDNS Console Administrative Extensions
-- =============================================================================

-- Admin users table
CREATE TABLE admin_users (
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
CREATE TABLE tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    contact_email VARCHAR(255),
    soa_contact_override VARCHAR(255) NULL COMMENT 'Optional SOA contact override for this tenant (format: admin.example.com)',
    max_domains INT DEFAULT 0, -- 0 = unlimited
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Nameservers management table
CREATE TABLE nameservers (
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
CREATE TABLE user_tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    tenant_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_tenant (user_id, tenant_id)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Domain-tenant relationships (extend existing domains table usage)
CREATE TABLE domain_tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    tenant_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_domain_tenant (domain_id, tenant_id)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Global settings (including white-labeling and DNS defaults)
CREATE TABLE global_settings (
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
CREATE TABLE dynamic_dns_tokens (
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
CREATE TABLE user_mfa (
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
CREATE TABLE user_sessions (
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
CREATE TABLE custom_record_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(10) NOT NULL UNIQUE,
    description TEXT,
    validation_pattern TEXT, -- Regex pattern for validation
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_name (type_name)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

-- Audit log
CREATE TABLE audit_log (
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
CREATE TABLE licenses (
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
CREATE TABLE license_purchases (
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
CREATE TABLE license_usage (
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
-- PART 3: Initial Data Population
-- =============================================================================

-- Initial global settings data
INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES
-- Branding settings
('site_name', 'PDNS Console', 'Site name displayed in header and titles', 'branding'),
('site_logo', '/assets/img/pdns_logo.png', 'Path to site logo image', 'branding'),
('company_name', 'PDNS Console', 'Company name for branding', 'branding'),
('footer_text', 'Powered by PDNS Console', 'Footer text displayed on all pages', 'branding'),
('theme_name', 'default', 'Bootstrap theme name (default or bootswatch theme)', 'branding'),

-- DNS settings
('soa_contact', 'admin.atmyip.com', 'SOA contact email for new domains', 'dns'),
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

-- Initial custom record types
INSERT INTO custom_record_types (type_name, description, validation_pattern, is_active) VALUES
('NAPTR', 'Naming Authority Pointer', '^[0-9]+ [0-9]+ "[^"]*" "[^"]*" "[^"]*" .+$', true),
('CAA', 'Certification Authority Authorization', '^[0-9]+ [a-zA-Z]+ "[^"]*"$', true),
('TLSA', 'Transport Layer Security Authentication', '^[0-3] [0-1] [0-2] [0-9a-fA-F]+$', true);

-- Initial nameservers data
INSERT INTO nameservers (hostname, priority, is_active) VALUES
('dns1.atmyip.com', 1, 1),
('dns2.atmyip.com', 2, 1);

-- =============================================================================
-- SETUP COMPLETE
-- =============================================================================
-- This schema provides:
-- 1. Full PowerDNS MySQL backend compatibility
-- 2. Multi-tenant administration capabilities with tenant-specific SOA overrides
-- 3. Advanced security features (2FA, encryption, audit logging)
-- 4. Commercial licensing system
-- 5. Dynamic DNS API support
-- 6. White-label branding system
-- 7. Centralized nameserver management with automatic NS record updates
-- 8. Tenant-specific SOA contact override functionality
-- =============================================================================
