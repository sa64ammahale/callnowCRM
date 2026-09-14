<?php
/**
 * Temporary Database Isolation Test
 *
 * Verifies that:
 * 1. Uploading to TBL_TEMP does NOT check TBL_MAIN for duplicates
 * 2. Re-uploading same data to TBL_TEMP detects duplicates within TBL_TEMP only
 * 3. Transferring from TBL_TEMP to TBL_MAIN checks TBL_MAIN and updates existing rows
 *
 * Usage: php temp_db_isolation_test.php
 */

require_once __DIR__ . '/php_scripts/config.php';

$testsPassed = 0;
$testsFailed = 0;
$testMobile = '9998887776';
$testName = 'Isolation Test User';
$testCompany = 'TestCo';
$testPackage = 'TestPkg';
$testOther = 'Isolation test data';
$errors = [];

function assert_true($condition, $message) {
    global $testsPassed, $testsFailed, $errors;
    if ($condition) {
        $testsPassed++;
        echo "  [PASS] $message\n";
    } else {
        $testsFailed++;
        $errors[] = $message;
        echo "  [FAIL] $message\n";
    }
}

function cleanup_temp($link, $mobile) {
    mysqli_query($link, "DELETE FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = '" . mysqli_real_escape_string($link, $mobile) . "'");
}

function cleanup_main($link, $mobile) {
    mysqli_query($link, "DELETE FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = '" . mysqli_real_escape_string($link, $mobile) . "'");
}

echo "=== Temporary Database Isolation Tests ===\n\n";

// --- Test 1: Temp upload does not check Main DB ---
echo "Test 1: Upload to TBL_TEMP ignores duplicates in TBL_MAIN\n";
cleanup_temp($link, $testMobile);
cleanup_main($link, $testMobile);

// Insert into Main DB
$mainInsert = mysqli_query($link, "INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_UPLOAD_DATETIME) VALUES ('$testName', '$testMobile', '$testCompany', '$testPackage', '$testOther', 'Not Called', NOW())");
assert_true($mainInsert, "Setup: Inserted test row into TBL_MAIN");

// Now simulate uploading the same mobile to TBL_TEMP
// This is what upload_data.php does for temporary target:
// INSERT INTO TBL_TEMP ... ON DUPLICATE KEY UPDATE ID = ID
$tempInsert = mysqli_query($link, "INSERT INTO " . tn('TBL_TEMP') . " (CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, CALL_DIALED_STATUS, TEMP_UPLOAD_DATETIME) VALUES ('$testName', '$testMobile', '$testCompany', '$testPackage', '$testOther', 'Not Called', NOW()) ON DUPLICATE KEY UPDATE ID = ID");

assert_true($tempInsert, "Setup: Attempted insert into TBL_TEMP with existing Main DB mobile");

// Verify row exists in TBL_TEMP (it should, because TBL_TEMP didn't have it)
$tempCount = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = '$testMobile'"))[0];
assert_true($tempCount == 1, "TBL_TEMP contains 1 row for mobile $testMobile (isolated from TBL_MAIN)");

// Verify TBL_MAIN is unchanged
$mainRow = mysqli_fetch_assoc(mysqli_query($link, "SELECT * FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = '$testMobile'"));
assert_true($mainRow !== null && $mainRow['MAINDATABASE_NAME'] === $testName, "TBL_MAIN row unchanged after temp upload");

cleanup_temp($link, $testMobile);
cleanup_main($link, $testMobile);
echo "\n";

// --- Test 2: Temp upload checks duplicates within Temp only ---
echo "Test 2: Re-uploading to TBL_TEMP detects duplicates within TBL_TEMP\n";
cleanup_temp($link, $testMobile);

// First upload to TBL_TEMP
$first = mysqli_query($link, "INSERT INTO " . tn('TBL_TEMP') . " (CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, CALL_DIALED_STATUS, TEMP_UPLOAD_DATETIME) VALUES ('$testName', '$testMobile', '$testCompany', '$testPackage', '$testOther', 'Not Called', NOW())");
assert_true($first, "Setup: First insert into TBL_TEMP succeeded");

