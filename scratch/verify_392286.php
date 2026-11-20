<?php
require_once __DIR__ . '/../php_scripts/config.php';
$r = $link->query("SELECT ID, MAINDATABASE_MOBILE, MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_CALL_DIALED_USER, LAST_DIALED_DATE_TIME, CALL_COUNT FROM " . tn('TBL_MAIN') . " WHERE ID = 392286")->fetch_assoc();
print_r($r);
