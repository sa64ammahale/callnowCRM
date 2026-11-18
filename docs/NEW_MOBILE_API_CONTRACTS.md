# CallNow Mobile API Contracts (Kotlin v1.2.0 & Flutter Compatibility) — v1.4

This document specifies the exact JSON API contracts for the CallNow native Kotlin Android application and legacy Flutter mobile client.

---

## 1. Authentication & Common Headers

All protected endpoints require HTTP Bearer authentication and multi-tenant routing:
```http
Authorization: Bearer <token>
X-Company-Code: <company_code>
Content-Type: application/json
```

Standard Success Envelope:
```json
{
  "success": true,
  "data": { ... }
}
```

Standard Error Envelope:
```json
{
  "success": false,
  "error": {
    "code": 400,
    "message": "Human readable error description"
  }
}
```

---

## 2. Canonical Claim Route: `POST /api/v1/telecaller/calling/claim`

Requests a batch of waterfall-allocated leads (P0 → P1 → P2 → P3).

### Request
* **Method**: `POST`
* **Path**: `/api/v1/telecaller/calling/claim`
* **Body**:
```json
{
  "claim_request_id": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
  "device_id": "android_98a76d54bc32",
  "session_epoch": 1,
  "batch_size": 15,
  "prefetch": false
}
```

### Parameters
| Field | Type | Required | Description |
|---|---|---|---|
| `claim_request_id` | `String` (UUID v4) | Yes | Persistent client-generated idempotency key. |
| `device_id` | `String` | Yes | Unique hardware or installation ID of the client. |
| `session_epoch` | `Integer` | Yes | Active telecaller session epoch. Incremented on device switch. |
| `batch_size` | `Integer` | No | Number of leads requested (default 15, min 1, max 30). |
| `prefetch` | `Boolean` | No | Flag indicating prefetch trigger (local queue <= 3). |

### Success Response (`200 OK`)
```json
{
  "success": true,
  "data": {
    "batch_uuid": "b47e2211-9831-4e78-90f1-4dfeb797d021",
    "lease_duration_seconds": 1200,
    "expires_at": "2026-11-18T19:45:00Z",
    "heartbeat_interval_seconds": 600,
    "prefetch_threshold": 3,
    "session_epoch": 1,
    "total_allocated": 15,
    "leads": [
      {
        "reservation_token": "res_88192a74-b52e-461f-9988-cf902b11d882",
        "priority": "P0",
        "priority_label": "Fresh Never-Called",
        "source": "TEMP",
        "record_id": 1052,
        "name": "Rajesh Sharma",
        "mobile": "9876543210",
        "formatted_mobile": "+91 98765 43210",
        "dial_phone": "+919876543210",
        "dial_uri": "tel:+919876543210",
        "company": "Fast Logistics Ltd",
        "package": "Gold",
        "other_info": "Uploaded today",
        "last_status": "Not Called",
        "scheduled_time": null,
        "followup_id": null
      },
      {
        "reservation_token": "res_22391b11-a44d-4819-bf91-ea118a9921c3",
        "priority": "P1",
        "priority_label": "Due Follow-up",
        "source": "MAIN",
        "record_id": 504,
        "name": "Pooja Mehta",
        "mobile": "9812345678",
        "formatted_mobile": "+91 98123 45678",
        "dial_phone": "+919812345678",
        "dial_uri": "tel:+919812345678",
        "company": "Mehta Traders",
        "package": "Silver",
        "other_info": "Customer requested loan callback",
        "last_status": "Callback / Busy",
        "scheduled_time": "2026-11-18T19:15:00Z",
        "followup_id": 48
      }
    ]
  }
}
```

### Idempotency Behavior:
1. **Identical Request**: If a request with an existing `claim_request_id` is re-sent with identical parameters within the same tenant and caller, the server returns the **exact original batch** without allocating new leads.
2. **Conflicting Request**: If the same `claim_request_id` is re-sent with different parameters, the server returns `409 Conflict`.

---

## 3. Dedicated Read-Only Active-Call Recovery: `GET /api/v1/telecaller/calls/active`

Used upon app startup or foreground return to safely query whether an in-flight call lock is currently active. Does not modify database state.

### Request
* **Method**: `GET`
* **Path**: `/api/v1/telecaller/calls/active`

