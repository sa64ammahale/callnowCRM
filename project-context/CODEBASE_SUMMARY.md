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
| `index.php` | Login page (multi-tenant routing via company code) |
| `dashboard.php` | KPI dashboard (aggregates on MAIN_DATABASE) |
| `logout.php` | Destroys session |
| `forgot-password.php` | Reset via Company + Email, mail() new password |
| `profile.php` | User edits own profile/password |
| `CallNowSignUp.php` | Initial administrator setup via SETUP_KEY or authenticated Admin |

### php_scripts/
| File | Purpose |
|---|---|
| `auth.php` | Core auth bootstrap: session, timeouts, role constants, `can($key)`, `requirePermission()`, `requireAnyPermission()`, `clearRbacCache()`, `logActivity()`, `getAccessibleUserIds()`, `requireRole()` (convenience only), `getTeamFilter()` |
| `tenant.php` | Multi-tenant dynamic resolver: master DB connection, `resolveTenant()`, `getTenantConnection()`, cookie prefill setter/clearer, upload directory isolation (`getTenantUploadDir()`) |
| `tenant_provisioner.php` | Tenant provisioning engine: `provisionTenantDatabase()`, `listTenantCompanies()`, `setTenantStatus()` for multi-database management |
| `header.php` | Full HTML `<head>` (Bootstrap CSS, Bootstrap Icons, app-theme.css), sidebar, topbar, opens `<main>`; `url()` helper. Pages set `$pageTitle` before including. |
| `super_admin.php` | Super admin limit helpers: `ensureSuperAdminSchema()`, `getCurrentPlan()`, `getEffectivePlan()`, `checkUserLimit()`, `checkApiTokenLimit()`, `checkStorageLimit()`, `getLimitUsageSummary()`, `logLimitViolation()` |
| `footer.php` | Closes layout, loads Bootstrap JS bundle, theme.js, sidebar.js, APP_BASE, APP_CSRF |
| `team_auth.php` | Team-scoped helpers: `canViewAllTeams()`, `getTeamFilterSQL()`, `requireTeamAccess()` |
| `reporting_schema.php` | Self-healing schema migrations for Call Reporting: `call_logs.lead_id`, `call_logs.client_contact_id`, `lead_followups.client_contact_id`, `contact_lead_conversions`, `reporting_targets` |
| `reporting_engine.php` | Performance engine: Client Contact metrics, Calling analytics, Lead metrics, Hourly breakdown (9:30-18:30), Employee targets & achievements, Supervisor rollups, Follow-up hub, Alerts, Rankings |
| `lead_ajax_check.php` | Stale duplicate (superseded by modules/leads/php_scripts/) |

### modules/settings/
| File | Purpose | Notes |
|---|---|---|
| `settings.php` | Settings page (gated by `manage_settings`): General + Users tabs only | Roles/Permissions moved to `permissions_manager.php` |
| `permissions_manager.php` | **Access Control** hub (gated by `manage_settings`): 4 tabs — Features (permission keys CRUD), Roles (CRUD), Role Permissions (per-role matrix), User Access (role assignment + per-user override matrix) | Uses `permissions`, `roles`, `role_permissions`, `user_permissions` tables |
| `setup_permissions.php` | Run once (CLI or Admin) to create `permissions`/`user_permissions` tables, seed 11 system keys + system-role defaults | Idempotent; guarded by `rbac_initialized` flag |
| `super_admin_panel.php` | **Super Admin Panel** (gated by `manage_super_admin`): 5 tabs — Overview (usage dashboard), Plans (CRUD plans for payment integration), Limits (configure user/API/storage limits), Access Control (toggle features on/off), Limit Logs | Uses `super_admin_plans`, `super_admin_account`, `super_admin_limit_logs` tables. Enforces limits in `users_add.php`, `upload_data.php`, `api_settings.php`. |

### modules/users/
| File | Purpose | Notes |
|---|---|---|
| `users_view.php` | List/filter users (Admin) | Prepared, escaped, Excel export. System Admin (ID=1) and other Admin accounts are non-editable (Protected badge shown). Table enhanced with client-side DataTables (search, sort, paging, copy/csv/excel/print). |
| `users_add.php` | Add/edit user | Admin full; Supervisor limited to own team. Server-side guard prevents editing ID=1 or other Admin accounts. |
| `DeleteRow.php` | Legacy destructive delete | **DELETED** — SQL injection + schema corruption |
| `teams_dashboard.php` | Team CRUD + reassignment | Was missing role guard |
| `team_members.php` | Member assignment, supervisor changes | Admin/Manager only |

### modules/logs/
| File | Purpose | Notes |
|---|---|---|
| `Reports.php` | Call performance reports (gated `view_reports`) | Modern reports hub: interactive Report Navigation Directory with themed micro-icon pills & live count badges; 3-tier multi-dimensional KPI cards relocated below charts inside Overview & Trends; presets, drilldowns, Doughnut breakdown, CSV export |
| `call_history.php` | Real-time call logs audit trail (gated `view_reports`) | Log of all dialed calls from mobile app & web CRM; status badges, duration pills, audio playback, pagination. Table enhanced with server-side DataTables (copy/csv/excel/print); JSON endpoint at `call_history_data.php`. |
| `ViewDialList.php` | Legacy per-user dial list | **DELETED** — broken schema + SQLi on dates |
| `manage_activity.php` | Activity log viewer/admin | CSRF-protected, sort whitelist. Table enhanced with server-side DataTables (copy/csv/excel/print + reuse existing `?export=csv`); JSON endpoint at `manage_activity_data.php`. |
| `call_history_data.php` | DataTables server-side JSON for call_history.php | GET-only, read-only; reuses existing WHERE/COUNT logic + role scoping; supports `length=-1` full export. |
| `manage_activity_data.php` | DataTables server-side JSON for manage_activity.php | GET-only, read-only; reuses existing WHERE/COUNT logic + role scoping; supports `length=-1` full export. |

### modules/leads/
| File | Purpose | Notes |
|---|---|---|
| `lead_common.php` | Shared helpers + runtime schema migrations | ALTER TABLE on load (static-guarded) |
| `leads_dashboard.php` | Lead summary dashboard | Escaped, access scoped; modern horizontal welcome strip, semantic KPI metric cards |
| `lead_insert.php` | Add new lead (upserts MAIN_DATABASE) | Transaction-wrapped |
| `lead_update.php` | POST save handler | Transaction-wrapped |
| `lead_pipeline.php` | Modern premium Kanban pipeline board (8 stages) matching SaaS design | Drag & drop RBAC gated, 8 horizontal summary KPI cards, live search & multi-facet filters, custom empty states, `assets/css/pipeline-premium.css` |
| `lead_list.php` | Lead register (card SPA) | Filter state server-persisted |
| `lead_view.php` | Single lead view/edit form | Access-controlled |
| `AddEnquiry.php` | Legacy enquiry form | **DELETED** — SQLi + broken schema |

#### modules/leads/php_scripts/
| File | Purpose |
|---|---|
| `lead_pipeline_ajax.php` | Drag & Drop stage movement and per-column lazy loading/pagination |
| `lead_list_data.php` | DataTables server-side JSON (legacy grid) |
| `lead_cards_data.php` | Cards JSON for lead_list.php |
| `lead_list_filter_state.php` | Persist user filter prefs |
| `lead_ajax_check_mobile.php` | Mobile duplicate checker (prepared, improved) |

### modules/database/
| File | Purpose | Notes |
|---|---|---|
| `upload_data.php` | CSV upload + archive | Was debug-logging sensitive data; now removed. Modernized with gradient header, colored metric cards, styled drop zone, progress modal, CSRF-fixed batch processing, robust CSV preview parser (quoted fields), reset button, and empty states. |
| `ViewDND.php` | DND numbers list (gated `manage_database`) | Dual-database listing (queries both `temporary_database` & `main_database` for 'Do Not Call'/'DND'/'DNC'), origin badges (Main DB vs Temp DB), search, pagination, restore to queue, permanent delete, CSRF-protected. Table enhanced with server-side DataTables (copy/csv/excel/print); JSON endpoint at `ViewDND_data.php`. |
| `ViewDND_data.php` | DataTables server-side JSON for ViewDND.php | GET-only, read-only; DND union (TEMP+MAIN) SELECT/COUNT + search; returns action HTML for restore/delete; supports `length=-1` full export. |
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
| `migrate_super_admin_limits.sql` | Migration for super admin plans, account, and limit logs tables + seed data |

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

**Super Admin limits tables (added 2026-09-03)**:
- `super_admin_plans` — subscription plans with configurable limits (max users, API tokens, API calls, DB records, storage MB) and pricing fields for future payment integration.
- `super_admin_account` — single-row account config (current plan, company, billing email, payment status, trial/period dates).
- `super_admin_limit_logs` — audit trail of limit violations (users, API tokens, API calls, storage, access).

**RBAC tables (added 2026-07-16)**:
- `permissions` (id, permission_key UNIQUE, label, category, description, is_system, created_at) — DB-backed permission keys; 11 system keys seeded, admins can add custom.
- `roles` (id, role_name UNIQUE, description, is_system) — Admin/Manager/Supervisor/Officer (system) + custom roles.
- `role_permissions` (role VARCHAR, permission_key, permission_value, UNIQUE(role,permission_key)) — per-role matrix; system-role defaults: Admin/Manager all-on, Supervisor 6, Officer 2.
- `user_permissions` (user_id, permission_key, permission_value, UNIQUE(user_id,permission_key)) — per-user overrides (win over role).
- `app_settings` holds `rbac_cache_version` (bumped by `clearRbacCache()` on any change) and `rbac_initialized` flag.
- `users.role_id` FK → `roles.id`. `can($key)` resolution: Admin or USER_ID===1 → true; else `user_permissions` override if present; else `role_permissions`; default false.
- Bootstrap/seed via `setup_permissions.php` (idempotent, guarded by `rbac_initialized`).

