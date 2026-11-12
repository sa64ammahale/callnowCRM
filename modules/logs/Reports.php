<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../php_scripts/team_auth.php';
require_once __DIR__ . '/../../php_scripts/reporting_engine.php';

requirePermission('view_reports');

// Determine roles and scope
$userRole     = USER_ROLE;
$isSuperAdmin = isUserSuperAdmin((int)USER_ID, $userRole);
$isAdmin      = ($userRole === 'Admin' || $userRole === 'Manager') && !$isSuperAdmin;
$isSupervisor = ($userRole === 'Supervisor') && !$isSuperAdmin && !$isAdmin;
$isOfficer    = ($userRole === 'Officer') && !$isAdmin && !$isSuperAdmin && !$isSupervisor;

// Prevent parameter tampering: check if requested user is authorized
$requested_user = isset($_GET['user']) && is_numeric($_GET['user']) ? (int)$_GET['user'] : null;
if ($isOfficer) {
    $selected_user_id = (int)USER_ID;
} elseif ($requested_user) {
    $selected_user_id = canUserAccessTargetUser($link, (int)USER_ID, $userRole, $requested_user) ? $requested_user : null;
} else {
    $selected_user_id = null;
}

// Super Admin Target Configuration Save Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_targets'])) {
    if (!$isSuperAdmin) {
        $_SESSION['error_message'] = "Unauthorized: Only Super Admin can configure targets.";
    } else {
        verifyCsrfToken($_POST['csrf_token'] ?? '');
        saveReportingTargets($link, $_POST, 'Officer');
        $_SESSION['success_message'] = "Reporting targets and performance thresholds updated successfully.";
    }
    header("Location: " . $_SERVER['PHP_SELF'] . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// Date range (default: Today) + presets
$range            = $_GET['range'] ?? 'today';
$from             = $_GET['from'] ?? '';
$to               = $_GET['to']   ?? '';
$team_filter      = $_GET['team'] ?? '';
$supervisor_filter= $_GET['supervisor'] ?? '';
$loan_type_filter = $_GET['loan_type'] ?? '';
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
        $from = date('Y-m-d');
        $to   = date('Y-m-d');
    }
} else {
    computeRange($range, $from, $to);
}

$rangeLabel    = $rangeLabels[$range] ?? 'Today';
$spanDays      = (new DateTime($to ?: 'today'))->diff(new DateTime($from ?: 'today'))->days + 1;
$bucket        = $spanDays > 31 ? 'month' : 'day';

// Teams & Supervisors dropdown list based on role
$TBL_TEAMS = [];
$allSupervisors = [];
if ($isSuperAdmin) {
    $tRes = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " ORDER BY NAME");
    if ($tRes) $TBL_TEAMS = mysqli_fetch_all($tRes, MYSQLI_ASSOC);

    $sRes = mysqli_query($link, "SELECT ID, NAME, ROLE FROM " . tn('TBL_USERS') . " WHERE ROLE IN ('Supervisor', 'Manager', 'Admin') AND STATUS = 'Active' AND ROLE != 'Super Admin' AND ID != 1 ORDER BY NAME");
    if ($sRes) $allSupervisors = mysqli_fetch_all($sRes, MYSQLI_ASSOC);
} elseif ($isAdmin) {
    $adminTeamIds = getAdminManagedTeamIds($link, (int)USER_ID);
    if (!empty($adminTeamIds)) {
        $tIn = implode(',', array_map('intval', $adminTeamIds));
        $tRes = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " WHERE ID IN ($tIn) ORDER BY NAME");
        if ($tRes) $TBL_TEAMS = mysqli_fetch_all($tRes, MYSQLI_ASSOC);
    }
    $adminSupIds = getAdminAssignedSupervisorIds($link, (int)USER_ID);
    if (!empty($adminSupIds)) {
        $sIn = implode(',', array_map('intval', $adminSupIds));
        $sRes = mysqli_query($link, "SELECT ID, NAME, ROLE FROM " . tn('TBL_USERS') . " WHERE ID IN ($sIn) AND STATUS = 'Active' ORDER BY NAME");
        if ($sRes) $allSupervisors = mysqli_fetch_all($sRes, MYSQLI_ASSOC);
    }
} elseif ($isSupervisor) {
    $supId = (int)USER_ID;
    $tRes = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " WHERE SUPERVISOR_ID = $supId ORDER BY NAME");
    if ($tRes) $TBL_TEAMS = mysqli_fetch_all($tRes, MYSQLI_ASSOC);
}

// Validate requested team & supervisor filters against user authorization
$teamIdParam = null;
if ($team_filter !== '' && is_numeric($team_filter)) {
    $candT = (int)$team_filter;
    $authTeams = getAuthorizedTeamIds($link, (int)USER_ID, $userRole, defined('USER_TEAM_ID') ? (int)USER_TEAM_ID : null);
    if ($isSuperAdmin || in_array($candT, $authTeams, true)) {
        $teamIdParam = $candT;
    }
}

$supIdParam = null;
if ($supervisor_filter !== '' && is_numeric($supervisor_filter)) {
    $candS = (int)$supervisor_filter;
    if ($isSuperAdmin) {
        $supIdParam = $candS;
    } elseif ($isAdmin) {
        $adminSups = getAdminAssignedSupervisorIds($link, (int)USER_ID);
        if (in_array($candS, $adminSups, true)) $supIdParam = $candS;
    }
}

// Fetch configured targets & performance thresholds
$targets = getReportingTargets($link, 'Officer');

// Fetch Metrics via Reporting Engine
$contactMetrics   = getClientContactMetrics($link, $selected_user_id, $teamIdParam, $supIdParam, $userRole);
$callMetrics      = getCallingMetrics($link, $from, $to, $selected_user_id, $teamIdParam, $supIdParam, $userRole);
$leadMetrics      = getLeadConversionMetrics($link, $from, $to, $selected_user_id, $teamIdParam, $supIdParam, $userRole);
$hourlyAnalytics  = getHourlyCallingAnalytics($link, $from, $to, $selected_user_id, $teamIdParam, $supIdParam, $userRole);
$empRows          = getEmployeePerformanceRows($link, $from, $to, $teamIdParam, $supIdParam, $selected_user_id, $userRole, $targets);
$supRows          = ($isSuperAdmin || $isAdmin) ? getSupervisorPerformanceRows($link, $from, $to, $teamIdParam, $targets, (int)USER_ID, $userRole) : [];
$adminRows        = ($isSuperAdmin || $isAdmin) ? getAdminPerformanceRows($link, $from, $to, $isAdmin ? (int)USER_ID : null, $targets, $userRole) : [];
$followupList     = getFollowupList($link, $selected_user_id, $teamIdParam, $userRole, 'all', 100);
$alerts           = getPerformanceAlerts($link, $empRows, $targets, $selected_user_id, $userRole);
$rankings         = calculateProductivityRankings($empRows, $targets);

// Client Contacts Table Data (Filtered)
$contactTableRows = [];
$cWhere = [];
$cParams = [];
$cTypes = '';

if ($userRole === 'Officer') {
    $cWhere[] = "m.MAINDATABASE_CALL_DIALED_USER = ?";
    $cParams[] = (int)USER_ID;
    $cTypes .= 'i';
} elseif ($selected_user_id) {
    $cWhere[] = "m.MAINDATABASE_CALL_DIALED_USER = ?";
    $cParams[] = $selected_user_id;
    $cTypes .= 'i';
} elseif ($teamIdParam) {
    $cWhere[] = "m.MAINDATABASE_CALL_DIALED_USER IN (SELECT ID FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = ?)";
    $cParams[] = $teamIdParam;
    $cTypes .= 'i';
} elseif ($isAdmin) {
    $cWhere[] = getHierarchyContactFilterSQL($link, (int)USER_ID, 'Admin', 'm');
} elseif ($isSupervisor) {
    $cWhere[] = getHierarchyContactFilterSQL($link, (int)USER_ID, 'Supervisor', 'm', defined('USER_TEAM_ID') ? (int)USER_TEAM_ID : null);
}

$cWhereSql = !empty($cWhere) ? "WHERE " . implode(' AND ', $cWhere) : "";
$cSql = "
    SELECT 
        m.ID, m.MAINDATABASE_NAME as name, m.MAINDATABASE_MOBILE as mobile, m.MAINDATABASE_COMPANY as company,
        m.MAINDATABASE_CALL_DIALED_STATUS as status, m.CALL_COUNT as call_count, m.LAST_DIALED_DATE_TIME as last_called_at,
        m.MAINDATABASE_UPLOAD_DATETIME as uploaded_at,
        u.NAME as officer_name,
        l.lead_id, l.lead_status_new as pipeline_stage, l.converted_at
    FROM " . tn('TBL_MAIN') . " m
    LEFT JOIN " . tn('TBL_USERS') . " u ON m.MAINDATABASE_CALL_DIALED_USER = u.ID
    LEFT JOIN " . tn('TBL_LEADS') . " l ON m.ID = l.cust_id
    $cWhereSql
    ORDER BY m.LAST_DIALED_DATE_TIME DESC, m.ID DESC
    LIMIT 100
";
$cStmt = $link->prepare($cSql);
if (!empty($cParams)) $cStmt->bind_param($cTypes, ...$cParams);
$cStmt->execute();
$contactTableRows = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$cStmt->close();

// Converted Leads Table Data (Filtered)
$leadTableRows = [];
$lWhere = ["l.created_at >= ? AND l.created_at < ? + INTERVAL 1 DAY"];
$lParams = [$from, $to];
$lTypes = 'ss';

