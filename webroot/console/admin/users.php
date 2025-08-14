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

/**
 * PDNS Console - User Management (Super Admin Only)
 */

// Get required classes
$user = new User();
$settings = new Settings();

// Check if user is super admin
if (!$user->isSuperAdmin($currentUser['id'])) {
    header('Location: /?page=dashboard');
    exit;
}

$pageTitle = 'User Management';
$branding = $settings->getBranding();
$db = Database::getInstance();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = 'Security token mismatch. Please refresh and try again.';
        $messageType = 'danger';
    } else {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'create_user':
                $username = trim($_POST['username'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? 'tenant_admin';
                $tenantIds = $_POST['tenant_ids'] ?? [];
                
                if (empty($username) || empty($email) || empty($password)) {
                    throw new Exception('Username, email, and password are required.');
                }
                
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email address.');
                }
                
                // Create user
                $createResult = $user->create($username, $email, $password, $role);
                if (!is_array($createResult) || empty($createResult['success'])) {
                    throw new Exception($createResult['error'] ?? 'Failed to create user');
                }
                $userId = (int)($createResult['user_id'] ?? 0);
                if ($userId <= 0) {
                    throw new Exception('Failed to retrieve new user ID');
                }

                // Assign to tenants if specified
                if (!empty($tenantIds) && $role === 'tenant_admin') {
                    foreach ($tenantIds as $tenantId) {
                        $tenantId = (int)$tenantId;
                        if ($tenantId > 0) {
                            $db->execute(
                                "INSERT INTO user_tenants (user_id, tenant_id) VALUES (?, ?)",
                                [$userId, $tenantId]
                            );
                        }
                    }
                }
                
                $message = 'User created successfully.';
                $messageType = 'success';
                break;
                
            case 'update_user':
                $userId = intval($_POST['user_id'] ?? 0);
                $email = trim($_POST['email'] ?? '');
                $role = $_POST['role'] ?? 'tenant_admin';
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                $tenantIds = $_POST['tenant_ids'] ?? [];
                
                if (empty($email)) {
                    throw new Exception('Email is required.');
                }
                
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email address.');
                }
                
                // Update user
                $db->execute(
                    "UPDATE admin_users SET email = ?, role = ?, is_active = ? WHERE id = ?",
                    [$email, $role, $isActive, $userId]
                );
                
                // Update tenant assignments
                $db->execute("DELETE FROM user_tenants WHERE user_id = ?", [$userId]);
                if (!empty($tenantIds) && $role === 'tenant_admin') {
                    foreach ($tenantIds as $tenantId) {
                        $db->execute(
                            "INSERT INTO user_tenants (user_id, tenant_id) VALUES (?, ?)",
                            [$userId, $tenantId]
                        );
                    }
                }
                
                $message = 'User updated successfully.';
                $messageType = 'success';
                break;
                
            case 'reset_password':
                $userId = intval($_POST['user_id'] ?? 0);
                $newPassword = $_POST['new_password'] ?? '';
                
                if (empty($newPassword)) {
                    throw new Exception('New password is required.');
                }
                
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $db->execute(
                    "UPDATE admin_users SET password_hash = ? WHERE id = ?",
                    [$passwordHash, $userId]
                );
                
                $message = 'Password reset successfully.';
                $messageType = 'success';
                break;
                
            case 'reset_mfa':
                $userId = intval($_POST['user_id'] ?? 0);
                
                // Reset MFA for user
                $db->execute("DELETE FROM user_mfa WHERE user_id = ?", [$userId]);
                
                $message = 'Two-factor authentication reset successfully.';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
    }
}

// Get all users with tenant information
$users = $db->fetchAll(
    "SELECT u.*, 
            GROUP_CONCAT(t.name SEPARATOR ', ') as tenant_names,
            GROUP_CONCAT(t.id) as tenant_ids
     FROM admin_users u
     LEFT JOIN user_tenants ut ON u.id = ut.user_id
     LEFT JOIN tenants t ON ut.tenant_id = t.id
     GROUP BY u.id
     ORDER BY u.created_at DESC"
);

