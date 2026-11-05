<?php
require_once "php_scripts/auth.php";

$pageTitle = 'Dashboard';
include 'php_scripts/header.php';

// === ALL DATA WITH NULL-SAFE FALLBACKS ===
$today_calls = $today_connected = $connect_rate = 0;
$total_leads = $total_numbers = $unused_numbers = $active_callers_today = 0;

function fmt($num) {
    return number_format((int)$num);
}

$tblMain = tn('TBL_MAIN');
$userRole = USER_ROLE;
$userId = (int)USER_ID;
$userTeamId = defined('USER_TEAM_ID') ? (int)USER_TEAM_ID : null;

$dashContactScope = getHierarchyContactFilterSQL($link, $userId, $userRole, 'm', $userTeamId);
$dashUserScope = getHierarchyUserFilterSQL($link, $userId, $userRole, 'm', 'MAINDATABASE_CALL_DIALED_USER', $userTeamId);

// 1. Today's Calls & Connected
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
} catch (\Throwable $e) { $row = ['total_calls' => 0, 'connected' => 0]; }
$today_calls     = (int)($row['total_calls'] ?? 0);
$today_connected = (int)($row['connected'] ?? 0);
$connect_rate    = $today_calls > 0 ? round(($today_connected / $today_calls) * 100, 1) : 0;

// 2. Total Hot Leads / Sales
try {
    $res2 = mysqli_query($link, "
        SELECT COUNT(*) as leads 
        FROM $tblMain m
        WHERE MAINDATABASE_CALL_DIALED_STATUS IN ('Connected','Interested','Follow Up','Sale','Callback')
          AND $dashContactScope
    ");
    $row2 = $res2 ? mysqli_fetch_assoc($res2) : ['leads' => 0];
} catch (\Throwable $e) { $row2 = ['leads' => 0]; }
$total_leads = (int)($row2['leads'] ?? 0);

// 3. Active Callers Today
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
} catch (\Throwable $e) { $row3 = ['cnt' => 0]; }
$active_callers_today = (int)($row3['cnt'] ?? 0);

// 4. Total Records
try {
    $res4 = mysqli_query($link, "SELECT COUNT(*) as total FROM $tblMain m WHERE $dashContactScope");
    $row4 = $res4 ? mysqli_fetch_assoc($res4) : ['total' => 0];
} catch (\Throwable $e) { $row4 = ['total' => 0]; }
$total_numbers = (int)($row4['total'] ?? 0);

// 5. Fresh / Not Called Numbers
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
} catch (\Throwable $e) { $row5 = ['unused' => 0]; }
$unused_numbers = (int)($row5['unused'] ?? 0);

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
$pending_pct = $total_numbers > 0 ? round(($unused_numbers / $total_numbers) * 100, 1) : 0;
?>

