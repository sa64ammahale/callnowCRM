<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/lead_distribution_engine.php';

echo "=== TESTING 90-DAY PER-CALLER SKIP FEATURE ===\n";

$testPhone = '9988776655';
$userA = 101;
$userB = 102;

// Clean up any test records
$link->query("DELETE FROM lead_reservations WHERE phone_number_10 = '$testPhone'");
$link->query("DELETE FROM " . tn('TBL_TEMP') . " WHERE RIGHT(REGEXP_REPLACE(CUST_MOBILE, '[^0-9]', ''), 10) = '$testPhone'");
$link->query("DELETE FROM " . tn('TBL_MAIN') . " WHERE RIGHT(REGEXP_REPLACE(MAINDATABASE_MOBILE, '[^0-9]', ''), 10) = '$testPhone'");

// Insert dummy lead into TBL_MAIN
$link->query("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_UPLOAD_DATETIME) VALUES ('Test Skip Lead', '$testPhone', '2000-01-01 00:00:00')");
$leadId = (int)$link->insert_id;

echo "Inserted test lead ID: $leadId with phone: $testPhone\n";

// 1. Check initial state for User A and User B
$initA = isPhoneReservedOrDnd($link, $testPhone, $userA);
$initB = isPhoneReservedOrDnd($link, $testPhone, $userB);
echo "Initial check: User A isReservedOrDnd=" . ($initA ? "true" : "false") . ", User B=" . ($initB ? "true" : "false") . "\n";
if ($initA || $initB) {
    echo "FAIL: Initially shouldn't be reserved or DND\n";
    exit(1);
}

// 2. User A skips this lead
$skipRes = skipLeadReservation($link, $userA, $leadId, 'MAIN', null);
echo "User A skipped lead result: " . json_encode($skipRes) . "\n";

// 3. Verify reservation table
$resQ = $link->query("SELECT * FROM lead_reservations WHERE phone_number_10 = '$testPhone'");
$row = $resQ->fetch_assoc();
echo "Reservation row: state=" . ($row['state'] ?? 'NONE') . ", caller=" . ($row['telecaller_id'] ?? 'NONE') . "\n";
if (($row['state'] ?? '') !== 'SKIPPED' || (int)($row['telecaller_id'] ?? 0) !== $userA) {
    echo "FAIL: Reservation state should be SKIPPED for user A\n";
    exit(1);
}

// 4. Test isPhoneReservedOrDnd for User A vs User B
$afterA = isPhoneReservedOrDnd($link, $testPhone, $userA);
$afterB = isPhoneReservedOrDnd($link, $testPhone, $userB);
echo "Post-skip check: User A isReservedOrDnd=" . ($afterA ? "true (BLOCKED)" : "false") . ", User B=" . ($afterB ? "true (BLOCKED)" : "false (ALLOWED)") . "\n";

if (!$afterA) {
    echo "FAIL: User A should be blocked for 90 days from this lead!\n";
    exit(1);
}

if ($afterB) {
    echo "FAIL: User B should NOT be blocked from this lead!\n";
    exit(1);
}

// 5. Test queryWaterfallP0
$p0UserA = queryWaterfallP0($link, $userA, 10, []);
$foundA = false;
foreach ($p0UserA as $c) {
    if ($c['phone_number_10'] === $testPhone) {
        $foundA = true;
        break;
    }
}

$p0UserB = queryWaterfallP0($link, $userB, 10, []);
$foundB = false;
foreach ($p0UserB as $c) {
    if ($c['phone_number_10'] === $testPhone) {
        $foundB = true;
        break;
    }
}

echo "Waterfall P0 result: Found in User A's candidates? " . ($foundA ? "YES (ERROR)" : "NO (CORRECT)") . "\n";
echo "Waterfall P0 result: Found in User B's candidates? " . ($foundB ? "YES (CORRECT)" : "NO (ERROR)") . "\n";

if ($foundA) {
    echo "FAIL: Lead appeared in User A's waterfall!\n";
    exit(1);
}
if (!$foundB) {
    echo "FAIL: Lead did NOT appear in User B's waterfall!\n";
    exit(1);
}

// Clean up test records
$link->query("DELETE FROM lead_reservations WHERE phone_number_10 = '$testPhone'");
$link->query("DELETE FROM " . tn('TBL_MAIN') . " WHERE ID = $leadId");

echo "=== ALL 90-DAY SKIP BACKEND TESTS PASSED! ===\n";