if ($userRole === 'Officer') {
    $lWhere[] = "l.assigned_to = ?";
    $lParams[] = (int)USER_ID;
    $lTypes .= 'i';
} elseif ($selected_user_id) {
    $lWhere[] = "l.assigned_to = ?";
    $lParams[] = $selected_user_id;
    $lTypes .= 'i';
} elseif ($teamIdParam) {
    $lWhere[] = "l.assigned_to IN (SELECT ID FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = ?)";
    $lParams[] = $teamIdParam;
    $lTypes .= 'i';
} elseif ($isAdmin) {
    $lWhere[] = getHierarchyLeadFilterSQL($link, (int)USER_ID, 'Admin', 'l');
} elseif ($isSupervisor) {
    $lWhere[] = getHierarchyLeadFilterSQL($link, (int)USER_ID, 'Supervisor', 'l', defined('USER_TEAM_ID') ? (int)USER_TEAM_ID : null);
}

if ($loan_type_filter !== '') {
    $lWhere[] = "l.loan_type = ?";
    $lParams[] = $loan_type_filter;
    $lTypes .= 's';
}

$lWhereSql = "WHERE " . implode(' AND ', $lWhere);
$lSql = "
    SELECT 
        l.lead_id, l.cust_id, l.NAME as name, l.MOBILE as mobile, l.COMPANY_NAME as company,
        l.loan_type, l.loan_amount, l.lead_status_new as pipeline_stage, l.bank_name, l.login_bank_name,
        l.created_at, l.converted_at,
        u.NAME as officer_name,
        t.NAME as team_name,
        s.NAME as supervisor_name
    FROM " . tn('TBL_LEADS') . " l
    LEFT JOIN " . tn('TBL_USERS') . " u ON l.assigned_to = u.ID
    LEFT JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID
    LEFT JOIN " . tn('TBL_USERS') . " s ON t.SUPERVISOR_ID = s.ID
    $lWhereSql
    ORDER BY l.created_at DESC
    LIMIT 100
";
$lStmt = $link->prepare($lSql);
$lStmt->bind_param($lTypes, ...$lParams);
$lStmt->execute();
$leadTableRows = $lStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lStmt->close();

// Call logs drilldown
$detailRows = [];
$dWhere = ["COALESCE(cl.call_time, cl.created_at) >= ? AND COALESCE(cl.call_time, cl.created_at) < ? + INTERVAL 1 DAY"];
$dParams = [$from, $to];
$dTypes = 'ss';

if ($userRole === 'Officer') {
    $dWhere[] = "cl.user_id = ?";
    $dParams[] = (int)USER_ID;
    $dTypes .= 'i';
} elseif ($selected_user_id) {
    $dWhere[] = "cl.user_id = ?";
    $dParams[] = $selected_user_id;
    $dTypes .= 'i';
} elseif ($teamIdParam) {
    $dWhere[] = "cl.user_id IN (SELECT ID FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = ?)";
    $dParams[] = $teamIdParam;
    $dTypes .= 'i';
} elseif ($isAdmin) {
    $dWhere[] = getHierarchyUserFilterSQL($link, (int)USER_ID, 'Admin', 'cl', 'user_id');
} elseif ($isSupervisor) {
    $dWhere[] = getHierarchyUserFilterSQL($link, (int)USER_ID, 'Supervisor', 'cl', 'user_id', defined('USER_TEAM_ID') ? (int)USER_TEAM_ID : null);
}

$dWhereSql = "WHERE " . implode(' AND ', $dWhere);
$dSql = "
    SELECT 
        cl.id, cl.call_time, cl.created_at, cl.call_status, cl.call_duration, cl.source, cl.recording_url, cl.notes,
        COALESCE(m.MAINDATABASE_NAME, t.CUST_NAME, l.NAME, '') as cust_name,
        COALESCE(m.MAINDATABASE_MOBILE, t.CUST_MOBILE, l.MOBILE, '') as cust_mobile,
        COALESCE(m.MAINDATABASE_COMPANY, t.CUST_COMPANY, l.COMPANY_NAME, '') as cust_company,
        cl.client_contact_id, cl.lead_id,
        u.NAME as officer_name
    FROM " . tn('TBL_CALL_LOGS') . " cl
    LEFT JOIN " . tn('TBL_MAIN') . " m ON (cl.client_contact_id = m.ID AND cl.source = 'MAIN')
    LEFT JOIN " . tn('TBL_TEMP') . " t ON (cl.client_contact_id = t.ID AND cl.source = 'TEMP')
    LEFT JOIN " . tn('TBL_LEADS') . " l ON cl.lead_id = l.lead_id
    LEFT JOIN " . tn('TBL_USERS') . " u ON cl.user_id = u.ID
    $dWhereSql
    ORDER BY COALESCE(cl.call_time, cl.created_at) DESC
    LIMIT 300
";
$dStmt = $link->prepare($dSql);
$dStmt->bind_param($dTypes, ...$dParams);
$dStmt->execute();
$detailRows = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dStmt->close();

// Selected user name for header/badge
$selected_user_name = '';
if ($selected_user_id) {
    $uRes = mysqli_query($link, "SELECT NAME FROM " . tn('TBL_USERS') . " WHERE ID = " . (int)$selected_user_id);
    if ($uRes && ($uRow = mysqli_fetch_assoc($uRes))) {
        $selected_user_name = $uRow['NAME'];
    }
}

