<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../modules/leads/lead_common.php';
require_once __DIR__ . '/lib/db_helper.php';
ensureLeadModuleSchema($link);
$passed = 0;
$failed = 0;
$tag = 'LP-' . bin2hex(random_bytes(5));
$fixtureUsers = [];
$fixtureLeads = [];
$fixtureCustomers = [];

function checkLeadTest(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($ok || $detail === '' ? '' : ': ' . $detail) . PHP_EOL;
}

function leadRequest(string $file, int $userId, array $post = [], array $get = [], string $method = 'POST'): array {
    $root = dirname(__DIR__);
    $payload = base64_encode(json_encode([$post, $get]));
    $code = 'chdir(' . var_export($root, true) . '); session_id("lp" . bin2hex(random_bytes(12))); session_start(); $_SESSION=["loggedin"=>true,"id"=>' . $userId . ',"csrf_token"=>"lp-test-token","LAST_ACTIVITY"=>time()]; '
        . '[$_POST,$_GET]=json_decode(base64_decode(' . var_export($payload, true) . '),true); $_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . '; $_SERVER["HTTP_HOST"]="localhost"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; '
        . 'register_shutdown_function(function(){echo "\\nLP_RESULT:".json_encode(["status"=>http_response_code()?:200,"flash"=>$_SESSION["success_message"]??""]); session_destroy();}); require ' . var_export($root . '/' . $file, true) . ';';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $parts = explode("\nLP_RESULT:", $output);
    return ['body' => $parts[0], 'json' => json_decode($parts[0], true), 'result' => json_decode($parts[1] ?? '{}', true), 'exit' => $exit, 'errors' => $errors];
}

