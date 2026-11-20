<?php
require_once __DIR__ . '/php_scripts/auth.php';
require_once __DIR__ . '/php_scripts/password_reset.php';

$Message = '';
$type = '';
$currentUserId = USER_ID;

// 1. Fetch fresh user profile directly from MySQL database
$userStmt = mysqli_prepare($link, "SELECT ID, NAME, MOBILE, EMAIL, LOGIN_ID, ROLE, role_id, TEAM_ID, COMPANY_NAME, COMPANY, JOIN_DATE, CREATED_AT, STATUS, daily_calling_limit, monthly_disbursement_limit, profile_photo, profile_picture, profile_picture_blob, PASSWORD FROM " . tn('TBL_USERS') . " WHERE ID = ? LIMIT 1");
mysqli_stmt_bind_param($userStmt, "i", $currentUserId);
mysqli_stmt_execute($userStmt);
$userRow = mysqli_stmt_get_result($userStmt)->fetch_assoc();
mysqli_stmt_close($userStmt);

if (!$userRow) {
    die("User account not found.");
}

// Resolve team name if assigned
$teamName = 'General';
if (!empty($userRow['TEAM_ID'])) {
    $tId = (int)$userRow['TEAM_ID'];
    $tRes = @mysqli_query($link, "SELECT NAME FROM " . tn('TBL_TEAMS') . " WHERE ID = $tId LIMIT 1");
    if ($tRes && ($tRow = mysqli_fetch_assoc($tRes))) {
        $teamName = $tRow['NAME'] ?? 'General';
    }
}

