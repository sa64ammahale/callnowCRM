<?php
/**
 * Automated Verification Suite: Controllable Users CRUD for Manager & Supervisor Roles
 */

require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/permissions.php';
require_once __DIR__ . '/../php_scripts/hierarchy_engine.php';

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $label): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] $label\n";
    } else {
        $failCount++;
        echo "  [FAIL] $label\n";
    }
}

echo "======================================================\n";
echo "  CONTROLLABLE USERS CRUD VERIFICATION SUITE\n";
echo "  Manager & Supervisor Roles Permissions & Scoping\n";
echo "======================================================\n\n";

// --- 1. RBAC Permission Checks ---
echo "--- 1. RBAC Permission Checks ---\n";

// Ensure schema is updated on local DB
ensureHierarchySchema($link);

// Test Manager Permissions
$stmtM = $link->prepare("SELECT permission_value FROM " . tn('TBL_ROLE_PERMISSIONS') . " WHERE role = 'Manager' AND permission_key = 'manage_users'");
$stmtM->execute();
$valM = (int)($stmtM->get_result()->fetch_row()[0] ?? 0);
assertTest($valM === 1, "Manager has manage_users = 1 in role_permissions");

// Test Supervisor Permissions
$stmtS = $link->prepare("SELECT permission_value FROM " . tn('TBL_ROLE_PERMISSIONS') . " WHERE role = 'Supervisor' AND permission_key = 'manage_users'");
$stmtS->execute();
$valS = (int)($stmtS->get_result()->fetch_row()[0] ?? 0);
assertTest($valS === 1, "Supervisor has manage_users = 1 in role_permissions");

// Test Officer Permissions
$stmtO = $link->prepare("SELECT permission_value FROM " . tn('TBL_ROLE_PERMISSIONS') . " WHERE role = 'Officer' AND permission_key = 'manage_users'");
$stmtO->execute();
$valO = (int)($stmtO->get_result()->fetch_row()[0] ?? 0);
assertTest($valO === 0, "Officer has manage_users = 0 in role_permissions");

// Verify system role defaults in PHP
$defaults = getSystemRoleDefaults();
assertTest(!empty($defaults['Supervisor']['manage_users']), "getSystemRoleDefaults() includes manage_users for Supervisor");
assertTest(!empty($defaults['Manager']['*']), "getSystemRoleDefaults() includes wildcard for Manager");

// --- 2. Role Assignment Guard (canActorAssignRole) ---
echo "\n--- 2. Role Assignment Guard (canActorAssignRole) ---\n";
assertTest(canActorAssignRole('Supervisor', 'Officer') === true, "Supervisor CAN assign Officer");
assertTest(canActorAssignRole('Supervisor', 'Supervisor') === false, "Supervisor CANNOT assign Supervisor");
assertTest(canActorAssignRole('Supervisor', 'Manager') === false, "Supervisor CANNOT assign Manager");
assertTest(canActorAssignRole('Supervisor', 'Admin') === false, "Supervisor CANNOT assign Admin");
assertTest(canActorAssignRole('Supervisor', 'Super Admin') === false, "Supervisor CANNOT assign Super Admin");

assertTest(canActorAssignRole('Manager', 'Officer') === true, "Manager CAN assign Officer");
assertTest(canActorAssignRole('Manager', 'Supervisor') === true, "Manager CAN assign Supervisor");
assertTest(canActorAssignRole('Manager', 'Manager') === false, "Manager CANNOT assign Manager");
assertTest(canActorAssignRole('Manager', 'Admin') === false, "Manager CANNOT assign Admin");
assertTest(canActorAssignRole('Manager', 'Super Admin') === false, "Manager CANNOT assign Super Admin");

// --- 3. Supervisor User Directory Scoping ---
echo "\n--- 3. Supervisor User Directory Scoping ---\n";
// Ensure test fixtures exist
$link->query("INSERT IGNORE INTO teams (ID, NAME, SUPERVISOR_ID, MANAGER_ID) VALUES (9001, 'Test Team Alpha', 9013, 9012)");
$link->query("INSERT IGNORE INTO teams (ID, NAME, SUPERVISOR_ID, MANAGER_ID) VALUES (9002, 'Test Team Beta', 9014, 9012)");

