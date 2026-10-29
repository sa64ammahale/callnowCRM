<?php
require_once '../../php_scripts/auth.php';

requirePermission('view_activity');

$userId = intval($_SESSION['id']);
$userRole = defined('USER_ROLE') ? USER_ROLE : ($_SESSION['role'] ?? '');

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

/* Alerts */
$alertMsg = '';
$alertType = '';

/* Handle POST actions (single delete / bulk delete) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if (($action === 'delete_single' || $action === 'delete_bulk') && $userRole !== 'Admin') {
        $alertMsg = "You don't have permission to delete activity logs.";
        $alertType = "danger";
    } else if ($action === 'delete_single' && isset($_POST['id'], $_POST['csrf']) && hash_equals($csrf, $_POST['csrf'])) {
        $deleteId = intval($_POST['id']);
        $sql = "DELETE FROM " . tn('TBL_ACTIVITY_LOG') . " WHERE LOG_ID = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $deleteId);
            if (mysqli_stmt_execute($stmt)) {
                $alertMsg = "Activity log #{$deleteId} deleted.";
                $alertType = "success";
            } else {
                $alertMsg = "Database error deleting record.";
                $alertType = "danger";
            }
            mysqli_stmt_close($stmt);
        } else {
            $alertMsg = "Database error: " . mysqli_error($link);
            $alertType = "danger";
        }
    } else if ($action === 'delete_bulk' && isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids']) && hash_equals($csrf, $_POST['csrf'])) {
        $ids = array_map('intval', $_POST['bulk_ids']);
        if (count($ids) > 0) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $sql = "DELETE FROM " . tn('TBL_ACTIVITY_LOG') . " WHERE LOG_ID IN ($placeholders)";
            if ($stmt = mysqli_prepare($link, $sql)) {
                $bind_names = [];
                $bind_names[] = $types;
                foreach ($ids as $v) $bind_names[] = $v;
                $refs = [];
                foreach ($bind_names as $k => $v) $refs[$k] = &$bind_names[$k];
                call_user_func_array([$stmt, 'bind_param'], $refs);
                if (mysqli_stmt_execute($stmt)) {
                    $affected = mysqli_stmt_affected_rows($stmt);
                    $alertMsg = "Deleted {$affected} activity log(s).";
                    $alertType = "success";
                } else {
                    $alertMsg = "Database error during bulk delete.";
                    $alertType = "danger";
                }
                mysqli_stmt_close($stmt);
            } else {
                $alertMsg = "Database error: " . mysqli_error($link);
                $alertType = "danger";
            }
        } else {
            $alertMsg = "No items selected for bulk delete.";
            $alertType = "warning";
        }
    } else {
        if (isset($_POST['csrf']) && !hash_equals($csrf, $_POST['csrf'])) {
            $alertMsg = "CSRF validation failed.";
            $alertType = "danger";
        }
    }
}

/* Filtering & pagination */
$perPage = 15;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$filter_user = $_GET['filter_user'] ?? '';
$filter_action = $_GET['filter_action'] ?? '';
$filter_table = $_GET['filter_table'] ?? '';
$q = trim($_GET['q'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$where = [];
$types = '';
$params = [];

if ($filter_user !== '') { $where[] = "a.USER_ID = ?"; $types .= 'i'; $params[] = intval($filter_user); }
if ($filter_action !== '') { $where[] = "a.ACTION_TYPE = ?"; $types .= 's'; $params[] = $filter_action; }
if ($filter_table !== '') { $where[] = "a.TARGET_TABLE = ?"; $types .= 's'; $params[] = $filter_table; }
if ($q !== '') {
    $where[] = "(a.ACTION_DETAILS LIKE ? OR a.AFFECTED_IDS LIKE ? OR a.TARGET_TABLE LIKE ? OR a.ACTION_TYPE LIKE ? OR u.NAME LIKE ?)";
    $types .= 'sssss';
    $like = "%$q%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($date_from !== '') { $where[] = "a.LOG_TIME >= ?"; $types .= 's'; $params[] = $date_from . " 00:00:00"; }
if ($date_to !== '') { $where[] = "a.LOG_TIME <= ?"; $types .= 's'; $params[] = $date_to . " 23:59:59"; }

if (isSupervisor()) {
    $where[] = "a.USER_ID IN (SELECT ID FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = ?)";
    $types .= 'i';
    $params[] = USER_TEAM_ID;
} elseif (isOfficer()) {
    $where[] = "a.USER_ID = ?";
    $types .= 'i';
    $params[] = USER_ID;
}

$where_sql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$allowedSort = ['LOG_TIME','USER_ID','ACTION_TYPE','LOG_ID'];
$rawSort = $_GET['sort'] ?? 'LOG_TIME';
$sort = in_array($rawSort, $allowedSort) ? $rawSort : 'LOG_TIME';
$dir = (isset($_GET['dir']) && strtoupper($_GET['dir']) === 'ASC') ? 'ASC' : 'DESC';
$sort_col = '`' . $sort . '`';

/* Count total */
$count_sql = "SELECT COUNT(*) FROM " . tn('TBL_ACTIVITY_LOG') . " a LEFT JOIN " . tn('TBL_USERS') . " u ON a.USER_ID = u.ID $where_sql";
$total = 0;
if ($stmt = mysqli_prepare($link, $count_sql)) {
    if ($types !== '') {
        $bind_names = []; $bind_names[] = $types; foreach ($params as $p) $bind_names[] = $p;
        $refs = []; foreach ($bind_names as $k=>$v) $refs[$k] = &$bind_names[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
}

/* Fetch page rows with user join */
$select_sql = "SELECT a.LOG_ID, a.USER_ID, u.NAME AS USER_NAME, u.ROLE AS USER_ROLE,
                      a.ACTION_TYPE, a.ACTION_DETAILS, a.AFFECTED_IDS, a.TARGET_TABLE, a.LOG_TIME, a.IP_ADDRESS
               FROM " . tn('TBL_ACTIVITY_LOG') . " a
               LEFT JOIN " . tn('TBL_USERS') . " u ON a.USER_ID = u.ID
               $where_sql
               ORDER BY $sort_col $dir
               LIMIT ? OFFSET ?";

$rows = [];
if ($stmt = mysqli_prepare($link, $select_sql)) {
    if ($types === '') {
        mysqli_stmt_bind_param($stmt, "ii", $perPage, $offset);
    } else {
        $bindTypes = $types . "ii";
        $bindParams = array_merge($params, [$perPage, $offset]);
        $bind_names = []; $bind_names[] = $bindTypes; foreach ($bindParams as $p) $bind_names[] = $p;
        $refs = []; foreach ($bind_names as $k=>$v) $refs[$k] = &$bind_names[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($stmt);
} else {
    $alertMsg = "Query prepare failed: " . mysqli_error($link);
    $alertType = "danger";
}

/* CSV export */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_sql = "SELECT a.LOG_ID, a.USER_ID, u.NAME AS USER_NAME, u.ROLE AS USER_ROLE,
                           a.ACTION_TYPE, a.ACTION_DETAILS, a.AFFECTED_IDS, a.TARGET_TABLE, a.LOG_TIME, a.IP_ADDRESS
                    FROM " . tn('TBL_ACTIVITY_LOG') . " a
                    LEFT JOIN " . tn('TBL_USERS') . " u ON a.USER_ID = u.ID
                    $where_sql
                    ORDER BY $sort_col $dir";
    if ($stmt = mysqli_prepare($link, $export_sql)) {
        if ($types !== '') {
            $bind_names = []; $bind_names[] = $types; foreach ($params as $p) $bind_names[] = $p;
            $refs = []; foreach ($bind_names as $k=>$v) $refs[$k] = &$bind_names[$k];
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=activity_export_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['LOG_ID','USER_ID','USER_NAME','USER_ROLE','ACTION_TYPE','ACTION_DETAILS','AFFECTED_IDS','TARGET_TABLE','LOG_TIME','IP_ADDRESS']);
        while ($row = mysqli_fetch_assoc($res)) {
            fputcsv($out, [
                $row['LOG_ID'], $row['USER_ID'], $row['USER_NAME'], $row['USER_ROLE'],
                $row['ACTION_TYPE'], $row['ACTION_DETAILS'], $row['AFFECTED_IDS'],
                $row['TARGET_TABLE'], $row['LOG_TIME'], $row['IP_ADDRESS']
            ]);
        }
        fclose($out);
        exit;
    } else {
        $alertMsg = "Export failed: " . mysqli_error($link);
        $alertType = "danger";
    }
}

/* Filter dropdown values */
$TBL_USERSList = [];
$res = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_USERS') . " WHERE ROLE != 'Super Admin' ORDER BY NAME ASC");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) $TBL_USERSList[$r['ID']] = $r['NAME'];
    mysqli_free_result($res);
}
$actionTypes = [];
$res = mysqli_query($link, "SELECT DISTINCT ACTION_TYPE FROM " . tn('TBL_ACTIVITY_LOG') . " ORDER BY ACTION_TYPE ASC");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) $actionTypes[] = $r['ACTION_TYPE'];
    mysqli_free_result($res);
}
$targetTables = [];
$res = mysqli_query($link, "SELECT DISTINCT TARGET_TABLE FROM " . tn('TBL_ACTIVITY_LOG') . " ORDER BY TARGET_TABLE ASC");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) $targetTables[] = $r['TARGET_TABLE'];
    mysqli_free_result($res);
}

/* Total pages */
$totalPages = max(1, (int)ceil($total / $perPage));
$baseQuery = $_GET;
?>
<?php $pageTitle = 'Activity Log Management'; include '../../php_scripts/header.php'; ?>

<div class="container-fluid px-3 py-3">

    <!-- Zoho CRM Module Header -->
    <div class="module-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/logs/Reports.php') ?>">Logs & Audit</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Activity Trail</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2">
                <h1 class="page-title mb-0">System Activity Logs</h1>
                <span class="badge bg-secondary-subtle text-secondary border"><?= number_format($total) ?> events</span>
            </div>
            <p class="text-muted small mb-0">Complete audit trail of user actions, database mutations, and system access</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a role="button" class="btn btn-outline-secondary btn-sm" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">
                <i class="bi bi-download me-1"></i> Export CSV
            </a>
            <?php if ($userRole === 'Admin'): ?>
                <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#bulkDeleteConfirm">
                    <i class="bi bi-trash me-1"></i> Bulk Delete
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($alertMsg): ?>
        <div class="alert alert-<?= htmlspecialchars($alertType ?: 'info'); ?> alert-dismissible fade show mb-3" role="alert">
            <i class="bi <?= $alertType === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
            <?= htmlspecialchars($alertMsg); ?>
            <button class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- KPI Metric Summary -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Total Audit Events</div>
                <div class="metric-value text-primary"><?= number_format($total) ?></div>
                <div class="text-muted small">Recorded system actions</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Active Users Tracked</div>
                <div class="metric-value text-success"><?= count($TBL_USERSList) ?></div>
                <div class="text-muted small">Users with recorded events</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Action Categories</div>
                <div class="metric-value text-dark"><?= count($actionTypes) ?></div>
                <div class="text-muted small">Event operations</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Entities Monitored</div>
                <div class="metric-value text-secondary"><?= count($targetTables) ?></div>
                <div class="text-muted small">Target schema tables</div>
            </div>
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="crm-card mb-3 p-3">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-12 col-sm-6 col-md-2">
                <label class="form-label small text-muted mb-1">User</label>
                <select name="filter_user" class="form-select form-select-sm">
                    <option value="">All Users</option>
                    <?php foreach ($TBL_USERSList as $uid => $uname): ?>
                        <option value="<?= (int)$uid; ?>" <?= (string)$uid === (string)$filter_user ? 'selected' : '' ?>><?= htmlspecialchars($uname); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-sm-3 col-md-2">
                <label class="form-label small text-muted mb-1">Action Type</label>
                <select name="filter_action" class="form-select form-select-sm">
                    <option value="">All Actions</option>
                    <?php foreach ($actionTypes as $a): ?>
                        <option value="<?= htmlspecialchars($a); ?>" <?= $a === $filter_action ? 'selected' : '' ?>><?= htmlspecialchars($a); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-sm-3 col-md-2">
                <label class="form-label small text-muted mb-1">Target Table</label>
                <select name="filter_table" class="form-select form-select-sm">
                    <option value="">All Tables</option>
                    <?php foreach ($targetTables as $t): ?>
                        <option value="<?= htmlspecialchars($t); ?>" <?= $t === $filter_table ? 'selected' : '' ?>><?= htmlspecialchars($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-sm-3 col-md-2">
                <label class="form-label small text-muted mb-1">From Date</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from); ?>">
            </div>

            <div class="col-6 col-sm-3 col-md-2">
                <label class="form-label small text-muted mb-1">To Date</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to); ?>">
            </div>

            <div class="col-12 col-md-2">
                <label class="form-label small text-muted mb-1">Search Details</label>
                <div class="input-group input-group-sm">
                    <input type="text" name="q" class="form-control" placeholder="Search keywords..." value="<?= htmlspecialchars($q); ?>">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                    <?php if ($filter_user !== '' || $filter_action !== '' || $filter_table !== '' || $date_from !== '' || $date_to !== '' || $q !== ''): ?>
                        <a href="manage_activity.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- Activity Log Register Table -->
    <div class="crm-card">
        <form id="bulkForm" method="POST">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="delete_bulk">

            <div class="table-responsive">
                <table class="table crm-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 38px;" class="text-center"><input id="select_all" type="checkbox" class="form-check-input"></th>
                            <th style="width: 70px;">Log ID</th>
                            <th style="min-width: 180px;">User</th>
                            <th style="width: 110px;">Action</th>
                            <th style="min-width: 140px;">Target</th>
                            <th>Details</th>
                            <th style="width: 160px;">Timestamp</th>
                            <th style="width: 120px;">IP Address</th>
                            <th style="width: 130px;" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($rows) === 0): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-5">
                                <i class="bi bi-journal-x fs-2 d-block mb-2 text-secondary"></i>
                                No activity logs match the selected filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php 
                                $act = strtoupper($r['ACTION_TYPE'] ?? '');
                                $badgeClass = 'bg-secondary-subtle text-secondary';
                                if ($act === 'INSERT') $badgeClass = 'bg-success-subtle text-success';
                                elseif ($act === 'UPDATE') $badgeClass = 'bg-primary-subtle text-primary';
                                elseif ($act === 'DELETE') $badgeClass = 'bg-danger-subtle text-danger';
                                elseif ($act === 'LOGIN') $badgeClass = 'bg-info-subtle text-info';
                            ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="form-check-input row_cb" name="bulk_ids[]" value="<?= (int)$r['LOG_ID']; ?>"></td>
                                <td class="fw-semibold text-secondary">#<?= (int)$r['LOG_ID']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-primary bg-primary-subtle flex-shrink-0" style="width: 30px; height: 30px; font-size: 0.8rem;">
                                            <?= htmlspecialchars(mb_substr($r['USER_NAME'] ?? 'U', 0, 1)); ?>
                                        </div>
                                        <div class="text-truncate" style="max-width: 150px;">
                                            <div class="fw-medium text-dark small text-truncate"><?= htmlspecialchars($r['USER_NAME'] ?? ('User #' . (int)$r['USER_ID'])); ?></div>
                                            <div class="text-muted" style="font-size: 0.72rem;"><?= htmlspecialchars($r['USER_ROLE'] ?? '—'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge <?= $badgeClass ?> border"><?= htmlspecialchars($r['ACTION_TYPE']); ?></span></td>
                                <td><code class="text-dark bg-light px-2 py-0.5 rounded border small font-monospace"><?= htmlspecialchars($r['TARGET_TABLE'] ?: '—'); ?></code></td>
                                <td class="small text-truncate" style="max-width: 320px;" title="<?= htmlspecialchars($r['ACTION_DETAILS']); ?>">
                                    <?= htmlspecialchars(mb_strimwidth($r['ACTION_DETAILS'], 0, 90, '...')); ?>
                                </td>
                                <td class="small text-muted font-monospace"><?= htmlspecialchars($r['LOG_TIME']); ?></td>
                                <td class="small text-muted font-monospace"><?= htmlspecialchars($r['IP_ADDRESS'] ?: '—'); ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1" data-bs-toggle="modal" data-bs-target="#viewModal" data-log='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8'); ?>' title="View Record">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <?php if ($userRole === 'Admin'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1 ms-1 single-delete-btn" data-id="<?= (int)$r['LOG_ID']; ?>" title="Delete Record">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Table Footer Toolbar -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 py-2 border-top bg-light">
                <div class="d-flex align-items-center gap-2">
                    <?php if ($userRole === 'Admin'): ?>
                        <button class="btn btn-sm btn-danger" type="button" id="bulkDeleteTriggerBtn">
                            <i class="bi bi-trash me-1"></i> Delete Selected
                        </button>
                    <?php endif; ?>
                    <span class="text-muted small">Showing <?= count($rows); ?> of <?= number_format($total); ?> entries</span>
                </div>

                <nav aria-label="Activity page navigation">
                    <ul class="pagination pagination-sm mb-0">
                        <?php 
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                        ?>
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($baseQuery, ['page' => 1]))); ?>">&laquo;</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($baseQuery, ['page' => $page - 1]))); ?>">&lsaquo;</a>
                            </li>
                        <?php endif; ?>

                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($baseQuery, ['page' => $p]))); ?>"><?= $p; ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($baseQuery, ['page' => $page + 1]))); ?>">&rsaquo;</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($baseQuery, ['page' => $totalPages]))); ?>">&raquo;</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        </form>
    </div>
