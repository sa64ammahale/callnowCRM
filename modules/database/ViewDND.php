<?php
// ViewDND.php - Comprehensive Do-Not-Disturb (DND) numbers management across Main & Temporary Databases

require_once '../../php_scripts/auth.php';
requirePermission('manage_database');

$csrf = ensureCsrfToken();
$alertMsg = '';
$alertType = '';

// Handle actions (POST: delete record or unmark DND)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? ($_POST['csrf'] ?? ''))) {
        $alertMsg = "CSRF validation failed. Please refresh and try again.";
        $alertType = "danger";
    } elseif (!isAdmin() && !can('manage_database')) {
        $alertMsg = "You do not have permission to modify DND records.";
        $alertType = "danger";
    } else {
        $action = trim($_POST['action']);
        $delId = intval($_POST['id'] ?? 0);
        $source = strtoupper(trim($_POST['source'] ?? 'MAIN'));
        $targetMobile = preg_replace('/\D/', '', trim($_POST['mobile'] ?? ''));

        if ($action === 'add') {
            $addMobile = preg_replace('/\D/', '', trim($_POST['add_mobile'] ?? ''));
            $addName = trim($_POST['add_name'] ?? '');
            $addCompany = trim($_POST['add_company'] ?? '');
            $addSource = strtoupper(trim($_POST['add_source'] ?? 'MAIN'));
            if (!in_array($addSource, ['MAIN', 'TEMP'], true)) {
                $addSource = 'MAIN';
            }

            if (strlen($addMobile) < 7) {
                $alertMsg = "Please enter a valid mobile number (at least 7 digits).";
                $alertType = "warning";
            } else {
                $uMain = mysqli_prepare($link, "UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_CALL_DIALED_STATUS = 'Do Not Call', BATCH_ASSIGNED_AT = NULL, LAST_DIALED_DATE_TIME = NOW(), MAINDATABASE_CALL_DIAL_TIME = NOW() WHERE MAINDATABASE_MOBILE = ?");
                $affectedMain = 0;
                if ($uMain) {
                    mysqli_stmt_bind_param($uMain, "s", $addMobile);
                    mysqli_stmt_execute($uMain);
                    $affectedMain = mysqli_stmt_affected_rows($uMain);
                    mysqli_stmt_close($uMain);
                }

                $uTemp = mysqli_prepare($link, "UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_STATUS = 'Do Not Call', BATCH_ASSIGNED_AT = NULL, LAST_DIALED_DATE_TIME = NOW() WHERE CUST_MOBILE = ?");
                $affectedTemp = 0;
                if ($uTemp) {
                    mysqli_stmt_bind_param($uTemp, "s", $addMobile);
                    mysqli_stmt_execute($uTemp);
                    $affectedTemp = mysqli_stmt_affected_rows($uTemp);
                    mysqli_stmt_close($uTemp);
                }

                if ($affectedMain === 0 && $affectedTemp === 0) {
                    if ($addSource === 'TEMP') {
                        $iStmt = mysqli_prepare($link, "INSERT INTO " . tn('TBL_TEMP') . " (CUST_NAME, CUST_MOBILE, CUST_COMPANY, CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, TEMP_UPLOAD_DATETIME) VALUES (?, ?, ?, 'Do Not Call', NOW(), NOW())");
                        if ($iStmt) {
                            $defName = $addName ?: 'DND Contact';
                            $defCompany = $addCompany ?: 'Manual DND';
                            mysqli_stmt_bind_param($iStmt, "sss", $defName, $addMobile, $defCompany);
                            mysqli_stmt_execute($iStmt);
                            mysqli_stmt_close($iStmt);
                        }
                    } else {
                        $iStmt = mysqli_prepare($link, "INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, MAINDATABASE_CALL_DIAL_TIME, MAINDATABASE_UPLOAD_DATETIME) VALUES (?, ?, ?, 'Do Not Call', NOW(), NOW(), NOW())");
                        if ($iStmt) {
                            $defName = $addName ?: 'DND Contact';
                            $defCompany = $addCompany ?: 'Manual DND';
                            mysqli_stmt_bind_param($iStmt, "sss", $defName, $addMobile, $defCompany);
                            mysqli_stmt_execute($iStmt);
                            mysqli_stmt_close($iStmt);
                        }
                    }
                }

                $alertMsg = "Mobile number $addMobile has been successfully added to Do-Not-Disturb (DND).";
                $alertType = "success";
                logActivity($link, 'DND_MANUAL_ADDED', "Added $addMobile to DND list ($addSource)");
            }
        } elseif ($delId > 0) {
            if ($action === 'delete') {
                if (!isAdmin()) {
                    $alertMsg = "Only Administrators are permitted to permanently delete database records.";
                    $alertType = "danger";
                } else {
                    $targetTable = ($source === 'TEMP') ? tn('TBL_TEMP') : tn('TBL_MAIN');
                    $stmt = mysqli_prepare($link, "DELETE FROM $targetTable WHERE ID = ?");
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "i", $delId);
                        if (mysqli_stmt_execute($stmt)) {
                            $alertMsg = "Record #$delId ($source) permanently deleted.";
                            $alertType = "success";
                            logActivity($link, 'DND_RECORD_DELETED', "Deleted DND record #$delId from $targetTable");
                        } else {
                            $alertMsg = "Database error while deleting: " . mysqli_error($link);
                            $alertType = "danger";
                        }
                        mysqli_stmt_close($stmt);
                    }
                }
            } elseif ($action === 'unmark') {
                $targetTable = ($source === 'TEMP') ? tn('TBL_TEMP') : tn('TBL_MAIN');
                $mobileField = ($source === 'TEMP') ? 'CUST_MOBILE' : 'MAINDATABASE_MOBILE';

                $mStmt = mysqli_prepare($link, "SELECT $mobileField FROM $targetTable WHERE ID = ? LIMIT 1");
                if ($mStmt) {
                    mysqli_stmt_bind_param($mStmt, "i", $delId);
                    mysqli_stmt_execute($mStmt);
                    $mRes = mysqli_stmt_get_result($mStmt);
                    if ($mRow = mysqli_fetch_assoc($mRes)) {
                        $dbMobile = preg_replace('/\D/', '', $mRow[$mobileField] ?? '');
                        if ($dbMobile !== '') {
                            $targetMobile = $dbMobile;
                        }
                    }
                    mysqli_stmt_close($mStmt);
                }

                if ($source === 'TEMP') {
                    $uStmt = mysqli_prepare($link, "UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_STATUS = 'Not Called', BATCH_ASSIGNED_AT = NULL WHERE ID = ?");
                } else {
                    $uStmt = mysqli_prepare($link, "UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_CALL_DIALED_STATUS = 'Not Called', BATCH_ASSIGNED_AT = NULL WHERE ID = ?");
                }
                if ($uStmt) {
                    mysqli_stmt_bind_param($uStmt, "i", $delId);
                    mysqli_stmt_execute($uStmt);
                    mysqli_stmt_close($uStmt);
                }

                if ($targetMobile !== '') {
                    $dndStatusInSql = "'do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd'";
                    $uMain = mysqli_prepare($link, "UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_CALL_DIALED_STATUS = 'Not Called', BATCH_ASSIGNED_AT = NULL WHERE MAINDATABASE_MOBILE = ? AND LOWER(MAINDATABASE_CALL_DIALED_STATUS) IN ($dndStatusInSql)");
                    if ($uMain) {
                        mysqli_stmt_bind_param($uMain, "s", $targetMobile);
                        mysqli_stmt_execute($uMain);
                        mysqli_stmt_close($uMain);
                    }

                    $uTemp = mysqli_prepare($link, "UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_STATUS = 'Not Called', BATCH_ASSIGNED_AT = NULL WHERE CUST_MOBILE = ? AND LOWER(CALL_DIALED_STATUS) IN ($dndStatusInSql)");
                    if ($uTemp) {
                        mysqli_stmt_bind_param($uTemp, "s", $targetMobile);
                        mysqli_stmt_execute($uTemp);
                        mysqli_stmt_close($uTemp);
                    }
                }

                $alertMsg = "Number successfully removed from DND and restored to calling queue.";
                $alertType = "success";
                logActivity($link, 'DND_UNMARKED', "Removed $targetMobile (Record #$delId) from DND");
            }
        } else {
            $alertMsg = "Invalid record ID.";
            $alertType = "warning";
        }
    }
}

