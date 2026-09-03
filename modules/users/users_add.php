<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php';
require_once '../../php_scripts/super_admin.php';

$isEdit = isset($_GET['ID']);
$userID = $isEdit ? intval($_GET['ID']) : 0;

requirePermission('manage_TBL_USERS');

if (!$isEdit) {
    $limitMsg = checkUserLimit($link, USER_ID);
    if ($limitMsg) {
        $_SESSION['access_denied_message'] = $limitMsg;
        appRedirect('dashboard.php');
    }
}

$Message = ""; $type = "";

if (USER_ROLE === 'Admin') {
    $TBL_TEAMS_result = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " ORDER BY NAME");
} elseif (USER_TEAM_ID) {
    $TBL_TEAMS_result = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " WHERE ID = " . (int)USER_TEAM_ID);
} else {
    $TBL_TEAMS_result = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " ORDER BY NAME");
}
$TBL_TEAMS = mysqli_fetch_all($TBL_TEAMS_result, MYSQLI_ASSOC);

// Fetch dynamic TBL_ROLES from DB
$allTBL_ROLES = [];
$ar = mysqli_query($link, "SELECT role_name, description FROM " . tn('TBL_ROLES') . " WHERE role_name != 'Super Admin' ORDER BY is_system DESC, id");
if ($ar) while ($a = mysqli_fetch_assoc($ar)) $allTBL_ROLES[] = $a;

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
        $name       = trim($_POST['name']);
        $mobile     = trim($_POST['mobile']);
        $login_id   = trim($_POST['username']);
        $email_addr = trim($_POST['email']);
        $company_name = trim($_POST['company_name']);
        $password   = $_POST['password'] ?? '';
        $role       = $_POST['role'];
        $team_id    = $_POST['team_id'];
        $package    = trim($_POST['package']);
        $status     = $_POST['status'];
        $company    = $editUser['COMPANY'] ?? $_SESSION["CompanyName"] ?? 'CallNow';

        if (!preg_match("/^[a-zA-Z ]+$/", $name)) $Message = "Invalid name";
        elseif (!preg_match("/^[6-9][0-9]{9}$/", $mobile)) $Message = "Invalid mobile number";
        elseif (!filter_var($login_id, FILTER_VALIDATE_EMAIL)) $Message = "Invalid login email";
        elseif ($email_addr !== '' && !filter_var($email_addr, FILTER_VALIDATE_EMAIL)) $Message = "Invalid alternate email";
        elseif (!$isEdit && strlen($password) < 6) $Message = "Password must be 6+ characters";
        elseif (empty($role) || empty($team_id) || empty($package)) $Message = "All required fields must be filled";
        else {
            $checkSql = "SELECT ID FROM " . tn('TBL_USERS') . " WHERE (MOBILE = ? OR LOGIN_ID = ?) AND ID != ?";
            $stmt = mysqli_prepare($link, $checkSql);
            mysqli_stmt_bind_param($stmt, "ssi", $mobile, $login_id, $userID);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            if (mysqli_stmt_num_rows($stmt) > 0) {
                $Message = "Mobile or Login ID already exists!";
                $type = "warning";
            } else {
                if ($isEdit) {
                    $sql = "UPDATE " . tn('TBL_USERS') . " SET NAME=?, MOBILE=?, LOGIN_ID=?, EMAIL=?, COMPANY_NAME=?, ROLE=?, TEAM_ID=?, PACKAGE=?, STATUS=?, COMPANY=?";
                    $params = [$name, $mobile, $login_id, $email_addr, $company_name, $role, $team_id, $package, $status, $company];
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
                    $params = [$name, $mobile, $login_id, $email_addr, $company_name, $hashed, $role, $team_id, $package, $status, $company];
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
                    $Message = "Database error!";
                    $type = "danger";
                }
            }
        }
        if ($Message && $type !== "success") $type = $type ?: "danger";
    }
}

?>
<?php $pageTitle = ($isEdit ? 'Edit' : 'Add New') . ' User - CallNow'; include '../../php_scripts/header.php'; ?>

