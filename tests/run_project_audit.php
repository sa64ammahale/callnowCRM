<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach ([8099, 8100] as $port) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
    if ($socket) { fclose($socket); throw new RuntimeException('Audit port ' . $port . ' is already occupied'); }
}
require_once __DIR__ . '/../php_scripts/config.php';
$sourceDb = $DB_NAME;
$auditDb = 'callnow_audit_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
if (!$link->query('CREATE DATABASE `' . $auditDb . '` CHARACTER SET utf8mb4')) throw new RuntimeException($link->error);
$tables = $link->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetch_all(MYSQLI_NUM);
$link->query('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $row) {
    $table = str_replace('`', '``', $row[0]);
    foreach (['CREATE TABLE `' . $auditDb . '`.`' . $table . '` LIKE `' . $sourceDb . '`.`' . $table . '`', 'INSERT INTO `' . $auditDb . '`.`' . $table . '` SELECT * FROM `' . $sourceDb . '`.`' . $table . '`'] as $sql) {
        if (!$link->query($sql)) throw new RuntimeException($link->error);
    }
}
$link->query('SET FOREIGN_KEY_CHECKS = 1');
putenv('DB_NAME=' . $auditDb);
putenv('TEST_API_BASE=http://127.0.0.1:8099/callnowCRM/api/v1');
putenv('APP_URL=http://127.0.0.1:8099/callnowCRM');
putenv('APP_DEBUG=1');
$root = dirname(__DIR__);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8099', '-t', $root, __DIR__ . '/audit_router.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/' . $auditDb . '-server.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/' . $auditDb . '-server.log', 'a']], $serverPipes, $root);
$results = [];
$serverTwo = proc_open([PHP_BINARY, '-S', '127.0.0.1:8100', '-t', $root, __DIR__ . '/audit_router.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/' . $auditDb . '-server2.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/' . $auditDb . '-server2.log', 'a']], $serverTwoPipes, $root);
try {
    for ($i = 0; $i < 30; $i++) {
        $socket = @fsockopen('127.0.0.1', 8099, $errno, $errstr, 0.1);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    $scripts = getenv('AUDIT_TESTS') ? explode(',', getenv('AUDIT_TESTS')) : array_merge(['run_all_pages_audit.php'], array_map('basename', glob(__DIR__ . '/test_*.php')));
    foreach ($scripts as $script) {
        $proc = proc_open([PHP_BINARY, __DIR__ . '/' . $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($proc);
        $results[$script] = ['exit' => $exit, 'output' => $out, 'errors' => $err, 'passed' => $exit === 0 && !preg_match('/\[FAIL\]|Fatal error|Parse error|Warning:|SKIP/', $out . $err)];
        echo "===== $script (exit $exit) =====\n$out$err\n";
    }
} finally {
    proc_terminate($server); proc_close($server);
    proc_terminate($serverTwo); proc_close($serverTwo);
    $report = sys_get_temp_dir() . '/' . $auditDb . '-results.json';
    file_put_contents($report, json_encode(['database' => $auditDb, 'results' => $results], JSON_PRETTY_PRINT));
    echo 'Audit database retained: ' . $auditDb . PHP_EOL . 'Report: ' . $report . PHP_EOL;
}
exit(count(array_filter($results, static fn($result) => !$result['passed'])) ? 1 : 0);
