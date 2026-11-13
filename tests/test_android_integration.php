<?php
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/lib/db_helper.php';
if (!str_starts_with($DB_NAME, 'callnow_audit_')) throw new RuntimeException('Run through tests/run_project_audit.php against the audit database');
ensureApiSchema($link);
$base = getenv('TEST_API_BASE');
$passed = $failed = 0;
function androidCheck(string $name, bool $ok, array $response = []): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($ok ? '' : ' ' . json_encode($response)) . PHP_EOL;
}
function androidApi(string $method, string $path, $payload = null, ?string $token = null): array {
    global $base;
    $curl = curl_init($base . $path);
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: CallNow-Android-Integration/1.0'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
    if ($payload !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, is_string($payload) ? $payload : json_encode($payload === [] ? (object)[] : $payload));
    $raw = curl_exec($curl);
    $result = ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'json' => json_decode($raw, true), 'transport_error' => curl_error($curl)];
    curl_close($curl);
    return $result;
}
$users = [];
$password = 'AndroidFixture123!';
$tag = 'Android-' . bin2hex(random_bytes(4));
foreach (['Active', 'Active', 'Inactive'] as $i => $status) {
    $name = $tag . '-' . $i;
    dbExecute('INSERT INTO ' . tn('TBL_USERS') . ' (NAME, LOGIN_ID, EMAIL, PASSWORD, ROLE, STATUS) VALUES (?, ?, ?, ?, ?, ?)', [$name, $name, $name . '@example.test', password_hash($password, PASSWORD_DEFAULT), 'Officer', $status]);
    $users[] = ['id' => (int)$link->insert_id, 'login' => $name];
}
$tokens = [];
foreach ($users as $i => $user) {
    $r = androidApi('POST', '/auth/login', ['username' => $user['login'], 'password' => $password]);
    androidCheck('Android login user ' . $i, $r['status'] === ($i === 2 ? 403 : 200), $r);
    if ($i < 2) $tokens[] = $r['json']['data']['token'] ?? '';
}
[$a, $b] = $tokens;
foreach (['/telecaller/dashboard', '/telecaller/calls', '/telecaller/leads', '/auth/me', '/admin/settings'] as $path) {
    $r = androidApi('GET', $path);
    androidCheck('Missing bearer rejected ' . $path, $r['status'] === 401, $r);
}
foreach (['/telecaller/dashboard', '/admin/settings', '/auth/me'] as $path) {
    $r = androidApi('OPTIONS', $path);
    androidCheck('Unauthenticated preflight ' . $path, in_array($r['status'], [200, 204], true), $r);
}
$r = androidApi('GET', '/admin/settings', null, $a);
androidCheck('Officer cannot access admin settings', $r['status'] === 403, $r);
$roleBefore = dbFetchOne('SELECT permission_value FROM ' . tn('TBL_ROLE_PERMISSIONS') . ' WHERE role = ? AND permission_key = ?', ['Officer', 'manage_api']);
dbExecute('INSERT INTO ' . tn('TBL_ROLE_PERMISSIONS') . ' (role, permission_key, permission_value) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE permission_value = 1', ['Officer', 'manage_api']);
dbExecute('INSERT INTO ' . tn('TBL_USER_PERMISSIONS') . ' (user_id, permission_key, permission_value) VALUES (?, ?, 0)', [$users[0]['id'], 'manage_api']);
$r = androidApi('GET', '/admin/settings', null, $a);
androidCheck('Explicit user denial wins over API role grant', $r['status'] === 403, $r);
if ($roleBefore) dbExecute('UPDATE ' . tn('TBL_ROLE_PERMISSIONS') . ' SET permission_value = ? WHERE role = ? AND permission_key = ?', [(int)$roleBefore['permission_value'], 'Officer', 'manage_api']);
else dbExecute('DELETE FROM ' . tn('TBL_ROLE_PERMISSIONS') . ' WHERE role = ? AND permission_key = ?', ['Officer', 'manage_api']);
$r = androidApi('GET', '/auth/me', null, $a);
androidCheck('Profile contains Android identity fields', isset($r['json']['data']['user']['id'], $r['json']['data']['user']['role']), $r);
$r = androidApi('POST', '/auth/profile', ['name' => 'Android customer agent'], $a);
androidCheck('Android updates allowed profile fields', $r['status'] === 200, $r);
foreach (['email', 'mobile'] as $field) {
    $r = androidApi('POST', '/auth/profile', [$field => 'changed'], $a);
    androidCheck('Identity change blocked ' . $field, $r['status'] === 400, $r);
}
$mobile = '987001' . random_int(1000, 9999);
$r = androidApi('POST', '/telecaller/leads', ['name' => 'Android हिन्दी ग्राहक', 'mobile' => '+91 ' . $mobile, 'notes' => 'Call after 6 pm'], $a);
$recordId = (int)($r['json']['data']['lead']['record_id'] ?? 0);
androidCheck('Android creates lead with formatted Indian number', $r['status'] === 201 && $recordId > 0, $r);
$r = androidApi('GET', '/telecaller/leads?search=' . $mobile, null, $a);
androidCheck('Created lead appears in Android list', ($r['json']['data']['pagination']['total'] ?? 0) >= 1, $r);
foreach (['GET', 'PUT'] as $method) {
    $r = androidApi($method, '/telecaller/leads/' . $recordId, $method === 'PUT' ? ['name' => 'Unauthorized overwrite'] : null, $b);
    androidCheck('Other officer denied lead ' . $method, $r['status'] === 403, $r);
}
$r = androidApi('POST', '/telecaller/leads', ['name' => 'Duplicate takeover', 'mobile' => $mobile], $b);
androidCheck('Duplicate mobile cannot take over another officer lead', $r['status'] === 403, $r);
$payload = ['record_id' => $recordId, 'source' => 'MAIN', 'call_status' => 'Connected', 'call_duration' => 65, 'total_duration' => 70, 'sim_slot' => 'SIM2', 'recording_url' => 'https://example.test/test.mp3', 'metadata' => ['android_version' => '15', 'network' => '4g'], 'notes' => 'ग्राहक interested', 'dial_time' => '2026-11-05T12:00:00+05:30', 'call_time' => '2026-11-05T12:00:05+05:30', 'call_end_time' => '2026-11-05T12:01:10+05:30', 'client_call_id' => $tag . '-call-1'];
$r = androidApi('POST', '/telecaller/submit_call', $payload, $a);
$logId = (int)($r['json']['data']['log_id'] ?? 0);
androidCheck('Full Android call telemetry is saved', $r['status'] === 200 && $logId > 0, $r);
$r = androidApi('POST', '/telecaller/submit_call', $payload, $a);
androidCheck('Offline retry returns original log ID', $r['status'] === 200 && (int)($r['json']['data']['log_id'] ?? 0) === $logId && ($r['json']['data']['replayed'] ?? false), $r);
$changed = $payload; $changed['call_duration'] = 66;
$r = androidApi('POST', '/telecaller/submit_call', $changed, $a);
androidCheck('Conflicting retry gets 409', $r['status'] === 409, $r);
$row = dbFetchOne('SELECT * FROM ' . tn('TBL_CALL_LOGS') . ' WHERE id = ?', [$logId]);
androidCheck('Timezone and Unicode survive round trip', ($row['dial_time'] ?? '') === '2026-11-05 12:00:00' && ($row['notes'] ?? '') === $payload['notes']);
$r = androidApi('GET', '/telecaller/calls?period=all', null, $a);
androidCheck('Android call list returns SIM and recording', ($r['json']['data']['data'][0]['sim_slot'] ?? '') === 'SIM2' && ($r['json']['data']['data'][0]['recording_url'] ?? '') === $payload['recording_url'], $r);

