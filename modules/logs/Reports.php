<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php'; // Gives USER_ROLE, USER_ID, USER_TEAM_ID

// Access gate
requirePermission('view_reports');


// ──────────────────────────────────────────────────────────────
// Date range (default: Today) + presets
// ──────────────────────────────────────────────────────────────
$range = $_GET['range'] ?? 'today';
$from  = $_GET['from'] ?? '';
$to    = $_GET['to']   ?? '';
$team_filter = $_GET['team'] ?? '';
$selected_user_id = isset($_GET['user']) && is_numeric($_GET['user'])
    ? (int)$_GET['user']
    : null;

$rangeLabels = [
    'today'        => 'Today',
    'yesterday'    => 'Yesterday',
    'last7'        => 'Last 7 Days',
    'last30'       => 'Last 30 Days',
    'last90'       => 'Last 90 Days',
    'this_month'   => 'This Month',
    'last_month'   => 'Last Month',
    'last3'        => 'Last 3 Months',
    'last6'        => 'Last 6 Months',
    'current_year' => 'Current Year',
    'custom'       => 'Custom Range',
];

/**
 * Resolve $from / $to (inclusive Y-m-d) for a given preset.
 */
function computeRange(string $preset, string &$from, string &$to): void {
    $today = new DateTime('today');
    $from  = $today->format('Y-m-d');
    $to    = $today->format('Y-m-d');
    switch ($preset) {
        case 'yesterday':
            $d = (clone $today)->modify('-1 day');
            $from = $to = $d->format('Y-m-d');
            break;
        case 'last7':
            $from = (clone $today)->modify('-6 days')->format('Y-m-d');
            break;
        case 'last30':
            $from = (clone $today)->modify('-29 days')->format('Y-m-d');
            break;
        case 'last90':
            $from = (clone $today)->modify('-89 days')->format('Y-m-d');
            break;
        case 'this_month':
            $from = $today->format('Y-m-01');
            break;
        case 'last_month':
            $m = (clone $today)->modify('first day of previous month');
            $from = $m->format('Y-m-01');
            $to   = $m->format('Y-m-t');
            break;
        case 'last3':
            $from = (clone $today)->modify('-3 months')->format('Y-m-01');
            break;
        case 'last6':
            $from = (clone $today)->modify('-6 months')->format('Y-m-01');
            break;
        case 'current_year':
            $from = $today->format('Y-01-01');
            break;
        case 'custom':
            // keep GET values; fall back handled by caller
            break;
        case 'today':
        default:
            $from = $to = $today->format('Y-m-d');
            break;
    }
}

if ($range === 'custom') {
    if (!$from || !$to) {
        $range = 'today';
        computeRange('today', $from, $to);
    }
} else {
    computeRange($range, $from, $to);
}

$rangeLabel = $rangeLabels[$range] ?? 'Today';
$spanDays   = (new DateTime($to))->diff(new DateTime($from))->days + 1;
$bucket     = $spanDays > 31 ? 'month' : 'day';


// ──────────────────────────────────────────────────────────────
// Query parameters + WHERE
// ──────────────────────────────────────────────────────────────
$paramsBase = [$from, $to];
$typesBase  = "ss";

$whereParts = [];
$whereParts[] = "m.user_id IS NOT NULL";
$whereParts[] = "m.call_time IS NOT NULL";
$whereParts[] = "DATE(m.call_time) BETWEEN ? AND ?";

if (USER_ROLE === 'Supervisor') {
    $whereParts[] = "u.TEAM_ID = ?";
    $paramsBase[] = USER_TEAM_ID;
    $typesBase   .= "i";
} elseif (USER_ROLE === 'Officer') {
    $whereParts[] = "u.ID = ?";
    $paramsBase[] = USER_ID;
    $typesBase   .= "i";
} elseif ($team_filter !== '' && is_numeric($team_filter) && in_array(USER_ROLE, ['Admin','Manager'])) {
    $whereParts[] = "u.TEAM_ID = ?";
    $paramsBase[] = (int)$team_filter;
    $typesBase   .= "i";
}

