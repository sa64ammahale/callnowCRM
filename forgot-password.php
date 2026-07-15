<?php
require_once "config.php";
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); } ensureCsrfToken();

$ErrorMessage = $NewPassword = "";
$success = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $ErrorMessage = "Error: Invalid session token. Please try again.";
    } else {
    $CompanyName = trim($_POST["CompanyName"]);
    $username = trim($_POST["username"]);
    
    // UPDATED QUERY with correct column names and order
    $sql = "SELECT ID, NAME, MOBILE, COMPANY, PACKAGE, STATUS, JOIN_DATE, ROLE, PASSWORD, LOGIN_ID, DEVICE_ID, TEAM_ID 
            FROM users WHERE LOGIN_ID = ?";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "s", $param_username);
        $param_username = $username;
        
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_store_result($stmt);
            
            if (mysqli_stmt_num_rows($stmt) == 1) {
                // UPDATED BIND_RESULT with correct column order
                mysqli_stmt_bind_result($stmt, $id, $name, $mobile, $company, $package, 
                                      $status, $join_date, $role, $hashed_password, 
                                      $login_id, $device_id, $team_id); // Added TEAM_ID
                
                if (mysqli_stmt_fetch($stmt)) {
                    // Input validation and sanitization
                    $inputCompanyName = htmlspecialchars(trim($_POST['CompanyName']), ENT_QUOTES, 'UTF-8');
                    
                    if ($company === $inputCompanyName && $status === 'Active') {
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
                        $headers = "From: no-reply@starsinfotech.in\r\n";
                        $headers .= "Reply-To: no-reply@increditsolutions.in\r\n";
                        $headers .= "X-Mailer: PHP/" . phpversion();
                        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                        
                        // Update password in database using prepared statement
                        $newHashedPassword = password_hash($NewPassword, PASSWORD_DEFAULT);
                        $update_sql = "UPDATE users SET PASSWORD = ? WHERE LOGIN_ID = ?";

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
                        $ErrorMessage = "Unable to reset the password with the provided details.";
                        $success = false;
                    }
                } else {
                    $ErrorMessage = "Error retrieving user information.";
                    $success = false;
                }
            } else {
                $ErrorMessage = "Unable to reset the password with the provided details.";
                $success = false;
            }
        } else {
            $ErrorMessage = "Oops! Something went wrong. Please try again later.";
            $success = false;
        }
        mysqli_stmt_close($stmt);
    } else {
        $ErrorMessage = "Database connection error. Please try again.";
        $success = false;
    }
    mysqli_close($link);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>CallNow | Reset Password</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app-theme.css" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        .login-header {
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            color: #fff;
            padding: 1.25rem 1rem;
            text-align: center;
        }
        .login-header h1 { color: #fff; font-size: 1.125rem; margin: 0.5rem 0 0.25rem; }
        .login-header .bi { font-size: 2rem; }
        .login-header p { opacity: 0.9; font-size: 0.8rem; margin: 0; }
        .instructions {
            background-color: var(--surface-2);
            border-radius: var(--radius-lg);
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--accent);
        }
        .instructions h6 { color: var(--accent); font-weight: 600; margin-bottom: 0.5rem; }
        .instructions ul { margin-bottom: 0; padding-left: 1rem; }
        .instructions li { font-size: 0.85rem; margin-bottom: 0.25rem; }
        .alert-custom {
            border: none;
            border-left: 4px solid;
            border-radius: 6px;
            padding: 1rem;
            font-size: 0.95rem;
        }
        .alert-success-custom {
            background-color: var(--success-soft);
            border-left-color: var(--success);
            color: var(--success);
        }
        .alert-danger-custom {
            background-color: var(--danger-soft);
            border-left-color: var(--danger);
            color: var(--danger);
        }
    </style>
</head>

