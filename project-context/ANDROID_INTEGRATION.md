# Android integration contract

## Connection

Use a base URL ending in `/api/v1/`, with endpoint paths such as `auth/login` and `telecaller/batch-leads`.

| Environment | Base URL |
|---|---|
| Android Studio emulator with XAMPP on this PC | `http://10.0.2.2/callnowCRM/api/v1/` |
| Phone on the same network | `http://<PC-LAN-IP>/callnowCRM/api/v1/` |
| Hosted deployment | `https://<your-domain>/<installation-path>/api/v1/` |

The emulator's `10.0.2.2` address maps to the development PC's loopback interface ([Android documentation](https://developer.android.com/studio/run/emulator-networking-address)). A physical phone needs the PC's reachable LAN address and an allowed Apache port. The server's `localhost` is not the phone's `localhost`.

Android apps targeting API 28 and later block cleartext HTTP by default ([Android network security documentation](https://developer.android.com/privacy-and-security/security-config)). Use HTTPS for deployment; any local HTTP exception belongs in the app's debug configuration. The app needs the INTERNET permission. Set the server's `APP_URL` to its actual reachable URL so returned photo URLs and emailed reset links use the correct host.

## Authentication and payloads

`POST auth/login` accepts `{"username":"agent@example.com","password":"..."}` or the `email` key instead of `username`. Store `data.token` and send `Authorization: Bearer <token>` on protected requests. API requests use JSON objects and `Content-Type: application/json`; web form CSRF tokens are not used for bearer-token API calls.

Success is `{"success":true,"data":{...}}`. Errors are `{"success":false,"error":{"code":400,"message":"..."}}`. Read the HTTP status as well as the JSON. `GET auth/me` returns the profile, `POST auth/refresh` extends a still-valid token, and `POST auth/logout` revokes it. Registered email/mobile cannot be changed through the mobile profile endpoint.

| Operation | Endpoint | Important response/identity |
|---|---|---|
| Acquire calling queue | `GET telecaller/batch-leads?limit=15` | `data.leads[]`, source MAIN or TEMP, 15-minute lease |
| Acquire one contact | `GET telecaller/next-call` | Contact/source or a no-calls message |
| Save call | `POST telecaller/submit_call` | `data.log_id`, record_id, source |
| Alternate save route | `POST telecaller/calls/{record_id}/complete` | Same handler and full-detail support |
| Own calls | `GET telecaller/calls?period=all` | Rows in `data.data`, paging in `data.pagination` |
| Record history | `GET telecaller/call-history/{record_id}?source=MAIN` | Scoped rows in `data.data` |
| Create lead | `POST telecaller/leads` | `data.lead.record_id` is the customer record; lead_id is the CRM lead |
| List leads | `GET telecaller/leads` | `data.leads[]` contains lead_id and record_id separately |
| CRM lead detail | `GET telecaller/leads/{lead_id}?source=LEAD` | Resolves the CRM lead to its customer record |
| Customer detail/update | `GET` / `PUT telecaller/leads/{record_id}?source=MAIN` | Use the customer record_id, not the CRM lead_id |

For calls, always use the contact's `record_id` and the underlying MAIN/TEMP source. Do not submit a CRM lead_id as a customer record ID. A lead-list row labeled LEAD refers to the CRM entity; its customer record_id belongs to MAIN.

## Retry-safe call submission

Generate one UUID per physical call and save it locally before the first network request. Send it as `client_call_id`; retain the same ID and payload through timeouts, reconnects, and app restarts.

```json
{
  "record_id": 123,
  "source": "MAIN",
  "client_call_id": "550e8400-e29b-41d4-a716-446655440000",
  "call_status": "Interested",
  "call_duration": 65,
  "total_duration": 70,
  "notes": "Customer requested pricing",
  "sim_slot": "SIM2",
  "call_time": "2026-11-05T12:00:05+05:30",
  "dial_time": "2026-11-05T12:00:00+05:30",
  "call_end_time": "2026-11-05T12:01:10+05:30",
  "recording_url": "https://example.com/recordings/call.mp3",
  "metadata": {"android_version":"15","network":"4g"}
}
```

An identical retry returns the original log_id with `replayed:true`. Reusing the ID with a changed payload returns 409. Without client_call_id, each request represents a new call and increments counts again. Durations are nonnegative integer seconds. Send timezone-qualified ISO timestamps; stored/output timestamps use Asia/Kolkata local time. The recording URL must use HTTP/HTTPS; this endpoint stores a URL and does not upload an audio file.

On 401, request a fresh login; on 403, stop the unauthorized operation; on 409, resolve the conflicting local call; on 429, back off; on 503, show that the mobile API is disabled. Never generate a fresh client_call_id merely because the first request timed out.

## Device checks still required

Backend tests simulate Android HTTP requests and simultaneous workers. No Android source, APK, connected phone, or emulator was present during this audit. Before release, test actual Retrofit/HTTP serialization, reachable base URL and TLS, runtime permissions, dialer/call-state integration, dual-SIM selection, recording availability on supported Android versions, background/offline queue persistence, process death, app upgrades, audio playback, and server connectivity over Wi-Fi and mobile data. Email reset-link delivery uses PHP's mail() transport and needs host mail configuration plus a delivery test; the SMTP_* entries in .env.example alone do not configure PHP mail().
