<?php
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/hierarchy_engine.php';
require_once __DIR__ . '/../php_scripts/team_auth.php';
require_once __DIR__ . '/../php_scripts/permissions.php';
require_once __DIR__ . '/../php_scripts/reporting_engine.php';

$failures = [];
function testAssert($condition, $description) {
    global $failures;
    if ($condition) {
        echo "[PASS] $description\n";
    } else {
        echo "[FAIL] $description\n";
        $failures[] = $description;
    }
}

echo "=== 1. Role Normalization Tests ===\n";
testAssert(getNormalizedRole('Supervisor') === 'Supervisor', 'getNormalizedRole("Supervisor") is Supervisor');
testAssert(getNormalizedRole('supervisor') === 'Supervisor', 'getNormalizedRole("supervisor") is Supervisor');
testAssert(getNormalizedRole('SUPERVISOR') === 'Supervisor', 'getNormalizedRole("SUPERVISOR") is Supervisor');
testAssert(getNormalizedRole('Super Admin') === 'Super Admin', 'getNormalizedRole("Super Admin") is Super Admin');
testAssert(getNormalizedRole('Admin') === 'Admin', 'getNormalizedRole("Admin") is Admin');
testAssert(getNormalizedRole('Manager') === 'Manager', 'getNormalizedRole("Manager") is Manager');
testAssert(getNormalizedRole('Officer') === 'Officer', 'getNormalizedRole("Officer") is Officer');

echo "\n=== 2. isUserSuperAdmin Tests ===\n";
testAssert(!isUserSuperAdmin(9013, 'Supervisor'), 'Supervisor 9013 is NOT Super Admin');
testAssert(!isUserSuperAdmin(9012, 'Manager'), 'Manager 9012 is NOT Super Admin');
testAssert(!isUserSuperAdmin(9011, 'Admin'), 'Admin 9011 is NOT Super Admin');
testAssert(!isUserSuperAdmin(3, 'Supervisor'), 'Supervisor 3 is NOT Super Admin');
testAssert(isUserSuperAdmin(1, 'Super Admin'), 'User 1 is Super Admin');

echo "\n=== 3. getAuthorizedUserIds Scoping Tests ===\n";
$sup9013Users = getAuthorizedUserIds($link, 9013, 'Supervisor', 9001);
testAssert(in_array(9013, $sup9013Users, true) && in_array(9015, $sup9013Users, true), 'Supervisor 9013 sees self (9013) and officer (9015)');
testAssert(!in_array(9014, $sup9013Users, true) && !in_array(9016, $sup9013Users, true), 'Supervisor 9013 does NOT see supervisor 9014 or officer 9016');
testAssert(!in_array(9012, $sup9013Users, true) && !in_array(9011, $sup9013Users, true) && !in_array(1, $sup9013Users, true), 'Supervisor 9013 does NOT see manager 9012, admin 9011, or superadmin 1');

$sup9014Users = getAuthorizedUserIds($link, 9014, 'Supervisor', 9002);
testAssert(in_array(9014, $sup9014Users, true) && in_array(9016, $sup9014Users, true), 'Supervisor 9014 sees self (9014) and officer (9016)');
testAssert(!in_array(9013, $sup9014Users, true) && !in_array(9015, $sup9014Users, true), 'Supervisor 9014 does NOT see supervisor 9013 or officer 9015');

$mgr9012Users = getAuthorizedUserIds($link, 9012, 'Manager', 9001);
testAssert(in_array(9013, $mgr9012Users, true) && in_array(9015, $mgr9012Users, true), 'Manager 9012 sees supervisor 9013 and officer 9015');
testAssert(!in_array(9014, $mgr9012Users, true) && !in_array(9016, $mgr9012Users, true), 'Manager 9012 does NOT see supervisor 9014 or officer 9016 (under Mgr 9022)');
testAssert(!in_array(9022, $mgr9012Users, true), 'Manager 9012 does NOT see other manager 9022');

