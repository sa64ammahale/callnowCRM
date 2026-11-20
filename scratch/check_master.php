<?php
$link = mysqli_connect('localhost', 'root', '', 'callnow_master');
$res = $link->query("SELECT * FROM callnow_telecalling_companys");
while ($row = $res->fetch_assoc()) {
    print_r($row);
}
