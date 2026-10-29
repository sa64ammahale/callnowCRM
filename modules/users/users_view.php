<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php';

requirePermission('manage_users');

$limit  = 50;
$page   = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$search  = trim($_GET['search'] ?? '');
$status  = $_GET['status'] ?? '';
$role    = $_GET['role'] ?? '';
$team_id = $_GET['team'] ?? '';
$package = $_GET['package'] ?? '';

$where  = "WHERE 1=1 AND u.ID != 1 AND u.ROLE != 'Super Admin'";
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

$countSql = "SELECT COUNT(*) FROM " . tn('TBL_USERS') . " u $where";
$stmt = mysqli_prepare($link, $countSql);
if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$totalRecords = mysqli_stmt_get_result($stmt)->fetch_row()[0];
$totalPages   = max(1, ceil($totalRecords / $limit));

$sql = "
    SELECT
        u.*,
        t.NAME as TEAM_NAME
    FROM " . tn('TBL_USERS') . " u
    LEFT JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID
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

$usersList = [];
while ($row = mysqli_fetch_assoc($result)) {
    $usersList[] = $row;
}

$activeUsers = [];
$otherUsers  = [];
foreach ($usersList as $u) {
    if ($u['STATUS'] === 'Active') {
        $activeUsers[] = $u;
    } else {
        $otherUsers[] = $u;
    }
}

$allRoles = mysqli_fetch_all(mysqli_query($link, "SELECT DISTINCT ROLE FROM " . tn('TBL_USERS') . " WHERE ROLE IS NOT NULL AND ROLE != 'Super Admin' ORDER BY ROLE"), MYSQLI_ASSOC);
$allTeams = mysqli_fetch_all(mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " ORDER BY NAME"), MYSQLI_ASSOC);
$packages = mysqli_fetch_all(mysqli_query($link, "SELECT DISTINCT PACKAGE FROM " . tn('TBL_USERS') . " WHERE PACKAGE IS NOT NULL AND PACKAGE != '' ORDER BY PACKAGE"), MYSQLI_ASSOC);

// Role description lookup
$roleDesc = [];
$rd = mysqli_query($link, "SELECT role_name, description FROM " . tn('TBL_ROLES'));
if ($rd) while ($r = mysqli_fetch_assoc($rd)) $roleDesc[$r['role_name']] = $r['description'];

$totalActive = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM " . tn('TBL_USERS') . " WHERE STATUS='Active' AND ROLE != 'Super Admin'"))[0];
$totalInactive = $totalRecords - $totalActive;
?>
<?php $pageTitle = 'User Management - CallNow CRM'; include '../../php_scripts/header.php'; ?>

