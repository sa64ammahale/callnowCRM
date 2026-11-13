<?php
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/php_scripts/rate_limiter.php';

// Show errors on POST so failures don't produce a blank 500
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
ensureCsrfToken();

$ErrorMessage = $NewPassword = "";
$companyName = $_COOKIE['callnow_company_code'] ?? '';
$success = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $ErrorMessage = 'Invalid session token. Refresh and try again.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $companyName = trim((string)($_POST['CompanyName'] ?? ''));
        $tenant = resolveTenant($companyName);
        if ($tenant) {
            $link = getTenantConnection($tenant['company_code']);
        }
        $rateLimitError = checkWebRateLimit($link, 'forgot_password', $username, 3, 900);
        if ($rateLimitError) {
            $ErrorMessage = $rateLimitError;
        } else {
            recordFailedAttemptWeb($link, 'forgot_password', $username);
            require_once __DIR__ . '/php_scripts/password_reset.php';
            ensurePasswordResetSchema($link);
            $stmt = $link->prepare("SELECT ID, LOGIN_ID FROM " . tn('TBL_USERS') . " WHERE LOGIN_ID = ? AND STATUS = 'Active' LIMIT 1");
            $stmt->bind_param('s', $username);
            $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($user && filter_var($user['LOGIN_ID'], FILTER_VALIDATE_EMAIL)) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expiresAt = date('Y-m-d H:i:s', time() + 1800);
                $stmt = $link->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
                $stmt->bind_param('iss', $user['ID'], $tokenHash, $expiresAt);
                if ($stmt->execute()) {
                    $resetUrl = url('reset-password') . '?token=' . rawurlencode($token);
                    @mail($user['LOGIN_ID'], 'Reset your CallNow password', "Open this link within 30 minutes to choose a new password:\n" . $resetUrl . "\nIf you did not request this, ignore this email.");
                }
                $stmt->close();
            }
            $success = true;
            $ErrorMessage = 'If an active account matches these details, a password reset link has been sent.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Reset Password &bull; CallNow CRM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="<?= vnd('css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= vnd('css/bootstrap-icons.css') ?>" rel="stylesheet">
    <link href="assets/css/app-theme.css?v=<?= file_exists(__DIR__ . '/assets/css/app-theme.css') ? filemtime(__DIR__ . '/assets/css/app-theme.css') : '5.1' ?>" rel="stylesheet">
</head>
<body class="bg-app">
    <div class="login-shell">
        <div class="login-card" style="max-width:440px;">
            <div class="login-brand">
                <span class="brand-mark"><i class="bi bi-key-fill"></i></span>
                <span class="brand-text">CallNow <span class="flux-gradient-text">CRM</span></span>
            </div>

            <h1 class="login-title">Reset Your Password</h1>
            <p class="login-subtitle">Enter your organization credentials to recover your account</p>

            <?php if($success && !empty($ErrorMessage)): ?>
                <div class="alert alert-success d-flex align-items-start gap-2 mb-3" role="alert">
                    <i class="bi bi-check-circle-fill text-success fs-5"></i>
                    <div>
                        <div class="fw-semibold">Check your email</div>
                        <div class="small mt-1"><?= htmlspecialchars($ErrorMessage, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
                <div class="d-grid mt-3">
                    <a href="index" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Proceed to Login
                    </a>
                </div>
            <?php else: ?>
                <div class="p-3 border rounded bg-subtle mb-3 small" style="border-radius:var(--radius-md);">
                    <div class="fw-semibold text-dark mb-1"><i class="bi bi-info-circle text-primary me-1"></i>Account Verification</div>
                    <div class="text-secondary">Enter your registered company name and login email. Your credentials will be verified against system records.</div>
                </div>

                <?php if(!empty($ErrorMessage) && !$success): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <?= htmlspecialchars($ErrorMessage, ENT_QUOTES, 'UTF-8') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    
                    <div class="mb-3">
                        <label for="company" class="form-label">Company Name <span class="req">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-building"></i></span>
                            <input type="text" id="company" class="form-control" name="CompanyName" 
                                   placeholder="Registered company name" required
                                   value="<?= htmlspecialchars($_POST['CompanyName'] ?? $companyName, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="username" class="form-label">Email Address <span class="req">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" id="username" class="form-control" name="username" 
                                   placeholder="registered@company.com" required
                                   value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                        </div>
                    </div>

                    <div class="d-grid gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-arrow-repeat me-1"></i> Generate New Password
                        </button>
                        <a href="index" class="btn btn-secondary">
                            Back to Sign In
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script src="<?= vnd('js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
