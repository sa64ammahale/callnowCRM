<?php
if (!defined('APP_BASE')) {
    require_once __DIR__ . '/config.php';
}

function ensureReportingAndConversionSchema(mysqli $link): void {
    static $initialized = false;
    if ($initialized) return;

    // Helper to check column existence
    $hasColumn = static function(mysqli $link, string $table, string $column): bool {
        $check = $link->prepare("
            SELECT 1 FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1
        ");
        if (!$check) return false;
        $check->bind_param('ss', $table, $column);
        $check->execute();
        $res = $check->get_result()->fetch_assoc();
        $check->close();
        return !empty($res);
    };

    // Helper to check index existence
    $hasIndex = static function(mysqli $link, string $table, string $indexName): bool {
        $check = $link->prepare("
            SELECT 1 FROM information_schema.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1
        ");
        if (!$check) return false;
        $check->bind_param('ss', $table, $indexName);
        $check->execute();
        $res = $check->get_result()->fetch_assoc();
        $check->close();
        return !empty($res);
    };

    // 1. call_logs enhancements
    $callLogsTable = tn('TBL_CALL_LOGS');
    if (!$hasColumn($link, $callLogsTable, 'lead_id')) {
        @$link->query("ALTER TABLE `{$callLogsTable}` ADD COLUMN `lead_id` INT NULL DEFAULT NULL AFTER `record_id`");
    }
    if (!$hasColumn($link, $callLogsTable, 'client_contact_id')) {
        @$link->query("ALTER TABLE `{$callLogsTable}` ADD COLUMN `client_contact_id` INT NULL DEFAULT NULL AFTER `lead_id`");
    }
    if (!$hasIndex($link, $callLogsTable, 'idx_call_lead')) {
        @$link->query("ALTER TABLE `{$callLogsTable}` ADD INDEX `idx_call_lead` (`lead_id`)");
    }
    if (!$hasIndex($link, $callLogsTable, 'idx_call_contact')) {
        @$link->query("ALTER TABLE `{$callLogsTable}` ADD INDEX `idx_call_contact` (`client_contact_id`)");
    }

    // 2. lead_followups enhancements
    $followupsTable = tn('TBL_LEAD_FOLLOWUPS');
    @$link->query("ALTER TABLE `{$followupsTable}` MODIFY COLUMN `lead_id` INT NULL DEFAULT NULL");
    if (!$hasColumn($link, $followupsTable, 'client_contact_id')) {
        @$link->query("ALTER TABLE `{$followupsTable}` ADD COLUMN `client_contact_id` INT NULL DEFAULT NULL AFTER `lead_id`");
    }
    if (!$hasColumn($link, $followupsTable, 'contact_source')) {
        @$link->query("ALTER TABLE `{$followupsTable}` ADD COLUMN `contact_source` VARCHAR(10) NOT NULL DEFAULT 'MAIN' AFTER `client_contact_id`");
    }
    if (!$hasColumn($link, $followupsTable, 'source_call_id')) {
        @$link->query("ALTER TABLE `{$followupsTable}` ADD COLUMN `source_call_id` INT NULL DEFAULT NULL AFTER `contact_source`");
    }
    if (!$hasColumn($link, $followupsTable, 'completed_at')) {
        @$link->query("ALTER TABLE `{$followupsTable}` ADD COLUMN `completed_at` DATETIME NULL DEFAULT NULL AFTER `status`");
    }
    if (!$hasColumn($link, $followupsTable, 'completed_by')) {
        @$link->query("ALTER TABLE `{$followupsTable}` ADD COLUMN `completed_by` INT NULL DEFAULT NULL AFTER `completed_at`");
    }
    if (!$hasIndex($link, $followupsTable, 'idx_followup_contact')) {
        @$link->query("ALTER TABLE `{$followupsTable}` ADD INDEX `idx_followup_contact` (`client_contact_id`)");
    }
    if (!$hasIndex($link, $followupsTable, 'idx_followup_source_call')) {
        @$link->query("ALTER TABLE `{$followupsTable}` ADD INDEX `idx_followup_source_call` (`source_call_id`)");
    }

    // 3. contact_lead_conversions table
    @$link->query("
        CREATE TABLE IF NOT EXISTS `contact_lead_conversions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `client_contact_id` INT NOT NULL,
            `contact_source` ENUM('MAIN', 'TEMP') NOT NULL DEFAULT 'MAIN',
            `lead_id` INT NOT NULL,
            `converted_by` INT NOT NULL,
            `source_call_id` INT NULL DEFAULT NULL,
            `conversion_date` DATETIME NOT NULL,
            `loan_type` VARCHAR(100) NULL,
            `loan_amount` VARCHAR(50) NULL,
            `notes` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_conv_contact` (`client_contact_id`),
            INDEX `idx_conv_lead` (`lead_id`),
            INDEX `idx_conv_user` (`converted_by`),
            INDEX `idx_conv_date` (`conversion_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 4. reporting_targets table
    @$link->query("
        CREATE TABLE IF NOT EXISTS `reporting_targets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `role` VARCHAR(50) NOT NULL DEFAULT 'Officer',
            `daily_call_target` INT NOT NULL DEFAULT 100,
            `daily_connected_target` INT NOT NULL DEFAULT 30,
            `daily_talk_time_minutes` INT NOT NULL DEFAULT 120,
            `daily_lead_target` INT NOT NULL DEFAULT 3,
            `min_connect_rate_pct` DECIMAL(5,2) NOT NULL DEFAULT 25.00,
            `max_overdue_followups` INT NOT NULL DEFAULT 5,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_target_role` (`role`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Seed default targets if not present
    $seedTargets = [
        ['Officer', 100, 30, 120, 3, 25.00, 5],
        ['Supervisor', 400, 120, 480, 12, 25.00, 15],
        ['Admin', 1000, 300, 1200, 30, 25.00, 30]
    ];
    foreach ($seedTargets as $tgt) {
        $stmt = $link->prepare("
            INSERT INTO `reporting_targets` (`role`, `daily_call_target`, `daily_connected_target`, `daily_talk_time_minutes`, `daily_lead_target`, `min_connect_rate_pct`, `max_overdue_followups`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `daily_call_target` = `daily_call_target`
        ");
        if ($stmt) {
            $stmt->bind_param('siiiidi', $tgt[0], $tgt[1], $tgt[2], $tgt[3], $tgt[4], $tgt[5], $tgt[6]);
            $stmt->execute();
            $stmt->close();
        }
    }

    // 5. Backfill existing call_logs client_contact_id and lead_id where possible
    @$link->query("
        UPDATE `{$callLogsTable}` 
        SET `client_contact_id` = `record_id` 
        WHERE `client_contact_id` IS NULL AND `source` IN ('MAIN', 'TEMP')
    ");

    @$link->query("
        UPDATE `{$callLogsTable}` cl
        JOIN " . tn('TBL_LEADS') . " l ON (cl.client_contact_id = l.cust_id AND cl.source IN ('MAIN', 'TEMP'))
        SET cl.lead_id = l.lead_id
        WHERE cl.lead_id IS NULL
    ");

    $initialized = true;
}

