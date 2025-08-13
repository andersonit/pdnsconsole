<?php
/**
 * PDNS Console - License Admin Portal (Protected)
 */

require __DIR__ . '/../../autoload.php';
$config = require __DIR__ . '/../../config.php';

function h($v){return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$ipAllowed = false;
if (!empty($config['ip_allow']) && is_array($config['ip_allow'])) {
    $ipAllowed = in_array($clientIp, $config['ip_allow'], true);
}

function parse_basic_auth(): array {
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']];
    }
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($hdr, 'Basic ') === 0) {
        $decoded = base64_decode(substr($hdr, 6), true);
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            return explode(':', $decoded, 2);
        }
    }
    return ['', ''];
}

function require_auth($realm = 'PDNS Console Admin'){
    header('WWW-Authenticate: Basic realm="'.$realm.'"');
    header('HTTP/1.1 401 Unauthorized');
    echo 'Authentication required';
    exit;
}

$authOk = false;
if ($ipAllowed) {
    $authOk = true;
} else {
    $auth = $config['admin_auth'] ?? null;
    if (is_array($auth) && !empty($auth['username']) && !empty($auth['password_hash'])) {
        [$u,$p] = parse_basic_auth();
        if ($u === '') { require_auth(); }
        if (hash_equals($auth['username'], $u) && password_verify($p, $auth['password_hash'])) {
            $authOk = true;
        } else {
            require_auth();
        }
    } else {
        http_response_code(403);
        echo 'Forbidden (admin portal disabled until admin_auth or ip_allow is configured)';
        exit;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = null; $dbError = null;
try {
    if (!empty($config['db']['dsn'])) {
        $pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
} catch (Throwable $e) { $dbError = $e->getMessage(); }

$messages = [];
$generated = null;

if (!empty($_SESSION['la_flash'])) {
    $flash = $_SESSION['la_flash'];
    unset($_SESSION['la_flash']);
    if (!empty($flash['messages']) && is_array($flash['messages'])) { $messages = array_merge($messages, $flash['messages']); }
    if (array_key_exists('generated', $flash)) { $generated = $flash['generated']; }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $domains = (int)($_POST['domains'] ?? 0);
    $type = $_POST['type'] ?? 'commercial';
    $installationId = trim($_POST['installation_id'] ?? '');
    $hasErrors = false;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $messages[] = ['error','Invalid email']; $hasErrors = true; }
    if ($domains < 0) { $messages[] = ['error','Domains must be >= 0']; $hasErrors = true; }
    if ($installationId === '') { $messages[] = ['error','Installation code is required']; $hasErrors = true; }

    if (!$hasErrors) try {
        $signer = new LicenseSigner($config['private_key_path']);
        $payload = [ 'email' => $email, 'domains' => $domains, 'type' => $type, 'installation_id' => $installationId ];
        $generated = $signer->sign($payload);

        if ($pdo) {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id FROM customers WHERE email = ?');
            $stmt->execute([$email]);
            $cid = $stmt->fetchColumn();
            if (!$cid) {
                $stmt = $pdo->prepare('INSERT INTO customers (email, name, created_at) VALUES (?,?, NOW())');
                $stmt->execute([$email, $email]);
                $cid = $pdo->lastInsertId();
            }
            $stmt = $pdo->prepare('SELECT id FROM licenses WHERE license_key = ? LIMIT 1');
            $stmt->execute([$generated]);
            $lid = $stmt->fetchColumn();
            if (!$lid) {
                $stmt = $pdo->prepare('INSERT INTO licenses (customer_id, installation_id, license_key, domain_limit, type, issued) VALUES (?,?,?,?,?,CURDATE())');
                $stmt->execute([$cid, ($installationId !== '' ? $installationId : null), $generated, $domains, $type]);
                $lid = $pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO license_events (license_id, event_type, detail) VALUES (?,?,?)');
                $stmt->execute([$lid, 'PORTAL_GENERATE', json_encode(['domains'=>$domains,'installation_id'=>$installationId])]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO license_events (license_id, event_type, detail) VALUES (?,?,?)');
                $stmt->execute([$lid, 'PORTAL_GENERATE', json_encode(['domains'=>$domains,'installation_id'=>$installationId,'duplicate'=>true])]);
            }
            $pdo->commit();
        }
        $messages[] = ['ok','License generated'];
    } catch (Throwable $e) {
        $messages[] = ['error','Generation failed: '.$e->getMessage()];
    }
    $_SESSION['la_flash'] = [ 'messages' => $messages, 'generated' => $generated ];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
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
.btn-secondary{background:#6c757d}
.btn-secondary:hover{background:#5a6268}
.alert{padding:.6rem .8rem;border-radius:4px;margin-bottom:.5rem;font-size:14px}
.alert.error{background:#ffe5e5;color:#8b0000}
.alert.ok{background:#e5ffe9;color:#055a1c}
pre{background:#222;color:#eee;padding:1rem;border-radius:6px;overflow:auto;font-size:13px}
pre.key{white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;max-width:100%;overflow-x:hidden}
.small{font-size:12px;color:#555;margin-top:.25rem}
.table{width:100%;border-collapse:collapse;margin-top:1.5rem}
.table th,.table td{border:1px solid #ddd;padding:.4rem .5rem;font-size:13px;text-align:left}
.table th{background:#fafafa}
.key{font-family:monospace}
</style>
</head>
<body>
<h1>License Admin Portal</h1>
<p class="small">Protected by IP allowlist or Basic Auth. Configure in license_admin/config.php.</p>
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
      <option value="commercial" <?=((($_POST['type']??'')==='commercial')?'selected':'')?>>Commercial</option>
      <option value="free" <?=((($_POST['type']??'')==='free')?'selected':'')?>>Free</option>
    </select>
  </label>
  <label>Domains Limit
    <input type="number" name="domains" min="0" value="<?php echo h($_POST['domains'] ?? '0') ?>" />
  </label>
  <label>Installation Code (required)
    <input type="text" name="installation_id" required value="<?php echo h($_POST['installation_id'] ?? '') ?>" placeholder="Paste the Installation Code from the Console (starts with PDNS-)" />
  </label>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
    <button type="submit">Generate</button>
    <button type="button" class="btn-secondary" id="btnResetFields">Reset Fields</button>
  </div>
  <?php if ($dbError): ?><div class="alert error">DB error: <?php echo h($dbError) ?></div><?php endif; ?>
  <?php if ($ipAllowed): ?><div class="small">Access: IP allowlisted (<?php echo h($clientIp) ?>)</div><?php endif; ?>
  <?php if (!$ipAllowed): ?><div class="small">Access: Basic Auth</div><?php endif; ?>
  <div class="small">Tip: Set config['admin_auth']['username'] and ['password_hash'] (password_hash)</div>
</form>
<?php if ($generated): ?>
<section>
  <h2>Generated License Key</h2>
  <div style="margin:.25rem 0 .5rem 0">
    <button type="button" onclick="copyGeneratedKey(this)">Copy to Clipboard</button>
  </div>
  <pre id="genKey" class="key"><?php echo h($generated); ?></pre>
  <p class="small">Copy & paste this into the public console license page.</p>
</section>
<?php endif; ?>
<?php if ($pdo): ?>
<section>
  <h2>Recent Licenses</h2>
  <?php
  $stmt = $pdo->query('SELECT l.id, c.email, l.license_key, l.type, l.domain_limit, l.revoked, l.created_at FROM licenses l JOIN customers c ON c.id = l.customer_id ORDER BY l.id DESC LIMIT 25');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows): ?>
    <table class="table">
  <thead><tr><th>ID</th><th>Email</th><th>Type</th><th>Domains</th><th>Status</th><th>Created</th><th>Key</th></tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td><?php echo h($r['id']) ?></td>
          <td><?php echo h($r['email']) ?></td>
          <td><?php echo h($r['type']) ?></td>
          <td><?php echo h($r['domain_limit']) ?></td>
          <td><?php echo ($r['revoked'] ? 'revoked' : 'active') ?></td>
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
<script>
function copyGeneratedKey(btn){
  var el = document.getElementById('genKey');
  if(!el){return;}
  var text = el.textContent || '';
  if (!text) {return;}
  var done = function(){
    var orig = btn.textContent;
    btn.textContent = 'Copied!';
    btn.disabled = true;
    setTimeout(function(){ btn.textContent = orig; btn.disabled = false; }, 1400);
  };
  if (navigator.clipboard && navigator.clipboard.writeText){
    navigator.clipboard.writeText(text).then(done).catch(function(){fallbackCopy(text, done);});
  } else {
    fallbackCopy(text, done);
  }
}
function fallbackCopy(text, cb){
  try{
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position='fixed'; ta.style.left='-9999px';
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }catch(e){}
  if (typeof cb === 'function') cb();
}
// Reset form fields without submitting
document.addEventListener('DOMContentLoaded', function(){
  var btn = document.getElementById('btnResetFields');
  if (!btn) return;
  btn.addEventListener('click', function(){
    var form = this.closest('form');
    if (!form) return;
    Array.prototype.forEach.call(form.querySelectorAll('input[type="text"], input[type="number"]'), function(el){
      if (el.name === 'domains') el.value = '0'; else el.value = '';
    });
    var sel = form.querySelector('select[name="type"]');
    if (sel) sel.value = 'commercial';
  });
});
</script>
</html>
