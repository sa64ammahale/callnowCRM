<?php
require_once 'php_scripts/auth.php';
require_once 'php_scripts/team_auth.php';  // optional, only if you use constants

// Restrict to Admin only
if (USER_ROLE !== 'Admin') {
    header("Location: dashboard.php");
    exit;
}

// Filters & Pagination
$limit  = 50;
$page   = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$search  = trim($_GET['search'] ?? '');
$status  = $_GET['status'] ?? '';
$role    = $_GET['role'] ?? '';
$team_id = $_GET['team'] ?? '';  // now TEAM_ID
$package = $_GET['package'] ?? '';

$where  = "WHERE 1=1";
$params = [];
$types  = "";

// Search
if ($search !== '') {
    $where .= " AND (u.NAME LIKE ? OR u.MOBILE LIKE ? OR u.LOGIN_ID LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s);
    $types .= "sss";
}

// Filters
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

// Count total records
$countSql = "SELECT COUNT(*) FROM USERS u $where";
$stmt = mysqli_prepare($link, $countSql);
if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$totalRecords = mysqli_stmt_get_result($stmt)->fetch_row()[0];
$totalPages   = max(1, ceil($totalRecords / $limit));

// Fetch users with Team Name
$sql = "
    SELECT 
        u.*, 
        t.NAME as TEAM_NAME
    FROM USERS u
    LEFT JOIN TEAMS t ON u.TEAM_ID = t.ID
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

$users = [];
while ($row = mysqli_fetch_assoc($result)) {
    $users[] = $row;
}

// Split into Active / Others (Inactive + Suspended etc.)
$active_users = [];
$other_users  = [];
foreach ($users as $u) {
    if ($u['STATUS'] === 'Active') {
        $active_users[] = $u;
    } else {
        $other_users[] = $u;
    }
}

