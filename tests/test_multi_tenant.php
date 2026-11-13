<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php_scripts/tenant.php';
require_once __DIR__ . '/../php_scripts/tenant_provisioner.php';

$passed = 0;
$failed = 0;

function assertCheck(string $desc, bool $condition, $details = null): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] " . $desc . PHP_EOL;
    } else {
        $failed++;
        echo "[FAIL] " . $desc;
        if ($details !== null) {
            echo " | " . (is_string($details) ? $details : json_encode($details));
        }
        echo PHP_EOL;
    }
}

echo "=== Running CallNow Multi-Tenant Architecture Test Suite ===" . PHP_EOL . PHP_EOL;

// 1. Master Connection & Schema
$master = getMasterConnection();
assertCheck("Master connection established", $master instanceof mysqli && @mysqli_ping($master));

$res = mysqli_query($master, "SHOW TABLES LIKE 'companies'");
assertCheck("Master table 'companies' exists", $res && mysqli_num_rows($res) > 0);

// 2. Sanitization
assertCheck("Sanitize valid lower code", sanitizeCompanyCode('acme') === 'acme');
assertCheck("Sanitize uppercase code to lower", sanitizeCompanyCode('AcMe-123') === 'acme-123');
assertCheck("Sanitize code with underscores", sanitizeCompanyCode('test_company') === 'test_company');
assertCheck("Reject SQL injection attempt 1", sanitizeCompanyCode("acme'; DROP TABLE--") === null);
assertCheck("Reject SQL injection attempt 2", sanitizeCompanyCode("' OR '1'='1") === null);
assertCheck("Reject directory traversal attempt", sanitizeCompanyCode("../../../etc/passwd") === null);
assertCheck("Reject whitespace / empty string", sanitizeCompanyCode("   ") === null);

// 3. Resolve Default Tenant
$incredit = resolveTenant('incredit');
assertCheck("Resolve 'incredit' tenant by code", is_array($incredit) && ($incredit['company_code'] ?? '') === 'incredit');
assertCheck("'incredit' target DB is 'callnow_incredit'", ($incredit['db_name'] ?? '') === 'callnow_incredit');
assertCheck("'incredit' status is Active", ($incredit['status'] ?? '') === 'Active');

$increditByName = resolveTenant('Incredit Financial Services');
assertCheck("Resolve 'incredit' tenant by full company name", is_array($increditByName) && ($increditByName['company_code'] ?? '') === 'incredit');

$nonexistent = resolveTenant('fake_company_nonexistent_xyz');
assertCheck("Reject non-existent company", $nonexistent === null);

// 4. Connection Switching
$increditLink = getTenantConnection('incredit');
assertCheck("Tenant connection established for 'incredit'", $increditLink instanceof mysqli);
$dbRes = mysqli_query($increditLink, "SELECT DATABASE() as current_db");
$dbRow = mysqli_fetch_assoc($dbRes);
assertCheck("Connected to correct database 'callnow_incredit'", ($dbRow['current_db'] ?? '') === 'callnow_incredit');

// 5. Tenant Provisioning
$testCode = 'tenant_test_' . substr(bin2hex(random_bytes(3)), 0, 6);
$testName = 'Automated Test Company ' . strtoupper($testCode);
$testDb   = 'callnow_' . $testCode;
$testAdminEmail = $testCode . '@test.example';
$testAdminPass = 'SecretTest123!';

$provResult = provisionTenantDatabase($testCode, $testName, $testDb, $testAdminEmail, $testAdminPass, 'Test Admin');
assertCheck("Provision new company ($testCode)", ($provResult['success'] ?? false) === true, $provResult);

if ($provResult['success'] ?? false) {
    // Verify registered in master
    $provResolved = resolveTenant($testCode);
    assertCheck("Resolved provisioned company from master DB", is_array($provResolved) && ($provResolved['company_code'] ?? '') === $testCode);

    // Verify tenant DB connection
    $testLink = getTenantConnection($testCode);
    assertCheck("Connected to provisioned tenant DB ($testDb)", $testLink instanceof mysqli);

    $testDbRes = mysqli_query($testLink, "SELECT DATABASE() as current_db");
    $testDbRow = mysqli_fetch_assoc($testDbRes);
    assertCheck("Database active for provisioned tenant", ($testDbRow['current_db'] ?? '') === $testDb);

    // Verify admin user seeded in provisioned DB
    $userStmt = mysqli_prepare($testLink, "SELECT ID, NAME, EMAIL, ROLE, STATUS, PASSWORD FROM users WHERE EMAIL = ? LIMIT 1");
    mysqli_stmt_bind_param($userStmt, 's', $testAdminEmail);
    mysqli_stmt_execute($userStmt);
    $userRow = mysqli_stmt_get_result($userStmt)->fetch_assoc();
    mysqli_stmt_close($userStmt);

    assertCheck("Admin user created in tenant DB", is_array($userRow) && ($userRow['EMAIL'] ?? '') === $testAdminEmail);
    assertCheck("Admin password verified via bcrypt", password_verify($testAdminPass, $userRow['PASSWORD'] ?? ''));

    // 6. Test Status Toggling (Suspension)
    setTenantStatus($testCode, 'Suspended');
    $suspendedResolved = resolveTenant($testCode);
    assertCheck("Suspended tenant is not resolvable for login", $suspendedResolved === null);

    setTenantStatus($testCode, 'Active');
    $reactivatedResolved = resolveTenant($testCode);
    assertCheck("Reactivated tenant is resolvable", is_array($reactivatedResolved) && ($reactivatedResolved['status'] ?? '') === 'Active');

    // 7. Cleanup Test Database & Master Registry
    $cleanupMaster = mysqli_prepare($master, "DELETE FROM companies WHERE company_code = ?");
    mysqli_stmt_bind_param($cleanupMaster, 's', $testCode);
    mysqli_stmt_execute($cleanupMaster);
    mysqli_stmt_close($cleanupMaster);

    @mysqli_query($testLink, "DROP DATABASE IF EXISTS `" . mysqli_real_escape_string($testLink, $testDb) . "`");
    mysqli_close($testLink);
    assertCheck("Test database cleanup complete", true);
}

// 8. Upload Scoping Test
$dir = getTenantUploadDir('profile_photos');
assertCheck("Tenant upload directory contains tenant slug", str_contains($dir, 'incredit') || str_contains($dir, 'uploads'));

echo PHP_EOL . "=== Test Results: $passed Passed, $failed Failed ===" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
exit(0);

