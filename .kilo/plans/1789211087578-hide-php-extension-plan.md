# Hide .php Extension — Implementation Plan

## Goal
Serve all page URLs without the `.php` extension while keeping the existing filesystem and PHP behavior intact. Do not rename or move PHP files. Do not break existing API routes or POST form submissions.

## Current State
- `.htaccess` already exists at project root with rewrite rules, HTTPS enforcement, and API router rules for `/api/v1/auth`, `/api/v1/admin`, and `/api/v1/telecaller`.
- `php_scripts/header.php` defines a `url(string $path)` helper used across views for link generation.
- Many PHP files use `header("Location: ...")` with hard-coded `.php` paths.
- Some JS uses `window.location.href = "..."` and `fetch('...php')`.
- PHP `require_once`/`include` paths are filesystem paths and must remain unchanged.

## Approach
Use Apache `mod_rewrite` to:
1. 301 redirect `/page.php` → `/page` for GET requests
2. Internally rewrite `/page` → `/page.php` when the physical `.php` file exists
3. Leave POST form submissions to `action="something.php"` untouched so request body data is preserved

Then update the `url()` helper to strip `.php` so all existing link generations become clean automatically.

## Decisions
- **No file renames.** Keep all `.php` files as-is.
- **Do not change `require_once`/`include` paths.** They reference the filesystem and must keep `.php`.
- **API routes remain unchanged.** The general rewrite is placed after the existing API `RewriteRule` lines, and API paths are already clean (`/api/v1/...`).
- **POST forms with `.php` action remain functional.** The canonical redirect rule is gated on `GET` only, so POST submissions hit the physical file directly.

## Implementation Steps
1. Update `.htaccess`:
   - Add the `.php` hiding rewrite block inside the existing `<IfModule mod_rewrite.c>` block, after the API router rules and before the sensitive-file protection rules.
   - Add a safeguard redirect for `index.php` to `/` if desired, but keep it scoped to `GET`.

2. Update `php_scripts/header.php`:
   - Modify `url()` to strip a trailing `.php` from the generated path.

3. Audit and optionally update hard-coded redirects:
   - Search for `header("Location: ...php")` calls that do not use `url()`.
   - Update them to use `url()` or strip `.php` manually.
   - This is optional for functionality, but recommended for consistency.

4. Audit and optionally update JS references:
   - `window.location.href = "...php"`
   - `fetch('...php')`
   - `form action="...php"`
   - These continue to work against physical files; update only if fully clean URLs are desired.

## Affected Boundaries
- `.htaccess` (root)
- `php_scripts/header.php`
- Any PHP file using `header("Location: ...")` with a literal `.php` path
- Any JS or HTML referencing `.php` URLs

## Validation Plan
- Visit `/` and confirm `index.php` loads.
- Visit `/dashboard` and confirm it loads and the browser address bar shows no `.php`.
- Visit `/dashboard.php` and confirm it 301 redirects to `/dashboard`.
- Submit a POST form (e.g., login, lead create) and confirm it still processes correctly.
- Call API endpoints (`/api/v1/auth/login`, `/api/v1/telecaller/...`) and confirm they still return expected responses.
- Run `php -l` on modified PHP files.

## Out of Scope
- Renaming files or directories
- Changing database schema
- Changing API response formats
- Modifying `require_once`/`include` paths
