<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php';

requirePermission('manage_TBL_USERS');

$limit  = 50;
$page   = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$search  = trim($_GET['search'] ?? '');
$status  = $_GET['status'] ?? '';
$role    = $_GET['role'] ?? '';
$team_id = $_GET['team'] ?? '';
$package = $_GET['package'] ?? '';

$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($search !== '') {
    $where .= " AND (u.NAME LIKE ? OR u.MOBILE LIKE ? OR u.LOGIN_ID LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s);
    $types .= "sss";
}
if ($status && in_array($status, ['Active','Inactive','Suspended'])) {
    $where .= " AND u.STATUS = ?";
    $params[] = $status;
    $types   .= "s";
}
if ($role) {
    $where .= " AND u.ROLE = ?";
    $params[] = $role;
    $types   .= "s";
}
if ($team_id !== '' && is_numeric($team_id)) {
    $where .= " AND u.TEAM_ID = ?";
    $params[] = $team_id;
    $types   .= "i";
}
if ($package) {
    $where .= " AND u.PACKAGE = ?";
    $params[] = $package;
    $types   .= "s";
}

$countSql = "SELECT COUNT(*) FROM TBL_USERS u $where";
$stmt = mysqli_prepare($link, $countSql);
if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$totalRecords = mysqli_stmt_get_result($stmt)->fetch_row()[0];
$totalPages   = max(1, ceil($totalRecords / $limit));

$sql = "
    SELECT
        u.*,
        t.NAME as TEAM_NAME
    FROM TBL_USERS u
    LEFT JOIN TBL_TEAMS t ON u.TEAM_ID = t.ID
    $where
    ORDER BY
        CASE WHEN u.STATUS = 'Active' THEN 0 ELSE 1 END,
        u.JOIN_DATE DESC, u.ID DESC
    LIMIT ? OFFSET ?
";

$stmt = mysqli_prepare($link, $sql);
$finalParams   = $params;
$finalParams[] = $limit;
$finalParams[] = $offset;
$types        .= "ii";

