<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php';

requirePermission('view_reports');

// Determine roles and scope
$userRole     = USER_ROLE;
$isSuperAdmin = isSuperAdmin() || ($userRole === 'Super Admin');
$isAdmin      = isAdmin() || ($userRole === 'Admin');
$isManager    = isManager() || ($userRole === 'Manager');
$isSupervisor = isSupervisor() || ($userRole === 'Supervisor');
$isOfficer    = ($userRole === 'Officer') && !$isAdmin && !$isSuperAdmin && !$isSupervisor && !$isManager;

// Date range (default: Today) + presets
$range            = $_GET['range'] ?? 'today';
$from             = $_GET['from'] ?? '';
$to               = $_GET['to']   ?? '';
$team_filter      = $_GET['team'] ?? '';
$supervisor_filter= $_GET['supervisor'] ?? '';
$selected_user_id = isset($_GET['user']) && is_numeric($_GET['user'])
    ? (int)$_GET['user']
    : ($isOfficer ? (int)USER_ID : null);
$active_tab       = $_GET['tab'] ?? ($selected_user_id ? 'drilldown' : 'overview');

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

$rangeLabel    = $rangeLabels[$range] ?? 'Today';
$spanDays      = (new DateTime($to ?: 'today'))->diff(new DateTime($from ?: 'today'))->days + 1;
$bucket        = $spanDays > 31 ? 'month' : 'day';
$missingCustom = ($range === 'custom' && (!$from || !$to));
$runQueries    = !$missingCustom;

// Supervisor team resolution
$supervisorTeamIds = [];
$supervisorTeamOptions = [];
if ($isSupervisor) {
    if (USER_TEAM_ID !== null && (int)USER_TEAM_ID > 0) {
        $supervisorTeamIds[] = (int)USER_TEAM_ID;
    }
    $stQ = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " WHERE SUPERVISOR_ID = " . (int)USER_ID);
    if ($stQ) {
        while ($stR = mysqli_fetch_assoc($stQ)) {
            $supervisorTeamIds[] = (int)$stR['ID'];
            $supervisorTeamOptions[] = $stR;
        }
    }
    $supervisorTeamIds = array_values(array_unique(array_filter($supervisorTeamIds)));
}

// Query parameters + WHERE
$paramsBase = [$from, $to];
$typesBase  = "ss";

$whereParts   = [];
$whereParts[] = "m.user_id IS NOT NULL";
$whereParts[] = "m.call_time IS NOT NULL";
$whereParts[] = "DATE(m.call_time) BETWEEN ? AND ?";

