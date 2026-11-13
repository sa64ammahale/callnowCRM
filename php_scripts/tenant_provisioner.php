<?php

require_once __DIR__ . '/tenant.php';

if (!function_exists('listTenantCompanies')) {
    function listTenantCompanies(): array {
        $master = getMasterConnection();
        $tbl = defined('TBL_MASTER_COMPANIES') ? TBL_MASTER_COMPANIES : 'callnow_telecalling_companys';
        $res = mysqli_query($master, "SELECT id, company_code, company_name, db_name, db_host, db_port, status, created_at, updated_at FROM `{$tbl}` ORDER BY id ASC");
        if (!$res) return [];
        $list = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $list[] = $row;
        }
        return $list;
    }
}

if (!function_exists('setTenantStatus')) {
    function setTenantStatus(string $companyCode, string $status): bool {
        $clean = sanitizeCompanyCode($companyCode);
        if (!$clean || !in_array($status, ['Active', 'Inactive', 'Suspended'], true)) {
            return false;
        }
        $master = getMasterConnection();
        $tbl = defined('TBL_MASTER_COMPANIES') ? TBL_MASTER_COMPANIES : 'callnow_telecalling_companys';
        $stmt = mysqli_prepare($master, "UPDATE `{$tbl}` SET status = ? WHERE company_code = ?");
        if (!$stmt) return false;
        mysqli_stmt_bind_param($stmt, 'ss', $status, $clean);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

if (!function_exists('provisionTenantDatabase')) {
    function provisionTenantDatabase(
        string $companyCode,
        string $companyName,
        ?string $dbName = null,
        ?string $adminEmail = null,
        ?string $adminPassword = null,
        ?string $adminName = null,
        ?string $dbHost = 'localhost',
        ?int $dbPort = 3306,
        ?string $dbUser = null,
        ?string $dbPass = null
    ): array {
        $cleanCode = sanitizeCompanyCode($companyCode);
        if (!$cleanCode) {
            return ['success' => false, 'error' => 'Invalid company code format. Use lowercase alphanumeric and hyphens.'];
        }

        $master = getMasterConnection();
        $tbl = defined('TBL_MASTER_COMPANIES') ? TBL_MASTER_COMPANIES : 'callnow_telecalling_companys';
        $checkStmt = mysqli_prepare($master, "SELECT id FROM `{$tbl}` WHERE company_code = ? LIMIT 1");
        mysqli_stmt_bind_param($checkStmt, 's', $cleanCode);
        mysqli_stmt_execute($checkStmt);
        $exists = mysqli_stmt_get_result($checkStmt)->fetch_assoc();
        mysqli_stmt_close($checkStmt);

        if ($exists) {
            return ['success' => false, 'error' => "Company code '$cleanCode' already exists."];
        }

        $targetDb = $dbName ? strtolower(trim($dbName)) : ('callnow_' . $cleanCode);
        if (!preg_match('/^[a-z0-9_]+$/', $targetDb)) {
            return ['success' => false, 'error' => 'Database name may only contain lowercase letters, numbers, and underscores.'];
        }
        $host = $dbHost ?: (getenv('DB_SERVERNAME') ?: 'localhost');
        $port = $dbPort ?: (int)(getenv('DB_PORT') ?: 3306);
        $user = $dbUser ?: (getenv('DB_USERNAME') ?: 'root');
        $pass = $dbPass !== null ? (string)$dbPass : (getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '');

        $rootLink = @mysqli_connect($host, $user, $pass, '', $port);
        if ($rootLink) {
            @mysqli_query($rootLink, "CREATE DATABASE IF NOT EXISTS `" . mysqli_real_escape_string($rootLink, $targetDb) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            mysqli_close($rootLink);
        }

        $tenantLink = @mysqli_connect($host, $user, $pass, $targetDb, $port);
        if (!$tenantLink) {
            return ['success' => false, 'error' => "Failed to connect to database '$targetDb': " . mysqli_connect_error()];
        }
        mysqli_set_charset($tenantLink, 'utf8mb4');

        $schemaFile = dirname(__DIR__) . '/database/hostinger_schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            if (mysqli_multi_query($tenantLink, $sql)) {
                do {
                    if ($res = mysqli_store_result($tenantLink)) {
                        mysqli_free_result($res);
                    }
                } while (mysqli_more_results($tenantLink) && mysqli_next_result($tenantLink));
            }
        }

        if ($adminEmail && $adminPassword) {
            $email = trim($adminEmail);
            $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $name = $adminName ? trim($adminName) : 'Administrator';
            $insStmt = mysqli_prepare($tenantLink, "INSERT INTO users (NAME, MOBILE, EMAIL, COMPANY, ROLE, PASSWORD, LOGIN_ID, STATUS) VALUES (?, '9999999999', ?, ?, 'Admin', ?, ?, 'Active') ON DUPLICATE KEY UPDATE PASSWORD = VALUES(PASSWORD), STATUS = 'Active'");
            if ($insStmt) {
                mysqli_stmt_bind_param($insStmt, 'sssss', $name, $email, $companyName, $hash, $email);
                mysqli_stmt_execute($insStmt);
                mysqli_stmt_close($insStmt);
            }
        }

        mysqli_close($tenantLink);

        $insMaster = mysqli_prepare($master, "INSERT INTO `{$tbl}` (company_code, company_name, db_name, db_host, db_port, db_user, db_pass, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')");
        if (!$insMaster) {
            return ['success' => false, 'error' => "Failed preparing master insert: " . mysqli_error($master)];
        }
        mysqli_stmt_bind_param($insMaster, 'ssssiss', $cleanCode, $companyName, $targetDb, $host, $port, $dbUser, $dbPass);
        $ok = mysqli_stmt_execute($insMaster);
        mysqli_stmt_close($insMaster);

        if (!$ok) {
            return ['success' => false, 'error' => "Failed registering company in master DB: " . mysqli_error($master)];
        }

        return [
            'success' => true,
            'company_code' => $cleanCode,
            'company_name' => $companyName,
            'db_name' => $targetDb,
        ];
    }
}
