# CallNow Lead Engine Rollback & Emergency Fallback Plan (v1.4)

## 1. Rollback Overview & Production Safety Standards

This document specifies the safe, 4-tier operational and database rollback procedure for the Lead Distribution Engine rollout.

### Critical Safety Principles
1. **Never Drop Reservation Tables Under Emergency**:
   Dropping `lead_reservations`, `lead_batches`, or idempotency indexes during an active emergency will destroy in-flight call records, break pending offline call submissions, cause mobile 500 crashes, and corrupt telecaller XP.
2. **Graceful Drain (Allocation Freeze vs Submission Preservation)**:
   Rollback first disables *new allocations* (`POST /calling/claim`) while preserving *completion endpoints* (`POST /calls/{id}/complete` and `/calls/sync`) so active callers can finish talking and submit outcomes without data loss.
3. **Legacy Lead Collision Prevention**:
   When legacy allocation (`GET /batch-leads`) takes over, it must NOT allocate contacts that are currently held in `RESERVED` or `DIALING` state by the new engine until those reservations naturally expire or are drained.

---

## 2. Tier 1: Instant Application Fallback (Zero DDL, Zero Downtime)

To immediately halt new allocations without running database DDL:

### Step 1: Flip Application Feature Flag
In `php_scripts/config.php`:
```php
define('FEATURE_LEAD_ENGINE_ALLOCATION_ENABLED', false);
```

### Step 2: Runtime Behavior Under Tier 1 Fallback
| Endpoint / Operation | Behavior When Allocation Flag is `false` |
|---|---|
| `POST /api/v1/telecaller/calling/claim` | Returns `HTTP 503 Service Unavailable`: `"Lead allocation is under scheduled maintenance. Please use web desk or standard batch queue."` |
| `GET /api/v1/telecaller/calls/active` | **Fully functional**. Allows in-flight callers to recover active dial state. |
| `POST /api/v1/telecaller/calls/start` | Allowed for existing `RESERVED` leads to prevent blocking callers mid-queue. |
| `POST /api/v1/telecaller/calls/{id}/complete` | **Fully functional**. Validates tokens, records call logs, awards XP, and updates customer status. |
| `POST /api/v1/telecaller/calls/{id}/abandon` | **Fully functional**. Releases dial locks cleanly. |
| `POST /api/v1/telecaller/calls/sync` | **Fully functional**. Processes pending offline call queues without dropping records. |
| `GET /api/v1/telecaller/batch-leads` (Legacy Flutter) | Continues allocating via legacy query with dual-table reservation exclusion guard. |

---

## 3. Tier 2: Safe Reservation Draining (Pre-DDL Rollback)

Before any database schema or table removal is performed, active reservations must be formally drained:

```sql
-- 1. Check count of active dialing calls
SELECT COUNT(*) AS active_dialing_calls
FROM lead_reservations
WHERE state = 'DIALING';

-- If active dialing calls > 0, WAIT until callers finish or shifts end.

-- 2. Once in-flight calls finish, expire remaining uncalled reserved leads:
UPDATE lead_reservations 
SET state = 'EXPIRED' 
WHERE state = 'RESERVED';

-- 3. Mark all active batches as EXPIRED:
UPDATE lead_batches 
SET status = 'EXPIRED' 
WHERE status = 'ACTIVE';
```

> [!CAUTION]
> **Safety Rule**: Never update `WHERE state = 'DIALING'`. Telecallers currently speaking on the phone must be allowed to submit their call results naturally.

---

## 4. Tier 3: Enable Legacy Allocation with Dual-Table Exclusion Guard

To ensure legacy allocation does not steal leads currently held in `RESERVED` or `DIALING` state:

Verify legacy allocation queries in `api/v1/telecaller/index.php` contain the exclusion filter:
```sql
-- temporary_database exclusion guard
AND NOT EXISTS (
    SELECT 1 FROM lead_reservations lr 
    WHERE lr.phone_number_10 = RIGHT(REGEXP_REPLACE(t.CUST_MOBILE, '[^0-9]', ''), 10)
      AND lr.state IN ('RESERVED', 'DIALING')
      AND (lr.state = 'DIALING' OR lr.expires_at > NOW())
)

-- main_database exclusion guard
AND NOT EXISTS (
    SELECT 1 FROM lead_reservations lr 
    WHERE lr.phone_number_10 = RIGHT(REGEXP_REPLACE(m.MAINDATABASE_MOBILE, '[^0-9]', ''), 10)
      AND lr.state IN ('RESERVED', 'DIALING')
      AND (lr.state = 'DIALING' OR lr.expires_at > NOW())
)
```

---

## 5. Tier 4: Planned SQL Schema Rollback Script

This script is generated directly from `database/migrations/2026_11_19_lead_distribution_engine.sql` and drops only the objects added during that migration.

> [!WARNING]
> Execute this script ONLY after Tier 1, Tier 2, and Tier 3 draining procedures are complete and telecaller queues are empty, or during isolated staging teardown.

```sql
-- ============================================================================
-- Rollback Migration: 2026_11_19_rollback_lead_distribution_engine.sql
-- Target: MariaDB 10.4.32+
-- Verification: Exactly reverses 2026_11_19_lead_distribution_engine.sql
-- ============================================================================

-- 1. Drop child table first (cleans up virtual column and foreign keys)
DROP TABLE IF EXISTS `lead_reservations`;

-- 2. Drop parent batch table
DROP TABLE IF EXISTS `lead_batches`;

-- 3. Drop column from users
ALTER TABLE `users`
    DROP COLUMN IF EXISTS `session_epoch`;

-- 4. Drop composite indexes added to temporary_database
ALTER TABLE `temporary_database`
    DROP INDEX `idx_temp_waterfall_p0`,
    DROP INDEX `idx_temp_waterfall_p2`;

-- 5. Drop composite indexes added to main_database
ALTER TABLE `main_database`
    DROP INDEX `idx_main_waterfall_p0`,
    DROP INDEX `idx_main_waterfall_p2`;

-- 6. Drop composite index added to lead_followups
ALTER TABLE `lead_followups`
    DROP INDEX `idx_followups_user_status_due`;
```

---

## 6. Staging Rollback & Recovery Test Procedure

Execute the following 6-step test on staging before any production deployment:

1. **Step 1: Populate Test Reservations**:
   Simulate 3 telecallers claiming batches (45 leads reserved, 3 in `DIALING` state).
2. **Step 2: Trigger Emergency Feature Flag**:
   Set `define('FEATURE_LEAD_ENGINE_ALLOCATION_ENABLED', false);`.
3. **Step 3: Verify Allocation Blocked**:
   Verify `POST /calling/claim` returns `503 Service Unavailable`.
4. **Step 4: Verify Submissions Accepted**:
   Submit `POST /calls/{id}/complete` for the 3 `DIALING` leads. Verify:
   - Status `200 OK` returned.
   - `call_logs` contains genuine duration and notes.
   - XP awarded in `telecaller_engagement`.
5. **Step 5: Run Draining SQL**:
   Execute Tier 2 script. Verify 0 active reservations remain.
6. **Step 6: Execute SQL Rollback & Verify CRM**:
   Run Tier 4 SQL rollback script. Verify legacy Flutter app and Web CRM can claim and dial leads normally without errors.
