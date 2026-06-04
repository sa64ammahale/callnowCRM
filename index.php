<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "config.php";

$username = $password = $CompanyName = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){
	
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
								session_start();
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

?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<title>CallNow | Professional Communication Platform</title>
		
		<!-- Bootstrap 5 CSS -->
		<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
		
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
			}
			
			body {
				font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
				background: linear-gradient(135deg, #f5f7ff 0%, #eef1ff 100%);
				min-height: 100vh;
				display: flex;
				flex-direction: column;
			}
			
			.login-container {
				display: flex;
				align-items: center;
				justify-content: center;
				flex: 1;
				padding: 2rem 1rem;
			}
			
			.login-card {
				background-color: white;
				border-radius: 16px;
				box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
				overflow: hidden;
				width: 100%;
				max-width: 420px;
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
				font-size: 2.5rem;
				margin-bottom: 0.5rem;
				color: white;
			}
			
			.brand-title {
				font-weight: 700;
				font-size: 1.8rem;
				letter-spacing: 0.5px;
			}
			
			.brand-subtitle {
				font-size: 0.9rem;
				opacity: 0.9;
				margin-top: 0.25rem;
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
			
			.btn-login {
				background: linear-gradient(to right, var(--primary-color), #3a56d9);
				border: none;
				padding: 0.75rem;
				font-weight: 600;
				letter-spacing: 0.5px;
				transition: all 0.3s;
			}
			
			.btn-login:hover {
				background: linear-gradient(to right, #3a56d9, #2a46c9);
				transform: translateY(-2px);
				box-shadow: 0 5px 15px rgba(74, 107, 255, 0.3);
			}
			
			.links-section a {
				color: var(--primary-color);
				text-decoration: none;
				font-weight: 500;
				transition: color 0.2s;
			}
			
			.links-section a:hover {
				color: #2a46c9;
				text-decoration: underline;
			}
			
			.alert-danger {
				background-color: rgba(220, 53, 69, 0.1);
				border: none;
				border-left: 4px solid #dc3545;
				border-radius: 4px;
				font-size: 0.9rem;
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
			
			.password-toggle {
				cursor: pointer;
				color: var(--secondary-color);
				transition: color 0.2s;
			}
			
			.password-toggle:hover {
				color: var(--primary-color);
			}
			
			.form-check-input:checked {
				background-color: var(--primary-color);
				border-color: var(--primary-color);
			}
			
			@media (max-width: 576px) {
				.login-card {
					box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
				}
				
				.card-header {
					padding: 1.5rem 1rem;
				}
				
				.card-body {
					padding: 1.5rem 1rem;
				}
			}
			
			/* Add this to your existing CSS */
.card-header {
    padding: 1rem 1.25rem !important; /* From 2rem 1.5rem */
}


.brand-title {
    font-size: 1.5rem !important; /* From 1.8rem */
}

.login-container {
    padding: 1.5rem 1rem !important; /* Reduce container padding */
}


    /* Add this to your CSS */
    .logo-only-container {
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 5px 0;
    }
    
    .full-logo {
        max-height: 80px;
        width: auto;
        max-width: 250px;
        object-fit: contain;
    }
			
		</style>
	</head>
	
	<body>
		<div class="login-container">
			<div class="login-card">
				<!-- Header -->
				<div class="card-header compact-header">
                    <div class="logo-only-container">
                        <img src="/assets/iclauncher.png" alt="CallNow" class="full-logo">
                    </div>
					<h1 class="brand-title">CallNow V5.00</h1>
				</div>
				
				<!-- Login Form -->
				<div class="card-body">
					<form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
						<!-- Company Name -->
						<div class="mb-3">
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
									value="<?php echo htmlspecialchars($CompanyName); ?>"
								>
							</div>
						</div>
						
						<!-- Username -->
						<div class="mb-3">
							<label for="username" class="form-label">Username / Email</label>
							<div class="input-group">
								<span class="input-group-text">
									<i class="fas fa-user"></i>
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
									<i class="fas fa-lock"></i>
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
									<i class="fas fa-eye"></i>
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
							<i class="fas fa-exclamation-circle me-2"></i>
							<?php echo $ErrorMessage; ?>
							<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
						</div>
						<?php endif; ?>
						
						<!-- Submit Button -->
						<div class="d-grid mb-4">
							<button type="submit" class="btn btn-login btn-lg text-white">
								<i class="fas fa-sign-in-alt me-2"></i>Log In
							</button>
						</div>
						
						<!-- Links -->
						<div class="links-section text-center">
							<div class="mb-2">
								<a href="forgot-password.php">
									<i class="fas fa-key me-1"></i>Forgot Password?
								</a>
							</div>
							<div>
								<p class="mb-1">Don't have an account?</p>
								<a href="CallNowSignUp.php" class="fw-bold">
									<i class="fas fa-user-plus me-1"></i>Sign Up Now
								</a>
							</div>
						</div>
					</form>
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
				<p class="mb-0 small mt-1">Callnow V5.00</p>
			</div>
		</footer>
		
		<!-- Bootstrap JS Bundle with Popper -->
		<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
		
		<!-- Custom JS -->
		<script>
			// Toggle password visibility
			document.getElementById('showPassword').addEventListener('change', function() {
				const passwordField = document.getElementById('password');
				const toggleIcon = document.getElementById('togglePassword').querySelector('i');
				
				if (this.checked) {
					passwordField.type = 'text';
					toggleIcon.classList.remove('fa-eye');
					toggleIcon.classList.add('fa-eye-slash');
				} else {
					passwordField.type = 'password';
					toggleIcon.classList.remove('fa-eye-slash');
					toggleIcon.classList.add('fa-eye');
				}
			});
			
			// Also toggle with the eye icon
			document.getElementById('togglePassword').addEventListener('click', function() {
				const passwordField = document.getElementById('password');
				const toggleIcon = this.querySelector('i');
				const showPasswordCheckbox = document.getElementById('showPassword');
				
				if (passwordField.type === 'password') {
					passwordField.type = 'text';
					toggleIcon.classList.remove('fa-eye');
					toggleIcon.classList.add('fa-eye-slash');
					showPasswordCheckbox.checked = true;
				} else {
					passwordField.type = 'password';
					toggleIcon.classList.remove('fa-eye-slash');
					toggleIcon.classList.add('fa-eye');
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