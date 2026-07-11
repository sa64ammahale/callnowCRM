<?php
require_once "php_scripts/auth.php";

$Message = $type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $Message = "Error: Invalid session token. Please try again.";
        $type = "danger";
    } else {
    $name     = trim($_POST['name'] ?? '');
    $mobile   = trim($_POST['mobile'] ?? '');
    $login_id = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!preg_match("/^[a-zA-Z ]+$/", $name)) {
        $Message = "Name must contain only letters and spaces.";
        $type = "danger";
    } elseif (!preg_match("/^[6-9][0-9]{9}$/", $mobile)) {
        $Message = "Enter a valid 10-digit mobile number starting with 6-9.";
        $type = "danger";
    } elseif (!filter_var($login_id, FILTER_VALIDATE_EMAIL)) {
        $Message = "Enter a valid email address.";
        $type = "danger";
    } elseif ($password !== '' && strlen($password) < 6) {
        $Message = "New password must be at least 6 characters.";
        $type = "danger";
    } elseif ($password !== '' && $password !== $confirm) {
        $Message = "Passwords do not match.";
        $type = "danger";
    } else {
        $duplicateSql = "SELECT ID FROM USERS WHERE (LOGIN_ID = ? OR MOBILE = ?) AND ID <> ? LIMIT 1";
        $duplicateStmt = mysqli_prepare($link, $duplicateSql);
        if (!$duplicateStmt) {
            $Message = "Database error: " . mysqli_error($link);
            $type = "danger";
        } else {
            mysqli_stmt_bind_param($duplicateStmt, "ssi", $login_id, $mobile, USER_ID);
            mysqli_stmt_execute($duplicateStmt);
            mysqli_stmt_store_result($duplicateStmt);

            if (mysqli_stmt_num_rows($duplicateStmt) > 0) {
                $Message = "That email or mobile number is already in use by another account.";
                $type = "danger";
                mysqli_stmt_close($duplicateStmt);
            } else {
                mysqli_stmt_close($duplicateStmt);

                $updateFields = ["NAME = ?", "MOBILE = ?", "LOGIN_ID = ?"];
                $params = [$name, $mobile, $login_id];
                $types  = "sss";

                if ($password !== '') {
                    $updateFields[] = "PASSWORD = ?";
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                    $types  .= "s";
                }

                $params[] = USER_ID;
                $types    .= "i";

                $sql = "UPDATE USERS SET " . implode(", ", $updateFields) . " WHERE ID = ?";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, $types, ...$params);

                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION['name']     = $name;
                    $_SESSION['mobile']   = $mobile;
                    $_SESSION['login_id'] = $login_id;
                    $Message = "Profile updated successfully!";
                    $type = "success";
                    logActivity($link, USER_ID, "UPDATE", "Updated own profile", (string)USER_ID, "USERS");
                } else {
                    $Message = "Database error: " . mysqli_error($link);
                    $type = "danger";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
    mysqli_close($link);
    }
}

$profile = [
    'NAME'     => $_SESSION['name']     ?? '',
    'MOBILE'   => $_SESSION['mobile']   ?? '',
    'LOGIN_ID' => $_SESSION['login_id'] ?? '',
    'ROLE'     => $_SESSION['role']     ?? '',
    'TEAM_ID'  => $_SESSION['team_id']  ?? '',
    'COMPANY'  => $_SESSION['company']  ?? '',
    'JOIN_DATE'=> $_SESSION['join_date']?? '',
];
?>
<?php $pageTitle = 'My Profile'; ?>
<?php include 'php_scripts/header.php'; ?>

