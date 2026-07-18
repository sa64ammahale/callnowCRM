<?php
// manage_activity.php - fixed delete-on-view bug + compact mobile-friendly UI

if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../php_scripts/auth.php';

requirePermission('view_activity');

$userId = intval($_SESSION['id']);
$userRole = $_SESSION['role'] ?? '';

define('TBL_ACTIVITY', 'ACTIVITY_LOG');
define('TBL_USERS', 'USERS');

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
        $sql = "DELETE FROM " . TBL_ACTIVITY . " WHERE LOG_ID = ?";
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
            $sql = "DELETE FROM " . TBL_ACTIVITY . " WHERE LOG_ID IN ($placeholders)";
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

/* ---------------- Filtering & pagination ---------------- */
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

$where_sql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$allowedSort = ['LOG_TIME','USER_ID','ACTION_TYPE','LOG_ID'];
$rawSort = $_GET['sort'] ?? 'LOG_TIME';
$sort = in_array($rawSort, $allowedSort) ? $rawSort : 'LOG_TIME';
$dir = (isset($_GET['dir']) && strtoupper($_GET['dir']) === 'ASC') ? 'ASC' : 'DESC';
$sort_col = '`' . $sort . '`';

/* Count total (JOIN users for name filter correctness) */
$count_sql = "SELECT COUNT(*) FROM " . TBL_ACTIVITY . " a LEFT JOIN " . TBL_USERS . " u ON a.USER_ID = u.ID $where_sql";
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
               FROM " . TBL_ACTIVITY . " a
               LEFT JOIN " . TBL_USERS . " u ON a.USER_ID = u.ID
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
                   FROM " . TBL_ACTIVITY . " a
                   LEFT JOIN " . TBL_USERS . " u ON a.USER_ID = u.ID
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

/* Build filter dropdown values (users with names) */
$usersList = [];
$res = mysqli_query($link, "SELECT ID, NAME FROM " . TBL_USERS . " ORDER BY NAME ASC");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) $usersList[$r['ID']] = $r['NAME'];
    mysqli_free_result($res);
}
$actionTypes = [];
$res = mysqli_query($link, "SELECT DISTINCT ACTION_TYPE FROM " . TBL_ACTIVITY . " ORDER BY ACTION_TYPE ASC");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) $actionTypes[] = $r['ACTION_TYPE'];
    mysqli_free_result($res);
}
$targetTables = [];
$res = mysqli_query($link, "SELECT DISTINCT TARGET_TABLE FROM " . TBL_ACTIVITY . " ORDER BY TARGET_TABLE ASC");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) $targetTables[] = $r['TARGET_TABLE'];
    mysqli_free_result($res);
}

/* Total pages */
$totalPages = max(1, (int)ceil($total / $perPage));
$baseQuery = $_GET;
?>
<?php $pageTitle = 'Activity Log Management'; include '../../php_scripts/header.php'; ?>

