<?php
header('Content-Type: text/plain; charset=utf-8');
require_once "config.php";
$tables = [];
$r = $link->query("SHOW TABLES");
if ($r) { while ($row = $r->fetch_row()) { $tables[] = $row[0]; } }
echo "TABLE_COUNT=" . count($tables) . "\n";
sort($tables);
echo implode("\n", $tables) . "\n";
echo "--- app_settings columns ---\n";
try {
    $c = $link->query("SHOW COLUMNS FROM app_settings");
    if ($c) { while ($x = $c->fetch_row()) echo $x[0] . " "; echo "\n"; } else { echo "MISSING\n"; }
} catch (\Throwable $e) { echo "ERR " . $e->getMessage() . "\n"; }
echo "--- users columns (first 5) ---\n";
try {
    $c = $link->query("SHOW COLUMNS FROM users");
    if ($c) { $i=0; while ($x = $c->fetch_row()) { echo $x[0]." "; if(++$i>=8) break; } echo "\n"; } else { echo "MISSING\n"; }
} catch (\Throwable $e) { echo "ERR " . $e->getMessage() . "\n"; }
echo "DONE\n";