// 2. Handle Profile Update Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $Message = "Error: Invalid session security token. Please try again.";
        $type = "danger";
    } else {
        $name     = trim($_POST['name'] ?? '');
        $mobile   = trim($_POST['mobile'] ?? '');
        $login_id = trim($_POST['login_id'] ?? '');
        $current_password = $_POST['current_password'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (!preg_match("/^[a-zA-Z\s\.\,\'-]+$/u", $name)) {
            $Message = "Full name must contain only letters and spaces.";
            $type = "danger";
        } elseif (!preg_match("/^[6-9][0-9]{9}$/", $mobile)) {
            $Message = "Enter a valid 10-digit mobile number starting with 6, 7, 8, or 9.";
            $type = "danger";
        } elseif (!filter_var($login_id, FILTER_VALIDATE_EMAIL)) {
            $Message = "Please enter a valid login email address.";
            $type = "danger";
        } elseif ($password !== '' && strlen($password) < 6) {
            $Message = "New password must be at least 6 characters long.";
            $type = "danger";
        } elseif ($password !== '' && $password !== $confirm) {
            $Message = "New password and confirmation do not match.";
            $type = "danger";
        } elseif ($password !== '' && !password_verify($current_password, $userRow['PASSWORD'] ?? '')) {
            $Message = "Current password is incorrect.";
            $type = "danger";
        } elseif ($password !== '' && $current_password === '') {
            $Message = "Current password is required to change your password.";
            $type = "danger";
        } else {
            // Check duplicate email or phone on other accounts
            $dupStmt = mysqli_prepare($link, "SELECT ID FROM " . tn('TBL_USERS') . " WHERE (LOGIN_ID = ? OR MOBILE = ?) AND ID <> ? LIMIT 1");
            mysqli_stmt_bind_param($dupStmt, "ssi", $login_id, $mobile, $currentUserId);
            mysqli_stmt_execute($dupStmt);
            mysqli_stmt_store_result($dupStmt);

            if (mysqli_stmt_num_rows($dupStmt) > 0) {
                $Message = "That email address or mobile number is already registered to another user.";
                $type = "danger";
                mysqli_stmt_close($dupStmt);
            } else {
                mysqli_stmt_close($dupStmt);

                $updateFields = ["NAME = ?", "MOBILE = ?", "LOGIN_ID = ?", "EMAIL = ?"];
                $params = [$name, $mobile, $login_id, $login_id];
                $types  = "ssss";

                if (isset($_POST['daily_calling_limit']) && $_POST['daily_calling_limit'] !== '') {
                    $daily_limit = max(0, (int)$_POST['daily_calling_limit']);
                    $updateFields[] = "daily_calling_limit = ?";
                    $params[] = $daily_limit;
                    $types .= "i";
                }

                if (isset($_POST['monthly_disbursement_limit']) && $_POST['monthly_disbursement_limit'] !== '') {
                    $monthly_limit = max(0.0, (float)$_POST['monthly_disbursement_limit']);
                    $updateFields[] = "monthly_disbursement_limit = ?";
                    $params[] = $monthly_limit;
                    $types .= "d";
                }

                if ($password !== '') {
                    $updateFields[] = "PASSWORD = ?";
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                    $types  .= "s";
                }

                $uploadDir = function_exists('getTenantUploadDir') ? getTenantUploadDir('profile_photos') : (__DIR__ . '/uploads/profile_photos/');
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }

                // Handle Photo Removal if requested
                if (!empty($_POST['remove_photo'])) {
                    $oldPhoto = $userRow['profile_photo'] ?? '';
                    if ($oldPhoto && file_exists($uploadDir . $oldPhoto)) {
                        @unlink($uploadDir . $oldPhoto);
                    }
                    $updateFields[] = "profile_photo = NULL";
                    $updateFields[] = "profile_picture = NULL";
                    $updateFields[] = "profile_picture_blob = NULL";
                }

                // Handle New Profile Photo Upload
                $newPhotoName = '';
                if (!empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['profile_photo'];
                    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                    $maxSize = 2 * 1024 * 1024; // 2MB

                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $detectedMime = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);

                    if (!in_array($detectedMime, $allowed, true)) {
                        $Message = "Invalid image format. Allowed formats: JPG, PNG, WebP or GIF.";
                        $type = "danger";
                    } elseif ($file['size'] > $maxSize) {
                        $Message = "Image file is too large. Maximum allowed size is 2MB.";
                        $type = "danger";
                    } else {
                        $fileBytes = file_get_contents($file['tmp_name']);
                        if ($fileBytes === false || strlen($fileBytes) === 0) {
                            $Message = "Failed to read uploaded photo.";
                            $type = "danger";
                        } else {
                            $ext = match($detectedMime) {
                                'image/png'  => 'png',
                                'image/webp' => 'webp',
                                'image/gif'  => 'gif',
                                default      => 'jpg'
                            };
                            $newPhotoName = 'user_' . USER_ID . '_' . time() . '.' . $ext;
                            $dest = $uploadDir . $newPhotoName;

                            if (file_put_contents($dest, $fileBytes) !== false) {
                                $base64Data = 'data:' . $detectedMime . ';base64,' . base64_encode($fileBytes);

                                $updateFields[] = "profile_photo = ?";
                                $params[] = $newPhotoName;
                                $types  .= "s";

                                $updateFields[] = "profile_picture = ?";
                                $params[] = $base64Data;
                                $types  .= "s";

                                $updateFields[] = "profile_picture_blob = ?";
                                $params[] = $fileBytes;
                                $types  .= "s";

                                $oldPhoto = $userRow['profile_photo'] ?? '';
                                if ($oldPhoto && $oldPhoto !== $newPhotoName && file_exists($uploadDir . $oldPhoto)) {
                                    @unlink($uploadDir . $oldPhoto);
                                }
                            } else {
                                $Message = "Failed to write uploaded image to server storage.";
                                $type = "danger";
                            }
                        }
                    }
                }

                if ($Message === '') {
                    $updateFields[] = "UPDATED_AT = NOW()";
                    $params[] = $currentUserId;
                    $types   .= "i";

                    $updateSql = "UPDATE " . tn('TBL_USERS') . " SET " . implode(", ", $updateFields) . " WHERE ID = ?";
                    $updStmt = mysqli_prepare($link, $updateSql);
                    $bindRefs = [];
                    foreach ($params as $k => &$v) {
                        $bindRefs[$k] = &$v;
                    }
                    mysqli_stmt_bind_param($updStmt, $types, ...$bindRefs);

                    if (mysqli_stmt_execute($updStmt)) {
                        $_SESSION['name']     = $name;
                        $_SESSION['mobile']   = $mobile;
                        $_SESSION['login_id'] = $login_id;
                        $_SESSION['email']    = $login_id;
                        if (!empty($newPhotoName)) {
                            $_SESSION['profile_photo'] = $newPhotoName;
                        } elseif (!empty($_POST['remove_photo'])) {
                            $_SESSION['profile_photo'] = '';
                        }
                        $Message = "Profile updated successfully!";
                        $type = "success";
                        logActivity($link, USER_ID, "UPDATE", "Updated profile & avatar", (string)USER_ID, "TBL_USERS");

                        $userStmt = mysqli_prepare($link, "SELECT ID, NAME, MOBILE, EMAIL, LOGIN_ID, ROLE, role_id, TEAM_ID, COMPANY_NAME, COMPANY, JOIN_DATE, CREATED_AT, STATUS, profile_photo, profile_picture, profile_picture_blob, PASSWORD FROM " . tn('TBL_USERS') . " WHERE ID = ? LIMIT 1");
                        mysqli_stmt_bind_param($userStmt, "i", $currentUserId);
                        mysqli_stmt_execute($userStmt);
                        $userRow = mysqli_stmt_get_result($userStmt)->fetch_assoc();
                        mysqli_stmt_close($userStmt);
                    } else {
                        $Message = "Database error: " . mysqli_error($link);
                        $type = "danger";
                    }
                    mysqli_stmt_close($updStmt);
                }
            }
        }
    }
}