// Test period aliases (month, week) and pagination metadata
$rMonth = androidApi('GET', '/telecaller/calls?period=month&limit=1', null, $a);
androidCheck('Android call list period=month returns monthly calls', $rMonth['status'] === 200 && count($rMonth['json']['data']['data'] ?? []) >= 1, $rMonth);
androidCheck('Android call list pagination contains total and has_more', isset($rMonth['json']['data']['pagination']['total']) && isset($rMonth['json']['data']['pagination']['has_more']), $rMonth);

$payloadWeek = $payload;
$payloadWeek['client_call_id'] = $tag . '-call-week';
$payloadWeek['dial_time'] = date('Y-m-d\TH:i:sP');
$payloadWeek['call_time'] = date('Y-m-d\TH:i:sP');
$payloadWeek['call_end_time'] = date('Y-m-d\TH:i:sP');
androidApi('POST', '/telecaller/submit_call', $payloadWeek, $a);

$rWeek = androidApi('GET', '/telecaller/calls?period=week', null, $a);
androidCheck('Android call list period=week returns calls', $rWeek['status'] === 200 && count($rWeek['json']['data']['data'] ?? []) >= 1, $rWeek);

$rSummary = androidApi('GET', '/telecaller/reports/summary?period=month', null, $a);
androidCheck('Android reports summary period=month aggregates monthly calls', $rSummary['status'] === 200 && ($rSummary['json']['data']['total_calls'] ?? 0) >= 1, $rSummary);

$rLeadsMonth = androidApi('GET', '/telecaller/leads?period=month', null, $a);
androidCheck('Android leads list supports period=month filter', $rLeadsMonth['status'] === 200 && isset($rLeadsMonth['json']['data']['pagination']['has_more']), $rLeadsMonth);

