-- ============================================================
--  CallNow CRM — Lead Management System Migration
--  Phase 0: tables, columns, enums, Back Office team seed
--  Safe & reversible. Run on the Hostinger database.
--  MySQL 8 / MariaDB 10.4+ supported (ADD COLUMN IF NOT EXISTS).
-- ============================================================

-- 1. leads_table: 8-stage pipeline + rework + login status + flags + multi-bank link
ALTER TABLE `leads_table`
  MODIFY COLUMN `lead_status_new` enum('LEAD','FOLLOWUP','INTERNAL_UNDERWRITING','LOGIN','BANK_UNDERWRITING','SANCTIONED','DISBURSED','REJECT') NOT NULL DEFAULT 'LEAD';

ALTER TABLE `leads_table`
  ADD COLUMN IF NOT EXISTS `rework_flag`        TINYINT(1)   NOT NULL DEFAULT 0 AFTER `lead_status_new`,
  ADD COLUMN IF NOT EXISTS `rework_stage`       ENUM('','INTERNAL','BANK') NOT NULL DEFAULT '' AFTER `rework_flag`,
  ADD COLUMN IF NOT EXISTS `login_status`       ENUM('','PENDING','SUCCESS','REWORK_PENDING','REJECTED') NOT NULL DEFAULT '' AFTER `rework_stage`,
  ADD COLUMN IF NOT EXISTS `login_submitted_by` INT(11)     DEFAULT NULL AFTER `login_status`,
  ADD COLUMN IF NOT EXISTS `login_submitted_at` DATETIME    DEFAULT NULL AFTER `login_submitted_by`,
  ADD COLUMN IF NOT EXISTS `forwarded_flag`     TINYINT(1)   NOT NULL DEFAULT 0 AFTER `login_submitted_at`,
  ADD COLUMN IF NOT EXISTS `sent_backward_flag` TINYINT(1)   NOT NULL DEFAULT 0 AFTER `forwarded_flag`,
  ADD COLUMN IF NOT EXISTS `parent_lead_id`     INT(11)     DEFAULT NULL AFTER `sent_backward_flag`,
  ADD COLUMN IF NOT EXISTS `team_id`            INT(11)     DEFAULT NULL AFTER `parent_lead_id`,
  ADD KEY IF NOT EXISTS `idx_team_id` (`team_id`),
  ADD KEY IF NOT EXISTS `idx_parent_lead` (`parent_lead_id`),
  ADD CONSTRAINT IF NOT EXISTS `leads_table_ibfk_6` FOREIGN KEY (`parent_lead_id`) REFERENCES `leads_table` (`lead_id`) ON DELETE SET NULL,
  ADD CONSTRAINT IF NOT EXISTS `leads_table_ibfk_7` FOREIGN KEY (`team_id`) REFERENCES `teams` (`ID`) ON DELETE SET NULL;

-- 2. lead_notes — threaded, append-only notes (replaces single remarks blob)
CREATE TABLE IF NOT EXISTS `lead_notes` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `lead_id`    INT(11)     NOT NULL,
  `user_id`    INT(11)     DEFAULT NULL,
  `note`       TEXT        NOT NULL,
  `created_at` DATETIME    DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  CONSTRAINT `lead_notes_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads_table` (`lead_id`) ON DELETE CASCADE,
  CONSTRAINT `lead_notes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`ID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. lead_followups — scheduled follow-ups (makes next_followup_at real)
CREATE TABLE IF NOT EXISTS `lead_followups` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `lead_id`     INT(11)      NOT NULL,
  `user_id`     INT(11)      DEFAULT NULL,
  `followup_at` DATETIME     NOT NULL,
  `note`        TEXT         DEFAULT NULL,
  `status`      ENUM('OPEN','DONE','CANCELLED') NOT NULL DEFAULT 'OPEN',
  `created_at`  DATETIME     DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  KEY `idx_followup_at` (`followup_at`),
  KEY `idx_status` (`status`),
  CONSTRAINT `lead_followups_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads_table` (`lead_id`) ON DELETE CASCADE,
  CONSTRAINT `lead_followups_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`ID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. lead_assignments — handoff / audit trail (replaces 3-sheet copy)
