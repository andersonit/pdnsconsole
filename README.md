# PDNS Console

A comprehensive web-based administration interface for PowerDNS with MySQL/MariaDB backend, featuring multi-tenant architecture, role-based access control, DNSSEC, Dynamic DNS, and commercial licensing. PDNS Console follows a freemium model with full functionality for up to 5 domains. A reasonably priced commercial license is available at https://pdnsconsole.com.

## *UNDER ACTIVE DEVELOPMENT*
Please visit https://pdnsconsole.com/demo.php to test a fully functional demo.
Please reach out with any input, feature requests or bugs.

### **DOCUMENTATION IN UPDATED - PLEASE REPORT ISSUES**

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

- Instructions based on Ubuntu/Debian Distros
- PHP 8.0+ with PDO MySQL extension
- MySQL 8.0+ or MariaDB 10.x
- Nginx Web server with SSL (Will work with Apache, but instructions not included)
- OpenSSL for encryption and DNSSEC operations
- PowerDNS with gmysql backend (Native domains) – see [PowerDNS Installation](docs/POWERDNS_INSTALLATION.md)

### Requirements Installation

#### 1. Install PHP 8.3 and Extensions

```bash
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install -y php8.3 php8.3-fpm php8.3-mysql php8.3-xml php8.3-mbstring php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl php8.3-cli php8.3-openssl
```

#### 2. Install MariaDB (or MySQL)

```bash
sudo apt install -y mariadb-server
sudo systemctl enable --now mariadb
```

#### 3. Install Nginx

```bash
sudo apt install -y nginx
sudo systemctl enable --now nginx
```

#### 4. Install PowerDNS (Authoritative) and MySQL Backend

```bash
sudo apt install -y pdns-server pdns-backend-mysql pdns-tools
```
*NOTE if pdns service fails to start, Google disabling the stub listener for the systemd-resolver service.

#### 5. Create Database and User

```bash
sudo mysql -e "CREATE DATABASE pdnsconsole CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'pdnsconsole'@'localhost' IDENTIFIED BY 'StrongPdnsPass!';"
sudo mysql -e "GRANT ALL ON pdnsconsole.* TO 'pdnsconsole'@'localhost'; FLUSH PRIVILEGES;"
```
- Replace `StrongPdnsPass!` with a secure password of your choice.


Note: PDNS Console operates in Native mode only. MASTER/SLAVE workflows are not exposed in the UI; all authoritative servers should share the same PowerDNS database.

## 🛠️ Installation
NOTE: Log in as "root" or user with sudo privileges

And enter password

### 1. Clone the Repository and create website

```bash
#Create website and set permissions(example website in /var/www/pdnsconsole)
# 1. Create the user without a home directory and as a system user
sudo useradd -r -s /bin/false pdnsconsole

# 2. Add the user to the necessary groups
sudo usermod -aG sudo pdnsconsole
sudo usermod -aG www-data pdnsconsole

# 3. Prepare the directory
sudo mkdir -p /var/www/pdnsconsole
sudo chown pdnsconsole:www-data /var/www/pdnsconsole

# 4. Clone the repository 
# We use 'sudo -u' to clone as the specific user so the hidden .git files 
# are owned correctly from the start.
sudo -u pdnsconsole git clone https://github.com/andersonit/pdnsconsole /var/www/pdnsconsole

# 5. Apply final permissions and the SetGID bit
sudo chown -R pdnsconsole:www-data /var/www/pdnsconsole
sudo chmod -R 775 /var/www/pdnsconsole
sudo chmod g+s /var/www/pdnsconsole

# 6. Install PHP Modules
cd/var/www/pdnsconsole
sudo -u pdnsconsole composer install
```

### 2. Configure Database

```bash
# Copy the sample configuration
cp config/config.sample.php config/config.php

# Edit with your database credentials
nano config/config.php
#Update Credentials and save
```

### 3. Import Database Schema

```bash
# Import the complete schema (PowerDNS + PDNS Console tables)
# Replace Username and Database name 
mysql -u dbusername -p dbname < db/complete-schema.sql
```

### 4. Configure PowerDNS to Use the Database

Edit/Append `/etc/powerdns/pdns.conf` and set the following (adjust password as needed):

```ini
# Backend
launch=gmysql
gmysql-host=127.0.0.1
gmysql-dbname=pdnsconsole
gmysql-user=pdnsconsole
gmysql-password=StrongPdnsPass!
gmysql-dnssec=yes

# API / Webserver (required for PDNS Console DNSSEC features)
api=yes
api-key=DevTestKey123
webserver=yes
webserver-address=127.0.0.1
webserver-port=8081
webserver-allow-from=127.0.0.1
```

- Replace `StrongPdnsPass!` with your actual database password.
- You may change the `api-key` to a secure value of your choice.

Restart PowerDNS to apply changes:
```bash
sudo systemctl restart pdns
```


### 5. Configure Application

