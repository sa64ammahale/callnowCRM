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


$stmt = mysqli_prepare($link, "SELECT ID, NAME, ROLE, TEAM_ID FROM USERS WHERE ID = ?");
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
function requireRole($roles) {
    $roles = is_array($roles) ? $roles : [$roles];
    if (!in_array(USER_ROLE, $roles)) {
        appRedirect('dashboard.php');
    }
}

// Team filter for queries
function getTeamFilter() {
    if (isAdmin() || isManager()) return "";
    if (isSupervisor()) return " AND TEAM_ID = " . USER_TEAM_ID;
    return " AND CALL_BY = " . $_SESSION['id'];
}


// Get teams current manager can control
function getManagerTeamIds(mysqli $link, int $managerId): array {
    $ids = [];
    $stmt = $link->prepare("SELECT ID FROM TEAMS WHERE MANAGER_ID = ?");
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
    $stmt = mysqli_prepare($link, "INSERT INTO ACTIVITY_LOG(USER_ID, ACTION_TYPE, ACTION_DETAILS, AFFECTED_IDS, TARGET_TABLE, LOG_TIME, IP_ADDRESS)
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
        // all users whose TEAM_ID is under manager's teams
        $teamIds = getManagerTeamIds($link, USER_ID);
        if (empty($teamIds)) return [USER_ID]; // manager with no teams - only self
        // fetch users in those teams
        $in = implode(',', array_map('intval', $teamIds));
        $sql = "SELECT ID FROM USERS WHERE TEAM_ID IN ($in)";
        $res = mysqli_query($link, $sql);
        $ids = [];
        while ($r = mysqli_fetch_assoc($res)) $ids[] = (int)$r['ID'];
        return $ids;
    }
    if (isSupervisor()) {
        // supervisor only sees users in their TEAM_ID
        if (USER_TEAM_ID === null) return [USER_ID];
        $stmt = $link->prepare("SELECT ID FROM USERS WHERE TEAM_ID = ?");
        $stmt->bind_param("i", USER_TEAM_ID);
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
