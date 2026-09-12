# Prevent Duplicate Inserts and Transfers — Plan

## Problem
The upload page and temp-to-main transfer are inserting duplicate records. The `ON DUPLICATE KEY UPDATE` clauses in the INSERT statements are no-ops because the mobile columns lack UNIQUE constraints.

## Root Cause
- `temporary_database.CUST_MOBILE` and `main_database.MAINDATABASE_MOBILE` have regular INDEXes, not UNIQUE constraints.
- `ON DUPLICATE KEY UPDATE` only fires when a UNIQUE/PK conflict occurs. Without a UNIQUE key, MySQL treats the INSERT as a plain insert and creates duplicates.
- The original schema (`setup_callnow_crm.sql`) had `UNIQUE` on these columns, but the current production schemas (`schema.sql`, `hostinger_schema.sql`) do not.

## Files Affected
- `modules/database/upload_data.php` — CSV import to TBL_TEMP and TBL_MAIN
- `modules/database/add_update_status.php` — quick add/update to TBL_TEMP
- `modules/database/data_management_temporary.php` — transfer_selected and transfer_all to TBL_MAIN
- `modules/database/add_single_number.php` — already has app-level checks, but needs DB-level protection
- `database/schema.sql` and `database/hostinger_schema.sql` — schema definitions

## Decisions Needed
1. **Duplicate definition**: Mobile number is the unique key across both tables. Confirm: should we deduplicate by `CUST_MOBILE` / `MAINDATABASE_MOBILE` only, or also by `CUST_NAME` + `CUST_MOBILE`?
   - **Recommended**: Mobile only, matching the existing application logic.

2. **Existing duplicate data**: The live database likely already has duplicates. We need a cleanup strategy before adding the constraint.
   - **Recommended**: Keep the first inserted record per mobile (lowest ID), delete subsequent duplicates.

3. **Transfer behavior on duplicate**: When a temp record matches an existing main record by mobile, should we:
   - Skip (keep main as-is)?
   - Update main with temp data?
   - **Recommended**: Update main with temp data (current code intent via `ON DUPLICATE KEY UPDATE`).

4. **Scope**: Should we also add UNIQUE constraints to the live DB via migration, or only update schema files for future deployments?
   - **Recommended**: Do both — update schema files AND provide a migration/cleanup script.

## Implementation Steps
1. Update `database/schema.sql` and `database/hostinger_schema.sql`:
   - Change `KEY idx_cust_mobile (CUST_MOBILE)` to `UNIQUE KEY idx_cust_mobile (CUST_MOBILE)` on TBL_TEMP
   - Change `KEY idx_mobile (MAINDATABASE_MOBILE)` to `UNIQUE KEY idx_mobile (MAINDATABASE_MOBILE)` on TBL_MAIN
   - Remove duplicate redundant indexes

2. Create a deduplication + migration script (or inline SQL) that:
   - Finds duplicates in TBL_TEMP (keep lowest ID per CUST_MOBILE, delete rest)
   - Finds duplicates in TBL_MAIN (keep lowest ID per MAINDATABASE_MOBILE, delete rest)
   - Adds the UNIQUE constraints
   - This must be run before the application code starts relying on the constraint

3. Verify application-level duplicate checks remain as safety nets:
   - `add_single_number.php` already checks before insert
   - `lead_insert.php` already checks before insert
   - `upload_data.php` and `add_update_status.php` rely on `ON DUPLICATE KEY UPDATE` — these will work once the constraint exists

4. Validate:
   - Run the cleanup script on a staging copy of the DB first
   - Confirm no duplicates remain
   - Test CSV upload with duplicate mobiles → should skip or update
   - Test transfer selected/all with duplicate mobiles → should update existing main records, not create new ones
   - Test single number add with duplicate → should show warning

## Risks
- Adding UNIQUE constraint on a table with existing duplicates will fail. Must clean first.
- If the live DB has millions of rows, deduplication could lock tables. Consider batching.
- Race conditions: two simultaneous uploads could still slip through app-level checks, but the DB UNIQUE constraint will catch them.

## Out of Scope
- Renaming tables or changing column names
- Changing the mobile-number-as-unique-key business rule
- Adding a composite unique key (name + mobile)
