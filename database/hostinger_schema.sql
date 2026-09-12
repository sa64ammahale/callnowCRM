-- ============================================================
-- CallNow CRM V5.00 — Hostinger-ready Database Schema
-- ============================================================
-- Safe to run multiple times (CREATE TABLE IF NOT EXISTS).
-- Uses INSERT IGNORE for seed data.
--
-- How to use:
--   1. Create a database in Hostinger hPanel
--   2. Open phpMyAdmin → select your database
--   3. Click Import → choose this file → Go
--
-- Or via MySQL CLI:
--   mysql -u USER -p DATABASE_NAME < database/hostinger_schema.sql
-- ============================================================
-- Server: MySQL 8 / MariaDB 10.4+ (Hostinger shared hosting)
-- ============================================================

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
SET NAMES utf8mb4;

-- ============================================================
-- 1. USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `NAME` varchar(100) NOT NULL,
  `MOBILE` varchar(15) NOT NULL,
  `EMAIL` varchar(255) DEFAULT NULL,
  `COMPANY_NAME` varchar(255) DEFAULT NULL,
  `COMPANY` varchar(100) NOT NULL DEFAULT 'CallNow',
  `PACKAGE` varchar(100) DEFAULT '',
  `STATUS` enum('Active','Inactive','Suspended') DEFAULT 'Active',
  `JOIN_DATE` datetime DEFAULT current_timestamp(),
  `ROLE` varchar(50) DEFAULT 'Officer',
  `role_id` int(11) DEFAULT NULL,
  `TEAM_ID` int(11) DEFAULT NULL,
  `PASSWORD` varchar(255) NOT NULL,
  `LOGIN_ID` varchar(100) NOT NULL,
  `DEVICE_ID` varchar(255) DEFAULT NULL,
  `LAST_LOGIN` datetime DEFAULT NULL,
  `CREATED_AT` datetime DEFAULT current_timestamp(),
  `UPDATED_AT` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `LOGIN_ID` (`LOGIN_ID`),
  UNIQUE KEY `idx_email` (`EMAIL`),
  KEY `idx_role` (`ROLE`),
  KEY `idx_team` (`TEAM_ID`),
  KEY `idx_status` (`STATUS`),
  KEY `idx_company` (`COMPANY`),
  KEY `idx_login_id` (`LOGIN_ID`),
  KEY `idx_mobile` (`MOBILE`),
  KEY `idx_team_role` (`TEAM_ID`,`ROLE`),
  KEY `fk_user_role` (`role_id`),
  CONSTRAINT `fk_user_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_team` FOREIGN KEY (`TEAM_ID`) REFERENCES `teams` (`ID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `users` (`NAME`, `MOBILE`, `EMAIL`, `COMPANY`, `STATUS`, `ROLE`, `role_id`, `PASSWORD`, `LOGIN_ID`)
VALUES ('System Administrator', '9999999999', 'admin@callnow.com', 'CallNow', 'Active', 'Super Admin', 1, '$2y$10$mp33ADcNyz9KvGqQfsiOpO40xynqmZ3tsruRcFhUJn7pULBeFnVZW', 'admin@callnow.com');

-- ============================================================
-- 2. ROLES
-- ============================================================
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT '',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `roles` (`id`, `role_name`, `description`, `is_system`, `created_at`) VALUES
(1, 'Super Admin', 'Hidden system owner with full access', 1, NOW()),
(2, 'Admin',      'Full system access — all permissions.', 1, NOW()),
(3, 'Manager',    'Manages teams, users, leads, and data operations.', 1, NOW()),
(4, 'Supervisor', 'Oversees team leads and reports.', 1, NOW()),
(5, 'Officer',    'Basic lead management only.', 1, NOW());

-- ============================================================
-- 3. PERMISSIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT 'General',
  `description` text DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_key` (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `permissions` (`permission_key`, `label`, `category`, `description`, `is_system`, `created_at`) VALUES
('manage_TBL_USERS',    'Manage TBL_USERS',     'Administration', 'Create, edit and delete user accounts', 1, NOW()),
('manage_TBL_TEAMS',    'Manage TBL_TEAMS',      'Administration', 'Create TBL_TEAMS and assign members', 1, NOW()),
('manage_settings',     'Manage Settings',   'Administration', 'Access the Settings hub and permission manager', 1, NOW()),
('view_activity',       'View Activity Log', 'Administration', 'Access the audit / activity log', 1, NOW()),
('manage_database',     'Manage Database',   'Data', 'Edit, delete and transfer records in temporary & main databases', 1, NOW()),
('upload_data',         'Upload Data',       'Data', 'Import CSV files into the databases', 1, NOW()),
('export_data',         'Export Data',       'Data', 'Export database lists to CSV', 1, NOW()),
('assign_leads',        'Assign Leads',      'Leads', 'Assign leads / numbers to telecallers', 1, NOW()),
('manage_leads',        'Manage Leads',      'Leads', 'Create and edit leads', 1, NOW()),
('delete_leads',        'Delete Leads',      'Leads', 'Delete lead records', 1, NOW()),
('view_reports',        'View Reports',      'Reporting', 'Access call-performance reports', 1, NOW()),
('manage_api',          'Manage API Access', 'Administration', 'Configure mobile API settings and tokens', 1, NOW()),
('manage_super_admin',  'Manage Super Admin Panel', 'Administration', 'Access the super admin panel for limits, plans, and payment settings', 1, NOW());

-- ============================================================
-- 4. ROLE_PERMISSIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` varchar(50) NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  `permission_value` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_perm` (`role`,`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `role_permissions` (`role`, `permission_key`, `permission_value`) VALUES
-- Super Admin: all permissions
('Super Admin','manage_TBL_USERS',1),('Super Admin','manage_TBL_TEAMS',1),('Super Admin','manage_settings',1),
('Super Admin','view_activity',1),('Super Admin','manage_database',1),('Super Admin','upload_data',1),
('Super Admin','export_data',1),('Super Admin','assign_leads',1),('Super Admin','manage_leads',1),
('Super Admin','delete_leads',1),('Super Admin','view_reports',1),('Super Admin','manage_api',1),
('Super Admin','manage_super_admin',1),
-- Admin: all permissions
('Admin','manage_TBL_USERS',1),('Admin','manage_TBL_TEAMS',1),('Admin','manage_settings',1),
('Admin','view_activity',1),('Admin','manage_database',1),('Admin','upload_data',1),
('Admin','export_data',1),('Admin','assign_leads',1),('Admin','manage_leads',1),
('Admin','delete_leads',1),('Admin','view_reports',1),('Admin','manage_api',1),
-- Manager: all except Super Admin panel
('Manager','manage_TBL_USERS',1),('Manager','manage_TBL_TEAMS',1),('Manager','manage_settings',1),
('Manager','view_activity',1),('Manager','manage_database',1),('Manager','upload_data',1),
('Manager','export_data',1),('Manager','assign_leads',1),('Manager','manage_leads',1),
('Manager','delete_leads',1),('Manager','view_reports',1),('Manager','manage_api',1),
-- Supervisor
('Supervisor','manage_TBL_USERS',0),('Supervisor','manage_TBL_TEAMS',1),('Supervisor','manage_settings',0),
('Supervisor','view_activity',1),('Supervisor','manage_database',0),('Supervisor','upload_data',0),
('Supervisor','export_data',1),('Supervisor','assign_leads',1),('Supervisor','manage_leads',1),
('Supervisor','delete_leads',0),('Supervisor','view_reports',1),
-- Officer
('Officer','manage_TBL_USERS',0),('Officer','manage_TBL_TEAMS',0),('Officer','manage_settings',0),
('Officer','view_activity',0),('Officer','manage_database',0),('Officer','upload_data',0),
('Officer','export_data',0),('Officer','assign_leads',0),('Officer','manage_leads',1),
('Officer','delete_leads',0),('Officer','view_reports',1);

-- ============================================================
-- 5. USER_PERMISSIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `permission_key` varchar(50) NOT NULL,
  `permission_value` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_perm` (`user_id`,`permission_key`),
  KEY `permission_key` (`permission_key`),
  CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`ID`) ON DELETE CASCADE,
  CONSTRAINT `user_permissions_ibfk_2` FOREIGN KEY (`permission_key`) REFERENCES `permissions` (`permission_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 6. TEAMS
-- ============================================================
CREATE TABLE IF NOT EXISTS `teams` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `TEAM_NAME` varchar(255) DEFAULT NULL,
  `NAME` varchar(100) NOT NULL,
  `SUPERVISOR_ID` int(11) DEFAULT NULL,
  `MANAGER_ID` int(11) DEFAULT NULL,
  `DESCRIPTION` text DEFAULT NULL,
  `CREATED_AT` datetime DEFAULT current_timestamp(),
  `UPDATED_AT` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `NAME` (`NAME`),
  KEY `idx_supervisor` (`SUPERVISOR_ID`),
  KEY `idx_manager` (`MANAGER_ID`),
  CONSTRAINT `teams_ibfk_1` FOREIGN KEY (`SUPERVISOR_ID`) REFERENCES `users` (`ID`) ON DELETE SET NULL,
  CONSTRAINT `teams_ibfk_2` FOREIGN KEY (`MANAGER_ID`) REFERENCES `users` (`ID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 7. APP_SETTINGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `app_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES
('app_name',           'CallNow CRM',       'Application display name', NOW()),
('company_name',       'CallNow',           'Default company name', NOW()),
('default_lead_status','LEAD',              'Default status for new leads', NOW()),
('default_lead_stage', 'Cold',              'Default stage for new leads', NOW()),
('pagination_size',    '50',                'Default records per page', NOW()),
('session_timeout',    '30',                'Session timeout in minutes', NOW()),
('enable_export',      '1',                 'Enable data export (1=yes, 0=no)', NOW()),
('timezone',           'Asia/Kolkata',      'System timezone', NOW()),
('rbac_cache_version', '1',                 'RBAC cache version (bumped on permission change)', NOW()),
('rbac_initialized',   '1',                 'RBAC system initialized flag', NOW());

-- ============================================================
-- 8. MAIN_DATABASE
-- ============================================================
CREATE TABLE IF NOT EXISTS `main_database` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `ADDED_BY` int(11) DEFAULT NULL,
  `MAINDATABASE_UPLOAD_DATETIME` datetime DEFAULT current_timestamp(),
  `MAINDATABASE_CALL_DIAL_TIME` datetime DEFAULT NULL,
  `CALL_COUNT` int(11) DEFAULT 0,
  `MAINDATABASE_NAME` varchar(100) NOT NULL DEFAULT '',
  `MAINDATABASE_MOBILE` varchar(15) NOT NULL DEFAULT '',
  `MAINDATABASE_COMPANY` varchar(100) DEFAULT '',
  `MAINDATABASE_PACKAGE` varchar(100) DEFAULT '',
  `MAINDATABASE_OTHER_INFO` text DEFAULT NULL,
  `MAINDATABASE_CALL_DIALED_STATUS` varchar(50) DEFAULT 'Not Called',
  `MAINDATABASE_CALL_DIALED_USER` int(11) DEFAULT NULL,
  `LAST_CONNECTED_PERIOD` datetime DEFAULT NULL,
  `CALL_BY` int(11) DEFAULT NULL,
  `LAST_DIALED_DATE_TIME` datetime DEFAULT NULL,
  `CALL_TIME` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `archive_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `idx_mobile` (`MAINDATABASE_MOBILE`),
  KEY `idx_status` (`MAINDATABASE_CALL_DIALED_STATUS`),
  KEY `idx_call_by` (`CALL_BY`),
  KEY `idx_added_by` (`ADDED_BY`),
  KEY `idx_called_at` (`MAINDATABASE_CALL_DIAL_TIME`),
  KEY `idx_upload_dt` (`MAINDATABASE_UPLOAD_DATETIME`),
  FULLTEXT KEY `idx_search` (`MAINDATABASE_NAME`,`MAINDATABASE_MOBILE`,`MAINDATABASE_COMPANY`,`MAINDATABASE_OTHER_INFO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 9. MAIN_DATABASE_ARCHIVE
-- ============================================================
CREATE TABLE IF NOT EXISTS `main_database_archive` (
  `archive_id` int(11) NOT NULL AUTO_INCREMENT,
  `NAME` varchar(255) DEFAULT NULL,
  `MOBILE` varchar(15) DEFAULT NULL,
  `COMPANY_NAME` varchar(255) DEFAULT NULL,
  `CALL_STATUS` varchar(50) DEFAULT NULL,
  `CALL_CONNECTED_TIME` varchar(50) DEFAULT NULL,
  `OTHER_INFO` text DEFAULT NULL,
  `ADDED_BY` int(11) DEFAULT NULL,
  `ADDED_AT` datetime DEFAULT NULL,
  `CALLED_AT` datetime DEFAULT NULL,
  `CALL_COUNT` int(11) DEFAULT 0,
  `ID` int(11) NOT NULL,
  `MAINDATABASE_NAME` varchar(100) NOT NULL,
  `MAINDATABASE_MOBILE` varchar(15) NOT NULL,
  `MAINDATABASE_COMPANY` varchar(100) DEFAULT '',
  `MAINDATABASE_PACKAGE` varchar(100) DEFAULT '',
  `MAINDATABASE_OTHER_INFO` text DEFAULT NULL,
  `MAINDATABASE_CALL_DIALED_STATUS` enum('Not Called','Dialed','Connected','Busy','No Answer','Do Not Call','Pending','Callback','Interested','Follow Up','Sale') DEFAULT 'Not Called',
  `MAINDATABASE_CALL_DIALED_USER` int(11) DEFAULT NULL,
  `MAINDATABASE_CALL_DIAL_TIME` datetime DEFAULT NULL,
  `MAINDATABASE_UPLOAD_DATETIME` datetime DEFAULT current_timestamp(),
  `LAST_DIALED_DATE_TIME` datetime DEFAULT NULL,
  `LAST_CONNECTED_PERIOD` datetime DEFAULT NULL,
  `CALL_BY` int(11) DEFAULT NULL,
  `CALL_TIME` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT current_timestamp(),
  `archived_by` int(11) DEFAULT NULL,
  `archive_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`archive_id`),
  KEY `idx_original_id` (`ID`),
  KEY `idx_mobile` (`MAINDATABASE_MOBILE`),
  KEY `idx_archived_at` (`archived_at`),
  KEY `idx_archived_by` (`archived_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 10. TEMPORARY_DATABASE
-- ============================================================
CREATE TABLE IF NOT EXISTS `temporary_database` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `CUST_NAME` varchar(100) NOT NULL DEFAULT '',
  `CUST_MOBILE` varchar(15) NOT NULL DEFAULT '',
  `CUST_COMPANY` varchar(100) DEFAULT '',
  `CUST_PACKAGE` varchar(100) DEFAULT '',
  `CUST_OTHER_INFO` text DEFAULT NULL,
  `CALL_DIALED_STATUS` varchar(50) DEFAULT 'Not Called',
  `CALL_DIALED_TELECALLER` int(11) DEFAULT NULL,
  `LAST_DIALED_DATE_TIME` datetime DEFAULT NULL,
  `LAST_CONNECTED_PERIOD` datetime DEFAULT NULL,
  `TEMP_UPLOAD_DATETIME` datetime DEFAULT current_timestamp(),
  `TEMP_LAST_CALLED_AT` datetime DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `idx_cust_mobile` (`CUST_MOBILE`),
  KEY `idx_dial_status` (`CALL_DIALED_STATUS`),
  KEY `idx_telecaller` (`CALL_DIALED_TELECALLER`),
  KEY `idx_last_dialed` (`LAST_DIALED_DATE_TIME`),
  KEY `idx_temp_status` (`CALL_DIALED_STATUS`),
  KEY `idx_temp_upload_dt` (`TEMP_UPLOAD_DATETIME`),
  FULLTEXT KEY `idx_search` (`CUST_NAME`,`CUST_MOBILE`,`CUST_COMPANY`,`CUST_OTHER_INFO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 11. LEADS_TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS `leads_table` (
  `ID` int(11) DEFAULT NULL,
  `NAME` varchar(255) DEFAULT NULL,
  `MOBILE` varchar(15) DEFAULT NULL,
  `COMPANY_NAME` varchar(255) DEFAULT NULL,
  `OTHER_INFO` text DEFAULT NULL,
  `CALL_STATUS` varchar(50) DEFAULT NULL,
  `PIPELINE_STATUS` varchar(50) DEFAULT 'Fresh',
  `FOLLOW_UP_DATE` date DEFAULT NULL,
  `NOTE` text DEFAULT NULL,
  `lead_id` int(11) NOT NULL AUTO_INCREMENT,
  `cust_id` int(11) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `lead_status` enum('New','In_Progress','Follow_Up','Converted','Lost') DEFAULT 'New',
  `lead_stage` enum('Hot','Warm','Cold') DEFAULT 'Cold',
  `priority` enum('High','Medium','Low') DEFAULT 'Medium',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `login_date` date DEFAULT NULL,
  `net_salary` varchar(50) DEFAULT NULL,
  `salary_account` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `loan_amount` varchar(50) DEFAULT NULL,
  `loan_tenure` varchar(50) DEFAULT NULL,
  `ADDED_BY` int(11) DEFAULT NULL,
  `ADDED_AT` datetime DEFAULT current_timestamp(),
  `promo_code` varchar(100) DEFAULT NULL,
  `login_bank_name` varchar(100) DEFAULT NULL,
  `lead_status_new` enum('LEAD','FOLLOWUP','INTERNAL_UNDERWRITING','LOGIN','BANK_UNDERWRITING','SANCTIONED','DISBURSED','REJECT') NOT NULL DEFAULT 'LEAD',
  `rework_flag` TINYINT(1) NOT NULL DEFAULT 0,
  `rework_stage` ENUM('','INTERNAL','BANK') NOT NULL DEFAULT '',
  `login_status` ENUM('','PENDING','SUCCESS','REWORK_PENDING','REJECTED') NOT NULL DEFAULT '',
  `login_submitted_by` INT(11) DEFAULT NULL,
  `login_submitted_at` DATETIME DEFAULT NULL,
  `forwarded_flag` TINYINT(1) NOT NULL DEFAULT 0,
  `sent_backward_flag` TINYINT(1) NOT NULL DEFAULT 0,
  `parent_lead_id` INT(11) DEFAULT NULL,
  `team_id` INT(11) DEFAULT NULL,
  `login_mode` enum('ONLINE','MAIL','PHYSICALLY') DEFAULT NULL,
  `loan_type` varchar(100) DEFAULT NULL,
  `loan_app_no` varchar(100) DEFAULT NULL,
  `login_location` varchar(100) DEFAULT NULL,
  `bank_rm_name` varchar(100) DEFAULT NULL,
  `bt_details` text DEFAULT NULL,
  `dsa_name` varchar(100) DEFAULT NULL,
  `next_followup_at` datetime DEFAULT NULL,
  `followup_count` int(11) DEFAULT 0,
  `last_contacted_at` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `lost_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`lead_id`),
  UNIQUE KEY `idx_id` (`ID`),
  KEY `idx_cust_id` (`cust_id`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_lead_status` (`lead_status`),
  KEY `idx_lead_stage` (`lead_stage`),
  KEY `idx_priority` (`priority`),
  KEY `idx_next_followup` (`next_followup_at`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_assigned_status` (`assigned_to`,`lead_status`),
  KEY `assigned_by` (`assigned_by`),
  KEY `created_by` (`created_by`),
  KEY `updated_by` (`updated_by`),
  KEY `idx_login_date` (`login_date`),
  KEY `idx_lead_status_new` (`lead_status_new`),
  KEY `idx_login_bank_name` (`login_bank_name`),
  KEY `idx_loan_type` (`loan_type`),
  KEY `idx_team_id` (`team_id`),
  KEY `idx_parent_lead` (`parent_lead_id`),
  FULLTEXT KEY `idx_remarks` (`remarks`),
  CONSTRAINT `leads_table_ibfk_1` FOREIGN KEY (`cust_id`) REFERENCES `main_database` (`ID`) ON DELETE SET NULL,
  CONSTRAINT `leads_table_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`ID`) ON DELETE SET NULL,
  CONSTRAINT `leads_table_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`ID`) ON DELETE SET NULL,
  CONSTRAINT `leads_table_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`ID`) ON DELETE SET NULL,
  CONSTRAINT `leads_table_ibfk_5` FOREIGN KEY (`updated_by`) REFERENCES `users` (`ID`) ON DELETE SET NULL,
  CONSTRAINT `leads_table_ibfk_6` FOREIGN KEY (`parent_lead_id`) REFERENCES `leads_table` (`lead_id`) ON DELETE SET NULL,
  CONSTRAINT `leads_table_ibfk_7` FOREIGN KEY (`team_id`) REFERENCES `teams` (`ID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 12. LEAD_NOTES
-- ============================================================
CREATE TABLE IF NOT EXISTS `lead_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `note` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  CONSTRAINT `lead_notes_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads_table` (`lead_id`) ON DELETE CASCADE,
  CONSTRAINT `lead_notes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`ID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 13. LEAD_FOLLOWUPS
-- ============================================================
CREATE TABLE IF NOT EXISTS `lead_followups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `followup_at` datetime NOT NULL,
  `note` text DEFAULT NULL,
  `status` enum('OPEN','DONE','CANCELLED') NOT NULL DEFAULT 'OPEN',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  KEY `idx_followup_at` (`followup_at`),
  KEY `idx_status` (`status`),
  CONSTRAINT `lead_followups_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads_table` (`lead_id`) ON DELETE CASCADE,
  CONSTRAINT `lead_followups_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`ID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 14. LEAD_ASSIGNMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `lead_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `from_user_id` int(11) DEFAULT NULL,
  `to_user_id` int(11) DEFAULT NULL,
  `from_team_id` int(11) DEFAULT NULL,
  `to_team_id` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  CONSTRAINT `lead_assignments_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads_table` (`lead_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 15. ACTIVITY_LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS `activity_log` (
  `ID` int(11) DEFAULT NULL,
  `LOG_ID` int(11) NOT NULL AUTO_INCREMENT,
  `USER_ID` int(11) NOT NULL,
  `ACTION_TYPE` enum('INSERT','UPDATE','DELETE','LOGIN','LOGOUT','UPLOAD','TRANSFER','EXPORT','ASSIGN','STATUS_CHANGE','REMARK','BULK_DELETE','BULK_UPDATE','TEAM_CHANGE') NOT NULL,
  `ACTION_DETAILS` text DEFAULT NULL,
  `AFFECTED_IDS` varchar(500) DEFAULT NULL,
  `TARGET_TABLE` varchar(100) DEFAULT NULL,
  `LOG_TIME` datetime DEFAULT current_timestamp(),
  `IP_ADDRESS` varchar(45) DEFAULT NULL,
  `USER_AGENT` varchar(500) DEFAULT NULL,
  `CREATED_AT` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`LOG_ID`),
  KEY `idx_user_id` (`USER_ID`),
  KEY `idx_action_type` (`ACTION_TYPE`),
  KEY `idx_target_table` (`TARGET_TABLE`),
  KEY `idx_log_time` (`LOG_TIME`),
  KEY `idx_affected_ids` (`AFFECTED_IDS`(255)),
  KEY `idx_user_time` (`USER_ID`,`LOG_TIME`),
  CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`USER_ID`) REFERENCES `users` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 16. CALL_LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `call_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `call_type` enum('INBOUND','OUTBOUND') DEFAULT 'OUTBOUND',
  `status` varchar(50) DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_lead_id` (`lead_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 17. USER_PAGE_FILTERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_page_filters` (
  `preference_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `page_key` varchar(100) NOT NULL,
  `filter_json` longtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`preference_id`),
  UNIQUE KEY `uniq_user_page` (`user_id`,`page_key`),
  KEY `idx_page_key` (`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 18. API_SETTINGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `api_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `api_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES
('api_enabled',            '1', 'Enable/disable mobile API access globally', NOW()),
('rate_limit_per_minute',  '60', 'Requests per minute per user (0 = unlimited)', NOW()),
('rate_limit_per_hour',    '1000', 'Requests per hour per user (0 = unlimited)', NOW()),
('token_expiry_days',      '90', 'Default token expiry in days (0 = never)', NOW()),
('allowed_databases',      '["temporary","leads"]', 'JSON array of allowed database types', NOW()),
('require_team_assignment','1', 'Require team assignment for database access', NOW()),
('log_requests',           '1', 'Log all API requests for audit', NOW());

-- ============================================================
-- 19. API_TOKENS
-- ============================================================
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(128) NOT NULL,
  `name` varchar(100) DEFAULT 'Mobile App',
  `expires_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 20. API_DATABASE_ASSIGNMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `api_database_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `database_type` enum('temporary','main','leads') NOT NULL,
  `team_id` int(11) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `assigned_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_db` (`user_id`,`database_type`,`team_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_team_id` (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 21. API_ACCESS_LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `api_access_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `token_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `endpoint` varchar(255) NOT NULL,
  `method` varchar(10) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `response_code` int(11) DEFAULT NULL,
  `execution_time_ms` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_token_id` (`token_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 22. SUPER_ADMIN_PLANS
-- ============================================================
CREATE TABLE IF NOT EXISTS `super_admin_plans` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `super_admin_plans` (`id`, `plan_name`, `plan_slug`, `max_users`, `max_api_tokens`, `max_api_calls_per_minute`, `max_api_calls_per_hour`, `max_db_records`, `max_storage_mb`, `price_monthly`, `price_yearly`, `is_active`, `is_default`) VALUES
(1, 'Starter',    'starter',    10,  5,  60,  1000,  50000,  500,  0.00,  0.00, 1, 1),
(2, 'Growth',     'growth',     50,  20, 120, 5000, 200000, 2000, 49.00, 490.00, 1, 0),
(3, 'Business',   'business',  200,  50, 300, 15000, 500000, 5000, 149.00, 1490.00, 1, 0),
(4, 'Enterprise', 'enterprise', 9999, 9999, 9999, 99999, 9999999, 50000, 499.00, 4990.00, 1, 0);

-- ============================================================
-- 23. SUPER_ADMIN_ACCOUNT
-- ============================================================
CREATE TABLE IF NOT EXISTS `super_admin_account` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `super_admin_account` (`id`, `current_plan_id`, `company_name`, `payment_status`, `trial_ends_at`) VALUES
(1, 1, 'CallNow', 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY));

-- ============================================================
-- 24. SUPER_ADMIN_LIMIT_LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `super_admin_limit_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 25. ENQUIRY (legacy, kept for compatibility)
-- ============================================================
CREATE TABLE IF NOT EXISTS `enquiry` (
  `ID` bigint(10) unsigned NOT NULL,
  `REQ_DATE` varchar(50) DEFAULT NULL,
  `TSA_ID` int(11) DEFAULT NULL,
  `RESIDENCE_TYPE` varchar(50) DEFAULT NULL,
  `REQ_PRODUCT` varchar(50) DEFAULT NULL,
  `CLIENT_NAME` varchar(50) DEFAULT NULL,
  `MOBILE` varchar(20) NOT NULL,
  `MESSAGE` text DEFAULT NULL,
  `ADDED_BY` int(11) DEFAULT NULL,
  `ADDED_AT` datetime DEFAULT current_timestamp(),
  `COMPANY` varchar(200) DEFAULT NULL,
  `LOAN_AMNT` varchar(50) DEFAULT NULL,
  `NET_SAL` varchar(50) DEFAULT NULL,
  `SAL_ACC` varchar(50) DEFAULT NULL,
  `REMARK` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Done
-- ============================================================
SET FOREIGN_KEY_CHECKS=1;