CREATE TABLE IF NOT EXISTS `lead_assignments` (
  `id`           INT(11)     NOT NULL AUTO_INCREMENT,
  `lead_id`      INT(11)     NOT NULL,
  `from_user_id` INT(11)     DEFAULT NULL,
  `to_user_id`   INT(11)     DEFAULT NULL,
  `from_team_id` INT(11)     DEFAULT NULL,
  `to_team_id`   INT(11)     DEFAULT NULL,
  `reason`       TEXT        DEFAULT NULL,
  `created_at`   DATETIME    DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead_id` (`lead_id`),
  CONSTRAINT `lead_assignments_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads_table` (`lead_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. Back Office team seed (company-wide, 1 supervisor + staff).
--    MANAGER_ID is auto-linked: set to the first Manager-role user if none exists yet.
--    SUPERVISOR_ID left NULL here — assign your Back Office supervisor from the Teams page.
INSERT INTO `teams` (`TEAM_NAME`, `NAME`, `MANAGER_ID`, `DESCRIPTION`, `CREATED_AT`)
SELECT * FROM (SELECT 'Back Office','back_office',
               (SELECT ID FROM `users` WHERE ROLE = 'Manager' ORDER BY ID LIMIT 1),
               'Company-wide Back Office team handling internal underwriting and bank login.',
               NOW()) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `teams` WHERE `NAME` = 'back_office');

-- 6. Grant Back Office team the lead permissions via role_permissions
--    (Back Office members use the Officer role by default; ensure manage_leads etc. apply)
--    The Access Control page (Phase: Access Control) lets Super Admin fine-tune this.
INSERT INTO `role_permissions` (`role`, `permission_key`, `permission_value`)
SELECT 'Officer', 'manage_leads', 1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` WHERE `role`='Officer' AND `permission_key`='manage_leads');
INSERT INTO `role_permissions` (`role`, `permission_key`, `permission_value`)
SELECT 'Officer', 'view_reports', 1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` WHERE `role`='Officer' AND `permission_key`='view_reports');
INSERT INTO `role_permissions` (`role`, `permission_key`, `permission_value`)
SELECT 'Supervisor', 'manage_leads', 1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` WHERE `role`='Supervisor' AND `permission_key`='manage_leads');
INSERT INTO `role_permissions` (`role`, `permission_key`, `permission_value`)
SELECT 'Supervisor', 'assign_leads', 1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` WHERE `role`='Supervisor' AND `permission_key`='assign_leads');
INSERT INTO `role_permissions` (`role`, `permission_key`, `permission_value`)
SELECT 'Supervisor', 'view_reports', 1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` WHERE `role`='Supervisor' AND `permission_key`='view_reports');
INSERT INTO `role_permissions` (`role`, `permission_key`, `permission_value`)
SELECT 'Supervisor', 'export_data', 1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` WHERE `role`='Supervisor' AND `permission_key`='export_data');

-- ============================================================
--  ROLLBACK (run only if you need to undo Phase 0):
--  ALTER TABLE `leads_table`
--    DROP COLUMN `rework_flag`, DROP COLUMN `rework_stage`,
--    DROP COLUMN `login_status`, DROP COLUMN `login_submitted_by`,
--    DROP COLUMN `login_submitted_at`, DROP COLUMN `forwarded_flag`,
--    DROP COLUMN `sent_backward_flag`, DROP COLUMN `parent_lead_id`,
--    DROP COLUMN `team_id`;
--  DROP TABLE IF EXISTS `lead_notes`;
--  DROP TABLE IF EXISTS `lead_followups`;
--  DROP TABLE IF EXISTS `lead_assignments`;
--  DELETE FROM `teams` WHERE `NAME` = 'back_office';
-- ============================================================