// 3. Handle Email OTP Reset (Send Code)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email_otp'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $Message = "Error: Invalid session security token. Please try again.";
        $type = "danger";
    } else {
        require_once __DIR__ . '/php_scripts/mailer.php';
        ensurePasswordResetSchema($link);

        $targetEmail = !empty($userRow['EMAIL']) && filter_var($userRow['EMAIL'], FILTER_VALIDATE_EMAIL) ? $userRow['EMAIL'] : $userRow['LOGIN_ID'];
        $otp = sprintf('%06d', random_int(100000, 999999));
        $tokenHash = hash('sha256', $otp);
        $expiresAt = date('Y-m-d H:i:s', time() + 900);

        $inv = $link->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
        $inv->bind_param('i', $currentUserId);
        $inv->execute();
        $inv->close();

        $tokStmt = $link->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
        $tokStmt->bind_param('iss', $currentUserId, $tokenHash, $expiresAt);
        $tokStmt->execute();
        $tokStmt->close();

        $emailSent = false;
        if (filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            $subject = 'CallNow CRM - Password Reset Verification Code';
            $body = "Hello,\n\nYour verification code to reset your CallNow CRM password is:\n\n{$otp}\n\nThis code will expire in 15 minutes.\nIf you did not request this, please ignore this email.\n\nBest regards,\nCallNow Support Team";
            $emailSent = sendSystemEmail($targetEmail, $subject, $body);
        }
        logActivity($link, $currentUserId, 'PASSWORD_RESET_OTP', "Password reset OTP requested for {$targetEmail}" . ($emailSent ? " (delivered)" : " (send failed)"), (string)$currentUserId, 'TBL_USERS');

        $Message = $emailSent ? "A verification code has been sent to your email." : "Failed to send verification email. Please contact support.";
        $type = $emailSent ? "success" : "danger";
    }
}

