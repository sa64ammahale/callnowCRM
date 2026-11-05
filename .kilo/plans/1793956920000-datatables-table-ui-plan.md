# Plan: DataTables rollout for plain HTML tables

**Goal:** Replace plain HTML tables with DataTables on the remaining table pages for better display, keeping every current feature (search, copy, download/CSV, Excel, print, pagination, filters) on the same page.

**Constraint (strict):** No application-logic or app-flow change. All existing URLs, GET/POST parameters, CSRF, permission gates, delete/restore/export behavior stay unchanged. DataTables is purely a presentation layer.

**Reference implementation (already in project, do not alter):**
- `modules/database/data_management_main.php` — server-side DataTables, buttons copy/csv/excel/print (+ custom Export Full DB)
- `modules/database/data_management_temporary.php` — server-side DataTables, buttons copy/csv/excel (dropdown)
- Assets vendored locally at `assets/vendor/dt/` — JS only; NO `dataTables.bootstrap5.min.css` (see Decision 5)
- Asset helper `vnd()` defined in `php_scripts/config.php:78` → `APP_BASE . '/assets/vendor/' . path`
- DataTables pages include scripts inline at page bottom (before `footer.php` include), not in `footer.php`

---

## Confirmed decisions

1. **Scope:** Add DataTables to the four plain-table pages:
   - `modules/users/users_view.php` — client-side DataTables (render all filtered users; remove PHP `LIMIT/OFFSET` pagination nav)
   - `modules/logs/call_history.php` — server-side via new additive AJAX endpoint
   - `modules/database/ViewDND.php` — server-side via new additive AJAX endpoint
   - `modules/logs/manage_activity.php` — server-side via new additive AJAX endpoint
   - Out of scope: `modules/database/add_update_status.php` 8-row sample table; already-DT pages (`data_management_main.php`, `data_management_temporary.php`)

2. **Processing mode:** Mixed.
   - Client-side (`users_view.php`): PHP renders all filtered rows server-side (no server pagination), DataTables enhances in place (`serverSide: false`). Zero new PHP endpoint.
   - Server-side (other 3): existing PHP SELECT/COUNT/WHERE logic kept as no-JS fallback HTML table; new `*_data.php` endpoint returns DataTables JSON `{draw, recordsTotal, recordsFiltered, data}`; DataTables `serverSide: true` overrides static rows via AJAX.

3. **Buttons:** copy, csv, excel, print per table. PDF excluded. Custom "export all" reuses an existing endpoint only where it already exists (`manage_activity.php?export=csv`). Pages without an existing export endpoint use DataTables csv/excel buttons configured to export all rows via the server-side endpoint (`length=-1`), so no new export logic is added.

4. **Search:** Existing PHP server-side filters (status, date range, source, role, team, package) remain and narrow the set before rendering. DataTables global search layered on top. For server-side tables, the endpoint reads the same `$_GET` filter params the page uses, so filter state is preserved across AJAX draws.

5. **CSS:** No DataTables CSS vendored (assets/vendor/dt/ holds JS only) and no CDN links (shared-hosting constraint). Decision: DataTables is functional without its CSS; use Bootstrap 5 table classes (`table table-hover align-middle`) already on every table, plus minimal page-specific `<style>` blocks for DataTables-specific polish (sort indicator arrows, pagination/button alignment, length-menu spacing). Do NOT vendor or download CSS files (mutating/download not permitted in plan mode). If the implementation agent wants vendored CSS, that's a follow-up task outside this plan.

6. **JS include location:** Per-page `<script>` block at page bottom (before `<?php include footer.php ?>`), matching existing convention. Do NOT add to `footer.php` (avoids loading heavy JS on non-table pages and double-includes on already-DT pages).

7. **CSRF:** GET read-only DataTables endpoints require NO CSRF token (consistent with `maindatabase_ajax/datatable.php` precedent; read-only). All mutating POST actions (delete/restore/assign/export-form) keep existing CSRF unchanged.

8. **Double-init guard:** Wrap each init in `if (!$.fn.dataTable.isDataTable('#id'))` with `destroy: true`.

