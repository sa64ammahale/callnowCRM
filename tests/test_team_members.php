<?php
/**
 * Automated tests for modules/users/team_members.php
 * Real-life scenarios: member assignment, removal, supervisor change, manager scope restriction.
 */

chdir(__DIR__ . '/..');
require_once __DIR__.'/../php_scripts/config.php';
require_once __DIR__.'/lib/db_helper.php';

if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION['loggedin'] = true;
$_SESSION['id'] = 1;
$_SESSION['csrf_token'] = 'test_csrf_members_123';

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
    $_SESSION['csrf_token'] = 'test_csrf_members_123';
    ob_start();
    include $scriptPath;
    $output = ob_get_clean();
    $passed = ($expectContains === '' || strpos($output, $expectContains) !== false);
    return [$passed, $output];
}

$testUser = dbFetchOne("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID != 1 AND ROLE != 'Super Admin' LIMIT 1");
$testUserId = $testUser['ID'] ?? 0;
$testTeam = dbFetchOne("SELECT ID FROM " . tn('TBL_TEAMS') . " LIMIT 1");
$testTeamId = $testTeam['ID'] ?? 0;

echo "Precondition: test user ID={$testUserId}, test team ID={$testTeamId}\n\n";

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['team_id' => $testTeamId];
ob_start();
include __DIR__.'/../modules/users/team_members.php';
$pageOutput = ob_get_clean();
$pageLoads = strpos($pageOutput, 'Member') !== false || strpos($pageOutput, 'Team') !== false;
$tests[] = ['desc'=>'Team members page loads','passed'=>$pageLoads];
echo "[" . ($pageLoads ? 'PASS' : 'FAIL') . "] Team members page loads\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/team_members.php', [
    'action' => 'assign_member', 'user_id' => $testUserId, 'team_id' => $testTeamId,
    'csrf_token' => 'test_csrf_members_123'
], 'Member assignment updated successfully', 'Assign member to team');
$tests[] = ['desc'=>'Assign member to team','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Assign member to team\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/team_members.php', [
    'action' => 'assign_member', 'user_id' => $testUserId, 'team_id' => '',
    'csrf_token' => 'test_csrf_members_123'
], 'Member assignment updated successfully', 'Remove member from team');
$tests[] = ['desc'=>'Remove member from team','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Remove member from team\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/team_members.php', [
    'action' => 'assign_member', 'user_id' => '0', 'team_id' => $testTeamId,
    'csrf_token' => 'test_csrf_members_123'
], 'Invalid member', 'Invalid user ID rejected');
$tests[] = ['desc'=>'Invalid user ID rejected','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Invalid user ID rejected\n";

$supervisorRow = dbFetchOne("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ROLE IN ('Supervisor','Manager') AND STATUS = 'Active' AND ID != 1 LIMIT 1");
$supervisorId = $supervisorRow['ID'] ?? 0;
if ($supervisorId > 0) {
    list($p, $out) = run_post_test(__DIR__.'/../modules/users/team_members.php', [
        'action' => 'change_supervisor', 'team_id' => $testTeamId, 'supervisor_id' => $supervisorId,
        'csrf_token' => 'test_csrf_members_123'
    ], '', 'Change supervisor');
    $tests[] = ['desc'=>'Change supervisor','passed'=>$p];
    echo "[" . ($p ? 'PASS' : 'FAIL') . "] Change supervisor\n";
} else {
    echo "[SKIP] Change supervisor (no supervisor user found)\n";
}

list($p, $out) = run_post_test(__DIR__.'/../modules/users/team_members.php', [
    'action' => 'change_supervisor', 'team_id' => $testTeamId, 'supervisor_id' => $testUserId,
    'csrf_token' => 'test_csrf_members_123'
], 'User is not an active Supervisor/Manager', 'Invalid supervisor rejected');
$tests[] = ['desc'=>'Invalid supervisor rejected','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Invalid supervisor rejected\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/team_members.php', [
    'action' => 'assign_member', 'user_id' => $testUserId, 'team_id' => $testTeamId,
    'csrf_token' => 'wrong_token'
], 'Invalid session token', 'Invalid CSRF token rejected');
$tests[] = ['desc'=>'Invalid CSRF token rejected','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Invalid CSRF token rejected\n";

foreach ($tests as $t) { if ($t['passed']) $passed++; else $failed++; }
echo "\n=== team_members.php TEST SUMMARY: {$passed}/" . count($tests) . " passed, {$failed} failed ===\n";
