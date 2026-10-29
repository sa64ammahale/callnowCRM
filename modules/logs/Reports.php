<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php';

requirePermission('view_reports');

// Date range (default: Today) + presets
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

if (!function_exists('computeRange')) {
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
            break;
        case 'today':
        default:
            $from = $to = $today->format('Y-m-d');
            break;
    }
}
}

if ($range === 'custom') {
    if (!$from || !$to) {
        $from = '';
        $to   = '';
    }
} else {
    computeRange($range, $from, $to);
}

$rangeLabel = $rangeLabels[$range] ?? 'Today';
$spanDays   = (new DateTime($to ?: 'today'))->diff(new DateTime($from ?: 'today'))->days + 1;
$bucket     = $spanDays > 31 ? 'month' : 'day';
$missingCustom = ($range === 'custom' && (!$from || !$to));
$runQueries = !$missingCustom;

// Query parameters + WHERE
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
} elseif ($team_filter !== '' && is_numeric($team_filter) && (in_array(USER_ROLE, ['Admin','Manager']) || USER_ROLE === 'Super Admin')) {
    $whereParts[] = "u.TEAM_ID = ?";
    $paramsBase[] = (int)$team_filter;
    $typesBase   .= "i";
}

$whereSql = $whereParts
    ? "WHERE " . implode(" AND ", $whereParts)
    : "";

$unionSubquery = "
    SELECT
        cl.id                  AS ID,
        cl.user_id             AS user_id,
        COALESCE(cl.call_time, cl.created_at) AS call_time,
        cl.call_status         AS call_status,
        cl.call_duration       AS call_duration,
        COALESCE(t.CUST_NAME, m.MAINDATABASE_NAME, l.NAME, '') AS cust_name,
        COALESCE(t.CUST_MOBILE, m.MAINDATABASE_MOBILE, l.MOBILE, '') AS cust_mobile,
        COALESCE(t.CUST_COMPANY, m.MAINDATABASE_COMPANY, l.COMPANY_NAME, '') AS cust_company,
        cl.source              AS source
    FROM " . tn('TBL_CALL_LOGS') . " cl
    LEFT JOIN " . tn('TBL_TEMP') . " t ON cl.source = 'TEMP' AND cl.record_id = t.ID
    LEFT JOIN " . tn('TBL_MAIN') . " m ON cl.source = 'MAIN' AND cl.record_id = m.ID
    LEFT JOIN " . tn('TBL_LEADS') . " l ON cl.source = 'LEADS' AND cl.record_id = l.lead_id
";

$sqlSummary = "
    SELECT
        u.ID   AS user_id,
        u.NAME AS user_name,
        t.ID   AS team_id,
        t.NAME AS team_name,
        t.SUPERVISOR_ID,
        s.NAME AS supervisor_name,
        COUNT(*) AS total,
        SUM(CASE WHEN (m.call_duration > 0 OR LOWER(m.call_status) IN ('connected', 'lead', 'interested', 'interested / hot', 'deal converted', 'follow-up needed', 'callback / follow-up', 'follow-up', 'follow up', 'not interested', 'other discussion')) THEN 1 ELSE 0 END) AS connected,
        SUM(CASE WHEN LOWER(m.call_status) IN ('interested', 'interested / hot', 'deal converted', 'lead', 'connected') THEN 1 ELSE 0 END) AS interested,
        SUM(CASE WHEN LOWER(m.call_status) IN ('not interested', 'not_interested') THEN 1 ELSE 0 END) AS not_interested,
        SUM(CASE WHEN LOWER(m.call_status) IN ('follow-up needed', 'callback / follow-up', 'follow up', 'follow-up', 'call back', 'callback', 'pending') THEN 1 ELSE 0 END) AS followup,
        SUM(CASE WHEN LOWER(m.call_status) IN ('busy / no answer', 'busy', 'no answer', 'no_answer') THEN 1 ELSE 0 END) AS busy_no_ans,
        SUM(CASE WHEN LOWER(m.call_status) IN ('do not call', 'dnc', 'dnd', 'dnd number', 'dnd requested', 'do not disturb', 'not_connected_dnd') THEN 1 ELSE 0 END) AS dnc,
        SUM(CASE WHEN LOWER(m.call_status) NOT IN (
            'interested', 'interested / hot', 'deal converted', 'lead', 'connected',
            'not interested', 'not_interested',
            'follow-up needed', 'callback / follow-up', 'follow up', 'follow-up', 'call back', 'callback', 'pending',
            'busy / no answer', 'busy', 'no answer', 'no_answer',
            'do not call', 'dnc', 'dnd', 'dnd number', 'dnd requested', 'do not disturb', 'not_connected_dnd'
        ) THEN 1 ELSE 0 END) AS other
    FROM (
        $unionSubquery
    ) AS m
    JOIN " . tn('TBL_USERS') . " u ON m.user_id = u.ID
