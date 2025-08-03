<?php
/**
 * PDNS Console Encryption Class
 * 
 * Handles AES-256-CBC encryption for sensitive data
 * Used for MFA secrets, backup codes, and other sensitive information
 */

class Encryption {
    private $method;
    private $key;
    
    public function __construct() {
        $this->method = ENCRYPTION_METHOD;
        $this->key = ENCRYPTION_KEY;
        
        // Validate encryption key length
        if (strlen($this->key) !== 32) {
            throw new Exception("Encryption key must be exactly 32 characters");
        }
    }
    
    /**
     * Encrypt data
     */
    public function encrypt($data) {
        if (empty($data)) {
            return '';
        }
        
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($this->method));
        $encrypted = openssl_encrypt($data, $this->method, $this->key, 0, $iv);
        
        if ($encrypted === false) {
            throw new Exception("Encryption failed");
        }
        
        // Return base64 encoded result with IV prepended
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt data
     */
    public function decrypt($data) {
        if (empty($data)) {
            return '';
        }
        
        $data = base64_decode($data);
        if ($data === false) {
            throw new Exception("Invalid encrypted data format");
        }
        
        $iv_length = openssl_cipher_iv_length($this->method);
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);
        
        $decrypted = openssl_decrypt($encrypted, $this->method, $this->key, 0, $iv);
        
        if ($decrypted === false) {
            throw new Exception("Decryption failed");
        }
        
        return $decrypted;
    }
    
    /**
     * Generate secure random string
     */
    public function generateRandomString($length = 32) {
        $bytes = openssl_random_pseudo_bytes($length);
        return bin2hex($bytes);
    }
    
    /**
     * Generate TOTP secret (Base32 encoded)
     */
    public function generateTotpSecret() {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }
    
    /**
     * Generate backup codes
     */
    public function generateBackupCodes($count = 8) {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = sprintf('%04d-%04d', random_int(1000, 9999), random_int(1000, 9999));
        }
        return $codes;
    }
    
    /**
     * Hash password using PHP's password_hash
     */
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Verify password against hash
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Generate secure token
     */
    public function generateToken($length = 64) {
        return bin2hex(openssl_random_pseudo_bytes($length / 2));
    }
    
    /**
     * Generate CSRF token
     */
    public function generateCsrfToken() {
        return $this->generateToken(32);
    }
    
    /**
     * Constant-time string comparison
     */
    public function compareStrings($str1, $str2) {
        return hash_equals($str1, $str2);
    }
}

?>
