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
$limit = 25;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');
$dbFilter = strtoupper(trim($_GET['source'] ?? 'ALL'));
if (!in_array($dbFilter, ['ALL', 'MAIN', 'TEMP'], true)) {
    $dbFilter = 'ALL';
}

// Construct WHERE clauses matching DND statuses across both tables
$dndStatusInSql = "'do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd'";
$whereMain = "LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) IN ($dndStatusInSql)";
$whereTemp = "LOWER(t.CALL_DIALED_STATUS) IN ($dndStatusInSql)";

$searchParamsMain = [];
$searchParamsTemp = [];
$searchTypesMain = '';
$searchTypesTemp = '';

if ($search !== '') {
    $searchParam = "%{$search}%";
    $whereMain .= " AND (m.MAINDATABASE_MOBILE LIKE ? OR m.MAINDATABASE_NAME LIKE ? OR m.MAINDATABASE_COMPANY LIKE ?)";
    $searchParamsMain = [$searchParam, $searchParam, $searchParam];
    $searchTypesMain = 'sss';

    $whereTemp .= " AND (t.CUST_MOBILE LIKE ? OR t.CUST_NAME LIKE ? OR t.CUST_COMPANY LIKE ?)";
    $searchParamsTemp = [$searchParam, $searchParam, $searchParam];
    $searchTypesTemp = 'sss';
}

// Total Count
$countSqlMain = "SELECT COUNT(*) AS cnt FROM " . tn('TBL_MAIN') . " m WHERE $whereMain";
$countSqlTemp = "SELECT COUNT(*) AS cnt FROM " . tn('TBL_TEMP') . " t WHERE $whereTemp";

$totalMain = 0;
$totalTemp = 0;

if ($dbFilter === 'ALL' || $dbFilter === 'MAIN') {
    if ($stmt = mysqli_prepare($link, $countSqlMain)) {
        if ($searchTypesMain !== '') {
            mysqli_stmt_bind_param($stmt, $searchTypesMain, ...$searchParamsMain);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($r = mysqli_fetch_assoc($res)) $totalMain = intval($r['cnt'] ?? 0);
        mysqli_stmt_close($stmt);
    }
}

if ($dbFilter === 'ALL' || $dbFilter === 'TEMP') {
    if ($stmt = mysqli_prepare($link, $countSqlTemp)) {
        if ($searchTypesTemp !== '') {
            mysqli_stmt_bind_param($stmt, $searchTypesTemp, ...$searchParamsTemp);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($r = mysqli_fetch_assoc($res)) $totalTemp = intval($r['cnt'] ?? 0);
        mysqli_stmt_close($stmt);
    }
}

$totalRecords = ($dbFilter === 'MAIN') ? $totalMain : (($dbFilter === 'TEMP') ? $totalTemp : ($totalMain + $totalTemp));
$totalPages = max(1, (int)ceil($totalRecords / $limit));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

// Unified Data Fetch Query
$queries = [];
$bindParams = [];
$bindTypes = '';

if ($dbFilter === 'ALL' || $dbFilter === 'MAIN') {
    $queries[] = "SELECT 
        m.ID, 
        m.MAINDATABASE_NAME AS name, 
        m.MAINDATABASE_MOBILE AS mobile, 
        m.MAINDATABASE_COMPANY AS company, 
        COALESCE(m.LAST_DIALED_DATE_TIME, m.MAINDATABASE_CALL_DIAL_TIME) AS marked_at,
        m.MAINDATABASE_CALL_DIALED_STATUS AS status,
        'MAIN' AS source
    FROM " . tn('TBL_MAIN') . " m
    WHERE $whereMain";
    
    foreach ($searchParamsMain as $p) $bindParams[] = $p;
    $bindTypes .= $searchTypesMain;
}

if ($dbFilter === 'ALL' || $dbFilter === 'TEMP') {
    $queries[] = "SELECT 
        t.ID, 
        t.CUST_NAME AS name, 
        t.CUST_MOBILE AS mobile, 
        t.CUST_COMPANY AS company, 
        COALESCE(t.LAST_DIALED_DATE_TIME, t.TEMP_UPLOAD_DATETIME) AS marked_at,
        t.CALL_DIALED_STATUS AS status,
        'TEMP' AS source
    FROM " . tn('TBL_TEMP') . " t
    WHERE $whereTemp";
    
    foreach ($searchParamsTemp as $p) $bindParams[] = $p;
    $bindTypes .= $searchTypesTemp;
}

$unionSql = implode(" UNION ALL ", $queries) . " ORDER BY marked_at DESC, ID DESC LIMIT ? OFFSET ?";
$bindParams[] = $limit;
$bindParams[] = $offset;
$bindTypes .= 'ii';

$dndRecords = [];
if ($stmt = mysqli_prepare($link, $unionSql)) {
    mysqli_stmt_bind_param($stmt, $bindTypes, ...$bindParams);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $dndRecords[] = $r;
        }
    }
    mysqli_stmt_close($stmt);
} else {
    $alertMsg = "Failed to load DND records: " . mysqli_error($link);
    $alertType = "danger";
}

