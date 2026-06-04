<?php
require_once "../php_scripts/auth.php";

$total = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) AS c FROM MAIN_DATABASE"))['c'];
echo $total;
?>