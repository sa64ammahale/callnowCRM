# CallNow CRM V5.00

A telecalling CRM built with PHP 8.x and MySQL for managing call campaigns, leads, and team performance.

## Features

- **Role-based access** — Admin, Manager, Supervisor, Officer hierarchy with granular permissions
- **Call database management** — Upload, import, and manage prospect numbers with full CRUD; track call statuses (Connected, Not Reachable, DND, Pending, etc.)
- **Leads pipeline** — Kanban-style board with stages (Fresh, Follow Up, Meeting Fixed, Converted, etc.)
- **Team management** — Hierarchical teams with supervisor/manager assignments
- **Reports** — Daily, monthly, and yearly performance reports with Excel export
- **Activity audit** — Full audit trail of all CRUD operations
- **DND management** — Do-Not-Disturb number tracking
- **Dark mode** — Light/dark theme toggle persisted across sessions
- **Responsive collapsible sidebar** — Role-aware navigation

## Tech Stack

| Layer | Stack |
|-------|-------|
| **Backend** | PHP 8.x (procedural) |
| **Database** | MySQL via `mysqli` (prepared statements) |
| **Frontend** | Bootstrap 5.3, Bootstrap Icons, jQuery, DataTables |
| **Auth** | Session-based with bcrypt password hashing, CSRF tokens |
| **Theme** | Custom CSS with light+dark tokens (Inter font, indigo accent) |

## Requirements

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` (optional, not required)
- XAMPP / WAMP / LAMP stack recommended

## Installation

1. Clone the repository to your web server's document root:
   ```bash
   git clone <repo-url> callnowCRM
   ```

2. Configure database connection via environment variables (recommended) or edit `config.php`:

   | Variable | Default | Description |
   |----------|---------|-------------|
   | `DB_SERVERNAME` | `localhost` | Database host |
   | `DB_USERNAME` | `root` | Database user |
   | `DB_PASSWORD` | *(empty)* | Database password |
   | `DB_NAME` | `callnow_incredit` | Database name |
   | `APP_DEBUG` | *(unset)* | Set to `1` to enable `display_errors` |

3. Import the database schema:
   ```bash
   mysql -u root -p < modules/database/sql/setup_callnow_crm.sql
   ```
   > **Warning:** The setup SQL uses `DROP TABLE IF EXISTS` and will destroy any existing data.

4. Access the application at `http://localhost/callnowCRM/`

### Default Login

| Role | Login ID | Password |
|------|----------|----------|
| Admin | `admin@callnow.com` | `Admin@123` |

## Project Structure

```
├── config.php                  # Database config, CSRF helpers, APP_BASE constant
├── index.php                   # Login page
├── dashboard.php               # KPI dashboard
├── logout.php                  # Session destroy
├── profile.php                 # User profile editor
├── CallNowSignUp.php           # Self-registration
├── forgot-password.php         # Password reset
│
├── php_scripts/                # Shared chrome & logic
│   ├── auth.php                # Auth bootstrap, roles, activity logging
│   ├── header.php              # Full HTML head (CSS), sidebar, topbar
│   ├── footer.php              # Closes layout, loads JS
│   ├── sidebar.php             # Collapsible role-aware sidebar
│   ├── topbar.php              # Search, theme toggle, user menu
│   ├── team_auth.php           # Team-scoped helpers
│   └── set_theme.php           # Theme preference endpoint
│
├── assets/
│   ├── css/
│   │   └── app-theme.css       # Full design system (light+dark tokens)
│   └── js/
│       ├── theme.js            # Theme toggle + localStorage
│       └── sidebar.js          # Sidebar collapse + responsive
│
├── modules/
│   ├── database/               # DB management, CSV upload, DND list
│   │   └── maindatabase_ajax/  # Bulk ops, export, datatable JSON
│   ├── leads/                  # Pipeline board, lead CRUD, enquiries
│   ├── logs/                   # Reports (daily/monthly/yearly), activity log
│   └── users/                  # User CRUD, team hierarchy
│
└── project-context/
    └── CODEBASE_SUMMARY.md     # Canonical project reference (for maintainers)
```

## Database

| Table | Purpose |
|-------|---------|
| `USERS` | Application users with roles (Admin/Manager/Supervisor/Officer) |
| `TEAMS` | Team hierarchy with supervisor/manager links |
| `MAIN_DATABASE` | Primary prospect call records (~105k rows) |
| `TEMPORARY_DATABASE` | Staging table for CSV uploads (~16k rows) |
| `LEADS_TABLE` | Sales pipeline leads |
| `ENQUIRY` | Customer enquiries |
| `ACTIVITY_LOG` | Full audit trail |
| `MAIN_DATABASE_archive` | Archived call records |

## Roles & Permissions

| Feature | Admin | Manager | Supervisor | Officer |
|---------|-------|---------|------------|---------|
| Database management | ✓ | ✓ (temp) | - | - |
| Leads pipeline | ✓ | ✓ | ✓ | ✓ |
| Team management | ✓ | ✓ | ✓ | - |
| User management | ✓ | ✓ | ✓ | - |
| Reports | ✓ | ✓ | ✓ | ✓ |
| Activity log | ✓ | ✓ | - | - |
| CSV export | ✓ | - | - | - |
| Bulk delete | ✓ | - | - | - |

## Security

- All database queries use prepared statements (no SQL injection)
- CSRF tokens on every form and AJAX POST request
- Role guards on all destructive actions
- Session regeneration on login (fixation prevention)
- Passwords hashed with `password_hash()` (bcrypt)
- `display_errors` gated behind `APP_DEBUG` environment variable
- Legacy vulnerable files deleted

## Author

**Sangam Mahale**
