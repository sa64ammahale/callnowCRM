<?php
require_once __DIR__ . '/php_scripts/config.php';
require_once __DIR__ . '/php_scripts/password_reset.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
ensureCsrfToken();
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$message = ''; $success = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) $message = 'Invalid session token. Refresh and try again.';
    elseif (strlen((string)($_POST['password'] ?? '')) < 8) $message = 'Use at least 8 characters.';
    elseif (($_POST['password'] ?? '') !== ($_POST['confirm_password'] ?? '')) $message = 'Passwords do not match.';
    else {
        $success = consumePasswordReset($link, $token, (string)$_POST['password']);
        $message = $success ? 'Password updated. You can now sign in.' : 'This reset link is invalid or expired. Request a new link.';
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reset Password • CallNow CRM</title><link rel="stylesheet" href="<?= htmlspecialchars(vnd('css/bootstrap.min.css'), ENT_QUOTES, 'UTF-8') ?>"><link rel="stylesheet" href="<?= htmlspecialchars(url('assets/css/app-theme.css'), ENT_QUOTES, 'UTF-8') ?>"></head>
<body class="bg-app"><main class="container py-5" style="max-width:480px"><div class="card p-4"><h1 class="h4">Choose a new password</h1>
<?php if ($message !== ''): ?><div class="alert <?= $success ? 'alert-success' : 'alert-danger' ?>" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if (!$success): ?><form method="post"><?= csrfField() ?><input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>"><label for="password" class="form-label">New password</label><input id="password" class="form-control mb-3" type="password" name="password" minlength="8" autocomplete="new-password" required><label for="confirm_password" class="form-label">Confirm password</label><input id="confirm_password" class="form-control mb-3" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required><button class="btn btn-primary w-100" type="submit">Reset password</button></form><?php endif; ?>
<a class="mt-3 d-block" href="<?= htmlspecialchars(url('index'), ENT_QUOTES, 'UTF-8') ?>">Back to sign in</a></div></main></body></html>