// --- Pagination & Search setup
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');
$dbFilter = strtoupper(trim($_GET['source'] ?? 'ALL'));
if (!in_array($dbFilter, ['ALL', 'TEMP', 'MAIN'], true)) {
    $dbFilter = 'ALL';
}

$searchCondition = "";
$searchParams = [];
$searchTypes = "";

if ($search !== '') {
    $cleanDigits = preg_replace('/\D/', '', $search);
    if ($cleanDigits !== '') {
        $searchCondition = "AND (mobile LIKE ? OR name LIKE ? OR company LIKE ?)";
        $searchParams = ["%$cleanDigits%", "%$search%", "%$search%"];
        $searchTypes = "sss";
    } else {
        $searchCondition = "AND (name LIKE ? OR company LIKE ?)";
        $searchParams = ["%$search%", "%$search%"];
        $searchTypes = "ss";
    }
}

$dndVariants = "LOWER(status) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')";

$cntTempRes = mysqli_query($link, "SELECT COUNT(*) FROM " . tn('TBL_TEMP') . " WHERE LOWER(CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')");
$totalTemp = ($cntTempRes && $r = mysqli_fetch_row($cntTempRes)) ? intval($r[0]) : 0;

$cntMainRes = mysqli_query($link, "SELECT COUNT(*) FROM " . tn('TBL_MAIN') . " WHERE LOWER(MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')");
$totalMain = ($cntMainRes && $r = mysqli_fetch_row($cntMainRes)) ? intval($r[0]) : 0;

