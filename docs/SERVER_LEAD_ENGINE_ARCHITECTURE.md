# CallNow Server-Side Lead Distribution Engine Architecture (v1.4)

## 1. System Overview & Core Philosophy

The CallNow PHP backend is the **single authoritative source of truth** for lead distribution, queue allocation, priority ordering, active-call locks, lease renewals, status reconciliation, and call outcome validations.

The native Kotlin Android application operates as a resilient local execution client:
- It maintains an offline-first SQLite cache.
- It executes the calling workflow via Android telephony intents, records duration, and uploads audio recordings.
- It **never** decides lead eligibility, priority waterfall ordering, or reservation state.
- It requests batches when its local uncalled queue drops to **3 or fewer remaining leads** (Prefetch Threshold).

---

## 2. Priority Waterfall Specification (v1.4)

The lead distribution engine evaluates eligible contacts across four strictly mutually exclusive priority tiers:

```
+-------------------------------------------------------------------------+
| P0: Fresh Never-Called Leads                                            |
| (LAST_DIALED_DATE_TIME IS NULL, CALL_COUNT = 0, no open follow-up)      |
+-------------------------------------------------------------------------+
                                    │
                                    ▼
+-------------------------------------------------------------------------+
| P1: Scheduled Due Callbacks / Follow-ups                                |
| (lead_followups.status = 'OPEN' AND followup_at <= NOW())               |
+-------------------------------------------------------------------------+
                                    │
                                    ▼
+-------------------------------------------------------------------------+
| P2: Attempted Unconnected Retries                                       |
| (Last call between 4h and 90d ago, CALL_COUNT 1-4, no open follow-up)   |
+-------------------------------------------------------------------------+
                                    │
                                    ▼
+-------------------------------------------------------------------------+
| P3: Recycled Aged Pool                                                  |
| (Last call older than 90 days, no open follow-up, DND/converted excluded|
+-------------------------------------------------------------------------+
```

### Mutually Exclusive Tier Definitions

| Tier | Name | Strict Eligibility Criteria | Order By |
|---|---|---|---|
| **P0** | **Fresh Never-Called** | `LAST_DIALED_DATE_TIME IS NULL AND CALL_COUNT = 0` AND status in (`'Not Called'`, `'Pending'`, `''`, `NULL`) AND `NOT EXISTS (SELECT 1 FROM lead_followups f WHERE f.status = 'OPEN' AND f.lead_id = t.ID)` AND `NOT IN (SELECT phone_number FROM dnd_registry)` AND not actively reserved. | `TEMP_UPLOAD_DATETIME ASC, id ASC` |
| **P1** | **Due Follow-ups / Callbacks** | Records in `lead_followups` where `status = 'OPEN' AND followup_at <= NOW()` AND assigned to caller (`user_id = $userId` or `CALL_BY = $userId`) AND customer not on DND.<br>**Customer Deduplication**: Deduplicated by normalized customer phone number (`MIN(followup_at)` selected) to prevent multi-call collisions on leads with multiple reminder notes. | `followup_at ASC, id ASC` |
| **P2** | **Attempted Unconnected** | `LAST_DIALED_DATE_TIME` between **4 hours ago** (cooldown) and **90 days ago** AND status in (`'Ringing'`, `'Busy'`, `'Switch Off'`, `'Not Reachable'`, `'Call Later'`, `'Callback / Busy'`) AND `CALL_COUNT BETWEEN 1 AND 4` (5th attempt ceiling) AND no open follow-up AND not converted/rejected AND customer not on DND. | `CALL_COUNT ASC, LAST_DIALED_DATE_TIME ASC, id ASC` |
| **P3** | **Recycled Aged Pool** | `LAST_DIALED_DATE_TIME < NOW() - INTERVAL 90 DAY` AND no open follow-up AND customer not on DND.<br>**Explicit Exclusions**: Excludes converted contacts (`Lead`, `Interested`, `Enquiry`, `Sanctioned`, `Disbursed`, `Login`), permanently rejected / invalid contacts (`Wrong Number`, `Not Interested`, `Lost`, `Do Not Call`, `DND`), and contacts with future follow-ups. | `LAST_DIALED_DATE_TIME ASC, id ASC` |

