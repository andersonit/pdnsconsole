# PDNS Console

A comprehensive web-based administration interface for PowerDNS with MySQL/MariaDB backend, featuring multi-tenant architecture, role-based access control, DNSSEC, Dynamic DNS, and commercial licensing. PDNS Console follows a freemium model with full functionality for up to 5 domains. A reasonably priced commercial license is available at https://pdnsconsole.com.

## 🚀 Features

- Multi-tenant architecture with tenant isolation
- Role-based access control (Super Admin, Tenant Admin)
- DNS record management: A, AAAA, CNAME, MX, TXT, SRV, PTR (NS/SOA managed automatically by system)
- Forward and reverse DNS zones (zone-aware filtering; PTR in reverse zones)
- Enhanced TXT validation: SPF, DKIM, DMARC semantic checks and hints
- DNSSEC: full UI for enable/disable, key generation, rollover (Add/Immediate/Timed), DS assistance, registrar verification
- Dynamic DNS API: ddclient-compatible A/AAAA updates with rate limiting
- Record comments: per-record single comment plus zone-level info comment
- Two-Factor Authentication (TOTP) with encrypted backup codes
- Human verification: Cloudflare Turnstile or Google reCAPTCHA
- White-label branding and Bootswatch themes
- Database-backed sessions (HA/cluster friendly)
- CSV import/export with validation (NS/SOA excluded)
- Audit logging for critical actions
- Licensing: free up to 5 domains; commercial license unlocks unlimited

## 📋 Requirements

- PHP 8.0+ with PDO MySQL extension
- MySQL 8.0+ or MariaDB 10.x
- Web server (Apache or Nginx) with SSL
- OpenSSL for encryption and DNSSEC operations
- PowerDNS with gmysql backend (Native domains) – see [PowerDNS Installation](docs/POWERDNS_INSTALLATION.md)

Note: PDNS Console operates in Native mode only. MASTER/SLAVE workflows are not exposed in the UI; all authoritative servers should share the same PowerDNS database.

## 🛠️ Installation

### 1. Clone the Repository

```bash
git clone https://github.com/andersonit/pdnsconsole.git
cd pdnsconsole
```

### 2. Configure Database

```bash
# Copy the sample configuration
cp config/config.sample.php config/config.php

# Edit with your database credentials
nano config/config.php
```

### 3. Import Database Schema

```bash
# Import the complete schema (PowerDNS + PDNS Console tables)
mysql -u your_user -p your_database < db/complete-schema.sql
```

### 4. Configure Application

Edit `config/app.php` and change the encryption key:

```php
define('ENCRYPTION_KEY', 'your-unique-32-character-secret-key');
```

### 5. Create Super Admin User

```bash
php cli/create_admin.php
```

### 6. Set Web Server Document Root

Point your web server document root to the `webroot/` directory.

### 7. Finish and Log In

Point your browser to the site (root or subdirectory where `webroot/` is served) and log in with the super admin you created.

## 🔧 Configuration

### Web Server Configuration

#### Apache (.htaccess)

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

#### Nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
```

### Security Headers

Add these headers to your web server configuration:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000; includeSubDomains
```

## 🎨 Theming

PDNS Console supports 25+ Bootstrap themes via Bootswatch:

- Access **Admin → Settings** as super admin
- Select from available themes
- Changes apply immediately to all users

Available themes: cerulean, cosmo, cyborg, darkly, flatly, journal, litera, lumen, lux, materia, minty, morph, pulse, quartz, sandstone, simplex, sketchy, slate, solar, spacelab, superhero, united, vapor, yeti, zephyr

## 🔐 Security Features

- **AES-256 Encryption** for sensitive data (MFA secrets, backup codes)
- **Database Sessions** for load balancer compatibility
- **CSRF Protection** on all forms
- **SQL Injection Prevention** with prepared statements
- **XSS Protection** with output escaping
- **Password Hashing** using PHP's password_hash()
- **Rate Limiting** for API endpoints
- **Session Security** with IP and user-agent validation

## 🔑 Two-Factor Authentication

- Optional TOTP (Time-based One-Time Password) support
- Encrypted storage of MFA secrets and backup codes
- QR code generation for authenticator apps
- Super admin can reset 2FA for other users
- CLI script for emergency 2FA reset

## 📊 Multi-Tenant Architecture

