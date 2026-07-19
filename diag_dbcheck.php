<?php
header('Content-Type: text/plain; charset=utf-8');
require_once "config.php";
// Current connection
echo "CURRENT_DB=" . $link->query("SELECT DATABASE()")->fetch_row()[0] . "\n";
// Try the other candidate DB using same creds
foreach (['u661890306_callnow5', 'callnow_incredit'] as $db) {
    $t = @mysqli_connect($link->host_info ?: 'localhost', 'root', '', $db, 3306);
    if (!$t) { echo "$db: connect_fail (" . mysqli_connect_error() . ")\n"; continue; }
    $r = mysqli_query($t, "SHOW TABLES LIKE 'main_database'");
    $has = $r && mysqli_num_rows($r) ? 'HAS main_database' : 'NO main_database';
    $r2 = mysqli_query($t, "SHOW TABLES LIKE 'app_settings'");
    $has2 = $r2 && mysqli_num_rows($r2) ? 'HAS app_settings' : 'NO app_settings';
    echo "$db: $has | $has2\n";
    mysqli_close($t);
}
echo "DONE\n";
