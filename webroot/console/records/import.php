<?php
/**
 * PDNS Console - CSV Import DNS Records
 *
 * Allows importing zones and records from standardized CSV.
 * Columns: zone,name,type,content,ttl,prio
 * - zone: Fully qualified zone (e.g. example.com or 1.168.192.in-addr.arpa)
 * - name: Relative or absolute record name (@ for root). If empty uses '@'.
 * - type: Record type (A, AAAA, CNAME, MX, TXT, NS, PTR, SRV, SOA)
 * - content: Record data (for MX include priority+target in one field per existing pattern)
 * - ttl: Optional TTL (defaults 3600)
 * - prio: Optional priority for MX/SRV; ignored otherwise
 *
 * Behavior:
 * - If zone does not exist and user has tenant permission, create it (forward zones only by default).
 * - Existing zone: records merged; conflicts obey standard validation (CNAME exclusivity, etc.).
 * - SOA / NS records skipped if present in CSV for existing zones (to avoid unintended override).
 */

$user = new User();
$records = new Records();
$domainObj = new Domain();

$isSuperAdmin = $user->isSuperAdmin($currentUser['id']);
$tenantId = null;
if (!$isSuperAdmin) {
    $tenantData = $user->getUserTenants($currentUser['id']);
    $tenantIds = array_column($tenantData, 'id');
    if (empty($tenantIds)) {
        $error = 'No tenants assigned to your account.';
    } else {
        $tenantId = $tenantIds[0];
    }
}

$templateCsv = "zone,name,type,content,ttl,prio\nexample.com,@,A,192.0.2.10,3600,0\nexample.com,www,A,192.0.2.11,3600,0\nexample.com,@,MX,10 mail.example.com.,3600,10\n1.168.192.in-addr.arpa,1,PTR,host1.example.com.,3600,0\n";

// Direct template download via GET to avoid file input validation
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="dns_import_template.csv"');
    echo $templateCsv;
    exit;
}

$importResults = [ 'created_zones' => [], 'created_records' => [], 'skipped' => [], 'errors' => [] ];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_action']) && empty($error)) {

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'CSV upload failed.';
    } else {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $fh = fopen($tmp, 'r');
        if (!$fh) {
            $error = 'Unable to read uploaded file.';
        } else {
            $header = fgetcsv($fh);
            $expected = ['zone','name','type','content','ttl','prio'];
            if (!$header || array_map('strtolower',$header) !== $expected) {
                $error = 'Invalid header row. Expected: '.implode(',', $expected);
            } else {
                $lineNo = 1;
                while (($row = fgetcsv($fh)) !== false) {
                    $lineNo++;
                    if (count(array_filter($row, fn($v)=>$v!==''))===0) continue; // skip empty
                    [$zone,$name,$type,$content,$ttl,$prio] = $row;
                    $zone = trim($zone);
                    $name = trim($name);
                    $type = strtoupper(trim($type));
                    $content = trim($content);
                    $ttl = $ttl !== '' ? (int)$ttl : 3600;
                    $prio = $prio !== '' ? (int)$prio : 0;

                    // Basic field validation
                    if (empty($zone) || empty($type) || empty($content)) {
                        $importResults['errors'][] = [
                            'line' => $lineNo,
                            'zone' => $zone,
                            'name' => $name,
                            'type' => $type,
                            'content' => $content,
                            'error' => 'Missing required fields (zone/type/content)'
                        ];
                        continue;
                    }

                    try {
                        // Lookup zone
                        $zoneDomain = $domainObj->getDomainByName($zone, $tenantId);
                        $createdZone = false;
                        if (!$zoneDomain) {
                            if ($tenantId === null && !$isSuperAdmin) {
                                throw new Exception('Zone not found and cannot create without tenant context');
                            }
                            // Only create forward zone here (reverse requires subnet logic not in CSV sample)
                            $zoneDomainId = $domainObj->createDomain($zone, $tenantId ?: $tenantIds[0], 'NATIVE', str_contains($zone, 'in-addr.arpa') ? 'reverse' : 'forward');
                            $zoneDomain = $domainObj->getDomainById($zoneDomainId, $tenantId);
                            $importResults['created_zones'][] = $zone;
                            $createdZone = true;
                        }

                        $domainId = $zoneDomain['id'];

                        // Skip SOA/NS for existing zones to avoid override (unless newly created)
                        if (!$createdZone && in_array($type, ['SOA','NS'])) {
                            $importResults['skipped'][] = "Line $lineNo: Skipped $type (system-managed) for existing zone $zone";
                            continue;
                        }

                        if ($name === '' || $name === '@') $name = '@';
                        $recordId = $records->createRecord($domainId, $name, $type, $content, $ttl, $prio, $tenantId);
                        $importResults['created_records'][] = [ 'line' => $lineNo, 'zone' => $zone, 'name' => $name, 'type' => $type, 'content' => $content, 'ttl' => $ttl, 'prio' => $prio ];
                    } catch (Exception $e) {
                        $importResults['errors'][] = [
                            'line' => $lineNo,
                            'zone' => $zone,
                            'name' => $name,
                            'type' => $type,
                            'content' => $content,
                            'error' => $e->getMessage()
                        ];
                    }
                }
            }
            if (is_resource($fh)) fclose($fh);
        }
    }
}

