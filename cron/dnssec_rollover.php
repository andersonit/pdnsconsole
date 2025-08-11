#!/usr/bin/env php
<?php
/**
 * PDNS Console - Automated DNSSEC Rollover Script
 *
 * Purpose:
 *   Implements a simple two‑phase automated rollover policy for DNSSEC keys.
 *   Phase 1 (Initiate): When rollover interval elapsed and only one active key, create a new key (pre‑publish) and mark start time.
 *   Phase 2 (Complete): After hold period elapsed (to allow DS update at parent) DEACTIVATE (not delete) older active keys of same type/algorithm and mark deactivation timestamp. A later cleanup pass deletes keys after grace period.
 *
 * Tracking state:
 *   Uses domainmetadata table with custom kinds:
 *     PDNSCONSOLE-ROLLDATE   => Date (Y-m-d) of last completed rollover (or initialization baseline)
 *     PDNSCONSOLE-ROLLSTART  => Timestamp (Y-m-d H:i:s) when new key was introduced (pre‑publish phase)
 *
 * Policy parameters (override via environment variables):
 *   ROLLOVER_INTERVAL_DAYS  (default 90)  – Minimum days between completed rollovers
 *   HOLD_PERIOD_DAYS        (default 7)   – Days to wait after introducing new key before pruning old
 *   DEFAULT_ALGORITHM       (default ECDSAP256SHA256)
 *   DEFAULT_KEYTYPE         (default csk)
 *   RSA_BITS                (default 2048) – Only used if algorithm begins with RSA
 *
 * Execution:
 *   php cron/dnssec_rollover.php [--dry-run] [--verbose]
 *   Schedule via cron (e.g. daily) or systemd timer.
 *
 * Safety / Notes:
 *   - This is a basic helper; it does not verify parent DS publication. HOLD_PERIOD_DAYS must accommodate DS TTL + parent update cycles.
 *   - Old keys are first deactivated, then deleted only after a grace period (default 7 days) post-deactivation with no reactivation.
 *   - If a domain has multiple active keys due to manual actions and no ROLLSTART marker, the script will only act if a valid ROLLSTART age condition is met OR initiating conditions apply.
 *   - First run initializes PDNSCONSOLE-ROLLDATE for domains lacking it (baseline, no action).
 */

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI" . PHP_EOL; exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$verbose = in_array('--verbose', $argv, true) || $dryRun;

function v($msg){ global $verbose; if($verbose) echo '[*] ' . $msg . PHP_EOL; }
function info($msg){ echo $msg . PHP_EOL; }
function warn($msg){ fwrite(STDERR, "[WARN] $msg\n"); }

require_once __DIR__ . '/../webroot/includes/bootstrap.php';
require_once __DIR__ . '/../webroot/classes/PdnsApiClient.php';
require_once __DIR__ . '/../webroot/classes/AuditLog.php';

$db = Database::getInstance();
$audit = new AuditLog();
$client = null;
try { $client = new PdnsApiClient(); } catch (Exception $e) { warn('Cannot initialize PdnsApiClient: ' . $e->getMessage()); exit(1); }

// Policy parameters
$ROLLOVER_INTERVAL_DAYS = (int)getenv('ROLLOVER_INTERVAL_DAYS') ?: 90;
$HOLD_PERIOD_DAYS = (int)getenv('HOLD_PERIOD_DAYS') ?: 7; // May be overridden per-domain via PDNSCONSOLE-HOLD metadata
$DELETION_GRACE_DAYS = (int)getenv('DELETION_GRACE_DAYS') ?: 7; // Days after deactivation before actual delete
$DEFAULT_ALGO = getenv('DEFAULT_ALGORITHM') ?: 'ECDSAP256SHA256';
$DEFAULT_KEYTYPE = getenv('DEFAULT_KEYTYPE') ?: 'csk';
$RSA_BITS = (int)getenv('RSA_BITS') ?: 2048;

$now = new DateTimeImmutable('now');

// Fetch domains that currently have at least one active key
$domains = $db->fetchAll("SELECT d.id,d.name FROM domains d JOIN cryptokeys ck ON ck.domain_id=d.id WHERE ck.active=1 GROUP BY d.id,d.name");
if (!$domains) { v('No domains with active DNSSEC keys found.'); exit(0); }

// Helper: get metadata value
$getMeta = function($domainId, $kind) use ($db){
    $row = $db->fetch("SELECT content FROM domainmetadata WHERE domain_id=? AND kind=?", [$domainId, $kind]);
    return $row['content'] ?? null;
};
// Helper: set metadata
$setMeta = function($domainId, $kind, $content) use ($db){
    $exists = $db->fetch("SELECT id FROM domainmetadata WHERE domain_id=? AND kind=?", [$domainId, $kind]);
    if ($exists) {
        $db->execute("UPDATE domainmetadata SET content=? WHERE id=?", [$content, $exists['id']]);
    } else {
        $db->execute("INSERT INTO domainmetadata (domain_id, kind, content) VALUES (?,?,?)", [$domainId, $kind, $content]);
    }
};
// Helper: delete metadata
$delMeta = function($domainId, $kind) use ($db){ $db->execute("DELETE FROM domainmetadata WHERE domain_id=? AND kind=?", [$domainId, $kind]); };

