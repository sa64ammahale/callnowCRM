<?php
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/php_scripts/rate_limiter.php';

// Show errors on POST so login failures don't produce a blank 500
ini_set('display_errors', (APP_DEBUG || $_SERVER['REQUEST_METHOD'] === 'POST') ? '1' : '0');
ini_set('display_startup_errors', (APP_DEBUG || $_SERVER['REQUEST_METHOD'] === 'POST') ? '1' : '0');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
ensureCsrfToken();

// Resolve the application logo for the login screen
$loginLogo = '';
if (isset($link) && $link) {
    try {
        $logoRes = $link->query("SELECT setting_value FROM " . tn('TBL_APP_SETTINGS') . " WHERE setting_key = 'logo_path'");
        if ($logoRes && $row = $logoRes->fetch_assoc()) {
            $loginLogo = APP_BASE . '/' . ltrim($row['setting_value'], '/');
        }
    } catch (\Throwable $e) {
        $loginLogo = '';
    }
}

$username = $password = $CompanyName = "";
$ErrorMessage = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $ErrorMessage = "Error: Invalid session token. Please try again.";
    } else {
        $CompanyName = trim($_POST["CompanyName"] ?? '');
        $username    = trim($_POST["username"] ?? '');
        $password    = trim($_POST["password"] ?? '');

        if (!empty($username) && !empty($password) && !empty($CompanyName)) {
            $rateLimitError = checkWebRateLimit($link, 'login', $username);
            if ($rateLimitError) {
                $ErrorMessage = $rateLimitError;
            } else {
                try {
                    $tblUsers = tn('TBL_USERS');
                    $sql = "SELECT ID, NAME, MOBILE, COMPANY, PACKAGE, STATUS, JOIN_DATE, ROLE, TEAM_ID, PASSWORD, LOGIN_ID, DEVICE_ID FROM $tblUsers WHERE LOGIN_ID = ?";
                    $stmt = mysqli_prepare($link, $sql);
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "s", $param_username);
                        $param_username = $username;
                        if (mysqli_stmt_execute($stmt)) {
                            mysqli_stmt_store_result($stmt);
                            if (mysqli_stmt_num_rows($stmt) == 1) {
                                mysqli_stmt_bind_result($stmt, $id, $name, $mobile, $company, $package, $status, $join_date, $role, $team, $hashed_password, $login_id, $device_id);
                                if (mysqli_stmt_fetch($stmt)) {
                                    if ($company == $CompanyName && $status == 'Active') {
                                        if (password_verify($password, $hashed_password)) {
                                            clearWebRateLimit($link, 'login', $username);
                                            session_regenerate_id(true);
                                            $_SESSION["loggedin"]    = true;
                                            $_SESSION["id"]          = $id;
                                            $_SESSION["name"]        = $name;
                                            $_SESSION["mobile"]      = $mobile;
                                            $_SESSION["company"]     = $company;
                                            $_SESSION["package"]     = $package;
                                            $_SESSION["status"]      = $status;
                                            $_SESSION["join_date"]   = $join_date;
                                            $_SESSION["role"]        = $role;
                                            $_SESSION["team_id"]     = $team;
                                            $_SESSION["login_id"]    = $login_id;
                                            $_SESSION["device_id"]   = $device_id;
                                            $_SESSION['LAST_ACTIVITY'] = time();
                                            header("Location: dashboard");
                                            exit;
                                        } else {
                                            recordFailedAttemptWeb($link, 'login', $username);
                                            $ErrorMessage = "Error: The password you entered is not valid.";
                                        }
                                    } else {
                                        recordFailedAttemptWeb($link, 'login', $username);
                                        $ErrorMessage = "Error: The Company Name is not valid OR your account is not active.";
                                    }
                                }
                            } else {
                                recordFailedAttemptWeb($link, 'login', $username);
                                $ErrorMessage = "Error: The username you entered is not valid.";
                            }
                        } else {
                            $ErrorMessage = "Oops! Something went wrong. Please try again later.";
                        }
                        mysqli_stmt_close($stmt);
                    } else {
                        $ErrorMessage = "Database error: " . mysqli_error($link);
                    }
                } catch (\Throwable $e) {
                    $ErrorMessage = "Error: " . $e->getMessage();
                }
            }
        }
    }

    if ($link) @mysqli_close($link);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Sign In &bull; CallNow CRM</title>
    <link href="<?= vnd('css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= vnd('css/bootstrap-icons.css') ?>" rel="stylesheet">
    <link href="assets/css/app-theme.css" rel="stylesheet">
</head>
<body class="bg-app">
    <div class="login-shell">
        <div class="login-card">
            <div class="login-brand">
                <?php if ($loginLogo): ?>
                    <img src="<?= htmlspecialchars($loginLogo, ENT_QUOTES, 'UTF-8') ?>" alt="CallNow" style="max-height:36px; max-width:180px; object-fit:contain;">
                <?php else: ?>
                    <span class="brand-mark"><i class="bi bi-telephone-fill"></i></span>
                    <span class="brand-text">CallNow CRM</span>
                <?php endif; ?>
            </div>

            <h1 class="login-title">Sign in to your account</h1>
            <p class="login-subtitle">Enter your organization credentials to continue</p>

            <?php if ($ErrorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <i class="bi bi-exclamation-circle me-1"></i>
                <?= htmlspecialchars($ErrorMessage, ENT_QUOTES, 'UTF-8') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <form action="<?= htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                
                <div class="mb-3">
                    <label for="company" class="form-label">Company Name <span class="req">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-building"></i></span>
                        <input type="text" id="company" class="form-control" name="CompanyName"
                               placeholder="e.g. Acme Corp" required
                               value="<?= htmlspecialchars($CompanyName) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="username" class="form-label">Email Address <span class="req">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" id="username" class="form-control" name="username"
                               placeholder="name@company.com" required
                               value="<?= htmlspecialchars($username) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="password" class="form-label mb-0">Password <span class="req">*</span></label>
                        <a href="forgot-password" class="small text-decoration-none">Forgot password?</a>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" id="password" class="form-control" name="password"
                               placeholder="Enter your password" required>
                        <button type="button" class="btn btn-secondary" id="togglePassword" type="button">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="d-grid mt-4 mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In to CRM
                    </button>
                </div>

                <div class="auth-footer">
                    <span>Don't have an account? </span>
                    <a href="CallNowSignUp" class="fw-semibold">Request Access</a>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= vnd('js/bootstrap.bundle.min.js') ?>"></script>
    <script>
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordField = document.getElementById('password');
            const icon = this.querySelector('i');
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                passwordField.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        });
    </script>
</body>
</html>