<style>
    .hero-card { background: var(--accent); color: #fff; border-radius: var(--radius-xl); padding: 2rem; box-shadow: var(--shadow-md); position: relative; overflow: hidden; }
    .hero-card > * { position: relative; z-index: 1; }
    .avatar-ring { width: 90px; height: 90px; border-radius: 50%; background: rgba(255,255,255,0.25); display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 700; color: #fff; border: 3px solid rgba(255,255,255,0.5); flex-shrink: 0; }
    .role-badge { display: inline-flex; align-items: center; gap: 0.375rem; background: rgba(255,255,255,0.2); padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; border: 1px solid rgba(255,255,255,0.3); }
    .info-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1rem 1.125rem; box-shadow: var(--shadow); transition: border-color 0.12s ease, box-shadow 0.12s ease; }
    .info-card:hover { border-color: var(--border-strong); box-shadow: var(--shadow-md); }
    .info-icon { width: 40px; height: 40px; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1rem; color: #fff; flex-shrink: 0; }
    .info-label { font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-muted); margin-bottom: 0.125rem; }
    .info-value { font-size: 0.875rem; font-weight: 600; color: var(--ink); word-break: break-all; }
    .form-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-xl); box-shadow: var(--shadow); overflow: hidden; }
    .form-card .card-header-custom { background: var(--accent); color: #fff; padding: 1.25rem 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
    .form-card .card-header-custom h3 { margin: 0; font-size: 1.125rem; font-weight: 600; }
    .form-card .card-body-custom { padding: 1.5rem; }
    .form-label-custom { font-size: 0.75rem; font-weight: 500; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.3125rem; }
    .password-wrapper { position: relative; }
    .password-wrapper .toggle-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--ink-muted); cursor: pointer; font-size: 0.9375rem; transition: color 0.12s; }
    .password-wrapper .toggle-btn:hover { color: var(--accent); }
    @media (max-width: 576px) { .hero-card { padding: 1.5rem; } .avatar-ring { width: 72px; height: 72px; font-size: 1.75rem; } }
</style>

