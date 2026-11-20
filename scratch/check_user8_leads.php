<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
putenv('APP_DEBUG=1');
$_SERVER['APP_DEBUG'] = '1';
require_once __DIR__ . '/../php_scripts/config.php';

$m = $link->query("SELECT ID, MAINDATABASE_MOBILE, MAINDATABASE_NAME, MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_CALL_DIALED_USER, BATCH_ASSIGNED_AT FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_CALL_DIALED_USER = 8 LIMIT 20");
echo "Rows in MAIN with user 8: " . $m->num_rows . "\n";
while ($r = $m->fetch_assoc()) {
    print_r($r);
}

$t = $link->query("SELECT ID, CUST_MOBILE, CUST_NAME, CALL_DIALED_STATUS, CALL_DIALED_TELECALLER, BATCH_ASSIGNED_AT FROM " . tn('TBL_TEMP') . " WHERE CALL_DIALED_TELECALLER = 8 LIMIT 20");
echo "Rows in TEMP with user 8: " . $t->num_rows . "\n";
while ($r = $t->fetch_assoc()) {
    print_r($r);
}

$l = $link->query("SELECT lead_id, cust_id, NAME, MOBILE, assigned_to FROM " . tn('TBL_LEADS') . " WHERE assigned_to = 8 LIMIT 20");
echo "Rows in LEADS with user 8: " . $l->num_rows . "\n";
while ($r = $l->fetch_assoc()) {
    print_r($r);
}
