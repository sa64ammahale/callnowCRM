<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
putenv('APP_DEBUG=1');
$_SERVER['APP_DEBUG'] = '1';
require_once __DIR__ . '/../php_scripts/config.php';

echo "--- CHECKING USER shekhar auti ---\n";
$u = $link->query("SELECT ID, USER_ID, NAME, ROLE, STATUS FROM " . tn('TBL_USERS') . " WHERE NAME LIKE '%shekhar%' OR USER_ID LIKE '%shekhar%'");
if (!$u) {
    echo "Error: " . $link->error . "\n";
} else {
    while ($row = $u->fetch_assoc()) {
        print_r($row);
    }
}

echo "--- CHECKING NUMBER 8971594009 IN MAIN ---\n";
$m = $link->query("SELECT ID, MAINDATABASE_MOBILE, MAINDATABASE_NAME, MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_CALL_DIALED_USER, MAINDATABASE_CALL_DIAL_TIME, LAST_DIALED_DATE_TIME, CALL_COUNT, BATCH_ASSIGNED_AT FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE LIKE '%8971594009%'");
while ($row = $m->fetch_assoc()) {
    print_r($row);
}

echo "--- CHECKING NUMBER 8971594009 IN TEMP ---\n";
$t = $link->query("SELECT ID, CUST_MOBILE, CUST_NAME, CALL_DIALED_STATUS, CALL_DIALED_TELECALLER, LAST_DIALED_DATE_TIME, CALL_COUNT, BATCH_ASSIGNED_AT FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE LIKE '%8971594009%'");
while ($row = $t->fetch_assoc()) {
    print_r($row);
}

echo "--- CHECKING CALL_LOGS FOR 8971594009 ---\n";
$c = $link->query("SELECT * FROM " . tn('TBL_CALL_LOGS') . " WHERE mobile_number LIKE '%8971594009%'");
while ($row = $c->fetch_assoc()) {
    print_r($row);
}

echo "--- CHECKING LEAD_RESERVATIONS FOR 8971594009 ---\n";
$r = $link->query("SELECT * FROM lead_reservations WHERE phone_number_10 LIKE '%8971594009%'");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        print_r($row);
    }
}

echo "--- CHECKING RECENT LEAD_BATCHES ---\n";
$b = $link->query("SELECT * FROM lead_batches ORDER BY id DESC LIMIT 5");
if ($b) {
    while ($row = $b->fetch_assoc()) {
        print_r($row);
    }
}