### Future Follow-up Protection
Any contact with an open scheduled follow-up in the future (`followup_at > NOW()`) is **strictly excluded** from all calling tiers (P0, P2, and P3). It will only become eligible when its scheduled timestamp arrives under P1.

---

## 3. Concurrency & Canonical Phone Uniqueness (MariaDB 10.4.32)

Because MariaDB 10.4 does not support `SKIP LOCKED`, two-tier concurrency and uniqueness protection is implemented:

### Tier A: Tenant Application Mutex (`GET_LOCK`)
Before running allocation queries, the engine acquires a tenant-scoped advisory lock:
```sql
SELECT GET_LOCK(CONCAT('cn_alloc_', SUBSTRING(MD5(DATABASE()), 1, 16)), 5);
```
- **Scope**: Per-tenant database.
- **Timeout**: 5 seconds. If timeout or error, returns `HTTP 503 Service Unavailable` ("Server busy allocating leads, please retry").
- **Release**: Guaranteed release in PHP `finally` block on the same connection.

### Tier B: MariaDB 10.4 Database-Level Unique Constraint on Canonical Phone
Cross-source duplication exists between `temporary_database` and `main_database` (e.g. leads uploaded via CSV into TEMP while existing in MAIN).

1. **Normalization**: Phone numbers are normalized using `normalize_indian_phone()`:
   - Strips non-digits, strips leading `+91` or `0`, validates 10 digits beginning with `[6-9]`.
   - If null, empty, or invalid, the virtual column evaluates to `NULL` (InnoDB allows multiple `NULL` values in unique indexes, so invalid numbers do not block index creation). However, allocation queries explicitly require `LENGTH(phone_number_10) = 10`.
2. **Shared Phone Numbers & Deduplication**:
   - The engine locks by **normalized 10-digit phone number**, NOT solely by `lead_id`.
   - If Phone `9876543210` is reserved by Telecaller X from `temporary_database` (ID 1052), Telecaller Y CANNOT be assigned `9876543210` from `main_database` (ID 504) simultaneously.
   - **No Uncontrolled Customer Merge**: The records in `temporary_database` and `main_database` remain separate customer entries in the CRM; their profiles are not merged without explicit CRM deduplication rules. But active calling concurrency on the same phone number is strictly prevented.
3. **MariaDB 10.4 Virtual Generated Column (`VIRTUAL`) Constraint**:
```sql
`active_phone_key` VARCHAR(30) GENERATED ALWAYS AS (
    CASE WHEN `state` IN ('RESERVED', 'DIALING') THEN `phone_number_10` ELSE NULL END
) VIRTUAL,
UNIQUE KEY `uniq_active_phone` (`active_phone_key`)
```
- Active states (`RESERVED` and `DIALING`) populate `active_phone_key` with `phone_number_10`.
- MariaDB immediately rejects concurrent active reservation with: `Duplicate entry '<phone>' for key 'uniq_active_phone'`.
- Terminal states (`COMPLETED`, `EXPIRED`, `RELEASED`) set `active_phone_key` to `NULL`, freeing the number for future eligibility while preserving audit history.

---

## 4. Legacy Allocation Collision Protection (v1.4)

To prevent legacy Flutter apps and Web CRM dialers from claiming leads actively held by the new Kotlin engine:

### A. Dual-Table Exclusion Guard in Legacy Allocation
In `api/v1/telecaller/index.php` (`/batch-leads`) and Web CRM dial lists, both the `temporary_database` and `main_database` candidate queries must include the active reservation guard:

```sql
-- Exclusion guard for temporary_database
AND NOT EXISTS (
    SELECT 1 FROM lead_reservations lr
    WHERE lr.phone_number_10 = RIGHT(REGEXP_REPLACE(t.CUST_MOBILE, '[^0-9]', ''), 10)
      AND lr.state IN ('RESERVED', 'DIALING')
      AND (lr.state = 'DIALING' OR lr.expires_at > NOW())
)

-- Exclusion guard for main_database
AND NOT EXISTS (
    SELECT 1 FROM lead_reservations lr
    WHERE lr.phone_number_10 = RIGHT(REGEXP_REPLACE(m.MAINDATABASE_MOBILE, '[^0-9]', ''), 10)
      AND lr.state IN ('RESERVED', 'DIALING')
      AND (lr.state = 'DIALING' OR lr.expires_at > NOW())
)
```

