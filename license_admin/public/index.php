<?php
require __DIR__ . '/../autoload.php';
$config = require __DIR__ . '/../config.php';

// Basic super-simple guard (optional): IP allowlist
if (!empty($config['ip_allow']) && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $config['ip_allow'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = null;
if (!empty($config['db']['dsn'])) {
    try {
        $pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Throwable $e) {
        $pdo = null; // continue in stateless mode
        $dbError = $e->getMessage();
    }
}

$messages = [];
$generated = null;

function h($v){return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $domains = (int)($_POST['domains'] ?? 0);
    $type = $_POST['type'] ?? 'commercial';
    $installationId = trim($_POST['installation_id'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $messages[] = ['error','Invalid email'];
    }
    if ($domains < 0) {
        $messages[] = ['error','Domains must be >= 0'];
    }
    try {
        $signer = new LicenseSigner($config['private_key_path']);
        $payload = [
            'email' => $email,
            'domains' => $domains,
            'type' => $type,
        ];
        if ($installationId !== '') {
            $payload['installation_id'] = $installationId;
        }
        $generated = $signer->sign($payload);

        if ($pdo) {
            $pdo->beginTransaction();
            // Find or create customer
            $stmt = $pdo->prepare('SELECT id FROM customers WHERE email = ?');
            $stmt->execute([$email]);
            $cid = $stmt->fetchColumn();
            if (!$cid) {
                $stmt = $pdo->prepare('INSERT INTO customers (email, created_at) VALUES (?, NOW())');
                $stmt->execute([$email]);
                $cid = $pdo->lastInsertId();
            }
            // Insert license row
            $stmt = $pdo->prepare('INSERT INTO licenses (customer_id, license_key, plan_name, domains_limit, status, created_at) VALUES (?,?,?,?,?,NOW())');
            $stmt->execute([$cid, $generated, strtoupper($type), $domains, 'active']);
            $lid = $pdo->lastInsertId();
            // Event log
            $stmt = $pdo->prepare('INSERT INTO license_events (license_id, event_type, details, created_at) VALUES (?,?,?,NOW())');
            $stmt->execute([$lid, 'PORTAL_GENERATE', json_encode(['domains'=>$domains,'installation_id'=>$installationId])]);
            $pdo->commit();
        }
        $messages[] = ['ok','License generated'];
    } catch (Throwable $e) {
        $messages[] = ['error','Generation failed: '.$e->getMessage()];
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>License Admin Portal</title>
<style>
body{font-family:system-ui,Arial,sans-serif;margin:2rem;max-width:900px}
form{background:#f5f5f5;padding:1rem 1.5rem;border-radius:6px;margin-bottom:2rem}
label{display:block;margin-top:.75rem;font-weight:600}
input[type=text],input[type=number],select{width:100%;padding:.5rem;border:1px solid #bbb;border-radius:4px;font-size:14px}
button{margin-top:1rem;padding:.6rem 1.2rem;font-size:14px;background:#1b6ef3;color:#fff;border:0;border-radius:4px;cursor:pointer}
button:hover{background:#1559c2}
.alert{padding:.6rem .8rem;border-radius:4px;margin-bottom:.5rem;font-size:14px}
.alert.error{background:#ffe5e5;color:#8b0000}
.alert.ok{background:#e5ffe9;color:#055a1c}
pre{background:#222;color:#eee;padding:1rem;border-radius:6px;overflow:auto;font-size:13px}
.small{font-size:12px;color:#555;margin-top:.25rem}
.table{width:100%;border-collapse:collapse;margin-top:1.5rem}
.table th,.table td{border:1px solid #ddd;padding:.4rem .5rem;font-size:13px;text-align:left}
.table th{background:#fafafa}
.key{font-family:monospace}
</style>
</head>
<body>
<h1>License Admin Portal</h1>
<p class="small">Minimal internal tool. Protect via network / auth. Generated licenses are persisted if DB configured.</p>
<?php foreach($messages as [$type,$msg]): ?>
<div class="alert <?=$type?>"><?php echo h($msg); ?></div>
<?php endforeach; ?>
<form method="post">
  <h2>Create License</h2>
  <label>Email
    <input type="text" name="email" required value="<?php echo h($_POST['email'] ?? '') ?>" />
  </label>
  <label>Type
    <select name="type">
      <option value="commercial" <?=(($_POST['type']??'')==='commercial')?'selected':''?>>Commercial</option>
      <option value="free" <?=(($_POST['type']??'')==='free')?'selected':''?>>Free</option>
    </select>
  </label>
  <label>Domains Limit
    <input type="number" name="domains" min="0" value="<?php echo h($_POST['domains'] ?? '0') ?>" />
  </label>
  <label>Installation ID (optional lock)
    <input type="text" name="installation_id" value="<?php echo h($_POST['installation_id'] ?? '') ?>" placeholder="Leave blank for portable license" />
  </label>
  <button type="submit">Generate</button>
</form>
<?php if ($generated): ?>
<section>
  <h2>Generated License Key</h2>
  <pre class="key"><?php echo h($generated); ?></pre>
  <p class="small">Copy & paste this into the public console license page.</p>
</section>
<?php endif; ?>
<?php if ($pdo): ?>
<section>
  <h2>Recent Licenses</h2>
  <?php
    $stmt = $pdo->query('SELECT l.id, c.email, l.license_key, l.plan_name, l.domains_limit, l.status, l.created_at FROM licenses l JOIN customers c ON c.id = l.customer_id ORDER BY l.id DESC LIMIT 25');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows): ?>
    <table class="table">
      <thead><tr><th>ID</th><th>Email</th><th>Plan</th><th>Domains</th><th>Status</th><th>Created</th><th>Key</th></tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td><?php echo h($r['id']) ?></td>
          <td><?php echo h($r['email']) ?></td>
          <td><?php echo h($r['plan_name']) ?></td>
          <td><?php echo h($r['domains_limit']) ?></td>
          <td><?php echo h($r['status']) ?></td>
          <td><?php echo h($r['created_at']) ?></td>
          <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis" title="<?php echo h($r['license_key']) ?>">...</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <p class="small">No licenses yet.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
<hr />
<p class="small">Version: 0.1-dev</p>
</body>
</html>