<div class="container-fluid px-3 py-3">

    <!-- Zoho CRM Module Header -->
    <div class="module-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/users/teams_dashboard.php') ?>">Teams & Staff</a></li>
                    <li class="breadcrumb-item active" aria-current="page">User Accounts</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2">
                <h1 class="page-title mb-0">User Directory</h1>
                <span class="badge bg-secondary-subtle text-secondary border"><?= number_format($totalRecords) ?> Users</span>
            </div>
            <p class="text-muted small mb-0">Manage agent logins, operational roles, team assignments, and calling permissions</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button id="exportBtn" class="btn btn-outline-secondary btn-sm" type="button">
                <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
            </button>
            <a href="<?= url('modules/users/teams_dashboard.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-diagram-3 me-1"></i> Teams Hub
            </a>
            <a href="<?= url('modules/users/users_add.php') ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-person-plus me-1"></i> Add User
            </a>
        </div>
    </div>

    <!-- Metric KPI Strip -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3 col-xl">
            <div class="metric-card">
                <div class="metric-label">Total Users</div>
                <div class="metric-value text-dark"><?= number_format($totalRecords) ?></div>
                <div class="text-muted small">Registered accounts</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="metric-card">
                <div class="metric-label">Active Agents</div>
                <div class="metric-value text-success"><?= number_format($totalActive) ?></div>
                <div class="text-muted small">Ready to call</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="metric-card">
                <div class="metric-label">Inactive / Suspended</div>
                <div class="metric-value text-danger"><?= number_format($totalInactive) ?></div>
                <div class="text-muted small">Access disabled</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="metric-card">
                <div class="metric-label">System Roles</div>
                <div class="metric-value text-primary"><?= count($allRoles) ?></div>
                <div class="text-muted small">Defined privileges</div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="metric-card">
                <div class="metric-label">Operational Teams</div>
                <div class="metric-value text-secondary"><?= count($allTeams) ?></div>
                <div class="text-muted small">Organized units</div>
            </div>
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="crm-card mb-3 p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small text-muted mb-1">Search User</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Name, Mobile or Login ID" value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="Active" <?= $status==='Active'?'selected':'' ?>>Active</option>
                    <option value="Inactive" <?= $status==='Inactive'?'selected':'' ?>>Inactive</option>
                    <option value="Suspended" <?= $status==='Suspended'?'selected':'' ?>>Suspended</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Role</label>
                <select name="role" class="form-select form-select-sm">
                    <option value="">All Roles</option>
                    <?php foreach($allRoles as $r): ?>
                        <option value="<?= htmlspecialchars($r['ROLE']) ?>" <?= $role===$r['ROLE']?'selected':'' ?>>
                            <?= htmlspecialchars($r['ROLE']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Team</label>
                <select name="team" class="form-select form-select-sm">
                    <option value="">All Teams</option>
                    <?php foreach($allTeams as $t): ?>
                        <option value="<?= (int)$t['ID'] ?>" <?= (string)$team_id===(string)$t['ID']?'selected':'' ?>>
                            <?= htmlspecialchars($t['NAME']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Package</label>
                <select name="package" class="form-select form-select-sm">
                    <option value="">All Packages</option>
                    <?php foreach($packages as $p): ?>
                        <option value="<?= htmlspecialchars($p['PACKAGE']) ?>" <?= $package===$p['PACKAGE']?'selected':'' ?>>
                            <?= htmlspecialchars($p['PACKAGE']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-1">
                <div class="d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100" title="Apply Filter">
                        <i class="bi bi-funnel"></i>
                    </button>
                    <?php if ($search !== '' || $status !== '' || $role !== '' || $team_id !== '' || $package !== ''): ?>
                        <a href="users_view.php" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- Active Users Table -->
    <div class="crm-card mb-3">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-success-subtle text-success p-1"><i class="bi bi-check-circle-fill"></i></span>
                <span class="fw-semibold text-dark">Active Users</span>
                <span class="badge bg-secondary-subtle text-secondary border"><?= count($activeUsers) ?></span>
            </div>
            <div class="text-muted small">Showing operational personnel</div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($activeUsers)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-people fs-2 d-block mb-2 text-secondary"></i>
                    No active users match your selected filter criteria.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table crm-table align-middle mb-0" id="usersTable">
                        <thead>
                            <tr>
                                <th style="width: 70px;">ID</th>
                                <th style="min-width: 200px;">User & Database</th>
                                <th style="width: 130px;">Mobile</th>
                                <th style="width: 140px;">Login ID</th>
                                <th style="width: 100px;">Status</th>
                                <th style="width: 110px;">Joined</th>
                                <th style="width: 120px;">Role</th>
                                <th style="min-width: 130px;">Team</th>
                                <th style="width: 100px;">Package</th>
                                <th style="width: 90px;" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeUsers as $u): ?>
                                <tr>
                                    <td><span class="fw-semibold text-secondary font-monospace">#<?= $u['ID'] ?></span></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-primary bg-primary-subtle flex-shrink-0" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                                <?= strtoupper(substr($u['NAME'] ?? '?', 0, 2)) ?>
                                            </div>
                                            <div class="text-truncate" style="max-width: 180px;">
                                                <div class="fw-medium text-dark small text-truncate"><?= htmlspecialchars($u['NAME'] ?: '—') ?></div>
                                                <?php if (!empty($u['current_calling_db'])): ?>
                                                    <span class="badge <?= $u['current_calling_db'] === 'TEMPORARY' ? 'bg-warning-subtle text-warning border-warning' : 'bg-success-subtle text-success border-success' ?> border mt-0.5" style="font-size: 0.65rem;" title="<?= !empty($u['last_batch_at']) ? 'Last batch: ' . date('d M H:i', strtotime($u['last_batch_at'])) : '' ?>">
                                                        <i class="bi bi-database me-1"></i><?= htmlspecialchars($u['current_calling_db']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><a href="tel:<?= $u['MOBILE'] ?>" class="text-decoration-none text-primary small font-monospace"><?= htmlspecialchars($u['MOBILE']) ?></a></td>
                                    <td><span class="small text-muted font-monospace"><?= htmlspecialchars($u['LOGIN_ID']) ?></span></td>
                                    <td><span class="badge bg-success-subtle text-success border"><i class="bi bi-circle-fill me-1" style="font-size: 0.4rem;"></i><?= $u['STATUS'] ?></span></td>
                                    <td><span class="small text-muted"><?= $u['JOIN_DATE'] ? date('d M Y', strtotime($u['JOIN_DATE'])) : '—' ?></span></td>
                                    <td><span class="badge bg-info-subtle text-primary border" title="<?= htmlspecialchars($roleDesc[$u['ROLE']] ?? '') ?>"><?= htmlspecialchars($u['ROLE'] ?: '—') ?></span></td>
                                    <td>
                                        <?php if ($u['TEAM_NAME']): ?>
                                            <span class="badge bg-primary-subtle text-primary border"><?= htmlspecialchars($u['TEAM_NAME']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['PACKAGE']): ?>
                                            <span class="badge bg-secondary-subtle text-dark border"><?= htmlspecialchars($u['PACKAGE']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ((int)$u['ID'] === 1 || (in_array($u['ROLE'], ['Admin', 'Super Admin']) && (int)$u['ID'] !== USER_ID)): ?>
                                            <span class="badge bg-light text-muted border" title="Protected System Account">
                                                <i class="bi bi-shield-lock"></i>
                                            </span>
                                        <?php else: ?>
                                            <a href="<?= url('modules/users/users_add.php') ?>?ID=<?= $u['ID'] ?>" class="btn btn-sm btn-outline-secondary px-2 py-1" title="Edit User">
                                                <i class="bi bi-pencil-square"></i>
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

    <!-- Inactive / Suspended Users Section -->
    <div class="crm-card mb-3">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-danger-subtle text-danger p-1"><i class="bi bi-person-x-fill"></i></span>
                <span class="fw-semibold text-dark">Inactive & Suspended Users</span>
                <span class="badge bg-secondary-subtle text-secondary border"><?= count($otherUsers) ?></span>
            </div>
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#inactiveCollapse" aria-expanded="false" aria-controls="inactiveCollapse">
                <i class="bi bi-chevron-down me-1"></i> Toggle Section
            </button>
        </div>
        <div class="collapse" id="inactiveCollapse">
            <div class="card-body p-0">
                <?php if (empty($otherUsers)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-check2-circle fs-3 d-block mb-1 text-success"></i>
                        No inactive or suspended users found.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table crm-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 70px;">ID</th>
                                    <th style="min-width: 200px;">User</th>
                                    <th style="width: 130px;">Mobile</th>
                                    <th style="width: 140px;">Login ID</th>
                                    <th style="width: 100px;">Status</th>
                                    <th style="width: 110px;">Joined</th>
                                    <th style="width: 120px;">Role</th>
                                    <th style="min-width: 130px;">Team</th>
                                    <th style="width: 100px;">Package</th>
                                    <th style="width: 90px;" class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($otherUsers as $u): ?>
                                    <tr class="opacity-75">
                                        <td><span class="fw-semibold text-secondary font-monospace">#<?= $u['ID'] ?></span></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-muted bg-light flex-shrink-0" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                                    <?= strtoupper(substr($u['NAME'] ?? '?', 0, 2)) ?>
                                                </div>
                                                <div class="fw-medium text-dark small"><?= htmlspecialchars($u['NAME'] ?: '—') ?></div>
                                            </div>
                                        </td>
                                        <td><a href="tel:<?= $u['MOBILE'] ?>" class="text-decoration-none text-secondary small font-monospace"><?= htmlspecialchars($u['MOBILE']) ?></a></td>
                                        <td><span class="small text-muted font-monospace"><?= htmlspecialchars($u['LOGIN_ID']) ?></span></td>
                                        <td>
                                            <?php
                                            $stClass = 'bg-secondary-subtle text-secondary';
                                            if ($u['STATUS'] === 'Inactive')  $stClass = 'bg-danger-subtle text-danger';
                                            if ($u['STATUS'] === 'Suspended') $stClass = 'bg-warning-subtle text-warning';
                                            ?>
                                            <span class="badge <?= $stClass ?> border"><i class="bi bi-circle-fill me-1" style="font-size: 0.4rem;"></i><?= $u['STATUS'] ?></span>
                                        </td>
                                        <td><span class="small text-muted"><?= $u['JOIN_DATE'] ? date('d M Y', strtotime($u['JOIN_DATE'])) : '—' ?></span></td>
                                        <td><span class="badge bg-secondary-subtle text-secondary border"><?= htmlspecialchars($u['ROLE'] ?: '—') ?></span></td>
                                        <td>
                                            <?php if ($u['TEAM_NAME']): ?>
                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($u['TEAM_NAME']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($u['PACKAGE']): ?>
                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($u['PACKAGE']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ((int)$u['ID'] === 1 || (in_array($u['ROLE'], ['Admin', 'Super Admin']) && (int)$u['ID'] !== USER_ID)): ?>
                                                <span class="badge bg-light text-muted border">
                                                    <i class="bi bi-shield-lock"></i>
                                                </span>
                                            <?php else: ?>
                                                <a href="<?= url('modules/users/users_add.php') ?>?ID=<?= $u['ID'] ?>" class="btn btn-sm btn-outline-secondary px-2 py-1" title="Edit User">
                                                    <i class="bi bi-pencil-square"></i>
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

    <!-- Pagination -->
    <?php if ($totalPages > 1):
        $baseUrl = url('modules/users/users_view.php');
        $q = http_build_query(array_merge($_GET, ['page' => '']));
    ?>
        <div class="d-flex justify-content-center mt-3">
            <nav aria-label="Users pagination">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page<=1?'disabled':'' ?>">
                        <a class="page-link" href="<?= $baseUrl ?>?page=1&<?= $q ?>">&laquo;</a>
                    </li>
                    <li class="page-item <?= $page<=1?'disabled':'' ?>">
                        <a class="page-link" href="<?= $baseUrl ?>?page=<?= $page-1 ?>&<?= $q ?>">&lsaquo;</a>
                    </li>
                    <?php for($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++): ?>
                        <li class="page-item <?= $i==$page?'active':'' ?>">
                            <a class="page-link" href="<?= $baseUrl ?>?page=<?= $i ?>&<?= $q ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
                        <a class="page-link" href="<?= $baseUrl ?>?page=<?= $page+1 ?>&<?= $q ?>">&rsaquo;</a>
                    </li>
                    <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
                        <a class="page-link" href="<?= $baseUrl ?>?page=<?= $totalPages ?>&<?= $q ?>">&raquo;</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>

</div>

<?php include '../../php_scripts/footer.php'; ?>

<script src="<?= vnd('js/table2excel.min.js') ?>"></script>
<script>
document.getElementById("exportBtn").addEventListener("click", function(){
    var btn = this;
    var originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Exporting...';
    btn.disabled = true;
    
    new Table2Excel().export(document.querySelector("#usersTable"), {
        name: "CallNow_Users",
        filename: "CallNow_Users_" + new Date().toISOString().slice(0,10)
    });
    
    setTimeout(function(){
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    }, 1500);
});
</script>
