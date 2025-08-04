# PDNS Console - Development Plan

## Project Overview

Development of a comprehensive web-based administration interface for PowerDNS with MySQL backend, featuring multi-tenant architecture with role-based access control and commercial licensing.

### Key Requirements
- Multi-tenant architecture with top-level admin and tenant isolation
- Full DNS record management (A, AAAA, CNAME, MX, TXT, SRV, PTR, NS, SOA)
- DNSSEC key management and creation
- Global nameserver and SOA configuration
- Input validation and security
- Modern, responsive web interface
- Commercial licensing system with domain limits

### Target Environment
- **Product Name**: PDNS Console
- **Domain**: pdnsconsole.com
- **Database**: PowerDNS MySQL backend (clustered, native domains)
- **Installation**: Flexible (root or subdirectory)
- **Licensing**: Open source (5 domain limit) + Commercial ($50 unlimited)

---

## Technical Architecture

### 1. Technology Stack
- **Backend**: PHP 8.x with PDO for database connections
- **Database**: MySQL (PowerDNS schema)
- **Frontend**: Bootstrap 5 (already available in assets/)
- **Authentication**: Session-based with secure password hashing
- **Session Storage**: Database-based sessions for HAProxy compatibility
- **Security**: CSRF protection, input sanitization, SQL prepared statements
- **Encryption**: AES encryption for sensitive data (MFA secrets, backup codes)

### 2. Database Extensions

The PDNS Console extends the standard PowerDNS MySQL schema with additional administrative tables while maintaining full compatibility with the original PowerDNS backend.

**Key Enhancement**: The `domains` table has been extended with a `zone_type` field (`ENUM('forward', 'reverse')`) to support reverse DNS zones for IP address management, enabling proper filtering of PTR records and zone-specific functionality.

#### 2.1 PowerDNS Core Tables (Required)
These are the standard PowerDNS tables that must be present for PowerDNS operation:

```sql
-- Standard PowerDNS MySQL Backend Schema
-- These tables are REQUIRED for PowerDNS operation and must be created first

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

CREATE TABLE supermasters (
  ip                    VARCHAR(64) NOT NULL,
  nameserver            VARCHAR(255) NOT NULL,
  account               VARCHAR(40) CHARACTER SET 'utf8' NOT NULL,
  PRIMARY KEY (ip, nameserver)
) Engine=InnoDB CHARACTER SET 'latin1';

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

CREATE TABLE domainmetadata (
  id                    INT AUTO_INCREMENT,
  domain_id             INT NOT NULL,
  kind                  VARCHAR(32),
  content               TEXT,
  PRIMARY KEY (id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE INDEX domainmetadata_idx ON domainmetadata (domain_id, kind);

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

CREATE TABLE tsigkeys (
  id                    INT AUTO_INCREMENT,
  name                  VARCHAR(255),
  algorithm             VARCHAR(50),
  secret                VARCHAR(255),
  PRIMARY KEY (id)
) Engine=InnoDB CHARACTER SET 'latin1';

CREATE UNIQUE INDEX namealgoindex ON tsigkeys(name, algorithm);
```

#### 2.2 PDNS Console Administrative Tables (New)
These tables extend PowerDNS functionality with multi-tenant administration capabilities:

```sql
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
    max_domains INT DEFAULT 0, -- 0 = unlimited
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

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
    category ENUM('branding', 'dns', 'security', 'system') DEFAULT 'system',
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
    user_id INT NOT NULL,
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

-- Initial global settings data
INSERT INTO global_settings (setting_key, setting_value, description, category) VALUES
-- Branding settings
('site_name', 'PDNS Console', 'Site name displayed in header and titles', 'branding'),
('site_logo', '/assets/img/pdns_logo.png', 'Path to site logo image', 'branding'),
('company_name', 'PDNS Console', 'Company name for branding', 'branding'),
('footer_text', 'Powered by PDNS Console', 'Footer text displayed on all pages', 'branding'),
('theme_name', 'default', 'Bootstrap theme name (default or bootswatch theme)', 'branding'),

-- DNS settings
('primary_nameserver', 'dns1.atmyip.com', 'Primary nameserver for new domains', 'dns'),
('secondary_nameserver', 'dns2.atmyip.com', 'Secondary nameserver for new domains', 'dns'),
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
```

---

## Application Structure

