<?php
// Hit this once after deploying to forcibly flush opcache on Hostinger.
if (function_exists('opcache_reset')) {
    $r = opcache_reset();
    echo "opcache_reset() => " . var_export($r, true) . "\n";
} else {
    echo "opcache not loaded\n";
}
// Touch config to force recompile
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__DIR__ . '/php_scripts/config.php', true);
    echo "invalidated config.php\n";
}
echo "OK\n";
