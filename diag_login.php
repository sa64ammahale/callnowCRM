<?php
header('Content-Type: text/plain; charset=utf-8');
// Simulate a logged-in user using the first user in the DB
require_once "config.php";
session_start();
$r = $link->query("SELECT ID, NAME, ROLE, TEAM_ID FROM TBL_USERS LIMIT 1");
if (!$r || !($u = $r->fetch_assoc())) { echo "NO USER FOUND\n"; exit; }
$_SESSION['loggedin'] = true;
$_SESSION['id'] = (int)$u['ID'];
$_SESSION['name'] = $u['NAME'];
$_SESSION['role'] = $u['ROLE'];
$_SESSION['team_id'] = $u['TEAM_ID'] ?? null;
$_SESSION['LAST_ACTIVITY'] = time();

echo "SIM_USER id=" . $u['ID'] . " role=" . $u['ROLE'] . "\n";
try {
    require_once "php_scripts/auth.php";
    echo "auth.php OK\n";
    echo "USER_ROLE=" . USER_ROLE . " USER_ID=" . USER_ID . "\n";
    // Test a can() call
    echo "can(manage_leads)=" . (can('manage_leads') ? '1' : '0') . "\n";
    echo "AUTH_FLOW_OK\n";
} catch (\Throwable $e) {
    echo "AUTH_THROW: " . get_class($e) . ": " . $e->getMessage() . "\n  @ " . $e->getFile() . ":" . $e->getLine() . "\n";
}
echo "DONE\n";
