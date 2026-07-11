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
            FROM USERS WHERE LOGIN_ID = ?";
    
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
                        $oldHashedPassword = $hashed_password;
                        $update_sql = "UPDATE USERS SET PASSWORD = ? WHERE LOGIN_ID = ?";
                        
                        if ($update_stmt = mysqli_prepare($link, $update_sql)) {
                            mysqli_stmt_bind_param($update_stmt, "ss", $newHashedPassword, $username);
                            
                            if ($to && mysqli_stmt_execute($update_stmt)) {
                                // Try to send email, but don't fail if email doesn't send
                                $emailSent = mail($to, $subject, $message, $headers);
                                if ($emailSent) {
                                    $ErrorMessage = "Success! Your new password has been sent to your email.";
                                    $success = true;
                                } else {
                                    $revert_sql = "UPDATE USERS SET PASSWORD = ? WHERE LOGIN_ID = ?";
                                    if ($revert_stmt = mysqli_prepare($link, $revert_sql)) {
                                        mysqli_stmt_bind_param($revert_stmt, "ss", $oldHashedPassword, $username);
                                        mysqli_stmt_execute($revert_stmt);
                                        mysqli_stmt_close($revert_stmt);
                                    }
                                    $ErrorMessage = "Password reset could not be completed because the email could not be sent.";
                                    $success = false;
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
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #4a6bff;
            --secondary-color: #6c757d;
            --accent-color: #00d4aa;
            --light-bg: #f8f9fa;
            --dark-bg: #1a1d29;
            --success-color: #28a745;
            --danger-color: #dc3545;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #f5f7ff 0%, #eef1ff 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .reset-container {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 1;
            padding: 2rem 1rem;
        }
        
        .reset-card {
            background-color: white;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            width: 100%;
            max-width: 450px;
            border: none;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), #3a56d9);
            color: white;
            padding: 1rem 1rem;
            text-align: center;
            border-bottom: none;
        }
        
        .brand-logo {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: white;
        }
        
        .brand-title {
            font-weight: 700;
            font-size: 1.6rem;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }
        
        .brand-subtitle {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-top: 0;
            line-height: 1.2;
        }
        
        .card-body {
            padding: 2rem 1.5rem;
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .input-group-text {
            background-color: #f8f9fa;
            border-right: none;
        }
        
        .form-control {
            border-left: none;
            padding-left: 0;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #ced4da;
            box-shadow: 0 0 0 0.25rem rgba(74, 107, 255, 0.15);
        }
        
        .input-group:focus-within .input-group-text {
            border-color: #86b7fe;
        }
        
        .btn-reset {
            background: linear-gradient(to right, var(--primary-color), #3a56d9);
            border: none;
            padding: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: all 0.3s;
            width: 100%;
        }
        
        .btn-reset:hover {
            background: linear-gradient(to right, #3a56d9, #2a46c9);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(74, 107, 255, 0.3);
        }
        
        .btn-back {
            background-color: #6c757d;
            border: none;
            padding: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: all 0.3s;
            width: 100%;
            margin-top: 10px;
        }
        
        .btn-back:hover {
            background-color: #5a6268;
            transform: translateY(-2px);
        }
        
        .alert-custom {
            border: none;
            border-left: 4px solid;
            border-radius: 6px;
            padding: 1rem;
            font-size: 0.95rem;
        }
        
        .alert-success-custom {
            background-color: rgba(40, 167, 69, 0.1);
            border-left-color: var(--success-color);
            color: #155724;
        }
        
        .alert-danger-custom {
            background-color: rgba(220, 53, 69, 0.1);
            border-left-color: var(--danger-color);
            color: #721c24;
        }
        
        .instructions {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary-color);
        }
        
        .instructions h6 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .instructions ul {
            margin-bottom: 0;
            padding-left: 1rem;
        }
        
        .instructions li {
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }
        
        .footer {
            background-color: var(--dark-bg);
            color: #adb5bd;
            padding: 1.5rem 0;
            margin-top: auto;
        }
        
        .company-name {
            color: var(--accent-color);
            font-weight: 600;
        }
        
        @media (max-width: 576px) {
            .reset-card {
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            }
            
            .card-header {
                padding: 1.25rem 1rem;
            }
            
            .brand-logo {
                font-size: 1.75rem;
            }
            
            .brand-title {
                font-size: 1.4rem;
            }
            
            .card-body {
                padding: 1.5rem 1rem;
            }
        }
        
        /* Animation for success message */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.5s ease-out;
        }
    </style>
</head>

<body>
    <div class="reset-container">
        <div class="reset-card">
            <!-- Header -->
            <div class="card-header">
                <div class="brand-logo">
                    <i class="fas fa-key"></i>
                </div>
                <h1 class="brand-title">Reset Password</h1>
                <p class="brand-subtitle">CallNow Account Recovery</p>
            </div>
            
            <!-- Reset Form -->
            <div class="card-body">
                <?php if($success && !empty($ErrorMessage)): ?>
                    <!-- Success Message -->
                    <div class="alert alert-success-custom alert-custom fade-in-up mb-4" role="alert">
                        <div class="d-flex">
                            <div class="me-3">
                                <i class="fas fa-check-circle" style="font-size: 1.5rem; color: var(--success-color);"></i>
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
                                    <a href="index.php" class="btn btn-reset">
                                        <i class="fas fa-sign-in-alt me-2"></i>Back to Login
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Instructions -->
                    <div class="instructions mb-4">
                        <h6><i class="fas fa-info-circle me-2"></i>Password Reset Instructions</h6>
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
                                    <i class="fas fa-building"></i>
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
                                    <i class="fas fa-envelope"></i>
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
                                        <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem; color: var(--danger-color);"></i>
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
                            <button type="submit" class="btn btn-reset btn-lg text-white">
                                <i class="fas fa-redo-alt me-2"></i>Reset Password
                            </button>
                        </div>
                        
                        <!-- Back to Login -->
                        <div class="d-grid">
                            <a href="index.php" class="btn btn-back text-white">
                                <i class="fas fa-arrow-left me-2"></i>Back to Login
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="footer">
        <div class="container text-center">
            <p class="mb-0">
                Copyright &copy; <span class="company-name">Sangam Mahale</span> 
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