// Supervisor Alpha (ID 9013, Team 9001)
$supScopedIds = getAuthorizedUserIds($link, 9013, 'Supervisor', 9001);

assertTest(in_array(9013, $supScopedIds, true), "Supervisor Alpha sees self (ID 9013)");
assertTest(in_array(9015, $supScopedIds, true), "Supervisor Alpha sees Team Alpha Officer (ID 9015)");
assertTest(!in_array(9014, $supScopedIds, true), "Supervisor Alpha CANNOT see Team Beta Supervisor (ID 9014)");
assertTest(!in_array(9016, $supScopedIds, true), "Supervisor Alpha CANNOT see Team Beta Officer (ID 9016)");
assertTest(!in_array(9011, $supScopedIds, true), "Supervisor Alpha CANNOT see Admin (ID 9011)");
assertTest(!in_array(1, $supScopedIds, true), "Supervisor Alpha CANNOT see Super Admin ID 1");

// --- 4. Manager User Directory Scoping ---
echo "\n--- 4. Manager User Directory Scoping ---\n";
// Manager (ID 9012, manages Team 9001 & 9002)
$mgrScopedIds = getAuthorizedUserIds($link, 9012, 'Manager', 9001);

assertTest(in_array(9012, $mgrScopedIds, true), "Manager sees self (ID 9012)");
assertTest(in_array(9013, $mgrScopedIds, true), "Manager sees Supervisor Alpha (ID 9013)");
assertTest(in_array(9014, $mgrScopedIds, true), "Manager sees Supervisor Beta (ID 9014)");
assertTest(in_array(9015, $mgrScopedIds, true), "Manager sees Officer Alpha (ID 9015)");
assertTest(in_array(9016, $mgrScopedIds, true), "Manager sees Officer Beta (ID 9016)");
assertTest(!in_array(9011, $mgrScopedIds, true), "Manager CANNOT see Admin (ID 9011)");
assertTest(!in_array(1, $mgrScopedIds, true), "Manager CANNOT see Super Admin ID 1");

// --- 5. Target Access Checks (canUserAccessTargetUser) ---
echo "\n--- 5. Target Access Checks (canUserAccessTargetUser) ---\n";
assertTest(canUserAccessTargetUser($link, 9013, 'Supervisor', 9015) === true, "Supervisor Alpha can access Officer Alpha");
assertTest(canUserAccessTargetUser($link, 9013, 'Supervisor', 9016) === false, "Supervisor Alpha CANNOT access Officer Beta");
assertTest(canUserAccessTargetUser($link, 9013, 'Supervisor', 9011) === false, "Supervisor Alpha CANNOT access Admin");
assertTest(canUserAccessTargetUser($link, 9013, 'Supervisor', 1) === false, "Supervisor Alpha CANNOT access Super Admin ID 1");

assertTest(canUserAccessTargetUser($link, 9012, 'Manager', 9013) === true, "Manager can access Supervisor Alpha");
assertTest(canUserAccessTargetUser($link, 9012, 'Manager', 9015) === true, "Manager can access Officer Alpha");
assertTest(canUserAccessTargetUser($link, 9012, 'Manager', 9011) === false, "Manager CANNOT access Admin");
assertTest(canUserAccessTargetUser($link, 9012, 'Manager', 1) === false, "Manager CANNOT access Super Admin ID 1");

// --- 6. Supervisor Authorized Teams Resolution ---
echo "\n--- 6. Supervisor Authorized Teams Resolution ---\n";
$supAuthTeams = getAuthorizedTeamIds($link, 9013, 'Supervisor', 9001);
assertTest(in_array(9001, $supAuthTeams, true), "Supervisor Alpha resolves Team Alpha (9001)");
assertTest(!in_array(9002, $supAuthTeams, true), "Supervisor Alpha does NOT resolve Team Beta (9002)");

$mgrAuthTeams = getAuthorizedTeamIds($link, 9012, 'Manager', 9001);
assertTest(in_array(9001, $mgrAuthTeams, true), "Manager resolves managed Team Alpha (9001)");
assertTest(in_array(9002, $mgrAuthTeams, true), "Manager resolves managed Team Beta (9002)");

echo "\n======================================================\n";
echo "  TEST SUMMARY: $passCount PASSED, $failCount FAILED\n";
echo "======================================================\n";

if ($failCount > 0) {
    exit(1);
}
