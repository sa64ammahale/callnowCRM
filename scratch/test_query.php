<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
putenv('APP_DEBUG=1');
$_SERVER['APP_DEBUG'] = '1';
require_once __DIR__ . '/../php_scripts/config.php';

$res = $link->query("
    SELECT m.ID, m.MAINDATABASE_MOBILE, RIGHT(REGEXP_REPLACE(m.MAINDATABASE_MOBILE, '[^0-9]', ''), 10) as clean_phone
    FROM " . tn('TBL_MAIN') . " m 
    WHERE m.ID = 392286
");
if (!$res) {
    echo "Query Error: " . $link->error . "\n";
} else {
    print_r($res->fetch_assoc());
}
