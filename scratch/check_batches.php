<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
putenv('APP_DEBUG=1');
$_SERVER['APP_DEBUG'] = '1';
require_once __DIR__ . '/../php_scripts/config.php';

echo "=== LAST 10 LEAD BATCHES ===\n";
$b = $link->query("SELECT id, batch_uuid, telecaller_id, status, allocated_count, created_at, expires_at FROM lead_batches ORDER BY id DESC LIMIT 10");
while ($r = $b->fetch_assoc()) {
    print_r($r);
}

echo "=== ANY RESERVATIONS FOR 8971594009 ===\n";
$res = $link->query("SELECT * FROM lead_reservations WHERE phone_number_10 LIKE '%8971594009%'");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}

echo "=== RECENT RESERVATIONS FOR USER 8 ===\n";
$res8 = $link->query("SELECT id, batch_id, phone_number_10, state, reserved_at, dialing_started_at, expires_at, completed_at FROM lead_reservations WHERE telecaller_id = 8 ORDER BY id DESC LIMIT 10");
while ($r = $res8->fetch_assoc()) {
    print_r($r);
}
