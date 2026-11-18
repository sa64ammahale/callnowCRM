# CallNow CRM V5.00

A multi-tenant telecalling CRM built with procedural PHP 8.x and MySQL for managing multiple companies, call campaigns, leads, team performance, and subscription-style admin limits from a single unified codebase.

---

## Key Highlights

- **Multi-Company (Database-per-Tenant)** — Serve multiple companies from a single codebase with 100% database-level isolation. Each company operates in its own dedicated MySQL database.
- **Dynamic Routing** — Automatic database switching based on company short code via Web Session, persistent 30-day cookies, and mobile API headers (`X-Company-Code`).
- **Super Admin Tenant Manager** — Web portal to provision new company databases, execute base schema automatically, seed initial admin credentials, and toggle company access (Active / Suspended).
- **Tenant-Scoped Asset Isolation** — User profile pictures and uploaded files are quarantined per-company (`uploads/{company_code}/...`) to prevent data leakage.
- **Lead Pipeline** — 8-stage Kanban board: `LEAD` → `FOLLOWUP` → `INTERNAL_UNDERWRITING` → `LOGIN` → `BANK_UNDERWRITING` → `SANCTIONED` → `DISBURSED` → `REJECT`.
- **RBAC System** — System roles (Super Admin, Admin, Manager, Supervisor, Officer) + custom roles with granular database-backed permissions matrix.
- **Mobile App Client Ready** — Native Flutter mobile client support with `company_code` login, encrypted storage, and automatic `X-Company-Code` request injection.

---

## Multi-Company Architecture Overview

```
                      +---------------------------------------+
                      |               Client                  |
                      +---------------------------------------+
                             /                         \
           (Web Browser)    /                           \   (Mobile App)
      [Session + Cookie]   /                             \  [Header: X-Company-Code]
                          v                               v
             +--------------------+              +--------------------+
             |   Web Controller   |              |   Mobile API v1    |
             |   (index/auth.php) |              |   (api_auth.php)   |
             +--------------------+              +--------------------+
                          \                               /
                           \                             /
                            v                           v
              +-----------------------------------------------+
              |        Tenant Database Connection Resolver    |
              |              (php_scripts/tenant.php)         |
              +-----------------------------------------------+
                       |                             |
     (1. Lookup Tenant)|                             | (2. Connect to Tenant DB)
                       v                             v
            +---------------------+        +-------------------------+
            |   callnow_master    |        |   callnow_<company>     |
            | (companies registry)|        | (users, leads, calls)   |
            +---------------------+        +-------------------------+
```

### Context Resolution Priority
When any request arrives, `php_scripts/tenant.php` resolves the target database in strict security order:
1. **Explicit Parameter**: Direct `$companyCode` passed to connection factory.
2. **Web Browser Session**: `$_SESSION['company_code']` (takes strict precedence to prevent cross-tenant session hijacking).
3. **Mobile API Header**: `HTTP_X_COMPANY_CODE` (sent by mobile apps or API clients).
4. **POST Form**: `$_POST['CompanyName']` on login and password reset forms.
5. **Persistent Cookie**: `$_COOKIE['callnow_company_code']` (pre-fills the login screen for 30 days).
6. **Default Fallback**: `DEFAULT_COMPANY_CODE` (`incredit`), connecting to `callnow_incredit`.

---

## Tech Stack

| Layer | Stack |
|-------|-------|
| **Backend** | PHP 8.x procedural |
| **Database** | MySQL / MariaDB via `mysqli` (prepared statements) |
| **Architecture** | Database-per-tenant multi-company routing |
| **Frontend** | Bootstrap 5.3, Bootstrap Icons, jQuery, DataTables, Chart.js |
| **Auth** | Session-based with bcrypt password hashing, CSRF tokens |
| **Mobile App** | Flutter 3.x (Dart), flutter_secure_storage, SharedPreferences |
| **Theme** | Custom CSS design system with light+dark tokens |

---

## Installation & Deployment

### 1. Requirements
- PHP 8.0+ with `mysqli`, `json`, `mbstring`, `fileinfo` extensions
- MySQL 5.7+ or MariaDB 10.3+
- Apache / Nginx with `mod_rewrite` enabled
- Hostinger Shared Hosting, cPanel, or Linux VPS compatible

---

### 2. Environment Configuration (`.env`)

Create a `.env` file in the project root (or configure environment variables in your hosting control panel):

