<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../php_scripts/team_auth.php';
require_once __DIR__ . '/../../php_scripts/TBL_PERMISSIONS.php';
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

        // ── Role permission matrix ──
        if ($action === 'save_role_perms') {
            $role = $_POST['role'] ?? '';
            $cs = $link->prepare("SELECT role_name FROM TBL_ROLES WHERE role_name = ?");
            $cs->bind_param('s', $role);
            $cs->execute();
            if (!$cs->get_result()->fetch_assoc()) {
                $msg = 'Role not found.';
                $msg_type = 'danger';
            } else {
                $stmt = $link->prepare("INSERT INTO TBL_ROLE_PERMISSIONS (role, permission_key, permission_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE permission_value = VALUES(permission_value)");
                foreach (array_keys(getTBL_PERMISSIONSeed()) as $pk) {
                    $v = ($role === 'Admin') ? 1 : (isset($_POST["perm_$pk"]) ? 1 : 0);
                    $stmt->bind_param('ssi', $role, $pk, $v);
                    $stmt->execute();
                }
                clearRbacCache();
                $msg = "TBL_PERMISSIONS updated for role '$role'.";
                $msg_type = 'success';
                logActivity($link, USER_ID, 'UPDATE', "Updated role TBL_PERMISSIONS for $role");
            }
            $selectedRole = $role;
        }

        // ── Per-user override matrix ──
        if ($action === 'save_user_perms') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $role = $_POST['role'] ?? '';
            $us = $link->prepare("SELECT ID FROM TBL_USERS WHERE ID = ?");
            $us->bind_param('i', $uid);
            $us->execute();
            if (!$us->get_result()->fetch_assoc()) {
                $msg = 'User not found.';
                $msg_type = 'danger';
            } else {
                $stmt = $link->prepare("INSERT INTO TBL_USER_PERMISSIONS (user_id, permission_key, permission_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE permission_value = VALUES(permission_value)");
                foreach (array_keys(getTBL_PERMISSIONSeed()) as $pk) {
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

        // ── Clear per-user overrides ──
        if ($action === 'clear_user_perms') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $link->query("DELETE FROM TBL_USER_PERMISSIONS WHERE user_id = $uid");
            clearRbacCache();
            $msg = "Overrides cleared; user #$uid now inherits role TBL_PERMISSIONS.";
            $msg_type = 'success';
            $selectedUser = $uid;
        }

        // ── Assign role to user ──
        if ($action === 'assign_role') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $role = $_POST['role'] ?? '';
            $cs = $link->prepare("SELECT role_name, id FROM TBL_ROLES WHERE role_name = ?");
            $cs->bind_param('s', $role);
            $cs->execute();
            $cr = $cs->get_result()->fetch_assoc();
            if (!$cr) {
                $msg = 'Role not found.';
                $msg_type = 'danger';
            } else {
                $up = $link->prepare("UPDATE TBL_USERS SET ROLE = ?, role_id = ? WHERE ID = ?");
                $up->bind_param('sii', $role, $cr['id'], $uid);
                $up->execute();
                clearRbacCache();
                $msg = "User #$uid assigned to role '$role'.";
                $msg_type = 'success';
                logActivity($link, USER_ID, 'UPDATE', "Assigned user $uid to role $role");
            }
            $selectedUser = $uid;
        }

        // ── Add / edit role ──
        if ($action === 'save_role') {
            $roleName = trim($_POST['role_name'] ?? '');
            $roleDesc = trim($_POST['role_description'] ?? '');
            $roleId = (int)($_POST['role_id'] ?? 0);
            if ($roleName === '') {
                $msg = 'Role name is required.';
                $msg_type = 'danger';
            } else {
                if ($roleId > 0) {
                    $cs = $link->prepare("SELECT role_name, is_system FROM TBL_ROLES WHERE id = ?");
                    $cs->bind_param('i', $roleId);
                    $cs->execute();
                    $cr = $cs->get_result()->fetch_assoc();
                    if ($cr && $cr['is_system']) {
                        $msg = 'System TBL_ROLES cannot be renamed.';
                        $msg_type = 'danger';
                    } else {
                        $oldName = $cr['role_name'];
                        $s = $link->prepare("UPDATE TBL_ROLES SET role_name = ?, description = ? WHERE id = ?");
                        $s->bind_param('ssi', $roleName, $roleDesc, $roleId);
                        $s->execute();
                        $up1 = $link->prepare("UPDATE TBL_ROLE_PERMISSIONS SET role = ? WHERE role = ?");
                        $up1->bind_param('ss', $roleName, $oldName);
                        $up1->execute();
                        $up2 = $link->prepare("UPDATE TBL_USERS SET ROLE = ? WHERE ROLE = ?");
                        $up2->bind_param('ss', $roleName, $oldName);
                        $up2->execute();
                        clearRbacCache();
                        $msg = "Role '$oldName' renamed to '$roleName'.";
                        $msg_type = 'success';
                        logActivity($link, USER_ID, 'UPDATE', "Renamed role $oldName to $roleName");
                    }
                } else {
                    $s = $link->prepare("INSERT INTO TBL_ROLES (role_name, description, is_system) VALUES (?, ?, 0)");
                    $s->bind_param('ss', $roleName, $roleDesc);
                    if ($s->execute()) {
                        $is2 = $link->prepare("INSERT IGNORE INTO TBL_ROLE_PERMISSIONS (role, permission_key, permission_value) VALUES (?, ?, 0)");
                        foreach (array_keys(getTBL_PERMISSIONSeed()) as $pk) {
                            $is2->bind_param('ss', $roleName, $pk);
                            $is2->execute();
                        }
                        clearRbacCache();
                        $msg = "Role '$roleName' created.";
                        $msg_type = 'success';
                        logActivity($link, USER_ID, 'INSERT', "Created role $roleName");
                    } else {
                        $msg = 'Role name already exists.';
                        $msg_type = 'danger';
                    }
                }
            }
        }

        // ── Delete role ──
        if ($action === 'delete_role') {
            $roleId = (int)($_POST['role_id'] ?? 0);
            $cs = $link->prepare("SELECT role_name, is_system FROM TBL_ROLES WHERE id = ?");
            $cs->bind_param('i', $roleId);
            $cs->execute();
            $cr = $cs->get_result()->fetch_assoc();
            if (!$cr) {
                $msg = 'Role not found.';
                $msg_type = 'danger';
            } elseif ($cr['is_system']) {
                $msg = 'System TBL_ROLES cannot be deleted.';
                $msg_type = 'danger';
            } else {
                $roleName = $cr['role_name'];
                $uc = $link->prepare("SELECT COUNT(*) as c FROM TBL_USERS WHERE ROLE = ?");
                $uc->bind_param('s', $roleName);
                $uc->execute();
                $ucr = $uc->get_result()->fetch_assoc();
                if ((int)$ucr['c'] > 0) {
                    $msg = "Cannot delete: {$ucr['c']} user(s) still have this role. Reassign them first.";
                    $msg_type = 'danger';
                } else {
                    $dp = $link->prepare("DELETE FROM TBL_ROLE_PERMISSIONS WHERE role = ?");
                    $dp->bind_param('s', $roleName);
                    $dp->execute();
                    $dr = $link->prepare("DELETE FROM TBL_ROLES WHERE id = ?");
                    $dr->bind_param('i', $roleId);
                    $dr->execute();
                    clearRbacCache();
                    $msg = "Role '$roleName' deleted.";
                    $msg_type = 'success';
                    logActivity($link, USER_ID, 'DELETE', "Deleted role $roleName");
                }
            }
        }

        // ── Add feature (permission key) ──
        if ($action === 'add_feature') {
            $pkey = strtolower(trim($_POST['perm_key'] ?? ''));
            $plabel = trim($_POST['perm_label'] ?? '');
            $pcat = trim($_POST['perm_category'] ?? 'General');
            $pdesc = trim($_POST['perm_description'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $pkey)) {
                $msg = 'Key must be lowercase letters, numbers and underscores (start with a letter).';
                $msg_type = 'danger';
            } elseif ($plabel === '') {
                $msg = 'Label is required.';
                $msg_type = 'danger';
            } elseif (permissionExists($link, $pkey)) {
                $msg = "Permission key '$pkey' already exists.";
                $msg_type = 'danger';
            } else {
                $stmt = $link->prepare("INSERT INTO TBL_PERMISSIONS (permission_key, label, category, description, is_system) VALUES (?, ?, ?, ?, 0)");
                $stmt->bind_param('ssss', $pkey, $plabel, $pcat, $pdesc);
                $stmt->execute();
                // Backfill a row for every existing role (default 0)
                $ins = $link->prepare("INSERT IGNORE INTO TBL_ROLE_PERMISSIONS (role, permission_key, permission_value) VALUES (?, ?, 0)");
                $rr = mysqli_query($link, "SELECT role_name FROM TBL_ROLES");
                while ($rr && $x = mysqli_fetch_assoc($rr)) {
                    $ins->bind_param('ss', $x['role_name'], $pkey);
                    $ins->execute();
                }
                clearRbacCache();
                $msg = "Permission '$pkey' added.";
                $msg_type = 'success';
                logActivity($link, USER_ID, 'INSERT', "Added permission key $pkey");
            }
        }

        // ── Edit feature ──
        if ($action === 'edit_feature') {
            $pkey = $_POST['perm_key'] ?? '';
            $plabel = trim($_POST['perm_label'] ?? '');
            $pcat = trim($_POST['perm_category'] ?? 'General');
            $pdesc = trim($_POST['perm_description'] ?? '');
            $cs = $link->prepare("SELECT permission_key, is_system FROM TBL_PERMISSIONS WHERE id = ?");
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
                $s = $link->prepare("UPDATE TBL_PERMISSIONS SET label = ?, category = ?, description = ? WHERE id = ?");
                $s->bind_param('sssi', $plabel, $pcat, $pdesc, $id);
                $s->execute();
                $msg = 'Permission updated.';
                $msg_type = 'success';
                logActivity($link, USER_ID, 'UPDATE', "Edited permission $pkey");
            }
        }

        // ── Delete feature ──
        if ($action === 'delete_feature') {
            $id = (int)($_POST['perm_id'] ?? 0);
            $cs = $link->prepare("SELECT permission_key, is_system FROM TBL_PERMISSIONS WHERE id = ?");
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
                $dp = $link->prepare("DELETE FROM TBL_PERMISSIONS WHERE id = ?");
                $dp->bind_param('i', $id);
                $dp->execute();
                clearRbacCache();
                $msg = "Permission '{$cr['permission_key']}' deleted.";
                $msg_type = 'success';
                logActivity($link, USER_ID, 'DELETE', "Deleted permission {$cr['permission_key']}");
            }
        }
    }
}

