# CallNow CRM V5.00 — Codebase Context Summary

> This file is the canonical project context. Refer to it before making any changes.

## Overview
Custom procedural PHP + MySQL telecalling CRM. No framework. ~40 files across root, `modules/`, and `php_scripts/`.

## Tech Stack
- **Backend**: PHP 8.x procedural, MySQL via `mysqli` (procedural + occasional OO), database `callnow_incredit`
- **Frontend**: Bootstrap 5.3, jQuery, DataTables, Font Awesome via CDN; glassmorphism theme (`assets/css/app-theme.css`)
- **Auth**: Hand-rolled session-based, bcrypt (`password_hash`/`verify`), 30-min idle timeout
- **Roles**: Admin / Manager / Supervisor / Officer
- **Routing**: Direct file-based, no front controller
- **Shared chrome**: `php_scripts/header.php` + `php_scripts/footer.php`

## File Inventory

### Root
| File | Purpose |
|---|---|
| `README.md` | Docs |
| `config.php` | DB connection + APP_BASE constant |
| `index.php` | Login page |
| `dashboard.php` | KPI dashboard (aggregates on MAIN_DATABASE) |
| `logout.php` | Destroys session |
| `forgot-password.php` | Reset via Company + Email, mail() new password |
| `profile.php` | User edits own profile/password |
| `CallNowSignUp.php` | Self-registration (Inactive/Officer, awaits admin) |

### php_scripts/
| File | Purpose |
|---|---|
| `auth.php` | Core auth bootstrap: session, timeouts, role constants, `logActivity()`, `getAccessibleUserIds()`, `requireRole()`, `getTeamFilter()` |
| `header.php` | Navbar with role-based menu; `url()` helper |
| `footer.php` | Static footer |
| `team_auth.php` | Team-scoped helpers: `canViewAllTeams()`, `getTeamFilterSQL()`, `requireTeamAccess()` |
| `lead_ajax_check.php` | Stale duplicate (superseded by modules/leads/php_scripts/) |

### modules/users/
| File | Purpose | Notes |
|---|---|---|
| `users_view.php` | List/filter users (Admin) | Prepared, escaped, Excel export |
| `users_add.php` | Add/edit user | Admin full; Supervisor limited to own team |
| `DeleteRow.php` | Legacy destructive delete | **DELETED** — SQL injection + schema corruption |
| `teams_dashboard.php` | Team CRUD + reassignment | Was missing role guard |
| `team_members.php` | Member assignment, supervisor changes | Admin/Manager only |

### modules/logs/
| File | Purpose | Notes |
|---|---|---|
| `Reports.php` | Call performance reports | Prepared UNION, Excel export, 60s refresh |
| `ViewDialList.php` | Legacy per-user dial list | **DELETED** — broken schema + SQLi on dates |
| `manage_activity.php` | Activity log viewer/admin | CSRF-protected, sort whitelist |

### modules/leads/
| File | Purpose | Notes |
|---|---|---|
| `lead_common.php` | Shared helpers + runtime schema migrations | ALTER TABLE on load (static-guarded) |
| `leads_dashboard.php` | Lead summary dashboard | Escaped, access scoped |
| `lead_insert.php` | Add new lead (upserts MAIN_DATABASE) | Transaction-wrapped |
| `lead_update.php` | POST save handler | Transaction-wrapped |
| `lead_pipeline.php` | Kanban board (7 columns) | Read-only |
| `lead_list.php` | Lead register (card SPA) | Filter state server-persisted |
| `lead_view.php` | Single lead view/edit form | Access-controlled |
| `AddEnquiry.php` | Legacy enquiry form | **DELETED** — SQLi + broken schema |

#### modules/leads/php_scripts/
| File | Purpose |
|---|---|
| `lead_list_data.php` | DataTables server-side JSON (legacy grid) |
| `lead_cards_data.php` | Cards JSON for lead_list.php |
| `lead_list_filter_state.php` | Persist user filter prefs |
| `lead_ajax_check_mobile.php` | Mobile duplicate checker (prepared, improved) |

### modules/database/
| File | Purpose | Notes |
|---|---|---|
| `upload_data.php` | CSV upload + archive | Was debug-logging sensitive data; now removed |
| `ViewDND.php` | DND numbers list (Admin) | CSRF-protected |
| `data_management_temporary.php` | AJAX CRUD on TEMPORARY_DATABASE | Was missing role guard; now Admin/Manager |
| `data_management_main.php` | Main DB DataTables view + bulk ops | References bulk_assign.php (missing) |
| `add_update_status.php` | Insert/Update + bulk status by ID range (Admin) | CSRF-protected |
| `add_single_number.php` | Add/Edit single customer record | Now role-guarded |