$pageTitle = 'Import DNS Records (CSV)';
include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid py-4">
    <?php 
    // Breadcrumbs
    include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
    renderBreadcrumb([
        ['label' => 'Zones', 'url' => '?page=zone_manage'],
        ['label' => 'Import CSV']
    ], $isSuperAdmin);
    ?>
    <div class="row">
        <div class="col-lg-10 mx-auto">
            <h2 class="h4 mb-3"><i class="bi bi-upload me-2 text-primary"></i>Import DNS Records (CSV)</h2>
            <div class="info-panel mb-3">
                <div class="info-panel-title"><i class="bi bi-info-circle"></i>How it works</div>
                <div class="info-panel-body">
                    <ol>
                        <li>CSV columns (header required): <code>zone,name,type,content,ttl,prio</code></li>
                        <li>Missing zone? It will be created (SOA & NS from system settings)</li>
                        <li>Existing zones: <code>SOA</code> / <code>NS</code> lines are skipped (system managed)</li>
                        <li>Validation identical to Add Record (name normalization, patterns, CNAME exclusivity)</li>
                        <li>TTL defaults to 3600 if blank; <code>prio</code> only meaningful for MX/SRV</li>
                        <li>Reverse: full reverse zone (e.g. <code>1.168.192.in-addr.arpa</code>), PTR name = final octet</li>
                    </ol>
                    <p class="note">Template provides starter rows; split huge imports for easier troubleshooting.</p>
                </div>
            </div>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data" class="mb-4">
                <input type="hidden" name="import_action" value="1">
                <div class="mb-3">
                    <label for="csv_file" class="form-label">CSV File</label>
                    <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                    <div class="form-text">Required columns in order: zone,name,type,content,ttl,prio</div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-upload me-1"></i>Import</button>
                    <a href="?page=records_import&download_template=1" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-arrow-down me-1"></i>Template</a>
                    <a href="?page=zone_manage" class="btn btn-light">Cancel</a>
                </div>
            </form>

            <?php if (!empty($importResults['created_zones']) || !empty($importResults['created_records']) || !empty($importResults['skipped']) || !empty($importResults['errors'])): ?>
                <div class="card mb-4">
                    <div class="card-header"><strong>Import Summary</strong></div>
                    <div class="card-body small">
                        <?php if (!empty($importResults['created_zones'])): ?>
                            <p class="mb-1 text-success"><strong>Zones Created:</strong> <?php echo htmlspecialchars(implode(', ', $importResults['created_zones'])); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($importResults['created_records'])): ?>
                            <p class="mb-2 text-success"><strong>Records Created:</strong> <?php echo count($importResults['created_records']); ?></p>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Line</th><th>Zone</th><th>Name</th><th>Type</th><th>Content</th><th>TTL</th><th>Prio</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($importResults['created_records'] as $rec): ?>
                                        <tr>
                                            <td><?php echo (int)$rec['line']; ?></td>
                                            <td><?php echo htmlspecialchars($rec['zone']); ?></td>
                                            <td><?php echo htmlspecialchars($rec['name']); ?></td>
                                            <td><?php echo htmlspecialchars($rec['type']); ?></td>
                                            <td class="text-truncate" style="max-width:240px"><?php echo htmlspecialchars($rec['content']); ?></td>
                                            <td><?php echo (int)$rec['ttl']; ?></td>
                                            <td><?php echo (int)$rec['prio']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($importResults['skipped'])): ?>
                            <p class="mb-1 text-warning"><strong>Skipped:</strong></p>
                            <ul>
                                <?php foreach ($importResults['skipped'] as $msg): ?>
                                    <li><?php echo htmlspecialchars($msg); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if (!empty($importResults['errors'])): ?>
                            <p class="mb-2 text-danger"><strong>Errors (<?php echo count($importResults['errors']); ?>)</strong></p>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Line</th><th>Zone</th><th>Name</th><th>Type</th><th>Content</th><th>Error</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($importResults['errors'] as $err): ?>
                                        <tr class="table-danger">
                                            <td><?php echo (int)$err['line']; ?></td>
                                            <td><?php echo htmlspecialchars($err['zone']); ?></td>
                                            <td><?php echo htmlspecialchars($err['name']); ?></td>
                                            <td><?php echo htmlspecialchars($err['type']); ?></td>
                                            <td class="text-truncate" style="max-width:240px"><?php echo htmlspecialchars($err['content']); ?></td>
                                            <td><?php echo htmlspecialchars($err['error']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><strong>Template Preview</strong></div>
                <div class="card-body">
                    <pre class="small mb-0"><?php echo htmlspecialchars($templateCsv); ?></pre>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
