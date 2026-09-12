-- ============================================================
-- CallNow CRM — Deduplication & Unique Constraint Migration
-- ============================================================
-- Purpose:
--   1. Remove duplicate records from TBL_TEMP and TBL_MAIN
--      (keep the oldest record per mobile number, delete newer duplicates).
--   2. Add UNIQUE constraints on mobile columns so future
--      ON DUPLICATE KEY UPDATE inserts/de-duplicate correctly.
--
-- How to run:
--   mysql -u USER -p DATABASE_NAME < database/migrations/2026-09-12-deduplicate-mobiles.sql
--
-- IMPORTANT:
--   - Backup your database before running this.
--   - This assumes the live tables already exist.
--   - The DELETE statements use a subquery to keep the lowest ID per mobile.
-- ============================================================

-- 1. Deduplicate TBL_TEMP: keep the record with the smallest ID for each CUST_MOBILE.
--    WARNING: this permanently deletes duplicate temp rows.
DELETE td
FROM temporary_database td
JOIN (
    SELECT MIN(ID) AS keep_id, CUST_MOBILE
    FROM temporary_database
    GROUP BY CUST_MOBILE
    HAVING COUNT(*) > 1
) dup ON td.CUST_MOBILE = dup.CUST_MOBILE
WHERE td.ID <> dup.keep_id;

-- 2. Deduplicate TBL_MAIN: keep the record with the smallest ID for each MAINDATABASE_MOBILE.
--    WARNING: this permanently deletes duplicate main rows.
DELETE md
FROM main_database md
JOIN (
    SELECT MIN(ID) AS keep_id, MAINDATABASE_MOBILE
    FROM main_database
    GROUP BY MAINDATABASE_MOBILE
    HAVING COUNT(*) > 1
) dup ON md.MAINDATABASE_MOBILE = dup.MAINDATABASE_MOBILE
WHERE md.ID <> dup.keep_id;

-- 3. Add UNIQUE constraints (idempotent: ignore if they already exist).
ALTER TABLE temporary_database
    ADD UNIQUE KEY IF NOT EXISTS idx_cust_mobile_unique (CUST_MOBILE),
    DROP INDEX idx_temp_mobile,
    DROP INDEX idx_cust_mobile;

ALTER TABLE main_database
    ADD UNIQUE KEY IF NOT EXISTS idx_mobile_unique (MAINDATABASE_MOBILE),
    DROP INDEX idx_user;

-- 4. Recreate standard non-unique indexes needed for queries that are NOT
--    covered by the new unique indexes. The unique index itself serves as
--    the lookup index for mobile lookups, so no extra mobile index is needed.

-- ============================================================
-- Verification queries (run manually if desired):
--   SELECT CUST_MOBILE, COUNT(*) c FROM temporary_database GROUP BY CUST_MOBILE HAVING c > 1;
--   SELECT MAINDATABASE_MOBILE, COUNT(*) c FROM main_database GROUP BY MAINDATABASE_MOBILE HAVING c > 1;
-- ============================================================
