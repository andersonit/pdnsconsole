<?php
/**
 * PDNS Console - Export DNS Records (CSV)
 * Exports all non-system records (excludes SOA and NS at zone apex) for a domain.
 * Includes headers: name,type,content,ttl,prio
 */

$user = new User();
$recordsObj = new Records();
$domainObj = new Domain();

$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);
$domainId = intval($_GET['domain_id'] ?? 0);
if (!$domainId) {
    header('Location: ?page=zones');
    exit;
}

// Tenant context
$tenantId = null;
if (!$isSuperAdmin) {
    $tenantData = $user->getUserTenants($currentUser['id']);
    $tenantIds = array_column($tenantData, 'id');
    if (empty($tenantIds)) {
        $_SESSION['error'] = 'No tenants assigned to your account.';
        header('Location: ?page=records&domain_id=' . $domainId);
        exit;
    }
    $tenantId = $tenantIds[0];
}

try {
    $domainInfo = $domainObj->getDomainById($domainId, $tenantId);
    if (!$domainInfo) {
        throw new Exception('Domain not found or access denied');
    }
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: ?page=records&domain_id=' . $domainId);
    exit;
}

$db = Database::getInstance();
// Fetch records excluding SOA and apex NS (system-managed)
$records = $db->fetchAll(
    "SELECT name,type,content,ttl,prio FROM records WHERE domain_id = ? AND NOT (type IN ('SOA','NS') AND name = ?) ORDER BY name,type,prio",
    [$domainId, $domainInfo['name']]
);

$filename = preg_replace('/[^a-zA-Z0-9.-]+/', '_', $domainInfo['name']) . '_records_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fputcsv($out, ['name','type','content','ttl','prio']);
foreach ($records as $r) {
    fputcsv($out, [$r['name'], $r['type'], $r['content'], $r['ttl'], $r['prio']]);
}
fclose($out);
exit;
