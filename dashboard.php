<?php
require_once "php_scripts/auth.php";

// === ALL DATA WITH NULL-SAFE FALLBACKS ===
$today_calls = $today_connected = $connect_rate = 0;
$total_leads = $total_numbers = $unused_numbers = $active_users_today = 0;

// Helper: Safe number formatting (never passes null)
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

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard • CallNow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app-theme.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; color: #333; }
        .glass-card { background: rgba(255,255,255,0.95); backdrop-filter: blur(20px); border-radius: 1.8rem; box-shadow: 0 25px 50px rgba(0,0,0,0.15); transition: all 0.4s ease; }
        .glass-card:hover { transform: translateY(-12px); box-shadow: 0 35px 70px rgba(102,126,234,0.3); }
        .stat-card { height: 160px; display: flex; align-items: center; padding: 1.8rem; position: relative; }
        .icon-circle { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; color: white; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .hero-welcome { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 2rem; box-shadow: 0 20px 60px rgba(102,126,234,0.4); }
        .btn-modern { border-radius: 50px; padding: 0.9rem 2.2rem; font-weight: 600; font-size: 1.1rem; }
        .progress { height: 10px; border-radius: 10px; background: rgba(0,0,0,0.1); }
        .badge-alert { animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.1); } }
    </style>
</head>
<body>
<?php include 'php_scripts/header.php'; ?>

<div class="container py-5 mt-4">
    <!-- Hero -->
    <div class="hero-welcome p-5 mb-5 text-center text-lg-start">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h1 class="display-4 fw-bold mb-3">
                    Good <?= ($h = date('H')) < 12 ? 'Morning' : ($h < 17 ? 'Afternoon' : 'Evening') ?>,
                    <span class="text-warning"><?= htmlspecialchars($_SESSION['name'] ?? 'Team') ?>!</span>
                </h1>
                <p class="lead mb-4 opacity-90">Your telecalling engine is running at full power today.</p>
                <div class="d-flex flex-wrap gap-3 justify-content-center justify-content-lg-start">
                    <a href="modules/logs/Reports.php?type=daily" class="btn btn-light btn-modern shadow-lg">Today's Report</a>
                    <a href="modules/database/data_management_temporary.php" class="btn btn-outline-light btn-modern">View Database</a>
                    <a href="modules/database/maindatabase_ajax/download_bach.php" class="btn btn-outline-light btn-modern">Export Full DB</a>
                </div>
            </div>
            <div class="col-lg-4 text-center">
                <i class="bi bi-headset display-1 opacity-80"></i>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-4">
        <div class="col-lg-4 col-md-6">
            <div class="glass-card stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <div class="icon-circle me-4" style="background: rgba(255,255,255,0.25);">
                    <i class="bi bi-telephone-outbound"></i>
                </div>
                <div>
                    <h2 class="fw-bold mb-1"><?= fmt($today_calls) ?></h2>
                    <p class="mb-0 opacity-90 fw-medium">Calls Made Today</p>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="glass-card stat-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white;">
                <div class="icon-circle me-4" style="background: rgba(255,255,255,0.25);">
                    <i class="bi bi-check2-circle"></i>
                </div>
                <div>
                    <h2 class="fw-bold mb-1"><?= fmt($today_connected) ?></h2>
                    <p class="mb-0 opacity-90 fw-medium">Connected Calls</p>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="glass-card stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                <div class="icon-circle me-4" style="background: rgba(255,255,255,0.25);">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <div>
                    <h2 class="fw-bold mb-1"><?= $connect_rate ?>%</h2>
                    <p class="mb-0 opacity-90 fw-medium">Connect Rate Today</p>
                    <div class="progress mt-2">
                        <div class="progress-bar" style="width: <?= $connect_rate ?>%; background: rgba(255,255,255,0.4);"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="glass-card stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
                <div class="icon-circle me-4" style="background: rgba(255,255,255,0.25);">
                    <i class="bi bi-trophy-fill"></i>
                </div>
                <div>
                    <h2 class="fw-bold mb-1"><?= fmt($total_leads) ?></h2>
                    <p class="mb-0 opacity-90 fw-medium">Hot Leads / Sales</p>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="glass-card stat-card position-relative" style="background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%); color: white;">
                <div class="icon-circle me-4" style="background: rgba(255,255,255,0.25);">
                    <i class="bi bi-phone-x"></i>
                </div>
                <div>
                    <h2 class="fw-bold mb-1"><?= fmt($unused_numbers) ?></h2>
                    <p class="mb-0 opacity-90 fw-medium">Fresh Numbers</p>
                    <?php if($total_numbers > 0): ?>
                        <small class="badge bg-white text-danger fw-bold mt-2 d-inline-block badge-alert">
                            <?= round(($unused_numbers / $total_numbers) * 100, 1) ?>% pending
                        </small>
                    <?php endif; ?>
                </div>
                <span class="position-absolute top-0 end-0 m-3 badge rounded-pill bg-white text-danger fw-bold badge-alert">Fresh</span>
            </div>
        </div>

        <div class="col-lg-4 col-md-6">
            <div class="glass-card stat-card" style="background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333;">
                <div class="icon-circle me-4" style="background: linear-gradient(135deg, #8b5cf6, #6366f1);">
                    <i class="bi bi-database-fill"></i>
                </div>
                <div>
                    <h2 class="fw-bold mb-1"><?= fmt($total_numbers) ?></h2>
                    <p class="mb-0 fw-bold opacity-90">Total Main Database</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Row -->
    <div class="row g-4 mt-4">
        <div class="col-lg-6">
            <div class="glass-card text-center p-5" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <i class="bi bi-people-fill display-3 mb-3"></i>
                <h1 class="display-5 fw-bold"><?= $active_users_today ?></h1>
                <p class="fs-5 mb-0 opacity-90">Active Callers Today</p>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="glass-card text-center p-5" style="background: linear-gradient(135deg, #10b981 0%, #34d399 100%); color: white;">
                <i class="bi bi-broadcast display-3 mb-3"></i>
                <h4 class="fw-bold mb-2">System Live & Running</h4>
                <p class="mb-0 opacity-90">Real-time sync • All agents connected</p>
            </div>
        </div>
    </div>
</div>

<?php include 'php_scripts/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
