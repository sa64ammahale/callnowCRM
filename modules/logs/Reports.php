<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php'; // Gives USER_ROLE, USER_ID, USER_TEAM_ID

// Role check
if (!in_array(USER_ROLE, ['Admin','Manager','Supervisor','Officer'])) {
    header("Location: dashboard.php");
    exit;
}


// Filters
$report_type = $_GET['type'] ?? 'daily';
$from        = $_GET['from'] ?? '';
$to          = $_GET['to'] ?? '';
$team_filter = $_GET['team'] ?? '';
$selected_user_id = isset($_GET['user']) && is_numeric($_GET['user'])
    ? (int)$_GET['user']
    : null;

// Base params for prepared statements
$paramsBase = [];
$typesBase  = "";

// Common WHERE parts applied on unified m + joined USERS/TEAMS
$whereParts = [];

// We only care about records that have a telecaller and a call time
$whereParts[] = "m.user_id IS NOT NULL";
$whereParts[] = "m.call_time IS NOT NULL";

/**
 * Date filters on m.call_time
 */
if ($report_type === 'custom' && $from && $to) {
    $whereParts[] = "DATE(m.call_time) BETWEEN ? AND ?";
    $paramsBase[] = $from;
    $paramsBase[] = $to;
    $typesBase   .= "ss";
} elseif ($report_type === 'monthly') {
    $whereParts[] = "MONTH(m.call_time) = MONTH(CURDATE()) 
                     AND YEAR(m.call_time) = YEAR(CURDATE())";
} elseif ($report_type === 'yearly') {
    $whereParts[] = "YEAR(m.call_time) = YEAR(CURDATE())";
} else {
    // daily (today) default
    $whereParts[] = "DATE(m.call_time) = CURDATE()";
}

/**
 * Role-based / team-based restrictions
 */
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

// Build WHERE SQL once
$whereSql = $whereParts
    ? "WHERE " . implode(" AND ", $whereParts)
    : "";

/**
 * Unified call log: TEMPORARY_DATABASE + MAIN_DATABASE
 * Alias fields to a common structure.
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
    FROM TEMPORARY_DATABASE

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
    FROM MAIN_DATABASE
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
    JOIN USERS u ON m.user_id = u.ID
    LEFT JOIN TEAMS t ON u.TEAM_ID = t.ID
    LEFT JOIN USERS s ON t.SUPERVISOR_ID = s.ID
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

// Group data by team
$data          = [];
$grand_total   = 0;
$grand_connected = 0;
$grand_dialed  = 0;
$grand_busy    = 0;
$grand_no_ans  = 0;
$grand_dnc     = 0;
$grand_pending = 0;
$grand_not_called = 0;
$users_index   = []; // for selected user name lookup

while ($row = mysqli_fetch_assoc($result)) {
    $row['rate'] = $row['total'] ? round($row['connected'] / $row['total'] * 100, 1) : 0;

    $team_name = $row['team_name'] ?: 'No Team';
    if (!isset($data[$team_name])) {
        $data[$team_name] = [
            'team_id'         => $row['team_id'],
            'supervisor_id'   => $row['SUPERVISOR_ID'],
            'supervisor_name' => $row['supervisor_name'],
            'users'           => []
        ];
    }
    $data[$team_name]['users'][] = $row;

    // Grand totals
    $grand_total      += (int)$row['total'];
    $grand_connected  += (int)$row['connected'];
    $grand_dialed     += (int)$row['dialed'];
    $grand_busy       += (int)$row['busy'];
    $grand_no_ans     += (int)$row['no_answer'];
    $grand_dnc        += (int)$row['dnc'];
    $grand_pending    += (int)$row['pending'];
    $grand_not_called += (int)$row['not_called'];

    $users_index[$row['user_id']] = $row['user_name'];
}

mysqli_stmt_close($stmt);

$grand_rate = $grand_total ? round($grand_connected / $grand_total * 100, 1) : 0;

// Teams for filter dropdown
$teams = in_array(USER_ROLE, ['Admin','Manager'])
    ? mysqli_fetch_all(mysqli_query($link, "SELECT ID, NAME FROM TEAMS ORDER BY NAME"), MYSQLI_ASSOC)
    : [];

/**
 * DETAIL QUERY: calls of a single telecaller (if requested)
 */
$detail_rows = [];
$selected_user_name = null;

