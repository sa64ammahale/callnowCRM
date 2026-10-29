<?php
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/php_scripts/rate_limiter.php';

// Show errors on POST so failures don't produce a blank 500
ini_set('display_errors', (APP_DEBUG || $_SERVER['REQUEST_METHOD'] === 'POST') ? '1' : '0');
ini_set('display_startup_errors', (APP_DEBUG || $_SERVER['REQUEST_METHOD'] === 'POST') ? '1' : '0');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
ensureCsrfToken();

$ErrorMessage = $NewPassword = "";
$success = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $ErrorMessage = "Error: Invalid session token. Please try again.";
    } else {
        $CompanyName = trim($_POST["CompanyName"] ?? '');
        $username = trim($_POST["username"] ?? '');
        
        if (!empty($username) && !empty($CompanyName)) {
            $rateLimitError = checkWebRateLimit($link, 'forgot_password', $username, 3, 900);
            if ($rateLimitError) {
                $ErrorMessage = $rateLimitError;
                $success = false;
            } else {
                try {
                    $tblUsers = tn('TBL_USERS');
                    $sql = "SELECT ID, NAME, MOBILE, COMPANY, PACKAGE, STATUS, JOIN_DATE, ROLE, PASSWORD, LOGIN_ID, DEVICE_ID, TEAM_ID 
                            FROM $tblUsers WHERE LOGIN_ID = ?";
                    
                    if ($stmt = mysqli_prepare($link, $sql)) {
                        mysqli_stmt_bind_param($stmt, "s", $param_username);
                        $param_username = $username;
                        
                        if (mysqli_stmt_execute($stmt)) {
                            mysqli_stmt_store_result($stmt);
                            
                            if (mysqli_stmt_num_rows($stmt) == 1) {
                                mysqli_stmt_bind_result($stmt, $id, $name, $mobile, $company, $package, 
                                                      $status, $join_date, $role, $hashed_password, 
                                                      $login_id, $device_id, $team_id);
                                
                                if (mysqli_stmt_fetch($stmt)) {
                                    $inputCompanyName = htmlspecialchars(trim($_POST['CompanyName']), ENT_QUOTES, 'UTF-8');
                                    
                                    if ($company === $inputCompanyName && $status === 'Active') {
                                        clearWebRateLimit($link, 'forgot_password', $username);
                                        // Generate secure random password
                                        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
                                        $NewPassword = '';
                                        for ($i = 0; $i < 12; $i++) {
                                            $NewPassword .= $chars[random_int(0, strlen($chars) - 1)];
                                        }
                                        
                                        // Prepare email
                                        $to = filter_var($login_id, FILTER_VALIDATE_EMAIL);
                                        $subject = "CallNow Password Reset";
                                        $message = "Hello " . $name . ",\n\n";
                                        $message .= "Your password has been reset successfully.\n\n";
                                        $message .= "Your new password is: " . $NewPassword . "\n\n";
                                        $message .= "Please login and change your password immediately for security reasons.\n\n";
                                        $message .= "Best regards,\nCallNow Team";
                                        
                                        // Email headers
                                        $mailDomain = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
                                        $noReply = 'no-reply@' . $mailDomain;
                                        $headers = "From: $noReply\r\n";
                                        $headers .= "Reply-To: $noReply\r\n";
                                        $headers .= "X-Mailer: PHP/" . phpversion();
                                        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                                        
                                        // Update password in database
                                        $newHashedPassword = password_hash($NewPassword, PASSWORD_DEFAULT);
                                        $update_sql = "UPDATE $tblUsers SET PASSWORD = ? WHERE LOGIN_ID = ?";

                                        if ($update_stmt = mysqli_prepare($link, $update_sql)) {
                                            mysqli_stmt_bind_param($update_stmt, "ss", $newHashedPassword, $username);

                                            if (mysqli_stmt_execute($update_stmt)) {
                                                $emailSent = false;
                                                if ($to) {
                                                    $emailSent = @mail($to, $subject, $message, $headers);
                                                }
                                                if ($emailSent) {
                                                    $ErrorMessage = "Success! Your new password has been sent to your email.";
                                                    $success = true;
                                                } else {
                                                    $ErrorMessage = "Your new password is: " . $NewPassword . " — (email not sent; configured mail server required for delivery)";
                                                    $success = true;
                                                }
                                            } else {
                                                $ErrorMessage = "Failed to reset password. Please try again.";
                                                $success = false;
                                            }
                                            mysqli_stmt_close($update_stmt);
                                        } else {
                                            $ErrorMessage = "Database error occurred. Please try again.";
                                            $success = false;
                                        }
                                    } else {
                                        recordFailedAttemptWeb($link, 'forgot_password', $username);
                                        $ErrorMessage = "Unable to reset the password with the provided details.";
                                        $success = false;
                                    }
                                } else {
                                    recordFailedAttemptWeb($link, 'forgot_password', $username);
                                    $ErrorMessage = "Error retrieving user information.";
                                    $success = false;
                                }
                            } else {
                                recordFailedAttemptWeb($link, 'forgot_password', $username);
                                $ErrorMessage = "Unable to reset the password with the provided details.";
                                self: $success = false;
                            }
                        } else {
                            $ErrorMessage = "Oops! Something went wrong. Please try again later.";
                            $success = false;
                        }
                        mysqli_stmt_close($stmt);
                    } else {
                        $ErrorMessage = "Database error: " . mysqli_error($link);
                        $success = false;
                    }
                } catch (\Throwable $e) {
                    $ErrorMessage = "Error: " . $e->getMessage();
                    $success = false;
                }
            }
        }
        
        if ($link) @mysqli_close($link);
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Reset Password &bull; CallNow CRM</title>
    <link href="<?= vnd('css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= vnd('css/bootstrap-icons.css') ?>" rel="stylesheet">
    <link href="assets/css/app-theme.css" rel="stylesheet">
</head>
<body class="bg-app">
    <div class="login-shell">
        <div class="login-card" style="max-width:420px;">
            <div class="login-brand">
                <span class="brand-mark"><i class="bi bi-key-fill"></i></span>
                <span class="brand-text">CallNow CRM</span>
            </div>

            <h1 class="login-title">Reset Your Password</h1>
            <p class="login-subtitle">Enter your organization credentials to recover your account</p>

            <?php if($success && !empty($ErrorMessage)): ?>
                <div class="alert alert-success d-flex align-items-start gap-2 mb-3" role="alert">
                    <i class="bi bi-check-circle-fill text-success fs-5"></i>
                    <div>
                        <div class="fw-semibold">Password Reset Successful</div>
                        <div class="small mt-1"><?= $ErrorMessage ?></div>
                        <?php if(strpos($ErrorMessage, 'Your new password is:') !== false): ?>
                            <div class="mt-2 p-2 bg-white rounded border">
                                <code class="fw-bold fs-6 text-dark"><?= htmlspecialchars($NewPassword) ?></code>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-grid mt-3">
                    <a href="index" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Proceed to Login
                    </a>
                </div>
            <?php else: ?>
                <div class="p-3 border rounded bg-subtle mb-3 small">
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
                                   value="<?= isset($_POST['CompanyName']) ? htmlspecialchars($_POST['CompanyName']) : ''; ?>">
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