### Success Response — Call Active (`200 OK`)
```json
{
  "success": true,
  "data": {
    "has_active_call": true,
    "call": {
      "batch_uuid": "b47e2211-9831-4e78-90f1-4dfeb797d021",
      "reservation_token": "res_88192a74-b52e-461f-9988-cf902b11d882",
      "call_lock_token": "lock_11a87e22-34f1-46ab-8891-ab2209d8112e",
      "lead_id": 1052,
      "source": "TEMP",
      "customer_name": "Rajesh Sharma",
      "phone_number_10": "9876543210",
      "dialing_started_at": "2026-11-18T19:20:00Z",
      "session_epoch": 1,
      "device_id": "android_98a76d54bc32"
    }
  }
}
```

### Success Response — No Call Active (`200 OK`)
```json
{
  "success": true,
  "data": {
    "has_active_call": false,
    "call": null
  }
}
```

---

## 4. Start-Call Route: `POST /api/v1/telecaller/calls/start`

Invoked immediately when the telecaller taps "Call" on a specific lead card. Activates the decoupled active-call lock and protects the lead while the Android native dialer is in the foreground.

### Request
* **Method**: `POST`
* **Path**: `/api/v1/telecaller/calls/start`
* **Body**:
```json
{
  "reservation_token": "res_88192a74-b52e-461f-9988-cf902b11d882",
  "lead_id": 1052,
  "source": "TEMP",
  "device_id": "android_98a76d54bc32",
  "session_epoch": 1
}
```

### Success Response (`200 OK`)
```json
{
  "success": true,
  "data": {
    "call_lock_token": "lock_11a87e22-34f1-46ab-8891-ab2209d8112e",
    "reservation_token": "res_88192a74-b52e-461f-9988-cf902b11d882",
    "state": "DIALING",
    "dialing_started_at": "2026-11-18T19:20:00Z",
    "expires_at": "2026-11-18T19:40:00Z",
    "decoupled_call_lock": true
  }
}
```

### Error Responses
* `403 Forbidden`: Reservation does not belong to authenticated caller or device.
* `409 Conflict`: Reservation has expired, was released, or session epoch is stale (`"Session active on another device"`).

---

## 5. Heartbeat Route: `POST /api/v1/telecaller/calling/heartbeat`

Periodic heartbeat (sent every 10 minutes) while the Calling Desk is active. Renews active uncalled lead reservations by +20 minutes.

### Request
* **Method**: `POST`
* **Path**: `/api/v1/telecaller/calling/heartbeat`
* **Body**:
```json
{
  "batch_uuid": "b47e2211-9831-4e78-90f1-4dfeb797d021",
  "device_id": "android_98a76d54bc32",
  "session_epoch": 1,
  "reservation_tokens": [
    "res_88192a74-b52e-461f-9988-cf902b11d882",
    "res_22391b11-a44d-4819-bf91-ea118a9921c3"
  ]
}
```

### Success Response (`200 OK`)
```json
{
  "success": true,
  "data": {
    "batch_uuid": "b47e2211-9831-4e78-90f1-4dfeb797d021",
    "renewed_count": 2,
    "expires_at": "2026-11-18T19:45:00Z",
    "server_time": "2026-11-18T19:25:00Z"
  }
}
```

---

## 6. Call Result Submission: `POST /api/v1/telecaller/calls/{id}/complete`

Submits the physical call outcome, duration, notes, and loan details.

### Request
* **Method**: `POST`
* **Path**: `/api/v1/telecaller/calls/1052/complete` *(or `/api/v1/telecaller/submit_call`)*
* **Body**:
```json
{
  "reservation_token": "res_88192a74-b52e-461f-9988-cf902b11d882",
  "call_lock_token": "lock_11a87e22-34f1-46ab-8891-ab2209d8112e",
  "source": "TEMP",
  "call_status": "Connected",
  "call_duration": 65,
  "total_duration": 72,
  "notes": "Customer interested in 5 Lakh personal loan",
  "next_followup": "2026-11-20 14:00:00",
  "recording_url": "https://example.com/recordings/call_1052.mp3",
  "client_call_id": "550e8400-e29b-41d4-a716-446655440000",
  "sim_slot": "SIM1",
  "loan_qualification": {
    "loan_type": "Personal Loan",
    "loan_amount": 500000,
    "net_salary": 45000,
    "salary_account": "HDFC",
    "bank_name": "HDFC Bank"
  }
}
```

