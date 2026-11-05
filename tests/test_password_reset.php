<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php_scripts/password_reset.php';
require_once __DIR__ . '/lib/db_helper.php';
require_once __DIR__ . '/lib/request_helper.php';
if (!str_starts_with($DB_NAME, 'callnow_audit_')) throw new RuntimeException('Run in isolated project audit');
ensurePasswordResetSchema($link);
$passed = $failed = 0;
function resetCheck(string $name, bool $ok): void {
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
}
$login = 'reset.' . bin2hex(random_bytes(4)) . '@example.test';
$originalHash = password_hash('OriginalPassword123!', PASSWORD_DEFAULT);
dbExecute('INSERT INTO ' . tn('TBL_USERS') . ' (NAME, LOGIN_ID, EMAIL, COMPANY, PASSWORD, ROLE, STATUS) VALUES (?, ?, ?, ?, ?, ?, ?)', ['Reset Test', $login, $login, 'Reset Test Company', $originalHash, 'Officer', 'Active']);
$id = (int)$link->insert_id;
$token = bin2hex(random_bytes(32));
dbExecute('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)', [$id, hash('sha256', $token), date('Y-m-d H:i:s', time() + 1800)]);
$csrf = ['csrf_token' => 'lp-test-token'];
$r = appRequest('reset-password.php', $id, $csrf + ['token' => $token, 'password' => 'NewPassword123!', 'confirm_password' => 'DifferentPassword']);
resetCheck('Mismatched password does not consume reset token', dbFetchOne('SELECT used_at FROM password_reset_tokens WHERE token_hash = ?', [hash('sha256', $token)])['used_at'] === null);
resetCheck('Short password rejected', !consumePasswordReset($link, $token, 'short'));
resetCheck('Unknown token rejected', !consumePasswordReset($link, bin2hex(random_bytes(32)), 'NewPassword123!'));
$apiToken = bin2hex(random_bytes(32));
dbExecute('INSERT INTO ' . tn('TBL_API_TOKENS') . ' (user_id, token, name, is_active) VALUES (?, ?, ?, 1)', [$id, $apiToken, 'Reset test']);
$r = appRequest('reset-password.php', $id, $csrf + ['token' => $token, 'password' => 'NewPassword123!', 'confirm_password' => 'NewPassword123!']);
resetCheck('Valid reset form changes password', password_verify('NewPassword123!', dbFetchOne('SELECT PASSWORD FROM ' . tn('TBL_USERS') . ' WHERE ID = ?', [$id])['PASSWORD']));
resetCheck('Used reset link cannot be replayed', !consumePasswordReset($link, $token, 'OtherPassword123!'));
resetCheck('Password reset revokes Android tokens', (int)dbFetchOne('SELECT is_active FROM ' . tn('TBL_API_TOKENS') . ' WHERE token = ?', [$apiToken])['is_active'] === 0);
$expired = bin2hex(random_bytes(32));
dbExecute('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)', [$id, hash('sha256', $expired), '2020-01-01 00:00:00']);
resetCheck('Expired reset rejected', !consumePasswordReset($link, $expired, 'ExpiredPassword123!'));
$r = appRequest('forgot-password.php', $id, $csrf + ['username' => 'missing@example.test', 'CompanyName' => 'Unknown']);
resetCheck('Reset request gives generic response without password disclosure', str_contains($r['body'], 'If an active account matches') && !str_contains($r['body'], 'Your new password is:'));
resetCheck('Reset request never changes current password', password_verify('NewPassword123!', dbFetchOne('SELECT PASSWORD FROM ' . tn('TBL_USERS') . ' WHERE ID = ?', [$id])['PASSWORD']));
echo "Password reset: $passed passed, $failed failed" . PHP_EOL;
exit($failed ? 1 : 0);
