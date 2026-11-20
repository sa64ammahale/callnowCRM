-- ============================================================================
-- Migration: 2026_11_19_lead_distribution_engine.sql
-- Target: MariaDB 10.4.32+
-- Purpose: Schema, constraints, and composite indexes for Lead Distribution Engine
-- ============================================================================

CREATE TABLE IF NOT EXISTS `lead_batches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `batch_uuid` VARCHAR(64) NOT NULL,
    `claim_request_id` VARCHAR(64) NOT NULL,
    `company_code` VARCHAR(50) NOT NULL DEFAULT 'default',
    `telecaller_id` INT NOT NULL,
    `device_id` VARCHAR(100) NOT NULL,
    `session_epoch` INT NOT NULL DEFAULT 1,
    `allocated_count` INT NOT NULL DEFAULT 0,
    `lease_duration_seconds` INT NOT NULL DEFAULT 1200,
    `expires_at` DATETIME NOT NULL,
    `status` ENUM('ACTIVE', 'EXPIRED', 'RELEASED', 'COMPLETED') NOT NULL DEFAULT 'ACTIVE',
    `request_params_json` TEXT NULL,
    `request_hash` CHAR(64) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_batch_uuid` (`batch_uuid`),
    UNIQUE KEY `uniq_claim_request` (`company_code`, `telecaller_id`, `claim_request_id`),
    KEY `idx_batch_caller_status` (`telecaller_id`, `status`, `expires_at`),
    KEY `idx_batch_expiry` (`expires_at`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `lead_reservations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `batch_id` INT NOT NULL,
    `batch_uuid` VARCHAR(64) NOT NULL,
    `reservation_token` VARCHAR(64) NOT NULL,
    `call_lock_token` VARCHAR(64) NULL,
    `company_code` VARCHAR(50) NOT NULL DEFAULT 'default',
    `telecaller_id` INT NOT NULL,
    `device_id` VARCHAR(100) NOT NULL,
    `session_epoch` INT NOT NULL DEFAULT 1,
    `source` ENUM('TEMP', 'MAIN') NOT NULL,
    `lead_id` INT NOT NULL,
    `phone_number_10` VARCHAR(15) NOT NULL,
    `priority_tier` ENUM('P0', 'P1', 'P2', 'P3') NOT NULL,
    `followup_id` INT DEFAULT NULL,
    `state` ENUM('RESERVED', 'DIALING', 'COMPLETED', 'EXPIRED', 'RELEASED') NOT NULL DEFAULT 'RESERVED',
    `reserved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `dialing_started_at` DATETIME DEFAULT NULL,
    `last_heartbeat_at` DATETIME DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `call_log_id` INT DEFAULT NULL,
    `client_call_id` VARCHAR(128) DEFAULT NULL,
    `reconciliation_needed` TINYINT(1) NOT NULL DEFAULT 0,
    `active_phone_key` VARCHAR(30) GENERATED ALWAYS AS (
        CASE WHEN `state` IN ('RESERVED', 'DIALING') THEN `phone_number_10` ELSE NULL END
    ) VIRTUAL,
    `active_lead_key` VARCHAR(32) GENERATED ALWAYS AS (
        CASE WHEN `state` IN ('RESERVED', 'DIALING') THEN CONCAT(`source`, ':', `lead_id`) ELSE NULL END
    ) VIRTUAL,
    UNIQUE KEY `uniq_reservation_token` (`reservation_token`),
    UNIQUE KEY `uniq_call_lock_token` (`call_lock_token`),
    UNIQUE KEY `uniq_active_phone` (`active_phone_key`),
    UNIQUE KEY `uniq_active_lead` (`active_lead_key`),
    KEY `idx_res_batch` (`batch_id`),
    KEY `idx_res_caller_state_exp` (`telecaller_id`, `state`, `expires_at`),
    KEY `idx_res_lead_source` (`source`, `lead_id`, `state`),
    KEY `idx_res_phone` (`phone_number_10`),
    KEY `idx_res_dialing_reconciliation` (`state`, `dialing_started_at`, `reconciliation_needed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `lead_reservations` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE `lead_batches` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

UPDATE `lead_reservations` lr
INNER JOIN (
    SELECT `source`, `lead_id`, MAX(`id`) AS keep_id
    FROM `lead_reservations`
    WHERE `state` IN ('RESERVED', 'DIALING')
    GROUP BY `source`, `lead_id`
    HAVING COUNT(*) > 1
) d ON d.`source` = lr.`source` AND d.`lead_id` = lr.`lead_id`
SET lr.`state` = 'RELEASED'
WHERE lr.`state` IN ('RESERVED', 'DIALING') AND lr.`id` <> d.keep_id;

ALTER TABLE `lead_reservations`
    ADD COLUMN IF NOT EXISTS `active_lead_key` VARCHAR(32) GENERATED ALWAYS AS (
        CASE WHEN `state` IN ('RESERVED', 'DIALING') THEN CONCAT(`source`, ':', `lead_id`) ELSE NULL END
    ) VIRTUAL,
    ADD UNIQUE INDEX IF NOT EXISTS `uniq_active_lead` (`active_lead_key`);

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `session_epoch` INT NOT NULL DEFAULT 1;

ALTER TABLE `temporary_database`
    ADD COLUMN IF NOT EXISTS `CALL_COUNT` INT NOT NULL DEFAULT 0;

ALTER TABLE `temporary_database`
    ADD INDEX IF NOT EXISTS `idx_temp_waterfall_p0` (`CALL_DIALED_STATUS`, `CALL_COUNT`, `TEMP_UPLOAD_DATETIME`),
    ADD INDEX IF NOT EXISTS `idx_temp_waterfall_p2` (`CALL_COUNT`, `LAST_DIALED_DATE_TIME`, `CALL_DIALED_STATUS`);

ALTER TABLE `main_database`
    ADD INDEX IF NOT EXISTS `idx_main_waterfall_p0` (`MAINDATABASE_CALL_DIALED_STATUS`, `CALL_COUNT`, `MAINDATABASE_UPLOAD_DATETIME`),
    ADD INDEX IF NOT EXISTS `idx_main_waterfall_p2` (`CALL_COUNT`, `LAST_DIALED_DATE_TIME`, `MAINDATABASE_CALL_DIALED_STATUS`);

ALTER TABLE `lead_followups`
    ADD INDEX IF NOT EXISTS `idx_followups_user_status_due` (`user_id`, `status`, `followup_at`);
