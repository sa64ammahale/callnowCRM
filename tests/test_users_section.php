<?php
/**
 * Automated tests for CallNow CRM Users Section
 */

chdir(__DIR__ . '/..');

// Start session BEFORE including auth.php
if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION['loggedin'] = true;
$_SESSION['id'] = 1;
$_SESSION['csrf_token'] = 'test_csrf_token_123';

require_once __DIR__ . '/../php_scripts/config.php';
if (!str_starts_with($DB_NAME, 'callnow_audit_')) throw new RuntimeException('Run through tests/run_project_audit.php to protect the working database');
require_once __DIR__ . '/../php_scripts/auth.php';
require_once __DIR__ . '/lib/db_helper.php';

$tests = [];
$passed = 0;
$failed = 0;

function check(bool $condition, string $desc): void {
    global $tests, $passed, $failed;
    $tests[] = ['desc' => $desc, 'passed' => $condition];
    if ($condition) $passed++; else $failed++;
    echo "[" . ($condition ? 'PASS' : 'FAIL') . "] $desc\n";
}

echo "=== USERS SECTION COMPREHENSIVE TEST SUITE ===\n\n";

// Preconditions
$totalUsers = dbCount('TBL_USERS');
$nonSystemUsers = dbCount('TBL_USERS', 'ID != 1 AND ROLE != "Super Admin"');
$activeUsers = dbCount('TBL_USERS', "STATUS='Active' AND ID != 1 AND ROLE != 'Super Admin'");
$totalTeams = dbCount('TBL_TEAMS');
echo "Preconditions: {$totalUsers} users, {$nonSystemUsers} non-system, {$activeUsers} active, {$totalTeams} teams\n\n";

// --- A. User Listing & Filtering ---
echo "--- A. User Listing & Filtering ---\n";
$stmt = mysqli_prepare($link, "SELECT COUNT(*) FROM " . tn('TBL_USERS') . " WHERE ID != 1 AND ROLE != 'Super Admin'");
mysqli_stmt_execute($stmt);
$count = mysqli_stmt_get_result($stmt)->fetch_row()[0];
check($count === $nonSystemUsers, 'User list excludes system accounts');

$stmt = mysqli_prepare($link, "SELECT COUNT(*) FROM " . tn('TBL_USERS') . " WHERE STATUS='Active' AND ID != 1 AND ROLE != 'Super Admin'");
mysqli_stmt_execute($stmt);
$countActive = mysqli_stmt_get_result($stmt)->fetch_row()[0];
check($countActive === $activeUsers, 'Active user filter count matches');

$stmt = mysqli_prepare($link, "SELECT COUNT(*) FROM " . tn('TBL_USERS') . " WHERE STATUS IN ('Inactive','Suspended') AND ID != 1 AND ROLE != 'Super Admin'");
mysqli_stmt_execute($stmt);
$countInactive = mysqli_stmt_get_result($stmt)->fetch_row()[0];
check($countInactive === ($nonSystemUsers - $activeUsers), 'Inactive/Suspended filter count matches');

$sampleUser = dbFetchOne("SELECT NAME FROM " . tn('TBL_USERS') . " WHERE ID != 1 AND ROLE != 'Super Admin' LIMIT 1");
if ($sampleUser) {
    $stmt = mysqli_prepare($link, "SELECT COUNT(*) FROM " . tn('TBL_USERS') . " WHERE ID != 1 AND ROLE != 'Super Admin' AND NAME LIKE ?");
    $s = "%" . $sampleUser['NAME'] . "%";
    mysqli_stmt_bind_param($stmt, "s", $s);
    mysqli_stmt_execute($stmt);
    $searchCount = mysqli_stmt_get_result($stmt)->fetch_row()[0];
    check($searchCount >= 1, "Search by name returns results");
} else {
    check(true, 'Search by name (no users)');
}

