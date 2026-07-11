<?php
require_once "php_scripts/auth.php";

$pageTitle = 'Dashboard';
include 'php_scripts/header.php';

// === ALL DATA WITH NULL-SAFE FALLBACKS ===
$today_calls = $today_connected = $connect_rate = 0;
$total_leads = $total_numbers = $unused_numbers = $active_users_today = 0;

function fmt($num) {
    return number_format((int)$num);
}

// 1. Today's Calls & Connected
$sql_today = "SELECT
                COUNT(*) as total_calls,
                SUM(CASE WHEN MAINDATABASE_CALL_DIALED_STATUS = 'Connected' THEN 1 ELSE 0 END) as connected
              FROM MAIN_DATABASE
              WHERE DATE(MAINDATABASE_CALL_DIAL_TIME) = CURDATE()";

$res = mysqli_query($link, $sql_today);
$row = $res ? mysqli_fetch_assoc($res) : ['total_calls' => 0, 'connected' => 0];
$today_calls     = (int)($row['total_calls'] ?? 0);
$today_connected = (int)($row['connected'] ?? 0);
$connect_rate    = $today_calls > 0 ? round(($today_connected / $today_calls) * 100, 1) : 0;

// 2. Total Hot Leads / Sales
$res2 = mysqli_query($link, "SELECT COUNT(*) as leads FROM MAIN_DATABASE WHERE MAINDATABASE_CALL_DIALED_STATUS IN ('Connected','Interested','Follow Up','Sale','Callback')");
$row2 = $res2 ? mysqli_fetch_assoc($res2) : ['leads' => 0];
$total_leads = (int)($row2['leads'] ?? 0);

// 3. Active Callers Today
$res3 = mysqli_query($link, "SELECT COUNT(DISTINCT MAINDATABASE_CALL_DIALED_USER) as users FROM MAIN_DATABASE WHERE DATE(MAINDATABASE_CALL_DIAL_TIME) = CURDATE() AND MAINDATABASE_CALL_DIALED_USER IS NOT NULL");
$row3 = $res3 ? mysqli_fetch_assoc($res3) : ['users' => 0];
$active_users_today = (int)($row3['users'] ?? 0);

// 4. Total Records
$res4 = mysqli_query($link, "SELECT COUNT(*) as total FROM MAIN_DATABASE");
$row4 = $res4 ? mysqli_fetch_assoc($res4) : ['total' => 0];
$total_numbers = (int)($row4['total'] ?? 0);

// 5. Fresh / Not Called Numbers
$res5 = mysqli_query($link, "SELECT COUNT(*) as unused FROM MAIN_DATABASE WHERE MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR MAINDATABASE_CALL_DIALED_STATUS IS NULL OR MAINDATABASE_CALL_DIALED_STATUS = '' OR MAINDATABASE_CALL_DIAL_TIME IS NULL");
$row5 = $res5 ? mysqli_fetch_assoc($res5) : ['unused' => 0];
$unused_numbers = (int)($row5['unused'] ?? 0);

mysqli_close($link);
?>

<div class="container py-4">
    <!-- Welcome -->
    <div class="welcome-card mb-4">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h1>Good <?= ($h = date('H')) < 12 ? 'Morning' : ($h < 17 ? 'Afternoon' : 'Evening') ?>, <?= htmlspecialchars($_SESSION['name'] ?? 'Team') ?></h1>
                <p>Your telecalling engine is running at full power today.</p>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <a href="modules/logs/Reports.php?type=daily" class="btn btn-light btn-sm">Today's Report</a>
                    <a href="modules/database/data_management_temporary.php" class="btn btn-outline-light btn-sm">View Database</a>
                    <a href="modules/database/maindatabase_ajax/download_bach.php" class="btn btn-outline-light btn-sm">Export Full DB</a>
                </div>
            </div>
            <div class="col-lg-4 text-center d-none d-lg-block">
                <i class="bi bi-headset" style="font-size:4rem;opacity:0.5"></i>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-lg-4 col-md-6">
            <div class="metric-card">
                <div class="metric-card-icon"><i class="bi bi-telephone-outbound"></i></div>
                <div class="metric-card-body">
                    <div class="metric-card-label">Calls Made Today</div>
                    <div class="metric-card-value"><?= fmt($today_calls) ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="metric-card">
                <div class="metric-card-icon success"><i class="bi bi-check2-circle"></i></div>
                <div class="metric-card-body">
                    <div class="metric-card-label">Connected Calls</div>
                    <div class="metric-card-value"><?= fmt($today_connected) ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="metric-card">
                <div class="metric-card-icon info"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="metric-card-body">
                    <div class="metric-card-label">Connect Rate Today</div>
                    <div class="metric-card-value"><?= $connect_rate ?>%</div>
                    <div class="progress mt-2" style="height:4px">
                        <div class="progress-bar" style="width:<?= $connect_rate ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="metric-card">
                <div class="metric-card-icon warning"><i class="bi bi-trophy-fill"></i></div>
                <div class="metric-card-body">
                    <div class="metric-card-label">Hot Leads / Sales</div>
                    <div class="metric-card-value"><?= fmt($total_leads) ?></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="metric-card">
                <div class="metric-card-icon danger"><i class="bi bi-phone-x"></i></div>
                <div class="metric-card-body">
                    <div class="metric-card-label">Fresh Numbers</div>
                    <div class="metric-card-value"><?= fmt($unused_numbers) ?></div>
                    <?php if($total_numbers > 0): ?>
                        <div class="metric-card-trend down"><?= round(($unused_numbers / $total_numbers) * 100, 1) ?>% pending</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="metric-card">
                <div class="metric-card-icon"><i class="bi bi-database-fill"></i></div>
                <div class="metric-card-body">
                    <div class="metric-card-label">Total Main Database</div>
                    <div class="metric-card-value"><?= fmt($total_numbers) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Row -->
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card text-center p-4">
                <div class="card-body">
                    <i class="bi bi-people-fill text-primary" style="font-size:2.5rem"></i>
                    <div class="metric-card-value mt-2"><?= $active_users_today ?></div>
                    <div class="metric-card-label">Active Callers Today</div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card text-center p-4">
                <div class="card-body">
                    <i class="bi bi-broadcast text-success" style="font-size:2.5rem"></i>
                    <h5 class="mt-2 mb-1">System Live & Running</h5>
                    <p class="text-muted mb-0 small">Real-time sync &bull; All agents connected</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'php_scripts/footer.php'; ?>