## Completed Goal (2026-11-06): Comprehensive Leads Section Testing & Bug Resolution
- **Supervisor & Hierarchy Permissions in `lead_common.php`**:
  - Enhanced `canEditLead()` to allow Supervisors to edit and move stages for leads belonging to their team or assigned officers (`inLeadManagerChain()`).
  - Extended `inLeadManagerChain()` to utilize `getAccessibleUserIds()` for reliable RBAC hierarchy resolution.
  - Added `canPullToTray()` support for Supervisor role.
  - Provided `getLeadAssignableUsers()` backwards-compatible alias.
- **SQL & Concurrency Hardening**:
  - `lead_clone_bank.php`: Replaced concatenated SQL query with a parameterized prepared statement.
  - `lead_insert.php`: Resolved `team_id` from assigned user's `TEAM_ID` upon lead creation.
  - `lead_insert.php`: Enforced `canMoveLeadStage()` on new lead submissions so Telecallers / Officers start strictly at `LEAD` stage.
  - `lead_list.php`, `lead_pipeline.php`, `lead_cards_data.php`: Guarded `mysqli_fetch_row()` count queries with null-safe checks.
  - `reporting_engine.php`: Added `disbursed_loans` and `disbursed_amount` alias keys in `getLeadConversionMetrics()`.
- **Automated Verification**:
  - `tests/test_leads_pipeline.php`: 57/57 tests passing 100% (covering lead insert, officer stage gating, supervisor/admin workflow progression, bank cloning, notes, follow-up idempotency, deletion cascades, and cards filtering).
  - `tests/test_loan_telecalling_performance.php`: 17/17 tests passing 100% (covering contact calling, idempotent conversions, disbursement metrics, hourly analytics, and follow-up completion).
  - Executed tests under isolated audit environment (`run_project_audit.php`) with 0 errors.
  - Repository-wide PHP syntax check passed with 0 errors.

## Completed Feature (2026-11-06): Status-Colored Lead Cards for CRM Web & Flutter Mobile App
- **CRM Web Application (`modules/leads/lead_list.php`, `modules/leads/lead_pipeline.php`, `assets/css/app-theme.css`)**:
  - Implemented dynamic status card color themes:
    - **Danger card for Reject**: Red wash (`#FEF2F2` to `#FFFFFF`), `#FECDD3` border, `4px solid #DC2626` left accent stripe.
    - **Success card for Disbursal**: Emerald wash (`#F0FDF4` to `#FFFFFF`), `#BBF7D0` border, `4px solid #16A34A` left accent stripe.
    - **Warning card when Rework**: Amber wash (`#FFFBEB` to `#FFFFFF`), `#FDE68A` border, `4px solid #D97706` left accent stripe.
    - **Regular card for Lead**: Clean white surface, `#E2E8F0` border, `4px solid #6D45F9` left accent stripe.
    - Status-matched colors for other stages (`FOLLOWUP`, `INTERNAL_UNDERWRITING`, `LOGIN`, `BANK_UNDERWRITING`, `SANCTIONED`).
  - Applied across both the Leads Grid Cards view (`lead_list.php`) and Kanban Pipeline Board (`lead_pipeline.php`).
  - Added global utility classes in `assets/css/app-theme.css`.
- **Flutter Mobile Application (`lib/model/crm_lead.dart`, `lib/screens/leads_screen.dart`)**:
  - Updated `CrmLead` model to parse `rework_flag` and expose `isRework`, `isDisbursed`, `isRejected`.
  - Updated `_buildLeadCard(CrmLead lead)` in `leads_screen.dart` with status-colored card backgrounds, borders, colored left indicator stripe (4.5px), and prominent Action Required Rework warning banner.
  - Zero logic changes; purely presentation & UI enhancement. Verified with `flutter analyze` (0 issues).

## Completed Feature (2026-11-05): Client Contacts, Call Reporting & Loan Telecalling Performance Management System
- **Core Business Distinction**:
  - Maintained strict separation between **Client Contacts** (`main_database` / `temporary_database`) and **Leads** (`leads_table`).
  - Contacts remain contacts through all standard dispositions (*Not Interested*, *Callback / Busy*, *DND*, *Switch Off*, *Not Reachable*, *Invalid*, *Ringing*).
  - Only positive interest (*Enquiry / Interested*, *Lead*, *Hot*) converts a contact into a Lead with loan qualification details (`loan_type`, `loan_amount`, `net_salary`, `salary_account`, `bank_name`).
  - Permanent relationship preserved via `leads_table.cust_id = main_database.ID` and audit logging in `contact_lead_conversions`.
  - Idempotent conversion prevents duplicate rows on repeated mobile retries.
- **Database Schema Enhancements (Additive & Self-Healing)**:
  - Added `lead_id` and `client_contact_id` to `TBL_CALL_LOGS` with indices.
  - Added `client_contact_id`, `contact_source`, and `source_call_id` to `TBL_LEAD_FOLLOWUPS` enabling pre-conversion contact callbacks as first-class entities.
  - Created `contact_lead_conversions` table for complete conversion audit history.
  - Created `reporting_targets` table storing Super Admin configurable quotas and alert thresholds.
  - Initialized automatically via `php_scripts/reporting_schema.php` on config boot.
- **Reporting Engine & Analytics (`php_scripts/reporting_engine.php`)**:
  - Implemented modular functions for Client Contact metrics, Calling analytics, Lead & loan pipeline metrics, Hourly calling distribution (09:00 AM - 06:00 PM), Employee targets & achievement %, Supervisor rollups, Follow-up hub, Performance alerts, and Rankings.
- **Call Reports Page Overhaul (`modules/logs/Reports.php`)**:
  - Rebuilt with Flux Dashboard design system and 10 integrated tabs:
    1. *Executive Overview & Trends* (3-Tier Hero cards, Hourly Velocity Bar chart, Dispositions Doughnut chart)
    2. *Client Contacts Calling Registry* (100 recent contacts, dispositions, lead status links, search)
    3. *Converted Loan Leads* (Lead ID, Orig. Contact ID, Loan Product, Requested Amount, Pipeline Stage, Disbursed Amount)
    4. *Sales Officers Performance* (Calling targets, achievements, connect rate, talk time)
    5. *Supervisor Rollup* (Squad aggregations, staff count, target achievement, connect rate)
    6. *Hourly Calling Analytics* (Hour-by-hour breakdown 09:00-18:00)
    7. *Follow-ups Hub* (Scheduled callbacks for both Contacts and Leads)
    8. *Performance Alerts Center* (Critical, Warning, Info, Success with configurable triggers)
    9. *Rankings Leaderboard* (6-Factor Calling Productivity Score & Loan Sales Score with Medals)
    10. *Detailed Call History* (Full audit trail with audio recordings)
  - Added Super Admin Target Configuration modal for live threshold tuning.
  - Added Comprehensive Multi-Section CSV Export (`?export=csv`).
- **Lead Detail Calling Journey View (`modules/leads/lead_view.php`)**:
  - Added *Customer Calling Journey* panel displaying all calls made before and after conversion.
- **Mobile Telecaller API (`api/v1/telecaller/`)**:
  - Updated `submit_call.php` to extract loan qualifications, set `client_contact_id` and `lead_id` on call logs, record conversion audit, and schedule pre-conversion follow-ups.
  - Added `/follow-ups` (GET / POST), `/follow-ups/{id}/complete` (PUT / POST), and `/follow-ups/{id}/reschedule` (PUT / POST) routes with role-scoping.
- **Automated Verification**:
  - Created `tests/test_loan_telecalling_performance.php` covering all 10 business lifecycle scenarios (17/17 assertions passing 100%).
  - Batch PHP lint across entire repository passed with 0 syntax errors.

## Completed Feature (2026-11-01): Mobile App Full User Details & Profile Management API
- **Endpoint Enhancements**:
  - `POST /api/v1/auth/login`: Now returns full user profile object in `data.user` (including `email`, `mobile`, `phone`, `role`, `company_name`, `team_name`, `department`, `package`, `status`, `join_date`, `profile_photo_url`).
  - `GET /api/v1/auth/me` & `GET /api/v1/auth/profile`: Returns full user profile joined with `TBL_TEAMS` and `TBL_ROLES`.
  - `POST` / `PUT` `/api/v1/auth/profile` (and `/api/v1/auth/me`, `/profile/update`): Allows telecaller to update their personal details (`name`, `company_name`, `profile_photo_base64`, and password).
  - **Strict Security Guard**: Changing registered email (`EMAIL`/`LOGIN_ID`) or mobile (`MOBILE`) from the mobile app is strictly blocked and rejected with HTTP 400.
  - `POST /api/v1/auth/change-password` (and `/password`): Authenticated password change with `current_password` verification and min-length validation.
- **Shared Middleware**:
  - Added `getFullUserProfileData(mysqli $link, int $userId): ?array` in `php_scripts/api_auth.php`.
- **Test Suite**:
  - Expanded `tests/test_api_module.php` with 6 new assertions (39 total assertions passing 100%).

