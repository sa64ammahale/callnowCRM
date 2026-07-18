<?php
/**
 * Set up the DB-backed permissions system. Safe to run multiple times.
 *
 *  - creates `app_settings` (for the rbac cache version) if missing
 *  - creates `permissions` and `user_permissions` tables
 *  - seeds the 11 system permission keys
 *  - seeds role_permissions rows for every role (system defaults; custom = 0)
 *
 * Run from CLI:   php setup_permissions.php
 * Run in browser: open as Admin
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/php_scripts/permissions.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    require_once __DIR__ . '/php_scripts/auth.php';
    if (!isAdmin()) { http_response_code(403); exit('Admin only'); }
}
function rbacMsg($s) { global $isCli; echo $isCli ? "$s\n" : "<div>$s</div>"; }

// Ensure app_settings exists (holds rbac_cache_version)
$link->query("CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 1. permissions table
$link->query("CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    category VARCHAR(50) DEFAULT 'General',
    description TEXT DEFAULT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 2. user_permissions table
$link->query("CREATE TABLE IF NOT EXISTS user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_key VARCHAR(50) NOT NULL,
    permission_value TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_user_perm (user_id, permission_key),
    FOREIGN KEY (user_id) REFERENCES users(ID) ON DELETE CASCADE,
    FOREIGN KEY (permission_key) REFERENCES permissions(permission_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 3. Seed permission keys
$seeded = seedPermissions($link);
rbacMsg("Seeded permission keys: $seeded new.");

// 4. Seed role_permissions for all roles.
// First run (guarded by rbac_initialized flag): reset SYSTEM roles to defined defaults
// and ensure custom roles have all keys present (value 0 = secure by default).
// Re-runs: never clobber existing values; only backfill missing role/key rows with 0.
$defaults = getSystemRoleDefaults();
$systemRoles = array_keys($defaults);
$keys = array_keys(getPermissionSeed());
$zero = 0;
$ins = mysqli_prepare($link, "INSERT IGNORE INTO role_permissions (role, permission_key, permission_value) VALUES (?, ?, ?)");

$initialized = 0;
$ires = mysqli_query($link, "SELECT setting_value FROM app_settings WHERE setting_key = 'rbac_initialized'");
if ($ires && $irow = mysqli_fetch_assoc($ires)) $initialized = (int)$irow['setting_value'];

if (!$initialized) {
    $del = mysqli_prepare($link, "DELETE FROM role_permissions WHERE role = ?");
    foreach ($systemRoles as $sysRole) {
        mysqli_stmt_bind_param($del, 's', $sysRole);
        mysqli_stmt_execute($del);
    }
    $changed = 0;
    foreach ($defaults as $role => $roleDef) {
        foreach ($keys as $pk) {
            $val = !empty($roleDef['*']) || !empty($roleDef[$pk]) ? 1 : 0;
            mysqli_stmt_bind_param($ins, 'ssi', $role, $pk, $val);
            mysqli_stmt_execute($ins);
            $changed += mysqli_stmt_affected_rows($ins);
        }
    }
    // custom roles -> ensure all keys present with value 0
    $custRes = mysqli_query($link, "SELECT role_name FROM roles WHERE role_name NOT IN ('Admin','Manager','Supervisor','Officer')");
    while ($custRes && $cr = mysqli_fetch_assoc($custRes)) {
        foreach ($keys as $pk) {
            mysqli_stmt_bind_param($ins, 'ssi', $cr['role_name'], $pk, $zero);
            mysqli_stmt_execute($ins);
        }
    }
    mysqli_query($link, "INSERT INTO app_settings (setting_key, setting_value) VALUES ('rbac_initialized', 1) ON DUPLICATE KEY UPDATE setting_value = 1");
    rbacMsg("Initialized system role default permissions ($changed rows).");
} else {
    $allRoles = [];
    $rr = mysqli_query($link, "SELECT role_name FROM roles");
    while ($rr && $x = mysqli_fetch_assoc($rr)) $allRoles[] = $x['role_name'];
    foreach ($allRoles as $role) {
        foreach ($keys as $pk) {
            mysqli_stmt_bind_param($ins, 'ssi', $role, $pk, $zero);
            mysqli_stmt_execute($ins);
        }
    }
    rbacMsg("Ensured full permission matrix exists for all roles (existing values preserved).");
}

// 5. Ensure rbac cache version row exists
$link->query("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('rbac_cache_version', 0)");

rbacMsg("");
rbacMsg("=== Permission system setup complete ===");
rbacMsg("Open Settings &rarr; Manage Roles &amp; Permissions.");
