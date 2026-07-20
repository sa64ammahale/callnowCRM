<?php
/**
 * UNIVERSAL 500 CATCHER — upload to your CallNow5 root, visit, get the REAL error.
 * Catches everything including fatal errors and displays them.
 */
// ── Catch ALL errors ─────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

set_error_handler(function ($severity, $msg, $file, $line) {
    echo "[ERROR_HANDLER] $msg in $file:$line\n\n";
    return true;
});

set_exception_handler(function ($e) {
    echo "[EXCEPTION_HANDLER] " . $e->getMessage() . "\n  in " . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n\n";
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "[FATAL_SHUTDOWN] {$err['message']} in {$err['file']}:{$err['line']}\n\n";
    }
});

echo "=== 500 DIAGNOSTIC ===\n\n";

// ── Step 1: config.php ─────────────────────────────────────────
echo "--- Step 1: Loading config.php ---\n";
try {
    require_once __DIR__ . '/php_scripts/config.php';
    echo "OK: config.php loaded\n\n";
} catch (\Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n\n";
    exit;
}

// ── Step 2: TBL constants ──────────────────────────────────────
echo "--- Step 2: TBL_* constants ---\n";
$expected = [
    'TBL_USERS'=>'users', 'TBL_MAIN'=>'main_database', 'TBL_LEADS'=>'leads_table',
    'TBL_APP_SETTINGS'=>'app_settings', 'TBL_ROLE_PERMISSIONS'=>'role_permissions',
    'TBL_USER_PERMISSIONS'=>'user_permissions', 'TBL_TEAMS'=>'teams', 'TBL_PERMISSIONS'=>'permissions'
];
foreach ($expected as $const => $expect) {
    if (defined($const)) {
        $v = constant($const);
        $status = $v === $expect ? 'OK' : ("WRONG_VALUE='" . $v . "'");
    } else {
        $status = 'UNDEFINED';
    }
    echo "  $const => $status\n";
}

// ── Step 3: tn() function ───────────────────────────────────────
echo "\n--- Step 3: tn() function ---\n";
if (function_exists('tn')) {
    echo "  tn('TBL_USERS') => " . tn('TBL_USERS') . "\n";
    echo "  tn('TBL_MAIN')  => " . tn('TBL_MAIN') . "\n";
    echo "  tn('FAKE')      => " . tn('FAKE') . "\n";
} else {
    echo "  tn() DOES NOT EXIST — FATAL!\n";
}

// ── Step 4: Database connection ────────────────────────────────
echo "\n--- Step 4: Database ---\n";
if (isset($link) && $link !== false) {
    echo "  DB connected: yes\n";
    try {
        $r = mysqli_query($link, "SELECT COUNT(*) c FROM " . tn('TBL_USERS'));
        if ($r) {
            $row = mysqli_fetch_assoc($r);
            echo "  Query TBL_USERS => " . ($row['c'] ?? '?') . " rows\n";
        } else {
            echo "  Query TBL_USERS => FAIL: " . mysqli_error($link) . "\n";
        }
    } catch (\Throwable $e) {
        echo "  Query THREW: " . $e->getMessage() . "\n";
    }
} else {
    echo "  DB connection: FAIL\n";
}

// ── Step 5: Auth load test ──────────────────────────────────────
echo "\n--- Step 5: Loading auth.php (session required) ---\n";
// Start a session if not started, with a test user id to simulate login
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$hadSession = isset($_SESSION['id']);
echo "  Session active: " . (session_status() === PHP_SESSION_ACTIVE ? 'yes' : 'no') . "\n";
echo "  User logged in: " . (isset($_SESSION['id']) ? "yes (id={$_SESSION['id']})" : 'no') . "\n";

try {
    require_once __DIR__ . '/php_scripts/auth.php';
    echo "  auth.php loaded: OK\n";
    echo "  USER_ID: " . (defined('USER_ID') ? constant('USER_ID') : 'N/A') . "\n";
} catch (\Throwable $e) {
    echo "  auth.php FAIL: " . $e->getMessage() . "\n";
}

echo "\n=== DIAGNOSTIC COMPLETE ===\n";
