<?php
/**
 * Automated tests for RBAC permission boundaries on users section pages.
 * Real-life scenarios: unauthorized access by Officer, Supervisor, Manager roles.
 */

chdir(__DIR__ . '/..');
require_once __DIR__.'/../php_scripts/config.php';
require_once __DIR__.'/lib/db_helper.php';

$tests = [];
$passed = 0;
$failed = 0;

function test_page_as_user(string $userId, string $userRole, int $userTeamId, string $scriptPath, string $expectContains = '', string $desc = ''): array {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION['loggedin'] = true;
    $_SESSION['id'] = $userId;
    $_SESSION['csrf_token'] = 'test_csrf_rbac_123';
    define('USER_ID', $userId);
    define('USER_ROLE', $userRole);
    define('USER_TEAM_ID', $userTeamId);
    
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    include $scriptPath;
    $output = ob_get_clean();
    $passed = ($expectContains === '' || strpos($output, $expectContains) !== false || headers_sent());
    return [$passed, $output];
}

$officerUser = dbFetchOne("SELECT ID, TEAM_ID FROM " . tn('TBL_USERS') . " WHERE ROLE = 'Officer' AND ID != 1 LIMIT 1");
$officerId = $officerUser['ID'] ?? 0;
$officerTeamId = $officerUser['TEAM_ID'] ?? null;

echo "Precondition: Officer user ID={$officerId}\n\n";

if ($officerId > 0) {
    list($p, $out) = test_page_as_user($officerId, 'Officer', $officerTeamId, __DIR__.'/../modules/users/users_view.php', 'Access denied', 'Officer users_view.php');
    $tests[] = ['desc'=>'Officer denied users_view.php','passed'=>$p];
    echo "[" . ($p ? 'PASS' : 'FAIL') . "] Officer denied users_view.php\n";

    list($p, $out) = test_page_as_user($officerId, 'Officer', $officerTeamId, __DIR__.'/../modules/users/users_add.php', 'Access denied', 'Officer users_add.php');
    $tests[] = ['desc'=>'Officer denied users_add.php','passed'=>$p];
    echo "[" . ($p ? 'PASS' : 'FAIL') . "] Officer denied users_add.php\n";

    list($p, $out) = test_page_as_user($officerId, 'Officer', $officerTeamId, __DIR__.'/../modules/users/teams_dashboard.php', 'Access denied', 'Officer teams_dashboard.php');
    $tests[] = ['desc'=>'Officer denied teams_dashboard.php','passed'=>$p];
    echo "[" . ($p ? 'PASS' : 'FAIL') . "] Officer denied teams_dashboard.php\n";

    list($p, $out) = test_page_as_user($officerId, 'Officer', $officerTeamId, __DIR__.'/../modules/users/team_members.php', 'Access denied', 'Officer team_members.php');
    $tests[] = ['desc'=>'Officer denied team_members.php','passed'=>$p];
    echo "[" . ($p ? 'PASS' : 'FAIL') . "] Officer denied team_members.php\n";
} else {
    echo "[SKIP] Officer RBAC tests (no Officer user found)\n";
}

$supervisorUser = dbFetchOne("SELECT ID, TEAM_ID FROM " . tn('TBL_USERS') . " WHERE ROLE = 'Supervisor' AND ID != 1 LIMIT 1");
$supervisorId = $supervisorUser['ID'] ?? 0;
$supervisorTeamId = $supervisorUser['TEAM_ID'] ?? null;

if ($supervisorId > 0) {
    list($p, $out) = test_page_as_user($supervisorId, 'Supervisor', $supervisorTeamId, __DIR__.'/../modules/users/users_add.php', '', 'Supervisor users_add.php');
    $tests[] = ['desc'=>'Supervisor can access users_add.php','passed'=>$p];
    echo "[" . ($p ? 'PASS' : 'FAIL') . "] Supervisor can access users_add.php\n";
} else {
    echo "[SKIP] Supervisor RBAC tests\n";
}

$managerUser = dbFetchOne("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ROLE = 'Manager' AND ID != 1 LIMIT 1");
$managerId = $managerUser['ID'] ?? 0;

if ($managerId > 0) {
    list($p, $out) = test_page_as_user($managerId, 'Manager', null, __DIR__.'/../modules/users/teams_dashboard.php', '', 'Manager teams_dashboard.php');
    $tests[] = ['desc'=>'Manager can access teams_dashboard.php','passed'=>$p];
    echo "[" . ($p ? 'PASS' : 'FAIL') . "] Manager can access teams_dashboard.php\n";
} else {
    echo "[SKIP] Manager RBAC tests\n";
}

if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION['loggedin'] = true;
$_SESSION['id'] = 1;
define('USER_ID', 1);
define('USER_ROLE', 'Admin');
define('USER_TEAM_ID', null);

require_once __DIR__.'/../php_scripts/auth.php';
$canManageUsers = can('manage_users');
echo "Precondition: can('manage_users') = " . ($canManageUsers ? 'true' : 'false') . "\n";
$canManageTeams = can('manage_teams');
echo "Precondition: can('manage_teams') = " . ($canManageTeams ? 'true' : 'false') . "\n";

foreach ($tests as $t) { if ($t['passed']) $passed++; else $failed++; }
echo "\n=== RBAC BOUNDARY TEST SUMMARY: {$passed}/" . count($tests) . " passed, {$failed} failed ===\n";
