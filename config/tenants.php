<?php
/**
 * CallNow CRM — Multi-Tenant Configuration Registry
 * Maps company subdomains / hostnames to independent MySQL database credentials.
 *
 * To add custom local/production tenants without committing secrets to git,
 * create `config/tenants.local.php` (which is git-ignored).
 */

$tenants = [
    // Default tenant (used for main domain, localhost, or single-tenant installs)
    'default' => [
        'company_name' => getenv('APP_NAME') ?: 'CallNow CRM',
        'db_host'      => getenv('DB_SERVERNAME') ?: (getenv('DB_HOST') ?: 'localhost'),
        'db_port'      => (int)(getenv('DB_PORT') ?: 3306),
        'db_name'      => getenv('DB_NAME') ?: 'callnow_incredit',
        'db_user'      => getenv('DB_USERNAME') ?: (getenv('DB_USER') ?: 'root'),
        'db_pass'      => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''),
        'active'       => true,
    ],

    // Example Tenant: Acme Capital
    // 'acme' => [
    //     'company_name' => 'Acme Capital Services',
    //     'db_host'      => 'localhost',
    //     'db_port'      => 3306,
    //     'db_name'      => 'callnow_acme',
    //     'db_user'      => 'acme_db_user',
    //     'db_pass'      => 'SecurePassword!',
    //     'active'       => true,
    // ],
];

// Merge local overrides if present
if (file_exists(__DIR__ . '/tenants.local.php')) {
    $local = include __DIR__ . '/tenants.local.php';
    if (is_array($local)) {
        $tenants = array_merge($tenants, $local);
    }
}

return $tenants;

