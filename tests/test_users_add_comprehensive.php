<?php
/**
 * Automated tests for modules/users/users_add.php
 * Real-life scenarios: add user, edit user, validation, duplicates, system account protection.
 */

chdir(__DIR__ . '/..');
require_once __DIR__.'/../php_scripts/config.php';
require_once __DIR__.'/lib/db_helper.php';

// Setup authenticated session (Admin user ID=1)
if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION['loggedin'] = true;
$_SESSION['id'] = 1;
$_SESSION['csrf_token'] = 'test_csrf_token_123';

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
    $_SESSION['csrf_token'] = 'test_csrf_token_123';
    ob_start();
    include $scriptPath;
    $output = ob_get_clean();
    $passed = ($expectContains === '' || strpos($output, $expectContains) !== false);
    return [$passed, $output];
}

$teamRow = dbFetchOne("SELECT ID FROM " . tn('TBL_TEAMS') . " LIMIT 1");
$validTeamId = $teamRow['ID'] ?? 1;
$roleRow = dbFetchOne("SELECT role_name FROM " . tn('TBL_ROLES') . " WHERE role_name != 'Super Admin' LIMIT 1");
$validRole = $roleRow['role_name'] ?? 'Officer';

list($p, $out) = run_post_test(__DIR__.'/../modules/users/users_add.php', [
    'name' => 'Test User One', 'mobile' => '9999900001', 'username' => 'testuser1@example.com',
    'password' => 'SecurePass123', 'role' => $validRole, 'team_id' => $validTeamId,
    'package' => 'Standard', 'status' => 'Active', 'csrf_token' => 'test_csrf_token_123', 'submit' => '1'
], 'New user created successfully', 'Valid user creation');
$tests[] = ['desc'=>'Valid user creation','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Valid user creation\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/users_add.php', [
    'name' => 'John123', 'mobile' => '9999900002', 'username' => 'testuser2@example.com',
    'password' => 'SecurePass123', 'role' => $validRole, 'team_id' => $validTeamId,
    'package' => 'Standard', 'status' => 'Active', 'csrf_token' => 'test_csrf_token_123', 'submit' => '1'
], 'Invalid name', 'Invalid name validation');
$tests[] = ['desc'=>'Invalid name validation','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Invalid name validation\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/users_add.php', [
    'name' => 'John Doe', 'mobile' => '12345', 'username' => 'testuser3@example.com',
    'password' => 'SecurePass123', 'role' => $validRole, 'team_id' => $validTeamId,
    'package' => 'Standard', 'status' => 'Active', 'csrf_token' => 'test_csrf_token_123', 'submit' => '1'
], 'Invalid 10-digit mobile number', 'Invalid mobile validation');
$tests[] = ['desc'=>'Invalid mobile validation','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Invalid mobile validation\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/users_add.php', [
    'name' => 'John Doe', 'mobile' => '9999900003', 'username' => 'testuser4@example.com',
    'password' => '123', 'role' => $validRole, 'team_id' => $validTeamId,
    'package' => 'Standard', 'status' => 'Active', 'csrf_token' => 'test_csrf_token_123', 'submit' => '1'
], 'Password must be at least 6 characters', 'Short password validation');
$tests[] = ['desc'=>'Short password validation','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Short password validation\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/users_add.php', [
    'name' => 'John Doe', 'mobile' => '9999900004', 'username' => 'not-an-email',
    'password' => 'SecurePass123', 'role' => $validRole, 'team_id' => $validTeamId,
    'package' => 'Standard', 'status' => 'Active', 'csrf_token' => 'test_csrf_token_123', 'submit' => '1'
], 'Invalid login email', 'Invalid login email format');
$tests[] = ['desc'=>'Invalid login email format','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Invalid login email format\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/users_add.php', [
    'name' => 'John Doe', 'mobile' => '9999900005', 'username' => 'testuser6@example.com',
    'password' => 'SecurePass123', 'role' => $validRole, 'team_id' => $validTeamId,
    'package' => 'Standard', 'status' => 'Active', 'email' => 'not-an-email',
    'csrf_token' => 'test_csrf_token_123', 'submit' => '1'
], 'Invalid alternate email', 'Invalid alternate email');
$tests[] = ['desc'=>'Invalid alternate email','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Invalid alternate email\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/users_add.php', [
    'name' => '', 'mobile' => '', 'username' => '', 'password' => 'SecurePass123',
    'role' => '', 'team_id' => '', 'package' => '', 'status' => 'Active',
    'csrf_token' => 'test_csrf_token_123', 'submit' => '1'
], 'All required fields must be filled', 'Missing required fields');
$tests[] = ['desc'=>'Missing required fields','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Missing required fields\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/users_add.php', [
    'name' => 'Dup Mobile User', 'mobile' => '9999900001', 'username' => 'dupuser@example.com',
    'password' => 'SecurePass123', 'role' => $validRole, 'team_id' => $validTeamId,
    'package' => 'Standard', 'status' => 'Active', 'csrf_token' => 'test_csrf_token_123', 'submit' => '1'
], 'already registered to another user', 'Duplicate mobile detection');
$tests[] = ['desc'=>'Duplicate mobile detection','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Duplicate mobile detection\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/users_add.php', [
    'name' => 'Dup Login User', 'mobile' => '9999900009', 'username' => 'testuser1@example.com',
    'password' => 'SecurePass123', 'role' => $validRole, 'team_id' => $validTeamId,
    'package' => 'Standard', 'status' => 'Active', 'csrf_token' => 'test_csrf_token_123', 'submit' => '1'
], 'already registered in the system', 'Duplicate login ID detection');
$tests[] = ['desc'=>'Duplicate login ID detection','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Duplicate login ID detection\n";

$_GET = ['ID' => 2];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
include __DIR__.'/../modules/users/users_add.php';
$editOutput = ob_get_clean();
$hasEditForm = strpos($editOutput, 'Edit User Account') !== false;
$tests[] = ['desc'=>'Edit user form loads','passed'=>$hasEditForm];
echo "[" . ($hasEditForm ? 'PASS' : 'FAIL') . "] Edit user form loads\n";

$_GET = ['ID' => 1];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
include __DIR__.'/../modules/users/users_add.php';
$id1Output = ob_get_clean();
$blockedId1 = strpos($id1Output, 'System Administrator cannot be edited') !== false;
$tests[] = ['desc'=>'System Admin ID=1 edit blocked','passed'=>$blockedId1];
echo "[" . ($blockedId1 ? 'PASS' : 'FAIL') . "] System Admin ID=1 edit blocked\n";

list($p, $out) = run_post_test(__DIR__.'/../modules/users/users_add.php', [
    'name' => 'CSRF Test', 'mobile' => '9999900010', 'username' => 'csrf@example.com',
    'password' => 'SecurePass123', 'role' => $validRole, 'team_id' => $validTeamId,
    'package' => 'Standard', 'status' => 'Active', 'csrf_token' => 'wrong_token', 'submit' => '1'
], 'Invalid session token', 'Invalid CSRF token');
$tests[] = ['desc'=>'Invalid CSRF token rejected','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Invalid CSRF token rejected\n";

foreach ($tests as $t) { if ($t['passed']) $passed++; else $failed++; }
echo "\n=== users_add.php TEST SUMMARY: {$passed}/" . count($tests) . " passed, {$failed} failed ===\n";
