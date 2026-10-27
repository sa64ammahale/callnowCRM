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

$pageTitle = 'Call History - CallNow';

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
    die('Count prepare failed: ' . htmlspecialchars($err ?: 'Unknown database error') . '<br><small>' . htmlspecialchars($countSql) . '</small>');
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
    die('Prepare failed: ' . htmlspecialchars($err ?: 'Unknown database error') . '<br><small>' . htmlspecialchars($sql) . '</small>');
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$statusesList = ['Connected', 'Dialed', 'Busy', 'No Answer', 'Do Not Call', 'Pending', 'Not Called', 'Follow Up', 'Interested', 'Call Back'];
?>

<?php
function getCallStatusBadge(string $status): string {
    $norm = strtolower(trim($status));
    switch ($norm) {
        case 'connected':
        case 'lead':
        case 'interested':
            return '<span class="ch-badge ch-badge-success"><i class="bi bi-telephone-check-fill"></i> ' . htmlspecialchars($status) . '</span>';
        case 'busy':
            return '<span class="ch-badge ch-badge-warning"><i class="bi bi-telephone-x-fill"></i> Busy</span>';
        case 'no answer':
        case 'no_answer':
            return '<span class="ch-badge ch-badge-secondary"><i class="bi bi-telephone-minus"></i> No Answer</span>';
        case 'do not call':
        case 'dnc':
            return '<span class="ch-badge ch-badge-danger"><i class="bi bi-slash-circle-fill"></i> DNC</span>';
        case 'pending':
        case 'follow up':
        case 'follow-up':
        case 'call back':
        case 'callback':
            return '<span class="ch-badge ch-badge-info"><i class="bi bi-clock-fill"></i> ' . htmlspecialchars($status) . '</span>';
        case 'dialed':
            return '<span class="ch-badge ch-badge-dark"><i class="bi bi-telephone-outbound-fill"></i> Dialed</span>';
        default:
            return '<span class="ch-badge ch-badge-neutral">' . htmlspecialchars($status) . '</span>';
    }
}

function getSourceBadge(string $source): string {
    switch (strtoupper($source)) {
        case 'TEMP':
            return '<span class="badge rounded-pill bg-info-subtle text-info border border-info-subtle px-2 py-1"><i class="bi bi-table me-1"></i>Temporary</span>';
        case 'MAIN':
            return '<span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><i class="bi bi-database me-1"></i>Main DB</span>';
        case 'LEADS':
            return '<span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="bi bi-bookmark-star me-1"></i>Leads</span>';
        default:
            return '<span class="badge rounded-pill bg-secondary-subtle text-secondary px-2 py-1">' . htmlspecialchars($source) . '</span>';
    }
}
?>

<?php $pageTitle = 'Call History - CallNow'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<style>
/* ── Hero ───────────────────────────────────────────── */
.ch-hero {
    background: linear-gradient(135deg, var(--accent) 0%, #6d5dd3 55%, #8b5cf6 100%);
    color: #ffffff !important;
    padding: 1.6rem 0 1.4rem;
    border-radius: 0 0 var(--radius-xl) var(--radius-xl);
    box-shadow: var(--shadow-md);
    margin-bottom: 1.5rem;
}
.ch-hero * { color: inherit; }
.ch-eyebrow {
    font-size: .72rem; text-transform: uppercase; letter-spacing: .08em;
    color: rgba(255,255,255,.85) !important; font-weight: 600;
}
.ch-title { font-size: 1.5rem; font-weight: 700; letter-spacing: -.02em; margin: .15rem 0 .25rem; color: #ffffff !important; }
.ch-sub { font-size: .88rem; color: rgba(255,255,255,.9) !important; margin: 0; }
.ch-stat-badge {
    background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.25);
    border-radius: var(--radius-lg); padding: .45rem .95rem; text-align: center;
    backdrop-filter: blur(6px);
}
.ch-stat-num { display: block; font-size: 1.25rem; font-weight: 700; line-height: 1.2; color: #ffffff !important; }
.ch-stat-lbl { display: block; font-size: .65rem; text-transform: uppercase; letter-spacing: .04em; color: rgba(255,255,255,.85) !important; }

/* ── Filter Card ────────────────────────────────────── */
.ch-filter-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: 1.15rem 1.25rem;
    margin-bottom: 1.5rem;
}
.ch-filter-card .form-label {
    font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em;
    color: var(--ink-soft); margin-bottom: .25rem;
}
.ch-filter-card .form-select, .ch-filter-card .form-control {
    font-size: .85rem; border-radius: var(--radius);
}

/* ── Table Card ─────────────────────────────────────── */
.ch-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    margin-bottom: 2rem;
}
.ch-card-header {
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .75rem;
    padding: 1rem 1.25rem; background: var(--surface); border-bottom: 1px solid var(--border);
}
.ch-card-title {
    font-weight: 600; font-size: .95rem; margin: 0; display: flex; align-items: center; gap: .5rem;
}
.ch-table { margin-bottom: 0; }
.ch-table th {
    font-size: .72rem; text-transform: uppercase; letter-spacing: .04em;
    color: var(--ink-soft); background: var(--surface-2); font-weight: 600; white-space: nowrap;
    padding: .85rem 1rem; border-bottom: 1px solid var(--border);
}
.ch-table td {
    padding: .85rem 1rem; vertical-align: middle; border-bottom: 1px solid var(--border);
    font-size: .85rem;
}
.ch-table tr:hover td {
    background: var(--surface-2);
}

