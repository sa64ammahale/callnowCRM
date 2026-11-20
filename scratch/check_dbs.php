<?php
$link = mysqli_connect('localhost', 'root', '', '');
$res = $link->query('SHOW DATABASES');
while ($row = $res->fetch_array()) {
    echo $row[0] . "\n";
}
