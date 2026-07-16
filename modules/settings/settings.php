<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../php_scripts/team_auth.php';
requireRole('Admin');

$msg = '';
$msg_type = '';
$tab = $_GET['tab'] ?? 'general';

// ─── Handle form saves ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid session token';
        $msg_type = 'danger';
    } else {
        $action = $_POST['settings_action'] ?? '';

        // Save general settings
        if ($action === 'save_settings') {
            $keys = ['app_name','company_name','default_lead_status','default_lead_stage','pagination_size','session_timeout','timezone'];
            $stmt = $link->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = ?, updated_at = NOW()");
            $uid = USER_ID;
            foreach ($keys as $k) {
                $v = $_POST[$k] ?? '';
                $stmt->bind_param('ssi', $k, $v, $uid);
                $stmt->execute();
            }
            $msg = 'Settings saved successfully!';
            $msg_type = 'success';
            logActivity($link, USER_ID, 'UPDATE', 'Updated general settings');
        }

        // Save permissions
        if ($action === 'save_permissions') {
            $rr = mysqli_query($link, "SELECT role_name FROM roles ORDER BY id");
            $dbRoles = [];
            while ($r = mysqli_fetch_assoc($rr)) $dbRoles[] = $r['role_name'];

            $perm_keys = ['manage_users','manage_teams','manage_leads','manage_settings','export_data','delete_leads','view_reports','upload_data','manage_database','assign_leads'];
            $stmt = $link->prepare("INSERT INTO role_permissions (role, permission_key, permission_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE permission_value = VALUES(permission_value)");
            foreach ($dbRoles as $role) {
                foreach ($perm_keys as $pk) {
                    $v = isset($_POST["perm_{$role}_{$pk}"]) ? 1 : 0;
                    // Admin always full
                    if ($role === 'Admin') $v = 1;
                    $stmt->bind_param('ssi', $role, $pk, $v);
                    $stmt->execute();
                }
            }
            // Clear cached perms for all roles so next request picks up changes
            foreach ($dbRoles as $role) {
                $ck = 'rbac_perms_' . md5($role);
                unset($_SESSION[$ck]);
            }
            $msg = 'Permissions updated successfully!';
            $msg_type = 'success';
            logActivity($link, USER_ID, 'UPDATE', 'Updated role permissions');
        }

        // Save / update role
        if ($action === 'save_role') {
            $roleName = trim($_POST['role_name'] ?? '');
            $roleDesc = trim($_POST['role_description'] ?? '');
            $roleId = (int)($_POST['role_id'] ?? 0);

            if ($roleName === '') {
                $msg = 'Role name is required.';
                $msg_type = 'danger';
            } else {
                if ($roleId > 0) {
                    // Edit — disallow renaming system roles, fetch old name
                    $cs = $link->prepare("SELECT role_name, is_system FROM roles WHERE id = ?");
                    $cs->bind_param('i', $roleId);
                    $cs->execute();
                    $cr = $cs->get_result()->fetch_assoc();
                    if ($cr && $cr['is_system']) {
                        $msg = 'System roles cannot be renamed.';
                        $msg_type = 'danger';
                    } else {
                        $oldName = $cr['role_name'];
                        $s = $link->prepare("UPDATE roles SET role_name = ?, description = ? WHERE id = ?");
                        $s->bind_param('ssi', $roleName, $roleDesc, $roleId);
                        $s->execute();
                        // Cascade rename to role_permissions and users
                        $up1 = $link->prepare("UPDATE role_permissions SET role = ? WHERE role = ?");
                        $up1->bind_param('ss', $roleName, $oldName);
                        $up1->execute();
                        $up2 = $link->prepare("UPDATE users SET ROLE = ? WHERE ROLE = ?");
                        $up2->bind_param('ss', $roleName, $oldName);
                        $up2->execute();
                        // Clear stale session caches
                        unset($_SESSION['rbac_perms_' . md5($oldName)]);
                        unset($_SESSION['rbac_perms_' . md5($roleName)]);
                        $msg = 'Role updated and cascaded to permissions and users.';
                        $msg_type = 'success';
                        logActivity($link, USER_ID, 'UPDATE', "Renamed role $oldName to $roleName");
                    }
                } else {
                    // Create new role
                    $s = $link->prepare("INSERT INTO roles (role_name, description, is_system) VALUES (?, ?, 0)");
                    $s->bind_param('ss', $roleName, $roleDesc);
                    if ($s->execute()) {
                        $perm_keys = ['manage_users','manage_teams','manage_leads','manage_settings','export_data','delete_leads','view_reports','upload_data','manage_database','assign_leads'];
                        $is2 = $link->prepare("INSERT IGNORE INTO role_permissions (role, permission_key, permission_value) VALUES (?, ?, 0)");
                        foreach ($perm_keys as $pk) {
                            $is2->bind_param('ss', $roleName, $pk);
                            $is2->execute();
                        }
                        $msg = "Role '$roleName' created!";
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
            $cs = $link->prepare("SELECT role_name, is_system FROM roles WHERE id = ?");
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
                // Check if any users have this role
                $uc = $link->prepare("SELECT COUNT(*) as c FROM users WHERE ROLE = ?");
                $uc->bind_param('s', $roleName);
                $uc->execute();
                $ucr = $uc->get_result()->fetch_assoc();
                if ((int)$ucr['c'] > 0) {
                    $msg = "Cannot delete: {$ucr['c']} user(s) still have this role. Reassign them first.";
                    $msg_type = 'danger';
                } else {
                    $dp = $link->prepare("DELETE FROM role_permissions WHERE role = ?");
                    $dp->bind_param('s', $roleName);
                    $dp->execute();
                    $dr = $link->prepare("DELETE FROM roles WHERE id = ?");
                    $dr->bind_param('i', $roleId);
                    $dr->execute();
                    unset($_SESSION['rbac_perms_' . md5($roleName)]);
                    $msg = "Role '$roleName' deleted.";
                    $msg_type = 'success';
                    logActivity($link, USER_ID, 'DELETE', "Deleted role $roleName");
                }
            }
        }
    }
}

// ─── Fetch settings ───
$settings = [];
$sr = mysqli_query($link, "SELECT setting_key, setting_value FROM app_settings");
while ($s = mysqli_fetch_assoc($sr)) $settings[$s['setting_key']] = $s['setting_value'];

// ─── Fetch roles ───
$allRoles = [];
$ar = mysqli_query($link, "SELECT r.*, (SELECT COUNT(*) FROM users WHERE ROLE = r.role_name) as user_count FROM roles r ORDER BY r.is_system DESC, r.id");
while ($a = mysqli_fetch_assoc($ar)) $allRoles[] = $a;
$rolesOrder = array_column($allRoles, 'role_name');

// ─── Fetch permissions ───
$perms = [];
$pr = mysqli_query($link, "SELECT role, permission_key, permission_value FROM role_permissions ORDER BY permission_key");
while ($p = mysqli_fetch_assoc($pr)) $perms[$p['role']][$p['permission_key']] = (int)$p['permission_value'];
$perm_keys = ['manage_users','manage_teams','manage_leads','manage_settings','export_data','delete_leads','view_reports','upload_data','manage_database','assign_leads'];
$perm_labels = [
    'manage_users' => 'Manage Users',
    'manage_teams' => 'Manage Teams',
    'manage_leads' => 'Manage Leads',
    'manage_settings' => 'Manage Settings',
    'export_data' => 'Export Data',
    'delete_leads' => 'Delete Leads',
    'view_reports' => 'View Reports',
    'upload_data' => 'Upload Data',
    'manage_database' => 'Manage Database',
    'assign_leads' => 'Assign Leads',
];

// ─── Fetch users summary ───
$total_users = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM users"))[0];
$active_users = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM users WHERE STATUS='Active'"))[0];
$role_counts = [];
$rc = mysqli_query($link, "SELECT ROLE, COUNT(*) as cnt FROM users GROUP BY ROLE");
while ($r = mysqli_fetch_assoc($rc)) $role_counts[$r['ROLE']] = $r['cnt'];
?>
<?php $pageTitle = 'Settings - CallNow Admin'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<style>
:root {
    --st-accent: #6366f1;
    --st-accent-dark: #4f46e5;
    --st-ink: #1e1b4b;
    --st-ink-soft: #6b6890;
    --st-soft: #f0f2ff;
    --st-border: #e2e4f0;
}

.st-header {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
    border-radius: 1rem;
    padding: 1.5rem 2rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.st-header::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.3;
}
.st-header-content {
    position: relative; z-index: 1;
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;
}
.st-header-left h1 {
    font-size: 1.35rem; font-weight: 700; color: #fff;
    margin: 0 0 0.2rem 0; letter-spacing: -0.02em;
    display: flex; align-items: center; gap: 0.5rem;
}
.st-header-left h1 i { font-size: 1.4rem; }
.st-header-left p { color: rgba(255,255,255,0.7); font-size: 0.8125rem; margin: 0; }

/* ── Tabs ── */
.st-tabs {
    display: flex; gap: 0.25rem; background: var(--st-soft);
    border: 1px solid var(--st-border); border-radius: 0.75rem;
    padding: 0.25rem; margin-bottom: 1.25rem;
}
.st-tab {
    flex: 1; text-align: center; padding: 0.5rem 1rem;
    border-radius: 0.625rem; font-size: 0.8125rem; font-weight: 600;
    color: var(--st-ink-soft); text-decoration: none; transition: all 0.12s ease;
}
.st-tab:hover { color: var(--st-ink); background: rgba(255,255,255,0.6); }
.st-tab.active {
    background: #fff; color: var(--st-accent); box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.st-tab i { margin-right: 0.35rem; }

/* ── Card ── */
.st-card {
    background: #fff; border: 1px solid var(--st-border);
    border-radius: 0.875rem; overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.st-card-body { padding: 1.75rem 2rem; }

/* ── Alert ── */
.st-alert {
    border-radius: 0.625rem; font-size: 0.8125rem;
    padding: 0.75rem 1rem; margin-bottom: 1.25rem;
    display: flex; align-items: center; gap: 0.5rem;
}

/* ── Form ── */
.st-label {
    font-size: 0.75rem; font-weight: 600; color: var(--st-ink);
    margin-bottom: 0.3rem;
}
.st-hint {
    font-size: 0.6875rem; color: var(--st-ink-soft); margin-top: 0.15rem;
}
.st-input, .st-select {
    border: 1px solid var(--st-border) !important;
    border-radius: 0.5rem !important; font-size: 0.8125rem !important;
    color: var(--st-ink) !important; padding: 0.4rem 0.75rem !important;
    background: #fff !important;
}
.st-input:focus, .st-select:focus {
    border-color: var(--st-accent) !important;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.12) !important;
    outline: none;
}
.st-btn-primary {
    background: linear-gradient(135deg, var(--st-accent), var(--st-accent-dark)) !important;
    border: none !important; color: #fff !important; border-radius: 0.5rem !important;
    font-size: 0.8125rem !important; font-weight: 600 !important;
    padding: 0.5rem 1.5rem !important;
    transition: all 0.15s ease !important;
    box-shadow: 0 2px 8px rgba(99,102,241,0.25) !important;
}
.st-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(99,102,241,0.35) !important;
}
.st-btn-danger {
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    border: none !important; color: #fff !important; border-radius: 0.5rem !important;
    font-size: 0.75rem !important; font-weight: 600 !important;
    padding: 0.35rem 1rem !important;
    transition: all 0.15s ease !important;
}
.st-btn-outline {
    border: 1px solid var(--st-border) !important;
    background: #fff !important; color: var(--st-ink-soft) !important;
    border-radius: 0.5rem !important; font-size: 0.75rem !important;
    padding: 0.35rem 1rem !important;
}

/* ── Permission grid ── */
.st-perm-grid {
    width: 100%; border-collapse: separate; border-spacing: 0;
    font-size: 0.8125rem;
}
.st-perm-grid thead th {
    background: #fafbff; color: var(--st-ink-soft);
    font-weight: 600; font-size: 0.6875rem; text-transform: uppercase;
    letter-spacing: 0.04em; padding: 0.625rem 0.875rem;
    border-bottom: 2px solid var(--st-border);
    text-align: center; white-space: nowrap;
}
.st-perm-grid thead th:first-child { text-align: left; }
.st-perm-grid tbody td {
    padding: 0.5rem 0.875rem; border-bottom: 1px solid #f0f1f8;
    text-align: center; vertical-align: middle;
}
.st-perm-grid tbody td:first-child {
    text-align: left; font-weight: 600; color: var(--st-ink);
}
.st-perm-grid tbody tr:hover { background: #f8f9ff; }
.st-perm-grid tbody tr:last-child td { border-bottom: none; }
.st-perm-switch {
    width: 38px; height: 22px; position: relative; display: inline-block;
}
.st-perm-switch input { opacity: 0; width: 0; height: 0; }
.st-perm-slider {
    position: absolute; cursor: pointer; inset: 0;
    background: #d4d6e8; border-radius: 22px; transition: 0.2s;
}
.st-perm-slider::before {
    content: ''; position: absolute; height: 16px; width: 16px;
    left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.2s;
}
.st-perm-switch input:checked + .st-perm-slider {
    background: var(--st-accent);
}
.st-perm-switch input:checked + .st-perm-slider::before {
    transform: translateX(16px);
}

/* ── Stat cards ── */
.st-stat-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 1rem; margin-bottom: 1.5rem;
}
.st-stat-card {
    background: var(--st-soft); border: 1px solid var(--st-border);
    border-radius: 0.75rem; padding: 1rem 1.25rem;
}
.st-stat-card .num {
    font-size: 1.5rem; font-weight: 700; color: var(--st-ink);
    line-height: 1.2;
}
.st-stat-card .lbl {
    font-size: 0.75rem; color: var(--st-ink-soft); margin: 0;
}

/* ── Role cards ── */
.st-role-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 1rem;
}
.st-role-card {
    background: #fff; border: 1px solid var(--st-border);
    border-radius: 0.75rem; padding: 1.25rem;
    transition: box-shadow 0.15s;
}
.st-role-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.05); }
.st-role-card h4 {
    font-size: 0.95rem; font-weight: 700; color: var(--st-ink);
    margin: 0 0 0.2rem 0; display: flex; align-items: center; gap: 0.4rem;
}
.st-role-card .desc {
    font-size: 0.75rem; color: var(--st-ink-soft); margin-bottom: 0.5rem;
}
.st-role-badge {
    display: inline-block; font-size: 0.6rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
    padding: 0.15rem 0.4rem; border-radius: 0.25rem;
}
.st-role-badge.system {
    background: #6366f1; color: #fff;
}
.st-role-badge.custom {
    background: #f0f2ff; color: #6366f1;
}