- **Tenants** - Organizational units that own domains
- **Domain Limits** - Configurable per tenant (0 = unlimited)
- **User Assignment** - Users can belong to multiple tenants
- **Data Isolation** - SQL-level filtering ensures tenant separation

## 🌐 Dynamic DNS API

Compatible with ddclient and other dynamic DNS clients:

```bash
# Update A record
curl -X PUT "https://yourserver/api/dynamic_dns.php" \
  -H "Authorization: Bearer your_token" \
  -d "hostname=test.example.com&myip=1.2.3.4"
```

Rate limiting: 3 requests per 3 minutes, then 1 request per 10 minutes.

See the detailed guide in [docs/DYNAMIC_DNS.md](docs/DYNAMIC_DNS.md).

API endpoint: `webroot/api/dynamic_dns.php` (routed via your web server).

Example ddclient configuration is provided in the documentation.

## 💾 Database Schema

The system extends the standard PowerDNS MySQL schema with additional tables:

### PowerDNS Core Tables
- `domains` - DNS zones
- `records` - DNS records
- `supermasters` - Auto-provisioning
- `comments` - Record comments
- `domainmetadata` - Domain metadata
- `cryptokeys` - DNSSEC keys
- `tsigkeys` - Transaction signatures

### PDNS Console Extensions
- `admin_users` - Administrative users
- `tenants` - Multi-tenant organizations
- `user_tenants` - User-to-tenant mapping
- `domain_tenants` - Domain-to-tenant mapping
- `global_settings` - Configuration, branding, licensing key and installation id
- `nameservers` - Managed NS set used for automatic NS/SOA management
- `user_sessions` - Database sessions (cluster-friendly)
- `user_mfa` - Encrypted TOTP secrets and backup codes
- `custom_record_types` - Admin-configurable record types
- `dynamic_dns_tokens` - Tokens and rate-limit state for Dynamic DNS API
- `record_comments` - Per-record single comment storage
- `audit_log` - Activity tracking
- `password_reset_tokens` - Secure password reset workflow

## 🔄 Status & Roadmap

- Core platform, multi-tenant auth, sessions, and theming: Complete
- Records management with enhanced TXT validation and CSV import/export: Complete (future: import dry-run/diff)
- Dynamic DNS API with rate limiting: Complete
- DNSSEC (enable/disable, keys, rollover, DS assistance, registrar verification): Complete — see [docs/DNSSEC.md](docs/DNSSEC.md)
- Licensing system (freemium + commercial): Complete
- Upcoming: expanded audit coverage, CSV import dry-run/diff, optional bulk DNSSEC helpers

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## 📄 License

Business Source License (BSL). Free for non-commercial use managing up to 5 domains per installation. Commercial license required for more domains or commercial use. See [LICENSE.md](LICENSE.md) and https://pdnsconsole.com.

## 🆘 Support

- **Community Support**: GitHub Issues
- **Commercial Support**: Available with commercial license
- **Documentation**: See `/docs` directory

## 🏗️ Architecture

```
┌─────────────────┐    ┌──────────────┐    ┌─────────────────┐
│   Web Browser   │◄──►│ PDNS Console │◄──►│    PowerDNS     │
└─────────────────┘    └──────────────┘    └─────────────────┘
                              │                       │
                              ▼                       ▼
                       ┌──────────────┐    ┌─────────────────┐
                       │    MySQL     │◄──►│ PowerDNS MySQL  │
                       │  (Console)   │    │    Backend      │
                       └──────────────┘    └─────────────────┘
```

## 🎯 Roadmap

- [ ] CSV import dry-run + diff/upsert reporting
- [ ] Expanded audit coverage (auth events, API usage, comment changes)
- [ ] REST API for external integrations
- [ ] DNS zone templates
- [ ] Optional bulk DNSSEC operations
- [ ] Extension system for validators

### Cron jobs

To support automated tasks, schedule the provided scripts:

- DNSSEC timed rollover finalization: `cron/dnssec_rollover.php`
- Session cleanup: `cron/session_cleanup.php`

Example crontab entries (adjust PHP path):

```
*/15 * * * * /usr/bin/php /var/www/pdnsconsole/cron/dnssec_rollover.php > /dev/null 2>&1
0 * * * * /usr/bin/php /var/www/pdnsconsole/cron/session_cleanup.php > /dev/null 2>&1
```

---

**PDNS Console** — Professional DNS management made simple.
