# Dynamic DNS (DDNS)

PDNS Console provides a built-in Dynamic DNS endpoint compatible with ddclient (dyndns2 protocol). Each token is bound 1:1 to a single A/AAAA record, with optional shared-secret and built‑in rate limiting.

## Endpoint

- URL: `/api/dynamic_dns.php`
- Auth: HTTP Basic
  - Username: token
  - Password: optional secret (if configured on the token)
- Methods:
  - GET: fetch current value, or update if `ip`/`myip` is provided
  - POST: update using `ip` or `myip`
- Responses:
  - JSON by default
  - Plain text dyndns2 when User-Agent is `ddclient`, `Accept: text/plain`, or `?format=plain`
    - `good <ip>`: updated
    - `nochg <ip>`: unchanged
    - `badauth`: invalid credentials or token inactive/expired
    - `abuse`: rate limited (Retry-After header provided)
    - `911`: general error

## Rate limiting

- 3 requests per 3 minutes per token
- On exceed, requests are throttled for 10 minutes (HTTP 429 + `Retry-After`)

## Token Management

Location: Zone → Dynamic DNS.

- Create a token bound to a specific A/AAAA record
- Optional secret (bcrypt hashed)
- Optional expiration
- Enable/disable/delete
- Observability: last used, last IP, current throttle state

## ddclient example

```
protocol=dyndns2
server=<your-console-host>
login=<TOKEN>
password=<SECRET_OR_EMPTY>
<zone-name>
```

ddclient will POST/GET to `/api/dynamic_dns.php` by default for dyndns2.

## Notes

- Only A/AAAA records are supported
- SOA serial is automatically updated after a change
- Audit events are recorded for auth failures, updates, and rate limiting
