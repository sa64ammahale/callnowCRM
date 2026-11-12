# CallNow CRM V5.00 — Production Multi-Tenant Platform

A high-performance telecalling CRM built with PHP 8.x and MySQL for managing calling campaigns, lead pipelines, hierarchical team performance, subscription-style admin limits, and **multi-tenant subdomain hosting with independent databases**.

---

## Key Highlights

- **Multi-Tenant Subdomain Routing** — Host multiple independent companies via subdomains (e.g. `zenith.increditsolutions.in`, `acme.increditsolutions.in`) or custom domains with independent database credentials.
- **Complete Database-Per-Tenant Isolation** — Physical data segregation per company for data privacy, compliance, and independent backups.
- **Universal Super Admin** — Platform owner access (`admin@callnow.com`) pre-seeded in every database with full RBAC bypass.
- **CLI Tenant Provisioner** — Automated, one-command provisioning (`php bin/provision_tenant.php`) to create databases, import the schema, and configure company subdomains in seconds.
- **Lead Pipeline Kanban Board** — 8-stage interactive board: `LEAD` → `FOLLOWUP` → `INTERNAL_UNDERWRITING` → `LOGIN` → `BANK_UNDERWRITING` → `SANCTIONED` → `DISBURSED` → `REJECT`.
- **Calling Desk & Background Tracking** — Telecalling queue with automated Android background call tracking, callback detection, and post-call action sheets.
- **Mobile App Multi-Tenant Connectivity** — Flutter Android app supports quick "Company Code" connection (e.g. entering `zenith` auto-connects to `https://zenith.increditsolutions.in/api/v1`).
- **Production-Hardened Security** — Tenant-scoped session cookie isolation (`CALLNOW_<hash>`), security headers (`X-Frame-Options`, `X-Content-Type-Options`), CSRF tokens, bcrypt hashing, and strict error shielding (`display_errors = 0`).

---

## Tech Stack

| Layer | Technology |
|---|---|
| **Backend** | PHP 8.x procedural |
| **Database** | MySQL 8.x / MariaDB 10.4+ via `mysqli` (strictly prepared statements) |
| **Web Frontend** | Bootstrap 5.3, Bootstrap Icons, jQuery, DataTables, Chart.js |
| **Mobile App** | Flutter 3.x / Dart (Android, phone_state_background, method channels) |
| **Multi-Tenancy** | Subdomain-to-Database resolver (`php_scripts/tenant_manager.php`, `config/tenants.php`) |
| **Auth & Security** | Per-tenant session scoping, bcrypt password hashes, CSRF tokens, RBAC permissions |

---

## Multi-Tenant Hosting Architecture

