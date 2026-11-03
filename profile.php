<?php
require_once __DIR__ . '/php_scripts/auth.php';

$Message = '';
$type = '';
$currentUserId = USER_ID;

// 1. Fetch fresh user profile directly from MySQL database
$userStmt = mysqli_prepare($link, "SELECT ID, NAME, MOBILE, EMAIL, LOGIN_ID, ROLE, role_id, TEAM_ID, COMPANY_NAME, COMPANY, JOIN_DATE, CREATED_AT, STATUS, profile_photo, profile_picture, profile_picture_blob FROM " . tn('TBL_USERS') . " WHERE ID = ? LIMIT 1");
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

                if ($password !== '') {
                    $updateFields[] = "PASSWORD = ?";
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                    $types  .= "s";
                }

                $uploadDir = __DIR__ . '/uploads/profile_photos/';
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

                                // Save filename in MySQL
                                $updateFields[] = "profile_photo = ?";
                                $params[] = $newPhotoName;
                                $types  .= "s";

                                // Save full Base64 Data URI in MySQL
                                $updateFields[] = "profile_picture = ?";
                                $params[] = $base64Data;
                                $types  .= "s";

                                // Save raw binary BLOB in MySQL
                                $updateFields[] = "profile_picture_blob = ?";
                                $params[] = $fileBytes;
                                $types  .= "s";

                                // Remove previous photo file if existed
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
                        $Message = "Profile updated and profile picture saved to MySQL successfully!";
                        $type = "success";
                        logActivity($link, USER_ID, "UPDATE", "Updated profile & avatar in MySQL", (string)USER_ID, "TBL_USERS");

                        // Refresh fresh user data
                        $userStmt = mysqli_prepare($link, "SELECT ID, NAME, MOBILE, EMAIL, LOGIN_ID, ROLE, role_id, TEAM_ID, COMPANY_NAME, COMPANY, JOIN_DATE, CREATED_AT, STATUS, profile_photo, profile_picture, profile_picture_blob FROM " . tn('TBL_USERS') . " WHERE ID = ? LIMIT 1");
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