function fmtDateTime($raw) {
    if (empty($raw) || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') return '<span class="text-muted">—</span>';
    $ts = strtotime($raw);
    if ($ts === false) return '<span class="text-muted">—</span>';
    return '<span class="fw-semibold">' . date("d M Y", $ts) . '</span><br><small class="text-muted">' . date("h:i A", $ts) . '</small>';
}

function buildQuery($overrides = []) {
    $q = array_merge($_GET, $overrides);
    return http_build_query($q);
}

$pageTitle = 'DND Numbers List - CallNow';
include '../../php_scripts/header.php';
?>

<style>
/* ── Hero ───────────────────────────────────────────── */
.dnd-hero {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 55%, #7f1d1d 100%);
    color: #ffffff !important;
    padding: 1.75rem 0;
    margin-bottom: 1.75rem;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.18);
}
.dnd-hero * { color: inherit; }
.dnd-hero h1 { font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 0.25rem; }
.dnd-hero p { margin: 0; opacity: 0.9; font-size: 0.9rem; }

.dnd-stat-pill {
    background: rgba(255, 255, 255, 0.16);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: var(--radius-lg);
    padding: 0.55rem 1rem;
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
}
.dnd-stat-val { font-size: 1.25rem; font-weight: 800; color: #ffffff !important; line-height: 1; }
.dnd-stat-lbl { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.85; }

/* ── Special Card ───────────────────────────────────── */
.rp-special-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04), 0 1px 3px rgba(0, 0, 0, 0.02);
    overflow: hidden;
    margin-bottom: 2rem;
    transition: box-shadow 0.2s ease;
}
.rp-special-card:hover {
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.07), 0 2px 6px rgba(0, 0, 0, 0.03);
}

