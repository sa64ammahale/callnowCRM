# CallNow Lead Engine Database Migration Plan (MariaDB 10.4.32) — v1.4

## 1. Migration Overview & Production Safety Standards

This document specifies the comprehensive database migration plan for the CallNow Server-Side Lead Distribution Engine upgrade.

### Safety Principles
1. **Zero Runtime DDL**: No automatic `ALTER TABLE` or `CREATE TABLE` execution during API requests or inside `config.php`.
2. **Deterministic Versioning**: All schema alterations are version-controlled in `database/migrations/`.
3. **MariaDB 10.4 Compatibility**:
   - Strictly compatible with MariaDB 10.4.32 InnoDB storage engine.
   - Virtual Generated Column (`VIRTUAL`) unique indexes for state-dependent deduplication.
   - Per-tenant `GET_LOCK` advisory locking for concurrency isolation.
4. **Maintenance Window Required (No Flawed "Zero Downtime" Claims)**:
   - Adding composite indexes to `temporary_database` and `main_database` requires metadata locks (MDL).
   - On production tables with high concurrency, `ALTER TABLE` can wait on active transactions, queuing subsequent `SELECT` statements and risking connection pool exhaustion.
   - **Requirement**: This migration must be executed during a planned, off-peak maintenance window with telecaller activity paused.

---

## 2. Migration Object Inventory

| Object Type | Object Name | Target Table | Purpose |
|---|---|---|---|
| **Table** | `lead_batches` | New Table | Tracks 15-lead batch leases, device sessions, and claim idempotency. |
| **Table** | `lead_reservations` | New Table | Tracks individual lead reservation tokens, dial locks, and call states. |
| **Column** | `session_epoch` | `users` | Telecaller device session epoch counter for multi-device fencing. |
| **Index** | `uniq_batch_uuid` | `lead_batches` | Ensures global batch UUID uniqueness. |
| **Index** | `uniq_claim_request` | `lead_batches` | Enforces `(company_code, telecaller_id, claim_request_id)` idempotency. |
| **Index** | `uniq_reservation_token`| `lead_reservations` | Ensures individual reservation token uniqueness. |
| **Index** | `uniq_call_lock_token` | `lead_reservations` | Ensures active dial lock token uniqueness. |
| **Index** | `uniq_active_phone` | `lead_reservations` | MariaDB 10.4 VGC unique index preventing double-booking active phones. |
| **Index** | `idx_temp_waterfall_p0` | `temporary_database`| Composite index for P0 fresh lead lookups. |
| **Index** | `idx_temp_waterfall_p2` | `temporary_database`| Composite index for P2 unconnected retry lookups. |
| **Index** | `idx_main_waterfall_p0` | `main_database` | Composite index for P0 fresh lead lookups in main database. |
| **Index** | `idx_main_waterfall_p2` | `main_database` | Composite index for P2 unconnected retry lookups in main database. |
| **Index** | `idx_followups_user_status_due`| `lead_followups`| Composite index for P1 due follow-up lookups. |

---

## 3. Schema Field Justification & Reconciled Usage

### A. `lead_batches` Fields
- `batch_uuid`: Public identifier for the 15-lead batch, returned to the Android app.
- `claim_request_id`: Client-generated UUID for batch claim idempotency.
- `company_code`: Multi-tenant isolation scope.
- `telecaller_id`: User ID of the telecaller who owns this batch.
- `device_id`: Hardware/client installation identifier.
- `session_epoch`: Active session epoch when this batch was claimed. Used to fence out stale devices.
- `allocated_count`: Count of leads assigned in this batch (e.g. 15).
- `lease_duration_seconds`: Batch lease lifetime (default 1200 seconds / 20 minutes).
- `expires_at`: Batch expiration timestamp. Uncalled leads (`RESERVED`) expire when this passes.
- `status`: Batch lifecycle state (`ACTIVE`, `EXPIRED`, `RELEASED`, `COMPLETED`).
- `request_params_json`: Exact JSON parameters of the claim request for audit.
- `request_hash`: SHA256 hash of canonical request parameters to detect conflicting retries.