// 4. Handle Email OTP Reset (Verify Code + Set New Password)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_email_otp'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $Message = "Error: Invalid session security token. Please try again.";
        $type = "danger";
    } else {
        $otp   = trim((string)($_POST['otp_code'] ?? ''));
        $newPass = (string)($_POST['otp_new_password'] ?? '');
        $confirmPass = (string)($_POST['otp_confirm_password'] ?? '');

        if ($otp === '') {
            $Message = "Verification code is required.";
            $type = "danger";
        } elseif (strlen($newPass) < 6) {
            $Message = "New password must be at least 6 characters long.";
            $type = "danger";
        } elseif ($newPass !== $confirmPass) {
            $Message = "New password and confirmation do not match.";
            $type = "danger";
        } else {
            ensurePasswordResetSchema($link);
            $otpHash = hash('sha256', $otp);

            $chk = $link->prepare("SELECT id FROM password_reset_tokens WHERE user_id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
            $chk->bind_param('is', $currentUserId, $otpHash);
            $chk->execute();
            $tokenRow = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$tokenRow) {
                $Message = "Invalid or expired verification code.";
                $type = "danger";
            } else {
                $link->begin_transaction();
                try {
                    $hash = password_hash($newPass, PASSWORD_DEFAULT);
                    $uStmt = $link->prepare("UPDATE " . tn('TBL_USERS') . " SET PASSWORD = ?, UPDATED_AT = NOW() WHERE ID = ?");
                    $uStmt->bind_param('si', $hash, $currentUserId);
                    if (!$uStmt->execute()) throw new RuntimeException('Password update failed');
                    $uStmt->close();

                    $tokUpd = $link->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
                    $tokUpd->bind_param('i', $currentUserId);
                    if (!$tokUpd->execute()) throw new RuntimeException('Token update failed');
                    $tokUpd->close();

                    $tokRevoke = $link->prepare("UPDATE " . tn('TBL_API_TOKENS') . " SET is_active = 0 WHERE user_id = ?");
                    $tokRevoke->bind_param('i', $currentUserId);
                    $tokRevoke->execute();
                    $tokRevoke->close();

                    $link->commit();
                } catch (Throwable $e) {
                    $link->rollback();
                    $Message = "Failed to reset password: " . $e->getMessage();
                    $type = "danger";
                }
                if ($type !== 'danger') {
                    logActivity($link, $currentUserId, 'PASSWORD_RESET', 'Password reset via email OTP on profile page', (string)$currentUserId, 'TBL_USERS');
                    $Message = "Password reset successfully! You can now login with your new password.";
                    $type = "success";
                }
            }
        }
    }
}

// 3. Resolve Current Avatar Image Source (MySQL Base64 URI or Disk File)
$hasAvatar = false;
$avatarSrc = '';
if (!empty($userRow['profile_picture']) && str_starts_with($userRow['profile_picture'], 'data:image/')) {
    $hasAvatar = true;
    $avatarSrc = $userRow['profile_picture'];
} elseif (!empty($userRow['profile_photo'])) {
    $tenantPhotoPath = (function_exists('getTenantUploadDir') ? getTenantUploadDir('profile_photos') : (__DIR__ . '/uploads/profile_photos/')) . $userRow['profile_photo'];
    $globalPhotoPath = __DIR__ . '/uploads/profile_photos/' . $userRow['profile_photo'];
    if (file_exists($tenantPhotoPath)) {
        $hasAvatar = true;
        $avatarSrc = (function_exists('getTenantUploadUrl') ? getTenantUploadUrl('profile_photos') : ((defined('APP_BASE') ? APP_BASE : '') . '/uploads/profile_photos/')) . $userRow['profile_photo'];
    } elseif (file_exists($globalPhotoPath)) {
        $hasAvatar = true;
        $avatarSrc = (defined('APP_BASE') ? APP_BASE : '') . '/uploads/profile_photos/' . $userRow['profile_photo'];
    }
} elseif (!empty($userRow['profile_picture_blob'])) {
    $hasAvatar = true;
    $avatarSrc = 'data:image/jpeg;base64,' . base64_encode($userRow['profile_picture_blob']);
}

$pageTitle = 'My Profile';
include __DIR__ . '/php_scripts/header.php';
?>

<!-- Breadcrumb Navigation -->
<nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-2">
        <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
        <li class="breadcrumb-item active" aria-current="page">My Profile</li>
    </ol>
</nav>

<!-- Module Header -->
<div class="crm-module-header">
    <div>
        <div class="crm-module-header-title">
            <i class="bi bi-person-badge text-primary"></i>
            <h1>Account Profile</h1>
        </div>
        <p class="crm-module-header-desc">Manage your identity, personal contact details, security credentials, and profile picture.</p>
    </div>
    <div class="crm-module-header-actions">
        <a href="<?= url('dashboard.php') ?>" class="btn btn-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
    </div>
</div>