// Helper for querystrings
if (!function_exists('reportQs')) {
function reportQs(array $extra = []): string {
    global $range, $from, $to, $team_filter, $supervisor_filter, $loan_type_filter, $selected_user_id, $active_tab;
    $qs = [
        'range'      => $range,
        'from'       => $from,
        'to'         => $to,
        'team'       => $team_filter,
        'supervisor' => $supervisor_filter,
        'loan_type'  => $loan_type_filter,
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

// Comprehensive Multi-Section CSV Export Handler
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="CallNow_Performance_Report_' . date('Y-m-d_His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, ['CALLNOW CRM — CALL REPORTING & LOAN TELECALLING PERFORMANCE MANAGEMENT SYSTEM']);
    fputcsv($out, ['Report Preset', $rangeLabel, 'Date Range', "$from to $to"]);
    fputcsv($out, ['Generated At', date('Y-m-d H:i:s'), 'Generated By', USER_NAME . ' (' . USER_ROLE . ')']);
    fputcsv($out, []);

    // 1. Executive Summary KPI Block
    fputcsv($out, ['=== 1. DAILY OVERVIEW & KPI EXECUTIVE SUMMARY ===']);
    fputcsv($out, ['CLIENT CONTACT METRICS', 'COUNT', 'CALLING PERFORMANCE METRICS', 'COUNT', 'LEAD & LOAN PIPELINE METRICS', 'COUNT']);
    fputcsv($out, [
        'Total Assigned Contacts', $contactMetrics['total_assigned'],
        'Total Call Attempts', $callMetrics['total_attempts'],
        'New Converted Leads', $leadMetrics['new_leads_created']
    ]);
    fputcsv($out, [
        'Contacts Attempted', $contactMetrics['attempted'],
        'Connected Calls', $callMetrics['connected_calls'],
        'Qualified Leads', $leadMetrics['qualified_leads']
    ]);
    fputcsv($out, [
        'Unique Contacts Called', $callMetrics['unique_contacts_called'],
        'Not Connected Calls', $callMetrics['not_connected_calls'],
        'Documents Collected', $leadMetrics['documents_collected']
    ]);
    fputcsv($out, [
        'Contacts Not Yet Called', $contactMetrics['not_yet_called'],
        'Call Connection Rate', $callMetrics['connect_rate'] . '%',
        'Applications Logged In', $leadMetrics['applications_logged_in']
    ]);
    fputcsv($out, [
        'Interested Contacts', $contactMetrics['interested'],
        'Total Talk Time', $callMetrics['total_talk_time_formatted'],
        'Sanctioned Loans', $leadMetrics['sanctioned_loans']
    ]);
    fputcsv($out, [
        'Not Interested Contacts', $contactMetrics['not_interested'],
        'Average Call Duration', $callMetrics['avg_duration_formatted'],
        'Disbursed Loans', $leadMetrics['disbursed_loans']
    ]);
    fputcsv($out, [
        'Callback / Busy Contacts', $contactMetrics['callback_busy'],
        'Follow-ups Created', $callMetrics['followups_created'],
        'Total Disbursed Amount', 'INR ' . number_format($leadMetrics['disbursed_amount'], 2)
    ]);
    fputcsv($out, [
        'DND Contacts', $contactMetrics['dnd'],
        'Follow-ups Completed', $callMetrics['followups_completed'],
        '', ''
    ]);
    fputcsv($out, []);

    // 2. Employee-wise Performance Matrix
    fputcsv($out, ['=== 2. EMPLOYEE-WISE TELECALLING & TARGET PERFORMANCE MATRIX ===']);
    fputcsv($out, ['Employee Name', 'Role', 'Team', 'Supervisor', 'Assigned Contacts', 'Total Dials', 'Unique Called', 'Connected', 'Connect Rate', 'Interested', 'Converted Leads', 'Talk Time', 'Daily Target', 'Target Achieved %']);
    foreach ($empRows as $emp) {
        fputcsv($out, [
            $emp['employee_name'],
            $emp['user_role'],
            $emp['team_name'] ?: 'No Team',
            $emp['supervisor_name'] ?: 'None',
            $emp['assigned_contacts'],
            $emp['total_dials'],
            $emp['unique_called'],
            $emp['connected'],
            $emp['connect_rate'] . '%',
            $emp['interested'],
            $emp['converted_leads'],
            $emp['talk_time_formatted'],
            $emp['target'],
            $emp['achievement_pct'] . '%'
        ]);
    }
    fputcsv($out, []);

    // 2b. Admin Rollup (for Super Admin & Admin)
    if (($isSuperAdmin || $isAdmin) && !empty($adminRows)) {
        fputcsv($out, ['=== 2b. ADMIN ORGANIZATIONAL ROLLUP ===']);
        fputcsv($out, ['Admin Name', 'Supervisors Count', 'Officers Count', 'Total Dials', 'Connected', 'Connect Rate', 'Interested', 'Converted Leads']);
        foreach ($adminRows as $adm) {
            fputcsv($out, [
                $adm['admin_name'],
                $adm['total_supervisors'],
                $adm['total_officers'],
                $adm['total_dials'],
                $adm['connected'],
                $adm['connect_rate'] . '%',
                $adm['interested'],
                $adm['converted_leads']
            ]);
        }
        fputcsv($out, []);
    }

    // 2c. Supervisor Rollup
    if (($isSuperAdmin || $isAdmin || $isSupervisor) && !empty($supRows)) {
        fputcsv($out, ['=== 2c. SUPERVISOR & SQUAD PERFORMANCE ROLLUP ===']);
        fputcsv($out, ['Supervisor Name', 'Squad Team', 'Officers Count', 'Total Dials', 'Connected', 'Connect Rate', 'Interested', 'Converted Leads', 'Squad Target', 'Squad Achievement %']);
        foreach ($supRows as $sup) {
            fputcsv($out, [
                $sup['supervisor_name'],
                $sup['team_name'],
                $sup['total_officers'],
                $sup['total_dials'],
                $sup['connected'],
                $sup['connect_rate'] . '%',
                $sup['interested'],
                $sup['converted_leads'],
                $sup['team_target'],
                $sup['achievement_pct'] . '%'
            ]);
        }
        fputcsv($out, []);
    }

    // 3. Hourly Performance Analytics
    fputcsv($out, ['=== 3. HOURLY CALLING ANALYTICS (09:30 AM TO 06:30 PM) ===']);
    fputcsv($out, ['Hour Window', 'Total Calls', 'Connected Calls', 'Interested Contacts', 'Unique Contacts Called', 'Talk Time', 'Connect Rate %']);
    foreach ($hourlyAnalytics as $ha) {
        fputcsv($out, [
            $ha['window'],
            $ha['total_calls'],
            $ha['connected_calls'],
            $ha['interested_calls'],
            $ha['unique_contacts'],
            $ha['talk_time_formatted'],
            $ha['connect_rate'] . '%'
        ]);
    }
    fputcsv($out, []);

    // 4. Converted Leads List
    if (!empty($leadTableRows)) {
        fputcsv($out, ['=== 4. CONVERTED LEADS & LOAN SALES OPPORTUNITIES ===']);
        fputcsv($out, ['Lead ID', 'Original Contact ID', 'Customer Name', 'Mobile Number', 'Company', 'Assigned Employee', 'Team', 'Loan Type', 'Loan Amount', 'Pipeline Stage', 'Lender Bank', 'Conversion Date']);
        foreach ($leadTableRows as $lr) {
            fputcsv($out, [
                $lr['lead_id'],
                $lr['cust_id'] ?: 'None',
                $lr['name'],
                $lr['mobile'],
                $lr['company'] ?: '',
                $lr['officer_name'] ?: 'Unassigned',
                $lr['team_name'] ?: 'No Team',
                $lr['loan_type'] ?: 'Personal Loan',
                $lr['loan_amount'] ?: '',
                $lr['pipeline_stage'],
                $lr['login_bank_name'] ?: ($lr['bank_name'] ?: ''),
                $lr['converted_at'] ?: $lr['created_at']
            ]);
        }
        fputcsv($out, []);
    }

    // 5. Detailed Call Logs
    if (!empty($detailRows)) {
        fputcsv($out, ['=== 5. CALL LOGS AUDIT TRAIL ===']);
        fputcsv($out, ['Call ID', 'Date & Time', 'Officer', 'Customer Name', 'Mobile Number', 'Duration', 'Disposition', 'Source', 'Notes']);
        foreach ($detailRows as $dr) {
            fputcsv($out, [
                $dr['id'],
                $dr['call_time'] ? date('d-m-Y H:i:s', strtotime($dr['call_time'])) : '',
                $dr['officer_name'] ?: 'Unknown',
                $dr['cust_name'] ?: '—',
                $dr['cust_mobile'] ?: '',
                (int)$dr['call_duration'] . 's',
                $dr['call_status'],
                $dr['source'],
                $dr['notes'] ?: ''
            ]);
        }
    }

    fclose($out);
    exit;
}

$pageTitle = 'Call Reports & Telecalling Performance • CallNow CRM';
include __DIR__ . '/../../php_scripts/header.php';
?>

<div class="container-fluid px-3 py-3">
    <!-- Module Header -->
    <div class="module-header mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 fs-xs">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/logs/Reports.php') ?>">Activities & Reports</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Call Reporting & Performance</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center flex-wrap gap-2">
                <h1 class="h4 fw-bold text-dark mb-0">Call Reporting & Loan Performance System</h1>
                <?php if ($isSuperAdmin): ?>
                    <span class="badge badge-purple px-2 py-1"><i class="bi bi-shield-check me-1"></i> Super Admin Scope (All Organization)</span>
                <?php elseif ($isAdmin): ?>
                    <span class="badge badge-primary px-2 py-1"><i class="bi bi-building me-1"></i> Admin Scope (All Teams)</span>
                <?php elseif ($isSupervisor): ?>
                    <span class="badge badge-info px-2 py-1"><i class="bi bi-people-fill me-1"></i> Supervisor Scope (My Teams)</span>
                <?php else: ?>
                    <span class="badge badge-secondary px-2 py-1"><i class="bi bi-person-circle me-1"></i> Sales Officer Scope (Personal Performance)</span>
                <?php endif; ?>
            </div>
            <div class="text-muted fs-xs mt-1">Period: <strong><?= htmlspecialchars($rangeLabel) ?></strong> &bull; <?= htmlspecialchars($from) ?> to <?= htmlspecialchars($to) ?></div>
        </div>
        <div class="module-header-actions d-flex align-items-center gap-2">
            <?php if ($isSuperAdmin): ?>
                <button type="button" class="btn btn-outline-purple btn-sm" data-bs-toggle="modal" data-bs-target="#targetsConfigModal">
                    <i class="bi bi-sliders me-1"></i> Configure Targets
                </button>
            <?php endif; ?>
            <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['export' => 'csv'])) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Comprehensive Report
            </a>
            <a href="<?= htmlspecialchars(url('modules/logs/call_history.php')) ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-clock-history me-1"></i> Live Call Logs
            </a>
        </div>
    </div>

    <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show fs-xs py-2 mb-3" role="alert">
            <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <!-- Advanced Filter Toolbar -->
    <div class="crm-card mb-3">
        <div class="crm-card-body py-2">
            <form method="GET" class="row g-2 align-items-end" id="reportFilterForm">
                <input type="hidden" name="tab" id="activeTabInput" value="<?= htmlspecialchars($active_tab) ?>">

                <div class="col-md-2 col-sm-6">
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
                            <option value="">All Teams</option>
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
                        <label class="form-label fs-xs mb-1">Supervisor</label>
                        <select name="supervisor" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Supervisors</option>
                            <?php foreach($allSupervisors as $s): ?>
                                <option value="<?= $s['ID'] ?>" <?= $supervisor_filter == $s['ID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['NAME']) ?>
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
                        <i class="bi bi-funnel me-1"></i> Apply Filter
                    </button>
                    <?php if ($team_filter !== '' || $supervisor_filter !== '' || ($selected_user_id && !$isOfficer) || $range !== 'today'): ?>
                        <a href="<?= $self ?>" class="btn btn-secondary btn-sm" title="Reset Filters">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Reports Navigation Directory -->
    <div class="report-nav-hub mb-3">
        <div class="d-flex align-items-center justify-content-between mb-2 pb-1 border-bottom">
            <div class="d-flex align-items-center gap-2">
                <span class="fs-xs fw-bold text-uppercase text-secondary tracking-wider"><i class="bi bi-grid-fill text-primary me-1"></i> Reports &amp; Analytics Hub</span>
                <span class="text-muted fs-xs d-none d-sm-inline">&bull; Click any module to inspect detailed reports</span>
            </div>
            <span class="badge bg-light text-secondary border fs-xs"><i class="bi bi-compass me-1"></i> Quick Nav</span>
        </div>
        <ul class="nav report-nav-deck" id="reportTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="report-tab-btn <?= $active_tab === 'overview' ? 'active' : '' ?>" id="overview-tab" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button" role="tab" data-theme="purple">
                    <span class="report-tab-icon"><i class="bi bi-graph-up-arrow"></i></span>
                    <span>Overview &amp; Trends</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="report-tab-btn <?= $active_tab === 'contacts' ? 'active' : '' ?>" id="contacts-tab" data-bs-toggle="tab" data-bs-target="#tab-contacts" type="button" role="tab" data-theme="blue">
                    <span class="report-tab-icon"><i class="bi bi-person-vcard"></i></span>
                    <span>Client Contacts</span>
                    <span class="report-tab-badge bg-primary-subtle text-primary border"><?= count($contactTableRows) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="report-tab-btn <?= $active_tab === 'leads' ? 'active' : '' ?>" id="leads-tab" data-bs-toggle="tab" data-bs-target="#tab-leads" type="button" role="tab" data-theme="green">
                    <span class="report-tab-icon"><i class="bi bi-cash-coin"></i></span>
                    <span>Converted Leads</span>
                    <span class="report-tab-badge bg-success-subtle text-success border"><?= count($leadTableRows) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="report-tab-btn <?= $active_tab === 'employees' ? 'active' : '' ?>" id="employees-tab" data-bs-toggle="tab" data-bs-target="#tab-employees" type="button" role="tab" data-theme="teal">
                    <span class="report-tab-icon"><i class="bi bi-person-badge"></i></span>
                    <span><?= $isOfficer ? 'My Performance' : 'Officers Performance' ?></span>
                    <span class="report-tab-badge bg-info-subtle text-info border"><?= count($empRows) ?></span>
                </button>
            </li>
            <?php if ($isSuperAdmin || $isAdmin || $isSupervisor): ?>
            <li class="nav-item" role="presentation">
                <button class="report-tab-btn <?= $active_tab === 'supervisors' ? 'active' : '' ?>" id="supervisors-tab" data-bs-toggle="tab" data-bs-target="#tab-supervisors" type="button" role="tab" data-theme="amber">
                    <span class="report-tab-icon"><i class="bi bi-people-fill"></i></span>
                    <span>Supervisor Rollup</span>
                    <span class="report-tab-badge bg-warning-subtle text-warning-emphasis border"><?= count($supRows) ?></span>
                </button>
            </li>
            <?php endif; ?>
            <?php if ($isSuperAdmin || $isAdmin): ?>
            <li class="nav-item" role="presentation">
                <button class="report-tab-btn <?= $active_tab === 'admins' ? 'active' : '' ?>" id="admins-tab" data-bs-toggle="tab" data-bs-target="#tab-admins" type="button" role="tab" data-theme="indigo">
                    <span class="report-tab-icon"><i class="bi bi-diagram-3-fill"></i></span>
                    <span>Admin Rollup</span>
                    <span class="report-tab-badge bg-purple-subtle text-purple border"><?= count($adminRows) ?></span>
                </button>
            </li>
            <?php endif; ?>
            <li class="nav-item" role="presentation">
                <button class="report-tab-btn <?= $active_tab === 'hourly' ? 'active' : '' ?>" id="hourly-tab" data-bs-toggle="tab" data-bs-target="#tab-hourly" type="button" role="tab" data-theme="sky">
                    <span class="report-tab-icon"><i class="bi bi-clock-history"></i></span>
                    <span>Hourly Calling</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="report-tab-btn <?= $active_tab === 'followups' ? 'active' : '' ?>" id="followups-tab" data-bs-toggle="tab" data-bs-target="#tab-followups" type="button" role="tab" data-theme="fuchsia">
                    <span class="report-tab-icon"><i class="bi bi-calendar2-check"></i></span>
                    <span>Follow-ups Hub</span>
                    <span class="report-tab-badge bg-purple-subtle text-purple border"><?= count($followupList) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="report-tab-btn <?= $active_tab === 'alerts' ? 'active' : '' ?>" id="alerts-tab" data-bs-toggle="tab" data-bs-target="#tab-alerts" type="button" role="tab" data-theme="rose">
                    <span class="report-tab-icon"><i class="bi bi-bell-fill"></i></span>
                    <span>Performance Alerts</span>
                    <?php if (!empty($alerts)): ?>
                        <span class="report-tab-badge bg-danger-subtle text-danger border"><?= count($alerts) ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <?php if (!$isOfficer): ?>
            <li class="nav-item" role="presentation">
                <button class="report-tab-btn <?= $active_tab === 'rankings' ? 'active' : '' ?>" id="rankings-tab" data-bs-toggle="tab" data-bs-target="#tab-rankings" type="button" role="tab" data-theme="gold">
                    <span class="report-tab-icon"><i class="bi bi-trophy-fill"></i></span>
                    <span>Rankings &amp; Scores</span>
                </button>
            </li>
            <?php endif; ?>
            <li class="nav-item" role="presentation">
                <button class="report-tab-btn <?= $active_tab === 'drilldown' ? 'active' : '' ?>" id="drilldown-tab" data-bs-toggle="tab" data-bs-target="#tab-drilldown" type="button" role="tab" data-theme="slate">
                    <span class="report-tab-icon"><i class="bi bi-journal-text"></i></span>
                    <span>Call History</span>
                    <?php if (!empty($detailRows)): ?>
                        <span class="report-tab-badge bg-secondary-subtle text-secondary border"><?= count($detailRows) ?></span>
                    <?php endif; ?>
                </button>
            </li>
        </ul>
    </div>

    <!-- Tab Contents Container -->
    <div class="tab-content" id="reportTabsContent">

        <!-- TAB 1: EXECUTIVE OVERVIEW & TRENDS -->
        <div class="tab-pane fade <?= $active_tab === 'overview' ? 'show active' : '' ?>" id="tab-overview" role="tabpanel">
            <div class="row g-3 mb-3">
                <div class="col-lg-8">
                    <div class="crm-card h-100">
                        <div class="crm-card-header d-flex align-items-center justify-content-between">
                            <div class="crm-card-title"><i class="bi bi-graph-up text-primary me-2"></i>Hourly Telecalling Velocity (Calls Dialed vs Connected)</div>
                            <span class="badge badge-secondary fs-xs">Window: <?= htmlspecialchars($rangeLabel) ?></span>
                        </div>
                        <div class="crm-card-body">
                            <div style="position:relative; height:280px;"><canvas id="hourlyTrendChart"></canvas></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="crm-card h-100">
                        <div class="crm-card-header">
                            <div class="crm-card-title"><i class="bi bi-pie-chart text-primary me-2"></i>Call Disposition Distribution</div>
                        </div>
                        <div class="crm-card-body">
                            <div style="position:relative; height:280px;"><canvas id="dispositionDoughnutChart"></canvas></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3-Tier Multi-Dimensional Hero KPI Cards -->
            <div class="mt-4 pt-2">
                <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
                    <div>
                        <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-speedometer2 text-primary me-2"></i>Key Performance Indicators &amp; Conversion Overview</h6>
                        <span class="text-muted fs-xs">Operational summary across contact coverage, calling activity, and loan conversions</span>
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary border fs-xs">Period: <?= htmlspecialchars($rangeLabel) ?></span>
                </div>

                <!-- TIER 1: CLIENT CONTACT COVERAGE -->
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fs-xs fw-bold text-uppercase text-secondary tracking-wider"><i class="bi bi-person-vcard text-primary me-1"></i> Client Contact Coverage</span>
                    <span class="text-muted fs-xs">Base: Assigned Customer Records</span>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-neutral p-3">
                            <div class="metric-card-title">Total Assigned Contacts</div>
                            <div class="metric-card-value text-dark text-nowrap"><?= number_format($contactMetrics['total_assigned']) ?></div>
                            <div class="metric-card-subtitle text-muted">In CRM repository</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-primary p-3">
                            <div class="metric-card-title">Contacts Attempted</div>
                            <div class="metric-card-value text-primary text-nowrap"><?= number_format($contactMetrics['attempted']) ?></div>
                            <div class="metric-card-subtitle text-muted"><?= $contactMetrics['total_assigned'] > 0 ? round(($contactMetrics['attempted']/$contactMetrics['total_assigned'])*100, 1) : 0 ?>% coverage</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-secondary p-3">
                            <div class="metric-card-title">Not Yet Called</div>
                            <div class="metric-card-value text-secondary text-nowrap"><?= number_format($contactMetrics['not_yet_called']) ?></div>
                            <div class="metric-card-subtitle text-muted">Fresh contact queue</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-success p-3">
                            <div class="metric-card-title">Interested Contacts</div>
                            <div class="metric-card-value text-success text-nowrap"><?= number_format($contactMetrics['interested']) ?></div>
                            <div class="metric-card-subtitle text-muted">Expressed loan interest</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-warning p-3">
                            <div class="metric-card-title">Callback / Busy</div>
                            <div class="metric-card-value text-warning text-nowrap"><?= number_format($contactMetrics['callback_busy']) ?></div>
                            <div class="metric-card-subtitle text-muted">Follow-up needed</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-danger p-3">
                            <div class="metric-card-title">DND Contacts</div>
                            <div class="metric-card-value text-danger text-nowrap"><?= number_format($contactMetrics['dnd']) ?></div>
                            <div class="metric-card-subtitle text-muted">Blocked from calling</div>
                        </div>
                    </div>
                </div>

                <!-- TIER 2: CALLING ACTIVITY & CONVERSATIONS -->
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fs-xs fw-bold text-uppercase text-secondary tracking-wider"><i class="bi bi-telephone-outbound text-info me-1"></i> Calling Productivity &amp; Conversations</span>
                    <span class="text-muted fs-xs">Window: <?= htmlspecialchars($rangeLabel) ?></span>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-neutral p-3">
                            <div class="metric-card-title">Total Call Attempts</div>
                            <div class="metric-card-value text-dark text-nowrap"><?= number_format($callMetrics['total_attempts']) ?></div>
                            <div class="metric-card-subtitle text-muted">Calls dialed by officers</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-primary p-3">
                            <div class="metric-card-title">Connected Calls</div>
                            <div class="metric-card-value text-primary text-nowrap"><?= number_format($callMetrics['connected_calls']) ?></div>
                            <div class="metric-card-subtitle text-muted">Dur &gt; 0s / Conversation</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card <?= $callMetrics['connect_rate'] >= $targets['min_connect_rate_pct'] ? 'metric-success' : 'metric-danger' ?> p-3">
                            <div class="metric-card-title">Connection Rate</div>
                            <div class="metric-card-value <?= $callMetrics['connect_rate'] >= $targets['min_connect_rate_pct'] ? 'text-success' : 'text-danger' ?> text-nowrap"><?= $callMetrics['connect_rate'] ?>%</div>
                            <div class="metric-card-subtitle text-muted">Target: <?= $targets['min_connect_rate_pct'] ?>%+</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-info p-3">
                            <div class="metric-card-title">Total Talk Time</div>
                            <div class="metric-card-value text-info text-nowrap"><?= $callMetrics['total_talk_time_formatted'] ?></div>
                            <div class="metric-card-subtitle text-muted">Connected conversation</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-secondary p-3">
                            <div class="metric-card-title">Avg Call Duration</div>
                            <div class="metric-card-value text-dark text-nowrap"><?= $callMetrics['avg_duration_formatted'] ?></div>
                            <div class="metric-card-subtitle text-muted">Per connected call</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-purple p-3">
                            <div class="metric-card-title">Follow-ups Handled</div>
                            <div class="metric-card-value text-purple text-nowrap"><?= number_format($callMetrics['followups_completed']) ?> <small class="fs-xs text-muted">/ <?= number_format($callMetrics['followups_created']) ?></small></div>
                            <div class="metric-card-subtitle text-muted"><?= $callMetrics['followups_created'] > 0 ? round(($callMetrics['followups_completed'] / $callMetrics['followups_created']) * 100) : 100 ?>% completion</div>
                        </div>
                    </div>
                </div>

                <!-- TIER 3: LOAN PIPELINE & CONVERSIONS -->
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fs-xs fw-bold text-uppercase text-secondary tracking-wider"><i class="bi bi-cash-stack text-success me-1"></i> Loan Sales Conversions &amp; Pipeline</span>
                    <span class="text-muted fs-xs">Disbursements &amp; Sanctions</span>
                </div>
                <div class="row g-2">
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-success p-3">
                            <div class="metric-card-title">New Converted Leads</div>
                            <div class="metric-card-value text-success text-nowrap"><?= number_format($leadMetrics['new_leads_created']) ?></div>
                            <div class="metric-card-subtitle text-muted">From interested contacts</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-primary p-3">
                            <div class="metric-card-title">Qualified Leads</div>
                            <div class="metric-card-value text-primary text-nowrap"><?= number_format($leadMetrics['qualified_leads']) ?></div>
                            <div class="metric-card-subtitle text-muted">Salary &amp; details verified</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-info p-3">
                            <div class="metric-card-title">Documents Collected</div>
                            <div class="metric-card-value text-info text-nowrap"><?= number_format($leadMetrics['documents_collected']) ?></div>
                            <div class="metric-card-subtitle text-muted">Underwriting review</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-secondary p-3">
                            <div class="metric-card-title">Applications Logged In</div>
                            <div class="metric-card-value text-dark text-nowrap"><?= number_format($leadMetrics['applications_logged_in']) ?></div>
                            <div class="metric-card-subtitle text-muted">Bank portal submissions</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-purple p-3">
                            <div class="metric-card-title">Sanctioned Loans</div>
                            <div class="metric-card-value text-purple text-nowrap"><?= number_format($leadMetrics['sanctioned_loans']) ?></div>
                            <div class="metric-card-subtitle text-muted">Approved by lenders</div>
                        </div>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="metric-card metric-success p-3">
                            <div class="metric-card-title">Disbursed Volume</div>
                            <div class="metric-card-value text-success text-nowrap"><?= $leadMetrics['disbursed_loans'] ?> <small class="fs-xs fw-normal text-muted">(₹<?= number_format($leadMetrics['disbursed_amount']) ?>)</small></div>
                            <div class="metric-card-subtitle text-muted">Final payouts realized</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: CLIENT CONTACT PERFORMANCE -->
        <div class="tab-pane fade <?= $active_tab === 'contacts' ? 'show active' : '' ?>" id="tab-contacts" role="tabpanel">
            <div class="crm-card mb-3">
                <div class="crm-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="crm-card-title mb-0"><i class="bi bi-person-vcard text-primary me-2"></i>Client Contacts Calling Registry</div>
                        <span class="badge badge-secondary fs-xs"><?= number_format($contactMetrics['total_assigned']) ?> Total Assigned</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <input type="text" id="contactFilterInput" class="form-control form-control-sm" placeholder="Search contacts..." style="max-width: 220px;">
                    </div>
                </div>
                <div class="crm-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="contactsReportTable">
                            <thead>
                                <tr>
                                    <th>Contact ID</th>
                                    <th>Customer Name</th>
                                    <th>Mobile Number</th>
                                    <th>Company</th>
                                    <th>Assigned Officer</th>
                                    <th class="text-center">Attempts</th>
                                    <th>Latest Outcome</th>
                                    <th>Lead Status</th>
                                    <th>Last Dialed</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($contactTableRows)): ?>
                                    <?php foreach ($contactTableRows as $c): ?>
                                        <tr>
                                            <td class="font-monospace fs-xs text-muted">#<?= $c['ID'] ?></td>
                                            <td><strong class="text-dark fs-xs"><?= htmlspecialchars($c['name'] ?: 'Unnamed') ?></strong></td>
                                            <td>
                                                <a href="tel:<?= urlencode($c['mobile']) ?>" class="text-decoration-none font-monospace fs-xs">
                                                    <?= htmlspecialchars($c['mobile']) ?>
                                                </a>
                                            </td>
                                            <td class="fs-xs text-muted"><?= htmlspecialchars($c['company'] ?: '—') ?></td>
                                            <td class="fs-xs text-dark"><?= htmlspecialchars($c['officer_name'] ?: 'Unassigned') ?></td>
                                            <td class="text-center fs-xs fw-bold"><?= (int)$c['call_count'] ?></td>
                                            <td>
                                                <?php 
                                                    $st = $c['status'] ?: 'Not Yet Called';
                                                    $stBadge = 'badge-secondary';
                                                    if (in_array(strtolower($st), ['interested', 'enquiry', 'lead'])) $stBadge = 'badge-success';
                                                    elseif (in_array(strtolower($st), ['callback', 'busy', 'follow-up'])) $stBadge = 'badge-info';
                                                    elseif (in_array(strtolower($st), ['not interested'])) $stBadge = 'badge-secondary';
                                                    elseif (in_array(strtolower($st), ['do not call', 'dnd'])) $stBadge = 'badge-danger';
                                                ?>
                                                <span class="badge <?= $stBadge ?> fs-xs"><?= htmlspecialchars($st) ?></span>
                                            </td>
                                            <td>
                                                <?php if ($c['lead_id']): ?>
                                                    <a href="<?= url('modules/leads/lead_view.php?id=' . $c['lead_id']) ?>" class="badge badge-success text-decoration-none fs-xs">
                                                        <i class="bi bi-link me-1"></i> Lead #<?= $c['lead_id'] ?> (<?= htmlspecialchars($c['pipeline_stage']) ?>)
                                                    </a>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary fs-xs">Client Contact</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fs-xs text-muted"><?= $c['last_called_at'] ? date('d M, h:i A', strtotime($c['last_called_at'])) : 'Never' ?></td>
                                            <td class="text-center">
                                                <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['tab' => 'drilldown'])) ?>" class="btn btn-secondary btn-sm py-1 px-2 fs-xs" title="View Call History">
                                                    <i class="bi bi-clock-history"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="10" class="text-center py-4 text-muted fs-xs">No client contacts found in this scope.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: CONVERTED LEADS PERFORMANCE -->
        <div class="tab-pane fade <?= $active_tab === 'leads' ? 'show active' : '' ?>" id="tab-leads" role="tabpanel">
            <div class="crm-card mb-3">
                <div class="crm-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="crm-card-title mb-0"><i class="bi bi-cash-stack text-success me-2"></i>Converted Loan Sales Leads</div>
                        <span class="badge badge-success fs-xs"><?= count($leadTableRows) ?> Opportunities</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <input type="text" id="leadFilterInput" class="form-control form-control-sm" placeholder="Search leads..." style="max-width: 220px;">
                    </div>
                </div>
                <div class="crm-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="leadsReportTable">
                            <thead>
                                <tr>
                                    <th>Lead ID</th>
                                    <th>Orig. Contact ID</th>
                                    <th>Customer Name</th>
                                    <th>Mobile</th>
                                    <th>Assigned Officer</th>
                                    <th>Team / Leader</th>
                                    <th>Loan Product</th>
                                    <th>Requested Amount</th>
                                    <th>Pipeline Stage</th>
                                    <th>Conversion Date</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($leadTableRows)): ?>
                                    <?php foreach ($leadTableRows as $l): ?>
                                        <tr>
                                            <td class="font-monospace fs-xs fw-bold text-primary">#<?= $l['lead_id'] ?></td>
                                            <td class="font-monospace fs-xs text-muted">
                                                <?= $l['cust_id'] ? '#' . $l['cust_id'] : '—' ?>
                                            </td>
                                            <td><strong class="text-dark fs-xs"><?= htmlspecialchars($l['name']) ?></strong></td>
                                            <td class="font-monospace fs-xs"><?= htmlspecialchars($l['mobile']) ?></td>
                                            <td class="fs-xs text-dark"><?= htmlspecialchars($l['officer_name'] ?: 'Unassigned') ?></td>
                                            <td class="fs-xs text-muted"><?= htmlspecialchars($l['team_name'] ?: 'No Team') ?></td>
                                            <td><span class="badge badge-secondary fs-xs"><?= htmlspecialchars($l['loan_type'] ?: 'Personal Loan') ?></span></td>
                                            <td class="fs-xs fw-bold text-dark"><?= htmlspecialchars($l['loan_amount'] ?: '—') ?></td>
                                            <td>
                                                <span class="badge badge-purple fs-xs"><?= htmlspecialchars($l['pipeline_stage']) ?></span>
                                            </td>
                                            <td class="fs-xs text-muted"><?= date('d M Y, h:i A', strtotime($l['converted_at'] ?: $l['created_at'])) ?></td>
                                            <td class="text-center">
                                                <a href="<?= url('modules/leads/lead_view.php?id=' . $l['lead_id']) ?>" class="btn btn-primary btn-sm py-1 px-2 fs-xs">
                                                    <i class="bi bi-arrow-up-right-square me-1"></i> View Lead
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="11" class="text-center py-4 text-muted fs-xs">No converted leads recorded for this date window.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 4: OFFICERS PERFORMANCE MATRIX -->
        <div class="tab-pane fade <?= $active_tab === 'employees' ? 'show active' : '' ?>" id="tab-employees" role="tabpanel">
            <div class="crm-card mb-3">
                <div class="crm-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="crm-card-title mb-0"><i class="bi bi-person-badge text-primary me-2"></i>Sales Officers Performance & Target Achievement</div>
                        <span class="badge badge-secondary fs-xs"><?= count($empRows) ?> Officers</span>
                    </div>
                    <input type="text" id="employeeFilterInput" class="form-control form-control-sm" placeholder="Search officer..." style="max-width: 220px;">
                </div>
                <div class="crm-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="employeeReportTable">
                            <thead>
                                <tr>
                                    <th>Sales Officer</th>
                                    <th>Team / Leader</th>
                                    <th class="text-center">Contacts</th>
                                    <th class="text-center">Total Dials</th>
                                    <th class="text-center">Connected</th>
                                    <th class="text-center">Connect %</th>
                                    <th class="text-center">Interested</th>
                                    <th class="text-center">Leads</th>
                                    <th>Talk Time</th>
                                    <th>Target Quota</th>
                                    <th>Achievement %</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($empRows)): ?>
                                    <?php foreach ($empRows as $emp): 
                                        $achGood = $emp['achievement_pct'] >= 100;
                                        $achWarn = $emp['achievement_pct'] < 50;
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="avatar-circle" style="width:28px; height:28px; font-size:11px;">
                                                        <?= strtoupper(substr($emp['employee_name'], 0, 2)) ?>
                                                    </div>
                                                    <div>
                                                        <strong class="text-dark fs-xs"><?= htmlspecialchars($emp['employee_name']) ?></strong>
                                                        <div class="text-muted fs-xs"><?= htmlspecialchars($emp['user_role']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="fs-xs text-muted"><?= htmlspecialchars($emp['team_name'] ?: 'No Team') ?></td>
                                            <td class="text-center fs-xs fw-semibold"><?= number_format($emp['assigned_contacts']) ?></td>
                                            <td class="text-center fs-xs fw-bold text-dark"><?= number_format($emp['total_dials']) ?></td>
                                            <td class="text-center fs-xs"><span class="badge badge-primary"><?= number_format($emp['connected']) ?></span></td>
                                            <td class="text-center fs-xs">
                                                <span class="badge <?= $emp['connect_rate'] >= $targets['min_connect_rate_pct'] ? 'badge-success' : 'badge-danger' ?>">
                                                    <?= $emp['connect_rate'] ?>%
                                                </span>
                                            </td>
                                            <td class="text-center fs-xs"><span class="badge badge-success"><?= number_format($emp['interested']) ?></span></td>
                                            <td class="text-center fs-xs"><span class="badge badge-purple"><?= number_format($emp['converted_leads']) ?></span></td>
                                            <td class="fs-xs text-muted"><?= $emp['talk_time_formatted'] ?></td>
                                            <td class="fs-xs text-muted"><?= $emp['target'] ?> dials</td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2" style="min-width: 110px;">
                                                    <div class="progress flex-grow-1" style="height: 6px;">
                                                        <div class="progress-bar <?= $achGood ? 'bg-success' : ($achWarn ? 'bg-danger' : 'bg-primary') ?>" style="width: <?= min(100, $emp['achievement_pct']) ?>%"></div>
                                                    </div>
                                                    <span class="fs-xs fw-bold <?= $achGood ? 'text-success' : ($achWarn ? 'text-danger' : 'text-primary') ?>"><?= $emp['achievement_pct'] ?>%</span>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['user' => $emp['user_id'], 'tab' => 'drilldown'])) ?>" class="btn btn-secondary btn-sm py-1 px-2 fs-xs">
                                                    <i class="bi bi-clock-history me-1 text-primary"></i> Logs
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="12" class="text-center py-4 text-muted fs-xs">No officer activity recorded in this scope.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isSuperAdmin || $isAdmin || $isSupervisor): ?>
        <!-- TAB 5: SUPERVISOR & TEAM PERFORMANCE ROLLUP -->
        <div class="tab-pane fade <?= $active_tab === 'supervisors' ? 'show active' : '' ?>" id="tab-supervisors" role="tabpanel">
            <div class="crm-card mb-3">
                <div class="crm-card-header d-flex align-items-center justify-content-between">
                    <div class="crm-card-title mb-0"><i class="bi bi-people text-warning me-2"></i>Supervisor & Squad Aggregates</div>
                    <span class="badge badge-warning fs-xs"><?= count($supRows) ?> Squads</span>
                </div>
                <div class="crm-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Supervisor / Team Leader</th>
                                    <th>Assigned Squad</th>
                                    <th class="text-center">Officers</th>
                                    <th class="text-center">Total Dials</th>
                                    <th class="text-center">Connected</th>
                                    <th class="text-center">Connect %</th>
                                    <th class="text-center">Interested</th>
                                    <th class="text-center">Converted Leads</th>
                                    <th>Squad Target</th>
                                    <th>Squad Achievement</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($supRows)): ?>
                                    <?php foreach ($supRows as $sup): ?>
                                        <tr>
                                            <td><strong class="text-dark fs-xs"><?= htmlspecialchars($sup['supervisor_name']) ?></strong></td>
                                            <td class="fs-xs text-muted"><?= htmlspecialchars($sup['team_name']) ?></td>
                                            <td class="text-center fs-xs fw-semibold"><?= (int)$sup['total_officers'] ?></td>
                                            <td class="text-center fs-xs fw-bold text-dark"><?= number_format($sup['total_dials']) ?></td>
                                            <td class="text-center fs-xs"><span class="badge badge-primary"><?= number_format($sup['connected']) ?></span></td>
                                            <td class="text-center fs-xs"><span class="badge badge-success"><?= $sup['connect_rate'] ?>%</span></td>
                                            <td class="text-center fs-xs"><span class="badge badge-info"><?= number_format($sup['interested']) ?></span></td>
                                            <td class="text-center fs-xs"><span class="badge badge-purple"><?= number_format($sup['converted_leads']) ?></span></td>
                                            <td class="fs-xs text-muted"><?= number_format($sup['team_target']) ?> dials</td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2" style="min-width: 100px;">
                                                    <div class="progress flex-grow-1" style="height: 6px;">
                                                        <div class="progress-bar bg-success" style="width: <?= min(100, $sup['achievement_pct']) ?>%"></div>
                                                    </div>
                                                    <span class="fs-xs fw-bold text-success"><?= $sup['achievement_pct'] ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="10" class="text-center py-4 text-muted fs-xs">No supervisor squads found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isSuperAdmin || $isAdmin): ?>
        <!-- TAB 5b: ADMIN ORGANIZATIONAL ROLLUP -->
        <div class="tab-pane fade <?= $active_tab === 'admins' ? 'show active' : '' ?>" id="tab-admins" role="tabpanel">
            <div class="crm-card mb-3">
                <div class="crm-card-header d-flex align-items-center justify-content-between">
                    <div class="crm-card-title mb-0"><i class="bi bi-diagram-3 text-purple me-2"></i>Admin-Level Organizational Performance</div>
                    <span class="badge badge-purple fs-xs"><?= count($adminRows) ?> Admin Accounts</span>
                </div>
                <div class="crm-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Admin</th>
                                    <th class="text-center">Supervisors Under</th>
                                    <th class="text-center">Officers Under</th>
                                    <th class="text-center">Total Dials</th>
                                    <th class="text-center">Connected</th>
                                    <th class="text-center">Connect %</th>
                                    <th class="text-center">Interested</th>
                                    <th class="text-center">Converted Leads</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($adminRows)): ?>
                                    <?php foreach ($adminRows as $adm): ?>
                                        <tr>
                                            <td><strong class="text-dark fs-xs"><i class="bi bi-person-fill-gear me-1 text-purple"></i> <?= htmlspecialchars($adm['admin_name']) ?></strong></td>
                                            <td class="text-center fs-xs fw-semibold"><?= (int)$adm['total_supervisors'] ?></td>
                                            <td class="text-center fs-xs fw-semibold"><?= (int)$adm['total_officers'] ?></td>
                                            <td class="text-center fs-xs fw-bold text-dark"><?= number_format($adm['total_dials']) ?></td>
                                            <td class="text-center fs-xs"><span class="badge badge-primary"><?= number_format($adm['connected']) ?></span></td>
                                            <td class="text-center fs-xs"><span class="badge badge-success"><?= $adm['connect_rate'] ?>%</span></td>
                                            <td class="text-center fs-xs"><span class="badge badge-info"><?= number_format($adm['interested']) ?></span></td>
                                            <td class="text-center fs-xs"><span class="badge badge-purple"><?= number_format($adm['converted_leads']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted fs-xs">No admin records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB 6: HOURLY CALLING ANALYTICS -->
        <div class="tab-pane fade <?= $active_tab === 'hourly' ? 'show active' : '' ?>" id="tab-hourly" role="tabpanel">
            <div class="crm-card mb-3">
                <div class="crm-card-header d-flex align-items-center justify-content-between">
                    <div class="crm-card-title mb-0"><i class="bi bi-hourglass-split text-info me-2"></i>Hourly Telecalling Distribution (09:00 AM to 06:00 PM)</div>
                    <span class="text-muted fs-xs">Timezone: Asia/Kolkata</span>
                </div>
                <div class="crm-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Hour Window</th>
                                    <th class="text-center">Total Calls Dialed</th>
                                    <th class="text-center">Connected Calls</th>
                                    <th class="text-center">Unique Contacts</th>
                                    <th class="text-center">Interested Leads</th>
                                    <th>Total Talk Time</th>
                                    <th class="text-center">Connect %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hourlyAnalytics as $ha): ?>
                                    <tr>
                                        <td class="fw-semibold text-dark fs-xs"><i class="bi bi-clock me-1 text-muted"></i> <?= $ha['window'] ?></td>
                                        <td class="text-center fs-xs fw-bold"><?= number_format($ha['total_calls']) ?></td>
                                        <td class="text-center fs-xs"><span class="badge badge-primary"><?= number_format($ha['connected_calls']) ?></span></td>
                                        <td class="text-center fs-xs text-muted"><?= number_format($ha['unique_contacts']) ?></td>
                                        <td class="text-center fs-xs"><span class="badge badge-success"><?= number_format($ha['interested_calls']) ?></span></td>
                                        <td class="fs-xs text-muted"><?= $ha['talk_time_formatted'] ?></td>
                                        <td class="text-center fs-xs">
                                            <span class="badge <?= $ha['connect_rate'] >= 30 ? 'badge-success' : 'badge-secondary' ?>">
                                                <?= $ha['connect_rate'] ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 7: FOLLOW-UP MANAGEMENT HUB -->
        <div class="tab-pane fade <?= $active_tab === 'followups' ? 'show active' : '' ?>" id="tab-followups" role="tabpanel">
            <div class="crm-card mb-3">
                <div class="crm-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="crm-card-title mb-0"><i class="bi bi-calendar-check text-purple me-2"></i>Follow-ups & Scheduled Callbacks Hub</div>
                        <span class="badge badge-purple fs-xs"><?= count($followupList) ?> Scheduled</span>
                    </div>
                    <input type="text" id="followupFilterInput" class="form-control form-control-sm" placeholder="Search follow-ups..." style="max-width: 220px;">
                </div>
                <div class="crm-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="followupsReportTable">
                            <thead>
                                <tr>
                                    <th>Customer Name</th>
                                    <th>Mobile Number</th>
                                    <th>Entity Classification</th>
                                    <th>Assigned Officer</th>
                                    <th>Scheduled Follow-up</th>
                                    <th>Due Status</th>
                                    <th>Notes / Remarks</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($followupList)): ?>
                                    <?php foreach ($followupList as $fo): 
                                        $isOverdue = (strtotime($fo['followup_at']) < time()) && ($fo['status'] === 'OPEN');
                                        $isToday = (date('Y-m-d', strtotime($fo['followup_at'])) === date('Y-m-d'));
                                    ?>
                                        <tr>
                                            <td><strong class="text-dark fs-xs"><?= htmlspecialchars($fo['customer_name'] ?: 'Customer') ?></strong></td>
                                            <td class="font-monospace fs-xs"><?= htmlspecialchars($fo['customer_mobile'] ?: '—') ?></td>
                                            <td>
                                                <?php if ($fo['lead_id']): ?>
                                                    <span class="badge badge-success fs-xs"><i class="bi bi-link me-1"></i> Converted Lead #<?= $fo['lead_id'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary fs-xs">Client Contact #<?= $fo['client_contact_id'] ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fs-xs text-dark"><?= htmlspecialchars($fo['officer_name'] ?: 'Unknown') ?></td>
                                            <td class="fs-xs text-dark fw-semibold"><?= date('d M Y, h:i A', strtotime($fo['followup_at'])) ?></td>
                                            <td>
                                                <?php if ($fo['status'] === 'DONE'): ?>
                                                    <span class="badge badge-success fs-xs">Completed</span>
                                                <?php elseif ($isOverdue): ?>
                                                    <span class="badge badge-danger fs-xs">Overdue</span>
                                                <?php elseif ($isToday): ?>
                                                    <span class="badge badge-warning fs-xs">Due Today</span>
                                                <?php else: ?>
                                                    <span class="badge badge-info fs-xs">Upcoming</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fs-xs text-muted" style="max-width: 250px;">
                                                <span class="d-inline-block text-truncate" style="max-width: 240px;"><?= htmlspecialchars($fo['note'] ?: '—') ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge <?= $fo['status'] === 'DONE' ? 'badge-success' : 'badge-primary' ?> fs-xs">
                                                    <?= htmlspecialchars($fo['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted fs-xs">No scheduled follow-ups found in this scope.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 8: PERFORMANCE ALERTS CENTER -->
        <div class="tab-pane fade <?= $active_tab === 'alerts' ? 'show active' : '' ?>" id="tab-alerts" role="tabpanel">
            <div class="crm-card mb-3">
                <div class="crm-card-header d-flex align-items-center justify-content-between">
                    <div class="crm-card-title mb-0"><i class="bi bi-bell text-danger me-2"></i>Performance Alerts Center</div>
                    <span class="badge badge-danger fs-xs"><?= count($alerts) ?> Active Alerts</span>
                </div>
                <div class="crm-card-body">
                    <?php if (!empty($alerts)): ?>
                        <div class="row g-2">
                            <?php foreach ($alerts as $alt): ?>
                                <div class="col-md-6">
                                    <div class="alert alert-<?= $alt['severity'] ?> py-2 px-3 mb-2 d-flex align-items-start gap-2">
                                        <i class="bi <?= $alt['severity'] === 'danger' ? 'bi-exclamation-octagon-fill' : ($alt['severity'] === 'warning' ? 'bi-exclamation-triangle-fill' : ($alt['severity'] === 'success' ? 'bi-check-circle-fill' : 'bi-info-circle-fill')) ?> fs-5 mt-1"></i>
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <strong class="fs-xs"><?= htmlspecialchars($alt['title']) ?></strong>
                                                <span class="badge bg-<?= $alt['severity'] ?> fs-xxs"><?= $alt['badge'] ?></span>
                                            </div>
                                            <div class="fs-xs mt-1"><?= htmlspecialchars($alt['message']) ?></div>
                                            <div class="text-muted fs-xxs mt-1">Responsible Officer: <strong><?= htmlspecialchars($alt['officer']) ?></strong></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-shield-check text-success fs-1 mb-2"></i>
                            <h6 class="fw-bold text-dark">Zero Performance Violations</h6>
                            <p class="fs-xs text-muted mb-0">All telecallers are meeting connection rate targets and timely callbacks.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$isOfficer): ?>
        <!-- TAB 9: RANKINGS & PRODUCTIVITY LEADERBOARD -->
        <div class="tab-pane fade <?= $active_tab === 'rankings' ? 'show active' : '' ?>" id="tab-rankings" role="tabpanel">
            <div class="crm-card mb-3">
                <div class="crm-card-header d-flex align-items-center justify-content-between">
                    <div class="crm-card-title mb-0"><i class="bi bi-trophy text-warning me-2"></i>Telecalling Productivity & Sales Score Leaderboard</div>
                    <span class="text-muted fs-xs">Ranked by 6-Factor Weighted Scoring Model</span>
                </div>
                <div class="crm-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width: 60px;">Rank</th>
                                    <th>Sales Officer</th>
                                    <th>Team</th>
                                    <th class="text-center">Total Dials</th>
                                    <th class="text-center">Connected</th>
                                    <th class="text-center">Connect %</th>
                                    <th class="text-center">Converted Leads</th>
                                    <th>Productivity Score</th>
                                    <th>Sales Score</th>
                                    <th class="text-center">Status Badge</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($rankings)): ?>
                                    <?php $rankIdx = 1; foreach ($rankings as $rk): 
                                        $medal = '';
                                        if ($rankIdx === 1) $medal = '🥇';
                                        elseif ($rankIdx === 2) $medal = '🥈';
                                        elseif ($rankIdx === 3) $medal = '🥉';
                                    ?>
                                        <tr>
                                            <td class="text-center fs-sm fw-bold">
                                                <?= $medal ?: ('#' . $rankIdx) ?>
                                            </td>
                                            <td>
                                                <strong class="text-dark fs-xs"><?= htmlspecialchars($rk['employee_name']) ?></strong>
                                            </td>
                                            <td class="fs-xs text-muted"><?= htmlspecialchars($rk['team_name'] ?: 'No Team') ?></td>
                                            <td class="text-center fs-xs fw-bold"><?= number_format($rk['total_dials']) ?></td>
                                            <td class="text-center fs-xs"><span class="badge badge-primary"><?= number_format($rk['connected']) ?></span></td>
                                            <td class="text-center fs-xs"><span class="badge badge-success"><?= $rk['connect_rate'] ?>%</span></td>
                                            <td class="text-center fs-xs"><span class="badge badge-purple"><?= number_format($rk['converted_leads']) ?></span></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2" style="min-width: 110px;">
                                                    <div class="progress flex-grow-1" style="height: 6px;">
                                                        <div class="progress-bar bg-warning" style="width: <?= min(100, $rk['calling_score']) ?>%"></div>
                                                    </div>
                                                    <span class="fs-xs fw-bold text-dark"><?= $rk['calling_score'] ?> / 100</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2" style="min-width: 110px;">
                                                    <div class="progress flex-grow-1" style="height: 6px;">
                                                        <div class="progress-bar bg-success" style="width: <?= min(100, $rk['sales_score']) ?>%"></div>
                                                    </div>
                                                    <span class="fs-xs fw-bold text-success"><?= $rk['sales_score'] ?> / 100</span>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($rankIdx <= 3 && $rk['calling_score'] >= 50): ?>
                                                    <span class="badge badge-warning fs-xs"><i class="bi bi-star-fill me-1"></i> TOP PERFORMER</span>
                                                <?php elseif ($rk['calling_score'] >= 70): ?>
                                                    <span class="badge badge-success fs-xs">EXCELLENT</span>
                                                <?php elseif ($rk['calling_score'] >= 40): ?>
                                                    <span class="badge badge-primary fs-xs">ON TRACK</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary fs-xs">NEEDS ATTENTION</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php $rankIdx++; endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="10" class="text-center py-4 text-muted fs-xs">No ranking data available for this window.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB 10: DETAILED CALL LOGS DRILLDOWN -->
        <div class="tab-pane fade <?= $active_tab === 'drilldown' ? 'show active' : '' ?>" id="tab-drilldown" role="tabpanel">
            <div class="crm-card mb-3">
                <div class="crm-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="crm-card-title mb-0"><i class="bi bi-clock-history text-primary me-2"></i>Call Logs Audit Trail</div>
                        <span class="badge badge-primary fs-xs"><?= count($detailRows) ?> Records</span>
                    </div>
                    <?php if ($selected_user_id && !$isOfficer): ?>
                        <a href="<?= $self ?>?<?= htmlspecialchars(reportQs(['user' => '', 'tab' => 'drilldown'])) ?>" class="btn btn-secondary btn-sm py-1 px-2 fs-xs">
                            <i class="bi bi-x-lg me-1"></i> Clear Officer Filter (<?= htmlspecialchars($selected_user_name) ?>)
                        </a>
                    <?php endif; ?>
                </div>
                <div class="crm-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Telecaller</th>
                                    <th>Customer Name</th>
                                    <th>Mobile Number</th>
                                    <th>Duration</th>
                                    <th>Outcome</th>
                                    <th>Entity Relationship</th>
                                    <th>Audio Recording</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($detailRows)): ?>
                                    <?php foreach ($detailRows as $dr): 
                                        $st = $dr['call_status'];
                                        $bCls = 'badge-secondary';
                                        if (in_array(strtolower($st), ['interested', 'lead', 'connected', 'deal converted'])) $bCls = 'badge-success';
                                        elseif (in_array(strtolower($st), ['callback', 'busy', 'follow-up'])) $bCls = 'badge-info';
                                        elseif (in_array(strtolower($st), ['do not call', 'dnd'])) $bCls = 'badge-danger';
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold fs-xs text-dark"><?= date('d M Y', strtotime($dr['call_time'] ?: $dr['created_at'])) ?></div>
                                                <small class="text-muted fs-xs"><?= date('h:i A', strtotime($dr['call_time'] ?: $dr['created_at'])) ?></small>
                                            </td>
                                            <td class="fs-xs fw-semibold text-dark"><?= htmlspecialchars($dr['officer_name'] ?: 'Unknown') ?></td>
                                            <td><strong class="text-dark fs-xs"><?= htmlspecialchars($dr['cust_name'] ?: '—') ?></strong></td>
                                            <td class="font-monospace fs-xs"><?= htmlspecialchars($dr['cust_mobile'] ?: '—') ?></td>
                                            <td>
                                                <span class="badge badge-secondary fs-xs">
                                                    <i class="bi bi-stopwatch me-1"></i> <?= (int)$dr['call_duration'] ?>s
                                                </span>
                                            </td>
                                            <td><span class="badge <?= $bCls ?> fs-xs"><?= htmlspecialchars($st) ?></span></td>
                                            <td>
                                                <?php if ($dr['lead_id']): ?>
                                                    <span class="badge badge-success fs-xs"><i class="bi bi-link me-1"></i> Converted Lead #<?= $dr['lead_id'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary fs-xs">Contact #<?= $dr['client_contact_id'] ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($dr['recording_url'])): ?>
                                                    <audio controls preload="none" style="height: 28px; max-width: 160px;">
                                                        <source src="<?= htmlspecialchars($dr['recording_url']) ?>">
                                                    </audio>
                                                <?php else: ?>
                                                    <span class="text-muted fs-xs">No Audio</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted fs-xs">No call logs found for this window.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /tab-content -->