// Get cryptokeys for domain
$getKeys = function($domainId) use ($db){
    return $db->fetchAll("SELECT id, domain_id, active, flags, content FROM cryptokeys WHERE domain_id=? ORDER BY id ASC", [$domainId]);
};

// Extract algorithm & keytype from content (PowerDNS stores full DNSKEY RDATA). Basic parse.
$parseKey = function($row){
    // DNSKEY RDATA: flags protocol algorithm publickey
    // flags: 256=ZSK, 257=KSK, for CSK modern PDNS uses combined semantics; we infer keytype by flags & active group.
    $parts = preg_split('/\s+/', trim($row['content']));
    $algoNum = isset($parts[2]) ? (int)$parts[2] : null;
    $algMap = [8=>'RSASHA256',10=>'RSASHA512',13=>'ECDSAP256SHA256',14=>'ECDSAP384SHA384',15=>'ED25519',16=>'ED448'];
    $algorithm = $algMap[$algoNum] ?? ('ALG' . $algoNum);
    $flags = (int)$row['flags'];
    $keytype = $flags === 257 ? 'ksk' : ($flags === 256 ? 'zsk' : 'csk');
    return [ 'id' => $row['id'], 'algorithm' => $algorithm, 'keytype' => $keytype, 'flags' => $flags, 'active' => (int)$row['active'] ];
};

// Phase handlers
function initiateRollover($domain, $existingKeys, $parseKey, $client, $setMeta, $audit, $now, $DEFAULT_ALGO, $DEFAULT_KEYTYPE, $RSA_BITS, $dryRun){
    $parsed = array_map($parseKey, $existingKeys);
    // Use algorithm & keytype of existing single key if present; else defaults
    $algo = $parsed[0]['algorithm'] ?? $DEFAULT_ALGO;
    $keytype = $parsed[0]['keytype'] ?? $DEFAULT_KEYTYPE;
    $payload = [ 'keytype' => $keytype, 'algorithm' => $algo ];
    if (str_starts_with($algo, 'RSA')) { $payload['bits'] = $RSA_BITS; }
    if ($dryRun) { v("[DRY] Would create new key for {$domain['name']} algo=$algo keytype=$keytype"); return true; }
    try { $client->createKey($domain['name'] . '.', $payload); } catch (Exception $e) { warn("Create key failed for {$domain['name']}: " . $e->getMessage()); return false; }
    $setMeta($domain['id'], 'PDNSCONSOLE-ROLLSTART', $now->format('Y-m-d H:i:s'));
    $audit->logAction(null, 'DNSSEC_KEY_ROLLOVER_START', 'domains', $domain['id'], null, null, null, [ 'domain' => $domain['name'], 'algorithm' => $algo, 'keytype' => $keytype ]);
    v("Initiated rollover for {$domain['name']} (algo=$algo keytype=$keytype)");
    return true;
}

function completeRollover($domain, $existingKeys, $parseKey, $client, $delMeta, $setMeta, $audit, $now, $dryRun){
    $parsed = array_map($parseKey, $existingKeys);
    // Group by keytype+algorithm; keep highest id per group (newest), remove others
    $groups = [];
    foreach ($parsed as $k){ $groups[$k['keytype'] . '|' . $k['algorithm']][] = $k; }
    $removed = [];
    foreach ($groups as $grp => $arr){
        if (count($arr) < 2) continue; // nothing to prune
        usort($arr, fn($a,$b)=> $a['id'] <=> $b['id']);
        $newest = array_pop($arr); // keep newest active
        foreach ($arr as $old){
            if ($dryRun) { v("[DRY] Would deactivate old key id={$old['id']} for {$domain['name']}"); }
            else {
                try { $client->setKeyActive($domain['name'] . '.', $old['id'], false); $removed[] = $old['id']; }
                catch (Exception $e) { warn("Deactivate key {$old['id']} failed for {$domain['name']}: " . $e->getMessage()); }
            }
        }
    }
    if (!$dryRun) {
        $delMeta($domain['id'], 'PDNSCONSOLE-ROLLSTART');
        $setMeta($domain['id'], 'PDNSCONSOLE-ROLLDATE', $now->format('Y-m-d'));
        if (!empty($removed)) {
            // Store deactivation timestamp per key
            foreach ($removed as $kid) {
                $setMeta($domain['id'], 'PDNSCONSOLE-OLDKEY-' . $kid . '-DEACTIVATED', $now->format('Y-m-d H:i:s'));
            }
        }
        $audit->logAction(null, 'DNSSEC_KEY_ROLLOVER_COMPLETE', 'domains', $domain['id'], null, null, null, [ 'domain' => $domain['name'], 'deactivated_keys' => $removed ]);
    }
    v("Completed rollover for {$domain['name']} (deactivated " . count($removed) . ' old keys)');
    return true;
}

