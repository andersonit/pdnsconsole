<?php
/**
 * PDNS Console - System Information (Super Admin Only)
 */

// Get required classes
$user = new User();
$settings = new Settings();

// Check if user is super admin
if (!$user->isSuperAdmin($currentUser['id'])) {
    header('Location: /?page=dashboard');
    exit;
}

$pageTitle = 'System Information';
$branding = $settings->getBranding();
$db = Database::getInstance();

// Get system information
$systemInfo = [
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
    'server_name' => $_SERVER['SERVER_NAME'] ?? 'Unknown',
    'load_average' => function_exists('sys_getloadavg') ? sys_getloadavg()[0] : 'N/A',
    'memory_usage' => memory_get_usage(true),
    'memory_peak' => memory_get_peak_usage(true),
    'disk_space' => disk_total_space('.'),
    'disk_free' => disk_free_space('.')
];

// Database information
try {
    $dbVersion = $db->fetch("SELECT VERSION() as version")['version'] ?? 'Unknown';
    $dbInfo = [
        'version' => $dbVersion,
        'connection' => 'Connected',
        'tables' => $db->fetch("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE()")['count'] ?? 0
    ];
} catch (Exception $e) {
    $dbInfo = [
        'version' => 'Unknown',
        'connection' => 'Error: ' . $e->getMessage(),
        'tables' => 0
    ];
}

