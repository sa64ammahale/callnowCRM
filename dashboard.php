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

// 1. Today's Calls & Connected
try {
    $res = mysqli_query($link, "SELECT COUNT(*) as total_calls, SUM(CASE WHEN MAINDATABASE_CALL_DIALED_STATUS = 'Connected' THEN 1 ELSE 0 END) as connected FROM $tblMain WHERE MAINDATABASE_CALL_DIAL_TIME >= CURDATE() AND MAINDATABASE_CALL_DIAL_TIME < CURDATE() + INTERVAL 1 DAY");
    $row = $res ? mysqli_fetch_assoc($res) : ['total_calls' => 0, 'connected' => 0];
} catch (\Throwable $e) { $row = ['total_calls' => 0, 'connected' => 0]; }
$today_calls     = (int)($row['total_calls'] ?? 0);
$today_connected = (int)($row['connected'] ?? 0);
$connect_rate    = $today_calls > 0 ? round(($today_connected / $today_calls) * 100, 1) : 0;

// 2. Total Hot Leads / Sales
try {
    $res2 = mysqli_query($link, "SELECT COUNT(*) as leads FROM $tblMain WHERE MAINDATABASE_CALL_DIALED_STATUS IN ('Connected','Interested','Follow Up','Sale','Callback')");
    $row2 = $res2 ? mysqli_fetch_assoc($res2) : ['leads' => 0];
} catch (\Throwable $e) { $row2 = ['leads' => 0]; }
$total_leads = (int)($row2['leads'] ?? 0);

// 3. Active Callers Today
try {
    $res3 = mysqli_query($link, "SELECT COUNT(DISTINCT MAINDATABASE_CALL_DIALED_USER) as cnt FROM $tblMain WHERE MAINDATABASE_CALL_DIAL_TIME >= CURDATE() AND MAINDATABASE_CALL_DIAL_TIME < CURDATE() + INTERVAL 1 DAY AND MAINDATABASE_CALL_DIALED_USER IS NOT NULL");
    $row3 = $res3 ? mysqli_fetch_assoc($res3) : ['cnt' => 0];
} catch (\Throwable $e) { $row3 = ['cnt' => 0]; }
$active_callers_today = (int)($row3['cnt'] ?? 0);

// 4. Total Records
try {
    $res4 = mysqli_query($link, "SELECT COUNT(*) as total FROM $tblMain");
    $row4 = $res4 ? mysqli_fetch_assoc($res4) : ['total' => 0];
} catch (\Throwable $e) { $row4 = ['total' => 0]; }
$total_numbers = (int)($row4['total'] ?? 0);

// 5. Fresh / Not Called Numbers
try {
    $res5 = mysqli_query($link, "SELECT COUNT(*) as unused FROM $tblMain WHERE MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR MAINDATABASE_CALL_DIALED_STATUS IS NULL OR MAINDATABASE_CALL_DIALED_STATUS = '' OR MAINDATABASE_CALL_DIAL_TIME IS NULL");
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

<!-- Welcome Strip (Flux Soft Ambient Wash) -->
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

<!-- Primary KPI Metrics Grid (Flux KPI Cards with Tabular Numerals) -->
<div class="row g-3 mb-4">
    <div class="col-xl-2 col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon"><i class="bi bi-telephone-outbound"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Calls Today</div>
                <div class="metric-card-value font-mono"><?= fmt($today_calls) ?></div>
                <div class="metric-card-sub"><span class="badge bg-primary">Dialed today</span></div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon success"><i class="bi bi-check2-circle"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Connected</div>
                <div class="metric-card-value font-mono"><?= fmt($today_connected) ?></div>
                <div class="metric-card-sub"><span class="badge bg-success"><?= $connect_rate ?>% rate</span></div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6">
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
    <div class="col-xl-2 col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon warning"><i class="bi bi-star-fill"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Hot Leads</div>
                <div class="metric-card-value font-mono"><?= fmt($total_leads) ?></div>
                <div class="metric-card-sub"><span class="badge bg-warning">Interested / Sales</span></div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon danger"><i class="bi bi-clock-history"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Fresh Pending</div>
                <div class="metric-card-value font-mono"><?= fmt($unused_numbers) ?></div>
                <div class="metric-card-sub"><span class="badge bg-danger"><?= $pending_pct ?>% remain</span></div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6">
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

<!-- Operational Activity & Quick Launcher -->
<div class="row g-3">
    <!-- Left: Telecaller Fleet Status -->
    <div class="col-lg-7">
        <div class="card h-100 mb-0">
            <div class="card-header">
                <span class="fw-bold"><i class="bi bi-people-fill text-primary me-2"></i>Telecaller Performance Overview</span>
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
                    <span class="text-muted small"><i class="bi bi-info-circle me-1"></i>All telecallers are synchronized via the lease-based auto-expiry queue.</span>
                    <a href="<?= url('modules/logs/Reports.php') ?>" class="btn btn-sm btn-outline-primary">
                        Drill Down Analytics <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Workspace Navigation Shortcuts -->
    <div class="col-lg-5">
        <div class="card h-100 mb-0">
            <div class="card-header">
                <span class="fw-bold"><i class="bi bi-grid-fill text-primary me-2"></i>Workspace Quick Launcher</span>
                <span class="text-muted small">Frequent Actions</span>
            </div>
            <div class="card-body p-2">
                <div class="list-group list-group-flush gap-1">
                    <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between px-3 py-2 border-0 rounded">
                        <div class="d-flex align-items-center gap-3">
                            <span class="metric-card-icon sm bg-primary text-white"><i class="bi bi-kanban"></i></span>
                            <div>
                                <div class="fw-semibold text-dark">Lead Pipeline Board</div>
                                <div class="text-muted small">Manage stages: Lead, Follow-up, Underwriting, Sanctioned</div>
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
                                <div class="text-muted small">Import customer records via CSV into Temporary or Main DB</div>
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
                                <div class="text-muted small">Review telecaller recordings, duration, and disposition logs</div>
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

<?php include 'php_scripts/footer.php'; ?>