.rp-special-card-head.dnd-head {
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.08) 0%, rgba(220, 38, 38, 0.04) 100%);
    border-bottom: 2px solid rgba(239, 68, 68, 0.18);
    padding: 1.15rem 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.rp-head-icon-dnd {
    width: 42px; height: 42px; border-radius: var(--radius-lg);
    background: linear-gradient(135deg, #ef4444, #b91c1c);
    color: #ffffff !important; display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}
.rp-head-icon-dnd i { color: #ffffff !important; }

/* ── Special Table with Alternating Colors ──────────── */
.rp-special-table {
    margin-bottom: 0;
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}
.rp-special-table thead th {
    background: var(--surface-2) !important;
    color: var(--ink-soft) !important;
    font-size: 0.72rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.04em !important;
    font-weight: 700 !important;
    padding: 0.95rem 1rem !important;
    border-bottom: 2px solid var(--border) !important;
    white-space: nowrap !important;
    vertical-align: middle !important;
}
.rp-special-table tbody td {
    padding: 0.85rem 1rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--border);
    font-size: 0.86rem;
    transition: background 0.15s ease;
}
.rp-special-table tbody tr:nth-child(even) td {
    background-color: rgba(239, 68, 68, 0.02);
}
[data-bs-theme="dark"] .rp-special-table tbody tr:nth-child(even) td {
    background-color: rgba(255, 255, 255, 0.02);
}
.rp-special-table tbody tr:hover td {
    background-color: rgba(239, 68, 68, 0.06) !important;
}

.badge-mobile {
    font-family: var(--font-mono, monospace);
    font-weight: 600;
    font-size: 0.84rem;
    color: var(--ink);
    background: var(--surface-2);
    padding: 0.25rem 0.55rem;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
}

.source-badge-main {
    background: rgba(99, 102, 241, 0.12);
    color: #4f46e5;
    font-size: 0.74rem;
    font-weight: 700;
    padding: 0.25rem 0.55rem;
    border-radius: 999px;
    border: 1px solid rgba(99, 102, 241, 0.25);
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}
.source-badge-temp {
    background: rgba(245, 158, 11, 0.12);
    color: #d97706;
    font-size: 0.74rem;
    font-weight: 700;
    padding: 0.25rem 0.55rem;
    border-radius: 999px;
    border: 1px solid rgba(245, 158, 11, 0.25);
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}

.dnd-badge {
    background: rgba(239, 68, 68, 0.14);
    color: #dc2626;
    font-size: 0.74rem;
    font-weight: 700;
    padding: 0.25rem 0.55rem;
    border-radius: 999px;
    border: 1px solid rgba(239, 68, 68, 0.28);
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}
</style>

<!-- Hero Section -->
<div class="dnd-hero">
    <div class="container-fluid px-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge bg-white text-danger fw-bold text-uppercase px-2 py-1" style="font-size:0.7rem; letter-spacing:0.05em;">
                        <i class="bi bi-shield-slash-fill me-1"></i> Global Call Protection
                    </span>
                </div>
                <h1>Do Not Disturb (DND) List</h1>
                <p>Numbers marked as DND in Temporary or Main databases are strictly blocked from mobile and queue dialing.</p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="dnd-stat-pill">
                    <i class="bi bi-slash-circle fs-4 text-white"></i>
                    <div>
                        <div class="dnd-stat-val"><?= number_format($totalRecords) ?></div>
                        <div class="dnd-stat-lbl">Active DND Numbers</div>
                    </div>
                </div>
                <div class="dnd-stat-pill">
                    <i class="bi bi-database fs-4 text-white"></i>
                    <div>
                        <div class="dnd-stat-val"><?= number_format($totalTemp) ?> / <?= number_format($totalMain) ?></div>
                        <div class="dnd-stat-lbl">Temp / Main DB</div>
                    </div>
                </div>
                <button type="button" class="btn btn-light text-danger fw-bold shadow-sm d-inline-flex align-items-center gap-2 px-3 py-2" style="border-radius: var(--radius-lg);" data-bs-toggle="modal" data-bs-target="#addDndModal">
                    <i class="bi bi-plus-circle-fill fs-5"></i>
                    <span>Add DND Number</span>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-4">
    <?php if ($alertMsg): ?>
        <div class="alert alert-<?= htmlspecialchars($alertType ?: 'info') ?> alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi <?= $alertType === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
            <?= htmlspecialchars($alertMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Main Special Card -->
    <div class="card rp-special-card">
        <div class="rp-special-card-head dnd-head">
            <div class="d-flex align-items-center gap-3">
                <div class="rp-head-icon-dnd">
                    <i class="bi bi-telephone-x-fill"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0 text-dark">DND Records Directory</h5>
                    <div class="small text-muted">Showing page <?= $page ?> of <?= $totalPages ?> (<?= number_format($totalRecords) ?> total records)</div>
                </div>
            </div>

            <!-- Filter & Search Bar -->
            <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
                <div class="btn-group btn-group-sm" role="group">
                    <a href="?<?= buildQuery(['source' => 'ALL', 'page' => 1]) ?>" class="btn <?= $dbFilter === 'ALL' ? 'btn-danger' : 'btn-outline-secondary' ?>">All Databases (<?= number_format($totalMain + $totalTemp) ?>)</a>
                    <a href="?<?= buildQuery(['source' => 'TEMP', 'page' => 1]) ?>" class="btn <?= $dbFilter === 'TEMP' ? 'btn-danger' : 'btn-outline-secondary' ?>">Temporary DB (<?= number_format($totalTemp) ?>)</a>
                    <a href="?<?= buildQuery(['source' => 'MAIN', 'page' => 1]) ?>" class="btn <?= $dbFilter === 'MAIN' ? 'btn-danger' : 'btn-outline-secondary' ?>">Main DB (<?= number_format($totalMain) ?>)</a>
                </div>

                <div class="input-group input-group-sm shadow-sm" style="min-width: 260px;">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Mobile, Name or Company" value="<?= htmlspecialchars($search) ?>">
                    <?php if ($dbFilter !== 'ALL'): ?><input type="hidden" name="source" value="<?= htmlspecialchars($dbFilter) ?>"><?php endif; ?>
                    <button class="btn btn-danger fw-semibold" type="submit">Search</button>
                    <?php if ($search): ?>
                        <a class="btn btn-outline-secondary" href="<?= url('modules/database/ViewDND.php') ?><?= $dbFilter !== 'ALL' ? '?source=' . htmlspecialchars($dbFilter) : '' ?>" title="Clear search"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Table View -->
        <div class="table-responsive">
            <table class="rp-special-table">
                <thead>
                    <tr>
                        <th style="width: 25%;">Customer Name</th>
                        <th style="width: 16%;">Mobile Number</th>
                        <th style="width: 22%;">Company</th>
                        <th style="width: 14%;">Database Source</th>
                        <th style="width: 12%;">Marked DND On</th>
                        <th style="width: 11%;" class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($dndRecords)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-shield-check display-6 text-success d-block mb-2"></i>
                            <div class="h6 fw-bold mb-1">No DND records found</div>
                            <div class="small text-muted">
                                <?= $search ? "No records matched your search query. Try clearing the filter." : "There are currently no numbers marked as Do-Not-Disturb in the selected database." ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dndRecords as $r): ?>
                        <tr>
                            <td>
                                <strong class="text-dark"><?= htmlspecialchars($r['name'] ?: '—') ?></strong>
                            </td>
                            <td>
                                <span class="badge-mobile"><?= htmlspecialchars($r['mobile'] ?: '—') ?></span>
                            </td>
                            <td>
                                <span class="text-secondary"><?= htmlspecialchars($r['company'] ?: '—') ?></span>
                            </td>
                            <td>
                                <?php if ($r['source'] === 'TEMP'): ?>
                                    <span class="source-badge-temp" title="Temporary Leads Database">
                                        <i class="bi bi-clock-history"></i> Temporary DB
                                    </span>
                                <?php else: ?>
                                    <span class="source-badge-main" title="Main Customer Database">
                                        <i class="bi bi-database-check"></i> Main DB
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= fmtDateTime($r['marked_at']) ?>
                            </td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex align-items-center gap-1">
                                    <!-- Unmark DND (Restore to calling queue) -->
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove <?= htmlspecialchars($r['mobile']) ?> from DND list and restore to active calling queue?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="unmark">
                                        <input type="hidden" name="id" value="<?= intval($r['ID']) ?>">
                                        <input type="hidden" name="source" value="<?= htmlspecialchars($r['source']) ?>">
                                        <input type="hidden" name="mobile" value="<?= htmlspecialchars($r['mobile']) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Unmark DND (Restore to Calling Queue)" style="padding: 0.25rem 0.5rem; font-size: 0.78rem;">
                                            <i class="bi bi-telephone-check me-1"></i> Restore
                                        </button>
                                    </form>

                                    <!-- Delete Record -->
                                    <?php if (isAdmin()): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete this customer record from <?= htmlspecialchars($r['source']) ?> database?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= intval($r['ID']) ?>">
                                            <input type="hidden" name="source" value="<?= htmlspecialchars($r['source']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Permanently Delete Lead" style="padding: 0.25rem 0.5rem; font-size: 0.78rem;">
                                                <i class="bi bi-trash"></i>
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

        <!-- Pagination Footer -->
        <?php if ($totalRecords > 0): ?>
            <div class="card-footer bg-surface border-top d-flex align-items-center justify-content-between flex-wrap gap-2 px-3 py-2">
                <div class="small text-muted">
                    Showing <strong><?= number_format($offset + 1) ?></strong>–<strong><?= number_format(min($offset + $limit, $totalRecords)) ?></strong> of <strong><?= number_format($totalRecords) ?></strong> DND records
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav aria-label="DND Pagination">
                        <ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= buildQuery(['page' => $page - 1]) ?>" aria-label="Previous">&laquo;</a>
                            </li>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);

                            if ($start > 1): ?>
                                <li class="page-item"><a class="page-link" href="?<?= buildQuery(['page' => 1]) ?>">1</a></li>
                                <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $start; $i <= $end; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= buildQuery(['page' => $i]) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($end < $totalPages): ?>
                                <?php if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                                <li class="page-item"><a class="page-link" href="?<?= buildQuery(['page' => $totalPages]) ?>"><?= $totalPages ?></a></li>
                            <?php endif; ?>

                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= buildQuery(['page' => $page + 1]) ?>" aria-label="Next">&raquo;</a>
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
        <div class="modal-content shadow border-0" style="border-radius: var(--radius-xl); overflow: hidden;">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header bg-danger text-white px-4 py-3">
                    <h5 class="modal-title fw-bold" id="addDndModalLabel">
                        <i class="bi bi-shield-slash-fill me-2"></i> Add Number to DND
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Mobile Number <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-surface-2"><i class="bi bi-telephone-fill text-muted"></i></span>
                            <input type="text" name="add_mobile" class="form-control" placeholder="e.g. 9876543210" required pattern="[0-9\s\+\-]{7,15}">
                        </div>
                        <div class="form-text">All existing records matching this number across Main & Temporary databases will be marked as DND.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Customer Name <small class="text-muted">(Optional)</small></label>
                        <input type="text" name="add_name" class="form-control" placeholder="Customer or Contact Name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Company / Remark <small class="text-muted">(Optional)</small></label>
                        <input type="text" name="add_company" class="form-control" placeholder="Company or Reason for DND">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default Target Database</label>
                        <select name="add_source" class="form-select">
                            <option value="MAIN" selected>Main Customer Database</option>
                            <option value="TEMP">Temporary Leads Database</option>
                        </select>
                        <div class="form-text">Used if this number is new and will be registered as a new record.</div>
                    </div>
                </div>
                <div class="modal-footer bg-surface-2 px-4 py-3 border-top">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-bold"><i class="bi bi-shield-x me-1"></i> Block Number (Mark DND)</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>
