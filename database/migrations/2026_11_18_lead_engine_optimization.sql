-- =======================================================================
-- CALLNOW CRM — SERVER DATABASE MIGRATION SCRIPT (2026-11-18)
-- Purpose: Apply high-concurrency lead engine indexes and virtual columns
-- Target Database: Live Tenant Database (e.g. callnow_incredit)
-- Requirements: Zero downtime, ALGORITHM=INPLACE, LOCK=NONE, MariaDB 10.4+
-- Note: In MariaDB 10.4, virtual column addition must be in its own statement
-- =======================================================================

SET lock_wait_timeout = 5;

-- 1. Virtual Clean Mobile Columns (Online DDL, ALGORITHM=INPLACE, LOCK=NONE)
ALTER TABLE `main_database` 
  ADD COLUMN IF NOT EXISTS `CLEAN_MOBILE` varchar(10) 
  GENERATED ALWAYS AS (RIGHT(REGEXP_REPLACE(`MAINDATABASE_MOBILE`, '[^0-9]', ''), 10)) VIRTUAL,
  ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE `temporary_database` 
  ADD COLUMN IF NOT EXISTS `CLEAN_MOBILE` varchar(10) 
  GENERATED ALWAYS AS (RIGHT(REGEXP_REPLACE(`CUST_MOBILE`, '[^0-9]', ''), 10)) VIRTUAL,
  ALGORITHM=INPLACE, LOCK=NONE;

-- 2. Secondary Indexes on Virtual Clean Mobile (Online DDL, ALGORITHM=INPLACE, LOCK=NONE)
ALTER TABLE `main_database` 
  ADD KEY IF NOT EXISTS `idx_main_clean_mobile` (`CLEAN_MOBILE`),
  ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE `temporary_database` 
  ADD KEY IF NOT EXISTS `idx_temp_clean_mobile` (`CLEAN_MOBILE`),
  ALGORITHM=INPLACE, LOCK=NONE;

-- 3. Priority Allocation Covering Indexes (Zero filesort, sub-millisecond point range lookups)
ALTER TABLE `main_database` 
  ADD KEY IF NOT EXISTS `idx_main_p0_alloc_v2` (`LAST_DIALED_DATE_TIME`, `MAINDATABASE_CALL_DIALED_STATUS`, `BATCH_ASSIGNED_AT`, `ID`),
  ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE `temporary_database` 
  ADD KEY IF NOT EXISTS `idx_temp_p0_alloc_v2` (`LAST_DIALED_DATE_TIME`, `CALL_DIALED_STATUS`, `BATCH_ASSIGNED_AT`, `ID`),
  ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE `main_database` 
  ADD KEY IF NOT EXISTS `idx_main_p2_alloc_v2` (`MAINDATABASE_CALL_DIALED_STATUS`, `LAST_CONNECTED_PERIOD`, `LAST_DIALED_DATE_TIME`, `BATCH_ASSIGNED_AT`, `CALL_COUNT`),
  ALGORITHM=INPLACE, LOCK=NONE;
