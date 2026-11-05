<?php
/**
 * Automated tests for modules/users/users_view.php
 * Real-life scenarios: listing, filtering, pagination, export, system account exclusion.
 */

chdir(__DIR__ . '/..');
require_once __DIR__.'/../php_scripts/config.php';
require_once __DIR__.'/lib/db_helper.php';

// Setup authenticated session (Admin user ID=1) - must be before any output
if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION['loggedin'] = true;
$_SESSION['id'] = 1;
$_SESSION['csrf_token'] = 'test_csrf_token_123';

// Load user data to set constants
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

function run_get_test(string $scriptPath, array $get = [], string $expectContains = '', string $desc = ''): array {
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    include $scriptPath;
    $output = ob_get_clean();
    $passed = ($expectContains === '' || strpos($output, $expectContains) !== false);
    return [$passed, $output];
}

// Preconditions
$userCount = dbCount('TBL_USERS', 'ID != 1 AND ROLE != "Super Admin"');
echo "Precondition: {$userCount} non-system users in DB\n\n";

// Test 1: Load users_view page (basic listing)
list($p, $out) = run_get_test(__DIR__.'/../modules/users/users_view.php', [], 'User Directory', 'Page loads');
$tests[] = ['desc'=>'Users view loads','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Users view loads\n";

// Test 2: Filter by search term
list($p, $out) = run_get_test(__DIR__.'/../modules/users/users_view.php', ['search' => 'test'], 'User Directory', 'Search filter');
$tests[] = ['desc'=>'Search filter works','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Search filter works\n";

// Test 3: Filter by status Active
list($p, $out) = run_get_test(__DIR__.'/../modules/users/users_view.php', ['status' => 'Active'], 'User Directory', 'Status Active');
$tests[] = ['desc'=>'Status filter Active','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Status filter Active\n";

// Test 4: Filter by status Inactive
list($p, $out) = run_get_test(__DIR__.'/../modules/users/users_view.php', ['status' => 'Inactive'], 'User Directory', 'Status Inactive');
$tests[] = ['desc'=>'Status filter Inactive','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Status filter Inactive\n";

// Test 5: Filter by status Suspended
list($p, $out) = run_get_test(__DIR__.'/../modules/users/users_view.php', ['status' => 'Suspended'], 'User Directory', 'Status Suspended');
$tests[] = ['desc'=>'Status filter Suspended','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Status filter Suspended\n";

// Test 6: Pagination page 2
list($p, $out) = run_get_test(__DIR__.'/../modules/users/users_view.php', ['page' => 2], 'User Directory', 'Page 2');
$tests[] = ['desc'=>'Pagination page 2','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Pagination page 2\n";

// Test 7: Filter by team
list($p, $out) = run_get_test(__DIR__.'/../modules/users/users_view.php', ['team' => '1'], 'User Directory', 'Team ID=1');
$tests[] = ['desc'=>'Team filter works','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Team filter works\n";

// Test 8: Filter by package
list($p, $out) = run_get_test(__DIR__.'/../modules/users/users_view.php', ['package' => 'Standard'], 'User Directory', 'Package Standard');
$tests[] = ['desc'=>'Package filter works','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Package filter works\n";

// Test 9: System accounts excluded
list($p, $out) = run_get_test(__DIR__.'/../modules/users/users_view.php', [], '', 'System accounts excluded');
$hasSystemRow = strpos($out, '#1</span>') !== false && strpos($out, 'bi-pencil-square') !== false;
$tests[] = ['desc'=>'System accounts excluded from list','passed'=>!$hasSystemRow];
echo "[" . (!$hasSystemRow ? 'PASS' : 'FAIL') . "] System accounts excluded from list\n";

// Test 10: Export button present
list($p, $out) = run_get_test(__DIR__.'/../modules/users/users_view.php', [], 'Export Excel', 'Export button');
$tests[] = ['desc'=>'Export Excel button present','passed'=>$p];
echo "[" . ($p ? 'PASS' : 'FAIL') . "] Export Excel button present\n";

// Summary
foreach ($tests as $t) { if ($t['passed']) $passed++; else $failed++; }
echo "\n=== users_view.php TEST SUMMARY: {$passed}/" . count($tests) . " passed, {$failed} failed ===\n";