</div>

<?php if ($isSuperAdmin): ?>
<!-- SUPER ADMIN TARGETS CONFIGURATION MODAL -->
<div class="modal fade" id="targetsConfigModal" tabindex="-1" aria-labelledby="targetsConfigModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <?= csrfField() ?>
                <input type="hidden" name="save_targets" value="1">
                <div class="modal-header">
                    <h5 class="modal-title fs-sm fw-bold" id="targetsConfigModalLabel">
                        <i class="bi bi-sliders text-purple me-2"></i>Configure Reporting Targets & Thresholds
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body fs-xs">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Daily Call Dials Target (per Officer)</label>
                        <input type="number" name="daily_call_target" class="form-control form-control-sm" value="<?= (int)$targets['daily_call_target'] ?>" required min="10" max="1000">
                        <div class="form-text fs-xxs">Used to compute achievement % and calling productivity rankings.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Daily Connected Calls Target</label>
                        <input type="number" name="daily_connected_target" class="form-control form-control-sm" value="<?= (int)$targets['daily_connected_target'] ?>" required min="5" max="500">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Daily Talk Time Target (Minutes)</label>
                        <input type="number" name="daily_talk_time_minutes" class="form-control form-control-sm" value="<?= (int)$targets['daily_talk_time_minutes'] ?>" required min="30" max="600">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Daily Lead Conversions Target</label>
                        <input type="number" name="daily_lead_target" class="form-control form-control-sm" value="<?= (int)$targets['daily_lead_target'] ?>" required min="1" max="50">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Min Connect Rate %</label>
                            <input type="number" step="0.1" name="min_connect_rate_pct" class="form-control form-control-sm" value="<?= (float)$targets['min_connect_rate_pct'] ?>" required min="5" max="100">
                            <div class="form-text fs-xxs">Alert triggers if below this.</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Max Overdue Follow-ups</label>
                            <input type="number" name="max_overdue_followups" class="form-control form-control-sm" value="<?= (int)$targets['max_overdue_followups'] ?>" required min="1" max="50">
                            <div class="form-text fs-xxs">Critical alert threshold.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Configuration</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