<style>
:root {
    --ua-accent: var(--accent);
    --ua-accent-dark: var(--accent-hover, #4f46e5);
    --ua-ink: #1e1b4b;
    --ua-ink-soft: #6b6890;
    --ua-soft: #f0f2ff;
    --ua-border: #e2e4f0;
}

.ua-header {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
    border-radius: 1rem;
    padding: 1.5rem 2rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.ua-header::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.3;
}
.ua-header-content {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}
.ua-header-left h1 {
    font-size: 1.35rem;
    font-weight: 700;
    color: #fff;
    margin: 0 0 0.2rem 0;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.ua-header-left h1 i { font-size: 1.4rem; }
.ua-header-left p {
    color: rgba(255,255,255,0.7);
    font-size: 0.8125rem;
    margin: 0;
}
.ua-header-actions { display: flex; gap: 0.5rem; }
.ua-header-actions .btn {
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.2);
    color: #fff;
    backdrop-filter: blur(4px);
    font-size: 0.75rem;
    padding: 0.4rem 0.9rem;
    border-radius: 0.5rem;
    transition: all 0.15s ease;
    text-decoration: none;
}
.ua-header-actions .btn:hover {
    background: rgba(255,255,255,0.25);
    border-color: rgba(255,255,255,0.35);
    color: #fff;
    transform: translateY(-1px);
}
.ua-header-actions .btn-ua-outline {
    background: transparent;
}

.ua-card {
    background: #fff;
    border: 1px solid var(--ua-border);
    border-radius: 0.875rem;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.ua-card-body { padding: 1.75rem 2rem; }

.ua-section-title {
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--ua-ink);
    margin: 0 0 1rem 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    letter-spacing: -0.01em;
}
.ua-section-title i {
    width: 28px; height: 28px;
    border-radius: 0.5rem;
    background: var(--ua-soft);
    color: var(--ua-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8125rem;
}
.ua-section-divider {
    border: 0;
    border-top: 1px solid var(--ua-border);
    margin: 1.25rem 0;
}

.ua-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--ua-ink);
    margin-bottom: 0.3rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}
.ua-label .required { color: #ef4444; }
.ua-label .optional {
    font-weight: 400;
    color: var(--ua-ink-soft);
    font-size: 0.6875rem;
}

.ua-input, .ua-select {
    border: 1px solid var(--ua-border) !important;
    border-radius: 0.5rem !important;
    font-size: 0.8125rem !important;
    color: var(--ua-ink) !important;
    padding: 0.45rem 0.75rem !important;
    background: #fff !important;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.ua-input:focus, .ua-select:focus {
    border-color: var(--ua-accent) !important;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.12) !important;
    outline: none;
}
.ua-input-group {
    display: flex;
    align-items: stretch;
}
.ua-input-group .ua-input {
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;
}
.ua-input-group-btn {
    display: flex;
    align-items: center;
    padding: 0 0.65rem;
    border: 1px solid var(--ua-border);
    border-left: 0;
    border-radius: 0 0.5rem 0.5rem 0;
    background: #f9fafb;
    color: var(--ua-ink-soft);
    cursor: pointer;
    font-size: 0.8125rem;
    transition: all 0.12s ease;
}
.ua-input-group-btn:hover {
    background: var(--ua-soft);
    color: var(--ua-accent);
}
.ua-input-group-btn i { font-size: 1rem; }

.ua-help {
    font-size: 0.6875rem;
    color: var(--ua-ink-soft);
    margin-top: 0.2rem;
}

.ua-btn-primary {
    background: linear-gradient(135deg, var(--ua-accent), var(--ua-accent-dark)) !important;
    border: none !important;
    color: #fff !important;
    border-radius: 0.5rem !important;
    font-size: 0.8125rem !important;
    font-weight: 600 !important;
    padding: 0.5rem 1.5rem !important;
    transition: all 0.15s ease !important;
    box-shadow: 0 2px 8px rgba(99,102,241,0.25) !important;
}
.ua-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(99,102,241,0.35) !important;
}
.ua-btn-secondary {
    border: 1px solid var(--ua-border) !important;
    border-radius: 0.5rem !important;
    font-size: 0.8125rem !important;
    font-weight: 500 !important;
    padding: 0.5rem 1.25rem !important;
    color: var(--ua-ink-soft) !important;
    background: #fff !important;
    transition: all 0.12s ease !important;
    text-decoration: none !important;
}
.ua-btn-secondary:hover {
    border-color: var(--ua-accent) !important;
    color: var(--ua-accent) !important;
    background: var(--ua-soft) !important;
}

