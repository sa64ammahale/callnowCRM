# CallNow CRM V5.00

A telecalling CRM built with PHP and MySQL for managing call campaigns, leads, and team performance.

## Features

- **Role-based access** — Admin, Manager, Supervisor, Officer hierarchy with granular permissions
- **Call database management** — Upload, import, and manage prospect numbers; track call statuses (Connected, Not Reachable, DND, etc.)
- **Leads pipeline** — Kanban-style pipeline with stages (Fresh, Follow Up, Meeting Fixed, Converted, etc.)
- **Team management** — Hierarchical teams with supervisor/manager assignments
- **Reports** — Daily, monthly, and yearly performance reports
- **Activity audit** — Full audit trail of all CRUD operations
- **DND management** — Do-Not-Disturb number tracking

## Tech Stack

| Layer | Stack |
|-------|-------|
| **Backend** | PHP 8.x (procedural) |
| **Database** | MySQL via `mysqli` |
| **Frontend** | Bootstrap 5.3, Font Awesome 6, custom glassmorphism theme |
| **Auth** | Session-based with bcrypt password hashing |

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

2. Import the database schema:
   ```bash
   mysql -u root -p < modules/database/sql/setup_callnow_crm.sql
   ```

3. Configure database credentials via environment variables (or edit `config.php`):

   | Variable | Default | Description |
   |----------|---------|-------------|
   | `DB_SERVERNAME` | `localhost` | Database host |
   | `DB_USERNAME` | `root` | Database user |
   | `DB_PASSWORD` | *(empty)* | Database password |
   | `DB_NAME` | `callnow_incredit` | Database name |

4. Access the application at `http://localhost/callnowCRM/`

### Default Login

- **Email:** `admin@callnow.com`
- **Password:** `Admin@123`

## Project Structure

```
├── config.php                 # Database configuration
├── index.php                  # Login page
├── dashboard.php              # Main dashboard
├── CallNowSignUp.php          # User registration
├── forgot-password.php        # Password reset
├── profile.php                # User profile
├── logout.php                 # Logout handler
├── php_scripts/
│   ├── auth.php               # Authentication, roles, activity logging
│   ├── header.php             # Shared navigation
│   └── footer.php             # Shared footer
├── modules/
│   ├── database/              # Database management, upload, DND
│   ├── leads/                 # Leads pipeline, CRUD, enquiries
│   ├── logs/                  # Reports and activity logs
│   └── users/                 # User and team management
└── assets/
    └── css/
        └── app-theme.css      # Theme styles
```

## Database Schema

| Table | Purpose |
|-------|---------|
| `USERS` | Application users with roles |
| `TEAMS` | Team hierarchy |
| `MAIN_DATABASE` | Prospect call records |
| `TEMPORARY_DATABASE` | Staging for uploaded data |
| `LEADS_TABLE` | Sales pipeline |
| `ENQUIRY` | Customer enquiries |
| `ACTIVITY_LOG` | Audit trail |
| `MAIN_DATABASE_archive` | Archived records |

## Roles & Permissions

| Feature | Admin | Manager | Supervisor | Officer |
|---------|-------|---------|------------|---------|
| Database Management | ✓ | - | - | - |
| Leads Pipeline | ✓ | ✓ | ✓ | ✓ |
| Team Management | ✓ | ✓ | ✓ | - |
| User Management | ✓ | ✓ | ✓ | - |
| Reports | ✓ | ✓ | ✓ | ✓ |
| Activity Log | ✓ | ✓ | - | - |

## Author

**Sangam Mahale**
