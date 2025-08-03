# PDNS Console

A comprehensive web-based administration interface for PowerDNS with MySQL backend, featuring multi-tenant architecture, role-based access control, and commercial licensing.

## 🚀 Features

- **Multi-tenant Architecture** - Isolate domains and users by tenant
- **Role-based Access Control** - Super admin and tenant admin roles
- **DNS Record Management** - Full support for A, AAAA, CNAME, MX, TXT, SRV, PTR, NS, SOA records
- **Enhanced TXT Record Validation** - SPF, DKIM, DMARC detection and validation
- **DNSSEC Support** - Key management and generation (Phase 4)
- **Dynamic DNS API** - ddclient compatible endpoints
- **Two-Factor Authentication** - TOTP with encrypted backup codes
- **White-label Branding** - Customizable themes and branding
- **Database Sessions** - HAProxy cluster compatible
- **CSV Import/Export** - Bulk operations with validation
- **Audit Logging** - Complete activity tracking
- **Commercial Licensing** - Freemium model with $50 unlimited license

## 📋 Requirements

- **PHP 8.0+** with PDO MySQL extension
- **MySQL 8.0+** or MariaDB 10.x
- **Web Server** (Apache/Nginx) with SSL
- **OpenSSL** for encryption and DNSSEC operations

## 🛠️ Installation

### 1. Clone the Repository

```bash
git clone https://github.com/yourusername/pdnsconsole.git
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

### 7. Test Installation

Visit `http://yourserver/test.php` to verify the setup.

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
- `global_settings` - Configuration & branding
- `user_sessions` - Database sessions
- `audit_log` - Activity tracking
- `licenses` - License management

## 🔄 Development Phases

- ✅ **Phase 1**: Foundation (Database, Auth, 2FA, Sessions)
- 🚧 **Phase 2**: Core DNS Management (Records, Validation)
- ⏳ **Phase 3**: Advanced Features (API, CSV, Custom Records)
- ⏳ **Phase 4**: DNSSEC & Polish
- ⏳ **Phase 5**: Licensing System

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

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

- [ ] REST API for external integrations
- [ ] Bulk domain operations
- [ ] DNS zone templates
- [ ] Advanced DNSSEC automation
- [ ] Mobile-responsive improvements
- [ ] Plugin system for extensions

---

**PDNS Console** - Professional DNS management made simple.
