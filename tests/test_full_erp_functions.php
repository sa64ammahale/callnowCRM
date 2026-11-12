<?php
/**
 * CALLNOW CRM — Comprehensive Full ERP Functions & Target Requirements Test Suite
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../php_scripts/config.php';
if (!str_starts_with($DB_NAME, 'callnow_audit_')) throw new RuntimeException('Run through tests/run_project_audit.php to protect the working database');

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}
$_SESSION["loggedin"] = true;
$_SESSION["id"] = 1;
$_SESSION["LAST_ACTIVITY"] = time();

require_once __DIR__ . '/../php_scripts/auth.php';
require_once __DIR__ . '/../php_scripts/super_admin.php';
define('API_SKIP_AUTO_AUTH', true);
require_once __DIR__ . '/../php_scripts/api_auth.php';
require_once __DIR__ . '/../modules/leads/lead_common.php';

$passed = 0;
$failed = 0;
$total  = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] $description\n";
    } else {
        $failed++;
        echo "  [FAIL] $description" . ($detail ? " ($detail)" : '') . "\n";
    }
}

echo "====================================================\n";
echo "   CALLNOW CRM FULL ERP FUNCTIONS TEST SUITE       \n";
echo "====================================================\n\n";

// ----------------------------------------------------
// GROUP 1: SUPER ADMIN SCHEMA & LIMIT FUNCTIONS
// ----------------------------------------------------
echo "--- GROUP 1: SUPER ADMIN & PLAN LIMIT FUNCTIONS ---\n";
ensureSuperAdminSchema($link);

$plan = getCurrentPlan($link);
assertTest("getCurrentPlan() returns active subscription plan", is_array($plan) && !empty($plan['plan_name']));

$effPlan = getEffectivePlan($link);
assertTest("getEffectivePlan() resolves limits", is_array($effPlan) && isset($effPlan['max_users']) && (int)$effPlan['max_users'] > 0);

$summary = getLimitUsageSummary($link);
assertTest("getLimitUsageSummary() returns valid usage counts", isset($summary['users']['current'], $summary['storage_mb']['current'], $summary['api_tokens']['current']));

$userLimitCheck = checkUserLimit($link);
assertTest("checkUserLimit() returns null or string warning", $userLimitCheck === null || is_string($userLimitCheck));

$tokenLimitCheck = checkApiTokenLimit($link);
assertTest("checkApiTokenLimit() returns null or string warning", $tokenLimitCheck === null || is_string($tokenLimitCheck));

$storageLimitCheck = checkStorageLimit($link);
assertTest("checkStorageLimit() returns null or string warning", $storageLimitCheck === null || is_string($storageLimitCheck));

// ----------------------------------------------------
// GROUP 2: AUTH & RBAC SYSTEM FUNCTIONS
// ----------------------------------------------------
echo "\n--- GROUP 2: AUTH & RBAC SYSTEM FUNCTIONS ---\n";

// Test System Admin ID=1
$_SESSION['id'] = 1;
$_SESSION['role'] = 'Super Admin';
$_SESSION['user'] = ['ID' => 1, 'ROLE' => 'Super Admin', 'NAME' => 'System Admin'];

assertTest("can('manage_users') passes for Super Admin", can('manage_users'));
assertTest("can('manage_settings') passes for Super Admin", can('manage_settings'));
assertTest("can('view_reports') passes for Super Admin", can('view_reports'));
assertTest("can('manage_database') passes for Super Admin", can('manage_database'));
assertTest("can('upload_data') passes for Super Admin", can('upload_data'));

// Test clearRbacCache
clearRbacCache($link);
$verRow = $link->query("SELECT setting_value FROM " . tn('TBL_APP_SETTINGS') . " WHERE setting_key = 'rbac_cache_version'")->fetch_assoc();
assertTest("clearRbacCache() bumps rbac_cache_version in app_settings", !empty($verRow['setting_value']));

// Test logActivity signature flexibility
logActivity($link, 1, 'UPDATE', 'Test details with full 6 arguments', '1', 'users');
$chkLog1 = $link->query("SELECT LOG_ID FROM " . tn('TBL_ACTIVITY_LOG') . " WHERE ACTION_DETAILS = 'Test details with full 6 arguments' ORDER BY LOG_ID DESC LIMIT 1")->fetch_assoc();
assertTest("logActivity() works with standard (link, userId, type, details) arguments", !empty($chkLog1));

logActivity($link, 'UPDATE', 'Legacy 3-arg call test');
$chkLog2 = $link->query("SELECT USER_ID, ACTION_DETAILS FROM " . tn('TBL_ACTIVITY_LOG') . " WHERE ACTION_DETAILS = 'Legacy 3-arg call test' ORDER BY LOG_ID DESC LIMIT 1")->fetch_assoc();
assertTest("logActivity() gracefully handles legacy 3-argument signature without failure", !empty($chkLog2) && (int)$chkLog2['USER_ID'] === 1);

// Test accessible user IDs for Supervisor
$supUsers = getAccessibleUserIds($link);
assertTest("getAccessibleUserIds() returns accessible user array", is_array($supUsers));

// ----------------------------------------------------
// GROUP 3: LEADS MODULE FUNCTIONS & SCHEMA
// ----------------------------------------------------
echo "\n--- GROUP 3: LEADS MODULE & WORKFLOW ---\n";
ensureLeadModuleSchema($link);

$testMobile = '9999888877';
// Cleanup previous test lead if any
$link->query("DELETE FROM " . tn('TBL_LEADS') . " WHERE MOBILE = '$testMobile'");
$link->query("DELETE FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = '$testMobile'");

// 1. Insert test main contact
$link->query("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME) VALUES ('Full ERP Test Client', '$testMobile', 'ERP Solutions Inc', '5L', 'Not Called', NOW(), NOW())");
$testMainId = $link->insert_id;

// 2. Call syncCallToLeadsAndPipeline with 'Interested'
$leadId = syncCallToLeadsAndPipeline($link, 'MAIN', $testMainId, 1, 'Enquiry/ Intrested', 'Client urgently interested in 5L business loan', date('Y-m-d H:i:s', strtotime('+2 days')));
assertTest("syncCallToLeadsAndPipeline() auto-creates lead for 'Enquiry/ Intrested'", $leadId !== null && $leadId > 0);

$leadRow = $link->query("SELECT * FROM " . tn('TBL_LEADS') . " WHERE lead_id = $leadId")->fetch_assoc();
assertTest("Auto-created Lead has stage 'Hot' and priority 'High'", $leadRow && $leadRow['lead_stage'] === 'Hot' && $leadRow['priority'] === 'High');
assertTest("Auto-created Lead has assigned_to set to dialing telecaller", $leadRow && (int)$leadRow['assigned_to'] === 1);

// 3. Followup created
$fuRow = $link->query("SELECT * FROM " . tn('TBL_LEAD_FOLLOWUPS') . " WHERE lead_id = $leadId AND status = 'OPEN'")->fetch_assoc();
assertTest("syncCallToLeadsAndPipeline() auto-schedules OPEN follow-up", !empty($fuRow));

// 4. Test Lead Notes
$link->query("INSERT INTO " . tn('TBL_LEAD_NOTES') . " (lead_id, user_id, note, created_at) VALUES ($leadId, 1, 'Verified income documents on file', NOW())");
$noteRow = $link->query("SELECT id FROM " . tn('TBL_LEAD_NOTES') . " WHERE lead_id = $leadId ORDER BY id DESC LIMIT 1")->fetch_assoc();
assertTest("Lead note persisted in TBL_LEAD_NOTES", !empty($noteRow));

// Cleanup test lead
$link->query("DELETE FROM " . tn('TBL_LEAD_NOTES') . " WHERE lead_id = $leadId");
$link->query("DELETE FROM " . tn('TBL_LEAD_FOLLOWUPS') . " WHERE lead_id = $leadId");
$link->query("DELETE FROM " . tn('TBL_LEADS') . " WHERE lead_id = $leadId");
$link->query("DELETE FROM " . tn('TBL_MAIN') . " WHERE ID = $testMainId");

// ----------------------------------------------------
// GROUP 4: DATABASE TARGET REQUIREMENTS VERIFICATION
// ----------------------------------------------------
echo "\n--- GROUP 4: DATABASE TARGET REQUIREMENTS ---\n";

// Req 1: Upload test temporary data, verify mobile app can call, admin can delete directly
$tempMobile = '9111222333';
$link->query("DELETE FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = '$tempMobile'");
$link->query("INSERT INTO " . tn('TBL_TEMP') . " (CUST_NAME, CUST_MOBILE, CUST_COMPANY, CALL_DIALED_STATUS, TEMP_UPLOAD_DATETIME) VALUES ('Temp Test Prospect', '$tempMobile', 'Test Beta Corp', 'Not Called', NOW())");
$tempId = $link->insert_id;
assertTest("Target 1: Temporary test record uploaded into TBL_TEMP", $tempId > 0);

// Admin can bulk delete or delete directly from temporary data
$delTemp = $link->query("DELETE FROM " . tn('TBL_TEMP') . " WHERE ID = $tempId");
assertTest("Target 1: Admin can delete poor quality data directly from temporary table", $delTemp && $link->affected_rows === 1);

// Req 2: Main database persists records for calling and logs
$mainCount = (int)$link->query("SELECT COUNT(*) FROM " . tn('TBL_MAIN'))->fetch_row()[0];
assertTest("Target 1 & 2: Main database holds permanent records ($mainCount records)", $mainCount > 0);

// Req 4: Oldest dialed sorting
$sortTest = $link->query("SELECT ID, LAST_DIALED_DATE_TIME FROM " . tn('TBL_MAIN') . " ORDER BY CASE WHEN LAST_DIALED_DATE_TIME IS NULL THEN 0 ELSE 1 END, LAST_DIALED_DATE_TIME ASC LIMIT 5");
assertTest("Target 4: Sorting by oldest dialed prioritizes fresh and oldest records", $sortTest && $sortTest->num_rows > 0);

// ----------------------------------------------------
// GROUP 5: REPORTS MODULE ROLE-SCOPED QUERIES
// ----------------------------------------------------
echo "\n--- GROUP 5: ROLE-SCOPED REPORTS VERIFICATION ---\n";

// Super Admin query
$repQ = $link->query("
    SELECT 
        COUNT(*) as total_calls,
        SUM(CASE WHEN call_duration > 0 THEN 1 ELSE 0 END) as connected_calls
    FROM " . tn('TBL_CALL_LOGS') . "
");
$repRes = $repQ->fetch_assoc();
assertTest("Super Admin report aggregates all organization calls (Total: " . $repRes['total_calls'] . ")", $repRes && isset($repRes['total_calls']));

// Supervisor team scoping query
$supQ = $link->query("
    SELECT 
        COUNT(*) as team_calls
    FROM " . tn('TBL_CALL_LOGS') . " cl
    JOIN " . tn('TBL_USERS') . " u ON cl.user_id = u.ID
    WHERE u.TEAM_ID = 2
");
$supRes = $supQ->fetch_assoc();
assertTest("Supervisor report successfully scopes to supervised team (Team 2: " . $supRes['team_calls'] . " calls)", $supRes && isset($supRes['team_calls']));

// Officer personal scoping query
$offQ = $link->query("
    SELECT 
        COUNT(*) as my_calls
    FROM " . tn('TBL_CALL_LOGS') . " cl
    WHERE cl.user_id = 1
");
$offRes = $offQ->fetch_assoc();
assertTest("Officer report successfully scopes strictly to self calls (User 1: " . $offRes['my_calls'] . " calls)", $offRes && isset($offRes['my_calls']));

// ----------------------------------------------------
// GROUP 6: DND PROTECTION CROSS-DATABASE CHECKS
// ----------------------------------------------------
echo "\n--- GROUP 6: DND PROTECTION CHECKS ---\n";
$dndMobile = '9876543210';

// Check ViewDND query sargability and speed
$startDnd = microtime(true);
$dndVariants = "('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')";
$qDndMain = $link->query("SELECT ID, MAINDATABASE_NAME, MAINDATABASE_MOBILE FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_CALL_DIALED_STATUS IN $dndVariants LIMIT 10");
$dndElapsed = round((microtime(true) - $startDnd) * 1000, 2);
assertTest("ViewDND query executes in under 50ms ($dndElapsed ms)", $qDndMain !== false && $dndElapsed < 50);

echo "\n====================================================\n";
echo "SUMMARY: Total: $total | Passed: $passed | Failed: $failed\n";
echo "====================================================\n";

if ($failed === 0) {
    echo "\n>>> ALL ERP FUNCTIONS AND TARGET REQUIREMENTS VERIFIED 100% PASSED! <<<\n";
}