// Get filter options
$roles    = mysqli_fetch_all(mysqli_query($link, "SELECT DISTINCT ROLE FROM USERS WHERE ROLE IS NOT NULL ORDER BY ROLE"), MYSQLI_ASSOC);
$teams    = mysqli_fetch_all(mysqli_query($link, "SELECT ID, NAME FROM TEAMS ORDER BY NAME"), MYSQLI_ASSOC);
$packages = mysqli_fetch_all(mysqli_query($link, "SELECT DISTINCT PACKAGE FROM USERS WHERE PACKAGE IS NOT NULL AND PACKAGE != '' ORDER BY PACKAGE"), MYSQLI_ASSOC);

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>App Users - CallNow Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/table2excel@1.0.4/dist/table2excel.min.js"></script>

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
            min-height: 100vh;
        }

        .page-wrapper {
            padding-top: 0.75rem;
            padding-bottom: 1.5rem;
        }

        /* Compact header bar */
        .page-header-bar {
            background: #ffffff;
            border-radius: 0.9rem;
            padding: 0.75rem 1.1rem;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.9rem;
        }
        .page-header-title {
            font-size: 1.05rem;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
        }
        .page-header-desc {
            font-size: 0.78rem;
            color: #6b7280;
            margin: 0.15rem 0 0;
        }
        .page-header-actions .btn {
            font-size: 0.78rem;
            padding: 0.25rem 0.7rem;
            border-radius: 999px;
        }

        /* Filters card */
        .filter-card {
            background: #ffffff;
            border-radius: 0.9rem;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.05);
            padding: 0.9rem 0.95rem;
            margin-bottom: 0.9rem;
        }
        .form-control-sm, .form-select-sm {
            font-size: 0.8rem;
        }
        .filter-label {
            font-size: 0.75rem;
            color: #6b7280;
            margin-bottom: 0.15rem;
        }

        /* Table cards */
        .table-card {
            background: #ffffff;
            border-radius: 0.9rem;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08);
            margin-bottom: 0.8rem;
        }
        .table-card-header {
            padding: 0.55rem 0.9rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .table-card-body {
            padding: 0.6rem 0.9rem 0.7rem;
        }

        .table-sm th,
        .table-sm td {
            padding: 0.3rem 0.6rem;
            font-size: 0.78rem;
            vertical-align: middle;
        }

        .table-striped > tbody > tr:nth-of-type(odd) > * {
            background-color: #f9fafb;
        }

        .status-badge {
            font-size: 0.75rem;
            padding: 0.2em 0.6em;
            border-radius: 999px;
        }

        .badge-team {
            background: #231b5e;
            color: #4338ca;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .status-row-active {
            background-color: #f0fdf4;
        }

        @media (max-width: 576px) {
            .page-header-bar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<?php include 'php_scripts/header.php'; ?>

<div class="container page-wrapper">

    <!-- Small Header Bar -->
    <div class="page-header-bar">
        <div>
            <p class="page-header-title mb-1">
                <i class="bi bi-people-fill text-primary me-1"></i>
                App Users Management
            </p>
            <p class="page-header-desc mb-0">
                Total Users: <strong><?= number_format($totalRecords) ?></strong> · Page <?= $page ?> of <?= $totalPages ?>
            </p>
        </div>
        <div class="page-header-actions d-flex gap-2">
            <button id="exportBtn" class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-excel"></i> Export Excel
            </button>
            <a href="dashboard.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-grid"></i> Go to Dashboard
            </a>
        </div>
    </div>

    <!-- Filters (same logic, compact UI) -->
    <div class="filter-card">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-lg-3">
                <label class="filter-label">Search</label>
                <input type="text"
                       name="search"
                       class="form-control form-control-sm"
                       placeholder="Name / Mobile / Login ID"
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-lg-2">
                <label class="filter-label">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="Active"    <?= $status==='Active'?'selected':'' ?>>Active</option>
                    <option value="Inactive"  <?= $status==='Inactive'?'selected':'' ?>>Inactive</option>
                    <option value="Suspended" <?= $status==='Suspended'?'selected':'' ?>>Suspended</option>
                </select>
            </div>
            <div class="col-lg-2">
                <label class="filter-label">Role</label>
                <select name="role" class="form-select form-select-sm">
                    <option value="">All Roles</option>
                    <?php foreach($roles as $r): ?>
                        <option value="<?= $r['ROLE'] ?>" <?= $role==$r['ROLE']?'selected':'' ?>>
                            <?= $r['ROLE'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2">
                <label class="filter-label">Team</label>
                <select name="team" class="form-select form-select-sm">
                    <option value="">All Teams</option>
                    <?php foreach($teams as $t): ?>
                        <option value="<?= $t['ID'] ?>" <?= $team_id==$t['ID']?'selected':'' ?>>
                            <?= htmlspecialchars($t['NAME']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2">
                <label class="filter-label">Package</label>
                <select name="package" class="form-select form-select-sm">
                    <option value="">All Packages</option>
                    <?php foreach($packages as $p): ?>
                        <option value="<?= $p['PACKAGE'] ?>" <?= $package==$p['PACKAGE']?'selected':'' ?>>
                            <?= $p['PACKAGE'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-1">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-funnel"></i> Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Active Users Card -->
    <div class="table-card">
        <div class="table-card-header d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-semibold">
                    <i class="bi bi-person-check-fill text-success me-1"></i>
                    Active Users
                </span>
                <span class="text-muted small ms-1">
                    (<?= count($active_users) ?> on this page)
                </span>
            </div>
        </div>
        <div class="table-card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0" id="usersTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Mobile</th>
                            <th>Login ID</th>
                            <th>Status</th>
                            <th>Join Date</th>
                            <th>Role</th>
                            <th>Team</th>
                            <th>Package</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($active_users)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted small py-3">
                                    No active users found for current filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($active_users as $u): ?>
                                <tr class="status-row-active">
                                    <td><strong>#<?= $u['ID'] ?></strong></td>
                                    <td><strong><?= htmlspecialchars($u['NAME'] ?: '—') ?></strong></td>
                                    <td><a href="tel:<?= $u['MOBILE'] ?>"><?= $u['MOBILE'] ?></a></td>
                                    <td><?= htmlspecialchars($u['LOGIN_ID']) ?></td>
                                    <td>
                                        <span class="badge bg-success status-badge">
                                            <?= $u['STATUS'] ?>
                                        </span>
                                    </td>
                                    <td><?= $u['JOIN_DATE'] ? date("d M Y", strtotime($u['JOIN_DATE'])) : '—' ?></td>
                                    <td>
                                        <span class="badge bg-info status-badge">
                                            <?= $u['ROLE'] ?: '—' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($u['TEAM_NAME']): ?>
                                            <span class="badge badge-team">
                                                <?= htmlspecialchars($u['TEAM_NAME']) ?>
                                            </span>
                                        <?php else: ?>
                                            <em class="text-muted small">No Team</em>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($u['PACKAGE'] ?: '—') ?></td>
                                    <td>
                                        <a href="users_add.php?ID=<?= $u['ID'] ?>" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-pencil-square"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Inactive / Other Users Card (Collapsed by default) -->
    <div class="table-card">
        <div class="table-card-header d-flex justify-content-between align-items-center">
            <button class="btn btn-link text-decoration-none p-0 d-flex align-items-center"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#inactiveUsersCollapse"
                    aria-expanded="false"
                    aria-controls="inactiveUsersCollapse">
                <span class="fw-semibold">
                    <i class="bi bi-person-dash-fill text-danger me-1"></i>
                    Inactive / Other Users
                </span>
                <span class="text-muted small ms-1">
                    (<?= count($other_users) ?> on this page)
                </span>
                <i class="bi bi-chevron-down ms-2 small"></i>
            </button>
        </div>
        <div class="collapse" id="inactiveUsersCollapse">
            <div class="table-card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Mobile</th>
                                <th>Login ID</th>
                                <th>Status</th>
                                <th>Join Date</th>
                                <th>Role</th>
                                <th>Team</th>
                                <th>Package</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($other_users)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted small py-3">
                                        No inactive / other users for current filters.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($other_users as $u): ?>
                                    <tr>
                                        <td><strong>#<?= $u['ID'] ?></strong></td>
                                        <td><strong><?= htmlspecialchars($u['NAME'] ?: '—') ?></strong></td>
                                        <td><a href="tel:<?= $u['MOBILE'] ?>"><?= $u['MOBILE'] ?></a></td>
                                        <td><?= htmlspecialchars($u['LOGIN_ID']) ?></td>
                                        <td>
                                            <?php
                                            $badgeClass = 'bg-secondary';
                                            if ($u['STATUS'] === 'Inactive')  $badgeClass = 'bg-danger';
                                            if ($u['STATUS'] === 'Suspended') $badgeClass = 'bg-warning text-dark';
                                            ?>
                                            <span class="badge <?= $badgeClass ?> status-badge">
                                                <?= $u['STATUS'] ?>
                                            </span>
                                        </td>
                                        <td><?= $u['JOIN_DATE'] ? date("d M Y", strtotime($u['JOIN_DATE'])) : '—' ?></td>
                                        <td>
                                            <span class="badge bg-info status-badge">
                                                <?= $u['ROLE'] ?: '—' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($u['TEAM_NAME']): ?>
                                                <span class="badge badge-team">
                                                    <?= htmlspecialchars($u['TEAM_NAME']) ?>
                                                </span>
                                            <?php else: ?>
                                                <em class="text-muted small">No Team</em>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($u['PACKAGE'] ?: '—') ?></td>
                                        <td>
                                            <a href="users_add.php?ID=<?= $u['ID'] ?>" class="btn btn-outline-primary btn-sm">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Pagination (logic unchanged) -->
    <?php if ($totalPages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm justify-content-center">
                <?php $q = http_build_query(array_merge($_GET, ['page' => ''])); ?>
                <li class="page-item <?= $page<=1?'disabled':'' ?>">
                    <a class="page-link" href="?page=1&<?= $q ?>">
                        <i class="bi bi-chevron-double-left"></i>
                    </a>
                </li>
                <li class="page-item <?= $page<=1?'disabled':'' ?>">
                    <a class="page-link" href="?page=<?= $page-1 ?>&<?= $q ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
                <?php for($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++): ?>
                    <li class="page-item <?= $i==$page?'active':'' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&<?= $q ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
                    <a class="page-link" href="?page=<?= $page+1 ?>&<?= $q ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
                    <a class="page-link" href="?page=<?= $totalPages ?>&<?= $q ?>">
                        <i class="bi bi-chevron-double-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php include 'php_scripts/footer.php'; ?>

<script>
document.getElementById("exportBtn").addEventListener("click", function(){
    // Export only the Active Users table (id="usersTable") – logic same library, new UI
    new Table2Excel().export(document.querySelector("#usersTable"), {
        name: "CallNow_App_Users",
        filename: "CallNow_Users_<?= date('d-m-Y') ?>"
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