mysqli_stmt_bind_param($stmt, $types, ...$finalParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$TBL_USERS = [];
while ($row = mysqli_fetch_assoc($result)) {
    $TBL_USERS[] = $row;
}

$active_TBL_USERS = [];
$other_TBL_USERS  = [];
foreach ($TBL_USERS as $u) {
    if ($u['STATUS'] === 'Active') {
        $active_TBL_USERS[] = $u;
    } else {
        $other_TBL_USERS[] = $u;
    }
}

$TBL_ROLES    = mysqli_fetch_all(mysqli_query($link, "SELECT DISTINCT ROLE FROM TBL_USERS WHERE ROLE IS NOT NULL ORDER BY ROLE"), MYSQLI_ASSOC);
$TBL_TEAMS    = mysqli_fetch_all(mysqli_query($link, "SELECT ID, NAME FROM TBL_TEAMS ORDER BY NAME"), MYSQLI_ASSOC);
$packages = mysqli_fetch_all(mysqli_query($link, "SELECT DISTINCT PACKAGE FROM TBL_USERS WHERE PACKAGE IS NOT NULL AND PACKAGE != '' ORDER BY PACKAGE"), MYSQLI_ASSOC);

// Build role description lookup from TBL_ROLES table
$roleDesc = [];
$rd = mysqli_query($link, "SELECT role_name, description FROM TBL_ROLES");
if ($rd) while ($r = mysqli_fetch_assoc($rd)) $roleDesc[$r['role_name']] = $r['description'];

$totalActive = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM TBL_USERS WHERE STATUS='Active'"))[0];
$totalInactive = $totalRecords - $totalActive;

?>
<?php $pageTitle = 'App TBL_USERS - CallNow Admin'; include '../../php_scripts/header.php'; ?>

<style>
:root {
    --uv-accent: var(--accent);
    --uv-accent-dark: var(--accent-hover, #4f46e5);
    --uv-ink: #1e1b4b;
    --uv-ink-soft: #6b6890;
    --uv-soft: #f0f2ff;
    --uv-border: #e2e4f0;
}

/* ── Header ── */
.uv-header {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
    border-radius: 1rem;
    padding: 1.75rem 2rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.uv-header::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.3;
}
.uv-header-content {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}
.uv-header-left h1 {
    font-size: 1.35rem;
    font-weight: 700;
    color: #fff;
    margin: 0 0 0.25rem 0;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.uv-header-left h1 i { font-size: 1.4rem; }
.uv-header-left p {
    color: rgba(255,255,255,0.75);
    font-size: 0.8125rem;
    margin: 0;
}
.uv-header-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.uv-header-actions .btn {
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.2);
    color: #fff;
    backdrop-filter: blur(4px);
    font-size: 0.75rem;
    padding: 0.4rem 0.9rem;
    border-radius: 0.5rem;
    transition: all 0.15s ease;
}
.uv-header-actions .btn:hover {
    background: rgba(255,255,255,0.25);
    border-color: rgba(255,255,255,0.35);
    color: #fff;
    transform: translateY(-1px);
}
.uv-header-actions .btn-uv-primary {
    background: #fff;
    border-color: #fff;
    color: var(--uv-accent-dark);
}
.uv-header-actions .btn-uv-primary:hover {
    background: rgba(255,255,255,0.9);
    color: var(--uv-accent-dark);
}

/* ── Stat chips ── */
.uv-stats {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 1rem;
    position: relative;
    z-index: 1;
}
.uv-stat {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 0.625rem;
    padding: 0.5rem 1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    backdrop-filter: blur(4px);
}
.uv-stat-icon {
    width: 34px; height: 34px;
    border-radius: 0.5rem;
    background: rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1rem;
}
.uv-stat-info .uv-stat-num {
    font-size: 1rem; font-weight: 700; color: #fff; line-height: 1.2;
}
.uv-stat-info .uv-stat-label {
    font-size: 0.6875rem; color: rgba(255,255,255,0.7); line-height: 1;
}

/* ── Filter bar ── */
.uv-filters {
    background: var(--uv-soft);
    border: 1px solid var(--uv-border);
    border-radius: 0.75rem;
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
}
.uv-filters .form-control, .uv-filters .form-select {
    font-size: 0.8125rem;
    border-color: var(--uv-border);
    background: #fff;
    color: var(--uv-ink);
    border-radius: 0.5rem;
    padding: 0.375rem 0.75rem;
}
.uv-filters .form-control:focus, .uv-filters .form-select:focus {
    border-color: var(--uv-accent);
    box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
}
.uv-filters label {
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--uv-ink-soft);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 0.2rem;
}

/* ── Table card ── */
.uv-card {
    background: #fff;
    border: 1px solid var(--uv-border);
    border-radius: 0.875rem;
    margin-bottom: 1.25rem;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.uv-card-header {
    background: linear-gradient(135deg, #f8f9ff 0%, #f0f2ff 100%);
    border-bottom: 1px solid var(--uv-border);
    padding: 0.75rem 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.uv-card-header-left {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--uv-ink);
}
.uv-card-header-left .uv-badge-count {
    font-size: 0.6875rem;
    font-weight: 600;
    background: var(--uv-soft);
    color: var(--uv-ink-soft);
    padding: 0.125rem 0.5rem;
    border-radius: 999px;
    border: 1px solid var(--uv-border);
}
.uv-card-header-toggle {
    background: none;
    border: 1px solid var(--uv-border);
    padding: 0.25rem 0.6rem;
    border-radius: 0.375rem;
    font-size: 0.75rem;
    color: var(--uv-ink-soft);
    cursor: pointer;
    transition: all 0.12s ease;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}
.uv-card-header-toggle:hover {
    background: var(--uv-soft);
    border-color: #c7c9e0;
    color: var(--uv-ink);
}
.uv-card-body { padding: 0; }

/* ── Table ── */
.uv-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.8125rem;
    margin: 0;
}
.uv-table thead th {
    background: #fafbff;
    color: var(--uv-ink-soft);
    font-weight: 600;
    font-size: 0.6875rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0.625rem 0.875rem;
    border-bottom: 1px solid var(--uv-border);
    white-space: nowrap;
}
.uv-table tbody td {
    padding: 0.625rem 0.875rem;
    border-bottom: 1px solid #f0f1f8;
    color: var(--uv-ink);
    vertical-align: middle;
}
.uv-table tbody tr:last-child td { border-bottom: none; }
.uv-table tbody tr {
    transition: background 0.1s ease;
}
.uv-table tbody tr:hover {
    background: #f8f9ff;
}
.uv-table tbody tr.uv-row-inactive td {
    color: var(--uv-ink-soft);
}

/* ── User ID badge ── */
.uv-id {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-weight: 600;
    font-size: 0.75rem;
    color: var(--uv-accent);
    background: var(--uv-soft);
    padding: 0.15rem 0.55rem;
    border-radius: 0.375rem;
    border: 1px solid #dde0f5;
}

/* ── Name cell ── */
.uv-name {
    display: flex;
    align-items: center;
    gap: 0.625rem;
}
.uv-avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--uv-accent), #8b5cf6);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.6875rem;
    font-weight: 700;
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(99,102,241,0.2);
}
.uv-name-text {
    font-weight: 600;
    color: var(--uv-ink);
}
.uv-name-sub {
    font-size: 0.6875rem;
    color: var(--uv-ink-soft);
}

