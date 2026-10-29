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
            // keep GET values; fall back handled by caller
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
$spanDays   = (new DateTime($to))->diff(new DateTime($from))->days + 1;
$bucket     = $spanDays > 31 ? 'month' : 'day';
$missingCustom = ($range === 'custom' && (!$from || !$to));
$runQueries = !$missingCustom;


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
} elseif ($team_filter !== '' && is_numeric($team_filter) && (in_array(USER_ROLE, ['Admin','Manager']) || USER_ROLE === 'Super Admin')) {
    $whereParts[] = "u.TEAM_ID = ?";
    $paramsBase[] = (int)$team_filter;
    $typesBase   .= "i";
}

$whereSql = $whereParts
    ? "WHERE " . implode(" AND ", $whereParts)
    : "";

/**
 * Unified call log: TBL_CALL_LOGS as canonical call log joined with source tables
 */
$unionSubquery = "
    SELECT
        cl.id                  AS ID,
        cl.user_id             AS user_id,
        COALESCE(cl.call_time, cl.created_at) AS call_time,
        cl.call_status         AS call_status,
        COALESCE(t.CUST_NAME, m.MAINDATABASE_NAME, l.NAME, '') AS cust_name,
        COALESCE(t.CUST_MOBILE, m.MAINDATABASE_MOBILE, l.MOBILE, '') AS cust_mobile,
        COALESCE(t.CUST_COMPANY, m.MAINDATABASE_COMPANY, l.COMPANY_NAME, '') AS cust_company,
        cl.source              AS source
    FROM " . tn('TBL_CALL_LOGS') . " cl
    LEFT JOIN " . tn('TBL_TEMP') . " t ON cl.source = 'TEMP' AND cl.record_id = t.ID
    LEFT JOIN " . tn('TBL_MAIN') . " m ON cl.source = 'MAIN' AND cl.record_id = m.ID
    LEFT JOIN " . tn('TBL_LEADS') . " l ON cl.source = 'LEADS' AND cl.record_id = l.lead_id
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
        SUM(CASE WHEN LOWER(m.call_status) IN ('connected', 'lead', 'interested') THEN 1 ELSE 0 END) AS connected,
        SUM(CASE WHEN LOWER(m.call_status) = 'dialed'      THEN 1 ELSE 0 END) AS dialed,
        SUM(CASE WHEN LOWER(m.call_status) = 'busy'        THEN 1 ELSE 0 END) AS busy,
        SUM(CASE WHEN LOWER(m.call_status) IN ('no answer', 'no_answer') THEN 1 ELSE 0 END) AS no_answer,
        SUM(CASE WHEN LOWER(m.call_status) IN ('do not call', 'dnc', 'dnd', 'dnd number', 'do not disturb', 'not_connected_dnd') THEN 1 ELSE 0 END) AS dnc,
        SUM(CASE WHEN LOWER(m.call_status) IN ('pending', 'call back', 'callback', 'follow-up', 'follow up', 'not interested') THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN LOWER(m.call_status) IN ('not called', 'not_called') THEN 1 ELSE 0 END) AS not_called
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
}

$grand_rate = $grand_total ? round($grand_connected / $grand_total * 100, 1) : 0;

// TBL_TEAMS for filter dropdown
$TBL_TEAMS = (in_array(USER_ROLE, ['Admin','Manager']) || USER_ROLE === 'Super Admin')
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
           SUM(CASE WHEN LOWER(m.call_status) IN ('connected', 'lead', 'interested') THEN 1 ELSE 0 END) AS connected
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

// Build a query-string helper that preserves current filters.
// User selection is only included when explicitly passed via $extra['user'].
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