$whereSql = $whereParts
    ? "WHERE " . implode(" AND ", $whereParts)
    : "";

/**
 * Unified call log: TBL_TEMP + TBL_MAIN
 */
$unionSubquery = "
    SELECT
        ID,
        CALL_DIALED_TELECALLER AS user_id,
        LAST_DIALED_DATE_TIME  AS call_time,
        CALL_DIALED_STATUS     AS call_status,
        CUST_NAME              AS cust_name,
        CUST_MOBILE            AS cust_mobile,
        CUST_COMPANY           AS cust_company,
        'TEMP'                 AS source
    FROM " . tn('TBL_TEMP') . "

    UNION ALL

    SELECT
        ID,
        MAINDATABASE_CALL_DIALED_USER AS user_id,
        COALESCE(MAINDATABASE_CALL_DIAL_TIME, LAST_DIALED_DATE_TIME) AS call_time,
        MAINDATABASE_CALL_DIALED_STATUS AS call_status,
        MAINDATABASE_NAME    AS cust_name,
        MAINDATABASE_MOBILE  AS cust_mobile,
        MAINDATABASE_COMPANY AS cust_company,
        'MAIN'               AS source
    FROM " . tn('TBL_MAIN') . "
";

/**
 * SUMMARY QUERY: per-telecaller stats
 */
$sqlSummary = "
    SELECT
        u.ID   AS user_id,
        u.NAME AS user_name,
        t.ID   AS team_id,
        t.NAME AS team_name,
        t.SUPERVISOR_ID,
        s.NAME AS supervisor_name,
        COUNT(*) AS total,
        SUM(CASE WHEN m.call_status = 'Connected'   THEN 1 ELSE 0 END) AS connected,
        SUM(CASE WHEN m.call_status = 'Dialed'      THEN 1 ELSE 0 END) AS dialed,
        SUM(CASE WHEN m.call_status = 'Busy'        THEN 1 ELSE 0 END) AS busy,
        SUM(CASE WHEN m.call_status = 'No Answer'   THEN 1 ELSE 0 END) AS no_answer,
        SUM(CASE WHEN m.call_status = 'Do Not Call' THEN 1 ELSE 0 END) AS dnc,
        SUM(CASE WHEN m.call_status = 'Pending'     THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN m.call_status = 'Not Called'  THEN 1 ELSE 0 END) AS not_called
    FROM (
        $unionSubquery
    ) AS m
    JOIN TBL_USERS u ON m.user_id = u.ID
    LEFT JOIN TBL_TEAMS t ON u.TEAM_ID = t.ID
    LEFT JOIN TBL_USERS s ON t.SUPERVISOR_ID = s.ID
    $whereSql
    GROUP BY u.ID, t.ID
    ORDER BY t.NAME, u.NAME
";

$stmt = mysqli_prepare($link, $sqlSummary);
if ($stmt === false) {
    die("Prepare failed: " . mysqli_error($link));
}
if ($typesBase !== "") {
    mysqli_stmt_bind_param($stmt, $typesBase, ...$paramsBase);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$data          = [];
$grand_total   = 0;
$grand_connected = 0;
$grand_dialed  = 0;
$grand_busy    = 0;
$grand_no_ans  = 0;
$grand_dnc     = 0;
$grand_pending = 0;
$grand_not_called = 0;
$TBL_USERS_index   = [];

while ($row = mysqli_fetch_assoc($result)) {
    $row['rate'] = $row['total'] ? round($row['connected'] / $row['total'] * 100, 1) : 0;

    $team_name = $row['team_name'] ?: 'No Team';
    if (!isset($data[$team_name])) {
        $data[$team_name] = [
            'team_id'         => $row['team_id'],
            'supervisor_id'   => $row['SUPERVISOR_ID'],
            'supervisor_name' => $row['supervisor_name'],
            'TBL_USERS'           => []
        ];
    }
    $data[$team_name]['TBL_USERS'][] = $row;

    $grand_total      += (int)$row['total'];
    $grand_connected  += (int)$row['connected'];
    $grand_dialed     += (int)$row['dialed'];
    $grand_busy       += (int)$row['busy'];
    $grand_no_ans     += (int)$row['no_answer'];
    $grand_dnc        += (int)$row['dnc'];
    $grand_pending    += (int)$row['pending'];
    $grand_not_called += (int)$row['not_called'];

    $TBL_USERS_index[$row['user_id']] = $row['user_name'];
}
mysqli_stmt_close($stmt);

$grand_rate = $grand_total ? round($grand_connected / $grand_total * 100, 1) : 0;

// TBL_TEAMS for filter dropdown
$TBL_TEAMS = in_array(USER_ROLE, ['Admin','Manager'])
    ? mysqli_fetch_all(mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " ORDER BY NAME"), MYSQLI_ASSOC)
    : [];

