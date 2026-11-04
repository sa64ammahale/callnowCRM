<?php
// Page helper for test scripts: renders a page using existing render_page_wrapper.php.
// Returns true on success, false on fatal error.

function page_helper(string $pagePath, string $role = 'Admin'): bool {
    // Ensure auth and role checks are simulated.
    // We'll set a session with user id and role.
    $sessionFile = sys_get_temp_dir() . '/page_helper_' . uniqid() . '.php';
    $code = <<<'PHP'
<?php
session_start();
$_SESSION['user_id'] = 1; // assume Super Admin exists
$_SESSION['role'] = '{ROLE}';
require_once __DIR__ . '/../../php_scripts/config.php';
require_once __DIR__ . '/../../php_scripts/auth.php';
requireRole('{ROLE}');
require_once __DIR__ . '/../../tests/render_page_wrapper.php';
render_page('{PAGE}');
PHP;
    $code = str_replace(['{ROLE}', '{PAGE}'], [$role, $pagePath], $code);
    file_put_contents($sessionFile, $code);
    $output = shell_exec('php -d display_errors=0 ' . escapeshellarg($sessionFile) . ' 2>&1');
    @unlink($sessionFile);
    // If output contains fatal error or exception, treat as failure.
    if (strpos(strtolower($output), 'fatal') !== false || strpos(strtolower($output), 'exception') !== false) {
        return false;
    }
    return true;
}
?>
