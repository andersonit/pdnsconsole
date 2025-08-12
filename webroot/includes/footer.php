<?php
/**
 * PDNS Console
 * Copyright (c) 2025 Neowyze LLC
 *
 * Licensed under the Business Source License 1.0.
 * You may use this file in compliance with the license terms.
 *
 * License details: https://github.com/andersonit/pdnsconsole/blob/main/LICENSE.md
 */

// Include Settings class for footer branding and optional PDNS API status
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../classes/PdnsApiClient.php';
require_once __DIR__ . '/../classes/Encryption.php';

// Prepare PowerDNS API status (cached in session for 60s to avoid blocking every page)
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$pdnsStatus = [
    'configured' => false,
    'online' => false,
    'version' => null,
    'latency_ms' => null,
    'error' => null
];

// Determine if current user is super admin (if context available)
$isSuperAdmin = false;
try {
    if (isset($user) && isset($currentUser['id']) && method_exists($user, 'isSuperAdmin')) {
        $isSuperAdmin = $user->isSuperAdmin($currentUser['id']);
    }
} catch (Throwable $e) { /* ignore */ }

try {
    $settingsObj = new Settings();
    $host = trim((string)$settingsObj->get('pdns_api_host', ''));
    $port = trim((string)$settingsObj->get('pdns_api_port', ''));
    $keyEnc = trim((string)$settingsObj->get('pdns_api_key_enc', ''));
    $serverId = trim((string)$settingsObj->get('pdns_api_server_id', 'localhost'));

    if ($host !== '' && $keyEnc !== '') {
        $pdnsStatus['configured'] = true;
        $fingerprint = sha1($host . '|' . ($port ?: '') . '|' . ($serverId ?: '') . '|' . $keyEnc);
        $cacheKey = 'pdns_api_status_cache_' . $fingerprint;
        $cached = isset($_SESSION[$cacheKey]) ? $_SESSION[$cacheKey] : null;

        if ($cached && isset($cached['ts']) && (time() - (int)$cached['ts']) < 60) {
            $pdnsStatus = array_merge($pdnsStatus, $cached['data']);
        } else {
            try {
                $client = new PdnsApiClient($host, $port, $keyEnc);
                $client->setServerId($serverId ?: 'localhost');
                // Favor quick failures to avoid blocking the page when unreachable
                $client->setConnectTimeout(1);
                $client->setTimeout(2);
                $t0 = microtime(true);
                $info = $client->getServerInfo();
                $t1 = microtime(true);
                if (is_array($info)) {
                    $pdnsStatus['online'] = true;
                    $pdnsStatus['version'] = $info['version'] ?? ($info['daemon_version'] ?? null);
                    $pdnsStatus['latency_ms'] = (int)round(($t1 - $t0) * 1000);
                    $pdnsStatus['error'] = null;
                } else {
                    $pdnsStatus['online'] = false;
                    $lr = $client->getLastResponseInfo();
                    $pdnsStatus['error'] = $lr['error'] ?? 'Unknown response';
                    $pdnsStatus['version'] = null;
                    $pdnsStatus['latency_ms'] = null;
                }
            } catch (Exception $ex) {
                $pdnsStatus['online'] = false;
                $pdnsStatus['error'] = $ex->getMessage();
                $pdnsStatus['version'] = null;
                $pdnsStatus['latency_ms'] = null;
            }

            $_SESSION[$cacheKey] = [
                'ts' => time(),
                'data' => $pdnsStatus
            ];
        }
    }
} catch (Throwable $e) {
    // Keep defaults on error; don't break footer rendering
}
?>
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Theme Selector JS -->
<script src="assets/js/theme-selector.js"></script>

<!-- Custom JS -->
<script>
    // Auto-hide alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            // Only auto-dismiss success/info alerts that are NOT inside a modal and not marked persistent
            if (!alert.classList.contains('alert-static') && !alert.closest('.modal') && (alert.classList.contains('alert-success') || alert.classList.contains('alert-info'))) {
                setTimeout(function() {
                    if (document.body.contains(alert)) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            }
        });
    });

    // Confirm delete actions
    function confirmDelete(message) {
        return confirm(message || 'Are you sure you want to delete this item?');
    }

    // Form validation helper
    function validateForm(formId) {
        const form = document.getElementById(formId);
        if (form) {
            return form.checkValidity();
        }
        return false;
    }

    // Show upgrade modal
    function showUpgradeModal() {
        alert('🚀 Upgrade to PDNS Console Commercial!\n\n' +
            'Commercial features include:\n' +
            '• Unlimited domains\n' +
            '• Advanced DNSSEC management\n' +
            '• Priority support\n' +
            '• White-label options\n' +
            '• Multi-tenant management\n\n' +
            'Contact us for pricing and features!');
    }
</script>