CallNow CRM enables running a multi-company platform without needing a complex central master database:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       Incoming HTTP / API Request                           │
│              (e.g., https://acme.increditsolutions.in/...)                   │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                                       ▼
                     ┌───────────────────────────────────┐
                     │     Host / Subdomain Parser       │
                     │   (extracts subdomain: 'acme')    │
                     └─────────────────┬─────────────────┘
                                       │
                                       ▼
                     ┌───────────────────────────────────┐
                     │   Tenant Config (tenants.php)     │
                     │  - Matches 'acme' or custom host  │
                     │  - Falls back to default .env     │
                     └─────────────────┬─────────────────┘
                                       │
                ┌──────────────────────┴──────────────────────┐
                │ Returns Tenant Credentials:                 │
                │ Host, Port, DB Name, User, Password         │
                └──────────────────────┬──────────────────────┘
                                       │
                                       ▼
                     ┌───────────────────────────────────┐
                     │      mysqli_connect($tenantDB)    │
                     │   UTF-8 mb4, timezone, reporting  │
                     └─────────────────┬─────────────────┘
                                       │
          ┌────────────────────────────┼────────────────────────────┐
          ▼                            ▼                            ▼
┌───────────────────┐        ┌───────────────────┐        ┌───────────────────┐
│  Company A DB     │        │  Company B DB     │        │  Default / Dev DB │
│  callnow_acme     │        │  callnow_beta     │        │  callnow_incredit │
│                   │        │                   │        │                   │
│ [Super Admin user]│        │ [Super Admin user]│        │ [Super Admin user]│
└───────────────────┘        └───────────────────┘        └───────────────────┘
```

### 1. DNS Wildcard Setup
Point all company subdomains to your server IP address by adding a wildcard DNS A-record in your domain registrar (or cPanel/Cloudflare):
```text
Type: A
Name: *
Value: <YOUR_SERVER_IP>
TTL: Auto / 300
```
Also ensure an SSL certificate covers wildcard subdomains (e.g. Let's Encrypt wildcard certificate for `*.increditsolutions.in` and `increditsolutions.in`).

### 2. Tenant Configuration (`config/tenants.php` & `config/tenants.local.php`)
Tenant configurations map subdomains to individual MySQL credentials. Keep production secrets in `config/tenants.local.php` (git-ignored):

```php
<?php
// config/tenants.local.php
return [
    'acme' => [
        'company_name' => 'Acme Financial Services',
        'db_host'      => 'localhost',
        'db_port'      => 3306,
        'db_name'      => 'callnow_acme',
        'db_user'      => 'acme_user',
        'db_pass'      => 'SuperSecretPassword!',
        'active'       => true,
    ],
    'zenith' => [
        'company_name' => 'Zenith Capital Corp',
        'db_host'      => '127.0.0.1',
        'db_port'      => 3306,
        'db_name'      => 'callnow_zenith',
        'db_user'      => 'zenith_user',
        'db_pass'      => 'AnotherSecret123!',
        'active'       => true,
    ],
];
```

If a visitor navigates to the root domain (`increditsolutions.in` or `localhost`), the system automatically uses the `'default'` configuration defined in `.env`.

---

## Provisioning a New Company (CLI Tool)

To onboard a new company, use the automated CLI provisioning utility. It creates the MySQL database, executes the baseline production schema, configures the tenant, and prepares Super Admin access in one step:

```bash
php bin/provision_tenant.php \
  --slug=zenith \
  --name="Zenith Capital" \
  --dbname=callnow_zenith \
  --dbuser=root \
  --dbpass="YourDbPassword" \
  --dbhost=localhost \
  --dbport=3306 \
  --admin-email=admin@callnow.com \
  --admin-pass=Admin@CallNow2026!
```

### CLI Parameters

| Option | Required | Default | Description |
|---|---|---|---|
| `--slug` | **Yes** | — | Unique company subdomain slug (e.g. `zenith` for `zenith.increditsolutions.in`) |
| `--name` | **Yes** | — | Company business name displayed on titles and reports |
| `--dbname` | **Yes** | — | MySQL database name for this company (e.g. `callnow_zenith`) |
| `--dbuser` | **Yes** | — | MySQL database username |
| `--dbpass` | No | `""` | MySQL database password |
| `--dbhost` | No | `127.0.0.1` | MySQL server host / IP |
| `--dbport` | No | `3306` | MySQL server port |
| `--admin-email` | No | `admin@callnow.com` | Super Admin login email |
| `--admin-pass` | No | `Admin@CallNow2026!` | Super Admin login password |

---

## Baseline Database Schema (`database/production_schema.sql`)

The baseline schema file `database/production_schema.sql` is 100% self-contained and idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`). It creates all 27 tables with proper indexes, constraints, and utf8mb4 collation:

### Core Tables
- `users`: Telecallers, supervisors, managers, admins, and pre-seeded Super Admin.
- `roles`: 5 hierarchical roles (`Super Admin`, `Admin`, `Manager`, `Supervisor`, `Officer`).
- `permissions`: 12 system permissions keys (`manage_users`, `manage_teams`, `manage_settings`, `view_activity`, `manage_database`, `view_reports`, `upload_data`, `manage_leads`, `export_data`, `edit_records`, `delete_records`, `manage_super_admin`).
- `role_permissions`: System-seeded access matrix.
- `user_permissions`: Per-user granular permission overrides.
- `teams`: Team hierarchies with supervisor and member tracking.
- `password_reset_tokens`: SHA256 hashed 6-digit OTPs and reset tokens for mobile & web.

