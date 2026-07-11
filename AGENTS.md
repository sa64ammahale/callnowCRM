# AGENTS.md — Opencode Instructions for CallNow CRM

## Context File
**Read `project-context/CODEBASE_SUMMARY.md` before making any changes to this project.**
It documents the file inventory, security state, conventions, and known issues. Keep it updated when you make significant changes.

## Tech Stack
PHP 8.x procedural, MySQL (`mysqli`), Bootstrap 5.3, jQuery, DataTables. No framework. File-based routing.

## Commands
- PHP syntax check (per file): `php -l <file>`
- Batch lint: `Get-ChildItem -Recurse -Include *.php | ForEach-Object { php -l $_.FullName }`

## Conventions
- **Auth**: `require_once __DIR__ . '/php_scripts/auth.php';` at top of protected pages.
- **Role checks**: `requireRole('Admin')`, `isAdmin()`, `isManager()`, etc. (defined in `auth.php`).
- **CSRF**: `ensureCsrfToken()` runs automatically in auth.php. Use `<?= csrfField() ?>` in forms; `verifyCsrfToken($_POST['csrf_token'] ?? '')` on POST handlers. For AJAX, send `csrf_token` in the request body.
- **DB**: Prepared statements only. Never concatenate user input. Use `$link->prepare()` + `bind_param`.
- **Output**: `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`.
- **Activity log**: `logActivity($link, $type, $description)`.
- **URLs**: `url('path')` helper or `APP_BASE . 'path'`.
- **Charset**: utf8mb4 set globally in config.php.
- **Comments**: Do not add comments unless explicitly requested.
- **Debug**: `display_errors` on only when `APP_DEBUG=1` env var set.