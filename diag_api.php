<?php
require_once 'config.php';

echo "=== USER ===\n";
$r = $link->query("SELECT ID, NAME, EMAIL, ROLE, STATUS FROM users WHERE EMAIL='sa64ammahale@gmail.com'");
$u = $r->fetch_assoc();
print_r($u);

echo "\n=== manage_api permission rows ===\n";
$r = $link->query("SELECT * FROM permissions WHERE permission_key='manage_api'");
echo "rows: " . $r->num_rows . "\n";
print_r($r->fetch_assoc());

echo "\n=== role_permissions for manage_api (Officer/Manager/Admin) ===\n";
$r = $link->query("SELECT * FROM role_permissions WHERE permission_key='manage_api'");
while ($x = $r->fetch_assoc()) print_r($x);

echo "\n=== api_settings table structure ===\n";
$r = $link->query("SHOW COLUMNS FROM api_settings");
while ($x = $r->fetch_assoc()) echo $x['Field'] . " " . $x['Type'] . " " . $x['Key'] . "\n";

echo "\n=== api_tokens table structure ===\n";
$r = $link->query("SHOW COLUMNS FROM api_tokens");
while ($x = $r->fetch_assoc()) echo $x['Field'] . " " . $x['Type'] . " " . $x['Key'] . "\n";

echo "\n=== api_database_assignments table structure ===\n";
$r = $link->query("SHOW COLUMNS FROM api_database_assignments");
while ($x = $r->fetch_assoc()) echo $x['Field'] . " " . $x['Type'] . " " . $x['Key'] . "\n";

echo "\n=== api_access_logs table structure ===\n";
$r = $link->query("SHOW COLUMNS FROM api_access_logs");
while ($x = $r->fetch_assoc()) echo $x['Field'] . " " . $x['Type'] . " " . $x['Key'] . "\n";
?>