// ─── Fetch data ───
$allTBL_ROLES = [];
$ar = mysqli_query($link, "SELECT r.*, (SELECT COUNT(*) FROM TBL_USERS WHERE ROLE = r.role_name) as user_count FROM TBL_ROLES r ORDER BY r.is_system DESC, r.id");
if ($ar) while ($a = mysqli_fetch_assoc($ar)) $allTBL_ROLES[] = $a;

$allTBL_PERMISSIONS = getAllTBL_PERMISSIONS($link);
$permsByCat = getTBL_PERMISSIONSByCategory($link);
$seedKeys = array_keys(getTBL_PERMISSIONSeed());

// Default selected role = first role
if ($selectedRole === null && !empty($allTBL_ROLES)) {
    $selectedRole = $allTBL_ROLES[0]['role_name'];
}
$rolePerms = [];
if ($selectedRole) {
    $rp = mysqli_query($link, "SELECT permission_key, permission_value FROM TBL_ROLE_PERMISSIONS WHERE role = '" . mysqli_real_escape_string($link, $selectedRole) . "'");
    while ($rp && $row = mysqli_fetch_assoc($rp)) $rolePerms[$row['permission_key']] = (int)$row['permission_value'];
}

// TBL_USERS for access tab
$allTBL_USERS = [];
$ur = mysqli_query($link, "SELECT ID, NAME, ROLE FROM TBL_USERS ORDER BY NAME");
if ($ur) while ($u = mysqli_fetch_assoc($ur)) $allTBL_USERS[] = $u;