/**
 * TREND QUERY: calls over time (daily or monthly bucket)
 */
$trend = [];
$bucketExpr = $bucket === 'month'
    ? "DATE_FORMAT(m.call_time, '%Y-%m-01')"
    : "DATE(m.call_time)";

$sqlTrend = "
    SELECT $bucketExpr AS bucket,
           COUNT(*) AS total,
           SUM(CASE WHEN m.call_status = 'Connected' THEN 1 ELSE 0 END) AS connected
    FROM (
        $unionSubquery
    ) AS m
    JOIN TBL_USERS u ON m.user_id = u.ID
    LEFT JOIN TBL_TEAMS t ON u.TEAM_ID = t.ID
    $whereSql
    GROUP BY bucket
    ORDER BY bucket
";
$stmtT = mysqli_prepare($link, $sqlTrend);
if ($stmtT === false) {
    die("Prepare failed (trend): " . mysqli_error($link));
}
if ($typesBase !== "") {
    mysqli_stmt_bind_param($stmtT, $typesBase, ...$paramsBase);
}
mysqli_stmt_execute($stmtT);
$resT = mysqli_stmt_get_result($stmtT);
while ($r = mysqli_fetch_assoc($resT)) {
    if ($bucket === 'month') {
        $label = (new DateTime($r['bucket']))->format('M Y');
    } else {
        $label = (new DateTime($r['bucket']))->format('d M');
    }
    $trend[] = [
        'label'     => $label,
        'total'     => (int)$r['total'],
        'connected' => (int)$r['connected'],
    ];
}
mysqli_stmt_close($stmtT);

// Per-user series (for bar chart)
$userLabels = [];
$userTotals = [];
foreach ($data as $block) {
    foreach ($block['TBL_USERS'] as $u) {
        $userLabels[] = $u['user_name'];
        $userTotals[] = (int)$u['total'];
    }
}

$statusLabels = ['Connected','Dialed','Busy','No Answer','DNC','Pending','Not Called'];
$statusValues = [
    (int)$grand_connected, (int)$grand_dialed, (int)$grand_busy,
    (int)$grand_no_ans, (int)$grand_dnc, (int)$grand_pending, (int)$grand_not_called
];

/**
 * DETAIL QUERY: calls of a single telecaller (if requested)
 */
$detail_rows = [];
$selected_user_name = null;

