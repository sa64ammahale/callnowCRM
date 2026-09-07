SET FOREIGN_KEY_CHECKS=0;
-- ============================================================
-- CallNow CRM - Database Schema
-- ============================================================
-- This file contains:
--   * All 18 table structures (CREATE TABLE)
--   * Seed/reference data: roles, permissions, role_permissions,
--     app_settings, api_settings
-- It does NOT contain any customer or user data.
--
-- How to use (replace credentials with your own):
--   mysql -u YOUR_USER -p YOUR_DB_NAME < database/schema.sql
--
-- Or in phpMyAdmin: Import -> select this file -> Go.
-- After import, create the first admin via CallNowSignUp.php
-- (?setup_key=... with SETUP_KEY set in .env).
-- ============================================================
-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: callnow_incredit
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_log`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=215 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_access_logs`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_database_assignments`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `api_database_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `database_type` enum('temporary','main','leads') NOT NULL,
  `team_id` int(11) DEFAULT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` datetime DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_db` (`user_id`,`database_type`,`team_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_team_id` (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_settings`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `api_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_tokens`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `name` varchar(100) DEFAULT 'Mobile App',
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_token` (`token`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `app_settings`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `app_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `enquiry`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leads_table`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `lead_notes`
--

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

--
-- Table structure for table `lead_followups`
--

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

--
-- Table structure for table `lead_assignments`
--

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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `main_database`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  KEY `idx_mobile` (`MAINDATABASE_MOBILE`),
  KEY `idx_status` (`MAINDATABASE_CALL_DIALED_STATUS`),
  KEY `idx_call_by` (`CALL_BY`),
  KEY `idx_added_by` (`ADDED_BY`),
  KEY `idx_called_at` (`MAINDATABASE_CALL_DIAL_TIME`),
  KEY `idx_upload_dt` (`MAINDATABASE_UPLOAD_DATETIME`),
  KEY `idx_user` (`CALL_BY`),
  FULLTEXT KEY `idx_search` (`MAINDATABASE_NAME`,`MAINDATABASE_MOBILE`,`MAINDATABASE_COMPANY`,`MAINDATABASE_OTHER_INFO`)
) ENGINE=InnoDB AUTO_INCREMENT=383953 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `main_database_archive`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `permissions`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `role_permissions`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` varchar(50) NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  `permission_value` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_perm` (`role`,`permission_key`)
) ENGINE=InnoDB AUTO_INCREMENT=182 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT '',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `teams`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `temporary_database`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
  KEY `idx_cust_mobile` (`CUST_MOBILE`),
  KEY `idx_dial_status` (`CALL_DIALED_STATUS`),
  KEY `idx_telecaller` (`CALL_DIALED_TELECALLER`),
  KEY `idx_last_dialed` (`LAST_DIALED_DATE_TIME`),
  KEY `idx_temp_mobile` (`CUST_MOBILE`),
  KEY `idx_temp_status` (`CALL_DIALED_STATUS`),
  KEY `idx_temp_upload_dt` (`TEMP_UPLOAD_DATETIME`),
  FULLTEXT KEY `idx_search` (`CUST_NAME`,`CUST_MOBILE`,`CUST_COMPANY`,`CUST_OTHER_INFO`)
) ENGINE=InnoDB AUTO_INCREMENT=130841 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_page_filters`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_permissions`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

INSERT IGNORE INTO `users` (`NAME`, `MOBILE`, `EMAIL`, `COMPANY`, `STATUS`, `ROLE`, `role_id`, `PASSWORD`, `LOGIN_ID`)
VALUES ('System Administrator', '9999999999', 'admin@callnow.com', 'CallNow', 'Active', 'Super Admin', 1, '$2y$10$mp33ADcNyz9KvGqQfsiOpO40xynqmZ3tsruRcFhUJn7pULBeFnVZW', 'admin@callnow.com');