<?php if ($Message): ?>
    <div class="alert alert-<?= htmlspecialchars($type ?: 'info') ?> alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
        <i class="bi <?= $type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-5"></i>
        <div class="fw-medium"><?= htmlspecialchars($Message) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Left column: Profile Identity Summary Card -->
    <div class="col-lg-4">
        <div class="card card-profile shadow card-border mb-4">
            <div class="card-body text-center p-4">
                <!-- Avatar Circle -->
                <div class="position-relative d-inline-block mb-3">
                    <div id="avatarContainer" class="mx-auto" style="width: 110px; height: 110px; border-radius: 50%; overflow: hidden; border: 3px solid var(--border); background: var(--bg-subtle); display: flex; align-items: center; justify-content: center;">
                        <img id="avatarImgPreview" 
                             src="<?= $hasAvatar ? htmlspecialchars($avatarSrc) : '' ?>" 
                             alt="Profile Photo" 
                             style="width: 100%; height: 100%; object-fit: cover; <?= $hasAvatar ? '' : 'display:none;' ?>">
                        <span id="avatarInitialPreview" class="fs-4 fw-bold text-primary" style="<?= $hasAvatar ? 'display:none;' : '' ?>">
                            <?= strtoupper(mb_substr($userRow['NAME'] ?: 'U', 0, 1)) ?>
                        </span>
                    </div>
                    <button type="button" class="position-absolute bottom-0 end-0 btn btn-primary btn-sm rounded-circle shadow-sm" title="Change profile picture" id="cameraBtn" style="width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; padding: 0;">
                        <i class="bi bi-camera-fill text-white fs-5"></i>
                    </button>
                    <?php if ($hasAvatar): ?>
                    <div class="position-absolute top-0 start-0 mt-1 ms-1">
                        <a href="#" class="btn btn-outline-danger btn-sm text-decoration-none" onclick="toggleRemovePhoto(this); return false;" style="padding: 0.15rem 0.4rem; font-size: 0.7rem;">Remove</a>
                    </div>
                    <?php endif; ?>
                </div>

                <h2 class="fs-5 fw-bold mb-1 text-dark"><?= htmlspecialchars($userRow['NAME']) ?></h2>
                <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                    <span class="badge bg-primary"><?= htmlspecialchars($userRow['ROLE']) ?></span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                        <i class="bi bi-dot"></i> Active
                    </span>
                </div>

                <div class="text-muted small mb-1">
                    <i class="bi bi-building me-1 text-secondary"></i><?= htmlspecialchars($userRow['COMPANY_NAME'] ?: ($userRow['COMPANY'] ?: ($_SESSION['company_name'] ?? ''))) ?>
                </div>
                <div class="text-muted small">
                    <i class="bi bi-calendar3 me-1 text-secondary"></i>Member since <?= htmlspecialchars(!empty($userRow['JOIN_DATE']) ? date('d M Y', strtotime($userRow['JOIN_DATE'])) : (!empty($userRow['CREATED_AT']) ? date('d M Y', strtotime($userRow['CREATED_AT'])) : 'Active')) ?>
                </div>
            </div>

            <!-- Profile Attributes List -->
            <div class="card-footer bg-subtle p-3">
                <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-subtle">
                    <span class="text-muted small text-uppercase fw-semibold"><i class="bi bi-envelope me-1"></i>Login Email</span>
                    <span class="fw-semibold text-dark small font-monospace"><?= htmlspecialchars($userRow['LOGIN_ID']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-subtle">
                    <span class="text-muted small text-uppercase fw-semibold"><i class="bi bi-telephone me-1"></i>Mobile</span>
                    <span class="fw-semibold text-dark small font-monospace">+91 <?= htmlspecialchars($userRow['MOBILE']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-subtle">
                    <span class="text-muted small text-uppercase fw-semibold"><i class="bi bi-people me-1"></i>Team Assigned</span>
                    <span class="badge bg-secondary"><?= htmlspecialchars($teamName) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-subtle">
                    <span class="text-muted small text-uppercase fw-semibold"><i class="bi bi-telephone-outbound me-1"></i>Daily Call Limit</span>
                    <span class="badge bg-primary fs-xs"><?= number_format((int)($userRow['daily_calling_limit'] ?? 250)) ?> calls</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted small text-uppercase fw-semibold"><i class="bi bi-hash me-1"></i>Account ID</span>
                    <span class="badge bg-secondary font-monospace">#<?= USER_ID ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Right column: Edit Details & Security Form -->
    <div class="col-lg-8">
        <div class="card card-profile shadow card-border mb-4">
            <div class="card-header bg-transparent border-bottom pb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-pencil-square text-primary fs-5"></i>
                    <span class="fw-bold text-dark">Edit Profile &amp; Security</span>
                </div>
            </div>
            <div class="card-body p-4">
                <form method="POST" novalidate enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <input type="file" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/webp,image/gif" style="display: none;">
                    <?php if ($hasAvatar): ?>
                    <input type="checkbox" id="remove_photo" name="remove_photo" value="1" style="display: none;">
                    <?php endif; ?>

                    <!-- Personal Information -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="name">Full Name <span class="req text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($userRow['NAME']) ?>" required />
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="mobile">Mobile Number <span class="req text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">+91</span>
                                <input type="text" maxlength="10" class="form-control" id="mobile" name="mobile" pattern="[6-9][0-9]{9}" value="<?= htmlspecialchars($userRow['MOBILE']) ?>" required />
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="login_id">Login Email <span class="req text-danger">*</span></label>
                            <input type="email" class="form-control" id="login_id" name="login_id" value="<?= htmlspecialchars($userRow['LOGIN_ID']) ?>" required />
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="role_display">Assigned Role</label>
                            <input type="text" readonly class="form-control bg-subtle" id="role_display" value="<?= htmlspecialchars($userRow['ROLE']) ?>" />
                            <div class="text-muted fs-xs mt-1">Role is configured by System Administrator.</div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <!-- Change Password -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold d-flex align-items-center gap-2" for="current_password">
                            <i class="bi bi-shield-lock text-primary fs-6"></i> Current Password <span class="req text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="current_password" name="current_password" placeholder="Enter your current password" required />
                            <button type="button" class="btn btn-outline-secondary toggle-btn" data-target="current_password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-text text-muted">Required to confirm your identity before changing your password.</div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="password">New Password</label>
                            <div class="input-group">
                                <input type="password" minlength="6" class="form-control" id="password" name="password" placeholder="Min. 6 characters" />
                                <button type="button" class="btn btn-outline-secondary toggle-btn" data-target="password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text text-muted">Leave blank to keep your current password.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="confirm_password">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" minlength="6" class="form-control" id="confirm_password" name="confirm_password" placeholder="Re-type new password" />
                                <button type="button" class="btn btn-outline-secondary toggle-btn" data-target="confirm_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <!-- Email OTP Password Reset -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold d-flex align-items-center gap-2" for="reset_email">
                            <i class="bi bi-envelope-at text-primary fs-6"></i> Reset Password via Email OTP
                        </label>
                        <div class="input-group mb-2">
                            <span class="input-group-text bg-light border-end-0"><?= htmlspecialchars(!empty($userRow['EMAIL']) ? $userRow['EMAIL'] : $userRow['LOGIN_ID']) ?></span>
                            <button type="button" class="btn btn-outline-primary" id="sendOtpBtn" onclick="toggleOtpForm()">
                                Send Code
                            </button>
                        </div>
                    </div>

                    <div id="otpForm" style="display: none;" class="card bg-light p-3 rounded-3 mb-3 border">
                        <div class="form-label fw-semibold small text-uppercase mb-2">Verification Code &amp; New Password</div>
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-4">
                                <input type="text" class="form-control form-control-sm text-center" id="otp_code" name="otp_code" maxlength="6" placeholder="######" />
                            </div>
                            <div class="col-12 col-md-4">
                                <input type="password" class="form-control form-control-sm" id="otp_new_password" name="otp_new_password" placeholder="New password" minlength="6" />
                            </div>
                            <div class="col-12 col-md-4">
                                <input type="password" class="form-control form-control-sm" id="otp_confirm_password" name="otp_confirm_password" placeholder="Confirm password" minlength="6" />
                            </div>
                        </div>
                        <div class="d-grid">
                            <button type="button" class="btn btn-primary btn-sm" onclick="submitOtpForm()">Verify &amp; Reset Password</button>
                        </div>
                        <div class="form-text text-muted mt-2">Code sent to your email expires in 15 minutes.</div>
                    </div>

                    <input type="hidden" name="otp_action" value="1">
                    <input type="hidden" id="fileInputHidden" name="fileInputHidden" value="">

                    <div class="d-flex align-items-center gap-2 pt-3 border-top">
                        <button type="submit" name="update_profile" class="btn btn-primary px-4">
                            <i class="bi bi-check2-circle me-1"></i> Save Changes
                        </button>
                        <a href="<?= url('dashboard.php') ?>" class="btn btn-outline-secondary px-4">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- OTP Reset Success Toast (auto-dismiss) -->
<?php if ($type === 'success' && isset($_POST['verify_email_otp'])): ?>
<script>
    const otpToast = new bootstrap.Toast(document.getElementById('otpToast'), { autohide: true, delay: 5000 });
    otpToast.show();
</script>
<div class="toast position-fixed bottom-0 end-0 m-3" id="otpToast" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-header bg-success text-white">
        <strong class="me-auto">Password Reset</strong>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
    </div>
    <div class="toast-body"><?= htmlspecialchars($Message) ?></div>
</div>
<?php endif; ?>

<script>
// Password visibility toggle
document.querySelectorAll('.toggle-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const target = document.getElementById(btn.dataset.target);
        if (!target) return;
        const icon = btn.querySelector('i');
        const isPassword = target.type === 'password';
        target.type = isPassword ? 'text' : 'password';
        icon.classList.toggle('bi-eye', !isPassword);
        icon.classList.toggle('bi-eye-slash', isPassword);
    });
});

