<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php';
require_once '../../php_scripts/super_admin.php';

$isEdit = isset($_GET['ID']);
$userID = $isEdit ? intval($_GET['ID']) : 0;

requirePermission('manage_users');

if (!$isEdit) {
    $limitMsg = checkUserLimit($link, USER_ID);
    if ($limitMsg) {
        $_SESSION['access_denied_message'] = $limitMsg;
        appRedirect('dashboard');
    }
}

$Message = ""; 
$type = "";

if (isAdmin() || isSuperAdmin()) {
    $teamsResult = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " ORDER BY NAME");
} elseif (USER_TEAM_ID) {
    $teamsResult = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " WHERE ID = " . (int)USER_TEAM_ID);
} else {
    $teamsResult = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " ORDER BY NAME");
}
$allTeams = mysqli_fetch_all($teamsResult, MYSQLI_ASSOC);

// Fetch dynamic roles from DB
$allRoles = [];
$ar = mysqli_query($link, "SELECT role_name, description FROM " . tn('TBL_ROLES') . " WHERE role_name != 'Super Admin' ORDER BY is_system DESC, id");
if ($ar) while ($a = mysqli_fetch_assoc($ar)) $allRoles[] = $a;

$editUser = null;
if ($isEdit) {
    $stmt = mysqli_prepare($link, "SELECT u.*, t.NAME as TEAM_NAME FROM " . tn('TBL_USERS') . " u LEFT JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID WHERE u.ID = ?");
    mysqli_stmt_bind_param($stmt, "i", $userID);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $editUser = mysqli_fetch_assoc($result);
    if (!$editUser) { header("Location: users_view.php"); exit; }
    // Protect system accounts from editing (allow self-edit)
    if ((int)$editUser['ID'] === 1 || $editUser['ROLE'] === 'Super Admin') {
        $_SESSION['error'] = 'System Administrator cannot be edited.';
        header('Location: users_view.php'); exit;
    }
    if ($editUser['ROLE'] === 'Admin' && (int)$editUser['ID'] !== USER_ID) {
        $_SESSION['error'] = 'Other Admin accounts cannot be edited.';
        header('Location: users_view.php'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $Message = "Invalid session token";
        $type = "danger";
    } else {
        $name         = trim($_POST['name'] ?? '');
        $mobile       = trim($_POST['mobile'] ?? '');
        $login_id     = trim($_POST['username'] ?? '');
        $email_input  = trim($_POST['email'] ?? '');
        $email_addr   = ($email_input !== '') ? $email_input : null;
        $company_name = trim($_POST['company_name'] ?? '');
        $company_name_db = ($company_name !== '') ? $company_name : null;
        $password     = $_POST['password'] ?? '';
        $role         = $_POST['role'] ?? '';
        $team_id      = $_POST['team_id'] ?? '';
        $package      = trim($_POST['package'] ?? '');
        $status       = $_POST['status'] ?? 'Active';
        $company      = $editUser['COMPANY'] ?? $_SESSION["CompanyName"] ?? 'CallNow';

        if (!preg_match("/^[a-zA-Z ]+$/", $name)) $Message = "Invalid name (alphabetic characters only)";
        elseif (!preg_match("/^[6-9][0-9]{9}$/", $mobile)) $Message = "Invalid 10-digit mobile number";
        elseif (!filter_var($login_id, FILTER_VALIDATE_EMAIL)) $Message = "Invalid login email";
        elseif ($email_addr !== null && !filter_var($email_addr, FILTER_VALIDATE_EMAIL)) $Message = "Invalid alternate email";
        elseif (!$isEdit && strlen($password) < 6) $Message = "Password must be at least 6 characters";
        elseif (empty($role) || empty($team_id) || empty($package)) $Message = "All required fields must be filled";
        else {
            // Check 1: Mobile uniqueness
            $checkMob = mysqli_prepare($link, "SELECT ID FROM " . tn('TBL_USERS') . " WHERE MOBILE = ? AND ID != ? LIMIT 1");
            mysqli_stmt_bind_param($checkMob, "si", $mobile, $userID);
            mysqli_stmt_execute($checkMob);
            mysqli_stmt_store_result($checkMob);
            if (mysqli_stmt_num_rows($checkMob) > 0) {
                $Message = "Mobile number ($mobile) is already registered to another user.";
                $type = "warning";
            }
            mysqli_stmt_close($checkMob);

            // Check 2: Login ID uniqueness
            if (!$Message) {
                $checkLogin = mysqli_prepare($link, "SELECT ID FROM " . tn('TBL_USERS') . " WHERE (LOGIN_ID = ? OR EMAIL = ?) AND ID != ? LIMIT 1");
                mysqli_stmt_bind_param($checkLogin, "ssi", $login_id, $login_id, $userID);
                mysqli_stmt_execute($checkLogin);
                mysqli_stmt_store_result($checkLogin);
                if (mysqli_stmt_num_rows($checkLogin) > 0) {
                    $Message = "Login email ($login_id) is already registered in the system.";
                    $type = "warning";
                }
                mysqli_stmt_close($checkLogin);
            }

            // Check 3: Alternate Email uniqueness if provided
            if (!$Message && $email_addr !== null) {
                $checkEmail = mysqli_prepare($link, "SELECT ID FROM " . tn('TBL_USERS') . " WHERE (EMAIL = ? OR LOGIN_ID = ?) AND ID != ? LIMIT 1");
                mysqli_stmt_bind_param($checkEmail, "ssi", $email_addr, $email_addr, $userID);
                mysqli_stmt_execute($checkEmail);
                mysqli_stmt_store_result($checkEmail);
                if (mysqli_stmt_num_rows($checkEmail) > 0) {
                    $Message = "Alternate email ($email_addr) is already in use by another user.";
                    $type = "warning";
                }
                mysqli_stmt_close($checkEmail);
            }

            if (!$Message) {
                if ($isEdit) {
                    $sql = "UPDATE " . tn('TBL_USERS') . " SET NAME=?, MOBILE=?, LOGIN_ID=?, EMAIL=?, COMPANY_NAME=?, ROLE=?, TEAM_ID=?, PACKAGE=?, STATUS=?, COMPANY=?";
                    $params = [$name, $mobile, $login_id, $email_addr, $company_name_db, $role, $team_id, $package, $status, $company];
                    $types = "ssssssisss";

                    if (!empty($password)) {
                        $sql .= ", PASSWORD=?";
                        $params[] = password_hash($password, PASSWORD_DEFAULT);
                        $types .= "s";
                    }
                    $sql .= " WHERE ID=?";
                    $params[] = $userID;
                    $types .= "i";
                } else {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "INSERT INTO " . tn('TBL_USERS') . " (NAME, MOBILE, LOGIN_ID, EMAIL, COMPANY_NAME, PASSWORD, ROLE, TEAM_ID, PACKAGE, STATUS, COMPANY, JOIN_DATE)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $params = [$name, $mobile, $login_id, $email_addr, $company_name_db, $hashed, $role, $team_id, $package, $status, $company];
                    $types = "sssssssisss";
                }

                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, $types, ...$params);
                if (mysqli_stmt_execute($stmt)) {
                    $Message = $isEdit ? "User updated successfully!" : "New user created successfully!";
                    $type = "success";
                    if (!$isEdit) {
                        $_POST = [];
                        header("Location: users_view.php?created=1"); exit;
                    }
                } else {
                    $errno = mysqli_errno($link);
                    if ($errno === 1062) {
                        $Message = "A user with this mobile, login ID, or email already exists.";
                        $type = "warning";
                    } else {
                        $Message = "Database error: " . mysqli_error($link);
                        $type = "danger";
                    }
                }
                if ($stmt) mysqli_stmt_close($stmt);
            }
        }
        if ($Message && $type !== "success") $type = $type ?: "danger";
    }
}
?>
<?php $pageTitle = ($isEdit ? 'Edit' : 'Add New') . ' User - CallNow CRM'; include '../../php_scripts/header.php'; ?>

