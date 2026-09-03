<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php';
requirePermission('view_reports');

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
    $whereParts[] = '(COALESCE(cust_name, "") LIKE ? OR COALESCE(cust_mobile, "") LIKE ? OR cl.notes LIKE ?)';
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
    die('Prepare failed: ' . mysqli_error($link));
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$statusesList = ['Connected', 'Dialed', 'Busy', 'No Answer', 'Do Not Call', 'Pending', 'Not Called', 'Follow Up', 'Interested', 'Call Back'];
?>

<?php $pageTitle = 'Call History - CallNow'; include '../../php_scripts/header.php'; ?>

<style>
.ch-hero {
    background: linear-gradient(135deg, var(--accent) 0%, #6d5dd3 55%, #8b5cf6 100%);
    color: #fff; padding: 1.4rem 0 1.2rem; border-radius: 0 0 var(--radius-xl) var(--radius-xl);
    box-shadow: var(--shadow-md); margin-bottom: 1.5rem;
}
.ch-title { font-size: 1.4rem; font-weight: 700; letter-spacing: -.02em; margin: 0; }
.ch-sub { opacity: .9; font-size: .9rem; margin-top: .15rem; }
.ch-toolbar { padding: .85rem 1rem; margin-bottom: 1.25rem; }
.ch-toolbar .form-select, .ch-toolbar .form-control { font-size: .85rem; }
.ch-toolbar .form-label { font-size: .75rem; color: var(--ink-soft); margin-bottom: .15rem; }
.ch-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; color: var(--ink-soft); background: var(--surface-2); font-weight: 600; white-space: nowrap; }
.ch-recording { color: var(--accent); text-decoration: none; }
.ch-recording:hover { text-decoration: underline; }
.ch-empty { text-align: center; padding: 3rem 1rem; color: var(--ink-soft); }
.ch-empty > i { font-size: 2.6rem; opacity: .35; display: block; margin-bottom: .75rem; }
</style>

<div class="ch-hero">
    <div class="container">
        <h1 class="ch-title"><i class="bi bi-telephone-inbound me-2"></i>Call History</h1>
        <div class="ch-sub">Full audit trail of every call submitted from the mobile app &amp; web</div>
    </div>
</div>

<div class="container py-4">
    <div class="ch-toolbar">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-auto">
                <label class="form-label d-block">Source</label>
                <select name="source" class="form-select">
                    <option value="all" <?= $sourceFilter==='all'?'selected':'' ?>>All Sources</option>
                    <option value="TEMP" <?= $sourceFilter==='TEMP'?'selected':'' ?>>Temporary DB</option>
                    <option value="MAIN" <?= $sourceFilter==='MAIN'?'selected':'' ?>>Main DB</option>
                    <option value="LEADS" <?= $sourceFilter==='LEADS'?'selected':'' ?>>Leads</option>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label d-block">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <?php foreach ($statusesList as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label d-block">From</label>
                <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label d-block">To</label>
                <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="col-auto flex-grow-1">
                <label class="form-label d-block">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Customer name / mobile / notes" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button>
            </div>
            <div class="col-auto">
                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
            </div>
        </form>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <?php if (empty($rows)): ?>
                <div class="ch-empty">
                    <i class="bi bi-inbox"></i>
                    <h5 class="mt-2">No call history found</h5>
                    <p class="text-muted mb-0">Try adjusting filters or run the test data seeder.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 ch-table">
                        <thead class="table-light">
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Customer</th>
                                <th>Mobile</th>
                                <th>Source</th>
                                <th>Status</th>
                                <th>Duration</th>
                                <th>Telecaller</th>
                                <th>Notes</th>
                                <th>Next Follow-up</th>
                                <th>Recording</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('d M Y, h:i A', strtotime($r['call_time'] ?? $r['created_at']))) ?></td>
                                    <td><?= htmlspecialchars($r['cust_name'] ?: 'Record #' . $r['record_id']) ?></td>
                                    <td><?= htmlspecialchars($r['cust_mobile'] ?: '—') ?></td>
                                    <td><span class="badge bg-<?= $r['source']==='TEMP'?'info':($r['source']==='MAIN'?'primary':'success') ?>"><?= htmlspecialchars($r['source']) ?></span></td>
                                    <td><?= htmlspecialchars($r['call_status']) ?></td>
                                    <td><?= $r['call_duration'] ? $r['call_duration'] . 's' : '—' ?></td>
                                    <td><?= htmlspecialchars($r['user_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($r['notes'] ?: '—') ?></td>
                                    <td><?= $r['next_followup'] ? htmlspecialchars(date('d M Y', strtotime($r['next_followup']))) : '—' ?></td>
                                    <td>
                                        <?php if ($r['recording_url']): ?>
                                            <a href="<?= htmlspecialchars($r['recording_url']) ?>" target="_blank" class="ch-recording"><i class="bi bi-play-circle"></i> Play</a>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-between align-items-center p-3">
                    <span class="text-muted small">Showing <?= number_format($offset + 1) ?> – <?= number_format(min($offset + $limit, $totalRows)) ?> of <?= number_format($totalRows) ?></span>
                    <div class="btn-group">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i> Prev</a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-outline-secondary btn-sm">Next <i class="bi bi-chevron-right"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>

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
