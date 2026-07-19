<?php
date_default_timezone_set('Asia/Kolkata');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../config.php';

function appRedirect(string $path): void {
    $base = defined('APP_BASE') ? APP_BASE : '';
    header('Location: ' . rtrim($base, '/') . '/' . ltrim($path, '/'));
    exit;
}

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    appRedirect('index.php');
}

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 1800)) {
    session_unset(); session_destroy();
    appRedirect('index.php');
}
$_SESSION['LAST_ACTIVITY'] = time();

ensureCsrfToken();



$user_id = (int)($_SESSION['id'] ?? 0);
if ($user_id <= 0) { appRedirect('index.php'); }


$stmt = mysqli_prepare($link, "SELECT ID, NAME, ROLE, TEAM_ID FROM TBL_USERS WHERE ID = ?");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);


if (!defined('CURRENT_USER')) define('CURRENT_USER', $user);
if (!defined('USER_ROLE')) define('USER_ROLE', $user['ROLE']);
if (!defined('USER_ID'))   define('USER_ID',   (int)$user['ID']);
if (!defined('USER_TEAM_ID')) define('USER_TEAM_ID', $user['TEAM_ID']);
if (!defined('THEME')) define('THEME', ($_SESSION['theme'] ?? 'light') === 'dark' ? 'dark' : 'light');


// Helpers
function isAdmin() { return USER_ROLE === 'Admin'; }
function isManager() { return USER_ROLE === 'Manager'; }
function isSupervisor() { return USER_ROLE === 'Supervisor'; }
function isOfficer() { return USER_ROLE === 'Officer'; }

// Require role
function requireRole($TBL_ROLES) {
    $TBL_ROLES = is_array($TBL_ROLES) ? $TBL_ROLES : [$TBL_ROLES];
    if (!in_array(USER_ROLE, $TBL_ROLES)) {
        appRedirect('dashboard.php');
    }
}

// ---- RBAC cache version (bumped on any role/permission change) ----
function rbacCacheVersion(): int {
    static $v = null;
    if ($v !== null) return $v;
    global $link;
    $v = 0;
    $res = mysqli_query($link, "SELECT setting_value FROM TBL_APP_SETTINGS WHERE setting_key = 'rbac_cache_version'");
    if ($res && $row = mysqli_fetch_assoc($res)) $v = (int)$row['setting_value'];
    return $v;
}

function clearRbacCache(): void {
    global $link;
    $v = rbacCacheVersion() + 1;
    mysqli_query($link, "INSERT INTO TBL_APP_SETTINGS (setting_key, setting_value) VALUES ('rbac_cache_version', $v) ON DUPLICATE KEY UPDATE setting_value = $v");
}

// Build the effective permission set for the current user:
//   - Admin role OR System Admin (USER_ID===1) => everything (handled in can())
//   - starts from TBL_ROLE_PERMISSIONS for the user's role
//   - TBL_USER_PERMISSIONS overrides win over role level
function loadEffectiveTBL_PERMISSIONS(): array {
    static $perms = null;
    if ($perms !== null) return $perms;

    $version = rbacCacheVersion();
    $cacheKey = 'rbac_user_perms_' . USER_ID . '_' . $version;
    if (isset($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
        $perms = $_SESSION[$cacheKey];
        return $perms;
    }

    global $link;
    $perms = [];

    $role = USER_ROLE;
    $stmt = mysqli_prepare($link, "SELECT permission_key, permission_value FROM TBL_ROLE_PERMISSIONS WHERE role = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $role);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $perms[$row['permission_key']] = (int)$row['permission_value'];
        }
        mysqli_stmt_close($stmt);
    }

    $curUid = USER_ID;
    $stmt2 = mysqli_prepare($link, "SELECT permission_key, permission_value FROM TBL_USER_PERMISSIONS WHERE user_id = ?");
    if ($stmt2) {
        mysqli_stmt_bind_param($stmt2, 'i', $curUid);
        mysqli_stmt_execute($stmt2);
        $res2 = mysqli_stmt_get_result($stmt2);
        while ($row = mysqli_fetch_assoc($res2)) {
            $perms[$row['permission_key']] = (int)$row['permission_value'];
        }
        mysqli_stmt_close($stmt2);
    }

    $_SESSION[$cacheKey] = $perms;
    return $perms;
}

// Authoritative permission check. Admin role and System Admin always pass.
function can(string $permissionKey): bool {
    if (USER_ROLE === 'Admin' || USER_ID === 1) return true;
    $perms = loadEffectiveTBL_PERMISSIONS();
    return !empty($perms[$permissionKey]);
}