<div class="container-fluid px-3 py-3" style="max-width: 1000px;">

    <!-- Zoho CRM Module Header -->
    <div class="module-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/users/users_view.php') ?>">User Accounts</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? 'Edit User' : 'New User' ?></li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2">
                <h1 class="page-title mb-0">
                    <i class="bi <?= $isEdit ? 'bi-person-gear text-primary' : 'bi-person-plus text-primary' ?> me-2"></i>
                    <?= $isEdit ? 'Edit User Account' : 'Create New User' ?>
                </h1>
                <?php if ($isEdit): ?>
                    <span class="badge bg-secondary-subtle text-secondary border">ID: #<?= (int)$userID ?></span>
                <?php endif; ?>
            </div>
            <p class="text-muted small mb-0"><?= $isEdit ? 'Modify user details, credentials, and access configuration' : 'Set up a new telecalling agent or supervisor account' ?></p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= url('modules/users/users_view.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Directory
            </a>
        </div>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> New user created successfully!
            <button class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($Message): ?>
        <div class="alert alert-<?= htmlspecialchars($type ?: 'info'); ?> alert-dismissible fade show mb-3" role="alert">
            <i class="bi <?= $type === 'success' ? 'bi-check-circle-fill' : ($type === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-x-circle-fill') ?> me-2"></i>
            <?= htmlspecialchars($Message); ?>
            <button class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- User Edit Form Card -->
    <div class="crm-card">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <span class="fw-semibold text-dark">User Account Information</span>
            <span class="text-muted small"><span class="text-danger">*</span> Required fields</span>
        </div>
        <div class="card-body p-4">
            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                <!-- 1. Personal Information -->
                <div class="mb-4">
                    <div class="d-flex align-items-center gap-2 pb-2 mb-3 border-bottom">
                        <i class="bi bi-person text-primary fs-5"></i>
                        <h6 class="fw-bold mb-0 text-dark">Personal Information</h6>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. John Doe" value="<?= htmlspecialchars($editUser['NAME'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Mobile Number <span class="text-danger">*</span></label>
                            <input type="text" name="mobile" class="form-control form-control-sm font-monospace" placeholder="10-digit mobile number" pattern="[6-9][0-9]{9}" value="<?= htmlspecialchars($editUser['MOBILE'] ?? '') ?>" required>
                        </div>
                    </div>
                </div>

                <!-- 2. Account & Credentials -->
                <div class="mb-4">
                    <div class="d-flex align-items-center gap-2 pb-2 mb-3 border-bottom">
                        <i class="bi bi-shield-lock text-primary fs-5"></i>
                        <h6 class="fw-bold mb-0 text-dark">Account Credentials</h6>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Login ID (Email) <span class="text-danger">*</span></label>
                            <input type="email" name="username" class="form-control form-control-sm" placeholder="e.g. agent@company.com" value="<?= htmlspecialchars($editUser['LOGIN_ID'] ?? '') ?>" required>
                            <div class="text-muted small mt-1">Used as primary username for portal sign-in</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">
                                Password <?= !$isEdit ? '<span class="text-danger">*</span>' : '<span class="text-muted">(leave blank to keep current)</span>' ?>
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="password" name="password" id="passField" class="form-control" placeholder="Min. 6 characters" <?= !$isEdit ? 'required' : '' ?> minlength="6">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePass()" id="passToggle">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Alternate Email <span class="text-muted">(Optional)</span></label>
                            <input type="email" name="email" class="form-control form-control-sm" placeholder="e.g. backup@email.com" value="<?= htmlspecialchars($editUser['EMAIL'] ?? '') ?>">
                            <div class="text-muted small mt-1">Optional secondary notification address</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Company / Branch Name <span class="text-muted">(Optional)</span></label>
                            <input type="text" name="company_name" class="form-control form-control-sm" placeholder="e.g. North Region Office" value="<?= htmlspecialchars($editUser['COMPANY_NAME'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- 3. Role & Team Assignment -->
                <div class="mb-4">
                    <div class="d-flex align-items-center gap-2 pb-2 mb-3 border-bottom">
                        <i class="bi bi-diagram-3 text-primary fs-5"></i>
                        <h6 class="fw-bold mb-0 text-dark">Role & Team Assignment</h6>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">System Role <span class="text-danger">*</span></label>
                            <?php if (isAdmin() || isSuperAdmin()): ?>
                                <select name="role" class="form-select form-select-sm" required>
                                    <option value="">-- Select Role --</option>
                                    <?php foreach ($allRoles as $r): ?>
                                        <?php $sel = ($editUser['ROLE'] ?? '') === $r['role_name'] ? 'selected' : ''; ?>
                                        <option value="<?= htmlspecialchars($r['role_name']) ?>" <?= $sel ?>>
                                            <?= htmlspecialchars($r['role_name']) ?>
                                            <?= $r['description'] ? '— ' . htmlspecialchars($r['description']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="hidden" name="role" value="<?= htmlspecialchars($editUser['ROLE'] ?? 'Officer') ?>">
                                <div class="form-control form-control-sm bg-light text-muted">
                                    <?= htmlspecialchars($editUser['ROLE'] ?? 'Officer') ?> (Telecalling Member)
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Operational Team <span class="text-danger">*</span></label>
                            <select name="team_id" class="form-select form-select-sm" <?= USER_ROLE==='Supervisor'?'disabled':'' ?> required>
                                <option value="">-- Select Team --</option>
                                <?php foreach($allTeams as $t): ?>
                                    <option value="<?= (int)$t['ID'] ?>" <?= ($editUser['TEAM_ID'] ?? USER_TEAM_ID) == $t['ID'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['NAME']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (USER_ROLE==='Supervisor'): ?>
                                <input type="hidden" name="team_id" value="<?= USER_TEAM_ID ?>">
                                <div class="text-muted small mt-1">Assigned directly to your supervisor team</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 4. Account Settings -->
                <div class="mb-4">
                    <div class="d-flex align-items-center gap-2 pb-2 mb-3 border-bottom">
                        <i class="bi bi-sliders text-primary fs-5"></i>
                        <h6 class="fw-bold mb-0 text-dark">Operational Configuration</h6>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Package Tier <span class="text-danger">*</span></label>
                            <input type="text" name="package" class="form-control form-control-sm" placeholder="e.g. Standard, Premium, Enterprise" value="<?= htmlspecialchars($editUser['PACKAGE'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Account Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="Active" <?= ($editUser['STATUS'] ?? '')=='Active'?'selected':'' ?>>Active (Enabled)</option>
                                <option value="Inactive" <?= ($editUser['STATUS'] ?? '')=='Inactive'?'selected':'' ?>>Inactive (Deactivated)</option>
                                <option value="Suspended" <?= ($editUser['STATUS'] ?? '')=='Suspended'?'selected':'' ?>>Suspended (Access Blocked)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Form Action Buttons -->
                <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                    <a href="<?= url('modules/users/users_view.php') ?>" class="btn btn-outline-secondary btn-sm px-3">
                        Cancel
                    </a>
                    <button type="submit" name="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi <?= $isEdit ? 'bi-check-lg' : 'bi-person-plus' ?> me-1"></i>
                        <?= $isEdit ? 'Save Changes' : 'Create Account' ?>
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>

<script>
function togglePass() {
    var f = document.getElementById('passField');
    var t = document.getElementById('passToggle');
    if (f.type === 'password') {
        f.type = 'text';
        t.innerHTML = '<i class="bi bi-eye-slash"></i>';
    } else {
        f.type = 'password';
        t.innerHTML = '<i class="bi bi-eye"></i>';
    }
}
</script>
