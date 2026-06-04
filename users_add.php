<?php
require_once 'php_scripts/auth.php';
require_once 'php_scripts/team_auth.php'; // for USER_ROLE, USER_TEAM_ID

// === ACCESS CONTROL ===
$isEdit = isset($_GET['ID']);
$userID = $isEdit ? intval($_GET['ID']) : 0;

// Supervisors can only edit their own team members
if (USER_ROLE === 'Supervisor') {
    if ($isEdit) {
        $check = mysqli_prepare($link, "SELECT TEAM_ID FROM USERS WHERE ID = ?");
        mysqli_stmt_bind_param($check, "i", $userID);
        mysqli_stmt_execute($check);
        $res = mysqli_stmt_get_result($check);
        $user = mysqli_fetch_assoc($res);
        if (!$user || $user['TEAM_ID'] != USER_TEAM_ID) {
            $_SESSION['error'] = "Access denied! You can only edit your team members.";
            header("Location: ViewUsers.php"); exit;
        }
    }
} elseif (USER_ROLE !== 'Admin') {
    header("Location: dashboard.php"); exit;
}

$Message = ""; $type = "";

// Fetch all teams (Admin sees all, Supervisor sees only own)
if (USER_ROLE === 'Admin') {
    $teams_result = mysqli_query($link, "SELECT ID, NAME FROM TEAMS ORDER BY NAME");
} else {
    $teams_result = mysqli_query($link, "SELECT ID, NAME FROM TEAMS WHERE ID = " . USER_TEAM_ID);
}
$teams = mysqli_fetch_all($teams_result, MYSQLI_ASSOC);