// Test Follow-up user isolation & scoping
$rFCreate = androidApi('POST', '/telecaller/follow-ups', [
    'lead_id' => $recordId,
    'followup_at' => date('Y-m-d H:i:s', strtotime('+2 hours')),
    'note' => 'Client callback requested',
    'contact_source' => 'MAIN',
], $a);
androidCheck('Officer A creates follow-up', $rFCreate['status'] === 200 && ($rFCreate['json']['data']['followup_id'] ?? 0) > 0, $rFCreate);
$fId = (int)($rFCreate['json']['data']['followup_id'] ?? 0);

$rFListA = androidApi('GET', '/telecaller/follow-ups?scope=mine', null, $a);
androidCheck('Officer A sees own follow-up in mine scope', $rFListA['status'] === 200 && in_array($fId, array_column($rFListA['json']['data']['data'] ?? [], 'id')), $rFListA);

$rFListB = androidApi('GET', '/telecaller/follow-ups?scope=mine', null, $b);
androidCheck('Officer B does not see Officer A follow-up in mine scope', $rFListB['status'] === 200 && !in_array($fId, array_column($rFListB['json']['data']['data'] ?? [], 'id')), $rFListB);

$rFListBAll = androidApi('GET', '/telecaller/follow-ups?scope=all', null, $b);
androidCheck('Officer B clamped to own follow-ups even with scope=all', $rFListBAll['status'] === 200 && !in_array($fId, array_column($rFListBAll['json']['data']['data'] ?? [], 'id')), $rFListBAll);

$rHistoryLead = androidApi('GET', '/telecaller/call-history/' . $recordId . '?source=MAIN', null, $a);
androidCheck('Officer can read own contact call history', ($rHistoryLead['json']['data']['total'] ?? 0) >= 1, $rHistoryLead);

$r = androidApi('GET', '/telecaller/call-history/' . $recordId, null, $b);
androidCheck('Other officer cannot read call history', ($r['json']['data']['total'] ?? -1) === 0, $r);
foreach (['call_duration' => -1, 'total_duration' => 'invalid', 'call_time' => 'invalid-date', 'source' => 'LEADS', 'recording_url' => 'javascript:alert(1)', 'notes' => str_repeat('x', 16001)] as $field => $value) {
    $bad = $payload; unset($bad['client_call_id']); $bad[$field] = $value;
    $r = androidApi('POST', '/telecaller/submit_call', $bad, $a);
    androidCheck('Bad Android field rejected: ' . $field, $r['status'] === 400, $r);
}
foreach (['{broken', '[]', '{"source":[]}'] as $raw) {
    foreach (['/telecaller/submit_call', '/telecaller/calls/' . $recordId . '/complete', '/telecaller/leads'] as $path) {
        $r = androidApi('POST', $path, $raw, $a);
        androidCheck('Malformed JSON rejected ' . $path . ' ' . $raw, $r['status'] === 400, $r);
    }
}
$compact = $payload; unset($compact['client_call_id']);
$r = androidApi('POST', '/telecaller/calls/' . $recordId . '/complete', $compact, $a);
androidCheck('Compact Android call route supports same payload', $r['status'] === 200, $r);
$r = androidApi('POST', '/telecaller/calls/2147483647/complete', ['source' => 'MAIN'], $a);
androidCheck('Unknown call target returns 404', $r['status'] === 404, $r);
$r = androidApi('POST', '/telecaller/calls/' . $recordId . '/complete', $compact, $b);
androidCheck('Other officer cannot complete assigned call', $r['status'] === 403, $r);
dbExecute('UPDATE ' . tn('TBL_LEADS') . ' SET lead_status_new = ? WHERE cust_id = ?', ['SANCTIONED', $recordId]);
$positive = $compact; $positive['call_status'] = 'Interested';
$r = androidApi('POST', '/telecaller/submit_call', $positive, $a);
androidCheck('Later interested call preserves sanctioned pipeline stage', $r['status'] === 200 && (dbFetchOne('SELECT lead_status_new FROM ' . tn('TBL_LEADS') . ' WHERE cust_id = ?', [$recordId])['lead_status_new'] ?? '') === 'SANCTIONED', $r);
$r = androidApi('POST', '/auth/logout', [], $a);
androidCheck('Android logout revokes bearer', $r['status'] === 200, $r);
$r = androidApi('GET', '/telecaller/dashboard', null, $a);
androidCheck('Logged out token rejected', $r['status'] === 401, $r);
dbExecute('UPDATE ' . tn('TBL_API_TOKENS') . ' SET expires_at = ? WHERE token = ?', ['2020-01-01 00:00:00', $b]);
$r = androidApi('GET', '/telecaller/dashboard', null, $b);
androidCheck('Expired token rejected', $r['status'] === 401, $r);
$r = androidApi('POST', '/auth/login', '{"username":[],"password":"x"}');
androidCheck('Malformed Android login types rejected', $r['status'] === 400, $r);
dbExecute('INSERT INTO ' . tn('TBL_API_SETTINGS') . ' (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', ['api_enabled', '0']);
$r = androidApi('POST', '/auth/login', ['username' => $users[0]['login'], 'password' => $password]);
androidCheck('Disabled API blocks Android login', $r['status'] === 503, $r);
dbExecute('UPDATE ' . tn('TBL_API_SETTINGS') . ' SET setting_value = ? WHERE setting_key = ?', ['1', 'api_enabled']);

