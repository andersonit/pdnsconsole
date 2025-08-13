<?php
// Simple contact form using PHPMailer (already available in vendor/)
session_start();

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;

// Turnstile keys (override via env if set)
$turnstileSecret = getenv('TURNSTILE_SECRET') ?: '0x4AAAAAABjo-EbSoBq5tkfYvx60pYOQdaU';
$turnstileSiteKey = getenv('TURNSTILE_SITEKEY') ?: '0x4AAAAAABjo-KY72e_0__at';

function verifyTurnstile(string $secret, string $token, string $ip = ''): bool {
  if ($token === '' || $secret === '') { return false; }
  $payload = http_build_query([
    'secret'   => $secret,
    'response' => $token,
    'remoteip' => $ip,
  ]);
  $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
  // Prefer cURL
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
  } else {
    $opts = [
      'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $payload,
        'timeout' => 10,
      ],
    ];
    $resp = @file_get_contents($url, false, stream_context_create($opts));
  }
  if (!$resp) { return false; }
  $data = json_decode($resp, true);
  return is_array($data) && !empty($data['success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
  $csrf = $_POST['csrf_token'] ?? '';
  $tsToken = $_POST['cf-turnstile-response'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $errors[] = 'Invalid form token. Please reload and try again.';
    }
    if ($name === '' || strlen($name) > 200) {
        $errors[] = 'Please enter your name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($message === '' || strlen($message) > 5000) {
        $errors[] = 'Please enter a message (max 5000 characters).';
    }

  if (!$errors) {
    // Verify Turnstile
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!verifyTurnstile($turnstileSecret, $tsToken, $ip)) {
      $errors[] = 'Please complete the human verification.';
    }
  }

  if (!$errors) {
        require_once __DIR__ . '/../../vendor/autoload.php';
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
      // Transport: prefer license_admin/config.php 'smtp' settings; fallback to environment variables; else PHP mail()
      $config = null;
      $configPath = __DIR__ . '/../config.php';
      if (is_readable($configPath)) {
        $config = require $configPath;
      }

      $smtpHost = '';
      $smtpPort = 587;
      $smtpSecure = 'tls'; // 'tls' (STARTTLS) or 'ssl'
      $smtpUser = '';
      $smtpPass = '';
      $fromEmail = 'no-reply@pdnsconsole.com';
      $fromName  = 'PDNS Console Website';

      if (is_array($config) && isset($config['smtp']) && is_array($config['smtp']) && !empty($config['smtp']['host'])) {
        $smtpHost = (string)$config['smtp']['host'];
        $smtpPort = (int)($config['smtp']['port'] ?? 587);
        $sec = strtolower(trim((string)($config['smtp']['secure'] ?? 'starttls')));
        if (in_array($sec, ['starttls','tls','ssl'], true)) {
          $smtpSecure = ($sec === 'starttls') ? 'tls' : $sec;
        }
        $smtpUser = (string)($config['smtp']['username'] ?? '');
        $smtpPass = (string)($config['smtp']['password'] ?? '');
        $fromEmail = (string)($config['smtp']['from_email'] ?? $fromEmail);
        $fromName  = (string)($config['smtp']['from_name']  ?? $fromName);
      } else {
        // Fallback to environment variables
        $smtpHost = getenv('SMTP_HOST') ?: '';
        $smtpPort = (int)(getenv('SMTP_PORT') ?: 587);
        $sec = strtolower(trim(getenv('SMTP_SECURE') ?: 'tls'));
        if (in_array($sec, ['starttls','tls','ssl'], true)) {
          $smtpSecure = ($sec === 'starttls') ? 'tls' : $sec;
        }
        $smtpUser = getenv('SMTP_USER') ?: '';
        $smtpPass = getenv('SMTP_PASS') ?: '';
        $fromEmail = getenv('SMTP_FROM') ?: $fromEmail;
        $fromName  = getenv('SMTP_FROMNAME') ?: $fromName;
      }

      // Optional debug flag (do not enable in production): set CONTACT_DEBUG=1 or config['smtp']['debug']=true
      $debugEnabled = (getenv('CONTACT_DEBUG') === '0');
      if (!$debugEnabled && is_array($config) && isset($config['smtp']['debug'])) {
        $debugEnabled = (bool)$config['smtp']['debug'];
      }
      if ($debugEnabled) {
        $mail->SMTPDebug = 2; // verbose
        $mail->Debugoutput = function ($str, $level) {
          error_log(sprintf('PHPMailer[%d]: %s', $level, $str));
        };
      }

      if ($smtpHost) {
        // Debug-only: DNS resolve and socket connectivity preflight
        if ($debugEnabled) {
          $resolved = gethostbyname($smtpHost);
          error_log(sprintf('SMTP preflight: host=%s resolved=%s port=%d secure=%s', $smtpHost, $resolved, $smtpPort, $smtpSecure));
          $errno = 0; $errstr = '';
          $fp = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 5.0);
          if ($fp) {
            error_log('SMTP preflight: TCP connect OK');
            fclose($fp);
          } else {
            error_log(sprintf('SMTP preflight: TCP connect FAILED (%d) %s', $errno, $errstr));
          }
        }
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->Port = $smtpPort;
        if ($smtpSecure === 'tls') {
          $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($smtpSecure === 'ssl') {
          $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        }
        if ($smtpUser !== '') {
          $mail->SMTPAuth = true;
          $mail->Username = $smtpUser;
          $mail->Password = $smtpPass;
        }
        $mail->SMTPAutoTLS = true;
        $mail->Timeout = 15;
        // Optional advanced SMTP options (e.g., SSL context)
        if (is_array($config) && isset($config['smtp']['options']) && is_array($config['smtp']['options'])) {
          $mail->SMTPOptions = $config['smtp']['options'];
        }
      }

      $mail->CharSet = 'UTF-8';
      $mail->isHTML(false);

      // Optional envelope sender override
      if (is_array($config) && isset($config['smtp']['sender']) && is_string($config['smtp']['sender']) && $config['smtp']['sender'] !== '') {
        $mail->Sender = $config['smtp']['sender'];
      }
      $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress('sales@pdnsconsole.com');
            $mail->addReplyTo($email, $name);
            $mail->Subject = 'PDNS Console – Website Inquiry';

            $body  = "Name: {$name}\n";
            $body .= "Email: {$email}\n\n";
            $body .= "Message:\n{$message}\n";

            $mail->Body = $body;
            $mail->AltBody = $body;

      $mail->send();
            $success = true;
            // rotate CSRF token after success
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
      // Log detailed error for troubleshooting, show detail only when debug is enabled
      $err = 'contact.php mail error: ' . $e->getMessage();
      if (isset($mail) && $mail instanceof PHPMailer\PHPMailer\PHPMailer && !empty($mail->ErrorInfo)) {
        $err .= ' | PHPMailer: ' . $mail->ErrorInfo;
      }
      error_log($err);
      if (!empty($debugEnabled)) {
        $errors[] = 'We could not send your message at this time. ' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8');
      } else {
        $errors[] = 'We could not send your message at this time.';
      }
        }
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact Us – PDNS Console</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  </head>
  <body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom">
      <div class="container py-2">
        <a class="navbar-brand" href="index.html"><i class="bi bi-shield-check me-2 text-primary"></i>PDNS Console</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topnav" aria-controls="topnav" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="topnav">
          <ul class="navbar-nav me-auto mb-2 mb-lg-0">
            <li class="nav-item"><a class="nav-link" href="index.html#features">Features</a></li>
            <li class="nav-item"><a class="nav-link" href="index.html#how">How it works</a></li>
            <li class="nav-item"><a class="nav-link" href="index.html#pricing">Pricing</a></li>
            <li class="nav-item"><a class="nav-link" href="index.html#faq">FAQ</a></li>
          </ul>
          <a class="btn btn-primary btn-sm" href="#">Client Login</a>
        </div>
      </div>
    </nav>

    <main class="py-5 bg-light">
      <div class="container" style="max-width: 820px;">
        <h1 class="h3 mb-3">Contact Us</h1>
        <p class="text-secondary">Have questions or need more information? Send us a message and we’ll get back to you.</p>

        <?php if ($success): ?>
          <div class="alert alert-success d-flex justify-content-between align-items-center">
            <div><i class="bi bi-check2-circle me-2"></i>Thank you! Your message has been sent.</div>
            <a class="alert-link" href="index.html">Return to main page</a>
          </div>
        <?php endif; ?>
        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <div class="fw-semibold mb-1">Please fix the following:</div>
            <ul class="mb-2">
              <?php foreach ($errors as $err): ?>
                <li><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></li>
              <?php endforeach; ?>
            </ul>
            <a class="alert-link" href="index.html">Return to main page</a>
          </div>
        <?php endif; ?>

        <form method="post" class="bg-white border rounded-3 p-4">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
          <div class="mb-3">
            <label class="form-label" for="name">Your Name</label>
            <input class="form-control" type="text" id="name" name="name" maxlength="200" required value="<?php echo isset($name) ? htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : '';?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input class="form-control" type="email" id="email" name="email" maxlength="255" required value="<?php echo isset($email) ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : '';?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="message">Message</label>
            <textarea class="form-control" id="message" name="message" rows="6" maxlength="5000" required><?php echo isset($message) ? htmlspecialchars($message, ENT_QUOTES, 'UTF-8') : '';?></textarea>
          </div>
          <div class="mb-3">
            <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars($turnstileSiteKey, ENT_QUOTES, 'UTF-8'); ?>" data-theme="light"></div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><i class="bi bi-send me-1"></i> Send Message</button>
            <a class="btn btn-outline-secondary" href="index.html">Back</a>
          </div>
        </form>

        <div class="text-secondary small mt-3">We value your privacy. Your information will only be used to respond to your inquiry.</div>
      </div>
    </main>

    <footer class="border-top py-4 text-secondary small">
      <div class="container d-flex justify-content-between flex-wrap gap-2">
        <div>&copy; <span id="year"></span> PDNS Console</div>
        <div>
          <a class="text-decoration-none" href="https://github.com/andersonit/pdnsconsole/blob/main/LICENSE.md">License</a>
          <span class="mx-2">|</span>
          <a class="text-decoration-none" href="https://github.com/andersonit/pdnsconsole">Source</a>
        </div>
      </div>
    </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <script>document.getElementById('year').textContent = new Date().getFullYear();</script>
  </body>
</html>
