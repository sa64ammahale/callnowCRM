<?php
// Temporary diagnostic — delete after use.
header('Content-Type: text/plain; charset=utf-8');
echo "PHP=" . phpversion() . " SAPI=" . php_sapi_name() . "\n";
echo "display_errors=" . ini_get('display_errors') . " error_reporting=" . ini_get('error_reporting') . "\n";
try {
    require_once __DIR__ . '/php_scripts/config.php';
    echo "CONFIG_OK\n";
    echo "APP_BASE=" . (defined('APP_BASE') ? APP_BASE : 'n/a') . "\n";
    echo "LINK=" . (isset($link) ? gettype($link) : 'unset') . "\n";
    if (isset($link) && is_object($link)) {
        $r = $link->query("SELECT COUNT(*) c FROM users");
        echo "users=" . ($r ? mysqli_fetch_assoc($r)['c'] : 'QUERY_FAIL:' . $link->error) . "\n";
    }
} catch (\Throwable $e) {
    echo "THROWABLE: " . get_class($e) . ": " . $e->getMessage() . "\n  @ " . $e->getFile() . ":" . $e->getLine() . "\n";
}
echo "DIAG_DONE\n";