<script src="<?= vnd('js/chart.umd.min.js') ?>"></script>
<script>
// Time window change handler
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

// Tab state sync with query params
document.querySelectorAll('#reportTabs button[data-bs-toggle="tab"]').forEach(function(tabBtn) {
    tabBtn.addEventListener('shown.bs.tab', function(e) {
        var target = e.target.getAttribute('data-bs-target').replace('#tab-', '');
        var input = document.getElementById('activeTabInput');
        if (input) input.value = target;
    });
});

// Client-side quick filter helpers
function setupTableSearch(inputId, tableId) {
    document.getElementById(inputId)?.addEventListener('input', function() {
        var term = this.value.toLowerCase().trim();
        document.querySelectorAll('#' + tableId + ' tbody tr').forEach(function(row) {
            row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
        });
    });
}
setupTableSearch('contactFilterInput', 'contactsReportTable');
setupTableSearch('leadFilterInput', 'leadsReportTable');
setupTableSearch('employeeFilterInput', 'employeeReportTable');
setupTableSearch('followupFilterInput', 'followupsReportTable');

// Charts Initialization
const hourlyLabels = <?= json_encode(array_column($hourlyAnalytics, 'window')) ?>;
const hourlyCalls = <?= json_encode(array_column($hourlyAnalytics, 'total_calls')) ?>;
const hourlyConnected = <?= json_encode(array_column($hourlyAnalytics, 'connected_calls')) ?>;