Edit `config/app.php` and change the encryption key (Generate a random 32 character key):
```bash
cp /config/app.sample.php /config/app.php
nano /config/app.php
```

```php
#Change the following line:
define('PDNS_ENV', 'production');
define('ENCRYPTION_KEY', 'your-unique-32-character-secret-key');
define('APP_URL', 'https://www.pdnsconsole.com'); // Update for your installation
```

### 6. Create Super Admin User

```bash
php cli/create_admin.php
```

### 7. Configure Web Server & SSL (Nginx example)

Note: This uses the included sample Nginx configuration as a starting point: [config/nginx-pdnsconsole.conf](config/nginx-pdnsconsole.conf).  Modify as needed.

#### 1. Copy the sample site configuration and enable it:

>IF USING YOUR OWN SSL CERT
```bash
# IF USING YOUR OWN SSL CERT
sudo cp config/nginx-pdnsconsole.conf /etc/nginx/conf.d/pdnsconsole.conf
# Edit the file if you need to adjust `root` and server name
# Remove HA section if not behind a proxy
# Update the SSL Cert and Key. 
sudo nginx -t && sudo systemctl reload nginx
```
> IF USING CERTBOT for Let's Encrypt SSL Certificate
```bash
sudo cp config/nginx-pdnsconsole.conf /etc/nginx/conf.d/pdnsconsole.conf
# Edit the file if you need to adjust `root` and server name
sudo nginx -t && sudo systemctl reload nginx
# CONTINUE TO OPTIONAL Let's Encrypt SECTION IN STEP 4
```

#### 2. Ensure the PHP-FPM socket in the config matches your system (e.g. `/var/run/php/php8.3-fpm.sock`), or change to `127.0.0.1:9000` if using TCP.

#### 3. Upload directory permissions (required for white-label branding uploads):

```bash
sudo mkdir -p /var/www/pdnsconsole/webroot/assets/img/uploads
sudo chown -R www-data:www-data /var/www/pdnsconsole/webroot/assets/img/uploads
sudo find /var/www/pdnsconsole/webroot/assets/img/uploads -type d -exec chmod 750 {} \;
sudo find /var/www/pdnsconsole/webroot/assets/img/uploads -type f -exec chmod 640 {} \;
sudo chmod g+s /var/www/pdnsconsole/webroot/assets/img/uploads
```

#### 4. Cron jobs

To support automated tasks, schedule the provided scripts:

- DNSSEC timed rollover finalization: `cron/dnssec_rollover.php`
- Session cleanup: `cron/session_cleanup.php`

Edit cron scheduled tasks
```bash
crontab -e
```
Add lines to schedule tasks (adjust PHP/Website path):  
```
*/15 * * * * /usr/bin/php /var/www/pdnsconsole/cron/dnssec_rollover.php > /dev/null 2>&1
0 * * * * /usr/bin/php /var/www/pdnsconsole/cron/session_cleanup.php > /dev/null 2>&1
```

#### 5. Optional: Let’s Encrypt (recommended) — obtain and install a certificate using Certbot.

If your active Nginx configuration already includes SSL (for example the bundled `config/nginx-pdnsconsole.conf`), Certbot may not be able to automatically modify it. In that case you can use a temporary HTTP-only server block that Certbot can edit safely.

1. Install Certbot and the Nginx plugin:

```bash
sudo apt update
sudo apt install -y certbot python3-certbot-nginx
```

2. Run Certbot to obtain and install certificates (Certbot will update the enabled site):

```bash
sudo certbot --nginx -d example.com -d www.example.com
```

3. After Certbot completes you have two options:

- Copy the SSL directives that were updated in the /etc/nginx/conf.d/nginx-pdnsconsole-certbot.conf.  Replace that .conf file with the /config/nginx-pdnsconsole.conf file and update the SSL directives to point at the Certbot-generated paths.

```nginx
ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
```

- Keep the /etc/nginx/conf.d/nginx-pdnsconsole-certbot.conf file and copy addtional config blocks from the /config/nginx-pdnsconsole.conf file, leaving the newly generated config lines added by Certbot.  

5. Verify Nginx is serving HTTPS and set up automatic renewal (Certbot installs a cron/systemd timer by default):


```bash
# Check .conf file for errors and fix if needed
sudo nginx -t
# Reload Nginx after updating config files
sudo systemctl reload nginx
# Check certbot is running and test a renewal
sudo systemctl status certbot.timer
sudo certbot renew --dry-run
```
#### 8. Finish and Log In

Point your browser to the site hostname and log in with the super admin you created.

### OPTIONAL: Load Sample Data for Testing

You can populate the database with sample tenants, domains, users, and DNS records for testing/demo purposes:

```bash
bash scripts/create_sample_data.sh
```

- This will use your current database configuration in `config/config.php`.
- Review and edit the script if you want to customize the sample data.

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
- [ ] Add ability to add "Secondary/Slave" zones (which will be read-only)


**PDNS Console** — Professional DNS management made simple.
