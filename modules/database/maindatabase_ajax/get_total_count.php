<?php
require_once "../../../php_scripts/auth.php";

if (!isAdmin()) {
    http_response_code(403);
    echo '0';
    exit;
}

$total = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) AS c FROM MAIN_DATABASE"))['c'];
echo $total;
?>