// Second upload (duplicate within temp)
$second = mysqli_query($link, "INSERT INTO " . tn('TBL_TEMP') . " (CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, CALL_DIALED_STATUS, TEMP_UPLOAD_DATETIME) VALUES ('$testName', '$testMobile', '$testCompany', '$testPackage', '$testOther', 'Not Called', NOW()) ON DUPLICATE KEY UPDATE ID = ID");
assert_true($second, "Setup: Second insert into TBL_TEMP with duplicate mobile");

$tempCountAfterDup = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = '$testMobile'"))[0];
assert_true($tempCountAfterDup == 1, "TBL_TEMP still has 1 row after duplicate upload (no new row created)");

cleanup_temp($link, $testMobile);
echo "\n";

// --- Test 3: Transfer checks Main DB and updates existing rows ---
echo "Test 3: Transfer from TBL_TEMP to TBL_MAIN updates existing Main DB rows\n";
cleanup_temp($link, $testMobile);
cleanup_main($link, $testMobile);

// Insert into Main with original status
mysqli_query($link, "INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_UPLOAD_DATETIME) VALUES ('$testName', '$testMobile', '$testCompany', '$testPackage', '$testOther', 'Not Called', NOW())");

// Insert into Temp with updated status (simulating edited test data)
mysqli_query($link, "INSERT INTO " . tn('TBL_TEMP') . " (CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD, TEMP_UPLOAD_DATETIME) VALUES ('$testName Updated', '$testMobile', '$testCompany', '$testPackage', '$testOther', 'Connected', '2024-01-01 10:00:00', '10:00', NOW())");

// Simulate transfer_selected behavior
$transferSql = "INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD, MAINDATABASE_UPLOAD_DATETIME)
                SELECT CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD, NOW()
                FROM " . tn('TBL_TEMP') . " AS td WHERE td.CUST_MOBILE = ?
                ON DUPLICATE KEY UPDATE
                    MAINDATABASE_NAME = VALUES(MAINDATABASE_NAME),
                    MAINDATABASE_COMPANY = VALUES(MAINDATABASE_COMPANY),
                    MAINDATABASE_PACKAGE = VALUES(MAINDATABASE_PACKAGE),
                    MAINDATABASE_OTHER_INFO = VALUES(MAINDATABASE_OTHER_INFO),
                    MAINDATABASE_CALL_DIALED_STATUS = VALUES(MAINDATABASE_CALL_DIALED_STATUS),
                    LAST_DIALED_DATE_TIME = VALUES(LAST_DIALED_DATE_TIME),
                    LAST_CONNECTED_PERIOD = VALUES(LAST_CONNECTED_PERIOD),
                    MAINDATABASE_UPLOAD_DATETIME = VALUES(MAINDATABASE_UPLOAD_DATETIME)";
$stmt = mysqli_prepare($link, $transferSql);
mysqli_stmt_bind_param($stmt, "s", $testMobile);
$transferOk = mysqli_stmt_execute($stmt);
assert_true($transferOk, "Transfer from TBL_TEMP to TBL_MAIN executed successfully");

$mainAfterTransfer = mysqli_fetch_assoc(mysqli_query($link, "SELECT * FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = '$testMobile'"));
assert_true($mainAfterTransfer !== null, "TBL_MAIN row still exists after transfer");
assert_true($mainAfterTransfer['MAINDATABASE_NAME'] === 'Isolation Test User Updated', "TBL_MAIN name updated from temp data: " . $mainAfterTransfer['MAINDATABASE_NAME']);
assert_true($mainAfterTransfer['MAINDATABASE_CALL_DIALED_STATUS'] === 'Connected', "TBL_MAIN status updated from temp data: " . $mainAfterTransfer['MAINDATABASE_CALL_DIALED_STATUS']);

// Verify TBL_TEMP still has the row (transfer_selected does not delete)
$tempAfterTransfer = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = '$testMobile'"))[0];
assert_true($tempAfterTransfer == 1, "TBL_TEMP row still exists after transfer_selected (cleanup is manual)");

cleanup_temp($link, $testMobile);
cleanup_main($link, $testMobile);
echo "\n";

// --- Summary ---
echo "=== Test Summary ===\n";
echo "Passed: $testsPassed\n";
echo "Failed: $testsFailed\n";
if ($errors) {
    echo "Failed tests:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
    exit(1);
}
echo "All isolation tests passed.\n";
exit(0);