// ──────────────────────────────────────────────────────────────
// Clean CSV / Excel Export Handler
// ──────────────────────────────────────────────────────────────
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="CallNow_Report_' . date('Y-m-d_His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens with proper encoding
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, ['CALLNOW CRM - CALL PERFORMANCE REPORT']);
    fputcsv($out, ['Report Preset', $rangeLabel, 'Date Range', $from . ' to ' . $to]);
    fputcsv($out, ['Total Calls', $grand_total, 'Connected', $grand_connected, 'Connect Rate', $grand_rate . '%']);
    fputcsv($out, []);

    // Summary Section
    fputcsv($out, ['--- TEAM & TELECALLER SUMMARY ---']);
    fputcsv($out, ['Team', 'Supervisor', 'Telecaller', 'Total Calls', 'Connected', 'Busy', 'No Answer', 'DNC', 'Pending', 'Not Called', 'Connect Rate (%)']);

    foreach ($data as $team_name => $block) {
        $sup = $block['supervisor_name'] ?: 'None';
        foreach ($block['TBL_USERS'] as $u) {
            fputcsv($out, [
                $team_name,
                $sup,
                $u['user_name'], // Clean employee name without initials or HTML tags
                (int)$u['total'],
                (int)$u['connected'],
                (int)$u['busy'],
                (int)$u['no_answer'],
                (int)$u['dnc'],
                (int)$u['pending'],
                (int)$u['not_called'],
                $u['rate'] . '%'
            ]);
        }
    }

    // Detailed Calls Section
    if (!empty($detail_rows)) {
        fputcsv($out, []);
        fputcsv($out, []);
        fputcsv($out, ['--- DETAILED CALLS (' . ($selected_user_name ?: ('User #' . $selected_user_id)) . ') ---']);
        fputcsv($out, ['Date & Time', 'Telecaller', 'Customer Name', 'Mobile Number', 'Company', 'Call Status', 'Source Database']);
        foreach ($detail_rows as $dr) {
            fputcsv($out, [
                $dr['call_time'] ? date('d-m-Y H:i:s', strtotime($dr['call_time'])) : '',
                $selected_user_name,
                $dr['cust_name'] ?: 'None',
                $dr['cust_mobile'] ?: '',
                $dr['cust_company'] ?: '',
                $dr['call_status'],
                $dr['source']
            ]);
        }
    }

    fclose($out);
    exit;
}

$pageTitle = 'Call Report - CallNow';
include '../../php_scripts/header.php';
?>