LEFT JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID
LEFT JOIN " . tn('TBL_USERS') . " s ON t.SUPERVISOR_ID = s.ID
    $whereSql
    GROUP BY u.ID, t.ID
    ORDER BY t.NAME, u.NAME
";

$data                 = [];
$grand_total          = 0;
$grand_connected      = 0;
$grand_interested     = 0;
$grand_not_interested = 0;
$grand_followup       = 0;
$grand_busy_no_ans    = 0;
$grand_dnc            = 0;
$grand_other          = 0;
$TBL_USERS_index      = [];

if ($runQueries) {
    $stmt = mysqli_prepare($link, $sqlSummary);
    if ($stmt === false) {
        die("Prepare failed: " . mysqli_error($link));
    }
    if ($typesBase !== "") {
        mysqli_stmt_bind_param($stmt, $typesBase, ...$paramsBase);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $row['rate'] = $row['total'] ? round($row['connected'] / $row['total'] * 100, 1) : 0;

        $team_name = $row['team_name'] ?: 'No Team';
        if (!isset($data[$team_name])) {
            $data[$team_name] = [
                'team_id'         => $row['team_id'],
                'supervisor_id'   => $row['SUPERVISOR_ID'],
                'supervisor_name' => $row['supervisor_name'],
                'TBL_USERS'       => []
            ];
        }
        $data[$team_name]['TBL_USERS'][] = $row;

        $grand_total          += (int)$row['total'];
        $grand_connected      += (int)$row['connected'];
        $grand_interested     += (int)$row['interested'];
        $grand_not_interested += (int)$row['not_interested'];
        $grand_followup       += (int)$row['followup'];
        $grand_busy_no_ans    += (int)$row['busy_no_ans'];
        $grand_dnc            += (int)$row['dnc'];
        $grand_other          += (int)$row['other'];

        $TBL_USERS_index[$row['user_id']] = $row['user_name'];
    }
    mysqli_stmt_close($stmt);
}

$grand_rate = $grand_total ? round($grand_connected / $grand_total * 100, 1) : 0;

$TBL_TEAMS = (in_array(USER_ROLE, ['Admin','Manager']) || USER_ROLE === 'Super Admin')
    ? mysqli_fetch_all(mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " ORDER BY NAME"), MYSQLI_ASSOC)
    : [];

$trend = [];
$bucketExpr = $bucket === 'month'
    ? "DATE_FORMAT(m.call_time, '%Y-%m-01')"
    : "DATE(m.call_time)";

$sqlTrend = "
    SELECT $bucketExpr AS bucket,
           COUNT(*) AS total,
           SUM(CASE WHEN (m.call_duration > 0 OR LOWER(m.call_status) IN ('connected', 'lead', 'interested', 'interested / hot', 'deal converted', 'follow-up needed', 'callback / follow-up', 'follow-up', 'follow up', 'not interested', 'other discussion')) THEN 1 ELSE 0 END) AS connected
    FROM (
        $unionSubquery
    ) AS m
    JOIN " . tn('TBL_USERS') . " u ON m.user_id = u.ID
LEFT JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID
    $whereSql
    GROUP BY bucket
    ORDER BY bucket
";
if ($runQueries) {
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
}

$userLabels = [];
$userTotals = [];
foreach ($data as $block) {
    foreach ($block['TBL_USERS'] as $u) {
        $userLabels[] = $u['user_name'];
        $userTotals[] = (int)$u['total'];
    }
}

$statusLabels = ['Interested / Connected', 'Not Interested', 'Follow-up', 'Busy / No Answer', 'DND', 'Other'];
$statusValues = [
    (int)$grand_interested,
    (int)$grand_not_interested,
    (int)$grand_followup,
    (int)$grand_busy_no_ans,
    (int)$grand_dnc,
    (int)$grand_other
];

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
            m.call_duration,
            m.cust_name,
            m.cust_mobile,
            m.cust_company,
            m.source
        FROM (
            $unionSubquery
        ) AS m
        JOIN " . tn('TBL_USERS') . " u ON m.user_id = u.ID