if ($selected_user_id !== null) {
    $selected_user_name = $TBL_USERS_index[$selected_user_id] ?? null;

    if ($selected_user_name === null) {
        $resUser = mysqli_query($link, "SELECT NAME FROM " . tn('TBL_USERS') . " WHERE ID = " . (int)$selected_user_id);
        if ($resUser && mysqli_num_rows($resUser) === 1) {
            $selected_user_name = mysqli_fetch_assoc($resUser)['NAME'];
        }
    }

    $paramsDetail = $paramsBase;
    $typesDetail  = $typesBase;
    $whereDetail  = $whereParts;

    $whereDetail[] = "m.user_id = ?";
    $paramsDetail[] = $selected_user_id;
    $typesDetail   .= "i";

    $whereDetailSql = "WHERE " . implode(" AND ", $whereDetail);

    $sqlDetail = "
        SELECT
            m.call_time,
            m.call_status,
            m.cust_name,
            m.cust_mobile,
            m.cust_company,
            m.source
        FROM (
            $unionSubquery
        ) AS m
        JOIN TBL_USERS u ON m.user_id = u.ID
        LEFT JOIN TBL_TEAMS t ON u.TEAM_ID = t.ID
        $whereDetailSql
        ORDER BY m.call_time DESC
    ";

    $stmt2 = mysqli_prepare($link, $sqlDetail);
    if ($stmt2 === false) {
        die("Prepare failed (detail): " . mysqli_error($link));
    }
    if ($typesDetail !== "") {
        mysqli_stmt_bind_param($stmt2, $typesDetail, ...$paramsDetail);
    }
    mysqli_stmt_execute($stmt2);
    $resDetail = mysqli_stmt_get_result($stmt2);

    while ($r = mysqli_fetch_assoc($resDetail)) {
        $detail_rows[] = $r;
    }
    mysqli_stmt_close($stmt2);
}

// Build a query-string helper that preserves current filters.
// User selection is only included when explicitly passed via $extra['user'].
function reportQs(array $extra = []): string {
    global $range, $from, $to, $team_filter;
    $qs = [
        'range' => $range,
        'from'  => $from,
        'to'    => $to,
        'team'  => $team_filter,
    ];
    $qs = array_merge($qs, $extra);
    return http_build_query($qs);
}
$self = htmlspecialchars($_SERVER['PHP_SELF']);
?>
<?php $pageTitle = 'Call Report - CallNow'; include '../../php_scripts/header.php'; ?>

<style>
/* ── Hero ───────────────────────────────────────────── */
.rp-hero {
    background: linear-gradient(135deg, var(--accent) 0%, #6d5dd3 55%, #8b5cf6 100%);
    color: #fff;
    padding: 1.6rem 0 1.4rem;
    border-radius: 0 0 var(--radius-xl) var(--radius-xl);
    box-shadow: var(--shadow-md);
    margin-bottom: 1.5rem;
}
.rp-eyebrow {
    font-size: .72rem; text-transform: uppercase; letter-spacing: .09em;
    opacity: .85; font-weight: 600;
}
.rp-title { font-size: 1.5rem; font-weight: 700; margin: .15rem 0 .25rem; letter-spacing: -.02em; }
.rp-scope { font-weight: 500; opacity: .85; font-size: 1rem; }
.rp-sub { font-size: .85rem; opacity: .9; }
.rp-sub strong { font-weight: 600; }
.rp-kpis { display: flex; gap: .65rem; flex-wrap: wrap; }
.rp-kpi {
    background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.25);
    border-radius: var(--radius-lg); padding: .55rem .95rem; min-width: 96px; text-align: center;
    backdrop-filter: blur(6px);
}
.rp-kpi-label { display: block; font-size: .68rem; opacity: .85; text-transform: uppercase; letter-spacing: .04em; }
.rp-kpi-value { display: block; font-size: 1.3rem; font-weight: 700; line-height: 1.25; }
.rp-kpi-value.text-success { color: #d1fae5 !important; }
.rp-kpi-value.text-danger  { color: #fee2e2 !important; }

/* ── Card base ─────────────────────────────────────── */
.rp-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);
    padding: 1rem 1.1rem; margin-bottom: 1.25rem;
}
.rp-card-head {
    display: flex; align-items: center; gap: .5rem;
    font-weight: 600; font-size: .9rem; color: var(--ink); margin-bottom: .85rem;
}
.rp-card-head i { color: var(--accent); font-size: 1rem; }

/* ── Toolbar ───────────────────────────────────────── */
.rp-toolbar { padding: .85rem 1rem; margin-bottom: 1.25rem; }
.rp-toolbar .form-select, .rp-toolbar .form-control { font-size: .85rem; }
.rp-toolbar .form-label { font-size: .75rem; color: var(--ink-soft); margin-bottom: .15rem; }

