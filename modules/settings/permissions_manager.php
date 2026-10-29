<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../php_scripts/team_auth.php';
require_once __DIR__ . '/../../php_scripts/permissions.php';
requireRole('Admin');

$msg = '';
$msg_type = '';
$tab = $_GET['tab'] ?? 'role_perms';
$selectedRole = $_GET['role'] ?? null;
$selectedUser = isset($_GET['user']) ? (int)$_GET['user'] : null;

// ─── Handle POST actions ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid session token';
        $msg_type = 'danger';
    } else {
        $action = $_POST['pm_action'] ?? '';

        // Role permission matrix
        if ($action === 'save_role_perms') {
            $role = $_POST['role'] ?? '';
            $cs = $link->prepare("SELECT role_name FROM " . tn('TBL_ROLES') . " WHERE role_name = ?");
            $cs->bind_param('s', $role);
            $cs->execute();
            if (!$cs->get_result()->fetch_assoc()) {
                $msg = 'Role not found.';
                $msg_type = 'danger';
            } else {
                $stmt = $link->prepare("INSERT INTO " . tn('TBL_ROLE_PERMISSIONS') . " (role, permission_key, permission_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE permission_value = VALUES(permission_value)");
                foreach (array_keys(getPermissionSeed()) as $pk) {
                    $v = ($role === 'Admin') ? 1 : (isset($_POST["perm_$pk"]) ? 1 : 0);
                    $stmt->bind_param('ssi', $role, $pk, $v);
                    $stmt->execute();
                }
                clearRbacCache();
                $msg = "Permissions updated successfully for role '$role'.";
                $msg_type = 'success';
                logActivity($link, USER_ID, 'UPDATE', "Updated role permissions for $role");
            }
            $selectedRole = $role;
        }

        // Per-user override matrix
        if ($action === 'save_user_perms') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $role = $_POST['role'] ?? '';
            $us = $link->prepare("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID = ?");
            $us->bind_param('i', $uid);
            $us->execute();
            if (!$us->get_result()->fetch_assoc()) {
                $msg = 'User not found.';
                $msg_type = 'danger';
            } else {
                $stmt = $link->prepare("INSERT INTO " . tn('TBL_USER_PERMISSIONS') . " (user_id, permission_key, permission_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE permission_value = VALUES(permission_value)");
                foreach (array_keys(getPermissionSeed()) as $pk) {
                    $v = isset($_POST["uperm_$pk"]) ? 1 : 0;
                    $stmt->bind_param('iis', $uid, $pk, $v);
                    $stmt->execute();
                }
                clearRbacCache();
                $msg = "Access overrides updated for user #$uid.";
                $msg_type = 'success';
                logActivity($link, USER_ID, 'UPDATE', "Updated user permission overrides for user $uid");
            }
            $selectedUser = $uid;
            $selectedRole = $role;
        }

        // Clear per-user overrides
        if ($action === 'clear_user_perms') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $link->query("DELETE FROM " . tn('TBL_USER_PERMISSIONS') . " WHERE user_id = $uid");
            clearRbacCache();
            $msg = "Overrides cleared; user #$uid now inherits base role permissions.";
            $msg_type = 'success';
            $selectedUser = $uid;
        }

        // Assign role to user
        if ($action === 'assign_role') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $role = $_POST['role'] ?? '';
            $cs = $link->prepare("SELECT role_name, id FROM " . tn('TBL_ROLES') . " WHERE role_name = ?");
            $cs->bind_param('s', $role);
            $cs->execute();
            $cr = $cs->get_result()->fetch_assoc();
            if (!$cr) {
                $msg = 'Role not found.';
                $msg_type = 'danger';
            } else {
                $up = $link->prepare("UPDATE " . tn('TBL_USERS') . " SET ROLE = ?, role_id = ? WHERE ID = ?");
                $up->bind_param('sii', $role, $cr['id'], $uid);
                $up->execute();
                clearRbacCache();
                $msg = "User #$uid assigned to role '$role'.";
                $msg_type = 'success';
                logActivity($link, USER_ID, 'UPDATE', "Assigned user $uid to role $role");
            }
            $selectedUser = $uid;
        }

        // Add / edit role
        if ($action === 'save_role') {
            $roleName = trim($_POST['role_name'] ?? '');
            $roleDesc = trim($_POST['role_description'] ?? '');
            $roleId = (int)($_POST['role_id'] ?? 0);
            if ($roleName === '') {
                $msg = 'Role name is required.';
                $msg_type = 'danger';
            } else {
                if ($roleId > 0) {
                    $cs = $link->prepare("SELECT role_name, is_system FROM " . tn('TBL_ROLES') . " WHERE id = ?");
                    $cs->bind_param('i', $roleId);
                    $cs->execute();
                    $cr = $cs->get_result()->fetch_assoc();
                    if ($cr && $cr['is_system']) {
                        $msg = 'System roles cannot be renamed.';
                        $msg_type = 'danger';
                    } else {
                        $oldName = $cr['role_name'];
                        $s = $link->prepare("UPDATE " . tn('TBL_ROLES') . " SET role_name = ?, description = ? WHERE id = ?");
                        $s->bind_param('ssi', $roleName, $roleDesc, $roleId);
                        $s->execute();
                        $up1 = $link->prepare("UPDATE " . tn('TBL_ROLE_PERMISSIONS') . " SET role = ? WHERE role = ?");
                        $up1->bind_param('ss', $roleName, $oldName);
                        $up1->execute();
                        $up2 = $link->prepare("UPDATE " . tn('TBL_USERS') . " SET ROLE = ? WHERE ROLE = ?");
                        $up2->bind_param('ss', $roleName, $oldName);
                        $up2->execute();
                        clearRbacCache();
                        $msg = "Role '$oldName' updated to '$roleName'.";
                        $msg_type = 'success';
                        logActivity($link, USER_ID, 'UPDATE', "Renamed role $oldName to $roleName");
                    }
                } else {
                    $s = $link->prepare("INSERT INTO " . tn('TBL_ROLES') . " (role_name, description, is_system) VALUES (?, ?, 0)");
                    $s->bind_param('ss', $roleName, $roleDesc);
                    if ($s->execute()) {
                        $is2 = $link->prepare("INSERT IGNORE INTO " . tn('TBL_ROLE_PERMISSIONS') . " (role, permission_key, permission_value) VALUES (?, ?, 0)");
                        foreach (array_keys(getPermissionSeed()) as $pk) {
                            $is2->bind_param('ss', $roleName, $pk);
                            $is2->execute();
                        }
                        clearRbacCache();
                        $msg = "Role '$roleName' created successfully.";
                        $msg_type = 'success';
                        logActivity($link, USER_ID, 'INSERT', "Created role $roleName");
                    } else {
                        $msg = 'Role name already exists.';
                        $msg_type = 'danger';
                    }
                }
            }
        }

        // Delete role
        if ($action === 'delete_role') {
            $roleId = (int)($_POST['role_id'] ?? 0);
            $cs = $link->prepare("SELECT role_name, is_system FROM " . tn('TBL_ROLES') . " WHERE id = ?");
            $cs->bind_param('i', $roleId);
            $cs->execute();
            $cr = $cs->get_result()->fetch_assoc();
            if (!$cr) {
                $msg = 'Role not found.';
                $msg_type = 'danger';
            } elseif ($cr['is_system']) {
                $msg = 'System roles cannot be deleted.';
                $msg_type = 'danger';
            } else {
                $roleName = $cr['role_name'];
                $uc = $link->prepare("SELECT COUNT(*) as c FROM " . tn('TBL_USERS') . " WHERE ROLE = ?");
                $uc->bind_param('s', $roleName);
                $uc->execute();
                $ucr = $uc->get_result()->fetch_assoc();
                if ((int)$ucr['c'] > 0) {
                    $msg = "Cannot delete: {$ucr['c']} user(s) still assigned to this role. Please reassign them first.";
                    $msg_type = 'danger';
                } else {
                    $dp = $link->prepare("DELETE FROM " . tn('TBL_ROLE_PERMISSIONS') . " WHERE role = ?");
                    $dp->bind_param('s', $roleName);
                    $dp->execute();
                    $dr = $link->prepare("DELETE FROM " . tn('TBL_ROLES') . " WHERE id = ?");
                    $dr->bind_param('i', $roleId);
                    $dr->execute();
                    clearRbacCache();
                    $msg = "Role '$roleName' removed.";
                    $msg_type = 'success';
                    logActivity($link, USER_ID, 'DELETE', "Deleted role $roleName");
                }
            }
        }

        // Add feature
        if ($action === 'add_feature') {
            $pkey = strtolower(trim($_POST['perm_key'] ?? ''));
            $plabel = trim($_POST['perm_label'] ?? '');
            $pcat = trim($_POST['perm_category'] ?? 'General');
            $pdesc = trim($_POST['perm_description'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $pkey)) {
                $msg = 'Key must be lowercase letters, numbers, and underscores (starting with letter).';
                $msg_type = 'danger';
            } elseif ($plabel === '') {
                $msg = 'Label is required.';
                $msg_type = 'danger';
            } elseif (permissionExists($link, $pkey)) {
                $msg = "Permission key '$pkey' already exists.";
                $msg_type = 'danger';
            } else {
                $stmt = $link->prepare("INSERT INTO " . tn('TBL_PERMISSIONS') . " (permission_key, label, category, description, is_system) VALUES (?, ?, ?, ?, 0)");
                $stmt->bind_param('ssss', $pkey, $plabel, $pcat, $pdesc);
                $stmt->execute();
                $ins = $link->prepare("INSERT IGNORE INTO " . tn('TBL_ROLE_PERMISSIONS') . " (role, permission_key, permission_value) VALUES (?, ?, 0)");
                $rr = mysqli_query($link, "SELECT role_name FROM " . tn('TBL_ROLES'));
                while ($rr && $x = mysqli_fetch_assoc($rr)) {
                    $ins->bind_param('ss', $x['role_name'], $pkey);
                    $ins->execute();
                }
                clearRbacCache();
                $msg = "Permission feature '$pkey' registered.";
                $msg_type = 'success';
                logActivity($link, USER_ID, 'INSERT', "Added permission key $pkey");
            }
        }

        // Edit feature
        if ($action === 'edit_feature') {
            $pkey = $_POST['perm_key'] ?? '';
            $plabel = trim($_POST['perm_label'] ?? '');
            $pcat = trim($_POST['perm_category'] ?? 'General');
            $pdesc = trim($_POST['perm_description'] ?? '');
            $cs = $link->prepare("SELECT permission_key, is_system FROM " . tn('TBL_PERMISSIONS') . " WHERE id = ?");
            $id = (int)($_POST['perm_id'] ?? 0);
            $cs->bind_param('i', $id);
            $cs->execute();
            $cr = $cs->get_result()->fetch_assoc();
            if (!$cr) {
                $msg = 'Permission not found.';
                $msg_type = 'danger';
            } elseif ($plabel === '') {
                $msg = 'Label is required.';
                $msg_type = 'danger';
            } else {
                $s = $link->prepare("UPDATE " . tn('TBL_PERMISSIONS') . " SET label = ?, category = ?, description = ? WHERE id = ?");
                $s->bind_param('sssi', $plabel, $pcat, $pdesc, $id);
                $s->execute();
                $msg = 'Permission feature updated.';
                $msg_type = 'success';
                logActivity($link, USER_ID, 'UPDATE', "Edited permission $pkey");
            }
        }

        // Delete feature
        if ($action === 'delete_feature') {
            $id = (int)($_POST['perm_id'] ?? 0);
            $cs = $link->prepare("SELECT permission_key, is_system FROM " . tn('TBL_PERMISSIONS') . " WHERE id = ?");
            $cs->bind_param('i', $id);
            $cs->execute();
            $cr = $cs->get_result()->fetch_assoc();
            if (!$cr) {
                $msg = 'Permission not found.';
                $msg_type = 'danger';
            } elseif ((int)$cr['is_system'] === 1) {
                $msg = 'System permission keys cannot be deleted.';
                $msg_type = 'danger';
            } else {
                $dp = $link->prepare("DELETE FROM " . tn('TBL_PERMISSIONS') . " WHERE id = ?");
                $dp->bind_param('i', $id);
                $dp->execute();
                clearRbacCache();
                $msg = "Permission '{$cr['permission_key']}' removed.";
                $msg_type = 'success';
                logActivity($link, USER_ID, 'DELETE', "Deleted permission {$cr['permission_key']}");
            }
        }
    }
}