// Edit mode: fetch user
$editUser = null;
if ($isEdit) {
    $stmt = mysqli_prepare($link, "SELECT u.*, t.NAME as TEAM_NAME FROM USERS u LEFT JOIN TEAMS t ON u.TEAM_ID = t.ID WHERE u.ID = ?");
    mysqli_stmt_bind_param($stmt, "i", $userID);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $editUser = mysqli_fetch_assoc($result);
    if (!$editUser) { header("Location: users_view.php"); exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $name     = trim($_POST['name']);
    $mobile   = trim($_POST['mobile']);
    $login_id = trim($_POST['username']);
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'];
    $team_id  = $_POST['team_id'];
    $package  = trim($_POST['package']);
    $status   = $_POST['status'];
    $company  = $editUser['COMPANY'] ?? $_SESSION["CompanyName"] ?? 'CallNow';

    // Validation
    if (!preg_match("/^[a-zA-Z ]+$/", $name)) $Message = "Invalid name";
    elseif (!preg_match("/^[6-9][0-9]{9}$/", $mobile)) $Message = "Invalid mobile number";
    elseif (!filter_var($login_id, FILTER_VALIDATE_EMAIL)) $Message = "Invalid email";
    elseif (!$isEdit && strlen($password) < 6) $Message = "Password must be 6+ characters";
    elseif (empty($role) || empty($team_id) || empty($package)) $Message = "All fields are required";
    else {
        // Check duplicate mobile/email
        $checkSql = "SELECT ID FROM USERS WHERE (MOBILE = ? OR LOGIN_ID = ?) AND ID != ?";
        $stmt = mysqli_prepare($link, $checkSql);
        mysqli_stmt_bind_param($stmt, "ssi", $mobile, $login_id, $userID);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        if (mysqli_stmt_num_rows($stmt) > 0) {
            $Message = "Mobile or Email already exists!";
            $type = "warning";
        } else {
            if ($isEdit) {
                $sql = "UPDATE USERS SET NAME=?, MOBILE=?, LOGIN_ID=?, ROLE=?, TEAM_ID=?, PACKAGE=?, STATUS=?, COMPANY=?";
                $params = [$name, $mobile, $login_id, $role, $team_id, $package, $status, $company];
                $types = "ssssisss";

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
                $sql = "INSERT INTO USERS (NAME, MOBILE, LOGIN_ID, PASSWORD, ROLE, TEAM_ID, PACKAGE, STATUS, COMPANY, JOIN_DATE)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $params = [$name, $mobile, $login_id, $hashed, $role, $team_id, $package, $status, $company];
                $types = "ssssissss";
            }

            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            if (mysqli_stmt_execute($stmt)) {
                $Message = $isEdit ? "User updated successfully!" : "New user created successfully!";
                $type = "success";
                if (!$isEdit) {
                    $_POST = [];
                    header("Location: users_add.php"); exit;
                }
            } else {
                $Message = "Database error!";
                $type = "danger";
            }
        }
    }
    if ($Message && $type !== "success") $type = $type ?: "danger";
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isEdit ? 'Edit' : 'Add New' ?> User - CallNow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    :root {
    --primary: #2563EB;
    --primary-dark: #1E40AF;
    --secondary: #475569;
    --accent: #0EA5E9;
    --success: #059669;
    --warning: #D97706;
    --danger: #DC2626;

    --card-bg: #FFFFFF;
    --bg-light: #F8FAFC;
    --border: #E2E8F0;
    --text-main: #0F172A;
    --text-muted: #64748B;
}
    
    
        body {
            background: var(--bg-light);
            color: var(--text-main);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
        }
        /* Compact header bar */
        .page-header-bar {
            background: var(--card-bg);
            border-bottom: 1px solid var(--border);
        }
        .page-header-bar .title {
            color: var(--primary-dark);
        }
        .page-header-bar .subtitle {
            color: var(--text-muted);
        }

        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 0.9rem;
            box-shadow: 0 8px 20px rgba(15,23,42,0.06);
        }
        
        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .form-label {
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }

        .form-control,
        .form-select {
            font-size: 0.9rem;
            padding: 0.4rem 0.6rem;
        }

        .btn-main {
            border-radius: 999px;
            font-size: 0.9rem;
            padding: 0.4rem 1.1rem;
        }

        .btn-icon {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .alert {
            font-size: 0.85rem;
            padding: 0.5rem 0.75rem;
        }
        
        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        
        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
        }
        .btn-outline-primary:hover {
            background: var(--primary);
            color: white;
        }
        
        .btn-outline-secondary {
            color: var(--secondary);
            border-color: var(--secondary);
        }
        .btn-outline-secondary:hover {
            background: var(--secondary);
            color: white;
        }
                
    </style>
</head>
<body>
<?php include 'php_scripts/header.php'; ?>

<!-- Compact header bar -->
<div class="page-header-bar py-2 mb-3">
    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="title">
                    <i class="bi bi-person-gear me-1"></i> <strong>
                    <?= $isEdit ? 'Edit User' : 'Add New User' ?> </strong>
                </div>
                <div class="subtitle text-muted">
                    <?= USER_ROLE === 'Supervisor'
                        ? 'Manage your team members'
                        : 'Create or update an app user account'
                    ?>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary btn-sm btn-icon">
                    <i class="bi bi-speedometer2"></i><span> Dashboard</span>
                </a>
                <a href="users_view.php" class="btn btn-outline-primary btn-sm btn-icon">
                    <i class="bi bi-people"></i><span> Users</span>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container py-3">
    <div class="row justify-content-center">
        <div class="col-lg-7 col-xl-6">

            <?php if ($Message): ?>
                <div class="alert alert-<?= $type ?> alert-dismissible fade show rounded-3 shadow-sm mb-3">
                    <strong><?= ucfirst($type) ?>:</strong> <?= $Message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="form-card p-3 p-md-4">
                <form method="POST" novalidate>
                    <div class="row g-3">
                        <!-- Name -->
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= htmlspecialchars($editUser['NAME'] ?? '') ?>" required>
                        </div>

                        <!-- Mobile -->
                        <div class="col-md-6">
                            <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
                            <input type="text" name="mobile" class="form-control"
                                   pattern="[6-9][0-9]{9}" value="<?= $editUser['MOBILE'] ?? '' ?>" required>
                        </div>

                        <!-- Email / Login ID -->
                        <div class="col-md-6">
                            <label class="form-label">Email (Login ID) <span class="text-danger">*</span></label>
                            <input type="email" name="username" class="form-control"
                                   value="<?= $editUser['LOGIN_ID'] ?? '' ?>" required>
                        </div>

                        <!-- Password -->
                        <div class="col-md-6">
                            <label class="form-label">
                                <?= $isEdit ? 'New Password (leave blank to keep)' : 'Password' ?>
                                <?= !$isEdit ? '<span class="text-danger">*</span>' : '' ?>
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="password" name="password" id="passField" class="form-control"
                                       <?= !$isEdit ? 'required' : '' ?> minlength="6">
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        onclick="document.getElementById('passField').type = document.getElementById('passField').type === 'password' ? 'text' : 'password'">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Role -->
                        <?php if (USER_ROLE === 'Admin'): ?>
                            <div class="col-md-6">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select name="role" class="form-select" required>
                                    <option value="">Select Role</option>
                                    <option value="Admin" <?= ($editUser['ROLE'] ?? '')=='Admin'?'selected':'' ?>>Admin</option>
                                    <option value="Manager" <?= ($editUser['ROLE'] ?? '')=='Manager'?'selected':'' ?>>Manager</option>
                                    <option value="Supervisor" <?= ($editUser['ROLE'] ?? '')=='Supervisor'?'selected':'' ?>>Supervisor</option>
                                    <option value="Officer" <?= ($editUser['ROLE'] ?? '')=='Officer'?'selected':'' ?>>Officer</option>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="role" value="Officer">
                            <div class="col-md-6">
                                <label class="form-label">Role</label>
                                <div class="form-control bg-light">Officer (Team Member)</div>
                            </div>
                        <?php endif; ?>

                        <!-- Team -->
                        <div class="col-md-6">
                            <label class="form-label">Team <span class="text-danger">*</span></label>
                            <select name="team_id" class="form-select" <?= USER_ROLE==='Supervisor'?'disabled':'' ?> required>
                                <?php foreach($teams as $t): ?>
                                    <option value="<?= $t['ID'] ?>"
                                        <?= ($editUser['TEAM_ID'] ?? USER_TEAM_ID) == $t['ID'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['NAME']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (USER_ROLE==='Supervisor'): ?>
                                <small class="text-muted">
                                    You can only assign to your team:
                                    <strong><?= htmlspecialchars($teams[0]['NAME'] ?? '') ?></strong>
                                </small>
                                <input type="hidden" name="team_id" value="<?= USER_TEAM_ID ?>">
                            <?php endif; ?>
                        </div>

                        <!-- Package -->
                        <div class="col-md-6">
                            <label class="form-label">Package <span class="text-danger">*</span></label>
                            <input type="text" name="package" class="form-control"
                                   value="<?= htmlspecialchars($editUser['PACKAGE'] ?? '') ?>" required>
                        </div>

                        <!-- Status -->
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="Active" <?= ($editUser['STATUS'] ?? '')=='Active'?'selected':'' ?>>Active</option>
                                <option value="Inactive" <?= ($editUser['STATUS'] ?? '')=='Inactive'?'selected':'' ?>>Inactive</option>
                                <option value="Suspended" <?= ($editUser['STATUS'] ?? '')=='Suspended'?'selected':'' ?>>Suspended</option>
                            </select>
                        </div>

                        <!-- Buttons -->
                        <div class="col-12 text-center mt-3">
                            <button type="submit" name="submit" class="btn btn-primary btn-main btn-icon me-2">
                                <i class="bi bi-save"></i>
                                <span><?= $isEdit ? 'Update User' : 'Create User' ?></span>
                            </button>
                            <a href="users_view.php" class="btn btn-outline-secondary btn-main btn-icon">
                                <i class="bi bi-arrow-left"></i>
                                <span>Back to Users</span>
                            </a>
                        </div>

                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<?php include 'php_scripts/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
