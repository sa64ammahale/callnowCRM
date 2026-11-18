-- ─────────────────────────────────────────────────────────────
-- Migration: 2026_11_18_call_bucket_leases.sql
-- Description: Server-authoritative call bucket leases for mobile app
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `call_buckets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bucket_uuid` VARCHAR(64) NOT NULL,
    `company_code` VARCHAR(50) NOT NULL DEFAULT 'default',
    `telecaller_id` INT NOT NULL,
    `state` ENUM('active', 'closed', 'expired') NOT NULL DEFAULT 'active',
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    `closed_at` DATETIME NULL DEFAULT NULL,
    `total_leads` INT NOT NULL DEFAULT 15,
    `completed_calls` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_bucket_uuid` (`bucket_uuid`),
    KEY `idx_telecaller_state` (`telecaller_id`, `state`, `expires_at`),
    KEY `idx_expires` (`expires_at`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `call_bucket_leads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bucket_id` INT NOT NULL,
    `bucket_uuid` VARCHAR(64) NOT NULL,
    `source` ENUM('TEMP', 'MAIN') NOT NULL DEFAULT 'TEMP',
    `lead_id` INT NOT NULL,
    `status` ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
    `call_log_id` INT NULL DEFAULT NULL,
    `completed_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_bucket` (`bucket_id`),
    KEY `idx_bucket_uuid` (`bucket_uuid`),
    KEY `idx_bucket_lead` (`bucket_uuid`, `source`, `lead_id`),
    KEY `idx_lead_source` (`source`, `lead_id`, `status`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
