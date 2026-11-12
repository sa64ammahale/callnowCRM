<?php
require_once "php_scripts/auth.php";
require_once "php_scripts/hierarchy_engine.php";

$pageTitle = 'Dashboard';
include 'php_scripts/header.php';

if (!function_exists('fmt')) {
    function fmt($num) {
        return number_format((int)$num);
    }
}

$tblMain = tn('TBL_MAIN');
$tblCallLogs = tn('TBL_CALL_LOGS');
$userRole = USER_ROLE;
$userId = (int)USER_ID;
$userTeamId = defined('USER_TEAM_ID') ? (int)USER_TEAM_ID : null;

$dashContactScope = getHierarchyContactFilterSQL($link, $userId, $userRole, 'm', $userTeamId);
$dashUserScope = getHierarchyUserFilterSQL($link, $userId, $userRole, 'm', 'MAINDATABASE_CALL_DIALED_USER', $userTeamId);

$today_calls = $today_connected = $connect_rate = 0;
try {
    $res = mysqli_query($link, "
        SELECT COUNT(*) as total_calls, 
               SUM(CASE WHEN MAINDATABASE_CALL_DIALED_STATUS = 'Connected' THEN 1 ELSE 0 END) as connected 
        FROM $tblMain m
        WHERE MAINDATABASE_CALL_DIAL_TIME >= CURDATE() 
          AND MAINDATABASE_CALL_DIAL_TIME < CURDATE() + INTERVAL 1 DAY
          AND $dashContactScope
    ");
    $row = $res ? mysqli_fetch_assoc($res) : ['total_calls' => 0, 'connected' => 0];
} catch (\Throwable $e) { 
    $row = ['total_calls' => 0, 'connected' => 0]; 
}
$today_calls     = (int)($row['total_calls'] ?? 0);
$today_connected = (int)($row['connected'] ?? 0);
$connect_rate    = $today_calls > 0 ? round(($today_connected / $today_calls) * 100, 1) : 0;

$total_leads = 0;
try {
    $res2 = mysqli_query($link, "
        SELECT COUNT(*) as leads 
        FROM $tblMain m
        WHERE MAINDATABASE_CALL_DIALED_STATUS IN ('Connected','Interested','Follow Up','Sale','Callback')
          AND $dashContactScope
    ");
    $row2 = $res2 ? mysqli_fetch_assoc($res2) : ['leads' => 0];
} catch (\Throwable $e) { 
    $row2 = ['leads' => 0]; 
}
$total_leads = (int)($row2['leads'] ?? 0);

$active_callers_today = 0;
try {
    $res3 = mysqli_query($link, "
        SELECT COUNT(DISTINCT MAINDATABASE_CALL_DIALED_USER) as cnt 
        FROM $tblMain m
        WHERE MAINDATABASE_CALL_DIAL_TIME >= CURDATE() 
          AND MAINDATABASE_CALL_DIAL_TIME < CURDATE() + INTERVAL 1 DAY 
          AND MAINDATABASE_CALL_DIALED_USER IS NOT NULL
          AND $dashUserScope
    ");
    $row3 = $res3 ? mysqli_fetch_assoc($res3) : ['cnt' => 0];
} catch (\Throwable $e) { 
    $row3 = ['cnt' => 0]; 
}
$active_callers_today = (int)($row3['cnt'] ?? 0);

$total_numbers = 0;
try {
    $res4 = mysqli_query($link, "SELECT COUNT(*) as total FROM $tblMain m WHERE $dashContactScope");
    $row4 = $res4 ? mysqli_fetch_assoc($res4) : ['total' => 0];
} catch (\Throwable $e) { 
    $row4 = ['total' => 0]; 
}
$total_numbers = (int)($row4['total'] ?? 0);

$unused_numbers = 0;
try {
    $res5 = mysqli_query($link, "
        SELECT COUNT(*) as unused 
        FROM $tblMain m
        WHERE (MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' 
           OR MAINDATABASE_CALL_DIALED_STATUS IS NULL 
           OR MAINDATABASE_CALL_DIALED_STATUS = '' 
           OR MAINDATABASE_CALL_DIAL_TIME IS NULL)
          AND $dashContactScope
    ");
    $row5 = $res5 ? mysqli_fetch_assoc($res5) : ['unused' => 0];
} catch (\Throwable $e) { 
    $row5 = ['unused' => 0]; 
}
$unused_numbers = (int)($row5['unused'] ?? 0);
$pending_pct = $total_numbers > 0 ? round(($unused_numbers / $total_numbers) * 100, 1) : 0;

$dashLeadScope = getHierarchyLeadFilterSQL($link, $userId, $userRole, 'l', $userTeamId);
$total_pipeline_leads = 0;
try {
    $resLeads = mysqli_query($link, "SELECT COUNT(*) as cnt FROM " . tn('TBL_LEADS') . " l WHERE $dashLeadScope");
    $total_pipeline_leads = (int)($resLeads ? mysqli_fetch_assoc($resLeads)['cnt'] : 0);
} catch (\Throwable $e) {}

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
$todayDateFormatted = date('l, d F Y');

$trendDays = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendDays[$d] = [
        'date' => $d,
        'day' => date('D', strtotime($d)),
        'label' => date('D, d M', strtotime($d)),
        'dials' => 0,
        'connected' => 0,
    ];
}
$fromTrend = date('Y-m-d', strtotime('-6 days'));
try {
    $tRes = mysqli_query($link, "
        SELECT DATE(COALESCE(call_time, created_at)) as call_date,
               COUNT(*) as total_dials,
               SUM(CASE WHEN call_duration > 0 OR LOWER(call_status) IN ('connected', 'interested', 'lead', 'enquiry', 'callback / busy', 'follow-up') THEN 1 ELSE 0 END) as connected_calls
        FROM $tblCallLogs
        WHERE COALESCE(call_time, created_at) >= '$fromTrend'
        GROUP BY call_date
        ORDER BY call_date ASC
    ");
    if ($tRes) {
        while ($tr = mysqli_fetch_assoc($tRes)) {
            if (isset($trendDays[$tr['call_date']])) {
                $trendDays[$tr['call_date']]['dials'] = (int)$tr['total_dials'];
                $trendDays[$tr['call_date']]['connected'] = (int)$tr['connected_calls'];
            }
        }
    }
} catch (\Throwable $e) {}

$chartLabels = array_map(function($item) { return $item['day']; }, array_values($trendDays));
$chartDials = array_map(function($item) { return $item['dials']; }, array_values($trendDays));
$chartConnected = array_map(function($item) { return $item['connected']; }, array_values($trendDays));

$dispFresh = $unused_numbers;
$dispInterested = 0;
$dispConnected = 0;
$dispFollowup = 0;
$dispOther = 0;
try {
    $dispRes = mysqli_query($link, "
        SELECT 
            SUM(CASE WHEN MAINDATABASE_CALL_DIALED_STATUS IN ('Interested', 'Callback', 'Lead') THEN 1 ELSE 0 END) as interested,
            SUM(CASE WHEN MAINDATABASE_CALL_DIALED_STATUS IN ('Connected', 'Sale', 'LOGIN') THEN 1 ELSE 0 END) as connected_sales,
            SUM(CASE WHEN MAINDATABASE_CALL_DIALED_STATUS IN ('Busy', 'Follow Up') THEN 1 ELSE 0 END) as follow_up,
            SUM(CASE WHEN MAINDATABASE_CALL_DIALED_STATUS NOT IN ('Not Called', 'Interested', 'Callback', 'Lead', 'Connected', 'Sale', 'LOGIN', 'Busy', 'Follow Up') 
                     AND MAINDATABASE_CALL_DIALED_STATUS IS NOT NULL 
                     AND MAINDATABASE_CALL_DIALED_STATUS != '' 
                     AND MAINDATABASE_CALL_DIAL_TIME IS NOT NULL THEN 1 ELSE 0 END) as other
        FROM $tblMain m
        WHERE $dashContactScope
    ");
    if ($dispRes && $dispRow = mysqli_fetch_assoc($dispRes)) {
        $dispInterested = (int)($dispRow['interested'] ?? 0);
        $dispConnected  = (int)($dispRow['connected_sales'] ?? 0);
        $dispFollowup   = (int)($dispRow['follow_up'] ?? 0);
        $dispOther      = (int)($dispRow['other'] ?? 0);
    }
} catch (\Throwable $e) {}

$hourlyCalls = array_fill(9, 10, 0); // 09:00 to 18:00
try {
    $hRes = mysqli_query($link, "
        SELECT HOUR(COALESCE(call_time, created_at)) as call_hour, COUNT(*) as cnt
        FROM $tblCallLogs
        WHERE DATE(COALESCE(call_time, created_at)) = CURDATE()
        GROUP BY call_hour
    ");
    if ($hRes) {
        while ($hr = mysqli_fetch_assoc($hRes)) {
            $hInt = (int)$hr['call_hour'];
            if ($hInt >= 9 && $hInt <= 18) {
                $hourlyCalls[$hInt] = (int)$hr['cnt'];
            }
        }
    }
} catch (\Throwable $e) {}
$maxHourlyCall = max(1, max($hourlyCalls));
?>

<link rel="stylesheet" href="<?= (defined('APP_BASE') && APP_BASE !== '' ? APP_BASE . '/' : '') ?>assets/css/dashboard-premium.css?v=<?= file_exists(__DIR__ . '/assets/css/dashboard-premium.css') ? filemtime(__DIR__ . '/assets/css/dashboard-premium.css') : '1.0' ?>">

<div class="dash-container">

    <!-- 1. Dashboard Header -->
    <div class="dash-header-wrap">
        <div class="dash-header-left">
            <div class="dash-header-icon-box">
                <i class="bi bi-speedometer2"></i>
            </div>
            <div>
                <h1 class="dash-header-title">Sales &amp; Telecalling Dashboard</h1>
                <p class="dash-header-desc">Real-time telecalling operations, conversion metrics &amp; database tracking</p>
            </div>
        </div>
        <div class="dash-header-actions">
            <span class="dash-status-pill">
                <span class="dash-dots"><span></span><span></span></span>
                System Operational
            </span>
            <?php if (can('manage_leads')): ?>
            <a href="<?= url('modules/leads/lead_insert.php') ?>" class="dash-btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Add Lead
            </a>
            <?php endif; ?>
            <?php if (can('view_reports')): ?>
            <a href="<?= url('modules/logs/Reports.php') ?>" class="dash-btn-secondary">
                <i class="bi bi-bar-chart"></i> View Reports
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_SESSION['access_denied_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4 border-0 shadow-sm" style="border-radius:14px;" role="alert">
        <i class="bi bi-shield-exclamation me-2"></i> <?= htmlspecialchars($_SESSION['access_denied_message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['access_denied_message']); ?>
    <?php endif; ?>

    <!-- 2. Welcome & Quick Actions Card -->
    <div class="dash-welcome-card">
        <div>
            <h2 class="dash-welcome-title">
                <?= htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') ?>, <span class="dash-welcome-user"><?= htmlspecialchars($_SESSION['name'] ?? 'System Administrator', ENT_QUOTES, 'UTF-8') ?></span>
            </h2>
            <p class="dash-welcome-desc">Welcome to your operational telecalling hub. Today is <?= $todayDateFormatted ?>.</p>
        </div>
        <div class="dash-quick-actions">
            <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="dash-action-btn">
                <i class="bi bi-kanban btn-icon-purple"></i> Pipeline Board
            </a>
            <a href="<?= url('modules/database/data_management_main.php') ?>" class="dash-action-btn">
                <i class="bi bi-database btn-icon-green"></i> Customer Database
            </a>
            <?php if (can('export_data')): ?>
            <a href="<?= url('modules/database/maindatabase_ajax/download_bach.php') ?>" class="dash-action-btn">
                <i class="bi bi-cloud-arrow-down btn-icon-blue"></i> Export Full DB
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 3. KPI Cards — Eight-Column Analytics Grid (4 per row on Desktop) -->
    <div class="dash-kpi-grid">
        <!-- Card 1: Calls Today (Purple) -->
        <div class="dash-kpi-card dash-kpi-purple">
            <div class="dash-kpi-inner">
                <div class="dash-kpi-icon-wrap">
                    <i class="bi bi-telephone-outbound"></i>
                </div>
                <div class="dash-kpi-content">
                    <div class="dash-kpi-label">CALLS TODAY</div>
                    <div class="dash-kpi-value"><?= fmt($today_calls) ?></div>
                    <div class="dash-kpi-pill">Dialed today</div>
                </div>
            </div>
            <svg class="dash-kpi-sparkline" viewBox="0 0 100 24" preserveAspectRatio="none">
                <path d="M0,20 Q25,8 50,16 T100,10" fill="none" stroke="#8B5CF6" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </div>

        <!-- Card 2: Connected (Green) -->
        <div class="dash-kpi-card dash-kpi-green">
            <div class="dash-kpi-inner">
                <div class="dash-kpi-icon-wrap">
                    <i class="bi bi-check2-circle"></i>
                </div>
                <div class="dash-kpi-content">
                    <div class="dash-kpi-label">CONNECTED</div>
                    <div class="dash-kpi-value"><?= fmt($today_connected) ?></div>
                    <div class="dash-kpi-pill"><?= $connect_rate ?>% rate</div>
                </div>
            </div>
            <svg class="dash-kpi-sparkline" viewBox="0 0 100 24" preserveAspectRatio="none">
                <path d="M0,18 Q30,6 60,14 T100,8" fill="none" stroke="#16A34A" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </div>

        <!-- Card 3: Connect Rate (Blue) -->
        <div class="dash-kpi-card dash-kpi-blue">
            <div class="dash-kpi-inner">
                <div class="dash-kpi-icon-wrap">
                    <i class="bi bi-activity"></i>
                </div>
                <div class="dash-kpi-content">
                    <div class="dash-kpi-label">CONNECT RATE</div>
                    <div class="dash-kpi-value"><?= $connect_rate ?>%</div>
                    <div class="progress mt-2" style="height: 5px; border-radius: 9999px; background: #DBEAFE;">
                        <div class="progress-bar" style="width: <?= min(100, $connect_rate) ?>%; background: #2563EB; border-radius: 9999px;"></div>
                    </div>
                </div>
            </div>
            <svg class="dash-kpi-sparkline" viewBox="0 0 100 24" preserveAspectRatio="none">
                <path d="M0,16 Q20,22 45,10 T100,12" fill="none" stroke="#2563EB" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </div>

        <!-- Card 4: Active Callers (Cyan) -->
        <div class="dash-kpi-card dash-kpi-cyan">
            <div class="dash-kpi-inner">
                <div class="dash-kpi-icon-wrap">
                    <i class="bi bi-headset"></i>
                </div>
                <div class="dash-kpi-content">
                    <div class="dash-kpi-label">ACTIVE CALLERS</div>
                    <div class="dash-kpi-value"><?= fmt($active_callers_today) ?></div>
                    <div class="dash-kpi-pill">On duty today</div>
                </div>
            </div>
            <svg class="dash-kpi-sparkline" viewBox="0 0 100 24" preserveAspectRatio="none">
                <path d="M0,16 Q25,6 60,18 T100,10" fill="none" stroke="#0891B2" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </div>

        <!-- Card 5: Hot Leads (Amber) -->
        <div class="dash-kpi-card dash-kpi-amber">
            <div class="dash-kpi-inner">
                <div class="dash-kpi-icon-wrap">
                    <i class="bi bi-star-fill"></i>
                </div>
                <div class="dash-kpi-content">
                    <div class="dash-kpi-label">HOT LEADS</div>
                    <div class="dash-kpi-value"><?= fmt($total_leads) ?></div>
                    <div class="dash-kpi-pill">Interested / Sales</div>
                </div>
            </div>
            <svg class="dash-kpi-sparkline" viewBox="0 0 100 24" preserveAspectRatio="none">
                <path d="M0,22 Q35,8 70,16 T100,6" fill="none" stroke="#F59E0B" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </div>

        <!-- Card 6: Pipeline Leads (Teal) -->
        <div class="dash-kpi-card dash-kpi-teal">
            <div class="dash-kpi-inner">
                <div class="dash-kpi-icon-wrap">
                    <i class="bi bi-kanban"></i>
                </div>
                <div class="dash-kpi-content">
                    <div class="dash-kpi-label">PIPELINE LEADS</div>
                    <div class="dash-kpi-value"><?= fmt($total_pipeline_leads) ?></div>
                    <div class="dash-kpi-pill">Loan applications</div>
                </div>
            </div>
            <svg class="dash-kpi-sparkline" viewBox="0 0 100 24" preserveAspectRatio="none">
                <path d="M0,18 Q35,8 65,16 T100,8" fill="none" stroke="#059669" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </div>

        <!-- Card 7: Fresh Pending (Rose/Red) -->
        <div class="dash-kpi-card dash-kpi-rose">
            <div class="dash-kpi-inner">
                <div class="dash-kpi-icon-wrap">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div class="dash-kpi-content">
                    <div class="dash-kpi-label">FRESH PENDING</div>
                    <div class="dash-kpi-value"><?= fmt($unused_numbers) ?></div>
                    <div class="dash-kpi-pill"><?= $pending_pct ?>% remain</div>
                </div>
            </div>
            <svg class="dash-kpi-sparkline" viewBox="0 0 100 24" preserveAspectRatio="none">
                <path d="M0,14 Q30,22 65,10 T100,16" fill="none" stroke="#F43F5E" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </div>

        <!-- Card 8: Main Database (Violet) -->
        <div class="dash-kpi-card dash-kpi-violet">
            <div class="dash-kpi-inner">
                <div class="dash-kpi-icon-wrap">
                    <i class="bi bi-layers-fill"></i>
                </div>
                <div class="dash-kpi-content">
                    <div class="dash-kpi-label">MAIN DATABASE</div>
                    <div class="dash-kpi-value"><?= fmt($total_numbers) ?></div>
                    <div class="dash-kpi-pill">Customer records</div>
                </div>
            </div>
            <svg class="dash-kpi-sparkline" viewBox="0 0 100 24" preserveAspectRatio="none">
                <path d="M0,16 Q40,6 75,18 T100,8" fill="none" stroke="#7C3AED" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </div>
    </div>

    <!-- 4. Main Analytics Section (Two Columns: 62% Left, 38% Right) -->
    <div class="dash-analytics-row">
        <!-- Left Panel: Call Performance Reports & Analytics (~62%) -->
        <div class="dash-analytics-col-left">
            <div class="dash-reports-panel">
                <div class="dash-reports-topbar-accent"></div>
                <div class="dash-reports-body">
                    <div class="dash-reports-badge-row">
                        <span class="dash-badge-hub">
                            <i class="bi bi-stars"></i> FEATURED REPORTS HUB
                        </span>
                        <span class="dash-badge-preset">
                            Single Date-Range &bull; Preset Analytics
                        </span>
                    </div>
                    <h3 class="dash-reports-title">Call Performance Reports &amp; Analytics</h3>
                    <p class="dash-reports-desc">
                        Analyze telecaller team performance, connected ratios, disposition breakdowns (Connected, Interested, Follow-up, DND, Other), call durations, and Excel exports.
                    </p>
                    <div class="dash-reports-actions">
                        <a href="<?= url('modules/logs/Reports.php') ?>" class="dash-btn-primary">
                            <i class="bi bi-box-arrow-up-right"></i> Open Performance Reports
                        </a>
                        <a href="<?= url('modules/logs/Reports.php?range=today') ?>" class="dash-btn-secondary">
                            <i class="bi bi-calendar-event text-primary"></i> Today's Report
                        </a>
                        <a href="<?= url('modules/logs/Reports.php?range=yesterday') ?>" class="dash-btn-secondary">
                            <i class="bi bi-calendar-minus text-muted"></i> Yesterday
                        </a>
                        <a href="<?= url('modules/logs/Reports.php?range=this_month') ?>" class="dash-btn-secondary">
                            <i class="bi bi-calendar-check text-success"></i> This Month
                        </a>
                        <a href="<?= url('modules/logs/Reports.php?range=last30') ?>" class="dash-btn-secondary">
                            <i class="bi bi-calendar-range text-info"></i> Last 30 Days
                        </a>
                    </div>

                    <!-- Charts Division -->
                    <div class="dash-charts-grid">
                        <!-- Chart A: 7-Day Call Volume & Connected Trend -->
                        <div class="dash-chart-card dash-chart-card-trend">
                            <div class="dash-chart-card-header">
                                <span class="dash-chart-card-title text-truncate">
                                    <i class="bi bi-graph-up text-primary"></i> Call Volume
                                </span>
                                <span class="dash-chart-card-sub text-nowrap">Last 7 Days</span>
                            </div>
                            <div class="dash-chart-canvas-wrap">
                                <canvas id="dashCallTrendChart"></canvas>
                            </div>
                        </div>

                        <!-- Chart B: Lead & Contact Disposition Breakdown -->
                        <div class="dash-chart-card dash-chart-card-disp">
                            <div class="dash-chart-card-header">
                                <span class="dash-chart-card-title text-truncate">
                                    <i class="bi bi-pie-chart text-success"></i> Dispositions
                                </span>
                                <span class="dash-chart-card-sub text-nowrap">Database</span>
                            </div>
                            <div class="dash-chart-canvas-wrap">
                                <canvas id="dashDispositionChart"></canvas>
                                <div class="dash-donut-center">
                                    <div class="dash-donut-center-num"><?= fmt($total_numbers) ?></div>
                                    <div class="dash-donut-center-lbl">Records</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Panel: Real-Time Calling Snapshot (~38%) -->
        <div class="dash-analytics-col-right">
            <div class="dash-snapshot-panel">
                <div>
                    <div class="dash-snapshot-header">
                        <h4 class="dash-snapshot-title">
                            <i class="bi bi-telephone-inbound text-primary"></i>
                            Real-Time Calling Snapshot
                        </h4>
                        <a href="<?= url('modules/logs/call_history.php') ?>" class="dash-snapshot-link">
                            Call Audio &amp; Logs <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>

                    <!-- 3 Metric Boxes -->
                    <div class="dash-snapshot-metrics">
                        <div class="dash-metric-box dash-metric-box-dialed">
                            <div class="dash-metric-box-lbl">Dialed</div>
                            <div class="dash-metric-box-val"><?= fmt($today_calls) ?></div>
                        </div>
                        <div class="dash-metric-box dash-metric-box-connected">
                            <div class="dash-metric-box-lbl">Connected</div>
                            <div class="dash-metric-box-val text-green"><?= fmt($today_connected) ?></div>
                        </div>
                        <div class="dash-metric-box dash-metric-box-rate">
                            <div class="dash-metric-box-lbl">Connect Rate</div>
                            <div class="dash-metric-box-val text-purple"><?= $connect_rate ?>%</div>
                        </div>
                    </div>

                    <!-- Quick Links -->
                    <div class="dash-snapshot-links">
                        <a href="<?= url('modules/logs/call_history.php') ?>" class="dash-sub-link-card">
                            <div class="link-lead">
                                <i class="bi bi-telephone-inbound" style="color:var(--dash-primary-purple);"></i>
                                <span>Real-time Call Logs</span>
                            </div>
                            <i class="bi bi-chevron-right text-muted small"></i>
                        </a>
                        <?php if (can('view_activity')): ?>
                        <a href="<?= url('modules/logs/manage_activity.php') ?>" class="dash-sub-link-card">
                            <div class="link-lead">
                                <i class="bi bi-clock-history" style="color:#0284C7;"></i>
                                <span>System Audit Trail</span>
                            </div>
                            <i class="bi bi-chevron-right text-muted small"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Bottom Visualization: Hourly Calling Activity -->
                <div class="dash-activity-widget">
                    <div class="dash-activity-widget-header">
                        <span><i class="bi bi-bar-chart-steps me-1"></i> Hourly Fleet Activity (Today)</span>
                        <span class="badge bg-white text-muted font-mono border" style="font-size:10px;">09:00 - 18:00</span>
                    </div>
                    <?php if ($today_calls > 0): ?>
                    <div class="dash-waveform-row" title="Hourly dialed calls today">
                        <?php for ($h = 9; $h <= 18; $h++): 
                            $c = $hourlyCalls[$h] ?? 0;
                            $pct = min(100, max(12, round(($c / $maxHourlyCall) * 100)));
                            $isActive = $c > 0;
                        ?>
                        <div class="dash-waveform-bar <?= $isActive ? 'active' : '' ?>" style="height:<?= $pct ?>%;" title="<?= sprintf('%02d:00', $h) ?>: <?= $c ?> calls"></div>
                        <?php endfor; ?>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2 text-muted" style="font-size:10px;">
                        <span>09:00</span>
                        <span>12:00</span>
                        <span>15:00</span>
                        <span>18:00</span>
                    </div>
                    <?php else: ?>
                    <div class="dash-activity-standby">
                        <i class="bi bi-headset text-primary fs-4 d-block mb-1"></i>
                        <div class="text-dark fw-bold" style="font-size:12px;">Fleet Standby</div>
                        <div class="text-muted" style="font-size:11px;">Waiting for telecaller outbound calls</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 5. Operational Fleet Activity & Capacity Status -->
    <div class="dash-fleet-card">
        <div class="dash-section-header">
            <h4 class="dash-section-title">
                <i class="bi bi-people-fill text-primary"></i>
                Telecaller Fleet Status &amp; Capacity
            </h4>
            <span class="badge bg-primary font-mono" style="border-radius:var(--dash-radius-pill); padding: 5px 12px;">
                <?= fmt($active_callers_today) ?> Active Callers Today
            </span>
        </div>
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="dash-fleet-box-1">
                    <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:11px; letter-spacing:0.04em;">Fleet Connect Ratio</div>
                    <div class="d-flex align-items-baseline gap-2 mb-2">
                        <span class="fs-4 fw-bold text-dark font-mono"><?= $connect_rate ?>%</span>
                        <span class="badge bg-success font-mono"><?= fmt($today_connected) ?> / <?= fmt($today_calls) ?> Connected</span>
                    </div>
                    <div class="progress" style="height:6px; border-radius:9999px; background:#DCFCE7;">
                        <div class="progress-bar bg-success" style="width:<?= min(100, $connect_rate) ?>%; border-radius:9999px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="dash-fleet-box-2">
                    <div class="text-muted small text-uppercase fw-semibold mb-1" style="font-size:11px; letter-spacing:0.04em;">Calling Queue Capacity</div>
                    <div class="d-flex align-items-baseline gap-2 mb-2">
                        <span class="fs-4 fw-bold text-dark font-mono"><?= fmt($unused_numbers) ?></span>
                        <span class="badge bg-secondary font-mono"><?= $pending_pct ?>% Pending Dialing</span>
                    </div>
                    <div class="progress" style="height:6px; border-radius:9999px; background:#EDE9FE;">
                        <div class="progress-bar" style="width:<?= min(100, 100 - $pending_pct) ?>%; background:var(--dash-primary-purple); border-radius:9999px;"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-between align-items-center pt-3 mt-3 border-top flex-wrap gap-2">
            <span class="text-muted small" style="font-size:12px;">
                <i class="bi bi-shield-check text-success me-1"></i>3-Month cool-off &amp; FIFO queue active across telecallers.
            </span>
            <a href="<?= url('modules/logs/Reports.php') ?>" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
                View Detailed Reports <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    </div>

    <!-- 6. Comprehensive CRM Sections & Module Shortcuts -->
    <div class="mb-4">
        <div class="dash-section-header">
            <div>
                <h4 class="dash-section-title">
                    <i class="bi bi-grid-3x3-gap-fill text-primary"></i>
                    All CRM Sections &amp; Module Shortcuts
                </h4>
                <div class="text-muted small" style="font-size:12px;">Direct single-click navigation across all departments</div>
            </div>
        </div>

        <div class="dash-section-grid">
            <!-- Section 1: Activities & Reports -->
            <?php if (can('view_reports') || can('view_activity')): ?>
            <div class="dash-module-card dash-mod-reports">
                <div class="dash-module-header">
                    <div class="dash-module-lead">
                        <div class="dash-module-icon" style="background:#F0ECFF; color:var(--dash-primary-purple);">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div>
                            <div class="dash-module-name">Reports &amp; Analytics</div>
                            <div class="dash-module-sub">Calling metrics, audio &amp; logs</div>
                        </div>
                    </div>
                    <span class="badge bg-primary" style="font-size:10px;">Reports Hub</span>
                </div>
                <div class="dash-module-links">
                    <?php if (can('view_reports')): ?>
                    <a href="<?= url('modules/logs/Reports.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-graph-up link-icon"></i>
                            <div>
                                <div class="link-title">Call Performance Reports</div>
                                <div class="link-desc">Disposition summary &amp; trends</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/logs/Reports.php?range=today') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-calendar-event link-icon"></i>
                            <div>
                                <div class="link-title">Today's Performance Report</div>
                                <div class="link-desc">Real-time daily dials &amp; conversion</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/logs/call_history.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-telephone-inbound link-icon"></i>
                            <div>
                                <div class="link-title">Call History &amp; Recordings</div>
                                <div class="link-desc">Audio playback &amp; duration logs</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (can('view_activity')): ?>
                    <a href="<?= url('modules/logs/manage_activity.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-clock-history link-icon"></i>
                            <div>
                                <div class="link-title">System Audit Trail</div>
                                <div class="link-desc">Security &amp; user modification logs</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Section 2: Leads & Sales Pipeline -->
            <div class="dash-module-card dash-mod-pipeline">
                <div class="dash-module-header">
                    <div class="dash-module-lead">
                        <div class="dash-module-icon" style="background:#DBEAFE; color:var(--dash-electric-blue);">
                            <i class="bi bi-kanban"></i>
                        </div>
                        <div>
                            <div class="dash-module-name">Leads &amp; Sales Pipeline</div>
                            <div class="dash-module-sub">Funnel stages &amp; loan processing</div>
                        </div>
                    </div>
                    <span class="badge bg-info" style="font-size:10px;">Pipeline</span>
                </div>
                <div class="dash-module-links">
                    <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-kanban link-icon"></i>
                            <div>
                                <div class="link-title">Pipeline Kanban Board</div>
                                <div class="link-desc">Drag &amp; drop loan pipeline stages</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/leads/lead_list.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-person-lines-fill link-icon"></i>
                            <div>
                                <div class="link-title">View Leads Register</div>
                                <div class="link-desc">Search, filter &amp; edit customer leads</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/leads/leads_dashboard.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-bar-chart-line link-icon"></i>
                            <div>
                                <div class="link-title">Leads Analytics &amp; Operations</div>
                                <div class="link-desc">Pipeline velocity &amp; funnel stats</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php if (can('manage_leads')): ?>
                    <a href="<?= url('modules/leads/lead_insert.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-plus-circle link-icon"></i>
                            <div>
                                <div class="link-title">+ Add New Lead</div>
                                <div class="link-desc">Intake new loan application</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 3: Data Management & Calling Queue -->
            <?php if (can('manage_database')): ?>
            <div class="dash-module-card dash-mod-database">
                <div class="dash-module-header">
                    <div class="dash-module-lead">
                        <div class="dash-module-icon" style="background:#DCFCE7; color:var(--dash-success-green);">
                            <i class="bi bi-database"></i>
                        </div>
                        <div>
                            <div class="dash-module-name">Data Management &amp; Queue</div>
                            <div class="dash-module-sub">Records, staging &amp; DND controls</div>
                        </div>
                    </div>
                    <span class="badge bg-success" style="font-size:10px;">Data Hub</span>
                </div>
                <div class="dash-module-links">
                    <a href="<?= url('modules/database/data_management_main.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-database link-icon"></i>
                            <div>
                                <div class="link-title">Main Customer Database</div>
                                <div class="link-desc">Full customer records &amp; history</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/database/data_management_temporary.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-table link-icon"></i>
                            <div>
                                <div class="link-title">Temporary Calling Database</div>
                                <div class="link-desc">Staging records for mobile dialing</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/database/upload_data.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-cloud-arrow-up link-icon"></i>
                            <div>
                                <div class="link-title">Upload Calling CSV Data</div>
                                <div class="link-desc">Batch import into Temporary or Main DB</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/database/ViewDND.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-slash-circle link-icon"></i>
                            <div>
                                <div class="link-title">DND Numbers Registry</div>
                                <div class="link-desc">Do Not Call compliance protection</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Section 4: Users & Teams Management -->
            <?php if (can('manage_users') || can('manage_teams')): ?>
            <div class="dash-module-card dash-mod-users">
                <div class="dash-module-header">
                    <div class="dash-module-lead">
                        <div class="dash-module-icon" style="background:#FEF3C7; color:var(--dash-warning-amber);">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <div>
                            <div class="dash-module-name">Users &amp; Team Management</div>
                            <div class="dash-module-sub">Roster, supervisors &amp; assignments</div>
                        </div>
                    </div>
                    <span class="badge bg-warning text-dark" style="font-size:10px;">Fleet Admin</span>
                </div>
                <div class="dash-module-links">
                    <?php if (can('manage_users')): ?>
                    <a href="<?= url('modules/users/users_view.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-people link-icon"></i>
                            <div>
                                <div class="link-title">User Directory &amp; Accounts</div>
                                <div class="link-desc">Manage telecallers, officers &amp; roles</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/users/users_add.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-person-plus link-icon"></i>
                            <div>
                                <div class="link-title">+ Register New User</div>
                                <div class="link-desc">Create telecaller account with team</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (can('manage_teams')): ?>
                    <a href="<?= url('modules/users/teams_dashboard.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-diagram-3 link-icon"></i>
                            <div>
                                <div class="link-title">Teams Dashboard</div>
                                <div class="link-desc">Department teams &amp; targets</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/users/team_members.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-person-check link-icon"></i>
                            <div>
                                <div class="link-title">Team Member Assignments</div>
                                <div class="link-desc">Allocate telecallers to supervisors</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Section 5: Settings & Administration -->
            <?php if (can('manage_settings') || isSuperAdmin()): ?>
            <div class="dash-module-card dash-mod-settings">
                <div class="dash-module-header">
                    <div class="dash-module-lead">
                        <div class="dash-module-icon" style="background:#F1F5F9; color:#475569;">
                            <i class="bi bi-gear-fill"></i>
                        </div>
                        <div>
                            <div class="dash-module-name">Settings &amp; Administration</div>
                            <div class="dash-module-sub">Permissions, limits &amp; system config</div>
                        </div>
                    </div>
                    <span class="badge bg-secondary" style="font-size:10px;">System</span>
                </div>
                <div class="dash-module-links">
                    <?php if (isAdmin() || isSuperAdmin()): ?>
                    <a href="<?= url('modules/settings/permissions_manager.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-shield-lock link-icon"></i>
                            <div>
                                <div class="link-title">Access Control Matrix (RBAC)</div>
                                <div class="link-desc">Role permissions &amp; user overrides</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/settings/settings.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-gear link-icon"></i>
                            <div>
                                <div class="link-title">System Settings</div>
                                <div class="link-desc">Branding, logo &amp; general options</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php if (can('manage_api')): ?>
                    <a href="<?= url('modules/settings/api_settings.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-key link-icon"></i>
                            <div>
                                <div class="link-title">Mobile API Tokens</div>
                                <div class="link-desc">Authenticate mobile app &amp; endpoints</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (isSuperAdmin()): ?>
                    <a href="<?= url('modules/settings/super_admin_panel.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-shield-shaded link-icon"></i>
                            <div>
                                <div class="link-title">Super Admin Panel</div>
                                <div class="link-desc">Tenant plan limits &amp; user quotas</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Section 6: Workspace & Profile -->
            <div class="dash-module-card dash-mod-profile">
                <div class="dash-module-header">
                    <div class="dash-module-lead">
                        <div class="dash-module-icon" style="background:#F0ECFF; color:var(--dash-primary-purple);">
                            <i class="bi bi-person-badge-fill"></i>
                        </div>
                        <div>
                            <div class="dash-module-name">My Workspace &amp; Profile</div>
                            <div class="dash-module-sub">Profile, avatar &amp; security</div>
                        </div>
                    </div>
                    <span class="badge bg-purple" style="font-size:10px;">Account</span>
                </div>
                <div class="dash-module-links">
                    <a href="<?= url('profile.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-person-circle link-icon"></i>
                            <div>
                                <div class="link-title">My Profile &amp; Avatar</div>
                                <div class="link-desc">Update display name &amp; photo</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('profile.php#password') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-shield-check link-icon"></i>
                            <div>
                                <div class="link-title">Security &amp; Password</div>
                                <div class="link-desc">Update account password</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('dashboard.php') ?>" class="dash-module-link-item">
                        <div class="link-info">
                            <i class="bi bi-speedometer2 link-icon"></i>
                            <div>
                                <div class="link-title">Dashboard Home</div>
                                <div class="link-desc">Refresh real-time telecalling KPI</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('logout.php') ?>" class="dash-module-link-item text-danger">
                        <div class="link-info">
                            <i class="bi bi-box-arrow-right link-icon text-danger"></i>
                            <div>
                                <div class="link-title text-danger">Log Out</div>
                                <div class="link-desc">Securely terminate active session</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow text-danger"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Chart.js Vendor Script -->
<script src="<?= vnd('js/chart.umd.min.js') ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Call Volume & Connected Trend Chart (Area Chart)
    const trendCtx = document.getElementById('dashCallTrendChart');
    if (trendCtx) {
        const trendLabels = <?= json_encode($chartLabels) ?>;
        const dialsData = <?= json_encode($chartDials) ?>;
        const connectedData = <?= json_encode($chartConnected) ?>;

        const ctx = trendCtx.getContext('2d');
        const purpleGradient = ctx.createLinearGradient(0, 0, 0, 180);
        purpleGradient.addColorStop(0, 'rgba(109, 59, 255, 0.22)');
        purpleGradient.addColorStop(1, 'rgba(109, 59, 255, 0.00)');

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [
                    {
                        label: 'Calls Dialed',
                        data: dialsData,
                        borderColor: '#6D3BFF',
                        backgroundColor: purpleGradient,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.38,
                        pointBackgroundColor: '#FFFFFF',
                        pointBorderColor: '#6D3BFF',
                        pointBorderWidth: 2,
                        pointRadius: 3.5,
                        pointHoverRadius: 5
                    },
                    {
                        label: 'Connected',
                        data: connectedData,
                        borderColor: '#2563EB',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [4, 4],
                        tension: 0.38,
                        pointBackgroundColor: '#FFFFFF',
                        pointBorderColor: '#2563EB',
                        pointBorderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 4.5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: {
                            boxWidth: 10,
                            boxHeight: 10,
                            usePointStyle: true,
                            font: { size: 10.5, family: 'Inter', weight: '600' },
                            color: '#64748B'
                        }
                    },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        titleFont: { size: 11, family: 'Inter', weight: '700' },
                        bodyFont: { size: 11, family: 'Inter' },
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: true
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { size: 10.5, family: 'Inter' },
                            color: '#94A3B8'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#F1F5F9' },
                        ticks: {
                            font: { size: 10, family: 'JetBrains Mono' },
                            color: '#94A3B8',
                            precision: 0
                        }
                    }
                }
            }
        });
    }

    // 2. Database Dispositions Donut Chart
    const dispCtx = document.getElementById('dashDispositionChart');
    if (dispCtx) {
        const freshCount = <?= (int)$dispFresh ?>;
        const interestedCount = <?= (int)$dispInterested ?>;
        const connectedCount = <?= (int)$dispConnected ?>;
        const followupCount = <?= (int)$dispFollowup ?>;
        const otherCount = <?= (int)$dispOther ?>;

        new Chart(dispCtx, {
            type: 'doughnut',
            data: {
                labels: ['Fresh Pending', 'Interested', 'Connected', 'Follow-up', 'Other'],
                datasets: [{
                    data: [freshCount, interestedCount, connectedCount, followupCount, otherCount],
                    backgroundColor: [
                        '#8B5CF6', // Fresh (Violet)
                        '#F59E0B', // Interested (Amber)
                        '#16A34A', // Connected (Green)
                        '#2563EB', // Follow-up (Blue)
                        '#94A3B8'  // Other (Slate)
                    ],
                    borderWidth: 2,
                    borderColor: '#FFFFFF',
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '74%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        titleFont: { size: 11, family: 'Inter', weight: '700' },
                        bodyFont: { size: 11, family: 'Inter' },
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const val = context.raw || 0;
                                const pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                                return ' ' + context.label + ': ' + Number(val).toLocaleString() + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php include 'php_scripts/footer.php'; ?>
