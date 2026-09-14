# Plan: Isolate Temporary DB Duplicate Checking from Main DB

## Goal
Ensure that uploading data to `TBL_TEMP` checks duplicates **only within the temporary table**, and does **not** cross-check against `TBL_MAIN`. Duplicate checking against `TBL_MAIN` should happen **only during transfer** from Temp → Main, where existing main records should be updated with temp data.

## Current State Verification
Based on code review of `modules/database/upload_data.php` and `modules/database/data_management_temporary.php`:

### Upload Behavior (upload_data.php)
- Lines 339-355: Dry-run uses `SELECT ID FROM $table WHERE mobile = ?` — checks only the **target table**
- Lines 357-359: Actual import uses `INSERT INTO $table ... ON DUPLICATE KEY UPDATE ID = ID` — checks only the **target table**
- **Conclusion**: When `target_database = 'temporary'`, duplicates are checked only within `TBL_TEMP`. `TBL_MAIN` is not consulted.

### Transfer Behavior (data_management_temporary.php)
- Lines 128-161 (`transfer_selected`): `INSERT INTO TBL_MAIN ... SELECT FROM TBL_TEMP ... ON DUPLICATE KEY UPDATE` with full field updates — checks against `TBL_MAIN` and updates existing rows.
- Lines 264-288 (`transfer_all`): Same pattern, batched — checks against `TBL_MAIN` and updates existing rows.
- **Conclusion**: Transfer correctly checks `TBL_MAIN` for duplicates and updates matching records.

## Problem Statement
The current behavior **already matches** the requirement. However, this isolation is not explicitly documented or surfaced in the UI, which could lead to:
1. User confusion about whether temp uploads check against main
2. Accidental belief that uploading to temp will "merge" with main
3. Lack of test coverage to guarantee this behavior remains intact after future changes

## Proposed Changes

### 1. Add Explicit Code Comments
In `modules/database/upload_data.php`, add a comment near the target table setup to clarify isolation:

```php
// Target is isolated: duplicates checked only within the selected target table.
// TBL_TEMP uploads do NOT cross-check against TBL_MAIN.
// Cross-table duplicate resolution happens only during transfer (Temp → Main).
```

In `modules/database/data_management_temporary.php`, add a comment near transfer functions:

```php
// Transfer checks TBL_MAIN for existing mobiles and updates them.
// This is the ONLY point where temp data merges with main data.
```

### 2. Add UI Indicator on Upload Page
In `modules/database/upload_data.php`, when **Target: Temporary** is selected, show a small info banner:

> "Temporary uploads are isolated. Duplicates are checked only within Temporary DB. No data in Main DB will be touched until you explicitly transfer."

This can be a conditional banner shown/hidden via JavaScript when the radio button changes.

### 3. Add UI Indicator on Temporary DB Page
In `modules/database/data_management_temporary.php`, add a persistent badge or banner:

> "Sandbox Mode — changes here do not affect Main DB until you use Transfer."

### 4. Add Automated Test Coverage
Create a test script or documented manual test cases to verify:

**Test Case 1: Temp upload does not check Main DB**
1. Ensure `TBL_MAIN` contains a mobile `9876543210`
2. Upload a CSV with mobile `9876543210` to **Temporary** target
3. Expected: Row is inserted into `TBL_TEMP` (not skipped), because `TBL_TEMP` has no duplicate
4. Verify `TBL_MAIN` is unchanged

**Test Case 2: Temp upload checks duplicates within Temp only**
1. Upload CSV with mobile `9876543210` to **Temporary** (first upload)
2. Upload the same CSV again to **Temporary**
3. Expected: Second upload shows `duplicates skipped: 1`, row not inserted again

**Test Case 3: Transfer checks Main DB and updates**
1. Ensure `TBL_MAIN` has mobile `9876543210` with status "Not Called"
2. In `TBL_TEMP`, set mobile `9876543210` status to "Connected"
3. Transfer that row to Main
4. Expected: `TBL_MAIN` row for `9876543210` is updated to "Connected"

## Files to Modify
1. `modules/database/upload_data.php` — add comments + UI banner
2. `modules/database/data_management_temporary.php` — add comments + UI banner
3. `tests/temp_db_isolation_test.php` (new) — automated test script (or documented manual tests if no test framework exists)

## Rollout / Validation
- Make code changes
- Run PHP syntax check: `php -l modules/database/upload_data.php` and `php -l modules/database/data_management_temporary.php`
- Manually execute Test Cases 1–3 above
- Verify no regression in existing upload/transfer flows

## Out of Scope
- Changing the duplicate-checking SQL logic (it already behaves correctly)
- Changing transfer merge behavior (it already updates main records as desired)
