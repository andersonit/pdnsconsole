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
## 4. Apply PDNS Console Migration Additions

The PDNS Console expects some additional tables / constraints. Apply the migration script located in the project repository:

```bash
mysql -D powerdns < /path/to/pdnsconsole/db/migrate_from_powerdns.sql
```

> Replace `/path/to/pdnsconsole` with your actual cloned path.

Re-check tables to ensure new structures (for example any supplemental tables used by the console) are present.

---
## 5. Configure `pdns.conf`

Edit (or create) `/etc/powerdns/pdns.conf`:
```
# Backend
launch=gmysql
gmysql-host=127.0.0.1
gmysql-dbname=powerdns
gmysql-user=powerdns
gmysql-password=StrongPdnsPass!

# API / Webserver (required for PDNS Console DNSSEC features)
api=yes
api-key=DevTestKey123
webserver=yes
webserver-address=127.0.0.1
webserver-port=8081
webserver-allow-from=127.0.0.1

# Server identity (must match PDNS Console "Server ID" setting)
server-id=localhost

# (Optional) tighten recursion & security
recursor=     # leave blank for pure authoritative
allow-recursion=0.0.0.0/0
allow-axfr-ips=127.0.0.1

# SOA defaults (console may override per zone)
default-soa-content=ns1.example.test hostmaster.example.test 0 10800 3600 604800 3600

# Logging (tune to preference)
loglevel=4
```
Restart PowerDNS:
```bash
sudo systemctl enable --now pdns
sudo systemctl restart pdns
sudo systemctl status pdns --no-pager
```

---
## 6. Verify API Connectivity

```bash
curl -s -H 'X-API-Key: DevTestKey123' http://127.0.0.1:8081/api/v1/servers | jq .
```
Expected: JSON array with at least one server (`id`: `localhost`).

List zones (likely empty initially):
```bash
curl -s -H 'X-API-Key: DevTestKey123' http://127.0.0.1:8081/api/v1/servers/localhost/zones | jq .
```

---
## 7. Create a Test Zone

You can use `pdnsutil` or the API.

### Using `pdnsutil`
```bash
sudo pdnsutil create-zone test.local ns1.test.local
sudo pdnsutil add-record test.local @ A 192.0.2.10
sudo pdnsutil add-record test.local ns1 A 192.0.2.53
```

### Enable DNSSEC
```bash
sudo pdnsutil secure-zone test.local   # (or: pdnsutil enable-dnssec test.local)
sudo pdnsutil show-zone test.local --dnssec
```

### Rectify (when needed)
```bash
sudo pdnsutil rectify-zone test.local
```

### Via API (alternative)
```bash
curl -X POST -H 'X-API-Key: DevTestKey123' -H 'Content-Type: application/json' \
  http://127.0.0.1:8081/api/v1/servers/localhost/zones \
  -d '{
    "name": "test.local.",
    "kind": "Native",
    "masters": [],
    "nameservers": ["ns1.test.local."]
  }'

curl -X PATCH -H 'X-API-Key: DevTestKey123' -H 'Content-Type: application/json' \
  http://127.0.0.1:8081/api/v1/servers/localhost/zones/test.local. \
  -d '{"dnssec": true}'
```

---
## 8. Add the Zone to PDNS Console

In PDNS Console, create the same domain (e.g. `test.local`). Ensure:
- Domain name matches exactly (without trailing dot in the UI; code will handle trailing dot in API calls).
- PDNS API settings (host, port, server-id, api key) are configured: 127.0.0.1 / 8081 / localhost / DevTestKey123
- Use the Test Connection button to confirm connectivity.

Then open the DNSSEC page for that zone; it should show status and keys if DNSSEC was enabled.

---
## 9. Applying Future Schema Changes

If PDNS Console introduces new migrations:
1. Back up DB: `mysqldump powerdns > backup_$(date +%F).sql`
2. Apply new migration SQL from `db/migrations/*.sql`
3. Verify application functionality

---
## 10. Troubleshooting

| Symptom | Possible Cause | Resolution |
|---------|----------------|-----------|
| 401 Unauthorized | Wrong API key / `api=yes` missing | Re-check `pdns.conf`, restart, confirm key matches console settings |
| 404 on zone in DNSSEC page | Zone not in PowerDNS OR server-id mismatch OR naming variant | Confirm zone exists via `pdnsutil list-all-zones`, ensure `server-id` matches console setting |
| DNSSEC keys not listed | DNSSEC not enabled or permission issue | Run `pdnsutil secure-zone <zone>` and reload |
| Connection OK in settings but failures later | Host/port reachable but wrong server-id | Verify `server-id` and console value |
| Mixed charset errors | Database created with wrong charset | Ensure `utf8mb4` charset & collation |

---
## 11. Optional: Docker Quick Start

```bash
docker run -d --name pdns \
  -p 5300:53/udp -p 5300:53/tcp -p 8081:8081 \
  -e PDNS_ALLOW_AXFR_IPS=127.0.0.1 \
  -e PDNS_API_KEY=DevTestKey123 \
  powerdns/powerdns-auth-48:latest
```
Then create zones with:
```bash
docker exec -it pdns pdnsutil create-zone test.local ns1.test.local
docker exec -it pdns pdnsutil secure-zone test.local
```
Set console host=127.0.0.1 port=8081 server-id=localhost key=DevTestKey123.

> Docker image stores data in the container by default; mount a volume for persistence in production.

---
## 12. Security Considerations
- Protect the API with firewall rules (only console host should access port 8081).
- Use TLS termination (reverse proxy: nginx/traefik) if remote console access is required.
- Rotate the API key periodically; update both `pdns.conf` and console settings.
- Restrict DB user privileges to only the `powerdns` schema.

---
## 13. Summary
After following these steps you have:
- Installed PowerDNS with a MySQL/MariaDB backend
- Initialized the standard schema and applied PDNS Console migrations
- Configured and verified the API
- Created and DNSSEC-enabled a test zone
- Integrated PDNS Console for live DNSSEC management

You are now ready to manage zones and DNSSEC keys directly through PDNS Console.
