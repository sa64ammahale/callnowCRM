<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/lib/db_helper.php';
require_once __DIR__ . '/lib/request_helper.php';
if (!str_starts_with($DB_NAME, 'callnow_audit_')) throw new RuntimeException('Run in the isolated project audit');
$passed = $failed = 0;
function userCheck(string $name, bool $ok, array $response = []): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($ok ? '' : ' ' . substr(json_encode($response), 0, 900)) . PHP_EOL;
}
dbExecute('UPDATE ' . tn('TBL_SUPER_ADMIN_PLANS') . ' SET max_users = 100000');
$tag = 'Audit Team ' . bin2hex(random_bytes(3));
$csrf = ['csrf_token' => 'lp-test-token'];
$r = appRequest('modules/users/teams_dashboard.php', 1, $csrf + ['action' => 'add', 'team_name' => $tag]);
$team = dbFetchOne('SELECT ID FROM ' . tn('TBL_TEAMS') . ' WHERE NAME = ?', [$tag]);
$teamId = (int)($team['ID'] ?? 0);
userCheck('Admin creates team using form handler', $teamId > 0, $r);
$r = appRequest('modules/users/teams_dashboard.php', 1, $csrf + ['action' => 'add', 'team_name' => $tag]);
userCheck('Duplicate team rejected', str_contains($r['body'], 'Team name already exists'), $r);
$r = appRequest('modules/users/teams_dashboard.php', 1, $csrf + ['action' => 'edit', 'team_id' => $teamId, 'team_name' => $tag . ' Edited']);
userCheck('Team edit persists', dbFetchOne('SELECT NAME FROM ' . tn('TBL_TEAMS') . ' WHERE ID = ?', [$teamId])['NAME'] === $tag . ' Edited', $r);
$mobile = '98' . random_int(10000000, 99999999);
$login = 'audit.' . bin2hex(random_bytes(4)) . '@example.test';
$form = $csrf + ['submit' => 1, 'name' => 'Audit Test User', 'mobile' => $mobile, 'username' => $login, 'password' => 'AuditPassword123!', 'role' => 'Officer', 'team_id' => $teamId, 'package' => 'Standard', 'status' => 'Active'];
$r = appRequest('modules/users/users_add.php', 1, $form);
$user = dbFetchOne('SELECT * FROM ' . tn('TBL_USERS') . ' WHERE LOGIN_ID = ?', [$login]);
$id = (int)($user['ID'] ?? 0);
userCheck('Admin creates officer through form', $id > 0, $r);
userCheck('Password is hashed', $id > 0 && password_verify($form['password'], $user['PASSWORD']));
$r = appRequest('modules/users/users_add.php', 1, $form);
userCheck('Duplicate user rejected', str_contains($r['body'], 'already registered'), $r);
foreach (['name' => 'Bad123', 'mobile' => '123', 'username' => 'invalid', 'password' => '123', 'role' => 'Super Admin', 'team_id' => 2147483647, 'status' => 'Injected'] as $field => $value) {
    $bad = $form; $bad['username'] = bin2hex(random_bytes(4)) . '@example.test'; $bad['mobile'] = '98' . random_int(10000000, 99999999); $bad[$field] = $value;
    $r = appRequest('modules/users/users_add.php', 1, $bad);
    userCheck('Validation rejects ' . $field, str_contains($r['body'], 'alert-danger') || str_contains($r['body'], 'alert-warning'), $r);
}
if ($id > 0) {
    $edit = $form; $edit['name'] = 'Updated Audit User'; $edit['password'] = '';
    $r = appRequest('modules/users/users_add.php', 1, $edit, ['ID' => $id]);
    userCheck('Admin edits user', dbFetchOne('SELECT NAME FROM ' . tn('TBL_USERS') . ' WHERE ID = ?', [$id])['NAME'] === $edit['name'], $r);
    foreach (['users_view.php', 'users_add.php', 'teams_dashboard.php', 'team_members.php'] as $page) {
        $r = appRequest('modules/users/' . $page, $id, [], [], 'GET');
        userCheck('Officer denied ' . $page, strlen($r['body']) < 500 && $r['exit'] === 0, $r);
    }
    $r = appRequest('modules/users/users_add.php', 1, $edit, ['ID' => 1]);
    userCheck('System account remains protected', strlen($r['body']) < 500, $r);
    $r = appRequest('modules/settings/permissions_manager.php', 1, $csrf + ['pm_action' => 'save_user_perms', 'user_id' => $id, 'role' => 'Officer', 'uperm_manage_database' => '1']);
    userCheck('Permission editor saves actual string permission keys', (int)(dbFetchOne('SELECT permission_value FROM ' . tn('TBL_USER_PERMISSIONS') . ' WHERE user_id = ? AND permission_key = ?', [$id, 'manage_database'])['permission_value'] ?? 0) === 1, $r);
    $r = appRequest('modules/database/data_management_main.php', $id, [], [], 'GET');
    userCheck('Granted permission allows database page', strlen($r['body']) > 1000, $r);
    $r = appRequest('modules/settings/permissions_manager.php', 1, $csrf + ['pm_action' => 'clear_user_perms', 'user_id' => $id]);
    userCheck('Permission overrides can be cleared', dbFetchOne('SELECT user_id FROM ' . tn('TBL_USER_PERMISSIONS') . ' WHERE user_id = ?', [$id]) === null, $r);
    $r = appRequest('modules/users/team_members.php', 1, $csrf + ['action' => 'assign_member', 'user_id' => $id, 'team_id' => '']);
    userCheck('Member can be unassigned', dbFetchOne('SELECT TEAM_ID FROM ' . tn('TBL_USERS') . ' WHERE ID = ?', [$id])['TEAM_ID'] === null, $r);
    $r = appRequest('modules/users/team_members.php', 1, $csrf + ['action' => 'assign_member', 'user_id' => $id, 'team_id' => $teamId]);
    userCheck('Member assignment persists', (int)dbFetchOne('SELECT TEAM_ID FROM ' . tn('TBL_USERS') . ' WHERE ID = ?', [$id])['TEAM_ID'] === $teamId, $r);
    $r = appRequest('modules/users/team_members.php', 1, $csrf + ['action' => 'change_supervisor', 'supervisor_id' => $id, 'team_id' => $teamId]);
    userCheck('Officer cannot become team supervisor', str_contains($r['body'], 'not an active Supervisor'), $r);
    foreach (['users_view.php', 'team_members.php', 'teams_dashboard.php', 'users_add.php'] as $page) {
        $r = appRequest('modules/users/' . $page, 1, [], [], 'GET');
        userCheck('Admin renders ' . $page, strlen($r['body']) > 1000 && !preg_match('/Fatal error|Warning:/', $r['body'] . $r['errors']), $r);
    }
}
$r = appRequest('modules/users/teams_dashboard.php', 1, ['action' => 'delete', 'team_id' => $teamId]);
userCheck('CSRF prevents deleting team', dbFetchOne('SELECT ID FROM ' . tn('TBL_TEAMS') . ' WHERE ID = ?', [$teamId]) !== null, $r);
$r = appRequest('modules/users/teams_dashboard.php', 1, $csrf + ['action' => 'delete', 'team_id' => $teamId]);
userCheck('Delete removes team and unassigns members', dbFetchOne('SELECT ID FROM ' . tn('TBL_TEAMS') . ' WHERE ID = ?', [$teamId]) === null && ($id === 0 || dbFetchOne('SELECT TEAM_ID FROM ' . tn('TBL_USERS') . ' WHERE ID = ?', [$id])['TEAM_ID'] === null), $r);
echo "User workflows: $passed passed, $failed failed" . PHP_EOL;
exit($failed ? 1 : 0);