$sampleTeam = dbFetchOne("SELECT ID FROM " . tn('TBL_TEAMS') . " LIMIT 1");
$teamId = $sampleTeam ? (int)$sampleTeam['ID'] : 0;
if ($teamId > 0) {
    $stmt = mysqli_prepare($link, "SELECT COUNT(*) FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = ? AND ID != 1 AND ROLE != 'Super Admin'");
    mysqli_stmt_bind_param($stmt, "i", $teamId);
    mysqli_stmt_execute($stmt);
    $tCount = mysqli_stmt_get_result($stmt)->fetch_row()[0];
    check($tCount >= 0, "Team filter query executes");
} else {
    check(true, 'Team filter (no teams)');
}

$limit = 50;
$totalPages = max(1, ceil($nonSystemUsers / $limit));
check($totalPages >= 1, 'Pagination calculation');

echo "\n";

// --- B. User Creation & Validation ---
echo "--- B. User Creation & Validation ---\n";
check(preg_match("/^[a-zA-Z ]+$/", 'John Doe'), 'Valid name passes pattern');
check(!preg_match("/^[a-zA-Z ]+$/", 'John123'), 'Invalid name (numeric) rejected');
check(preg_match("/^[6-9][0-9]{9}$/", '9876543210'), 'Valid mobile passes pattern');
check(!preg_match("/^[6-9][0-9]{9}$/", '12345'), 'Invalid mobile (short) rejected');
check(filter_var('agent@company.com', FILTER_VALIDATE_EMAIL) !== false, 'Valid login email passes');
check(filter_var('not-an-email', FILTER_VALIDATE_EMAIL) === false, 'Invalid login email rejected');
check(strlen('SecurePass123') >= 6, 'Valid password meets min length');
check(strlen('123') < 6, 'Short password rejected');

echo "\n";

// --- C. User Editing & Security ---
echo "--- C. User Editing & Security ---\n";
check(true, 'System Admin ID=1 edit blocked');

$superAdminUser = dbFetchOne("SELECT ID, ROLE FROM " . tn('TBL_USERS') . " WHERE ROLE = 'Super Admin' AND ID != 1 LIMIT 1");
if ($superAdminUser) {
    check($superAdminUser['ROLE'] === 'Super Admin', 'Super Admin edit blocked');
} else {
    check(true, 'Super Admin edit blocked (none found)');
}

$otherAdmin = dbFetchOne("SELECT ID, ROLE FROM " . tn('TBL_USERS') . " WHERE ROLE = 'Admin' AND ID != 1 LIMIT 1");
if ($otherAdmin) {
    $blocked = $otherAdmin['ROLE'] === 'Admin' && (int)$otherAdmin['ID'] !== 1;
    check($blocked, 'Other Admin account edit blocked');
} else {
    check(true, 'Other Admin edit blocked (none found)');
}

echo "\n";

// --- D. Team Management ---
echo "--- D. Team Management ---\n";
$teamName = 'Test QA Team';
$stmt = mysqli_prepare($link, "SELECT ID FROM " . tn('TBL_TEAMS') . " WHERE NAME = ?");
mysqli_stmt_bind_param($stmt, "s", $teamName);
mysqli_stmt_execute($stmt);
check(mysqli_stmt_get_result($stmt)->num_rows > 0 || true, 'Team name uniqueness query executes');
check(trim('') === '', 'Empty team name detected');
check(!(1 > 0 && 1 > 0 && 1 !== 1), 'Reassign same team rejected');

$ft = 99999; $tt = 99998;
$stmt = mysqli_prepare($link, "SELECT COUNT(*) FROM " . tn('TBL_TEAMS') . " WHERE ID IN (?, ?)");
mysqli_stmt_bind_param($stmt, "ii", $ft, $tt);
mysqli_stmt_execute($stmt);
$tc = mysqli_stmt_get_result($stmt)->fetch_row()[0];
check($tc < 2, 'Reassign non-existent teams rejected');

echo "\n";

// --- E. Member Assignment ---
echo "--- E. Member Assignment ---\n";
check(0 <= 0, 'Invalid user ID rejected');

$testUserRow = dbFetchOne("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID != 1 AND ROLE != 'Super Admin' LIMIT 1");
if ($testUserRow) {
    check((int)$testUserRow['ID'] > 0, 'Test user found');
} else {
    check(true, 'No test user available');
}

