<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
putenv('APP_DEBUG=1');
$_SERVER['APP_DEBUG'] = '1';
require_once __DIR__ . '/../php_scripts/config.php';

$res = $link->query("SELECT ID, NAME, ROLE, MOBILE, EMAIL, UPDATED_AT, LAST_LOGIN FROM " . tn('TBL_USERS') . " WHERE UPDATED_AT >= '2026-11-20' OR ID IN (4, 5, 7, 8, 11, 12, 15, 9011, 9015, 9016, 9040)");
while ($r = $res->fetch_assoc()) {
    echo "ID: {$r['ID']}, Name: {$r['NAME']}, Role: {$r['ROLE']}, Mobile: {$r['MOBILE']}, UpdatedAt: {$r['UPDATED_AT']}\n";
}
