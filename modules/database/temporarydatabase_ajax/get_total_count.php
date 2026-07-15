<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../../../config.php';

if (!isAdmin()) {
    http_response_code(403);
    echo '0';
    exit;
}

$total = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) AS c FROM temporary_database"))['c'];
echo $total;
?>