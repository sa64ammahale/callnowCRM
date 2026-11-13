<?php

mysqli_report(MYSQLI_REPORT_OFF);

if (!function_exists('sanitizeCompanyCode')) {
    function sanitizeCompanyCode(?string $code): ?string {
        if ($code === null) return null;
        $clean = strtolower(trim($code));
        return preg_match('/^[a-z0-9_\-]+$/i', $clean) ? $clean : null;
    }
}

if (!function_exists('getMasterConnection')) {
    function getMasterConnection(): ?mysqli {
        static $masterLink = null;
        if ($masterLink instanceof mysqli && @mysqli_ping($masterLink)) {
            return $masterLink;
        }

        $host = getenv('DB_SERVERNAME') ?: (getenv('DB_HOST') ?: 'localhost');
        $user = getenv('DB_USERNAME') ?: 'root';
        $pass = getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '';
        $port = (int)(getenv('DB_PORT') ?: 3306);
        $masterDb = getenv('MASTER_DB_NAME') ?: 'callnow_master';

        $connectError = '';
        try {
            $masterLink = @mysqli_connect($host, $user, $pass, $masterDb, $port);
        } catch (\Throwable $e) {
            $masterLink = false;
            $connectError = $e->getMessage();
        }

        if ($masterLink === false) {
            $errMsg = $connectError ?: mysqli_connect_error();
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die("ERROR: Master database connection failed: " . htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8') . " [Host: $host, User: $user, MasterDB: $masterDb, Port: $port]");
            }
            return null;
        }

        mysqli_set_charset($masterLink, 'utf8mb4');
        return $masterLink;
    }
}

if (!defined('TBL_MASTER_COMPANIES')) {
    define('TBL_MASTER_COMPANIES', getenv('MASTER_COMPANIES_TABLE') ?: 'callnow_telecalling_companys');
}

if (!function_exists('resolveTenant')) {
    function resolveTenant(string $companyInput): ?array {
        $raw = trim($companyInput);
        if ($raw === '') return null;
        $cleanCode = sanitizeCompanyCode($raw);

        $master = getMasterConnection();
        if (!$master) {
            return null;
        }

        $tbl = defined('TBL_MASTER_COMPANIES') ? TBL_MASTER_COMPANIES : 'callnow_telecalling_companys';
        $stmt = false;
        $prepareError = '';
        try {
            $stmt = @mysqli_prepare($master, "SELECT * FROM `{$tbl}` WHERE (company_code = ? OR company_name = ?) AND status = 'Active' LIMIT 1");
        } catch (\Throwable $e) {
            $stmt = false;
            $prepareError = $e->getMessage();
        }

        if (!$stmt) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $err = $prepareError ?: mysqli_error($master);
                die("ERROR: Failed querying master registry table `{$tbl}`: " . htmlspecialchars($err, ENT_QUOTES, 'UTF-8'));
            }
            return null;
        }

        $checkCode = $cleanCode ?: '';
        mysqli_stmt_bind_param($stmt, 'ss', $checkCode, $raw);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $tenant = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$tenant || $tenant['status'] !== 'Active') {
            return null;
        }

        if (empty($tenant['db_name']) && !empty($tenant['database_name'])) {
            $tenant['db_name'] = $tenant['database_name'];
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

        $defaultCode = getenv('DEFAULT_COMPANY_CODE') ?: 'incredit';
        if (!$resolvedCode) {
            $resolvedCode = sanitizeCompanyCode($defaultCode) ?: 'incredit';
        }

        if (isset($tenantLinks[$resolvedCode]) && $tenantLinks[$resolvedCode] instanceof mysqli && @mysqli_ping($tenantLinks[$resolvedCode])) {
            return $tenantLinks[$resolvedCode];
        }

        $tenant = resolveTenant($resolvedCode);
        $defaultHost = getenv('DB_SERVERNAME') ?: (getenv('DB_HOST') ?: 'localhost');
        $defaultUser = getenv('DB_USERNAME') ?: 'root';
        $defaultPass = getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '';
        $defaultPort = (int)(getenv('DB_PORT') ?: 3306);

        if (!$tenant) {
            $fallbackDb = getenv('DB_NAME');
            if ($fallbackDb && ($resolvedCode === $defaultCode || empty($_SESSION['company_code']))) {
                $host = $defaultHost;
                $user = $defaultUser;
                $pass = $defaultPass;
                $port = $defaultPort;
                $db   = $fallbackDb;
            } else {
                if (isset($_SESSION['company_code']) && $_SESSION['company_code'] === $resolvedCode) {
                    unset($_SESSION['company_code'], $_SESSION['company_name'], $_SESSION['company_db']);
                }
                if (defined('APP_DEBUG') && APP_DEBUG) {
                    die("ERROR: Invalid company or company account is inactive: " . htmlspecialchars($resolvedCode, ENT_QUOTES, 'UTF-8'));
                }
                die("ERROR: Invalid company or company account is inactive.");
            }
        } else {
            $host = !empty($tenant['db_host']) ? $tenant['db_host'] : $defaultHost;
            $user = !empty($tenant['db_user']) ? $tenant['db_user'] : $defaultUser;
            $pass = $tenant['db_pass'] !== null ? (string)$tenant['db_pass'] : $defaultPass;
            $port = !empty($tenant['db_port']) ? (int)$tenant['db_port'] : $defaultPort;
            $db   = $tenant['db_name'];
        }

        $connectError = '';
        try {
            $link = @mysqli_connect($host, $user, $pass, $db, $port);
        } catch (\Throwable $e) {
            $link = false;
            $connectError = $e->getMessage();
        }

        if ($link === false) {
            $errMsg = $connectError ?: mysqli_connect_error();
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die("ERROR: Failed connecting to database '$db' for company '$resolvedCode': " . htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8'));
            }
            die("ERROR: Failed connecting to company database. Please contact administrator.");
        }

        mysqli_set_charset($link, 'utf8mb4');

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

