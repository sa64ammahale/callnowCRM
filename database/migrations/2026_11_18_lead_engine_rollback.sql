-- =======================================================================
-- CALLNOW CRM — SERVER DATABASE ROLLBACK SCRIPT (2026-11-18)
-- Target Database: Live Tenant Database (e.g. callnow_incredit)
-- =======================================================================

SET lock_wait_timeout = 5;

ALTER TABLE `main_database` 
  DROP KEY IF EXISTS `idx_main_p0_alloc_v2`,
  DROP KEY IF EXISTS `idx_main_p2_alloc_v2`,
  DROP KEY IF EXISTS `idx_main_clean_mobile`,
  DROP COLUMN IF EXISTS `CLEAN_MOBILE`,
  ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE `temporary_database` 
  DROP KEY IF EXISTS `idx_temp_p0_alloc_v2`,
  DROP KEY IF EXISTS `idx_temp_clean_mobile`,
  DROP COLUMN IF EXISTS `CLEAN_MOBILE`,
  ALGORITHM=INPLACE, LOCK=NONE;