/* ── Badges & Details ───────────────────────────────── */
.ch-badge {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .75rem; font-weight: 600; padding: .3rem .65rem;
    border-radius: 999px; white-space: nowrap;
}
.ch-badge-success { background: rgba(16, 185, 129, 0.12); color: #059669; border: 1px solid rgba(16, 185, 129, 0.28); }
.ch-badge-warning { background: rgba(245, 158, 11, 0.12); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.28); }
.ch-badge-danger  { background: rgba(239, 68, 68, 0.12); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.28); }
.ch-badge-info    { background: rgba(14, 165, 233, 0.12); color: #0284c7; border: 1px solid rgba(14, 165, 233, 0.28); }
.ch-badge-secondary { background: rgba(100, 116, 139, 0.12); color: #475569; border: 1px solid rgba(100, 116, 139, 0.28); }
.ch-badge-dark    { background: rgba(99, 102, 241, 0.12); color: #4f46e5; border: 1px solid rgba(99, 102, 241, 0.28); }
.ch-badge-neutral { background: var(--surface-2); color: var(--ink-soft); border: 1px solid var(--border); }

.ch-dur-pill {
    display: inline-flex; align-items: center; gap: .3rem;
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: var(--radius); padding: .2rem .55rem; font-size: .78rem; font-weight: 600; color: var(--ink);
}

.ch-avatar {
    width: 30px; height: 30px; border-radius: 50%;
    background: var(--accent); color: #ffffff; display: flex;
    align-items: center; justify-content: center; font-weight: 700; font-size: .72rem; flex-shrink: 0;
}

.ch-empty { text-align: center; padding: 3.5rem 1rem; color: var(--ink-soft); }
.ch-empty > i { font-size: 2.8rem; opacity: .35; display: block; margin-bottom: .75rem; color: var(--accent); }
</style>

<div class="ch-hero">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <div class="ch-eyebrow"><i class="bi bi-clock-history"></i> Audit Trail</div>
                <h1 class="ch-title"><i class="bi bi-telephone-inbound me-2"></i>Call History</h1>
                <div class="ch-sub">Real-time log of every call dialed from mobile app &amp; web CRM</div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="ch-stat-badge">
                    <span class="ch-stat-num"><?= number_format($totalRows) ?></span>
                    <span class="ch-stat-lbl">Total Logs</span>
                </div>
                <a href="<?= htmlspecialchars(url('modules/logs/Reports.php')) ?>" class="btn btn-light btn-sm px-3 fw-semibold shadow-sm">
                    <i class="bi bi-bar-chart-fill me-1"></i> Reports Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <!-- Filter Toolbar -->
    <div class="ch-filter-card">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2 col-sm-6">
                <label class="form-label d-block"><i class="bi bi-diagram-3 me-1"></i>Source</label>
                <select name="source" class="form-select">
                    <option value="all" <?= $sourceFilter==='all'?'selected':'' ?>>All Sources</option>
                    <option value="TEMP" <?= $sourceFilter==='TEMP'?'selected':'' ?>>Temporary DB</option>
                    <option value="MAIN" <?= $sourceFilter==='MAIN'?'selected':'' ?>>Main DB</option>
                    <option value="LEADS" <?= $sourceFilter==='LEADS'?'selected':'' ?>>Leads</option>
                </select>
            </div>
            <div class="col-md-2 col-sm-6">
                <label class="form-label d-block"><i class="bi bi-flag me-1"></i>Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <?php foreach ($statusesList as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-sm-6">
                <label class="form-label d-block"><i class="bi bi-calendar-event me-1"></i>From Date</label>
                <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-md-2 col-sm-6">
                <label class="form-label d-block"><i class="bi bi-calendar-check me-1"></i>To Date</label>
                <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="col-md-4 col-sm-12">
                <label class="form-label d-block"><i class="bi bi-search me-1"></i>Search</label>
                <input type="text" name="search" class="form-control" placeholder="Customer name / mobile / notes..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-12 d-flex justify-content-end gap-2 pt-1">
                <button type="submit" class="btn btn-primary btn-sm px-4"><i class="bi bi-funnel-fill me-1"></i> Apply Filters</button>
                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="btn btn-outline-secondary btn-sm px-3"><i class="bi bi-arrow-counterclockwise me-1"></i> Reset</a>
            </div>
        </form>
    </div>

    <!-- Table Card -->
    <div class="ch-card">
        <div class="ch-card-header">
            <h5 class="ch-card-title">
                <i class="bi bi-table text-primary"></i> Call Records
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill ms-2"><?= number_format($totalRows) ?> total</span>
            </h5>
            <div class="text-muted small">
                Showing <?= number_format(empty($rows) ? 0 : $offset + 1) ?> – <?= number_format(min($offset + $limit, $totalRows)) ?>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($rows)): ?>
                <div class="ch-empty">
                    <i class="bi bi-inbox"></i>
                    <h5 class="mt-2 fw-semibold">No call records found</h5>
                    <p class="text-muted mb-0">Try adjusting your filters or search keywords.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle ch-table">
                        <thead>
                            <tr>
                                <th style="width:14%;">Date &amp; Time</th>
                                <th style="width:20%;">Customer / Contact</th>
                                <th style="width:10%;">Source</th>
                                <th style="width:14%;">Call Status</th>
                                <th style="width:10%;">Duration</th>
                                <th style="width:14%;">Telecaller</th>
                                <th style="width:10%;">Follow-up</th>
                                <th style="width:8%;">Recording</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                $callTimeVal = $r['call_time'] ?? $r['created_at'];
                                $formattedDate = $callTimeVal ? date('d M Y', strtotime($callTimeVal)) : '—';
                                $formattedTime = $callTimeVal ? date('h:i A', strtotime($callTimeVal)) : '';
                                $userInitial = strtoupper(substr($r['user_name'] ?? '?', 0, 2));
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-nowrap"><?= htmlspecialchars($formattedDate) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($formattedTime) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($r['cust_name'] ?: ('Record #' . $r['record_id'])) ?></div>
                                        <?php if ($r['cust_mobile']): ?>
                                            <div class="small">
                                                <a href="tel:<?= urlencode($r['cust_mobile']) ?>" class="text-decoration-none text-muted">
                                                    <i class="bi bi-telephone me-1 text-primary"></i><?= htmlspecialchars($r['cust_mobile']) ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($r['cust_company']): ?>
                                            <div class="small text-muted text-truncate" style="max-width:200px;">
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
                                            <div class="text-muted small mt-1 text-truncate" style="max-width: 180px;" title="<?= htmlspecialchars($r['notes']) ?>">
                                                <i class="bi bi-card-text me-1 text-muted"></i><?= htmlspecialchars($r['notes']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$r['call_duration'] > 0): ?>
                                            <span class="ch-dur-pill">
                                                <i class="bi bi-stopwatch text-primary"></i>
                                                <?= (int)$r['call_duration'] >= 60 ? floor($r['call_duration']/60) . 'm ' . ($r['call_duration']%60) . 's' : $r['call_duration'] . 's' ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">0s</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="ch-avatar"><?= $userInitial ?></div>
                                            <div class="fw-semibold small text-truncate" style="max-width: 120px;" title="<?= htmlspecialchars($r['user_name'] ?? '—') ?>">
                                                <?= htmlspecialchars($r['user_name'] ?? '—') ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($r['next_followup']): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">
                                                <i class="bi bi-calendar-event me-1"></i><?= date('d M Y', strtotime($r['next_followup'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['recording_url']): ?>
                                            <a href="<?= htmlspecialchars($r['recording_url']) ?>" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill px-2 py-0 text-nowrap" title="Play Recording">
                                                <i class="bi bi-play-circle me-1"></i>Play
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-between align-items-center p-3 border-top">
                    <span class="text-muted small">
                        Showing <?= number_format($offset + 1) ?> – <?= number_format(min($offset + $limit, $totalRows)) ?> of <?= number_format($totalRows) ?>
                    </span>
                    <nav aria-label="Call history pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        <i class="bi bi-chevron-left"></i> Prev
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-left"></i> Prev</span></li>
                            <?php endif; ?>

                            <li class="page-item active">
                                <span class="page-link"><?= $page ?> / <?= $totalPages ?></span>
                            </li>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                        Next <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled"><span class="page-link">Next <i class="bi bi-chevron-right"></i></span></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

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