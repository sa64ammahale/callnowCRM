# CallNow CRM — Security & Code Quality Audit

Scope: All `.php` files (88 files) audited for syntax, SQL injection, XSS, CSRF, auth bypass, DB connection, logic errors, exposed secrets, and AGENTS.md convention adherence.

Reference baseline: PHP 8.x procedural, MySQLi, Bootstrap 5.3, no framework.

---

## CRITICAL

| # | File | Line(s) | Category | Description |
|---|------|---------|----------|-------------|
| 1 | `diag_500.php` | 1–218 | Auth bypass / Info disclosure | Completely unauthenticated. Bypasses `auth.php`, loads `config.php` directly, exposes DB table names, row counts, column schemas, session state (`loggedin`, `id`, `csrf_token`), and exact login query behavior to any network attacker. |
| 2 | `opcache_clear.php` | 1–14 | Auth bypass / DoS | No authentication. Calls `opcache_reset()` and `opcache_invalidate()` on `config.php`. Any remote attacker can flush the entire PHP opcache, causing immediate performance degradation and forcing recompilation of code paths. |
| 3 | `diag_full.php` | 1–9 | Info disclosure | Includes `index.php` (which does auth) but wraps it in `try/catch` and outputs full exception class names, messages, file paths, and line numbers to the client. |
| 4 | `api/v1/auth/index.php` | 10 | CORS / Auth bypass | `Access-Control-Allow-Origin: *` on authenticated API endpoints. Allows any origin to make authenticated requests, enabling cross-site request forgery against the API from any attacker-controlled page. |
| 5 | `api/v1/admin/index.php` | 10 | CORS / Auth bypass | Same wildcard CORS on authenticated admin API. |
| 6 | `php_scripts/api_auth.php` | 10–11 | Exposed secrets | Logs full `Authorization` header (including Bearer tokens) to `error_log()` on every API request. Server logs are often readable by other users/processes and may be forwarded to SIEMs. |

---

## HIGH

| # | File | Line(s) | Category | Description |
|---|------|---------|----------|-------------|
| 7 | `modules/database/maindatabase_ajax/get_total_count.php` | 3–4 | Debug exposure | `error_reporting(E_ALL); ini_set('display_errors', 1);` at file top, **before** `require_once auth.php`. If auth is bypassed or fails, detailed PHP errors with stack traces and DB credentials are sent to the client. |
| 8 | `modules/leads/php_scripts/lead_cards_data.php` | 1–255 | CSRF | POST AJAX endpoint with no `verifyCsrfToken()` call. `api_init()` is invoked but does not enforce CSRF. An attacker can forge a POST request from any page the user visits. |
| 9 | `php_scripts/auth.php` | 159 | SQL injection risk | `getTeamFilter()` returns `"... AND CALL_BY = " . $_SESSION['id']` — raw `$_SESSION['id']` interpolated into SQL without `(int)` cast. If the session is tampered with (fixation/hijacking), this is directly exploitable. |
| 10 | `php_scripts/team_auth.php` | 35, 39 | SQL injection risk | `getTeamFilterSQL()` interpolates `USER_TEAM_ID` and `USER_ID` directly into SQL strings. These are constants derived from DB, but raw interpolation bypasses prepared statements. |
| 11 | `modules/database/ViewDND.php` | 12 | Auth bypass risk | Uses raw `$_SESSION['role']` for the delete permission check instead of the `USER_ROLE` constant. If session data is manipulated, the role gate can be bypassed. |
| 12 | `modules/leads/php_scripts/lead_cards_data.php` | 57–66, 72–102 | SQL injection risk ( mitigated ) | Dynamic query builder uses `mysqli_real_escape_string()` but still constructs SQL via string concatenation. `$currentMonth` / `$previousMonth` are interpolated without escaping (safe because they come from `date()`, but inconsistent). Pipeline status list at line 72 is imploded without escaping. |
| 13 | `modules/database/temporarydatabase_ajax/datatable.php` | 60–61 | SQL injection risk (ORDER BY) | `$orderby = $columns[$col] ?? 'TEMP_UPLOAD_DATETIME';` — column index from `$_GET['order'][0]['column']` maps to a hardcoded whitelist, which is correct, but the file also has `$dir` from `$_GET['order'][0]['dir']` concatenated as `" " . ($dir === 'asc' ? 'ASC' : 'DESC')`. The ternary limits direction, but if the ternary is ever bypassed, raw `$dir` hits SQL. Same pattern in `maindatabase_ajax/datatable.php:59–61`. |

---

## MEDIUM

