<?php
require_once "config.php";
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); } ensureCsrfToken();

// Public self-registration is disabled. This script is only for initial
// admin provisioning via a one-time SETUP_KEY (set in .env / environment),
// or by an already-authenticated Admin. Otherwise it is blocked.
$setupKey = getenv('SETUP_KEY') ?: '';
$providedKey = $_GET['setup_key'] ?? ($_POST['setup_key'] ?? '');
$isAdminUser = false;
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    require_once __DIR__ . '/php_scripts/auth.php';
    $isAdminUser = isAdmin();
}
if (!$isAdminUser && ($setupKey === '' || $providedKey !== $setupKey)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Disabled</title>'
       . '<link href="' . vnd('css/bootstrap.min.css') . '" rel="stylesheet"></head>'
       . '<body class="p-5"><div class="alert alert-warning w-50 mx-auto">'
       . '<i class="bi bi-lock-fill me-2"></i>Self-registration is disabled on this server. '
       . 'Contact your administrator to request an account.</div></body></html>';
    exit;
}


// Initialize variables
$name = $mobile = $company = $login_id = $password = $confirm_password = "";
$name_err = $mobile_err = $company_err = $login_id_err = $password_err = $confirm_password_err = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error_message = "Error: Invalid session token. Please try again.";
        $success = false;
    } else {
    
    // Validate Company Name
    if(!empty(trim($_POST["company"]))){ 
        $company = trim($_POST["company"]);
        $company = htmlspecialchars($company, ENT_QUOTES, 'UTF-8');
    } else { 
        $company_err = "Please Enter Your Company Name."; 
    }
    
    // Validate Full Name - Only letters and spaces allowed
    if(!empty(trim($_POST["name"]))){ 
        $name = trim($_POST["name"]);
        if(!preg_match("/^[a-zA-Z\s.'-]+$/", $name)) {
            $name_err = "Name can only contain letters, spaces, and basic punctuation (., '-).";
        } elseif(strlen($name) < 2) {
            $name_err = "Name must be at least 2 characters long.";
        } elseif(strlen($name) > 50) {
            $name_err = "Name cannot exceed 50 characters.";
        }
    } else { 
        $name_err = "Please enter Your Full Name."; 
    }	
    
    // Validate Mobile Number
    if(!empty(trim($_POST["mobile"]))){ 
        $mobile = trim($_POST["mobile"]); 
        $mobile = preg_replace("/[^0-9]/", "", $mobile);
        
        if(strlen($mobile) != 10){
            $mobile_err = "Mobile number must be exactly 10 digits.";
        } else {
            // Check if mobile number already exists in database
            $sql = "SELECT ID FROM " . tn('TBL_USERS') . " WHERE MOBILE = ?";
            if($stmt = mysqli_prepare($link, $sql)){ 
                mysqli_stmt_bind_param($stmt, "s", $param_mobile);
                $param_mobile = $mobile;
                if(mysqli_stmt_execute($stmt)){
                    mysqli_stmt_store_result($stmt);
                    if(mysqli_stmt_num_rows($stmt) >= 1){
                        $mobile_err = "This mobile number is already registered.";
                    }
                } else{
                    $mobile_err = "Error checking mobile number. Please try again.";
                }
                mysqli_stmt_close($stmt);
            }
        }
    } else { 
        $mobile_err = "Please Enter Your Mobile Number."; 
    }
    
    // Validate Login ID (Email) with duplicate check
    if(empty(trim($_POST["login_id"]))){ 
        $login_id_err = "Please Enter Your Email Address."; 
    } else { 
        $login_id = trim($_POST["login_id"]);
        
        if (!filter_var($login_id, FILTER_VALIDATE_EMAIL)) {
            $login_id_err = "Please enter a valid email address.";
        } else {
            // Check if email already exists in database
            $sql = "SELECT ID FROM " . tn('TBL_USERS') . " WHERE LOGIN_ID = ?";
            if($stmt = mysqli_prepare($link, $sql)){ 
                mysqli_stmt_bind_param($stmt, "s", $param_login_id);
                $param_login_id = $login_id;
                if(mysqli_stmt_execute($stmt)){
                    mysqli_stmt_store_result($stmt);
                    if(mysqli_stmt_num_rows($stmt) >= 1){
                        $login_id_err = "This email is already registered.";
                    }
                } else{
                    $login_id_err = "Error checking email. Please try again.";
                }
                mysqli_stmt_close($stmt);
            }         
        }
    }
    
    // Validate Password
    if(empty(trim($_POST["password"]))){ 
        $password_err = "Please enter a password."; 
    } elseif (strlen(trim($_POST["password"])) < 6){ 
        $password_err = "Password must have atleast 6 characters."; 
    } else { 
        $password = trim($_POST["password"]); 
        
        // Optional: Add password strength validation
        if (!preg_match('/[A-Z]/', $password)) {
            $password_err = "Password should contain at least one uppercase letter.";
        } elseif (!preg_match('/[a-z]/', $password)) {
            $password_err = "Password should contain at least one lowercase letter.";
        } elseif (!preg_match('/[0-9]/', $password)) {
            $password_err = "Password should contain at least one number.";
        }
    }
    
    // Validate Confirm Password
    if(empty(trim($_POST["confirm_password"]))){ 
        $confirm_password_err = "Please confirm password."; 
    } else { 
        $confirm_password = trim($_POST["confirm_password"]); 
        if(empty($password_err) && ($password != $confirm_password)){ 
            $confirm_password_err = "Password did not match."; 
        }
    }
    
    // Save into database if no errors
    if(empty($company_err) && empty($name_err) && empty($mobile_err) && 
       empty($login_id_err) && empty($password_err) && empty($confirm_password_err)) {
        
        $sql = "INSERT INTO " . tn('TBL_USERS') . " (NAME, MOBILE, COMPANY, LOGIN_ID, PASSWORD, STATUS, JOIN_DATE, ROLE, PACKAGE, DEVICE_ID, TEAM_ID) 
                VALUES (?, ?, ?, ?, ?, 'Inactive', CURDATE(), 'Officer', NULL, NULL, NULL)";
         
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "sssss", $param_name, $param_mobile, $param_company, $param_login_id, $param_password);
            
            $param_name = $name;
            $param_mobile = $mobile;
            $param_company = $company;
            $param_login_id = $login_id;
            $param_password = password_hash($password, PASSWORD_DEFAULT);
            
            if(mysqli_stmt_execute($stmt)){
                $success_message = "Your account has been created successfully! Please wait for admin approval. You will be redirected to login page.";
                $success = true;
            } else{
                $error_message = "Something went wrong. Please try again later.";
                $success = false;
            }
        } else {
            $error_message = "Database error. Please try again.";
            $success = false;
        }
        mysqli_stmt_close($stmt);
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
    <title>CallNow | Sign Up</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="<?= vnd('css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="assets/css/app-theme.css" rel="stylesheet">
    
    <link rel="stylesheet" href="<?= vnd('css/bootstrap-icons.css') ?>">
    
    <style>
        .signup-card { max-width: 550px; }
        .signup-header {
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            color: #fff;
            padding: 1.5rem 1.5rem;
            text-align: center;
        }
        .signup-header h1 { color: #fff; font-size: 1.25rem; margin: 0.5rem 0 0.25rem; }
        .signup-header .bi { font-size: 2rem; }
        .signup-header p { opacity: 0.9; font-size: 0.8rem; margin: 0; }
        .form-label { font-size: 0.8rem; text-transform: none; letter-spacing: 0; font-weight: 600; }
        .form-label-flex { display: flex; justify-content: space-between; align-items: center; }
        .required-field::after { content: " *"; color: var(--danger); }
        .validation-info { font-size: 0.75rem; color: var(--ink-muted); font-weight: normal; text-transform: none; letter-spacing: 0; }
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
        .password-strength { height: 4px; border-radius: 2px; margin-top: 5px; background-color: var(--border); overflow: hidden; }
        .password-strength-bar { height: 100%; width: 0%; transition: width 0.3s ease; }
        .form-text { font-size: 0.75rem; color: var(--ink-muted); }
        .error-message { color: var(--danger); font-size: 0.8rem; margin-top: 0.25rem; display: flex; align-items: center; gap: 5px; }
        .success-message { color: var(--success); font-size: 0.8rem; margin-top: 0.25rem; display: flex; align-items: center; gap: 5px; }
        .password-toggle { cursor: pointer; transition: color 0.2s; border-left: 1px solid var(--border) !important; }
        .password-toggle:hover { color: var(--accent); }
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
        .validation-icon { position: absolute; right: 45px; top: 50%; transform: translateY(-50%); z-index: 5; display: none; }
        .valid-icon { color: var(--success); }
        .invalid-icon { color: var(--danger); }
        .spin { animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    </style>
</head>

<body>
    <div class="login-shell">
        <div class="login-card signup-card">
            <!-- Header -->
            <div class="signup-header">
                <i class="bi bi-person-plus"></i>
                <h1>Create Account</h1>
                <p>Join CallNow Professional Communication Platform</p>
            </div>
            
            <!-- Sign Up Form -->
            <div class="p-4">
                <!-- Success/Error Messages from PHP -->
                <?php if(isset($success_message) && $success): ?>
                    <div class="alert alert-success-custom alert-custom fade-in-up mb-4" role="alert">
                        <div class="d-flex">
                            <div class="me-3">
                                <i class="bi bi-check-circle" style="font-size: 1.5rem;"></i>
                            </div>
                            <div>
                                <h5 class="alert-heading mb-2">Account Created Successfully!</h5>
                                <p class="mb-0"><?php echo $success_message; ?></p>
                            </div>
                        </div>
                    </div>
                    <script>
                        setTimeout(function() {
                            window.location.href = "index";
                        }, 3000);
                    </script>
                <?php elseif(isset($error_message) && !$success): ?>
                    <div class="alert alert-danger-custom alert-custom fade-in-up mb-4" role="alert">
                        <div class="d-flex">
                            <div class="me-3">
                                <i class="bi bi-exclamation-triangle" style="font-size: 1.5rem;"></i>
                            </div>
                            <div>
                                <h5 class="alert-heading mb-2">Registration Failed</h5>
                                <p class="mb-0"><?php echo $error_message; ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Instructions -->
                <div class="instructions mb-4">
                    <h6><i class="bi bi-info-circle me-2"></i>Account Creation Information</h6>
                    <ul>
                        <li>All fields marked with * are required</li>
                        <li>Your account will be set to "Inactive" initially</li>
                        <li>After submission, wait for admin approval to activate your account</li>
                        <li>Use a valid email for login credentials</li>
                    </ul>
                </div>
                
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="signupForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <!-- Company Name -->
                    <div class="mb-3">
                        <label for="company" class="form-label required-field">
                            Company Name
                            <span class="validation-info">Min. 2 characters</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-building"></i>
                            </span>
                            <input 
                                type="text" 
                                id="company" 
                                name="company" 
                                class="form-control <?php echo (!empty($company_err)) ? 'is-invalid' : ''; ?>" 
                                placeholder="Enter your company name" 
                                value="<?php echo htmlspecialchars($company); ?>"
                                required
                                minlength="2"
                                maxlength="200"
                            >
                        </div>
                        <?php if(!empty($company_err)): ?>
                            <div class="error-message">
                                <i class="bi bi-exclamation-circle"></i><?php echo $company_err; ?>
                            </div>
                        <?php endif; ?>
                        <div class="form-text">Enter your registered company name (2-200 characters)</div>
                    </div>
                    
                    <!-- Full Name -->
                    <div class="mb-3">
                        <label for="name" class="form-label required-field">
                            Full Name
                            <span class="validation-info">Only letters and spaces</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-person"></i>
                            </span>
                            <input 
                                type="text" 
                                id="name" 
                                name="name" 
                                class="form-control <?php echo (!empty($name_err)) ? 'is-invalid' : ''; ?>" 
                                placeholder="Enter your full name" 
                                value="<?php echo htmlspecialchars($name); ?>"
                                required
                                pattern="[a-zA-Z\s.'-]+"
                                title="Only letters, spaces, and basic punctuation (., '-) allowed"
                                minlength="2"
                                maxlength="50"
                            >
                        </div>
                        <?php if(!empty($name_err)): ?>
                            <div class="error-message">
                                <i class="bi bi-exclamation-circle"></i><?php echo $name_err; ?>
                            </div>
                        <?php endif; ?>
                        <div class="form-text">Only letters, spaces, and basic punctuation (., '-) allowed (2-50 characters)</div>
                    </div>
                    
                    <!-- Mobile Number -->
                    <div class="mb-3">
                        <label for="mobile" class="form-label required-field">
                            Mobile Number
                            <span class="validation-info">10 digits only</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-phone"></i>
                            </span>
                            <input 
                                type="tel" 
                                id="mobile" 
                                name="mobile" 
                                class="form-control <?php echo (!empty($mobile_err)) ? 'is-invalid' : ''; ?>" 
                                placeholder="Enter your 10-digit mobile number" 
                                value="<?php echo htmlspecialchars($mobile); ?>"
                                pattern="[0-9]{10}"
                                maxlength="10"
                                required
                            >
                        </div>
                        <?php if(!empty($mobile_err)): ?>
                            <div class="error-message">
                                <i class="bi bi-exclamation-circle"></i><?php echo $mobile_err; ?>
                            </div>
                        <?php endif; ?>
                        <div class="success-message" id="mobileAvailable" style="display: none;">
                            <i class="bi bi-check-circle"></i>Mobile number is available
                        </div>
                        <div class="error-message" id="mobileDuplicate" style="display: none;">
                            <i class="bi bi-x-circle"></i>Mobile number already registered
                        </div>
                        <div class="form-text">Must be exactly 10 digits (no country code)</div>
                    </div>
                    
                    <!-- Email / Login ID -->
                    <div class="mb-3">
                        <label for="login_id" class="form-label required-field">
                            Email Address (Username)
                            <span class="validation-info">Valid email format</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-envelope"></i>
                            </span>
                            <input 
                                type="email" 
                                id="login_id" 
                                name="login_id" 
                                class="form-control <?php echo (!empty($login_id_err)) ? 'is-invalid' : ''; ?>" 
                                placeholder="Enter your email address" 
                                value="<?php echo htmlspecialchars($login_id); ?>"
                                required
                            >
                        </div>
                        <?php if(!empty($login_id_err)): ?>
                            <div class="error-message">
                                <i class="bi bi-exclamation-circle"></i><?php echo $login_id_err; ?>
                            </div>
                        <?php endif; ?>
                        <div class="success-message" id="emailAvailable" style="display: none;">
                            <i class="bi bi-check-circle"></i>Email is available
                        </div>
                        <div class="error-message" id="emailDuplicate" style="display: none;">
                            <i class="bi bi-x-circle"></i>Email already registered
                        </div>
                        <div class="form-text">This will be your username for login</div>
                    </div>
                    
                    <!-- Password -->
                    <div class="mb-3">
                        <label for="password" class="form-label required-field">
                            Password
                            <span class="validation-info">Min. 6 characters with mix</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-lock"></i>
                            </span>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" 
                                placeholder="Create a password (min. 6 characters)" 
                                required
                                minlength="6"
                            >
                            <span class="input-group-text password-toggle" id="togglePassword">
                                <i class="bi bi-eye"></i>
                            </span>
                        </div>
                        <div class="password-strength">
                            <div class="password-strength-bar" id="passwordStrengthBar"></div>
                        </div>
                        <div class="form-text" id="passwordRequirements">
                            Minimum 6 characters with at least one uppercase, one lowercase, and one number
                        </div>
                        <?php if(!empty($password_err)): ?>
                            <div class="error-message">
                                <i class="bi bi-exclamation-circle"></i><?php echo $password_err; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Confirm Password -->
                    <div class="mb-4">
                        <label for="confirm_password" class="form-label required-field">
                            Confirm Password
                            <span class="validation-info">Must match password</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-lock"></i>
                            </span>
                            <input 
                                type="password" 
                                id="confirm_password" 
                                name="confirm_password" 
                                class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" 
                                placeholder="Re-enter your password" 
                                required
                            >
                            <span class="input-group-text password-toggle" id="toggleConfirmPassword">
                                <i class="bi bi-eye"></i>
                            </span>
                        </div>
                        <?php if(!empty($confirm_password_err)): ?>
                            <div class="error-message">
                                <i class="bi bi-exclamation-circle"></i><?php echo $confirm_password_err; ?>
                            </div>
                        <?php endif; ?>
                        <div class="success-message" id="passwordMatch" style="display: none;">
                            <i class="bi bi-check-circle"></i>Passwords match
                        </div>
                        <div class="error-message" id="passwordMismatch" style="display: none;">
                            <i class="bi bi-x-circle"></i>Passwords don't match
                        </div>
                    </div>
                    
                    <!-- Terms and Conditions -->
                    <div class="mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="terms" required>
                            <label class="form-check-label" for="terms" style="font-size: 0.9rem;">
                                I agree to the <a href="#" class="text-decoration-none">Terms of Service</a> and <a href="#" class="text-decoration-none">Privacy Policy</a>
                            </label>
                            <div class="error-message" id="termsError" style="display: none;">
                                <i class="bi bi-exclamation-circle"></i>You must accept the terms and conditions
                            </div>
                        </div>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-success btn-lg w-100" id="submitBtn">
                            <i class="bi bi-person-plus me-2"></i>Create Account
                        </button>
                    </div>
                    
                    <!-- Reset Form -->
                    <div class="d-grid mb-3">
                        <button type="button" class="btn btn-secondary w-100" id="resetForm">
                            <i class="bi bi-arrow-counterclockwise me-2"></i>Reset Form
                        </button>
                    </div>
                    
                    <!-- Back to Login -->
                    <div class="d-grid">
                        <a href="index" class="btn btn-primary w-100">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Back to Login
                        </a>
                    </div>
                </form>
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
    <script src="<?= vnd('js/bootstrap.bundle.min.js') ?>"></script>
    
    <!-- Custom JS with Enhanced Validations -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // DOM Elements
            const passwordField = document.getElementById('password');
            const confirmPasswordField = document.getElementById('confirm_password');
            const togglePassword = document.getElementById('togglePassword');
            const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
            const passwordStrengthBar = document.getElementById('passwordStrengthBar');
            const form = document.getElementById('signupForm');
            const submitBtn = document.getElementById('submitBtn');
            
            // Variables for validation
            let isMobileValid = false;
            let isEmailValid = false;
            let isPasswordValid = false;
            let isConfirmPasswordValid = false;
            
            // Password visibility toggle
            togglePassword.addEventListener('click', function() {
                const icon = this.querySelector('i');
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                } else {
                    passwordField.type = 'password';
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            });
            
            toggleConfirmPassword.addEventListener('click', function() {
                const icon = this.querySelector('i');
                if (confirmPasswordField.type === 'password') {
                    confirmPasswordField.type = 'text';
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                } else {
                    confirmPasswordField.type = 'password';
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            });
            
            // Real-time validation functions
            function validateName(name) {
                const regex = /^[a-zA-Z\s.'-]+$/;
                const isValid = regex.test(name) && name.length >= 2 && name.length <= 50;
                updateValidationUI('name', isValid, name.length > 0);
                return isValid;
            }
            
            function validateCompany(company) {
                const isValid = company.length >= 2 && company.length <= 200;
                updateValidationUI('company', isValid, company.length > 0);
                return isValid;
            }
            
            function validateMobile(mobile) {
                const regex = /^[0-9]{10}$/;
                const isValid = regex.test(mobile);
                isMobileValid = isValid;
                updateValidationUI('mobile', isValid, mobile.length > 0);
                
                if (isValid) {
                    // Show checking message
                    document.getElementById('mobileAvailable').style.display = 'none';
                    document.getElementById('mobileDuplicate').style.display = 'none';
                } else {
                    document.getElementById('mobileAvailable').style.display = 'none';
                    document.getElementById('mobileDuplicate').style.display = 'none';
                }
                
                return isValid;
            }
            
            function validateEmail(email) {
                const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                const isValid = regex.test(email);
                isEmailValid = isValid;
                updateValidationUI('login_id', isValid, email.length > 0);
                
                if (isValid) {
                    // Show checking message
                    document.getElementById('emailAvailable').style.display = 'none';
                    document.getElementById('emailDuplicate').style.display = 'none';
                } else {
                    document.getElementById('emailAvailable').style.display = 'none';
                    document.getElementById('emailDuplicate').style.display = 'none';
                }
                
                return isValid;
            }
            
            function validatePassword(password) {
                const hasMinLength = password.length >= 6;
                const hasUpperCase = /[A-Z]/.test(password);
                const hasLowerCase = /[a-z]/.test(password);
                const hasNumber = /[0-9]/.test(password);
                
                const isValid = hasMinLength && hasUpperCase && hasLowerCase && hasNumber;
                isPasswordValid = isValid;
                updateValidationUI('password', isValid, password.length > 0);
                
                // Update password strength bar
                let strength = 0;
                if (hasMinLength) strength += 25;
                if (hasUpperCase) strength += 25;
                if (hasLowerCase) strength += 25;
                if (hasNumber) strength += 25;
                
                passwordStrengthBar.style.width = strength + '%';
                
                if (strength < 50) {
                    passwordStrengthBar.style.backgroundColor = '#dc3545';
                } else if (strength < 75) {
                    passwordStrengthBar.style.backgroundColor = '#ffc107';
                } else {
                    passwordStrengthBar.style.backgroundColor = '#28a745';
                }
                
                return isValid;
            }
            
            function validateConfirmPassword(confirmPassword) {
                const password = passwordField.value;
                const isValid = confirmPassword === password && confirmPassword.length > 0;
                isConfirmPasswordValid = isValid;
                updateValidationUI('confirm_password', isValid, confirmPassword.length > 0);
                
                if (confirmPassword.length > 0) {
                    if (isValid) {
                        document.getElementById('passwordMatch').style.display = 'flex';
                        document.getElementById('passwordMismatch').style.display = 'none';
                    } else {
                        document.getElementById('passwordMatch').style.display = 'none';
                        document.getElementById('passwordMismatch').style.display = 'flex';
                    }
                } else {
                    document.getElementById('passwordMatch').style.display = 'none';
                    document.getElementById('passwordMismatch').style.display = 'none';
                }
                
                return isValid;
            }
            
            function updateValidationUI(fieldName, isValid, hasValue) {
                const inputElement = document.getElementById(fieldName);
                
                if (hasValue) {
                    if (isValid) {
                        inputElement.classList.remove('is-invalid');
                        inputElement.classList.add('is-valid');
                    } else {
                        inputElement.classList.remove('is-valid');
                        inputElement.classList.add('is-invalid');
                    }
                } else {
                    inputElement.classList.remove('is-valid', 'is-invalid');
                }
            }
            
            // Event listeners for real-time validation
            document.getElementById('name').addEventListener('input', function() {
                validateName(this.value);
            });
            
            document.getElementById('company').addEventListener('input', function() {
                validateCompany(this.value);
            });
            
            document.getElementById('mobile').addEventListener('input', function() {
                // Allow only numbers
                this.value = this.value.replace(/[^0-9]/g, '');
                // Limit to 10 digits
                if (this.value.length > 10) {
                    this.value = this.value.substring(0, 10);
                }
                validateMobile(this.value);
            });
            
            document.getElementById('login_id').addEventListener('input', function() {
                validateEmail(this.value);
            });
            
            passwordField.addEventListener('input', function() {
                validatePassword(this.value);
                // Also validate confirm password if it has value
                if (confirmPasswordField.value.length > 0) {
                    validateConfirmPassword(confirmPasswordField.value);
                }
            });
            
            confirmPasswordField.addEventListener('input', function() {
                validateConfirmPassword(this.value);
            });
            
            // Terms checkbox validation
            document.getElementById('terms').addEventListener('change', function() {
                const termsError = document.getElementById('termsError');
                termsError.style.display = this.checked ? 'none' : 'flex';
            });
            
            // Form submission validation
            form.addEventListener('submit', function(e) {
                // Prevent default only if we need to show errors
                let hasErrors = false;
                
                // Validate all fields
                const nameValid = validateName(document.getElementById('name').value);
                const companyValid = validateCompany(document.getElementById('company').value);
                const mobileValid = validateMobile(document.getElementById('mobile').value);
                const emailValid = validateEmail(document.getElementById('login_id').value);
                const passwordValid = validatePassword(passwordField.value);
                const confirmPasswordValid = validateConfirmPassword(confirmPasswordField.value);
                const termsAccepted = document.getElementById('terms').checked;
                
                // Show terms error if not accepted
                const termsError = document.getElementById('termsError');
                if (!termsAccepted) {
                    termsError.style.display = 'flex';
                    hasErrors = true;
                } else {
                    termsError.style.display = 'none';
                }
                
                // Check if all validations pass
                if (!nameValid || !companyValid || !mobileValid || !emailValid || 
                    !passwordValid || !confirmPasswordValid || !termsAccepted) {
                    hasErrors = true;
                }
                
                if (hasErrors) {
                    e.preventDefault();
                    
                    // Scroll to first error
                    const firstError = document.querySelector('.is-invalid');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        firstError.focus();
                    } else if (!termsAccepted) {
                        document.getElementById('terms').scrollIntoView({ behavior: 'smooth', block: 'center' });
                        document.getElementById('terms').focus();
                    }
                    
                    // Show alert with specific errors
                    let errorMessage = "Please fix the following errors:\n";
                    
                    if (!nameValid) errorMessage += "- Invalid name format (only letters, spaces, and basic punctuation)\n";
                    if (!companyValid) errorMessage += "- Company name must be 2-200 characters\n";
                    if (!mobileValid) errorMessage += "- Mobile must be exactly 10 digits\n";
                    if (!emailValid) errorMessage += "- Invalid email format\n";
                    if (!passwordValid) errorMessage += "- Password must have at least 6 characters with one uppercase, one lowercase, and one number\n";
                    if (!confirmPasswordValid) errorMessage += "- Passwords don't match\n";
                    if (!termsAccepted) errorMessage += "- You must accept terms and conditions\n";
                    
                    alert(errorMessage);
                } else {
                    // Disable submit button to prevent double submission
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spin me-2"></i>Creating Account...';
                    
                    // Allow form to submit normally
                    return true;
                }
            });
            
            // Reset form button
            document.getElementById('resetForm').addEventListener('click', function() {
                form.reset();
                passwordStrengthBar.style.width = '0%';
                
                // Reset all validation states
                ['name', 'company', 'mobile', 'login_id', 'password', 'confirm_password'].forEach(field => {
                    document.getElementById(field).classList.remove('is-valid', 'is-invalid');
                });
                
                // Hide all messages
                document.getElementById('mobileAvailable').style.display = 'none';
                document.getElementById('mobileDuplicate').style.display = 'none';
                document.getElementById('emailAvailable').style.display = 'none';
                document.getElementById('emailDuplicate').style.display = 'none';
                document.getElementById('passwordMatch').style.display = 'none';
                document.getElementById('passwordMismatch').style.display = 'none';
                document.getElementById('termsError').style.display = 'none';
                
                // Reset validation variables
                isMobileValid = false;
                isEmailValid = false;
                isPasswordValid = false;
                isConfirmPasswordValid = false;
                
                // Reset submit button
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-person-plus me-2"></i>Create Account';
                
                // Focus on first field
                document.getElementById('company').focus();
            });
            
            // Initial focus
            document.getElementById('company').focus();
        });
    </script>
</body>
</html>
