<?php
/**
 * Test script for ERP database and schema health check
 */
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/super_admin.php';

ensureSuperAdminSchema($link);

echo "=== CHECKING CRM DATABASE TABLES ===" . PHP_EOL;
$tables = [
    'TBL_USERS', 'TBL_ROLES', 'TBL_PERMISSIONS', 'TBL_ROLE_PERMISSIONS',
    'TBL_USER_PERMISSIONS', 'TBL_TEAMS', 'TBL_LEADS', 'TBL_LEAD_NOTES',
    'TBL_LEAD_FOLLOWUPS', 'TBL_LEAD_ASSIGNMENTS', 'TBL_TEMP', 'TBL_MAIN',
    'TBL_CALL_LOGS', 'TBL_ACTIVITY_LOG', 'TBL_APP_SETTINGS', 'TBL_API_TOKENS',
    'TBL_API_SETTINGS', 'TBL_API_DB_ASSIGNMENTS', 'TBL_API_ACCESS_LOGS',
    'TBL_SUPER_ADMIN_PLANS', 'TBL_SUPER_ADMIN_ACCOUNT', 'TBL_SUPER_ADMIN_LIMIT_LOGS'
];

$missingTables = [];
foreach ($tables as $t) {
    $name = tn($t);
    $q = $link->query("SHOW TABLES LIKE '" . $link->real_escape_string($name) . "'");
    if ($q && $q->num_rows > 0) {
        $countRow = $link->query("SELECT COUNT(*) FROM `$name`");
        $cnt = $countRow ? $countRow->fetch_row()[0] : 'err';
        echo sprintf("  [OK] %-25s -> %-25s (%s rows)\n", $t, $name, $cnt);
    } else {
        echo sprintf("  [MISSING] %-25s -> %-25s\n", $t, $name);
        $missingTables[] = $t;
    }
}

if (empty($missingTables)) {
    echo "All 22 core database tables exist!" . PHP_EOL;
} else {
    echo "Warning: Missing tables: " . implode(', ', $missingTables) . PHP_EOL;
}
