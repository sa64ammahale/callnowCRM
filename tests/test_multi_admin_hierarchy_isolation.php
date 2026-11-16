<?php
/**
 * Automated Verification Suite for Multi-Admin & Multi-Manager Hierarchy Isolation
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION["loggedin"] = true;
$_SESSION["id"] = 1;
$_SESSION["Role"] = "Super Admin";
$_SESSION["CompanyName"] = "CallNow";

require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/auth.php';
require_once __DIR__ . '/../php_scripts/team_auth.php';
require_once __DIR__ . '/../php_scripts/hierarchy_engine.php';
require_once __DIR__ . '/../php_scripts/reporting_engine.php';

echo "=== STARTING MULTI-ADMIN & MULTI-MANAGER HIERARCHY ISOLATION TESTS ===\n\n";

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

// Ensure test branches exist
// Admin 1 branch: Admin 7
// Admin 2 branch: Admin 9011
// Manager 9012 (under Admin 9011) manages Team 9001 (Supervisor 9013, Officer 9015)
// Let's create or verify a second manager 9022 (under Admin 9011) with Team 9002 (Supervisor 9014, Officer 9016)

$link->query("SET FOREIGN_KEY_CHECKS=0");

// Check or create test teams and users if needed
$chkAdmin9011 = $link->query("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID = 9011")->fetch_assoc();
if (!$chkAdmin9011) {
    $link->query("INSERT INTO " . tn('TBL_USERS') . " (ID, NAME, MOBILE, LOGIN_ID, ROLE, STATUS, admin_id) VALUES (9011, 'Branch Admin North', '9999999011', 'admin_north', 'Admin', 'Active', NULL)");
}

$chkMgr9012 = $link->query("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID = 9012")->fetch_assoc();
if (!$chkMgr9012) {
    $link->query("INSERT INTO " . tn('TBL_USERS') . " (ID, NAME, MOBILE, LOGIN_ID, ROLE, STATUS, admin_id) VALUES (9012, 'Branch Manager Alpha', '9999999012', 'mgr_alpha', 'Manager', 'Active', 9011)");
}

$chkMgr9022 = $link->query("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID = 9022")->fetch_assoc();
if (!$chkMgr9022) {
    $link->query("INSERT INTO " . tn('TBL_USERS') . " (ID, NAME, MOBILE, LOGIN_ID, ROLE, STATUS, admin_id) VALUES (9022, 'Branch Manager Beta', '9999999022', 'mgr_beta', 'Manager', 'Active', 9011)");
}

$chkSup9013 = $link->query("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID = 9013")->fetch_assoc();
if (!$chkSup9013) {
    $link->query("INSERT INTO " . tn('TBL_USERS') . " (ID, NAME, MOBILE, LOGIN_ID, ROLE, STATUS, admin_id, TEAM_ID) VALUES (9013, 'Supervisor Alpha', '9999999013', 'sup_alpha', 'Supervisor', 'Active', 9011, 9001)");
}

$chkSup9014 = $link->query("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID = 9014")->fetch_assoc();
if (!$chkSup9014) {
    $link->query("INSERT INTO " . tn('TBL_USERS') . " (ID, NAME, MOBILE, LOGIN_ID, ROLE, STATUS, admin_id, TEAM_ID) VALUES (9014, 'Supervisor Beta', '9999999014', 'sup_beta', 'Supervisor', 'Active', 9011, 9002)");
}

$chkOff9015 = $link->query("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID = 9015")->fetch_assoc();
if (!$chkOff9015) {
    $link->query("INSERT INTO " . tn('TBL_USERS') . " (ID, NAME, MOBILE, LOGIN_ID, ROLE, STATUS, admin_id, supervisor_id, TEAM_ID) VALUES (9015, 'Officer Alpha 1', '9999999015', 'off_alpha1', 'Officer', 'Active', 9011, 9013, 9001)");
}

$chkOff9016 = $link->query("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID = 9016")->fetch_assoc();
if (!$chkOff9016) {
    $link->query("INSERT INTO " . tn('TBL_USERS') . " (ID, NAME, MOBILE, LOGIN_ID, ROLE, STATUS, admin_id, supervisor_id, TEAM_ID) VALUES (9016, 'Officer Beta 1', '9999999016', 'off_beta1', 'Officer', 'Active', 9011, 9014, 9002)");
}

// Teams 9001 & 9002
$link->query("INSERT INTO " . tn('TBL_TEAMS') . " (ID, NAME, SUPERVISOR_ID, MANAGER_ID, admin_id) VALUES (9001, 'Team Alpha', 9013, 9012, 9011) ON DUPLICATE KEY UPDATE SUPERVISOR_ID=9013, MANAGER_ID=9012, admin_id=9011");
$link->query("INSERT INTO " . tn('TBL_TEAMS') . " (ID, NAME, SUPERVISOR_ID, MANAGER_ID, admin_id) VALUES (9002, 'Team Beta', 9014, 9022, 9011) ON DUPLICATE KEY UPDATE SUPERVISOR_ID=9014, MANAGER_ID=9022, admin_id=9011");

// Test 1: Role Normalization
echo "--- TEST SUITE 1: ROLE NORMALIZATION ---\n";
assertCondition(getNormalizedRole('Manager') === 'Manager', "Manager normalizes strictly to 'Manager' (not 'Admin')");
assertCondition(getNormalizedRole('Admin') === 'Admin', "Admin normalizes strictly to 'Admin'");
assertCondition(getNormalizedRole('Super Admin') === 'Super Admin', "Super Admin normalizes to 'Super Admin'");
assertCondition(getRoleHierarchyLevel('Super Admin') === 5, "Super Admin level is 5");
assertCondition(getRoleHierarchyLevel('Admin') === 4, "Admin level is 4");
assertCondition(getRoleHierarchyLevel('Manager') === 3, "Manager level is 3");
assertCondition(getRoleHierarchyLevel('Supervisor') === 2, "Supervisor level is 2");
assertCondition(getRoleHierarchyLevel('Officer') === 1, "Officer level is 1");

// Test 2: Multi-Admin Branch Isolation
echo "\n--- TEST SUITE 2: MULTI-ADMIN BRANCH ISOLATION ---\n";
// Admin 9011 authorized users
$admin9011Users = getAuthorizedUserIds($link, 9011, 'Admin');
assertCondition(in_array(9011, $admin9011Users, true), "Admin 9011 sees self");
assertCondition(in_array(9012, $admin9011Users, true), "Admin 9011 sees Manager Alpha (9012)");
assertCondition(in_array(9022, $admin9011Users, true), "Admin 9011 sees Manager Beta (9022)");
assertCondition(in_array(9015, $admin9011Users, true), "Admin 9011 sees Officer Alpha 1 (9015)");
assertCondition(!in_array(1, $admin9011Users, true), "Admin 9011 CANNOT see Super Admin (1)");
assertCondition(!in_array(7, $admin9011Users, true), "Admin 9011 CANNOT see Admin 7 (another admin)");

// Admin 7 authorized users
$admin7Users = getAuthorizedUserIds($link, 7, 'Admin');
assertCondition(!in_array(9011, $admin7Users, true), "Admin 7 CANNOT see Admin 9011");
assertCondition(!in_array(9012, $admin7Users, true), "Admin 7 CANNOT see Manager 9012 under Admin 9011");
assertCondition(!in_array(9015, $admin7Users, true), "Admin 7 CANNOT see Officer 9015 under Admin 9011");

// Test 3: Multi-Manager Team Isolation
echo "\n--- TEST SUITE 3: MULTI-MANAGER TEAM ISOLATION ---\n";
// Manager Alpha (9012) manages Team 9001
$mgrAlphaTeams = getAuthorizedTeamIds($link, 9012, 'Manager');
assertCondition(in_array(9001, $mgrAlphaTeams, true), "Manager Alpha sees Team 9001");
assertCondition(!in_array(9002, $mgrAlphaTeams, true), "Manager Alpha CANNOT see Team 9002 (Manager Beta's team)");

$mgrAlphaUsers = getAuthorizedUserIds($link, 9012, 'Manager');
assertCondition(in_array(9012, $mgrAlphaUsers, true), "Manager Alpha sees self");
assertCondition(in_array(9013, $mgrAlphaUsers, true), "Manager Alpha sees Supervisor Alpha (9013)");
assertCondition(in_array(9015, $mgrAlphaUsers, true), "Manager Alpha sees Officer Alpha 1 (9015)");
assertCondition(!in_array(9022, $mgrAlphaUsers, true), "Manager Alpha CANNOT see Manager Beta (9022)");
assertCondition(!in_array(9014, $mgrAlphaUsers, true), "Manager Alpha CANNOT see Supervisor Beta (9014)");
assertCondition(!in_array(9016, $mgrAlphaUsers, true), "Manager Alpha CANNOT see Officer Beta 1 (9016)");
assertCondition(!in_array(9011, $mgrAlphaUsers, true), "Manager Alpha CANNOT see Admin 9011 (superordinate)");
assertCondition(!in_array(1, $mgrAlphaUsers, true), "Manager Alpha CANNOT see Super Admin 1");

// Manager Beta (9022) manages Team 9002
$mgrBetaTeams = getAuthorizedTeamIds($link, 9022, 'Manager');
assertCondition(in_array(9002, $mgrBetaTeams, true), "Manager Beta sees Team 9002");
assertCondition(!in_array(9001, $mgrBetaTeams, true), "Manager Beta CANNOT see Team 9001");

$mgrBetaUsers = getAuthorizedUserIds($link, 9022, 'Manager');
assertCondition(in_array(9022, $mgrBetaUsers, true), "Manager Beta sees self");
assertCondition(in_array(9014, $mgrBetaUsers, true), "Manager Beta sees Supervisor Beta (9014)");
assertCondition(in_array(9016, $mgrBetaUsers, true), "Manager Beta sees Officer Beta 1 (9016)");
assertCondition(!in_array(9012, $mgrBetaUsers, true), "Manager Beta CANNOT see Manager Alpha (9012)");
assertCondition(!in_array(9015, $mgrBetaUsers, true), "Manager Beta CANNOT see Officer Alpha 1 (9015)");

// Test 4: Unassigned Manager Isolation
echo "\n--- TEST SUITE 4: UNASSIGNED MANAGER ISOLATION ---\n";
// Create unassigned manager 9030
$link->query("INSERT INTO " . tn('TBL_USERS') . " (ID, NAME, MOBILE, LOGIN_ID, ROLE, STATUS, admin_id) VALUES (9030, 'Unassigned Manager', '9999999030', 'unassigned_mgr', 'Manager', 'Active', 9011) ON DUPLICATE KEY UPDATE ROLE='Manager'");
$unassignedMgrTeams = getAuthorizedTeamIds($link, 9030, 'Manager');
$unassignedMgrUsers = getAuthorizedUserIds($link, 9030, 'Manager');
assertCondition(empty($unassignedMgrTeams), "Unassigned Manager has 0 authorized teams (no global fallback leak)");
assertCondition($unassignedMgrUsers === [9030], "Unassigned Manager sees strictly self-only (no users leak)");

// Test 5: Supervisor Isolation
echo "\n--- TEST SUITE 5: SUPERVISOR ISOLATION ---\n";
$supAlphaTeams = getAuthorizedTeamIds($link, 9013, 'Supervisor', 9001);
assertCondition(in_array(9001, $supAlphaTeams, true), "Supervisor Alpha sees Team 9001");
assertCondition(!in_array(9002, $supAlphaTeams, true), "Supervisor Alpha CANNOT see Team 9002");

$supAlphaUsers = getAuthorizedUserIds($link, 9013, 'Supervisor', 9001);
assertCondition(in_array(9013, $supAlphaUsers, true), "Supervisor Alpha sees self");
assertCondition(in_array(9015, $supAlphaUsers, true), "Supervisor Alpha sees Officer Alpha 1 (9015)");
assertCondition(!in_array(9016, $supAlphaUsers, true), "Supervisor Alpha CANNOT see Officer Beta 1 (9016)");
assertCondition(!in_array(9014, $supAlphaUsers, true), "Supervisor Alpha CANNOT see Supervisor Beta (9014)");
assertCondition(!in_array(9012, $supAlphaUsers, true), "Supervisor Alpha CANNOT see Manager Alpha (9012)");

// Test 6: Officer Isolation
echo "\n--- TEST SUITE 6: OFFICER ISOLATION ---\n";
$offAlphaUsers = getAuthorizedUserIds($link, 9015, 'Officer', 9001);
assertCondition($offAlphaUsers === [9015], "Officer Alpha sees strictly self-only");

// Test 7: Super Admin Global Visibility
echo "\n--- TEST SUITE 7: SUPER ADMIN GLOBAL VISIBILITY ---\n";
$superAdminUsers = getAuthorizedUserIds($link, 1, 'Super Admin');
assertCondition(in_array(9011, $superAdminUsers, true), "Super Admin sees Admin 9011");
assertCondition(in_array(9012, $superAdminUsers, true), "Super Admin sees Manager Alpha 9012");
assertCondition(in_array(9022, $superAdminUsers, true), "Super Admin sees Manager Beta 9022");
assertCondition(in_array(7, $superAdminUsers, true), "Super Admin sees Admin 7");

// Test 8: Reporting Engine Isolation
echo "\n--- TEST SUITE 8: REPORTING ENGINE ISOLATION ---\n";
$scopeRaw = shell_exec('C:\xampp\php\php.exe -r "session_start(); $_SESSION[\'loggedin\']=true; $_SESSION[\'id\']=9012; require \'php_scripts/config.php\'; require \'php_scripts/team_auth.php\'; require \'php_scripts/reporting_engine.php\'; echo json_encode(getEffectiveReportingScope($link, null, null, null, \'Manager\'));"');
$mgrAlphaScope = json_decode($scopeRaw, true);

assertCondition($mgrAlphaScope['mode'] === 'manager', "Effective reporting scope mode is 'manager'");
assertCondition(!in_array(9016, $mgrAlphaScope['user_ids'], true), "Manager Alpha reporting scope excludes Officer Beta 1 (9016)");
assertCondition(in_array(9015, $mgrAlphaScope['user_ids'], true), "Manager Alpha reporting scope includes Officer Alpha 1 (9015)");
assertCondition(in_array(9013, $mgrAlphaScope['user_ids'], true), "Manager Alpha reporting scope includes Supervisor Alpha (9013)");
assertCondition(!in_array(9022, $mgrAlphaScope['user_ids'], true), "Manager Alpha reporting scope excludes Manager Beta (9022)");

$targets = getReportingTargets($link, 'Officer');
$mgrAlphaSupRows = getSupervisorPerformanceRows($link, date('Y-m-d'), date('Y-m-d'), null, $targets, 9012, 'Manager');
$mgrAlphaTeamIdsInRows = array_column($mgrAlphaSupRows, 'team_id');
assertCondition(!in_array(9002, $mgrAlphaTeamIdsInRows, true), "Manager Alpha supervisor performance rows exclude Team Beta (9002)");

$mgrAlphaAdminRows = getAdminPerformanceRows($link, date('Y-m-d'), date('Y-m-d'), null, $targets, 'Manager');
assertCondition(empty($mgrAlphaAdminRows), "Manager Alpha admin performance rows is strictly empty []");

// Test 9: Team Auth Helper Functions
echo "\n--- TEST SUITE 9: TEAM AUTH HELPER FUNCTIONS ---\n";
// Run a separate PHP process with Manager session to verify canViewAllTBL_TEAMS() returns false
$mgrOutput = shell_exec('C:\xampp\php\php.exe -r "session_start(); $_SESSION[\'loggedin\']=true; $_SESSION[\'id\']=9012; require \'php_scripts/config.php\'; require \'php_scripts/team_auth.php\'; echo canViewAllTBL_TEAMS() ? \'TRUE\' : \'FALSE\';"');
assertCondition(trim($mgrOutput) === 'FALSE', "canViewAllTBL_TEAMS() returns FALSE for Manager");

$admOutput = shell_exec('C:\xampp\php\php.exe -r "session_start(); $_SESSION[\'loggedin\']=true; $_SESSION[\'id\']=9011; require \'php_scripts/config.php\'; require \'php_scripts/team_auth.php\'; echo canViewAllTBL_TEAMS() ? \'TRUE\' : \'FALSE\';"');
assertCondition(trim($admOutput) === 'FALSE', "canViewAllTBL_TEAMS() returns FALSE for Admin");

$saOutput = shell_exec('C:\xampp\php\php.exe -r "session_start(); $_SESSION[\'loggedin\']=true; $_SESSION[\'id\']=1; require \'php_scripts/config.php\'; require \'php_scripts/team_auth.php\'; echo canViewAllTBL_TEAMS() ? \'TRUE\' : \'FALSE\';"');
assertCondition(trim($saOutput) === 'TRUE', "canViewAllTBL_TEAMS() returns TRUE for Super Admin");

echo "\n======================================================\n";
echo "TEST RESULTS: Passed: $passCount | Failed: $failCount\n";
echo "======================================================\n";

if ($failCount > 0) {
    exit(1);
}