.ua-alert {
    border-radius: 0.625rem;
    font-size: 0.8125rem;
    padding: 0.75rem 1rem;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

@media (max-width: 575px) {
    .ua-card-body { padding: 1.25rem; }
    .ua-header-content { flex-direction: column; align-items: stretch; }
    .ua-header-actions { justify-content: stretch; }
    .ua-header-actions .btn { flex: 1; text-align: center; }
}

/* ── Success banner on redirect from TBL_USERS_view ── */
.ua-success-banner {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    border: 1px solid #6ee7b7;
    border-radius: 0.75rem;
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: #065f46;
    font-size: 0.875rem;
    font-weight: 500;
}
.ua-success-banner i { font-size: 1.25rem; }
</style>

<div class="container page-wrapper">

    <!-- ─── Header ─── -->
    <div class="ua-header">
        <div class="ua-header-content">
            <div class="ua-header-left">
                <h1>
                    <i class="bi <?= $isEdit ? 'bi-person-gear' : 'bi-person-plus-fill' ?>"></i>
                    <?= $isEdit ? 'Edit User' : 'Add New User' ?>
                </h1>
                <p><?= $isEdit ? 'Update user account details' : 'Create a new app user account' ?></p>
            </div>
            <div class="ua-header-actions">
                <a href="<?= url('modules/users/users_view.php') ?>" class="btn btn-ua-outline"><i class="bi bi-people"></i> View All TBL_USERS</a>
                <a href="<?= url('dashboard.php') ?>" class="btn btn-ua-outline"><i class="bi bi-grid"></i> Dashboard</a>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="ua-success-banner">
            <i class="bi bi-check-circle-fill"></i> New user created successfully!
        </div>
    <?php endif; ?>

    <?php if ($Message): ?>
        <div class="ua-alert" style="background:<?= $type==='success'?'#d1fae5':($type==='warning'?'#fef3c7':'#fee2e2') ?>;border:1px solid <?= $type==='success'?'#6ee7b7':($type==='warning'?'#fcd34d':'#fca5a5') ?>;color:<?= $type==='success'?'#065f46':($type==='warning'?'#92400e':'#991b1b') ?>;">
            <i class="bi <?= $type==='success'?'bi-check-circle-fill':($type==='warning'?'bi-exclamation-triangle-fill':'bi-x-circle-fill') ?>"></i>
            <?= $Message ?>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="font-size:0.7rem;"></button>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-9 col-xl-8">

            <div class="ua-card">
                <div class="ua-card-body">
                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                        <!-- ── Section: Personal Info ── -->
                        <div class="ua-section-title"><i class="bi bi-person"></i> Personal Information</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="ua-label">Full Name <span class="required">*</span></label>
                                <input type="text" name="name" class="form-control ua-input"
                                       placeholder="e.g. John Doe"
                                       value="<?= htmlspecialchars($editUser['NAME'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="ua-label">Mobile Number <span class="required">*</span></label>
                                <input type="text" name="mobile" class="form-control ua-input"
                                       placeholder="e.g. 9876543210"
                                       pattern="[6-9][0-9]{9}"
                                       value="<?= $editUser['MOBILE'] ?? '' ?>" required>
                            </div>
                        </div>

                        <hr class="ua-section-divider">

                        <!-- ── Section: Account Details ── -->
                        <div class="ua-section-title"><i class="bi bi-shield-lock"></i> Account Details</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="ua-label">Login ID (Email) <span class="required">*</span></label>
                                <input type="email" name="username" class="form-control ua-input"
                                       placeholder="e.g. john@company.com"
                                       value="<?= $editUser['LOGIN_ID'] ?? '' ?>" required>
                                <div class="ua-help">Used for signing into the app</div>
                            </div>
                            <div class="col-md-6">
                                <label class="ua-label">
                                    Password <?= !$isEdit ? '<span class="required">*</span>' : '<span class="optional">(leave blank to keep)</span>' ?>
                                </label>
                                <div class="ua-input-group">
                                    <input type="password" name="password" id="passField" class="form-control ua-input"
                                           placeholder="Min. 6 characters"
                                           <?= !$isEdit ? 'required' : '' ?> minlength="6">
                                    <span class="ua-input-group-btn" onclick="togglePass()" id="passToggle">
                                        <i class="bi bi-eye"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="ua-label">Alternate Email <span class="optional">(optional)</span></label>
                                <input type="email" name="email" class="form-control ua-input"
                                       placeholder="e.g. personal@email.com"
                                       value="<?= $editUser['EMAIL'] ?? '' ?>">
                                <div class="ua-help">Separate from the login email above</div>
                            </div>
                            <div class="col-md-6">
                                <label class="ua-label">Company Name <span class="optional">(optional)</span></label>
                                <input type="text" name="company_name" class="form-control ua-input"
                                       placeholder="e.g. Acme Corp"
                                       value="<?= $editUser['COMPANY_NAME'] ?? '' ?>">
                            </div>
                        </div>

                        <hr class="ua-section-divider">

                        <!-- ── Section: Role & Access ── -->
                        <div class="ua-section-title"><i class="bi bi-shield-check"></i> Role &amp; Access</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="ua-label">Role <span class="required">*</span></label>
                                <?php if (USER_ROLE === 'Admin'): ?>
                                    <select name="role" class="form-select ua-select" required>
                                        <option value="">-- Select Role --</option>
                                        <?php foreach ($allTBL_ROLES as $r): ?>
                                            <?php $sel = ($editUser['ROLE'] ?? '') === $r['role_name'] ? 'selected' : ''; ?>
                                            <option value="<?= htmlspecialchars($r['role_name']) ?>" <?= $sel ?>>
                                                <?= htmlspecialchars($r['role_name']) ?>
                                                <?= $r['description'] ? '— ' . htmlspecialchars($r['description']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="hidden" name="role" value="<?= htmlspecialchars($editUser['ROLE'] ?? 'Officer') ?>">
                                    <div class="ua-input" style="background:#f9fafb !important;cursor:not-allowed;">
                                        <?= htmlspecialchars($editUser['ROLE'] ?? 'Officer') ?> (Team Member)
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="ua-label">Team <span class="required">*</span></label>
                                <select name="team_id" class="form-select ua-select"
                                        <?= USER_ROLE==='Supervisor'?'disabled':'' ?> required>
                                    <option value="">-- Select Team --</option>
                                    <?php foreach($TBL_TEAMS as $t): ?>
                                        <option value="<?= $t['ID'] ?>"
                                            <?= ($editUser['TEAM_ID'] ?? USER_TEAM_ID) == $t['ID'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($t['NAME']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (USER_ROLE==='Supervisor'): ?>
                                    <div class="ua-help">
                                        Assigned to your team: <strong><?= htmlspecialchars($TBL_TEAMS[0]['NAME'] ?? '') ?></strong>
                                    </div>
                                    <input type="hidden" name="team_id" value="<?= USER_TEAM_ID ?>">
                                <?php endif; ?>
                            </div>
                        </div>

                        <hr class="ua-section-divider">

                        <!-- ── Section: Account Status ── -->
                        <div class="ua-section-title"><i class="bi bi-sliders"></i> Account Settings</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="ua-label">Package <span class="required">*</span></label>
                                <input type="text" name="package" class="form-control ua-input"
                                       placeholder="e.g. Premium, Basic"
                                       value="<?= htmlspecialchars($editUser['PACKAGE'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="ua-label">Status</label>
                                <select name="status" class="form-select ua-select">
                                    <option value="Active" <?= ($editUser['STATUS'] ?? '')=='Active'?'selected':'' ?>>Active</option>
                                    <option value="Inactive" <?= ($editUser['STATUS'] ?? '')=='Inactive'?'selected':'' ?>>Inactive</option>
                                    <option value="Suspended" <?= ($editUser['STATUS'] ?? '')=='Suspended'?'selected':'' ?>>Suspended</option>
                                </select>
                            </div>
                        </div>

                        <hr class="ua-section-divider">

                        <!-- ── Buttons ── -->
                        <div class="d-flex justify-content-center gap-3 pt-2">
                            <a href="<?= url('modules/users/users_view.php') ?>" class="ua-btn-secondary">
                                <i class="bi bi-arrow-left"></i> Cancel
                            </a>
                            <button type="submit" name="submit" class="ua-btn-primary">
                                <i class="bi <?= $isEdit ? 'bi-check-lg' : 'bi-person-plus' ?>"></i>
                                <?= $isEdit ? 'Update User' : 'Create User' ?>
                            </button>
                        </div>

                    </form>
                </div>
            </div>

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