### B. Cleanup of Stale RESERVED Leads Before Allocation
Before executing allocation queries in both engines, a fast sweep expires uncalled leads whose leases have lapsed:
```sql
UPDATE lead_reservations 
SET state = 'EXPIRED' 
WHERE state = 'RESERVED' AND expires_at <= NOW();

UPDATE lead_batches 
SET status = 'EXPIRED' 
WHERE status = 'ACTIVE' AND expires_at <= NOW();
```
> [!CRITICAL]
> **Safety Rule**: Notice `WHERE state = 'RESERVED'`. Leads in `DIALING` state are **NEVER** touched by normal lease cleanup!

---

## 5. Active-Call Lifecycle & Decoupled Call Locks (v1.4)

### A. Decoupling Batch Lease from Active-Call Lock
- **Batch Lease** (`lead_batches.expires_at`): 20-minute lease for the batch of 15 queued uncalled leads (`RESERVED`).
- **Active-Call Lock** (`lead_reservations.state = 'DIALING'`): Created when `/calls/start` is called.
- **Strict Rule**: When a lead transitions to `DIALING`, it is completely decoupled from the batch lease. Even if `lead_batches.status` transitions to `EXPIRED`, the `DIALING` lock remains active. It is **NEVER** automatically reassigned or released just because the batch expired.

### B. Removal of Arbitrary Timeouts
- **No 30-Minute Hard Ceiling**: In enterprise financial telecalling, calls can exceed 30 or 45 minutes. A wall-clock timer must never presume a call has ended.
- **No Automatic 19:00 IST Release**: Telecallers working overtime or making evening callbacks must not have in-flight dial locks dropped by an arbitrary end-of-day cron.

### C. Four-Hour Silence Trigger & Safe Orphan Reconciliation
- If a reservation has remained in `DIALING` for **4 hours** without a heartbeat, call completion, or app contact:
  - The server flags the record: `reconciliation_needed = 1`.
  - It does **NOT** automatically release the lead to other callers.
  - An administrator or supervisor reviews the orphan via the Calling Desk Admin Hub.
  - The supervisor can manually reconcile the lock: either enter the call outcome, or mark it `ABANDONED_ORPHAN` with an audit reason, releasing the lead back to pool.

### D. Dedicated Read-Only Active-Call Recovery API
To safely recover after app backgrounding, crashes, or device reboots without side-effects, the server provides a **dedicated read-only endpoint**:
- **Route**: `GET /api/v1/telecaller/calls/active`
- **Behavior**: Inspects `lead_reservations` for the authenticated caller where `state = 'DIALING'`.
- Returns the full call context (`reservation_token`, `call_lock_token`, `lead_id`, `source`, `customer_name`, `phone_number_10`, `dialing_started_at`) so the app can resume the call completion dialog.
- *Strict Rule*: `POST /calls/start` is write-only and is **never** reused for read-only recovery.

---

## 6. Multi-Device Fencing & `session_epoch` (v1.4)

To handle telecallers switching devices or logging in from replacement phones:

1. **`session_epoch` Counter**:
   - Stored in `users.session_epoch` (INT NOT NULL DEFAULT 1).
   - When a telecaller logs in or claims a batch from Device B:
     - Server increments `session_epoch = session_epoch + 1`.
     - New batch is bound to `(device_id = 'Device B', session_epoch = new_epoch)`.
2. **Selective State Transition on Transfer**:
   - **`RESERVED` Leads**: Any leads held by the previous device that have NOT yet been dialed (`state = 'RESERVED'`) are transitioned to `RELEASED` and returned to the pool.
   - **`DIALING` Leads**: Any lead currently in `DIALING` state is **PRESERVED**! It is NOT released.
