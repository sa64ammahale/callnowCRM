<?php
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/lib/db_helper.php';
require_once __DIR__ . '/lib/request_helper.php';
if (!str_starts_with($DB_NAME, 'callnow_audit_')) throw new RuntimeException('Run in isolated project audit');
$passed = $failed = 0;
function dataCheck(string $name, bool $ok, array $r = []): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($ok ? '' : ' ' . substr(json_encode($r), 0, 800)) . PHP_EOL;
}
$csrf = ['csrf_token' => 'lp-test-token'];
$mobile = '99' . random_int(10000000, 99999999);
$form = $csrf + ['submit' => '1', 'name' => 'Audit Customer', 'mobile' => $mobile, 'company' => 'Audit Company', 'package' => '500000', 'other_info' => 'Customer requested afternoon call', 'upload_to' => 'both'];
$r = appRequest('modules/database/add_single_number.php', 1, $form);
$main = dbFetchOne('SELECT * FROM ' . tn('TBL_MAIN') . ' WHERE MAINDATABASE_MOBILE = ?', [$mobile]);
$temp = dbFetchOne('SELECT * FROM ' . tn('TBL_TEMP') . ' WHERE CUST_MOBILE = ?', [$mobile]);
dataCheck('Add contact to main and temporary database', $main !== null && $temp !== null, $r);
$id = (int)($main['ID'] ?? 0); $tempId = (int)($temp['ID'] ?? 0);
$edit = $form; $edit['other_info'] = 'Updated notes must remain text';
$r = appRequest('modules/database/add_single_number.php', 1, $edit, ['ID' => $id]);
dataCheck('Editing contact preserves text notes', (dbFetchOne('SELECT MAINDATABASE_OTHER_INFO FROM ' . tn('TBL_MAIN') . ' WHERE ID = ?', [$id])['MAINDATABASE_OTHER_INFO'] ?? '') === $edit['other_info'], $r);
foreach (['maindatabase_ajax', 'temporarydatabase_ajax'] as $folder) {
    foreach ([10, -1, 0] as $length) {
        $r = appRequest('modules/database/' . $folder . '/datatable.php', 1, [], ['length' => $length, 'start' => -1, 'search' => ['value' => $mobile]], 'GET');
        dataCheck($folder . ' search and length ' . $length, is_array($r['json']['data'] ?? null) && (int)($r['json']['recordsFiltered'] ?? 0) >= 1, $r);
    }
    $r = appRequest('modules/database/' . $folder . '/export_batch.php', 1, $csrf + ['offset' => -1, 'limit' => 5]);
    dataCheck($folder . ' bounded CSV export', ($r['json']['success'] ?? false) === true && isset($r['json']['csv']), $r);
    $r = appRequest('modules/database/' . $folder . '/get_total_count.php', 1, [], [], 'GET');
    dataCheck($folder . ' record count JSON', is_array($r['json']), $r);
    $r = appRequest('modules/database/' . $folder . '/bulk_assign.php', 1, $csrf + ['ids' => [$folder === 'maindatabase_ajax' ? $id : $tempId], 'user_id' => 1]);
    dataCheck($folder . ' bulk assignment', ($r['json']['success'] ?? false) === true, $r);
}
$r = appRequest('modules/database/data_management_temporary.php', 1, $csrf + ['action' => 'view', 'id' => $tempId]);
dataCheck('Temporary contact detail JSON', (int)($r['json']['row']['ID'] ?? 0) === $tempId, $r);
$r = appRequest('modules/database/ViewDND.php', 1, $csrf + ['action' => 'add', 'add_mobile' => $mobile]);
dataCheck('DND updates both database copies', dbFetchOne('SELECT MAINDATABASE_CALL_DIALED_STATUS AS s FROM ' . tn('TBL_MAIN') . ' WHERE ID = ?', [$id])['s'] === 'Do Not Call' && dbFetchOne('SELECT CALL_DIALED_STATUS AS s FROM ' . tn('TBL_TEMP') . ' WHERE ID = ?', [$tempId])['s'] === 'Do Not Call', $r);
$r = appRequest('modules/database/ViewDND.php', 1, $csrf + ['action' => 'unmark', 'id' => $id, 'source' => 'MAIN']);
dataCheck('DND restoration releases both copies', dbFetchOne('SELECT MAINDATABASE_CALL_DIALED_STATUS AS s FROM ' . tn('TBL_MAIN') . ' WHERE ID = ?', [$id])['s'] === 'Not Called' && dbFetchOne('SELECT CALL_DIALED_STATUS AS s FROM ' . tn('TBL_TEMP') . ' WHERE ID = ?', [$tempId])['s'] === 'Not Called', $r);
$r = appRequest('modules/settings/settings.php', 1, $csrf + ['settings_action' => 'save_settings', 'app_name' => 'Audit CRM', 'company_name' => 'Audit Company', 'pagination_size' => '25', 'session_timeout' => '30', 'timezone' => 'Asia/Kolkata']);
dataCheck('General settings save', (dbFetchOne('SELECT setting_value FROM ' . tn('TBL_APP_SETTINGS') . ' WHERE setting_key = ?', ['app_name'])['setting_value'] ?? '') === 'Audit CRM', $r);
foreach (['modules/logs/Reports.php', 'modules/logs/call_history.php', 'modules/logs/manage_activity.php'] as $page) {
    $r = appRequest($page, 1, [], ['from' => '2026-01-01', 'to' => '2026-12-31', 'search' => 'no-such-customer'], 'GET');
    dataCheck('Reports empty search renders ' . $page, strlen($r['body']) > 1000 && !preg_match('/Fatal error|Warning:/', $r['body'] . $r['errors']), $r);
}
foreach (['maindatabase_ajax' => $id, 'temporarydatabase_ajax' => $tempId] as $folder => $target) {
    $r = appRequest('modules/database/' . $folder . '/bulk_delete.php', 1, $csrf + ['ids' => [$target]]);
    dataCheck($folder . ' bulk delete', ($r['json']['success'] ?? false) === true, $r);
}
$session = 'auditweb' . bin2hex(random_bytes(8));
$code = 'session_id(' . var_export($session, true) . '); session_start(); $_SESSION=["loggedin"=>true,"id"=>1,"csrf_token"=>"lp-test-token","LAST_ACTIVITY"=>time()]; session_write_close();';
$proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
$webBase = preg_replace('#/api/v1$#', '', getenv('TEST_API_BASE'));
$http = static function (array $data) use ($session, $webBase): array {
    $curl = curl_init($webBase . '/modules/database/upload_data.php');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_COOKIE => 'PHPSESSID=' . $session, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    return ['status' => $status, 'json' => json_decode($body, true), 'raw' => substr($body, 0, 400)];
};
dbExecute('UPDATE ' . tn('TBL_SUPER_ADMIN_PLANS') . ' SET max_db_records = 1000000, max_storage_mb = 10000');
$r = $http($csrf + ['import' => '1', 'terms_agreed' => '1', 'has_header' => '1', 'target_database' => 'temporary', 'file' => new CURLFile(__DIR__ . '/fixtures/project_audit.csv', 'text/csv', 'project_audit.csv')]);
$taskId = $r['json']['task_id'] ?? '';
dataCheck('Real multipart CSV upload starts import', $taskId !== '', $r);
if ($taskId !== '') {
    $r = $http(['process_upload_batch' => '1', 'task_id' => $taskId]);
    dataCheck('CSV batch requires CSRF', $r['status'] === 403, $r);
    $r = $http($csrf + ['process_upload_batch' => '1', 'task_id' => $taskId]);
    dataCheck('CSV batch skips header and counts valid and invalid rows', ($r['json']['status'] ?? '') === 'complete' && (int)($r['json']['stats']['rows_processed'] ?? 0) === 2 && (int)($r['json']['stats']['invalid'] ?? 0) === 1, $r);
    dataCheck('CSV import stores valid contact', dbFetchOne('SELECT ID FROM ' . tn('TBL_TEMP') . ' WHERE CUST_MOBILE = ?', ['9870098765']) !== null);
}
echo "Web data workflows: $passed passed, $failed failed" . PHP_EOL;
exit($failed ? 1 : 0);
