-- ============================================================
-- CallNow CRM V5.00 — Master Catalog Database Schema
-- ============================================================
-- Stores the global tenant registry (callnow_telecalling_companys table).
--
-- On VPS / Local:
--   mysql -u root -p < database/master_schema.sql
--
-- On Shared Hosting (Hostinger / cPanel):
--   1. Create database in hPanel (e.g. u661890306_COMMON)
--   2. In .env set: MASTER_DB_NAME=u661890306_COMMON
--   3. Open phpMyAdmin -> Select your database -> Import
-- ============================================================

CREATE DATABASE IF NOT EXISTS `callnow_master` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `callnow_master`;

CREATE TABLE IF NOT EXISTS `callnow_telecalling_companys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_code` varchar(50) NOT NULL,
  `company_name` varchar(150) NOT NULL,
  `db_name` varchar(100) NOT NULL,
  `db_host` varchar(100) DEFAULT 'localhost',
  `db_port` int(11) DEFAULT 3306,
  `db_user` varchar(100) DEFAULT NULL,
  `db_pass` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive','Suspended') NOT NULL DEFAULT 'Active',
  `logo_url` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_company_code` (`company_code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `callnow_telecalling_companys` (`company_code`, `company_name`, `db_name`, `status`)
VALUES ('incredit', 'Incredit Financial Services', 'callnow_incredit', 'Active')
ON DUPLICATE KEY UPDATE `company_name` = VALUES(`company_name`), `db_name` = VALUES(`db_name`), `status` = VALUES(`status`);