</div>

<!-- Standalone Single Delete Form -->
<form id="singleDeleteForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf); ?>">
    <input type="hidden" name="action" value="delete_single">
    <input type="hidden" name="id" id="singleDeleteId" value="">
</form>

<!-- View Activity Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewModalLabel"><i class="bi bi-info-circle text-primary me-2"></i> Activity Log Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-bordered crm-table align-middle">
                        <tbody>
                            <tr>
                                <th class="bg-light text-muted" style="width: 25%;">Log ID</th>
                                <td><span id="v_logid" class="fw-bold"></span></td>
                            </tr>
                            <tr>
                                <th class="bg-light text-muted">User Account</th>
                                <td>
                                    <span id="v_user" class="fw-semibold"></span>
                                    <span id="v_userrole" class="badge bg-secondary-subtle text-secondary border ms-2"></span>
                                </td>
                            </tr>
                            <tr>
                                <th class="bg-light text-muted">Action Type</th>
                                <td><span id="v_action" class="badge bg-primary-subtle text-primary border"></span></td>
                            </tr>
                            <tr>
                                <th class="bg-light text-muted">Target Table</th>
                                <td><code id="v_table" class="bg-light px-2 py-1 rounded border font-monospace"></code></td>
                            </tr>
                            <tr>
                                <th class="bg-light text-muted">Affected Entity ID(s)</th>
                                <td><span id="v_affected" class="font-monospace text-dark"></span></td>
                            </tr>
                            <tr>
                                <th class="bg-light text-muted">Recorded Timestamp</th>
                                <td><span id="v_time" class="font-monospace"></span></td>
                            </tr>
                            <tr>
                                <th class="bg-light text-muted">Client IP Address</th>
                                <td><span id="v_ip" class="font-monospace"></span></td>
                            </tr>
                            <tr>
                                <th class="bg-light text-muted align-top">Action Details</th>
                                <td>
                                    <pre id="v_details" class="p-3 bg-light rounded border text-wrap mb-0 font-monospace small" style="max-height: 250px; overflow-y: auto;"></pre>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Delete Confirm Modal -->