<style>
/* ── Hero ───────────────────────────────────────────── */
.rp-hero {
    background: linear-gradient(135deg, var(--accent) 0%, #6d5dd3 55%, #8b5cf6 100%);
    color: #ffffff !important;
    padding: 1.6rem 0 1.4rem;
    border-radius: 0 0 var(--radius-xl) var(--radius-xl);
    box-shadow: var(--shadow-md);
    margin-bottom: 1.5rem;
}
.rp-hero * { color: inherit; }
.rp-eyebrow {
    font-size: .72rem; text-transform: uppercase; letter-spacing: .09em;
    color: rgba(255,255,255,.85) !important; font-weight: 600;
}
.rp-title { font-size: 1.5rem; font-weight: 700; margin: .15rem 0 .25rem; letter-spacing: -.02em; color: #ffffff !important; }
.rp-scope { font-weight: 500; color: rgba(255,255,255,.85) !important; font-size: 1rem; }
.rp-sub { font-size: .85rem; color: rgba(255,255,255,.92) !important; }
.rp-sub strong { font-weight: 600; color: #ffffff !important; }
.rp-kpis { display: flex; gap: .65rem; flex-wrap: wrap; }
.rp-kpi {
    background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.25);
    border-radius: var(--radius-lg); padding: .55rem .95rem; min-width: 96px; text-align: center;
    backdrop-filter: blur(6px);
}
.rp-kpi-label { display: block; font-size: .68rem; color: rgba(255,255,255,.85) !important; text-transform: uppercase; letter-spacing: .04em; }
.rp-kpi-value { display: block; font-size: 1.3rem; font-weight: 700; line-height: 1.25; color: #ffffff !important; }
.rp-kpi-value.text-success { color: #6ee7b7 !important; }
.rp-kpi-value.text-danger  { color: #fca5a5 !important; }

/* ── Summary table ──────────────────────────────────── */
.rp-table thead th { white-space: nowrap; }
.rp-avatar-sm { flex-shrink: 0; }
.rp-btn-hero {
    background: #ffffff !important;
    color: #4f46e5 !important;
    font-weight: 600 !important;
    border: none !important;
    border-radius: var(--radius-lg) !important;
    padding: 0.55rem 1.15rem !important;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.16) !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 0.45rem !important;
    transition: all 0.2s ease !important;
    text-decoration: none !important;
}
.rp-btn-hero:hover {
    background: #f8fafc !important;
    color: #4338ca !important;
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.22) !important;
}
.rp-btn-hero i {
    color: #4f46e5 !important;
    font-size: 1rem !important;
}

/* ── Special Card & Colored Headers ────────────────── */
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

.rp-special-card-head {
    padding: 1.15rem 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}
.rp-special-card-head.summary-head {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.08) 0%, rgba(139, 92, 246, 0.08) 100%);
    border-bottom: 2px solid rgba(99, 102, 241, 0.18);
}
.rp-special-card-head.detail-head {
    background: linear-gradient(135deg, rgba(14, 165, 233, 0.08) 0%, rgba(99, 102, 241, 0.08) 100%);
    border-bottom: 2px solid rgba(14, 165, 233, 0.18);
}

.rp-head-icon {
    width: 42px; height: 42px; border-radius: var(--radius-lg);
    background: linear-gradient(135deg, var(--accent, #6366f1), #8b5cf6);
    color: #ffffff !important; display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}
.rp-head-icon i { color: #ffffff !important; }
.rp-head-title { font-size: 1.05rem; font-weight: 700; margin: 0; color: var(--ink); }
.rp-head-sub { font-size: 0.8rem; color: var(--ink-soft); margin-top: 0.1rem; }

/* ── Modern Table with Alternative Coloring ──────────── */
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
    padding: 0.9rem 1rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--border);
    font-size: 0.86rem;
    transition: background 0.15s ease;
}

/* Alternating row zebra styling */
.rp-special-table tbody tr:nth-child(even) td {
    background-color: rgba(99, 102, 241, 0.025);
}
[data-bs-theme="dark"] .rp-special-table tbody tr:nth-child(even) td {
    background-color: rgba(255, 255, 255, 0.025);
}
.rp-special-table tbody tr:hover td {
    background-color: rgba(99, 102, 241, 0.08) !important;
}
.rp-special-table tbody tr.table-selected td {
    background-color: rgba(99, 102, 241, 0.14) !important;
}

/* Metric count pills */
.rp-num-badge {
    display: inline-block;
    min-width: 34px;
    padding: 0.2rem 0.55rem;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.82rem;
    text-align: center;
}
.rp-num-connected { background: rgba(16, 185, 129, 0.12); color: #059669; }
.rp-num-busy      { background: rgba(245, 158, 11, 0.12); color: #d97706; }
.rp-num-noans     { background: rgba(100, 116, 139, 0.12); color: #475569; }
.rp-num-dnc       { background: rgba(239, 68, 68, 0.12); color: #dc2626; }
.rp-num-pending   { background: rgba(14, 165, 233, 0.12); color: #0284c7; }
.rp-num-notcalled { background: var(--surface-2); color: var(--ink-muted); }

.rp-rate-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.65rem;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.82rem;
}
.rp-rate-good { background: rgba(16, 185, 129, 0.14); color: #059669; border: 1px solid rgba(16, 185, 129, 0.3); }
.rp-rate-poor { background: rgba(239, 68, 68, 0.14); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.3); }

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
                    <a href="<?= htmlspecialchars(url('modules/logs/call_history.php')) ?>" class="btn rp-btn-hero btn-sm ms-auto" style="align-self:center;">
                        <i class="bi bi-clock-history"></i> View Full Call History
                    </a>
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
                <select name="range" class="form-select" id="rangeSelect">
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

            <div id="customDateRange" class="row g-3 <?= $range === 'custom' ? '' : 'd-none' ?>">
                <div class="col-auto">
                    <label class="form-label d-block">From</label>
                    <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>" required>
                </div>
                <div class="col-auto">
                    <label class="form-label d-block">To</label>
                    <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>" required>
                </div>
            </div>

            <?php if ($missingCustom): ?>
                <div class="col-12">
                    <div class="alert alert-danger py-2 mb-0">Please select both From and To dates.</div>
                </div>
            <?php endif; ?>

            <?php if (in_array(USER_ROLE, ['Admin','Manager']) || USER_ROLE === 'Super Admin'): ?>
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

    <script>
    document.getElementById('rangeSelect').addEventListener('change', function() {
        var el = document.getElementById('customDateRange');
        if (this.value === 'custom') {
            el.classList.remove('d-none');
        } else {
            el.classList.add('d-none');
            this.form.submit();
        }
    });
    </script>

    <!-- Team & Telecaller Summary -->
    <div id="exportArea">
    <?php if (!empty($data)): ?>
        <div class="card rp-special-card mb-4">
            <div class="rp-special-card-head summary-head">
                <div class="d-flex align-items-center gap-3">
                    <div class="rp-head-icon"><i class="bi bi-people-fill"></i></div>
                    <div>
                        <h5 class="rp-head-title">Team &amp; Telecaller Summary</h5>
                        <div class="rp-head-sub">Performance metrics and connect rates grouped by team</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-white text-primary border rounded-pill px-3 py-2 fw-bold shadow-sm">
                        <i class="bi bi-person-badge me-1"></i><?= number_format(count($TBL_USERS_index)) ?> Active Telecallers
                    </span>
                    <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['export' => 'csv'])) ?>" class="btn btn-sm btn-success rounded-pill px-3 fw-semibold shadow-sm">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Download Excel
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle rp-special-table mb-0">
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th>Supervisor</th>
                                <th>Telecaller</th>
                                <th class="text-center">Total Calls</th>
                                <th class="text-center">Connected</th>
                                <th class="text-center">Busy</th>
                                <th class="text-center">No Answer</th>
                                <th class="text-center">DNC</th>
                                <th class="text-center">Pending</th>
                                <th class="text-center">Not Called</th>
                                <th class="text-center">Connect Rate</th>
                                <th class="text-center" data-exclude="true">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $team_name => $block):
                                $team_total = 0;
                                $team_connected = 0;
                                $team_busy = 0;
                                $team_no_ans = 0;
                                $team_dnc = 0;
                                $team_pending = 0;
                                $team_not_called = 0;
                                foreach ($block['TBL_USERS'] as $u) {
                                    $team_total     += (int)$u['total'];
                                    $team_connected += (int)$u['connected'];
                                    $team_busy      += (int)$u['busy'];
                                    $team_no_ans    += (int)$u['no_answer'];
                                    $team_dnc       += (int)$u['dnc'];
                                    $team_pending   += (int)$u['pending'];
                                    $team_not_called+= (int)$u['not_called'];
                                }
                                $team_rate = $team_total ? round($team_connected / $team_total * 100, 1) : 0;
                                $sup = $block['supervisor_name'] ?? '—';
                            ?>
                                <?php foreach ($block['TBL_USERS'] as $u):
                                    $rate = $u['rate'];
                                    $rateGood = $rate >= 40;
                                    $isSelected = $selected_user_id && $selected_user_id == $u['user_id'];
                                    $initials = strtoupper(substr($u['user_name'] ?? '?', 0, 2));
                                ?>
                                    <tr class="<?= $isSelected ? 'table-selected' : '' ?>">
                                        <td class="fw-semibold text-secondary"><?= htmlspecialchars($team_name) ?></td>
                                        <td class="text-muted"><?= htmlspecialchars($sup ?: '—') ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="rp-avatar-sm rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" style="width:34px;height:34px;background:var(--accent);font-size:.78rem;flex-shrink:0;">
                                                    <?= $initials ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark rp-emp-name"><?= htmlspecialchars($u['user_name'] ?? 'Unknown') ?></div>
                                                    <?php if ($isSelected): ?>
                                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-0" style="font-size:0.68rem;" data-exclude="true">Active Filter</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center"><span class="fw-bold"><?= number_format($u['total']) ?></span></td>
                                        <td class="text-center"><span class="rp-num-badge rp-num-connected"><?= number_format($u['connected']) ?></span></td>
                                        <td class="text-center"><span class="rp-num-badge rp-num-busy"><?= number_format($u['busy']) ?></span></td>
                                        <td class="text-center"><span class="rp-num-badge rp-num-noans"><?= number_format($u['no_answer']) ?></span></td>
                                        <td class="text-center"><span class="rp-num-badge rp-num-dnc"><?= number_format($u['dnc']) ?></span></td>
                                        <td class="text-center"><span class="rp-num-badge rp-num-pending"><?= number_format($u['pending']) ?></span></td>
                                        <td class="text-center"><span class="rp-num-badge rp-num-notcalled"><?= number_format($u['not_called']) ?></span></td>
                                        <td class="text-center">
                                            <span class="rp-rate-badge <?= $rateGood ? 'rp-rate-good' : 'rp-rate-poor' ?>">
                                                <i class="bi <?= $rateGood ? 'bi-arrow-up-right' : 'bi-arrow-down-right' ?>"></i> <?= $rate ?>%
                                            </span>
                                        </td>
                                        <td class="text-center" data-exclude="true">
                                            <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['user' => $u['user_id']])) ?>"
                                               class="btn btn-outline-primary btn-sm rounded-pill px-3 py-1 fw-semibold">
                                                <i class="bi bi-person-lines-fill me-1"></i> Details
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

    <!-- Detailed Calls for selected telecaller -->
    <?php if ($selected_user_id && !empty($detail_rows)): ?>
        <div class="card rp-special-card mt-4">
            <div class="rp-special-card-head detail-head">
                <div class="d-flex align-items-center gap-3">
                    <div class="rp-head-icon" style="background: linear-gradient(135deg, #0ea5e9, var(--accent));">
                        <i class="bi bi-person-lines-fill"></i>
                    </div>
                    <div>
                        <h5 class="rp-head-title">
                            Detailed Calls &bull; <?= htmlspecialchars($selected_user_name ?? ('User #' . $selected_user_id)) ?>
                        </h5>
                        <div class="rp-head-sub">Log of all dialed calls for this telecaller with applied date filters</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle rounded-pill px-3 py-2 fw-bold">
                        <?= count($detail_rows) ?> calls recorded
                    </span>
                    <a href="<?= $self ?>?<?= htmlspecialchars(reportQs()) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-semibold">
                        <i class="bi bi-x-circle me-1"></i> Clear Selection
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle rp-special-table mb-0">
                        <thead>
                            <tr>
                                <th style="width: 18%;">Date &amp; Time</th>
                                <th style="width: 24%;">Customer</th>
                                <th style="width: 16%;">Mobile</th>
                                <th style="width: 18%;">Company</th>
                                <th style="width: 14%;">Status</th>
                                <th style="width: 10%;">Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($detail_rows as $r):
                                $timeStr = $r['call_time'] ? date('d M Y, h:i A', strtotime($r['call_time'])) : '—';
                                $stLow = strtolower(trim($r['call_status']));
                                $stBadge = 'bg-secondary-subtle text-secondary';
                                if (in_array($stLow, ['connected', 'lead', 'interested'])) $stBadge = 'bg-success-subtle text-success border border-success-subtle';
                                elseif ($stLow === 'busy') $stBadge = 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
                                elseif (in_array($stLow, ['no answer', 'no_answer'])) $stBadge = 'bg-secondary-subtle text-secondary border border-secondary-subtle';
                                elseif (in_array($stLow, ['do not call', 'dnc', 'dnd', 'dnd number', 'do not disturb', 'not_connected_dnd'])) $stBadge = 'bg-danger-subtle text-danger border border-danger-subtle';
                                elseif (in_array($stLow, ['pending', 'call back', 'callback', 'follow up', 'follow-up'])) $stBadge = 'bg-info-subtle text-info border border-info-subtle';
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars(date('d M Y', strtotime($r['call_time']))) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars(date('h:i A', strtotime($r['call_time']))) ?></small>
                                    </td>
                                    <td><span class="fw-bold text-dark"><?= htmlspecialchars($r['cust_name'] ?: '—') ?></span></td>
                                    <td>
                                        <?php if ($r['cust_mobile']): ?>
                                            <a href="tel:<?= urlencode($r['cust_mobile']) ?>" class="text-decoration-none text-muted fw-semibold">
                                                <i class="bi bi-telephone me-1 text-primary"></i><?= htmlspecialchars($r['cust_mobile']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($r['cust_company'] ?: '—') ?></td>
                                    <td>
                                        <span class="badge rounded-pill <?= $stBadge ?> px-2 py-1">
                                            <?= htmlspecialchars($r['call_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
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
    <?php elseif ($selected_user_id && empty($detail_rows)): ?>
        <div class="rp-card rp-empty mt-4">
            <i class="bi bi-inbox"></i>
            <h6 class="mt-2 mb-0">No calls found for this telecaller with selected filters.</h6>
        </div>
    <?php endif; ?>
    </div><!-- /exportArea -->

    <?php if ($grand_total > 0): ?>
    <div class="rp-export my-4 d-flex justify-content-center gap-3 flex-wrap">
        <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['export' => 'csv'])) ?>" class="btn btn-success btn-lg px-4 shadow-sm fw-semibold rounded-pill">
            <i class="bi bi-file-earmark-spreadsheet me-2"></i> Download Clean Excel / CSV
        </a>
        <button id="btnExport" class="btn btn-outline-success btn-lg px-4 shadow-sm fw-semibold rounded-pill">
            <i class="bi bi-file-earmark-excel me-2"></i> Export Table View
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

// Export tables inside exportArea with DOM sanitation
document.getElementById('btnExport')?.addEventListener('click', () => {
    // Clone exportArea and clean up avatars, badges, and action columns
    const container = document.getElementById('exportArea');
    if (!container) return;

    const clone = container.cloneNode(true);
    // Remove avatar elements and badges
    clone.querySelectorAll('.rp-avatar-sm, [data-exclude="true"]').forEach(el => el.remove());

    // Replace composite telecaller cells with just clean name
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
