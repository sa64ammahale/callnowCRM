-- =======================================================================
-- CALLNOW CRM — SERVER DATABASE MIGRATION SCRIPT (2026-11-14)
-- Purpose: Apply schema changes for:
--   1. High-Concurrency Batch Leasing (BATCH_ASSIGNED_AT & composite indexes)
--   2. Permanent & Irreversible DND Registry (dnd_registry table & backfill)
-- Target Database: Live Tenant Database (e.g. callnow_incredit)
-- =======================================================================

-- 1. ADD BATCH LEASING COLUMN TO MAIN_DATABASE (If not already present)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = 'main_database' 
                 AND COLUMN_NAME = 'BATCH_ASSIGNED_AT');
SET @sql := IF(@exist = 0, 'ALTER TABLE `main_database` ADD COLUMN `BATCH_ASSIGNED_AT` datetime DEFAULT NULL AFTER `MAINDATABASE_CALL_DIALED_USER`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. ADD COMPOSITE COVERING INDEX TO MAIN_DATABASE
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS 
               WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = 'main_database' 
                 AND INDEX_NAME = 'idx_main_concurrency_batch');
SET @sql := IF(@exist = 0, 'ALTER TABLE `main_database` ADD INDEX `idx_main_concurrency_batch` (`MAINDATABASE_CALL_DIALED_STATUS`, `BATCH_ASSIGNED_AT`, `LAST_DIALED_DATE_TIME`, `ID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. ADD BATCH LEASING COLUMN TO TEMPORARY_DATABASE (If not already present)
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = 'temporary_database' 
                 AND COLUMN_NAME = 'BATCH_ASSIGNED_AT');
SET @sql := IF(@exist = 0, 'ALTER TABLE `temporary_database` ADD COLUMN `BATCH_ASSIGNED_AT` datetime DEFAULT NULL AFTER `CALL_DIALED_TELECALLER`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. ADD COMPOSITE COVERING INDEX TO TEMPORARY_DATABASE
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS 
               WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = 'temporary_database' 
                 AND INDEX_NAME = 'idx_temp_concurrency_batch');
SET @sql := IF(@exist = 0, 'ALTER TABLE `temporary_database` ADD INDEX `idx_temp_concurrency_batch` (`CALL_DIALED_STATUS`, `BATCH_ASSIGNED_AT`, `LAST_DIALED_DATE_TIME`, `ID`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. ADD DAILY CALLS INDEX TO CALL_LOGS
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS 
               WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = 'call_logs' 
                 AND INDEX_NAME = 'idx_logs_user_daily');
SET @sql := IF(@exist = 0, 'ALTER TABLE `call_logs` ADD INDEX `idx_logs_user_daily` (`user_id`, `created_at`, `call_duration`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. CREATE DND REGISTRY TABLE (Centralized & Permanent DND list)
CREATE TABLE IF NOT EXISTS `dnd_registry` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone_number` varchar(10) NOT NULL,
  `reason` varchar(255) DEFAULT 'Customer Requested DND',
  `source` varchar(50) DEFAULT 'CALL_RESULT',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dnd_phone` (`phone_number`),
  KEY `idx_dnd_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 7. HISTORICAL BACKFILL: Migrate existing DND numbers from main_database
INSERT IGNORE INTO `dnd_registry` (`phone_number`, `reason`, `source`, `created_at`)
SELECT DISTINCT 
    RIGHT(REGEXP_REPLACE(`MAINDATABASE_MOBILE`, '[^0-9]', ''), 10) AS phone_number,
    'Historical DND Migration' AS reason,
    'SYSTEM_MIGRATION' AS source,
    COALESCE(`LAST_DIALED_DATE_TIME`, NOW()) AS created_at
FROM `main_database`
WHERE LOWER(COALESCE(`MAINDATABASE_CALL_DIALED_STATUS`, '')) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
  AND CHAR_LENGTH(REGEXP_REPLACE(`MAINDATABASE_MOBILE`, '[^0-9]', '')) >= 10;

-- 8. HISTORICAL BACKFILL: Migrate existing DND numbers from temporary_database
INSERT IGNORE INTO `dnd_registry` (`phone_number`, `reason`, `source`, `created_at`)
SELECT DISTINCT 
    RIGHT(REGEXP_REPLACE(`CUST_MOBILE`, '[^0-9]', ''), 10) AS phone_number,
    'Historical DND Migration' AS reason,
    'SYSTEM_MIGRATION' AS source,
    COALESCE(`LAST_DIALED_DATE_TIME`, NOW()) AS created_at
FROM `temporary_database`
WHERE LOWER(COALESCE(`CALL_DIALED_STATUS`, '')) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
  AND CHAR_LENGTH(REGEXP_REPLACE(`CUST_MOBILE`, '[^0-9]', '')) >= 10;
