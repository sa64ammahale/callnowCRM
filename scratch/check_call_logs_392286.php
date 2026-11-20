<?php
require_once __DIR__ . '/../php_scripts/config.php';
$res = $link->query("SELECT * FROM " . tn('TBL_CALL_LOGS') . " WHERE record_id = 392286 AND source = 'MAIN'");
echo "Call logs for record 392286: " . $res->num_rows . "\n";
while ($r = $res->fetch_assoc()) {
    print_r($r);
}

echo "=== ANY CALL LOGS TODAY (2026-11-20) ===\n";
$today = $link->query("SELECT id, record_id, source, user_id, call_status, call_duration, created_at, dial_time FROM " . tn('TBL_CALL_LOGS') . " WHERE created_at >= '2026-11-20' ORDER BY id DESC LIMIT 20");
echo "Calls logged today: " . $today->num_rows . "\n";
while ($r = $today->fetch_assoc()) {
    print_r($r);
}
