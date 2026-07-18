# CallNow CRM V5.00 — Codebase Context Summary

> This file is the canonical project context. Refer to it before making any changes.

## Overview
Custom procedural PHP + MySQL telecalling CRM. No framework. ~40 files across root, `modules/`, and `php_scripts/`.

## Tech Stack
- **Backend**: PHP 8.x procedural, MySQL via `mysqli` (procedural + occasional OO), database `callnow_incredit`
- **Frontend**: Bootstrap 5.3, jQuery, DataTables, Bootstrap Icons via CDN; monochrome theme (`assets/css/app-theme.css` with light+dark tokens)
- **Auth**: Hand-rolled session-based, bcrypt (`password_hash`/`verify`), 30-min idle timeout
- **RBAC**: Permission-based. 11 system permission keys + DB-backed custom keys (`permissions` table). Roles (incl. custom) map to perms via `role_permissions`; users may have per-user overrides in `user_permissions`. `can($key)` is the sole access gate (Admin + System Admin ID=1 always pass).
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
| `auth.php` | Core auth bootstrap: session, timeouts, role constants, `can($key)`, `requirePermission()`, `requireAnyPermission()`, `clearRbacCache()`, `logActivity()`, `getAccessibleUserIds()`, `requireRole()` (convenience only), `getTeamFilter()` |
| `header.php` | Full HTML `<head>` (Bootstrap CSS, Bootstrap Icons, app-theme.css), sidebar, topbar, opens `<main>`; `url()` helper. Pages set `$pageTitle` before including. |
| `footer.php` | Closes layout, loads Bootstrap JS bundle, theme.js, sidebar.js, APP_BASE, APP_CSRF |
| `team_auth.php` | Team-scoped helpers: `canViewAllTeams()`, `getTeamFilterSQL()`, `requireTeamAccess()` |
| `lead_ajax_check.php` | Stale duplicate (superseded by modules/leads/php_scripts/) |

### modules/settings/
| File | Purpose | Notes |
|---|---|---|
| `settings.php` | Settings page (gated by `manage_settings`): General + Users tabs only | Roles/Permissions moved to `permissions_manager.php` |
| `permissions_manager.php` | **Access Control** hub (gated by `manage_settings`): 4 tabs — Features (permission keys CRUD), Roles (CRUD), Role Permissions (per-role matrix), User Access (role assignment + per-user override matrix) | Uses `permissions`, `roles`, `role_permissions`, `user_permissions` tables |
| `setup_permissions.php` | Run once (CLI or Admin) to create `permissions`/`user_permissions` tables, seed 11 system keys + system-role defaults | Idempotent; guarded by `rbac_initialized` flag |

### modules/users/
| File | Purpose | Notes |
|---|---|---|
| `users_view.php` | List/filter users (Admin) | Prepared, escaped, Excel export. System Admin (ID=1) and other Admin accounts are non-editable (Protected badge shown). |
| `users_add.php` | Add/edit user | Admin full; Supervisor limited to own team. Server-side guard prevents editing ID=1 or other Admin accounts. |
| `DeleteRow.php` | Legacy destructive delete | **DELETED** — SQL injection + schema corruption |
| `teams_dashboard.php` | Team CRUD + reassignment | Was missing role guard |
| `team_members.php` | Member assignment, supervisor changes | Admin/Manager only |

### modules/logs/
| File | Purpose | Notes |
|---|---|---|
| `Reports.php` | Call performance reports (gated `view_reports`) | Single date-range page: preset ranges (today default, yesterday, last7/30/90, this/last month, last3/6, current_year, custom), Trend/Status/Per-User Chart.js charts above the per-telecaller table; prepared UNION of `temporary_database`+`main_database`, Table2Excel export |
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

### 2. Permission-based access gates (can() is now the SOLE gate)
All feature/AJAX pages now call `requirePermission()`/`requireAnyPermission()` instead of `requireRole()`:
- `settings.php` → `manage_settings`; `permissions_manager.php` → `manage_settings`
- `users_view.php`, `users_add.php` → `manage_users`
- `teams_dashboard.php`, `team_members.php` → `manage_teams`
- `lead_insert.php`, `lead_update.php` → `manage_leads`; `lead_delete.php` → `delete_leads`
- `data_management_temporary.php`, `data_management_main.php`, `add_single_number.php`, `add_update_status.php`, `ViewDND.php` → `manage_database`
- `upload_data.php` → `upload_data`
- `maindatabase_ajax/bulk_delete.php`, `temporarydatabase_ajax/bulk_delete.php` → `requireAnyPermission(['manage_database','delete_leads'])`
- `maindatabase_ajax/bulk_assign.php`, `temporarydatabase_ajax/bulk_assign.php` → `requireAnyPermission(['assign_leads','manage_database'])`
- `maindatabase_ajax/export_csv.php`, `export_batch.php`, `download_bach.php`, `temporarydatabase_ajax/export_batch.php`, `download_bach.php` → `export_data`
- `maindatabase_ajax/get_total_count.php`, `temporarydatabase_ajax/get_total_count.php` → `manage_database`
- `Reports.php` → `view_reports`; `manage_activity.php` → `view_activity`
- In-page `isAdmin()`/`isManager()` checks that narrow DATA SCOPE (team/own filtering) are intentionally left role-based.

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

