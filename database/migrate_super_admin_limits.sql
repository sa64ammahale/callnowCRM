-- ============================================================
--  CallNow CRM — Super Admin Limits & Plans Migration
--  Adds subscription-style limits for users, API, and storage.
--  Safe to run multiple times (uses CREATE TABLE IF NOT EXISTS
--  and INSERT IGNORE for seed data).
-- ============================================================

-- 1. Plans table
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

-- 2. Account / billing table (single row per app)
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

-- 3. Limit violation logs
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

-- 4. Seed default plans
INSERT IGNORE INTO `super_admin_plans` (`id`, `plan_name`, `plan_slug`, `max_users`, `max_api_tokens`, `max_api_calls_per_minute`, `max_api_calls_per_hour`, `max_db_records`, `max_storage_mb`, `price_monthly`, `price_yearly`, `is_active`, `is_default`) VALUES
(1, 'Starter',    'starter',    10,  5,  60,  1000,  50000,  500,  0.00,  0.00, 1, 1),
(2, 'Growth',     'growth',     50,  20, 120, 5000, 200000, 2000, 49.00, 490.00, 1, 0),
(3, 'Business',   'business',  200,  50, 300, 15000, 500000, 5000, 149.00, 1490.00, 1, 0),
(4, 'Enterprise', 'enterprise', 9999, 9999, 9999, 99999, 9999999, 50000, 499.00, 4990.00, 1, 0);

-- 5. Seed default account row
INSERT IGNORE INTO `super_admin_account` (`id`, `current_plan_id`, `company_name`, `payment_status`, `trial_ends_at`) VALUES
(1, 1, 'CallNow', 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY));

-- ============================================================
--  ROLLBACK (run only if you need to undo this migration):
--  DROP TABLE IF EXISTS `super_admin_limit_logs`;
--  DROP TABLE IF EXISTS `super_admin_account`;
--  DROP TABLE IF EXISTS `super_admin_plans`;
-- ============================================================
