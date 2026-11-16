<?php
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/hierarchy_engine.php';
require_once __DIR__ . '/../php_scripts/reporting_engine.php';

$pass = 0; $fail = 0;
function assertT(bool $cond, string $msg) {
    global $pass, $fail;
    if ($cond) { echo " [PASS] $msg\n"; $pass++; }
    else { echo " [FAIL] $msg\n"; $fail++; }
}

echo "=== VERIFYING TEAM-WISE FILTER FOR SUPER ADMIN, ADMIN & MANAGER ===\n\n";

// --- 1. SUPER ADMIN TEAM FILTER ---
echo "--- 1. Super Admin Team Filter ---\n";
$superAll = getEffectiveReportingScope($link, null, null, null, 'Super Admin', 1);
assertT(empty($superAll['user_ids']), "Super Admin without team filter has global access (empty user_ids filter)");

$superTeam1 = getEffectiveReportingScope($link, null, 9001, null, 'Super Admin', 1);
assertT(in_array(9015, $superTeam1['user_ids'], true), "Super Admin with Team 9001 includes Officer Alpha (9015)");
assertT(!in_array(9016, $superTeam1['user_ids'], true), "Super Admin with Team 9001 EXCLUDES Officer Beta (9016 from Team 9002)");

$superTeam2 = getEffectiveReportingScope($link, null, 9002, null, 'Super Admin', 1);
assertT(in_array(9016, $superTeam2['user_ids'], true), "Super Admin with Team 9002 includes Officer Beta (9016)");
assertT(!in_array(9015, $superTeam2['user_ids'], true), "Super Admin with Team 9002 EXCLUDES Officer Alpha (9015)");

// --- 2. ADMIN TEAM FILTER ---
echo "\n--- 2. Admin Team Filter ---\n";
$adminAll = getEffectiveReportingScope($link, null, null, null, 'Admin', 9011);
assertT(in_array(9015, $adminAll['user_ids'], true), "Admin without team filter includes Officer Alpha 1 (9015)");

$adminTeam1 = getEffectiveReportingScope($link, null, 9001, null, 'Admin', 9011);
assertT(in_array(9015, $adminTeam1['user_ids'], true), "Admin with Team 9001 includes Officer Alpha 1 (9015)");
assertT(!in_array(9016, $adminTeam1['user_ids'], true), "Admin with Team 9001 EXCLUDES Officer Beta 1 (9016 from Team 9002)");

// --- 3. MANAGER TEAM FILTER ---
echo "\n--- 3. Manager Team Filter ---\n";
$mgrTeam1 = getEffectiveReportingScope($link, null, 9001, null, 'Manager', 9012);
assertT(in_array(9015, $mgrTeam1['user_ids'], true), "Manager Alpha with Team 9001 includes Officer Alpha (9015)");
assertT(!in_array(9016, $mgrTeam1['user_ids'], true), "Manager Alpha with Team 9001 EXCLUDES Officer Beta (9016)");

// --- 4. EMPLOYEE PERFORMANCE ROWS UNDER TEAM FILTER ---
echo "\n--- 4. Employee Performance Rows Under Team Filter ---\n";
$targets = getReportingTargets($link, 'Officer');
$empRowsTeam1 = getEmployeePerformanceRows($link, '2026-01-01', '2026-12-31', 9001, null, null, 'Super Admin', $targets);
$empUserIds = array_map('intval', array_column($empRowsTeam1, 'user_id'));
assertT(in_array(9015, $empUserIds, true), "empRows for Team 9001 contains Officer Alpha (9015)");
assertT(!in_array(9016, $empUserIds, true), "empRows for Team 9001 strictly excludes Officer Beta (9016)");

$empRowsTeam2 = getEmployeePerformanceRows($link, '2026-01-01', '2026-12-31', 9002, null, null, 'Super Admin', $targets);
$empUserIds2 = array_map('intval', array_column($empRowsTeam2, 'user_id'));
assertT(in_array(9016, $empUserIds2, true), "empRows for Team 9002 contains Officer Beta (9016)");
assertT(!in_array(9015, $empUserIds2, true), "empRows for Team 9002 strictly excludes Officer Alpha (9015)");

// --- 5. SUPERVISOR PERFORMANCE ROWS UNDER TEAM FILTER ---
echo "\n--- 5. Supervisor Performance Rows Under Team Filter ---\n";
$supRowsTeam1 = getSupervisorPerformanceRows($link, '2026-01-01', '2026-12-31', 9001, $targets, 1, 'Super Admin');
$supTeamIds = array_map('intval', array_column($supRowsTeam1, 'team_id'));
assertT(in_array(9001, $supTeamIds, true), "supRows for Team 9001 contains Team 9001");
assertT(!in_array(9002, $supTeamIds, true), "supRows for Team 9001 strictly excludes Team 9002");

// Clean debug file
if (file_exists(__DIR__ . '/debug_team.php')) unlink(__DIR__ . '/debug_team.php');

echo "\n======================================================\n";
echo "  RESULTS: $pass PASSED, $fail FAILED\n";
echo "======================================================\n";

if ($fail > 0) exit(1);
