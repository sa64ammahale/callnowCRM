<?php
require_once __DIR__ . '/../php_scripts/config.php';
$clQ = $link->prepare("SELECT id FROM " . tn('TBL_CALL_LOGS') . " 
    WHERE (phone_number = ? OR RIGHT(REGEXP_REPLACE(phone_number, '[^0-9]', ''), 10) = ?) 
      AND created_at >= NOW() - INTERVAL 12 HOUR 
    LIMIT 1");
if (!$clQ) {
    echo "PREPARE FAILED: " . $link->error . "\n";
} else {
    echo "PREPARE SUCCESS\n";
}
