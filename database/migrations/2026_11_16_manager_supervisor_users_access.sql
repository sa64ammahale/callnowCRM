-- =======================================================================
-- CALLNOW CRM — SERVER DATABASE MIGRATION SCRIPT (2026-11-16)
-- Purpose: Grant Manager & Supervisor roles access to manage controllable
--          users in the CRM & fix permission key typo.
-- Target Database: Live Tenant Database (e.g. callnow_incredit)
-- =======================================================================

-- 1. Fix permission key typos in permissions and role_permissions if present
UPDATE permissions SET permission_key = 'manage_users', label = 'Manage Users', description = 'Create, edit and manage user accounts' WHERE permission_key = 'manage_TBL_USERS';
UPDATE permissions SET permission_key = 'manage_teams', label = 'Manage Teams', description = 'Create teams and assign members' WHERE permission_key = 'manage_TBL_TEAMS';
UPDATE role_permissions SET permission_key = 'manage_users' WHERE permission_key = 'manage_TBL_USERS';
UPDATE role_permissions SET permission_key = 'manage_teams' WHERE permission_key = 'manage_TBL_TEAMS';

-- 2. Ensure manage_users permission key exists in permissions table
INSERT IGNORE INTO permissions (permission_key, label, category, description, is_system, created_at)
VALUES ('manage_users', 'Manage Users', 'Administration', 'Create, edit and manage controllable user accounts', 1, NOW());

-- 3. Grant manage_users to Manager and Supervisor roles
INSERT INTO role_permissions (role, permission_key, permission_value)
VALUES ('Manager', 'manage_users', 1)
ON DUPLICATE KEY UPDATE permission_value = 1;

INSERT INTO role_permissions (role, permission_key, permission_value)
VALUES ('Supervisor', 'manage_users', 1)
ON DUPLICATE KEY UPDATE permission_value = 1;

-- 4. Invalidate cached RBAC permissions so sessions refresh immediately
UPDATE app_settings 
SET setting_value = CAST(COALESCE(NULLIF(setting_value, ''), '0') AS UNSIGNED) + 1 
WHERE setting_key = 'rbac_cache_version';