$unionQueries = [];
if ($dbFilter === 'ALL' || $dbFilter === 'TEMP') {
    $unionQueries[] = "
        SELECT 
            ID, 
            CUST_NAME AS name, 
            CUST_MOBILE AS mobile, 
            CUST_COMPANY AS company, 
            CALL_DIALED_STATUS AS status,
            'TEMP' AS source, 
            COALESCE(LAST_DIALED_DATE_TIME, TEMP_UPLOAD_DATETIME) AS marked_at 
        FROM " . tn('TBL_TEMP') . " 
        WHERE LOWER(CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
    ";
}
if ($dbFilter === 'ALL' || $dbFilter === 'MAIN') {
    $unionQueries[] = "
        SELECT 
            ID, 
            MAINDATABASE_NAME AS name, 
            MAINDATABASE_MOBILE AS mobile, 
            MAINDATABASE_COMPANY AS company, 
            MAINDATABASE_CALL_DIALED_STATUS AS status,
            'MAIN' AS source, 
            COALESCE(MAINDATABASE_CALL_DIAL_TIME, LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME) AS marked_at 
        FROM " . tn('TBL_MAIN') . " 
        WHERE LOWER(MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
    ";
}

$baseUnionSql = implode(" UNION ALL ", $unionQueries);

$countSql = "SELECT COUNT(*) FROM ($baseUnionSql) AS combined WHERE 1=1 $searchCondition";
$stmtCount = mysqli_prepare($link, $countSql);
if ($stmtCount) {
    if (!empty($searchParams)) {
        mysqli_stmt_bind_param($stmtCount, $searchTypes, ...$searchParams);
    }
    mysqli_stmt_execute($stmtCount);
    $resCount = mysqli_stmt_get_result($stmtCount);
    $totalRecords = ($resCount && $row = mysqli_fetch_row($resCount)) ? intval($row[0]) : 0;
    mysqli_stmt_close($stmtCount);
} else {
    $totalRecords = 0;
}

$totalPages = max(1, intval(ceil($totalRecords / $limit)));
if ($page > $totalPages) $page = $totalPages;

$dataSql = "
    SELECT ID, name, mobile, company, status, source, marked_at 
    FROM ($baseUnionSql) AS combined 
    WHERE 1=1 $searchCondition 
    ORDER BY marked_at DESC, ID DESC 
    LIMIT ? OFFSET ?
";
$stmtData = mysqli_prepare($link, $dataSql);
$dndRecords = [];

if ($stmtData) {
    if (!empty($searchParams)) {
        $combinedTypes = $searchTypes . "ii";
        $combinedParams = array_merge($searchParams, [$limit, $offset]);
        mysqli_stmt_bind_param($stmtData, $combinedTypes, ...$combinedParams);
    } else {
        mysqli_stmt_bind_param($stmtData, "ii", $limit, $offset);
    }
    mysqli_stmt_execute($stmtData);
    $resData = mysqli_stmt_get_result($stmtData);
    if ($resData) {
        while ($row = mysqli_fetch_assoc($resData)) {
            $dndRecords[] = $row;
        }
    }
    mysqli_stmt_close($stmtData);
}

function fmtDateTime($val) {
    if (empty($val) || $val === '0000-00-00 00:00:00') return '<span class="text-muted fs-xs">Not Recorded</span>';
    $t = strtotime($val);
    if (!$t) return htmlspecialchars($val);
    return '<span class="fw-semibold text-dark fs-xs">' . date('d M Y', $t) . '</span> <span class="text-muted fs-xs">' . date('h:i A', $t) . '</span>';
}

function buildQuery($overrides = []) {
    $q = array_merge($_GET, $overrides);
    return http_build_query($q);
}

$pageTitle = 'Do Not Disturb (DND) • CallNow CRM';
include '../../php_scripts/header.php';
?>

<div class="container-fluid px-3 py-3">
    <!-- Module Header -->
    <div class="module-header mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 fs-xs">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/database/data_management_main.php') ?>">Data Management</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Do Not Disturb (DND)</li>
                </ol>
            </nav>
            <h1 class="h4 fw-bold text-dark mb-0">Do Not Disturb (DND) Register</h1>
            <div class="text-muted fs-xs">Globally excluded phone numbers protected against telecalling dials and automated batch queue allocation</div>
        </div>
        <div class="module-header-actions">
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDndModal">
                <i class="bi bi-shield-plus me-1"></i> Add DND Number
            </button>
            <a href="<?= url('modules/database/data_management_main.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-database me-1"></i> Main DB
            </a>
            <a href="<?= url('modules/database/data_management_temporary.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-database-gear me-1"></i> Temp DB
            </a>
        </div>
    </div>

    <?php if ($alertMsg): ?>
        <div class="alert alert-<?= htmlspecialchars($alertType ?: 'info') ?> alert-dismissible fade show mb-3" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi <?= $alertType === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2 fs-5"></i>
                <div class="fs-xs"><?= htmlspecialchars($alertMsg) ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Metric KPI Cards -->
    <div class="row g-2 mb-3">
        <div class="col-sm-6 col-md-4">
            <div class="metric-card">
                <div class="metric-card-title">Active DND Contacts</div>
                <div class="metric-card-value text-danger"><?= number_format($totalRecords) ?></div>
                <div class="metric-card-subtitle text-muted">Protected phone numbers</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-4">
            <div class="metric-card">
                <div class="metric-card-title">Main Database DND</div>
                <div class="metric-card-value text-primary"><?= number_format($totalMain) ?></div>
                <div class="metric-card-subtitle text-muted">Permanent records</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-4">
            <div class="metric-card">
                <div class="metric-card-title">Staging Database DND</div>
                <div class="metric-card-value text-secondary"><?= number_format($totalTemp) ?></div>
                <div class="metric-card-subtitle text-muted">Temporary staging records</div>
            </div>
        </div>
    </div>

    <!-- Action Toolbar & Search -->
    <div class="crm-card mb-3">
        <div class="crm-card-body py-2">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <!-- Repository filter tabs -->
                <div class="btn-group btn-group-sm" role="group">
                    <a href="<?= url('modules/database/ViewDND.php') ?>?<?= buildQuery(['source' => 'ALL', 'page' => 1]) ?>" class="btn <?= $dbFilter === 'ALL' ? 'btn-primary' : 'btn-secondary' ?>">
                        All Sources (<?= number_format($totalMain + $totalTemp) ?>)
                    </a>
                    <a href="<?= url('modules/database/ViewDND.php') ?>?<?= buildQuery(['source' => 'MAIN', 'page' => 1]) ?>" class="btn <?= $dbFilter === 'MAIN' ? 'btn-primary' : 'btn-secondary' ?>">
                        Main DB (<?= number_format($totalMain) ?>)
                    </a>
                    <a href="<?= url('modules/database/ViewDND.php') ?>?<?= buildQuery(['source' => 'TEMP', 'page' => 1]) ?>" class="btn <?= $dbFilter === 'TEMP' ? 'btn-primary' : 'btn-secondary' ?>">
                        Temporary DB (<?= number_format($totalTemp) ?>)
                    </a>
                </div>

                <!-- Search form -->
                <form method="GET" class="d-flex align-items-center gap-1">
                    <div class="input-group input-group-sm" style="min-width: 280px;">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search mobile, name, or company..." value="<?= htmlspecialchars($search) ?>">
                        <?php if ($dbFilter !== 'ALL'): ?><input type="hidden" name="source" value="<?= htmlspecialchars($dbFilter) ?>"><?php endif; ?>
                        <button class="btn btn-secondary" type="submit">Filter</button>
                        <?php if ($search): ?>
                            <a class="btn btn-secondary" href="<?= url('modules/database/ViewDND.php') ?><?= $dbFilter !== 'ALL' ? '?source=' . htmlspecialchars($dbFilter) : '' ?>" title="Clear search"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="crm-card">
        <div class="crm-card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Customer Name</th>
                            <th>Mobile Number</th>
                            <th>Company / Remarks</th>
                            <th>Source</th>
                            <th>Marked DND Date</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($dndRecords)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-shield-check text-success fs-1 d-block mb-2"></i>
                                <div class="fw-bold mb-1">No DND records found</div>
                                <div class="fs-xs text-muted">
                                    <?= $search ? "No records matched your search query. Try clearing the filter." : "There are currently no numbers marked as Do-Not-Disturb in the selected database." ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dndRecords as $r): ?>
                            <tr>
                                <td>
                                    <strong class="text-dark fs-xs"><?= htmlspecialchars($r['name'] ?: '—') ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge-secondary fs-xs font-monospace"><?= htmlspecialchars($r['mobile'] ?: '—') ?></span>
                                </td>
                                <td>
                                    <span class="text-muted fs-xs"><?= htmlspecialchars($r['company'] ?: '—') ?></span>
                                </td>
                                <td>
                                    <?php if ($r['source'] === 'TEMP'): ?>
                                        <span class="badge badge-warning fs-xs">
                                            <i class="bi bi-clock-history me-1"></i> Temp DB
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-primary fs-xs">
                                            <i class="bi bi-database me-1"></i> Main DB
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= fmtDateTime($r['marked_at']) ?>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <!-- Restore / Unmark DND -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Restore <?= htmlspecialchars($r['mobile']) ?> to active calling queue?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="unmark">
                                            <input type="hidden" name="id" value="<?= intval($r['ID']) ?>">
                                            <input type="hidden" name="source" value="<?= htmlspecialchars($r['source']) ?>">
                                            <input type="hidden" name="mobile" value="<?= htmlspecialchars($r['mobile']) ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm py-1 px-2 fs-xs" title="Restore to Calling Queue">
                                                <i class="bi bi-telephone-plus me-1 text-success"></i> Restore
                                            </button>
                                        </form>

                                        <!-- Delete Record -->
                                        <?php if (isAdmin()): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete record #<?= intval($r['ID']) ?> from <?= htmlspecialchars($r['source']) ?> database?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= intval($r['ID']) ?>">
                                                <input type="hidden" name="source" value="<?= htmlspecialchars($r['source']) ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm py-1 px-2 fs-xs" title="Permanently Delete">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination Footer -->
        <?php if ($totalRecords > 0): ?>
            <div class="crm-card-footer d-flex align-items-center justify-content-between flex-wrap gap-2 px-3 py-2 border-top">
                <div class="fs-xs text-muted">
                    Showing <strong><?= number_format($offset + 1) ?></strong>–<strong><?= number_format(min($offset + $limit, $totalRecords)) ?></strong> of <strong><?= number_format($totalRecords) ?></strong> records
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav aria-label="DND Pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= url('modules/database/ViewDND.php') ?>?<?= buildQuery(['page' => $page - 1]) ?>" aria-label="Previous">&laquo;</a>
                            </li>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);

                            if ($start > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?= url('modules/database/ViewDND.php') ?>?<?= buildQuery(['page' => 1]) ?>">1</a></li>
                                <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $start; $i <= $end; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= url('modules/database/ViewDND.php') ?>?<?= buildQuery(['page' => $i]) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($end < $totalPages): ?>
                                <?php if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                                <li class="page-item"><a class="page-link" href="<?= url('modules/database/ViewDND.php') ?>?<?= buildQuery(['page' => $totalPages]) ?>"><?= $totalPages ?></a></li>
                            <?php endif; ?>

                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= url('modules/database/ViewDND.php') ?>?<?= buildQuery(['page' => $page + 1]) ?>" aria-label="Next">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add DND Number Modal -->
<div class="modal fade" id="addDndModal" tabindex="-1" aria-labelledby="addDndModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="addDndModalLabel">
                    <i class="bi bi-shield-plus text-danger me-2"></i>Add Number to DND
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="add_mobile">Mobile Number <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                        <input type="text" name="add_mobile" id="add_mobile" class="form-control" placeholder="10-digit mobile" required pattern="[0-9\s\+\-]{7,15}">
                    </div>
                    <div class="form-text fs-xs">Matching records across both Main & Temporary databases will be updated to DND.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="add_name">Customer Name <small class="text-muted">(Optional)</small></label>
                    <input type="text" name="add_name" id="add_name" class="form-control" placeholder="Customer name">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="add_company">Company / Reason <small class="text-muted">(Optional)</small></label>
                    <input type="text" name="add_company" id="add_company" class="form-control" placeholder="Organization or notes">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="add_source">Default Repository</label>
                    <select name="add_source" id="add_source" class="form-select">
                        <option value="MAIN" selected>Main Database</option>
                        <option value="TEMP">Temporary Staging Database</option>
                    </select>
                    <div class="form-text fs-xs">Target database if this is a newly inserted number.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-shield-x me-1"></i> Block Number</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>