<div class="container py-4">
    <div class="row g-4">

        <!-- Left column: Profile overview -->
        <div class="col-lg-4">
            <!-- Hero / Identity card -->
            <div class="hero-card mb-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="avatar-ring">
                        <?= strtoupper(mb_substr($profile['NAME'], 0, 1)) ?>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-1"><?= htmlspecialchars($profile['NAME']) ?></h4>
                        <span class="role-badge">
                            <i class="bi bi-shield-check"></i>
                            <?= htmlspecialchars($profile['ROLE']) ?>
                        </span>
                    </div>
                </div>
                <p class="mb-2 opacity-90 small">
                    <i class="bi bi-building me-2"></i><?= htmlspecialchars($profile['COMPANY']) ?>
                </p>
                <p class="mb-0 opacity-75 small">
                    <i class="bi bi-calendar3 me-2"></i>Joined
                    <?= htmlspecialchars(date('d M Y', strtotime($profile['JOIN_DATE']))) ?>
                </p>
            </div>

            <!-- Quick info cards -->
            <div class="info-card mb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="info-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                        <i class="bi bi-envelope"></i>
                    </div>
                    <div>
                        <div class="info-label">Email</div>
                        <div class="info-value"><?= htmlspecialchars($profile['LOGIN_ID']) ?></div>
                    </div>
                </div>
            </div>

            <div class="info-card mb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="info-icon" style="background: linear-gradient(135deg, #10b981, #34d399);">
                        <i class="bi bi-phone"></i>
                    </div>
                    <div>
                        <div class="info-label">Mobile</div>
                        <div class="info-value">+91 <?= htmlspecialchars($profile['MOBILE']) ?></div>
                    </div>
                </div>
            </div>

            <div class="info-card mb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="info-icon" style="background: linear-gradient(135deg, #f59e0b, #f97316);">
                        <i class="bi bi-people"></i>
                    </div>
                    <div>
                        <div class="info-label">Team ID</div>
                        <div class="info-value"><?= $profile['TEAM_ID'] ?? 'Not assigned' ?></div>
                    </div>
                </div>
            </div>

            <div class="info-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="info-icon" style="background: linear-gradient(135deg, #ef4444, #f97316);">
                        <i class="bi bi-fingerprint"></i>
                    </div>
                    <div>
                        <div class="info-label">User ID</div>
                        <div class="info-value">#<?= USER_ID ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right column: Edit form -->
        <div class="col-lg-8">
            <div class="form-card">
                <div class="card-header-custom">
                    <i class="bi bi-pencil-square fs-3"></i>
                    <div>
                        <h3>Edit Profile</h3>
                        <small class="opacity-75">Update your personal information</small>
                    </div>
                </div>
                <div class="card-body-custom">

                    <?php if ($Message): ?>
                        <div class="alert alert-<?= htmlspecialchars($type ?: 'info') ?> alert-custom mb-4 d-flex align-items-center gap-2"
                             role="alert">
                            <i class="bi <?= $type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-5"></i>
                            <div><?= htmlspecialchars($Message) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="form-label-custom" for="name">
                                    <i class="bi bi-person me-1"></i>Full Name
                                </label>
                                <input type="text"
                                       class="form-control"
                                       id="name" name="name"
                                       value="<?= htmlspecialchars($profile['NAME']) ?>"
                                       required />
                            </div>

                            <div class="col-md-6">
                                <label class="form-label-custom" for="mobile">
                                    <i class="bi bi-phone me-1"></i>Mobile Number
                                </label>
                                <input type="text" maxlength="10"
                                       class="form-control"
                                       id="mobile" name="mobile"
                                       pattern="[6-9][0-9]{9}"
                                       value="<?= htmlspecialchars($profile['MOBILE']) ?>"
                                       required />
                            </div>

                            <div class="col-md-6">
                                <label class="form-label-custom" for="login_id">
                                    <i class="bi bi-envelope me-1"></i>Email (Login ID)
                                </label>
                                <input type="email"
                                       class="form-control"
                                       id="login_id" name="login_id"
                                       value="<?= htmlspecialchars($profile['LOGIN_ID']) ?>"
                                       required />
                            </div>

                            <div class="col-md-6">
                                <label class="form-label-custom" for="role_display">
                                    <i class="bi bi-shield me-1"></i>Role
                                </label>
                                <input type="text" readonly
                                       class="form-control form-control-custom bg-light"
                                       id="role_display"
                                       value="<?= htmlspecialchars($profile['ROLE']) ?>" />
                                <small class="text-muted">Role cannot be changed here.</small>
                            </div>

                            <hr class="my-3 text-muted opacity-25" />

                            <div class="col-12">
                                <p class="form-label-custom mb-2">
                                    <i class="bi bi-lock me-1"></i>Change Password
                                    <span class="text-muted fw-normal">(leave blank to keep current)</span>
                                </p>
                            </div>

                            <div class="col-md-6">
                                <div class="password-wrapper">
                                    <label class="form-label-custom" for="password">New Password</label>
                                    <input type="password" minlength="6"
                                           class="form-control form-control-custom pe-5"
                                           id="password" name="password"
                                           placeholder="Minimum 6 characters" />
                                    <button type="button" class="toggle-btn"
                                            data-target="password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="password-wrapper">
                                    <label class="form-label-custom" for="confirm_password">Confirm Password</label>
                                    <input type="password" minlength="6"
                                           class="form-control form-control-custom pe-5"
                                           id="confirm_password" name="confirm_password"
                                           placeholder="Re-type new password" />
                                    <button type="button" class="toggle-btn"
                                            data-target="confirm_password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-12 mt-2 d-flex flex-wrap gap-2">
                                <button type="submit" name="update_profile"
                                        class="btn btn-energy">
                                    <i class="bi bi-check2-circle me-2"></i>Save Changes
                                </button>
                                <a href="dashboard.php"
                                   class="btn btn-energy-outline">
                                    <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                                </a>
                            </div>

                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include 'php_scripts/footer.php'; ?>

<script>
    document.querySelectorAll('.toggle-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = document.getElementById(btn.dataset.target);
            const icon   = btn.querySelector('i');
            const isPassword = target.type === 'password';
            target.type = isPassword ? 'text' : 'password';
            icon.classList.toggle('bi-eye', !isPassword);
            icon.classList.toggle('bi-eye-slash', isPassword);
        });
    });
</script>
