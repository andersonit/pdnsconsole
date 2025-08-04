<?php
/**
 * PDNS Console User Management Class
 * 
 * Handles user authentication, management, and role-based access control
 */

class User {
    private $db;
    private $encryption;
    private $settings;
    private $auditLog;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
        $this->settings = new Settings();
        $this->auditLog = new AuditLog();
    }
    
    /**
     * Authenticate user login
     */
    public function authenticate($username, $password) {
        // Get user by username or email
        $user = $this->db->fetch(
            "SELECT id, username, email, password_hash, role, is_active, last_login 
             FROM admin_users 
             WHERE (username = ? OR email = ?) AND is_active = 1",
            [$username, $username]
        );
        
        if (!$user) {
            // Log failed login attempt (username not found)
            $this->auditLog->logUserLoginFailed($username);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }
        
        // Verify password
        if (!$this->encryption->verifyPassword($password, $user['password_hash'])) {
            // Log failed login attempt
            $this->logFailedLogin($user['id']);
            $this->auditLog->logUserLoginFailed($username, ['user_id' => $user['id']]);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }
        
        // Check if account is locked due to failed attempts
        if ($this->isAccountLocked($user['id'])) {
            return ['success' => false, 'error' => 'Account temporarily locked due to failed login attempts'];
        }
        
        // Clear failed login attempts on successful login
        $this->clearFailedLogins($user['id']);
        
        // Log successful login
        $this->auditLog->logUserLogin($user['id']);
        
        return [
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'last_login' => $user['last_login']
            ]
        ];
    }
    
    /**
     * Create new user
     */
    public function create($username, $email, $password, $role = 'tenant_admin') {
        // Validate inputs
        $validation = $this->validateUserData($username, $email, $password);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }
        
        // Check if username or email already exists
        $existing = $this->db->fetch(
            "SELECT id FROM admin_users WHERE username = ? OR email = ?",
            [$username, $email]
        );
        
        if ($existing) {
            return ['success' => false, 'error' => 'Username or email already exists'];
        }
        
        // Hash password
        $passwordHash = $this->encryption->hashPassword($password);
        
        try {
            $userId = $this->db->insert(
                "INSERT INTO admin_users (username, email, password_hash, role, is_active, created_at) 
                 VALUES (?, ?, ?, ?, 1, NOW())",
                [$username, $email, $passwordHash, $role]
            );
            
            return [
                'success' => true,
                'user_id' => $userId,
                'message' => 'User created successfully'
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to create user'];
        }
    }
    
    /**
     * Get user by ID
     */
    public function getById($userId) {
        return $this->db->fetch(
            "SELECT id, username, email, role, is_active, created_at, last_login 
             FROM admin_users 
             WHERE id = ?",
            [$userId]
        );
    }
    
    /**
     * Get user by username
     */
    public function getByUsername($username) {
        return $this->db->fetch(
            "SELECT id, username, email, role, is_active, created_at, last_login 
             FROM admin_users 
             WHERE username = ?",
            [$username]
        );
    }
    
    /**
     * Update user
     */
    public function update($userId, $data) {
        $allowedFields = ['username', 'email', 'role', 'is_active'];
        $updateFields = [];
        $params = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updateFields[] = "{$field} = ?";
                $params[] = $value;
            }
        }
        
        if (empty($updateFields)) {
            return ['success' => false, 'error' => 'No valid fields to update'];
        }
        
        // Check for username/email conflicts
        if (isset($data['username']) || isset($data['email'])) {
            $existing = $this->db->fetch(
                "SELECT id FROM admin_users WHERE (username = ? OR email = ?) AND id != ?",
                [$data['username'] ?? '', $data['email'] ?? '', $userId]
            );
            
            if ($existing) {
                return ['success' => false, 'error' => 'Username or email already exists'];
            }
        }
        
        $params[] = $userId;
        
        try {
            $this->db->execute(
                "UPDATE admin_users SET " . implode(', ', $updateFields) . " WHERE id = ?",
                $params
            );
            
            return ['success' => true, 'message' => 'User updated successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to update user'];
        }
    }
    
    /**
     * Change user password
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        // Get current password hash
        $user = $this->db->fetch(
            "SELECT password_hash FROM admin_users WHERE id = ?",
            [$userId]
        );
        
        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }
        
        // Verify current password
        if (!$this->encryption->verifyPassword($currentPassword, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Current password is incorrect'];
        }
        
        // Validate new password
        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'error' => 'New password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'];
        }
        
        // Hash new password
        $newPasswordHash = $this->encryption->hashPassword($newPassword);
        
        try {
            $this->db->execute(
                "UPDATE admin_users SET password_hash = ? WHERE id = ?",
                [$newPasswordHash, $userId]
            );
            
            return ['success' => true, 'message' => 'Password changed successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to change password'];
        }
    }
    
    /**
     * Reset user password (admin function)
     */
    public function resetPassword($userId, $newPassword) {
        // Validate new password
        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'error' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'];
        }
        
        // Hash new password
        $passwordHash = $this->encryption->hashPassword($newPassword);
        
        try {
            $this->db->execute(
                "UPDATE admin_users SET password_hash = ? WHERE id = ?",
                [$passwordHash, $userId]
            );
            
            return ['success' => true, 'message' => 'Password reset successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to reset password'];
        }
    }
    
    /**
     * Delete user
     */
    public function delete($userId) {
        try {
            // This will cascade delete related records due to foreign key constraints
            $this->db->execute(
                "DELETE FROM admin_users WHERE id = ?",
                [$userId]
            );
            
            return ['success' => true, 'message' => 'User deleted successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Failed to delete user'];
        }
    }
    
    /**
     * Get all users with pagination
     */
    public function getAll($page = 1, $perPage = 25, $search = '', $role = '') {
        $offset = ($page - 1) * $perPage;
        $whereConditions = [];
        $params = [];
        
        if (!empty($search)) {
            $whereConditions[] = "(username LIKE ? OR email LIKE ?)";
            $searchTerm = '%' . $this->db->escapeLike($search) . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if (!empty($role)) {
            $whereConditions[] = "role = ?";
            $params[] = $role;
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM admin_users {$whereClause}";
        $totalResult = $this->db->fetch($countSql, $params);
        $total = $totalResult['total'];
        
        // Get users
        $params[] = $perPage;
        $params[] = $offset;
        
        $users = $this->db->fetchAll(
            "SELECT id, username, email, role, is_active, created_at, last_login 
             FROM admin_users 
             {$whereClause} 
             ORDER BY created_at DESC 
             LIMIT ? OFFSET ?",
            $params
        );
        
        return [
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }
    
    /**
     * Check if user is super admin
     */
    public function isSuperAdmin($userId) {
        $user = $this->db->fetch(
            "SELECT role FROM admin_users WHERE id = ?",
            [$userId]
        );
        
        return $user && $user['role'] === 'super_admin';
    }
    
    /**
     * Get user's tenants
     */
    public function getUserTenants($userId) {
        return $this->db->fetchAll(
            "SELECT t.id, t.name, t.contact_email, t.max_domains, t.is_active, t.created_at
             FROM tenants t
             JOIN user_tenants ut ON t.id = ut.tenant_id
             WHERE ut.user_id = ? AND t.is_active = 1
             ORDER BY t.name",
            [$userId]
        );
    }
    
    /**
     * Validate user data
     */
    private function validateUserData($username, $email, $password) {
        if (empty($username)) {
            return ['valid' => false, 'error' => 'Username is required'];
        }
        
        if (strlen($username) < 3) {
            return ['valid' => false, 'error' => 'Username must be at least 3 characters'];
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'error' => 'Invalid email format'];
        }
        
        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            return ['valid' => false, 'error' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Log failed login attempt
     */
    private function logFailedLogin($userId) {
        // This could be expanded to use a dedicated failed_logins table
        // For now, we'll use a simple approach
        $this->db->execute(
            "INSERT INTO audit_log (user_id, action, ip_address, created_at) VALUES (?, 'failed_login', ?, NOW())",
            [$userId, $_SERVER['REMOTE_ADDR'] ?? '']
        );
    }
    
    /**
     * Check if account is locked
     */
    private function isAccountLocked($userId) {
        $maxAttempts = $this->settings->get('max_login_attempts', 5);
        $lockoutWindow = 900; // 15 minutes
        
        $failedAttempts = $this->db->fetch(
            "SELECT COUNT(*) as count 
             FROM audit_log 
             WHERE user_id = ? AND action = 'failed_login' 
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$userId, $lockoutWindow]
        );
        
        return $failedAttempts['count'] >= $maxAttempts;
    }
    
    /**
     * Clear failed login attempts
     */
    private function clearFailedLogins($userId) {
        $this->db->execute(
            "DELETE FROM audit_log WHERE user_id = ? AND action = 'failed_login'",
            [$userId]
        );
    }
    
    /**
     * Get user by ID (alias for getById for consistency)
     */
    public function getUserById($userId) {
        return $this->getById($userId);
    }
    
    /**
     * Check if username exists (excluding specific user ID)
     */
    public function usernameExists($username, $excludeUserId = null) {
        $sql = "SELECT COUNT(*) as count FROM admin_users WHERE username = ?";
        $params = [$username];
        
        if ($excludeUserId) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
        }
        
        $result = $this->db->fetch($sql, $params);
        return $result['count'] > 0;
    }
    
    /**
     * Check if email exists (excluding specific user ID)
     */
    public function emailExists($email, $excludeUserId = null) {
        $sql = "SELECT COUNT(*) as count FROM admin_users WHERE email = ?";
        $params = [$email];
        
        if ($excludeUserId) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
        }
        
        $result = $this->db->fetch($sql, $params);
        return $result['count'] > 0;
    }
    
    /**
     * Update user profile (username and email)
     */
    public function updateProfile($userId, $username, $email) {
        try {
            $sql = "UPDATE admin_users SET username = ?, email = ? WHERE id = ?";
            $this->db->execute($sql, [$username, $email, $userId]);
            
            // Log the update
            $this->auditLog->logUserUpdated($userId, $userId, 
                ['username' => $username, 'email' => $email],
                ['username' => $username, 'email' => $email]
            );
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Verify user password
     */
    public function verifyPassword($userId, $password) {
        $user = $this->db->fetch(
            "SELECT password_hash FROM admin_users WHERE id = ?",
            [$userId]
        );
        
        if (!$user) {
            return false;
        }
        
        return password_verify($password, $user['password_hash']);
    }
    
    /**
     * Change password (without requiring current password - for profile page)
     */
    public function changePasswordDirect($userId, $newPassword) {
        try {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $sql = "UPDATE admin_users SET password_hash = ? WHERE id = ?";
            $this->db->execute($sql, [$hashedPassword, $userId]);
            
            // Log the password change
            $this->auditLog->logPasswordChanged($userId, $userId);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Update user's last login timestamp
     */
    public function updateLastLogin($userId) {
        try {
            $this->db->execute(
                "UPDATE admin_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?",
                [$userId]
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

?>
