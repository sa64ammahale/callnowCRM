-- =======================================================================
-- CALLNOW CRM — SERVER DATABASE MIGRATION SCRIPT (2026-11-16)
-- Purpose: Enforce strict 5-tier organizational hierarchy isolation
--          (Super Admin -> Admins -> Managers -> Supervisors -> Telecallers)
--          by adding explicit admin_id to teams and backfilling linkages.
-- Target Database: Live Tenant Database (e.g. callnow_incredit)
-- =======================================================================

-- 1. Add admin_id column to teams table if not present
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS 
               WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = 'teams' 
                 AND COLUMN_NAME = 'admin_id');
SET @sql := IF(@exist = 0, 'ALTER TABLE `teams` ADD COLUMN `admin_id` INT NULL DEFAULT NULL AFTER `MANAGER_ID`, ADD INDEX `idx_team_admin` (`admin_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Backfill existing teams' admin_id from their Manager or Supervisor
UPDATE `teams` t
LEFT JOIN `users` m ON t.MANAGER_ID = m.ID
LEFT JOIN `users` s ON t.SUPERVISOR_ID = s.ID
SET t.admin_id = COALESCE(m.admin_id, s.admin_id)
WHERE t.admin_id IS NULL AND COALESCE(m.admin_id, s.admin_id) IS NOT NULL;

-- 3. Backfill users' admin_id from their Team's admin_id
UPDATE `users` u
JOIN `teams` t ON u.TEAM_ID = t.ID
SET u.admin_id = t.admin_id
WHERE u.admin_id IS NULL AND t.admin_id IS NOT NULL;

-- 4. Invalidate cached RBAC permissions so sessions refresh immediately
UPDATE app_settings 
SET setting_value = CAST(COALESCE(NULLIF(setting_value, ''), '0') AS UNSIGNED) + 1 
WHERE setting_key = 'rbac_cache_version';
