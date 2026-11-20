<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
putenv('APP_DEBUG=1');
$_SERVER['APP_DEBUG'] = '1';

require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/lead_distribution_engine.php';

ensureLeadEngineSchema($link);

echo "=== TEST 1: startCallLock sets LAST_DIALED_DATE_TIME on MAIN ===\n";
// Pick or insert a test record in MAIN
$testPhone = '9998887770';
$link->query("DELETE FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = '$testPhone'");
$link->query("DELETE FROM lead_reservations WHERE phone_number_10 = '$testPhone'");
$link->query("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_CALL_DIALED_STATUS, CALL_COUNT, MAINDATABASE_UPLOAD_DATETIME) VALUES ('Test Dup Fix', '$testPhone', 'Not Called', 0, NOW())");
$testId = (int)$link->insert_id;

// Claim a batch for user 888
$claimRes = claimLeadBatch($link, 888, 'incredit', 'test_dev', 1, 'claim_test_1', 1, false);
$token = null;
foreach ($claimRes['leads'] as $l) {
    if ($l['mobile'] === $testPhone || $l['id'] === $testId) {
        $token = $l['reservation_token'];
        break;
    }
}
if (!$token) {
    // Manually create reservation to test startCallLock
    $token = 'res_test_' . time();
    $link->query("INSERT INTO lead_reservations (batch_id, batch_uuid, reservation_token, company_code, telecaller_id, device_id, source, lead_id, phone_number_10, state, expires_at) VALUES (1, 'b1', '$token', 'incredit', 888, 'd1', 'MAIN', $testId, '$testPhone', 'RESERVED', NOW() + INTERVAL 2 HOUR)");
}

$lockRes = startCallLock($link, 888, $token, $testId, 'MAIN', 'd1', 1);
echo "startCallLock result: " . ($lockRes['success'] ? "SUCCESS" : json_encode($lockRes)) . "\n";

$checkM = $link->query("SELECT LAST_DIALED_DATE_TIME, CALL_COUNT FROM " . tn('TBL_MAIN') . " WHERE ID = $testId")->fetch_assoc();
echo "MAIN LAST_DIALED_DATE_TIME: " . ($checkM['LAST_DIALED_DATE_TIME'] ?? 'NULL') . "\n";
echo "MAIN CALL_COUNT: " . ($checkM['CALL_COUNT'] ?? '0') . "\n";
assert(!empty($checkM['LAST_DIALED_DATE_TIME']), "LAST_DIALED_DATE_TIME must be set!");
assert((int)$checkM['CALL_COUNT'] >= 1, "CALL_COUNT must be >= 1!");

echo "\n=== TEST 2: isPhoneReservedOrDnd blocks recently dialed phone ===\n";
$isBlocked = isPhoneReservedOrDnd($link, $testPhone, 888);
echo "isPhoneReservedOrDnd for $testPhone (user 888): " . ($isBlocked ? "BLOCKED (CORRECT)" : "NOT BLOCKED (BUG)") . "\n";
assert($isBlocked === true, "Must be blocked!");

$isBlockedOther = isPhoneReservedOrDnd($link, $testPhone, 999);
echo "isPhoneReservedOrDnd for $testPhone (other user 999): " . ($isBlockedOther ? "BLOCKED (CORRECT)" : "NOT BLOCKED (BUG)") . "\n";
assert($isBlockedOther === true, "Must be blocked for other users too!");

echo "\n=== TEST 3: queryWaterfallP0 excludes this dialed lead ===\n";
$p0Leads = queryWaterfallP0($link, 888, 10, []);
$found = false;
foreach ($p0Leads as $pl) {
    if ($pl['phone_number_10'] === $testPhone) {
        $found = true;
        break;
    }
}
echo "P0 contains dialed test lead: " . ($found ? "YES (BUG)" : "NO (CORRECT)") . "\n";
assert(!$found, "Dialed lead must not be in P0!");

// Clean up test record
$link->query("DELETE FROM " . tn('TBL_MAIN') . " WHERE ID = $testId");
$link->query("DELETE FROM lead_reservations WHERE phone_number_10 = '$testPhone'");

echo "\nALL TESTS PASSED SUCCESSFULLY!\n";