9. **Export-all per page:**
   - `call_history.php` — DataTables csv/excel buttons with `exportOptions: { modifier: { page: 'all' } }` (full export via endpoint; call_history has no existing export endpoint, so no custom "Export All" button added).
   - `manage_activity.php` — custom "Export All" → `?export=csv` (existing endpoint).
   - `ViewDND.php` — DataTables csv/excel buttons with `exportOptions: { modifier: { page: 'all' } }` (full export via endpoint; ViewDND has no existing export endpoint).
   - `users_view.php` — DataTables csv/excel buttons (client-side, all rendered rows).

---

## AJAX endpoints to create (additive, GET-only, read-only)

| Endpoint | Serves | Reuses logic from |
|---|---|---|
| `modules/logs/call_history_data.php` | `call_history.php` | existing SELECT/COUNT/WHERE + role scoping (Supervisor/Officer) + `$_GET` filters |
| `modules/database/ViewDND_data.php` | `ViewDND.php` | existing DND union (TEMP+MAIN) SELECT/COUNT + search |
| `modules/logs/manage_activity_data.php` | `manage_activity.php` | existing activity-log SELECT/COUNT/WHERE + filters + role scoping |

Each endpoint: reads DataTables params (`draw`, `start`, `length`, `search[value]`, `order`) plus existing `$_GET` filters, builds prepared-statement queries mirroring the page's existing logic, echoes JSON `{draw, recordsTotal, recordsFiltered, data}`. Exits before any HTML output. No session/auth change beyond the page's existing `requirePermission`. Support `length=-1` returning all rows (for ViewDND full export).

**Column order:** Each endpoint must return `data` arrays whose column order matches the DataTable `<th>` order on its page (including the checkbox column if present). Sort `order[0].column` index maps to that same column order. Do not reorder or omit columns between the static HTML table and the JSON response.

---

## Implementation task list

### Task 1 — DataTables CSS (inline, no vendor download)
- No CSS files vendored; no CDN. DataTables works with Bootstrap 5 table classes (`table table-hover align-middle`) already on every table.
- Add minimal page-specific `<style>` blocks for DataTables polish: sort indicator arrows (`.sorting`, `.sorting_asc`, `.sorting_desc`), pagination/button alignment, length-menu spacing. Keep each block small and page-local.
- Do NOT download or vendor CSS files (mutating/download not permitted in plan scope).

### Task 2 — `modules/users/users_view.php` (client-side)
- Render all filtered users server-side (drop PHP `LIMIT/OFFSET` and bottom pagination nav); keep filter form (GET) narrowing the set.
- Two DataTable instances: `#usersTable-active` (Active card) and `#usersTable-inactive` (Inactive/Suspended card), each `serverSide: false`, `paging: true`, `searching: true`, ordering, buttons copy/csv/excel/print.
- Replace Table2Excel export button with DataTables buttons; keep Add User + Teams Hub buttons.
- Keep per-row edit links, protected-badge logic, escape output unchanged.
- On inactive-section show, call `$('#usersTable-inactive').DataTable().columns.adjust()` to fix sizing on previously-hidden table.

### Task 3 — `modules/logs/call_history.php` (server-side)
- Keep existing PHP HTML table + pagination as no-JS fallback.
- Add `modules/logs/call_history_data.php` endpoint (GET, DataTables JSON).
- Add id `#callHistoryTable` to `<table>`; init `serverSide: true`, `ajax` → endpoint, buttons copy/csv/excel/print with `exportOptions: { modifier: { page: 'all' } }` on csv/excel (full export via endpoint; call_history has no existing export endpoint, so no new export logic added).
- Keep filter form (source/status/date/search), badges, audio playback, detail drill-down, no-JS pagination unchanged.