/* ── Charts ────────────────────────────────────────── */
.rp-charts { display: grid; grid-template-columns: 2fr 1fr; gap: 1.25rem; margin-bottom: 0; }
.rp-chart-wrap { position: relative; height: 260px; }
@media (max-width: 767px) { .rp-charts { grid-template-columns: 1fr; } }

/* ── Team grid ─────────────────────────────────────── */
.rp-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 1.25rem; margin-bottom: .5rem;
}
.rp-team {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;
}
.rp-team-head {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;
    padding: .9rem 1.1rem; background: var(--surface-2); border-bottom: 1px solid var(--border);
}
.rp-team-name { font-weight: 700; font-size: 1rem; }
.rp-team-sub { font-size: .8rem; color: var(--ink-soft); margin-top: .1rem; }
.rp-team-total { font-weight: 700; font-size: 1.1rem; }
.rp-team-total span { font-size: .78rem; font-weight: 500; color: var(--ink-soft); margin-left: .2rem; }
.rp-team-rate { font-size: .85rem; font-weight: 600; }
.rp-team-body { padding: .35rem .6rem; }

/* ── User row ──────────────────────────────────────── */
.rp-user {
    display: flex; justify-content: space-between; align-items: center; gap: 1rem;
    padding: .7rem .5rem; border-radius: var(--radius); transition: background .15s ease;
}
.rp-user + .rp-user { border-top: 1px solid var(--border); }
.rp-user:hover { background: var(--surface-2); }
.rp-user.is-selected { background: var(--accent-soft); }
.rp-user-id { display: flex; align-items: center; gap: .75rem; flex: 1; min-width: 0; }
.rp-avatar {
    width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
    background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: .82rem;
}
.rp-user-name { font-weight: 600; display: flex; align-items: center; gap: .4rem; }
.rp-pills { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .35rem; }
.rp-pill {
    font-size: .72rem; color: var(--ink-soft); background: var(--surface-2);
    border: 1px solid var(--border); padding: .12rem .5rem; border-radius: 999px; white-space: nowrap;
}
.rp-pill b { color: var(--ink); font-weight: 600; }
.rp-pill-ok { color: var(--success); border-color: var(--success-soft); background: var(--success-soft); }
.rp-pill-ok b { color: var(--success); }
.rp-pill-danger { color: var(--danger); border-color: var(--danger-soft); background: var(--danger-soft); }
.rp-pill-danger b { color: var(--danger); }
.rp-user-act { display: flex; align-items: center; gap: .9rem; flex-shrink: 0; }
.rp-rate { font-weight: 700; font-size: 1.05rem; }
.rp-rate.rate-good { color: var(--success); }
.rp-rate.rate-poor { color: var(--danger); }

/* ── Empty state ───────────────────────────────────── */
.rp-empty { text-align: center; padding: 3rem 1rem; color: var(--ink-soft); }
.rp-empty > i { font-size: 2.6rem; opacity: .35; display: block; margin-bottom: .75rem; }
.rp-empty h5, .rp-empty h6 { color: var(--ink-soft); font-weight: 600; }

/* ── Detail ────────────────────────────────────────── */
.rp-detail { padding: 0; overflow: hidden; }
.rp-detail-head {
    display: flex; justify-content: space-between; align-items: center; gap: 1rem;
    padding: 1rem 1.1rem; border-bottom: 1px solid var(--border); background: var(--surface-2);
}
.rp-detail-head h5 { margin: 0; font-size: 1rem; }
.rp-table th {
    font-size: .72rem; text-transform: uppercase; letter-spacing: .03em;
    color: var(--ink-soft); background: var(--surface-2); font-weight: 600; white-space: nowrap;
}

/* ── Export ────────────────────────────────────────── */
.rp-export { text-align: center; margin: 1.5rem 0 .25rem; }
</style>