if ($selectedUser === null && !empty($allTBL_USERS)) {
    $selectedUser = (int)$allTBL_USERS[0]['ID'];
}
$userInfo = null;
$userPerms = [];
$effectivePerms = [];
if ($selectedUser) {
    $us = $link->prepare("SELECT ID, NAME, ROLE FROM TBL_USERS WHERE ID = ?");
    $us->bind_param('i', $selectedUser);
    $us->execute();
    $userInfo = $us->get_result()->fetch_assoc();
    $up = mysqli_query($link, "SELECT permission_key, permission_value FROM TBL_USER_PERMISSIONS WHERE user_id = $selectedUser");
    while ($up && $row = mysqli_fetch_assoc($up)) $userPerms[$row['permission_key']] = (int)$row['permission_value'];
    // effective perms for this user (reuse can() logic approximation)
    if ($userInfo) {
        $rp = mysqli_query($link, "SELECT permission_key, permission_value FROM TBL_ROLE_PERMISSIONS WHERE role = '" . mysqli_real_escape_string($link, $userInfo['ROLE']) . "'");
        $userRolePerms = [];
        while ($rp && $row = mysqli_fetch_assoc($rp)) $userRolePerms[$row['permission_key']] = (int)$row['permission_value'];
        foreach ($seedKeys as $pk) {
            $effectivePerms[$pk] = $userPerms[$pk] ?? $userRolePerms[$pk] ?? 0;
        }
    }
}
?>
<?php $pageTitle = 'Access Control - CallNow Admin'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<style>
:root {
    --st-accent: var(--accent);
    --st-accent-dark: var(--accent-hover, #4f46e5);
    --st-ink: #1e1b4b;
    --st-ink-soft: #6b6890;
    --st-soft: #f0f2ff;
    --st-border: #e2e4f0;
}
.pm-header {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
    border-radius: 1rem; padding: 1.5rem 2rem; margin-bottom: 1.5rem; position: relative; overflow: hidden;
}
.pm-header h1 { font-size: 1.35rem; font-weight: 700; color: #fff; margin: 0 0 0.2rem 0; }
.pm-header p { color: rgba(255,255,255,0.7); font-size: 0.8125rem; margin: 0; }
.pm-tabs { display: flex; gap: 0.25rem; background: var(--st-soft); border: 1px solid var(--st-border); border-radius: 0.75rem; padding: 0.25rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.pm-tab { flex: 1; text-align: center; padding: 0.5rem 1rem; border-radius: 0.625rem; font-size: 0.8125rem; font-weight: 600; color: var(--st-ink-soft); text-decoration: none; transition: all 0.12s ease; white-space: nowrap; }
.pm-tab:hover { color: var(--st-ink); background: rgba(255,255,255,0.6); }
.pm-tab.active { background: #fff; color: var(--st-accent); box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
.pm-tab i { margin-right: 0.35rem; }
.pm-card { background: #fff; border: 1px solid var(--st-border); border-radius: 0.875rem; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
.pm-card-body { padding: 1.75rem 2rem; }
.pm-alert { border-radius: 0.625rem; font-size: 0.8125rem; padding: 0.75rem 1rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem; }
.pm-label { font-size: 0.75rem; font-weight: 600; color: var(--st-ink); margin-bottom: 0.3rem; }
.pm-hint { font-size: 0.6875rem; color: var(--st-ink-soft); margin-top: 0.15rem; }
.pm-input, .pm-select { border: 1px solid var(--st-border) !important; border-radius: 0.5rem !important; font-size: 0.8125rem !important; color: var(--st-ink) !important; padding: 0.4rem 0.75rem !important; background: #fff !important; }
.pm-input:focus, .pm-select:focus { border-color: var(--st-accent) !important; box-shadow: 0 0 0 3px rgba(99,102,241,0.12) !important; outline: none; }
.pm-btn-primary { background: linear-gradient(135deg, var(--st-accent), var(--st-accent-dark)) !important; border: none !important; color: #fff !important; border-radius: 0.5rem !important; font-size: 0.8125rem !important; font-weight: 600 !important; padding: 0.5rem 1.5rem !important; box-shadow: 0 2px 8px rgba(99,102,241,0.25) !important; }
.pm-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(99,102,241,0.35) !important; }
.pm-btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626) !important; border: none !important; color: #fff !important; border-radius: 0.5rem !important; font-size: 0.75rem !important; font-weight: 600 !important; padding: 0.35rem 1rem !important; }
.pm-btn-outline { border: 1px solid var(--st-border) !important; background: #fff !important; color: var(--st-ink-soft) !important; border-radius: 0.5rem !important; font-size: 0.75rem !important; padding: 0.35rem 1rem !important; }
.pm-perm-grid { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.8125rem; }
.pm-perm-grid thead th { background: #fafbff; color: var(--st-ink-soft); font-weight: 600; font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.625rem 0.875rem; border-bottom: 2px solid var(--st-border); text-align: center; white-space: nowrap; }
.pm-perm-grid thead th:first-child { text-align: left; }
.pm-perm-grid tbody td { padding: 0.5rem 0.875rem; border-bottom: 1px solid #f0f1f8; text-align: center; vertical-align: middle; }
.pm-perm-grid tbody td:first-child { text-align: left; font-weight: 600; color: var(--st-ink); }
.pm-perm-grid tbody tr:hover { background: #f8f9ff; }
.pm-perm-grid tbody tr:last-child td { border-bottom: none; }
.pm-perm-grid .cat-row td { background: #f5f6ff; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--st-accent); font-weight: 700; text-align: left; }
.pm-switch { width: 38px; height: 22px; position: relative; display: inline-block; }
.pm-switch input { opacity: 0; width: 0; height: 0; }
.pm-slider { position: absolute; cursor: pointer; inset: 0; background: #d4d6e8; border-radius: 22px; transition: 0.2s; }
.pm-slider::before { content: ''; position: absolute; height: 16px; width: 16px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.2s; }
.pm-switch input:checked + .pm-slider { background: var(--st-accent); }
.pm-switch input:checked + .pm-slider::before { transform: translateX(16px); }
.pm-role-pills { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.pm-role-pill { border: 1px solid var(--st-border); border-radius: 2rem; padding: 0.4rem 1rem; font-size: 0.8125rem; font-weight: 600; color: var(--st-ink-soft); text-decoration: none; background: #fff; }
.pm-role-pill:hover { color: var(--st-ink); }
.pm-role-pill.active { background: var(--st-accent); color: #fff; border-color: var(--st-accent); }
.pm-role-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem; }
.pm-role-card { background: #fff; border: 1px solid var(--st-border); border-radius: 0.75rem; padding: 1.25rem; transition: box-shadow 0.15s; }
.pm-role-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.05); }
.pm-role-card h4 { font-size: 0.95rem; font-weight: 700; color: var(--st-ink); margin: 0 0 0.2rem 0; display: flex; align-items: center; gap: 0.4rem; }
.pm-badge { display: inline-block; font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.15rem 0.4rem; border-radius: 0.25rem; }
.pm-badge.system { background: #6366f1; color: #fff; }
.pm-badge.custom { background: #f0f2ff; color: #6366f1; }
.pm-feat-tbl { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.8125rem; }
.pm-feat-tbl th { background: #fafbff; color: var(--st-ink-soft); font-weight: 600; font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.625rem 0.875rem; border-bottom: 2px solid var(--st-border); text-align: left; }
.pm-feat-tbl td { padding: 0.55rem 0.875rem; border-bottom: 1px solid #f0f1f8; }
.pm-feat-tbl tr:hover { background: #f8f9ff; }
.pm-feat-tbl code { font-size: 0.75rem; color: var(--st-accent-dark); }
@media (max-width: 767px) { .pm-card-body { padding: 1.25rem; } .pm-perm-grid { font-size: 0.7rem; } .pm-perm-grid thead th, .pm-perm-grid tbody td { padding: 0.4rem 0.4rem; } }
</style>

<div class="container page-wrapper">

    <div class="pm-header">
        <h1><i class="bi bi-shield-lock"></i> Access Control</h1>
        <p>Manage permission keys, TBL_ROLES, role TBL_PERMISSIONS, and per-user access</p>
    </div>

    <?php if ($msg): ?>
        <div class="pm-alert" style="background:<?= $msg_type==='success'?'#d1fae5':'#fee2e2' ?>;border:1px solid <?= $msg_type==='success'?'#6ee7b7':'#fca5a5' ?>;color:<?= $msg_type==='success'?'#065f46':'#991b1b' ?>;">
            <i class="bi <?= $msg_type==='success'?'bi-check-circle-fill':'bi-x-circle-fill' ?>"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="font-size:0.65rem;"></button>
        </div>
    <?php endif; ?>

    <div class="pm-tabs">
        <a href="<?= url('modules/settings/TBL_PERMISSIONS_manager.php') ?>?tab=features" class="pm-tab <?= $tab==='features'?'active':'' ?>"><i class="bi bi-key"></i> Features</a>
        <a href="<?= url('modules/settings/TBL_PERMISSIONS_manager.php') ?>?tab=TBL_ROLES" class="pm-tab <?= $tab==='TBL_ROLES'?'active':'' ?>"><i class="bi bi-diagram-3"></i> TBL_ROLES</a>
        <a href="<?= url('modules/settings/TBL_PERMISSIONS_manager.php') ?>?tab=role_perms" class="pm-tab <?= $tab==='role_perms'?'active':'' ?>"><i class="bi bi-shield-check"></i> Role TBL_PERMISSIONS</a>
        <a href="<?= url('modules/settings/TBL_PERMISSIONS_manager.php') ?>?tab=user_access" class="pm-tab <?= $tab==='user_access'?'active':'' ?>"><i class="bi bi-person-gear"></i> User Access</a>
    </div>

    <?php if ($tab === 'features'): ?>
    <!-- ═══ Features (permission keys) ═══ -->
    <div class="pm-card">
        <div class="pm-card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 style="font-size:1rem;font-weight:700;color:var(--st-ink);margin:0;"><i class="bi bi-key"></i> Permission Features</h5>
                    <p style="font-size:0.75rem;color:var(--st-ink-soft);margin:0;">These keys gate every action in the app. System keys are protected.</p>
                </div>
                <button type="button" class="pm-btn-primary" data-bs-toggle="modal" data-bs-target="#addFeatureModal" style="font-size:0.75rem!important;padding:0.4rem 1rem!important;"><i class="bi bi-plus-lg"></i> New Feature</button>
            </div>

            <div class="table-responsive">
                <table class="pm-feat-tbl">
                    <thead><tr><th>Key</th><th>Label</th><th>Category</th><th>Description</th><th>Type</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($allTBL_PERMISSIONS as $p): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($p['permission_key']) ?></code></td>
                            <td><?= htmlspecialchars($p['label']) ?></td>
                            <td><?= htmlspecialchars($p['category']) ?></td>
                            <td style="color:var(--st-ink-soft);"><?= htmlspecialchars($p['description'] ?: '—') ?></td>
                            <td><?= (int)$p['is_system']===1 ? '<span class="pm-badge system">System</span>' : '<span class="pm-badge custom">Custom</span>' ?></td>
                            <td class="text-end" style="white-space:nowrap;">
                                <button type="button" class="pm-btn-outline" style="padding:0.2rem 0.6rem!important;" data-bs-toggle="modal" data-bs-target="#editFeatureModal"
                                    data-id="<?= $p['id'] ?>" data-label="<?= htmlspecialchars($p['label']) ?>" data-cat="<?= htmlspecialchars($p['category']) ?>" data-desc="<?= htmlspecialchars($p['description']) ?>"><i class="bi bi-pencil"></i></button>
                                <?php if ((int)$p['is_system'] !== 1): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete feature &quot;<?= htmlspecialchars($p['permission_key']) ?>&quot;? This removes it for all TBL_ROLES.')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="pm_action" value="delete_feature">
                                    <input type="hidden" name="perm_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="pm-btn-outline" style="padding:0.2rem 0.6rem!important;color:#ef4444!important;border-color:#fca5a5!important;"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php else: ?>
                                    <span style="font-size:0.6rem;color:#a3a3a3;"><i class="bi bi-lock"></i></span>
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
    <div class="modal fade" id="addFeatureModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:0.75rem;border:1px solid var(--st-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="pm_action" value="add_feature">
                    <div class="modal-header" style="border-bottom-color:var(--st-border);padding:1rem 1.25rem;">
                        <h6 style="font-weight:700;font-size:0.9rem;margin:0;"><i class="bi bi-plus-circle"></i> New Permission Feature</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:0.7rem;"></button>
                    </div>
                    <div class="modal-body" style="padding:1.25rem;">
                        <div class="mb-3">
                            <label class="pm-label">Key (machine name)</label>
                            <input type="text" name="perm_key" class="form-control pm-input" required pattern="[a-z][a-z0-9_]*" placeholder="e.g. export_reports" maxlength="50">
                            <div class="pm-hint">Lowercase letters, numbers, underscores. Used in code via can('key').</div>
                        </div>
                        <div class="mb-3">
                            <label class="pm-label">Label</label>
                            <input type="text" name="perm_label" class="form-control pm-input" required maxlength="100" placeholder="Export Reports">
                        </div>
                        <div class="mb-3">
                            <label class="pm-label">Category</label>
                            <input type="text" name="perm_category" class="form-control pm-input" value="General" maxlength="50">
                        </div>
                        <div>
                            <label class="pm-label">Description</label>
                            <input type="text" name="perm_description" class="form-control pm-input" maxlength="255" placeholder="What this permission allows">
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top-color:var(--st-border);padding:0.75rem 1.25rem;">
                        <button type="button" class="pm-btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="pm-btn-primary" style="padding:0.4rem 1.2rem!important;">Add Feature</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Feature Modal -->
    <div class="modal fade" id="editFeatureModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:0.75rem;border:1px solid var(--st-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="pm_action" value="edit_feature">
                    <input type="hidden" name="perm_id" id="edit_perm_id" value="0">
                    <div class="modal-header" style="border-bottom-color:var(--st-border);padding:1rem 1.25rem;">
                        <h6 style="font-weight:700;font-size:0.9rem;margin:0;"><i class="bi bi-pencil-square"></i> Edit Feature</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:0.7rem;"></button>
                    </div>
                    <div class="modal-body" style="padding:1.25rem;">
                        <div class="mb-3">
                            <label class="pm-label">Label</label>
                            <input type="text" name="perm_label" id="edit_perm_label" class="form-control pm-input" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label class="pm-label">Category</label>
                            <input type="text" name="perm_category" id="edit_perm_cat" class="form-control pm-input" maxlength="50">
                        </div>
                        <div>
                            <label class="pm-label">Description</label>
                            <input type="text" name="perm_description" id="edit_perm_desc" class="form-control pm-input" maxlength="255">
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top-color:var(--st-border);padding:0.75rem 1.25rem;">
                        <button type="button" class="pm-btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="pm-btn-primary" style="padding:0.4rem 1.2rem!important;">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php elseif ($tab === 'TBL_ROLES'): ?>
    <!-- ═══ TBL_ROLES ═══ -->
    <div class="pm-card">
        <div class="pm-card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 style="font-size:1rem;font-weight:700;color:var(--st-ink);margin:0;"><i class="bi bi-diagram-3"></i> TBL_ROLES</h5>
                    <p style="font-size:0.75rem;color:var(--st-ink-soft);margin:0;">Create TBL_ROLES. New custom TBL_ROLES start with <strong>all TBL_PERMISSIONS OFF</strong> (secure by default).</p>
                </div>
                <button type="button" class="pm-btn-primary" data-bs-toggle="modal" data-bs-target="#addRoleModal" style="font-size:0.75rem!important;padding:0.4rem 1rem!important;"><i class="bi bi-plus-lg"></i> New Role</button>
            </div>

            <div class="pm-role-grid">
                <?php foreach ($allTBL_ROLES as $role): ?>
                    <?php $isSys = (int)$role['is_system'] === 1; ?>
                    <div class="pm-role-card">
                        <h4>
                            <i class="bi bi-person-badge" style="color:<?= $isSys?'#6366f1':'#a3a3a3' ?>;"></i>
                            <?= htmlspecialchars($role['role_name']) ?>
                            <span class="pm-badge <?= $isSys ? 'system' : 'custom' ?>"><?= $isSys ? 'System' : 'Custom' ?></span>
                        </h4>
                        <div style="font-size:0.75rem;color:var(--st-ink-soft);"><?= htmlspecialchars($role['description'] ?: 'No description') ?></div>
                        <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.75rem;color:var(--st-ink-soft);margin-top:0.6rem;">
                            <span><i class="bi bi-people"></i> <?= (int)$role['user_count'] ?> user(s)</span>
                            <div style="display:flex;gap:0.4rem;">
                                <?php if (!$isSys): ?>
                                    <button type="button" class="pm-btn-outline" style="padding:0.2rem 0.6rem!important;" data-bs-toggle="modal" data-bs-target="#editRoleModal"
                                        data-id="<?= $role['id'] ?>" data-name="<?= htmlspecialchars($role['role_name']) ?>" data-desc="<?= htmlspecialchars($role['description']) ?>"><i class="bi bi-pencil"></i></button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete role &quot;<?= htmlspecialchars($role['role_name']) ?>&quot;?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="pm_action" value="delete_role">
                                        <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                                        <button type="submit" class="pm-btn-outline" style="padding:0.2rem 0.6rem!important;color:#ef4444!important;border-color:#fca5a5!important;"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size:0.6rem;color:#a3a3a3;"><i class="bi bi-lock"></i> Protected</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addRoleModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content" style="border-radius:0.75rem;border:1px solid var(--st-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="pm_action" value="save_role">
                    <input type="hidden" name="role_id" value="0">
                    <div class="modal-header" style="border-bottom-color:var(--st-border);padding:1rem 1.25rem;">
                        <h6 style="font-weight:700;font-size:0.9rem;margin:0;"><i class="bi bi-plus-circle"></i> New Role</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:0.7rem;"></button>
                    </div>
                    <div class="modal-body" style="padding:1.25rem;">
                        <div class="mb-3">
                            <label class="pm-label">Role Name</label>
                            <input type="text" name="role_name" class="form-control pm-input" required maxlength="50" placeholder="e.g. Team Lead">
                        </div>
                        <div>
                            <label class="pm-label">Description</label>
                            <input type="text" name="role_description" class="form-control pm-input" placeholder="Brief description" maxlength="255">
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top-color:var(--st-border);padding:0.75rem 1.25rem;">
                        <button type="button" class="pm-btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="pm-btn-primary" style="padding:0.4rem 1.2rem!important;">Create Role</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editRoleModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content" style="border-radius:0.75rem;border:1px solid var(--st-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="pm_action" value="save_role">
                    <input type="hidden" name="role_id" id="edit_role_id" value="0">
                    <div class="modal-header" style="border-bottom-color:var(--st-border);padding:1rem 1.25rem;">
                        <h6 style="font-weight:700;font-size:0.9rem;margin:0;"><i class="bi bi-pencil-square"></i> Edit Role</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:0.7rem;"></button>
                    </div>
                    <div class="modal-body" style="padding:1.25rem;">
                        <div class="mb-3">
                            <label class="pm-label">Role Name</label>
                            <input type="text" name="role_name" id="edit_role_name" class="form-control pm-input" required maxlength="50">
                        </div>
                        <div>
                            <label class="pm-label">Description</label>
                            <input type="text" name="role_description" id="edit_role_desc" class="form-control pm-input" maxlength="255">
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top-color:var(--st-border);padding:0.75rem 1.25rem;">
                        <button type="button" class="pm-btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="pm-btn-primary" style="padding:0.4rem 1.2rem!important;">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php elseif ($tab === 'role_perms'): ?>
    <!-- ═══ Role TBL_PERMISSIONS (main) ═══ -->
    <div class="pm-card">
        <div class="pm-card-body">
            <h5 style="font-size:1rem;font-weight:700;color:var(--st-ink);margin:0 0 0.2rem 0;"><i class="bi bi-shield-check"></i> Role TBL_PERMISSIONS</h5>
            <p style="font-size:0.75rem;color:var(--st-ink-soft);margin:0 0 1rem 0;">Select a role, then toggle its TBL_PERMISSIONS. <strong>Admin</strong> always has full access.</p>

            <div class="pm-role-pills">
                <?php foreach ($allTBL_ROLES as $role): ?>
                    <a href="<?= url('modules/settings/TBL_PERMISSIONS_manager.php') ?>?tab=role_perms&role=<?= urlencode($role['role_name']) ?>" class="pm-role-pill <?= $selectedRole===$role['role_name']?'active':'' ?>"><?= htmlspecialchars($role['role_name']) ?></a>
                <?php endforeach; ?>
            </div>

            <?php if ($selectedRole): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="pm_action" value="save_role_perms">
                <input type="hidden" name="role" value="<?= htmlspecialchars($selectedRole) ?>">
                <div class="table-responsive">
                    <table class="pm-perm-grid">
                        <thead>
                            <tr><th>Permission</th><th><i class="bi bi-person"></i> <?= htmlspecialchars($selectedRole) ?></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($permsByCat as $cat => $plist): ?>
                                <tr class="cat-row"><td colspan="2"><?= htmlspecialchars($cat) ?></td></tr>
                                <?php foreach ($plist as $p): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($p['label']) ?></strong>
                                        <br><span style="font-size:0.6875rem;color:var(--st-ink-soft);"><?= htmlspecialchars($p['permission_key']) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($selectedRole === 'Admin'): ?>
                                            <span style="color:#10b981;font-size:0.75rem;font-weight:600;"><i class="bi bi-check-lg"></i> Full</span>
                                        <?php else: ?>
                                            <label class="pm-switch">
                                                <input type="checkbox" name="perm_<?= $p['permission_key'] ?>" value="1" <?= ($rolePerms[$p['permission_key']] ?? 0) ? 'checked' : '' ?>>
                                                <span class="pm-slider"></span>
                                            </label>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($selectedRole !== 'Admin'): ?>
                <hr style="border-color:var(--st-border);margin:1.25rem 0;">
                <div class="text-end">
                    <button type="submit" class="pm-btn-primary"><i class="bi bi-check-lg"></i> Save TBL_PERMISSIONS</button>
                </div>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($tab === 'user_access'): ?>
    <!-- ═══ User Access ═══ -->
    <div class="pm-card">
        <div class="pm-card-body">
            <h5 style="font-size:1rem;font-weight:700;color:var(--st-ink);margin:0 0 0.2rem 0;"><i class="bi bi-person-gear"></i> User Access</h5>
            <p style="font-size:0.75rem;color:var(--st-ink-soft);margin:0 0 1rem 0;">Assign a role and set per-user permission overrides (overrides win over the role).</p>

            <form method="GET" class="mb-4">
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="pm-label">User</label>
                        <select name="user" class="form-select pm-select" onchange="this.form.submit()">
                            <?php foreach ($allTBL_USERS as $u): ?>
                                <option value="<?= $u['ID'] ?>" <?= $selectedUser===(int)$u['ID']?'selected':'' ?>><?= htmlspecialchars($u['NAME'] . ' (' . $u['ROLE'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>

            <?php if ($userInfo): ?>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="pm_action" value="assign_role">
                        <input type="hidden" name="user_id" value="<?= $userInfo['ID'] ?>">
                        <label class="pm-label">Assigned Role</label>
                        <div class="input-group">
                            <select name="role" class="form-select pm-select">
                                <?php foreach ($allTBL_ROLES as $role): ?>
                                    <option value="<?= htmlspecialchars($role['role_name']) ?>" <?= $userInfo['ROLE']===$role['role_name']?'selected':'' ?>><?= htmlspecialchars($role['role_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="pm-btn-primary" style="padding:0.4rem 1rem!important;"><i class="bi bi-check-lg"></i> Assign</button>
                        </div>
                    </form>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <a href="<?= url('modules/TBL_USERS/TBL_USERS_view.php') ?>" class="pm-btn-outline"><i class="bi bi-arrow-right"></i> Open in User Management</a>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="pm_action" value="save_user_perms">
                <input type="hidden" name="user_id" value="<?= $userInfo['ID'] ?>">
                <input type="hidden" name="role" value="<?= htmlspecialchars($userInfo['ROLE']) ?>">
                <div class="table-responsive">
                    <table class="pm-perm-grid">
                        <thead>
                            <tr><th>Permission</th><th>Role Default</th><th>User Override</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($permsByCat as $cat => $plist): ?>
                                <tr class="cat-row"><td colspan="3"><?= htmlspecialchars($cat) ?></td></tr>
                                <?php foreach ($plist as $p): ?>
                                <?php $eff = $effectivePerms[$p['permission_key']] ?? 0; $hasOverride = array_key_exists($p['permission_key'], $userPerms); ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($p['label']) ?></strong>
                                        <br><span style="font-size:0.6875rem;color:var(--st-ink-soft);"><?= htmlspecialchars($p['permission_key']) ?></span>
                                    </td>
                                    <td>
                                        <?php if (($userRolePerms[$p['permission_key']] ?? 0)): ?><span style="color:#10b981;font-weight:600;"><i class="bi bi-check-lg"></i> On</span><?php else: ?><span style="color:#ef4444;font-weight:600;"><i class="bi bi-x-lg"></i> Off</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <label class="pm-switch">
                                            <input type="checkbox" name="uperm_<?= $p['permission_key'] ?>" value="1" <?= $eff ? 'checked' : '' ?>>
                                            <span class="pm-slider"></span>
                                        </label>
                                        <?php if ($hasOverride): ?><div style="font-size:0.6rem;color:var(--st-accent);">override set</div><?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <hr style="border-color:var(--st-border);margin:1.25rem 0;">
                <div class="text-end">
                    <button type="submit" class="pm-btn-primary"><i class="bi bi-check-lg"></i> Save Overrides</button>
                    <button type="button" class="pm-btn-outline" data-bs-toggle="modal" data-bs-target="#clearOverrideModal"><i class="bi bi-arrow-counterclockwise"></i> Clear Overrides</button>
                </div>
            </form>

            <div class="modal fade" id="clearOverrideModal" tabindex="-1">
                <div class="modal-dialog modal-sm modal-dialog-centered">
                    <div class="modal-content" style="border-radius:0.75rem;border:1px solid var(--st-border);">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="pm_action" value="clear_user_perms">
                            <input type="hidden" name="user_id" value="<?= $userInfo['ID'] ?>">
                            <div class="modal-body text-center" style="padding:1.5rem;">
                                <i class="bi bi-exclamation-triangle" style="font-size:2rem;color:#f59e0b;"></i>
                                <p style="margin:0.75rem 0;font-size:0.8125rem;">Remove all per-user overrides for <strong><?= htmlspecialchars($userInfo['NAME']) ?></strong>? They will inherit the role's TBL_PERMISSIONS.</p>
                            </div>
                            <div class="modal-footer" style="border-top-color:var(--st-border);padding:0.75rem 1.25rem;justify-content:center;">
                                <button type="button" class="pm-btn-outline" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="pm-btn-danger">Clear Overrides</button>
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

<script>
document.getElementById('editRoleModal')?.addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('edit_role_id').value = btn.dataset.id;
    document.getElementById('edit_role_name').value = btn.dataset.name;
    document.getElementById('edit_role_desc').value = btn.dataset.desc;
});
document.getElementById('editFeatureModal')?.addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('edit_perm_id').value = btn.dataset.id;
    document.getElementById('edit_perm_label').value = btn.dataset.label;
    document.getElementById('edit_perm_cat').value = btn.dataset.cat;
    document.getElementById('edit_perm_desc').value = btn.dataset.desc;
});
</script>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