<div class="container py-3">

    <div class="topbar d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h1><i class="bi bi-journal-text me-2"></i> Activity Log</h1>
            <div class="small-muted">Audit trail — filter, view, export, manage logs</div>
        </div>
        <div class="mt-2 mt-sm-0">
            <a role="button" type="button" class="btn btn-sm btn-light border shadow-sm" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['export'=>'csv']))); ?>">
                <i class="bi bi-download me-1"></i> Export CSV
            </a>
            <?php if ($userRole === 'Admin'): ?>
                <button type="button" class="btn btn-sm btn-gradient ms-2" data-bs-toggle="modal" data-bs-target="#bulkDeleteConfirm"><i class="bi bi-trash me-1"></i> Bulk Delete</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($alertMsg): ?>
        <div class="alert alert-<?php echo htmlspecialchars($alertType ?: 'info'); ?> alert-dismissible fade show mt-3">
            <?php echo htmlspecialchars($alertMsg); ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card card-modern my-3 p-3">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-12 col-sm-6 col-md-3">
                <label class="form-label small mb-1">User</label>
                <select name="filter_user" class="form-select form-select-sm">
                    <option value="">All users</option>
                    <?php foreach ($usersList as $uid => $uname): ?>
                        <option value="<?php echo (int)$uid; ?>" <?php if((string)$uid === (string)$filter_user) echo 'selected'; ?>><?php echo htmlspecialchars($uname); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-sm-4 col-md-2">
                <label class="form-label small mb-1">Action</label>
                <select name="filter_action" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($actionTypes as $a): ?>
                        <option value="<?php echo htmlspecialchars($a); ?>" <?php if($a === $filter_action) echo 'selected'; ?>><?php echo htmlspecialchars($a); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-sm-4 col-md-2">
                <label class="form-label small mb-1">Target</label>
                <select name="filter_table" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($targetTables as $t): ?>
                        <option value="<?php echo htmlspecialchars($t); ?>" <?php if($t === $filter_table) echo 'selected'; ?>><?php echo htmlspecialchars($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-sm-4 col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>

            <div class="col-6 col-sm-4 col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>

            <div class="col-12 col-md-3">
                <label class="form-label small mb-1">Search</label>
                <div class="input-group search-box">
                    <input type="text" name="q" class="form-control form-control-sm" placeholder="Search details, action, user..." value="<?php echo htmlspecialchars($q); ?>">
                    <button class="btn btn-gradient btn-sm" type="submit"><i class="bi bi-search"></i></button>
                </div>
            </div>
        </form>
    </div>

    <div class="card card-modern">
        <div class="card-body p-0">
            <form id="bulkForm" method="POST">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="delete_bulk">

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="small">
                            <tr>
                                <th style="width:34px;"><input id="select_all" type="checkbox"></th>
                                <th style="width:64px;">ID</th>
                                <th>User</th>
                                <th style="width:96px;">Action</th>
                                <th>Target</th>
                                <th>Details</th>
                                <th style="width:150px;">Time</th>
                                <th style="width:100px;">IP</th>
                                <th style="width:130px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($rows) === 0): ?>
                            <tr><td colspan="9" class="text-center small-muted py-4">No activity logs found for selected filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php $actionBadgeClass = in_array($r['ACTION_TYPE'], ['INSERT','UPDATE','DELETE']) ? $r['ACTION_TYPE'] : 'OTHER'; ?>
                                <tr>
                                    <td><input type="checkbox" class="row_cb" name="bulk_ids[]" value="<?php echo (int)$r['LOG_ID']; ?>"></td>
                                    <td class="fw-semibold small"><?php echo (int)$r['LOG_ID']; ?></td>
                                    <td class="small">
                                        <div class="d-flex align-items-center">
                                            <div class="me-2">
                                                <div style="width:34px;height:34px;border-radius:8px;background:linear-gradient(135deg,#eef2ff,#f7f7ff);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--accent-1);font-size:0.95rem;">
                                                    <?php echo htmlspecialchars(mb_substr($r['USER_NAME'] ?? 'U',0,1)); ?>
                                                </div>
                                            </div>
                                            <div>
                                                <div class="fw-semibold small mb-0"><?php echo htmlspecialchars($r['USER_NAME'] ?? ('User #' . (int)$r['USER_ID'])); ?></div>
                                                <div class="small-muted small mt-0"><span class="badge role-badge <?php echo htmlspecialchars($r['USER_ROLE'] ?? ''); ?>"><?php echo htmlspecialchars($r['USER_ROLE'] ?? '—'); ?></span></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge action-badge <?php echo htmlspecialchars($actionBadgeClass); ?>"><?php echo htmlspecialchars($r['ACTION_TYPE']); ?></span></td>
                                    <td class="small"><?php echo htmlspecialchars($r['TARGET_TABLE']); ?></td>
                                    <td class="text-truncate-200 small" title="<?php echo htmlspecialchars($r['ACTION_DETAILS']); ?>"><?php echo htmlspecialchars(mb_strimwidth($r['ACTION_DETAILS'],0,100,'...')); ?></td>
                                    <td class="small"><?php echo htmlspecialchars($r['LOG_TIME']); ?></td>
                                    <td class="small"><?php echo htmlspecialchars($r['IP_ADDRESS']); ?></td>
                                    <td>
                                        <!-- VIEW should NOT submit the form: set type="button" -->
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewModal" data-log='<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8'); ?>'>
                                            <i class="bi bi-eye"></i> <span class="d-none d-sm-inline">View</span>
                                        </button>

                                        <?php if ($userRole === 'Admin'): ?>
                                            <form method="POST" class="d-inline-block ms-1" onsubmit="return confirm('Delete this activity log?');">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                                <input type="hidden" name="action" value="delete_single">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['LOG_ID']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> <span class="d-none d-sm-inline">Delete</span></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center p-2 border-top">
                    <div>
                        <?php if ($userRole === 'Admin'): ?>
                            <button class="btn btn-sm btn-danger" type="submit" onclick="return confirm('Delete selected logs?');"><i class="bi bi-trash me-1"></i> Delete Selected</button>
                        <?php endif; ?>
                        <span class="ms-3 small-muted">Showing <?php echo count($rows); ?> of <?php echo (int)$total; ?> results</span>
                    </div>

                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($p = 1; $p <= $totalPages; $p++):
                                $baseQuery['page'] = $p;
                                $linkUrl = '?' . htmlspecialchars(http_build_query($baseQuery));
                                ?>
                                <li class="page-item <?php if ($p == $page) echo 'active'; ?>"><a class="page-link" href="<?php echo $linkUrl; ?>"><?php echo $p; ?></a></li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>

            </form>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-info-circle"></i> Activity Details</h5>
        <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-0">
            <dt class="col-sm-4 small-muted">Log ID</dt><dd class="col-sm-8" id="v_logid"></dd>
            <dt class="col-sm-4 small-muted">User</dt><dd class="col-sm-8" id="v_user"></dd>
            <dt class="col-sm-4 small-muted">User Role</dt><dd class="col-sm-8" id="v_userrole"></dd>
            <dt class="col-sm-4 small-muted">Action</dt><dd class="col-sm-8" id="v_action"></dd>
            <dt class="col-sm-4 small-muted">Target Table</dt><dd class="col-sm-8" id="v_table"></dd>
            <dt class="col-sm-4 small-muted">Affected IDs</dt><dd class="col-sm-8" id="v_affected"></dd>
            <dt class="col-sm-4 small-muted">Time</dt><dd class="col-sm-8" id="v_time"></dd>
            <dt class="col-sm-4 small-muted">IP</dt><dd class="col-sm-8" id="v_ip"></dd>
            <dt class="col-sm-4 small-muted">Details</dt><dd class="col-sm-8"><pre id="v_details" style="white-space:pre-wrap;"></pre></dd>
        </dl>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Bulk Delete Confirm Modal (Admin) -->
<div class="modal fade" id="bulkDeleteConfirm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body text-center p-4">
        <h5 class="mb-3">Bulk delete selected logs?</h5>
        <p class="small-muted mb-3">This will permanently remove the selected activity logs.</p>
        <div class="d-flex justify-content-center gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button id="confirmBulkDeleteBtn" type="button" class="btn btn-danger">Delete</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>

<script>
    // guard DOM queries
    (function(){
        const selectAll = document.getElementById('select_all');
        if (selectAll) {
            selectAll.addEventListener('change', function(){
                document.querySelectorAll('.row_cb').forEach(cb => cb.checked = this.checked);
            });
        }

        // View modal fill
        const viewModal = document.getElementById('viewModal');
        if (viewModal) {
            viewModal.addEventListener('show.bs.modal', function (event) {
                const btn = event.relatedTarget;
                const data = btn.getAttribute('data-log');
                let obj = {};
                try { obj = JSON.parse(data); } catch(e){ obj = {}; }

                document.getElementById('v_logid').textContent = obj.LOG_ID || '';
                const userDisplay = (obj.USER_NAME ? obj.USER_NAME : ('User #' + (obj.USER_ID || '')));
                document.getElementById('v_user').textContent = userDisplay;
                document.getElementById('v_userrole').textContent = obj.USER_ROLE || '';
                document.getElementById('v_action').textContent = obj.ACTION_TYPE || '';
                document.getElementById('v_table').textContent = obj.TARGET_TABLE || '';
                document.getElementById('v_affected').textContent = obj.AFFECTED_IDS || '';
                document.getElementById('v_time').textContent = obj.LOG_TIME || '';
                document.getElementById('v_ip').textContent = obj.IP_ADDRESS || '';
                document.getElementById('v_details').textContent = obj.ACTION_DETAILS || '';
            });
        }

        // Bulk delete confirm - submits bulkForm
        const confirmBulkBtn = document.getElementById('confirmBulkDeleteBtn');
        if (confirmBulkBtn) {
            confirmBulkBtn.addEventListener('click', function(){
                const selected = document.querySelectorAll('.row_cb:checked');
                if (selected.length === 0) {
                    alert('Please select at least one row to delete.');
                    return;
                }
                // submit the bulk form
                const bulkForm = document.getElementById('bulkForm');
                if (bulkForm) bulkForm.submit();
            });
        }
    })();
</script>