### B. `lead_reservations` Fields
- `batch_id` & `batch_uuid`: References the parent batch.
- `reservation_token`: Unique token (`res_<uuid>`) issued at claim time.
- `call_lock_token`: Unique token (`lock_<uuid>`) issued when `POST /calls/start` transitions lead to `DIALING`.
- `company_code`, `telecaller_id`, `device_id`, `session_epoch`: Ownership and fencing fields.
- `source`: Contact origin table (`TEMP` or `MAIN`).
- `lead_id`: Primary key ID in `temporary_database` or `main_database`.
- `phone_number_10`: Normalized 10-digit Indian phone number (`[6-9]\d{9}`).
- `priority_tier`: Waterfall tier under which lead was allocated (`P0`, `P1`, `P2`, `P3`).
- `followup_id`: Linked `lead_followups.id` for P1 leads.
- `state`: Lifecycle state (`RESERVED`, `DIALING`, `COMPLETED`, `EXPIRED`, `RELEASED`).
- `reserved_at`: Timestamp when batch was claimed.
- `dialing_started_at`: Timestamp when telecaller clicked "Call" and app launched Android dialer.
- `last_heartbeat_at`: Timestamp of most recent heartbeat renewal while dial lock active.
- `expires_at`: Lease expiration timestamp for `RESERVED` state.
- `completed_at`: Timestamp when call outcome was submitted.
- `call_log_id`: Foreign key linking to inserted `call_logs.id`.
- `client_call_id`: Client-generated idempotency key from call completion.
- `reconciliation_needed`: Flag set to 1 if call remains in `DIALING` for > 4 hours with no activity.
- `active_phone_key`: MariaDB 10.4 Virtual Generated Column (`VIRTUAL`):
  `CASE WHEN state IN ('RESERVED', 'DIALING') THEN phone_number_10 ELSE NULL END`.

---

## 4. Preflight Health & Verification Checks

Run these read-only SQL queries before applying any migration:

```sql
-- 1. Verify MariaDB Version (Must be 10.4+)
SELECT VERSION();

-- 2. Verify Character Set and Collation
SELECT @@character_set_database, @@collation_database;

-- 3. Check for Duplicate client_call_id Entries in call_logs [VERIFIED]
SELECT user_id, client_call_id, COUNT(*) as cnt
FROM call_logs
WHERE client_call_id IS NOT NULL AND client_call_id != ''
GROUP BY user_id, client_call_id
HAVING cnt > 1;

-- 4. Check for Existing Active Locks or Open Transactions
SHOW FULL PROCESSLIST;

-- 5. Inspect Table Status and Sizes
SHOW TABLE STATUS WHERE Name IN ('temporary_database', 'main_database', 'call_logs', 'lead_followups', 'users');
```

---

## 5. Pure SQL Migration Script

File target: `database/migrations/2026_11_19_lead_distribution_engine.sql`

```sql
-- ============================================================================
-- Migration: 2026_11_19_lead_distribution_engine.sql
-- Target: MariaDB 10.4.32+
-- Purpose: Schema, constraints, and composite indexes for Lead Distribution Engine
-- ============================================================================

-- 1. Table: lead_batches
CREATE TABLE IF NOT EXISTS `lead_batches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `batch_uuid` VARCHAR(64) NOT NULL,
    `claim_request_id` VARCHAR(64) NOT NULL,
    `company_code` VARCHAR(50) NOT NULL DEFAULT 'default',
    `telecaller_id` INT NOT NULL,
    `device_id` VARCHAR(100) NOT NULL,
    `session_epoch` INT NOT NULL DEFAULT 1,
    `allocated_count` INT NOT NULL DEFAULT 0,
    `lease_duration_seconds` INT NOT NULL DEFAULT 1200,
    `expires_at` DATETIME NOT NULL,
    `status` ENUM('ACTIVE', 'EXPIRED', 'RELEASED', 'COMPLETED') NOT NULL DEFAULT 'ACTIVE',
    `request_params_json` TEXT NULL,
    `request_hash` CHAR(64) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_batch_uuid` (`batch_uuid`),
    UNIQUE KEY `uniq_claim_request` (`company_code`, `telecaller_id`, `claim_request_id`),
    KEY `idx_batch_caller_status` (`telecaller_id`, `status`, `expires_at`),
    KEY `idx_batch_expiry` (`expires_at`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table: lead_reservations
CREATE TABLE IF NOT EXISTS `lead_reservations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `batch_id` INT NOT NULL,
    `batch_uuid` VARCHAR(64) NOT NULL,
    `reservation_token` VARCHAR(64) NOT NULL,
    `call_lock_token` VARCHAR(64) NULL,
    `company_code` VARCHAR(50) NOT NULL DEFAULT 'default',
    `telecaller_id` INT NOT NULL,
    `device_id` VARCHAR(100) NOT NULL,
    `session_epoch` INT NOT NULL DEFAULT 1,
    `source` ENUM('TEMP', 'MAIN') NOT NULL,
    `lead_id` INT NOT NULL,
    `phone_number_10` VARCHAR(15) NOT NULL,
    `priority_tier` ENUM('P0', 'P1', 'P2', 'P3') NOT NULL,
    `followup_id` INT DEFAULT NULL,
    `state` ENUM('RESERVED', 'DIALING', 'COMPLETED', 'EXPIRED', 'RELEASED') NOT NULL DEFAULT 'RESERVED',
    `reserved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `dialing_started_at` DATETIME DEFAULT NULL,
    `last_heartbeat_at` DATETIME DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `call_log_id` INT DEFAULT NULL,
    `client_call_id` VARCHAR(128) DEFAULT NULL,
    `reconciliation_needed` TINYINT(1) NOT NULL DEFAULT 0,
    `active_phone_key` VARCHAR(30) GENERATED ALWAYS AS (
        CASE WHEN `state` IN ('RESERVED', 'DIALING') THEN `phone_number_10` ELSE NULL END
    ) VIRTUAL,
    UNIQUE KEY `uniq_reservation_token` (`reservation_token`),
    UNIQUE KEY `uniq_call_lock_token` (`call_lock_token`),
    UNIQUE KEY `uniq_active_phone` (`active_phone_key`),
    KEY `idx_res_batch` (`batch_id`),
    KEY `idx_res_caller_state_exp` (`telecaller_id`, `state`, `expires_at`),
    KEY `idx_res_lead_source` (`source`, `lead_id`, `state`),
    KEY `idx_res_phone` (`phone_number_10`),
    KEY `idx_res_dialing_reconciliation` (`state`, `dialing_started_at`, `reconciliation_needed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Telecaller Session Epoch on users
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `session_epoch` INT NOT NULL DEFAULT 1;

