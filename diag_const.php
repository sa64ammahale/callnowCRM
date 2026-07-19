<?php
header('Content-Type: text/plain; charset=utf-8');
require_once "config.php";
foreach (['TBL_MAIN','TBL_USERS','TBL_LEADS','TBL_APP_SETTINGS','TBL_ROLE_PERMISSIONS','TBL_USER_PERMISSIONS','TBL_TEAMS'] as $c) {
    echo "$c = " . (defined($c) ? var_export(constant($c), true) : 'UNDEFINED') . "\n";
}
echo "DONE\n";
