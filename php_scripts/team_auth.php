<?php


require_once __DIR__ . '/config.php';

$user_id = $_SESSION["id"];
$stmt = $link->prepare("SELECT u.*, t.NAME as TEAM_NAME, t.SUPERVISOR_ID 
                        FROM " . tn('TBL_USERS') . " u 
                        LEFT JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID 
                        WHERE u.ID = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!defined('USER_ROLE'))     define('USER_ROLE',     $user['ROLE'] ?? 'Officer');
if (!defined('USER_TEAM_ID'))  define('USER_TEAM_ID',  $user['TEAM_ID'] ?? null);
if (!defined('USER_ID'))       define('USER_ID',       $user['ID']);
if (!defined('USER_NAME'))     define('USER_NAME',     $user['NAME'] ?? $user['LOGIN_ID']);
if (!defined('USER_TEAM_NAME')) define('USER_TEAM_NAME', $user['TEAM_NAME'] ?? 'No Team');
if (!defined('IS_SUPERVISOR_OF_TEAM'))  define('IS_SUPERVISOR_OF_TEAM', ($user['ID'] == $user['SUPERVISOR_ID']));

// Helper Functions
if (!function_exists('canViewAllTeams')) {
    function canViewAllTeams(): bool {
        if (function_exists('isSuperAdmin') && isSuperAdmin()) return true;
        $role = defined('USER_ROLE') ? USER_ROLE : ($_SESSION['role'] ?? '');
        if (function_exists('getNormalizedRole')) {
            return getNormalizedRole($role) === 'Super Admin';
        }
        return $role === 'Super Admin';
    }
}

if (!function_exists('canManageTeams')) {
    function canManageTeams(): bool {
        if (function_exists('isSuperAdmin') && isSuperAdmin()) return true;
        $role = defined('USER_ROLE') ? USER_ROLE : ($_SESSION['role'] ?? '');
        if (function_exists('getNormalizedRole')) {
            $norm = getNormalizedRole($role);
            return in_array($norm, ['Super Admin', 'Admin', 'Manager'], true);
        }
        return in_array($role, ['Super Admin', 'Admin', 'Manager'], true);
    }
}

if (!function_exists('canViewAllTBL_TEAMS')) {
    function canViewAllTBL_TEAMS(): bool {
        return canViewAllTeams();
    }
}

if (!function_exists('canManageTBL_TEAMS')) {
    function canManageTBL_TEAMS(): bool {
        return canManageTeams();
    }
}

function getTeamFilterSQL($table_alias = 'm') {
    if (canViewAllTeams()) return "";
    
    if (USER_ROLE == 'Supervisor') {
        return " AND {$table_alias}.CALL_BY IN (SELECT ID FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = " . USER_TEAM_ID . ")";
    }
    
    if (USER_ROLE == 'Officer') {
        return " AND {$table_alias}.CALL_BY = " . USER_ID;
    }
    return "";
}

function requireTeamAccess($required_TBL_ROLES = ['Admin','Manager','Supervisor']) {
    if (!in_array(USER_ROLE, $required_TBL_ROLES)) {
        $_SESSION['error'] = "Access Denied!";
        header("Location: " . (defined('APP_BASE') ? APP_BASE . '/' : '') . "dashboard"); exit;
    }
}
?>