LEFT JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID
        $whereDetailSql
        ORDER BY m.call_time DESC
    ";

    if ($runQueries) {
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
}

if (!function_exists('reportQs')) {
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
}
$self = htmlspecialchars($_SERVER['PHP_SELF']);

if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="CallNow_Report_' . date('Y-m-d_His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, ['CALLNOW CRM - CALL PERFORMANCE REPORT']);
    fputcsv($out, ['Report Preset', $rangeLabel, 'Date Range', $from . ' to ' . $to]);
    fputcsv($out, ['Total Calls', $grand_total, 'Connected', $grand_connected, 'Connect Rate', $grand_rate . '%']);
    fputcsv($out, []);

    fputcsv($out, ['--- TEAM & TELECALLER SUMMARY ---']);
    fputcsv($out, ['Team', 'Supervisor', 'Telecaller', 'Total Calls', 'Connected', 'Interested', 'Not Interested', 'Follow-up', 'Busy / No Answer', 'DNC', 'Other', 'Connect Rate (%)']);

    foreach ($data as $team_name => $block) {
        $sup = $block['supervisor_name'] ?: 'None';
        foreach ($block['TBL_USERS'] as $u) {
            fputcsv($out, [
                $team_name,
                $sup,
                $u['user_name'],
                (int)$u['total'],
                (int)$u['connected'],
                (int)$u['interested'],
                (int)$u['not_interested'],
                (int)$u['followup'],
                (int)$u['busy_no_ans'],
                (int)$u['dnc'],
                (int)$u['other'],
                $u['rate'] . '%'
            ]);
        }
    }

    if (!empty($detail_rows)) {
        fputcsv($out, []);
        fputcsv($out, []);
        fputcsv($out, ['--- DETAILED CALLS (' . ($selected_user_name ?: ('User #' . $selected_user_id)) . ') ---']);
        fputcsv($out, ['Date & Time', 'Telecaller', 'Customer Name', 'Mobile Number', 'Company', 'Duration', 'Call Status', 'Source Database']);
        foreach ($detail_rows as $dr) {
            $durStr = (int)($dr['call_duration'] ?? 0) > 0 ? (int)$dr['call_duration'] . 's' : '0s';
            fputcsv($out, [
                $dr['call_time'] ? date('d-m-Y H:i:s', strtotime($dr['call_time'])) : '',
                $selected_user_name,
                $dr['cust_name'] ?: 'None',
                $dr['cust_mobile'] ?: '',
                $dr['cust_company'] ?: '',
                $durStr,
                $dr['call_status'],
                $dr['source']
            ]);
        }
    }

    fclose($out);
    exit;
}

$pageTitle = 'Call Reports • CallNow CRM';
include '../../php_scripts/header.php';
?>