| # | File | Line(s) | Category | Description |
|---|------|---------|----------|-------------|
| 14 | `forgot-password.php` | 85 | Secret exposure | When `mail()` fails, the newly generated plaintext password is echoed to the user via `$ErrorMessage = "Your new password is: " . $NewPassword`. Passwords should never be displayed. |
| 15 | `modules/database/data_management_temporary.php` | 205–209 | Privacy leak | The `fetch_activity` action returns the full `TBL_ACTIVITY_LOG` including `USER_ID`, `IP_ADDRESS`, `ACTION_DETAILS`, and `AFFECTED_IDS` to any user with `manage_database`. No paging or filtering by the requesting user’s own ID. |
| 16 | `index.php` | 87 | Info disclosure | Catch block: `$ErrorMessage = "Error: " . $e->getMessage();` — raw exception messages (including SQL errors, file paths, line numbers) are rendered to the login page. |
| 17 | `CallNowSignUp.php` | 39 | Data integrity | `$company = htmlspecialchars($company, ENT_QUOTES, 'UTF-8');` before DB insert. This permanently alters the stored company name (e.g., `&` → `&amp;`), causing lookup mismatches on login. |
| 18 | `modules/leads/php_scripts/lead_cards_data.php` | 22 | Missing input validation | `$search = trim((string)($_POST['search'] ?? ''));` with no maximum length. An attacker can POST a multi-MB search string, causing expensive `LIKE` queries and memory consumption. |
| 19 | `modules/database/data_management_temporary.php` | 56 | Logic / validation | `substr($mobile, -10)` is used before regex validation. If `$mobile` is shorter than 10 chars, `substr` returns the full string; regex still catches it, but the intent is fragile. |
| 20 | `modules/database/temporarydatabase_ajax/get_total_count.php` | 7 | Plain-text response | Echoes raw integer count without JSON wrapper (`echo $total;`). Auth is enforced, but response is plain text while sibling endpoints return JSON, indicating inconsistent API contract. |

---

## LOW

| # | File | Line(s) | Category | Description |
|---|------|---------|----------|-------------|
| 21 | Multiple files | Various | Convention inconsistency | CSRF is verified via `verifyCsrfToken($_POST['csrf_token'] ?? '')` in most places, but `ViewDND.php:18` and `add_update_status.php:39` use raw `hash_equals($_SESSION['csrf_token'], $_POST['csrf'] ?? '')`. The field name also differs (`csrf_token` vs `csrf`). |
| 22 | `modules/database/data_management_temporary.php` | 765 | XSS mitigation weak | `escapeHtml()` is implemented client-side via jQuery (`$('<div>').text(s).html()`). This is only applied in the `fetch_activity` modal and does not protect against XSS if the same data is rendered server-side elsewhere. |
| 23 | `modules/leads/php_scripts/lead_cards_data.php` | 123, 168 | Error handling | `mysqli_query()` results are used without null checks: `mysqli_fetch_row(mysqli_query(...))[0]` and `mysqli_fetch_assoc($result)`. If queries fail, PHP 8 throws a fatal error from undefined array key on null. |
| 24 | `modules/database/maindatabase_ajax/export_csv.php` | 1–37 | Auth flow | Missing `api_init()` call (present in sibling export endpoints). Functionally harmless because `requirePermission('export_data')` gating is sufficient, but inconsistent with `export_batch.php` and `download_bach.php`. |
| 25 | Project-wide | Various | Secret hygiene | No evidence of `.env` files or secret management. `SETUP_KEY` is read from `getenv('SETUP_KEY')` in `CallNowSignUp.php:8`, which is acceptable, but there is no fallback or missing-key warning. |

---

## POSITIVE FINDINGS

- All user-facing output (forms, tables, alerts) consistently uses `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- The core admin/lead CRUD operations (`lead_insert.php`, `lead_update.php`, `add_single_number.php`, `data_management_temporary.php` update/delete, etc.) consistently use prepared statements.
- Password hashing uses `password_hash(..., PASSWORD_DEFAULT)` and verification uses `password_verify()`.
- Session timeout is enforced in `auth.php:18` (30 min idle).
- RBAC system (`can()`, `requirePermission()`, `requireAnyPermission()`) is centralized and Admin + System Admin (ID=1) always pass.

---

## RECOMMENDATIONS (Priority Order)

1. Remove or move `diag_500.php`, `diag_full.php`, and `opcache_clear.php` outside the web root or gate them with `require_once 'php_scripts/auth.php'; isAdmin()`.
2. Remove `Access-Control-Allow-Origin: *` from authenticated API endpoints in `api/v1/auth/index.php` and `api/v1/admin/index.php`. Use a strict origin whitelist or remove CORS headers entirely for same-origin apps.
3. Redact bearer tokens from `api_auth.php` error_log calls (`error_log("[API] Auth attempted from " . $_SERVER['REMOTE_ADDR']);`).
4. Move `error_reporting(E_ALL); ini_set('display_errors', 1);` in `maindatabase_ajax/get_total_count.php` **after** the auth check, or remove it entirely in production.
5. Add `verifyCsrfToken()` to `lead_cards_data.php`.
6. Cast `$_SESSION['id']` in `auth.php:159` to `(int)` or use the `USER_ID` constant.
7. Replace raw interpolation in `team_auth.php:35,39` with prepared statements or ensure values are `(int)` cast before interpolation.
8. Replace raw `$_SESSION['role']` in `ViewDND.php:12` with `USER_ROLE`.
9. In `forgot-password.php`, never echo the generated password back to the client; force an email-only success pathway.
10. Add a max length (e.g., `min(255, ...)`) on `$search` in `lead_cards_data.php` and all search inputs.
11. In `CallNowSignUp.php`, store the raw `$company` and escape only at output time.
