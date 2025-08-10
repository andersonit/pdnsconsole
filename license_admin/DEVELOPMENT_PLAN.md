# License Admin Frontend Development Plan

Private portal for provisioning PDNS Console commercial licenses and integrating Stripe payments.
Do NOT distribute publicly. Keep keys & credentials out of version control.

## Objectives
1. Accept customer details + plan selection.
2. Process payment (Stripe Checkout / Billing portal).
3. Capture installation code supplied later (or during purchase) and generate license key.
4. Allow regeneration / revocation of existing license keys.
5. Provide audit trail of actions.
6. Export license & customer data (CSV/JSON) for accounting.

## Data Model (see schema.sql)
- customers(id, email, name, organization, status)
- plans(id, code, name, domain_limit, price_cents, currency, interval_unit, active)
- purchases(id, customer_id, plan_id, stripe ids, amount, status)
- licenses(id, customer_id, purchase_id, installation_id, license_key, domain_limit, type, issued, revoked)
- license_events(id, license_id, event_type, detail)

## License Lifecycle
1. Customer selects plan -> Stripe Checkout Session created.
2. Webhook confirms payment (payment_intent.succeeded) -> create purchase (status=paid).
3. If installation code already provided (embedded in checkout metadata) -> generate license key immediately.
4. Else send email with secure link to portal to submit installation code -> then generate key.
5. License key signed using RSA private key (not checked into repo) via same format used by runtime: `PDNS-COMMERCIAL-BASE64(JSON)-HEX_SIG`.
6. JSON payload fields: email, type, domains (limit or 0), issued (YYYY-MM-DD), optional: purchase_id, installation_id (if bound). Keep payload minimal to avoid leaking internal info.

## Stripe Integration
- Use Checkout for one-time payments; product & price IDs map to plans.
- Map plan.code -> Stripe Price ID via config file.
- On webhook validate signature; update purchase, create license if possible.
- Support refunds: mark purchase refunded, revoke linked license (set revoked=1 + event log).

## Planned Pages (Admin Portal)
1. Login / Session management (simple email+password + CSRF)
2. Dashboard summary (counts: active licenses, pending payments)
3. Customers list & detail (show purchases, licenses)
4. Plans management (CRUD for internal codes; toggle active)
5. Purchases list (filter by status)
6. License issuance queue (purchases paid but missing installation_id)
7. License detail (regenerate, revoke, view events)
8. Audit log
9. Exports (CSV)

## API Endpoints (Internal)
- POST /webhook/stripe
- POST /license/issue (admin only)
- POST /license/revoke
- POST /installation/submit (customer provides installation code referencing purchase)

## Security
- Enforce strong random admin password; store bcrypt hashes.
- Separate `.env` for DB and Stripe secrets.
- Rate limit installation code submissions.
- Audit every license issue / revoke / regen.

## Implementation Phases
### Phase 1 (MVP)
- Auth + basic layout
- Plans seeded from schema
- Manual purchase creation (simulate payment) for testing
- License generation CLI script reuse / internal function
- Installation code submission form
- License listing & download

### Phase 2 (Stripe Integration)
- Stripe Checkout session creation
- Webhook handler
- Auto license generation on success
- Email notification templates

### Phase 3 (Lifecycle & Revocation)
- Revoke / regenerate license flows
- Audit log & license_events population
- Exports

### Phase 4 (Hardening)
- Rate limiting
- 2FA for admin login
- IP allowlist option
- Security headers & CSRF tokens

## CLI Utilities
- `php tools/issue_license.php --email --plan CODE --install INSTALLATION_ID`
- `php tools/revoke_license.php --key KEY --reason "..."`

## Monitoring
- Dashboard metrics: daily licenses issued, revenue (sum paid purchases), revocations.

## Future Enhancements
- Subscription renewals (Stripe Billing customer portal)
- Usage telemetry opt-in
- Self-service plan upgrades (prorated)

---
Keep private key offline; for signing inside portal load from secure path defined in ENV: LICENSE_PRIVATE_KEY_PATH.
