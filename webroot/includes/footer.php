<?php
// Include Settings class for footer branding
require_once __DIR__ . '/../classes/Settings.php';
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
                // Only auto-dismiss success/info alerts that are NOT inside a modal
                if (!alert.closest('.modal') && (alert.classList.contains('alert-success') || alert.classList.contains('alert-info'))) {
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
                            $licenseStatus = null; $licenseLabel = 'Free Tier'; $licenseBadge='info'; $limitText='';
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
                                    $licenseBadge='success';
                                } else {
                                    $max = $licenseStatus['max_domains'] ?? 5;
                                    $countRow = Database::getInstance()->fetch("SELECT COUNT(*) c FROM domains");
                                    $used = (int)($countRow['c'] ?? 0);
                                    $limitText = $used . '/' . $max . ' domains';
                                    $percent = $max > 0 ? round(($used / $max) * 100) : 0;
                                    if ($percent >= 80) { $licenseBadge='warning'; }
                                    if ($percent >= 100) { $licenseBadge='danger'; }
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
                        <i class="bi bi-rocket me-2"></i>
                        <div>
                            <small class="text-light">PDNS Console v1.0</small>
                            <div class="fw-bold small">Phase 1 Complete</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="d-flex align-items-center justify-content-center">
                        <i class="bi bi-server me-2 text-warning"></i>
                        <div>
                            <small class="text-light">Nameservers Status</small>
                            <div class="fw-bold small">Checking...</div>
                        </div>
                        <span class="badge bg-warning ms-2">Pending</span>
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
                            <a href="#" class="text-decoration-none text-info" onclick="showUpgradeModal()">
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
