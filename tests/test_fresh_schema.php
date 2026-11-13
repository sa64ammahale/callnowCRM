<?php
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../modules/leads/lead_common.php';
require_once __DIR__ . '/../php_scripts/password_reset.php';
if (!str_starts_with($DB_NAME, 'callnow_audit_')) throw new RuntimeException('Run in isolated project audit');
$freshDb = 'callnow_audit_schema_' . bin2hex(random_bytes(6));
try {
    if (!$link->query('CREATE DATABASE `' . $freshDb . '` CHARACTER SET utf8mb4')) throw new RuntimeException($link->error);
    $link->select_db($freshDb);
    $sql = file_get_contents(__DIR__ . '/../database/hostinger_schema.sql');
    if (!$link->multi_query($sql)) throw new RuntimeException($link->error);
    do {
        if ($result = $link->store_result()) $result->free();
        if (!$link->more_results()) break;
        if (!$link->next_result()) throw new RuntimeException($link->error);
    } while (true);
    echo '[PASS] Fresh installation SQL imports successfully' . PHP_EOL;
    ensureApiSchema($link);
    ensureLeadModuleSchema($link);
    ensurePasswordResetSchema($link);
    $result = $link->query('SELECT record_id, source, call_status, call_duration, total_duration, timing_classification, metadata, recording_url, sim_slot, dial_time, call_end_time, client_call_id, request_hash FROM ' . tn('TBL_CALL_LOGS') . ' LIMIT 0');
    if (!$result) throw new RuntimeException($link->error);
    echo '[PASS] Fresh call-log schema supports full Android payload and retries' . PHP_EOL;
    $result = $link->query('SELECT token_hash, expires_at, used_at FROM password_reset_tokens LIMIT 0');
    if (!$result) throw new RuntimeException($link->error);
    echo '[PASS] Fresh installation supports secure password reset' . PHP_EOL;
    echo 'Fresh schema: 3 passed, 0 failed. Database retained: ' . $freshDb . PHP_EOL;
} catch (Throwable $e) {
    echo '[FAIL] Fresh schema: ' . $e->getMessage() . PHP_EOL;
    exit(1);
} finally {
    $link->select_db($DB_NAME);
}
