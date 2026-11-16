<?php

require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/phone_helper.php';

echo "======================================================\n";
echo "  PHONE NORMALIZATION & +91 DETECTION TEST SUITE      \n";
echo "======================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assertCondition(bool $cond, string $msg): void {
    global $testsPassed, $testsFailed;
    if ($cond) {
        echo "  [PASS] $msg\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] $msg\n";
        $testsFailed++;
    }
}

echo "--- 1. Unit Normalization Tests ---\n";
assertCondition(normalize_indian_phone('9876543210') === '9876543210', "Plain 10-digit number preserves as 9876543210");
assertCondition(normalize_indian_phone('+919876543210') === '9876543210', "+91 prefix strips to 9876543210");
assertCondition(normalize_indian_phone('+91 98765 43210') === '9876543210', "+91 with spaces strips to 9876543210");
assertCondition(normalize_indian_phone('+91-98765-43210') === '9876543210', "+91 with dashes strips to 9876543210");
assertCondition(normalize_indian_phone('919876543210') === '9876543210', "12-digit 91 prefix strips to 9876543210");
assertCondition(normalize_indian_phone('09876543210') === '9876543210', "Trunk zero 09876543210 strips to 9876543210");
assertCondition(normalize_indian_phone('(0) 98765 43210') === '9876543210', "Parentheses (0) format strips to 9876543210");

echo "\n--- 2. Invalid Number Rejection Tests ---\n";
assertCondition(normalize_indian_phone('1234567890') === null, "Number starting with 1 rejected");
assertCondition(normalize_indian_phone('+91 55555 55555') === null, "Number starting with 5 rejected");
assertCondition(normalize_indian_phone('98765') === null, "Short number rejected");
assertCondition(normalize_indian_phone('+1 415 555 2671') === null, "US number rejected for Indian mobile series");
assertCondition(normalize_indian_phone('') === null, "Empty string returns null");
assertCondition(normalize_indian_phone(null) === null, "Null input returns null");

echo "\n--- 3. Formatting Helper Tests ---\n";
assertCondition(format_indian_phone('9876543210', 'e164') === '+919876543210', "E.164 format returns +919876543210");
assertCondition(format_indian_phone('9876543210', 'display') === '+91 98765 43210', "Display format returns +91 98765 43210");
assertCondition(format_indian_phone('9876543210', 'dial_uri') === 'tel:+919876543210', "Dial URI format returns tel:+919876543210");
assertCondition(format_indian_phone('9876543210', 'national') === '09876543210', "National format returns 09876543210");

echo "\n--- 4. Database Ingestion & Deduplication Integration ---\n";
$testNum = '9876599999';
$rawInputWithCode = '+91 98765 99999';
$rawInputWithZero = '09876599999';

// Cleanup any leftover test row
mysqli_query($link, "DELETE FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = '$testNum'");

// Simulate normalized insertion via single add logic
$norm1 = normalize_indian_phone($rawInputWithCode);
assertCondition($norm1 === $testNum, "Raw input with +91 normalizes to $testNum");

$ins = mysqli_prepare($link, "INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_CALL_DIAL_TIME) VALUES (?, ?, NOW())");
$testName = "Test Country Code Contact";
mysqli_stmt_bind_param($ins, "ss", $testName, $norm1);
$insRes = mysqli_stmt_execute($ins);
mysqli_stmt_close($ins);
assertCondition($insRes === true, "Inserted test record into main_database with normalized 10 digits");

// Verify stored value in DB is exactly 10 digits
$q = mysqli_query($link, "SELECT MAINDATABASE_MOBILE FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = '$testNum' LIMIT 1");
$row = mysqli_fetch_assoc($q);
assertCondition(($row['MAINDATABASE_MOBILE'] ?? '') === $testNum, "Stored number in database is exactly 10 digits without prefix");

// Second attempt with 0-prefix must detect duplicate
$norm2 = normalize_indian_phone($rawInputWithZero);
$chkDup = mysqli_prepare($link, "SELECT ID FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = ? LIMIT 1");
mysqli_stmt_bind_param($chkDup, "s", $norm2);
mysqli_stmt_execute($chkDup);
mysqli_stmt_store_result($chkDup);
$isDup = (mysqli_stmt_num_rows($chkDup) > 0);
mysqli_stmt_close($chkDup);
assertCondition($isDup === true, "Second upload with '09876599999' correctly identified duplicate against existing '9876599999'");

// Cleanup
mysqli_query($link, "DELETE FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = '$testNum'");
echo "  [CLEANUP] Deleted test records.\n";

echo "\n======================================================\n";
echo "  TEST SUMMARY: $testsPassed PASSED, $testsFailed FAILED\n";
echo "======================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
