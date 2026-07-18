<?php
/**
 * Create API tables for mobile telecaller access
 * Run once to set up: api_tokens, api_settings, api_access_logs
 */
require_once __DIR__ . '/config.php';

echo "Creating API tables...\n";

$tables = [
    'api_tokens' => "
        CREATE TABLE IF NOT EXISTS `api_tokens` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `token` VARCHAR(64) NOT NULL UNIQUE,
            `name` VARCHAR(100) DEFAULT 'Mobile App',
            `last_used_at` DATETIME NULL,
            `expires_at` DATETIME NULL,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_token` (`token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'api_settings' => "
        CREATE TABLE IF NOT EXISTS `api_settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `setting_key` VARCHAR(100) NOT NULL UNIQUE,
            `setting_value` TEXT NULL,
            `description` VARCHAR(255) NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'api_access_logs' => "
        CREATE TABLE IF NOT EXISTS `api_access_logs` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `token_id` INT NULL,
            `user_id` INT NULL,
            `endpoint` VARCHAR(255) NOT NULL,
            `method` VARCHAR(10) NOT NULL,
            `ip_address` VARCHAR(45) NULL,
            `user_agent` TEXT NULL,
            `response_code` INT NULL,
            `execution_time_ms` INT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_token_id` (`token_id`),
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'api_database_assignments' => "
        CREATE TABLE IF NOT EXISTS `api_database_assignments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `database_type` ENUM('temporary','main','leads') NOT NULL,
            `team_id` INT NULL,
            `assigned_by` INT NOT NULL,
            `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `is_active` TINYINT(1) DEFAULT 1,
            UNIQUE KEY `uk_user_db` (`user_id`, `database_type`, `team_id`),
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_team_id` (`team_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
];

foreach ($tables as $name => $sql) {
    if ($link->query($sql)) {
        echo "✓ Table '$name' created/verified\n";
    } else {
        echo "✗ Failed to create '$name': " . $link->error . "\n";
    }
}

// Default API settings
$defaultSettings = [
    'api_enabled' => ['1', 'Enable/disable mobile API access globally'],
    'rate_limit_per_minute' => ['60', 'Requests per minute per user (0 = unlimited)'],
    'rate_limit_per_hour' => ['1000', 'Requests per hour per user (0 = unlimited)'],
    'token_expiry_days' => ['90', 'Default token expiry in days (0 = never)'],
    'allowed_databases' => ['["temporary","leads"]', 'JSON array of allowed database types: temporary, main, leads'],
    'require_team_assignment' => ['1', 'Require team assignment for database access'],
    'log_requests' => ['1', 'Log all API requests for audit'],
];

foreach ($defaultSettings as $key => [$value, $desc]) {
    $stmt = $link->prepare("INSERT IGNORE INTO api_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $key, $value, $desc);
    $stmt->execute();
}

echo "\nDefault API settings inserted.\n";

// Create API permission
$permStmt = $link->prepare("INSERT IGNORE INTO permissions (permission_key, label, category, description, is_system) VALUES ('manage_api', 'Manage API Access', 'Administration', 'Configure mobile API settings and tokens', 1)");
$permStmt->execute();

// Assign to Admin and Manager roles
foreach (['Admin', 'Manager'] as $role) {
    $pid = $link->query("SELECT id FROM permissions WHERE permission_key = 'manage_api'")->fetch_assoc()['id'];
    $link->query("INSERT IGNORE INTO role_permissions (role, permission_key, permission_value) VALUES ('$role', 'manage_api', 1)");
}

echo "API permission 'manage_api' created and assigned to Admin/Manager.\n";
echo "\nSetup complete!\n";