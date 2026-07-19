<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

define('DIAG_MODE', true);

function diag_log($msg) {
    echo '<pre style="background:#111;color:#0f0;padding:8px;margin:4px 0;white-space:pre-wrap;">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</pre>';
}

echo '<h3>1. bootstrap config + vnd()</h3>';
try {
    require_once __DIR__ . '/php_scripts/config.php';
    diag_log('config.php loaded. APP_BASE=' . (defined('APP_BASE') ? APP_BASE : 'UNDEFINED') .
        ' | vnd exists=' . (function_exists('vnd') ? 'yes' : 'NO') .
        ' | url exists=' . (function_exists('url') ? 'yes' : 'NO'));
} catch (\Throwable $e) {
    diag_log('config.php FATAL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo '<h3>2. header.php include</h3>';
try {
    $pageTitle = 'DIAG';
    include __DIR__ . '/php_scripts/header.php';
    diag_log('header.php included OK');
} catch (\Throwable $e) {
    diag_log('header.php FATAL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo '<h3>3. sidebar.php include</h3>';
try {
    include __DIR__ . '/php_scripts/sidebar.php';
    diag_log('sidebar.php included OK');
} catch (\Throwable $e) {
    diag_log('sidebar.php FATAL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo '<h3>4. topbar.php include</h3>';
try {
    include __DIR__ . '/php_scripts/topbar.php';
    diag_log('topbar.php included OK');
} catch (\Throwable $e) {
    diag_log('topbar.php FATAL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo '<h3>5. footer.php include</h3>';
try {
    include __DIR__ . '/php_scripts/footer.php';
    diag_log('footer.php included OK');
} catch (\Throwable $e) {
    diag_log('footer.php FATAL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo '<h3>6. asset reachability</h3>';
$assets = [
    vnd('css/bootstrap.min.css'),
    vnd('css/bootstrap-icons.css'),
    vnd('css/fonts/bootstrap-icons.woff2'),
    vnd('js/jquery.min.js'),
    vnd('js/bootstrap.bundle.min.js'),
    vnd('dt/datatables.min.css'),
    vnd('dt/datatables.min.js'),
    'assets/css/app-theme.css',
];
foreach ($assets as $a) {
    $u = (defined('APP_BASE') ? APP_BASE : '') . ltrim($a, '/');
    $code = '?';
    $curl = curl_init($u);
    curl_setopt_array($curl, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
    curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    diag_log(($code >= 200 && $code < 400 ? 'OK ' : 'FAIL ') . $code . '  ' . $u);
}

echo '<h3>DIAG DONE</h3>';