<!-- System Status Footer -->
<footer class="bg-dark text-white mt-auto footer-full-width">
    <div class="container-fluid py-3">
        <div class="row align-items-center">
            <div class="col-md-3">
                <div class="d-flex align-items-center justify-content-center">
                    <i class="bi bi-rocket me-2"></i>
                    <div>
                        <small class="text-light">Version</small>
                        <div class="fw-bold small">PDNS Console v0.8.11</div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="d-flex align-items-center justify-content-center">
                    <i class="bi bi-calendar-check me-2"></i>
                    <div>
                        <small class="text-light">Last Login</small>
                        <div class="fw-bold small"><?php echo date('M j, Y g:i A'); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="d-flex align-items-center justify-content-center">
                    <i class="bi bi-shield-check me-2 text-info"></i>
                    <div>
                        <small class="text-light">License Status</small>
                        <?php
                        $licenseStatus = null;
                        $licenseLabel = 'Free Tier';
                        $licenseBadge = 'info';
                        $limitText = '';
                        if (class_exists('LicenseManager')) {
                            $licenseStatus = LicenseManager::getStatus();
                            if ($licenseStatus['license_type'] === 'commercial') {
                                if ($licenseStatus['unlimited']) {
                                    $licenseLabel = 'Commercial (Unlimited)';
                                    $limitText = 'Unlimited domains';
                                } else {
                                    $licenseLabel = 'Commercial (' . ($licenseStatus['max_domains'] ?? '?') . ')';
                                    $limitText = 'Limit ' . ($licenseStatus['max_domains'] ?? '?');
                                }
                                $licenseBadge = 'success';
                            } else {
                                $max = $licenseStatus['max_domains'] ?? 5;
                                $countRow = Database::getInstance()->fetch("SELECT COUNT(*) c FROM domains");
                                $used = (int)($countRow['c'] ?? 0);
                                $limitText = $used . '/' . $max . ' domains';
                                $percent = $max > 0 ? round(($used / $max) * 100) : 0;
                                if ($percent >= 80) {
                                    $licenseBadge = 'warning';
                                }
                                if ($percent >= 100) {
                                    $licenseBadge = 'danger';
                                }
                            }
                        }
                        echo '<div class="fw-bold small">' . htmlspecialchars($licenseLabel) . '</div>';
                        if (!empty($limitText)) echo '<div class="text-muted small">' . htmlspecialchars($limitText) . '</div>';
                        ?>
                    </div>
                    <span class="badge bg-<?php echo $licenseBadge; ?> ms-2">Active</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="d-flex align-items-center justify-content-center">
                    <i class="bi bi-hdd-network me-2 <?php echo !$pdnsStatus['configured'] ? 'text-secondary' : ($pdnsStatus['online'] ? 'text-success' : 'text-danger'); ?>"></i>
                    <div>
                        <small class="text-light">PowerDNS API</small>
                        <?php if (!$pdnsStatus['configured']): ?>
                            <div class="fw-bold small">Not configured</div>
                        <?php elseif ($pdnsStatus['online']): ?>
                            <div class="fw-bold small">Online<?php echo $pdnsStatus['latency_ms'] !== null ? ' (' . (int)$pdnsStatus['latency_ms'] . ' ms)' : ''; ?></div>
                            <?php if (!empty($pdnsStatus['version'])): ?>
                                <div class="text-light small">PowerDNS v<?php echo htmlspecialchars($pdnsStatus['version']); ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="fw-bold small">Offline</div>
                            <?php if (!empty($pdnsStatus['error'])): ?>
                                <div class="text-muted small" title="<?php echo htmlspecialchars($pdnsStatus['error']); ?>">Status check failed</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!$pdnsStatus['configured']): ?>
                        <?php if ($isSuperAdmin): ?>
                            <a href="?page=admin_settings" class="ms-2 text-decoration-none" title="Configure PowerDNS API">
                                <span class="badge bg-secondary">Not configured</span>
                            </a>
                        <?php else: ?>
                            <span class="badge bg-secondary ms-2">Not configured</span>
                        <?php endif; ?>
                    <?php elseif ($pdnsStatus['online']): ?>
                        <span class="badge bg-success ms-2">Online</span>
                    <?php else: ?>
                        <span class="badge bg-danger ms-2">Offline</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Copyright and Upgrade Section -->
        <div class="row mt-3 pt-3 border-top border-secondary">
            <div class="col-md-6">
                <small class="text-light">
                    <i class="bi bi-c-circle me-1"></i>
                    <?php
                    // Get footer text from branding settings
                    if (isset($branding) && !empty($branding['footer_text'])) {
                        echo htmlspecialchars($branding['footer_text']);
                    } else {
                        // Fallback if branding not available
                        $footerSettings = new Settings();
                        $footerBranding = $footerSettings->getBranding();
                        echo htmlspecialchars($footerBranding['footer_text']);
                    }
                    ?>
                </small>
            </div>
            <div class="col-md-6 text-end">
                <?php if (!empty($licenseStatus) && $licenseStatus['license_type'] === 'free'): ?>
                    <small>
                        <a href="?page=admin_license" class="text-decoration-none text-info">
                            <i class="bi bi-arrow-up me-1"></i>
                            Upgrade to Commercial
                        </a>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</footer>
</body>

</html>