<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../config.php';

function ensureSuperAdminSchema(mysqli $link): void {
    static $done = false;
    if ($done) return;

    mysqli_query($link, "CREATE TABLE IF NOT EXISTS `super_admin_plans` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `plan_name` VARCHAR(100) NOT NULL,
        `plan_slug` VARCHAR(100) NOT NULL,
        `max_users` INT(11) NOT NULL DEFAULT 10,
        `max_api_tokens` INT(11) NOT NULL DEFAULT 5,
        `max_api_calls_per_minute` INT(11) NOT NULL DEFAULT 60,
        `max_api_calls_per_hour` INT(11) NOT NULL DEFAULT 1000,
        `max_db_records` INT(11) NOT NULL DEFAULT 50000,
        `max_storage_mb` INT(11) NOT NULL DEFAULT 500,
        `price_monthly` DECIMAL(10,2) DEFAULT NULL,
        `price_yearly` DECIMAL(10,2) DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `is_default` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_plan_slug` (`plan_slug`),
        KEY `idx_is_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    mysqli_query($link, "CREATE TABLE IF NOT EXISTS `super_admin_account` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `current_plan_id` INT(11) DEFAULT NULL,
        `company_name` VARCHAR(255) DEFAULT NULL,
        `billing_email` VARCHAR(255) DEFAULT NULL,
        `payment_status` ENUM('trial','active','overdue','cancelled') NOT NULL DEFAULT 'trial',
        `current_period_ends_at` DATETIME DEFAULT NULL,
        `trial_ends_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_plan_id` (`current_plan_id`),
        KEY `idx_payment_status` (`payment_status`),
        CONSTRAINT `fk_super_admin_plan` FOREIGN KEY (`current_plan_id`) REFERENCES `super_admin_plans` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    mysqli_query($link, "CREATE TABLE IF NOT EXISTS `super_admin_limit_logs` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `limit_type` ENUM('users','api_tokens','api_calls','storage','access') NOT NULL,
        `action` VARCHAR(100) NOT NULL,
        `requested_value` VARCHAR(255) DEFAULT NULL,
        `allowed_value` VARCHAR(255) DEFAULT NULL,
        `current_usage` VARCHAR(255) DEFAULT NULL,
        `user_id` INT(11) DEFAULT NULL,
        `ip_address` VARCHAR(45) DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_limit_type` (`limit_type`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_created_at` (`created_at`),
        CONSTRAINT `fk_limit_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`ID`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $done = true;
}

function seedManageSuperAdminPermission(mysqli $link): void {
    $perm = 'manage_super_admin';
    $label = 'Manage Super Admin Panel';
    $category = 'Administration';
    $description = 'Access the super admin panel for limits, plans, and payment settings';
    $is_system = 1;
    $stmt = mysqli_prepare($link, "INSERT IGNORE INTO " . tn('TBL_PERMISSIONS') . " (permission_key, label, category, description, is_system) VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'ssssi', $perm, $label, $category, $description, $is_system);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function getCurrentPlan(mysqli $link): ?array {
    ensureSuperAdminSchema($link);
    $res = mysqli_query($link, "SELECT p.* FROM `super_admin_plans` p JOIN `super_admin_account` a ON a.current_plan_id = p.id WHERE a.id = 1 LIMIT 1");
    if ($res && $row = mysqli_fetch_assoc($res)) return $row;
    return null;
}

function getDefaultPlan(mysqli $link): ?array {
    ensureSuperAdminSchema($link);
    $res = mysqli_query($link, "SELECT * FROM `super_admin_plans` WHERE is_default = 1 AND is_active = 1 LIMIT 1");
    if ($res && $row = mysqli_fetch_assoc($res)) return $row;
    return null;
}

function getActivePlans(mysqli $link): array {
    ensureSuperAdminSchema($link);
    $plans = [];
    $res = mysqli_query($link, "SELECT * FROM `super_admin_plans` WHERE is_active = 1 ORDER BY id");
    if ($res) while ($r = mysqli_fetch_assoc($res)) $plans[] = $r;
    return $plans;
}

function getEffectivePlan(mysqli $link): array {
    $plan = getCurrentPlan($link);
    if ($plan) return $plan;
    $plan = getDefaultPlan($link);
    if ($plan) return $plan;
    return [
        'id' => 0, 'plan_name' => 'Free', 'plan_slug' => 'free',
        'max_users' => 10, 'max_api_tokens' => 5,
        'max_api_calls_per_minute' => 60, 'max_api_calls_per_hour' => 1000,
        'max_db_records' => 50000, 'max_storage_mb' => 500
    ];
}

function countTotalUsers(mysqli $link): int {
    $res = mysqli_query($link, "SELECT COUNT(*) FROM `users`");
    return $res ? (int)mysqli_fetch_row($res)[0] : 0;
}

function countTotalDbRecords(mysqli $link): int {
    $tables = ['temporary_database', 'main_database', 'leads_table'];
    $total = 0;
    foreach ($tables as $t) {
        $res = @mysqli_query($link, "SELECT COUNT(*) FROM `$t`");
        if ($res) $total += (int)mysqli_fetch_row($res)[0];
    }
    return $total;
}

function estimateStorageMb(mysqli $link): float {
    $res = @mysqli_query($link, "SELECT SUM(data_length + index_length) / 1024 / 1024 AS mb FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name IN ('temporary_database','main_database','leads_table','main_database_archive','lead_notes','lead_followups','lead_assignments')");
    if ($res && $row = mysqli_fetch_assoc($res)) return round((float)($row['mb'] ?? 0), 2);
    return 0.0;
}

function countActiveApiTokens(mysqli $link): int {
    $res = @mysqli_query($link, "SELECT COUNT(*) FROM `api_tokens` WHERE is_active = 1");
    return $res ? (int)mysqli_fetch_row($res)[0] : 0;
}

function logLimitViolation(mysqli $link, string $type, string $action, ?string $requested, ?string $allowed, ?string $usage, ?int $userId = null): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $uid = $userId ?? (defined('USER_ID') ? USER_ID : 0);
    $stmt = mysqli_prepare($link, "INSERT INTO `super_admin_limit_logs` (limit_type, action, requested_value, allowed_value, current_usage, user_id, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "sssssis", $type, $action, $requested, $allowed, $usage, $uid, $ip);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function checkUserLimit(mysqli $link, ?int $triggeredByUserId = null): ?string {
    $plan = getEffectivePlan($link);
    $maxUsers = (int)($plan['max_users'] ?? 10);
    $current = countTotalUsers($link);
    if ($current >= $maxUsers) {
        logLimitViolation($link, 'users', 'create_user', (string)($current + 1), (string)$maxUsers, (string)$current, $triggeredByUserId);
        return "User limit reached: maximum {$maxUsers} users allowed on your current plan ({$plan['plan_name']}). Current users: {$current}.";
    }
    return null;
}

function checkApiTokenLimit(mysqli $link, ?int $triggeredByUserId = null): ?string {
    $plan = getEffectivePlan($link);
    $maxTokens = (int)($plan['max_api_tokens'] ?? 5);
    $current = countActiveApiTokens($link);
    if ($current >= $maxTokens) {
        logLimitViolation($link, 'api_tokens', 'create_token', (string)($current + 1), (string)$maxTokens, (string)$current, $triggeredByUserId);
        return "API token limit reached: maximum {$maxTokens} active tokens allowed on your current plan ({$plan['plan_name']}). Current tokens: {$current}.";
    }
    return null;
}

function checkStorageLimit(mysqli $link, int $additionalRecords = 0, ?int $triggeredByUserId = null): ?string {
    $plan = getEffectivePlan($link);
    $maxRecords = (int)($plan['max_db_records'] ?? 50000);
    $current = countTotalDbRecords($link);
    if ($current + $additionalRecords > $maxRecords) {
        logLimitViolation($link, 'storage', 'upload_data', (string)($current + $additionalRecords), (string)$maxRecords, (string)$current, $triggeredByUserId);
        return "Storage limit exceeded: maximum {$maxRecords} database records allowed on your current plan ({$plan['plan_name']}). Current records: {$current}.";
    }
    $maxMb = (int)($plan['max_storage_mb'] ?? 500);
    $currentMb = estimateStorageMb($link);
    if ($currentMb >= $maxMb) {
        logLimitViolation($link, 'storage', 'upload_data', "{$currentMb}MB", "{$maxMb}MB", "{$currentMb}MB", $triggeredByUserId);
        return "Storage limit exceeded: maximum {$maxMb} MB allowed on your current plan ({$plan['plan_name']}). Current usage: " . round($currentMb, 1) . " MB.";
    }
    return null;
}

function getLimitUsageSummary(mysqli $link): array {
    $plan = getEffectivePlan($link);
    $totalUsers = countTotalUsers($link);
    $totalRecords = countTotalDbRecords($link);
    $storageMb = estimateStorageMb($link);
    $activeTokens = countActiveApiTokens($link);

    return [
        'plan' => $plan,
        'users' => [
            'current' => $totalUsers,
            'max' => (int)($plan['max_users'] ?? 10),
            'pct' => $plan['max_users'] > 0 ? min(100, round(($totalUsers / (int)($plan['max_users'] ?? 10)) * 100)) : 0,
        ],
        'api_tokens' => [
            'current' => $activeTokens,
            'max' => (int)($plan['max_api_tokens'] ?? 5),
            'pct' => $plan['max_api_tokens'] > 0 ? min(100, round(($activeTokens / (int)($plan['max_api_tokens'] ?? 5)) * 100)) : 0,
        ],
        'api_calls' => [
            'current' => 0,
            'max_per_min' => (int)($plan['max_api_calls_per_minute'] ?? 60),
            'max_per_hour' => (int)($plan['max_api_calls_per_hour'] ?? 1000),
            'pct' => 0,
        ],
        'storage_records' => [
            'current' => $totalRecords,
            'max' => (int)($plan['max_db_records'] ?? 50000),
            'pct' => $plan['max_db_records'] > 0 ? min(100, round(($totalRecords / (int)($plan['max_db_records'] ?? 50000)) * 100)) : 0,
        ],
        'storage_mb' => [
            'current' => $storageMb,
            'max' => (int)($plan['max_storage_mb'] ?? 500),
            'pct' => $plan['max_storage_mb'] > 0 ? min(100, round(($storageMb / (int)($plan['max_storage_mb'] ?? 500)) * 100)) : 0,
        ],
    ];
}

function seedDefaultPlan(mysqli $link): void {
    ensureSuperAdminSchema($link);
    $stmt = mysqli_prepare($link, "INSERT IGNORE INTO `super_admin_plans` (`id`, `plan_name`, `plan_slug`, `max_users`, `max_api_tokens`, `max_api_calls_per_minute`, `max_api_calls_per_hour`, `max_db_records`, `max_storage_mb`, `is_active`, `is_default`) VALUES (1, 'Starter', 'starter', 10, 5, 60, 1000, 50000, 500, 1, 1)");
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $stmt2 = mysqli_prepare($link, "INSERT IGNORE INTO `super_admin_account` (`id`, `current_plan_id`, `company_name`, `payment_status`, `trial_ends_at`) VALUES (1, 1, 'CallNow', 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY))");
    mysqli_stmt_execute($stmt2);
    mysqli_stmt_close($stmt2);
}

function seedDefaultSuperAdmin(mysqli $link): void {
    static $done = false;
    if ($done) return;

    $loginId = 'sangammahale02@gmail.com';
    $plainPassword = 'Sangam@12345';
    $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
    $name = 'Super Admin';

    $check = mysqli_prepare($link, "SELECT ID FROM " . tn('TBL_USERS') . " WHERE LOGIN_ID = ? LIMIT 1");
    mysqli_stmt_bind_param($check, 's', $loginId);
    mysqli_stmt_execute($check);
    mysqli_stmt_store_result($check);
    $exists = mysqli_stmt_num_rows($check) > 0;
    mysqli_stmt_close($check);

    $userId = 0;
    if (!$exists) {
        $stmt = mysqli_prepare($link, "INSERT INTO " . tn('TBL_USERS') . " (NAME, LOGIN_ID, PASSWORD, ROLE, STATUS, COMPANY, EMAIL) VALUES (?, ?, ?, 'Super Admin', 'Active', 'CallNow', ?)");
        mysqli_stmt_bind_param($stmt, 'ssss', $name, $loginId, $hashedPassword, $loginId);
        mysqli_stmt_execute($stmt);
        $userId = (int)mysqli_insert_id($link);
        mysqli_stmt_close($stmt);
    } else {
        $res = mysqli_query($link, "SELECT ID FROM " . tn('TBL_USERS') . " WHERE LOGIN_ID = '" . mysqli_real_escape_string($link, $loginId) . "' LIMIT 1");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $userId = (int)$row['ID'];
            if ($userId > 0) {
                mysqli_query($link, "UPDATE " . tn('TBL_USERS') . " SET ROLE = 'Super Admin' WHERE ID = " . (int)$userId);
            }
        }
    }

    if ($userId > 0) {
        $perm = mysqli_prepare($link, "INSERT IGNORE INTO " . tn('TBL_USER_PERMISSIONS') . " (user_id, permission_key, permission_value) VALUES (?, 'manage_super_admin', 1)");
        mysqli_stmt_bind_param($perm, 'i', $userId);
        mysqli_stmt_execute($perm);
        mysqli_stmt_close($perm);
    }

    $roleCheck = mysqli_query($link, "SELECT role_name FROM " . tn('TBL_ROLES') . " WHERE role_name = 'Super Admin' LIMIT 1");
    if (!$roleCheck || !mysqli_fetch_assoc($roleCheck)) {
        mysqli_query($link, "INSERT IGNORE INTO " . tn('TBL_ROLES') . " (role_name, description, is_system) VALUES ('Super Admin', 'Hidden system owner with full access', 1)");
    }

    $done = true;
}