### Success Response (`200 OK`)
```json
{
  "success": true,
  "data": {
    "message": "Call result submitted successfully",
    "log_id": 4912,
    "record_id": 1052,
    "source": "TEMP",
    "replayed": false,
    "is_lead_generated": true,
    "lead_id": 84,
    "timing_classification": "CONNECTED_CONVERSATION",
    "duration": 65,
    "reconciliation": "STATUS_UPDATED",
    "engagement": {
      "xp_earned": 50,
      "total_xp": 1350,
      "level": 2,
      "level_title": "Silver Caller 🥈"
    }
  }
}
```

### Idempotency & Replay Verification
1. **Identical Replay**: If the same `client_call_id` is re-sent with the identical canonical payload, returns `200 OK` with `"replayed": true` and the original `log_id`. Side-effects (XP, `CALL_COUNT`, lead creation) run **strictly once**.
2. **Conflicting Replay**: If the same `client_call_id` is sent with a different canonical payload (e.g. different `call_status` or different `call_duration`), rejected with **`409 Conflict`**: `"client_call_id already used with a different call payload"`.
3. **Flutter Backward Compatibility**: If `reservation_token` is omitted, the endpoint processes the submission normally without error.
4. **Stale Session Exemption**: Completed call submissions are accepted even if `session_epoch` was updated on another device, ensuring calls placed on the dialer are never lost.

---

## 7. Abandon / Cancel Call Lock: `POST /api/v1/telecaller/calls/{id}/abandon`

Invoked when a telecaller canceled a call before dialing, or when the external dialer failed to place the call. Releases the active dial lock cleanly.

### Request
* **Method**: `POST`
* **Path**: `/api/v1/telecaller/calls/1052/abandon`
* **Body**:
```json
{
  "reservation_token": "res_88192a74-b52e-461f-9988-cf902b11d882",
  "call_lock_token": "lock_11a87e22-34f1-46ab-8891-ab2209d8112e",
  "source": "TEMP",
  "device_id": "android_98a76d54bc32",
  "reason": "USER_CANCELED"
}
```

### Success Response (`200 OK`)
```json
{
  "success": true,
  "data": {
    "reservation_token": "res_88192a74-b52e-461f-9988-cf902b11d882",
    "state": "RELEASED",
    "message": "Call lock abandoned successfully"
  }
}
```

---

## 8. Offline Batch Synchronization: `POST /api/v1/telecaller/calls/sync`

Submits multiple call results accumulated during offline periods.

### Request
* **Method**: `POST`
* **Path**: `/api/v1/telecaller/calls/sync`
* **Body**:
```json
{
  "device_id": "android_98a76d54bc32",
  "calls": [
    {
      "client_call_id": "550e8400-e29b-41d4-a716-446655440001",
      "reservation_token": "res_88192a74-b52e-461f-9988-cf902b11d882",
      "record_id": 1052,
      "source": "TEMP",
      "call_status": "Connected",
      "call_duration": 45,
      "notes": "Discussed terms",
      "call_time": "2026-11-18 18:30:00"
    },
    {
      "client_call_id": "550e8400-e29b-41d4-a716-446655440002",
      "reservation_token": "res_22391b11-a44d-4819-bf91-ea118a9921c3",
      "record_id": 504,
      "source": "MAIN",
      "call_status": "Ringing",
      "call_duration": 0,
      "notes": "No answer",
      "call_time": "2026-11-18 18:35:00"
    }
  ]
}
```

### Success Response (`200 OK`)
```json
{
  "success": true,
  "data": {
    "total_submitted": 2,
    "processed": 2,
    "failed": 0,
    "results": [
      {
        "client_call_id": "550e8400-e29b-41d4-a716-446655440001",
        "status": "SAVED",
        "log_id": 4913,
        "replayed": false,
        "reconciliation": "STATUS_UPDATED"
      },
      {
        "client_call_id": "550e8400-e29b-41d4-a716-446655440002",
        "status": "SAVED",
        "log_id": 4914,
        "replayed": false,
        "reconciliation": "STATUS_SUPERSEDED"
      }
    ]
  }
}
```

---

## 9. Release Queue Route: `POST /api/v1/telecaller/calling/release`

Explicitly releases all uncalled lead reservations for the telecaller.

### Request
* **Method**: `POST`
* **Path**: `/api/v1/telecaller/calling/release`
* **Body**:
```json
{
  "batch_uuid": "b47e2211-9831-4e78-90f1-4dfeb797d021",
  "device_id": "android_98a76d54bc32"
}
```

### Success Response (`200 OK`)
```json
{
  "success": true,
  "data": {
    "batch_uuid": "b47e2211-9831-4e78-90f1-4dfeb797d021",
    "released_count": 8,
    "message": "Uncalled reservations released successfully"
  }
}
```