--
-- Dumping routines for database 'callnow_incredit'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-18 18:48:17
-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: callnow_incredit
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT IGNORE INTO `roles` VALUES (1,'Admin','Full system access — all permissions.',1,'2026-07-15 19:08:36'),(2,'Manager','Manages teams, users, leads, and data operations.',1,'2026-07-15 19:08:36'),(3,'Supervisor','Oversees team leads and reports.',1,'2026-07-15 19:08:36'),(4,'Officer','Basic lead management only.',1,'2026-07-15 19:08:36');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-18 18:48:17
-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: callnow_incredit
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT IGNORE INTO `permissions` VALUES (1,'manage_TBL_USERS','Manage TBL_USERS','Administration','Create, edit and delete user accounts',1,'2026-07-16 13:05:54'),(2,'manage_TBL_TEAMS','Manage TBL_TEAMS','Administration','Create TBL_TEAMS and assign members',1,'2026-07-16 13:05:54'),(3,'manage_settings','Manage Settings','Administration','Access the Settings hub and permission manager',1,'2026-07-16 13:05:54'),(4,'view_activity','View Activity Log','Administration','Access the audit / activity log',1,'2026-07-16 13:05:54'),(5,'manage_database','Manage Database','Data','Edit, delete and transfer records in temporary & main databases',1,'2026-07-16 13:05:54'),(6,'upload_data','Upload Data','Data','Import CSV files into the databases',1,'2026-07-16 13:05:54'),(7,'export_data','Export Data','Data','Export database lists to CSV',1,'2026-07-16 13:05:54'),(8,'assign_leads','Assign Leads','Leads','Assign leads / numbers to telecallers',1,'2026-07-16 13:05:54'),(9,'manage_leads','Manage Leads','Leads','Create and edit leads',1,'2026-07-16 13:05:54'),(10,'delete_leads','Delete Leads','Leads','Delete lead records',1,'2026-07-16 13:05:54'),(11,'view_reports','View Reports','Reporting','Access call-performance reports',1,'2026-07-16 13:05:54'),(23,'manage_api','Manage API Access','Administration','Configure mobile API settings and tokens',1,'2026-07-18 11:05:30');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-18 18:48:17
-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: callnow_incredit
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT IGNORE INTO `role_permissions` VALUES (125,'Admin','manage_users',1),(126,'Admin','manage_teams',1),(127,'Admin','manage_settings',1),(128,'Admin','view_activity',1),(129,'Admin','manage_database',1),(130,'Admin','upload_data',1),(131,'Admin','export_data',1),(132,'Admin','assign_leads',1),(133,'Admin','manage_leads',1),(134,'Admin','delete_leads',1),(135,'Admin','view_reports',1),(136,'Manager','manage_users',1),(137,'Manager','manage_teams',1),(138,'Manager','manage_settings',1),(139,'Manager','view_activity',1),(140,'Manager','manage_database',1),(141,'Manager','upload_data',1),(142,'Manager','export_data',1),(143,'Manager','assign_leads',1),(144,'Manager','manage_leads',1),(145,'Manager','delete_leads',1),(146,'Manager','view_reports',1),(147,'Supervisor','manage_users',0),(148,'Supervisor','manage_teams',1),(149,'Supervisor','manage_settings',0),(150,'Supervisor','view_activity',1),(151,'Supervisor','manage_database',0),(152,'Supervisor','upload_data',0),(153,'Supervisor','export_data',1),(154,'Supervisor','assign_leads',1),(155,'Supervisor','manage_leads',1),(156,'Supervisor','delete_leads',0),(157,'Supervisor','view_reports',1),(158,'Officer','manage_users',0),(159,'Officer','manage_teams',0),(160,'Officer','manage_settings',0),(161,'Officer','view_activity',0),(162,'Officer','manage_database',0),(163,'Officer','upload_data',0),(164,'Officer','export_data',0),(165,'Officer','assign_leads',0),(166,'Officer','manage_leads',1),(167,'Officer','delete_leads',0),(168,'Officer','view_reports',1),(180,'Admin','manage_api',1),(181,'Manager','manage_api',1);
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-18 18:48:17
-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: callnow_incredit
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `app_settings`
--

LOCK TABLES `app_settings` WRITE;
/*!40000 ALTER TABLE `app_settings` DISABLE KEYS */;
INSERT IGNORE INTO `app_settings` VALUES (1,'app_name','CallNow CRM','Application display name',NULL,'2026-07-15 16:44:04'),(2,'company_name','CallNow','Default company name',NULL,'2026-07-15 16:44:04'),(3,'default_lead_status','LEAD','Default status for new leads',NULL,'2026-07-15 16:44:04'),(4,'default_lead_stage','Cold','Default stage for new leads',NULL,'2026-07-15 16:44:04'),(5,'pagination_size','50','Default records per page',NULL,'2026-07-15 16:44:04'),(6,'session_timeout','30','Session timeout in minutes',NULL,'2026-07-15 16:44:04'),(7,'enable_export','1','Enable data export (1=yes, 0=no)',NULL,'2026-07-15 16:44:04'),(8,'timezone','Asia/Kolkata','System timezone',NULL,'2026-07-15 16:44:04'),(16,'rbac_cache_version','7',NULL,NULL,'2026-07-16 17:34:23'),(17,'rbac_initialized','1',NULL,NULL,'2026-07-16 13:07:51');
/*!40000 ALTER TABLE `app_settings` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-18 18:48:17
-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: callnow_incredit
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `api_settings`
--

LOCK TABLES `api_settings` WRITE;
/*!40000 ALTER TABLE `api_settings` DISABLE KEYS */;
INSERT IGNORE INTO `api_settings` VALUES (1,'api_enabled','1','Enable/disable mobile API access globally','2026-07-18 11:05:30'),(2,'rate_limit_per_minute','60','Requests per minute per user (0 = unlimited)','2026-07-18 11:05:30'),(3,'rate_limit_per_hour','1000','Requests per hour per user (0 = unlimited)','2026-07-18 11:05:30'),(4,'token_expiry_days','90','Default token expiry in days (0 = never)','2026-07-18 11:05:30'),(5,'allowed_databases','[\"temporary\",\"leads\"]','JSON array of allowed database types: temporary, main, leads','2026-07-18 11:05:30'),(6,'require_team_assignment','1','Require team assignment for database access','2026-07-18 11:05:30'),(7,'log_requests','1','Log all API requests for audit','2026-07-18 11:05:30');
/*!40000 ALTER TABLE `api_settings` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-18 18:48:17


SET FOREIGN_KEY_CHECKS=1;