// Re-login user 0 to get an active token for subsequent tests
$rLoginActive = androidApi('POST', '/auth/login', ['username' => $users[0]['login'], 'password' => $password]);
$activeToken = $rLoginActive['json']['data']['token'] ?? '';

// Verify mobile password reset OTP flow
$rForgot = androidApi('POST', '/auth/forgot-password', ['email' => $users[0]['login']]);
androidCheck('Forgot password returns success', $rForgot['status'] === 200 && ($rForgot['json']['success'] ?? false) === true, $rForgot);
$otp = $rForgot['json']['data']['debug_otp'] ?? null;
if ($otp) {
    $rReset = androidApi('POST', '/auth/reset-password', [
        'email' => $users[0]['login'],
        'otp' => $otp,
        'new_password' => 'NewPassword!123',
        'password_confirmation' => 'NewPassword!123',
    ]);
    androidCheck('Reset password with OTP succeeds', $rReset['status'] === 200 && ($rReset['json']['success'] ?? false) === true, $rReset);
    // Restore original password
    $restoreHash = password_hash($password, PASSWORD_DEFAULT);
    dbExecute('UPDATE ' . tn('TBL_USERS') . ' SET PASSWORD = ? WHERE ID = ?', [$restoreHash, $users[0]['id']]);
    // Re-login to get active session
    $rRelogin = androidApi('POST', '/auth/login', ['username' => $users[0]['login'], 'password' => $password]);
    $activeToken = $rRelogin['json']['data']['token'] ?? $activeToken;
}

// Verify lead creation with complete financial & bank fields
$leadMobile = '98' . sprintf('%08d', random_int(10000000, 99999999));
$rLead = androidApi('POST', '/telecaller/leads', [
    'name' => 'Auto Lead Test',
    'mobile' => $leadMobile,
    'company' => 'Acme Corp',
    'package' => '500000',
    'loan_type' => 'Business Loan',
    'lead_stage' => 'Hot',
    'priority' => 'High',
    'login_bank_name' => 'HDFC Bank',
    'bank_name' => 'HDFC Bank',
    'net_salary' => '75000',
    'salary_account' => 'ICICI Bank',
    'status' => 'LEAD',
], $activeToken);
androidCheck('Lead creation with bank & finance fields succeeds', $rLead['status'] === 201 || $rLead['status'] === 200, $rLead);
$createdLId = (int)($rLead['json']['data']['lead']['lead_id'] ?? 0);
$checkLeadDb = dbFetchOne('SELECT loan_type, login_bank_name, net_salary, priority FROM ' . tn('TBL_LEADS') . ' WHERE MOBILE = ?', [$leadMobile]);
androidCheck('TBL_LEADS persisted financial and bank columns', ($checkLeadDb['login_bank_name'] ?? '') === 'HDFC Bank' && ($checkLeadDb['loan_type'] ?? '') === 'Business Loan', $checkLeadDb ?: []);

// Verify phone-only follow-up creation
$fPhoneMobile = '97' . sprintf('%08d', random_int(10000000, 99999999));
$rFPhone = androidApi('POST', '/telecaller/follow-ups', [
    'phone_number' => $fPhoneMobile,
    'customer_name' => 'Direct Phone Followup',
    'followup_at' => date('Y-m-d H:i:s', time() + 3600),
    'note' => 'Call back regarding quotation',
], $activeToken);
androidCheck('Follow-up creation with phone number succeeds', $rFPhone['status'] === 200 && !empty($rFPhone['json']['data']['followup_id']), $rFPhone);

// Verify phone-only background call submission
$bgCallMobile = '96' . sprintf('%08d', random_int(10000000, 99999999));
$rBgCall = androidApi('POST', '/telecaller/submit_call', [
    'phone_number' => $bgCallMobile,
    'call_status' => 'Connected',
    'call_duration' => 45,
    'source' => 'MAIN',
    'notes' => 'Background tracked call test',
], $activeToken);
androidCheck('Background call submission with phone number succeeds', $rBgCall['status'] === 200 && !empty($rBgCall['json']['data']['log_id']), $rBgCall);

echo "Android integration: $passed passed, $failed failed" . PHP_EOL;
exit($failed ? 1 : 0);
