<?php
/**
 * Test Tenant CLI Provisioning Tool
 */
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

$testSlug = 'provtest_' . substr(md5(uniqid()), 0, 6);
$testDb = 'callnow_' . $testSlug;
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$port = getenv('DB_PORT') ?: '3306';

$args = [
    PHP_BINARY,
    __DIR__ . '/../bin/provision_tenant.php',
    '--slug=' . $testSlug,
    '--name=Test Provisioned Co',
    '--dbname=' . $testDb,
    '--dbuser=' . $user,
    '--dbpass=' . $pass,
    '--dbhost=' . $host,
    '--dbport=' . $port,
];

$proc = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
$exitCode = proc_close($proc);

try {
    check("Provisioning CLI exited with 0", $exitCode === 0);

    // Verify database was created
    $link = @mysqli_connect($host, $user, $pass, $testDb, (int)$port);
    check("Target database was created and connects successfully", $link !== false);

    if ($link) {
        $tRes = $link->query("SHOW TABLES");
        check("Tables were imported into new database", $tRes && $tRes->num_rows >= 25);

        // Verify Super Admin
        $uRes = $link->query("SELECT * FROM users WHERE LOGIN_ID = 'admin@callnow.com'");
        $uRow = $uRes ? $uRes->fetch_assoc() : null;
        check("Super Admin user exists in provisioned DB", $uRow !== null);
        check("Super Admin password verifies against 'Admin@CallNow2026!'", password_verify('Admin@CallNow2026!', $uRow['PASSWORD'] ?? ''));
        $link->close();
    }

    // Verify config/tenants.local.php was updated
    $localFile = __DIR__ . '/../config/tenants.local.php';
    check("config/tenants.local.php exists", file_exists($localFile));
    if (file_exists($localFile)) {
        $localTenants = include $localFile;
        check("Tenant '$testSlug' exists in config/tenants.local.php", isset($localTenants[$testSlug]));
        check("Configured db_name matches '$testDb'", ($localTenants[$testSlug]['db_name'] ?? '') === $testDb);
    }

} finally {
    // Cleanup test database
    $cleanupLink = @mysqli_connect($host, $user, $pass, '', (int)$port);
    if ($cleanupLink) {
        $cleanupLink->query("DROP DATABASE IF EXISTS `$testDb`");
        $cleanupLink->close();
    }
    // Remove test slug from tenants.local.php
    $localFile = __DIR__ . '/../config/tenants.local.php';
    if (file_exists($localFile)) {
        $localTenants = include $localFile;
        if (is_array($localTenants)) {
            unset($localTenants[$testSlug]);
            if (empty($localTenants)) {
                unlink($localFile);
            } else {
                file_put_contents($localFile, "<?php\nreturn " . var_export($localTenants, true) . ";\n");
            }
        }
    }
}

echo "\nTenant Provisioning Tests: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
