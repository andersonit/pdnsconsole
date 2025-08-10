# PDNS Console License Admin (Private Tool)

This folder contains a standalone, non-distributed admin tool for generating commercial (and free override) license keys.
It is **NOT** shipped to end users. Keep it in a private / internal repository.

## Components
- `schema.sql` – Database schema for customers, plans, purchases, licenses, events (optional but recommended).
- `config.sample.php` – Sample configuration (copy to `config.php`).
- `autoload.php` – Very small PSR-0 style autoloader for `lib/`.
- `lib/LicenseSigner.php` – RSA signer class used by CLI + portal.
- `cli_generate.php` – Command line license generator (can persist to DB & log events).
- `public/index.php` – Minimal web form portal to create & list licenses.

## Current Flow (Public Console)
1. Customer installs open-source PDNS Console (ships running in Free mode).
2. Customer opens Admin -> License page and copies the Installation Code.
3. You (vendor) open this private license admin (CLI or portal), paste the Installation Code (or leave blank for portable license), set domains limit / plan, generate license key.
4. Provide license key to customer (secure channel). They paste key in their UI. System validates offline.

## License Format
```
PDNS-TYPE-BASE64(JSON)-HEX_SIGNATURE
```

Canonical JSON payload fields (ordering enforced before signing):
- `email` (string) – Customer email.
- `type` (string) – `commercial` or `free`.
- `domains` (int) – 0 means unlimited (only interpreted as unlimited for commercial keys; free mode internal fallback may still cap differently if desired).
- `issued` (YYYY-MM-DD) – Auto injected if omitted.
- `installation_id` (string, optional) – If present, locks license to that installation.

Signature: RSA SHA256 over the BASE64(JSON) segment (not the full composite string) using your private key.

## Quick Start
1. Copy `config.sample.php` to `config.php` and edit values.
2. Generate an RSA key pair (private kept here, public key copied—obfuscated or external file—into public console deployment):
   ```
   openssl genrsa -out private.pem 4096
   openssl rsa -in private.pem -pubout -out public.pem
   ```
3. Set `private_key_path` in `config.php` to the absolute (or relative) path of `private.pem`.
4. (Optional) Create database & import schema:
   ```
   mysql -u root -p pdns_license < schema.sql
   ```
5. Use CLI or portal:
   - CLI example:
     ```
     php cli_generate.php --email customer@example.com --domains 250 --type commercial --installation-id ABC123
     ```
   - Portal: Serve `public/` (e.g. `php -S 127.0.0.1:8081 -t public`) ensuring access restricted (firewall / VPN / auth).

## Portal Notes
- Very minimal; no authentication built-in. Protect via network controls (IP allow list in `config.php` + reverse proxy auth recommended).
- Persists generated licenses if DB configured; otherwise operates stateless.
- Lists last 25 licenses (truncated key display).

## CLI Notes
Run `php cli_generate.php --help` for supported options. When DB configured, it inserts/updates customer, license, and logs an event row (`CLI_GENERATE`).

## Database Schema Overview
- `customers` – End customer identities (email).
- `plans` – Predefined plan metadata (seeded examples).
- `purchases` – (Future) commercial purchase records or Stripe mapping.
- `licenses` – Issued licenses with current domains limit, status.
- `license_events` – Append-only audit trail (generation, revocation, etc.).

## Revocation / Updates (Future)
To revoke: set `status='revoked'` in `licenses` and optionally emit event. Public console could periodically (optional) query an endpoint or accept a signed revocation list (not yet implemented).

## Security Hardening Ideas
- HTTP basic auth or SSO in front of portal.
- IP allow list (simple array already supported in `config.php`).
- Rate limiting & CSRF token if exposure increases.
- Split signing key onto offline machine; portal requests offline signing (manual approval) for high-value plans.

## Stripe / Commerce Roadmap (Deferred)
See `DEVELOPMENT_PLAN.md` for staged integration (webhook ingestion, purchase linking, automated license issuance, upgrade/downgrade handling).

## Testing a License End-to-End
1. Start portal locally; create a license for your email with small domain cap (e.g. 5).
2. Paste key into public console License page.
3. Create domains until limit reached; verify UI warnings & enforcement.
4. Generate new license with higher limit & same installation_id; replace key; verify limit increases without restart.

## Troubleshooting
- "Signature invalid" – Ensure you copied latest public key into the public console external key path / obfuscation block matches.
- "Integrity warning" in console – Public key mismatch or tampering; redeploy correct public key file.
- Installation lock not taking effect – Confirm installation_id in license payload matches value displayed in console.
- DB not persisting – Check DSN / credentials; ensure schema imported.

## IMPORTANT
Never ship `private.pem`, `config.php` (if it contains sensitive paths), or this entire `license_admin` folder to customers.
