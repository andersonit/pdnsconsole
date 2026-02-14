# PowerDNS Authoritative Server Installation & Integration Guide

This guide walks through setting up a local or production PowerDNS Authoritative Server using a MySQL / MariaDB backend and preparing its schema so it works with PDNS Console. It covers:

1. Install packages (Debian/Ubuntu & RHEL/CentOS examples)
2. Create and initialize the PowerDNS database (standard schema)
3. Apply PDNS Console migration additions (`db/migrate_from_powerdns.sql`)
4. Configure `pdns.conf` (API + webserver for PDNS Console)
5. Start and verify the service
6. Create a test zone & enable DNSSEC (optional)
7. Point PDNS Console to the API

---
## 1. Package Installation

### Debian / Ubuntu (apt)
```bash
sudo apt update
sudo apt install -y mariadb-server pdns-server pdns-backend-mysql pdns-tools
```

If you see: `Unable to locate package pdns-server` (older Ubuntu/Debian releases may not ship a recent PowerDNS), add the official PowerDNS repository:

```bash
sudo apt install -y curl gnupg lsb-release
curl -fsSL https://repo.powerdns.com/FD380FBB-pub.asc | sudo gpg --dearmor -o /usr/share/keyrings/powerdns.gpg
echo "deb [signed-by=/usr/share/keyrings/powerdns.gpg] http://repo.powerdns.com/$(lsb_release -cs) auth-48 main" | sudo tee /etc/apt/sources.list.d/powerdns-auth.list
sudo apt update
sudo apt install -y pdns-server pdns-backend-mysql pdns-tools
```

(Adjust `auth-48` to the desired major series if needed.)

### RHEL / CentOS / Alma / Rocky (dnf)
Enable the PowerDNS Authoritative repo first (example – adjust for version):
```bash
sudo dnf install -y epel-release
sudo rpm -Uvh https://repo.powerdns.com/repo-files/authoritative/powerdns-auth-48.repo
sudo dnf install -y MariaDB-server pdns pdns-backend-mysql pdns-tools
```
Then start MariaDB:
```bash
sudo systemctl enable --now mariadb
```

> If you use MySQL instead of MariaDB, package names may differ (e.g. `mysql-server`).

### STOP!!!
TO CONTINUE INSTALLING PDNS CONSOLE, MOVE TO [README FILE](README.md) 

---------------
# THE FOLLOWING INSTRUCITONS ARE INFORMATIONAL FOR BASE POWERDNS INSTALLATIONS
---
## 2. Create Database and User

```bash
sudo mysql -e "CREATE DATABASE powerdns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'powerdns'@'localhost' IDENTIFIED BY 'StrongPdnsPass!';"
sudo mysql -e "GRANT ALL ON powerdns.* TO 'powerdns'@'localhost'; FLUSH PRIVILEGES;"
```

---
## 3. Import the Default PowerDNS Schema

Locate the distributed schema file (path can vary by distro):
- Debian/Ubuntu: `/usr/share/doc/pdns-backend-mysql/schema.mysql.sql` (may be gzipped)  
- RHEL-based: `/usr/share/doc/pdns-backend-mysql-<version>/schema.mysql.sql`

Import:
```bash
sudo zcat /usr/share/doc/pdns-backend-mysql/schema.mysql.sql.gz | mysql powerdns 2>/dev/null || \
cat /usr/share/doc/pdns-backend-mysql/schema.mysql.sql | mysql powerdns
```

Verify tables:
```bash
mysql -D powerdns -e "SHOW TABLES;"
```
You should see tables like: `domains, records, supermasters, comments, domainmetadata, cryptokeys, tsigkeys`.

---
## 4. Configure `pdns.conf`

Edit (or create) `/etc/powerdns/pdns.conf`:
```
# Backend
launch=gmysql
gmysql-host=127.0.0.1
gmysql-dbname=powerdns
gmysql-user=powerdns
gmysql-password=StrongPdnsPass!
gmysql-dnssec=yes

# API / Webserver (required for PDNS Console DNSSEC features)
api=yes
api-key=DevTestKey123
webserver=yes
webserver-address=127.0.0.1
webserver-port=8081
webserver-allow-from=127.0.0.1

# Server identity (must match PDNS Console "Server ID" setting)
server-id=localhost

# Logging (tune to preference)
# 0=critical only, 1=errors, 2=warnings, 3=notice, 4=info (default), 5=debug, 6=insane (very verbose)
loglevel=4
```
Restart PowerDNS:
```bash
sudo systemctl enable --now pdns
sudo systemctl restart pdns
sudo systemctl status pdns --no-pager
```

---
## 5. Verify API Connectivity

```bash
curl -s -H 'X-API-Key: DevTestKey123' http://127.0.0.1:8081/api/v1/servers | jq .
```
Expected: JSON array with at least one server (`id`: `localhost`).

List zones (likely empty initially):
```bash
curl -s -H 'X-API-Key: DevTestKey123' http://127.0.0.1:8081/api/v1/servers/localhost/zones | jq .
```

---
## 6. Security Considerations
- Protect the API with firewall rules (only console host should access port 8081).
- Use TLS termination (reverse proxy: nginx/traefik) if remote console access is required.
- Rotate the API key periodically; update both `pdns.conf` and console settings.
- Restrict DB user privileges to only the `powerdns` schema.

---
## 13. Summary
After following these steps you have:
- Installed PowerDNS with a MySQL/MariaDB backend
- Configured and verified the API

You are now ready to manage zones and DNSSEC keys directly through PowerDNS command line or from within the raw MySQL database (optionallly install phpMyAdmin)
