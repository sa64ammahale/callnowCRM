<?php
// Temporary diagnostic — delete after use.
header('Content-Type: text/plain; charset=utf-8');
try {
    require_once "config.php";

    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    echo "STEP session_start OK\n";
    $sp = session_save_path();
    echo "session_save_path=" . ($sp ?: '(' . ini_get('session.save_path') . ')') . " writable=" . (is_writable($sp ?: ini_get('session.save_path')) ? 'yes' : 'NO') . "\n";

    ensureCsrfToken();
    echo "STEP ensureCsrfToken OK\n";

    $loginLogo = '';
    if (isset($link) && $link) {
        $logoRes = $link->query("SELECT setting_value FROM TBL_APP_SETTINGS WHERE setting_key = 'logo_path'");
        if ($logoRes && $row = $logoRes->fetch_assoc()) {
            $loginLogo = APP_BASE . '/' . ltrim($row['setting_value'], '/');
        }
    }
    echo "STEP logo query OK loginLogo=" . var_export($loginLogo, true) . "\n";

    echo "DIAG_DONE\n";
} catch (\Throwable $e) {
    echo "THROWABLE: " . get_class($e) . ": " . $e->getMessage() . "\n  @ " . $e->getFile() . ":" . $e->getLine() . "\n";
}
