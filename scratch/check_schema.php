<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
putenv('APP_DEBUG=1');
$_SERVER['APP_DEBUG'] = '1';
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/lead_distribution_engine.php';

ensureLeadEngineSchema($link);

$tables = ['lead_batches', 'lead_reservations', 'call_logs', tn('TBL_MAIN'), tn('TBL_TEMP')];
foreach ($tables as $t) {
    $res = $link->query("SELECT COUNT(*) as cnt FROM $t");
    if ($res) {
        $row = $res->fetch_assoc();
        echo "Table $t: " . $row['cnt'] . " rows\n";
    } else {
        echo "Table $t: ERROR - " . $link->error . "\n";
    }
}
