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
        $duplicateSql = "SELECT ID FROM " . tn('TBL_USERS') . " WHERE (LOGIN_ID = ? OR MOBILE = ?) AND ID <> ? LIMIT 1";
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

                // Handle profile photo removal
                if (!empty($_POST['remove_photo'])) {
                    $oldPhoto = $profile['profile_photo'] ?? '';
                    if ($oldPhoto && file_exists(__DIR__ . '/uploads/profile_photos/' . $oldPhoto)) {
                        @unlink(__DIR__ . '/uploads/profile_photos/' . $oldPhoto);
                    }
                    $updateFields[] = "profile_photo = NULL";
                }

                // Handle profile photo upload
                $newName = '';
                if (!empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['profile_photo'];
                    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
                    $maxSize = 2 * 1024 * 1024; // 2MB

                    if (!in_array($file['type'], $allowed, true)) {
                        $Message = "Invalid image format. Use JPG, PNG, WebP or GIF.";
                        $type = "danger";
                    } elseif ($file['size'] > $maxSize) {
                        $Message = "Image must be under 2MB.";
                        $type = "danger";
                    } else {
                        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $newName = 'user_' . USER_ID . '_' . time() . '.' . $ext;
                        $dest = __DIR__ . '/uploads/profile_photos/' . $newName;

                        if (move_uploaded_file($file['tmp_name'], $dest)) {
                            $updateFields[] = "profile_photo = ?";
                            $params[] = $newName;
                            $types  .= "s";

                            // Delete old photo if exists
                            $oldPhoto = $profile['profile_photo'] ?? '';
                            if ($oldPhoto && file_exists(__DIR__ . '/uploads/profile_photos/' . $oldPhoto)) {
                                @unlink(__DIR__ . '/uploads/profile_photos/' . $oldPhoto);
                            }
                        } else {
                            $Message = "Failed to upload image.";
                            $type = "danger";
                        }
                    }
                }

                if ($Message === '') {
                    $params[] = USER_ID;
                    $types    .= "i";

                    $sql = "UPDATE " . tn('TBL_USERS') . " SET " . implode(", ", $updateFields) . " WHERE ID = ?";
                    $stmt = mysqli_prepare($link, $sql);
                    mysqli_stmt_bind_param($stmt, $types, ...$params);

                    if (mysqli_stmt_execute($stmt)) {
                        $_SESSION['name']     = $name;
                        $_SESSION['mobile']   = $mobile;
                        $_SESSION['login_id'] = $login_id;
                        if (!empty($newName)) {
                            $_SESSION['profile_photo'] = $newName;
                        } elseif (!empty($_POST['remove_photo'])) {
                            $_SESSION['profile_photo'] = '';
                        }
                        $Message = "Profile updated successfully!";
                        $type = "success";
                        logActivity($link, USER_ID, "UPDATE", "Updated own profile", (string)USER_ID, "TBL_USERS");
                    } else {
                        $Message = "Database error: " . mysqli_error($link);
                        $type = "danger";
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
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
    'profile_photo' => $_SESSION['profile_photo'] ?? '',
];
?>
<?php $pageTitle = 'My Profile'; ?>
<?php include 'php_scripts/header.php'; ?>

<div class="crm-module-header">
    <div>
        <div class="crm-module-header-title">
            <i class="bi bi-person-badge text-primary"></i>
            <h1>Account Profile</h1>
        </div>
        <p class="crm-module-header-desc">Manage your identity, personal contact details and account security</p>
    </div>
    <div class="crm-module-header-actions">
        <a href="<?= url('dashboard.php') ?>" class="btn btn-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>
    </div>
</div>

<?php if ($Message): ?>
    <div class="alert alert-<?= htmlspecialchars($type ?: 'info') ?> alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
        <i class="bi <?= $type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
        <div><?= htmlspecialchars($Message) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-3">
    <!-- Left column: Profile Summary Card -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-body text-center p-4">
                <div class="mx-auto mb-3" style="width: 80px; height: 80px; border-radius: 50%; overflow: hidden; border: 2px solid var(--border); background: var(--primary-soft); display: flex; align-items: center; justify-content: center;">
                    <?php if (!empty($profile['profile_photo']) && file_exists(__DIR__ . '/uploads/profile_photos/' . $profile['profile_photo'])): ?>
                        <img src="<?= htmlspecialchars((defined('APP_BASE') ? APP_BASE : '') . '/uploads/profile_photos/' . $profile['profile_photo']) ?>" 
                             alt="Profile" 
                             style="width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        <span class="fs-2 fw-bold text-primary"><?= strtoupper(mb_substr($profile['NAME'], 0, 1)) ?></span>
                    <?php endif; ?>
                </div>
                <h2 class="fs-5 fw-bold mb-1 text-dark"><?= htmlspecialchars($profile['NAME']) ?></h2>
                <div class="mb-2">
                    <span class="badge bg-primary"><?= htmlspecialchars($profile['ROLE']) ?></span>
                </div>
                <div class="text-muted small mb-1">
                    <i class="bi bi-building me-1"></i><?= htmlspecialchars($profile['COMPANY'] ?: 'CallNow Telecalling') ?>
                </div>
                <div class="text-muted small">
                    <i class="bi bi-calendar3 me-1"></i>Member since <?= htmlspecialchars($profile['JOIN_DATE'] ? date('d M Y', strtotime($profile['JOIN_DATE'])) : 'Active') ?>
                </div>
            </div>
            <div class="card-footer bg-subtle p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small text-uppercase fw-semibold">Email ID</span>
                    <span class="fw-semibold text-dark small"><?= htmlspecialchars($profile['LOGIN_ID']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small text-uppercase fw-semibold">Mobile</span>
                    <span class="fw-semibold text-dark small">+91 <?= htmlspecialchars($profile['MOBILE']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small text-uppercase fw-semibold">Team Assigned</span>
                    <span class="badge bg-secondary"><?= htmlspecialchars($profile['TEAM_ID'] ?: 'General') ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted small text-uppercase fw-semibold">Account ID</span>
                    <span class="badge bg-secondary">#<?= USER_ID ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Right column: Edit Details & Security Form -->
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">
                <span><i class="bi bi-pencil-square text-primary me-2"></i>Edit Profile & Security</span>
                <span class="text-muted small">Update contact details or password</span>
            </div>
            <div class="card-body p-3">
                <form method="POST" novalidate enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-section-title">
                        <i class="bi bi-person text-primary"></i> Personal Information
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="form-label" for="profile_photo">Profile Photo</label>
                            <div class="d-flex align-items-center gap-3">
                                <input type="file" class="form-control" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/webp,image/gif">
                                <?php if (!empty($profile['profile_photo'])): ?>
                                    <label class="text-danger small mb-0" style="cursor: pointer; white-space: nowrap;">
                                        <input type="checkbox" name="remove_photo" value="1" class="form-check-input me-1">
                                        Remove photo
                                    </label>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small mt-1">Accepted formats: JPG, PNG, WebP or GIF. Max 2MB.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="name">Full Name <span class="req">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($profile['NAME']) ?>" required />
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="mobile">Mobile Number <span class="req">*</span></label>
                            <input type="text" maxlength="10" class="form-control" id="mobile" name="mobile" pattern="[6-9][0-9]{9}" value="<?= htmlspecialchars($profile['MOBILE']) ?>" required />
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="login_id">Login Email <span class="req">*</span></label>
                            <input type="email" class="form-control" id="login_id" name="login_id" value="<?= htmlspecialchars($profile['LOGIN_ID']) ?>" required />
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="role_display">Assigned Role</label>
                            <input type="text" readonly class="form-control bg-subtle" id="role_display" value="<?= htmlspecialchars($profile['ROLE']) ?>" />
                            <div class="text-muted small mt-1">Role is configured by System Administrator.</div>
                        </div>
                    </div>

                    <div class="form-section-title mt-4">
                        <i class="bi bi-shield-lock text-primary"></i> Change Password
                        <span class="text-muted fw-normal small ms-2">(Leave blank to keep existing password)</span>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label" for="password">New Password</label>
                            <div class="input-group">
                                <input type="password" minlength="6" class="form-control" id="password" name="password" placeholder="Min. 6 characters" />
                                <button type="button" class="btn btn-secondary toggle-btn" data-target="password" type="button">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="confirm_password">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" minlength="6" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm password" />
                                <button type="button" class="btn btn-secondary toggle-btn" data-target="confirm_password" type="button">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 pt-2 border-top">
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="bi bi-check2"></i> Save Changes
                        </button>
                        <a href="<?= url('dashboard.php') ?>" class="btn btn-secondary">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

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

<?php include 'php_scripts/footer.php'; ?>
