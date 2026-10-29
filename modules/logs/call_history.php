<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../php_scripts/team_auth.php';
requirePermission('view_reports');

if (!function_exists('ensureCallHistorySchema')) {
    function ensureCallHistorySchema(mysqli $link): void {
        ensureApiSchema($link);
        $res = mysqli_query($link, "SHOW COLUMNS FROM " . tn('TBL_CALL_LOGS'));
        $existing = [];
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $existing[strtolower($r['Field'])] = true;
            }
        }
        if (!isset($existing['recording_url'])) {
            @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN recording_url VARCHAR(500) NULL AFTER notes");
        }
        if (!isset($existing['next_followup'])) {
            @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN next_followup DATETIME NULL AFTER recording_url");
        }
        if (!isset($existing['call_time'])) {
            @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN call_time DATETIME NULL AFTER next_followup");
        }
        if (!isset($existing['record_id'])) {
            if (isset($existing['lead_id'])) {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " CHANGE COLUMN lead_id record_id INT NOT NULL DEFAULT 0");
            } else {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN record_id INT NOT NULL DEFAULT 0 AFTER id");
            }
        }
        if (!isset($existing['source'])) {
            if (isset($existing['call_type'])) {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " CHANGE COLUMN call_type source ENUM('TEMP','MAIN','LEADS') NOT NULL DEFAULT 'MAIN'");
            } else {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN source ENUM('TEMP','MAIN','LEADS') NOT NULL DEFAULT 'MAIN' AFTER record_id");
            }
        }
        if (!isset($existing['call_status'])) {
            if (isset($existing['status'])) {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " CHANGE COLUMN status call_status VARCHAR(50) NOT NULL DEFAULT 'Connected'");
            } else {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN call_status VARCHAR(50) NOT NULL DEFAULT 'Connected' AFTER user_id");
            }
        }
        if (!isset($existing['call_duration'])) {
            if (isset($existing['duration_seconds'])) {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " CHANGE COLUMN duration_seconds call_duration INT NOT NULL DEFAULT 0");
            } else {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN call_duration INT NOT NULL DEFAULT 0 AFTER call_status");
            }
        }
    }
}
ensureCallHistorySchema($link);

$sourceFilter = $_GET['source'] ?? 'all';
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(10, (int)($_GET['limit'] ?? 25)));
$offset = ($page - 1) * $limit;

$whereParts = ['1=1'];
$params = [];
$types = '';

