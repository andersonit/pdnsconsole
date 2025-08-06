<?php
/**
 * PDNS Console - Forgot Password Handler
 */

// Ensure this is only accessed via AJAX POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Set JSON response header
header('Content-Type: application/json');

try {
    // Get email from POST data
    $email = trim($_POST['reset_email'] ?? '');
    
    if (empty($email)) {
        echo json_encode(['success' => false, 'error' => 'Email address is required']);
        exit;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email address format']);
        exit;
    }
    
    // Initialize required classes
    $user = new User();
    $email_handler = new Email();
    
    // Generate password reset token
    $result = $user->generatePasswordResetToken($email);
    
    if (!$result['success']) {
        // Always return success to prevent email enumeration
        echo json_encode([
            'success' => true, 
            'message' => 'If an account exists with that email address, you will receive a password reset link shortly.'
        ]);
        exit;
    }
    
    // Send password reset email
    $emailSent = $email_handler->sendPasswordReset(
        $result['user']['email'],
        $result['user']['username'],
        $result['token']
    );
    
    if ($emailSent) {
        echo json_encode([
            'success' => true, 
            'message' => 'Password reset link has been sent to your email address.'
        ]);
    } else {
        // Log the error but don't reveal it to user
        error_log("Failed to send password reset email to: " . $email);
        echo json_encode([
            'success' => true, 
            'message' => 'If an account exists with that email address, you will receive a password reset link shortly.'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Forgot password error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'An error occurred while processing your request. Please try again later.'
    ]);
}
?>