### 3. Directory Structure
```
/config/
├── database.php.sample  # Database configuration template
├── database.php         # Actual config (gitignored)
│── app.php             # Application settings
└── global_settings.php # Global DNS settings
/webroot/
├── index.php                 # Main dashboard/login
├── includes/
│   ├── auth.php            # Authentication functions
│   ├── functions.php       # Utility functions
│   ├── validation.php      # Input validation
│   ├── branding.php        # White-label branding functions
│   ├── mfa.php            # Two-Factor Authentication
│   └── dnssec.php         # DNSSEC management
├── classes/
│   ├── Database.php        # Database connection class
│   ├── User.php           # User management
│   ├── Tenant.php         # Tenant management
│   ├── Domain.php         # Domain operations
│   ├── Record.php         # DNS records management
│   ├── DNSSEC.php         # DNSSEC operations
│   ├── Settings.php       # Global settings management
│   ├── MFA.php            # Two-Factor Authentication with encryption
│   ├── Session.php        # Database session management
│   ├── Encryption.php     # AES encryption for sensitive data
│   └── AuditLog.php       # Logging class
├── console/
│   ├── login.php          # Login page
│   ├── dashboard.php      # Main dashboard
│   ├── domains/
│   │   ├── list.php       # Domain listing
│   │   ├── add.php        # Add domain
│   │   ├── edit.php       # Edit domain
│   │   └── delete.php     # Delete domain
│   ├── records/
│   │   ├── list.php       # Records for a domain
│   │   ├── add.php        # Add single record
│   │   ├── add_bulk.php   # Add multiple records at once
│   │   ├── edit.php       # Edit record
│   │   ├── delete.php     # Delete record
│   │   ├── import.php     # CSV import
│   │   └── export.php     # CSV export
│   ├── dynamic_dns/
│   │   ├── tokens.php     # Manage API tokens
│   │   └── logs.php       # Dynamic DNS logs
│   ├── dnssec/
│   │   ├── manage.php     # DNSSEC management
│   │   └── keys.php       # Key management
│   ├── admin/             # System Administration Dashboard
│   │   ├── dashboard.php  # System admin dashboard
│   │   ├── users.php      # User management (create, edit, assign roles)
│   │   ├── tenants.php    # Tenant management (create, edit, domain limits)
│   │   ├── settings.php   # Global DNS settings (nameservers, SOA defaults)
│   │   ├── branding.php   # White-label branding and theme management
│   │   ├── record_types.php # Custom record type management
│   │   ├── audit.php      # System audit logs and activity monitoring
│   │   ├── licenses.php   # License management and domain usage
│   │   └── system.php     # System information and maintenance tools
│   └── profile.php        # User profile and 2FA settings
├── api/
│   ├── domains.php        # Domain API endpoints
│   ├── records.php        # Records API endpoints
│   ├── dynamic_dns.php    # Dynamic DNS update API
│   └── validation.php     # Real-time validation
├── assets/                # Use existing assets
/cli/
│   └── reset_mfa.php      # Command-line MFA reset script
├── .gitignore            # Exclude config/database.php
/docs/                 # Documentation
```

---

## Feature Specifications

### 4. Authentication & Authorization

#### 4.1 User Roles
- **Super Admin**: Full system access, tenant management, global settings, system administration
- **Tenant Admin**: Full access to assigned tenant domains and users
- **Tenant User**: Limited access to assigned tenant domains (read-only or specific permissions)

#### 4.2 Authentication Flow
1. Login with username/email and password (stored in MySQL)
2. Two-Factor Authentication (TOTP) with backup codes (optional per user)
3. Database-based session management (HAProxy cluster compatible)
4. Role-based page access control
5. Automatic session timeout and cleanup
6. Designed as standalone admin interface (GitHub open-source)

#### 4.3 Two-Factor Authentication & Security
- **TOTP Support**: Time-based One-Time Password using PHP library
- **Encrypted Storage**: MFA secrets and backup codes encrypted with AES-256
- **Backup Codes**: Generated recovery codes for account access
- **User Choice**: Optional 2FA activation per user account
- **Admin Reset**: Super admin can reset 2FA for tenant users
- **CLI Reset**: Command-line script for super admin 2FA reset
- **QR Code**: Easy setup with authenticator apps

#### 4.4 Database Session Management
- **HAProxy Compatible**: Database sessions work with load balancers
- **Session Table**: Dedicated table for session storage
- **Automatic Cleanup**: Expired session removal
- **Multi-Server Support**: Shared sessions across web server cluster
- **Security**: Session hijacking protection with IP/User-Agent validation

### 5. Domain Management

#### 5.1 Domain Operations
- **Create Domain**: 
  - Validate domain name format
  - Check tenant domain limit (0 = unlimited)
  - Auto-create SOA record with global settings
  - Auto-create NS records from global settings
  - Assign to tenant
- **Edit Domain**: Metadata and tenant assignment
- **Delete Domain**: Cascade delete all records and crypto keys
- **Transfer Domain**: Move between tenants (super admin only, respects limits)

#### 5.2 Domain Listing
- Filterable and sortable table
- Search by domain name
- Show record count, DNSSEC status, domain limit usage
- Tenant-filtered view for tenant admins

### 6. DNS Records Management

#### 6.1 Supported Record Types

**Standard Record Types:**
| Type | Validation Requirements |
|------|------------------------|
| A | IPv4 address format |
| AAAA | IPv6 address format |
| CNAME | Valid hostname, no conflicts |
| MX | Priority + valid hostname |
| TXT | Enhanced validation for SPF/DKIM/DMARC + general text |
| SRV | Priority, weight, port, target |
| NS | Valid hostname |
| SOA | Serial, refresh, retry, expire, minimum |
| PTR | Valid hostname for reverse DNS |

**Enhanced TXT Record Validation:**
- **SPF Records**: Validate SPF syntax (`v=spf1` prefix, mechanisms, qualifiers)
- **DKIM Records**: Validate DKIM key format (`v=DKIM1`, key parameters)
- **DMARC Records**: Validate DMARC policy syntax (`v=DMARC1`, policy tags)
- **General TXT**: Standard text validation with proper escaping