if (document.getElementById('hourlyTrendChart')) {
    new Chart(document.getElementById('hourlyTrendChart'), {
        type: 'bar',
        data: {
            labels: hourlyLabels,
            datasets: [
                { label: 'Total Calls Dialed', data: hourlyCalls, backgroundColor: '#6D45F9', borderRadius: 4 },
                { label: 'Connected Calls', data: hourlyConnected, backgroundColor: '#0090DF', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
}

if (document.getElementById('dispositionDoughnutChart')) {
    new Chart(document.getElementById('dispositionDoughnutChart'), {
        type: 'doughnut',
        data: {
            labels: ['Interested / Lead', 'Callback / Busy', 'Not Interested', 'DND', 'Switch Off / Unreachable'],
            datasets: [{
                data: [
                    <?= (int)$contactMetrics['interested'] ?>,
                    <?= (int)$contactMetrics['callback_busy'] ?>,
                    <?= (int)$contactMetrics['not_interested'] ?>,
                    <?= (int)$contactMetrics['dnd'] ?>,
                    <?= (int)($contactMetrics['switch_off'] + $contactMetrics['not_reachable']) ?>
                ],
                backgroundColor: ['#16A66A', '#D89012', '#64748b', '#E5484D', '#8C72FF']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });
}
</script>
