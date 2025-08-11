# DNSSEC Management Guide

This document explains how PDNS Console implements and manages DNSSEC, including key lifecycle, rollover automation, and DS delegation assistance.

## Overview
PDNS Console integrates with the PowerDNS Authoritative API to provide end‑to‑end DNSSEC management:
- Enable / disable DNSSEC for a zone
- Generate cryptographic keys (CSK, KSK, ZSK) with selectable algorithms
- Rollover strategies: Add, Immediate Replace, Timed Rollover
- Automated timed rollover cron with deactivation-first policy & delayed deletion
- DS record aggregation and registrar submission assistance
- Lifecycle visibility (Rollover progress, Pending retire, Inactive delete countdown)
- Safety modals preventing accidental outage-causing actions

## Key Concepts
| Term | Description |
|------|-------------|
| CSK | Combined Signing Key (single key signs records & has DS) |
| KSK | Key Signing Key (signs DNSKEY set, has DS) |
| ZSK | Zone Signing Key (signs zone records; no DS) |
| Hold Period | Days both old and new keys coexist during timed rollover before old deactivation |
| Deletion Grace | Additional days after deactivation before permanent deletion |

Environment variables (fallback defaults shown):
```
ROLLOVER_INTERVAL_DAYS=90
HOLD_PERIOD_DAYS=7
DELETION_GRACE_DAYS=7
DEFAULT_ALGORITHM=ECDSAP256SHA256
DEFAULT_KEYTYPE=csk
RSA_BITS=2048
```
Global hold period can be set via System Settings (dnssec_hold_period_days). Per-domain overrides are presently disabled to keep policy uniform.

## Rollover Modes
1. Add: Creates an additional active key; no automatic retirement.
2. Immediate Replace: Creates new key and immediately deactivates older active key(s) of same type+algorithm.
3. Timed Rollover: Creates new key; old remains active until hold period elapses; cron then deactivates old, later deletes after grace.

### Timed Rollover Flow
1. User generates new key in Timed mode.
2. Metadata marker PDNSCONSOLE-ROLLSTART stored.
3. Both keys sign; DS for new key (if KSK/CSK) published at parent.
4. Cron (after HOLD_PERIOD_DAYS) deactivates old key(s) and records deactivation timestamp PDNSCONSOLE-OLDKEY-<id>-DEACTIVATED.
5. After DELETION_GRACE_DAYS cron permanently deletes inactive aged keys.

## Cron Script
File: `cron/dnssec_rollover.php`
- Initiates rollovers when interval since PDNSCONSOLE-ROLLDATE >= ROLLOVER_INTERVAL_DAYS and only one active key for group.
- Completes timed rollovers by deactivating old keys.
- Cleans up deactivated keys past grace window.
Run daily via cron or systemd timer.

Example crontab:
```
# Daily DNSSEC rollover maintenance (verbose log)
15 2 * * * /usr/bin/php /path/to/app/cron/dnssec_rollover.php --verbose >> /var/log/pdnsconsole/dnssec_rollover.log 2>&1
```

## UI Elements
- DNSSEC Status Card: Enable/Disable + Serial + Rectify
- Key Table: Lifecycle column shows Active, Rollover, Pending Retire, Inactive (delete countdown)
- Safety Modals: Deactivate (warn if only key), Delete (high risk) – disabled during timed rollover for retiring keys
- DS Assist Panel: Copy DS button, checklist, rollover status summary

## DS Delegation Assistance
The DS panel aggregates all DS records from active keys. Recommended procedure:
1. Copy DS records (Copy button).
2. Publish at registrar / parent zone.
3. Wait for parent TTL propagation (often 1–24h).
4. Allow cron to handle old key deactivation (timed) or manually manage (add/immediate modes).
5. Verify chain using `dig +dnssec <zone> DS` and `dig +multi <zone> DNSKEY`.

## Audit Events
Representative audit actions (table `audit_log`):
- DNSSEC_ENABLE / DNSSEC_DISABLE
- DNSSEC_KEY_CREATE
- DNSSEC_KEY_ROLLOVER_START
- DNSSEC_KEY_ROLLOVER_COMPLETE (cron)
- DNSSEC_KEY_DEACTIVATE (manual or cron) / DNSSEC_KEY_ACTIVATE (manual)
- DNSSEC_KEY_DELETE (manual or cleanup)
- DNSSEC_RECTIFY

Metadata includes zone name, rollover mode, hold days, reason tags, and key IDs when applicable.

## Operational Best Practices
- Prefer CSK + ECDSAP256SHA256 for modern, short signatures.
- Use Timed Rollover for scheduled rotations; Immediate only for urgent rekey.
- Avoid manual deletion unless certain DS no longer references target key.
- Keep cron running daily; missed runs delay rollovers but do not corrupt state.
- Monitor Lifecycle column for anomalies (multiple pending retire without progress indicates cron issue).

## Troubleshooting
| Symptom | Possible Cause | Action |
|---------|----------------|--------|
| Rollover never completes | Cron not running or metadata mismatch | Run cron manually with --verbose; inspect metadata rows |
| Old key deleted unexpectedly | Deletion grace too short | Increase DELETION_GRACE_DAYS env and restart cron schedule |
| DS validation fails after rollover | Parent DS not updated or insufficient propagation time | Re-publish DS; wait full parent TTL; verify with dig |
| Keys not generating | PowerDNS API auth or DNSSEC disabled in backend | Check pdns.conf for gmysql-dnssec backend and API key |

## Future Enhancements (Planned)
- Parent DS publish checklist state tracking
- Inline DNSKEY display/export
- Automated external validation probes (optional)
- Enhanced test harness with mocked PowerDNS responses

---
Last Updated: 2025-08-11
