# CallNow CRM V5.00

A telecalling CRM built with PHP 8.x and MySQL for managing call campaigns, leads, team performance, and subscription-style admin limits.

## Features

- **Role-based access** — Admin, Manager, Supervisor, Officer hierarchy with granular DB-backed permissions
- **Hidden Super Admin** — Separate Super Admin role with full access, hidden from all normal user views
- **Lead pipeline** — 8-stage Kanban board: LEAD → FOLLOWUP → INTERNAL_UNDERWRITING → LOGIN → BANK_UNDERWRITING → SANCTIONED → DISBURSED → REJECT
- **Team management** — Hierarchical teams with supervisor/manager assignments and member counts
- **Call database** — Upload, import, and manage prospect numbers with full CRUD; track call statuses
- **Reports** — Daily, monthly, and yearly performance reports with Chart.js and Excel export
- **Activity audit** — Full audit trail of all CRUD operations
- **DND management** — Do-Not-Disturb number tracking
- **Dark mode** — Light/dark theme toggle persisted across sessions
- **Responsive sidebar** — Collapsible, role-aware navigation with mobile overlay
- **CSRF protection** — Tokens on every form and AJAX POST request
- **API access** — Token-based mobile API with rate limiting and database assignments
- **Super Admin limits** — Subscription-style plans with configurable user/API/storage limits

## Tech Stack

| Layer | Stack |
|-------|-------|
| **Backend** | PHP 8.x procedural |
| **Database** | MySQL / MariaDB via `mysqli` (prepared statements) |
| **Frontend** | Bootstrap 5.3, Bootstrap Icons, jQuery, DataTables, Chart.js |
| **Auth** | Session-based with bcrypt password hashing, CSRF tokens |
| **Theme** | Custom CSS with light+dark tokens |

## Requirements

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Apache / Nginx with PHP support
- Hostinger shared hosting compatible

## Installation

### 1. Upload files

Upload all project files to your hosting account, preferably in a subfolder like `callnowCRM/`.

### 2. Create database

1. Log in to **Hostinger hPanel** → **Databases** → **MySQL Databases**
2. Create a new database (e.g., `callnow_incredit`)
3. Create a MySQL user and assign it to the database
4. Note down the database name, username, and password

### 3. Configure database connection

Edit `config.php` or create a `.env` file in the project root:

```
DB_SERVERNAME=localhost
DB_PORT=3306
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
DB_NAME=callnow_incredit
APP_DEBUG=0
```

The app also reads these from PHP environment variables if set in hosting control panel.

### 4. Import database schema

1. Go to **Hostinger hPanel** → **Databases** → **phpMyAdmin**
2. Select your database
3. Click **Import** → Choose file `database/hostinger_schema.sql`
4. Click **Go**

> `hostinger_schema.sql` uses `CREATE TABLE IF NOT EXISTS` and `INSERT IGNORE`, so it is safe to re-run. It includes the default Super Admin user.

### 5. Set permissions

Ensure `config.php` and `.env` are not publicly accessible. On Hostinger, sensitive files are typically protected by default.

### 7. Access the application

Visit: `https://yourdomain.com/callnowCRM/`

## Default Logins

| Role | Login ID | Password | Note |
|------|----------|----------|------|
| **Super Admin** | `admin@callnow.com` | `Admin@123` | Hidden full access — created by default in schema |
| Admin | `admin@callnow.com` | `Admin@123` | Full access |
| Manager | `manager@callnow.com` | `Admin@123` | Team + leads |
| Supervisor | `supervisor@callnow.com` | `Admin@123` | Team oversight |
| Officer | `officer@callnow.com` | `Admin@123` | Basic lead access |

> **Change default passwords immediately after first login.**

## Project Structure

```
├── config.php                      # DB connection, CSRF helpers, APP_BASE
├── index.php                       # Login page
├── dashboard.php                   # KPI dashboard
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
│   ├── hostinger_schema.sql          # Recommended for Hostinger deployment (includes default Super Admin user)
│   └── schema.sql                    # Full schema dump from development database
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
