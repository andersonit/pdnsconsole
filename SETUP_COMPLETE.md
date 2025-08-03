# PDNS Console Setup Summary

## ✅ Setup Complete

Your PDNS Console installation is now fully configured and ready to use!

### 🔑 Generated Configuration

#### Encryption Key
- **Generated Key**: `23caf988963acc2a051b253498e7016b`
- **Location**: `/var/www/pdnsconsole/config/app.php`
- ⚠️ **Keep this key secure** - it's used for encrypting sensitive data like 2FA secrets

#### Database Configuration
- **Host**: 127.0.0.1
- **Database**: pdnsconsole
- **User**: pdnscadmin
- **Port**: 3306

### 🌐 Nginx Configuration

#### SSL Configuration File
- **Location**: `/var/www/pdnsconsole/config/nginx-pdnsconsole.conf`
- **Hostname**: dev.pdnsconsole.com
- **Document Root**: /var/www/pdnsconsole/webroot

#### SSL Certificate Setup Required
You need to obtain SSL certificates for `dev.pdnsconsole.com`:

```bash
# Option 1: Self-signed certificate (for development)
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/ssl/private/dev.pdnsconsole.com.key \
  -out /etc/ssl/certs/dev.pdnsconsole.com.crt \
  -subj "/C=US/ST=State/L=City/O=Organization/CN=dev.pdnsconsole.com"

# Option 2: Let's Encrypt (for production)
sudo certbot certonly --nginx -d dev.pdnsconsole.com
```

#### Nginx Setup
```bash
# Copy the configuration to Nginx sites-available
sudo cp /var/www/pdnsconsole/config/nginx-pdnsconsole.conf /etc/nginx/sites-available/pdnsconsole

# Enable the site
sudo ln -s /etc/nginx/sites-available/pdnsconsole /etc/nginx/sites-enabled/

# Test and reload Nginx
sudo nginx -t
sudo systemctl reload nginx
```

### 🎨 Theme Configuration
- **Default Theme**: Standard Bootstrap (clean and professional)
- **Available Themes**: 25+ Bootswatch themes available via Admin → Settings
- **Current Setting**: Set to 'default' for clean Bootstrap styling

### 📊 Sample Data Created

#### Test Tenants
1. **Example Corp** (max 10 domains)
   - Contact: admin@example.com
   - Domains: example.com, subdomain.example.com

2. **Test Company** (max 5 domains)
   - Contact: admin@testcompany.com  
   - Domains: testcompany.com

3. **Demo Organization** (unlimited domains)
   - Contact: admin@demo.org
   - Domains: demo.org

#### Sample DNS Records
Each domain includes comprehensive DNS setup:
- **SOA records** - Start of Authority
- **NS records** - Name servers (ns1/ns2.domain.com)
- **A records** - IPv4 addresses for www, mail, etc.
- **AAAA records** - IPv6 addresses
- **CNAME records** - Aliases (blog, shop, support)
- **MX records** - Mail exchange records
- **TXT records** - SPF, DKIM, DMARC, verification
- **SRV records** - Service records (_sip, _xmpp)

#### Test User Accounts
| Username | Password | Role | Tenant | Email |
|----------|----------|------|--------|-------|
| john.doe | password123 | tenant_admin | Example Corp | john.doe@example.com |
| jane.smith | password123 | tenant_admin | Test Company | jane.smith@testcompany.com |
| viewer.user | password123 | tenant_admin | Example Corp | viewer@example.com |

### 🚀 Next Steps

#### 1. Create Super Admin Account
```bash
cd /var/www/pdnsconsole
php cli/create_admin.php
```

#### 2. Test the Installation
```bash
# Visit in browser (adjust URL for your setup)
http://dev.pdnsconsole.com/test.php
```

#### 3. Access the Console
```bash
# Login URL
https://dev.pdnsconsole.com/
```

#### 4. Verify Sample Data
- Login with any test user credentials above
- Navigate to **Domains** to see sample domains
- Click on a domain to view DNS records
- Test adding/editing records

### 🔧 Development Mode Features

Since the environment is set to 'development':
- **Error reporting** is enabled
- **Debug information** is displayed
- **Detailed error messages** are shown

For production, change in `/var/www/pdnsconsole/config/app.php`:
```php
define('PDNS_ENV', 'production');
```

### 📁 Important Files

```
/var/www/pdnsconsole/
├── config/
│   ├── config.php              # Database credentials
│   ├── app.php                 # Application config (with encryption key)
│   └── nginx-pdnsconsole.conf  # Nginx configuration
├── webroot/                    # Web document root
│   ├── index.php              # Main application entry point
│   ├── test.php               # Installation test script
│   └── assets/                # CSS, JS, images
├── scripts/
│   ├── create_sample_data_fixed.sh  # Sample data script
│   └── create_sample_data.sh        # Original (with errors)
├── cli/
│   └── create_admin.php       # Super admin creation tool
└── db/
    └── complete-schema.sql    # Complete database schema
```

### 🔐 Security Notes

1. **Change default passwords** for all test users
2. **Secure the encryption key** - never commit to version control
3. **Use HTTPS only** in production
4. **Regular backups** of database and configuration
5. **Keep PHP and dependencies updated**

### 🐛 Troubleshooting

#### Database Connection Issues
```bash
# Test database connection
php -r "
try {
    \$pdo = new PDO('mysql:host=127.0.0.1;dbname=pdnsconsole;charset=utf8mb4', 'pdnscadmin', 'Ch1m3r@76!');
    echo 'Database connection successful!\n';
} catch (Exception \$e) {
    echo 'Database error: ' . \$e->getMessage() . '\n';
}
"
```

#### Permission Issues
```bash
# Set correct permissions
sudo chown -R www-data:www-data /var/www/pdnsconsole/webroot
sudo chmod -R 755 /var/www/pdnsconsole/webroot
```

#### PHP Extensions
```bash
# Verify required extensions
php -m | grep -E "(pdo|mysql|openssl|curl|json)"
```

---

## 🎉 Installation Complete!

Your PDNS Console is ready for Phase 2 development or immediate use with the sample data. The foundation includes:

- ✅ Complete database schema with sample data
- ✅ Multi-tenant architecture working
- ✅ Authentication system functional  
- ✅ Encryption configured securely
- ✅ Nginx configuration with SSL ready
- ✅ Standard Bootstrap theme active
- ✅ Test users and domains available

**Happy DNS management!** 🚀
