# Project audit — 5 November 2026

## Verified results

All 22 test entry points in the full audit completed successfully, without reported failures, PHP warnings, or skipped cases. An additional fresh-install schema suite subsequently passed, bringing the verified entry points to 23. Some legacy entry points intentionally share the replacement user-workflow suite; their repeated assertions are not independent coverage.

| Area | Verified checks |
|---|---:|
| Main web-page audit | 25 routes: render or expected quota denial |
| Android HTTP integration | 52 |
| Simultaneous Android workers | 5 |
| Existing API integration | 46 |
| Leads and pipeline scenarios | 56 |
| Web user/team/permission workflows | 31 |
| Existing team-management checks | 64 |
| Existing users-section checks | 39 |
| Web login/profile/logout | 10 |
| Password-reset security | 9 |
| Database, exports, DND, settings, reports, CSV workflows | 27 |
| Existing full ERP checks | 28 |
| Existing telecaller CRM-goal checks | 20 |
| Database health | 22 core tables present |
| Fresh-install SQL and runtime schema | 3 |

PHP lint covers every PHP file in the repository. The Apache check confirms diagnostic test URLs return 403. API/lead checks were repeated after the final shared-handler and pipeline-preservation changes.

The full-run evidence is the JSON report `callnow_audit_20261105_135103_26a71d-results.json` in the Windows temporary directory. The later focused API evidence is `callnow_audit_20261105_135403_b29796-results.json`; fresh-install evidence is `callnow_audit_20261105_135630_24eb9d-results.json`. Corresponding disposable databases were retained for investigation; mutation suites used those databases, not the configured working CRM database.

## Scenarios exercised

- Active/inactive officers, missing and expired bearer tokens, logout/revocation, forbidden admin access, per-user permission denial overriding a role grant, API disablement, and malformed JSON/field types.
- Phone-number normalization, new leads, duplicate/mobile takeover prevention, customer read/update authorization, full call telemetry, Unicode notes, timezone-qualified dates, recording links, and missing source records.
- Two independent PHP workers acquiring batches simultaneously; the workers received disjoint records. Two concurrent submissions with the same client_call_id produced one log and one source counter increment. A conflicting retry returns 409.
- Lead creation, duplicate prevention, early rejection, follow-up scheduling and repeated completion, underwriting/login progression, bank cloning, transfer between users, old-owner access removal, permission revocation, notes escaping, filters, paging, deletion, and surviving bank applications.
- A later Interested call preserves an already sanctioned pipeline stage instead of demoting the lead.
- Main/temporary contact insertion, textual note edits, bounded DataTables searches including zero/negative lengths, batched CSV export, bulk assignment/deletion, and DND propagation/restoration.
- Actual multipart CSV upload and batch processing, valid/invalid row counts, header skipping, and CSRF enforcement.
- Cookie-based browser-form login, failed login, session-ID rotation, protected dashboard access, profile update, and logout.
- Password-reset mismatch, short password, invalid/expired/used token, successful form submission, bearer revocation, and absence of password disclosure.

## Issues fixed

1. Both call submission routes now use one transactional implementation. Source updates, log insertion, activity logging, and lead synchronization commit together. Optional client_call_id protects offline retries.
2. Call payload validation rejects malformed objects, invalid durations/dates, excessive text, and unsafe recording URL schemes. Errors no longer expose filesystem paths or SQL details.
3. Cross-user customer access and duplicate-mobile takeover are blocked. API permission overrides, preflight requests, disabled-gateway behavior, and request user-agent logging are corrected.
4. Follow-up times, pipeline progression, bank cloning, lead action bootstrap paths, and deletion cleanup are corrected. Advanced pipeline stages survive later call results.
5. User role/team/status validation and edit scope are enforced server-side. Permission-editor string keys no longer bind as integers.
6. CSV headers/counters, batch CSRF/ownership checks, customer text-note binding, export bounds, and working-directory-dependent includes are corrected.
7. Password recovery no longer replaces/discloses passwords based only on organization/login knowledge. It uses hashed, expiring, single-use email tokens; password reset revokes mobile tokens.
8. Test/context files are blocked by Apache. Fresh-install call-log SQL matches current runtime requirements.

## Coverage limits and release checks

Passing these tests does not prove every possible execution path or device behavior. HTML rendering checks are not visual or JavaScript interaction tests. No Android source, APK, emulator, or physical phone was present, so OS call-state/permission behavior, actual audio recording/playback, dual-SIM behavior, background/process-death recovery, and real phone-network connectivity remain device acceptance checks. SMTP delivery, public-domain TLS, hosting configuration, and sustained production load also require their deployment environments.

These items are explicitly recorded as unverified; they are not counted as passing or silently skipped tests. See `ANDROID_INTEGRATION.md` for the connection contract and device checklist.

## Repeat the audit

```powershell
& 'C:\xampp\php\php.exe' tests/run_project_audit.php
```

The runner creates a uniquely named database clone and starts test servers on loopback ports 8099 and 8100. It writes JSON evidence and server logs to the Windows temporary directory. Destructive legacy suites reject direct execution against the working database. `tests/run_users_tests.php` runs the isolated users/team subset.