try {
    foreach (['Officer', 'Officer'] as $index => $role) {
        $login = $tag . '-' . $index;
        dbExecute('INSERT INTO ' . tn('TBL_USERS') . ' (NAME, LOGIN_ID, EMAIL, ROLE, STATUS, PASSWORD) VALUES (?, ?, ?, ?, ?, ?)', [$login, $login, $login . '@example.test', $role, 'Active', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
        $fixtureUsers[] = (int)$link->insert_id;
        dbExecute('INSERT INTO ' . tn('TBL_USER_PERMISSIONS') . ' (user_id, permission_key, permission_value) VALUES (?, ?, 1)', [$link->insert_id, 'manage_leads']);
    }
    [$owner, $outsider] = $fixtureUsers;
    $mobile = '98' . substr((string)random_int(100000000000, 999999999999), 0, 10);
    $create = ['csrf_token' => 'lp-test-token', 'name' => $tag, 'mobile' => $mobile, 'assigned_to' => $owner, 'loan_amount' => '500000', 'next_followup_at' => '2027-02-10'];
    $r = leadRequest('modules/leads/lead_insert.php', $owner, $create);
    $customer = dbFetchOne('SELECT ID FROM ' . tn('TBL_MAIN') . ' WHERE MAINDATABASE_MOBILE = ?', [$mobile]);
    if ($customer) $fixtureCustomers[] = (int)$customer['ID'];
    $lead = dbFetchOne('SELECT * FROM ' . tn('TBL_LEADS') . ' WHERE cust_id = ?', [$customer['ID'] ?? 0]);
    if ($lead) $fixtureLeads[] = (int)$lead['lead_id'];
    checkLeadTest('Officer creates a real customer and lead', $lead !== null, $r['body'] . $r['errors']);
    if (!$lead) throw new RuntimeException('Lead creation failed');
    $id = (int)$lead['lead_id'];
    $reject = ['csrf_token' => 'lp-test-token', 'lead_id' => $id, 'customer_name' => $tag, 'mobile' => $mobile, 'assigned_to' => $owner, 'lead_status_new' => 'REJECT'];
    $r = leadRequest('modules/leads/lead_update.php', $owner, $reject);
    checkLeadTest('Officer cannot change lead status', dbFetchOne('SELECT lead_status_new FROM ' . tn('TBL_LEADS') . ' WHERE lead_id = ?', [$id])['lead_status_new'] === 'LEAD');
    $r = leadRequest('modules/leads/lead_update.php', 1, $reject);
    checkLeadTest('Customer can decline before underwriting via Admin', dbFetchOne('SELECT lead_status_new FROM ' . tn('TBL_LEADS') . ' WHERE lead_id = ?', [$id])['lead_status_new'] === 'REJECT', json_encode($r['result']));
    $reject['lead_status_new'] = 'LEAD';
    leadRequest('modules/leads/lead_update.php', 1, $reject);
    $r = leadRequest('modules/leads/lead_insert.php', $owner, $create);
    checkLeadTest('Duplicate mobile does not create another lead', (int)dbFetchOne('SELECT COUNT(*) AS n FROM ' . tn('TBL_LEADS') . ' WHERE cust_id = ?', [$customer['ID']])['n'] === 1);
    foreach (['lead_note_add', 'lead_followup_add', 'lead_forward_toggle', 'lead_send_backward_toggle', 'lead_clone_bank', 'lead_assign'] as $action) {
        $post = ['lead_id' => $id, 'note' => 'Customer needs a second bank quote', 'followup_at' => '2027-02-11 14:30:00', 'to_user_id' => $outsider, 'csrf_token' => 'lp-test-token'];
        $r = leadRequest('modules/leads/php_scripts/' . $action . '.php', $outsider, $post);
        checkLeadTest('Unrelated officer denied ' . $action, in_array($r['result']['status'] ?? 0, [403, 404], true), $r['body'] . $r['errors']);
    }
    $base = ['lead_id' => $id, 'csrf_token' => 'lp-test-token'];
    $r = leadRequest('modules/leads/php_scripts/lead_note_add.php', $owner, $base + ['note' => '<script>alert(1)</script> customer note']);
    checkLeadTest('Owner adds threaded note', ($r['json']['success'] ?? false) === true, $r['body'] . $r['errors']);
    foreach (['2027-02-12 16:45:00', '2027-02-11 14:30:00'] as $at) {
        $r = leadRequest('modules/leads/php_scripts/lead_followup_add.php', $owner, $base + ['followup_at' => $at, 'note' => 'Call after office']);
        checkLeadTest('Schedule follow-up ' . $at, ($r['json']['success'] ?? false) === true, $r['body'] . $r['errors']);
    }
    $next = dbFetchOne('SELECT next_followup_at FROM ' . tn('TBL_LEADS') . ' WHERE lead_id = ?', [$id]);
    checkLeadTest('Next follow-up selects earliest open appointment with exact time', $next['next_followup_at'] === '2027-02-11 14:30:00');
    $follow = dbFetchOne('SELECT id FROM ' . tn('TBL_LEAD_FOLLOWUPS') . ' WHERE lead_id = ? ORDER BY followup_at LIMIT 1', [$id]);
    foreach ([1, 2] as $repeat) {
        $r = leadRequest('modules/leads/php_scripts/lead_followup_done.php', $owner, ['id' => $follow['id'], 'csrf_token' => 'lp-test-token']);
        checkLeadTest('Complete follow-up repeat ' . $repeat, ($r['json']['success'] ?? false) === true, $r['body'] . $r['errors']);
    }
    $next = dbFetchOne('SELECT followup_count, next_followup_at FROM ' . tn('TBL_LEADS') . ' WHERE lead_id = ?', [$id]);
    checkLeadTest('Completion is idempotent and advances next appointment', (int)$next['followup_count'] === 1 && $next['next_followup_at'] === '2027-02-12 16:45:00');
    foreach (['lead_forward_toggle', 'lead_send_backward_toggle'] as $action) {
        foreach ([1, 0] as $value) {
            $r = leadRequest('modules/leads/php_scripts/' . $action . '.php', $owner, $base);
            checkLeadTest($action . ' toggles to ' . $value, ($r['json']['success'] ?? false) === true, $r['body'] . $r['errors']);
        }
    }
    $save = $base + ['customer_name' => $tag, 'mobile' => $mobile, 'assigned_to' => $owner, 'next_followup_at' => '2027-02-12T16:45', 'login_status' => 'PENDING'];
    foreach (['LOGIN', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT'] as $index => $stage) {
        $data = $save; $data['lead_status_new'] = $stage; $data['login_status'] = $index >= 5 ? 'SUCCESS' : 'PENDING';
        $r = leadRequest('modules/leads/lead_update.php', 1, $data);
        $actual = dbFetchOne('SELECT lead_status_new, next_followup_at FROM ' . tn('TBL_LEADS') . ' WHERE lead_id = ?', [$id]);
        $expected = $index === 0 ? 'LEAD' : ($index === 4 ? 'LOGIN' : $stage);
        checkLeadTest('Workflow step ' . $index . ': ' . $stage . ' => ' . $expected, $actual['lead_status_new'] === $expected, json_encode($r['result']) . $r['errors']);
    }
    checkLeadTest('Saving lead preserves follow-up time', $actual['next_followup_at'] === '2027-02-12 16:45:00');
    $r = leadRequest('modules/leads/php_scripts/lead_clone_bank.php', $owner, $base);
    $cloneId = (int)($r['json']['new_lead_id'] ?? 0);
    if ($cloneId) $fixtureLeads[] = $cloneId;
    checkLeadTest('Clone for another bank creates linked login', $cloneId > 0, $r['body'] . $r['errors']);
    foreach (['lead_view.php', 'lead_pipeline.php', 'lead_list.php', 'leads_dashboard.php'] as $page) {
        $r = leadRequest('modules/leads/' . $page, $owner, [], ['id' => $id], 'GET');
        checkLeadTest('Render ' . $page, $r['exit'] === 0 && strlen($r['body']) > 1000 && !preg_match('/Fatal error|Warning:|Parse error/', $r['body'] . $r['errors']), $r['errors']);
        if ($page === 'lead_view.php') checkLeadTest('Note HTML is escaped on detail page', str_contains($r['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'));
    }
    $r = leadRequest('modules/leads/php_scripts/lead_cards_data.php', $owner, ['csrf_token' => 'lp-test-token', 'search' => $tag, 'loginMonth' => '', 'quickStatus' => 'ALL']);
    checkLeadTest('Cards search returns fixture lead', ($r['json']['ok'] ?? false) && count($r['json']['cards'] ?? []) > 0, $r['body']);
    $r = leadRequest('modules/leads/php_scripts/lead_cards_data.php', $outsider, ['csrf_token' => 'lp-test-token', 'search' => $tag, 'loginMonth' => '', 'quickStatus' => 'ALL']);
    checkLeadTest('Cards hide other officers leads', ($r['json']['ok'] ?? false) && count($r['json']['cards'] ?? []) === 0);
    $r = leadRequest('modules/leads/php_scripts/lead_cards_data.php', $owner, ['csrf_token' => 'lp-test-token', 'search' => $tag, 'loginMonth' => '', 'quickStatus' => 'REJECT', 'perPage' => 1]);
    checkLeadTest('Status filter and pagination return one rejected lead', ($r['json']['ok'] ?? false) && count($r['json']['cards'] ?? []) === 1 && $r['json']['cards'][0]['status'] === 'REJECT');
    $r = leadRequest('modules/leads/php_scripts/lead_followup_add.php', $owner, $base + ['followup_at' => 'invalid-date']);
    checkLeadTest('Invalid appointment is rejected', ($r['result']['status'] ?? 0) === 422);
    $r = leadRequest('modules/leads/php_scripts/lead_note_add.php', $owner, $base + ['note' => '']);
    checkLeadTest('Empty note is rejected', ($r['result']['status'] ?? 0) === 422);
    if ($cloneId) {
        $cloned = dbFetchOne('SELECT parent_lead_id, lead_status_new, lead_status FROM ' . tn('TBL_LEADS') . ' WHERE lead_id = ?', [$cloneId]);
        checkLeadTest('Bank clone resets terminal legacy status and keeps parent link', (int)$cloned['parent_lead_id'] === $id && $cloned['lead_status_new'] === 'LOGIN' && $cloned['lead_status'] === 'New');
    }
    $r = leadRequest('modules/leads/php_scripts/lead_assign.php', 1, $base + ['to_user_id' => $outsider, 'reason' => 'Customer moved to second officer']);
    checkLeadTest('Admin reassigns lead with audit history', ($r['json']['success'] ?? false) && (int)dbFetchOne('SELECT assigned_to FROM ' . tn('TBL_LEADS') . ' WHERE lead_id = ?', [$id])['assigned_to'] === $outsider, $r['body'] . $r['errors']);
    $r = leadRequest('modules/leads/php_scripts/lead_note_add.php', $owner, $base + ['note' => 'Previous owner update']);
    checkLeadTest('Previous owner loses edit access after transfer', in_array($r['result']['status'] ?? 0, [403, 404], true));
    $r = leadRequest('modules/leads/php_scripts/lead_note_add.php', $outsider, $base + ['note' => 'New owner contacted customer']);
    checkLeadTest('New owner can continue customer history', ($r['json']['success'] ?? false) === true);
    dbExecute('UPDATE ' . tn('TBL_USER_PERMISSIONS') . ' SET permission_value = 0 WHERE user_id = ? AND permission_key = ?', [$outsider, 'manage_leads']);
    foreach (['lead_view.php', 'lead_pipeline.php', 'lead_list.php', 'leads_dashboard.php'] as $page) {
        $r = leadRequest('modules/leads/' . $page, $outsider, [], ['id' => $id], 'GET');
        checkLeadTest('Permission revocation blocks ' . $page, strlen($r['body']) < 1000);
    }
    $r = leadRequest('modules/leads/php_scripts/lead_note_add.php', $outsider, $base + ['note' => 'Revoked user']);
    checkLeadTest('Permission revocation blocks mutation', ($r['json']['success'] ?? false) !== true);
    $r = leadRequest('modules/leads/php_scripts/lead_note_add.php', $owner, ['lead_id' => $id, 'note' => 'bad csrf']);
    checkLeadTest('CSRF rejection prevents note mutation', ($r['result']['status'] ?? 0) === 403);
    $r = leadRequest('modules/leads/php_scripts/lead_delete.php', 1, $base);
    checkLeadTest('Admin deletes lead', ($r['json']['ok'] ?? false) === true && dbFetchOne('SELECT lead_id FROM ' . tn('TBL_LEADS') . ' WHERE lead_id = ?', [$id]) === null, $r['body'] . $r['errors']);
    foreach (['TBL_LEAD_NOTES', 'TBL_LEAD_FOLLOWUPS', 'TBL_LEAD_ASSIGNMENTS'] as $table) {
        checkLeadTest('Deletion removes orphan rows in ' . $table, (int)dbFetchOne('SELECT COUNT(*) AS n FROM ' . tn($table) . ' WHERE lead_id = ?', [$id])['n'] === 0);
    }
    if ($cloneId) checkLeadTest('Deleting parent preserves independent bank application', dbFetchOne('SELECT parent_lead_id FROM ' . tn('TBL_LEADS') . ' WHERE lead_id = ?', [$cloneId])['parent_lead_id'] === null);
} catch (Throwable $e) {
    checkLeadTest('Scenario execution', false, $e->getMessage());
} finally {
    foreach ($fixtureCustomers as $customerId) {
        $rows = $link->query('SELECT lead_id FROM ' . tn('TBL_LEADS') . ' WHERE cust_id = ' . (int)$customerId);
        while ($row = $rows->fetch_assoc()) $fixtureLeads[] = (int)$row['lead_id'];
    }
    foreach (array_unique($fixtureLeads) as $id) {
        dbExecute('DELETE FROM ' . tn('TBL_ACTIVITY_LOG') . ' WHERE AFFECTED_IDS = ? AND TARGET_TABLE = ?', [(string)$id, tn('TBL_LEADS')]);
        foreach (['TBL_LEAD_NOTES', 'TBL_LEAD_FOLLOWUPS', 'TBL_LEAD_ASSIGNMENTS'] as $table) dbExecute('DELETE FROM ' . tn($table) . ' WHERE lead_id = ?', [$id]);
        dbExecute('DELETE FROM ' . tn('TBL_LEADS') . ' WHERE lead_id = ?', [$id]);
    }
    foreach ($fixtureCustomers as $id) dbExecute('DELETE FROM ' . tn('TBL_MAIN') . ' WHERE ID = ?', [$id]);
    foreach ($fixtureUsers as $id) {
        dbExecute('DELETE FROM ' . tn('TBL_ACTIVITY_LOG') . ' WHERE USER_ID = ?', [$id]);
        dbExecute('DELETE FROM ' . tn('TBL_USER_PERMISSIONS') . ' WHERE user_id = ?', [$id]);
        dbExecute('DELETE FROM ' . tn('TBL_USERS') . ' WHERE ID = ?', [$id]);
    }
}
echo "Leads and pipeline: $passed passed, $failed failed. Fixtures removed." . PHP_EOL;
exit($failed > 0 ? 1 : 0);
