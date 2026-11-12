<?php
/**
 * Test production_schema.sql integrity
 */
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$port = (int)(getenv('DB_PORT') ?: 3306);

$dbName = 'callnow_schema_test_' . substr(md5(uniqid('', true)), 0, 8);

$link = @mysqli_connect($host, $user, $pass, '', $port);
if (!$link) {
    echo "[FAIL] Cannot connect to MySQL server at $host:$port\n";
    exit(1);
}

$schemaFile = __DIR__ . '/../database/production_schema.sql';
if (!file_exists($schemaFile)) {
    echo "[FAIL] Schema file missing: database/production_schema.sql\n";
    exit(1);
}

$passed = 0;
$failed = 0;

function check(string $desc, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $desc\n";
        $passed++;
    } else {
        echo "[FAIL] $desc\n";
        $failed++;
    }
}

try {
    $link->query("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $link->select_db($dbName);

    $sql = file_get_contents($schemaFile);
    $link->multi_query($sql);
    do {
        if ($res = $link->store_result()) $res->free();
    } while ($link->more_results() && $link->next_result());

    if ($link->errno) {
        echo "[FAIL] SQL import error: " . $link->error . "\n";
        $failed++;
    } else {
        check("Schema imported without SQL error", true);
    }

    // 1. Verify tables count
    $tRes = $link->query("SHOW TABLES");
    $tables = [];
    while ($row = $tRes->fetch_row()) $tables[] = $row[0];
    check("At least 25 tables created (actual: " . count($tables) . ")", count($tables) >= 25);

    $requiredTables = [
        'users', 'roles', 'permissions', 'role_permissions', 'user_permissions', 'teams',
        'main_database', 'main_database_archive', 'temporary_database',
        'leads_table', 'lead_notes', 'lead_followups', 'lead_assignments', 'contact_lead_conversions',
        'call_logs', 'reporting_targets', 'activity_log', 'enquiry',
        'api_tokens', 'api_settings', 'api_access_logs', 'app_settings',
        'super_admin_plans', 'super_admin_account', 'super_admin_limit_logs',
        'user_page_filters', 'password_reset_tokens'
    ];

    foreach ($requiredTables as $rt) {
        check("Table '$rt' exists", in_array($rt, $tables, true));
    }

    // 2. Verify Super Admin user & password
    $uRes = $link->query("SELECT * FROM `users` WHERE `LOGIN_ID` = 'admin@callnow.com'");
    $uRow = $uRes ? $uRes->fetch_assoc() : null;
    check("Super Admin user exists", $uRow !== null);
    check("Super Admin role is 'Super Admin'", ($uRow['ROLE'] ?? '') === 'Super Admin');
    check("Super Admin password verifies against 'Admin@CallNow2026!'", password_verify('Admin@CallNow2026!', $uRow['PASSWORD'] ?? ''));

    // 3. Verify permissions keys have NO macro errors
    $pRes = $link->query("SELECT permission_key FROM `permissions` WHERE permission_key LIKE '%TBL%'");
    check("No permission keys contain 'TBL' macro errors", $pRes && $pRes->num_rows === 0);

    $mUsers = $link->query("SELECT id FROM `permissions` WHERE permission_key = 'manage_users'");
    check("Permission 'manage_users' exists", $mUsers && $mUsers->num_rows === 1);

    // 4. Verify leads_table columns
    $lCols = [];
    $cRes = $link->query("SHOW COLUMNS FROM `leads_table`");
    while ($c = $cRes->fetch_assoc()) $lCols[] = $c['Field'];
    foreach (['loan_type', 'login_bank_name', 'target_bank', 'net_salary', 'salary_account', 'priority', 'lead_stage', 'next_followup_at', 'lead_status_new'] as $f) {
        check("leads_table has column '$f'", in_array($f, $lCols, true));
    }

    // 5. Verify call_logs columns
    $clCols = [];
    $clRes = $link->query("SHOW COLUMNS FROM `call_logs`");
    while ($c = $clRes->fetch_assoc()) $clCols[] = $c['Field'];
    foreach (['timing_classification', 'metadata', 'next_followup', 'recording_url', 'sim_slot', 'client_call_id', 'request_hash'] as $f) {
        check("call_logs has column '$f'", in_array($f, $clCols, true));
    }

} finally {
    $link->query("DROP DATABASE IF EXISTS `$dbName`");
    $link->close();
}

echo "\nSummary: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);

