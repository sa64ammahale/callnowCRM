<?php
require 'config.php';
$r = mysqli_query($link, 'DESCRIBE '.tn('TBL_USERS'));
while($row = mysqli_fetch_assoc($r)) { echo $row['Field'].':'.$row['Type']."\n"; }