<div class="crm-module-header">
    <div>
        <div class="crm-module-header-title">
            <i class="bi bi-speedometer2 text-primary fs-3"></i>
            <div>
                <h1 class="mb-0">Sales & Telecalling Dashboard</h1>
                <p class="crm-module-header-desc">Real-time telecalling operations, conversion metrics & database tracking</p>
            </div>
        </div>
    </div>
    <div class="crm-module-header-actions">
        <span class="status-pill success"><i class="bi bi-circle-fill"></i> System Operational</span>
        <?php if (can('manage_leads')): ?>
        <a href="<?= url('modules/leads/lead_insert.php') ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> + Add Lead
        </a>
        <?php endif; ?>
        <?php if (can('view_reports')): ?>
        <a href="<?= url('modules/logs/Reports.php') ?>" class="btn btn-secondary btn-sm">
            <i class="bi bi-bar-chart"></i> View Reports
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($_SESSION['access_denied_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-shield-exclamation me-1"></i> <?= htmlspecialchars($_SESSION['access_denied_message'], ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['access_denied_message']); ?>
<?php endif; ?>

<!-- Welcome Strip (Soft Ambient Wash) -->
<div class="card border-0 flux-brand-wash p-4 mb-4" style="border-radius:var(--radius-lg); box-shadow:var(--shadow-card);">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h2 class="h3 fw-bold text-dark mb-1"><?= htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') ?>, <span class="flux-gradient-text"><?= htmlspecialchars($_SESSION['name'] ?? 'Team', ENT_QUOTES, 'UTF-8') ?></span></h2>
            <p class="text-secondary mb-0 small">Welcome to your operational telecalling hub. Today is <?= date('l, d F Y') ?>.</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-kanban text-primary"></i> Pipeline Board
            </a>
            <a href="<?= url('modules/database/data_management_main.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-database text-success"></i> Customer Database
            </a>
            <?php if (can('export_data')): ?>
            <a href="<?= url('modules/database/maindatabase_ajax/download_bach.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-cloud-arrow-down text-info"></i> Export Full DB
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Primary KPI Metrics Grid (KPI Cards with Tabular Numerals) -->
<div class="row g-3 mb-4">
    <div class="col-xxl-2 col-xl-4 col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon"><i class="bi bi-telephone-outbound"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Calls Today</div>
                <div class="metric-card-value font-mono"><?= fmt($today_calls) ?></div>
                <div class="metric-card-sub"><span class="badge bg-primary">Dialed today</span></div>
            </div>
        </div>
    </div>
    <div class="col-xxl-2 col-xl-4 col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon success"><i class="bi bi-check2-circle"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Connected</div>
                <div class="metric-card-value font-mono"><?= fmt($today_connected) ?></div>
                <div class="metric-card-sub"><span class="badge bg-success"><?= $connect_rate ?>% rate</span></div>
            </div>
        </div>
    </div>
    <div class="col-xxl-2 col-xl-4 col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon info"><i class="bi bi-activity"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Connect Rate</div>
                <div class="metric-card-value font-mono"><?= $connect_rate ?>%</div>
                <div class="progress mt-2" style="height:4px; border-radius:9999px;">
                    <div class="progress-bar bg-info" style="width:<?= min(100, $connect_rate) ?>%"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xxl-2 col-xl-4 col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon warning"><i class="bi bi-star-fill"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Hot Leads</div>
                <div class="metric-card-value font-mono"><?= fmt($total_leads) ?></div>
                <div class="metric-card-sub"><span class="badge bg-warning">Interested / Sales</span></div>
            </div>
        </div>
    </div>
    <div class="col-xxl-2 col-xl-4 col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon danger"><i class="bi bi-clock-history"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Fresh Pending</div>
                <div class="metric-card-value font-mono"><?= fmt($unused_numbers) ?></div>
                <div class="metric-card-sub"><span class="badge bg-danger"><?= $pending_pct ?>% remain</span></div>
            </div>
        </div>
    </div>
    <div class="col-xxl-2 col-xl-4 col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon purple"><i class="bi bi-database"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Main Database</div>
                <div class="metric-card-value font-mono"><?= fmt($total_numbers) ?></div>
                <div class="metric-card-sub"><span class="badge bg-purple">Customer records</span></div>
            </div>
        </div>
    </div>
</div>

<!-- Featured Calling Reports & Analytics Spotlight (User High Priority Focus) -->
<?php if (can('view_reports')): ?>
<div class="dashboard-reports-spotlight p-3 p-md-4 mb-4">
    <div class="row align-items-center g-3">
        <div class="col-xl-7 col-lg-6">
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="badge bg-primary px-2.5 py-1" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                    <i class="bi bi-stars me-1"></i> FEATURED REPORTS HUB
                </span>
                <span class="badge bg-purple px-2 py-1" style="font-size: 0.72rem;">
                    Single Date-Range &bull; Preset Analytics
                </span>
            </div>
            <h3 class="fw-bold text-dark mb-1 d-flex align-items-center gap-2">
                <span>Call Performance Reports &amp; Analytics</span>
            </h3>
            <p class="text-secondary mb-3 small" style="max-width: 620px;">
                Analyze telecaller team performance, connected ratios, disposition breakdowns (Connected, Interested, Follow-up, DND, Other), call durations, and Excel exports.
            </p>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="<?= url('modules/logs/Reports.php') ?>" class="btn btn-primary btn-sm px-3">
                    <i class="bi bi-graph-up-arrow me-1"></i> Open Performance Reports
                </a>
                <a href="<?= url('modules/logs/Reports.php?range=today') ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-calendar-event me-1"></i> Today's Report
                </a>
                <a href="<?= url('modules/logs/Reports.php?range=yesterday') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-calendar-minus me-1"></i> Yesterday
                </a>
                <a href="<?= url('modules/logs/Reports.php?range=this_month') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-calendar-check me-1"></i> This Month
                </a>
                <a href="<?= url('modules/logs/Reports.php?range=last30') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-calendar-range me-1"></i> Last 30 Days
                </a>
            </div>
        </div>
        <div class="col-xl-5 col-lg-6">
            <div class="p-3 bg-white border rounded shadow-xs" style="border-radius: var(--radius-md);">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fs-xs fw-bold text-uppercase text-muted">Real-time Calling Snapshot</span>
                    <a href="<?= url('modules/logs/call_history.php') ?>" class="small text-primary fw-semibold text-decoration-none">
                        Call Audio &amp; Logs <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <div class="p-2 border rounded text-center bg-light">
                            <div class="fs-xs text-muted">Dialed</div>
                            <div class="fs-5 fw-bold text-dark font-mono"><?= fmt($today_calls) ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-2 border rounded text-center bg-light">
                            <div class="fs-xs text-muted">Connected</div>
                            <div class="fs-5 fw-bold text-success font-mono"><?= fmt($today_connected) ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-2 border rounded text-center bg-light">
                            <div class="fs-xs text-muted">Connect Rate</div>
                            <div class="fs-5 fw-bold text-primary font-mono"><?= $connect_rate ?>%</div>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                    <a href="<?= url('modules/logs/call_history.php') ?>" class="btn btn-outline-dark btn-sm py-1 px-2 fs-xs">
                        <i class="bi bi-telephone-inbound me-1 text-primary"></i> Real-time Call Logs
                    </a>
                    <?php if (can('view_activity')): ?>
                    <a href="<?= url('modules/logs/manage_activity.php') ?>" class="btn btn-outline-dark btn-sm py-1 px-2 fs-xs">
                        <i class="bi bi-clock-history me-1 text-info"></i> System Audit Trail
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Operational Fleet Activity & Capacity Status -->
<div class="row g-3 mb-4">
    <!-- Left: Telecaller Fleet Status -->
    <div class="col-lg-7">
        <div class="card h-100 mb-0">
            <div class="card-header">
                <span class="fw-bold"><i class="bi bi-people-fill text-primary me-2"></i>Telecaller Fleet Status &amp; Performance</span>
                <span class="badge bg-primary font-mono"><?= fmt($active_callers_today) ?> Active Callers Today</span>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <div class="p-3 border rounded bg-subtle" style="border-radius:var(--radius-md);">
                            <div class="text-muted small text-uppercase fw-semibold mb-1">Fleet Connect Ratio</div>
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="fs-4 fw-bold text-dark font-mono"><?= $connect_rate ?>%</span>
                                <span class="badge bg-success font-mono"><?= fmt($today_connected) ?> / <?= fmt($today_calls) ?></span>
                            </div>
                            <div class="progress mt-2" style="height:6px; border-radius:9999px;">
                                <div class="progress-bar bg-success" style="width:<?= min(100, $connect_rate) ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border rounded bg-subtle" style="border-radius:var(--radius-md);">
                            <div class="text-muted small text-uppercase fw-semibold mb-1">Calling Queue Capacity</div>
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="fs-4 fw-bold text-dark font-mono"><?= fmt($unused_numbers) ?></span>
                                <span class="badge bg-secondary">Pending Dialing</span>
                            </div>
                            <div class="progress mt-2" style="height:6px; border-radius:9999px;">
                                <div class="progress-bar bg-primary" style="width:<?= min(100, 100 - $pending_pct) ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-2 border-top flex-wrap gap-2">
                    <span class="text-muted small"><i class="bi bi-shield-check text-success me-1"></i>3-Month cool-off &amp; lease-based FIFO queue active across mobile callers.</span>
                    <a href="<?= url('modules/logs/Reports.php') ?>" class="btn btn-sm btn-outline-primary">
                        View Detailed Reports <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Operational Queue & Fast Triggers -->
    <div class="col-lg-5">
        <div class="card h-100 mb-0">
            <div class="card-header">
                <span class="fw-bold"><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Quick Operational Triggers</span>
                <span class="text-muted small">Fast Access</span>
            </div>
            <div class="card-body p-2">
                <div class="list-group list-group-flush gap-1">
                    <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between px-3 py-2 border-0 rounded">
                        <div class="d-flex align-items-center gap-3">
                            <span class="metric-card-icon sm bg-primary text-white"><i class="bi bi-kanban"></i></span>
                            <div>
                                <div class="fw-semibold text-dark">Lead Pipeline Board</div>
                                <div class="text-muted small">Manage stages: Lead, Follow-up, UW, Sanctioned</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-muted small"></i>
                    </a>

                    <a href="<?= url('modules/leads/lead_list.php') ?>" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between px-3 py-2 border-0 rounded">
                        <div class="d-flex align-items-center gap-3">
                            <span class="metric-card-icon sm bg-info text-white"><i class="bi bi-person-lines-fill"></i></span>
                            <div>
                                <div class="fw-semibold text-dark">Leads Register</div>
                                <div class="text-muted small">Search, filter and edit customer loan leads</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-muted small"></i>
                    </a>

                    <?php if (can('manage_database')): ?>
                    <a href="<?= url('modules/database/upload_data.php') ?>" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between px-3 py-2 border-0 rounded">
                        <div class="d-flex align-items-center gap-3">
                            <span class="metric-card-icon sm bg-success text-white"><i class="bi bi-cloud-arrow-up"></i></span>
                            <div>
                                <div class="fw-semibold text-dark">Upload Calling Data</div>
                                <div class="text-muted small">Import records into Temporary or Main DB</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-muted small"></i>
                    </a>
                    <?php endif; ?>

                    <?php if (can('view_reports')): ?>
                    <a href="<?= url('modules/logs/call_history.php') ?>" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between px-3 py-2 border-0 rounded">
                        <div class="d-flex align-items-center gap-3">
                            <span class="metric-card-icon sm bg-warning text-dark"><i class="bi bi-telephone-inbound"></i></span>
                            <div>
                                <div class="fw-semibold text-dark">Call History Logs</div>
                                <div class="text-muted small">Review telecaller recordings, duration &amp; outcomes</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-muted small"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Comprehensive All Sections & Operational Modules Directory -->
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-grid-3x3-gap-fill text-primary fs-5"></i>
            <h4 class="fw-bold mb-0 text-dark">All CRM Sections &amp; Module Shortcuts</h4>
        </div>
        <span class="text-muted small">Direct single-click navigation across all departments</span>
    </div>

    <div class="row g-3">
        <!-- Section 1: Activities & Reports (Highlighted) -->
        <?php if (can('view_reports') || can('view_activity')): ?>
        <div class="col-xxl-4 col-md-6">
            <div class="dashboard-section-card">
                <div class="dashboard-section-card-header">
                    <div class="d-flex align-items-center gap-2.5">
                        <div class="dashboard-section-icon-wrap" style="background: var(--primary-soft); color: var(--primary);">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark">Reports &amp; Analytics</div>
                            <div class="text-muted fs-xs">Calling metrics, audio &amp; logs</div>
                        </div>
                    </div>
                    <span class="badge bg-primary">Reports Hub</span>
                </div>
                <div class="card-body p-2 d-flex flex-column gap-1">
                    <?php if (can('view_reports')): ?>
                    <a href="<?= url('modules/logs/Reports.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-graph-up link-icon"></i>
                            <div>
                                <div class="link-title">Call Performance Reports</div>
                                <div class="link-desc">Disposition summary, league table &amp; trends</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/logs/Reports.php?range=today') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-calendar-event link-icon"></i>
                            <div>
                                <div class="link-title">Today's Performance Report</div>
                                <div class="link-desc">Real-time daily dials and conversion rates</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/logs/call_history.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-telephone-inbound link-icon"></i>
                            <div>
                                <div class="link-title">Call History &amp; Recordings</div>
                                <div class="link-desc">Audio playback, duration and timestamps</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (can('view_activity')): ?>
                    <a href="<?= url('modules/logs/manage_activity.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-clock-history link-icon"></i>
                            <div>
                                <div class="link-title">System Audit Trail</div>
                                <div class="link-desc">Security events and user modification logs</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section 2: Leads & Sales Pipeline -->
        <div class="col-xxl-4 col-md-6">
            <div class="dashboard-section-card">
                <div class="dashboard-section-card-header">
                    <div class="d-flex align-items-center gap-2.5">
                        <div class="dashboard-section-icon-wrap" style="background: var(--info-soft); color: var(--info-text);">
                            <i class="bi bi-kanban"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark">Leads &amp; Sales Pipeline</div>
                            <div class="text-muted fs-xs">Funnel stages &amp; loan processing</div>
                        </div>
                    </div>
                    <span class="badge bg-info">Pipeline</span>
                </div>
                <div class="card-body p-2 d-flex flex-column gap-1">
                    <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-kanban link-icon"></i>
                            <div>
                                <div class="link-title">Pipeline Kanban Board</div>
                                <div class="link-desc">Drag &amp; drop loan pipeline stages</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/leads/lead_list.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-person-lines-fill link-icon"></i>
                            <div>
                                <div class="link-title">View Leads Register</div>
                                <div class="link-desc">Filter, search, edit and assign customer leads</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/leads/leads_dashboard.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-bar-chart-line link-icon"></i>
                            <div>
                                <div class="link-title">Leads Analytics &amp; Operations</div>
                                <div class="link-desc">Pipeline velocity, funnel &amp; bank status</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php if (can('manage_leads')): ?>
                    <a href="<?= url('modules/leads/lead_insert.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-plus-circle link-icon"></i>
                            <div>
                                <div class="link-title">+ Add New Lead</div>
                                <div class="link-desc">Intake new loan application &amp; borrower profile</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Section 3: Data Management & Calling Queue -->
        <?php if (can('manage_database')): ?>
        <div class="col-xxl-4 col-md-6">
            <div class="dashboard-section-card">
                <div class="dashboard-section-card-header">
                    <div class="d-flex align-items-center gap-2.5">
                        <div class="dashboard-section-icon-wrap" style="background: var(--success-soft); color: var(--success-text);">
                            <i class="bi bi-database"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark">Data Management &amp; Queue</div>
                            <div class="text-muted fs-xs">Records, staging &amp; DND controls</div>
                        </div>
                    </div>
                    <span class="badge bg-success">Data Hub</span>
                </div>
                <div class="card-body p-2 d-flex flex-column gap-1">
                    <a href="<?= url('modules/database/data_management_main.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-database link-icon"></i>
                            <div>
                                <div class="link-title">Main Customer Database</div>
                                <div class="link-desc">Full customer records, assignments &amp; history</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/database/data_management_temporary.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-table link-icon"></i>
                            <div>
                                <div class="link-title">Temporary Calling Database</div>
                                <div class="link-desc">Staging records for testing and mobile dialing</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/database/upload_data.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-cloud-arrow-up link-icon"></i>
                            <div>
                                <div class="link-title">Upload Calling CSV Data</div>
                                <div class="link-desc">Batch import into Temporary or Main DB</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/database/ViewDND.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-slash-circle link-icon"></i>
                            <div>
                                <div class="link-title">DND Numbers Registry</div>
                                <div class="link-desc">Compliance protection for Do Not Call records</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section 4: Users & Teams Management -->
        <?php if (can('manage_users') || can('manage_teams')): ?>
        <div class="col-xxl-4 col-md-6">
            <div class="dashboard-section-card">
                <div class="dashboard-section-card-header">
                    <div class="d-flex align-items-center gap-2.5">
                        <div class="dashboard-section-icon-wrap" style="background: var(--warning-soft); color: var(--warning-text);">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark">Users &amp; Team Management</div>
                            <div class="text-muted fs-xs">Roster, supervisors &amp; assignments</div>
                        </div>
                    </div>
                    <span class="badge bg-warning text-dark">Fleet Admin</span>
                </div>
                <div class="card-body p-2 d-flex flex-column gap-1">
                    <?php if (can('manage_users')): ?>
                    <a href="<?= url('modules/users/users_view.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-people link-icon"></i>
                            <div>
                                <div class="link-title">User Directory &amp; Accounts</div>
                                <div class="link-desc">Manage telecallers, officers &amp; roles</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/users/users_add.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-person-plus link-icon"></i>
                            <div>
                                <div class="link-title">+ Register New User</div>
                                <div class="link-desc">Create telecaller account with team assignment</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (can('manage_teams')): ?>
                    <a href="<?= url('modules/users/teams_dashboard.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-diagram-3 link-icon"></i>
                            <div>
                                <div class="link-title">Teams Dashboard</div>
                                <div class="link-desc">Department teams, targets &amp; performance</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/users/team_members.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
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
        </div>
        <?php endif; ?>

        <!-- Section 5: Settings & Administration -->
        <?php if (can('manage_settings') || isSuperAdmin()): ?>
        <div class="col-xxl-4 col-md-6">
            <div class="dashboard-section-card">
                <div class="dashboard-section-card-header">
                    <div class="d-flex align-items-center gap-2.5">
                        <div class="dashboard-section-icon-wrap" style="background: #F1F5F9; color: #475569;">
                            <i class="bi bi-gear-fill"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark">Settings &amp; Administration</div>
                            <div class="text-muted fs-xs">Permissions, limits &amp; system config</div>
                        </div>
                    </div>
                    <span class="badge bg-secondary">System</span>
                </div>
                <div class="card-body p-2 d-flex flex-column gap-1">
                    <?php if (isAdmin() || isSuperAdmin()): ?>
                    <a href="<?= url('modules/settings/permissions_manager.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-shield-lock link-icon"></i>
                            <div>
                                <div class="link-title">Access Control Matrix (RBAC)</div>
                                <div class="link-desc">Role permissions and user access overrides</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('modules/settings/settings.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-gear link-icon"></i>
                            <div>
                                <div class="link-title">System Settings</div>
                                <div class="link-desc">Company branding, logo, and general options</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php if (can('manage_api')): ?>
                    <a href="<?= url('modules/settings/api_settings.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-key link-icon"></i>
                            <div>
                                <div class="link-title">Mobile API Tokens</div>
                                <div class="link-desc">Authenticate mobile app and integrate endpoints</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (isSuperAdmin()): ?>
                    <a href="<?= url('modules/settings/super_admin_panel.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-shield-shaded link-icon"></i>
                            <div>
                                <div class="link-title">Super Admin Panel</div>
                                <div class="link-desc">Tenant plan limits, user quotas &amp; features</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section 6: Workspace & Profile -->
        <div class="col-xxl-4 col-md-6">
            <div class="dashboard-section-card">
                <div class="dashboard-section-card-header">
                    <div class="d-flex align-items-center gap-2.5">
                        <div class="dashboard-section-icon-wrap" style="background: rgba(109, 69, 249, 0.12); color: var(--primary);">
                            <i class="bi bi-person-badge-fill"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark">My Workspace &amp; Profile</div>
                            <div class="text-muted fs-xs">Personal profile, avatar &amp; security</div>
                        </div>
                    </div>
                    <span class="badge bg-purple">Account</span>
                </div>
                <div class="card-body p-2 d-flex flex-column gap-1">
                    <a href="<?= url('profile.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-person-circle link-icon"></i>
                            <div>
                                <div class="link-title">My Profile &amp; Avatar</div>
                                <div class="link-desc">Update display name, email and avatar photo</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('profile.php#password') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-shield-check link-icon"></i>
                            <div>
                                <div class="link-title">Security &amp; Password</div>
                                <div class="link-desc">Update account password and session tokens</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('dashboard.php') ?>" class="dashboard-section-link-item">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-speedometer2 link-icon"></i>
                            <div>
                                <div class="link-title">Dashboard Home</div>
                                <div class="link-desc">Refresh real-time telecalling KPI overview</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow"></i>
                    </a>
                    <a href="<?= url('logout.php') ?>" class="dashboard-section-link-item text-danger">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="bi bi-box-arrow-right link-icon text-danger"></i>
                            <div>
                                <div class="link-title text-danger">Log Out</div>
                                <div class="link-desc">Securely terminate active CRM session</div>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right link-arrow text-danger"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'php_scripts/footer.php'; ?>