<div class="modal fade" id="bulkDeleteConfirm" tabindex="-1" aria-labelledby="bulkDeleteConfirmLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center p-4">
                <div class="rounded-circle bg-danger-subtle text-danger d-inline-flex align-items-center justify-content-center mb-3" style="width: 50px; height: 50px;">
                    <i class="bi bi-trash fs-4"></i>
                </div>
                <h6 class="modal-title fw-bold mb-2" id="bulkDeleteConfirmLabel">Delete Selected Logs?</h6>
                <p class="text-muted small mb-3">This action will permanently purge the selected activity records from the database audit trail.</p>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button id="confirmBulkDeleteBtn" type="button" class="btn btn-danger btn-sm">Yes, Delete</button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('select_all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.row_cb').forEach(cb => cb.checked = this.checked);
        });
    }

    // Single delete action
    document.querySelectorAll('.single-delete-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            if (confirm('Are you sure you want to delete activity log #' + id + '?')) {
                document.getElementById('singleDeleteId').value = id;
                document.getElementById('singleDeleteForm').submit();
            }
        });
    });

    // View modal fill
    const viewModal = document.getElementById('viewModal');
    if (viewModal) {
        viewModal.addEventListener('show.bs.modal', function(event) {
            const btn = event.relatedTarget;
            const data = btn.getAttribute('data-log');
            let obj = {};
            try { obj = JSON.parse(data); } catch(e) { obj = {}; }

            document.getElementById('v_logid').textContent = '#' + (obj.LOG_ID || '');
            const userDisplay = (obj.USER_NAME ? obj.USER_NAME : ('User #' + (obj.USER_ID || '')));
            document.getElementById('v_user').textContent = userDisplay;
            document.getElementById('v_userrole').textContent = obj.USER_ROLE || '—';
            document.getElementById('v_action').textContent = obj.ACTION_TYPE || '—';
            document.getElementById('v_table').textContent = obj.TARGET_TABLE || '—';
            document.getElementById('v_affected').textContent = obj.AFFECTED_IDS || '—';
            document.getElementById('v_time').textContent = obj.LOG_TIME || '—';
            document.getElementById('v_ip').textContent = obj.IP_ADDRESS || '—';
            document.getElementById('v_details').textContent = obj.ACTION_DETAILS || '—';
        });
    }

    // Bulk delete confirmation
    const bulkTriggerBtn = document.getElementById('bulkDeleteTriggerBtn');
    if (bulkTriggerBtn) {
        bulkTriggerBtn.addEventListener('click', function() {
            const selected = document.querySelectorAll('.row_cb:checked');
            if (selected.length === 0) {
                alert('Please select at least one log entry to delete.');
                return;
            }
            const modal = new bootstrap.Modal(document.getElementById('bulkDeleteConfirm'));
            modal.show();
        });
    }

    const confirmBulkBtn = document.getElementById('confirmBulkDeleteBtn');
    if (confirmBulkBtn) {
        confirmBulkBtn.addEventListener('click', function() {
            const selected = document.querySelectorAll('.row_cb:checked');
            if (selected.length === 0) {
                alert('Please select at least one row to delete.');
                return;
            }
            document.getElementById('bulkForm').submit();
        });
    }
});
</script>