<body>
    <div class="login-shell">
        <div class="login-card" style="max-width:450px">
            <!-- Header -->
            <div class="login-header">
                <i class="bi bi-key"></i>
                <h1>Reset Password</h1>
                <p>CallNow Account Recovery</p>
            </div>
            
            <!-- Reset Form -->
            <div class="p-4">
                <?php if($success && !empty($ErrorMessage)): ?>
                    <!-- Success Message -->
                    <div class="alert alert-success-custom alert-custom fade-in-up mb-4" role="alert">
                        <div class="d-flex">
                            <div class="me-3">
                                <i class="bi bi-check-circle" style="font-size: 1.5rem;"></i>
                            </div>
                            <div>
                                <h5 class="alert-heading mb-2">Password Reset Successful!</h5>
                                <p class="mb-0"><?php echo $ErrorMessage; ?></p>
                                <?php if(strpos($ErrorMessage, 'Your new password is:') !== false): ?>
                                    <div class="mt-3 p-2 bg-light rounded">
                                        <small class="text-muted d-block">Please save this password securely:</small>
                                        <code class="d-block mt-1 p-2 bg-white rounded border"><?php echo htmlspecialchars($NewPassword); ?></code>
                                    </div>
                                <?php endif; ?>
                                <div class="mt-3">
                                    <a href="index.php" class="btn btn-primary w-100">
                                        <i class="bi bi-box-arrow-in-right me-2"></i>Back to Login
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Instructions -->
                    <div class="instructions mb-4">
                        <h6><i class="bi bi-info-circle me-2"></i>Password Reset Instructions</h6>
                        <ul>
                            <li>Enter your registered company name</li>
                            <li>Enter your registered email address</li>
                            <li>A new password will be generated and sent to your email</li>
                            <li>Login with the new password and change it immediately</li>
                        </ul>
                    </div>
                    
                    <!-- Reset Form -->
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <!-- Company Name -->
                        <div class="mb-4">
                            <label for="company" class="form-label">Company Name</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-building"></i>
                                </span>
                                <input 
                                    type="text" 
                                    id="company" 
                                    class="form-control" 
                                    name="CompanyName" 
                                    placeholder="Enter your registered company name" 
                                    required
                                    value="<?php echo isset($_POST['CompanyName']) ? htmlspecialchars($_POST['CompanyName']) : ''; ?>"
                                >
                            </div>
                        </div>
                        
                        <!-- Email Address -->
                        <div class="mb-4">
                            <label for="username" class="form-label">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-envelope"></i>
                                </span>
                                <input 
                                    type="email" 
                                    id="username" 
                                    class="form-control" 
                                    name="username" 
                                    placeholder="Enter your registered email address" 
                                    required
                                    value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                                >
                            </div>
                        </div>
                        
                        <!-- Error Message -->
                        <?php if(!empty($ErrorMessage) && !$success): ?>
                            <div class="alert alert-danger-custom alert-custom mb-4 fade-in-up" role="alert">
                                <div class="d-flex">
                                    <div class="me-3">
                                        <i class="bi bi-exclamation-triangle" style="font-size: 1.5rem;"></i>
                                    </div>
                                    <div>
                                        <h5 class="alert-heading mb-1">Unable to Reset Password</h5>
                                        <p class="mb-0"><?php echo $ErrorMessage; ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Submit Button -->
                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="bi bi-arrow-repeat me-2"></i>Reset Password
                            </button>
                        </div>
                        
                        <!-- Back to Login -->
                        <div class="d-grid">
                            <a href="index.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-left me-2"></i>Back to Login
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="app-footer">
        <div class="container text-center">
            <p class="mb-0">
                Copyright &copy; <span class="text-primary fw-semibold">Sangam Mahale</span> 
                <script>document.write(new Date().getFullYear())</script>
            </p>
            <p class="mb-0 small mt-1">CallNow V5.00</p>
        </div>
    </footer>
    
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JS -->
    <script>
        // Auto-hide alerts after 8 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-custom');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 8000);
            });
            
            // Focus on first input field
            const companyInput = document.getElementById('company');
            if (companyInput) {
                companyInput.focus();
            }
        });
    </script>
</body>
</html>