### Customer & Lead Management
- `main_database` & `main_database_archive`: Permanent verified customer contacts.
- `temporary_database`: Raw uncalled data batches with cooldown tracking.
- `leads_table`: CRM leads with loan types, partner banks (`target_bank`), net salary, salary account, priority, pipeline stage, and follow-up timestamps.
- `lead_notes`: Threaded history notes per lead.
- `lead_followups`: Scheduled call reminders with user isolation.
- `lead_assignments`: Reassignment audit logs.
- `contact_lead_conversions`: Traceability linking calls to converted leads.

### Calling & Reporting
- `call_logs`: Telemetry logs with talk duration, timing classification (`CONNECTED_CONVERSATION`, `CONNECTED_SHORT`, `NOT_CONNECTED`), SIM slot, audio recordings, and idempotency request hashes.
- `reporting_targets`: Daily employee calling targets and talk-time quotas.
- `activity_log`: Detailed audit trail of all CRUD actions.
- `api_tokens` & `api_access_logs`: Bearer token mobile authentication and rate limiting.
- `super_admin_plans`, `super_admin_account`, `super_admin_limit_logs`: Subscription limits.

---

## Default Super Admin Credentials

Every provisioned database automatically includes the Super Admin:

| Field | Value |
|---|---|
| **Role** | `Super Admin` (ID = 1, full bypass of all permissions) |
| **Login ID / Email** | `admin@callnow.com` |
| **Default Password** | `Admin@CallNow2026!` |

> **Security Note:** Log in to each company's admin dashboard upon setup and update the Super Admin password under **Profile Settings**.

---

## Mobile App Multi-Tenant Connection

The Flutter mobile app (`callnowMobileApp`) seamlessly connects to any company's backend:

1. Open the Mobile App.
2. Tap the **Server Settings** icon (or go to **Settings** → **API Server Endpoint**).
3. In the **Base API Address** field, enter either:
   - **Company Code**: e.g. `zenith` (automatically resolves to `https://zenith.increditsolutions.in/api/v1`)
   - **Full Custom URL**: e.g. `https://zenith.increditsolutions.in/api/v1` or `https://crm.customclient.com/api/v1`
4. Tap **Test Connection** to verify server connectivity.
5. Tap **Save & Apply Server Address**.

---

## Testing & Quality Assurance

The codebase includes an automated audit and regression test suite:

```powershell
# Run the complete project audit suite (9 suites, 25 pages):
& "C:\xampp\php\php.exe" tests\run_project_audit.php

# Run multi-tenant subdomain resolution test:
& "C:\xampp\php\php.exe" tests\test_tenant_resolution.php

# Run multi-tenant database connection test:
& "C:\xampp\php\php.exe" tests\test_multi_tenant_connection.php

# Run baseline production schema import test:
& "C:\xampp\php\php.exe" tests\test_production_schema.php

# Run automated tenant provisioning CLI test:
& "C:\xampp\php\php.exe" tests\test_tenant_provisioning.php

# Verify Flutter mobile app syntax & static analysis:
dart analyze e:\Projects\FlutterProjects\callnowMobileApp
```

---

## Production Security Best Practices

1. **Error Shielding**: Keep `APP_DEBUG=0` in `.env` or server environment variables so sensitive stack traces and database credentials are never displayed to end users.
2. **Session Isolation**: Each company subdomain uses an isolated session name (`CALLNOW_<hash>`) and cookie domain (`domain: <host>`) preventing session bleed.
3. **Database Credentials**: Store sensitive database passwords in `config/tenants.local.php` or environment variables; never commit credentials into version control.
4. **HTTPS Enforcement**: Always enforce HTTPS / SSL across all tenant subdomains to protect user passwords, borrower information, and Bearer API tokens in transit.

---

## Author & Ownership

**Sangam Mahale**  
Incraax AI Automation Pvt Ltd — All rights reserved.