**Admin-Configurable Record Types:**
- **Custom Types**: Admin can enable additional record types (NAPTR, CAA, TLSA, etc.)
- **Validation Patterns**: Regex-based validation for custom types
- **Dynamic Forms**: Auto-generated forms based on configured types

#### 6.2 Record Operations
- **Add Single Record**: Form with type-specific validation
- **Add Multiple Records**: Bulk form allowing multiple records before submission
- **Edit Record**: In-place editing with validation
- **Delete Record**: Confirmation dialog
- **Bulk Operations**: Import/export via CSV with downloadable template
- **Record Comments**: Using PowerDNS comments table
- **Dynamic DNS**: API endpoint for automated record updates

#### 6.3 Validation Rules
- Real-time client-side validation
- Server-side validation with detailed error messages
- Check for conflicts (CNAME with other records)
- TTL validation (minimum/maximum values)
- Content format validation per record type

### 7. DNSSEC Management

#### 7.1 Key Management
- **Key Generation**: ECDSA P-256 (ECDSAP256SHA256) as primary algorithm
- **Key Types**: Combined Signing Key (CSK) with flags 257 (Zone + SEP)
- **Key Activation**: Enable/disable keys
- **Key Publishing**: Control DS record publication
- **Key Information Display**: Show Key ID, Algorithm, Bits, Flags, Key Tag, Active/Published status

#### 7.2 DNSSEC Operations
- Enable/disable DNSSEC per domain
- Generate DS records for parent zone
- Display DNSKEY records in proper format
- Validate DNSSEC chain
- Monitor key expiration (12-month rotation interval)
- **Implementation**: Phase 4 (weeks 7-8)

#### 7.3 Key Display Format
- Show keys in PowerDNS format with all metadata
- Export DNSKEY records in DNS zone file format
- Display DS records for parent zone delegation

### 8. Dynamic DNS API

#### 8.1 API Authentication
- Token-based authentication for dynamic updates
- Per-domain token generation with specific record permissions
- Token expiration and usage tracking
- Rate limiting per token

#### 8.2 API Endpoints
- **Update Record**: PUT /api/dynamic_dns.php (ddclient compatible)
- **Get Record**: GET /api/dynamic_dns.php
- **Supported Operations**: A and AAAA record updates for dynamic IP scenarios
- **Response Format**: JSON with success/error status
- **ddclient Compatibility**: Authentication and URL format compatible with ddclient

#### 8.3 Rate Limiting
- **Initial Limit**: 3 requests in 3-minute period
- **Throttled Rate**: After limit exceeded, 1 request per 10 minutes
- **Per-Token Tracking**: Individual rate limits per API token
- **Reset Mechanism**: Automatic rate limit reset after time period

#### 8.4 Token Management
- Generate secure tokens per domain
- Define allowed record names and types per token (A, AAAA only)
- Token activity logging and monitoring
- Automatic token expiration options

#### 8.5 CSV Import/Export
- **Export**: Download current records in CSV format (excludes NS/SOA records)
- **Import**: Upload CSV with validation and preview (existing domains only)
- **Filtering**: Automatically filter out NS and SOA records during import
- **Validation**: Same validation rules as web interface
- **Update Behavior**: Update existing records if they already exist
- **Summary Report**: Show invalid records and import results
- **Template**: Downloadable CSV template with proper headers
- **Supported Formats**: Standard DNS record format (Name, Type, Content, TTL, Priority)

### 9. System Administration Dashboard

#### 9.1 Admin Dashboard Overview
- **Centralized Control Panel**: Dedicated dashboard for super administrators
- **System Statistics**: Overview of total domains, users, tenants, and system health
- **Quick Actions**: Fast access to common administrative tasks
- **Recent Activity**: Summary of recent system changes and user activity
- **System Alerts**: License status, domain limits, and maintenance notifications

#### 9.2 User & Role Management
- **User Administration**: 
  - Create, edit, and deactivate admin users
  - Assign roles: Super Admin, Tenant Admin, Tenant User
  - Password reset and 2FA management for users
  - User activity monitoring and session management
- **Role-Based Permissions**:
  - Super Admin: Full system access and configuration
  - Tenant Admin: Full control over assigned tenant(s)
  - Tenant User: Limited access to specific domains within tenant
- **User Assignment**: Assign users to specific tenants and set domain permissions

#### 9.3 Tenant Management Interface
- **Tenant Operations**:
  - Create and configure tenant organizations
  - Set domain limits per tenant (0 = unlimited)
  - Assign users to tenants with specific roles
  - Monitor tenant domain usage and statistics
- **Domain Allocation**: Track and manage domain distribution across tenants
- **Tenant Activity**: Monitor tenant-specific DNS operations and changes

#### 9.4 Global System Settings
- **DNS Configuration**:
  - Default nameservers (primary and secondary)
  - SOA record defaults (contact, refresh, retry, expire, minimum)
  - Default TTL values for new records
  - Global DNS validation rules and restrictions
- **System Defaults**:
  - Session timeout and security settings
  - Password policy requirements
  - Records per page and display preferences
  - Audit log retention policies

#### 9.5 System Monitoring & Audit
- **Audit Log Viewer**:
  - Comprehensive activity logging across all users and tenants
  - Filterable by user, action type, date range, and tenant
  - Export audit logs for compliance and analysis
  - Real-time activity monitoring
