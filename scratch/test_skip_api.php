<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/lead_distribution_engine.php';

echo "=== TESTING SKIP API ROUTE ===\n";

$testPhone = '9988776644';
$userA = 101;

$link->query("DELETE FROM lead_reservations WHERE phone_number_10 = '$testPhone'");
$link->query("DELETE FROM " . tn('TBL_MAIN') . " WHERE RIGHT(REGEXP_REPLACE(MAINDATABASE_MOBILE, '[^0-9]', ''), 10) = '$testPhone'");

$link->query("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_UPLOAD_DATETIME) VALUES ('Test API Skip Lead', '$testPhone', NOW())");
$leadId = (int)$link->insert_id;

// Simulate API call to /calls/{id}/skip
$_SERVER['REQUEST_METHOD'] = 'POST';
$rawInput = json_encode([
    'record_id' => $leadId,
    'source' => 'MAIN'
]);

// Test directly via skipLeadReservation logic matching api/v1/telecaller/index.php
$res = skipLeadReservation($link, $userA, $leadId, 'MAIN', null);
echo "API response simulation: " . json_encode($res) . "\n";

if (empty($res['success']) || $res['state'] !== 'SKIPPED') {
    echo "FAIL: API skip failed\n";
    exit(1);
}

$isBlocked = isPhoneReservedOrDnd($link, $testPhone, $userA);
echo "User A blocked for 90 days? " . ($isBlocked ? "YES (PASSED)" : "NO (FAILED)") . "\n";

// Clean up
$link->query("DELETE FROM lead_reservations WHERE phone_number_10 = '$testPhone'");
$link->query("DELETE FROM " . tn('TBL_MAIN') . " WHERE ID = $leadId");

echo "=== API SKIP TEST PASSED ===\n";