### Task 4 — `modules/database/ViewDND.php` (server-side)
- Keep existing PHP HTML table + pagination + source tabs + restore/delete forms as no-JS fallback.
- Add `modules/database/ViewDND_data.php` endpoint (GET, DataTables JSON over DND union).
- Add id `#viewDndTable` to `<table>`; init `serverSide: true`, `ajax` → endpoint, buttons copy/csv/excel/print with `exportOptions: { modifier: { page: 'all' } }` on csv/excel (full export via endpoint; ViewDND has no existing export endpoint, so no new export logic added).
- Keep Add DND modal, source filter tabs, search, restore/unmark/delete forms, CSRF on POST forms unchanged.

### Task 5 — `modules/logs/manage_activity.php` (server-side)
- Keep existing PHP HTML table + bulk-delete forms + view modal + pagination as no-JS fallback.
- Add `modules/logs/manage_activity_data.php` endpoint (GET, DataTables JSON).
- Init activity table `serverSide: true`, `ajax` → endpoint, buttons copy/csv/excel/print + custom "Export All" → `?export=csv`.
- Keep filter form, single-delete, bulk-delete, view modal, role-based bulk-delete gate, CSV export link unchanged.

### Task 6 — Verification
- `php -l` on every edited/added PHP file (batch lint).
- Load each page as Admin / Manager / Supervisor / Officer; confirm table renders, search/sort/pagination work, copy/csv/excel/print fire, existing delete/restore/bulk-delete/export actions still work, no JS console errors.
- Confirm no-JS fallback (JS disabled) still paginates/filters/exports.
- Confirm RBAC gates unchanged per role (`can()` / `requirePermission`).

---
## Rollout / migration path
- This is a pure presentation-layer change: no DB schema, no permission, no CSRF, no URL changes. Deploy the 4 edited pages + 3 new `*_data.php` endpoints together.
- Because every page keeps its full server-rendered HTML table + pagination as a no-JS fallback, each page degrades gracefully if DataTables JS fails to load; rollback is simply reverting the page file(s).
- New `*_data.php` endpoints are additive (no existing file references them), so they cannot break any current page until the page's DataTables init is enabled.
- Rollout can be done page-by-page; no coordinated release required across modules.
- No data migration needed (no schema changes).

---
## Risks & mitigation

| Risk | Mitigation |
|---|---|
| Missing DataTables CSS (vendored JS only) | Use Bootstrap 5 table classes + page-specific `<style>` for sort/pagination polish; no CDN, no vendored download in plan scope |
| Double initialization / script double-load | Per-page script blocks; `if (!$.fn.dataTable.isDataTable('#id'))` + `destroy: true`; not in `footer.php` |
| Server-side endpoint wrong JSON shape | Match `{draw, recordsTotal, recordsFiltered, data}` exactly like `maindatabase_ajax/datatable.php` |
| DT buttons export only current page (server-side) | Wire custom "export all" buttons to existing full-export endpoints; ViewDND uses endpoint `length=-1` all-rows |
| Filter state lost across AJAX draws | Endpoint reads same `$_GET` filters the page uses |
| users_view two tables + collapsible inactive section | Call `columns.adjust()` on section show; init both tables |
| Existing Table2Excel + DT buttons redundant on users_view | Replace Table2Excel button with DT buttons; one export mechanism |
| Breaking no-JS fallback | Keep existing server-rendered HTML rows + pagination; DT overrides only when JS enabled |
| CSRF on GET endpoints | Not required (read-only); mutating POST forms keep existing CSRF |

---

## Out of scope
- `add_update_status.php` sample table
- New feature logic, permission changes, CSRF changes, DB schema changes
- PDF export button, CDN assets, backend logic changes
- Altering already-DT pages (`data_management_main.php`, `data_management_temporary.php`)

---

## Validation checklist (implementation agent)
- [ ] `php -l` passes for all touched/added PHP files
- [ ] Each table page loads without JS console errors
- [ ] Search, sort, pagination, copy, csv, excel, print functional per page
- [ ] Existing delete/restore/bulk-delete/export actions unchanged
- [ ] No-JS fallback still works (pagination, filters, export)
- [ ] RBAC gates unchanged per role (Admin/Manager/Supervisor/Officer)
- [ ] DataTables CSS included on all DataTables pages (no broken button/table layout)