- **System Health**:
  - Database connectivity and performance metrics
  - License status and domain usage tracking
  - System resource monitoring and alerts
  - Backup status and data integrity checks

#### 9.6 License & Usage Management
- **License Overview**: Current license status, domain limits, and usage statistics
- **Domain Usage Tracking**: Monitor domain allocation across all tenants
- **License Enforcement**: Configure domain limits and enforcement policies
- **Usage Reports**: Generate reports on system utilization and growth trends

#### 9.7 White-Label & Branding Management
- **Brand Customization**:
  - Site name, logo, and company branding
  - Footer text and copyright information
  - Theme selection with live preview
  - Custom CSS overrides and styling
- **Theme Management**: 
  - Bootswatch theme selection and preview
  - Dark mode configuration
  - Custom CSS file management

#### 9.8 System Administration Access Control
- **Admin Menu Integration**: Seamless access from main dashboard for super admins
- **Security Controls**: Admin-only pages with additional authentication checks
- **Activity Logging**: All administrative actions logged for audit purposes
- **Role Validation**: Strict role-based access control for all admin functions

### 10. Global Settings & White-Labeling

#### 10.1 White-Label Branding & Theming
- **Site Name**: Configurable site name in header and page titles
- **Logo**: Uploadable logo image with fallback to default
- **Company Name**: Customizable company branding
- **Footer Text**: Configurable footer text
- **Bootstrap Themes**: Support for default Bootstrap and Bootswatch themes

#### 10.2 Theme System
- **Default Theme**: Standard Bootstrap 5 styling
- **Bootswatch Integration**: CDN-based theme loading from jsdelivr
- **Available Themes**: cerulean, cosmo, cyborg, darkly, flatly, journal, litera, lumen, lux, materia, minty, morph, pulse, quartz, sandstone, simplex, sketchy, slate, solar, spacelab, superhero, united, vapor, yeti, zephyr
- **Theme Selection**: Admin configurable via settings interface with live preview
- **CSS Override**: Minimal custom CSS to maintain theme compatibility
- **Dynamic Loading**: Theme CSS loaded based on database setting
- **Dark Mode Support**: Separate toggle for dark mode independent of theme choice
- **Theme Categories**: Organized into Light and Dark theme groups for better usability
- **Theme Selector Modal**: Interactive theme selection with visual previews
- **Detailed Documentation**: Complete theme system documentation in `/docs/Theme_Implementation.md`

#### 10.3 CSS Architecture & Organization
- **Centralized CSS**: All custom styles in `/webroot/assets/css/custom.css`
- **No Inline Styles**: PHP files contain no `<style>` blocks or `style=""` attributes
- **Organized Structure**:
  ```
  /* PDNS Console Custom CSS */
  ├── Core card and form enhancements
  ├── Layout & Navigation Styles (sidebar, navbar, main content)
  ├── Utility Classes (text-xs, font-weight-bold, etc.)
  ├── Icon Styles (Font Awesome and Bootstrap Icons)
  ├── Layout Structure (sticky footer, flexbox layout)
  ├── Theme-friendly Enhancements (hover effects, CSS variables)
  ├── Dashboard-specific Styles (cards, lists, animations)
  ├── Alert & Notification Styles
  ├── Navigation Specific Styles
  └── Footer Specific Styles
  ```
- **CSS Variables**: Uses `var(--bs-primary)`, `var(--bs-border-color)` for theme compatibility
- **Maintainability**: Single source of truth for all custom styling
- **Performance**: Single CSS file, no inline styles blocking rendering

#### 10.4 DNS Default Values (Configurable)
- **Primary Nameserver**: Default primary NS (configurable via settings)
- **Secondary Nameserver**: Default secondary NS (configurable via settings)
- **SOA Contact**: Default SOA contact email
- **Default TTL**: Default time-to-live for new records
- **SOA Values**: Refresh, retry, expire, minimum TTL intervals
- **All values stored in database** and editable through admin interface

#### 10.5 System Settings
- Session timeout duration
- Password policy requirements
- Audit log retention period
- Default tenant domain limits (0 = unlimited)
- Records per page display
- Custom record type management

#### 10.6 Installation Flexibility
- **Relative Paths**: All paths relative for flexible installation
- **Root or Subdirectory**: Can be installed in domain root or subdirectory
- **Portable**: Designed for easy deployment on any domain
- **Open Source**: GitHub repository for community use and extension

---

## User Interface Design

### 11. User Interface Design

#### 11.1 Dashboard Layout & Navigation
- **Card-Based Dashboard**: Modern card-based layout instead of traditional sidebar navigation
- **Full-Width Header**: Fixed header with branding and user dropdown controls
- **Main Content Area**: Central content area with 2x2 DNS management cards layout
- **Sticky Footer**: Full-width footer with system status and copyright information
- **Responsive Design**: Adapts to mobile devices with responsive card grid

#### 11.2 Top Header Bar
- **Left Side**: Logo and site name (white-labeled)
- **Right Side**: User dropdown menu with profile/settings/logout
- **User Menu Options**:
  - Profile Settings (with Bootstrap Icons)
  - Two-Factor Authentication
  - Change Password
  - Theme Selection (active theme switcher)
  - Logout
