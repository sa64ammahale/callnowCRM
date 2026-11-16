<?php
require_once __DIR__ . '/../php_scripts/config.php';

echo "Testing user insert and update bind params...\n";

// 1. Test INSERT
$hashed = password_hash('Pass@123', PASSWORD_DEFAULT);
$sql = "INSERT INTO " . tn('TBL_USERS') . " (NAME, MOBILE, LOGIN_ID, EMAIL, COMPANY_NAME, PASSWORD, ROLE, TEAM_ID, supervisor_id, manager_id, admin_id, PACKAGE, STATUS, COMPANY, daily_calling_limit, monthly_disbursement_limit, JOIN_DATE)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
$params = ['Test Officer Bind', '9811122233', 'test_bind@callnow.com', 'test_bind@callnow.com', 'Test Corp', $hashed, 'Officer', null, null, null, null, '', 'Active', 'CallNow', 250, 1500000.00];
$types = "sssssssiiiisssid";

$stmt = mysqli_prepare($link, $sql);
if (!$stmt) {
    die("INSERT prepare failed: " . mysqli_error($link) . "\n");
}
mysqli_stmt_bind_param($stmt, $types, ...$params);

if (!mysqli_stmt_execute($stmt)) {
    die("INSERT execute failed: " . mysqli_stmt_error($stmt) . "\n");
}

$newId = mysqli_insert_id($link);
echo "[PASS] Inserted user ID $newId successfully!\n";
mysqli_stmt_close($stmt);

// 2. Test UPDATE without password
$updateSql = "UPDATE " . tn('TBL_USERS') . " SET NAME=?, MOBILE=?, LOGIN_ID=?, EMAIL=?, COMPANY_NAME=?, ROLE=?, TEAM_ID=?, supervisor_id=COALESCE(?, supervisor_id), manager_id=?, admin_id=?, PACKAGE=?, STATUS=?, COMPANY=?, daily_calling_limit=?, monthly_disbursement_limit=? WHERE ID=?";
$updateParams = ['Test Officer Bind Updated', '9811122233', 'test_bind@callnow.com', 'test_bind@callnow.com', 'Test Corp', 'Officer', null, null, null, null, '', 'Active', 'CallNow', 300, 2000000.00, $newId];
$updateTypes = "ssssssiiiisssid" . "i";

$uStmt = mysqli_prepare($link, $updateSql);
if (!$uStmt) {
    die("UPDATE prepare failed: " . mysqli_error($link) . "\n");
}
mysqli_stmt_bind_param($uStmt, $updateTypes, ...$updateParams);

if (!mysqli_stmt_execute($uStmt)) {
    die("UPDATE execute failed: " . mysqli_stmt_error($uStmt) . "\n");
}
echo "[PASS] Updated user without password successfully!\n";
mysqli_stmt_close($uStmt);

// 3. Test UPDATE with password
$updateSqlPwd = "UPDATE " . tn('TBL_USERS') . " SET NAME=?, MOBILE=?, LOGIN_ID=?, EMAIL=?, COMPANY_NAME=?, ROLE=?, TEAM_ID=?, supervisor_id=COALESCE(?, supervisor_id), manager_id=?, admin_id=?, PACKAGE=?, STATUS=?, COMPANY=?, daily_calling_limit=?, monthly_disbursement_limit=?, PASSWORD=? WHERE ID=?";
$updateParamsPwd = ['Test Officer Bind Updated 2', '9811122233', 'test_bind@callnow.com', 'test_bind@callnow.com', 'Test Corp', 'Officer', null, null, null, null, '', 'Active', 'CallNow', 350, 2500000.00, password_hash('NewPass@123', PASSWORD_DEFAULT), $newId];
$updateTypesPwd = "ssssssiiiisssid" . "s" . "i";

$uStmtPwd = mysqli_prepare($link, $updateSqlPwd);
if (!$uStmtPwd) {
    die("UPDATE with password prepare failed: " . mysqli_error($link) . "\n");
}
mysqli_stmt_bind_param($uStmtPwd, $updateTypesPwd, ...$updateParamsPwd);

if (!mysqli_stmt_execute($uStmtPwd)) {
    die("UPDATE with password execute failed: " . mysqli_stmt_error($uStmtPwd) . "\n");
}
echo "[PASS] Updated user with password successfully!\n";
mysqli_stmt_close($uStmtPwd);

// Cleanup
mysqli_query($link, "DELETE FROM " . tn('TBL_USERS') . " WHERE ID = " . (int)$newId);
echo "[CLEANUP] Deleted test user successfully!\n";
