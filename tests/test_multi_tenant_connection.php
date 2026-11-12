<?php
/**
 * Test Multi-Tenant Database Connection & Session Scoping
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

// 1. Create a mock tenant in config/tenants.local.php
$localConfig = __DIR__ . '/../config/tenants.local.php';
$testSlug = 'tenanttest_' . substr(md5(uniqid()), 0, 6);
$mockTenant = [
    $testSlug => [
        'company_name' => 'Automated Test Company',
        'db_host'      => getenv('DB_HOST') ?: '127.0.0.1',
        'db_port'      => (int)(getenv('DB_PORT') ?: 3306),
        'db_name'      => getenv('DB_NAME') ?: 'callnow_incredit',
        'db_user'      => getenv('DB_USER') ?: 'root',
        'db_pass'      => getenv('DB_PASS') ?: '',
        'active'       => true,
    ]
];

file_put_contents($localConfig, "<?php\nreturn " . var_export($mockTenant, true) . ";\n");

try {
    // 2. Test resolution with subdomain
    require_once __DIR__ . '/../php_scripts/tenant_manager.php';
    $tenant = resolveTenant($testSlug . '.increditsolutions.in');

    check("Mock tenant resolved from subdomain", $tenant['found'] === true);
    check("Tenant slug is '$testSlug'", $tenant['slug'] === $testSlug);
    check("Company name is 'Automated Test Company'", $tenant['company_name'] === 'Automated Test Company');

    // 3. Test session name generation
    $sessName = getTenantSessionName($tenant['slug'], $tenant['db_name']);
    check("Session name is prefixed with CALLNOW_", str_starts_with($sessName, 'CALLNOW_'));

    // 4. Test header-based tenant resolution (mobile app support)
    $_SERVER['HTTP_X_TENANT_CODE'] = $testSlug;
    $headerTenant = resolveTenant('api.increditsolutions.in');
    check("Tenant resolved via X-Tenant-Code header", $headerTenant['slug'] === $testSlug);
    unset($_SERVER['HTTP_X_TENANT_CODE']);

} finally {
    if (file_exists($localConfig)) {
        unlink($localConfig);
    }
}

echo "\nMulti-Tenant Connection Tests: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);

