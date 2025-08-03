<?php
/**
 * PDNS Console - Login Page
 */

// Generate CSRF token for this page load
$csrfToken = bin2hex(random_bytes(32));

// Get theme info including dark mode
$themeInfo = $settings->getThemeInfo();
$bodyClasses = 'login-page';
if ($themeInfo['effective_dark']) {
    $bodyClasses .= ' dark-mode';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="<?php echo $settings->getThemeUrl(); ?>" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="assets/css/custom.css" rel="stylesheet">
</head>
<body class="<?php echo $bodyClasses; ?>">
    <div class="login-container">
        <div class="login-header">
            <?php if (!empty($branding['site_logo'])): ?>
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" class="img-fluid">
            <?php endif; ?>
            <h2><?php echo htmlspecialchars($branding['site_name']); ?></h2>
            <p>DNS Management Console</p>
        </div>
        
        <?php if (!empty($loginError)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($loginError); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($loginSuccess)): ?>
            <div class="alert alert-success" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($loginSuccess); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="?page=login">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <div class="form-floating">
                <input type="text" 
                       class="form-control" 
                       id="username" 
                       name="username" 
                       placeholder="Username or Email"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                       required>
                <label for="username">Username or Email</label>
            </div>
            
            <div class="form-floating">
                <input type="password" 
                       class="form-control" 
                       id="password" 
                       name="password" 
                       placeholder="Password"
                       required>
                <label for="password">Password</label>
            </div>
            
            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>
                    Sign In
                </button>
            </div>
        </form>
        
        <div class="footer-text">
            <?php echo htmlspecialchars($branding['footer_text']); ?>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Font Awesome -->
    <script src="https://kit.fontawesome.com/your-fontawesome-kit.js" crossorigin="anonymous"></script>
</body>
</html>