if ($isSupervisor) {
    if (!empty($supervisorTeamIds)) {
        if ($team_filter !== '' && is_numeric($team_filter) && in_array((int)$team_filter, $supervisorTeamIds, true)) {
            $whereParts[] = "u.TEAM_ID = ?";
            $paramsBase[] = (int)$team_filter;
            $typesBase   .= "i";
        } else {
            $inList = implode(',', $supervisorTeamIds);
            $whereParts[] = "(u.TEAM_ID IN ($inList) OR u.ID = ?)";
            $paramsBase[] = (int)USER_ID;
            $typesBase   .= "i";
        }
    } else {
        $whereParts[] = "u.ID = ?";
        $paramsBase[] = (int)USER_ID;
        $typesBase   .= "i";
    }
} elseif ($isOfficer) {
    $whereParts[] = "u.ID = ?";
    $paramsBase[] = (int)USER_ID;
    $typesBase   .= "i";
} else {
    // Super Admin / Admin / Manager
    if ($team_filter !== '' && is_numeric($team_filter)) {
        $whereParts[] = "u.TEAM_ID = ?";
        $paramsBase[] = (int)$team_filter;
        $typesBase   .= "i";
    }
    if ($supervisor_filter !== '' && is_numeric($supervisor_filter)) {
        $whereParts[] = "t.SUPERVISOR_ID = ?";
        $paramsBase[] = (int)$supervisor_filter;
        $typesBase   .= "i";
    }
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
        SUM(CASE WHEN (m.call_duration > 0 OR LOWER(m.call_status) IN ('connected', 'lead', 'interested', 'intrested', 'interested / hot', 'intrested / hot', 'deal converted', 'follow-up needed', 'callback / follow-up', 'follow-up', 'follow up', 'not interested', 'not intrested', 'other discussion', 'enquiry/ interested', 'enquiry/ intrested', 'callback/ busy', 'callback / busy')) THEN 1 ELSE 0 END) AS connected,
        SUM(CASE WHEN (LOWER(m.call_status) NOT LIKE '%not%' AND (LOWER(m.call_status) LIKE '%interest%' OR LOWER(m.call_status) LIKE '%intrest%' OR LOWER(m.call_status) LIKE '%enquiry%' OR LOWER(m.call_status) LIKE '%converted%' OR LOWER(m.call_status) LIKE '%lead%')) THEN 1 ELSE 0 END) AS interested,
        SUM(CASE WHEN (LOWER(m.call_status) LIKE '%not interest%' OR LOWER(m.call_status) LIKE '%not intrest%' OR LOWER(m.call_status) IN ('not interested', 'not_interested', 'not intrested')) THEN 1 ELSE 0 END) AS not_interested,
        SUM(CASE WHEN (LOWER(m.call_status) LIKE '%callback%' OR LOWER(m.call_status) LIKE '%call back%' OR LOWER(m.call_status) LIKE '%follow%' OR LOWER(m.call_status) IN ('follow-up needed', 'callback / follow-up', 'follow up', 'follow-up', 'call back', 'callback', 'pending', 'callback/ busy', 'callback / busy')) THEN 1 ELSE 0 END) AS followup,
        SUM(CASE WHEN LOWER(m.call_status) IN ('busy / no answer', 'busy', 'no answer', 'no_answer', 'ringing', 'switch off', 'switch_off', 'not rechable', 'not reachable') THEN 1 ELSE 0 END) AS busy_no_ans,
        SUM(CASE WHEN LOWER(m.call_status) IN ('do not call', 'dnc', 'dnd', 'dnd number', 'dnd requested', 'do not disturb', 'not_connected_dnd') THEN 1 ELSE 0 END) AS dnc,
        SUM(CASE WHEN (
            (LOWER(m.call_status) NOT LIKE '%not%' AND (LOWER(m.call_status) LIKE '%interest%' OR LOWER(m.call_status) LIKE '%intrest%' OR LOWER(m.call_status) LIKE '%enquiry%' OR LOWER(m.call_status) LIKE '%converted%' OR LOWER(m.call_status) LIKE '%lead%')) = 0
            AND (LOWER(m.call_status) LIKE '%not interest%' OR LOWER(m.call_status) LIKE '%not intrest%' OR LOWER(m.call_status) IN ('not interested', 'not_interested', 'not intrested')) = 0
            AND (LOWER(m.call_status) LIKE '%callback%' OR LOWER(m.call_status) LIKE '%call back%' OR LOWER(m.call_status) LIKE '%follow%' OR LOWER(m.call_status) IN ('follow-up needed', 'callback / follow-up', 'follow up', 'follow-up', 'call back', 'callback', 'pending', 'callback/ busy', 'callback / busy')) = 0
            AND LOWER(m.call_status) NOT IN ('busy / no answer', 'busy', 'no answer', 'no_answer', 'ringing', 'switch off', 'switch_off', 'not rechable', 'not reachable')
            AND LOWER(m.call_status) NOT IN ('do not call', 'dnc', 'dnd', 'dnd number', 'dnd requested', 'do not disturb', 'not_connected_dnd')
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

        $team_name = $row['team_name'] ?: 'General Calling Fleet';
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

// Filter dropdown lists
$TBL_TEAMS = ($isSuperAdmin || $isAdmin || $isManager)
    ? mysqli_fetch_all(mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " ORDER BY NAME"), MYSQLI_ASSOC)
    : ($isSupervisor ? $supervisorTeamOptions : []);

$allSupervisors = ($isSuperAdmin || $isAdmin || $isManager)
    ? mysqli_fetch_all(mysqli_query($link, "SELECT ID, NAME, ROLE FROM " . tn('TBL_USERS') . " WHERE ROLE IN ('Supervisor', 'Manager', 'Admin') AND STATUS = 'Active' ORDER BY NAME"), MYSQLI_ASSOC)
    : [];

$trend = [];
$bucketExpr = $bucket === 'month'
    ? "DATE_FORMAT(m.call_time, '%Y-%m-01')"
    : "DATE(m.call_time)";

$sqlTrend = "
    SELECT $bucketExpr AS bucket,
           COUNT(*) AS total,
           SUM(CASE WHEN (m.call_duration > 0 OR LOWER(m.call_status) IN ('connected', 'lead', 'interested', 'intrested', 'interested / hot', 'intrested / hot', 'deal converted', 'follow-up needed', 'callback / follow-up', 'follow-up', 'follow up', 'not interested', 'not intrested', 'other discussion', 'enquiry/ interested', 'enquiry/ intrested', 'callback/ busy', 'callback / busy')) THEN 1 ELSE 0 END) AS connected
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
        LIMIT 500
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
    global $range, $from, $to, $team_filter, $supervisor_filter, $selected_user_id, $active_tab;
    $qs = [
        'range'      => $range,
        'from'       => $from,
        'to'         => $to,
        'team'       => $team_filter,
        'supervisor' => $supervisor_filter,
        'tab'        => $active_tab,
    ];
    if ($selected_user_id) {
        $qs['user'] = $selected_user_id;
    }
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
    fputcsv($out, ['Scope', $isSuperAdmin ? 'Super Admin (All Organization)' : ($isAdmin ? 'Admin (Company-wide)' : ($isSupervisor ? 'Team Leader' : 'Officer'))]);
    fputcsv($out, ['Total Calls', $grand_total, 'Connected', $grand_connected, 'Connect Rate', $grand_rate . '%']);
    fputcsv($out, []);

    fputcsv($out, ['--- TEAM & TELECALLER PERFORMANCE SUMMARY ---']);
    fputcsv($out, ['Team', 'Supervisor / Team Leader', 'Telecaller', 'Total Calls', 'Connected', 'Interested', 'Not Interested', 'Follow-up', 'Busy / No Answer', 'DNC', 'Other', 'Connect Rate (%)']);

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
        fputcsv($out, ['--- DETAILED CALL LOGS (' . ($selected_user_name ?: ('User #' . $selected_user_id)) . ') ---']);
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
    <div class="module-header mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 fs-xs">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/logs/Reports.php') ?>">Activities & Reports</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Call Reports</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center flex-wrap gap-2">
                <h1 class="h4 fw-bold text-dark mb-0">Call Performance Reports</h1>
                <?php if ($isSuperAdmin): ?>
                    <span class="badge badge-purple px-2 py-1"><i class="bi bi-shield-check me-1"></i> Super Admin Scope (All Organization)</span>
                <?php elseif ($isAdmin): ?>
                    <span class="badge badge-primary px-2 py-1"><i class="bi bi-building me-1"></i> Admin Scope (All Teams)</span>
                <?php elseif ($isSupervisor): ?>
                    <span class="badge badge-info px-2 py-1"><i class="bi bi-people-fill me-1"></i> Team Leader Scope (My Teams)</span>
                <?php else: ?>
                    <span class="badge badge-secondary px-2 py-1"><i class="bi bi-person-circle me-1"></i> Personal Scope (My Calls)</span>
                <?php endif; ?>
            </div>
            <div class="text-muted fs-xs mt-1">Period: <strong><?= htmlspecialchars($rangeLabel) ?></strong> &bull; <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?></div>
        </div>
        <div class="module-header-actions d-flex align-items-center gap-2">
            <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['export' => 'csv'])) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Excel
            </a>
            <a href="<?= htmlspecialchars(url('modules/logs/call_history.php')) ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-clock-history me-1"></i> Live Call Logs
            </a>
        </div>
    </div>

    <!-- Metric KPI Hero Cards -->
    <div class="row g-3 mb-3">
        <div class="col-xl col-lg-4 col-md-6 col-sm-6">
            <div class="metric-card metric-neutral p-3">
                <div class="metric-card-title">Total Calls Dialed</div>
                <div class="metric-card-value text-dark text-nowrap"><?= number_format($grand_total) ?></div>
                <div class="metric-card-subtitle text-muted">Calls in selected window</div>
            </div>
        </div>
        <div class="col-xl col-lg-4 col-md-6 col-sm-6">
            <div class="metric-card metric-primary p-3">
                <div class="metric-card-title">Connected Calls</div>
                <div class="metric-card-value text-primary text-nowrap"><?= number_format($grand_connected) ?></div>
                <div class="metric-card-subtitle text-muted">Verified duration &gt; 0s</div>
            </div>
        </div>
        <div class="col-xl col-lg-4 col-md-6 col-sm-6">
            <div class="metric-card metric-success p-3">
                <div class="metric-card-title">Interested / Hot Leads</div>
                <div class="metric-card-value text-success text-nowrap"><?= number_format($grand_interested) ?></div>
                <div class="metric-card-subtitle text-muted">Productive conversion</div>
            </div>
        </div>
        <div class="col-xl col-lg-4 col-md-6 col-sm-6">
            <div class="metric-card metric-secondary p-3">
                <div class="metric-card-title">Not Interested</div>
                <div class="metric-card-value text-secondary text-nowrap"><?= number_format($grand_not_interested) ?></div>
                <div class="metric-card-subtitle text-muted">Customer declined offer</div>
            </div>
        </div>
        <div class="col-xl col-lg-4 col-md-6 col-sm-6">
            <div class="metric-card <?= $grand_rate >= 40 ? 'metric-success' : 'metric-danger' ?> p-3">
                <div class="metric-card-title">Connect Rate</div>
                <div class="metric-card-value <?= $grand_rate >= 40 ? 'text-success' : 'text-danger' ?> text-nowrap"><?= $grand_rate ?>%</div>
                <div class="metric-card-subtitle text-muted">Benchmark: 40%+ target</div>
            </div>
        </div>
    </div>

    <!-- Advanced Filter Toolbar -->
    <div class="crm-card mb-3">
        <div class="crm-card-body py-2">
            <form method="GET" class="row g-2 align-items-end" id="reportFilterForm">
                <input type="hidden" name="tab" id="activeTabInput" value="<?= htmlspecialchars($active_tab) ?>">

                <div class="col-md-3 col-sm-6">
                    <label class="form-label fs-xs mb-1" for="rangeSelect">Time Window</label>
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
                        <option value="custom"       <?= $range==='custom'?'selected':'' ?>>Custom Date Range</option>
                    </select>
                </div>

                <div id="customDateRange" class="col-md-3 col-sm-6 <?= $range === 'custom' ? 'd-flex' : 'd-none' ?> gap-2">
                    <div class="w-50">
                        <label class="form-label fs-xs mb-1">From Date</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($from) ?>">
                    </div>
                    <div class="w-50">
                        <label class="form-label fs-xs mb-1">To Date</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($to) ?>">
                    </div>
                </div>

                <?php if ($isSuperAdmin || $isAdmin || $isManager || ($isSupervisor && count($TBL_TEAMS) > 1)): ?>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label fs-xs mb-1">Team Filter</label>
                        <select name="team" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Assigned Teams</option>
                            <?php foreach($TBL_TEAMS as $t): ?>
                                <option value="<?= $t['ID'] ?>" <?= $team_filter == $t['ID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['NAME']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if (($isSuperAdmin || $isAdmin || $isManager) && !empty($allSupervisors)): ?>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label fs-xs mb-1">Team Leader</label>
                        <select name="supervisor" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Team Leaders</option>
                            <?php foreach($allSupervisors as $s): ?>
                                <option value="<?= $s['ID'] ?>" <?= $supervisor_filter == $s['ID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['NAME']) ?> (<?= htmlspecialchars($s['ROLE']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($selected_user_id && !$isOfficer): ?>
                    <input type="hidden" name="user" value="<?= (int)$selected_user_id ?>">
                <?php endif; ?>

                <div class="col-auto ms-auto d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel me-1"></i> Apply
                    </button>
                    <?php if ($team_filter !== '' || $supervisor_filter !== '' || ($selected_user_id && !$isOfficer) || $range !== 'today'): ?>
                        <a href="<?= $self ?>" class="btn btn-secondary btn-sm" title="Reset Filters">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Advanced Normalized Navigation Tabs -->
    <ul class="nav nav-tabs nav-fill mb-3" id="reportTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $active_tab === 'overview' ? 'active' : '' ?>" id="overview-tab" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button" role="tab" aria-controls="tab-overview" aria-selected="<?= $active_tab === 'overview' ? 'true' : 'false' ?>">
                <i class="bi bi-graph-up me-1 text-primary"></i> Executive Overview & Trends
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $active_tab === 'teams' ? 'active' : '' ?>" id="teams-tab" data-bs-toggle="tab" data-bs-target="#tab-teams" type="button" role="tab" aria-controls="tab-teams" aria-selected="<?= $active_tab === 'teams' ? 'true' : 'false' ?>">
                <i class="bi bi-people me-1 text-success"></i> Team & Telecaller Performance
                <span class="badge bg-secondary ms-1 fs-xs"><?= count($TBL_USERS_index) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $active_tab === 'drilldown' ? 'active' : '' ?>" id="drilldown-tab" data-bs-toggle="tab" data-bs-target="#tab-drilldown" type="button" role="tab" aria-controls="tab-drilldown" aria-selected="<?= $active_tab === 'drilldown' ? 'true' : 'false' ?>">
                <i class="bi bi-list-check me-1 text-info"></i> Detailed Call Logs Drilldown
                <?php if (!empty($detail_rows)): ?>
                    <span class="badge bg-primary ms-1 fs-xs"><?= count($detail_rows) ?></span>
                <?php endif; ?>
            </button>
        </li>
    </ul>

    <!-- Tab Contents Container -->
    <div class="tab-content" id="reportTabsContent">

        <!-- TAB 1: EXECUTIVE OVERVIEW & TRENDS -->
        <div class="tab-pane fade <?= $active_tab === 'overview' ? 'show active' : '' ?>" id="tab-overview" role="tabpanel" aria-labelledby="overview-tab">
            <?php if ($grand_total > 0): ?>
                <div class="row g-3 mb-3">
                    <div class="col-lg-8">
                        <div class="crm-card h-100">
                            <div class="crm-card-header d-flex align-items-center justify-content-between">
                                <div class="crm-card-title"><i class="bi bi-graph-up text-primary me-2"></i>Call Volume & Connection Trend (<?= $bucket==='month'?'Monthly':'Daily' ?>)</div>
                                <span class="badge badge-secondary fs-xs">Total: <?= number_format($grand_total) ?></span>
                            </div>
                            <div class="crm-card-body">
                                <div style="position:relative; height:260px;"><canvas id="trendChart"></canvas></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="crm-card h-100">
                            <div class="crm-card-header">
                                <div class="crm-card-title"><i class="bi bi-pie-chart text-primary me-2"></i>Disposition Breakdown</div>
                            </div>
                            <div class="crm-card-body">
                                <div style="position:relative; height:260px;"><canvas id="statusChart"></canvas></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="crm-card">
                            <div class="crm-card-header d-flex align-items-center justify-content-between">
                                <div class="crm-card-title"><i class="bi bi-bar-chart text-primary me-2"></i>Telecaller Volume Comparison</div>
                                <span class="text-muted fs-xs">Ranked by calls dialed</span>
                            </div>
                            <div class="crm-card-body">
                                <div style="position:relative; height:260px;"><canvas id="userChart"></canvas></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="crm-card text-center py-5">
                    <i class="bi bi-telephone-x text-muted fs-1 mb-2"></i>
                    <h6 class="fw-bold text-dark">No call records found for this period</h6>
                    <p class="fs-xs text-muted mb-0">Try adjusting your time window or clearing active filters above.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 2: TEAM & TELECALLER PERFORMANCE -->
        <div class="tab-pane fade <?= $active_tab === 'teams' ? 'show active' : '' ?>" id="tab-teams" role="tabpanel" aria-labelledby="teams-tab">
            <div id="exportArea">
                <?php if (!empty($data)): ?>
                    <div class="crm-card mb-3">
                        <div class="crm-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <div class="crm-card-title mb-0"><i class="bi bi-people text-primary me-2"></i>Team & Telecaller Performance Summary</div>
                                <span class="badge badge-secondary fs-xs">
                                    <?= number_format(count($TBL_USERS_index)) ?> Active Telecallers
                                </span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <div class="input-group input-group-sm" style="max-width: 240px;">
                                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                                    <input type="text" id="telecallerFilterInput" class="form-control" placeholder="Search telecaller / team...">
                                </div>
                                <button id="btnExport" class="btn btn-secondary btn-sm py-1 px-2 fs-xs">
                                    <i class="bi bi-file-earmark-excel me-1 text-success"></i> Export Grid
                                </button>
                            </div>
                        </div>
                        <div class="crm-card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="telecallerSummaryTable">
                                    <thead>
                                        <tr>
                                            <th>Team</th>
                                            <th>Team Leader / Supervisor</th>
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
                                            <th class="text-center" data-exclude="true">Action</th>
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
                                                <tr class="telecaller-row <?= $isSelected ? 'table-active' : '' ?>">
                                                    <td class="fw-semibold text-secondary fs-xs"><?= htmlspecialchars($team_name) ?></td>
                                                    <td class="text-muted fs-xs"><?= htmlspecialchars($sup ?: '—') ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <div class="avatar-circle" style="width:28px; height:28px; font-size:11px;">
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
                                                        <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['user' => $u['user_id'], 'tab' => 'drilldown'])) ?>"
                                                           class="btn btn-secondary btn-sm py-1 px-2 fs-xs" title="View Call Records Drilldown">
                                                            <i class="bi bi-clock-history me-1 text-primary"></i> Logs
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
                <?php else: ?>
                    <div class="crm-card text-center py-5">
                        <i class="bi bi-people text-muted fs-1 mb-2"></i>
                        <h6 class="fw-bold text-dark">No telecaller calling activity recorded for this period</h6>
                        <p class="fs-xs text-muted mb-0">Select a wider date range or clear team filters.</p>
                    </div>
                <?php endif; ?>
            </div><!-- /exportArea -->
        </div>

        <!-- TAB 3: DETAILED CALL LOGS DRILLDOWN -->
        <div class="tab-pane fade <?= $active_tab === 'drilldown' ? 'show active' : '' ?>" id="tab-drilldown" role="tabpanel" aria-labelledby="drilldown-tab">
            <?php if ($selected_user_id && !empty($detail_rows)): ?>
                <div class="crm-card">
                    <div class="crm-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="crm-card-title mb-0">
                            <i class="bi bi-person-lines-fill text-primary me-2"></i>
                            Call Records Drilldown &bull; <strong><?= htmlspecialchars($selected_user_name ?? ('User #' . $selected_user_id)) ?></strong>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge badge-primary fs-xs"><?= count($detail_rows) ?> calls logged</span>
                            <?php if (!$isOfficer): ?>
                                <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['user' => '', 'tab' => 'drilldown'])) ?>" class="btn btn-secondary btn-sm py-1 px-2 fs-xs">
                                    <i class="bi bi-x-lg me-1"></i> Clear Selection
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (count($detail_rows) >= 500): ?>
                        <div class="px-3 pt-3">
                            <div class="alert alert-info py-2 px-3 fs-xs mb-0 d-flex align-items-center justify-content-between">
                                <span><i class="bi bi-info-circle me-1"></i> Showing latest 500 calls for this period.</span>
                                <a href="<?= url('modules/logs/call_history.php') ?>" class="btn btn-outline-primary btn-sm py-0 px-2 fs-xs">Open Full Audit Trail</a>
                            </div>
                        </div>
                    <?php endif; ?>
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
                                        <th>Call Outcome</th>
                                        <th>Database Source</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($detail_rows as $r):
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
            <?php else: ?>
                <div class="crm-card text-center py-5">
                    <i class="bi bi-person-lines-fill text-muted fs-1 mb-2"></i>
                    <?php if ($selected_user_id): ?>
                        <h6 class="fw-bold text-dark">No call logs found for <?= htmlspecialchars($selected_user_name ?? 'this user') ?> in this period</h6>
                        <p class="fs-xs text-muted mb-0">Try adjusting your date range filter above.</p>
                    <?php else: ?>
                        <h6 class="fw-bold text-dark">No telecaller selected for drilldown</h6>
                        <p class="fs-xs text-muted mb-0">Switch to the "Team & Telecaller Performance" tab and click "Logs" next to any telecaller to inspect their individual call activity.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /tab-content -->
