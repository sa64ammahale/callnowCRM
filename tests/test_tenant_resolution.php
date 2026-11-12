<?php
/**
 * Test Tenant Resolution & Subdomain Parsing
 */
require_once __DIR__ . '/../php_scripts/tenant_manager.php';

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

// 1. Subdomain extraction tests
check("Subdomain from acme.increditsolutions.in is 'acme'", extractSubdomain('acme.increditsolutions.in') === 'acme');
check("Subdomain from client-2.increditsolutions.in:8080 is 'client-2'", extractSubdomain('client-2.increditsolutions.in:8080') === 'client-2');
check("Main domain increditsolutions.in has no subdomain", extractSubdomain('increditsolutions.in') === '');
check("www.increditsolutions.in has no subdomain", extractSubdomain('www.increditsolutions.in') === '');
check("localhost has no subdomain", extractSubdomain('localhost') === '');
check("127.0.0.1 has no subdomain", extractSubdomain('127.0.0.1') === '');
check("Subdomain from zenith.callnowcrm.com is 'zenith'", extractSubdomain('zenith.callnowcrm.com') === 'zenith');

// Mock tenant registry
$mockTenants = [
    'acme' => [
        'company_name' => 'Acme Corp',
        'db_host'      => '127.0.0.1',
        'db_port'      => 3306,
        'db_name'      => 'callnow_acme',
        'db_user'      => 'acme_user',
        'db_pass'      => 'SecretAcme123!',
        'active'       => true,
    ],
    'suspended' => [
        'company_name' => 'Suspended Company',
        'db_host'      => '127.0.0.1',
        'db_port'      => 3306,
        'db_name'      => 'callnow_suspended',
        'db_user'      => 'susp_user',
        'db_pass'      => 'pass',
        'active'       => false,
    ],
    'default' => [
        'company_name' => 'CallNow CRM Default',
        'db_host'      => 'localhost',
        'db_port'      => 3306,
        'db_name'      => 'callnow_incredit',
        'db_user'      => 'root',
        'db_pass'      => '',
        'active'       => true,
    ],
];

// 2. Tenant resolution
$tAcme = resolveTenant('acme.increditsolutions.in', $mockTenants);
check("acme resolves to found = true", ($tAcme['found'] ?? false) === true);
check("acme resolves to active = true", ($tAcme['active'] ?? false) === true);
check("acme db_name is 'callnow_acme'", ($tAcme['db_name'] ?? '') === 'callnow_acme');
check("acme slug is 'acme'", ($tAcme['slug'] ?? '') === 'acme');

// Main domain falls back to default
$tDefault = resolveTenant('increditsolutions.in', $mockTenants);
check("increditsolutions.in resolves to default tenant", ($tDefault['slug'] ?? '') === 'default');
check("default tenant db_name is 'callnow_incredit'", ($tDefault['db_name'] ?? '') === 'callnow_incredit');

// Unknown subdomain returns found = false
$tUnknown = resolveTenant('unregistered.increditsolutions.in', $mockTenants);
check("unregistered subdomain returns found = false", ($tUnknown['found'] ?? true) === false);

// Suspended tenant returns active = false
$tSuspended = resolveTenant('suspended.increditsolutions.in', $mockTenants);
check("suspended subdomain returns active = false", ($tSuspended['active'] ?? true) === false);

// 3. Dynamic Session Name
$sessAcme = getTenantSessionName('acme', 'callnow_acme');
$sessDefault = getTenantSessionName('default', 'callnow_incredit');
check("Tenant session name starts with CALLNOW_", str_starts_with($sessAcme, 'CALLNOW_'));
check("Tenant session names are distinct across tenants", $sessAcme !== $sessDefault);
check("Tenant session name length is controlled (12-24 chars)", strlen($sessAcme) >= 12 && strlen($sessAcme) <= 24);

echo "\nTenant Resolution Tests: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);