// Gate a page/action: redirect to dashboard if the user lacks the permission.
function requirePermission(string $key): void {
    if (!can($key)) appRedirect('dashboard.php');
}

// Gate a page/action: redirect unless the user has at least one of the keys.
function requireAnyPermission(array $keys): void {
    foreach ($keys as $k) {
        if (can($k)) return;
    }
    appRedirect('dashboard.php');
}

// Team filter for queries
function getTeamFilter() {
    if (isAdmin() || isManager()) return "";
    if (isSupervisor()) return " AND TEAM_ID = " . USER_TEAM_ID;
    return " AND CALL_BY = " . $_SESSION['id'];
}


// Get TBL_TEAMS current manager can control
function getManagerTeamIds(mysqli $link, int $managerId): array {
    $ids = [];
    $stmt = $link->prepare("SELECT ID FROM TBL_TEAMS WHERE MANAGER_ID = ?");
    $stmt->bind_param("i", $managerId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $ids[] = (int)$r['ID'];
    }
    return $ids;
}

// ---- Activity logging helper ----
function normalizeActivityType($actionType): string {
    $normalized = strtoupper(trim((string)$actionType));
    $normalized = str_replace([' ', '-'], '_', $normalized);

    $allowed = [
        'INSERT', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT',
        'UPLOAD', 'TRANSFER', 'EXPORT', 'ASSIGN', 'STATUS_CHANGE',
        'REMARK', 'BULK_DELETE', 'BULK_UPDATE', 'TEAM_CHANGE'
    ];

    if (in_array($normalized, $allowed, true)) {
        return $normalized;
    }

    $aliases = [
        'MANUAL_LEAD_CREATED' => 'INSERT',
        'UPSERT' => 'UPDATE',
        'UPDATE_ROW' => 'UPDATE',
        'DELETE_ROW' => 'DELETE',
        'DELETE_SELECTED' => 'BULK_DELETE',
        'DELETE_ALL' => 'BULK_DELETE',
        'TRANSFER_SELECTED' => 'TRANSFER',
        'BULKUPDATE' => 'BULK_UPDATE',
    ];

    return $aliases[$normalized] ?? 'UPDATE';
}

function logActivity($link, $userId, $actionType, $actionDetails = null, $affectedIds = null, $targetTable = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $actionType = normalizeActivityType($actionType);
    $stmt = mysqli_prepare($link, "INSERT INTO TBL_ACTIVITY_LOG(USER_ID, ACTION_TYPE, ACTION_DETAILS, AFFECTED_IDS, TARGET_TABLE, LOG_TIME, IP_ADDRESS)
        VALUES (?, ?, ?, ?, ?, NOW(), ?)");
    mysqli_stmt_bind_param($stmt, "isssss", $userId, $actionType, $actionDetails, $affectedIds, $targetTable, $ip);
    mysqli_stmt_execute($stmt);
}

// Return array of user IDs accessible to current user (for filtering queries)
function getAccessibleUserIds($link) : array {
    if (isAdmin()) {
        return []; // empty meaning "no restriction" for usage below
    }
    if (isManager()) {
        // all TBL_USERS whose TEAM_ID is under manager's TBL_TEAMS
        $teamIds = getManagerTeamIds($link, USER_ID);
        if (empty($teamIds)) return [USER_ID]; // manager with no TBL_TEAMS - only self
        // fetch TBL_USERS in those TBL_TEAMS
        $in = implode(',', array_map('intval', $teamIds));
        $sql = "SELECT ID FROM TBL_USERS WHERE TEAM_ID IN ($in)";
        $res = mysqli_query($link, $sql);
        $ids = [];
        while ($r = mysqli_fetch_assoc($res)) $ids[] = (int)$r['ID'];
        return $ids;
    }
    if (isSupervisor()) {
        // supervisor only sees TBL_USERS in their TEAM_ID
        if (USER_TEAM_ID === null) return [USER_ID];
        $teamId = USER_TEAM_ID;
        $stmt = $link->prepare("SELECT ID FROM TBL_USERS WHERE TEAM_ID = ?");
        $stmt->bind_param("i", $teamId);
        $stmt->execute();
        $res = $stmt->get_result();
        $ids = [];
        while ($r = $res->fetch_assoc()) $ids[] = (int)$r['ID'];
        return $ids;
    }
    // officers -> only themselves
    return [USER_ID];
}





?>