@media (max-width: 767px) {
    .st-card-body { padding: 1.25rem; }
    .st-perm-grid { font-size: 0.75rem; }
    .st-perm-grid thead th, .st-perm-grid tbody td { padding: 0.4rem 0.5rem; }
}
</style>

<div class="container page-wrapper">

    <!-- ─── Header ─── -->
    <div class="st-header">
        <div class="st-header-content">
            <div class="st-header-left">
                <h1><i class="bi bi-gear-fill"></i> Settings</h1>
                <p>Manage application settings, roles, permissions, and users</p>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="st-alert" style="background:<?= $msg_type==='success'?'#d1fae5':'#fee2e2' ?>;border:1px solid <?= $msg_type==='success'?'#6ee7b7':'#fca5a5' ?>;color:<?= $msg_type==='success'?'#065f46':'#991b1b' ?>;">
            <i class="bi <?= $msg_type==='success'?'bi-check-circle-fill':'bi-x-circle-fill' ?>"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="font-size:0.65rem;"></button>
        </div>
    <?php endif; ?>

    <!-- ─── Tabs ─── -->
    <div class="st-tabs">
        <a href="<?= url('modules/settings/settings.php') ?>?tab=general" class="st-tab <?= $tab==='general'?'active':'' ?>">
            <i class="bi bi-sliders"></i> General
        </a>
        <a href="<?= url('modules/settings/settings.php') ?>?tab=roles" class="st-tab <?= $tab==='roles'?'active':'' ?>">
            <i class="bi bi-diagram-3"></i> Roles
        </a>
        <a href="<?= url('modules/settings/settings.php') ?>?tab=permissions" class="st-tab <?= $tab==='permissions'?'active':'' ?>">
            <i class="bi bi-shield-check"></i> Permissions
        </a>
        <a href="<?= url('modules/settings/settings.php') ?>?tab=users" class="st-tab <?= $tab==='users'?'active':'' ?>">
            <i class="bi bi-people"></i> Users
        </a>
    </div>

    <?php if ($tab === 'general'): ?>
    <!-- ═══ General Settings ═══ -->
    <div class="st-card">
        <div class="st-card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="settings_action" value="save_settings">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="st-label">Application Name</label>
                        <input type="text" name="app_name" class="form-control st-input" value="<?= htmlspecialchars($settings['app_name'] ?? 'CallNow CRM') ?>">
                        <div class="st-hint">Displayed in the browser title bar and header</div>
                    </div>
                    <div class="col-md-6">
                        <label class="st-label">Company Name</label>
                        <input type="text" name="company_name" class="form-control st-input" value="<?= htmlspecialchars($settings['company_name'] ?? 'CallNow') ?>">
                        <div class="st-hint">Default company for new users</div>
                    </div>
                    <div class="col-md-4">
                        <label class="st-label">Default Lead Status</label>
                        <select name="default_lead_status" class="form-select st-select">
                            <?php foreach (['LEAD','FOLLOWUP','LOGIN','UNDERWRTING','SANCTIONED','DISBURSED','REJECT'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= ($settings['default_lead_status'] ?? 'LEAD') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="st-label">Default Lead Stage</label>
                        <select name="default_lead_stage" class="form-select st-select">
                            <?php foreach (['Cold','Warm','Hot'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= ($settings['default_lead_stage'] ?? 'Cold') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="st-label">Timezone</label>
                        <select name="timezone" class="form-select st-select">
                            <?php foreach (['Asia/Kolkata','Asia/Dubai','Asia/Singapore','UTC'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= ($settings['timezone'] ?? 'Asia/Kolkata') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="st-label">Records Per Page</label>
                        <input type="number" name="pagination_size" class="form-control st-input" value="<?= htmlspecialchars($settings['pagination_size'] ?? '50') ?>" min="10" max="200">
                    </div>
                    <div class="col-md-4">
                        <label class="st-label">Session Timeout (minutes)</label>
                        <input type="number" name="session_timeout" class="form-control st-input" value="<?= htmlspecialchars($settings['session_timeout'] ?? '30') ?>" min="5" max="480">
                    </div>
                </div>

                <hr style="border-color:var(--st-border);margin:1.25rem 0;">
                <div class="text-end">
                    <button type="submit" class="st-btn-primary"><i class="bi bi-check-lg"></i> Save Settings</button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($tab === 'roles'): ?>
    <!-- ═══ Role Manager ═══ -->
    <div class="st-card">
        <div class="st-card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 style="font-size:1rem;font-weight:700;color:var(--st-ink);margin:0;"><i class="bi bi-diagram-3"></i> Roles</h5>
                    <p style="font-size:0.75rem;color:var(--st-ink-soft);margin:0;">Create and manage user roles. System roles (Admin, Manager, etc.) cannot be deleted.</p>
                </div>
                <button type="button" class="st-btn-primary" data-bs-toggle="modal" data-bs-target="#addRoleModal" style="font-size:0.75rem!important;padding:0.4rem 1rem!important;">
                    <i class="bi bi-plus-lg"></i> New Role
                </button>
            </div>

            <div class="st-role-grid">
                <?php foreach ($allRoles as $role): ?>
                    <?php $isSys = (int)$role['is_system'] === 1; ?>
                    <div class="st-role-card">
                        <h4>
                            <i class="bi bi-person-badge" style="color:<?= $isSys?'#6366f1':'#a3a3a3' ?>;"></i>
                            <?= htmlspecialchars($role['role_name']) ?>
                            <span class="st-role-badge <?= $isSys ? 'system' : 'custom' ?>">
                                <?= $isSys ? 'System' : 'Custom' ?>
                            </span>
                        </h4>
                        <div class="desc"><?= htmlspecialchars($role['description'] ?: 'No description') ?></div>
                        <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.75rem;color:var(--st-ink-soft);">
                            <span><i class="bi bi-people"></i> <?= (int)$role['user_count'] ?> user(s)</span>
                            <div style="display:flex;gap:0.4rem;">
                                <?php if (!$isSys): ?>
                                    <button type="button" class="st-btn-outline" style="padding:0.2rem 0.6rem!important;"
                                        data-bs-toggle="modal" data-bs-target="#editRoleModal"
                                        data-id="<?= $role['id'] ?>"
                                        data-name="<?= htmlspecialchars($role['role_name']) ?>"
                                        data-desc="<?= htmlspecialchars($role['description']) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete role &quot;<?= htmlspecialchars($role['role_name']) ?>&quot;?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="settings_action" value="delete_role">
                                        <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                                        <button type="submit" class="st-btn-outline" style="padding:0.2rem 0.6rem!important;color:#ef4444!important;border-color:#fca5a5!important;">
                                            <i class="bi bi-trash"></i>
                                        </button>
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

    <!-- Add Role Modal -->
    <div class="modal fade" id="addRoleModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content" style="border-radius:0.75rem;border:1px solid var(--st-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="settings_action" value="save_role">
                    <input type="hidden" name="role_id" value="0">
                    <div class="modal-header" style="border-bottom-color:var(--st-border);padding:1rem 1.25rem;">
                        <h6 style="font-weight:700;font-size:0.9rem;margin:0;"><i class="bi bi-plus-circle"></i> New Role</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:0.7rem;"></button>
                    </div>
                    <div class="modal-body" style="padding:1.25rem;">
                        <div class="mb-3">
                            <label class="st-label">Role Name</label>
                            <input type="text" name="role_name" class="form-control st-input" required maxlength="50" placeholder="e.g. Team Lead">
                        </div>
                        <div>
                            <label class="st-label">Description</label>
                            <input type="text" name="role_description" class="form-control st-input" placeholder="Brief description" maxlength="255">
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top-color:var(--st-border);padding:0.75rem 1.25rem;">
                        <button type="button" class="st-btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="st-btn-primary" style="padding:0.4rem 1.2rem!important;">Create Role</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Role Modal -->
    <div class="modal fade" id="editRoleModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content" style="border-radius:0.75rem;border:1px solid var(--st-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="settings_action" value="save_role">
                    <input type="hidden" name="role_id" id="edit_role_id" value="0">
                    <div class="modal-header" style="border-bottom-color:var(--st-border);padding:1rem 1.25rem;">
                        <h6 style="font-weight:700;font-size:0.9rem;margin:0;"><i class="bi bi-pencil-square"></i> Edit Role</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:0.7rem;"></button>
                    </div>
                    <div class="modal-body" style="padding:1.25rem;">
                        <div class="mb-3">
                            <label class="st-label">Role Name</label>
                            <input type="text" name="role_name" id="edit_role_name" class="form-control st-input" required maxlength="50">
                        </div>
                        <div>
                            <label class="st-label">Description</label>
                            <input type="text" name="role_description" id="edit_role_desc" class="form-control st-input" maxlength="255">
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top-color:var(--st-border);padding:0.75rem 1.25rem;">
                        <button type="button" class="st-btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="st-btn-primary" style="padding:0.4rem 1.2rem!important;">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('editRoleModal')?.addEventListener('show.bs.modal', function (e) {
        const btn = e.relatedTarget;
        document.getElementById('edit_role_id').value = btn.dataset.id;
        document.getElementById('edit_role_name').value = btn.dataset.name;
        document.getElementById('edit_role_desc').value = btn.dataset.desc;
    });
    </script>

    <?php elseif ($tab === 'permissions'): ?>
    <!-- ═══ Permissions Manager ═══ -->
    <div class="st-card">
        <div class="st-card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="settings_action" value="save_permissions">

                <p style="font-size:0.8125rem;color:var(--st-ink-soft);margin-bottom:1rem;">
                    Toggle permissions for each role. <strong>Admin</strong> always has full access.
                    <br>Add or rename roles in the <a href="<?= url('modules/settings/settings.php') ?>?tab=roles" style="color:var(--st-accent);">Roles tab</a>.
                </p>

                <?php if (empty($rolesOrder)): ?>
                    <p style="color:#ef4444;">No roles found. <a href="<?= url('modules/settings/settings.php') ?>?tab=roles">Create one</a>.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="st-perm-grid">
                        <thead>
                            <tr>
                                <th>Permission</th>
                                <?php foreach ($rolesOrder as $role): ?>
                                    <th><i class="bi bi-person"></i> <?= htmlspecialchars($role) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($perm_keys as $pk): ?>
                                <tr>
                                    <td>
                                        <strong><?= $perm_labels[$pk] ?></strong>
                                        <br><span style="font-size:0.6875rem;color:var(--st-ink-soft);"><?= $pk ?></span>
                                    </td>
                                    <?php foreach ($rolesOrder as $role): ?>
                                        <td>
                                            <?php if ($role === 'Admin'): ?>
                                                <span style="color:#10b981;font-size:0.75rem;font-weight:600;"><i class="bi bi-check-lg"></i> Full</span>
                                                <input type="hidden" name="perm_<?= $role ?>_<?= $pk ?>" value="1">
                                            <?php else: ?>
                                                <label class="st-perm-switch">
                                                    <input type="checkbox" name="perm_<?= $role ?>_<?= $pk ?>" value="1" <?= ($perms[$role][$pk] ?? 0) ? 'checked' : '' ?>>
                                                    <span class="st-perm-slider"></span>
                                                </label>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <hr style="border-color:var(--st-border);margin:1.25rem 0;">
                <div class="text-end">
                    <button type="submit" class="st-btn-primary"><i class="bi bi-check-lg"></i> Save Permissions</button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($tab === 'users'): ?>
    <!-- ═══ User Management ═══ -->
    <div class="st-card">
        <div class="st-card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 style="font-size:1rem;font-weight:700;color:var(--st-ink);margin:0;">User Overview</h5>
                    <p style="font-size:0.75rem;color:var(--st-ink-soft);margin:0;">Summary of all registered users</p>
                </div>
                <a href="<?= url('modules/users/users_add.php') ?>" class="st-btn-primary" style="font-size:0.75rem!important;padding:0.4rem 1rem!important;">
                    <i class="bi bi-person-plus"></i> Add User
                </a>
            </div>

            <div class="st-stat-grid">
                <div class="st-stat-card">
                    <div class="num"><?= $total_users ?></div>
                    <p class="lbl">Total Users</p>
                </div>
                <div class="st-stat-card">
                    <div class="num"><?= $active_users ?></div>
                    <p class="lbl">Active</p>
                </div>
                <?php foreach ($role_counts as $role => $cnt): ?>
                    <div class="st-stat-card">
                        <div class="num"><?= $cnt ?></div>
                        <p class="lbl"><?= htmlspecialchars($role) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <hr style="border-color:var(--st-border);margin:1rem 0;">
            <p style="font-size:0.8125rem;color:var(--st-ink-soft);margin-bottom:0;">
                <i class="bi bi-arrow-right-circle"></i>
                <a href="<?= url('modules/users/users_view.php') ?>" style="color:var(--st-accent);font-weight:600;">Go to full User Management</a> to edit, filter, and manage all users.
            </p>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
