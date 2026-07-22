<?php


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

    // PHP 8.2 defaults to MYSQLI_REPORT_STRICT which throws exceptions
    // on query failures → uncaught → HTTP 500. Remove STRICT so mysqli
    // returns false on error (like PHP 7.x) instead of throwing.
    mysqli_report(MYSQLI_REPORT_OFF);

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // App install path relative to the document root.
    // Derive it from THIS file's physical location (php_scripts/config.php lives
    // in <approot>/php_scripts) so it is identical for every request — including
    // pages served from subfolders like /modules/database. This keeps asset URLs
    // anchored to the app root instead of the current script's directory.
    $appRootReal = str_replace('\\', '/', dirname(dirname(__FILE__)));
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
        define('TBL_LEAD_NOTES',         'lead_notes');
        define('TBL_LEAD_FOLLOWUPS',     'lead_followups');
        define('TBL_LEAD_ASSIGNMENTS',   'lead_assignments');
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

    // ── Self-heal guard (defense against a stale/deployed config or
    // opcache serving an old copy where these constants were undefined
    // or equal to their own name, e.g. TBL_USERS === 'TBL_USERS') ──
    $__cn_tbl = [
        'TBL_ACTIVITY_LOG'      => 'activity_log',
        'TBL_API_ACCESS_LOGS'  => 'api_access_logs',
        'TBL_API_DB_ASSIGNMENTS'=> 'api_database_assignments',
        'TBL_API_SETTINGS'     => 'api_settings',
        'TBL_API_TOKENS'       => 'api_tokens',
        'TBL_APP_SETTINGS'     => 'app_settings',
        'TBL_ENQUIRY'          => 'enquiry',
        'TBL_LEADS'            => 'leads_table',
        'TBL_LEAD_NOTES'       => 'lead_notes',
        'TBL_LEAD_FOLLOWUPS'   => 'lead_followups',
        'TBL_LEAD_ASSIGNMENTS' => 'lead_assignments',
        'TBL_MAIN'             => 'main_database',
        'TBL_MAIN_ARCHIVE'     => 'main_database_archive',
        'TBL_PERMISSIONS'      => 'permissions',
        'TBL_ROLE_PERMISSIONS'=> 'role_permissions',
        'TBL_ROLES'            => 'roles',
        'TBL_TEAMS'            => 'teams',
        'TBL_TEMP'             => 'temporary_database',
        'TBL_USER_PAGE_FILTERS'=> 'user_page_filters',
        'TBL_USER_PERMISSIONS' => 'user_permissions',
        'TBL_USERS'            => 'users',
    ];
    foreach ($__cn_tbl as $__cn_k => $__cn_v) {
        $__cn_cur = defined($__cn_k) ? constant($__cn_k) : null;
        // Fix if undefined, empty, or accidentally equal to its own name.
        if ($__cn_cur === null || $__cn_cur === '' || $__cn_cur === $__cn_k) {
            // Can't redefine an existing constant; use runkit if present,
            // otherwise redefine via a clean trick only when not yet defined.
            if (!defined($__cn_k)) {
                define($__cn_k, $__cn_v);
            } elseif (function_exists('runkit_constant_redefine')) {
                runkit_constant_redefine($__cn_k, $__cn_v);
            } else {
                // Last-resort: expose a global map so code can fall back.
                $GLOBALS['__cn_tbl_override'][$__cn_k] = $__cn_v;
            }
        }
    }
    // Safe table-name resolver: falls back to the override map if a
    // constant could not be redefined (should never happen with opcache off).
    if (!function_exists('tn')) {
        function tn(string $name): string {
            if (defined($name)) {
                $v = constant($name);
                if ($v !== '' && $v !== $name) return $v;
            }
            return $GLOBALS['__cn_tbl_override'][$name] ?? $name;
        }
    }
    unset($__cn_tbl, $__cn_k, $__cn_v, $__cn_cur);

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

    if (!function_exists('ensureApiSchema')) {
        function ensureApiSchema(mysqli $link): void {
            static $done = false;
            if ($done) return;

            mysqli_query($link, "CREATE TABLE IF NOT EXISTS " . tn('TBL_API_SETTINGS') . " (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT DEFAULT NULL,
                description TEXT DEFAULT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_setting_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            mysqli_query($link, "CREATE TABLE IF NOT EXISTS " . tn('TBL_API_TOKENS') . " (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(128) NOT NULL,
                name VARCHAR(100) DEFAULT 'Mobile App',
                expires_at DATETIME DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_used_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_token (token),
                KEY idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            mysqli_query($link, "CREATE TABLE IF NOT EXISTS " . tn('TBL_API_DB_ASSIGNMENTS') . " (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                database_type ENUM('temporary','main','leads') NOT NULL,
                team_id INT DEFAULT NULL,
                assigned_by INT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user_db (user_id, database_type),
                KEY idx_team_id (team_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            mysqli_query($link, "CREATE TABLE IF NOT EXISTS " . tn('TBL_API_ACCESS_LOGS') . " (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token_id INT DEFAULT NULL,
                user_id INT DEFAULT NULL,
                endpoint VARCHAR(255) DEFAULT NULL,
                method VARCHAR(10) DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                response_code INT DEFAULT 200,
                execution_time_ms INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_token_id (token_id),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $done = true;
        }
    }

?>