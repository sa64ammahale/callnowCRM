<?php
/**
 * Render single page in isolated simulated web process
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$targetFile = $argv[1] ?? '';
$targetRole = $argv[2] ?? 'Super Admin';
$targetUserId = (int)($argv[3] ?? 1);

if (!$targetFile || !file_exists($targetFile)) {
    echo "ERROR: File not found: $targetFile";
    exit(1);
}

// Emulate Web Server Environment
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Setup Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['loggedin'] = true;
$_SESSION['id'] = $targetUserId;
$_SESSION['user_id'] = $targetUserId;
$_SESSION['role'] = $targetRole;
$_SESSION['LAST_ACTIVITY'] = time();

// Change directory to the target file's directory (crucial for relative includes)
chdir(dirname($targetFile));

ob_start();
try {
    include $targetFile;
    $out = ob_get_clean();
    if (strpos($out, 'Fatal error') !== false || strpos($out, 'Parse error') !== false) {
        echo "ERROR: " . substr(strip_tags($out), 0, 300);
        exit(1);
    }
    echo "OK (" . strlen($out) . " bytes)";
    exit(0);
} catch (Throwable $e) {
    ob_end_clean();
    echo "EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
    exit(1);
}

