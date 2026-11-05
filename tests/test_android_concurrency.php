<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/lib/db_helper.php';
if (!str_starts_with($DB_NAME, 'callnow_audit_')) throw new RuntimeException('Run in isolated project audit');
ensureApiSchema($link);
$passed = $failed = 0;
function concurrencyCheck(string $name, bool $ok, $detail = null): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($ok ? '' : ' ' . json_encode($detail)) . PHP_EOL;
}
function parallelCalls(array $requests): array {
    $multi = curl_multi_init(); $handles = [];
    foreach ($requests as $i => $request) {
        [$path, $token, $payload] = $request;
        $curl = curl_init('http://127.0.0.1:' . ($i === 0 ? '8099' : '8100') . '/callnowCRM/api/v1' . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token]]);
        if ($payload !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload)]);
        curl_multi_add_handle($multi, $curl); $handles[] = $curl;
    }
    do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 1); } while ($running);
    $results = [];
    foreach ($handles as $curl) { $results[] = ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'json' => json_decode(curl_multi_getcontent($curl), true), 'error' => curl_error($curl)]; curl_multi_remove_handle($multi, $curl); curl_close($curl); }
    curl_multi_close($multi);
    return $results;
}
$tag = 'Concurrent-' . bin2hex(random_bytes(4));
$userIds = $tokens = [];
foreach ([0, 1] as $index) {
    dbExecute('INSERT INTO ' . tn('TBL_USERS') . ' (NAME, LOGIN_ID, PASSWORD, ROLE, STATUS) VALUES (?, ?, ?, ?, ?)', [$tag . $index, $tag . $index, password_hash($tag, PASSWORD_DEFAULT), 'Officer', 'Active']);
    $userIds[] = (int)$link->insert_id;
    $token = bin2hex(random_bytes(32));
    dbExecute('INSERT INTO ' . tn('TBL_API_TOKENS') . ' (user_id, token, name, is_active) VALUES (?, ?, ?, 1)', [$link->insert_id, $token, $tag]);
    $tokens[] = $token;
}
$link->query('CREATE TEMPORARY TABLE audit_main_status AS SELECT ID, MAINDATABASE_CALL_DIALED_STATUS FROM ' . tn('TBL_MAIN'));
$link->query('CREATE TEMPORARY TABLE audit_temp_status AS SELECT ID, CALL_DIALED_STATUS FROM ' . tn('TBL_TEMP'));
try {
    $link->query("UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_CALL_DIALED_STATUS = 'Audit Hold'");
    $link->query("UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_STATUS = 'Audit Hold'");
    $fixtureIds = [];
    foreach ([0, 1, 2, 3] as $i) {
        dbExecute('INSERT INTO ' . tn('TBL_TEMP') . " (CUST_NAME, CUST_MOBILE, CALL_DIALED_STATUS, TEMP_UPLOAD_DATETIME) VALUES (?, ?, 'Not Called', NOW())", [$tag, '96' . random_int(10000000, 99999999)]);
        $fixtureIds[] = (int)$link->insert_id;
    }
    $results = parallelCalls([['/telecaller/batch-leads?limit=2', $tokens[0], null], ['/telecaller/batch-leads?limit=2', $tokens[1], null]]);
    concurrencyCheck('Two Android workers receive successful batch responses', $results[0]['status'] === 200 && $results[1]['status'] === 200, $results);
    $first = array_column($results[0]['json']['data']['leads'] ?? [], 'id');
    $second = array_column($results[1]['json']['data']['leads'] ?? [], 'id');
    concurrencyCheck('Concurrent batches contain two distinct records each', count($first) === 2 && count($second) === 2 && count(array_intersect($first, $second)) === 0, $results);
    dbExecute('INSERT INTO ' . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_CALL_DIALED_USER, CALL_COUNT, MAINDATABASE_UPLOAD_DATETIME) VALUES (?, ?, ?, 0, NOW())", [$tag, '95' . random_int(10000000, 99999999), $userIds[0]]);
    $id = (int)$link->insert_id;
    $payload = ['record_id' => $id, 'source' => 'MAIN', 'call_status' => 'Busy', 'call_duration' => 0, 'client_call_id' => $tag];
    $results = parallelCalls([['/telecaller/submit_call', $tokens[0], $payload], ['/telecaller/submit_call', $tokens[0], $payload]]);
    concurrencyCheck('Simultaneous offline retries return same saved log', $results[0]['status'] === 200 && $results[1]['status'] === 200 && ($results[0]['json']['data']['log_id'] ?? 0) > 0 && ($results[0]['json']['data']['log_id'] ?? 0) === ($results[1]['json']['data']['log_id'] ?? -1), $results);
    concurrencyCheck('Concurrent retries increment source count once', (int)dbFetchOne('SELECT CALL_COUNT FROM ' . tn('TBL_MAIN') . ' WHERE ID = ?', [$id])['CALL_COUNT'] === 1);
    concurrencyCheck('Concurrent retries insert one log row', (int)dbFetchOne('SELECT COUNT(*) AS n FROM ' . tn('TBL_CALL_LOGS') . ' WHERE client_call_id = ?', [$tag])['n'] === 1);
} finally {
    $link->query('UPDATE ' . tn('TBL_MAIN') . ' m JOIN audit_main_status s ON m.ID = s.ID SET m.MAINDATABASE_CALL_DIALED_STATUS = s.MAINDATABASE_CALL_DIALED_STATUS');
    $link->query('UPDATE ' . tn('TBL_TEMP') . ' t JOIN audit_temp_status s ON t.ID = s.ID SET t.CALL_DIALED_STATUS = s.CALL_DIALED_STATUS');
    foreach ($fixtureIds ?? [] as $id) dbExecute('DELETE FROM ' . tn('TBL_TEMP') . ' WHERE ID = ?', [$id]);
}
echo "Android concurrency: $passed passed, $failed failed" . PHP_EOL;
exit($failed ? 1 : 0);