-- 4. Composite Performance Indexes for Waterfall Lookups
-- temporary_database indexes
ALTER TABLE `temporary_database`
    ADD INDEX `idx_temp_waterfall_p0` (`CALL_DIALED_STATUS`, `CALL_COUNT`, `TEMP_UPLOAD_DATETIME`),
    ADD INDEX `idx_temp_waterfall_p2` (`CALL_COUNT`, `LAST_DIALED_DATE_TIME`, `CALL_DIALED_STATUS`);

-- main_database indexes
ALTER TABLE `main_database`
    ADD INDEX `idx_main_waterfall_p0` (`MAINDATABASE_CALL_DIALED_STATUS`, `CALL_COUNT`, `MAINDATABASE_UPLOAD_DATETIME`),
    ADD INDEX `idx_main_waterfall_p2` (`CALL_COUNT`, `LAST_DIALED_DATE_TIME`, `MAINDATABASE_CALL_DIALED_STATUS`);

-- lead_followups index
ALTER TABLE `lead_followups`
    ADD INDEX `idx_followups_user_status_due` (`user_id`, `status`, `followup_at`);
```

---

## 6. Post-Migration Verification Queries

```sql
-- 1. Check table existence
SHOW TABLES LIKE 'lead_%';

-- 2. Verify lead_batches structure
DESCRIBE lead_batches;

-- 3. Verify lead_reservations structure and virtual column
DESCRIBE lead_reservations;

-- 4. Verify session_epoch column on users
SHOW COLUMNS FROM users LIKE 'session_epoch';

-- 5. Test active_phone_key uniqueness constraint in rollback-safe transaction
START TRANSACTION;
INSERT INTO lead_batches (batch_uuid, claim_request_id, company_code, telecaller_id, device_id, allocated_count, expires_at)
VALUES ('test-batch-1', 'req-1', 'default', 1, 'dev-1', 1, NOW() + INTERVAL 20 MINUTE);

INSERT INTO lead_reservations (batch_id, batch_uuid, reservation_token, company_code, telecaller_id, device_id, source, lead_id, phone_number_10, priority_tier, expires_at, state)
VALUES (LAST_INSERT_ID(), 'test-batch-1', 'res-1', 'default', 1, 'dev-1', 'TEMP', 999999, '9999999999', 'P0', NOW() + INTERVAL 20 MINUTE, 'RESERVED');

-- This duplicate insert MUST fail with: Duplicate entry '9999999999' for key 'uniq_active_phone'
-- INSERT INTO lead_reservations (batch_id, batch_uuid, reservation_token, company_code, telecaller_id, device_id, source, lead_id, phone_number_10, priority_tier, expires_at, state)
-- VALUES (1, 'test-batch-2', 'res-2', 'default', 2, 'dev-2', 'MAIN', 888888, '9999999999', 'P0', NOW() + INTERVAL 20 MINUTE, 'RESERVED');

ROLLBACK;
```
