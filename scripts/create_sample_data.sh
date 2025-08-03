#!/bin/bash
# PDNS Console Sample Data Creation Script (Corrected)
# This script creates sample tenants, domains, and DNS records for testing

# Database connection parameters
DB_HOST="127.0.0.1"
DB_USER="pdnscadmin"
DB_PASS="Ch1m3r@76!"
DB_NAME="pdnsconsole"

echo "Creating sample data for PDNS Console..."

# Function to execute SQL
execute_sql() {
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$1"
}

# 1. Create sample tenants (using correct column names)
echo "Creating sample tenants..."
execute_sql "
INSERT INTO tenants (name, contact_email, max_domains, created_at) VALUES
('Example Corp', 'admin@example.com', 10, NOW()),
('Test Company', 'admin@testcompany.com', 5, NOW()),
('Demo Organization', 'admin@demo.org', 0, NOW());
"

# 2. Create sample domains in PowerDNS format (no tenant_id in domains table)
echo "Creating sample domains..."
execute_sql "
INSERT INTO domains (name, master, last_check, type, notified_serial, account) VALUES
('example.com', NULL, NULL, 'NATIVE', NULL, NULL),
('testcompany.com', NULL, NULL, 'NATIVE', NULL, NULL),
('demo.org', NULL, NULL, 'NATIVE', NULL, NULL),
('subdomain.example.com', NULL, NULL, 'NATIVE', NULL, NULL);
"

# Get tenant and domain IDs for relationships
TENANT1_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT id FROM tenants WHERE name = 'Example Corp';")
TENANT2_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT id FROM tenants WHERE name = 'Test Company';")
TENANT3_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT id FROM tenants WHERE name = 'Demo Organization';")

DOMAIN1_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT id FROM domains WHERE name = 'example.com';")
DOMAIN2_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT id FROM domains WHERE name = 'testcompany.com';")
DOMAIN3_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT id FROM domains WHERE name = 'demo.org';")
DOMAIN4_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT id FROM domains WHERE name = 'subdomain.example.com';")

# 3. Create domain-tenant relationships
echo "Creating domain-tenant relationships..."
execute_sql "
INSERT INTO domain_tenants (domain_id, tenant_id) VALUES
($DOMAIN1_ID, $TENANT1_ID),
($DOMAIN4_ID, $TENANT1_ID),
($DOMAIN2_ID, $TENANT2_ID),
($DOMAIN3_ID, $TENANT3_ID);
"

echo "Creating DNS records..."

# 4. Create SOA records (required for each domain)
execute_sql "
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, ordername, auth) VALUES
($DOMAIN1_ID, 'example.com', 'SOA', 'ns1.example.com admin.example.com 2024080201 7200 3600 604800 86400', 86400, NULL, 0, NULL, 1),
($DOMAIN2_ID, 'testcompany.com', 'SOA', 'ns1.testcompany.com admin.testcompany.com 2024080201 7200 3600 604800 86400', 86400, NULL, 0, NULL, 1),
($DOMAIN3_ID, 'demo.org', 'SOA', 'ns1.demo.org admin.demo.org 2024080201 7200 3600 604800 86400', 86400, NULL, 0, NULL, 1),
($DOMAIN4_ID, 'subdomain.example.com', 'SOA', 'ns1.example.com admin.example.com 2024080201 7200 3600 604800 86400', 86400, NULL, 0, NULL, 1);
"

# 5. Create NS records
execute_sql "
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, ordername, auth) VALUES
($DOMAIN1_ID, 'example.com', 'NS', 'ns1.example.com', 86400, NULL, 0, NULL, 1),
($DOMAIN1_ID, 'example.com', 'NS', 'ns2.example.com', 86400, NULL, 0, NULL, 1),
($DOMAIN2_ID, 'testcompany.com', 'NS', 'ns1.testcompany.com', 86400, NULL, 0, NULL, 1),
($DOMAIN2_ID, 'testcompany.com', 'NS', 'ns2.testcompany.com', 86400, NULL, 0, NULL, 1),
($DOMAIN3_ID, 'demo.org', 'NS', 'ns1.demo.org', 86400, NULL, 0, NULL, 1),
($DOMAIN3_ID, 'demo.org', 'NS', 'ns2.demo.org', 86400, NULL, 0, NULL, 1);
"

# 6. Create A records
execute_sql "
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, ordername, auth) VALUES
($DOMAIN1_ID, 'example.com', 'A', '192.0.2.1', 3600, NULL, 0, NULL, 1),
($DOMAIN1_ID, 'www.example.com', 'A', '192.0.2.1', 3600, NULL, 0, NULL, 1),
($DOMAIN1_ID, 'mail.example.com', 'A', '192.0.2.10', 3600, NULL, 0, NULL, 1),
($DOMAIN1_ID, 'ns1.example.com', 'A', '192.0.2.2', 86400, NULL, 0, NULL, 1),
($DOMAIN1_ID, 'ns2.example.com', 'A', '192.0.2.3', 86400, NULL, 0, NULL, 1),
($DOMAIN2_ID, 'testcompany.com', 'A', '203.0.113.1', 3600, NULL, 0, NULL, 1),
($DOMAIN2_ID, 'www.testcompany.com', 'A', '203.0.113.1', 3600, NULL, 0, NULL, 1),
($DOMAIN3_ID, 'demo.org', 'A', '198.51.100.1', 3600, NULL, 0, NULL, 1),
($DOMAIN3_ID, 'www.demo.org', 'A', '198.51.100.1', 3600, NULL, 0, NULL, 1);
"

