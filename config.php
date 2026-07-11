<?php

    $DB_SERVERNAME = getenv('DB_SERVERNAME') ?: "localhost";
    $DB_USERNAME = getenv('DB_USERNAME') ?: "root";
    $DB_PASSWORD = getenv('DB_PASSWORD') ?: "";
    $DB_NAME = getenv('DB_NAME') ?: "callnow_incredit";

    $link = mysqli_connect($DB_SERVERNAME, $DB_USERNAME, $DB_PASSWORD, $DB_NAME);
    if($link === false){
        die("ERROR: Could Not Connect to Database, Reason:- " . mysqli_connect_error());
    }

    mysqli_set_charset($link, 'utf8mb4');

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
    $scriptName = str_replace('\\', '/', $scriptName);
    $projectDir = basename(str_replace('\\', '/', __DIR__));
    $pos = strpos($scriptName, '/' . $projectDir . '/');
    if ($pos === false) {
        $appPath = rtrim(dirname($scriptName), '/\\');
    } else {
        $appPath = substr($scriptName, 0, $pos + strlen('/' . $projectDir));
    }
    define('APP_BASE', rtrim($protocol . '://' . $host . $appPath, '/'));

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