<?php
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/lib/db_helper.php';
require_once __DIR__ . '/lib/http_helper.php';
require_once __DIR__ . '/lib/page_helper.php';

function assertTest(string $name, bool $cond) {
    echo $name . ': ' . ($cond ? "PASS" : "FAIL") . PHP_EOL;
    if (!$cond) {
        exit(1);
    }
}

// Phase 0: Environment validation
// Check DB connection is alive
assertTest('DB connection alive', $link !== false && $link->ping());
// Check essential tables exist
$tables = [
    'TBL_USERS',
    'TBL_MAIN',
    'TBL_TEMP',
    'TBL_TEAMS',
    'TBL_PERMISSIONS'
];
foreach ($tables as $tblConst) {
    $tblName = constant($tblConst);
    $exists = dbExists($tblName, '1=1');
    assertTest("Table $tblName exists", $exists);
}

// Further phases will be included here later.
?>