/* ── Badges ── */
.uv-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.2rem 0.65rem;
    border-radius: 999px;
    font-size: 0.6875rem;
    font-weight: 600;
    white-space: nowrap;
}
.uv-badge-success {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
    border: 1px solid #6ee7b7;
}
.uv-badge-danger {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
    border: 1px solid #fca5a5;
}
.uv-badge-warning {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
    border: 1px solid #fcd34d;
}
.uv-badge-secondary {
    background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
    color: #4b5563;
    border: 1px solid #d1d5db;
}
.uv-badge-role {
    background: linear-gradient(135deg, #ede9fe, #ddd6fe);
    color: #5b21b6;
    border: 1px solid #c4b5fd;
}
.uv-badge-team {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
    border: 1px solid #93c5fd;
}
.uv-badge-package {
    background: linear-gradient(135deg, #fce7f3, #fbcfe8);
    color: #9d174d;
    border: 1px solid #f9a8d4;
}

/* ── Action button ── */
.uv-action {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.3rem 0.75rem;
    border-radius: 0.375rem;
    font-size: 0.6875rem;
    font-weight: 600;
    border: 1px solid var(--uv-border);
    background: #fff;
    color: var(--uv-ink-soft);
    text-decoration: none;
    transition: all 0.12s ease;
}
.uv-action:hover {
    background: var(--uv-soft);
    border-color: var(--uv-accent);
    color: var(--uv-accent);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(99,102,241,0.12);
}

/* ── Empty state ── */
.uv-empty {
    text-align: center;
    padding: 2.5rem 1rem;
    color: var(--uv-ink-soft);
}
.uv-empty-icon {
    font-size: 2.5rem;
    color: #d4d6e8;
    margin-bottom: 0.5rem;
}
.uv-empty-text {
    font-size: 0.875rem;
    margin-bottom: 0;
}

/* ── Pagination ── */
.uv-pagination {
    display: flex;
    justify-content: center;
    gap: 0.25rem;
    padding: 1rem 0 0.5rem;
}
.uv-page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 32px;
    padding: 0 0.5rem;
    border-radius: 0.5rem;
    border: 1px solid var(--uv-border);
    background: #fff;
    color: var(--uv-ink-soft);
    font-size: 0.75rem;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.12s ease;
}
.uv-page-link:hover {
    background: var(--uv-soft);
    border-color: #c7c9e0;
    color: var(--uv-ink);
}
.uv-page-link.uv-active {
    background: linear-gradient(135deg, var(--uv-accent), var(--uv-accent-dark));
    border-color: var(--uv-accent);
    color: #fff;
    box-shadow: 0 2px 8px rgba(99,102,241,0.25);
}
.uv-page-link.uv-disabled {
    opacity: 0.4;
    pointer-events: none;
}

/* ── Export spinner ── */
@keyframes uv-spin { to { transform: rotate(360deg); } }
.uv-spinning i {
    animation: uv-spin 0.8s linear infinite;
}

/* ── Responsive ── */
@media (max-width: 767px) {
    .uv-header { padding: 1.25rem; }
    .uv-header-content { flex-direction: column; align-items: stretch; }
    .uv-header-actions { justify-content: stretch; }
    .uv-header-actions .btn { flex: 1; justify-content: center; }
    .uv-stats { gap: 0.5rem; }
    .uv-stat { flex: 1; min-width: 0; padding: 0.4rem 0.75rem; }
    .uv-table thead { display: none; }
    .uv-table tbody td {
        display: flex;
        padding: 0.375rem 0.75rem;
        border-bottom: 1px solid #f0f1f8;
    }
    .uv-table tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        font-size: 0.6875rem;
        color: var(--uv-ink-soft);
        width: 75px;
        flex-shrink: 0;
    }
    .uv-table tbody tr {
        display: block;
        padding: 0.625rem 0;
        border-bottom: 1px solid var(--uv-border);
    }
    .uv-table tbody tr:last-child { border-bottom: none; }
}
</style>

