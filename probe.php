<?php
// Temporary probe — delete after diagnosing.
echo "PHP_VERSION=" . phpversion() . "\n";
echo "SAPI=" . php_sapi_name() . "\n";
echo "display_errors=" . ini_get('display_errors') . "\n";
echo "OK_PROBE_REACHED\n";
