<?php
// License management dedicated page
$user = new User();
$settings = new Settings();
if (!$user->isSuperAdmin($currentUser['id'])) { header('Location: /?page=dashboard'); exit; }
$db = Database::getInstance();
$message=''; $messageType='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $message='Security token mismatch.'; $messageType='danger'; }
    else {
        $action = $_POST['action'] ?? '';
        if ($action==='update_license_key') {
            $newKey = trim($_POST['license_key'] ?? '');
            $old = $db->fetch("SELECT setting_value FROM global_settings WHERE setting_key='license_key'");
            $oldVal = $old['setting_value'] ?? '';
            if ($newKey==='') {
                $db->execute("DELETE FROM global_settings WHERE setting_key='license_key'");
                $message='License key removed. System operating in Free mode.'; $messageType='success';
                if (isset($_SESSION['user_id'])) (new AuditLog())->logSettingUpdated($_SESSION['user_id'],'license_key',$oldVal,'(cleared)');
            } else {
                if ($oldVal==='') $db->execute("INSERT INTO global_settings (setting_key,setting_value,description,category) VALUES ('license_key', ?, 'Installed license key','licensing')",[$newKey]);
                else $db->execute("UPDATE global_settings SET setting_value=? WHERE setting_key='license_key'",[$newKey]);
                if (class_exists('LicenseManager')) { LicenseManager::clearCache(); }
                if (isset($_SESSION['user_id'])) (new AuditLog())->logSettingUpdated($_SESSION['user_id'],'license_key',$oldVal,$newKey);
                $st = LicenseManager::getStatus();
                if (!$st['valid']) {
                    $reason = $st['reason'] ?? 'unknown';
                    if ($reason === 'LX_BIND') {
                        $message = 'License key does not match this Installation Code. Please request a new license using the Installation Code shown below.';
                        $messageType = 'danger';
                    } else {
                        $message = 'Key saved but invalid (code ' . htmlspecialchars($reason) . '). Free mode active.';
                        $messageType = 'warning';
                    }
                } else {
                    $message='License validated: '.($st['license_type']==='commercial' ? ($st['unlimited']?'Commercial Unlimited':'Commercial limit '.$st['max_domains']) : 'Free'); $messageType='success';
                }
            }
        }
    }
}
$pageTitle='License Management';
$branding=$settings->getBranding();
include $_SERVER['DOCUMENT_ROOT'].'/includes/header.php';
?>
<div class="container-fluid py-4">
 <?php
        include_once $_SERVER['DOCUMENT_ROOT'].'/includes/breadcrumbs.php';
        renderBreadcrumb([
                ['label' => 'License']
        ], $user->isSuperAdmin($currentUser['id']));
 ?>
 <div class="mb-3">
     <h1 class="h4 mb-1"><i class="bi bi-shield-lock me-2"></i>License Management</h1>
     <p class="text-muted mb-0">Enter a commercial license key or view your installation code.</p>
 </div>
 <?php if ($message): ?>
 <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show"><span><?php echo htmlspecialchars($message); ?></span><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
 <?php endif; ?>
 <div class="row g-4">
  <div class="col-lg-6">
   <div class="card border-0 shadow-sm h-100">
    <div class="card-header bg-transparent border-0"><h6 class="mb-0">Current Status</h6></div>
    <div class="card-body">
     <?php $status = class_exists('LicenseManager') ? LicenseManager::getStatus() : ['license_type'=>'free','max_domains'=>5,'unlimited'=>false,'valid'=>true];
        $countRow = $db->fetch("SELECT COUNT(*) c FROM domains"); $used=(int)($countRow['c']??0);
        $limit = $status['unlimited']?null:$status['max_domains'];
        $percent = ($limit && $limit>0)?min(100,round(($used/$limit)*100)):0;
     ?>
     <p class="mb-2"><strong>Mode:</strong> <?php echo htmlspecialchars($status['license_type']==='commercial' ? ($status['unlimited']?'Commercial (Unlimited)':'Commercial ('.$limit.')') : 'Free (5 domains)'); ?></p>
     <?php if ($limit): ?>
        <div class="progress mb-2" style="height:8px;"><div class="progress-bar <?php echo $percent>=100?'bg-danger':($percent>=80?'bg-warning':'bg-info'); ?>" style="width:<?php echo $percent; ?>%"></div></div>
        <div class="small text-muted"><?php echo $used; ?> / <?php echo $limit; ?> domains (<?php echo $percent; ?>%)</div>
     <?php else: ?>
        <div class="small text-success">Unlimited domains</div>
     <?php endif; ?>
     <?php if (!empty($status['integrity_error'])): ?><div class="alert alert-warning mt-3 py-2">Integrity: <?php echo htmlspecialchars($status['integrity_error']); ?></div><?php endif; ?>
    </div>
   </div>
  </div>
  <div class="col-lg-6">
   <div class="card border-0 shadow-sm h-100">
    <div class="card-header bg-transparent border-0"><h6 class="mb-0">Enter / Update License Key</h6></div>
    <div class="card-body">
     <form method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
      <input type="hidden" name="action" value="update_license_key">
      <div class="mb-3">
       <label class="form-label">License Key</label>
       <?php $cur = $db->fetch("SELECT setting_value FROM global_settings WHERE setting_key='license_key'"); ?>
       <textarea class="form-control" name="license_key" rows="3" placeholder="Paste license key here..." spellcheck="false"><?php echo htmlspecialchars($cur['setting_value'] ?? ''); ?></textarea>
       <small class="text-muted">Format: PDNS-...-...-HEX_SIGNATURE</small>
      </div>
      <div class="d-grid"><button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save License Key</button></div>
     </form>
     <hr>
     <h6>Installation Code</h6>
     <p class="small text-muted mb-1">Provide this code to obtain a commercial license key.</p>
     <div class="input-group mb-2">
        <?php $installCode = class_exists('LicenseManager') ? LicenseManager::getInstallationCode() : 'UNAVAILABLE'; ?>
        <input type="text" class="form-control" id="installCode" readonly value="<?php echo htmlspecialchars($installCode); ?>">
        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('installCode').value);this.innerHTML='Copied!';setTimeout(()=>this.innerHTML='Copy',1800);">Copy</button>
     </div>
     <small class="text-muted">The code contains no secrets; it is a deterministic fingerprint.</small>
    <div class="d-grid mt-3">
        <a href="https://pdnsconsole.com/purchase.php" target="_blank" rel="noopener" class="btn btn-success">
            <i class="bi bi-cart-check me-1"></i>Purchase Commercial License
        </a>
    </div>
    </div>
   </div>
  </div>
 </div>
    <div class="col-12 mt-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent border-0"><h6 class="mb-0"><i class="bi bi-arrow-up-right-circle me-2"></i>Why Upgrade?</h6></div>
            <div class="card-body">
                <p class="mb-2">Unlock the full power of PDNS Console with a Commercial License:</p>
                <ul class="mb-3 small">
                    <li>Unlimited domains (remove the 5-domain free tier cap)</li>
                    <li>Advanced DNSSEC management (key lifecycle, DS export)</li>
                    <li>Priority support & faster issue turnaround</li>
                    <li>White-label and branding enhancements</li>
                    <li>Multi-tenant scalability & advanced usage reports</li>
                </ul>
                <p class="small text-muted mb-2">Your license is validated locally (offline) – no external calls. Upgrading requires only pasting the key here.</p>
                <a href="https://pdnsconsole.com" target="_blank" rel="noopener" class="small">Learn more at pdnsconsole.com</a>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
