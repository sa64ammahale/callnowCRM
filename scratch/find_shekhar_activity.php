<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
putenv('APP_DEBUG=1');
$_SERVER['APP_DEBUG'] = '1';
require_once __DIR__ . '/../php_scripts/config.php';

echo "=== ALL BATCHES FOR USER 8 ===\n";
$b = $link->query("SELECT * FROM lead_batches WHERE telecaller_id = 8 ORDER BY id DESC LIMIT 10");
while ($r = $b->fetch_assoc()) {
    print_r($r);
}

echo "=== ALL RESERVATIONS FOR USER 8 ===\n";
$res = $link->query("SELECT * FROM lead_reservations WHERE telecaller_id = 8 ORDER BY id DESC LIMIT 15");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}

echo "=== ALL CALL LOGS FOR USER 8 ===\n";
$cl = $link->query("SELECT * FROM " . tn('TBL_CALL_LOGS') . " WHERE user_id = 8 ORDER BY id DESC LIMIT 10");
while ($r = $cl->fetch_assoc()) {
    print_r($r);
}

echo "=== ALL ACTIVITY LOGS FOR USER 8 ===\n";
$al = $link->query("SELECT * FROM " . tn('TBL_ACTIVITY_LOG') . " WHERE user_id = 8 ORDER BY id DESC LIMIT 10");
if ($al) {
    while ($r = $al->fetch_assoc()) {
        print_r($r);
    }
}