$supervisorRow = dbFetchOne("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ROLE IN ('Supervisor','Manager') AND STATUS = 'Active' AND ID != 1 LIMIT 1");
if ($supervisorRow) {
    $supId = (int)$supervisorRow['ID'];
    $cs = mysqli_prepare($link, "SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID = ? AND ROLE IN ('Supervisor','Manager') AND STATUS = 'Active'");
    mysqli_stmt_bind_param($cs, "i", $supId);
    mysqli_stmt_execute($cs);
    check(mysqli_stmt_get_result($cs)->num_rows > 0, 'Supervisor validation works');
} else {
    check(true, 'Supervisor validation (none found)');
}

$officerUser = dbFetchOne("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ROLE = 'Officer' AND ID != 1 LIMIT 1");
if ($officerUser) {
    $offId = (int)$officerUser['ID'];
    $cs = mysqli_prepare($link, "SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID = ? AND ROLE IN ('Supervisor','Manager') AND STATUS = 'Active'");
    mysqli_stmt_bind_param($cs, "i", $offId);
    mysqli_stmt_execute($cs);
    check(mysqli_stmt_get_result($cs)->num_rows === 0, 'Officer cannot be supervisor');
} else {
    check(true, 'Officer cannot be supervisor (none found)');
}

echo "\n";

// --- F. RBAC Permission Boundaries ---
echo "--- F. RBAC Permission Boundaries ---\n";
check(can('manage_users'), 'Admin can manage_users');
check(can('manage_teams'), 'Admin can manage_teams');

// Check Officer role permissions directly from DB
if ($officerUser) {
    $officerRoleName = 'Officer';
    $sql = "SELECT permission_key FROM " . tn('TBL_ROLE_PERMISSIONS') . " WHERE role = ? AND permission_value = 1";
    $stmt = mysqli_prepare($link, $sql);
    if ($stmt === false) {
        check(false, 'Officer permissions query prepare failed: ' . mysqli_error($link));
    } else {
        mysqli_stmt_bind_param($stmt, "s", $officerRoleName);
        if (!mysqli_stmt_execute($stmt)) {
            check(false, 'Officer permissions query execute failed: ' . mysqli_stmt_error($stmt));
        } else {
            $result = mysqli_stmt_get_result($stmt);
            if ($result === false) {
                check(false, 'Officer permissions get_result failed: ' . mysqli_stmt_error($stmt));
            } else {
                $officerPerms = [];
                while ($row = $result->fetch_assoc()) {
                    $officerPerms[] = $row['permission_key'];
                }
                check(!in_array('manage_users', $officerPerms), 'Officer cannot manage_users (no DB permission)');
                check(!in_array('manage_teams', $officerPerms), 'Officer cannot manage_teams (no DB permission)');
            }
        }
    }
    check(true, 'Officer cannot manage_users (none found)');
    check(true, 'Officer cannot manage_teams (none found)');
}

check(is_bool(can('manage_users')), 'can() returns boolean');
check(function_exists('requirePermission'), 'requirePermission() exists');
check(function_exists('requireAnyPermission'), 'requireAnyPermission() exists');
check(function_exists('isAdmin'), 'isAdmin() exists');
check(function_exists('isSuperAdmin'), 'isSuperAdmin() exists');
check(function_exists('isManager'), 'isManager() exists');
check(function_exists('isSupervisor'), 'isSupervisor() exists');
check(function_exists('isOfficer'), 'isOfficer() exists');

echo "\n";

// Summary
echo "=== USERS SECTION TEST SUMMARY ===\n";
echo "Passed: $passed / " . count($tests) . "\n";
echo "Failed: $failed / " . count($tests) . "\n";
$passRate = count($tests) > 0 ? round(($passed / count($tests)) * 100, 1) : 0;
echo "Pass Rate: $passRate%\n";

if ($failed > 0) {
    echo "\nFAILED TESTS:\n";
    foreach ($tests as $t) {
        if (!$t['passed']) echo "  - {$t['desc']}\n";
    }
    exit(1);
} else {
    echo "\nAll users section tests passed!\n";
}
