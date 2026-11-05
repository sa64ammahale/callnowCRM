<?php
/**
 * Test Organizational Hierarchy & RBAC Scoping Boundaries
 */
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/hierarchy_engine.php';
require_once __DIR__ . '/../php_scripts/reporting_engine.php';

echo "=== STARTING ORGANIZATIONAL HIERARCHY TESTS ===\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition(bool $cond, string $desc) {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] $desc\n";
        $passCount++;
    } else {
        echo " [FAIL] $desc\n";
        $failCount++;
    }
}

// 1. Super Admin isolation test
$superAdminIds = getSuperAdminUserIds($link);
assertCondition(!empty($superAdminIds), "Super Admin accounts identified: " . implode(',', $superAdminIds));

// 2. Role normalizer tests
assertCondition(getNormalizedRole('Super Admin') === 'Super Admin', "Normalize 'Super Admin' -> 'Super Admin'");
assertCondition(getNormalizedRole('admin') === 'Admin', "Normalize 'admin' -> 'Admin'");
assertCondition(getNormalizedRole('Supervisor') === 'Supervisor', "Normalize 'Supervisor' -> 'Supervisor'");
assertCondition(getNormalizedRole('manager') === 'Admin', "Normalize 'manager' -> 'Admin'");
assertCondition(getNormalizedRole('Telecaller') === 'Officer', "Normalize 'Telecaller' -> 'Officer'");
assertCondition(getNormalizedRole('officer') === 'Officer', "Normalize 'officer' -> 'Officer'");

// 3. Hierarchy level comparisons
assertCondition(getRoleHierarchyLevel('Super Admin') === 4, "Hierarchy level: Super Admin = 4");
assertCondition(getRoleHierarchyLevel('Admin') === 3, "Hierarchy level: Admin = 3");
assertCondition(getRoleHierarchyLevel('Supervisor') === 2, "Hierarchy level: Supervisor = 2");
assertCondition(getRoleHierarchyLevel('Officer') === 1, "Hierarchy level: Officer = 1");

// 4. Role Assignment Permissions
assertCondition(canActorAssignRole('Super Admin', 'Admin') === true, "Super Admin can assign Admin");
assertCondition(canActorAssignRole('Super Admin', 'Supervisor') === true, "Super Admin can assign Supervisor");
assertCondition(canActorAssignRole('Admin', 'Super Admin') === false, "Admin CANNOT assign Super Admin");
assertCondition(canActorAssignRole('Admin', 'Admin') === false, "Admin CANNOT assign Admin");
assertCondition(canActorAssignRole('Admin', 'Supervisor') === true, "Admin CAN assign Supervisor");
assertCondition(canActorAssignRole('Admin', 'Officer') === true, "Admin CAN assign Officer");
assertCondition(canActorAssignRole('Supervisor', 'Admin') === false, "Supervisor CANNOT assign Admin");
assertCondition(canActorAssignRole('Supervisor', 'Supervisor') === false, "Supervisor CANNOT assign Supervisor");
assertCondition(canActorAssignRole('Supervisor', 'Officer') === false, "Supervisor CANNOT assign Officer (user management is Admin/SuperAdmin only)");
assertCondition(canActorAssignRole('Officer', 'Officer') === false, "Officer CANNOT assign any role");

// 5. Hierarchy Scope SQL Generation
$saFilter = getHierarchyUserFilterSQL($link, 1, 'Super Admin', 'u', 'ID');
assertCondition($saFilter === "1=1", "Super Admin scope SQL is global (1=1)");

$officerFilter = getHierarchyUserFilterSQL($link, 999, 'Officer', 'u', 'ID');
assertCondition(strpos($officerFilter, "u.ID = 999") !== false, "Officer scope SQL strictly restricts to self (u.ID = 999)");

$officerContactFilter = getHierarchyContactFilterSQL($link, 999, 'Officer', 'm');
assertCondition(strpos($officerContactFilter, "m.MAINDATABASE_CALL_DIALED_USER = 999") !== false, "Officer contact filter strictly restricts to self");

$officerLeadFilter = getHierarchyLeadFilterSQL($link, 999, 'Officer', 'l');
assertCondition(strpos($officerLeadFilter, "l.assigned_to = 999") !== false, "Officer lead filter strictly restricts to self");

// 6. Test Super Admin Exclusions in Subordinate queries
$fakeAdminScope = getHierarchyUserFilterSQL($link, 2, 'Admin', 'u', 'ID');
foreach ($superAdminIds as $said) {
    assertCondition(strpos($fakeAdminScope, (string)$said) !== false || strpos($fakeAdminScope, "NOT IN") !== false || strpos($fakeAdminScope, "IN") !== false, "Admin scope explicitly excludes Super Admin ID $said");
}

// 7. Verify Reporting Scope Isolation
$officerScope = getEffectiveReportingScope($link, 999, null, null, 'Officer');
assertCondition($officerScope['user_ids'] === [999], "Officer reporting scope has exactly 1 user (self)");
assertCondition(empty($officerScope['supervisor_ids']), "Officer reporting scope has zero supervisors");

$targets = getReportingTargets($link);
$officerAdminRows = getAdminPerformanceRows($link, '2026-01-01', '2026-12-31', 999, $targets, 'Officer');
assertCondition(empty($officerAdminRows), "Officer cannot see Admin Performance rows (returns empty array)");

$officerSupRows = getSupervisorPerformanceRows($link, '2026-01-01', '2026-12-31', null, $targets, 999, 'Officer');
assertCondition(empty($officerSupRows), "Officer cannot see Supervisor Performance rows (returns empty array)");

// 8. Test parameter tampering guard
assertCondition(canUserAccessTargetUser($link, 999, 'Officer', 999) === true, "Officer can access self (999 -> 999)");
assertCondition(canUserAccessTargetUser($link, 999, 'Officer', 1000) === false, "Officer CANNOT access peer (999 -> 1000)");
foreach ($superAdminIds as $said) {
    assertCondition(canUserAccessTargetUser($link, 2, 'Admin', $said) === false, "Admin CANNOT access Super Admin (ID $said)");
    assertCondition(canUserAccessTargetUser($link, 999, 'Officer', $said) === false, "Officer CANNOT access Super Admin (ID $said)");
}

echo "\n=== TEST SUMMARY: $passCount PASSED, $failCount FAILED ===\n";
if ($failCount > 0) exit(1);