$admin9011Users = getAuthorizedUserIds($link, 9011, 'Admin');
testAssert(in_array(9012, $admin9011Users, true) && in_array(9013, $admin9011Users, true) && in_array(9015, $admin9011Users, true), 'Admin 9011 sees staff under branch');
testAssert(!in_array(7, $admin9011Users, true) && !in_array(2, $admin9011Users, true) && !in_array(3, $admin9011Users, true), 'Admin 9011 does NOT see other Admin 7 or Admin 7 staff');
testAssert(!in_array(1, $admin9011Users, true), 'Admin 9011 does NOT see Super Admin 1');

echo "\n=== 4. getAuthorizedTeamIds Scoping Tests ===\n";
$sup9013Teams = getAuthorizedTeamIds($link, 9013, 'Supervisor', 9001);
testAssert($sup9013Teams === [9001], 'Supervisor 9013 has strictly team 9001');

$mgr9012Teams = getAuthorizedTeamIds($link, 9012, 'Manager', 9001);
testAssert(in_array(9001, $mgr9012Teams, true) && !in_array(9002, $mgr9012Teams, true), 'Manager 9012 has team 9001 and NOT team 9002');

echo "\n=== 5. canActorAssignRole Tests ===\n";
testAssert(canActorAssignRole('Supervisor', 'Officer'), 'Supervisor CAN assign Officer');
testAssert(!canActorAssignRole('Supervisor', 'Supervisor'), 'Supervisor CANNOT assign Supervisor');
testAssert(!canActorAssignRole('Supervisor', 'Manager'), 'Supervisor CANNOT assign Manager');
testAssert(!canActorAssignRole('Supervisor', 'Admin'), 'Supervisor CANNOT assign Admin');
testAssert(canActorAssignRole('Manager', 'Supervisor') && canActorAssignRole('Manager', 'Officer'), 'Manager CAN assign Supervisor and Officer');
testAssert(!canActorAssignRole('Manager', 'Manager'), 'Manager CANNOT assign Manager');
testAssert(!canActorAssignRole('Manager', 'Admin'), 'Manager CANNOT assign Admin');

echo "\n=== 6. SQL Scope Filters Tests ===\n";
$userFilterSql = getHierarchyUserFilterSQL($link, 9013, 'Supervisor', 'u', 'ID', 9001);
testAssert(strpos($userFilterSql, '9013') !== false && strpos($userFilterSql, '9015') !== false, 'Supervisor SQL user filter contains 9013 and 9015');
testAssert($userFilterSql !== '1=1', 'Supervisor SQL user filter is NOT 1=1');

$leadFilterSql = getHierarchyLeadFilterSQL($link, 9013, 'Supervisor', 'l', 9001);
testAssert(strpos($leadFilterSql, '9013') !== false && strpos($leadFilterSql, '9015') !== false, 'Supervisor SQL lead filter contains 9013 and 9015');
testAssert($leadFilterSql !== '1=1', 'Supervisor SQL lead filter is NOT 1=1');

echo "\n=== 7. Reporting Engine Scope Tests ===\n";
$supReportScope = getEffectiveReportingScope($link, null, null, null, 'Supervisor', 9013);
testAssert($supReportScope['mode'] === 'supervisor', 'Supervisor report scope mode is supervisor');
testAssert(in_array(9013, $supReportScope['user_ids'], true) && in_array(9015, $supReportScope['user_ids'], true), 'Supervisor report scope user_ids has 9013 and 9015');
testAssert(!in_array(9014, $supReportScope['user_ids'], true) && !in_array(9016, $supReportScope['user_ids'], true), 'Supervisor report scope user_ids does NOT have 9014 or 9016');

$targets = ['daily_dials' => 50, 'talk_time_minutes' => 60, 'connected_calls' => 20, 'leads_converted' => 2, 'disbursed_amount' => 100000];
$today = date('Y-m-d');
$adminRollupForSup = getAdminPerformanceRows($link, $today, $today, null, $targets, 'Supervisor');
testAssert(empty($adminRollupForSup), 'getAdminPerformanceRows returns [] for Supervisor');

$adminRollupForMgr = getAdminPerformanceRows($link, $today, $today, null, $targets, 'Manager');
testAssert(empty($adminRollupForMgr), 'getAdminPerformanceRows returns [] for Manager');

echo "\n=====================================\n";
if (empty($failures)) {
    echo "ALL TESTS PASSED SUCCESSFULLY! (0 failures)\n";
} else {
    echo "TEST FAILURES (" . count($failures) . "):\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
}