// Camera button triggers hidden file input
const cameraBtn = document.getElementById('cameraBtn');
const fileInput = document.getElementById('profile_photo');
if (cameraBtn && fileInput) {
    cameraBtn.addEventListener('click', () => fileInput.click());
}

// Toggle remove-photo checkbox (hidden input inside the form)
function toggleRemovePhoto(link) {
    const cb = document.getElementById('remove_photo');
    if (!cb) return;
    cb.checked = !cb.checked;
    link.textContent = cb.checked ? 'Undo Remove' : 'Remove';
    link.classList.toggle('btn-danger', cb.checked);
    link.classList.toggle('btn-outline-danger', !cb.checked);
}

// Instant live profile photo preview on file selection
if (fileInput) {
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            if (file.size > 2 * 1024 * 1024) {
                alert('The selected image is larger than 2MB. Please choose a smaller image.');
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(evt) {
                const dataUrl = evt.target.result;

                const leftImg = document.getElementById('avatarImgPreview');
                const leftInitial = document.getElementById('avatarInitialPreview');
                if (leftImg) {
                    leftImg.src = dataUrl;
                    leftImg.style.display = 'block';
                }
                if (leftInitial) leftInitial.style.display = 'none';
            };
            reader.readAsDataURL(file);
        }
    });
}

// OTP form toggle
function toggleOtpForm() {
    const form = document.getElementById('otpForm');
    const btn = document.getElementById('sendOtpBtn');
    if (form.style.display === 'none') {
        form.style.display = 'block';
        btn.textContent = 'Hide';
    } else {
        form.style.display = 'none';
        btn.textContent = 'Send Code';
    }
}

// Submit OTP form via AJAX-ish: hijack a hidden submit
function submitOtpForm() {
    const otpCode = document.getElementById('otp_code').value.trim();
    const newPass = document.getElementById('otp_new_password').value;
    const confirmPass = document.getElementById('otp_confirm_password').value;

    if (!otpCode) { alert('Please enter the verification code.'); return; }
    if (newPass.length < 6) { alert('New password must be at least 6 characters.'); return; }
    if (newPass !== confirmPass) { alert('Passwords do not match.'); return; }

    document.getElementById('otp_action').value = '1';
    const form = document.querySelector('form');
    // Append otp fields to main form and submit as verify_email_otp
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'verify_email_otp';
    hidden.value = '1';
    form.appendChild(hidden);
    form.submit();
}
</script>

<?php include __DIR__ . '/php_scripts/footer.php'; ?>