if (in_array($sourceFilter, ['TEMP', 'MAIN', 'LEADS'])) {
    $whereParts[] = 'cl.source = ?';
    $params[] = $sourceFilter;
    $types .= 's';
}
if ($statusFilter !== '') {
    $whereParts[] = 'cl.call_status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
if ($dateFrom) {
    $whereParts[] = 'DATE(cl.created_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo) {
    $whereParts[] = 'DATE(cl.created_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}
if ($search !== '') {
    $whereParts[] = '(COALESCE(t.CUST_NAME, m.MAINDATABASE_NAME, l.NAME, "") LIKE ? OR COALESCE(t.CUST_MOBILE, m.MAINDATABASE_MOBILE, l.MOBILE, "") LIKE ? OR cl.notes LIKE ?)';
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if (USER_ROLE === 'Supervisor') {
    $whereParts[] = 'u.TEAM_ID = ?';
    $params[] = USER_TEAM_ID;
    $types .= 'i';
} elseif (USER_ROLE === 'Officer') {
    $whereParts[] = 'u.ID = ?';
    $params[] = USER_ID;
    $types .= 'i';
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

$countSql = "
    SELECT COUNT(*) AS c
    FROM " . tn('TBL_CALL_LOGS') . " cl
    JOIN " . tn('TBL_USERS') . " u ON cl.user_id = u.ID
    LEFT JOIN " . tn('TBL_TEMP') . " t ON cl.source = 'TEMP' AND cl.record_id = t.ID
    LEFT JOIN " . tn('TBL_MAIN') . " m ON cl.source = 'MAIN' AND cl.record_id = m.ID
    LEFT JOIN " . tn('TBL_LEADS') . " l ON cl.source = 'LEADS' AND cl.record_id = l.lead_id
    $whereSql
";

$countStmt = mysqli_prepare($link, $countSql);
if ($countStmt === false) {
    $err = mysqli_error($link);
    die('Count prepare failed: ' . htmlspecialchars($err ?: 'Unknown database error'));
}
if ($types !== '') {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
}
mysqli_stmt_execute($countStmt);
$totalRows = (int)mysqli_stmt_get_result($countStmt)->fetch_assoc()['c'];
mysqli_stmt_close($countStmt);

$totalPages = $totalRows > 0 ? (int)ceil($totalRows / $limit) : 1;

$sql = "
    SELECT
        cl.id,
        cl.record_id,
        cl.source,
        cl.call_status,
        cl.call_duration,
        cl.notes,
        cl.next_followup,
        cl.recording_url,
        cl.call_time,
        cl.created_at,
        u.NAME AS user_name,
        COALESCE(t.CUST_NAME, m.MAINDATABASE_NAME, l.NAME) AS cust_name,
        COALESCE(t.CUST_MOBILE, m.MAINDATABASE_MOBILE, l.MOBILE) AS cust_mobile,
        COALESCE(t.CUST_COMPANY, m.MAINDATABASE_COMPANY, l.COMPANY_NAME) AS cust_company
    FROM " . tn('TBL_CALL_LOGS') . " cl
    JOIN " . tn('TBL_USERS') . " u ON cl.user_id = u.ID
    LEFT JOIN " . tn('TBL_TEMP') . " t ON cl.source = 'TEMP' AND cl.record_id = t.ID
    LEFT JOIN " . tn('TBL_MAIN') . " m ON cl.source = 'MAIN' AND cl.record_id = m.ID
    LEFT JOIN " . tn('TBL_LEADS') . " l ON cl.source = 'LEADS' AND cl.record_id = l.lead_id
    $whereSql
    ORDER BY cl.created_at DESC
    LIMIT ?, ?
";

$params[] = $offset;
$params[] = $limit;
$types .= 'ii';

$stmt = mysqli_prepare($link, $sql);
if ($stmt === false) {
    $err = mysqli_error($link);
    die('Prepare failed: ' . htmlspecialchars($err ?: 'Unknown database error'));
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$statusesList = [
    'Connected',
    'Interested / Hot',
    'Interested',
    'Deal Converted',
    'Follow-up Needed',
    'Not Interested',
    'Busy / No Answer',
    'Busy',
    'No Answer',
    'DND Number',
    'Do Not Call',
    'Wrong Number',
    'Switched Off',
    'Voicemail',
    'Pending',
    'Dialed'
];

function getCallStatusBadge(string $status): string {
    $norm = strtolower(trim($status));
    switch ($norm) {
        case 'connected':
        case 'lead':
        case 'interested':
        case 'interested / hot':
        case 'deal converted':
            return '<span class="badge badge-success fs-xs"><i class="bi bi-telephone-check me-1"></i>' . htmlspecialchars($status) . '</span>';
        case 'not interested':
        case 'not_interested':
            return '<span class="badge badge-secondary fs-xs"><i class="bi bi-hand-thumbs-down me-1"></i>' . htmlspecialchars($status) . '</span>';
        case 'busy':
        case 'busy / no answer':
            return '<span class="badge badge-warning fs-xs"><i class="bi bi-telephone-x me-1"></i>' . htmlspecialchars($status) . '</span>';
        case 'no answer':
        case 'no_answer':
            return '<span class="badge badge-secondary fs-xs"><i class="bi bi-telephone-minus me-1"></i>No Answer</span>';
        case 'do not call':
        case 'dnc':
        case 'dnd':
        case 'dnd number':
        case 'dnd requested':
        case 'do not disturb':
        case 'not_connected_dnd':
            return '<span class="badge badge-danger fs-xs"><i class="bi bi-slash-circle me-1"></i>' . htmlspecialchars($status) . '</span>';
        case 'pending':
        case 'follow up':
        case 'follow-up':
        case 'follow-up needed':
        case 'callback / follow-up':
        case 'call back':
        case 'callback':
            return '<span class="badge badge-info fs-xs"><i class="bi bi-clock me-1"></i>' . htmlspecialchars($status) . '</span>';
        case 'dialed':
            return '<span class="badge badge-primary fs-xs"><i class="bi bi-telephone-outbound me-1"></i>Dialed</span>';
        default:
            return '<span class="badge badge-secondary fs-xs">' . htmlspecialchars($status) . '</span>';
    }
}

function getSourceBadge(string $source): string {
    switch (strtoupper($source)) {
        case 'TEMP':
            return '<span class="badge badge-warning fs-xs"><i class="bi bi-clock-history me-1"></i>Temporary</span>';
        case 'MAIN':
            return '<span class="badge badge-primary fs-xs"><i class="bi bi-database me-1"></i>Main DB</span>';
        case 'LEADS':
            return '<span class="badge badge-success fs-xs"><i class="bi bi-kanban me-1"></i>Leads</span>';
        default:
            return '<span class="badge badge-secondary fs-xs">' . htmlspecialchars($source) . '</span>';
    }
}

$pageTitle = 'Call History • CallNow CRM';
include __DIR__ . '/../../php_scripts/header.php';
?>

<div class="container-fluid px-3 py-3">
    <!-- Module Header -->
    <div class="module-header mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 fs-xs">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="#">Activities & Reports</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Call History</li>
                </ol>
            </nav>
            <h1 class="h4 fw-bold text-dark mb-0">Call History & Audit Trail</h1>
            <div class="text-muted fs-xs">Centralized audit trail of every outbound phone interaction logged via mobile or web CRM</div>
        </div>
        <div class="module-header-actions">
            <a href="<?= htmlspecialchars(url('modules/logs/Reports.php')) ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-bar-chart me-1"></i> Performance Reports
            </a>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="crm-card mb-3">
        <div class="crm-card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2 col-6">
                    <label class="form-label fs-xs mb-1" for="source">Source DB</label>
                    <select name="source" id="source" class="form-select form-select-sm">
                        <option value="all" <?= $sourceFilter==='all'?'selected':'' ?>>All Sources</option>
                        <option value="TEMP" <?= $sourceFilter==='TEMP'?'selected':'' ?>>Temporary DB</option>
                        <option value="MAIN" <?= $sourceFilter==='MAIN'?'selected':'' ?>>Main DB</option>
                        <option value="LEADS" <?= $sourceFilter==='LEADS'?'selected':'' ?>>Leads</option>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label fs-xs mb-1" for="status">Call Status</label>
                    <select name="status" id="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <?php foreach ($statusesList as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label fs-xs mb-1" for="from">From Date</label>
                    <input type="date" name="from" id="from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label fs-xs mb-1" for="to">To Date</label>
                    <input type="date" name="to" id="to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="col-md-3 col-12">
                    <label class="form-label fs-xs mb-1" for="search">Customer / Notes</label>
                    <input type="text" name="search" id="search" class="form-control form-control-sm" placeholder="Name, mobile, or note..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-1 col-6">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-funnel me-1"></i> Filter
                    </button>
                </div>
                <?php if ($sourceFilter !== 'all' || $statusFilter !== '' || $dateFrom || $dateTo || $search): ?>
                    <div class="col-md-1 col-6">
                        <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="btn btn-secondary btn-sm w-100" title="Reset Filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Table Card -->
    <div class="crm-card">
        <div class="crm-card-header">
            <div class="crm-card-title">
                <i class="bi bi-telephone-inbound text-primary me-2"></i>Call Activity Logs
                <span class="badge badge-secondary fs-xs ms-2"><?= number_format($totalRows) ?> total</span>
            </div>
            <div class="fs-xs text-muted">
                Showing <?= number_format(empty($rows) ? 0 : $offset + 1) ?>–<?= number_format(min($offset + $limit, $totalRows)) ?>
            </div>
        </div>
        <div class="crm-card-body p-0">
            <?php if (empty($rows)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox text-muted fs-1 mb-2"></i>
                    <h6 class="fw-bold text-dark">No call logs found</h6>
                    <p class="fs-xs text-muted mb-0">Try clearing filters or checking other dates.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:14%;">Date & Time</th>
                                <th style="width:22%;">Customer / Contact</th>
                                <th style="width:10%;">Source</th>
                                <th style="width:14%;">Status</th>
                                <th style="width:10%;">Duration</th>
                                <th style="width:14%;">Telecaller</th>
                                <th style="width:10%;">Follow-up</th>
                                <th style="width:6%;">Audio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                $callTimeVal = $r['call_time'] ?? $r['created_at'];
                                $formattedDate = $callTimeVal ? date('d M Y', strtotime($callTimeVal)) : '—';
                                $formattedTime = $callTimeVal ? date('h:i A', strtotime($callTimeVal)) : '';
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark fs-xs"><?= htmlspecialchars($formattedDate) ?></div>
                                        <small class="text-muted fs-xs"><?= htmlspecialchars($formattedTime) ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-dark fs-xs"><?= htmlspecialchars($r['cust_name'] ?: ('Record #' . $r['record_id'])) ?></strong>
                                        <?php if ($r['cust_mobile']): ?>
                                            <div class="fs-xs">
                                                <a href="tel:<?= urlencode($r['cust_mobile']) ?>" class="text-decoration-none font-monospace text-muted">
                                                    <i class="bi bi-telephone text-primary me-1"></i><?= htmlspecialchars($r['cust_mobile']) ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($r['cust_company']): ?>
                                            <div class="fs-xs text-muted text-truncate" style="max-width:200px;">
                                                <i class="bi bi-building me-1"></i><?= htmlspecialchars($r['cust_company']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= getSourceBadge($r['source'] ?? 'MAIN') ?>
                                    </td>
                                    <td>
                                        <?= getCallStatusBadge($r['call_status'] ?? 'Connected') ?>
                                        <?php if ($r['notes']): ?>
                                            <div class="text-muted fs-xs mt-1 text-truncate" style="max-width: 180px;" title="<?= htmlspecialchars($r['notes']) ?>">
                                                <i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($r['notes']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$r['call_duration'] > 0): ?>
                                            <span class="badge badge-secondary fs-xs">
                                                <i class="bi bi-stopwatch me-1"></i>
                                                <?= (int)$r['call_duration'] >= 60 ? floor($r['call_duration']/60) . 'm ' . ($r['call_duration']%60) . 's' : $r['call_duration'] . 's' ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted fs-xs">0s</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div style="width:26px; height:26px; font-size:10px; background:#e2e8f0; color:#1e293b; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700;">
                                                <?= strtoupper(substr($r['user_name'] ?? '?', 0, 2)) ?>
                                            </div>
                                            <div class="fw-semibold fs-xs text-truncate" style="max-width: 120px;" title="<?= htmlspecialchars($r['user_name'] ?? '—') ?>">
                                                <?= htmlspecialchars($r['user_name'] ?? '—') ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($r['next_followup']): ?>
                                            <span class="badge badge-warning fs-xs">
                                                <i class="bi bi-calendar-event me-1"></i><?= date('d M Y', strtotime($r['next_followup'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted fs-xs">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['recording_url']): ?>
                                            <a href="<?= htmlspecialchars($r['recording_url']) ?>" target="_blank" class="btn btn-secondary btn-sm py-1 px-2 fs-xs" title="Play Recording">
                                                <i class="bi bi-play-circle text-primary"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted fs-xs">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="crm-card-footer d-flex align-items-center justify-content-between flex-wrap gap-2 px-3 py-2 border-top">
                        <span class="text-muted fs-xs">
                            Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $limit, $totalRows)) ?> of <?= number_format($totalRows) ?>
                        </span>
                        <nav aria-label="Call history pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                            &laquo;
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled"><span class="page-link">&laquo;</span></li>
                                <?php endif; ?>

                                <li class="page-item active">
                                    <span class="page-link"><?= $page ?> / <?= $totalPages ?></span>
                                </li>

                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                            &raquo;
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li class="page-item disabled"><span class="page-link">&raquo;</span></li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    function keepAlive() {
        fetch('<?= htmlspecialchars(url('modules/logs/call_history.php')) ?>', {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).catch(function() {});
    }
    setInterval(keepAlive, 300000);
})();
</script>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