// Get application statistics
$appStats = [
    'total_domains' => $db->fetch("SELECT COUNT(*) as count FROM domains")['count'] ?? 0,
    'total_records' => $db->fetch("SELECT COUNT(*) as count FROM records")['count'] ?? 0,
    'total_users' => $db->fetch("SELECT COUNT(*) as count FROM admin_users")['count'] ?? 0,
    'total_tenants' => $db->fetch("SELECT COUNT(*) as count FROM tenants")['count'] ?? 0,
    'active_sessions' => $db->fetch("SELECT COUNT(*) as count FROM user_sessions WHERE last_accessed > DATE_SUB(NOW(), INTERVAL 1 HOUR)")['count'] ?? 0
];

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
}

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="bi bi-cpu me-2"></i>
                        System Information
                    </h1>
                    <p class="text-muted mb-0">Server health and system statistics</p>
                </div>
                <div>
                    <a href="?page=admin_dashboard" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>
                        Back to Admin
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Server Information -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-server me-2"></i>
                        Server Information
                    </h6>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">PHP Version:</dt>
                        <dd class="col-sm-8"><code><?php echo htmlspecialchars($systemInfo['php_version']); ?></code></dd>
                        
                        <dt class="col-sm-4">Server Software:</dt>
                        <dd class="col-sm-8"><code><?php echo htmlspecialchars($systemInfo['server_software']); ?></code></dd>
                        
                        <dt class="col-sm-4">Server Name:</dt>
                        <dd class="col-sm-8"><code><?php echo htmlspecialchars($systemInfo['server_name']); ?></code></dd>
                        
                        <dt class="col-sm-4">Document Root:</dt>
                        <dd class="col-sm-8"><small><code><?php echo htmlspecialchars($systemInfo['document_root']); ?></code></small></dd>
                        
                        <dt class="col-sm-4">Load Average:</dt>
                        <dd class="col-sm-8">
                            <?php if ($systemInfo['load_average'] !== 'N/A'): ?>
                                <span class="badge bg-<?php echo $systemInfo['load_average'] > 2 ? 'warning' : 'success'; ?>">
                                    <?php echo number_format($systemInfo['load_average'], 2); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Database Information -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-database me-2"></i>
                        Database Information
                    </h6>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">Version:</dt>
                        <dd class="col-sm-8"><code><?php echo htmlspecialchars($dbInfo['version']); ?></code></dd>
                        
                        <dt class="col-sm-4">Connection:</dt>
                        <dd class="col-sm-8">
                            <span class="badge bg-<?php echo $dbInfo['connection'] === 'Connected' ? 'success' : 'danger'; ?>">
                                <?php echo htmlspecialchars($dbInfo['connection']); ?>
                            </span>
                        </dd>
                        
                        <dt class="col-sm-4">Tables:</dt>
                        <dd class="col-sm-8"><?php echo number_format($dbInfo['tables']); ?> tables</dd>
                        
                        <dt class="col-sm-4">Config Path:</dt>
                        <dd class="col-sm-8"><small><code>/config/</code></small></dd>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Memory Usage -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-memory me-2"></i>
                        Memory Usage
                    </h6>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-5">Current Usage:</dt>
                        <dd class="col-sm-7"><?php echo formatBytes($systemInfo['memory_usage']); ?></dd>
                        
                        <dt class="col-sm-5">Peak Usage:</dt>
                        <dd class="col-sm-7"><?php echo formatBytes($systemInfo['memory_peak']); ?></dd>
                        
                        <dt class="col-sm-5">Memory Limit:</dt>
                        <dd class="col-sm-7"><code><?php echo ini_get('memory_limit'); ?></code></dd>
                    </dl>
                    
                    <?php 
                    $memoryLimitBytes = ini_get('memory_limit');
                    if ($memoryLimitBytes !== '-1') {
                        $memoryLimitBytes = str_replace(['K', 'M', 'G'], ['*1024', '*1024*1024', '*1024*1024*1024'], $memoryLimitBytes);
                        eval("\$memoryLimitBytes = $memoryLimitBytes;");
                        $usagePercentage = ($systemInfo['memory_usage'] / $memoryLimitBytes) * 100;
                    ?>
                    <div class="progress mt-2" style="height: 6px;">
                        <div class="progress-bar <?php echo $usagePercentage > 80 ? 'bg-warning' : 'bg-success'; ?>" 
                             style="width: <?php echo min($usagePercentage, 100); ?>%"></div>
                    </div>
                    <small class="text-muted"><?php echo number_format($usagePercentage, 1); ?>% used</small>
                    <?php } ?>
                </div>
            </div>
        </div>

        <!-- Disk Space -->
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-hdd me-2"></i>
                        Disk Space
                    </h6>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">Total Space:</dt>
                        <dd class="col-sm-8"><?php echo formatBytes($systemInfo['disk_space']); ?></dd>
                        
                        <dt class="col-sm-4">Free Space:</dt>
                        <dd class="col-sm-8"><?php echo formatBytes($systemInfo['disk_free']); ?></dd>
                        
                        <dt class="col-sm-4">Used Space:</dt>
                        <dd class="col-sm-8"><?php echo formatBytes($systemInfo['disk_space'] - $systemInfo['disk_free']); ?></dd>
                    </dl>
                    
                    <?php 
                    $diskUsagePercentage = (($systemInfo['disk_space'] - $systemInfo['disk_free']) / $systemInfo['disk_space']) * 100;
                    ?>
                    <div class="progress mt-2" style="height: 6px;">
                        <div class="progress-bar <?php echo $diskUsagePercentage > 80 ? 'bg-warning' : 'bg-success'; ?>" 
                             style="width: <?php echo $diskUsagePercentage; ?>%"></div>
                    </div>
                    <small class="text-muted"><?php echo number_format($diskUsagePercentage, 1); ?>% used</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Application Statistics -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-graph-up me-2"></i>
                        Application Statistics
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-2">
                            <div class="text-center">
                                <div class="fs-3 fw-bold text-primary"><?php echo number_format($appStats['total_domains']); ?></div>
                                <small class="text-muted">Domains</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <div class="fs-3 fw-bold text-success"><?php echo number_format($appStats['total_records']); ?></div>
                                <small class="text-muted">DNS Records</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <div class="fs-3 fw-bold text-info"><?php echo number_format($appStats['total_users']); ?></div>
                                <small class="text-muted">Users</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <div class="fs-3 fw-bold text-warning"><?php echo number_format($appStats['total_tenants']); ?></div>
                                <small class="text-muted">Tenants</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <div class="fs-3 fw-bold text-secondary"><?php echo number_format($appStats['active_sessions']); ?></div>
                                <small class="text-muted">Active Sessions</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="text-center">
                                <div class="fs-3 fw-bold text-muted"><?php echo date('Y-m-d'); ?></div>
                                <small class="text-muted">Today</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- System Status -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">
                        <i class="bi bi-heart-pulse me-2"></i>
                        System Health Status
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-success bg-opacity-10 rounded-3 p-2 me-3">
                                    <i class="bi bi-check-circle text-success"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">Application</div>
                                    <small class="text-success">Running</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-<?php echo $dbInfo['connection'] === 'Connected' ? 'success' : 'danger'; ?> bg-opacity-10 rounded-3 p-2 me-3">
                                    <i class="bi bi-database text-<?php echo $dbInfo['connection'] === 'Connected' ? 'success' : 'danger'; ?>"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">Database</div>
                                    <small class="text-<?php echo $dbInfo['connection'] === 'Connected' ? 'success' : 'danger'; ?>"><?php echo $dbInfo['connection']; ?></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-info bg-opacity-10 rounded-3 p-2 me-3">
                                    <i class="bi bi-people text-info"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">Sessions</div>
                                    <small class="text-muted"><?php echo $appStats['active_sessions']; ?> active</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-warning bg-opacity-10 rounded-3 p-2 me-3">
                                    <i class="bi bi-clock text-warning"></i>
                                </div>
                                <div>
                                    <div class="fw-semibold">Uptime</div>
                                    <small class="text-muted">Since deployment</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
