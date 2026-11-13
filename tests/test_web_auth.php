<?php
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/lib/db_helper.php';
if (!str_starts_with($DB_NAME, 'callnow_audit_')) throw new RuntimeException('Run in isolated project audit');
$passed = $failed = 0;
function webAuthCheck(string $name, bool $ok): void {
    global $passed, $failed, $r; $ok ? $passed++ : $failed++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$ok) {
        preg_match('/(Error:|Too many|Fatal error|Warning:)[^<]+/', $r['body'] ?? '', $error);
        echo 'HTTP ' . ($r['status'] ?? 0) . ' ' . ($error[0] ?? substr(strip_tags($r['body'] ?? ''), 0, 150)) . PHP_EOL;
    }
}
$login = 'web.auth.' . bin2hex(random_bytes(4)) . '@example.test';
$password = 'WebAuthTest123!';
$mobile = '97' . random_int(10000000, 99999999);
dbExecute('INSERT INTO ' . tn('TBL_USERS') . ' (NAME, LOGIN_ID, EMAIL, COMPANY, MOBILE, PASSWORD, ROLE, STATUS) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', ['Web Auth Test', $login, $login, 'Audit Company', $mobile, password_hash($password, PASSWORD_DEFAULT), 'Officer', 'Active']);
$id = (int)$link->insert_id;
$client = curl_init();
curl_setopt($client, CURLOPT_COOKIEFILE, '');
$base = preg_replace('#/api/v1$#', '', getenv('TEST_API_BASE'));
$request = static function (string $path, ?array $post = null) use ($client, $base): array {
    $curl = $client;
    curl_setopt_array($curl, [CURLOPT_URL => $base . $path, CURLOPT_HTTPGET => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $raw = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); $headerLength = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => $status, 'headers' => substr($raw, 0, $headerLength), 'body' => substr($raw, $headerLength)];
};
$r = $request('/dashboard');
webAuthCheck('Guest redirected from protected dashboard', $r['status'] === 302);
$r = $request('/index.php');
preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $r['body'], $match);
$csrf = $match[1] ?? '';
webAuthCheck('Login form serves CSRF token', $r['status'] === 200 && $csrf !== '');
$r = $request('/index.php', ['CompanyName' => 'Audit Company', 'username' => $login, 'password' => $password]);
webAuthCheck('Login requires CSRF', $r['status'] === 200 && str_contains($r['body'], 'Invalid session token'));
$r = $request('/index.php', ['csrf_token' => $csrf, 'CompanyName' => 'Audit Company', 'username' => $login, 'password' => 'wrong']);
webAuthCheck('Wrong password rejected', $r['status'] === 200 && str_contains($r['body'], 'password you entered is not valid'));
$beforeCookie = curl_getinfo($client, CURLINFO_COOKIELIST);
$r = $request('/index.php', ['csrf_token' => $csrf, 'CompanyName' => 'Audit Company', 'username' => $login, 'password' => $password]);
webAuthCheck('Valid web login redirects to dashboard', $r['status'] === 302 && str_contains($r['headers'], 'Location: dashboard'));
webAuthCheck('Web login rotates session ID', curl_getinfo($client, CURLINFO_COOKIELIST) !== $beforeCookie);
$r = $request('/dashboard');
webAuthCheck('Authenticated web dashboard renders', $r['status'] === 200 && strlen($r['body']) > 1000);
$r = $request('/profile.php', ['csrf_token' => $csrf, 'update_profile' => '1', 'name' => 'Updated Web User', 'mobile' => $mobile, 'login_id' => $login, 'password' => '', 'confirm_password' => '']);
webAuthCheck('Web profile update persists', (dbFetchOne('SELECT NAME FROM ' . tn('TBL_USERS') . ' WHERE ID = ?', [$id])['NAME'] ?? '') === 'Updated Web User');
$r = $request('/logout.php');
webAuthCheck('Web logout redirects', $r['status'] === 302);
$r = $request('/dashboard');
webAuthCheck('Logged out session cannot access dashboard', $r['status'] === 302);
curl_close($client);
echo "Web authentication: $passed passed, $failed failed" . PHP_EOL;
exit($failed ? 1 : 0);