<!-- Hero -->
<div class="rp-hero">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3">
            <div>
                <div class="rp-eyebrow"><i class="bi bi-bar-chart"></i> Reports</div>
                <h1 class="rp-title">
                    Call Performance Report
                    <?php if (USER_ROLE === 'Supervisor'): ?><span class="rp-scope">· My Team</span><?php endif; ?>
                    <?php if (USER_ROLE === 'Officer'): ?><span class="rp-scope">· My Calls</span><?php endif; ?>
                </h1>
                <div class="rp-sub">
                    Range: <strong><?= htmlspecialchars($rangeLabel) ?></strong>
                    · <?= htmlspecialchars($from) ?> → <?= htmlspecialchars($to) ?>
                </div>
            </div>
            <div class="rp-kpis">
                <div class="rp-kpi">
                    <span class="rp-kpi-label">Total Calls</span>
                    <span class="rp-kpi-value"><?= number_format($grand_total) ?></span>
                </div>
                <div class="rp-kpi">
                    <span class="rp-kpi-label">Connected</span>
                    <span class="rp-kpi-value text-success"><?= number_format($grand_connected) ?></span>
                </div>
                <div class="rp-kpi">
                    <span class="rp-kpi-label">Connect Rate</span>
                    <span class="rp-kpi-value <?= $grand_rate >= 40 ? 'text-success' : 'text-danger' ?>"><?= $grand_rate ?>%</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container py-4">
    <!-- Filters -->
    <div class="rp-card rp-toolbar">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-auto">
                <label class="form-label d-block">Time Range</label>
                <select name="range" class="form-select" onchange="this.form.submit()">
                    <option value="today"        <?= $range==='today'?'selected':'' ?>>Today</option>
                    <option value="yesterday"    <?= $range==='yesterday'?'selected':'' ?>>Yesterday</option>
                    <option value="last7"        <?= $range==='last7'?'selected':'' ?>>Last 7 Days</option>
                    <option value="last30"       <?= $range==='last30'?'selected':'' ?>>Last 30 Days</option>
                    <option value="last90"       <?= $range==='last90'?'selected':'' ?>>Last 90 Days</option>
                    <option value="this_month"   <?= $range==='this_month'?'selected':'' ?>>This Month</option>
                    <option value="last_month"   <?= $range==='last_month'?'selected':'' ?>>Last Month</option>
                    <option value="last3"        <?= $range==='last3'?'selected':'' ?>>Last 3 Months</option>
                    <option value="last6"        <?= $range==='last6'?'selected':'' ?>>Last 6 Months</option>
                    <option value="current_year" <?= $range==='current_year'?'selected':'' ?>>Current Year</option>
                    <option value="custom"       <?= $range==='custom'?'selected':'' ?>>Custom Range</option>
                </select>
            </div>

            <?php if ($range === 'custom'): ?>
                <div class="col-auto">
                    <label class="form-label d-block">From</label>
                    <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>" required>
                </div>
                <div class="col-auto">
                    <label class="form-label d-block">To</label>
                    <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>" required>
                </div>
            <?php endif; ?>

            <?php if (in_array(USER_ROLE, ['Admin','Manager'])): ?>
                <div class="col-auto">
                    <label class="form-label d-block">Team</label>
                    <select name="team" class="form-select" onchange="this.form.submit()">
                        <option value="">All TBL_TEAMS</option>
                        <?php foreach($TBL_TEAMS as $t): ?>
                            <option value="<?= $t['ID'] ?>" <?= $team_filter==$t['ID']?'selected':'' ?>>
                                <?= htmlspecialchars($t['NAME']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($selected_user_id): ?>
                <input type="hidden" name="user" value="<?= (int)$selected_user_id ?>">
            <?php endif; ?>

            <div class="col-auto ms-auto">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel"></i> Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Charts -->
    <?php if ($grand_total > 0): ?>
    <div class="rp-charts">
        <div class="rp-card">
            <div class="rp-card-head"><i class="bi bi-graph-up"></i> Call Trend (<?= $bucket==='month'?'Monthly':'Daily' ?>)</div>
            <div class="rp-chart-wrap"><canvas id="trendChart"></canvas></div>
        </div>
        <div class="rp-card">
            <div class="rp-card-head"><i class="bi bi-pie-chart"></i> Status Breakdown</div>
            <div class="rp-chart-wrap"><canvas id="statusChart"></canvas></div>
        </div>
    </div>
    <div class="rp-card">
        <div class="rp-card-head"><i class="bi bi-bar-chart"></i> Calls per Telecaller</div>
        <div class="rp-chart-wrap" style="height:300px;"><canvas id="userChart"></canvas></div>
    </div>
    <?php else: ?>
        <div class="rp-card rp-empty">
            <i class="bi bi-telephone-outbound"></i>
            <h5 class="mt-2">No calls found for the selected period</h5>
            <p class="text-muted mb-0">Try widening the date range or clearing filters.</p>
        </div>
    <?php endif; ?>

    <!-- Team & Telecaller Summary -->
    <div id="exportArea">
    <?php if (!empty($data)): ?>
        <div class="rp-grid">
        <?php foreach ($data as $team_name => $block):
            $team_total = 0;
            $team_connected = 0;
            foreach ($block['TBL_USERS'] as $u) {
                $team_total     += $u['total'];
                $team_connected += $u['connected'];
            }
            $team_rate = $team_total ? round($team_connected / $team_total * 100, 1) : 0;
            $sup = $block['supervisor_name'] ?? '—';
        ?>
            <div class="rp-team">
                <div class="rp-team-head">
                    <div>
                        <div class="rp-team-name"><?= htmlspecialchars($team_name) ?></div>
                        <div class="rp-team-sub">Supervisor: <?= htmlspecialchars($sup ?: '—') ?></div>
                    </div>
                    <div class="text-end">
                        <div class="rp-team-total"><?= number_format($team_total) ?><span>calls</span></div>
                        <div class="rp-team-rate <?= $team_rate >= 40 ? 'text-success' : 'text-danger' ?>">
                            <?= $team_rate ?>% connected
                        </div>
                    </div>
                </div>
                <div class="rp-team-body">
                    <?php foreach ($block['TBL_USERS'] as $u):
                        $rate = $u['rate'];
                        $rateClass = $rate >= 40 ? 'rate-good' : 'rate-poor';
                        $isSelected = $selected_user_id && $selected_user_id == $u['user_id'];
                    ?>
                        <div class="rp-user <?= $isSelected ? 'is-selected' : '' ?>">
                            <div class="rp-user-id">
                                <div class="rp-avatar">
                                    <?= strtoupper(substr($u['user_name'] ?? '?', 0, 2)) ?>
                                </div>
                                <div>
                                    <div class="rp-user-name">
                                        <?= htmlspecialchars($u['user_name'] ?? 'Unknown') ?>
                                        <?php if ($isSelected): ?>
                                            <span class="badge bg-info ms-1">Selected</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rp-pills">
                                        <span class="rp-pill">Total <b><?= (int)$u['total'] ?></b></span>
                                        <span class="rp-pill rp-pill-ok">Connected <b><?= (int)$u['connected'] ?></b></span>
                                        <span class="rp-pill">Busy <b><?= (int)$u['busy'] ?></b></span>
                                        <span class="rp-pill">No Ans <b><?= (int)$u['no_answer'] ?></b></span>
                                        <span class="rp-pill rp-pill-danger">DNC <b><?= (int)$u['dnc'] ?></b></span>
                                        <span class="rp-pill">Pending <b><?= (int)$u['pending'] ?></b></span>
                                        <span class="rp-pill">Not Called <b><?= (int)$u['not_called'] ?></b></span>
                                    </div>
                                </div>
                            </div>
                            <div class="rp-user-act">
                                <div class="text-end">
                                    <div class="rp-rate <?= $rateClass ?>"><?= $rate ?>%</div>
                                    <small class="text-muted">connect rate</small>
                                </div>
                                <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['user' => $u['user_id']])) ?>"
                                   class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-person-lines-fill"></i> Details
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Detailed Calls for selected telecaller -->
    <?php if ($selected_user_id && !empty($detail_rows)): ?>
        <div class="rp-card rp-detail mt-4">
            <div class="rp-detail-head">
                <div>
                    <h5>
                        <i class="bi bi-person-circle me-2"></i>
                        Detailed Calls ·
                        <?= htmlspecialchars($selected_user_name ?? ('User #' . $selected_user_id)) ?>
                    </h5>
                    <small class="text-muted">Showing <?= count($detail_rows) ?> calls for applied filters</small>
                </div>
                <div>
                    <a href="<?= $self ?>?<?= htmlspecialchars(reportQs()) ?>"
                       class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle"></i> Clear Selection
                    </a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 rp-table">
                    <thead>
                        <tr>
                            <th style="width: 16%;">Date &amp; Time</th>
                            <th>Customer</th>
                            <th style="width: 13%;">Mobile</th>
                            <th>Company</th>
                            <th style="width: 12%;">Status</th>
                            <th style="width: 8%;">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detail_rows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['call_time']) ?></td>
                                <td><?= htmlspecialchars($r['cust_name'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($r['cust_mobile']) ?></td>
                                <td><?= htmlspecialchars($r['cust_company'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($r['call_status']) ?></td>
                                <td>
                                    <span class="badge bg-info-subtle text-info-emphasis">
                                        <?= htmlspecialchars($r['source']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ($selected_user_id && empty($detail_rows)): ?>
        <div class="rp-card rp-empty mt-4">
            <i class="bi bi-inbox"></i>
            <h6 class="mt-2 mb-0">No calls found for this telecaller with selected filters.</h6>
        </div>
    <?php endif; ?>
    </div><!-- /exportArea -->

    <?php if ($grand_total > 0): ?>
    <div class="rp-export">
        <button id="btnExport" class="btn btn-success btn-lg px-5">
            <i class="bi bi-file-earmark-excel"></i> Export to Excel
        </button>
    </div>
    <?php endif; ?>
</div>

<?php include '../../php_scripts/footer.php'; ?>

<script src="<?= vnd('js/chart.umd.min.js') ?>"></script>
<script src="<?= vnd('js/table2excel.min.js') ?>"></script>
<script>
<?php if ($grand_total > 0): ?>
// Adapt chart colors to the active theme
const css = getComputedStyle(document.documentElement);
Chart.defaults.color = css.getPropertyValue('--ink-soft').trim() || '#666';
Chart.defaults.borderColor = css.getPropertyValue('--border').trim() || 'rgba(0,0,0,.06)';
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";

const trendLabels   = <?= json_encode(array_column($trend, 'label')) ?>;
const trendTotal    = <?= json_encode(array_column($trend, 'total')) ?>;
const trendConnected= <?= json_encode(array_column($trend, 'connected')) ?>;
const statusLabels  = <?= json_encode($statusLabels) ?>;
const statusValues  = <?= json_encode($statusValues) ?>;
const userLabels    = <?= json_encode($userLabels) ?>;
const userTotals    = <?= json_encode($userTotals) ?>;

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: trendLabels,
        datasets: [
            { label: 'Total Calls', data: trendTotal, borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.15)', fill: true, tension: .3 },
            { label: 'Connected', data: trendConnected, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.15)', fill: true, tension: .3 }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'bottom' } } }
});

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusValues,
            backgroundColor: ['#198754','#0d6efd','#fd7e14','#ffc107','#dc3545','#6c757d','#adb5bd']
        }]
    },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } } }
});

new Chart(document.getElementById('userChart'), {
    type: 'bar',
    data: {
        labels: userLabels,
        datasets: [{ label: 'Total Calls', data: userTotals, backgroundColor: '#6366f1', borderRadius: 4 }]
    },
    options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
<?php endif; ?>

// Export all tables inside exportArea
document.getElementById('btnExport')?.addEventListener('click', () => {
    const tables = document.querySelectorAll('#exportArea table');
    if (!tables.length) return;
    const exporter = new Table2Excel();
    exporter.export(tables, {
        filename: "CallNow_CallReport_<?= date('d-m-Y_H-i') ?>"
    });
});
</script>
