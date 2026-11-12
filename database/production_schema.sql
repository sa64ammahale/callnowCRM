-- ============================================================
-- CallNow CRM — Universal Baseline Production Database Schema
-- ============================================================
-- Fully idempotent (CREATE TABLE IF NOT EXISTS, INSERT IGNORE)
-- Charset: utf8mb4 / InnoDB engine
-- Default Super Admin: admin@callnow.com / Admin@CallNow2026!
-- ============================================================

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. USERS
-- ------------------------------------------------------------
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
  KEY `fk_user_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed Default Super Admin Account (Password: Admin@CallNow2026!)
INSERT IGNORE INTO `users` (`ID`, `NAME`, `MOBILE`, `EMAIL`, `COMPANY`, `STATUS`, `ROLE`, `role_id`, `PASSWORD`, `LOGIN_ID`)
VALUES (1, 'System Administrator', '9999999999', 'admin@callnow.com', 'CallNow', 'Active', 'Super Admin', 1, '$2y$10$x26FSeqEue.q5qP7uNXqh.XCegooCcvGfSC/z2z772VzMLowoF.gO', 'admin@callnow.com');

-- ------------------------------------------------------------
-- 2. ROLES
-- ------------------------------------------------------------
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
(1, 'Super Admin', 'Platform system owner with full bypass access', 1, NOW()),
(2, 'Admin',       'Full organization access across all features', 1, NOW()),
(3, 'Manager',     'Manages teams, users, leads, reports, and pipeline', 1, NOW()),
(4, 'Supervisor',  'Oversees assigned team telecallers and leads', 1, NOW()),
(5, 'Officer',     'Telecaller executing assigned calling queues', 1, NOW());

-- ------------------------------------------------------------
-- 3. PERMISSIONS
-- ------------------------------------------------------------
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
('manage_users',       'Manage Users',        'Administration', 'Create, edit, suspend, and view user accounts', 1, NOW()),
('manage_teams',       'Manage Teams',        'Administration', 'Create and configure teams and member assignments', 1, NOW()),
('manage_settings',    'Manage Settings',     'Administration', 'Access Settings and RBAC permission matrices', 1, NOW()),
('view_activity',      'View Activity Log',   'Administration', 'Inspect system and telecaller audit logs', 1, NOW()),
('manage_database',    'Manage Database',     'Data',           'Direct record editing, filtering, and transfers', 1, NOW()),
('view_reports',       'View Reports',        'Reports',        'Access calling performance, hourly charts, and KPI stats', 1, NOW()),
('upload_data',        'Upload Data',         'Data',           'Upload and import customer contact batches (CSV/Excel)', 1, NOW()),
('manage_leads',       'Manage Leads',        'Leads',          'View, edit, stage transition, and assign CRM leads', 1, NOW()),
('export_data',        'Export Data',         'Data',           'Download reports, contact databases, and lead lists', 1, NOW()),
('edit_records',       'Edit Records',        'Data',           'Modify contact details and borrower information', 1, NOW()),
('delete_records',     'Delete Records',      'Data',           'Remove contact records or purge invalid entries', 1, NOW()),
('manage_super_admin', 'Manage Super Admin',  'Administration', 'Access platform plans, limits, and system controls', 1, NOW());

-- ------------------------------------------------------------
-- 4. ROLE_PERMISSIONS (RBAC Matrix)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_role_perm` (`role_id`,`permission_id`),
  KEY `fk_rp_permission` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Admin role (id=2) gets all permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, id FROM `permissions`;

-- Manager role (id=3) gets operational permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, id FROM `permissions` WHERE permission_key IN (
  'manage_users', 'manage_teams', 'manage_database', 'view_reports',
  'upload_data', 'manage_leads', 'export_data', 'edit_records', 'view_activity'
);

-- Supervisor role (id=4)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, id FROM `permissions` WHERE permission_key IN (
  'view_reports', 'manage_leads', 'export_data', 'edit_records'
);

-- Officer role (id=5)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 5, id FROM `permissions` WHERE permission_key IN (
  'manage_leads', 'edit_records'
);