// Get all tenants for the form
$tenants = $db->fetchAll(
    "SELECT id, name FROM tenants WHERE is_active = 1 ORDER BY name"
);

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid mt-4">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
        renderBreadcrumb([
            ['label' => 'User Management']
        ], true);
    ?>
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">
                        <i class="bi bi-people-fill me-2"></i>
                        User Management
                    </h1>
                    <p class="text-muted mb-0">Manage system administrators and tenant users</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#createUserModal">
                        <i class="bi bi-person-plus me-1"></i>
                        Create User
                    </button>
                    <a href="?page=admin_dashboard" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>
                        Back to Admin
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if (!empty($message)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Users Table -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0">
                    <h6 class="card-title mb-0">System Users</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Tenants</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $userRecord): ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($userRecord['username']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($userRecord['email']); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $userRecord['role'] === 'super_admin' ? 'danger' : 'primary'; ?>">
                                                <?php echo $userRecord['role'] === 'super_admin' ? 'Super Admin' : 'Tenant Admin'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($userRecord['tenant_names'])): ?>
                                                <small><?php echo htmlspecialchars($userRecord['tenant_names']); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">No tenants assigned</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $userRecord['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $userRecord['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($userRecord['last_login']): ?>
                                                <small><?php echo date('M j, Y g:i A', strtotime($userRecord['last_login'])); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">Never</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary" 
                                                        onclick="editUser(<?php echo htmlspecialchars(json_encode($userRecord)); ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-warning"
                                                        onclick="resetPassword(<?php echo $userRecord['id']; ?>, '<?php echo htmlspecialchars($userRecord['username']); ?>')">
                                                    <i class="bi bi-key"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info"
                                                        onclick="resetMFA(<?php echo $userRecord['id']; ?>, '<?php echo htmlspecialchars($userRecord['username']); ?>')">
                                                    <i class="bi bi-shield-x"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            <i class="bi bi-people fs-1 d-block mb-2 opacity-50"></i>
                                            No users found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_user">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Create New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username *</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email *</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password *</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label for="role" class="form-label">Role</label>
                        <select class="form-select" id="role" name="role" onchange="toggleTenantSelection()">
                            <option value="tenant_admin">Tenant Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                    <div class="mb-3" id="tenantSelection">
                        <label for="tenant_ids" class="form-label">Assign to Tenants</label>
                        <select class="form-select" id="tenant_ids" name="tenant_ids[]" multiple size="5">
                            <?php foreach ($tenants as $tenant): ?>
                                <option value="<?php echo $tenant['id']; ?>">
                                    <?php echo htmlspecialchars($tenant['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Hold Ctrl/Cmd to select multiple tenants</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="edit_username" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="edit_email" class="form-label">Email *</label>
                        <input type="email" class="form-control" id="edit_email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_role" class="form-label">Role</label>
                        <select class="form-select" id="edit_role" name="role" onchange="toggleEditTenantSelection()">
                            <option value="tenant_admin">Tenant Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                            <label class="form-check-label" for="edit_is_active">
                                Active User
                            </label>
                        </div>
                    </div>
                    <div class="mb-3" id="editTenantSelection">
                        <label for="edit_tenant_ids" class="form-label">Assign to Tenants</label>
                        <select class="form-select" id="edit_tenant_ids" name="tenant_ids[]" multiple size="5">
                            <?php foreach ($tenants as $tenant): ?>
                                <option value="<?php echo $tenant['id']; ?>">
                                    <?php echo htmlspecialchars($tenant['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Hold Ctrl/Cmd to select multiple tenants</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="reset_user_id">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Reset password for user: <strong id="reset_username"></strong></p>
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password *</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset MFA Modal -->
<div class="modal fade" id="resetMFAModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="reset_mfa">
                <input type="hidden" name="user_id" id="mfa_user_id">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Two-Factor Authentication</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Reset two-factor authentication for user: <strong id="mfa_username"></strong></p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This will disable 2FA for the user and they will need to set it up again.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Reset 2FA</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleTenantSelection() {
    const role = document.getElementById('role').value;
    const tenantSelection = document.getElementById('tenantSelection');
    tenantSelection.style.display = role === 'super_admin' ? 'none' : 'block';
}

function toggleEditTenantSelection() {
    const role = document.getElementById('edit_role').value;
    const tenantSelection = document.getElementById('editTenantSelection');
    tenantSelection.style.display = role === 'super_admin' ? 'none' : 'block';
}

function editUser(userData) {
    document.getElementById('edit_user_id').value = userData.id;
    document.getElementById('edit_username').value = userData.username;
    document.getElementById('edit_email').value = userData.email;
    document.getElementById('edit_role').value = userData.role;
    document.getElementById('edit_is_active').checked = userData.is_active == 1;
    
    // Set tenant selections
    const tenantIds = userData.tenant_ids ? userData.tenant_ids.split(',') : [];
    const selectOptions = document.getElementById('edit_tenant_ids').options;
    for (let i = 0; i < selectOptions.length; i++) {
        selectOptions[i].selected = tenantIds.includes(selectOptions[i].value);
    }
    
    toggleEditTenantSelection();
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

function resetPassword(userId, username) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_username').textContent = username;
    document.getElementById('new_password').value = '';
    new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
}

function resetMFA(userId, username) {
    document.getElementById('mfa_user_id').value = userId;
    document.getElementById('mfa_username').textContent = username;
    new bootstrap.Modal(document.getElementById('resetMFAModal')).show();
}

// Initialize tenant selection visibility
document.addEventListener('DOMContentLoaded', function() {
    toggleTenantSelection();
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