## Completed Major Fix (2026-11-01): Full Flux Dashboard UI Design System Overhaul
Strictly aligned the entire project with `flux_dashboard_ui_design_system_skill.md` (Next.js 16 Flux Dashboard aesthetic: violet `#6D45F9` & electric blue `#0090DF` brand accents, frosted glass topbar, high-contrast dark `#11111A` sidebar, 14px signature card radius, 10px inputs & buttons, 9999px pill badges, colored layered shadows, dot grid textures, Inter + JetBrains Mono typography, strictly zero backend login/auth changes):
- **Core Design Tokens & Theme Engine (`assets/css/app-theme.css`)**:
  - Implemented Flux Design System tokens (`--primary: #6D45F9`, `--primary-hover: #5E37EC`, `--accent: #0090DF`, `--bg-page: #FAFAFB`, `--bg-surface: #FFFFFF`, `--sidebar-bg: #11111A`, `--border-default: #E5E5E8`, `--radius-lg: 14px`, `--radius-md: 10px`, `--radius-pill: 9999px`).
  - Added signature utilities: `.flux-gradient-text`, `.flux-gradient-brand`, `.flux-card-highlight`, `.flux-glow`, `.flux-frosted`, `.flux-dot-grid`, `.flux-brand-wash`.
  - Added soft colored layered shadows: `--shadow-card`, `--shadow-card-hover`, `--shadow-dropdown`, `--shadow-modal`, `--shadow-flux`, `--shadow-glow`.
  - Updated status chips and badges: Success (`#16A66A` / `#E9F8F1`), Warning (`#D89012` / `#FFF6DF`), Danger (`#E5484D` / `#FFF0F0`), Info (`#4D72E8` / `#EEF3FF`), Purple (`#8C72FF` / `#F0ECFF`).
- **Master Navigation & Chrome**:
  - `php_scripts/header.php`: Preconnected and loaded Google Fonts (Inter + JetBrains Mono).
  - `php_scripts/sidebar.php`: Flux high-contrast dark sidebar (`#11111A`), gradient brand mark, active nav item with violet highlight and glow indicator.
  - `php_scripts/topbar.php`: Frosted glass top header (`rgba(255,255,255,0.80)` + blur 14px), command launcher (`Ctrl+K`), violet quick action `+ Create` button, status pill.
  - `php_scripts/footer.php`: Global OmniSearch command palette modal (`#omniSearchModal`) with Flux styling and monospace keyboard navigation hints.
- **Authentication & Root Shells**:
  - `index.php`, `forgot-password.php`, `CallNowSignUp.php`: Enhanced cards with soft ambient brand wash, 16px radius, violet CTA button, zero logic changes.
  - `dashboard.php`: Flux KPI stat cards with tabular numerals, soft brand wash welcome card, quick action launcher with rounded cards.
  - `profile.php`: 14px profile summary card and edit form with 10px inputs and security controls.
- **Leads & Pipeline Management**:
  - `modules/leads/leads_dashboard.php`: Flux KPI cards, conversion funnel with Flux palette (`#6D45F9`, `#0090DF`, `#4D72E8`, `#D89012`, `#8C72FF`, `#16A66A`, `#087A4B`).
  - `modules/leads/lead_pipeline.php`: Flux Kanban board with 14px column containers, 10px lead cards, hover elevation, and stage accent badges.
  - `modules/leads/lead_list.php`: Filter toolbar with segmented controls and 14px lead cards with hover glow.
  - `modules/leads/lead_view.php`: Customer details view with workflow pipeline bar, sticky action strip, and 14px section cards.
  - `modules/leads/lead_insert.php`: 2-column form layout with 10px controls and datalists.
- **Database & Data Management**:
  - `modules/database/data_management_main.php`: 14px table card container, bulk action toolbar, and assign/edit modals.
  - `modules/database/data_management_temporary.php`: Dual-tier toolbar and transfer progress modals.
  - `modules/database/upload_data.php`: Dropped dashed dropzone with dot-grid pattern and violet hover wash.
  - `modules/database/ViewDND.php`, `add_single_number.php`, `add_update_status.php`: Flux cards and form controls.
- **Logs & Audit Trails**:
  - `modules/logs/Reports.php`: Chart.js updated with Flux palette (`#6D45F9` violet area fill, `#0090DF` blue fill, doughnut and bar colors), KPI cards, and filter bar.
  - `modules/logs/call_history.php` & `manage_activity.php`: Clean table views with status chips.
- **Verification & Testing**:
  - Batch PHP lint across all 74 repository files (`& "C:\xampp\php\php.exe" -l`) passed with 100% zero syntax errors.
  - Automated test suite `tests/test_api_module.php` passed 33 of 33 test assertions (100% pass rate).
  - Verified strictly NO login or authentication backend logic was modified.
## Completed Major Fix (2026-10-29): Full Zoho CRM NextGen Enterprise UI/UX Overhaul
Strictly aligned the entire project with `zoho-crm-ui-ux-skill.md` (enterprise high density, slate/neutral theme with navy accent `#0066cc`, 4–8px radius, no bubbly gradients or oversized cards, crisp standard components):
- **Core Design Tokens & Theme Engine (`assets/css/app-theme.css`)**:
  - Replaced purple/gradient styles with Zoho CRM NextGen design tokens (`--crm-primary: #0066cc`, `--crm-navy: #0b1f3a`, `--crm-surface: #ffffff`, `--crm-bg: #f4f6f9`, `--crm-border: #e2e8f0`).
  - Standardized enterprise heights: 38–44px table rows, 34–36px input fields & buttons, 11–13px typography.
  - Implemented standard components: `.crm-card`, `.metric-card`, `.module-header`, `.crm-table`, status badges, high-contrast modal chrome, and accessible focus states.
- **Master Navigation & Chrome**:
  - `php_scripts/header.php`: Standardized Inter font, document head, dynamic title format, responsive meta tags.
  - `php_scripts/sidebar.php`: Zoho CRM NextGen 6-section sidebar with telecalling status pill, active tint highlights, and smooth collapse toggling.
  - `php_scripts/topbar.php`: Global OmniSearch trigger (`Ctrl+K`), quick `+ Create` action menu, system telecalling indicator, user profile dropdown.
  - `php_scripts/footer.php`: Global Omni-Search modal (`#omniSearchModal`) with keyboard shortcut handler (`Ctrl+K`) and keyboard navigation.
- **Authentication & Account Shells**:
  - `index.php`, `forgot-password.php`, `CallNowSignUp.php`, `profile.php`: Rebuilt with clean enterprise cards, standard input fields, password toggle visibility, and unified feedback alerts.
- **Leads & Pipeline Management**:
  - `modules/leads/leads_dashboard.php`: 5 compact KPI metric cards, stage funnel, bank login distribution, follow-ups table.
  - `modules/leads/lead_pipeline.php`: Zoho CRM Kanban board, stage count strip, column color badges, instant filter.
  - `modules/leads/lead_list.php`: Filter bar with 7 dropdowns, compact lead cards grid, batch selection, pagination.
  - `modules/leads/lead_view.php`: Customer record detail view, workflow pipeline bar, ownership handover banner, 2-column detail sections, threaded notes, follow-up scheduler, sticky bottom action strip.
  - `modules/leads/lead_insert.php`: 2-column lead creation form with datalists and clean action buttons.
- **Database & Data Management**:
  - `modules/database/data_management_main.php`: High density DataTables table, 4 KPI cards, edit & assign modals.
  - `modules/database/data_management_temporary.php`: Dual-tier toolbar (bulk actions + filters), staging alert, transfer progress modals.
  - `modules/database/upload_data.php`: Dashed dropzone, target radio pills, terms modal, KPI cards, activity log accordion.
  - `modules/database/ViewDND.php`: DND repository with tabs (All, Main, Temp), search, restore and delete actions.
  - `modules/database/add_single_number.php`: Standard 2-column customer entry card.
  - `modules/database/add_update_status.php`: Side-by-side single and range update cards with recent logs table.
  - `modules/database/maindatabase_ajax/download_bach.php` & `temporarydatabase_ajax/download_bach.php`: Multi-part CSV batch export orchestrators.
- **Logs & Audit Trails**:
  - `modules/logs/Reports.php`: High-density performance analytics, 5 KPI cards, date range presets, Chart.js trends, telecaller drilldown, Excel export.
  - `modules/logs/call_history.php`: Comprehensive call logs table with audio recording playback, disposition badges, and pagination.
  - `modules/logs/manage_activity.php`: Enterprise audit trail with multi-faceted filtering, detail inspector modal, and bulk delete.
- **Users, Teams & Administration**:
  - `modules/users/users_view.php`: User directory, 5 KPI cards, search toolbar, active/inactive collapsibles, Table2Excel export.
  - `modules/users/users_add.php`: 2-column user creation and editing form with role privileges helper.
  - `modules/users/teams_dashboard.php`: Squad directory, supervisor indicators, member inspector modal, quick reassign.
  - `modules/users/team_members.php`: 2-column allocation hub with supervisor changes and inline team reassignment.
  - `modules/settings/settings.php`: General settings, branding logo uploader with preview, staff breakdown.
  - `modules/settings/permissions_manager.php`: 4-tab Access Control Hub (Role Matrix, Roles Directory, Feature Registry, User Overrides).
  - `modules/settings/api_settings.php`: Mobile API configuration, token generator modal, database scoping, endpoint documentation guide, live traffic logs.
  - `modules/settings/super_admin_panel.php`: 5-tab Super Admin Control Center, usage gauges, subscription plan CRUD, limit thresholds, global module master switches, limit violation logs.
- **Verification**: Batch PHP lint across all 74 repository files (`& "C:\xampp\php\php.exe" -l`) passed with 100% zero syntax errors.