- **Admin Menu** (Super Admin only):
  - System Administration Dashboard
  - User Management (create, edit, roles, 2FA reset)
  - Tenant Management (create, edit, domain limits)
  - Global DNS Settings (nameservers, SOA defaults)
  - White-Label Branding & Themes
  - Custom Record Types Management
  - System Audit Logs & Activity
  - License Management & Usage
  - System Information & Maintenance

#### 11.3 Dashboard Card Layout (No Sidebar)
- **Welcome Section**: User greeting with refresh and theme buttons
- **2x2 DNS Management Cards**: Four main navigation cards in responsive grid
  - **Domains Card**: Domain management with action buttons
  - **DNS Records Card**: Record management interface
  - **DNSSEC Card**: Security key management (Phase 4)
  - **Dynamic DNS Card**: API token management (Phase 3)
- **System Status Footer**: Sticky footer with license, version, and status information

#### 11.4 System Administration Dashboard (Super Admin Only)
- **Admin Statistics Cards**: System overview with key metrics
  - Total users across all tenants
  - Total domains and usage statistics
  - License status and domain limits
  - System health indicators
- **Quick Administration Actions**: Fast access to common tasks
  - Create new user or tenant
  - View recent system activity
  - Generate usage reports
  - System maintenance shortcuts
- **Administrative Navigation**: Organized management sections
  - User & Tenant Management
  - DNS & System Configuration
  - Monitoring & Audit Tools
  - License & Usage Tracking

#### 11.5 Icon System & Visual Design
- **Bootstrap Icons**: Clean, monochromatic icons that inherit text colors
- **Professional Appearance**: Simple geometric shapes instead of colorful Font Awesome icons
- **Consistent Styling**: All icons follow the same design language throughout interface
- **Card-Based Navigation**: Large, touch-friendly cards with hover effects and subtle animations
- **Modern Aesthetics**: Clean lines, proper spacing, and professional color scheme

### 12. Bulk Record Addition Interface

#### 12.1 Add Multiple Records Page
- **Dynamic Form**: JavaScript-powered form with "Add Another Record" functionality
- **Record Type Selection**: Dropdown per row with type-specific fields
- **Validation**: Real-time validation per row before submission
- **Preview**: Summary table showing all records to be added
- **Batch Submission**: Single submit button for all records at once

#### 12.2 Form Features
- **Add/Remove Rows**: Add or remove record rows dynamically
- **Field Templates**: Auto-populate fields based on record type
- **Duplicate Detection**: Warn about potential duplicate records
- **Validation Summary**: Show all validation errors before submission
- **Progress Indicator**: Show progress during batch submission

### 13. Responsive Design & Theming
- **Bootstrap 5 with Bootswatch theme support**: 26 available themes with live preview
- **Centralized CSS Architecture**: All custom CSS consolidated in `/webroot/assets/css/custom.css`
  - No inline styles in PHP files for better maintainability
  - Organized sections: Layout, Navigation, Dashboard, Utilities, Icons, Themes
  - CSS variables used throughout for theme compatibility
  - Clean separation of concerns between content and presentation
- **Theme-Compatible CSS**: Uses CSS variables (var(--bs-primary)) instead of hard-coded colors
- **CDN-based theme loading**: `https://cdn.jsdelivr.net/npm/bootswatch@5.3.7/dist/THEME/bootstrap.min.css`
- **Mobile-first approach**: Maintains theme consistency across all devices
- **Touch-friendly interface**: Large buttons, cards, and form elements
- **Optimized responsive tables**: Transforms into mobile-friendly lists on small screens

---

## Security Implementation

### 14. Security Implementation

#### 14.1 Security Measures
- SQL injection prevention (prepared statements)
- XSS protection (output escaping)
- CSRF tokens on all forms
- Input validation and sanitization

#### 14.2 Authentication Security
- Password hashing (PHP password_hash())
- Two-Factor Authentication (TOTP) with encrypted backup codes
- Database session management with automatic cleanup
- AES-256 encryption for sensitive data storage
- Session security (httponly, secure, samesite when using cookies)
- Rate limiting on login attempts
- Account lockout after failed attempts
- MFA reset capabilities for admins
- Multi-server session support for load balancers

#### 14.3 Authorization Security
- Role-based access control
- Tenant isolation (data segregation)
- SQL-level tenant filtering
- Audit logging for all actions

#### 14.4 Network Security
- HTTPS enforcement
- Content Security Policy headers
- Security headers (HSTS, X-Frame-Options)

#### 14.5 API Security
- Secure token generation and storage
- ddclient-compatible authentication
- Rate limiting: 3 requests/3min, then 1 request/10min
- Request logging and monitoring

---

## Development Phases

### 15. Development Phases