3. **Stale Device Enforcement**:
   - Stale Device A attempting `/calling/claim`, `/calling/heartbeat`, or `/calls/start` with an outdated `session_epoch` is rejected with `HTTP 409 Conflict` (`"Session revoked on this device. Telecaller active on another device."`).
   - **Critical Submission Exemption**: If Device A submits `POST /calls/{id}/complete` for its active `DIALING` call, the server **ACCEPTS** the submission! The call record, duration, and XP are saved, ensuring calls placed on the native dialer are never discarded due to device transfers.

---

## 7. Idempotency Design (Claim & Call Submissions)

### A. Batch Claim Idempotency
- **Scope**: `UNIQUE KEY (company_code, telecaller_id, claim_request_id)` on `lead_batches`.
- **Request Hash**: `request_hash = SHA256(canonical_json(batch_size, prefetch))`.
- **Behavior**:
  - **Identical Request**: Replaying the same `claim_request_id` with matching hash immediately fetches and returns the original allocated batch and lead tokens.
  - **Conflicting Request**: Sending the same `claim_request_id` with different parameters returns `HTTP 409 Conflict`.
  - **Concurrent Retries**: Wrapped in a database transaction; duplicate concurrent claims are caught by `uniq_claim_request`, returning the batch allocated by the first thread.
  - **Expired / Released Batch**: Replaying an expired batch returns `HTTP 200 OK` with `status: "EXPIRED"` and `leads: []`, prompting the app to request a new batch.

### B. Call Submission Idempotency
- **Scope**: Verified MariaDB constraint `UNIQUE KEY uniq_user_client_call (user_id, client_call_id)` on `call_logs`.
- **Payload Hash**: `request_hash = SHA256(canonical_json(client_call_id, record_id, source, call_status, call_duration, notes, next_followup))`.
- **Atomic Execution Boundary**:
  All call submission side effects execute inside a single transactional boundary:
  ```sql
  START TRANSACTION;
  -- 1. Insert call_logs record with request_hash
  -- 2. Update temporary_database or main_database (CALL_COUNT, LAST_DIALED_DATE_TIME, status)
  -- 3. Award telecaller XP and streak in engagement_engine
  -- 4. Insert lead_followups if next_followup specified
  -- 5. Mark lead_reservations state = 'COMPLETED'
  COMMIT;
  ```
- **Replay & Mismatch Handling**:
  - **Same `client_call_id` + Same Payload**: Server returns original HTTP 200 response with original `log_id`, `"replayed": true`, and engagement metrics. None of the database side effects re-execute.
  - **Same `client_call_id` + Different Payload**: Server rejects request with `HTTP 409 Conflict` (`"client_call_id already exists with different payload"`).

---

## 8. Status Reconciliation & Recency Protection (v1.4)

When call outcomes are submitted asynchronously or out of order:
1. **Immutable Call Log**: Every valid call is permanently appended to `call_logs`.
2. **Customer Master Status Hierarchy**:
   - **Level 4 (Permanent / Protected)**: `Do Not Call`, `DND`. Once set, NO subsequent call result can undo DND.
   - **Level 3 (High Value / Converted)**: `Lead`, `Interested`, `Enquiry`, `Sanctioned`, `Disbursed`, `Login`.
   - **Level 2 (Terminal Negative)**: `Wrong Number`, `Not Interested`, `Lost`, `Rejected`.
   - **Level 1 (Transient Unconnected)**: `Ringing`, `Busy`, `Switch Off`, `Not Reachable`, `Call Later`.
3. **Deterministic Event Ordering & Tie-Breaking**:
   - Compare incoming call's `dial_time` against customer's `LAST_DIALED_DATE_TIME`.
   - **Downgrade Prevention**: An incoming call with Level 1 or Level 2 status can **never downgrade** a customer currently at Level 3 or Level 4.
   - **Stale Submission**: If `dial_time < LAST_DIALED_DATE_TIME`:
     - Master customer status is **preserved**.
     - `call_logs` is inserted, XP is awarded.
     - API response returns `"reconciliation": "STATUS_SUPERSEDED"`.
   - **DND Immutability**: If a contact is in `dnd_registry` or marked `Do Not Call`, ordinary call submissions will never clear or alter DND.