// ─── Fetch data ───
$allRoles = [];
$ar = mysqli_query($link, "SELECT r.*, (SELECT COUNT(*) FROM " . tn('TBL_USERS') . " WHERE ROLE = r.role_name AND ROLE != 'Super Admin') as user_count FROM " . tn('TBL_ROLES') . " r WHERE r.role_name != 'Super Admin' ORDER BY r.is_system DESC, r.id");
if ($ar) while ($a = mysqli_fetch_assoc($ar)) $allRoles[] = $a;

$allPermissions = getAllPermissions($link);
$permsByCat = getPermissionsByCategory($link);
$seedKeys = array_keys(getPermissionSeed());

if ($selectedRole === null && !empty($allRoles)) {
    $selectedRole = $allRoles[0]['role_name'];
}
$rolePerms = [];
if ($selectedRole) {
    $rp = mysqli_query($link, "SELECT permission_key, permission_value FROM " . tn('TBL_ROLE_PERMISSIONS') . " WHERE role = '" . mysqli_real_escape_string($link, $selectedRole) . "'");
    while ($rp && $row = mysqli_fetch_assoc($rp)) $rolePerms[$row['permission_key']] = (int)$row['permission_value'];
}

$allUsers = [];
$ur = mysqli_query($link, "SELECT ID, NAME, ROLE FROM " . tn('TBL_USERS') . " WHERE ROLE != 'Super Admin' ORDER BY NAME");
if ($ur) while ($u = mysqli_fetch_assoc($ur)) $allUsers[] = $u;

