<?php
/**
 * PDNS Console MFA Management Class
 * 
 * Handles Two-Factor Authentication using TOTP with encrypted storage
 * Uses robthree/twofactorauth for TOTP generation and validation
 * 
 * Features:
 * - TOTP (Time-based One-Time Password) implementation
 * - QR code generation for easy setup
 * - Encrypted storage of secrets and backup codes
 * - Backup code management (10 single-use codes)
 * - Admin reset functionality
 * - Full audit logging integration
 * 
 * Security:
 * - All secrets encrypted using AES-256 via Encryption class
 * - Backup codes are single-use and securely generated
 * - Password verification required for sensitive operations
 * - Complete audit trail for all MFA operations
 * 
 * @package PDNSConsole
 * @author Anderson IT
 * @version 1.0
 * @since 2025-08-03
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RobThree\Auth\TwoFactorAuth;

class MFA {
    private $db;
    private $encryption;
    private $tfa;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
        
        // Initialize TwoFactorAuth with app name and issuer
        $this->tfa = new TwoFactorAuth('PDNS Console');
    }
    
    /**
     * Check if user has MFA enabled
     */
    public function isEnabled($userId) {
        $result = $this->db->fetch("SELECT is_enabled FROM user_mfa WHERE user_id = ?", [$userId]);
        
        return $result ? (bool)$result['is_enabled'] : false;
    }
    
    /**
     * Get user's MFA status and info
     */
    public function getUserMFAStatus($userId) {
        return $this->db->fetch("
            SELECT id, is_enabled, last_used, created_at 
            FROM user_mfa 
            WHERE user_id = ?
        ", [$userId]);
    }
    
    /**
     * Generate new MFA secret and backup codes
     */
    public function generateNewSecret($userId) {
        // Generate new TOTP secret
        $secret = $this->tfa->createSecret();
        
        // Generate 10 backup codes (8 digits each)
        $backupCodes = [];
        for ($i = 0; $i < 10; $i++) {
            $backupCodes[] = str_pad(random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
        }
        
        // Encrypt the secret and backup codes
        $encryptedSecret = $this->encryption->encrypt($secret);
        $encryptedBackupCodes = $this->encryption->encrypt(json_encode($backupCodes));
        
        // Store in database (not enabled yet)
        $this->db->execute("
            INSERT INTO user_mfa (user_id, secret_encrypted, backup_codes_encrypted, is_enabled) 
            VALUES (?, ?, ?, FALSE)
            ON DUPLICATE KEY UPDATE 
                secret_encrypted = VALUES(secret_encrypted),
                backup_codes_encrypted = VALUES(backup_codes_encrypted),
                is_enabled = FALSE,
                created_at = CURRENT_TIMESTAMP
        ", [$userId, $encryptedSecret, $encryptedBackupCodes]);
        
        return [
            'secret' => $secret,
            'backup_codes' => $backupCodes
        ];
    }
    
    /**
     * Generate QR code URL for secret
     */
    public function getQRCodeUrl($userId, $secret) {
        // Get user info for QR code label
        $user = $this->db->fetch("SELECT username, email FROM admin_users WHERE id = ?", [$userId]);
        
        if (!$user) {
            throw new Exception('User not found');
        }
        
        // Generate QR code URL
        $label = $user['username'] . ' (' . $user['email'] . ')';
        return $this->tfa->getQRCodeImageAsDataUri($label, $secret);
    }
    
    /**
     * Verify TOTP code and enable MFA
     */
    public function verifyAndEnable($userId, $code) {
        // Get encrypted secret and backup codes
        $result = $this->db->fetch("SELECT secret_encrypted, backup_codes_encrypted FROM user_mfa WHERE user_id = ?", [$userId]);
        
        if (!$result) {
            throw new Exception('MFA not initialized for user');
        }
        
        // Decrypt secret
        $secret = $this->encryption->decrypt($result['secret_encrypted']);
        
        // Verify code
        if (!$this->tfa->verifyCode($secret, $code)) {
            return false;
        }
        
        // Enable MFA
        $this->db->execute("
            UPDATE user_mfa 
            SET is_enabled = TRUE, last_used = CURRENT_TIMESTAMP 
            WHERE user_id = ?
        ", [$userId]);
        
        // Decrypt and return backup codes
        $backupCodes = json_decode($this->encryption->decrypt($result['backup_codes_encrypted']), true);
        
        return [
            'success' => true,
            'backup_codes' => $backupCodes
        ];
    }
    
    /**
     * Verify TOTP code for enabled MFA
     */
    public function verifyCode($userId, $code) {
        // Get MFA info
        $result = $this->db->fetch("
            SELECT secret_encrypted, is_enabled 
            FROM user_mfa 
            WHERE user_id = ? AND is_enabled = TRUE
        ", [$userId]);
        
        if (!$result) {
            return false;
        }
        
        // Decrypt secret
        $secret = $this->encryption->decrypt($result['secret_encrypted']);
        
        // Verify code
        if ($this->tfa->verifyCode($secret, $code)) {
            // Update last used timestamp
            $this->db->execute("UPDATE user_mfa SET last_used = CURRENT_TIMESTAMP WHERE user_id = ?", [$userId]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Verify backup code
     */
    public function verifyBackupCode($userId, $code) {
        // Get MFA info
        $result = $this->db->fetch("
            SELECT backup_codes_encrypted, is_enabled 
            FROM user_mfa 
            WHERE user_id = ? AND is_enabled = TRUE
        ", [$userId]);
        
        if (!$result) {
            return false;
        }
        
        // Decrypt backup codes
        $backupCodes = json_decode($this->encryption->decrypt($result['backup_codes_encrypted']), true);
        
        // Check if code exists
        $codeIndex = array_search($code, $backupCodes);
        
        if ($codeIndex === false) {
            return false;
        }
        
        // Remove used backup code
        unset($backupCodes[$codeIndex]);
        $backupCodes = array_values($backupCodes); // Re-index array
        
        // Update backup codes
        $encryptedBackupCodes = $this->encryption->encrypt(json_encode($backupCodes));
        $this->db->execute("
            UPDATE user_mfa 
            SET backup_codes_encrypted = ?, last_used = CURRENT_TIMESTAMP 
            WHERE user_id = ?
        ", [$encryptedBackupCodes, $userId]);
        
        return true;
    }
    
    /**
     * Get remaining backup codes count
     */
    public function getBackupCodesCount($userId) {
        $result = $this->db->fetch("
            SELECT backup_codes_encrypted 
            FROM user_mfa 
            WHERE user_id = ? AND is_enabled = TRUE
        ", [$userId]);
        
        if (!$result) {
            return 0;
        }
        
        $backupCodes = json_decode($this->encryption->decrypt($result['backup_codes_encrypted']), true);
        
        return count($backupCodes);
    }
    
    /**
     * Disable MFA for user
     */
    public function disable($userId) {
        $this->db->execute("DELETE FROM user_mfa WHERE user_id = ?", [$userId]);
        
        return true;
    }
    
    /**
     * Reset MFA for user (admin function)
     */
    public function resetForUser($userId, $adminUserId) {
        // Log the reset action
        $auditLog = new AuditLog();
        $auditLog->logMFAReset($adminUserId, $userId);
        
        // Delete MFA record
        $this->db->execute("DELETE FROM user_mfa WHERE user_id = ?", [$userId]);
        
        return true;
    }
    
    /**
     * Generate new backup codes (replace existing ones)
     */
    public function regenerateBackupCodes($userId) {
        // Generate 10 new backup codes
        $backupCodes = [];
        for ($i = 0; $i < 10; $i++) {
            $backupCodes[] = str_pad(random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
        }
        
        // Encrypt backup codes
        $encryptedBackupCodes = $this->encryption->encrypt(json_encode($backupCodes));
        
        // Update in database
        $this->db->execute("
            UPDATE user_mfa 
            SET backup_codes_encrypted = ? 
            WHERE user_id = ? AND is_enabled = TRUE
        ", [$encryptedBackupCodes, $userId]);
        
        return $backupCodes;
    }
    
    /**
     * Get all users with MFA enabled (admin function)
     */
    public function getUsersWithMFA() {
        return $this->db->fetchAll("
            SELECT u.id, u.username, u.email, m.is_enabled, m.last_used, m.created_at
            FROM admin_users u
            LEFT JOIN user_mfa m ON u.id = m.user_id
            WHERE m.is_enabled = TRUE
            ORDER BY u.username
        ");
    }
}