## Completed Major Fix (2026-07-21): `tn()` Safe Table Resolver
- **Root cause of HTTP 500s**: Stale config.php on Hostinger (missing new `TBL_*` constants) + opcache. Global `mysqli_report(MYSQLI_REPORT_ERROR)` added to prevent PHP 8.2 from throwing exceptions on query failures.
- **`tn()` function**: Defined in `config.php` — resolves table names safely via constant, falls back to a global override map. All ~40+ PHP files converted to use `tn('TBL_*')` instead of bare constants.
- **Missing table constants added**: `TBL_LEAD_NOTES`, `TBL_LEAD_FOLLOWUPS`, `TBL_LEAD_ASSIGNMENTS`, `TBL_USER_PAGE_FILTERS` — defined in config.php with self-heal guard.
- **Auto-creation**: `ensureLeadModuleSchema()` now creates `lead_notes`, `lead_followups`, `lead_assignments` tables on first call.
- **Scope of conversion**: All core files (`auth.php`, `team_auth.php`, `api_auth.php`, `permissions.php`, `header.php`, `profile.php`, index, dashboard, forgot-password), all lead module files (30+ scripts), all AJAX endpoints, all API endpoints, all settings/users/teams pages, all data management pages.
- **Verification**: All PHP files pass `php -l` syntax check across entire repo.

## Completed Security & Bug Fixes (2026-10-27)
- **User Addition "Duplicate entry" Fix**:
  - Root cause: In `modules/users/users_add.php`, leaving the optional Alternate Email blank passed an empty string `""` to the `EMAIL` column. Because `users.EMAIL` has a UNIQUE index (`idx_email`), MySQL rejected subsequent inserts with `Duplicate entry '' for key 'idx_email'`.
  - Fix: Empty alternate emails now bind as SQL `NULL` (MySQL permits multiple `NULL`s in unique columns).
  - Added dedicated pre-checks for Mobile, Login Email, and Alternate Email collisions before database queries run, returning specific, user-friendly warnings. Sanitized existing empty strings in `users.EMAIL` to `NULL` and set user ID 8's missing `LOGIN_ID`.
- **Permission Key Typo Fix**:
  - Restored `manage_users` and `manage_teams` keys across `php_scripts/permissions.php`, `php_scripts/sidebar.php`, `modules/users/users_view.php`, `modules/users/users_add.php`, `modules/users/teams_dashboard.php`, and `modules/users/team_members.php` (which had been corrupted to `manage_TBL_USERS` and `manage_TBL_TEAMS`).