#### 15.1 Phase 1: Foundation (Weeks 1-2)
- [x] **PowerDNS Core Schema**: Implement original PowerDNS tables (domains, records, supermasters, comments, domainmetadata, cryptokeys, tsigkeys)
- [x] **PDNS Console Schema**: Database schema implementation with all administrative tables (sessions, MFA, custom types, licensing)
- [x] Configuration management with sample files (.gitignore setup)
- [x] AES encryption system for sensitive data
- [x] Database session management system
- [x] White-label branding system with Bootswatch theme support
- [x] Basic authentication system
- [x] Two-Factor Authentication implementation with encryption
- [x] Application structure setup
- [x] User management (CRUD)
- [x] **Modern Dashboard Layout**: Card-based dashboard with Bootstrap Icons
- [x] **Professional Icon System**: Bootstrap Icons integration throughout interface
- [x] **Responsive 2x2 Layout**: DNS management cards in responsive grid
- [x] **Sticky Footer Design**: Full-width footer with system status and branding
- [x] **Active Theme Switcher**: Functional Bootswatch theme selection system with live preview
- [x] CLI MFA reset script

#### 15.2 Phase 2: Core DNS Management (Weeks 3-4)
- [x] Dashboard layout with theme support
- [x] Domain management interface with tenant limits
- [x] Basic record types (A, AAAA, CNAME, MX, TXT, NS, PTR, SRV) with enhanced validation
- [x] TXT record validation (SPF, DKIM, DMARC detection and validation)
- [x] Input validation system with custom record type support
- [x] Tenant isolation implementation
- [x] DNS Records management system with search and filtering
- [x] Domain CRUD operations (Create, Read, Update, Delete)
- [x] Records CRUD operations with conflict detection
- [x] Automatic SOA serial number updates
- [x] Record statistics and pagination
- [x] Bulk record addition interface
- [ ] Dashboard with statistics and domain usage
- [x] **System Administration Dashboard**: Super admin interface with user/tenant management, global settings, and system monitoring

#### 15.3 Phase 3: Advanced Features (Weeks 5-6)
- [x] **Advanced record types (SRV, PTR - NS/SOA managed automatically)**
- [x] **Custom record type management interface** (NAPTR, CAA, TLSA, etc.)
- [x] **Reverse DNS zone support** (IPv4/IPv6 subnet-based zone creation with .in-addr.arpa/.ip6.arpa)
- [x] **Zone type filtering** (PTR records only for reverse zones, excluded from forward zones)
- [x] **Audit logging** (Comprehensive system-wide logging for domains, records, users, tenants, and settings)
- [ ] Global settings management interface with theme selection
- [ ] CSV import/export with filtering (no NS/SOA, update existing records)
- [ ] Dynamic DNS API endpoints (ddclient compatible, A/AAAA records)
- [ ] Rate limiting implementation (3/3min, then 1/10min)
- [ ] Search and filtering

#### 15.4 Phase 4: DNSSEC & Polish (Weeks 7-8)
- [ ] DNSSEC key management (ECDSA P-256, 12-month rotation)
- [ ] Key generation and activation
- [ ] DNSKEY/DS record display and export
- [ ] Dynamic DNS token management interface
- [ ] Final UI/UX improvements and theme customization
- [ ] Comprehensive testing and bug fixes
- [ ] Documentation and GitHub preparation

---

#### 15.5 Phase 5: Licensing & Monetization (Weeks 9-10)
- [ ] RSA key pair generation and public key embedding
- [ ] License key generation tool (your side) with command-line interface
- [ ] License validation system implementation with digital signature verification
- [ ] Installation fingerprinting system for anti-piracy
- [ ] Domain count enforcement for free tier (5 domain limit)
- [ ] License activation system with activation limits (max 3 per key)
- [ ] License management interface with status display and key entry
- [ ] Domain creation blocking when limits exceeded
- [ ] License upgrade prompts and commercial pricing display
- [ ] License recovery and transfer system for customer support

### 16. Licensing System Architecture

#### 16.1 License Types
- **Free License**: 5 domains maximum, all core features included
- **Commercial License**: Unlimited domains, $50 one-time purchase
- **Trial Period**: 30-day trial of commercial features

#### 16.2 License Key System (Offline Validation)
- **Key Format**: `PDNS-COM-[BASE64_DATA]-[SIGNATURE]`
- **Cryptographic Signing**: RSA digital signatures for tamper-proof keys
- **Embedded Data**: Customer email, license type, domain limits, issue date
- **Offline Validation**: No external server calls required
- **Public Key**: Embedded in application code for signature verification
- **Installation Fingerprinting**: Unique installation ID based on server characteristics

#### 16.3 License Enforcement
- Domain count validation on domain creation attempts
- Real-time license status checking during critical operations
- Installation fingerprint binding (max 3 activations per key)
- Soft warnings before hard limits are reached
- Grace period for license issues (7-day warning period)

#### 16.4 Anti-Piracy Measures
- **Digital Signatures**: Cannot forge keys without private key access
- **Installation Binding**: Keys tied to specific server installations
- **Usage Monitoring**: Track domain counts and activation attempts
- **Activation Limits**: Maximum 3 installations per commercial license
- **Embedded Customer Data**: Email and purchase info in license key
- **Basic Code Protection**: License validation spread across multiple functions

#### 16.5 License Management Interface
- Current license status display with domain usage
- License key entry and validation form
- Upgrade options and commercial pricing display
- Installation fingerprint display for support
- License activation history and status

### 17. License Key Implementation Details

#### 17.1 License Key Generation (Your Side)
````php
<?php
// License Generator Tool (run on your computer)
class LicenseGenerator {
    private $privateKey;
    