-- ------------------------------------------------------------
-- 5. USER_PERMISSIONS (Per-user overrides)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `granted` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_perm` (`user_id`,`permission_id`),
  KEY `fk_up_permission` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 6. TEAMS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teams` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `TEAM_NAME` varchar(100) NOT NULL,
  `SUPERVISOR_ID` int(11) DEFAULT NULL,
  `STATUS` enum('Active','Inactive') DEFAULT 'Active',
  `DESCRIPTION` text DEFAULT NULL,
  `CREATED_AT` datetime DEFAULT current_timestamp(),
  `UPDATED_AT` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `TEAM_NAME` (`TEAM_NAME`),
  KEY `idx_supervisor` (`SUPERVISOR_ID`),
  KEY `idx_team_status` (`STATUS`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `teams` (`ID`, `TEAM_NAME`, `DESCRIPTION`, `STATUS`)
VALUES (1, 'Default Team', 'Main telecalling desk team', 'Active');

-- ------------------------------------------------------------
-- 7. PASSWORD_RESET_TOKENS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `otp_hash` varchar(64) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token_hash`),
  KEY `idx_otp` (`otp_hash`),
  KEY `idx_user_expiry` (`user_id`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 8. MAIN_DATABASE (Permanent verified contacts)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `main_database` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `MAINDATABASE_NAME` varchar(255) DEFAULT '',
  `MAINDATABASE_MOBILE` varchar(20) NOT NULL,
  `MAINDATABASE_EMAIL` varchar(255) DEFAULT '',
  `MAINDATABASE_COMPANY` varchar(255) DEFAULT '',
  `MAINDATABASE_PACKAGE` varchar(100) DEFAULT '',
  `MAINDATABASE_CITY` varchar(100) DEFAULT '',
  `MAINDATABASE_STATE` varchar(100) DEFAULT '',
  `MAINDATABASE_CALL_DIALED_STATUS` varchar(100) DEFAULT 'Pending',
  `MAINDATABASE_CALL_DIALED_USER` int(11) DEFAULT NULL,
  `MAINDATABASE_CALL_DIAL_TIME` datetime DEFAULT NULL,
  `MAINDATABASE_OTHER_INFO` text DEFAULT NULL,
  `MAINDATABASE_UPLOAD_DATETIME` datetime DEFAULT current_timestamp(),
  `LAST_DIALED_DATE_TIME` datetime DEFAULT NULL,
  `LAST_CONNECTED_PERIOD` datetime DEFAULT NULL,
  `CALL_COUNT` int(11) DEFAULT 0,
  `BATCH_ASSIGNED_AT` datetime DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `idx_main_mobile` (`MAINDATABASE_MOBILE`),
  KEY `idx_main_status` (`MAINDATABASE_CALL_DIALED_STATUS`),
  KEY `idx_main_user` (`MAINDATABASE_CALL_DIALED_USER`),
  KEY `idx_main_last_dialed` (`LAST_DIALED_DATE_TIME`),
  KEY `idx_main_last_connected` (`LAST_CONNECTED_PERIOD`),
  KEY `idx_main_batch` (`BATCH_ASSIGNED_AT`),
  KEY `idx_main_upload` (`MAINDATABASE_UPLOAD_DATETIME`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 9. MAIN_DATABASE_ARCHIVE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `main_database_archive` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `ORIGINAL_ID` int(11) NOT NULL,
  `MAINDATABASE_NAME` varchar(255) DEFAULT '',
  `MAINDATABASE_MOBILE` varchar(20) NOT NULL,
  `MAINDATABASE_EMAIL` varchar(255) DEFAULT '',
  `MAINDATABASE_COMPANY` varchar(255) DEFAULT '',
  `MAINDATABASE_PACKAGE` varchar(100) DEFAULT '',
  `MAINDATABASE_CITY` varchar(100) DEFAULT '',
  `MAINDATABASE_STATE` varchar(100) DEFAULT '',
  `MAINDATABASE_CALL_DIALED_STATUS` varchar(100) DEFAULT '',
  `MAINDATABASE_CALL_DIALED_USER` int(11) DEFAULT NULL,
  `MAINDATABASE_CALL_DIAL_TIME` datetime DEFAULT NULL,
  `MAINDATABASE_OTHER_INFO` text DEFAULT NULL,
  `MAINDATABASE_UPLOAD_DATETIME` datetime DEFAULT NULL,
  `ARCHIVED_AT` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`ID`),
  KEY `idx_orig_id` (`ORIGINAL_ID`),
  KEY `idx_arch_mobile` (`MAINDATABASE_MOBILE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 10. TEMPORARY_DATABASE (Fresh raw data uploads)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `temporary_database` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `CUST_NAME` varchar(255) DEFAULT '',
  `CUST_MOBILE` varchar(20) NOT NULL,
  `CUST_EMAIL` varchar(255) DEFAULT '',
  `CUST_COMPANY` varchar(255) DEFAULT '',
  `CUST_PACKAGE` varchar(100) DEFAULT '',
  `CUST_CITY` varchar(100) DEFAULT '',
  `CUST_STATE` varchar(100) DEFAULT '',
  `CALL_DIALED_STATUS` varchar(100) DEFAULT 'Pending',
  `CALL_DIALED_TELECALLER` int(11) DEFAULT NULL,
  `CALL_DIAL_TIME` datetime DEFAULT NULL,
  `CUST_OTHER_INFO` text DEFAULT NULL,
  `UPLOAD_DATETIME` datetime DEFAULT current_timestamp(),
  `LAST_DIALED_DATE_TIME` datetime DEFAULT NULL,
  `LAST_CONNECTED_PERIOD` datetime DEFAULT NULL,
  `BATCH_ASSIGNED_AT` datetime DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `idx_temp_mobile` (`CUST_MOBILE`),
  KEY `idx_temp_status` (`CALL_DIALED_STATUS`),
  KEY `idx_temp_user` (`CALL_DIALED_TELECALLER`),
  KEY `idx_temp_batch` (`BATCH_ASSIGNED_AT`),
  KEY `idx_temp_upload` (`UPLOAD_DATETIME`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 11. LEADS_TABLE (CRM Qualified Leads & Pipeline)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leads_table` (
  `lead_id` int(11) NOT NULL AUTO_INCREMENT,
  `cust_id` int(11) DEFAULT NULL,
  `contact_source` varchar(10) DEFAULT 'MAIN',
  `NAME` varchar(255) NOT NULL,
  `MOBILE` varchar(20) NOT NULL,
  `EMAIL` varchar(255) DEFAULT '',
  `COMPANY_NAME` varchar(255) DEFAULT '',
  `CITY` varchar(100) DEFAULT '',
  `STATE` varchar(100) DEFAULT '',
  `loan_type` varchar(100) DEFAULT 'Personal Loan',
  `loan_amount` decimal(15,2) DEFAULT 0.00,
  `login_bank_name` varchar(150) DEFAULT '',
  `target_bank` varchar(150) DEFAULT '',
  `net_salary` decimal(15,2) DEFAULT NULL,
  `salary_account` varchar(100) DEFAULT NULL,
  `priority` enum('LOW','MEDIUM','HIGH','URGENT') DEFAULT 'MEDIUM',
  `lead_stage` varchar(50) DEFAULT 'LEAD',
  `lead_status_new` varchar(50) DEFAULT 'LEAD',
  `lead_status` varchar(100) DEFAULT 'Interested',
  `temperature` enum('COLD','WARM','HOT') DEFAULT 'WARM',
  `assigned_to` int(11) DEFAULT NULL,
  `source_call_id` int(11) DEFAULT NULL,
  `next_followup_at` datetime DEFAULT NULL,
  `NOTES` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`lead_id`),
  KEY `idx_lead_mobile` (`MOBILE`),
  KEY `idx_lead_stage` (`lead_status_new`),
  KEY `idx_lead_assigned` (`assigned_to`),
  KEY `idx_lead_cust_id` (`cust_id`),
  KEY `idx_lead_followup` (`next_followup_at`),
  KEY `idx_lead_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 12. LEAD_NOTES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lead_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `note` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ln_lead` (`lead_id`),
  KEY `idx_ln_user` (`user_id`),
  KEY `idx_ln_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 13. LEAD_FOLLOWUPS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lead_followups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) DEFAULT NULL,
  `client_contact_id` int(11) DEFAULT NULL,
  `contact_source` varchar(20) DEFAULT 'MAIN',
  `source_call_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `followup_at` datetime NOT NULL,
  `note` text DEFAULT NULL,
  `status` enum('PENDING','DONE','CANCELLED') DEFAULT 'PENDING',
  `completed_at` datetime DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lf_lead` (`lead_id`),
  KEY `idx_lf_contact` (`client_contact_id`),
  KEY `idx_lf_user` (`user_id`),
  KEY `idx_lf_at` (`followup_at`),
  KEY `idx_lf_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 14. LEAD_ASSIGNMENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lead_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `assigned_from` int(11) DEFAULT NULL,
  `assigned_to` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_la_lead` (`lead_id`),
  KEY `idx_la_to` (`assigned_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 15. CONTACT_LEAD_CONVERSIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contact_lead_conversions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contact_id` int(11) NOT NULL,
  `contact_source` varchar(10) NOT NULL DEFAULT 'MAIN',
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `source_call_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_clc_contact` (`contact_id`,`contact_source`),
  KEY `idx_clc_lead` (`lead_id`),
  KEY `idx_clc_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 16. CALL_LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `call_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `record_id` int(11) DEFAULT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `client_contact_id` int(11) DEFAULT NULL,
  `source` enum('TEMP','MAIN','LEADS') DEFAULT 'MAIN',
  `call_type` varchar(50) DEFAULT 'MAIN',
  `user_id` int(11) NOT NULL,
  `call_status` varchar(50) NOT NULL,
  `call_duration` int(11) DEFAULT 0,
  `total_duration` int(11) DEFAULT 0,
  `timing_classification` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `metadata` longtext DEFAULT NULL,
  `next_followup` datetime DEFAULT NULL,
  `recording_url` varchar(500) DEFAULT NULL,
  `sim_slot` varchar(50) DEFAULT NULL,
  `call_time` datetime DEFAULT NULL,
  `dial_time` datetime DEFAULT NULL,
  `call_end_time` datetime DEFAULT NULL,
  `client_call_id` varchar(128) DEFAULT NULL,
  `request_hash` char(64) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cl_user` (`user_id`),
  KEY `idx_cl_record` (`record_id`,`source`),
  KEY `idx_cl_lead` (`lead_id`),
  KEY `idx_cl_status` (`call_status`),
  KEY `idx_cl_created` (`created_at`),
  KEY `idx_cl_call_time` (`call_time`),
  KEY `idx_cl_client_call_id` (`client_call_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 17. REPORTING_TARGETS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reporting_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `target_date` date NOT NULL,
  `daily_call_target` int(11) DEFAULT 100,
  `daily_lead_target` int(11) DEFAULT 5,
  `daily_talk_time_target_seconds` int(11) DEFAULT 7200,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_date` (`user_id`,`target_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 18. ACTIVITY_LOG
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `record_id` varchar(100) DEFAULT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_al_user` (`user_id`),
  KEY `idx_al_action` (`action`),
  KEY `idx_al_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 19. ENQUIRY
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enquiry` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_enq_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 20. API_TOKENS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `device_name` varchar(100) DEFAULT NULL,
  `device_id` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_at_user` (`user_id`),
  KEY `idx_at_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 21. API_SETTINGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `api_settings` (`setting_key`, `setting_value`) VALUES
('api_enabled', '1'),
('rate_limit_per_minute', '60'),
('token_expiry_hours', '168'),
('allowed_origins', '*');

-- ------------------------------------------------------------
-- 22. API_ACCESS_LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_access_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `endpoint` varchar(255) NOT NULL,
  `method` varchar(10) NOT NULL,
  `status_code` int(11) NOT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `response_time_ms` float DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_aal_user` (`user_id`),
  KEY `idx_aal_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 23. APP_SETTINGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES
('company_name', 'CallNow CRM'),
('default_country_code', '+91'),
('calling_desk_batch_size', '15'),
('cooldown_period_days', '90'),
('calling_lease_minutes', '15');

-- ------------------------------------------------------------
-- 24. SUPER_ADMIN_PLANS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `super_admin_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_name` varchar(50) NOT NULL,
  `max_users` int(11) NOT NULL DEFAULT 10,
  `max_api_tokens` int(11) NOT NULL DEFAULT 20,
  `max_storage_mb` int(11) NOT NULL DEFAULT 500,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_name` (`plan_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `super_admin_plans` (`id`, `plan_name`, `max_users`, `max_api_tokens`, `max_storage_mb`, `is_active`) VALUES
(1, 'Starter', 10, 25, 500, 1),
(2, 'Growth', 50, 100, 2048, 1),
(3, 'Enterprise', 250, 500, 10240, 1);

-- ------------------------------------------------------------
-- 25. SUPER_ADMIN_ACCOUNT
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `super_admin_account` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `current_plan_id` int(11) DEFAULT 1,
  `custom_max_users` int(11) DEFAULT NULL,
  `custom_max_api_tokens` int(11) DEFAULT NULL,
  `custom_max_storage_mb` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `super_admin_account` (`id`, `current_plan_id`) VALUES (1, 1);

-- ------------------------------------------------------------
-- 26. SUPER_ADMIN_LIMIT_LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `super_admin_limit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `limit_type` enum('USERS','API_TOKENS','STORAGE') NOT NULL,
  `current_value` int(11) NOT NULL,
  `max_value` int(11) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sall_type` (`limit_type`),
  KEY `idx_sall_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 27. USER_PAGE_FILTERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_page_filters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `page_name` varchar(50) NOT NULL,
  `filters_json` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_upf_user_page` (`user_id`,`page_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS=1;

