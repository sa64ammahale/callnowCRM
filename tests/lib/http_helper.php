<?php
// HTTP helper for test scripts: runs a target PHP script (AJAX endpoint) in a subprocess.
// It sets up $_POST, $_GET, $_SERVER, includes the script, captures JSON output.

function http_helper(string $scriptPath, array $post = [], array $get = [], string $csrfToken = ''): array {
    // Prepare environment variables for the subprocess.
    $env = [];
    // Ensure session start and CSRF token.
    if ($csrfToken !== '') {
        $post['csrf_token'] = $csrfToken;
    }
    // Build PHP code to execute.
    $code = <<<'PHP'
<?php
// Simulate request environment.
$_POST = json_decode(base64_decode('{POST}'), true) ?: [];
$_GET  = json_decode(base64_decode('{GET}'), true) ?: [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../../php_scripts/config.php'; // ensure $link, tn, etc.
require_once '{SCRIPT}';
PHP;
    // Encode arrays safely.
    $postEnc = base64_encode(json_encode($post));
    $getEnc = base64_encode(json_encode($get));
    $code = str_replace(['{POST}', '{GET}', '{SCRIPT}'], [$postEnc, $getEnc, $scriptPath], $code);
    // Write temporary script.
    $tmpFile = sys_get_temp_dir() . '/http_helper_' . uniqid() . '.php';
    file_put_contents($tmpFile, $code);
    // Execute PHP and capture output.
    $output = shell_exec('php -d display_errors=0 ' . escapeshellarg($tmpFile));
    @unlink($tmpFile);
    // Attempt to decode JSON.
    $data = json_decode($output, true);
    if ($data === null) {
        // Return raw output for debugging.
        return ['__raw' => $output, '__error' => 'Invalid JSON'];
    }
    return $data;
}
?>
