<?php
// ── Live error capture (temporary diagnostic) ──
// Writes PHP errors/fatals to <approot>/php_errors.log so they are visible on
// shared hosting where display_errors is off. Append ?cnerror=1 to any URL to
// also show the error on the page. Remove once the 500 is fixed.
if (!function_exists('__cn_log_err')) {
    function __cn_log_err(string $msg): void {
        @file_put_contents(__DIR__ . '/../php_errors.log', $msg, FILE_APPEND);
    }
}
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    __cn_log_err('[' . date('Y-m-d H:i:s') . "] ERR $errno: $errstr @ $errfile:$errline\n");
    return false;
});
if (!function_exists('__cn_fatal_log')) {
    function __cn_fatal_log(): void {
        $err = error_get_last();
        if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $msg = '[' . date('Y-m-d H:i:s') . '] FATAL ' . $err['type'] . ': ' . $err['message']
                . ' @ ' . $err['file'] . ':' . $err['line'] . "\n";
            __cn_log_err($msg);
            if (!empty($_GET['cnerror'])) {
                header('Content-Type: text/plain; charset=utf-8');
                echo "FATAL ERROR (cnerror mode)\n" . $msg;
                exit;
            }
        }
    }
    register_shutdown_function('__cn_fatal_log');
}


    // Load a local .env file if present (shared hosting without env support)
    if (file_exists(__DIR__ . '/../.env')) {
        $lines = @file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            foreach ($lines as $line) {
                if ($line[0] === '#' || strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim(trim($v), '"\'');
                if (!getenv($k)) {
                    putenv("$k=$v");
                    $_ENV[$k] = $v;
                }
            }
        }
    }

    $DB_SERVERNAME = getenv('DB_SERVERNAME') ?: (getenv('DB_HOST') ?: "localhost");
    $DB_PORT = getenv('DB_PORT') ?: "3306";
    $DB_USERNAME = getenv('DB_USERNAME') ?: "root";
    $DB_PASSWORD = getenv('DB_PASSWORD') ?: "";
    $DB_NAME = getenv('DB_NAME') ?: "callnow_incredit";

    $link = mysqli_connect($DB_SERVERNAME, $DB_USERNAME, $DB_PASSWORD, $DB_NAME, (int)$DB_PORT);
    if($link === false){
        if (defined('APP_DEBUG') && APP_DEBUG) {
            die("ERROR: Could Not Connect to Database, Reason:- " . mysqli_connect_error());
        }
        die("ERROR: Could not connect to the database. Please contact the administrator.");
    }

    mysqli_set_charset($link, 'utf8mb4');

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // App install path relative to the document root.
    // Derive it from THIS file's physical location (php_scripts/config.php lives
    // in <approot>/php_scripts) so it is identical for every request — including
    // pages served from subfolders like /modules/database. This keeps asset URLs
    // anchored to the app root instead of the current script's directory.
    $appRootReal = dirname(dirname(__FILE__));
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appPath = '';
    if ($docRoot !== '' && strpos($appRootReal, $docRoot) === 0) {
        $appPath = rtrim(substr($appRootReal, strlen($docRoot)), '/');
    } else {
        // Fallback: use the requested script's directory
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
        $appPath = rtrim(dirname($scriptName), '/\\');
    }
    if ($appPath !== '' && $appPath[0] !== '/') {
        $appPath = '/' . $appPath;
    }
    define('APP_BASE', rtrim($protocol . '://' . $host . $appPath, '/'));
    define('APP_PATH', $appPath);

    // Local vendored asset URL (no external CDNs — works on shared hosting).
    // Always anchored to the app root so module pages resolve correctly.
    if (!function_exists('vnd')) {
        function vnd(string $path): string {
            return APP_BASE . '/assets/vendor/' . ltrim($path, '/');
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Canonical table names (lowercase). Reference these constants in
    // every SQL statement instead of raw strings. MySQL/MariaDB table
    // names are case-sensitive on Linux (Hostinger), so hardcoding the
    // correct lowercase form here prevents "table doesn't exist" errors
    // that only appear in production.
    // ─────────────────────────────────────────────────────────────
    if (!defined('TBL_ACTIVITY_LOG')) {
        define('TBL_ACTIVITY_LOG',      'activity_log');
        define('TBL_API_ACCESS_LOGS',    'api_access_logs');
        define('TBL_API_DB_ASSIGNMENTS', 'api_database_assignments');
        define('TBL_API_SETTINGS',       'api_settings');
        define('TBL_API_TOKENS',         'api_tokens');
        define('TBL_APP_SETTINGS',       'app_settings');
        define('TBL_ENQUIRY',            'enquiry');
        define('TBL_LEADS',              'leads_table');
        define('TBL_MAIN',               'main_database');
        define('TBL_MAIN_ARCHIVE',       'main_database_archive');
        define('TBL_PERMISSIONS',        'permissions');
        define('TBL_ROLE_PERMISSIONS',   'role_permissions');
        define('TBL_ROLES',              'roles');
        define('TBL_TEAMS',              'teams');
        define('TBL_TEMP',               'temporary_database');
        define('TBL_USER_PAGE_FILTERS',  'user_page_filters');
        define('TBL_USER_PERMISSIONS',   'user_permissions');
        define('TBL_USERS',              'users');
    }

    // ─────────────────────────────────────────────────────────────
    // Shared AJAX helpers. Call api_init() at the top of any JSON
    // endpoint so unexpected fatals are returned as JSON (not a blank
    // page or HTML error) and the UI can show the real message.
    // ─────────────────────────────────────────────────────────────
    if (!function_exists('api_send_json')) {
        function api_send_json($payload, int $code = 200): void {
            while (ob_get_level()) { ob_end_clean(); }
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    if (!function_exists('api_error')) {
        function api_error(string $message, int $code = 500, $extra = []): void {
            $payload = array_merge(['error' => true, 'message' => $message], (array)$extra);
            api_send_json($payload, $code);
        }
    }
    if (!function_exists('api_init')) {
        function api_init(): void {
            set_exception_handler(function ($e) {
                api_error(APP_DEBUG ? ($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()) : 'Server error', 500);
            });
            register_shutdown_function(function () {
                $err = error_get_last();
                if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                    api_error(APP_DEBUG ? ($err['message'] . ' @ ' . $err['file'] . ':' . $err['line']) : 'Server error', 500);
                }
            });
        }
    }

    define('APP_DEBUG', getenv('APP_DEBUG') === '1');
    error_reporting(E_ALL);
    ini_set('display_errors', APP_DEBUG ? '1' : '0');
    ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
    ini_set('log_errors', '1');

    if (!function_exists('ensureCsrfToken')) {
        function ensureCsrfToken(): void {
            if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
        }
    }
    if (!function_exists('verifyCsrfToken')) {
        function verifyCsrfToken(?string $token): bool {
            ensureCsrfToken();
            return is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
        }
    }
    if (!function_exists('csrfField')) {
        function csrfField(): string {
            ensureCsrfToken();
            return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">';
        }
    }

?>