</div>

<?php include '../../php_scripts/footer.php'; ?>

<script src="<?= vnd('js/chart.umd.min.js') ?>"></script>
<script src="<?= vnd('js/table2excel.min.js') ?>"></script>
<script>
// Filter preset toggle
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

// Tab state sync with form
document.querySelectorAll('#reportTabs button[data-bs-toggle="tab"]').forEach(function(tabBtn) {
    tabBtn.addEventListener('shown.bs.tab', function(e) {
        var target = e.target.getAttribute('data-bs-target').replace('#tab-', '');
        var input = document.getElementById('activeTabInput');
        if (input) input.value = target;
    });
});

// Live filter in telecaller summary table
document.getElementById('telecallerFilterInput')?.addEventListener('input', function() {
    var term = this.value.toLowerCase().trim();
    document.querySelectorAll('#telecallerSummaryTable tbody tr.telecaller-row').forEach(function(row) {
        var text = row.textContent.toLowerCase();
        row.style.display = (term === '' || text.indexOf(term) !== -1) ? '' : 'none';
    });
});

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

if (document.getElementById('trendChart')) {
    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [
                { label: 'Total Calls Dialed', data: trendTotal, borderColor: '#6D45F9', backgroundColor: 'rgba(109,69,249,0.12)', fill: true, tension: .2 },
                { label: 'Connected Calls', data: trendConnected, borderColor: '#0090DF', backgroundColor: 'rgba(0,144,223,0.10)', fill: true, tension: .2 }
            ]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } } 
        }
    });
}

if (document.getElementById('statusChart')) {
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                data: statusValues,
                backgroundColor: ['#16A66A', '#6D45F9', '#0090DF', '#D89012', '#E5484D', '#8C72FF']
            }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } } 
        }
    });
}

if (document.getElementById('userChart')) {
    new Chart(document.getElementById('userChart'), {
        type: 'bar',
        data: {
            labels: userLabels,
            datasets: [{ label: 'Calls Dialed', data: userTotals, backgroundColor: '#6D45F9', borderRadius: 8 }]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } 
        }
    });
}
<?php endif; ?>

// Excel grid export
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