<div class="container page-wrapper">

    <!-- ─── Header ─── -->
    <div class="uv-header">
        <div class="uv-header-content">
            <div class="uv-header-left">
                <h1><i class="bi bi-people-fill"></i> App TBL_USERS Management</h1>
                <p><?= number_format($totalRecords) ?> total &middot; Page <?= $page ?> of <?= $totalPages ?></p>
            </div>
            <div class="uv-header-actions">
                <a href="<?= url('modules/TBL_USERS/TBL_USERS_add.php') ?>" class="btn btn-uv-primary">
                    <i class="bi bi-person-plus"></i> Add User
                </a>
                <button id="exportBtn" class="btn btn-outline-primary">
                    <i class="bi bi-file-earmark-excel"></i> Export
                </button>
                <a href="<?= url('dashboard.php') ?>" class="btn">
                    <i class="bi bi-grid"></i> Dashboard
                </a>
            </div>
        </div>
        <div class="uv-stats">
            <div class="uv-stat">
                <div class="uv-stat-icon"><i class="bi bi-people"></i></div>
                <div class="uv-stat-info">
                    <div class="uv-stat-num"><?= number_format($totalRecords) ?></div>
                    <div class="uv-stat-label">Total</div>
                </div>
            </div>
            <div class="uv-stat">
                <div class="uv-stat-icon"><i class="bi bi-person-check"></i></div>
                <div class="uv-stat-info">
                    <div class="uv-stat-num"><?= number_format($totalActive) ?></div>
                    <div class="uv-stat-label">Active</div>
                </div>
            </div>
            <div class="uv-stat">
                <div class="uv-stat-icon"><i class="bi bi-person-dash"></i></div>
                <div class="uv-stat-info">
                    <div class="uv-stat-num"><?= number_format($totalInactive) ?></div>
                    <div class="uv-stat-label">Inactive</div>
                </div>
            </div>
            <div class="uv-stat">
                <div class="uv-stat-icon"><i class="bi bi-shield-check"></i></div>
                <div class="uv-stat-info">
                    <div class="uv-stat-num"><?= count($TBL_ROLES) ?></div>
                    <div class="uv-stat-label">TBL_ROLES</div>
                </div>
            </div>
            <div class="uv-stat">
                <div class="uv-stat-icon"><i class="bi bi-diagram-3"></i></div>
                <div class="uv-stat-info">
                    <div class="uv-stat-num"><?= count($TBL_TEAMS) ?></div>
                    <div class="uv-stat-label">TBL_TEAMS</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Filters ─── -->
    <div class="uv-filters">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-lg-3">
                <label>Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Name, Mobile or Login ID"
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-lg-2">
                <label>Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="Active"    <?= $status==='Active'?'selected':'' ?>>Active</option>
                    <option value="Inactive"  <?= $status==='Inactive'?'selected':'' ?>>Inactive</option>
                    <option value="Suspended" <?= $status==='Suspended'?'selected':'' ?>>Suspended</option>
                </select>
            </div>
            <div class="col-lg-2">
                <label>Role</label>
                <select name="role" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach($TBL_ROLES as $r): ?>
                        <option value="<?= $r['ROLE'] ?>" <?= $role==$r['ROLE']?'selected':'' ?>>
                            <?= $r['ROLE'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2">
                <label>Team</label>
                <select name="team" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach($TBL_TEAMS as $t): ?>
                        <option value="<?= $t['ID'] ?>" <?= $team_id==$t['ID']?'selected':'' ?>>
                            <?= htmlspecialchars($t['NAME']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2">
                <label>Package</label>
                <select name="package" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach($packages as $p): ?>
                        <option value="<?= $p['PACKAGE'] ?>" <?= $package==$p['PACKAGE']?'selected':'' ?>>
                            <?= $p['PACKAGE'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-1">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-funnel"></i> Filter
                </button>
            </div>
        </form>
    </div>

    <!-- ─── Active TBL_USERS ─── -->
    <div class="uv-card">
        <div class="uv-card-header">
            <div class="uv-card-header-left">
                <i class="bi bi-person-check-fill" style="color:#10b981;"></i>
                Active TBL_USERS
                <span class="uv-badge-count"><?= count($active_TBL_USERS) ?></span>
            </div>
        </div>
        <div class="uv-card-body">
            <?php if (empty($active_TBL_USERS)): ?>
                <div class="uv-empty">
                    <div class="uv-empty-icon"><i class="bi bi-people"></i></div>
                    <p class="uv-empty-text">No active TBL_USERS match your filters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="uv-table" id="TBL_USERSTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Mobile</th>
                                <th>Login ID</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Role</th>
                                <th>Team</th>
                                <th>Package</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_TBL_USERS as $u): ?>
                                <tr>
                                    <td><span class="uv-id">#<?= $u['ID'] ?></span></td>
                                    <td>
                                        <div class="uv-name">
                                            <div class="uv-avatar"><?= strtoupper(substr($u['NAME'] ?? '?', 0, 2)) ?></div>
                                            <div>
                                                <div class="uv-name-text"><?= htmlspecialchars($u['NAME'] ?: '—') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><a href="tel:<?= $u['MOBILE'] ?>" style="color:var(--uv-accent);text-decoration:none;font-weight:500;"><?= $u['MOBILE'] ?></a></td>
                                    <td style="font-size:0.75rem;color:var(--uv-ink-soft);"><?= htmlspecialchars($u['LOGIN_ID']) ?></td>
                                    <td><span class="uv-badge uv-badge-success"><i class="bi bi-circle-fill" style="font-size:0.4rem;"></i> <?= $u['STATUS'] ?></span></td>
                                    <td style="font-size:0.75rem;color:var(--uv-ink-soft);white-space:nowrap;"><?= $u['JOIN_DATE'] ? date('d M Y', strtotime($u['JOIN_DATE'])) : '—' ?></td>
                                    <td><span class="uv-badge uv-badge-role" title="<?= htmlspecialchars($roleDesc[$u['ROLE']] ?? '') ?>"><?= $u['ROLE'] ?: '—' ?></span></td>
                                    <td>
                                        <?php if ($u['TEAM_NAME']): ?>
                                            <span class="uv-badge uv-badge-team"><?= htmlspecialchars($u['TEAM_NAME']) ?></span>
                                        <?php else: ?>
                                            <span style="font-size:0.75rem;color:var(--uv-ink-muted, #a3a3a3);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['PACKAGE']): ?>
                                            <span class="uv-badge uv-badge-package"><?= htmlspecialchars($u['PACKAGE']) ?></span>
                                        <?php else: ?>
                                            <span style="font-size:0.75rem;color:var(--uv-ink-muted, #a3a3a3);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$u['ID'] === 1 || ($u['ROLE'] === 'Admin' && (int)$u['ID'] !== USER_ID)): ?>
                                            <span style="font-size:0.6875rem;color:#a3a3a3;display:flex;align-items:center;gap:0.3rem;">
                                                <i class="bi bi-shield-lock"></i> Protected
                                            </span>
                                        <?php else: ?>
                                            <a href="<?= url('modules/TBL_USERS/TBL_USERS_add.php') ?>?ID=<?= $u['ID'] ?>" class="uv-action">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ─── Inactive / Other TBL_USERS ─── -->
    <div class="uv-card">
        <div class="uv-card-header">
            <div class="uv-card-header-left">
                <i class="bi bi-person-dash-fill" style="color:#ef4444;"></i>
                Inactive / Other TBL_USERS
                <span class="uv-badge-count"><?= count($other_TBL_USERS) ?></span>
            </div>
            <button class="uv-card-header-toggle" type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#uvInactiveCollapse"
                    aria-expanded="false"
                    aria-controls="uvInactiveCollapse">
                <i class="bi bi-chevron-down"></i> Toggle
            </button>
        </div>
        <div class="collapse" id="uvInactiveCollapse">
            <div class="uv-card-body">
                <?php if (empty($other_TBL_USERS)): ?>
                    <div class="uv-empty">
                        <div class="uv-empty-icon"><i class="bi bi-people"></i></div>
                        <p class="uv-empty-text">No inactive TBL_USERS match your filters.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                    <table class="uv-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Mobile</th>
                                    <th>Login ID</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Role</th>
                                    <th>Team</th>
                                    <th>Package</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($other_TBL_USERS as $u): ?>
                                    <tr class="uv-row-inactive">
                                        <td><span class="uv-id">#<?= $u['ID'] ?></span></td>
                                        <td>
                                            <div class="uv-name">
                                                <div class="uv-avatar"><?= strtoupper(substr($u['NAME'] ?? '?', 0, 2)) ?></div>
                                                <div>
                                                    <div class="uv-name-text"><?= htmlspecialchars($u['NAME'] ?: '—') ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><a href="tel:<?= $u['MOBILE'] ?>" style="color:var(--uv-accent);text-decoration:none;font-weight:500;"><?= $u['MOBILE'] ?></a></td>
                                        <td style="font-size:0.75rem;color:var(--uv-ink-soft);"><?= htmlspecialchars($u['LOGIN_ID']) ?></td>
                                        <td>
                                            <?php
                                            $b = 'uv-badge-secondary';
                                            if ($u['STATUS'] === 'Inactive')  $b = 'uv-badge-danger';
                                            if ($u['STATUS'] === 'Suspended') $b = 'uv-badge-warning';
                                            ?>
                                            <span class="uv-badge <?= $b ?>"><i class="bi bi-circle-fill" style="font-size:0.4rem;"></i> <?= $u['STATUS'] ?></span>
                                        </td>
                                        <td style="font-size:0.75rem;color:var(--uv-ink-soft);white-space:nowrap;"><?= $u['JOIN_DATE'] ? date('d M Y', strtotime($u['JOIN_DATE'])) : '—' ?></td>
                                        <td><span class="uv-badge uv-badge-role" title="<?= htmlspecialchars($roleDesc[$u['ROLE']] ?? '') ?>"><?= $u['ROLE'] ?: '—' ?></span></td>
                                        <td>
                                            <?php if ($u['TEAM_NAME']): ?>
                                                <span class="uv-badge uv-badge-team"><?= htmlspecialchars($u['TEAM_NAME']) ?></span>
                                            <?php else: ?>
                                                <span style="font-size:0.75rem;color:var(--uv-ink-muted, #a3a3a3);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($u['PACKAGE']): ?>
                                                <span class="uv-badge uv-badge-package"><?= htmlspecialchars($u['PACKAGE']) ?></span>
                                            <?php else: ?>
                                                <span style="font-size:0.75rem;color:var(--uv-ink-muted, #a3a3a3);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ((int)$u['ID'] === 1 || ($u['ROLE'] === 'Admin' && (int)$u['ID'] !== USER_ID)): ?>
                                                <span style="font-size:0.6875rem;color:#a3a3a3;display:flex;align-items:center;gap:0.3rem;">
                                                    <i class="bi bi-shield-lock"></i> Protected
                                                </span>
                                            <?php else: ?>
                                                <a href="<?= url('modules/TBL_USERS/TBL_USERS_add.php') ?>?ID=<?= $u['ID'] ?>" class="uv-action">
                                                    <i class="bi bi-pencil-square"></i> Edit
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ─── Pagination ─── -->
    <?php if ($totalPages > 1):
        $baseUrl = url('modules/TBL_USERS/TBL_USERS_view.php');
        $q = http_build_query(array_merge($_GET, ['page' => '']));
    ?>
        <div class="uv-pagination">
            <a class="uv-page-link <?= $page<=1?'uv-disabled':'' ?>" href="<?= $baseUrl ?>?page=1&<?= $q ?>">
                <i class="bi bi-chevron-double-left"></i>
            </a>
            <a class="uv-page-link <?= $page<=1?'uv-disabled':'' ?>" href="<?= $baseUrl ?>?page=<?= $page-1 ?>&<?= $q ?>">
                <i class="bi bi-chevron-left"></i>
            </a>
            <?php for($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++): ?>
                <a class="uv-page-link <?= $i==$page?'uv-active':'' ?>" href="<?= $baseUrl ?>?page=<?= $i ?>&<?= $q ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
            <a class="uv-page-link <?= $page>=$totalPages?'uv-disabled':'' ?>" href="<?= $baseUrl ?>?page=<?= $page+1 ?>&<?= $q ?>">
                <i class="bi bi-chevron-right"></i>
            </a>
            <a class="uv-page-link <?= $page>=$totalPages?'uv-disabled':'' ?>" href="<?= $baseUrl ?>?page=<?= $totalPages ?>&<?= $q ?>">
                <i class="bi bi-chevron-double-right"></i>
            </a>
        </div>
    <?php endif; ?>

</div>

<?php include '../../php_scripts/footer.php'; ?>

<script src="<?= vnd('js/table2excel.min.js') ?>"></script>
<script>
document.getElementById("exportBtn").addEventListener("click", function(){
    var btn = this;
    btn.classList.add('uv-spinning');
    new Table2Excel().export(document.querySelector("#TBL_USERSTable"), {
        name: "CallNow_App_TBL_USERS",
        filename: "CallNow_TBL_USERS_" + new Date().toISOString().slice(0,10)
    });
    setTimeout(function(){ btn.classList.remove('uv-spinning'); }, 2000);
});
</script>