if ($selected_user_id !== null) {
    $selected_user_name = $users_index[$selected_user_id] ?? null;

    // If selected user is not in summary (because of filters), we still try to fetch their name
    if ($selected_user_name === null) {
        $resUser = mysqli_query($link, "SELECT NAME FROM USERS WHERE ID = " . (int)$selected_user_id);
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
        JOIN USERS u ON m.user_id = u.ID
        LEFT JOIN TEAMS t ON u.TEAM_ID = t.ID
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

mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Call Report • CallNow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/app-theme.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; margin:0; }
        .header-bar { background: linear-gradient(135deg, #6366f1, #8b5cf6); padding: 1rem 0; }
        .filter-bar { background: rgba(255,255,255,0.08); border-radius: 1rem; padding: 1rem; margin-bottom: 1.5rem; }
        .team-box { background: rgba(255,255,255,0.06); border-radius: 1rem; overflow: hidden; margin-bottom: 1.2rem; border: 1px solid rgba(255,255,255,0.1); }
        .team-head { background: linear-gradient(135deg, #6366f1, #8b5cf6); padding: 0.8rem 1.2rem; font-size: 0.95rem; }
        .user-item { padding: 0.75rem 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 0.9rem; }
        .user-item:last-child { border-bottom: none; }
        .avatar { width: 36px; height: 36px; background: #6366f1; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.8rem; }
        .badge-rate { font-size: 1.1rem; padding: 0.5rem 1rem; border-radius: 50px; }
        .no-data { text-align: center; padding: 4rem 0; color: #94a3b8; }
        .stat-pill { font-size: 0.75rem; padding: 0.15rem 0.5rem; border-radius: 999px; background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(148, 163, 184, 0.4); margin-right: 0.25rem; white-space: nowrap; }
        .rate-good { color: #22c55e; }
        .rate-poor { color: #ef4444; }
        .detail-card { background: rgba(15,23,42,0.9); border-radius: 1rem; border: 1px solid rgba(148,163,184,0.3); padding: 1rem; }
        .table-sm td, .table-sm th { padding: 0.3rem 0.5rem; }
    </style>
</head>
<body>
<?php include '../../php_scripts/header.php'; ?>

<!-- Header -->
<div class="header-bar text-white">
    <div class="container">
        <div class="row align-items-center">
            <div class="col">
                <h4 class="mb-0 fw-bold">
                    Call Performance Report
                    <?php if (USER_ROLE === 'Supervisor'): ?> • My Team<?php endif; ?>
                    <?php if (USER_ROLE === 'Officer'): ?> • My Calls<?php endif; ?>
                </h4>
                <small class="opacity-75">
                    Summary per telecaller • Click "View Details" for full call list
                </small>
            </div>
            <div class="col-auto text-end">
                <div class="d-flex gap-3 align-items-center">
                    <div>
                        <small class="opacity-75">Total Calls</small><br>
                        <strong class="fs-4"><?= number_format($grand_total) ?></strong>
                    </div>
                    <div>
                        <small class="opacity-75">Connected</small><br>
                        <strong><?= number_format($grand_connected) ?></strong>
                    </div>
                    <div>
                        <small class="opacity-75">Busy / No Answer</small><br>
                        <strong><?= number_format($grand_busy + $grand_no_ans) ?></strong>
                    </div>
                    <div class="badge-rate <?= $grand_rate >= 40 ? 'bg-success' : 'bg-danger' ?>">
                        <?= $grand_rate ?>% Connected
                    </div>
                    <div class="text-end">
                        <small class="opacity-75 d-block">
                            Auto-refresh in <span id="timer">60</span>s
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Live countdown
let secs = 60;
setInterval(() => {
    secs--;
    if (secs < 0) secs = 60;
    const el = document.getElementById('timer');
    if (el) el.textContent = secs;
}, 1000);
</script>

<div class="container py-4" id="exportArea">
    <!-- Filters -->
    <div class="filter-bar">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-auto">
                <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="daily"   <?= $report_type=='daily'?'selected':'' ?>>Today</option>
                    <option value="monthly" <?= $report_type=='monthly'?'selected':'' ?>>This Month</option>
                    <option value="yearly"  <?= $report_type=='yearly'?'selected':'' ?>>This Year</option>
                    <option value="custom"  <?= $report_type=='custom'?'selected':'' ?>>Custom</option>
                </select>
            </div>

            <?php if ($report_type === 'custom'): ?>
                <div class="col-auto">
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($from) ?>" required>
                </div>
                <div class="col-auto">
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($to) ?>" required>
                </div>
            <?php endif; ?>

            <?php if (in_array(USER_ROLE, ['Admin','Manager'])): ?>
                <div class="col-auto">
                    <select name="team" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Teams</option>
                        <?php foreach($teams as $t): ?>
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
                    <i class="bi bi-funnel"></i> Go
                </button>
            </div>
        </form>
    </div>

    <!-- Team & Telecaller Summary -->
    <?php if (!empty($data)): ?>
        <?php foreach ($data as $team_name => $block):
            $team_total = 0;
            $team_connected = 0;
            foreach ($block['users'] as $u) {
                $team_total     += $u['total'];
                $team_connected += $u['connected'];
            }
            $team_rate = $team_total ? round($team_connected / $team_total * 100, 1) : 0;
            $sup = $block['supervisor_name'] ?? '—';
        ?>
            <div class="team-box mb-3">
                <div class="team-head">
                    <div class="row align-items-center">
                        <div class="col">
                            <strong><?= htmlspecialchars($team_name) ?></strong>
                            <small class="opacity-75"> • Supervisor: <?= htmlspecialchars($sup ?: '—') ?></small>
                        </div>
                        <div class="col-auto text-end">
                            <strong><?= number_format($team_total) ?></strong> calls •
                            <span class="<?= $team_rate >= 40 ? 'text-success' : 'text-danger' ?>">
                                <?= $team_rate ?>% connected
                            </span>
                        </div>
                    </div>
                </div>
                <div>
                    <?php foreach ($block['users'] as $u): ?>
                        <?php
                        $rate = $u['rate'];
                        $rateClass = $rate >= 40 ? 'rate-good' : 'rate-poor';
                        $isSelected = $selected_user_id && $selected_user_id == $u['user_id'];
                        ?>
                        <div class="user-item d-flex align-items-center justify-content-between <?= $isSelected ? 'bg-opacity-75 bg-black' : '' ?>">
                            <div class="d-flex align-items-center gap-3 flex-grow-1">
                                <div class="avatar">
                                    <?= strtoupper(substr($u['user_name'] ?? '?', 0, 2)) ?>
                                </div>
                                <div>
                                    <div class="fw-semibold">
                                        <?= htmlspecialchars($u['user_name'] ?? 'Unknown') ?>
                                        <?php if ($isSelected): ?>
                                            <span class="badge bg-info ms-1">Selected</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-1">
                                        <span class="stat-pill">
                                            Total: <?= (int)$u['total'] ?>
                                        </span>
                                        <span class="stat-pill">
                                            Connected: <?= (int)$u['connected'] ?>
                                        </span>
                                        <span class="stat-pill">
                                            Busy: <?= (int)$u['busy'] ?>
                                        </span>
                                        <span class="stat-pill">
                                            No Ans: <?= (int)$u['no_answer'] ?>
                                        </span>
                                        <span class="stat-pill">
                                            DNC: <?= (int)$u['dnc'] ?>
                                        </span>
                                        <span class="stat-pill">
                                            Pending: <?= (int)$u['pending'] ?>
                                        </span>
                                        <span class="stat-pill">
                                            Not Called: <?= (int)$u['not_called'] ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <div class="text-end me-3">
                                    <div class="fw-bold <?= $rateClass ?>"><?= $rate ?>%</div>
                                    <small class="opacity-70">connect rate</small>
                                </div>
                                <div>
                                    <?php
                                        // Build query string preserving filters, adding user param
                                        $qs = [
                                            'type' => $report_type,
                                            'from' => $from,
                                            'to'   => $to,
                                            'team' => $team_filter,
                                            'user' => $u['user_id'],
                                        ];
                                        $self = htmlspecialchars($_SERVER['PHP_SELF']);
                                        $detail_link = $self . '?' . http_build_query($qs);
                                    ?>
                                    <a href="<?= $detail_link ?>"
                                       class="btn btn-outline-light btn-sm">
                                        <i class="bi bi-person-lines-fill"></i>
                                        View Details
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="no-data">
            <i class="bi bi-telephone-outbound display-1 opacity-50"></i>
            <h5 class="mt-3 text-muted">No calls found for selected period</h5>
        </div>
    <?php endif; ?>

    <!-- Detailed Calls for selected telecaller -->
    <?php if ($selected_user_id && !empty($detail_rows)): ?>
        <div class="mt-4">
            <div class="detail-card">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <h5 class="mb-0">
                            <i class="bi bi-person-circle me-2"></i>
                            Detailed Calls •
                            <?= htmlspecialchars($selected_user_name ?? ('User #' . $selected_user_id)) ?>
                        </h5>
                        <small class="text-muted">
                            Showing <?= count($detail_rows) ?> calls for applied filters
                        </small>
                    </div>
                    <div>
                        <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query([
                            'type' => $report_type,
                            'from' => $from,
                            'to'   => $to,
                            'team' => $team_filter
                        ]) ?>"
                           class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-x-circle"></i> Clear Selection
                        </a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-striped table-hover table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 16%;">Date & Time</th>
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
        </div>
    <?php elseif ($selected_user_id && empty($detail_rows)): ?>
        <div class="mt-4 text-center text-muted">
            No calls found for this telecaller with selected filters.
        </div>
    <?php endif; ?>

    <!-- Export Button -->
    <div class="text-center mt-4">
        <button id="btnExport" class="btn btn-success btn-lg px-5">
            <i class="bi bi-file-earmark-excel"></i> Export to Excel
        </button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/table2excel@1.0.4/dist/table2excel.min.js"></script>
<script>
// Auto-refresh every 60 seconds
setTimeout(() => location.reload(), 60000);

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
</body>
</html>