    public function __construct($privateKeyPath) {
        $this->privateKey = openssl_pkey_get_private(file_get_contents($privateKeyPath));
    }
    
    public function generateLicense($email, $type = 'commercial', $domains = 0) {
        $data = [
            'email' => $email,
            'type' => $type,
            'domains' => $domains, // 0 = unlimited for commercial
            'issued' => time(),
            'expires' => null // Lifetime license
        ];
        
        $encoded = base64_encode(json_encode($data));
        
        // Sign the encoded data
        openssl_sign($encoded, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        $signatureHex = bin2hex($signature);
        
        // Format: PDNS-TYPE-DATA-SIGNATURE
        return sprintf('PDNS-%s-%s-%s', 
            strtoupper($type), 
            $encoded, 
            $signatureHex
        );
    }
}

// Usage Example:
// $generator = new LicenseGenerator('/path/to/private.key');
// $license = $generator->generateLicense('customer@example.com', 'commercial');
// echo "License Key: " . $license;
````

#### 17.2 License Key Validation (In PDNS Console)
````php
<?php
class LicenseValidator {
    // Your public key (embedded in application)
    private $publicKey = '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA...
-----END PUBLIC KEY-----';
    
    public function validateLicense($licenseKey) {
        $parts = explode('-', $licenseKey);
        if (count($parts) !== 4 || $parts[0] !== 'PDNS') {
            return ['valid' => false, 'error' => 'Invalid license format'];
        }
        
        $type = strtolower($parts[1]);
        $encoded = $parts[2];
        $signature = hex2bin($parts[3]);
        
        // Verify digital signature
        $publicKeyRes = openssl_pkey_get_public($this->publicKey);
        $verified = openssl_verify($encoded, $signature, $publicKeyRes, OPENSSL_ALGO_SHA256);
        
        if ($verified !== 1) {
            return ['valid' => false, 'error' => 'Invalid license signature'];
        }
        
        // Decode license data
        $data = json_decode(base64_decode($encoded), true);
        
        return [
            'valid' => true,
            'email' => $data['email'],
            'type' => $data['type'],
            'domains' => $data['domains'],
            'issued' => $data['issued'],
            'expires' => $data['expires']
        ];
    }
    
    // Generate installation fingerprint
    public function getInstallationFingerprint() {
        $factors = [
            $_SERVER['SERVER_NAME'] ?? 'unknown',
            $_SERVER['HTTP_HOST'] ?? 'unknown',
            __DIR__, // Installation path
            php_uname('n'), // Server hostname
        ];
        return hash('sha256', implode('|', $factors));
    }
}
````

#### 17.3 License Enforcement System
````php
<?php
class LicenseManager {
    private $db;
    private $validator;
    
    public function __construct($database) {
        $this->db = $database;
        $this->validator = new LicenseValidator();
    }
    
    // Check if user can add more domains
    public function canAddDomain($tenantId) {
        $license = $this->getCurrentLicense();
        
        if (!$license || !$license['is_active']) {
            return ['allowed' => false, 'reason' => 'No valid license'];
        }
        
        // Free license check
        if ($license['license_type'] === 'free') {
            $currentDomains = $this->getDomainCount($tenantId);
            if ($currentDomains >= 5) {
                return [
                    'allowed' => false, 
                    'reason' => 'Free license limited to 5 domains. Upgrade to commercial license.',
                    'upgrade_available' => true
                ];
            }
        }
        
        return ['allowed' => true];
    }
    
    // Activate license key
    public function activateLicense($licenseKey) {
        $validation = $this->validator->validateLicense($licenseKey);
        
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }
        
        $fingerprint = $this->validator->getInstallationFingerprint();
        