<div class="container-fluid px-3 py-3">
    <!-- Module Header -->
    <div class="module-header mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 fs-xs">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="#">Activities & Reports</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Call Reports</li>
                </ol>
            </nav>
            <h1 class="h4 fw-bold text-dark mb-0">
                Call Performance Reports
                <?php if (USER_ROLE === 'Supervisor'): ?><span class="text-muted fs-6 fw-normal ms-1">(My Team)</span><?php endif; ?>
                <?php if (USER_ROLE === 'Officer'): ?><span class="text-muted fs-6 fw-normal ms-1">(My Calls)</span><?php endif; ?>
            </h1>
            <div class="text-muted fs-xs">Range: <strong><?= htmlspecialchars($rangeLabel) ?></strong> &bull; <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?></div>
        </div>
        <div class="module-header-actions">
            <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['export' => 'csv'])) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Excel
            </a>
            <a href="<?= htmlspecialchars(url('modules/logs/call_history.php')) ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-clock-history me-1"></i> Call History Log
            </a>
        </div>
    </div>

    <!-- Metric KPI Cards -->
    <div class="row g-2 mb-3">
        <div class="col-sm-6 col-md-2">
            <div class="metric-card">
                <div class="metric-card-title">Total Calls</div>
                <div class="metric-card-value text-dark"><?= number_format($grand_total) ?></div>
                <div class="metric-card-subtitle text-muted">Dialed in period</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-2">
            <div class="metric-card">
                <div class="metric-card-title">Connected</div>
                <div class="metric-card-value text-primary"><?= number_format($grand_connected) ?></div>
                <div class="metric-card-subtitle text-muted">Duration > 0s</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="metric-card">
                <div class="metric-card-title">Interested / Hot</div>
                <div class="metric-card-value text-success"><?= number_format($grand_interested) ?></div>
                <div class="metric-card-subtitle text-muted">Positive conversion</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="metric-card">
                <div class="metric-card-title">Not Interested</div>
                <div class="metric-card-value text-secondary"><?= number_format($grand_not_interested) ?></div>
                <div class="metric-card-subtitle text-muted">Customer declined</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-2">
            <div class="metric-card">
                <div class="metric-card-title">Connect Rate</div>
                <div class="metric-card-value <?= $grand_rate >= 40 ? 'text-success' : 'text-danger' ?>"><?= $grand_rate ?>%</div>
                <div class="metric-card-subtitle text-muted">Fleet benchmark</div>
            </div>
        </div>
    </div>

    <!-- Filter Toolbar Card -->
    <div class="crm-card mb-3">
        <div class="crm-card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3 col-6">
                    <label class="form-label fs-xs mb-1" for="rangeSelect">Time Preset</label>
                    <select name="range" class="form-select form-select-sm" id="rangeSelect">
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

                <div id="customDateRange" class="col-md-4 col-12 <?= $range === 'custom' ? 'd-flex' : 'd-none' ?> gap-2">
                    <div class="w-50">
                        <label class="form-label fs-xs mb-1">From Date</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($from) ?>">
                    </div>
                    <div class="w-50">
                        <label class="form-label fs-xs mb-1">To Date</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($to) ?>">
                    </div>
                </div>

                <?php if (in_array(USER_ROLE, ['Admin','Manager']) || USER_ROLE === 'Super Admin'): ?>
                    <div class="col-md-3 col-6">
                        <label class="form-label fs-xs mb-1">Team Filter</label>
                        <select name="team" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Teams</option>
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
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel me-1"></i> Apply Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.getElementById('rangeSelect').addEventListener('change', function() {
        var el = document.getElementById('customDateRange');
        if (this.value === 'custom') {
            el.classList.remove('d-none');
            el.classList.add('d-flex');
        } else {
            el.classList.add('d-none');
            el.classList.remove('d-flex');
            this.form.submit();
        }
    });
    </script>

    <!-- Team & Telecaller Summary -->
    <div id="exportArea">
    <?php if (!empty($data)): ?>
        <div class="crm-card mb-3">
            <div class="crm-card-header">
                <div class="crm-card-title"><i class="bi bi-people text-primary me-2"></i>Team & Telecaller Performance Summary</div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge badge-secondary fs-xs">
                        <?= number_format(count($TBL_USERS_index)) ?> Active Telecallers
                    </span>
                    <button id="btnExport" class="btn btn-secondary btn-sm py-1 px-2 fs-xs">
                        <i class="bi bi-file-earmark-excel me-1 text-success"></i> Export Grid
                    </button>
                </div>
            </div>
            <div class="crm-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th>Supervisor</th>
                                <th>Telecaller</th>
                                <th class="text-center">Total Dials</th>
                                <th class="text-center">Connected</th>
                                <th class="text-center">Interested</th>
                                <th class="text-center">Not Interested</th>
                                <th class="text-center">Follow-up</th>
                                <th class="text-center">Busy / No Ans</th>
                                <th class="text-center">DNC</th>
                                <th class="text-center">Other</th>
                                <th class="text-center">Connect %</th>
                                <th class="text-center" data-exclude="true">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $team_name => $block):
                                $team_total = 0;
                                $team_connected = 0;
                                foreach ($block['TBL_USERS'] as $u) {
                                    $team_total     += (int)$u['total'];
                                    $team_connected += (int)$u['connected'];
                                }
                                $sup = $block['supervisor_name'] ?? '—';
                            ?>
                                <?php foreach ($block['TBL_USERS'] as $u):
                                    $rate = $u['rate'];
                                    $rateGood = $rate >= 40;
                                    $isSelected = $selected_user_id && $selected_user_id == $u['user_id'];
                                ?>
                                    <tr class="<?= $isSelected ? 'table-active' : '' ?>">
                                        <td class="fw-semibold text-secondary fs-xs"><?= htmlspecialchars($team_name) ?></td>
                                        <td class="text-muted fs-xs"><?= htmlspecialchars($sup ?: '—') ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="avatar-circle" style="width:28px; height:28px; font-size:11px; background:#e2e8f0; color:#1e293b; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700;">
                                                    <?= strtoupper(substr($u['user_name'] ?? '?', 0, 2)) ?>
                                                </div>
                                                <div>
                                                    <strong class="text-dark fs-xs rp-emp-name"><?= htmlspecialchars($u['user_name'] ?? 'Unknown') ?></strong>
                                                    <?php if ($isSelected): ?>
                                                        <span class="badge badge-primary fs-xs ms-1" data-exclude="true">Filtered</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center fs-xs fw-bold"><?= number_format($u['total']) ?></td>
                                        <td class="text-center fs-xs"><span class="badge badge-primary"><?= number_format($u['connected']) ?></span></td>
                                        <td class="text-center fs-xs"><span class="badge badge-success"><?= number_format($u['interested']) ?></span></td>
                                        <td class="text-center fs-xs text-muted"><?= number_format($u['not_interested']) ?></td>
                                        <td class="text-center fs-xs"><span class="badge badge-info"><?= number_format($u['followup']) ?></span></td>
                                        <td class="text-center fs-xs text-muted"><?= number_format($u['busy_no_ans']) ?></td>
                                        <td class="text-center fs-xs"><span class="badge badge-danger"><?= number_format($u['dnc']) ?></span></td>
                                        <td class="text-center fs-xs text-muted"><?= number_format($u['other']) ?></td>
                                        <td class="text-center fs-xs">
                                            <span class="badge <?= $rateGood ? 'badge-success' : 'badge-danger' ?>">
                                                <?= $rate ?>%
                                            </span>
                                        </td>
                                        <td class="text-center" data-exclude="true">
                                            <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['user' => $u['user_id']])) ?>"
                                               class="btn btn-secondary btn-sm py-1 px-2 fs-xs">
                                                <i class="bi bi-list-check me-1"></i> Logs
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Charts Row -->
    <?php if ($grand_total > 0): ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="crm-card h-100">
                <div class="crm-card-header">
                    <div class="crm-card-title"><i class="bi bi-graph-up text-primary me-2"></i>Call Trend (<?= $bucket==='month'?'Monthly':'Daily' ?>)</div>
                </div>
                <div class="crm-card-body">
                    <div style="position:relative; height:240px;"><canvas id="trendChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="crm-card h-100">
                <div class="crm-card-header">
                    <div class="crm-card-title"><i class="bi bi-pie-chart text-primary me-2"></i>Disposition Breakdown</div>
                </div>
                <div class="crm-card-body">
                    <div style="position:relative; height:240px;"><canvas id="statusChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="crm-card">
                <div class="crm-card-header">
                    <div class="crm-card-title"><i class="bi bi-bar-chart text-primary me-2"></i>Calls per Telecaller Comparison</div>
                </div>
                <div class="crm-card-body">
                    <div style="position:relative; height:260px;"><canvas id="userChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
        <div class="crm-card mb-3 text-center py-5">
            <i class="bi bi-telephone-x text-muted fs-1 mb-2"></i>
            <h6 class="fw-bold text-dark">No call records found for this period</h6>
            <p class="fs-xs text-muted mb-0">Try adjusting your date range or clearing the active filters.</p>
        </div>
    <?php endif; ?>

    <!-- Detailed Calls for selected telecaller -->
    <?php if ($selected_user_id && !empty($detail_rows)): ?>
        <div class="crm-card mt-3">
            <div class="crm-card-header">
                <div class="crm-card-title">
                    <i class="bi bi-person-lines-fill text-primary me-2"></i>
                    Call Logs Drilldown &bull; <?= htmlspecialchars($selected_user_name ?? ('User #' . $selected_user_id)) ?>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge badge-primary fs-xs"><?= count($detail_rows) ?> calls</span>
                    <a href="<?= $self ?>?<?= htmlspecialchars(reportQs()) ?>" class="btn btn-secondary btn-sm py-1 px-2 fs-xs">
                        <i class="bi bi-x-lg me-1"></i> Clear Selection
                    </a>
                </div>
            </div>
            <div class="crm-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Customer Name</th>
                                <th>Mobile Number</th>
                                <th>Company</th>
                                <th>Duration</th>
                                <th>Call Status</th>
                                <th>Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($detail_rows as $r):
                                $timeStr = $r['call_time'] ? date('d M Y, h:i A', strtotime($r['call_time'])) : '—';
                                $stLow = strtolower(trim($r['call_status']));
                                $badgeCls = 'badge-secondary';
                                if (in_array($stLow, ['connected', 'lead', 'interested', 'interested / hot', 'deal converted'])) {
                                    $badgeCls = 'badge-success';
                                } elseif (in_array($stLow, ['not interested', 'not_interested'])) {
                                    $badgeCls = 'badge-secondary';
                                } elseif (in_array($stLow, ['busy', 'busy / no answer', 'no answer', 'no_answer'])) {
                                    $badgeCls = 'badge-warning';
                                } elseif (in_array($stLow, ['do not call', 'dnc', 'dnd', 'dnd number', 'dnd requested', 'do not disturb', 'not_connected_dnd'])) {
                                    $badgeCls = 'badge-danger';
                                } elseif (in_array($stLow, ['pending', 'call back', 'callback', 'follow up', 'follow-up', 'follow-up needed', 'callback / follow-up'])) {
                                    $badgeCls = 'badge-info';
                                }
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold fs-xs text-dark"><?= htmlspecialchars(date('d M Y', strtotime($r['call_time']))) ?></div>
                                        <small class="text-muted fs-xs"><?= htmlspecialchars(date('h:i A', strtotime($r['call_time']))) ?></small>
                                    </td>
                                    <td><strong class="text-dark fs-xs"><?= htmlspecialchars($r['cust_name'] ?: '—') ?></strong></td>
                                    <td>
                                        <?php if ($r['cust_mobile']): ?>
                                            <a href="tel:<?= urlencode($r['cust_mobile']) ?>" class="text-decoration-none font-monospace fs-xs">
                                                <i class="bi bi-telephone me-1 text-primary"></i><?= htmlspecialchars($r['cust_mobile']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted fs-xs">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="text-muted fs-xs"><?= htmlspecialchars($r['cust_company'] ?: '—') ?></span></td>
                                    <td>
                                        <?php if ((int)($r['call_duration'] ?? 0) > 0): ?>
                                            <span class="badge badge-secondary fs-xs">
                                                <i class="bi bi-stopwatch me-1"></i>
                                                <?= (int)$r['call_duration'] >= 60 ? floor($r['call_duration']/60) . 'm ' . ($r['call_duration']%60) . 's' : $r['call_duration'] . 's' ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted fs-xs">0s</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $badgeCls ?> fs-xs">
                                            <?= htmlspecialchars($r['call_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-secondary fs-xs">
                                            <?= htmlspecialchars($r['source']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
    </div><!-- /exportArea -->
</div>

<?php include '../../php_scripts/footer.php'; ?>

<script src="<?= vnd('js/chart.umd.min.js') ?>"></script>
<script src="<?= vnd('js/table2excel.min.js') ?>"></script>
<script>
<?php if ($grand_total > 0): ?>
const css = getComputedStyle(document.documentElement);
Chart.defaults.color = css.getPropertyValue('--crm-text-secondary').trim() || '#64748b';
Chart.defaults.borderColor = css.getPropertyValue('--crm-border-color').trim() || '#e2e8f0';
Chart.defaults.font.family = "'Inter', -apple-system, sans-serif";

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
            { label: 'Total Calls', data: trendTotal, borderColor: '#0066cc', backgroundColor: 'rgba(0,102,204,0.08)', fill: true, tension: .2 },
            { label: 'Connected', data: trendConnected, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.08)', fill: true, tension: .2 }
        ]
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false, 
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'bottom' } } 
    }
});

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusValues,
            backgroundColor: ['#10b981', '#64748b', '#0066cc', '#f59e0b', '#ef4444', '#8b5cf6']
        }]
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } } 
    }
});

new Chart(document.getElementById('userChart'), {
    type: 'bar',
    data: {
        labels: userLabels,
        datasets: [{ label: 'Total Calls', data: userTotals, backgroundColor: '#0066cc', borderRadius: 4 }]
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } 
    }
});
<?php endif; ?>

document.getElementById('btnExport')?.addEventListener('click', () => {
    const container = document.getElementById('exportArea');
    if (!container) return;

    const clone = container.cloneNode(true);
    clone.querySelectorAll('.avatar-circle, [data-exclude="true"]').forEach(el => el.remove());

    clone.querySelectorAll('.rp-emp-name').forEach(el => {
        const text = el.textContent.trim();
        const parentTd = el.closest('td');
        if (parentTd) parentTd.textContent = text;
    });

    const tables = clone.querySelectorAll('table');
    if (!tables.length) return;

    const exporter = new Table2Excel();
    exporter.export(tables, {
        filename: "CallNow_CallReport_<?= date('d-m-Y_H-i') ?>"
    });
});
</script>
