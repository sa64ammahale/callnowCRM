<?php

if (!function_exists('sanitizeCompanyCode')) {
    function sanitizeCompanyCode(?string $code): ?string {
        if ($code === null) return null;
        $clean = strtolower(trim($code));
        return preg_match('/^[a-z0-9_\-]+$/i', $clean) ? $clean : null;
    }
}

if (!function_exists('getMasterConnection')) {
    function getMasterConnection(): mysqli {
        static $masterLink = null;
        if ($masterLink instanceof mysqli && @mysqli_ping($masterLink)) {
            return $masterLink;
        }

        $host = getenv('DB_SERVERNAME') ?: (getenv('DB_HOST') ?: 'localhost');
        $user = getenv('DB_USERNAME') ?: 'root';
        $pass = getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '';
        $port = (int)(getenv('DB_PORT') ?: 3306);
        $masterDb = getenv('MASTER_DB_NAME') ?: 'callnow_master';

        $masterLink = mysqli_connect($host, $user, $pass, $masterDb, $port);
        if ($masterLink === false) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die("ERROR: Master database connection failed: " . mysqli_connect_error());
            }
            die("ERROR: System database connection failed. Please contact administrator.");
        }

        mysqli_set_charset($masterLink, 'utf8mb4');
        mysqli_report(MYSQLI_REPORT_OFF);
        return $masterLink;
    }
}

if (!function_exists('resolveTenant')) {
    function resolveTenant(string $companyInput): ?array {
        $raw = trim($companyInput);
        if ($raw === '') return null;
        $cleanCode = sanitizeCompanyCode($raw);

        $master = getMasterConnection();
        $stmt = mysqli_prepare($master, "SELECT id, company_code, company_name, db_name, db_host, db_port, db_user, db_pass, status FROM companies WHERE (company_code = ? OR company_name = ?) AND status = 'Active' LIMIT 1");
        if (!$stmt) return null;

        $checkCode = $cleanCode ?: '';
        mysqli_stmt_bind_param($stmt, 'ss', $checkCode, $raw);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $tenant = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$tenant || $tenant['status'] !== 'Active') {
            return null;
        }

        return $tenant;
    }
}

if (!function_exists('getTenantConnection')) {
    function getTenantConnection(?string $companyCode = null): mysqli {
        static $tenantLinks = [];

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }

        $resolvedCode = null;
        $cachedDb = null;

        if ($companyCode !== null && trim($companyCode) !== '') {
            $resolvedCode = sanitizeCompanyCode($companyCode);
        }

        if (!$resolvedCode && !empty($_SESSION['company_code'])) {
            $resolvedCode = sanitizeCompanyCode($_SESSION['company_code']);
            if (!empty($_SESSION['company_db'])) {
                $cachedDb = $_SESSION['company_db'];
            }
        }

        if (!$resolvedCode && !empty($_SERVER['HTTP_X_COMPANY_CODE'])) {
            $resolvedCode = sanitizeCompanyCode($_SERVER['HTTP_X_COMPANY_CODE']);
        }

        if (!$resolvedCode && !empty($_POST['CompanyName'])) {
            $resolvedCode = sanitizeCompanyCode($_POST['CompanyName']);
        }

        if (!$resolvedCode && !empty($_COOKIE['callnow_company_code'])) {
            $resolvedCode = sanitizeCompanyCode($_COOKIE['callnow_company_code']);
        }

        if (!$resolvedCode) {
            $defaultCode = getenv('DEFAULT_COMPANY_CODE') ?: 'incredit';
            $resolvedCode = sanitizeCompanyCode($defaultCode) ?: 'incredit';
        }

        if (isset($tenantLinks[$resolvedCode]) && $tenantLinks[$resolvedCode] instanceof mysqli && @mysqli_ping($tenantLinks[$resolvedCode])) {
            return $tenantLinks[$resolvedCode];
        }

        $tenant = resolveTenant($resolvedCode);
        if (!$tenant) {
            if (isset($_SESSION['company_code']) && $_SESSION['company_code'] === $resolvedCode) {
                unset($_SESSION['company_code'], $_SESSION['company_name'], $_SESSION['company_db']);
            }
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die("ERROR: Invalid company or company account is inactive: " . htmlspecialchars($resolvedCode, ENT_QUOTES, 'UTF-8'));
            }
            die("ERROR: Invalid company or company account is inactive.");
        }

        $defaultHost = getenv('DB_SERVERNAME') ?: (getenv('DB_HOST') ?: 'localhost');
        $defaultUser = getenv('DB_USERNAME') ?: 'root';
        $defaultPass = getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '';
        $defaultPort = (int)(getenv('DB_PORT') ?: 3306);

        $host = !empty($tenant['db_host']) ? $tenant['db_host'] : $defaultHost;
        $user = !empty($tenant['db_user']) ? $tenant['db_user'] : $defaultUser;
        $pass = $tenant['db_pass'] !== null ? (string)$tenant['db_pass'] : $defaultPass;
        $port = !empty($tenant['db_port']) ? (int)$tenant['db_port'] : $defaultPort;
        $db   = $tenant['db_name'];

        $link = mysqli_connect($host, $user, $pass, $db, $port);
        if ($link === false) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die("ERROR: Failed connecting to database for company '$resolvedCode': " . mysqli_connect_error());
            }
            die("ERROR: Failed connecting to company database. Please contact administrator.");
        }

        mysqli_set_charset($link, 'utf8mb4');
        mysqli_report(MYSQLI_REPORT_OFF);

        $tenantLinks[$resolvedCode] = $link;
        return $link;
    }
}

if (!function_exists('setTenantCookie')) {
    function setTenantCookie(string $companyCode): void {
        $clean = sanitizeCompanyCode($companyCode);
        if (!$clean || headers_sent()) return;

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        setcookie('callnow_company_code', $clean, [
            'expires' => time() + (30 * 86400),
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}

if (!function_exists('clearTenantCookie')) {
    function clearTenantCookie(): void {
        if (headers_sent()) return;
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        setcookie('callnow_company_code', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}

if (!function_exists('getTenantUploadDir')) {
    function getTenantUploadDir(string $subDir = ''): string {
        $companyCode = $_SESSION['company_code'] ?? ($_SERVER['HTTP_X_COMPANY_CODE'] ?? 'incredit');
        $clean = sanitizeCompanyCode($companyCode) ?: 'incredit';
        $base = dirname(__DIR__) . '/uploads/' . $clean . '/';
        if ($subDir !== '') {
            $cleanSub = preg_replace('/[^a-zA-Z0-9_\-]/', '', $subDir);
            $base .= $cleanSub . '/';
        }
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        return $base;
    }
}

if (!function_exists('getTenantUploadUrl')) {
    function getTenantUploadUrl(string $subDir = ''): string {
        $companyCode = $_SESSION['company_code'] ?? ($_SERVER['HTTP_X_COMPANY_CODE'] ?? 'incredit');
        $clean = sanitizeCompanyCode($companyCode) ?: 'incredit';
        $base = (defined('APP_BASE') ? APP_BASE : '') . '/uploads/' . $clean . '/';
        if ($subDir !== '') {
            $cleanSub = preg_replace('/[^a-zA-Z0-9_\-]/', '', $subDir);
            $base .= $cleanSub . '/';
        }
        return $base;
    }
}