# 7. Create AAAA records (IPv6)
execute_sql "
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, ordername, auth) VALUES
($DOMAIN1_ID, 'example.com', 'AAAA', '2001:db8::1', 3600, NULL, 0, NULL, 1),
($DOMAIN1_ID, 'www.example.com', 'AAAA', '2001:db8::1', 3600, NULL, 0, NULL, 1),
($DOMAIN2_ID, 'testcompany.com', 'AAAA', '2001:db8:1::1', 3600, NULL, 0, NULL, 1);
"

# 8. Create CNAME records
execute_sql "
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, ordername, auth) VALUES
($DOMAIN1_ID, 'blog.example.com', 'CNAME', 'www.example.com', 3600, NULL, 0, NULL, 1),
($DOMAIN1_ID, 'shop.example.com', 'CNAME', 'www.example.com', 3600, NULL, 0, NULL, 1),
($DOMAIN2_ID, 'support.testcompany.com', 'CNAME', 'www.testcompany.com', 3600, NULL, 0, NULL, 1);
"

# 9. Create MX records
execute_sql "
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, ordername, auth) VALUES
($DOMAIN1_ID, 'example.com', 'MX', 'mail.example.com', 3600, 10, 0, NULL, 1),
($DOMAIN1_ID, 'example.com', 'MX', 'mail2.example.com', 3600, 20, 0, NULL, 1),
($DOMAIN2_ID, 'testcompany.com', 'MX', 'mail.testcompany.com', 3600, 10, 0, NULL, 1),
($DOMAIN3_ID, 'demo.org', 'MX', 'mail.demo.org', 3600, 10, 0, NULL, 1);
"

# 10. Create TXT records (including SPF, DKIM, DMARC)
execute_sql "
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, ordername, auth) VALUES
($DOMAIN1_ID, 'example.com', 'TXT', 'v=spf1 ip4:192.0.2.0/24 include:_spf.google.com ~all', 3600, NULL, 0, NULL, 1),
($DOMAIN1_ID, 'example.com', 'TXT', 'google-site-verification=example123456789', 3600, NULL, 0, NULL, 1),
($DOMAIN1_ID, '_dmarc.example.com', 'TXT', 'v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com', 3600, NULL, 0, NULL, 1),
($DOMAIN1_ID, 'selector1._domainkey.example.com', 'TXT', 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC...', 3600, NULL, 0, NULL, 1),
($DOMAIN2_ID, 'testcompany.com', 'TXT', 'v=spf1 ip4:203.0.113.0/24 ~all', 3600, NULL, 0, NULL, 1);
"

# 11. Create SRV records
execute_sql "
INSERT INTO records (domain_id, name, type, content, ttl, prio, disabled, ordername, auth) VALUES
($DOMAIN1_ID, '_sip._tcp.example.com', 'SRV', '10 5 5060 sip.example.com', 3600, NULL, 0, NULL, 1),
($DOMAIN1_ID, '_xmpp-server._tcp.example.com', 'SRV', '5 0 5269 xmpp.example.com', 3600, NULL, 0, NULL, 1);
"

# 12. Create some comments for records
execute_sql "
INSERT INTO comments (domain_id, name, type, modified_at, account, comment) VALUES
($DOMAIN1_ID, 'example.com', 'A', UNIX_TIMESTAMP(), 'admin', 'Main website IP address'),
($DOMAIN1_ID, 'mail.example.com', 'A', UNIX_TIMESTAMP(), 'admin', 'Mail server primary IP');
"

# 13. Create sample tenant users (using correct admin_users table structure)
echo "Creating sample tenant users..."

# Note: Passwords are hashed using PHP's password_hash() function
# Default password for all test users: "password123"
HASHED_PASSWORD='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'

execute_sql "
INSERT INTO admin_users (username, email, password_hash, role, is_active, created_at) VALUES
('john.doe', 'john.doe@example.com', '$HASHED_PASSWORD', 'tenant_admin', 1, NOW()),
('jane.smith', 'jane.smith@testcompany.com', '$HASHED_PASSWORD', 'tenant_admin', 1, NOW()),
('viewer.user', 'viewer@example.com', '$HASHED_PASSWORD', 'viewer', 1, NOW());
"

# 14. Create user-tenant relationships
USER1_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT id FROM admin_users WHERE username = 'john.doe';")
USER2_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT id FROM admin_users WHERE username = 'jane.smith';")
USER3_ID=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -sN -e "SELECT id FROM admin_users WHERE username = 'viewer.user';")

execute_sql "
INSERT INTO user_tenants (user_id, tenant_id) VALUES
($USER1_ID, $TENANT1_ID),
($USER2_ID, $TENANT2_ID),
($USER3_ID, $TENANT1_ID);
"

echo ""
echo "Sample data creation completed successfully!"
echo ""
echo "Created:"
echo "- 3 sample tenants (Example Corp, Test Company, Demo Organization)"
echo "- 4 sample domains with full DNS records"
echo "- Various record types: SOA, NS, A, AAAA, CNAME, MX, TXT, SRV"
echo "- Sample SPF, DKIM, and DMARC records"
echo "- Record comments for documentation"
echo "- 3 sample tenant users with proper relationships"
echo ""
echo "Test user credentials:"
echo "- Username: john.doe, Password: password123 (Example Corp admin)"
echo "- Username: jane.smith, Password: password123 (Test Company admin)"
echo "- Username: viewer.user, Password: password123 (Example Corp viewer)"
echo ""
echo "Sample domains created:"
echo "- example.com (Example Corp) - Full DNS setup"
echo "- testcompany.com (Test Company) - Full DNS setup"
echo "- demo.org (Demo Organization) - Basic DNS setup"
echo "- subdomain.example.com (Example Corp) - Subdomain example"
echo ""
echo "You can now log into the PDNS Console and explore the sample data!"
