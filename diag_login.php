<?php
// Capture ALL errors fatals included, even outside try/catch.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "FATAL_SHUTDOWN type={$e['type']}: {$e['message']}\n  @ {$e['file']}:{$e['line']}\n";
    }
});
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false;
    header('Content-Type: text/plain; charset=utf-8');
    echo "PHP_ERROR $no: $str @ $file:$line\n";
    return false;
});
header('Content-Type: text/plain; charset=utf-8');

require_once "config.php";
session_start();
$r = $link->query("SELECT ID, NAME, ROLE, TEAM_ID FROM TBL_USERS LIMIT 1");
if (!$r || !($u = $r->fetch_assoc())) { echo "NO USER\n"; exit; }
$_SESSION['loggedin'] = true;
$_SESSION['id'] = (int)$u['ID'];
$_SESSION['name'] = $u['NAME'];
$_SESSION['role'] = $u['ROLE'];
$_SESSION['team_id'] = $u['TEAM_ID'] ?? null;
$_SESSION['LAST_ACTIVITY'] = time();

echo "SIM_USER id={$u['ID']} role={$u['ROLE']}\n";
try {
    require_once "php_scripts/auth.php";
    echo "auth.php OK | USER_ROLE=" . USER_ROLE . " USER_ID=" . USER_ID . "\n";
    echo "can(manage_leads)=" . (can('manage_leads') ? '1':'0') . "\n";
} catch (\Throwable $e) {
    echo "CAUGHT: " . get_class($e) . ": " . $e->getMessage() . "\n  @ " . $e->getFile() . ":" . $e->getLine() . "\n";
}
echo "DONE\n";
