<?php
/**
 * Automated tests for modules/users/teams_dashboard.php
 * Real-life scenarios: team CRUD, member reassignment, duplicate team name.
 */

chdir(__DIR__ . '/..');
require_once __DIR__.'/../php_scripts/config.php';
require_once __DIR__.'/lib/db_helper.php';

if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION['loggedin'] = true;
$_SESSION['id'] = 1;
$_SESSION['csrf_token'] = 'test_csrf_teams_123';

$adminId = 1;
$stmt = mysqli_prepare($link, "SELECT ID, NAME, ROLE, TEAM_ID FROM " . tn('TBL_USERS') . " WHERE ID = ?");
mysqli_stmt_bind_param($stmt, "i", $adminId);
mysqli_stmt_execute($stmt);
$user = mysqli_stmt_get_result($stmt)->fetch_assoc();
define('USER_ID', (int)$user['ID']);
define('USER_ROLE', $user['ROLE']);
define('USER_TEAM_ID', $user['TEAM_ID']);

$tests = [];
$passed = 0;
$failed = 0;

function run_post_test(string $scriptPath, array $post = [], string $expectContains = '', string $desc = ''): array {
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SESSION['csrf_token'] = 'test_csrf_teams_123';
    ob_start();
    include $scriptPath;
    $output = ob_get_clean();
    $passed = ($expectContains === '' || strpos($output, $expectContains) !== false);
    return [$passed, $output];
}

$teamCount = dbCount('TBL_TEAMS');
echo "Precondition: {$teamCount} teams in DB\n\n";

$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
include __DIR__.'/../modules/users/teams_dashboard.php';
$pageOutput = ob_get_clean();
$pageLoads = strpos($pageOutput, 'Teams') !== false || strpos($pageOutput, 'Team') !== false;
$tests[] = ['desc'=>'Teams dashboard loads','passed'=>$pageLoads];
echo "[" . ($pageLoads ? 'PASS' : 'FAIL') . "] Teams dashboard loads\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/teams_dashboard.php', [
    'action' => 'add', 'team_name' => 'Test QA Team', 'supervisor_id' => '', 'manager_id' => '',
    'csrf_token' => 'test_csrf_teams_123'
], 'Team created successfully', 'Add new team');
$tests[] = ['desc'=>'Add new team','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Add new team\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/teams_dashboard.php', [
    'action' => 'add', 'team_name' => 'Test QA Team', 'supervisor_id' => '', 'manager_id' => '',
    'csrf_token' => 'test_csrf_teams_123'
], 'Team name already exists', 'Duplicate team name rejected');
$tests[] = ['desc'=>'Duplicate team name rejected','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Duplicate team name rejected\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/teams_dashboard.php', [
    'action' => 'add', 'team_name' => '', 'supervisor_id' => '', 'manager_id' => '',
    'csrf_token' => 'test_csrf_teams_123'
], 'Team name is required', 'Empty team name rejected');
$tests[] = ['desc'=>'Empty team name rejected','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Empty team name rejected\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/teams_dashboard.php', [
    'action' => 'reassign_members', 'from_team_id' => '1', 'to_team_id' => '1',
    'csrf_token' => 'test_csrf_teams_123'
], 'Please select a valid target team', 'Reassign same team rejected');
$tests[] = ['desc'=>'Reassign same team rejected','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Reassign same team rejected\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/teams_dashboard.php', [
    'action' => 'reassign_members', 'from_team_id' => '99999', 'to_team_id' => '99998',
    'csrf_token' => 'test_csrf_teams_123'
], 'Invalid team selection', 'Reassign non-existent teams rejected');
$tests[] = ['desc'=>'Reassign non-existent teams rejected','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Reassign non-existent teams rejected\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/teams_dashboard.php', [
    'action' => 'add', 'team_name' => 'CSRF Team', 'supervisor_id' => '', 'manager_id' => '',
    'csrf_token' => 'wrong_token'
], 'Invalid session token', 'Invalid CSRF token rejected');
$tests[] = ['desc'=>'Invalid CSRF token rejected','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Invalid CSRF token rejected\n";

foreach ($tests as $t) { if ($t['passed']) $passed++; else $failed++; }
echo "\n=== teams_dashboard.php TEST SUMMARY: {$passed}/" . count($tests) . " passed, {$failed} failed ===\n";
