<?php
error_reporting(E_ALL);

require_once "config.php";

ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
ensureCsrfToken();

$username = $password = $CompanyName = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $ErrorMessage = "Error: Invalid session token. Please try again.";
    } else {

	$CompanyName = trim($_POST["CompanyName"]);
	$username = trim($_POST["username"]);
	$password = trim($_POST["password"]);

	if(!empty($username) && !empty($password) && !empty($CompanyName)){
		
        $sql = "SELECT ID, NAME, MOBILE, COMPANY, PACKAGE, STATUS, JOIN_DATE, ROLE, TEAM_ID, PASSWORD, LOGIN_ID, DEVICE_ID FROM USERS WHERE LOGIN_ID = ?";        
			if($stmt = mysqli_prepare($link, $sql)){
				mysqli_stmt_bind_param($stmt, "s", $param_username);
				$param_username = $username;
				if(mysqli_stmt_execute($stmt)){
					mysqli_stmt_store_result($stmt);
					if(mysqli_stmt_num_rows($stmt) == 1){                    
						mysqli_stmt_bind_result($stmt, $id, $name, $mobile, $company, $package, $status, $join_date, $role, $team, $hashed_password, $login_id, $device_id);
						if(mysqli_stmt_fetch($stmt)){
							if($company == $CompanyName && $status == 'Active'){
							if(password_verify($password, $hashed_password)){
								session_regenerate_id(true);
								// Save all user information in session
								$_SESSION["loggedin"] = true;
								$_SESSION["id"] = $id;
								$_SESSION["name"] = $name;
								$_SESSION["mobile"] = $mobile;
								$_SESSION["company"] = $company;
								$_SESSION["package"] = $package;
								$_SESSION["status"] = $status;
								$_SESSION["join_date"] = $join_date;
								$_SESSION["role"] = $role;
								$_SESSION["team_id"] = $team;
								$_SESSION["login_id"] = $login_id;
								$_SESSION["device_id"] = $device_id;
								// Update last activity time stamp
                                $_SESSION['LAST_ACTIVITY'] = time();
								
								header("location: dashboard.php");
								exit();
							}
							else {$ErrorMessage = "Error: The password you entered is not valid."; }
							}
							else {$ErrorMessage = "Error: The Company Name is not valid OR your account is not active";}
						}
					}
					else {$ErrorMessage = "Error: The User Name you entered is not valid."; }
				} 
				else{ $ErrorMessage = "Oops! Something went wrong. Please try again later."; }
				
			mysqli_stmt_close($stmt);
		}
	}
	mysqli_close($link);
    }
}

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= ($_SESSION['theme'] ?? 'light') === 'dark' ? 'dark' : 'light' ?>">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<title>CallNow | Professional Communication Platform</title>
		
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
			.login-header h1 { color: #fff; font-size: 1.25rem; margin: 0.5rem 0 0; }
			.login-header .logo-container {
				max-height: 60px;
				width: auto;
				max-width: 200px;
				object-fit: contain;
			}
			.links-section a { font-weight: 500; transition: color 0.2s; }
			.links-section a:hover { text-decoration: underline; }
			.password-toggle { cursor: pointer; transition: color 0.2s; }
		</style>
	</head>
	
	<body>
		<div class="login-shell">
			<div class="login-card">
				<!-- Header -->
				<div class="login-header">
					<img src="/assets/iclauncher.png" alt="CallNow" class="logo-container">
					<h1>CallNow V5.00</h1>
				</div>
				
				<!-- Login Form -->
				<div class="p-4">
					<form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
						<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
						<!-- Company Name -->
						<div class="mb-3">
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
									value="<?php echo htmlspecialchars($CompanyName); ?>"
								>
							</div>
						</div>
						
						<!-- Username -->
						<div class="mb-3">
							<label for="username" class="form-label">Username / Email</label>
							<div class="input-group">
							<span class="input-group-text">
								<i class="bi bi-person"></i>
							</span>
								<input 
									type="text" 
									id="username" 
									class="form-control" 
									name="username" 
									placeholder="Enter your registered email" 
									required
									value="<?php echo htmlspecialchars($username); ?>"
								>
							</div>
						</div>
						
						<!-- Password -->
						<div class="mb-4">
							<label for="password" class="form-label">Password</label>
							<div class="input-group">
							<span class="input-group-text">
								<i class="bi bi-lock"></i>
							</span>
								<input 
									type="password" 
									id="password" 
									class="form-control" 
									name="password" 
									placeholder="Enter your password" 
									required
								>
								<span class="input-group-text password-toggle" id="togglePassword">
									<i class="bi bi-eye"></i>
								</span>
							</div>
							<div class="form-check mt-2">
								<input class="form-check-input" type="checkbox" id="showPassword">
								<label class="form-check-label" for="showPassword" style="font-size: 0.85rem;">
									Show Password
								</label>
							</div>
						</div>
						
						<!-- Error Message -->
						<?php if(isset($ErrorMessage)): ?>
						<div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
							<i class="bi bi-exclamation-circle me-2"></i>
							<?php echo $ErrorMessage; ?>
							<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
						</div>
						<?php endif; ?>
						
						<!-- Submit Button -->
						<div class="d-grid mb-4">
							<button type="submit" class="btn btn-primary btn-lg w-100">
								<i class="bi bi-box-arrow-in-right me-2"></i>Log In
							</button>
						</div>
						
						<!-- Links -->
						<div class="links-section text-center">
							<div class="mb-2">
								<a href="forgot-password.php">
									<i class="bi bi-key me-1"></i>Forgot Password?
								</a>
							</div>
							<div>
								<p class="mb-1">Don't have an account?</p>
								<a href="CallNowSignUp.php" class="fw-bold">
									<i class="bi bi-person-plus me-1"></i>Sign Up Now
								</a>
							</div>
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
				<p class="mb-0 small mt-1">Callnow V5.00</p>
			</div>
		</footer>
		
		<!-- Bootstrap JS Bundle with Popper -->
		<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
		
		<!-- Custom JS -->
		<script>
			// Toggle password visibility
			document.getElementById('showPassword').addEventListener('change', function() {
				const passwordField = document.getElementById('password');
				const toggleIcon = document.getElementById('togglePassword').querySelector('i');
				
				if (this.checked) {
					passwordField.type = 'text';
					toggleIcon.classList.remove('bi-eye');
					toggleIcon.classList.add('bi-eye-slash');
				} else {
					passwordField.type = 'password';
					toggleIcon.classList.remove('bi-eye-slash');
					toggleIcon.classList.add('bi-eye');
				}
			});
			
			// Also toggle with the eye icon
			document.getElementById('togglePassword').addEventListener('click', function() {
				const passwordField = document.getElementById('password');
				const toggleIcon = this.querySelector('i');
				const showPasswordCheckbox = document.getElementById('showPassword');
				
				if (passwordField.type === 'password') {
					passwordField.type = 'text';
					toggleIcon.classList.remove('bi-eye');
					toggleIcon.classList.add('bi-eye-slash');
					showPasswordCheckbox.checked = true;
				} else {
					passwordField.type = 'password';
					toggleIcon.classList.remove('bi-eye-slash');
					toggleIcon.classList.add('bi-eye');
					showPasswordCheckbox.checked = false;
				}
			});
			
			// Auto-hide alert after 5 seconds
			document.addEventListener('DOMContentLoaded', function() {
				const alert = document.querySelector('.alert');
				if (alert) {
					setTimeout(function() {
						const bsAlert = new bootstrap.Alert(alert);
						bsAlert.close();
					}, 5000);
				}
			});
		</script>
	</body>
</html>
