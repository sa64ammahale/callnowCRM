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

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // The install path is the directory that contains the current script.
    // SCRIPT_NAME is always the on-disk path from the document root, so
    // dirname() correctly yields "" at the domain root, "/crm" in a subfolder,
    // or "/" when the subdomain docroot already is the app folder.
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $appPath = rtrim(dirname($scriptName), '/\\');
    define('APP_BASE', rtrim($protocol . '://' . $host . $appPath, '/'));

    // Local vendored asset URL (no external CDNs — works on shared hosting)
    if (!function_exists('vnd')) {
        function vnd(string $path): string {
            return APP_BASE . '/assets/vendor/' . ltrim($path, '/');
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