// 3. Resolve Current Avatar Image Source (MySQL Base64 URI or Disk File)
$hasAvatar = false;
$avatarSrc = '';
if (!empty($userRow['profile_picture']) && str_starts_with($userRow['profile_picture'], 'data:image/')) {
    $hasAvatar = true;
    $avatarSrc = $userRow['profile_picture'];
} elseif (!empty($userRow['profile_photo']) && file_exists(__DIR__ . '/uploads/profile_photos/' . $userRow['profile_photo'])) {
    $hasAvatar = true;
    $avatarSrc = (defined('APP_BASE') ? APP_BASE : '') . '/uploads/profile_photos/' . $userRow['profile_photo'];
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
        <p class="crm-module-header-desc">Manage your identity, personal contact details, security credentials, and profile picture synced across web &amp; mobile</p>
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

<div class="row g-3">
    <!-- Left column: Profile Identity Summary Card (Natural Gradient) -->
    <div class="col-lg-4">
        <div class="card mb-3" style="border-top: 3px solid #7C3AED;">
            <div class="card-body text-center p-4">
                <!-- Avatar Circle with Natural Ambient Shadow -->
                <div class="position-relative d-inline-block mb-3">
                    <div id="avatarContainer" class="mx-auto" style="width: 96px; height: 96px; border-radius: 50%; overflow: hidden; border: 3px solid #E9D5FF; background: linear-gradient(135deg, #F0ECFF 0%, #E2DAFF 100%); display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 16px rgba(109, 69, 249, 0.16);">
                        <img id="avatarImgPreview" 
                             src="<?= $hasAvatar ? htmlspecialchars($avatarSrc) : '' ?>" 
                             alt="Profile Photo" 
                             style="width: 100%; height: 100%; object-fit: cover; <?= $hasAvatar ? '' : 'display:none;' ?>">
                        <span id="avatarInitialPreview" class="fs-2 fw-bold text-primary" style="<?= $hasAvatar ? 'display:none;' : '' ?>">
                            <?= strtoupper(mb_substr($userRow['NAME'] ?: 'U', 0, 1)) ?>
                        </span>
                    </div>
                    <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-primary p-2 border border-2 border-white shadow-sm" title="Profile picture">
                        <i class="bi bi-camera-fill fs-xs text-white"></i>
                    </span>
                </div>

                <h2 class="fs-5 fw-bold mb-1 text-dark"><?= htmlspecialchars($userRow['NAME']) ?></h2>
                <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                    <span class="badge bg-primary"><?= htmlspecialchars($userRow['ROLE']) ?></span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                        <i class="bi bi-dot"></i>Active Session
                    </span>
                </div>

                <div class="text-muted small mb-1">
                    <i class="bi bi-building me-1 text-secondary"></i><?= htmlspecialchars($userRow['COMPANY_NAME'] ?: ($userRow['COMPANY'] ?: 'CallNow CRM')) ?>
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
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted small text-uppercase fw-semibold"><i class="bi bi-hash me-1"></i>Account ID</span>
                    <span class="badge bg-secondary font-monospace">#<?= USER_ID ?></span>
                </div>
            </div>
        </div>

        <!-- Security & Sync Status Box (Natural Slate Tint) -->
        <div class="card metric-card metric-neutral p-3 mb-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-shield-check text-primary fs-5"></i>
                <div class="fw-bold text-dark fs-sm">Security &amp; Sync State</div>
            </div>
            <div class="text-muted fs-xs mb-3">Your credentials and profile photo are synchronized in real-time across the Web CRM and Mobile Telecaller App.</div>
            <div class="d-flex flex-column gap-2 fs-xs">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted">Password Encryption</span>
                    <span class="badge bg-success-subtle text-success">Bcrypt Hashed</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted">Photo Storage</span>
                    <span class="badge bg-primary-subtle text-primary">MySQL BLOB &amp; Cloud</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted">Mobile App Access</span>
                    <span class="badge bg-info-subtle text-info">REST API v1 Active</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Right column: Edit Details & Photo Upload Form -->
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">
                <div>
                    <i class="bi bi-pencil-square text-primary me-2"></i>
                    <span class="fw-bold">Edit Profile &amp; Photo</span>
                </div>
                <span class="text-muted small">Update photo, contact details, or login password</span>
            </div>
            <div class="card-body p-3 p-md-4">
                <form method="POST" novalidate enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                    <!-- Section 1: Profile Picture Upload Box (Natural Wash) -->
                    <div class="form-section-title mb-2">
                        <i class="bi bi-image text-primary me-1"></i> Profile Picture
                    </div>

                    <div class="p-3 mb-4 rounded-3 border" style="background: linear-gradient(180deg, #FAFAFC 0%, #F1F5F9 100%); border-color: #CBD5E1 !important;">
                        <div class="row align-items-center g-3">
                            <div class="col-auto">
                                <div style="width: 68px; height: 68px; border-radius: 50%; overflow: hidden; border: 2px solid #CBD5E1; background: #FFFFFF; display: flex; align-items: center; justify-content: center;">
                                    <img id="formPhotoPreview" 
                                         src="<?= $hasAvatar ? htmlspecialchars($avatarSrc) : '' ?>" 
                                         alt="Preview" 
                                         style="width: 100%; height: 100%; object-fit: cover; <?= $hasAvatar ? '' : 'display:none;' ?>">
                                    <i id="formPhotoPlaceholder" class="bi bi-person text-muted fs-2" style="<?= $hasAvatar ? 'display:none;' : '' ?>"></i>
                                </div>
                            </div>
                            <div class="col">
                                <label class="form-label fw-bold mb-1" for="profile_photo">
                                    Upload New Profile Photo
                                </label>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <input type="file" 
                                           class="form-control form-control-sm" 
                                           id="profile_photo" 
                                           name="profile_photo" 
                                           accept="image/jpeg,image/png,image/webp,image/gif"
                                           style="max-width: 320px;">

                                    <?php if ($hasAvatar): ?>
                                        <div class="form-check form-check-inline ms-1">
                                            <input class="form-check-input" type="checkbox" id="remove_photo" name="remove_photo" value="1">
                                            <label class="form-check-label text-danger small fw-semibold" for="remove_photo" style="cursor: pointer;">
                                                <i class="bi bi-trash3 me-1"></i> Remove photo
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
                                    <span class="badge bg-success-subtle text-success border border-success-subtle fs-xs">
                                        <i class="bi bi-database-check me-1"></i>Saved in MySQL &amp; Synced to Mobile API
                                    </span>
                                    <span class="text-muted fs-xs">JPG, PNG, WebP or GIF. Max 2MB.</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Personal Information -->
                    <div class="form-section-title mb-2">
                        <i class="bi bi-person text-primary me-1"></i> Personal Information
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label" for="name">Full Name <span class="req text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($userRow['NAME']) ?>" required />
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="mobile">Mobile Number <span class="req text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">+91</span>
                                <input type="text" maxlength="10" class="form-control" id="mobile" name="mobile" pattern="[6-9][0-9]{9}" value="<?= htmlspecialchars($userRow['MOBILE']) ?>" required />
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="login_id">Login Email <span class="req text-danger">*</span></label>
                            <input type="email" class="form-control" id="login_id" name="login_id" value="<?= htmlspecialchars($userRow['LOGIN_ID']) ?>" required />
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="role_display">Assigned Role</label>
                            <input type="text" readonly class="form-control bg-subtle" id="role_display" value="<?= htmlspecialchars($userRow['ROLE']) ?>" />
                            <div class="text-muted fs-xs mt-1">Role is configured by System Administrator.</div>
                        </div>
                    </div>

                    <!-- Section 3: Change Password -->
                    <div class="form-section-title mb-2">
                        <i class="bi bi-shield-lock text-primary me-1"></i> Change Password
                        <span class="text-muted fw-normal fs-xs ms-2">(Leave blank to keep current password)</span>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label" for="password">New Password</label>
                            <div class="input-group">
                                <input type="password" minlength="6" class="form-control" id="password" name="password" placeholder="Min. 6 characters" />
                                <button type="button" class="btn btn-outline-secondary toggle-btn" data-target="password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="confirm_password">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" minlength="6" class="form-control" id="confirm_password" name="confirm_password" placeholder="Re-type new password" />
                                <button type="button" class="btn btn-outline-secondary toggle-btn" data-target="confirm_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 pt-3 border-top">
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="bi bi-check2-circle me-1"></i> Save Profile Changes
                        </button>
                        <a href="<?= url('dashboard.php') ?>" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

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

// Instant live profile photo preview on file selection
const fileInput = document.getElementById('profile_photo');
if (fileInput) {
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            // Validate file size client-side (2MB)
            if (file.size > 2 * 1024 * 1024) {
                alert('The selected image is larger than 2MB. Please choose a smaller image.');
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(evt) {
                const dataUrl = evt.target.result;
                
                // Update Left Column Avatar
                const leftImg = document.getElementById('avatarImgPreview');
                const leftInitial = document.getElementById('avatarInitialPreview');
                if (leftImg) {
                    leftImg.src = dataUrl;
                    leftImg.style.display = 'block';
                }
                if (leftInitial) {
                    leftInitial.style.display = 'none';
                }

                // Update Form Box Preview
                const formImg = document.getElementById('formPhotoPreview');
                const formPlaceholder = document.getElementById('formPhotoPlaceholder');
                if (formImg) {
                    formImg.src = dataUrl;
                    formImg.style.display = 'block';
                }
                if (formPlaceholder) {
                    formPlaceholder.style.display = 'none';
                }

                // Uncheck remove_photo if user picked a new file
                const removeCheck = document.getElementById('remove_photo');
                if (removeCheck) {
                    removeCheck.checked = false;
                }
            };
            reader.readAsDataURL(file);
        }
    });
}
</script>

<?php include __DIR__ . '/php_scripts/footer.php'; ?>
