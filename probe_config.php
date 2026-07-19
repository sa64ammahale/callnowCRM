<?php
// Temporary probe — delete after diagnosing.
// Loads only the config chain to isolate parse/exec errors there.
require_once __DIR__ . '/php_scripts/config.php';
echo "CONFIG_LOADED_OK\n";
echo "APP_BASE=" . (defined('APP_BASE') ? APP_BASE : 'undefined') . "\n";
echo "LINK_TYPE=" . gettype($link) . "\n";
if (is_object($link)) {
    $r = $link->query("SELECT 1");
    echo "DB_QUERY_OK=" . ($r ? 'yes' : 'no: ' . $link->error) . "\n";
}
