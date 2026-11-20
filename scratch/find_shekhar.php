<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
putenv('APP_DEBUG=1');
$_SERVER['APP_DEBUG'] = '1';
require_once __DIR__ . '/../php_scripts/config.php';

$res = $link->query("SELECT * FROM " . tn('TBL_USERS') . " WHERE NAME LIKE '%shekhar%'");
while ($r = $res->fetch_assoc()) {
    print_r($r);
}

$res2 = $link->query("SELECT * FROM " . tn('TBL_USERS') . " WHERE EMAIL LIKE '%shekhar%' OR USERNAME LIKE '%shekhar%'");
if ($res2) {
    while ($r = $res2->fetch_assoc()) {
        print_r($r);
    }
}
