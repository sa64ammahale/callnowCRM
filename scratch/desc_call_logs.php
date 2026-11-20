<?php
require_once __DIR__ . '/../php_scripts/config.php';
$res = $link->query('DESCRIBE ' . tn('TBL_CALL_LOGS'));
while ($r = $res->fetch_assoc()) {
    echo $r['Field'] . ' (' . $r['Type'] . ")\n";
}
