# CallNow Server-Side Lead Distribution Engine Upgrade — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade CallNow CRM's PHP backend and MariaDB database to power a high-reliability, server-authoritative lead distribution engine supporting the new Native Kotlin Android application while maintaining 100% backward compatibility with the existing Flutter app and Web CRM.

**Architecture:** Implement a robust reservation and lease management engine (`lead_reservations`) with a deterministic P0→P3 priority waterfall, 20-minute leases, 10-minute heartbeat renewals, tenant-scoped application mutex locks (`GET_LOCK`) compatible with MariaDB 10.4, idempotent call submissions with payload hash verification, and safe late-outcome status protection.

**Tech Stack:** PHP 8.x procedural, MariaDB 10.4.32, `mysqli` prepared statements, RESTful JSON APIs.

**Spec:** [docs/superpowers/specs/2026-11-18-call-bucket-leases-design.md](file:///c:/xampp/htdocs/callnowCRM/docs/superpowers/specs/2026-11-18-call-bucket-leases-design.md) & User Engine Upgrade Specification.

---

## Global Constraints
- Target Database: MariaDB 10.4.32 (strictly NO `SELECT ... FOR UPDATE SKIP LOCKED` syntax).
- Lease Duration: 20 minutes from reservation grant or heartbeat renewal.
- Heartbeat Interval: 10 minutes while telecaller Calling Desk is active.
- Prefetch Threshold: 3 or fewer remaining leads in caller queue.
- Priority Waterfall: P0 (Due Callbacks) → P1 (Fresh Leads) → P2 (Retry Leads) → P3 (Recycled Database).
- Flutter Backward Compatibility: The existing Flutter mobile application and Web CRM must remain 100% functional without requiring `bucket_id` or `reservation_token`.
- Tenant Isolation: Strict database-per-tenant isolation enforced via `php_scripts/tenant.php`.

## Review Focus
1. **MariaDB 10.4 Concurrency**: MariaDB 10.4 lacks `SKIP LOCKED`. Two concurrent telecallers must never be issued the same customer lead. Enforce via tenant-scoped `GET_LOCK(CONCAT('lead_alloc_', DATABASE()), 5)` and ACID transactions.
2. **Flutter Backward Compatibility**: Submitting calls without `bucket_id` or `reservation_token` from existing Flutter app must succeed with HTTP 200 and never throw HTTP 400.
3. **Never Lose Genuine Calls**: If a telecaller submits a call on an expired lease, the call log and audio record must ALWAYS be preserved in `call_logs`.
4. **Late Submission Status Protection**: Late submissions on expired leases must NOT overwrite newer positive statuses (`Lead`, `Interested`, `Converted`, `DND`) set by another agent.
5. **Idempotent Replay Integrity**: Same `client_call_id` + same payload returns original acknowledgement with `replayed: true`; same `client_call_id` + changed payload returns HTTP 409.

---

## Detailed Repository Audit & Architectural Findings

### 1. Existing Functionality to Preserve
* **Authentication & Session Lifecycle**:
  * `api/v1/auth/index.php`: Bearer token generation, 30-day token refresh, login via username/email + `company_code`, password hashing (bcrypt), and profile metadata.
* **Multi-Tenant Dynamic Routing**:
  * `php_scripts/tenant.php`: Dynamic database switching (`callnow_<company>`) via `X-Company-Code` header or session. Master database `callnow_master` holds the tenant company registry.
* **Lead Conversion & CRM Pipeline**:
  * `modules/leads/lead_common.php` & `syncCallToLeadsAndPipeline()`: Conversion of interested contacts into `leads_table` rows with 8-stage Kanban stages (`LEAD` → `FOLLOWUP` → `INTERNAL_UNDERWRITING` → `LOGIN` → `BANK_UNDERWRITING` → `SANCTIONED` → `DISBURSED` → `REJECT`).
* **Telecaller Engagement & XP Engine**:
  * `php_scripts/engagement_engine.php`: Real-time call XP (+5 attempt, +20 connect, +50 conversation), 5-tier mastery ranks (`Bronze` to `Diamond`), and daily target streak tracking.
* **Permanent DND Blacklist**:
  * `TBL_DND_REGISTRY`: Automatic registration and two-way synchronization across `temporary_database` and `main_database`. Rejection of any calls to DND numbers with HTTP 403.
* **Existing 31 Endpoints**:
  * All 31 existing endpoints in `api/v1/auth/`, `api/v1/telecaller/`, and `api/v1/admin/` must retain their route signatures and standard payload formats.

---

### 2. Existing Bugs to Fix
* **Bug 1: Allocation Pool Shortfall in `batch-leads`**:
  * In `api/v1/telecaller/index.php` line 378: `if ($remaining > 0 && count($leads) === 0)` only falls back to `main_database` if `count($leads) === 0`. If `temporary_database` has only 2 leads, telecallers receive only 2 leads instead of 15, leaving the queue underfilled.
* **Bug 2: Unsafe Concurrency in Legacy `batch-leads`**:
  * Lines 325–341 run an uncoordinated `SELECT ... LIMIT 30` without transaction locking, then `UPDATE ... WHERE ID IN (...)`. Concurrent callers overlap on candidate IDs, causing dropped claims and uneven distributions.
* **Bug 3: Hardcoded 15-Minute Expiration in SQL Queries**:
  * SQL queries in `batch-leads` and `next-call` hardcode `(NOW() - INTERVAL 15 MINUTE)`. This conflicts with the confirmed 20-minute lease duration and causes premature lead reassignment while telecallers are still in their 20-minute window.
* **Bug 4: Missing Heartbeat & Lease Extension**:
  * No mechanism currently exists to extend active lead reservations while a telecaller is actively handling long conversations, causing uncalled batch leads to expire on the server after 15 minutes.
* **Bug 5: Follow-Up Disconnect from Calling Desk**:
  * `batch-leads` and `next-call` only query `temporary_database` and `main_database`. Scheduled callbacks in `lead_followups` that are due are completely omitted from the calling queue, forcing callers to check Tab 7 manually.
* **Bug 6: Overly Sensitive Request Hash on Idempotent Retries**:
  * `submit_call.php` hashes the raw input JSON with `sha256`. Client-side offline retries containing fluctuating metadata (e.g., `retry_count`, slight clock variance) trigger false-positive `409 Conflict` errors instead of returning the saved call record.
* **Bug 7: Unprotected Late Overwrites on Customer Master**:
  * If a telecaller's lease expires and another agent marks the customer `Interested` or `Lead`, a late offline submission from the first caller unconditionally overwrites `MAINDATABASE_CALL_DIALED_STATUS`, erasing positive customer outcomes.

---

### 3. New Functionality to Implement
* **Engine Core (`php_scripts/lead_distribution_engine.php`)**:
  * Centralized, modular distribution engine controlling lead eligibility, P0–P3 waterfall ordering, reservation creation, heartbeat renewals, and expiry recovery.
  * MariaDB 10.4-compatible concurrency control using tenant-scoped application mutex lock: `SELECT GET_LOCK(CONCAT('lead_alloc_', DATABASE()), 5)`.
* **Reservation Lifecycle & State Machine**:
  * Lifecycle states: `RESERVED` → `DIALING` → `COMPLETED` / `EXPIRED` / `RELEASED`.
  * Dedicated reservation table: `lead_reservations` tracking `reservation_token`, `lease_id`, `telecaller_id`, `source`, `lead_id`, `priority`, `reserved_at`, `expires_at`, `last_heartbeat_at`.
* **P0–P3 Waterfall Allocator**:
  * **P0**: Scheduled Callbacks / Follow-ups Due or Overdue (`lead_followups.status = 'OPEN' AND followup_at <= NOW()`).
  * **P1**: Fresh / Uncalled Leads (`LAST_DIALED_DATE_TIME IS NULL`, `CALL_DIALED_STATUS IN ('Pending', 'Not Called', '')`).
  * **P2**: Retry / Unconnected Leads (`CALL_DIALED_STATUS IN ('Ringing', 'Callback / Busy', 'Not Reachable', 'Switch Off')`, cooled down past 2 hours, `CALL_COUNT < 5`).
  * **P3**: Recycled / Aged Database (Contacts past 90-day cooldown).
* **Heartbeat & Renewal Handler**:
  * Extends active uncalled lead reservations by `+20 minutes` when calling desk heartbeat is received (expected every 10 minutes).
* **Safe Late-Submission Handler**:
  * Always persists call log, duration, notes, and audio recording.
  * Evaluates current master lead status before overwriting: prevents low-priority dispositions (`Not Reachable`, `Ringing`) from overwriting high-priority dispositions (`Lead`, `Enquiry`, `Interested`, `Do Not Call`).
* **Offline Synchronization Endpoint (`POST /telecaller/calls/sync`)**:
  * Ingests array of offline call completions with per-item idempotency and granular response receipts.

---

### 4. Database Changes
* **New Table: `lead_reservations`**:
  ```sql
  CREATE TABLE IF NOT EXISTS `lead_reservations` (
      `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
      `reservation_token` VARCHAR(64) NOT NULL,
      `lease_id` VARCHAR(64) NOT NULL,
      `company_code` VARCHAR(50) NOT NULL DEFAULT 'default',
      `telecaller_id` INT NOT NULL,
      `team_id` INT NULL DEFAULT NULL,
      `source` ENUM('TEMP', 'MAIN', 'FOLLOWUP') NOT NULL DEFAULT 'TEMP',
      `lead_id` INT NOT NULL,
      `client_contact_id` INT NULL DEFAULT NULL,
      `followup_id` INT NULL DEFAULT NULL,
      `priority` ENUM('P0', 'P1', 'P2', 'P3') NOT NULL DEFAULT 'P1',
      `state` ENUM('RESERVED', 'DIALING', 'COMPLETED', 'EXPIRED', 'RELEASED') NOT NULL DEFAULT 'RESERVED',
      `reserved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `expires_at` DATETIME NOT NULL,
      `last_heartbeat_at` DATETIME NULL DEFAULT NULL,
      `completed_at` DATETIME NULL DEFAULT NULL,
      `call_log_id` INT NULL DEFAULT NULL,
      `client_call_id` VARCHAR(128) NULL DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY `uniq_reservation_token` (`reservation_token`),
      KEY `idx_telecaller_lease` (`telecaller_id`, `lease_id`, `state`),
      KEY `idx_active_lead` (`source`, `lead_id`, `state`, `expires_at`),
      KEY `idx_expires_state` (`expires_at`, `state`),
      KEY `idx_client_call` (`client_call_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  ```
* **Performance & Concurrency Composite Indexes**:
  * `temporary_database`: `idx_temp_alloc_p1` on `(CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, TEMP_UPLOAD_DATETIME, ID)`
  * `main_database`: `idx_main_alloc_p1` on `(MAINDATABASE_CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME, ID)`
  * `lead_followups`: `idx_followup_p0_alloc` on `(status, user_id, followup_at, id)`
  * `call_logs`: `idx_call_logs_client_call` on `(user_id, client_call_id)`
* **Migration Script**: `database/migrations/2026_11_19_server_lead_distribution_engine.sql`.
* **Hostinger Master Schema**: Update `database/hostinger_schema.sql` (Section 33).

---

### 5. API Changes Required by Kotlin (With Zero Flutter Breakage)
* **New Route: `POST /api/v1/telecaller/calling/claim` (and `/telecaller/leads/claim`)**:
  * Request: `{"batch_size": 15, "prefetch": false}`
  * Response: Returns `lease_id`, `lease_duration_seconds: 1200`, `heartbeat_interval_seconds: 600`, `expires_at`, `prefetch_threshold: 3`, and 15 waterfall-sorted leads with `reservation_token`, `priority: "P0"|"P1"|"P2"|"P3"`, `dial_phone`, `dial_uri`, etc.
* **New Route: `POST /api/v1/telecaller/calling/heartbeat` (and `/telecaller/leads/heartbeat`)**:
  * Request: `{"lease_id": "...", "reservation_tokens": ["token1", "token2"]}`
  * Extends `expires_at` by 20 minutes for active uncalled reservations. Returns updated `expires_at` and active counts.
* **New Route: `POST /api/v1/telecaller/calling/release` (and `/telecaller/leads/release`)**:
  * Explicitly releases uncalled reservations if a telecaller leaves the Calling Desk.
* **New Route: `POST /api/v1/telecaller/calls/sync`**:
  * Offline batch sync endpoint accepting `{"calls": [...]}` and returning per-call results.
* **Backward-Compatible Existing Routes**:
  * `GET /api/v1/telecaller/batch-leads`: Preserves exact legacy output structure for Flutter, internally backed by the new P0–P3 allocation engine.
  * `POST /api/v1/telecaller/calls/{id}/complete` & `/submit_call`: Accepts optional `reservation_token` or `bucket_id` when sent by Kotlin, but permits calls without them for Flutter.

---

## Required Documentation Deliverables (Section 7)
The following documents will be created under `docs/`:
1. `docs/SERVER_LEAD_ENGINE_ARCHITECTURE.md`: Complete system architecture, state machines, priority waterfall rules, MariaDB 10.4 locking strategy, and concurrency proofs.
2. `docs/NEW_MOBILE_API_CONTRACTS.md`: Detailed OpenAPI/JSON specs for all Kotlin and Flutter calling endpoints.
3. `docs/DATABASE_MIGRATION_PLAN.md`: Step-by-step schema evolution, index creation, MariaDB 10.4 compatibility validation, and self-healing bootstrap.
4. `docs/ROLLBACK_PLAN.md`: Emergency rollback procedures, downgrade SQL scripts, and feature toggle fallbacks.
5. `docs/SERVER_TEST_REPORT.md`: Audit test results, concurrency benchmarks, and compatibility validation reports.

---

## Detailed Task Breakdown

### Stage 1: Documentation & Architecture Validation
- [ ] **Task 1.1**: Create `docs/SERVER_LEAD_ENGINE_ARCHITECTURE.md`
- [ ] **Task 1.2**: Create `docs/NEW_MOBILE_API_CONTRACTS.md`
- [ ] **Task 1.3**: Create `docs/DATABASE_MIGRATION_PLAN.md`
- [ ] **Task 1.4**: Create `docs/ROLLBACK_PLAN.md`

### Stage 2: Database Migration Preparation & Self-Healing Engine
- [ ] **Task 2.1**: Write migration `database/migrations/2026_11_19_server_lead_distribution_engine.sql`
- [ ] **Task 2.2**: Implement `ensureLeadDistributionEngineSchema(mysqli $link)` in `php_scripts/config.php`
- [ ] **Task 2.3**: Register `TBL_LEAD_RESERVATIONS` in `config.php` and `tn()` table resolver
- [ ] **Task 2.4**: Update `database/hostinger_schema.sql` with Section 33 schema

### Stage 3: Core Lead Distribution Engine (`php_scripts/lead_distribution_engine.php`)
- [ ] **Task 3.1**: Implement tenant mutex lock: `acquireTenantAllocationLock($link, $timeout = 5)` & release helper
- [ ] **Task 3.2**: Implement P0 allocator: `fetchDueFollowupLeads($link, $userId, $teamId, $limit)`
- [ ] **Task 3.3**: Implement P1 allocator: `fetchFreshUncalledLeads($link, $userId, $limit)`
- [ ] **Task 3.4**: Implement P2 allocator: `fetchRetryCooldownLeads($link, $userId, $limit)`
- [ ] **Task 3.5**: Implement P3 allocator: `fetchRecycledAgedLeads($link, $userId, $limit)`
- [ ] **Task 3.6**: Implement atomic waterfall orchestrator: `claimLeadBatchWaterfall($link, $userId, $teamId, $batchSize = 15)`
- [ ] **Task 3.7**: Implement lease heartbeat renewal: `renewLeadReservations($link, $userId, $leaseId, $tokens)`
- [ ] **Task 3.8**: Implement reservation cleanup & expiration recovery: `expireStaleReservations($link)`

### Stage 4: API Endpoints & Idempotent Submission Upgrades
- [ ] **Task 4.1**: Implement `POST /telecaller/calling/claim` and `/telecaller/leads/claim` in `api/v1/telecaller/index.php`
- [ ] **Task 4.2**: Implement `POST /telecaller/calling/heartbeat` in `api/v1/telecaller/index.php`
- [ ] **Task 4.3**: Implement `POST /telecaller/calling/release` in `api/v1/telecaller/index.php`
- [ ] **Task 4.4**: Refactor `GET /telecaller/batch-leads` to leverage P0–P3 waterfall while preserving exact Flutter response shape
- [ ] **Task 4.5**: Upgrade `api/v1/telecaller/submit_call.php`:
  - Graceful optional `reservation_token` handling (zero 400 errors for Flutter)
  - Resilient `client_call_id` replay verification (ignores fluctuating metadata)
  - Late submission status protection (never overwrites newer positive lead status)
  - Mark reservation `COMPLETED` on valid submission
- [ ] **Task 4.6**: Implement offline batch sync `POST /telecaller/calls/sync` in `api/v1/telecaller/index.php`

### Stage 5: Automated Testing, Concurrency Verification & Reports
- [ ] **Task 5.1**: Write automated test suite `tests/test_lead_distribution_engine.php` (verifying P0–P3 waterfall, 20-min lease, 10-min heartbeat, concurrency isolation)
- [ ] **Task 5.2**: Write HTTP endpoint simulation suite `tests/test_calling_api_contracts.php` (verifying Kotlin contracts + Flutter backward compatibility)
- [ ] **Task 5.3**: Run concurrency stress test simulating 10 simultaneous telecallers (0 duplicate assignments)
- [ ] **Task 5.4**: Generate `docs/SERVER_TEST_REPORT.md`
- [ ] **Task 5.5**: Clean up temporary test scripts from codebase as required

### Stage 6: Documentation & Context Summary Updates
- [ ] **Task 6.1**: Update `project-context/CODEBASE_SUMMARY.md`
- [ ] **Task 6.2**: Update `README.md`
- [ ] **Task 6.3**: Batch PHP syntax linting verification across entire repository

---

## Approval Gate
Per prompt instruction: **Wait for explicit user approval before implementing database or PHP changes.**
