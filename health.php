<?php
// Temporary health probe — delete after diagnosing.
header('Content-Type: text/plain; charset=utf-8');
echo "PHP_VERSION=" . phpversion() . "\n";
echo "SAPI=" . php_sapi_name() . "\n";

// Load only the DB part of config without the full app
$dbHost = getenv('DB_HOST') ?: getenv('DB_SERVERNAME') ?: 'localhost';
$dbUser = getenv('DB_USERNAME') ?: 'root';
$dbPass = getenv('DB_PASSWORD') ?: '';
$dbName = getenv('DB_NAME') ?: 'callnow_incredit';
echo "DB_HOST=$dbHost DB_NAME=$dbName\n";

$link = @mysqli_connect($dbHost, $dbUser, $dbPass, $dbName, 3306);
if (!$link) {
    echo "DB_CONNECT_FAIL: " . mysqli_connect_error() . "\n";
} else {
    echo "DB_CONNECT_OK\n";
    $r = mysqli_query($link, "SELECT COUNT(*) AS c FROM users");
    if ($r) {
        $row = mysqli_fetch_assoc($r);
        echo "users_count=" . $row['c'] . "\n";
    } else {
        echo "users_query_fail: " . mysqli_error($link) . "\n";
    }
}
echo "HEALTH_OK\n";