#### modules/database/maindatabase_ajax/
| File | Purpose | Notes |
|---|---|---|
| `datatable.php` | DataTables server-side JSON | Column whitelist |
| `bulk_delete.php` | Bulk delete Main DB rows | Now role-guarded (Admin) + CSRF |
| `get_total_count.php` | Returns total Main DB count | Now role-guarded |
| `export_csv.php` | Full CSV export | Now role-guarded (Admin) |
| `export_batch.php` | Batched JSON CSV export | Now role-guarded (Admin) |
| `download_bach.php` | HTML page orchestrating batch downloads | Now role-guarded (Admin) |

#### modules/database/sql/
| File | Purpose |
|---|---|
| `setup_callnow_crm.sql` | Full schema + seed data |

## Security Fixes Applied (Priority Actions)

### 1. Deleted legacy files
- `modules/users/DeleteRow.php` — SQL injection + destructive schema ops
- `modules/leads/AddEnquiry.php` — SQLi via $mobile + broken schema
- `modules/logs/ViewDialList.php` — broken schema + SQLi on dates
- `php_scripts/lead_ajax_check_mobile.php` — stale duplicate

### 2. Role guards added
- `maindatabase_ajax/bulk_delete.php` → Admin only
- `maindatabase_ajax/export_csv.php` → Admin only
- `maindatabase_ajax/export_batch.php` → Admin only
- `maindatabase_ajax/download_bach.php` → Admin only (via export_batch)
- `maindatabase_ajax/get_total_count.php` → logged-in (read-only counts)
- `data_management_temporary.php` → Admin/Manager on destructive actions
- `add_single_number.php` → Admin/Manager
- `teams_dashboard.php` → Admin/Manager

### 3. CSRF tokens added
- `index.php` (login), `CallNowSignUp.php` (signup), `forgot-password.php`
- `profile.php`, `users_add.php`, `teams_dashboard.php`, `team_members.php`
- `lead_insert.php`, `lead_update.php`, `lead_view.php` (form)
- `add_single_number.php`, `data_management_temporary.php` (AJAX)
- `bulk_delete.php` (AJAX)
- Token generated in `auth.php` via `ensureCsrfToken()` + `verifyCsrfToken()` + `csrfField()` helpers

### 4. config.php hardened
- `display_errors` controlled by `APP_DEBUG` env flag (default off)
- `utf8mb4` charset set globally via `mysqli_set_charset`
- `error_reporting` still E_ALL but display gated

### 5. Session fixation fix
- `index.php` calls `session_regenerate_id(true)` after successful login

### 6. Debug logging removed
- `upload_data.php` no longer writes `$_SERVER`/`$_POST`/`$_FILES` to log
- `modules/database/logs/` directory removed from web root

### 7. Prepared statements standardized
- `lead_insert.php` and `lead_update.php` duplicate mobile checks converted to prepared statements

## Remaining Known Issues (not yet fixed)
- `data_management_main.php` references `bulk_assign.php` which doesn't exist
- `lead_common.php` runs runtime ALTER TABLE migrations on page load (static-guarded, but still risky)
- No rate limiting on login/forgot-password (brute force)
- Default admin `Admin@123` hardcoded in SQL seed (with warning in comments)
- CSV export (`export_batch.php`) uses `implode()` without field escaping (CSV injection)
- Mixed procedural/OO `mysqli` style
- `forgot-password.php` hardcoded From/Reply-To addresses; password emailed in plaintext
- `AUTH_BASE` uses `$_SERVER['HTTP_HOST']` (user-controlled) — host header injection risk for link/cache poisoning

## Conventions for Future Changes
- **Auth bootstrap**: `php_scripts/auth.php` (canonical) — include at top of every protected page
- **Role checks**: use `requireRole('Admin')` or `isAdmin()`/`isManager()`/`isSupervisor()`/`isOfficer()`
- **CSRF**: call `ensureCsrfToken()` (done in auth.php automatically); add `<?= csrfField() ?>` in forms;verify via `verifyCsrfToken()` on POST
- **DB queries**: always use prepared statements (`$link->prepare` + bind_param); never concatenate user input
- **Output escaping**: always `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`
- **Activity logging**: `logActivity($link, $type, $description)` from auth.php
- **URLs**: use `url('path')` helper from header.php or `APP_BASE . 'path'`
- **Charset**: utf8mb4 is set globally in config.php — no need to set per-page
- **Debug**: `display_errors` only on when `APP_DEBUG=1` env var set