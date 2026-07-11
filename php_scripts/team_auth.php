<?php


require_once __DIR__ . "/../config.php";

$user_id = $_SESSION["id"];
$stmt = $link->prepare("SELECT u.*, t.NAME as TEAM_NAME, t.SUPERVISOR_ID 
                        FROM USERS u 
                        LEFT JOIN TEAMS t ON u.TEAM_ID = t.ID 
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
function canViewAllTeams() {
    return in_array(USER_ROLE, ['Admin', 'Manager']);
}

function canManageTeams() {
    return in_array(USER_ROLE, ['Admin', 'Manager']);
}

function getTeamFilterSQL($table_alias = 'm') {
    if (canViewAllTeams()) return "";
    
    if (USER_ROLE == 'Supervisor') {
        return " AND {$table_alias}.CALL_BY IN (SELECT ID FROM USERS WHERE TEAM_ID = " . USER_TEAM_ID . ")";
    }
    
    if (USER_ROLE == 'Officer') {
        return " AND {$table_alias}.CALL_BY = " . USER_ID;
    }
    return "";
}

function requireTeamAccess($required_roles = ['Admin','Manager','Supervisor']) {
    if (!in_array(USER_ROLE, $required_roles)) {
        $_SESSION['error'] = "Access Denied!";
        header("Location: " . (defined('APP_BASE') ? APP_BASE . '/' : '') . "dashboard.php"); exit;
    }
}
?>
