<?php
// Temporary diagnostic — requires the real index.php and catches any fatal.
header('Content-Type: text/plain; charset=utf-8');
try {
    require 'index.php';
    echo "\nREQUIRE_INDEX_OK\n";
} catch (\Throwable $e) {
    echo "\nTHROWABLE: " . get_class($e) . ": " . $e->getMessage() . "\n  @ " . $e->getFile() . ":" . $e->getLine() . "\n";
}
