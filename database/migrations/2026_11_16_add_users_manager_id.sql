-- =======================================================================
-- CALLNOW CRM — SERVER DATABASE MIGRATION SCRIPT (2026-11-16)
-- Purpose: Add manager_id to users table for explicit 5-tier hierarchy
--          (Super Admin -> Admin -> Manager -> Supervisor -> Telecaller)
--          and backfill manager & admin linkages.
-- Target Database: Live Tenant Database (e.g. callnow_incredit) & Other Databases
-- =======================================================================

-- 1. Add manager_id column to users table if not present
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = 'users' 
                 AND COLUMN_NAME = 'manager_id');
SET @sql := IF(@exist = 0, 'ALTER TABLE `users` ADD COLUMN `manager_id` INT NULL DEFAULT NULL AFTER `supervisor_id`, ADD INDEX `idx_user_manager` (`manager_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Backfill users' manager_id from their team's MANAGER_ID
UPDATE `users` u
JOIN `teams` t ON u.TEAM_ID = t.ID
SET u.manager_id = t.MANAGER_ID
WHERE u.manager_id IS NULL AND t.MANAGER_ID IS NOT NULL AND u.ROLE NOT IN ('Super Admin', 'Admin', 'Manager');

-- 3. Backfill users' admin_id from their manager's admin_id (and fix any users where admin_id was mistakenly set to manager's ID)
UPDATE `users` u
JOIN `users` m ON u.manager_id = m.ID
SET u.admin_id = m.admin_id
WHERE (u.admin_id IS NULL OR u.admin_id = u.manager_id) AND m.admin_id IS NOT NULL;

-- 4. Invalidate cached RBAC permissions so sessions refresh immediately
UPDATE app_settings 
SET setting_value = CAST(COALESCE(NULLIF(setting_value, ''), '0') AS UNSIGNED) + 1 
WHERE setting_key = 'rbac_cache_version';