- **Main Database Bulk Assign**:
  - Created [`modules/database/maindatabase_ajax/bulk_assign.php`](file:///c:/xampp/htdocs/callnowCRM/modules/database/maindatabase_ajax/bulk_assign.php) resolving the missing endpoint referenced in `data_management_main.php`.
- **Web Authentication Rate Limiting**:
  - Created [`php_scripts/rate_limiter.php`](file:///c:/xampp/htdocs/callnowCRM/php_scripts/rate_limiter.php) with auto-created table `login_attempts`. Enforces lockout on brute-force attempts across login (`index.php`) and password reset (`forgot-password.php`).
- **CSV Formula Injection Sanitization**:
  - Neutralized spreadsheet formula injection in [`modules/database/maindatabase_ajax/export_batch.php`](file:///c:/xampp/htdocs/callnowCRM/modules/database/maindatabase_ajax/export_batch.php), [`modules/database/maindatabase_ajax/export_csv.php`](file:///c:/xampp/htdocs/callnowCRM/modules/database/maindatabase_ajax/export_csv.php), and [`modules/database/temporarydatabase_ajax/export_batch.php`](file:///c:/xampp/htdocs/callnowCRM/modules/database/temporarydatabase_ajax/export_batch.php) by escaping `=`, `+`, `-`, `@`, `\t`, and `\r`.
- **Host Header Poisoning Defense**:
  - Sanitized `$_SERVER['HTTP_HOST']` in [`php_scripts/config.php`](file:///c:/xampp/htdocs/callnowCRM/php_scripts/config.php) and added optional `APP_URL`/`APP_BASE` environment override.
- **Telecaller Lease-Based Batch Queue Engine & Database Tracking**:
  - Implemented 20-minute lease-based auto-expiry locking in [`api/v1/telecaller/index.php`](file:///c:/xampp/htdocs/callnowCRM/api/v1/telecaller/index.php) (`GET /batch-leads`, `GET /next-call`, `POST /calls/{id}/complete`, `POST /release-queue`).
  - Added self-healing schema additions: `BATCH_ASSIGNED_AT` in `TEMPORARY_DATABASE` and `MAIN_DATABASE`, and `current_calling_db`, `last_batch_at` in `users`.
  - Enforced strict hierarchy: Temporary DB depleted first (oldest uncalled first); then Main DB (Tier 1 fresh uncalled `CALL_COUNT = 0` oldest first $\to$ Tier 2 user pending follow-ups $\to$ Tier 3 retries oldest dialed first).
- **API Module Audit, Bug Fixes & Automated Test Suite (2026-10-27)**:
  - Fixed schema discrepancies in `api/v1/telecaller/index.php`: aligned `TBL_CALL_LOGS` queries with database column definitions (`record_id` instead of `lead_id`, `source` instead of `call_type`, `call_status` instead of `status`, `call_duration` instead of `duration_seconds`).
  - Fixed `LAST_CONNECTED_PERIOD` datetime corruptions in `TBL_MAIN` and `TBL_TEMP` (was binding `'XXs'` string into DATETIME column; now accurately sets `NOW()` when connected and retains existing value otherwise).
  - Fixed `bind_param` parameter count mismatches (`sissii` instead of `sissiii`) in `api/v1/telecaller/index.php` and `api/v1/telecaller/submit_call.php`.
  - Fixed `api/v1/telecaller/submit_call.php`: resolved non-existent columns (`CALL_DURATION`, `CALL_NOTES`, `NEXT_FOLLOWUP_DATE`, `RECORDING_URL`), corrected `MAINDATABASE_CALL_DIALED_STATUS` column mapping on `TBL_MAIN`, and fixed string binding type for `recording_url`.
  - Added support for `/submit_call`, `/submit-call`, and `/calls/complete` directly in `api/v1/telecaller/index.php` routing for versatile mobile client compatibility.
  - Excluded `localhost` and `127.0.0.1` from HTTPS enforcement in `.htaccess` to allow local API development and testing without SSL chain errors.
  - Created automated test suite [`tests/test_api_module.php`](file:///c:/xampp/htdocs/callnowCRM/tests/test_api_module.php) covering 33 test assertions spanning Auth, Telecaller, Lease Locking, Analytics, Admin settings, and Token lifecycle with 100% pass rate.
- **Rich Call Telemetry & Auto-Conversion to Leads Pipeline (2026-10-27)**:
  - Added full call telemetry extraction & persistence (`dial_time`, `call_end_time`, `total_duration`, `sim_slot`, and `metadata` JSON) in `TBL_CALL_LOGS` across both `/calls/{id}/complete` and `/submit_call`.
  - Added `syncCallToLeadsAndPipeline()` in `php_scripts/api_auth.php`: when a telecaller submits a call with status `Lead` or `Interested`, the record is automatically converted and inserted into `TBL_LEADS` (`leads_table`) with caller attribution and open follow-up in `TBL_LEAD_FOLLOWUPS`. If `Call Back` or `Follow-up` is submitted, the lead's follow-up schedule and stage are automatically updated.
  - Updated `modules/logs/Reports.php` aggregation query: included `Lead`, `Interested` in `connected` calls, and `Call Back`, `callback`, `Follow-up` in `pending` calls so productive mobile calls reflect in performance reports.
  - Updated `.htaccess` HTTPS rewrite condition: exempted private LAN IP ranges (`10.x.x.x`, `192.168.x.x`, `172.16-31.x.x`) to facilitate direct local mobile app testing over Wi-Fi without SSL rejection.
- **Mobile App Lead Creation & Synchronization APIs (2026-10-29)**:
  - Added `POST /api/v1/telecaller/leads`: allows mobile apps to create and send new leads to the backend CRM. Validates 10-digit mobile number, runs cross-database DND check against both `main_database` and `temporary_database`, prevents duplicate conflicts, assigns lead to the telecaller, syncs to `TBL_LEADS`, and logs activity.
  - Added `GET /api/v1/telecaller/leads`: allows mobile apps to receive created/assigned leads with pagination, search, status filtering, and telecaller role-scoping.
  - Added `GET /api/v1/telecaller/leads/{id}` and `PUT /api/v1/telecaller/leads/{id}`: retrieve and update existing lead details from the mobile client.
  - Updated mobile app `ApiService` (`createLead`, `getCreatedLeads`, `updateLead`) and `LeadsScreen` with "+ Add Lead" modal bottom sheet.
- **Large Database Input, Scalability & UX Hardening (2026-11-01)**:
  - **DataTables Negative Limit Bug (`length = -1`)**: Selecting "All" or passing negative length caused MySQL syntax error `LIMIT 0, -1`. Normalized `$length = ($length <= 0) ? 250 : min($length, 2000)` and `$start = max(0, $start)` across `modules/database/maindatabase_ajax/datatable.php` and `modules/database/temporarydatabase_ajax/datatable.php`.
  - **Count Query Optimization**: When DataTables search is empty (`$search === ''`), the filtered record count query is bypassed and `$filtered = $total` is assigned directly, halving DB overhead on 57,000+ row tables.
  - **Cell Overflow & XSS Hardening**: Added `htmlspecialchars()` sanitization and `<span class="d-inline-block text-truncate" style="max-width: 180px;">` cell containment on customer name and company columns in DataTables to prevent table blowout on long unbroken text or malicious input.
  - **Sargable Date Index Optimization**: Replaced non-sargable `DATE(col) = CURDATE()` full table scans with index-friendly datetime ranges (`col >= CURDATE() AND col < CURDATE() + INTERVAL 1 DAY`) in `dashboard.php`, `modules/database/add_update_status.php`, and `api/v1/telecaller/index.php`. Benchmarked query execution dropped from 77.65 ms to 1.02 ms (39x faster).
  - **ViewDND Index Restoration**: Removed `LOWER(status)` function wraps in `modules/database/ViewDND.php` in favor of case-insensitive `IN (...)`, dropping scanned rows from 57,039 to 8 rows via index seek.
  - **User Management Negative KPI Count Fix**: In `modules/users/users_view.php`, fixed negative count calculations (`Inactive = filteredTotal - active`) during search filters; isolated global user metrics (`ID != 1 AND ROLE != 'Super Admin'`) with `max(0, ...)` safeguards.
  - **Telecaller API Search & Subquery Optimization**: In `api/v1/telecaller/index.php` `/calls`, migrated client search filtering from post-query PHP into the SQL `WHERE` clause and count query, and eliminated redundant self-referential correlated subqueries.
  - **Call History High-Volume Pagination**: In `modules/logs/call_history.php`, bypassed expensive table joins in count queries when search is empty, and added windowed sliding pagination with ellipsis navigation (`1, 2 ... 14, 15, 16 ... 100`) to prevent UI collapse across large datasets.
  - **DOM & Memory Limits Safeguards**: Added `LIMIT 500` caps with warning notices in `modules/logs/Reports.php` detail drill-downs and `modules/leads/lead_pipeline.php` Kanban board to prevent browser lockup on telecallers with tens of thousands of records.
  - **CSV Batch Import Memory Capping**: Capped the task progress log array in `modules/database/upload_data.php` to the latest 100 entries (`array_slice($logs, -100)`), preventing progress JSON bloat during 100k+ row imports.
- **Web App UI & Navigation Link Alignment (2026-11-02)**:
  - **Breadcrumb Navigation**: Styled `.breadcrumb`, `.breadcrumb-item`, and `.breadcrumb-item a` with subtle `--text-secondary` font, chevron separator (`bi-chevron-right`), and hover highlighting, eliminating raw unstyled violet text across all modules.
  - **Component Collision & Attaching Elements**: Resolved UI collisions where headers sat directly on top of cards (e.g. `leads_dashboard.php` `.welcome-strip` now has proper margins and flex spacing).
  - **Metric Card Stacking**: Fixed `.metric-card` horizontal squishing by setting `flex-direction: column` by default with `overflow: hidden` and `white-space: normal` on badge pills, while retaining row flex for cards with left icons.
  - **Responsive KPI Grid**: Refactored `dashboard.php` KPI grid to `col-xxl-2 col-xl-4 col-md-4 col-sm-6` to provide comfortable card widths on standard 1200px-1399px viewports.
  - **Container-fluid Normalization**: Reset double padding on `.app-content > .container-fluid`.
- **Card Format Overflow Fix, Natural Color Palettes & Dropdown Icons (2026-11-03)**:
  - **KPI & Stat Card Stacking & Column Normalization**: In `modules/logs/Reports.php`, normalized uneven narrow columns to `<div class="col-xl col-md-4 col-sm-6">` with vertical stacking (`flex-direction: column !important; justify-content: flex-start !important;`) and responsive text wrapping (`word-break: break-word !important; white-space: normal !important;`) preventing text clipping ("Durat > 0s", "Fleet benchm").
  - **Quota Progress Cards Stacking**: In `modules/settings/super_admin_panel.php`, normalized quota cards to responsive `<div class="col-12 col-sm-6 col-xl-3">` with stacked metric label, numerical quota, progress bar, and percentage subtext.
  - **Universal Dropdown Chevron Icons**: Fixed missing dropdown icons across all `<select class="form-select">` and `<select class="form-select-sm">` by providing explicit SVG chevron background icons and right-padding (`padding-right: 2.25rem !important;`), and enhanced all `.dropdown-toggle` buttons with neat chevron carets and smooth open rotation.
  - **Natural Card Background Tinting**: Added subtle natural gradient backgrounds (`linear-gradient(180deg, #FFFFFF 0%, #FAFAFC 100%)`) with soft border definition (`#E2E8F0`) for all cards, plus semantic natural tint palettes (Success/Green, Primary/Purple, Warning/Amber, Danger/Red, Info/Sky Blue, Neutral/Slate) with 3px top accent borders for `.metric-card` and `.stat-card`.
  - **Browser Cache Invalidation**: Added `?v=<?= filemtime(...) ?>` query parameters to `header.php`, `index.php`, `forgot-password.php`, and `CallNowSignUp.php` to immediately load updated CSS without browser cache persistence.
- **Profile Page Natural Redesign & MySQL Photo Storage with Mobile API Sync (2026-11-03)**:
  - **Profile Page Natural Redesign**: Overhauled `profile.php` with natural card backgrounds, 96px circular avatar with camera overlay, live JavaScript `FileReader` client-side image preview, and natural security stat indicators.
  - **Direct MySQL Picture Storage**: Added `profile_picture_blob` (`MEDIUMBLOB`) and `profile_picture` (`LONGTEXT` data URI) columns to `TBL_USERS` with auto-schema self-healing in `php_scripts/config.php`. Profile pictures are saved as both binary data and Base64 string directly in MySQL alongside disk cache.
  - **Mobile API Photo Delivery**: Updated `getFullUserProfileData()` in `php_scripts/api_auth.php` and `api/v1/auth/index.php` (`/me`, `/profile`, `/user`, `/login`, `/refresh`) to return `profile_photo_url`, `profile_picture` (Base64 data URI), `profile_picture_url`, `avatar_url`, and `profile_photo`.
  - **Direct MySQL Photo Stream Endpoint**: Added `GET /api/v1/auth/photo` and `/avatar` to stream the image binary directly from MySQL with `Content-Type: image/jpeg` and caching headers.
  - **Topbar Avatar Sync**: Updated `php_scripts/topbar.php` to display the user's uploaded profile picture in the topbar navigation avatar ring.
- **UI / UX Polish for Card Bounds, Dropdown Chevrons, and Natural Styling (2026-11-03)**:
  - **Card Content Overflow Prevention**: Constrained `.metric-card-body` to `flex: 1 1 0%`, `min-width: 0`, and `overflow: hidden`, preventing stat badges (e.g., `Interested / Sales`) from breaking through card boundaries.
  - **Metric Card Collision Fix in Reports & Dashboards**: Standardized `.col-xl col-lg-4 col-md-6 col-sm-6` responsive grid columns and vertical flex containment in `Reports.php` and `leads_dashboard.php`, eliminating card overlap and text clipping.
  - **Resource Quota Wrapping**: Updated `super_admin_panel.php` tenant quota values to align numerators, denominators, and units cleanly with baseline flex, preventing awkward line breaks for large counts.
  - **Universal Dropdown Chevrons**: Applied high-contrast SVG arrow icons across all native `select` and `.form-select` controls with dedicated padding-right, and added rotating carets to all `.dropdown-toggle` buttons.
  - **Natural Card Tinting & Soft Badges**: Updated `.card` and `.crm-card` with soft natural off-white gradients and subtle borders, alongside pastel semantic badge themes (`bg-success`, `bg-warning`, `bg-danger`, `bg-purple`, `bg-info`, `bg-secondary`).
- **Telecalling CRM End Goals Implementation & Verification (2026-11-03)**:
  - **Goal 1 (Server Data Upload)**: Verified dual-tier architecture. Temporary DB (`TBL_TEMP`) serves test calling batches; admin can inspect quality and bulk-delete poor test data directly without affecting `TBL_MAIN`. Main Database (`TBL_MAIN`) serves as the permanent enterprise calling and data logs storage repository.
  - **Goal 2 (Mobile One-by-One Updates)**: Telecaller mobile app updates call status one-by-one via `POST /api/v1/telecaller/calls/{id}/complete` (and `/submit_call`). Updates status, timestamps (`LAST_DIALED_DATE_TIME = NOW()`), increments `CALL_COUNT`, clears active queue leases (`BATCH_ASSIGNED_AT = NULL`), and records full telemetry in `TBL_CALL_LOGS` with timing tiers (`CONNECTED_SHORT`, `CONNECTED_CONVERSATION`, `NOT_CONNECTED`).
  - **Goal 3 (Leads Generation, Pipeline & Reports)**: When telecallers submit positive call results (`Interested`, `Interested / Hot`, `Lead`, `Converted`), `syncCallToLeadsAndPipeline()` automatically creates/updates CRM leads in `TBL_LEADS` (`lead_stage = 'Hot'`, `priority = 'High'`), auto-promotes temporary contacts to `TBL_MAIN` to guarantee permanent customer IDs, makes self leads visible in mobile `GET /telecaller/leads`, and exposes performance analytics in mobile `GET /telecaller/reports/summary` and web `modules/logs/Reports.php`.
  - **Goal 4 (Concurrency, 15-Minute Leases, Sorting & 3-Month Protection)**:
    - **Concurrency**: Wrapped batch allocation and single-call fallback in MySQL transactions with `FOR UPDATE` row-locking, guaranteeing one number to one telecaller at a time with 0 collisions.
    - **15-Minute Lease**: Configured 15-minute lease duration (`BATCH_ASSIGNED_AT < NOW() - INTERVAL 15 MINUTE`) auto-releasing uncalled records.
    - **Queue Auto-Fallback**: Automatically depletes Temporary DB first; when temporary data ends, seamlessly falls back to Main DB.
    - **Oldest Dialed Sorting**: Main DB records are prioritized by fresh uncalled first (`LAST_DIALED_DATE_TIME IS NULL`), followed by oldest dialed (`LAST_DIALED_DATE_TIME ASC`).
    - **Strict 3-Month (90-Day) Cooldown**: Numbers dialed by another user within 90 days are strictly blocked from being shared with other users (`LAST_DIALED_DATE_TIME < NOW() - INTERVAL 90 DAY OR m.MAINDATABASE_CALL_DIALED_USER = ?`).
    - **Activity Protection**: A number dialed > 90 days ago can only be re-allocated to another telecaller if NO activity exists in the system (blocked if an active lead in `TBL_LEADS`, scheduled future follow-up, or recent call in `TBL_CALL_LOGS` within 90 days exists).
  - **Verification Suite**: Created `tests/test_telecaller_crm_goals.php` (20/20 assertions passing 100%) and verified `tests/test_api_module.php` (43/43 assertions passing 100%). Total 63/63 automated test assertions passing.
- **Sidebar Collapsible Accordion & Dashboard Shortcut Directory (2026-11-03)**:
  - **Sidebar Collapsible Menus**: In `php_scripts/sidebar.php`, converted static sidebar navigation sections (Workspace, Leads & Pipeline, Data Management, Activities & Reports, Users & Teams, Settings & Admin) into collapsible accordions. On page load, only the section matching the current URL is expanded (`is-open is-active`); all other sections remain collapsed (`is-collapsed` with `display: none;`). Added explicit `onclick="toggleSidebarSection(this)"` with inline fallback handler and CSS visibility rules (`.app-sidebar-section.is-collapsed .app-sidebar-section-collapse { display: none; }`), eliminating race conditions and browser cache failures. Added `?v=<?= filemtime(...) ?>` to `sidebar.js` and `theme.js` in `footer.php`.
  - **Dashboard Reports & Analytics Spotlight**: In `dashboard.php`, added a prominent Featured Reports Spotlight Banner right below the primary KPI cards. Includes a direct primary CTA button to `modules/logs/Reports.php`, quick date-preset jump buttons (`Today's Report`, `Yesterday`, `This Month`, `Last 30 Days`), and live operational metric cards for Dialed, Connected, and Connect Rate, with direct links to `call_history.php` and `manage_activity.php`.
- **Premium Modern SaaS Dashboard Redesign (2026-11-12)**:
  - Redesigned `dashboard.php` to match modern Indigo-Violet SaaS aesthetic (`#6D3BFF`, `#8B5CF6`, `#2563EB`, `#16A34A`, `#F59E0B`, `#F43F5E`) based on reference redesign specification without altering sidebar or top navbar.
  - Added `assets/css/dashboard-premium.css` for scoped styling, 18-20px rounded cards, subtle shadows, and responsive grid layout.
  - Implemented 8-card KPI grid with pastel backgrounds, distinct borders, and bottom sparklines (Calls Today, Connected, Connect Rate, Active Callers, Hot Leads, Pipeline Leads, Fresh Pending, Main Database).
  - Main analytics section with two columns (62% Left for Call Performance & Disposition Analytics with Chart.js Area & Donut charts, 38% Right for Real-Time Calling Snapshot & Hourly Activity Histogram).
  - Preserved all existing role-gated CRM module shortcuts and operational fleet status. Tested with 25/25 passing web page tests.
  - **All Sections & Operational Modules Directory**: Added a comprehensive 6-card shortcut directory on `dashboard.php` representing all 6 CRM modules (Reports, Leads, Database, Users & Teams, Settings, Profile), fully role-gated via `can(...)` with direct links and descriptions.
  - **UI Alignment Polish**: In `modules/leads/leads_dashboard.php`, normalized search input to `form-control-sm` and `input-group-sm` to perfectly align with `btn-sm`.
- **Sidebar Sub-menu Link Switching, Card Format Normalization, Natural Background Tints & Universal Dropdown Chevrons (2026-11-03)**:
  - **Sidebar Section Switching & Sub-menu Visibility**: Resolved double-toggle race conditions between inline event handlers and external script listeners. Standardized `onclick="toggleSidebarSection(this, event)"` on all 6 sidebar section headers and removed conflicting inline `style="display: none;"` on `.app-sidebar-section-collapse`. Clicking any section automatically expands `document.body` if in collapsed rail mode, closes other sections, and sets `.style.setProperty('display', 'block'/'none', 'important')` so sub-menu links are immediately visible and clickable.
  - **Infallible Global `url()` Helper**: Added `url()` definition directly inside `php_scripts/config.php` and wrapped it in `if (!function_exists('url'))` in both `config.php` and `header.php`, preventing fatal "Cannot redeclare url()" HTTP 500 error on page loads.
  - **KPI & Stat Card Overflow Fix**: Fixed horizontal squishing and text clipping on narrow viewports in `modules/logs/Reports.php` ("Duration > 0s", "Fleet benchmark") by ensuring vertical flex direction (`flex-direction: column !important; justify-content: flex-start !important;`), explicit card padding (`p-3`), and `text-nowrap` on numerical values.
  - **Resource Quota Wrapping**: In `modules/settings/super_admin_panel.php`, replaced `flex-wrap` with `text-nowrap` on tenant quota numbers (`4 / 10`, `6 / 5`, `58,818 / 500,000`, `21.64 MB / 500 MB`) so fractions never split vertically across multiple lines.
  - **Universal Dropdown Chevrons**: Replaced fragile URL-encoded SVGs with crisp, browser-universal Base64-encoded SVG downward chevrons on all `<select>`, `.form-select`, and `.form-select-sm` inputs with dedicated 2rem right padding, and ensured rotating carets on all `.dropdown-toggle` and `[data-bs-toggle="dropdown"]` buttons.
  - **Natural Card Coloring**: Refined card backgrounds across the entire project (`.card`, `.crm-card`, `.metric-card`, `.stat-card`, `.card-header`) with subtle natural gradients (`linear-gradient(180deg, #FFFFFF 0%, #F9FAFB 50%, #F3F4F6 100%)`), soft slate borders (`#E2E8F0`), and pastel semantic color tints (Green, Purple, Amber, Red, Sky Blue).

## Leads and Pipeline Verification (2026-11-05)

- Added `tests/test_leads_pipeline.php`, which runs the real PHP request handlers in isolated sessions using disposable customer, lead, and user fixtures. Covers creation, duplicate prevention, rejection before underwriting, workflow progression and login gates, notes, precise follow-up times and completion, bank cloning, reassignment, access denial, permission revocation, filters, pagination, page rendering, output escaping, CSRF, and deletion cleanup.
- Fixed broken auth include paths on seven lead actions, missing permission gates, constant-by-reference insert failures, the lead update bind signature, missing previous-stage selection, premature rejection blocking, missing successful-login gates for sanction/disbursal, truncated follow-up times, stale next appointments after completion, clone parameter mismatch and inherited terminal status, and the create form duplicate-check URL.
- Lead deletion now removes dependent notes/follow-ups/assignment history in a transaction and detaches surviving bank applications. The login-status schema accepts REWORK_PENDING, matching the edit form.

## Remaining Known Issues (not yet fixed)
- **Call-detail API parity and retrieval (2026-11-05)**: Both `POST /api/v1/telecaller/submit_call` and `POST /api/v1/telecaller/calls/{id}/complete` now persist the full call-detail payload (`total_duration`, `metadata`, `recording_url`, `sim_slot`, and call/dial/end timestamps). Both call retrieval endpoints return those details; per-record history is scoped to the caller, Supervisor team, or Admin/Manager. The web Call History page displays the extra details and expandable metadata. `tests/test_api_module.php` includes full-detail submission/retrieval regression assertions (46 passing assertions as of 2026-11-05).
- `lead_common.php` `ensureLeadModuleSchema()` self-heals ALL lead-module columns on page load (incl. Phase 0 columns: team_id, rework_flag, rework_stage, login_status, login_submitted_by/at, forwarded_flag, sent_backward_flag, parent_lead_id). The standalone `database/migrate_lead_management.sql` is still the canonical migration but no longer required to be run manually.
- Default admin `Admin@123` hardcoded in SQL seed (with warning in comments)
- Mixed procedural/OO `mysqli` style
- Password recovery now uses hashed, expiring, single-use tokens in `password_reset_tokens` and `reset-password.php`; password reset revokes API tokens. SMTP delivery must be configured and verified on the deployment host.

## Full project and Android audit (2026-11-05)

- `tests/run_project_audit.php` clones the configured database to a uniquely named `callnow_audit_*` database, starts two loopback PHP servers, runs every `test_*.php` entry point plus the page audit, records pass/fail output, and retains the audit database and JSON report in the system temp directory for investigation. It does not run destructive suites on the working database. Legacy user test entry points now use the real isolated-request workflow suite instead of reusing global sessions/constants incorrectly.
- Added Android HTTP contract, simultaneous-worker, web form authentication, password reset, and web data/CSV workflow suites. Integration details and remaining device checks are in `project-context/ANDROID_INTEGRATION.md`.
- Call endpoints share one transactional handler, validate JSON/durations/timestamps/recording URLs, scope customer detail/update access, support optional per-user `client_call_id` retry deduplication, preserve timestamps consistently, and return generic server errors. API preflight works before authentication; explicit user permission denials override role grants.
- Fixed permission-editor parameter types, unsafe user role/team/status submission, working-directory-dependent includes, corrupted text notes in customer edits, upload header/count bugs, missing CSV task CSRF/ownership checks, API request user-agent logging, and disabled-API enforcement. Internal tests and project context are blocked through Apache.
- Fresh-install call-log SQL now matches the runtime schema, including retry keys. The runtime adds missing retry columns/indexes through `ensureApiSchema()`.

## Conventions for Future Changes
- **Auth bootstrap**: `php_scripts/auth.php` (canonical) — include at top of every protected page
- **Role checks**: `can($key)` / `requirePermission($key)` / `requireAnyPermission([...])` is the SOLE access gate. `isAdmin()`/`isManager()`/`isSupervisor()`/`isOfficer()` remain as convenience helpers for data-scoping only, not page gates.
- **CSRF**: call `ensureCsrfToken()` (done in auth.php automatically); add `<?= csrfField() ?>` in forms;verify via `verifyCsrfToken()` on POST
- **DB queries**: always use prepared statements (`$link->prepare` + bind_param); never concatenate user input
- **Output escaping**: always `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`
- **Activity logging**: `logActivity($link, $type, $description)` from auth.php
- **URLs**: use `url('path')` helper from header.php or `APP_BASE . 'path'`
- **Charset**: utf8mb4 is set globally in config.php — no need to set per-page
- **Super admin limits**: call `ensureSuperAdminSchema($link)` before any limit check; use `checkUserLimit()`, `checkApiTokenLimit()`, `checkStorageLimit()` before destructive actions; use `getLimitUsageSummary()` for dashboard stats; log violations via `logLimitViolation()`. Gate the panel with `requirePermission('manage_super_admin')`.
- **Debug**: `display_errors` only on when `APP_DEBUG=1` env var set
- **CSS architecture**: `header.php` includes Bootstrap CSS, Bootstrap Icons, and `app-theme.css` centrally. Module pages should NOT include their own `<link>` tags for these. Only add minimal page-specific `<style>` blocks when needed. Standalone pages (index.php, forgot-password.php, CallNowSignUp.php) keep their own `<head>` but should use theme classes.
- **Page pattern**: Protected pages set `$pageTitle` then `include header.php` at top, `include footer.php` at bottom. No `<!DOCTYPE>`, `<html>`, `<head>`, or `<body>` tags in module pages.
- **Sidebar**: Fixed left column with collapsible `data-sidebar` states (`expanded`, `collapsed`, `mobile-open`). Mobile uses overlay + backdrop via `sidebar.js`. Body scroll locked when mobile sidebar open.

## Upload Data Page Modernization & Bug Fixes (2026-11-05)

- **Modern Coloring**: Replaced plain white UI with violet gradient module header (`#6D45F9 → #4A3CB8`), tinted card backgrounds (`#f3f0ff → #ffffff`), soft borders (`#e9ecef`), drop shadow elevation, and semantic status badges (success/warning/danger/info).
- **Drop Zone**: Enhanced dashed file drop zone with hover lift, violet hover wash, radial gradient overlay, and file info display (name + size).
- **Metric Cards**: Color-coded KPI cards — Processed (dark), Inserted (green), Duplicates (amber), Invalid (red) — with subtle shadows and hover lift.
- **Progress Modal**: Gradient modal header, animated striped progress bar, result alert with close button, and close-btn disabled during active processing.
- **Reset Button**: New "Reset" action clears file input, drop zone, preview, and form options with notification feedback.
- **Critical Bug Fix**: `processUploadBatch()` JavaScript was omitting `csrf_token` in the batch-processing AJAX POST, causing all chunked uploads to fail with 403. Now sends `csrf_token` via `FormData`, matching server-side `verifyCsrfToken()` check.
- **CSV Preview Parser**: Replaced naive `split(',')` with a proper RFC-4180-style parser that respects double-quoted fields containing commas, newlines, and escaped quotes (`""`).
- **Optional Header Fallback Fix**: When optional columns (name, company, package, other info) are missing from the header, they now default to empty string instead of incorrectly falling back to positional column 0.
- **Empty States**: Added centered empty-state styling for preview area and log accordion when no data is present.
- **Tests Verified**: Mobile normalization (`+91`, `0`, leading zeros), canonical header mapping, quoted-field CSV parsing, header alias resolution, and duplicate-key detection logic all validated.

## Mobile App & Telecaller Sync / Filtering Enhancements (2026-11-12)

- **Target Bank Selection & Custom Input**:
  - In `leads_screen.dart` (`_showAddLeadModal`), added `isExpanded: true` and `selectedItemBuilder` on all dropdowns (`Target Bank`, `Initial Stage`, `Priority`, `Temperature`), eliminating text clipping and `RenderFlex overflowed by X pixels` errors on long bank names (such as "Punjab National Bank (PNB)" or "State Bank of India (SBI)").
  - Added `+ Other / Custom Bank` option: when selected, dynamically displays an editable `TextFormField` (`customBankCtrl`, label: "Enter Target Bank Name *") allowing users to enter custom/unlisted bank names.
  - Form validation and submission seamlessly forwards either the selected partner bank or the typed custom bank name via `loginBank`.
- **Follow-Up Section User Scoping & Cross-Contamination Fix**:
  - **Backend (`api/v1/telecaller/index.php`)**: Added default scope `scope=mine`. Restricts query to `(f.user_id = ? OR l.assigned_to = ?)` for the authenticated user, fixing a critical leak where Admins, Managers, and Super Admins received all company follow-ups by default. Added `scope=all` restricted to management roles (company-wide) or supervisors (team-wide). Count query now properly joins `tbl_leads` so `l.assigned_to` resolves cleanly.
  - **Mobile Model (`followup_model.dart`)**: Added `userId` and `officerName` to `FollowupModel` with full JSON serialization and copy constructors.
  - **Mobile Service (`followup_service.dart`)**: Storage key is now partitioned by logged-in user (`callnow_saved_followups_uid_${userId}`). Added `clearLocalCache()` called on logout in `ConfigService.clearAuthData()`. `syncWithServer(scope: 'mine')` enforces client-side data isolation, discarding stale or foreign follow-ups.
  - **Mobile UI (`followup_screen.dart`)**: Added a scope toggle pill (`My Follow-ups` vs `All Team Follow-ups`) for Admin/Manager/Supervisor roles. Follow-up cards now render an officer badge (`👤 Officer: <Name>`) when viewing team records.

## Completed Feature (2026-11-12): Comprehensive Mobile App & CRM API Parity Audit & Fixes
- **Mobile Password Reset via OTP (`api/v1/auth/index.php`)**:
  - Implemented `POST /api/v1/auth/forgot-password` (and aliases `/auth/send-otp`, `/auth/reset-password/otp`, `/auth/password/email-otp`): verifies active user, generates 6-digit numeric OTP, saves sha256 hash in `password_reset_tokens` (15-min expiry), sends email notification, and returns success JSON.
  - Implemented `POST /api/v1/auth/reset-password` (and aliases `/auth/verify-otp-reset`, `/auth/password/reset`): validates OTP hash against `password_reset_tokens`, updates bcrypt password in `TBL_USERS`, expires the token, revokes active `TBL_API_TOKENS`, and returns success JSON.
- **Lead Creation & Update Full Field Persistence (`api/v1/telecaller/index.php`)**:
  - `POST /telecaller/leads`: Now persists all financial and banking fields (`loan_type`, `login_bank_name`, `bank_name`, `net_salary`, `salary_account`, `priority`, `lead_stage`, `next_followup_at`, `lead_status_new`, `NAME`, `MOBILE`, `COMPANY_NAME`, `OTHER_INFO`) into `TBL_LEADS` on both insert and update.
  - `PUT /telecaller/leads/$id`: Now synchronizes updates to `TBL_LEADS` simultaneously with `TBL_MAIN`/`TBL_TEMP`, preventing data divergence between web pipeline board and mobile screens.
- **Background Call Tracking & Phone Resolution (`api/v1/telecaller/submit_call.php`, `call_tracking_service.dart`)**:
  - Updated `submit_call.php` to handle non-integer or > 2,147,483,647 phone numbers in `record_id` and explicit `phone_number`/`mobile` fields. It searches `TBL_MAIN` and `TBL_TEMP` by 10-digit mobile number, or automatically creates a contact record in `TBL_MAIN` for external dialed calls, completely eliminating HTTP 400 integer validation errors and HTTP 404 missing record errors during background call logging.
  - Aligned `call_tracking_service.dart` to send `phone_number` and `mobile` in background call sync payloads.
- **Follow-up Creation Fallback by Phone (`api/v1/telecaller/index.php`, `api_service.dart`, `followup_service.dart`)**:
  - Updated `POST /telecaller/follow-ups` to accept `phone_number` and `customer_name` when `lead_id` and `client_contact_id` are absent. Resolves existing contact in `TBL_MAIN` or `TBL_TEMP` by 10-digit mobile number, or auto-creates contact in `TBL_MAIN`, associating the follow-up record cleanly.
  - Updated `ApiService.createServerFollowup` and `FollowupService.addFollowup` to forward `customerName` and `phoneNumber`.
- **Verification**:
  - All 9 test suites in `tests/run_project_audit.php` passed with exit code 0 (25 web audit, 25 web pages, 5 concurrency, 67 android integration, 46 api module, 39 users module, 31 users view, 10 web auth, 27 web data workflows).
  - `dart analyze` on Flutter app passed with 0 issues.
## Completed Feature (2026-11-14): Comprehensive RBAC Filter Enforcement & Super Admin Exclusion
- **Super Admin Exclusion from User Filters & Salesperson Dropdowns**:
  - `modules/leads/lead_pipeline.php`: Replaced raw unfiltered `SELECT ID, NAME FROM users WHERE STATUS = 1` query with `getLeadAssignableUsers($link)` and applied `getAccessibleUserIds($link)` for lead query scoping.
  - `modules/leads/lead_common.php`: Hardened `getLeadAssignableTBL_USERS` to filter by `(STATUS = 'Active' OR STATUS = '1' OR STATUS = 1)`, explicitly exclude `ROLE != 'Super Admin'` and `ID NOT IN (getSuperAdminUserIds)`, and enforce strict team scoping for non-admin roles with fail-closed fallback.
  - `modules/leads/lead_list.php`: Added `(u.ROLE != 'Super Admin' OR u.ROLE IS NULL)` to distinct assigned names query.
  - `modules/database/data_management_main.php` & `data_management_temporary.php`: Scoped `$telecallers` list via `getAccessibleUserIds($link)` and excluded `ROLE != 'Super Admin'` and `getSuperAdminUserIds($link)`.
  - `modules/logs/manage_activity.php` & `manage_activity_data.php`: Scoped user list `$TBL_USERSList` and activity query using `getAccessibleUserIds($link)` and excluded Super Admin accounts.
  - `modules/logs/call_history.php` & `call_history_data.php`: Scoped telecallers by `getAccessibleUserIds($link)` and excluded Super Admin records for non-Super Admin callers.
  - `api/v1/telecaller/index.php`: Added `ROLE != 'Super Admin' AND ID != 1` to `/team-officers` user discovery queries.
- **Verification**:
  - `tests/test_verify_filter_rbac.php`: Supervisor (ID 3), Manager (ID 2), Admin (ID 7), and Officer (ID 4) verified with 0 leaks of Super Admin accounts.
  - Multi-tenant architecture suite passed (26/26 tests passing).

- **Incoming Callback Auto-Tracking & Post-Call Action Prompt**:
  - **Backend (`api/v1/telecaller/index.php`, `submit_call.php`)**: Added `GET /telecaller/lookup-contact` endpoint to resolve contact details across `TBL_LEADS`, `TBL_MAIN`, and `TBL_TEMP` by 10-digit phone number. In `submit_call.php`, normalized phone numbers using `substr($cleanPhone, -10)` to reliably match Indian incoming numbers (`+91...` or `0...`).
  - **Native Android (`MainActivity.kt`)**: Added `getRecentCallLogs` method channel invoking `CallLog.Calls.CONTENT_URI` to inspect recent incoming and outgoing calls with durations, timestamps, and caller types.
  - **Mobile Service (`call_tracking_service.dart`)**: Background listener stores pending post-call metadata in `SharedPreferences` when an incoming call connects with `duration > 0`, and auto-submits call to CRM with status `Incoming Connected`. Added `getRecentCallLogs`, `getPendingPostCall`, and processed call caching.
  - **Mobile UI (`incoming_call_action_dialog.dart`, `home_screen.dart`)**: On app resume, checks for pending connected calls/callbacks. Displays `IncomingCallActionDialog` with previous status badge (e.g. `Was: Ringing`), talk duration, and 3 quick action tabs: Quick Status Update, Convert to CRM Lead, and Schedule Follow-up.
- **Leads Differentiation for TL & Managers (`api/v1/telecaller/index.php`, `leads_screen.dart`, `crm_lead.dart`)**:
  - Added `GET /telecaller/team-officers` for hierarchical team and member discovery.
  - Enhanced `GET /telecaller/leads` with `scope` (`mine`, `team`, `all`), `team_id`, and `user_id` filtering. Joined `TBL_TEAMS` and `TBL_USERS` to return `assigned_user_id`, `assigned_user_name`, `team_id`, `team_name`, and `is_own_lead`.
  - In `leads_screen.dart`, added `_buildManagementFilterBar()` with scope toggle pills, team dropdown, and officer dropdown. Updated cards with ownership badges (`👤 My Lead` vs `👤 Officer: [Name] • [Team]`).
- **Submit Call Result 400 Fix (`php_scripts/api_auth.php`)**:
  - Whitelisted `loan_qualification`, `loan_details`, and `qualification` associative arrays in `apiReadJson()` validator to allow rich loan payloads.
- **Verification**:
  - `tests/run_project_audit.php`: All 9 test suites passed with exit code 0 (68/68 android integration assertions).
  - `dart analyze` in Flutter app: 0 issues found (0 errors, 0 warnings).

## Completed Goal (2026-11-14): Comprehensive Automated Test Suite Expansion & Quality Audit
- **New Test Fixtures**:
  - `tests/fixtures/test_teams.sql`: Seeds Alpha & Beta teams with designated managers and supervisors.
  - `tests/fixtures/test_users.sql`: Seeds deterministic test users across roles (Super Admin, Admin, Manager, Supervisor Alpha/Beta, Officer Alpha/Beta, Suspended Officer) with bcrypt passwords.
  - `tests/fixtures/test_permissions.sql`: Seeds custom test role (`Quality Analyst`), role permissions matrix, and per-user overrides.
- **Implemented 8 New Automated Test Suites**:
  - `tests/test_rbac_matrix.php`: 40/40 assertions passing (13 system permission keys, `can()` gates, `canActorAssignRole()` hierarchy, `clearRbacCache()` version bump, per-user overrides, `requirePermission`/`requireAnyPermission`).
  - `tests/test_session_security.php`: Session fixation rotation on login, CSRF verification, 4-hour idle expiration, and login rate limiting (lockout after 5 failed attempts, clear on success).
  - `tests/test_multi_tenant_isolation.php`: 15/15 assertions passing (tenant resolution by code/name, strict database isolation, header resolution, suspended status gating).
  - `tests/test_tenant_provisioning.php`: 16/16 assertions passing (full provisioning lifecycle, schema self-heal, admin user bcrypt verification, duplicate code rejection, status toggling).
  - `tests/test_file_isolation.php`: 10/10 assertions passing (tenant upload directory separation, traversal prevention, CSV formula injection sanitization).
  - `tests/test_lead_pipeline_rbac.php`: 17/17 assertions passing (officer stage move restriction, supervisor edit permission, outsider access rejection, CSRF protection, cascading deletion).
  - `tests/test_report_access.php`: 18/18 assertions passing (`view_reports` gating, supervisor team rollup scoping, officer self-scoping, parameter tampering prevention, Super Admin isolation).
  - `tests/test_web_mobile_api.php`: Preflight OPTIONS, Bearer token lifecycle, profile access, password change validation, email/mobile immutability, telecaller team discovery scoping, phone contact lookup.
- **Baseline Defect Resolution**:
  - `tests/test_web_auth.php`: Fixed tenant registration in master table during audit tests, resolving 5 baseline failures to 10/10 passing.
  - `tests/lib/db_helper.php`: Added `dbLoadFixture()` supporting multi-query SQL fixture imports.
- **Verification**:
  - All 27 automated test suites passed 100% via `tests/run_project_audit.php` in an isolated database environment (`callnow_audit_*`) on loopback ports 8099/8100.
  - Batch PHP syntax check passed across all files with 0 errors.

## Completed Task (2026-11-14): Production Deployment Package Cleanup
- **Removed Test Files & Harnesses**:
  - Deleted `.kilo/` planning directory.
  - Deleted stale test report `project-context/PROJECT_TEST_REPORT.md`.
- **Removed Temporary Artifacts**:
  - Cleared test GIF image uploads and transient directories under `uploads/`.
- **Deployment Assets In Place**:
  - Created [DEPLOYMENT.md](file:///c:/xampp/htdocs/callnowCRM/DEPLOYMENT.md) for production installation and verification.
  - Synchronized [database/hostinger_schema.sql](file:///c:/xampp/htdocs/callnowCRM/database/hostinger_schema.sql) with all 29 database tables.

## Completed Goal (2026-11-14): High-Concurrency Scaling for Multi-User Mobile App Data Requests
- **Problem Addressed**:
  - Previously, `/batch-leads` executed a blocking `SELECT ... FOR UPDATE` with heavy correlated subqueries across 57,000+ records, scanning ~29,000 rows with filesorts. On MariaDB 10.4–10.11 (which lacks `SKIP LOCKED`), concurrent telecaller requests caused lock wait timeouts, deadlocks, and severe MySQL CPU saturation.
- **Architectural Solution Implemented**:
  1. **Atomic Two-Phase Lease Claiming**:
     - Eliminated long-lived transactions and `SELECT ... FOR UPDATE`.
     - Candidate IDs are discovered via read-only queries with zero locks.
     - Telecallers claim batches via atomic primary-key conditional UPDATEs (`UPDATE main_database SET MAINDATABASE_CALL_DIALED_USER = ?, BATCH_ASSIGNED_AT = NOW() WHERE ID IN (...) AND (MAINDATABASE_CALL_DIALED_USER IS NULL OR BATCH_ASSIGNED_AT < (NOW() - INTERVAL 15 MINUTE))`).
     - Exclusively claimed rows are returned via fast indexed read.
  2. **Candidate Pool Sampling**:
     - Candidates are sampled from a pool of up to 150 candidate IDs and shuffled in PHP.
     - If collision contention occurs during peak concurrency, unattempted candidate pool IDs are refilled automatically in an immediate second atomic pass.
  3. **High-Concurrency Composite Covering Indexes**:
     - `idx_main_concurrency_batch` (`MAINDATABASE_CALL_DIALED_STATUS`, `BATCH_ASSIGNED_AT`, `LAST_DIALED_DATE_TIME`, `ID`) on `main_database`.
     - `idx_temp_concurrency_batch` (`CALL_DIALED_STATUS`, `BATCH_ASSIGNED_AT`, `LAST_DIALED_DATE_TIME`, `ID`) on `temporary_database`.
     - `idx_logs_user_daily` (`user_id`, `created_at`, `call_duration`) on `call_logs`.
     - Self-healing checks integrated into `ensureApiSchema($link)` in `php_scripts/config.php` and `database/hostinger_schema.sql`.
  4. **45-Second TTL Dashboard Queue Count Caching**:
     - Implemented per-user 45-second cache in `app_settings` (`cached_queue_counts_{userId}`) for dashboard pending count queries.
     - Reduces 57k-row `COUNT(*)` database scans by up to 98% during repetitive app navigation and post-call returns.
- **Verification & Benchmarks**:
  - `tests/test_high_concurrency_scaling.php`:
    - Dispatched 20 concurrent parallel HTTP requests (`curl_multi`) simulating distinct telecallers at the exact same millisecond.
    - Test 1 (Batch Leads): 20/20 HTTP 200 responses, 143 distinct leads claimed, 0 collisions (zero duplicate assignments), 152ms avg latency.
    - Test 2 (Next-Call Fallback): 20/20 HTTP 200 single lead claims, 0 collisions.
    - Test 3 (Dashboard Caching): Cold query 413ms -> warm cached query 58ms (86% latency reduction).
  - Clean batch linting across all PHP files in repository with 0 syntax errors.





