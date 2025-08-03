<?php
/**
 * PDNS Console - Sidebar Navigation
 */

// Get current page for active menu highlighting
$currentPage = $_GET['page'] ?? 'dashboard';
?>

<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
    <div class="position-sticky pt-3">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>" href="?page=dashboard">
                    <i class="fas fa-tachometer-alt me-2"></i>
                    Dashboard
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo in_array($currentPage, ['domains', 'domain_add', 'domain_edit']) ? 'active' : ''; ?>" href="?page=domains">
                    <i class="fas fa-globe me-2"></i>
                    Domains
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo in_array($currentPage, ['records', 'record_add', 'record_bulk', 'record_edit', 'record_import', 'record_export']) ? 'active' : ''; ?>" href="?page=records">
                    <i class="fas fa-list me-2"></i>
                    DNS Records
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="?page=dynamic_dns">
                    <i class="fas fa-sync-alt me-2"></i>
                    Dynamic DNS
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="?page=dnssec">
                    <i class="fas fa-shield-alt me-2"></i>
                    DNSSEC
                </a>
            </li>
        </ul>
        
        <?php if ($user->isSuperAdmin($currentUser['id'])): ?>
            <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
                <span>Administration</span>
            </h6>
            
            <ul class="nav flex-column mb-2">
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage === 'admin_users' ? 'active' : ''; ?>" href="?page=admin_users">
                        <i class="fas fa-users me-2"></i>
                        Users
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage === 'admin_tenants' ? 'active' : ''; ?>" href="?page=admin_tenants">
                        <i class="fas fa-building me-2"></i>
                        Tenants
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage === 'admin_settings' ? 'active' : ''; ?>" href="?page=admin_settings">
                        <i class="fas fa-cog me-2"></i>
                        Settings
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage === 'admin_record_types' ? 'active' : ''; ?>" href="?page=admin_record_types">
                        <i class="fas fa-tags me-2"></i>
                        Record Types
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage === 'admin_audit' ? 'active' : ''; ?>" href="?page=admin_audit">
                        <i class="fas fa-history me-2"></i>
                        Audit Log
                    </a>
                </li>
            </ul>
        <?php endif; ?>
        
        <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
            <span>Account</span>
        </h6>
        
        <ul class="nav flex-column mb-2">
            <li class="nav-item">
                <a class="nav-link <?php echo $currentPage === 'profile' ? 'active' : ''; ?>" href="?page=profile">
                    <i class="fas fa-user-cog me-2"></i>
                    Profile
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="?page=logout">
                    <i class="fas fa-sign-out-alt me-2"></i>
                    Logout
                </a>
            </li>
        </ul>
    </div>
</nav>