## Database Schema (Post-Migration 2026-07-11)
**`TEMPORARY_DATABASE` migrated from old schema to current schema** with no data loss. All 16,168 records preserved.
- Renamed `ADDED_AT` → `TEMP_UPLOAD_DATETIME`, `CALLED_AT` → `TEMP_LAST_CALLED_AT`
- Dropped `SOURCE`, `NOTES`, `ADDED_BY`
- Added indexes: `idx_temp_mobile`, `idx_temp_status`, `idx_temp_upload_dt`

**`MAIN_DATABASE` migrated from old schema (CUST_*, ADDED_*, CALLED_*) to new schema (MAINDATABASE_*)** with no data loss. All 105,593 customer records, 8 users, 22 leads, 16,168 temp records, 212 activity logs preserved.

Column mapping applied:
- `CUST_NAME` → `MAINDATABASE_NAME`
- `CUST_MOBILE` → `MAINDATABASE_MOBILE`
- `CUST_COMPANY` → `MAINDATABASE_COMPANY`
- `CUST_OTHER_INFO` → `MAINDATABASE_OTHER_INFO`
- `CALL_DIALED_STATUS` → `MAINDATABASE_CALL_DIALED_STATUS`
- `ADDED_AT` → `MAINDATABASE_UPLOAD_DATETIME`
- `CALLED_AT` → `MAINDATABASE_CALL_DIAL_TIME`
- `CALL_DIAL_COUNT_NUMBER` → `CALL_COUNT`
- `SOURCE` column dropped
- New columns added: `MAINDATABASE_CALL_DIALED_USER`, `LAST_DIALED_DATE_TIME`, `CALL_TIME`, `archived_at`, `archived_by`, `archive_reason`
- Indexes added: `idx_upload_dt`, `idx_user` (on MAINDATABASE_CALL_DIALED_USER)

**IMPORTANT**: Do NOT run `setup_callnow_crm.sql` — it uses `DROP TABLE IF EXISTS` and will wipe all live data. The live DB is now the source of truth.

**RBAC tables (added 2026-07-16)**:
- `permissions` (id, permission_key UNIQUE, label, category, description, is_system, created_at) — DB-backed permission keys; 11 system keys seeded, admins can add custom.
- `roles` (id, role_name UNIQUE, description, is_system) — Admin/Manager/Supervisor/Officer (system) + custom roles.
- `role_permissions` (role VARCHAR, permission_key, permission_value, UNIQUE(role,permission_key)) — per-role matrix; system-role defaults: Admin/Manager all-on, Supervisor 6, Officer 2.
- `user_permissions` (user_id, permission_key, permission_value, UNIQUE(user_id,permission_key)) — per-user overrides (win over role).
- `app_settings` holds `rbac_cache_version` (bumped by `clearRbacCache()` on any change) and `rbac_initialized` flag.
- `users.role_id` FK → `roles.id`. `can($key)` resolution: Admin or USER_ID===1 → true; else `user_permissions` override if present; else `role_permissions`; default false.
- Bootstrap/seed via `setup_permissions.php` (idempotent, guarded by `rbac_initialized`).

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
- **Role checks**: `can($key)` / `requirePermission($key)` / `requireAnyPermission([...])` is the SOLE access gate. `isAdmin()`/`isManager()`/`isSupervisor()`/`isOfficer()` remain as convenience helpers for data-scoping only, not page gates.
- **CSRF**: call `ensureCsrfToken()` (done in auth.php automatically); add `<?= csrfField() ?>` in forms;verify via `verifyCsrfToken()` on POST
- **DB queries**: always use prepared statements (`$link->prepare` + bind_param); never concatenate user input
- **Output escaping**: always `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`
- **Activity logging**: `logActivity($link, $type, $description)` from auth.php
- **URLs**: use `url('path')` helper from header.php or `APP_BASE . 'path'`
- **Charset**: utf8mb4 is set globally in config.php — no need to set per-page
- **Debug**: `display_errors` only on when `APP_DEBUG=1` env var set
- **CSS architecture**: `header.php` includes Bootstrap CSS, Bootstrap Icons, and `app-theme.css` centrally. Module pages should NOT include their own `<link>` tags for these. Only add minimal page-specific `<style>` blocks when needed. Standalone pages (index.php, forgot-password.php, CallNowSignUp.php) keep their own `<head>` but should use theme classes.
- **Page pattern**: Protected pages set `$pageTitle` then `include header.php` at top, `include footer.php` at bottom. No `<!DOCTYPE>`, `<html>`, `<head>`, or `<body>` tags in module pages.
- **Sidebar**: Fixed left column with collapsible `data-sidebar` states (`expanded`, `collapsed`, `mobile-open`). Mobile uses overlay + backdrop via `sidebar.js`. Body scroll locked when mobile sidebar open.