// Cleanup: delete keys whose deactivation grace has elapsed
function cleanupDeactivatedKeys($domain, $parseKey, $client, $getMeta, $delMeta, $audit, $now, $DELETION_GRACE_DAYS, $dryRun){
    $keys = Database::getInstance()->fetchAll("SELECT id, domain_id, active FROM cryptokeys WHERE domain_id=?", [$domain['id']]);
    if (!$keys) return 0;
    $deleted = 0;
    foreach ($keys as $k){
        if ((int)$k['active'] === 1) continue; // only consider inactive
        $marker = $getMeta($domain['id'], 'PDNSCONSOLE-OLDKEY-' . $k['id'] . '-DEACTIVATED');
        if (!$marker) continue;
        try { $dt = new DateTimeImmutable($marker); } catch (Exception $e){ continue; }
        $ageDays = (int)$dt->diff($now)->format('%a');
        if ($ageDays >= $DELETION_GRACE_DAYS) {
            if ($dryRun) { v("[DRY] Would delete deactivated key id={$k['id']} domain={$domain['name']} age={$ageDays}d"); }
            else {
                try { $client->deleteKey($domain['name'] . '.', $k['id']); $deleted++; $delMeta($domain['id'], 'PDNSCONSOLE-OLDKEY-' . $k['id'] . '-DEACTIVATED'); $audit->logAction(null, 'DNSSEC_KEY_DELETE', 'cryptokeys', $k['id'], null, null, null, ['domain'=>$domain['name'],'age_days'=>$ageDays]); }
                catch (Exception $e){ warn("Delete inactive key {$k['id']} failed for {$domain['name']}: " . $e->getMessage()); }
            }
        }
    }
    return $deleted;
}

$initiated = 0; $completed = 0; $baselined = 0; $skipped = 0;

foreach ($domains as $domain) {
    $name = rtrim($domain['name'], '.');
    $keys = $getKeys($domain['id']);
    if (!$keys) { $skipped++; continue; }

    $rollDate = $getMeta($domain['id'], 'PDNSCONSOLE-ROLLDATE');
    $rollStart = $getMeta($domain['id'], 'PDNSCONSOLE-ROLLSTART');
    $holdOverride = $getMeta($domain['id'], 'PDNSCONSOLE-HOLD');
    $effectiveHold = $holdOverride !== null ? (int)$holdOverride : $HOLD_PERIOD_DAYS;

    // Baseline initialization (first run)
    if (!$rollDate) {
        if (!$dryRun) { $setMeta($domain['id'], 'PDNSCONSOLE-ROLLDATE', $now->format('Y-m-d')); }
        v("Baseline set for {$name}");
        $baselined++; continue;
    }

    // If in completion phase
    if ($rollStart) {
        try { $startDT = new DateTimeImmutable($rollStart); } catch (Exception $e){ warn("Invalid ROLLSTART for {$name}"); $skipped++; continue; }
        $ageDays = (int)$startDT->diff($now)->format('%a');
        if ($ageDays >= $effectiveHold) {
            completeRollover($domain, $keys, $parseKey, $client, $delMeta, $setMeta, $audit, $now, $dryRun) && $completed++;
        } else {
            v("Rollover pending hold for {$name} ({$ageDays}/{$effectiveHold} days)");
            $skipped++;
        }
        continue;
    }

    // Initiation phase check
    try { $dateDT = new DateTimeImmutable($rollDate); } catch (Exception $e){ warn("Invalid ROLLDATE for {$name}"); $skipped++; continue; }
    $since = (int)$dateDT->diff($now)->format('%a');
    if ($since >= $ROLLOVER_INTERVAL_DAYS && count(array_filter($keys, fn($k)=> (int)$k['active'] === 1)) === 1) {
        initiateRollover($domain, $keys, $parseKey, $client, $setMeta, $audit, $now, $DEFAULT_ALGO, $DEFAULT_KEYTYPE, $RSA_BITS, $dryRun) && $initiated++;
    } else {
        $skipped++;
        v("Skip {$name} (since={$since}d, activeKeys=" . count(array_filter($keys, fn($k)=> (int)$k['active']===1)) . ")");
    }

    // Cleanup pass each loop
    $deletedOld = cleanupDeactivatedKeys($domain, $parseKey, $client, $getMeta, $delMeta, $audit, $now, $DELETION_GRACE_DAYS, $dryRun);
    if ($deletedOld > 0) { v("Deleted $deletedOld fully aged deactivated keys for {$name}"); }
}

info("Rollover summary: initiated=$initiated completed=$completed baselined=$baselined skipped=$skipped");
if ($dryRun) info('Dry run mode - no changes applied.');

exit(0);
