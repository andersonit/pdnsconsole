<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Email {
    
    private $mailer;
    private $auditLog;
    
    public function __construct() {
        $this->mailer = new PHPMailer(true);
        $this->auditLog = new AuditLog();
        $this->configureMailer();
    }
    
    private function configureMailer() {
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = SMTP_HOST;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = SMTP_USERNAME;
            $this->mailer->Password = SMTP_PASSWORD;
            $this->mailer->Port = SMTP_PORT;
            
            // Security settings
            if (SMTP_SECURE === 'tls') {
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif (SMTP_SECURE === 'ssl') {
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }
            
            // Default From address
            $this->mailer->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            
            // Character set
            $this->mailer->CharSet = 'UTF-8';
            
        } catch (Exception $e) {
            error_log("Email configuration error: " . $e->getMessage());
            throw new Exception("Email system configuration error");
        }
    }
    
    /**
     * Send password reset email
     */
    public function sendPasswordReset($userEmail, $userName, $resetToken) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($userEmail);
            
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Password Reset Request - PDNS Console';
            
            // Create reset URL - construct properly without double slashes
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $resetUrl = $protocol . '://' . $host . '/console/reset_password.php?token=' . urlencode($resetToken);
            
            $this->mailer->Body = $this->getPasswordResetTemplate($userName, $resetUrl);
            $this->mailer->AltBody = $this->getPasswordResetPlainText($userName, $resetUrl);
            
            $result = $this->mailer->send();
            
            // Log the email attempt
            $this->auditLog->logAction(null, 'EMAIL_SENT', 'users', null, null, null, null, [
                'recipient' => $userEmail,
                'type' => 'password_reset'
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Password reset email error: " . $e->getMessage());
            $this->auditLog->logAction(null, 'EMAIL_ERROR', 'users', null, null, null, null, [
                'recipient' => $userEmail,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Send general notification email
     */
    public function sendNotification($userEmail, $subject, $message, $isHTML = true) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($userEmail);
            
            $this->mailer->isHTML($isHTML);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $message;
            
            if ($isHTML) {
                $this->mailer->AltBody = strip_tags($message);
            }
            
            $result = $this->mailer->send();
            
            // Log the email attempt
            $this->auditLog->logAction(null, 'EMAIL_SENT', 'users', null, null, null, null, [
                'recipient' => $userEmail,
                'subject' => $subject
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Notification email error: " . $e->getMessage());
            $this->auditLog->logAction(null, 'EMAIL_ERROR', 'users', null, null, null, null, [
                'recipient' => $userEmail,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Test email configuration
     */
    public function testConfiguration($testEmail = null) {
        try {
            if ($testEmail) {
                $this->mailer->clearAddresses();
                $this->mailer->addAddress($testEmail);
                $this->mailer->Subject = 'PDNS Console - Email Configuration Test';
                $this->mailer->Body = 'This is a test email to verify your SMTP configuration is working correctly.';
                $this->mailer->send();
            }
            return true;
        } catch (Exception $e) {
            error_log("Email test error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * HTML template for password reset email
     */
    private function getPasswordResetTemplate($userName, $resetUrl) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Password Reset Request</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .button { display: inline-block; padding: 12px 24px; background-color: #007bff; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>PDNS Console</h1>
                    <h2>Password Reset Request</h2>
                </div>
                <div class='content'>
                    <p>Hello " . htmlspecialchars($userName) . ",</p>
                    <p>We received a request to reset your password for your PDNS Console account.</p>
                    <p>To reset your password, please click the button below:</p>
                    <p style='text-align: center;'>
                        <a href='" . htmlspecialchars($resetUrl) . "' class='button'>Reset Password</a>
                    </p>
                    <p>If the button doesn't work, you can copy and paste this link into your browser:</p>
                    <p style='word-break: break-all; background-color: #f0f0f0; padding: 10px; border-radius: 4px;'>
                        " . htmlspecialchars($resetUrl) . "
                    </p>
                    <p><strong>Important:</strong> This link will expire in 1 hour for security reasons.</p>
                    <p>If you did not request this password reset, please ignore this email and your password will remain unchanged.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message from PDNS Console. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Plain text version for password reset email
     */
    private function getPasswordResetPlainText($userName, $resetUrl) {
        return "PDNS Console - Password Reset Request

Hello " . $userName . ",

We received a request to reset your password for your PDNS Console account.

To reset your password, please visit the following link:
" . $resetUrl . "

Important: This link will expire in 1 hour for security reasons.

If you did not request this password reset, please ignore this email and your password will remain unchanged.

This is an automated message from PDNS Console. Please do not reply to this email.";
    }
}
?>