        // Check if license already exists
        $existing = $this->db->prepare("
            SELECT * FROM licenses WHERE license_key = ?
        ");
        $existing->execute([$licenseKey]);
        $existingLicense = $existing->fetch();
        
        if ($existingLicense) {
            // Check activation limits
            if ($existingLicense['activation_count'] >= $existingLicense['max_activations']) {
                return ['success' => false, 'error' => 'License activation limit exceeded'];
            }
            
            // Update existing license
            $stmt = $this->db->prepare("
                UPDATE licenses SET 
                    installation_fingerprint = ?,
                    activation_count = activation_count + 1,
                    last_validated = NOW(),
                    is_active = 1
                WHERE license_key = ?
            ");
            $stmt->execute([$fingerprint, $licenseKey]);
        } else {
            // Create new license record
            $stmt = $this->db->prepare("
                INSERT INTO licenses 
                (license_key, license_type, max_domains, contact_email, 
                 installation_fingerprint, activation_count, license_data, is_active) 
                VALUES (?, ?, ?, ?, ?, 1, ?, 1)
            ");
            $stmt->execute([
                $licenseKey,
                $validation['type'],
                $validation['domains'],
                $validation['email'],
                $fingerprint,
                json_encode($validation)
            ]);
        }
        
        return ['success' => true, 'message' => 'License activated successfully'];
    }
}
````

#### 17.4 RSA Key Pair Generation (Setup)
````bash
# Generate private key (keep this secure on your computer!)
openssl genrsa -out pdns_private.key 2048

# Generate public key (embed this in PDNS Console code)
openssl rsa -in pdns_private.key -pubout -out pdns_public.key

# View public key for embedding in code
cat pdns_public.key
````

#### 17.5 Your License Sales Workflow
1. **Customer purchases** via Stripe/PayPal/etc.
2. **You run**: `php generate_license.php customer@email.com commercial`
3. **System generates**: `PDNS-COMMERCIAL-eyJlbWFpbCI6ImN1c3RvbWVyQGVtYWlsLmNvbSIsInR5cGUiOiJjb21tZXJjaWFsIiwiZG9tYWlucyI6MCwiaXNzdWVkIjoxNzMzMTAwMDAwLCJleHBpcmVzIjpudWxsfQ-a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6`
4. **Email license** to customer with installation instructions
5. **Customer enters** license key in their PDNS Console installation

---

## Testing Strategy

### 18. Testing Strategy

#### 18.1 Testing Approach

#### 18.1 Unit Testing
- PHP functions validation
- Database operations
- Authentication logic
- Input validation

#### 18.2 Integration Testing
- Database connectivity
- DNS record validation
- DNSSEC operations
- Multi-tenant isolation
- Dynamic DNS API functionality

#### 18.3 Security Testing
- SQL injection attempts
- XSS vulnerability tests
- Authentication bypass tests
- Authorization boundary tests

#### 18.4 User Acceptance Testing
- Admin user workflows
- Tenant user workflows
- Mobile responsiveness
- Browser compatibility

---

## Deployment Considerations

### 19. Deployment Considerations

#### 19.1 Production Requirements

#### 19.1 Server Requirements
- PHP 8.x with PDO MySQL extension
- MySQL 8.x or MariaDB 10.x
- Web server (Apache/Nginx) with SSL
- OpenSSL for DNSSEC operations

#### 19.2 Configuration
- Secure database credentials
- SSL certificate installation
- File permissions setup
- Log file rotation

#### 19.3 Backup Strategy
- Database backup procedures
- Configuration file backups
- DNSSEC key backup and recovery

---

## Additional Questions for Clarification

### 20. Additional Questions for Clarification

#### 20.1 Follow-up Questions

This comprehensive plan incorporates all requirements and clarifications:

**✅ Core Features:**
- MySQL-based authentication with encrypted Two-Factor Authentication (TOTP + backup codes)
- Database session management for HAProxy cluster compatibility
- Multi-tenant architecture with unlimited default domain limits (configurable)
- White-label branding with Bootswatch theme support (25+ themes)
- Dashboard layout with collapsible sidebar and top header
- Bulk record addition interface
- ECDSA P-256 DNSSEC implementation (Phase 4, 12-month rotation)

**✅ Enhanced Record Management:**
- Standard DNS record types with comprehensive validation
- Enhanced TXT record validation for SPF, DKIM, and DMARC records
- Admin-configurable custom record types (NAPTR, CAA, TLSA, etc.)
- Regex-based validation patterns for custom types
- Dynamic form generation based on configured record types

**✅ Licensing & Monetization System:**
- Freemium model with 5-domain limit for free version
- Commercial license at $50 for unlimited domains
- 30-day trial period for commercial features
- License validation and enforcement system
- Anti-piracy measures and secure license verification

**✅ CSV Import/Export:**
- Export all record types except NS/SOA
- Import only to existing domains with validation
- Filter out NS/SOA records automatically
- Update existing records during import
- Comprehensive validation and error reporting

**✅ Dynamic DNS API:**
- ddclient compatible authentication and endpoints
- Support for A and AAAA record updates
- Rate limiting: 3 requests/3min, then 1 request/10min
- Per-token tracking and management

**✅ Security & Administration:**
- AES-256 encrypted storage for MFA secrets and backup codes
- Database session management with automatic cleanup and multi-server support
- Two-Factor Authentication for all users with encrypted storage
- Super admin MFA reset capabilities
- Command-line MFA reset script for super admins
- Audit logging and role-based access control
- HAProxy cluster compatibility

**✅ Theming & Customization:**
- Bootstrap 5 with Bootswatch theme support (25+ themes)
- CDN-based theme loading with minimal custom CSS
- Admin-configurable theme selection
- White-label branding with logo and text customization
- Extensible record type system for future expansion

**✅ Installation & Portability:**
- Relative paths for flexible installation (root or subdirectory)
- Sample configuration files with .gitignore setup

**✅ Distribution & Support:**
- Open source GitHub repository with MIT/GPL license
- SaaS option at pdnsconsole.com (when registered)
- Self-hosted installation with automated setup
- Community support for free version, email support for commercial
- Comprehensive documentation and video tutorials

The development timeline is now extended to 10 weeks total with the addition of the licensing system in Phase 5. All branding has been updated to "PDNS Console" for copyright safety while maintaining full feature parity with the original specification.

This system will compete directly with expensive SaaS DNS management solutions by offering a one-time $50 commercial license for unlimited domains, making it extremely cost-effective for businesses and hosting providers who want to maintain control over their DNS infrastructure.
- Designed as open-source GitHub project
- Extensible architecture for future front-end integration

**🚀 Ready for Implementation!**

The plan is complete and addresses all requirements. No additional clarifications needed - ready to begin development with Phase 1!