```env
# Master Registry Database
MASTER_DB_NAME=callnow_master

# Default / Fallback Tenant Database
DB_SERVERNAME=localhost
DB_PORT=3306
DB_USERNAME=your_mysql_username
DB_PASSWORD=your_mysql_password
DB_NAME=callnow_incredit
DEFAULT_COMPANY_CODE=incredit

# Application Configuration
APP_URL=https://crm.yourdomain.com/callnowCRM
APP_DEBUG=0
SETUP_KEY=callnow2026
```

---

### 3. Database Initialization

#### Step A: Initialize the Master Catalog Database (`callnow_master`)
1. Create the master database in MySQL / phpMyAdmin:
   ```sql
   CREATE DATABASE IF NOT EXISTS `callnow_master` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. Import `database/master_schema.sql`:
   ```bash
   mysql -u your_user -p callnow_master < database/master_schema.sql
   ```
   *(On Hostinger shared hosting: Create the database via hPanel -> MySQL Databases, set `MASTER_DB_NAME=u123_master`, then import `database/master_schema.sql` via phpMyAdmin).*

#### Step B: Initialize the Primary Tenant Database (`callnow_incredit`)
1. Create the first company's database:
   ```sql
   CREATE DATABASE IF NOT EXISTS `callnow_incredit` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. Import the base tenant schema:
   ```bash
   mysql -u your_user -p callnow_incredit < database/hostinger_schema.sql
   ```

---

### 4. File Permissions
Ensure the following permissions on the server:
- `uploads/` → `755` (writable by web server)
- `config.php` and `.env` → `644` (protected)
- All PHP and asset files → `644`

---

### 5. Accessing the Application

- **Web Portal**: `https://yourdomain.com/callnowCRM/`
- Enter **Company Code or Name**: `incredit` (or `Incredit Financial Services`)
- Enter your credentials:
  - **Login ID**: `admin@callnow.com`
  - **Password**: `Admin@123`
- *(Change default passwords immediately after first login)*.

---

## Super Admin: Provisioning New Companies

Super Admins can manage companies and spin up new databases directly from the Web UI:

1. Log in as Super Admin (`admin@callnow.com`).
2. Navigate to **Super Admin Control** (sidebar) → **Companies & Databases** tab.
3. In the **"Provision New Company"** panel:
   - **Company Code**: e.g., `acme` (lowercase, alphanumeric, hyphens allowed)
   - **Company Name**: e.g., `Acme Telecalling Solutions`
   - **Custom Database Name** (optional): e.g., `callnow_acme` (defaults to `callnow_<company_code>`)
   - **Admin Name**: Initial administrator name
   - **Admin Email**: Login ID for the tenant administrator
   - **Admin Password**: Initial password for the tenant administrator
4. Click **Provision & Create Database**:
   - Creates the database `callnow_acme`.
   - Automatically executes the full 28-table schema from `database/hostinger_schema.sql`.
   - Seeds the initial tenant administrator account in that company's `users` table.
   - Registers the company in `callnow_master.callnow_telecalling_companys`.
5. **Access Control**: Super Admins can click **Suspend** to instantly block a company's web and mobile access, or **Activate** to restore it.

---

## Mobile App Client Integration (Flutter)

