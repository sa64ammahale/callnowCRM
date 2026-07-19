<?php
header('Content-Type: text/plain; charset=utf-8');
require_once "config.php";
echo "CONNECTED_DB=" . $link->query("SELECT DATABASE()")->fetch_row()[0] . "\n";

function tryQ($link, $label, $q) {
    try {
        $r = $link->query($q);
        if ($r) { $row = $r->fetch_assoc(); echo "$label OK\n"; }
        else { echo "$label FAIL: " . $link->error . "\n"; }
    } catch (\Throwable $e) { echo "$label THROW: " . $e->getMessage() . "\n"; }
}

// Dashboard queries (from dashboard.php)
tryQ($link, "dash_today", "SELECT COUNT(*) c, SUM(CASE WHEN MAINDATABASE_CALL_DIALED_STATUS = 'Connected' THEN 1 ELSE 0 END) conn FROM TBL_MAIN WHERE DATE(MAINDATABASE_CALL_DIAL_TIME) = CURDATE()");
tryQ($link, "dash_total", "SELECT COUNT(*) c FROM TBL_MAIN");

// auth.php user lookup (simulate with id=1)
tryQ($link, "auth_user", "SELECT ID, NAME, ROLE, TEAM_ID FROM TBL_USERS WHERE ID = 1");

// RBAC queries
tryQ($link, "rbac_settings", "SELECT setting_value FROM TBL_APP_SETTINGS WHERE setting_key = 'rbac_cache_version'");
tryQ($link, "rbac_roleperms", "SELECT permission_key, permission_value FROM TBL_ROLE_PERMISSIONS WHERE role = 'Admin'");
tryQ($link, "rbac_userperms", "SELECT permission_key, permission_value FROM TBL_USER_PERMISSIONS WHERE user_id = 1");

// Full column lists
foreach (['users','main_database','leads_table'] as $t) {
    try {
        $c = $link->query("SHOW COLUMNS FROM `$t`");
        $cols=[]; while($x=$c->fetch_row()) $cols[]=$x[0];
        echo "$t_cols(" . count($cols) . ")=" . implode(",", $cols) . "\n";
    } catch (\Throwable $e) { echo "$t_cols THROW: " . $e->getMessage() . "\n"; }
}
echo "DONE\n";
