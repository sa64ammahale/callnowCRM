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
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>My Profile • CallNow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="assets/css/app-theme.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <style>
        :root {
            --grad-start: #667eea;
            --grad-end: #764ba2;
            --surface: #ffffff;
            --surface-glass: rgba(255, 255, 255, 0.92);
            --text-main: #1e293b;
            --text-muted: #64748b;
            --accent: #f59e0b;
            --danger: #ef4444;
            --success: #10b981;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Poppins', system-ui, sans-serif;
            background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 50%, #f3e8ff 100%);
            min-height: 100vh;
            color: var(--text-main);
            overflow-x: hidden;
        }

        /* ── animated blobs ── */
        .blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.35;
            z-index: 0;
            pointer-events: none;
            animation: float 18s ease-in-out infinite alternate;
        }
        .blob-1 { width: 520px; height: 520px; background: var(--grad-start); top: -120px; left: -120px; }
        .blob-2 { width: 400px; height: 400px; background: var(--grad-end); bottom: -80px; right: -80px; animation-delay: -6s; }
        .blob-3 { width: 300px; height: 300px; background: #818cf8; top: 50%; left: 60%; animation-delay: -12s; }

        @keyframes float {
            0%   { transform: translate(0, 0) scale(1); }
            50%  { transform: translate(40px, -30px) scale(1.08); }
            100% { transform: translate(-20px, 20px) scale(0.94); }
        }

        .page-wrapper { position: relative; z-index: 1; }

        /* ── hero card ── */
        .hero-card {
            background: linear-gradient(135deg, var(--grad-start), var(--grad-end));
            border-radius: 2rem;
            padding: 2.8rem 2.4rem;
            color: white;
            box-shadow: 0 30px 60px rgba(102, 126, 234, 0.35);
            position: relative;
            overflow: hidden;
        }
        .hero-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 80% 20%, rgba(255,255,255,0.2) 0%, transparent 50%),
                radial-gradient(circle at 20% 80%, rgba(255,255,255,0.12) 0%, transparent 45%);
        }
        .hero-card > * { position: relative; z-index: 1; }

        .avatar-ring {
            width: 110px; height: 110px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            display: flex; align-items: center; justify-content: center;
            font-size: 2.6rem; font-weight: 700;
            color: white;
            border: 4px solid rgba(255,255,255,0.5);
            box-shadow: 0 12px 30px rgba(0,0,0,0.18);
            flex-shrink: 0;
            user-select: none;
        }

        .role-badge {
            display: inline-flex;
            align-items: center; gap: 0.4rem;
            background: rgba(255,255,255,0.22);
            backdrop-filter: blur(10px);
            padding: 0.4rem 1rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            border: 1px solid rgba(255,255,255,0.3);
        }

        /* ── info grid ── */
        .info-card {
            background: var(--surface-glass);
            backdrop-filter: blur(18px);
            border-radius: 1.4rem;
            padding: 1.4rem 1.6rem;
            border: 1px solid rgba(255,255,255,0.6);
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .info-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 40px rgba(102, 126, 234, 0.18);
        }
        .info-icon {
            width: 44px; height: 44px;
            border-radius: 0.9rem;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
            color: white;
            flex-shrink: 0;
        }
        .info-label {
            font-size: 0.78rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 0.15rem;
        }
        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-main);
            word-break: break-all;
        }

        /* ── edit form card ── */
        .form-card {
            background: var(--surface-glass);
            backdrop-filter: blur(18px);
            border-radius: 1.6rem;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.07);
            border: 1px solid rgba(255,255,255,0.65);
            overflow: hidden;
        }
        .form-card .card-header-custom {
            background: linear-gradient(135deg, var(--grad-start), var(--grad-end));
            color: white;
            padding: 1.4rem 2rem;
            display: flex; align-items: center; gap: 0.8rem;
        }
        .form-card .card-header-custom h3 {
            margin: 0; font-size: 1.25rem; font-weight: 700;
        }
        .form-card .card-body-custom { padding: 2rem; }

        .form-control-custom, .form-select-custom {
            border: 1.5px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 0.7rem 1rem;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background: #f8fafc;
            font-family: 'Poppins', sans-serif;
        }
        .form-control-custom:focus, .form-select-custom:focus {
            border-color: var(--grad-start);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.12);
            background: white;
            outline: none;
        }
        .form-label-custom {
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 0.35rem;
        }

        /* ── buttons ── */
        .btn-energy {
            background: linear-gradient(135deg, var(--grad-start), var(--grad-end));
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 999px;
            font-weight: 700;
            letter-spacing: 0.3px;
            font-size: 0.95rem;
            transition: all 0.25s ease;
            box-shadow: 0 8px 22px rgba(102, 126, 234, 0.3);
        }
        .btn-energy:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 30px rgba(102, 126, 234, 0.45);
            color: white;
        }
        .btn-energy:active { transform: translateY(-1px); }
        .btn-energy-outline {
            background: transparent;
            color: var(--grad-start);
            border: 2px solid var(--grad-start);
            padding: 0.7rem 1.8rem;
            border-radius: 999px;
            font-weight: 600;
            transition: all 0.25s ease;
        }
        .btn-energy-outline:hover {
            background: var(--grad-start);
            color: white;
            box-shadow: 0 8px 22px rgba(102, 126, 234, 0.3);
        }

        /* ── alert ── */
        .alert-custom {
            border-radius: 1rem;
            border: none;
            font-weight: 500;
            box-shadow: 0 4px 14px rgba(0,0,0,0.06);
            animation: slideDown 0.35s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── section title ── */
        .section-title {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--text-main);
            margin-bottom: 1.2rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .section-title .icon-circle-sm {
            width: 34px; height: 34px;
            border-radius: 0.6rem;
            background: linear-gradient(135deg, var(--grad-start), var(--grad-end));
            color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.95rem;
        }

        /* ── password toggle ── */
        .password-wrapper { position: relative; }
        .password-wrapper .toggle-btn {
            position: absolute;
            right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 0.95rem;
            transition: color 0.2s;
        }
        .password-wrapper .toggle-btn:hover { color: var(--grad-start); }

        /* ── footer spacing ── */
        .py-footer { padding-bottom: 7rem; }

        @media (max-width: 576px) {
            .hero-card { padding: 1.8rem 1.2rem; }
            .avatar-ring { width: 80px; height: 80px; font-size: 2rem; }
            .form-card .card-body-custom { padding: 1.2rem; }
        }
    </style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>
<div class="blob blob-3"></div>

<div class="page-wrapper">
<?php include 'php_scripts/header.php'; ?>

<div class="container py-5 py-footer">
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
                                       class="form-control form-control-custom"
                                       id="name" name="name"
                                       value="<?= htmlspecialchars($profile['NAME']) ?>"
                                       required />
                            </div>

                            <div class="col-md-6">
                                <label class="form-label-custom" for="mobile">
                                    <i class="bi bi-phone me-1"></i>Mobile Number
                                </label>
                                <input type="text" maxlength="10"
                                       class="form-control form-control-custom"
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
                                       class="form-control form-control-custom"
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
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
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
</body>
</html>
