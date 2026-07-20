<?php
/**
 * 500 DIAGNOSTIC V2 — incremental flush, NO auth.php include,
 * manually tests the critical queries. Always shows output.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

set_error_handler(function ($s, $m, $f, $l) { echo "[ERR] $m in $f:$l\n"; return true; });
set_exception_handler(function ($e) { echo "[EXC] {$e->getMessage()}\n  {$e->getFile()}:{$e->getLine()}\n{$e->getTraceAsString()}\n"; });
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) echo "[FATAL] {$e['message']} in {$e['file']}:{$e['line']}\n";
});

header('Content-Type: text/plain; charset=utf-8');

echo "=== CallNow 500 DIAGNOSTIC V2 ===\n\n";

// ── Step 1: config.php ──
echo "--- Step 1: Load config.php ---\n";
try {
    require_once __DIR__ . '/php_scripts/config.php';
    echo "OK\n\n";
} catch (\Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n\n";
    exit;
}

// ── Step 2: TBL_* constants ──
echo "--- Step 2: TBL_* constants ---\n";
$checks = ['TBL_USERS'=>'users','TBL_MAIN'=>'main_database','TBL_LEADS'=>'leads_table','TBL_APP_SETTINGS'=>'app_settings','TBL_ROLE_PERMISSIONS'=>'role_permissions','TBL_USER_PERMISSIONS'=>'user_permissions','TBL_TEAMS'=>'teams','TBL_PERMISSIONS'=>'permissions'];
foreach ($checks as $c=>$e) {
    $v = defined($c) ? constant($c) : null;
    echo "  $c => " . ($v === null ? 'UNDEFINED' : ($v === $e ? "OK ($v)" : "WRONG ('$v' expected '$e')")) . "\n";
}

// ── Step 3: tn() ──
echo "\n--- Step 3: tn() function ---\n";
if (function_exists('tn')) {
    echo "  tn() exists\n";
    echo "  tn('TBL_USERS') => " . tn('TBL_USERS') . "\n";
    echo "  tn('TBL_MAIN')  => " . tn('TBL_MAIN') . "\n";
} else {
    echo "  tn() => DOES NOT EXIST\n";
}

// ── Step 4: DB ──
echo "\n--- Step 4: Database queries ---\n";
if (!$link) { echo "  DB FAIL\n"; exit; }
echo "  DB connected OK\n";

// Test 4a: users table
echo "\n  Test 4a: SELECT from users...\n";
$tbl = function_exists('tn') ? tn('TBL_USERS') : (defined('TBL_USERS') ? constant('TBL_USERS') : 'users');
try {
    $r = mysqli_query($link, "SELECT COUNT(*) c FROM $tbl");
    echo $r ? "  => " . mysqli_fetch_assoc($r)['c'] . " rows\n" : "  => FAIL: " . mysqli_error($link) . "\n";
} catch (\Throwable $e) { echo "  => THREW: " . $e->getMessage() . "\n"; }

// Test 4b: role_permissions
echo "\n  Test 4b: SELECT from role_permissions...\n";
$tbl2 = function_exists('tn') ? tn('TBL_ROLE_PERMISSIONS') : (defined('TBL_ROLE_PERMISSIONS') ? constant('TBL_ROLE_PERMISSIONS') : 'role_permissions');
try {
    $r = mysqli_query($link, "SELECT COUNT(*) c FROM $tbl2");
    echo $r ? "  => " . mysqli_fetch_assoc($r)['c'] . " rows\n" : "  => FAIL: " . mysqli_error($link) . "\n";
} catch (\Throwable $e) { echo "  => THREW: " . $e->getMessage() . "\n"; }

// Test 4c: user_permissions
echo "\n  Test 4c: SELECT from user_permissions...\n";
$tbl3 = function_exists('tn') ? tn('TBL_USER_PERMISSIONS') : (defined('TBL_USER_PERMISSIONS') ? constant('TBL_USER_PERMISSIONS') : 'user_permissions');
try {
    $r = mysqli_query($link, "SELECT COUNT(*) c FROM $tbl3");
    echo $r ? "  => " . mysqli_fetch_assoc($r)['c'] . " rows\n" : "  => FAIL: " . mysqli_error($link) . "\n";
} catch (\Throwable $e) { echo "  => THREW: " . $e->getMessage() . "\n"; }

// Test 4d: app_settings (used by auth.php)
echo "\n  Test 4d: SELECT from app_settings...\n";
$tbl4 = function_exists('tn') ? tn('TBL_APP_SETTINGS') : (defined('TBL_APP_SETTINGS') ? constant('TBL_APP_SETTINGS') : 'app_settings');
try {
    $r = mysqli_query($link, "SELECT COUNT(*) c FROM $tbl4");
    echo $r ? "  => " . mysqli_fetch_assoc($r)['c'] . " rows\n" : "  => FAIL: " . mysqli_error($link) . "\n";
} catch (\Throwable $e) { echo "  => THREW: " . $e->getMessage() . "\n"; }

// Test 4e: teams
echo "\n  Test 4e: SELECT from teams...\n";
$tbl5 = function_exists('tn') ? tn('TBL_TEAMS') : (defined('TBL_TEAMS') ? constant('TBL_TEAMS') : 'teams');
try {
    $r = mysqli_query($link, "SELECT COUNT(*) c FROM $tbl5");
    echo $r ? "  => " . mysqli_fetch_assoc($r)['c'] . " rows\n" : "  => FAIL: " . mysqli_error($link) . "\n";
} catch (\Throwable $e) { echo "  => THREW: " . $e->getMessage() . "\n"; }

// Test 4f: permissions
echo "\n  Test 4f: SELECT from permissions...\n";
$tbl6 = function_exists('tn') ? tn('TBL_PERMISSIONS') : (defined('TBL_PERMISSIONS') ? constant('TBL_PERMISSIONS') : 'permissions');
try {
    $r = mysqli_query($link, "SELECT COUNT(*) c FROM $tbl6");
    echo $r ? "  => " . mysqli_fetch_assoc($r)['c'] . " rows\n" : "  => FAIL: " . mysqli_error($link) . "\n";
} catch (\Throwable $e) { echo "  => THREW: " . $e->getMessage() . "\n"; }

// Test 4g: main_database (used by dashboard.php)
echo "\n  Test 4g: SELECT COUNT(*) FROM main_database...\n";
try {
    $r = mysqli_query($link, "SELECT COUNT(*) c FROM main_database");
    echo $r ? "  => " . mysqli_fetch_assoc($r)['c'] . " rows\n" : "  => FAIL: " . mysqli_error($link) . "\n";
} catch (\Throwable $e) { echo "  => THREW: " . $e->getMessage() . "\n"; }

// Test 4h: EXACT dashboard query (checks if columns exist)
echo "\n  Test 4h: EXACT dashboard.php query (calls today)...\n";
try {
    $r = mysqli_query($link, "SELECT COUNT(*) as total_calls, SUM(CASE WHEN MAINDATABASE_CALL_DIALED_STATUS = 'Connected' THEN 1 ELSE 0 END) as connected FROM main_database WHERE DATE(MAINDATABASE_CALL_DIAL_TIME) = CURDATE()");
    if ($r) { $row = mysqli_fetch_assoc($r); echo "  => total_calls={$row['total_calls']} connected={$row['connected']}\n"; }
    else { echo "  => FAIL: " . mysqli_error($link) . "\n"; }
} catch (\Throwable $e) { echo "  => THREW: " . $e->getMessage() . "\n"; }

// Test 4i: Dashboard active users query
echo "\n  Test 4i: EXACT dashboard.php active users query...\n";
try {
    $r = mysqli_query($link, "SELECT COUNT(DISTINCT MAINDATABASE_CALL_DIALED_USER) as TBL_USERS FROM main_database WHERE DATE(MAINDATABASE_CALL_DIAL_TIME) = CURDATE() AND MAINDATABASE_CALL_DIALED_USER IS NOT NULL");
    if ($r) { $row = mysqli_fetch_assoc($r); echo "  => count={$row['TBL_USERS']}\n"; }
    else { echo "  => FAIL: " . mysqli_error($link) . "\n"; }
} catch (\Throwable $e) { echo "  => THREW: " . $e->getMessage() . "\n"; }

// Test 4j: leads_table (used by leads module)
echo "\n  Test 4j: SELECT COUNT(*) FROM leads_table...\n";
try {
    $r = mysqli_query($link, "SELECT COUNT(*) c FROM leads_table");
    echo $r ? "  => " . mysqli_fetch_assoc($r)['c'] . " rows\n" : "  => FAIL: " . mysqli_error($link) . "\n";
} catch (\Throwable $e) { echo "  => THREW: " . $e->getMessage() . "\n"; }

// ── Step 5: Session ──
echo "\n--- Step 5: Session ---\n";
@session_start();
echo "  Session active: " . (session_status() === PHP_SESSION_ACTIVE ? 'yes' : 'no') . "\n";
echo "  \$_SESSION['loggedin']: " . (isset($_SESSION['loggedin']) ? ($_SESSION['loggedin'] ? 'true' : 'false') : 'NOT SET') . "\n";
echo "  \$_SESSION['id']: " . ($_SESSION['id'] ?? 'NOT SET') . "\n";
echo "  \$_SESSION['csrf_token']: " . (isset($_SESSION['csrf_token']) ? 'SET' : 'NOT SET') . "\n";

// ── Step 6: Simulate the EXACT auth.php queries ──
echo "\n--- Step 6: Simulate auth.php queries ---\n";
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && isset($_SESSION['id'])) {
    $uid = (int)$_SESSION['id'];
    $tblU = function_exists('tn') ? tn('TBL_USERS') : (defined('TBL_USERS') ? constant('TBL_USERS') : 'users');
    try {
        $stmt = mysqli_prepare($link, "SELECT ID, NAME, ROLE, TEAM_ID FROM $tblU WHERE ID = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $uid);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($res);
            if ($user) {
                echo "  User found: ID={$user['ID']} NAME={$user['NAME']} ROLE={$user['ROLE']} TEAM_ID={$user['TEAM_ID']}\n";
                
                // Test RBAC query (what loadEffectivePermissions does)
                $tblRP = function_exists('tn') ? tn('TBL_ROLE_PERMISSIONS') : (defined('TBL_ROLE_PERMISSIONS') ? constant('TBL_ROLE_PERMISSIONS') : 'role_permissions');
                $stmt2 = mysqli_prepare($link, "SELECT permission_key, permission_value FROM $tblRP WHERE role = ?");
                if ($stmt2) {
                    $role = $user['ROLE'];
                    mysqli_stmt_bind_param($stmt2, 's', $role);
                    mysqli_stmt_execute($stmt2);
                    echo "  RBAC query OK for role '$role'\n";
                    mysqli_stmt_close($stmt2);
                } else {
                    echo "  RBAC prepare FAIL: " . mysqli_error($link) . "\n";
                }
                
                $tblUP = function_exists('tn') ? tn('TBL_USER_PERMISSIONS') : (defined('TBL_USER_PERMISSIONS') ? constant('TBL_USER_PERMISSIONS') : 'user_permissions');
                $stmt3 = mysqli_prepare($link, "SELECT permission_key, permission_value FROM $tblUP WHERE user_id = ?");
                if ($stmt3) {
                    mysqli_stmt_bind_param($stmt3, 'i', $uid);
                    mysqli_stmt_execute($stmt3);
                    echo "  User permissions query OK\n";
                    mysqli_stmt_close($stmt3);
                } else {
                    echo "  User perms prepare FAIL: " . mysqli_error($link) . "\n";
                }
            } else {
                echo "  User NOT FOUND in DB (id=$uid) — causes NULL CURRENT_USER → 500\n";
            }
        } else {
            echo "  User query prepare FAIL: " . mysqli_error($link) . "\n";
        }
    } catch (\Throwable $e) {
        echo "  auth.php simulation THREW: " . $e->getMessage() . "\n";
    }
} else {
    echo "  Not logged in in this session.\n";
    echo "  ==> Log in FIRST in the SAME browser tab, then visit this URL again.\n";
}

echo "\n=== DIAGNOSTIC COMPLETE ===\n";