The mobile app client ([callnowMobileApp](file:///e:/Projects/FlutterProjects/callnowMobileApp)) has been updated to support multi-tenancy:

### 1. Login Screen
- The login form includes a **Company Code** field (`TextFormField`) pre-filled from local storage if previously used.
- The user inputs their company code (`acme`), email, and password.
- The login request passes `company_code` in the JSON body:
  ```json
  POST /api/v1/auth/login
  {
    "company_code": "acme",
    "email": "agent@acme.com",
    "password": "Password123"
  }
  ```

### 2. Encrypted Local Storage
- Upon successful login, the app stores the returned `company_code` and `token` in `FlutterSecureStorage` (backed by Android Keystore and iOS Keychain):
  - `ConfigService.saveAuthData(token, userData, companyCode: companyCode)`
  - Key: `callnow_company_code`

### 3. Automatic Header Injection
- Every subsequent API call through `ApiService` automatically attaches the company code header:
  ```http
  GET /api/v1/telecaller/calls HTTP/1.1
  Host: crm.yourdomain.com
  Authorization: Bearer <token>
  X-Company-Code: acme
  ```
- The backend `php_scripts/api_auth.php` detects `X-Company-Code` and routes all database operations to that company's database (`callnow_acme`).

---

## Database Schema Inventory

### Master Database (`callnow_master`)

| Table | Purpose |
|-------|---------|
| `callnow_telecalling_companys` | Global catalog mapping company codes to database names, host, credentials, status (Active/Inactive/Suspended), and logo URL |

### Tenant Database Schema (`database/hostinger_schema.sql`)

All 28 tables are created per tenant:

| # | Table | Purpose |
|---|-------|---------|
| 1 | `users` | Telecallers, officers, managers, and admins with bcrypt credentials |
| 2 | `roles` | System roles (Admin, Manager, Supervisor, Officer, Super Admin) |
| 3 | `permissions` | 11 system permission keys + custom access keys |
| 4 | `role_permissions` | Role-to-permission mapping matrix |
| 5 | `user_permissions` | Per-user permission overrides |
| 6 | `teams` | Team hierarchy with supervisor/manager assignments |
| 7 | `app_settings` | Per-tenant settings (logo path, theme, RBAC cache version) |
| 8 | `main_database` | Primary prospect customer calling records |
| 9 | `main_database_archive` | Archived prospect records |
| 10 | `temporary_database` | Staging database for batch CSV imports |
| 11 | `leads_table` | 8-stage sales pipeline leads (Kanban board) |
| 12 | `lead_notes` | Lead interaction notes & comments |
| 13 | `lead_followups` | Scheduled callback alarms & follow-ups |
| 14 | `lead_assignments` | Audit trail of lead assignments across agents |
| 15 | `activity_log` | Full audit trail of all CRUD operations with IP logging |
| 16 | `call_logs` | Dialed call records, durations, audio recordings |
| 17 | `user_page_filters` | Persisted DataTables and filter preferences per user |
| 18 | `api_settings` | Mobile API rate limits and enabled flags |
| 19 | `api_tokens` | Bearer tokens for mobile app authentication |
| 20 | `api_database_assignments` | Per-user allowed database assignments (temporary, main, leads) |
| 21 | `api_access_logs` | Mobile API request access log with execution time |
| 22 | `super_admin_plans` | Subscription quotas & resource limits |
| 23 | `super_admin_account` | Tenant subscription status & billing contact |
| 24 | `super_admin_limit_logs` | Limit violation audit trail |
| 25 | `enquiry` | Legacy customer enquiry records |
| 26 | `password_reset_tokens` | SHA-256 hashed 30-minute password reset tokens |
| 27 | `contact_lead_conversions` | Performance tracking for prospect-to-lead conversions |
| 28 | `reporting_targets` | Role-based daily call, talk time, and lead targets |

---

## Testing & Verification

### Run CRM Backend Tests
Verify multi-tenant isolation, database switching, sanitization, and provisioning:
```powershell
php tests/test_multi_tenant.php
```
*(Expected output: 26 Passed, 0 Failed)*.

### Batch PHP Syntax Linting
Verify zero syntax errors across all PHP files:
```powershell
Get-ChildItem -Recurse -Include *.php | ForEach-Object { php -l $_.FullName }
```

### Run Flutter Mobile App Tests
Verify secure storage, company code caching, and configuration persistence:
```powershell
cd e:\Projects\FlutterProjects\callnowMobileApp
flutter test test/config_service_test.dart
flutter test test/user_profile_test.dart
```
├── logout.php                      # Session destroy
├── profile.php                     # User profile editor
├── CallNowSignUp.php               # Self-registration + setup seed
├── forgot-password.php             # Password reset
│
├── php_scripts/
│   ├── auth.php                    # Auth bootstrap, roles, can(), activity log
│   ├── header.php                  # HTML head, sidebar, topbar
│   ├── footer.php                  # JS includes, layout close
│   ├── sidebar.php                 # Collapsible navigation
│   ├── topbar.php                  # Search, theme toggle, user menu
│   ├── team_auth.php               # Team-scoped access helpers
│   ├── super_admin.php             # Super Admin limits + plans
│   └── permissions.php             # Permission seed + defaults
│
├── assets/
│   ├── css/
│   │   └── app-theme.css           # Full design system (light+dark)
│   └── js/
│       ├── theme.js                # Theme toggle
│       └── sidebar.js              # Sidebar responsive behavior
│
├── modules/
│   ├── database/
│   │   ├── data_management_main.php      # Main DB DataTables + bulk ops
│   │   ├── data_management_temporary.php # Temp DB CRUD
│   │   ├── upload_data.php               # CSV import + archive
│   │   ├── add_single_number.php         # Add/edit single record
│   │   ├── add_update_status.php         # Bulk status update
│   │   ├── ViewDND.php                   # DND numbers list
│   │   └── maindatabase_ajax/            # AJAX endpoints
│   │       ├── datatable.php             # DataTables JSON
│   │       ├── bulk_delete.php           # Bulk delete
│   │       ├── bulk_assign.php           # Bulk assign
│   │       ├── export_csv.php            # CSV export
│   │       └── export_batch.php          # Batched export
│   │
│   ├── leads/
│   │   ├── lead_insert.php         # Add new lead
│   │   ├── lead_update.php         # Update lead
│   │   ├── lead_view.php           # Single lead view/edit
│   │   ├── lead_list.php           # Lead register (card SPA)
│   │   ├── lead_pipeline.php       # Kanban board (8 stages)
│   │   ├── leads_dashboard.php     # Lead summary dashboard
│   │   └── php_scripts/
│   │       ├── lead_assign.php     # Assign lead to user
│   │       ├── lead_note_add.php   # Add note
│   │       ├── lead_followup_add.php # Schedule followup
│   │       ├── lead_cards_data.php # Cards JSON
│   │       └── lead_list_data.php  # DataTables JSON
│   │
│   ├── users/
│   │   ├── users_view.php          # List/filter users
│   │   ├── users_add.php           # Add/edit user
│   │   ├── teams_dashboard.php     # Team CRUD
│   │   └── team_members.php        # Member assignment
│   │
│   ├── logs/
│   │   ├── Reports.php             # Call performance reports
│   │   └── manage_activity.php     # Activity log viewer
│   │
│   └── settings/
│       ├── settings.php            # General settings
│       ├── permissions_manager.php # RBAC hub
│       └── super_admin_panel.php   # Super Admin limits/plans
│
├── api/v1/
│   ├── auth/index.php              # Mobile API login
│   ├── telecaller/index.php        # Telecaller endpoints
│   └── admin/index.php             # Admin API endpoints
│
├── database/
│   └── hostinger_schema.sql          # Recommended for Hostinger deployment (includes default Super Admin user)
│
└── project-context/
    ├── CODEBASE_SUMMARY.md         # Canonical project reference
    └── SECURITY_AUDIT.md           # Security audit log
```

## Database Schema

### Core Tables

| Table | Purpose |
|-------|---------|
| `users` | Application users with roles, teams, and auth |
| `roles` | System + custom roles (Admin, Manager, Supervisor, Officer, Super Admin) |
| `permissions` | Permission keys (11 system + custom) |
| `role_permissions` | Per-role permission matrix |
| `user_permissions` | Per-user permission overrides |
| `teams` | Team hierarchy with supervisor/manager |
| `app_settings` | Application settings (timezone, session timeout, RBAC flags) |

### Lead Management Tables

| Table | Purpose |
|-------|---------|
| `leads_table` | Main leads with 8-stage pipeline, rework flags, login status |
| `lead_notes` | Threaded notes per lead |
| `lead_followups` | Scheduled follow-ups |
| `lead_assignments` | Lead handoff audit trail |

### Call Database Tables

| Table | Purpose |
|-------|---------|
| `main_database` | Primary prospect call records |
| `main_database_archive` | Archived call records |
| `temporary_database` | Staging table for CSV uploads |

### Super Admin Tables

| Table | Purpose |
|-------|---------|
| `super_admin_plans` | Subscription plans with limits |
| `super_admin_account` | Single-row account/billing config |
| `super_admin_limit_logs` | Limit violation audit trail |

### API Tables

| Table | Purpose |
|-------|---------|
| `api_tokens` | Mobile API tokens |
| `api_settings` | API configuration |
| `api_database_assignments` | Token-to-database assignments |
| `api_access_logs` | API request audit trail |

### Logging Tables

| Table | Purpose |
|-------|---------|
| `activity_log` | Full CRUD + auth audit trail |
| `call_logs` | Call history logs |
| `user_page_filters` | User filter preferences |

## Roles & Permissions

### System Roles

| Role | Access |
|------|--------|
| **Super Admin** | Full system access, hidden from all normal views |
| **Admin** | Full access except Super Admin panel |
| **Manager** | Teams, users, leads, data, reports |
| **Supervisor** | Team leads, reports, export |
| **Officer** | Basic lead management, reports |

### Permission Matrix (defaults)

| Permission | Admin | Manager | Supervisor | Officer |
|------------|-------|---------|------------|---------|
| Manage Users | ✓ | ✓ | - | - |
| Manage Teams | ✓ | ✓ | ✓ | - |
| Manage Settings | ✓ | ✓ | - | - |
| View Activity Log | ✓ | ✓ | - | - |
| Manage Database | ✓ | ✓ | - | - |
| Upload Data | ✓ | ✓ | - | - |
| Export Data | ✓ | - | ✓ | - |
| Assign Leads | ✓ | ✓ | ✓ | - |
| Manage Leads | ✓ | ✓ | ✓ | ✓ |
| Delete Leads | ✓ | ✓ | - | - |
| View Reports | ✓ | ✓ | ✓ | ✓ |
| Manage API | ✓ | ✓ | - | - |

## Lead Pipeline Stages

| Stage | Owner | Description |
|-------|-------|-------------|
| LEAD | Telecaller | Fresh enquiry and qualification |
| FOLLOWUP | Telecaller | Waiting on customer response |
| INTERNAL_UNDERWRITING | Back Office | Document/eligibility check before bank login |
| LOGIN | Back Office | File logged into bank/lending partner |
| BANK_UNDERWRITING | Bank/Manager | Bank review for approval |
| SANCTIONED | Manager | Loan approved, ready for release |
| DISBURSED | Manager | Funds released successfully |
| REJECT | Manager | Case closed or declined |

## Hostinger Deployment Notes

### Pre-upload checklist

1. **PHP Version**: Set PHP 8.0+ in hPanel → **Advanced** → **PHP Configuration**
2. **MySQL**: Create a new database in hPanel → **Databases** → **MySQL Databases**
3. **File Permissions**: 
   - `config.php` → `644` (readable, not writable)
   - `uploads/` → `755` (writable by web server)
   - All other files → `644`
4. **.env**: Copy `.env.example` to `.env` and fill in your database credentials, OR set environment variables in hPanel → **Advanced** → **Environment Variables**
5. **SSL**: Enable free SSL in hPanel → **SSL** → **Free SSL**
6. **Remove debug files**: Ensure no `diag_*.php`, `opcache_clear.php`, or similar files are present in production

### Upload steps

1. Upload all project files to your hosting account (recommended: `public_html/callnowCRM/`)
2. Import `database/hostinger_schema.sql` via phpMyAdmin (hPanel → **Databases** → **phpMyAdmin**)
3. Verify `uploads/` directory exists and is writable (755)
4. Set environment variables in hPanel or create `.env` file

### Environment Variables for Hostinger

Set these in hPanel → **Advanced** → **Environment Variables**:

| Variable | Value |
|----------|-------|
| `DB_SERVERNAME` | `localhost` |
| `DB_PORT` | `3306` |
| `DB_USERNAME` | `your_db_user` |
| `DB_PASSWORD` | `your_db_password` |
| `DB_NAME` | `callnow_incredit` |
| `APP_DEBUG` | `0` |
| `SETUP_KEY` | `callnow2026` (optional, for initial setup) |

### Post-installation

1. Visit `https://yourdomain.com/callnowCRM/`
2. Log in with default Super Admin credentials:
   - **Login ID**: `admin@callnow.com`
   - **Password**: `Admin@123`
3. **Change the default password immediately**
4. Verify the Super Admin link appears in the sidebar
5. Test creating leads, users, and teams

### Troubleshooting

| Issue | Solution |
|-------|----------|
| 500 Internal Server Error | Check `APP_DEBUG=1` in `.env` to see detailed errors. Common causes: missing PHP extensions, wrong file permissions |
| Database connection error | Verify DB credentials in `.env` or hPanel environment variables |
| Session not persisting | Ensure `tmp/` directory is writable, or set `session.save_path` in `.htaccess` |
| CSS/JS not loading | Check that `assets/` directory permissions are 755, and `.htaccess` is present |
| "Table doesn't exist" | MySQL on Hostinger is case-sensitive. Ensure table names in SQL are lowercase |
| Super Admin link not showing | Verify user has `ROLE='Super Admin'` in database, not just `ID=1` |

## Security

- All queries use prepared statements (no SQL injection)
- CSRF tokens on every form and AJAX POST
- Role-based access control via `can($permissionKey)`
- Session regeneration on login
- Bcrypt password hashing
- `display_errors` disabled in production (`APP_DEBUG=0`)
- Super Admin hidden from all normal user views
- Legacy vulnerable files removed

## Browser Support

- Chrome (recommended)
- Firefox
- Safari
- Edge

## Author

**Sangam Mahale**

## License

Incraax AI Automation Pvt Ltd — All rights reserved.