if ($selectedUser === null && !empty($allUsers)) {
    $selectedUser = (int)$allUsers[0]['ID'];
}
$userInfo = null;
$userPerms = [];
$effectivePerms = [];
if ($selectedUser) {
    $us = $link->prepare("SELECT ID, NAME, ROLE FROM " . tn('TBL_USERS') . " WHERE ID = ?");
    $us->bind_param('i', $selectedUser);
    $us->execute();
    $userInfo = $us->get_result()->fetch_assoc();
    $up = mysqli_query($link, "SELECT permission_key, permission_value FROM " . tn('TBL_USER_PERMISSIONS') . " WHERE user_id = $selectedUser");
    while ($up && $row = mysqli_fetch_assoc($up)) $userPerms[$row['permission_key']] = (int)$row['permission_value'];
    if ($userInfo) {
        $rp = mysqli_query($link, "SELECT permission_key, permission_value FROM " . tn('TBL_ROLE_PERMISSIONS') . " WHERE role = '" . mysqli_real_escape_string($link, $userInfo['ROLE']) . "'");
        $userRolePerms = [];
        while ($rp && $row = mysqli_fetch_assoc($rp)) $userRolePerms[$row['permission_key']] = (int)$row['permission_value'];
        foreach ($seedKeys as $pk) {
            $effectivePerms[$pk] = $userPerms[$pk] ?? $userRolePerms[$pk] ?? 0;
        }
    }
}
?>
<?php $pageTitle = 'Access Control - CallNow CRM'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<div class="container-fluid px-3 py-3" style="max-width: 1200px;">

    <!-- Zoho CRM Module Header -->
    <div class="module-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/settings/settings.php') ?>">Administration</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Access Control</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2">
                <h1 class="page-title mb-0">Role &amp; Permission Hub</h1>
                <span class="badge bg-secondary-subtle text-secondary border">RBAC</span>
            </div>
            <p class="text-muted small mb-0">Govern role definitions, fine-grained capability switches, and user-level overrides</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= url('modules/settings/settings.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-sliders me-1"></i> General Settings
            </a>
            <a href="<?= url('modules/users/users_view.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-people me-1"></i> User Directory
            </a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= htmlspecialchars($msg_type ?: 'info'); ?> alert-dismissible fade show mb-3" role="alert">
            <i class="bi <?= $msg_type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
            <?= htmlspecialchars($msg); ?>
            <button class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Zoho Navigation Tabs -->
    <ul class="nav nav-tabs mb-3 border-bottom">
        <li class="nav-item">
            <a class="nav-link <?= $tab==='role_perms'?'active fw-semibold text-primary':'' ?>" href="<?= url('modules/settings/permissions_manager.php') ?>?tab=role_perms">
                <i class="bi bi-shield-check me-1"></i> Role Permissions Matrix
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='roles'?'active fw-semibold text-primary':'' ?>" href="<?= url('modules/settings/permissions_manager.php') ?>?tab=roles">
                <i class="bi bi-diagram-3 me-1"></i> Roles Directory
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='features'?'active fw-semibold text-primary':'' ?>" href="<?= url('modules/settings/permissions_manager.php') ?>?tab=features">
                <i class="bi bi-key me-1"></i> Feature Registry
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='user_access'?'active fw-semibold text-primary':'' ?>" href="<?= url('modules/settings/permissions_manager.php') ?>?tab=user_access">
                <i class="bi bi-person-gear me-1"></i> User Overrides
            </a>
        </li>
    </ul>

    <?php if ($tab === 'role_perms'): ?>
    <!-- ═══ Role Permissions Matrix ═══ -->
    <div class="crm-card">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <span class="fw-semibold text-dark">Role Authorization Matrix</span>
                <span class="text-muted small ms-2">Select a role below to configure its module permissions</span>
            </div>
            <span class="badge bg-primary-subtle text-primary border">Active Role: <?= htmlspecialchars($selectedRole ?? 'None') ?></span>
        </div>
        <div class="p-3 bg-light border-bottom">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="small text-muted fw-semibold">Target Role:</span>
                <?php foreach ($allRoles as $role): ?>
                    <a href="<?= url('modules/settings/permissions_manager.php') ?>?tab=role_perms&role=<?= urlencode($role['role_name']) ?>" class="btn btn-sm <?= $selectedRole===$role['role_name']?'btn-primary':'btn-outline-secondary' ?> py-1 px-3">
                        <i class="bi bi-person-badge me-1"></i> <?= htmlspecialchars($role['role_name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if ($selectedRole): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="pm_action" value="save_role_perms">
                <input type="hidden" name="role" value="<?= htmlspecialchars($selectedRole) ?>">

                <div class="table-responsive">
                    <table class="table crm-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="min-width: 250px;">Capability &amp; Code</th>
                                <th style="width: 140px;" class="text-center">Access Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($permsByCat as $cat => $plist): ?>
                                <tr class="table-light">
                                    <td colspan="2" class="fw-bold text-uppercase text-secondary py-1 px-3" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                                        <i class="bi bi-folder2-open me-1"></i> <?= htmlspecialchars($cat) ?> Module
                                    </td>
                                </tr>
                                <?php foreach ($plist as $p): ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-medium text-dark small"><?= htmlspecialchars($p['label']) ?></div>
                                        <code class="text-muted font-monospace" style="font-size: 0.72rem;"><?= htmlspecialchars($p['permission_key']) ?></code>
                                        <?php if (!empty($p['description'])): ?>
                                            <div class="text-muted" style="font-size: 0.72rem;"><?= htmlspecialchars($p['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($selectedRole === 'Admin'): ?>
                                            <span class="badge bg-success-subtle text-success border">
                                                <i class="bi bi-check-lg me-1"></i> Full Access
                                            </span>
                                        <?php else: ?>
                                            <div class="form-check form-switch d-inline-block">
                                                <input class="form-check-input" type="checkbox" name="perm_<?= $p['permission_key'] ?>" value="1" <?= ($rolePerms[$p['permission_key']] ?? 0) ? 'checked' : '' ?>>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($selectedRole !== 'Admin'): ?>
                <div class="d-flex justify-content-end p-3 border-top bg-light">
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-check-lg me-1"></i> Save Role Permissions
                    </button>
                </div>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($tab === 'roles'): ?>
    <!-- ═══ Roles Directory ═══ -->
    <div class="crm-card">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-semibold text-dark">Defined Security Roles</span>
                <span class="text-muted small ms-2">System roles cannot be deleted</span>
            </div>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addRoleModal">
                <i class="bi bi-plus-circle me-1"></i> Create Role
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table crm-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 160px;">Role Name</th>
                            <th>Description</th>
                            <th style="width: 110px;">Classification</th>
                            <th style="width: 110px;" class="text-center">Assigned Users</th>
                            <th style="width: 120px;" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allRoles as $role): ?>
                            <?php $isSys = (int)$role['is_system'] === 1; ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold <?= $isSys ? 'text-primary bg-primary-subtle' : 'text-secondary bg-secondary-subtle' ?>" style="width: 28px; height: 28px; font-size: 0.75rem;">
                                            <i class="bi bi-person-badge"></i>
                                        </div>
                                        <span class="fw-semibold text-dark small"><?= htmlspecialchars($role['role_name']) ?></span>
                                    </div>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($role['description'] ?: '—') ?></td>
                                <td>
                                    <span class="badge <?= $isSys ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary' ?> border">
                                        <?= $isSys ? 'System' : 'Custom' ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border"><?= (int)$role['user_count'] ?> users</span>
                                </td>
                                <td class="text-end">
                                    <?php if (!$isSys): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1" data-bs-toggle="modal" data-bs-target="#editRoleModal" data-id="<?= $role['id'] ?>" data-name="<?= htmlspecialchars($role['role_name']) ?>" data-desc="<?= htmlspecialchars($role['description']) ?>" title="Edit Role">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete custom role &quot;<?= htmlspecialchars($role['role_name']) ?>&quot;?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                            <input type="hidden" name="pm_action" value="delete_role">
                                            <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-1" title="Delete Role">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border"><i class="bi bi-shield-lock me-1"></i>Protected</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Role Modal -->
    <div class="modal fade" id="addRoleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="pm_action" value="save_role">
                    <input type="hidden" name="role_id" value="0">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-plus-circle text-primary me-2"></i> Create Custom Role</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Role Identifier <span class="text-danger">*</span></label>
                            <input type="text" name="role_name" class="form-control form-control-sm" required maxlength="50" placeholder="e.g. Senior Underwriter">
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">Role Description</label>
                            <input type="text" name="role_description" class="form-control form-control-sm" placeholder="Purpose and authority scope" maxlength="255">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">Create Role</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Role Modal -->
    <div class="modal fade" id="editRoleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="pm_action" value="save_role">
                    <input type="hidden" name="role_id" id="edit_role_id" value="0">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i> Edit Custom Role</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Role Identifier <span class="text-danger">*</span></label>
                            <input type="text" name="role_name" id="edit_role_name" class="form-control form-control-sm" required maxlength="50">
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">Role Description</label>
                            <input type="text" name="role_description" id="edit_role_desc" class="form-control form-control-sm" maxlength="255">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php elseif ($tab === 'features'): ?>
    <!-- ═══ Feature Registry ═══ -->
    <div class="crm-card">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-semibold text-dark">Registered Capability Keys</span>
                <span class="text-muted small ms-2">Keys enforced via <code>can('key')</code></span>
            </div>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addFeatureModal">
                <i class="bi bi-plus-circle me-1"></i> New Capability Key
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table crm-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 170px;">Permission Key</th>
                            <th style="min-width: 160px;">Display Label</th>
                            <th style="width: 120px;">Category</th>
                            <th>Description</th>
                            <th style="width: 100px;">Classification</th>
                            <th style="width: 100px;" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allPermissions as $p): ?>
                        <tr>
                            <td><code class="text-dark bg-light px-2 py-0.5 rounded border small font-monospace"><?= htmlspecialchars($p['permission_key']) ?></code></td>
                            <td class="fw-medium text-dark small"><?= htmlspecialchars($p['label']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['category']) ?></span></td>
                            <td class="text-muted small"><?= htmlspecialchars($p['description'] ?: '—') ?></td>
                            <td>
                                <span class="badge <?= (int)$p['is_system']===1 ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary' ?> border">
                                    <?= (int)$p['is_system']===1 ? 'System' : 'Custom' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1" data-bs-toggle="modal" data-bs-target="#editFeatureModal"
                                    data-id="<?= $p['id'] ?>" data-label="<?= htmlspecialchars($p['label']) ?>" data-cat="<?= htmlspecialchars($p['category']) ?>" data-desc="<?= htmlspecialchars($p['description']) ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if ((int)$p['is_system'] !== 1): ?>
                                <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete capability key &quot;<?= htmlspecialchars($p['permission_key']) ?>&quot;?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="pm_action" value="delete_feature">
                                    <input type="hidden" name="perm_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-1"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Feature Modal -->
    <div class="modal fade" id="addFeatureModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="pm_action" value="add_feature">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-plus-circle text-primary me-2"></i> Register New Feature Key</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Unique Key (machine name) <span class="text-danger">*</span></label>
                            <input type="text" name="perm_key" class="form-control form-control-sm font-monospace" required pattern="[a-z][a-z0-9_]*" placeholder="e.g. export_reports" maxlength="50">
                            <div class="text-muted small mt-1">Lowercase letters, numbers, underscores only</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Human Label <span class="text-danger">*</span></label>
                            <input type="text" name="perm_label" class="form-control form-control-sm" required maxlength="100" placeholder="Export Analytics Reports">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Category Group</label>
                            <input type="text" name="perm_category" class="form-control form-control-sm" value="General" maxlength="50">
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">Description</label>
                            <input type="text" name="perm_description" class="form-control form-control-sm" maxlength="255" placeholder="Detailed purpose of this permission">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">Register Feature</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Feature Modal -->
    <div class="modal fade" id="editFeatureModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="pm_action" value="edit_feature">
                    <input type="hidden" name="perm_id" id="edit_perm_id" value="0">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i> Edit Feature Definition</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Human Label <span class="text-danger">*</span></label>
                            <input type="text" name="perm_label" id="edit_perm_label" class="form-control form-control-sm" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Category</label>
                            <input type="text" name="perm_category" id="edit_perm_cat" class="form-control form-control-sm" maxlength="50">
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">Description</label>
                            <input type="text" name="perm_description" id="edit_perm_desc" class="form-control form-control-sm" maxlength="255">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php elseif ($tab === 'user_access'): ?>
    <!-- ═══ User Access Overrides ═══ -->
    <div class="crm-card">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-semibold text-dark">User-Level Access Overrides</span>
                <span class="text-muted small ms-2">Overrides take absolute precedence over role defaults</span>
            </div>
            <a href="<?= url('modules/users/users_view.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-people me-1"></i> User Directory
            </a>
        </div>
        <div class="p-3 bg-light border-bottom">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="user_access">
                <div class="col-md-6">
                    <label class="form-label small text-muted mb-1">Select User Account</label>
                    <select name="user" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= $u['ID'] ?>" <?= $selectedUser===(int)$u['ID']?'selected':'' ?>>
                                <?= htmlspecialchars($u['NAME'] . ' (' . $u['ROLE'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
        <div class="card-body p-0">
            <?php if ($userInfo): ?>
                <div class="p-3 border-bottom bg-white">
                    <form method="POST" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="pm_action" value="assign_role">
                        <input type="hidden" name="user_id" value="<?= $userInfo['ID'] ?>">
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-1">Assigned Security Role</label>
                            <div class="input-group input-group-sm">
                                <select name="role" class="form-select">
                                    <?php foreach ($allRoles as $role): ?>
                                        <option value="<?= htmlspecialchars($role['role_name']) ?>" <?= $userInfo['ROLE']===$role['role_name']?'selected':'' ?>>
                                            <?= htmlspecialchars($role['role_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-outline-primary">Update Role</button>
                            </div>
                        </div>
                    </form>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="pm_action" value="save_user_perms">
                    <input type="hidden" name="user_id" value="<?= $userInfo['ID'] ?>">
                    <input type="hidden" name="role" value="<?= htmlspecialchars($userInfo['ROLE']) ?>">

                    <div class="table-responsive">
                        <table class="table crm-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="min-width: 250px;">Permission Capability</th>
                                    <th style="width: 140px;" class="text-center">Role Baseline</th>
                                    <th style="width: 160px;" class="text-center">Explicit Override</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($permsByCat as $cat => $plist): ?>
                                    <tr class="table-light">
                                        <td colspan="3" class="fw-bold text-uppercase text-secondary py-1 px-3" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                                            <?= htmlspecialchars($cat) ?> Module
                                        </td>
                                    </tr>
                                    <?php foreach ($plist as $p): ?>
                                    <?php 
                                        $eff = $effectivePerms[$p['permission_key']] ?? 0;
                                        $hasOverride = array_key_exists($p['permission_key'], $userPerms);
                                    ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-medium text-dark small"><?= htmlspecialchars($p['label']) ?></div>
                                            <code class="text-muted font-monospace" style="font-size: 0.72rem;"><?= htmlspecialchars($p['permission_key']) ?></code>
                                        </td>
                                        <td class="text-center">
                                            <?php if (($userRolePerms[$p['permission_key']] ?? 0)): ?>
                                                <span class="badge bg-success-subtle text-success border">Enabled</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border">Disabled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="form-check form-switch d-inline-block">
                                                <input class="form-check-input" type="checkbox" name="uperm_<?= $p['permission_key'] ?>" value="1" <?= $eff ? 'checked' : '' ?>>
                                            </div>
                                            <?php if ($hasOverride): ?>
                                                <div class="text-primary fw-medium" style="font-size: 0.65rem;">Override active</div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center p-3 border-top bg-light">
                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#clearOverrideModal">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset to Role Defaults
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm px-4">
                            <i class="bi bi-check-lg me-1"></i> Save Overrides
                        </button>
                    </div>
                </form>

                <!-- Clear Override Modal -->
                <div class="modal fade" id="clearOverrideModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-sm modal-dialog-centered">
                        <div class="modal-content">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                <input type="hidden" name="pm_action" value="clear_user_perms">
                                <input type="hidden" name="user_id" value="<?= $userInfo['ID'] ?>">
                                <div class="modal-body text-center p-4">
                                    <div class="rounded-circle bg-warning-subtle text-warning d-inline-flex align-items-center justify-content-center mb-3" style="width: 50px; height: 50px;">
                                        <i class="bi bi-exclamation-triangle fs-4"></i>
                                    </div>
                                    <h6 class="modal-title fw-bold mb-2">Clear Access Overrides?</h6>
                                    <p class="text-muted small mb-3">All custom permission overrides for <strong><?= htmlspecialchars($userInfo['NAME']) ?></strong> will be removed. The user will strictly inherit their role's baseline permissions.</p>
                                    <div class="d-flex justify-content-center gap-2">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-danger btn-sm">Yes, Clear</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const editRoleModal = document.getElementById('editRoleModal');
    if (editRoleModal) {
        editRoleModal.addEventListener('show.bs.modal', function(e) {
            const btn = e.relatedTarget;
            document.getElementById('edit_role_id').value = btn.dataset.id;
            document.getElementById('edit_role_name').value = btn.dataset.name;
            document.getElementById('edit_role_desc').value = btn.dataset.desc;
        });
    }

    const editFeatureModal = document.getElementById('editFeatureModal');
    if (editFeatureModal) {
        editFeatureModal.addEventListener('show.bs.modal', function(e) {
            const btn = e.relatedTarget;
            document.getElementById('edit_perm_id').value = btn.dataset.id;
            document.getElementById('edit_perm_label').value = btn.dataset.label;
            document.getElementById('edit_perm_cat').value = btn.dataset.cat;
            document.getElementById('edit_perm_desc').value = btn.dataset.desc;
        });
    }
});
</script>
