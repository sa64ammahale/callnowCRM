<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../php_scripts/super_admin.php';

global $link;
ensureSuperAdminSchema($link);
seedDefaultPlan($link);
seedManageSuperAdminPermission($link);
seedDefaultSuperAdmin($link);

if (!isSuperAdmin()) {
    appRedirect('dashboard.php');
}

$msg = '';
$msg_type = '';
$tab = $_GET['tab'] ?? 'overview';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid session token';
        $msg_type = 'danger';
    } else {
        $action = $_POST['sa_action'] ?? '';

        if ($action === 'update_limits') {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $maxUsers = (int)($_POST['max_users'] ?? 10);
            $maxApiTokens = (int)($_POST['max_api_tokens'] ?? 5);
            $maxApiCallsPerMin = (int)($_POST['max_api_calls_per_minute'] ?? 60);
            $maxApiCallsPerHour = (int)($_POST['max_api_calls_per_hour'] ?? 1000);
            $maxDbRecords = (int)($_POST['max_db_records'] ?? 50000);
            $maxStorageMb = (int)($_POST['max_storage_mb'] ?? 500);

            $stmt = mysqli_prepare($link, "UPDATE `super_admin_plans` SET max_users=?, max_api_tokens=?, max_api_calls_per_minute=?, max_api_calls_per_hour=?, max_db_records=?, max_storage_mb=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "iiiiiii", $maxUsers, $maxApiTokens, $maxApiCallsPerMin, $maxApiCallsPerHour, $maxDbRecords, $maxStorageMb, $planId);
            if (mysqli_stmt_execute($stmt)) {
                $msg = 'Limits updated successfully!';
                $msg_type = 'success';
                logActivity($link, USER_ID, 'UPDATE', 'Updated super admin limits for plan #' . $planId);
            } else {
                $msg = 'Database error: ' . mysqli_stmt_error($stmt);
                $msg_type = 'danger';
            }
            mysqli_stmt_close($stmt);
        }

        if ($action === 'switch_plan') {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $companyName = trim($_POST['company_name'] ?? 'CallNow');
            $billingEmail = trim($_POST['billing_email'] ?? '');
            $paymentStatus = trim($_POST['payment_status'] ?? 'trial');
            $stmt = mysqli_prepare($link, "UPDATE `super_admin_account` SET current_plan_id=?, company_name=?, billing_email=?, payment_status=? WHERE id=1");
            mysqli_stmt_bind_param($stmt, "isss", $planId, $companyName, $billingEmail, $paymentStatus);
            if (mysqli_stmt_execute($stmt)) {
                $msg = 'Plan switched successfully!';
                $msg_type = 'success';
                logActivity($link, USER_ID, 'UPDATE', 'Switched super admin plan to #' . $planId);
            } else {
                $msg = 'Database error: ' . mysqli_stmt_error($stmt);
                $msg_type = 'danger';
            }
            mysqli_stmt_close($stmt);
        }

        if ($action === 'create_plan') {
            $planName = trim($_POST['plan_name'] ?? '');
            $planSlug = strtolower(trim($_POST['plan_slug'] ?? ''));
            $maxUsers = (int)($_POST['max_users'] ?? 10);
            $maxApiTokens = (int)($_POST['max_api_tokens'] ?? 5);
            $maxApiCallsPerMin = (int)($_POST['max_api_calls_per_minute'] ?? 60);
            $maxApiCallsPerHour = (int)($_POST['max_api_calls_per_hour'] ?? 1000);
            $maxDbRecords = (int)($_POST['max_db_records'] ?? 50000);
            $maxStorageMb = (int)($_POST['max_storage_mb'] ?? 500);
            $priceMonthly = !empty($_POST['price_monthly']) ? (float)$_POST['price_monthly'] : null;
            $priceYearly = !empty($_POST['price_yearly']) ? (float)$_POST['price_yearly'] : null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($planName && $planSlug) {
                $stmt = mysqli_prepare($link, "INSERT INTO `super_admin_plans` (plan_name, plan_slug, max_users, max_api_tokens, max_api_calls_per_minute, max_api_calls_per_hour, max_db_records, max_storage_mb, price_monthly, price_yearly, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "ssiiiiidddi", $planName, $planSlug, $maxUsers, $maxApiTokens, $maxApiCallsPerMin, $maxApiCallsPerHour, $maxDbRecords, $maxStorageMb, $priceMonthly, $priceYearly, $isActive);
                if (mysqli_stmt_execute($stmt)) {
                    $msg = 'Plan created successfully!';
                    $msg_type = 'success';
                    logActivity($link, USER_ID, 'INSERT', 'Created super admin plan: ' . $planName);
                } else {
                    $msg = 'Database error: ' . mysqli_stmt_error($stmt);
                    $msg_type = 'danger';
                }
                mysqli_stmt_close($stmt);
            } else {
                $msg = 'Plan name and slug are required.';
                $msg_type = 'danger';
            }
        }

        if ($action === 'delete_plan') {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $stmt = mysqli_prepare($link, "DELETE FROM `super_admin_plans` WHERE id=? AND is_default=0");
            mysqli_stmt_bind_param($stmt, "i", $planId);
            if (mysqli_stmt_execute($stmt)) {
                $msg = 'Plan deleted successfully!';
                $msg_type = 'success';
                logActivity($link, USER_ID, 'DELETE', 'Deleted super admin plan #' . $planId);
            } else {
                $msg = 'Cannot delete default plan or database error.';
                $msg_type = 'danger';
            }
            mysqli_stmt_close($stmt);
        }

        if ($action === 'toggle_access') {
            $feature = trim($_POST['feature'] ?? '');
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $key = 'feature_' . $feature;
            $stmt = mysqli_prepare($link, "INSERT INTO " . tn('TBL_APP_SETTINGS') . " (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
            mysqli_stmt_bind_param($stmt, "ss", $key, $enabled);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $msg = 'Access setting updated!';
            $msg_type = 'success';
            logActivity($link, USER_ID, 'UPDATE', 'Toggled feature access: ' . $feature . ' = ' . ($enabled ? 'enabled' : 'disabled'));
        }
    }
}

$summary = getLimitUsageSummary($link);
$plans = getActivePlans($link);
$currentPlan = getCurrentPlan($link) ?: getDefaultPlan($link);
$account = [];
$res = mysqli_query($link, "SELECT * FROM `super_admin_account` WHERE id = 1 LIMIT 1");
if ($res && $row = mysqli_fetch_assoc($res)) $account = $row;

$recentLogs = [];
$res = mysqli_query($link, "SELECT l.*, u.NAME as user_name FROM `super_admin_limit_logs` l LEFT JOIN `users` u ON l.user_id = u.ID ORDER BY l.created_at DESC LIMIT 20");
if ($res) while ($r = mysqli_fetch_assoc($res)) $recentLogs[] = $r;

$appSettings = [];
$sr = mysqli_query($link, "SELECT setting_key, setting_value FROM " . tn('TBL_APP_SETTINGS'));
if ($sr) while ($s = mysqli_fetch_assoc($sr)) $appSettings[$s['setting_key']] = $s['setting_value'];

function isFeatureEnabled(string $feature, array $settings): bool {
    $key = 'feature_' . $feature;
    return ($settings[$key] ?? '1') === '1';
}

$featureFlags = [
    'users' => isFeatureEnabled('users', $appSettings),
    'leads' => isFeatureEnabled('leads', $appSettings),
    'database' => isFeatureEnabled('database', $appSettings),
    'reports' => isFeatureEnabled('reports', $appSettings),
    'api' => isFeatureEnabled('api', $appSettings),
    'teams' => isFeatureEnabled('teams', $appSettings),
    'settings' => isFeatureEnabled('settings', $appSettings),
    'uploads' => isFeatureEnabled('uploads', $appSettings),
];

?>
<?php $pageTitle = 'Super Admin Panel - CallNow'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<style>
:root {
    --sa-accent: var(--accent);
    --sa-accent-dark: var(--accent-hover, #4f46e5);
    --sa-ink: #1e1b4b;
    --sa-ink-soft: #6b6890;
    --sa-soft: #f0f2ff;
    --sa-border: #e2e4f0;
    --sa-danger: #ef4444;
    --sa-warning: #f59e0b;
    --sa-success: #10b981;
}

.sa-header {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4f46e5 100%);
    border-radius: 1rem;
    padding: 1.5rem 2rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.sa-header::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.4;
}
.sa-header-content {
    position: relative; z-index: 1;
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;
}
.sa-header-left h1 {
    font-size: 1.35rem; font-weight: 700; color: #fff;
    margin: 0 0 0.2rem 0; letter-spacing: -0.02em;
    display: flex; align-items: center; gap: 0.5rem;
}
.sa-header-left h1 i { font-size: 1.4rem; }
.sa-header-left p { color: rgba(255,255,255,0.7); font-size: 0.8125rem; margin: 0; }

.sa-tabs {
    display: flex; gap: 0.25rem; background: var(--sa-soft);
    border: 1px solid var(--sa-border); border-radius: 0.75rem;
    padding: 0.25rem; margin-bottom: 1.25rem; flex-wrap: wrap;
}
.sa-tab {
    flex: 1; text-align: center; padding: 0.5rem 0.75rem;
    border-radius: 0.625rem; font-size: 0.75rem; font-weight: 600;
    color: var(--sa-ink-soft); text-decoration: none; transition: all 0.12s ease;
    white-space: nowrap;
}
.sa-tab:hover { color: var(--sa-ink); background: rgba(255,255,255,0.6); }
.sa-tab.active { background: #fff; color: var(--sa-accent); box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
.sa-tab i { margin-right: 0.35rem; }

.sa-card {
    background: #fff; border: 1px solid var(--sa-border);
    border-radius: 0.875rem; overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03); margin-bottom: 1.25rem;
}
.sa-card-body { padding: 1.5rem 1.75rem; }

.sa-stat-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 1rem; margin-bottom: 1.5rem;
}
.sa-stat-card {
    background: var(--sa-soft); border: 1px solid var(--sa-border);
    border-radius: 0.75rem; padding: 1rem 1.25rem; text-align: center;
}
.sa-stat-card .num { font-size: 1.75rem; font-weight: 700; color: var(--sa-ink); line-height: 1.2; }
.sa-stat-card .lbl { font-size: 0.7rem; color: var(--sa-ink-soft); margin: 0; text-transform: uppercase; letter-spacing: 0.04em; }

.sa-progress { height: 0.5rem; background: #e5e7eb; border-radius: 999px; overflow: hidden; }
.sa-progress-bar { height: 100%; border-radius: 999px; transition: width 0.3s ease; }
.sa-progress-bar.danger { background: linear-gradient(90deg, #ef4444, #dc2626); }
.sa-progress-bar.warning { background: linear-gradient(90deg, #f59e0b, #d97706); }
.sa-progress-bar.success { background: linear-gradient(90deg, #10b981, #059669); }

.sa-alert {
    border-radius: 0.625rem; font-size: 0.8125rem;
    padding: 0.75rem 1rem; margin-bottom: 1.25rem;
    display: flex; align-items: center; gap: 0.5rem;
}

.sa-label { font-size: 0.75rem; font-weight: 600; color: var(--sa-ink); margin-bottom: 0.3rem; }
.sa-hint { font-size: 0.6875rem; color: var(--sa-ink-soft); margin-top: 0.15rem; }
.sa-input, .sa-select {
    border: 1px solid var(--sa-border) !important;
    border-radius: 0.5rem !important; font-size: 0.8125rem !important;
    color: var(--sa-ink) !important; padding: 0.4rem 0.75rem !important;
    background: #fff !important;
}
.sa-input:focus, .sa-select:focus {
    border-color: var(--sa-accent) !important;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.12) !important; outline: none;
}
.sa-btn-primary {
    background: linear-gradient(135deg, var(--sa-accent), var(--sa-accent-dark)) !important;
    border: none !important; color: #fff !important; border-radius: 0.5rem !important;
    font-size: 0.8125rem !important; font-weight: 600 !important;
    padding: 0.5rem 1.5rem !important; transition: all 0.15s ease !important;
    box-shadow: 0 2px 8px rgba(99,102,241,0.25) !important;
}
.sa-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(99,102,241,0.35) !important; }
.sa-btn-danger {
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    border: none !important; color: #fff !important; border-radius: 0.5rem !important;
    font-size: 0.75rem !important; font-weight: 600 !important;
    padding: 0.35rem 1rem !important; transition: all 0.15s ease !important;
}
.sa-btn-outline {
    border: 1px solid var(--sa-border) !important;
    background: #fff !important; color: var(--sa-ink-soft) !important;
    border-radius: 0.5rem !important; font-size: 0.75rem !important;
    padding: 0.35rem 1rem !important;
}

.sa-badge {
    display: inline-flex; align-items: center; gap: 0.25rem;
    padding: 0.2rem 0.65rem; border-radius: 999px;
    font-size: 0.6875rem; font-weight: 600; white-space: nowrap;
}
.sa-badge-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.sa-badge-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.sa-badge-warning { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
.sa-badge-secondary { background: #f3f4f6; color: #4b5563; border: 1px solid #d1d5db; }
.sa-badge-info { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }

.sa-access-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem;
}
.sa-access-card {
    background: #fff; border: 1px solid var(--sa-border);
    border-radius: 0.75rem; padding: 1.25rem;
    display: flex; align-items: center; justify-content: space-between;
    transition: box-shadow 0.15s;
}
.sa-access-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.05); }
.sa-access-card .info h5 { font-size: 0.875rem; font-weight: 600; color: var(--sa-ink); margin: 0 0 0.15rem 0; }
.sa-access-card .info p { font-size: 0.7rem; color: var(--sa-ink-soft); margin: 0; }

.sa-log-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.8125rem; }
.sa-log-table thead th {
    background: #fafbff; color: var(--sa-ink-soft); font-weight: 600;
    font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em;
    padding: 0.625rem 0.875rem; border-bottom: 2px solid var(--sa-border); text-align: left;
}
.sa-log-table tbody td { padding: 0.5rem 0.875rem; border-bottom: 1px solid #f0f1f8; color: var(--sa-ink); }
.sa-log-table tbody tr:hover { background: #f8f9ff; }
.sa-log-table tbody tr:last-child td { border-bottom: none; }

.sa-plan-card {
    background: #fff; border: 1px solid var(--sa-border);
    border-radius: 0.75rem; padding: 1.25rem; position: relative;
    transition: box-shadow 0.15s;
}
.sa-plan-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.05); }
.sa-plan-card.current { border-color: var(--sa-accent); box-shadow: 0 0 0 2px rgba(99,102,241,0.15); }
.sa-plan-card .plan-badge {
    position: absolute; top: 0.75rem; right: 0.75rem;
    font-size: 0.6rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.04em; padding: 0.15rem 0.4rem; border-radius: 0.25rem;
}
.sa-plan-card .plan-badge.current-badge { background: var(--sa-accent); color: #fff; }
.sa-plan-card .plan-badge.default-badge { background: var(--sa-soft); color: var(--sa-accent); border: 1px solid var(--sa-border); }
.sa-plan-card h4 { font-size: 0.95rem; font-weight: 700; color: var(--sa-ink); margin: 0 0 0.2rem 0; }
.sa-plan-card .plan-slug { font-size: 0.7rem; color: var(--sa-ink-soft); margin-bottom: 0.75rem; }
.sa-plan-card .plan-price { font-size: 1.25rem; font-weight: 700; color: var(--sa-ink); }
.sa-plan-card .plan-price small { font-size: 0.7rem; font-weight: 400; color: var(--sa-ink-soft); }

@media (max-width: 767px) {
    .sa-card-body { padding: 1.25rem; }
    .sa-stat-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
    .sa-access-grid { grid-template-columns: 1fr; }
}
</style>

<div class="container page-wrapper">

    <!-- ─── Header ─── -->
    <div class="sa-header">
        <div class="sa-header-content">
            <div class="sa-header-left">
                <h1><i class="bi bi-shield-lock-fill"></i> Super Admin Panel</h1>
                <p>Manage app limits, plans, access control, and payment settings</p>
            </div>
            <div class="sa-header-right">
                <a href="<?= url('modules/settings/settings.php') ?>" class="sa-btn-outline" style="text-decoration:none;font-size:0.8125rem!important;padding:0.5rem 1.1rem!important;display:inline-flex;align-items:center;gap:0.4rem;">
                    <i class="bi bi-gear"></i> General Settings
                </a>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="sa-alert" style="background:<?= $msg_type==='success'?'#d1fae5':'#fee2e2' ?>;border:1px solid <?= $msg_type==='success'?'#6ee7b7':'#fca5a5' ?>;color:<?= $msg_type==='success'?'#065f46':'#991b1b' ?>;">
            <i class="bi <?= $msg_type==='success'?'bi-check-circle-fill':'bi-x-circle-fill' ?>"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="font-size:0.65rem;"></button>
        </div>
    <?php endif; ?>

    <!-- ─── Tabs ─── -->
    <div class="sa-tabs">
        <a href="<?= url('modules/settings/super_admin_panel.php') ?>?tab=overview" class="sa-tab <?= $tab==='overview'?'active':'' ?>">
            <i class="bi bi-speedometer2"></i> Overview
        </a>
        <a href="<?= url('modules/settings/super_admin_panel.php') ?>?tab=plans" class="sa-tab <?= $tab==='plans'?'active':'' ?>">
            <i class="bi bi-box-seam"></i> Plans
        </a>
        <a href="<?= url('modules/settings/super_admin_panel.php') ?>?tab=limits" class="sa-tab <?= $tab==='limits'?'active':'' ?>">
            <i class="bi bi-sliders2"></i> Limits
        </a>
        <a href="<?= url('modules/settings/super_admin_panel.php') ?>?tab=access" class="sa-tab <?= $tab==='access'?'active':'' ?>">
            <i class="bi bi-shield-check"></i> Access Control
        </a>
        <a href="<?= url('modules/settings/super_admin_panel.php') ?>?tab=logs" class="sa-tab <?= $tab==='logs'?'active':'' ?>">
            <i class="bi bi-journal-text"></i> Limit Logs
        </a>
    </div>

    <?php if ($tab === 'overview'): ?>
    <!-- ═══ Overview Dashboard ═══ -->
    <div class="sa-card">
        <div class="sa-card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 style="font-size:1rem;font-weight:700;color:var(--sa-ink);margin:0;">Usage Overview</h5>
                    <p style="font-size:0.75rem;color:var(--sa-ink-soft);margin:0;">Current plan: <strong><?= htmlspecialchars($summary['plan']['plan_name'] ?? 'Free') ?></strong></p>
                </div>
                <a href="<?= url('modules/settings/super_admin_panel.php') ?>?tab=limits" class="sa-btn-primary" style="font-size:0.75rem!important;padding:0.4rem 1rem!important;text-decoration:none;">
                    <i class="bi bi-sliders2"></i> Adjust Limits
                </a>
            </div>

            <div class="sa-stat-grid">
                <div class="sa-stat-card">
                    <div class="num"><?= number_format($summary['users']['current']) ?> / <?= number_format($summary['users']['max']) ?></div>
                    <p class="lbl">Users</p>
                    <div class="sa-progress mt-2">
                        <div class="sa-progress-bar <?= $summary['users']['pct'] >= 90 ? 'danger' : ($summary['users']['pct'] >= 70 ? 'warning' : 'success') ?>" style="width: <?= $summary['users']['pct'] ?>%"></div>
                    </div>
                    <small class="text-muted"><?= $summary['users']['pct'] ?>% used</small>
                </div>
                <div class="sa-stat-card">
                    <div class="num"><?= number_format($summary['api_tokens']['current']) ?> / <?= number_format($summary['api_tokens']['max']) ?></div>
                    <p class="lbl">API Tokens</p>
                    <div class="sa-progress mt-2">
                        <div class="sa-progress-bar <?= $summary['api_tokens']['pct'] >= 90 ? 'danger' : ($summary['api_tokens']['pct'] >= 70 ? 'warning' : 'success') ?>" style="width: <?= $summary['api_tokens']['pct'] ?>%"></div>
                    </div>
                    <small class="text-muted"><?= $summary['api_tokens']['pct'] ?>% used</small>
                </div>
                <div class="sa-stat-card">
                    <div class="num"><?= number_format($summary['storage_records']['current']) ?> / <?= number_format($summary['storage_records']['max']) ?></div>
                    <p class="lbl">DB Records</p>
                    <div class="sa-progress mt-2">
                        <div class="sa-progress-bar <?= $summary['storage_records']['pct'] >= 90 ? 'danger' : ($summary['storage_records']['pct'] >= 70 ? 'warning' : 'success') ?>" style="width: <?= $summary['storage_records']['pct'] ?>%"></div>
                    </div>
                    <small class="text-muted"><?= $summary['storage_records']['pct'] ?>% used</small>
                </div>
                <div class="sa-stat-card">
                    <div class="num"><?= $summary['storage_mb']['current'] ?> MB / <?= $summary['storage_mb']['max'] ?> MB</div>
                    <p class="lbl">Storage</p>
                    <div class="sa-progress mt-2">
                        <div class="sa-progress-bar <?= $summary['storage_mb']['pct'] >= 90 ? 'danger' : ($summary['storage_mb']['pct'] >= 70 ? 'warning' : 'success') ?>" style="width: <?= $summary['storage_mb']['pct'] ?>%"></div>
                    </div>
                    <small class="text-muted"><?= $summary['storage_mb']['pct'] ?>% used</small>
                </div>
            </div>

            <hr style="border-color:var(--sa-border);margin:1.25rem 0;">

            <div class="row g-3">
                <div class="col-md-6">
                    <h6 style="font-size:0.8125rem;font-weight:700;color:var(--sa-ink);margin:0 0 0.75rem 0;">
                        <i class="bi bi-gear me-1"></i> Account Details
                    </h6>
                    <table class="table table-sm mb-0" style="font-size:0.8125rem;">
                        <tr><td style="color:var(--sa-ink-soft);width:40%;">Company</td><td><strong><?= htmlspecialchars($account['company_name'] ?? 'CallNow') ?></strong></td></tr>
                        <tr><td style="color:var(--sa-ink-soft);">Billing Email</td><td><?= htmlspecialchars($account['billing_email'] ?? '—') ?></td></tr>
                        <tr><td style="color:var(--sa-ink-soft);">Payment Status</td><td>
                            <?php $ps = $account['payment_status'] ?? 'trial'; ?>
                            <span class="sa-badge sa-badge-<?= $ps==='active'?'success':($ps==='trial'?'info':($ps==='overdue'?'danger':'secondary')) ?>">
                                <?= ucfirst($ps) ?>
                            </span>
                        </td></tr>
                        <tr><td style="color:var(--sa-ink-soft);">Current Plan</td><td><strong><?= htmlspecialchars($currentPlan['plan_name'] ?? 'Free') ?></strong></td></tr>
                        <?php if ($account['trial_ends_at']): ?>
                        <tr><td style="color:var(--sa-ink-soft);">Trial Ends</td><td><?= date('d M Y', strtotime($account['trial_ends_at'])) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($account['current_period_ends_at']): ?>
                        <tr><td style="color:var(--sa-ink-soft);">Next Billing</td><td><?= date('d M Y', strtotime($account['current_period_ends_at'])) ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6 style="font-size:0.8125rem;font-weight:700;color:var(--sa-ink);margin:0 0 0.75rem 0;">
                        <i class="bi bi-lightning me-1"></i> API Limits
                    </h6>
                    <table class="table table-sm mb-0" style="font-size:0.8125rem;">
                        <tr><td style="color:var(--sa-ink-soft);width:40%;">Calls per minute</td><td><strong><?= number_format($summary['api_calls']['max_per_min']) ?></strong></td></tr>
                        <tr><td style="color:var(--sa-ink-soft);">Calls per hour</td><td><strong><?= number_format($summary['api_calls']['max_per_hour']) ?></strong></td></tr>
                        <tr><td style="color:var(--sa-ink-soft);">Active Tokens</td><td><strong><?= number_format($summary['api_tokens']['current']) ?> / <?= number_format($summary['api_tokens']['max']) ?></strong></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($tab === 'plans'): ?>
    <!-- ═══ Plans Management ═══ -->
    <div class="sa-card">
        <div class="sa-card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 style="font-size:1rem;font-weight:700;color:var(--sa-ink);margin:0;">Subscription Plans</h5>
                    <p style="font-size:0.75rem;color:var(--sa-ink-soft);margin:0;">Manage plans for future payment integration</p>
                </div>
                <button class="sa-btn-primary" style="font-size:0.75rem!important;padding:0.4rem 1rem!important;" data-bs-toggle="modal" data-bs-target="#createPlanModal">
                    <i class="bi bi-plus-circle"></i> New Plan
                </button>
            </div>

            <div class="row g-3">
                <?php foreach ($plans as $p): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="sa-plan-card <?= ($currentPlan && $currentPlan['id'] == $p['id']) ? 'current' : '' ?>">
                        <?php if ($p['is_default']): ?>
                            <span class="plan-badge default-badge">Default</span>
                        <?php elseif ($currentPlan && $currentPlan['id'] == $p['id']): ?>
                            <span class="plan-badge current-badge">Active</span>
                        <?php endif; ?>
                        <h4><?= htmlspecialchars($p['plan_name']) ?></h4>
                        <div class="plan-slug"><?= htmlspecialchars($p['plan_slug']) ?></div>
                        <div class="plan-price">
                            <?php if ($p['price_monthly'] > 0): ?>
                                $<?= number_format($p['price_monthly'], 2) ?><small>/mo</small>
                            <?php else: ?>
                                <small>Free</small>
                            <?php endif; ?>
                        </div>
                        <?php if ($p['price_yearly'] > 0): ?>
                            <div style="font-size:0.75rem;color:var(--sa-ink-soft);">$<?= number_format($p['price_yearly'], 2) ?>/yr</div>
                        <?php endif; ?>
                        <hr style="border-color:var(--sa-border);margin:0.75rem 0;">
                        <ul style="font-size:0.75rem;color:var(--sa-ink-soft);list-style:none;padding:0;margin:0 0 0.75rem 0;">
                            <li><i class="bi bi-people me-1"></i> <?= number_format($p['max_users']) ?> users</li>
                            <li><i class="bi bi-key me-1"></i> <?= number_format($p['max_api_tokens']) ?> API tokens</li>
                            <li><i class="bi bi-database me-1"></i> <?= number_format($p['max_db_records']) ?> records</li>
                            <li><i class="bi bi-hdd me-1"></i> <?= number_format($p['max_storage_mb']) ?> MB storage</li>
                        </ul>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Switch to this plan?');">
                            <input type="hidden" name="sa_action" value="switch_plan">
                            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="company_name" value="<?= htmlspecialchars($account['company_name'] ?? 'CallNow') ?>">
                            <input type="hidden" name="billing_email" value="<?= htmlspecialchars($account['billing_email'] ?? '') ?>">
                            <input type="hidden" name="payment_status" value="<?= htmlspecialchars($account['payment_status'] ?? 'trial') ?>">
                            <button type="submit" class="sa-btn-primary w-100" style="font-size:0.75rem!important;padding:0.35rem 0.75rem!important;" <?= $currentPlan && $currentPlan['id'] == $p['id'] ? 'disabled' : '' ?>>
                                <?= $currentPlan && $currentPlan['id'] == $p['id'] ? 'Current Plan' : 'Activate' ?>
                            </button>
                        </form>
                        <?php if (!$p['is_default']): ?>
                        <form method="POST" class="d-inline w-100 mt-1" onsubmit="return confirm('Delete this plan?');">
                            <input type="hidden" name="sa_action" value="delete_plan">
                            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <button type="submit" class="sa-btn-danger w-100" style="font-size:0.7rem!important;padding:0.3rem 0.75rem!important;">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php elseif ($tab === 'limits'): ?>
    <!-- ═══ Limits Configuration ═══ -->
    <div class="sa-card">
        <div class="sa-card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 style="font-size:1rem;font-weight:700;color:var(--sa-ink);margin:0;">Configure Limits</h5>
                    <p style="font-size:0.75rem;color:var(--sa-ink-soft);margin:0;">Editing: <strong><?= htmlspecialchars($currentPlan['plan_name'] ?? 'Free') ?></strong> plan</p>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="sa_action" value="update_limits">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="plan_id" value="<?= (int)($currentPlan['id'] ?? 0) ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="sa-label">Max Users</label>
                        <input type="number" name="max_users" class="form-control sa-input" value="<?= (int)($currentPlan['max_users'] ?? 10) ?>" min="1" max="99999">
                        <div class="sa-hint">Total user accounts allowed</div>
                    </div>
                    <div class="col-md-6">
                        <label class="sa-label">Max API Tokens</label>
                        <input type="number" name="max_api_tokens" class="form-control sa-input" value="<?= (int)($currentPlan['max_api_tokens'] ?? 5) ?>" min="0" max="9999">
                        <div class="sa-hint">Active mobile API tokens</div>
                    </div>
                    <div class="col-md-6">
                        <label class="sa-label">Max API Calls / Minute</label>
                        <input type="number" name="max_api_calls_per_minute" class="form-control sa-input" value="<?= (int)($currentPlan['max_api_calls_per_minute'] ?? 60) ?>" min="0" max="99999">
                        <div class="sa-hint">0 = unlimited</div>
                    </div>
                    <div class="col-md-6">
                        <label class="sa-label">Max API Calls / Hour</label>
                        <input type="number" name="max_api_calls_per_hour" class="form-control sa-input" value="<?= (int)($currentPlan['max_api_calls_per_hour'] ?? 1000) ?>" min="0" max="999999">
                        <div class="sa-hint">0 = unlimited</div>
                    </div>
                    <div class="col-md-6">
                        <label class="sa-label">Max DB Records</label>
                        <input type="number" name="max_db_records" class="form-control sa-input" value="<?= (int)($currentPlan['max_db_records'] ?? 50000) ?>" min="0" max="99999999">
                        <div class="sa-hint">Combined across Temporary, Main, and Leads</div>
                    </div>
                    <div class="col-md-6">
                        <label class="sa-label">Max Storage (MB)</label>
                        <input type="number" name="max_storage_mb" class="form-control sa-input" value="<?= (int)($currentPlan['max_storage_mb'] ?? 500) ?>" min="0" max="999999">
                        <div class="sa-hint">Estimated database size</div>
                    </div>
                </div>

                <hr style="border-color:var(--sa-border);margin:1.25rem 0;">
                <div class="text-end">
                    <button type="submit" class="sa-btn-primary"><i class="bi bi-check-lg"></i> Save Limits</button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($tab === 'access'): ?>
    <!-- ═══ Access Control ═══ -->
    <div class="sa-card">
        <div class="sa-card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 style="font-size:1rem;font-weight:700;color:var(--sa-ink);margin:0;">Feature Access Control</h5>
                    <p style="font-size:0.75rem;color:var(--sa-ink-soft);margin:0;">Enable or disable entire modules for all users</p>
                </div>
            </div>

            <div class="sa-access-grid">
                <?php foreach ($featureFlags as $feature => $enabled): ?>
                <div class="sa-access-card">
                    <div class="info">
                        <h5><i class="bi bi-<?= $feature==='users'?'people':($feature==='leads'?'journal-text':($feature==='database'?'database':($feature==='reports'?'bar-chart':($feature==='api'?'phone':($feature==='teams'?'diagram-3':($feature==='settings'?'gear':'cloud-upload')))))) ?> me-2"></i><?= ucfirst($feature) ?></h5>
                        <p><?= $enabled ? 'Currently enabled' : 'Currently disabled' ?></p>
                    </div>
                    <form method="POST" class="text-end">
                        <input type="hidden" name="sa_action" value="toggle_access">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="feature" value="<?= $feature ?>">
                        <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" <?= $enabled ? 'checked' : '' ?> onchange="this.form.submit()">
                        </div>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>

            <hr style="border-color:var(--sa-border);margin:1.25rem 0;">
            <div class="sa-hint">
                <i class="bi bi-info-circle me-1"></i>
                Disabling a feature here will block access to the corresponding module for all non-super-admin users. Super Admin always retains access.
            </div>
        </div>
    </div>

    <?php elseif ($tab === 'logs'): ?>
    <!-- ═══ Limit Logs ═══ -->
    <div class="sa-card">
        <div class="sa-card-body">
            <h5 style="font-size:1rem;font-weight:700;color:var(--sa-ink);margin:0 0 1rem 0;">Recent Limit Violations</h5>
            <?php if (empty($recentLogs)): ?>
                <p class="text-muted text-center py-3">No limit violations recorded yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="sa-log-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Action</th>
                                <th>Requested</th>
                                <th>Allowed</th>
                                <th>Current Usage</th>
                                <th>User</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td class="small"><?= date('d M Y H:i', strtotime($log['created_at'])) ?></td>
                                    <td><span class="sa-badge sa-badge-<?= $log['limit_type']==='users'?'info':($log['limit_type']==='api_tokens'?'warning':($log['limit_type']==='storage'?'danger':'secondary')) ?>"><?= ucfirst(str_replace('_', ' ', $log['limit_type'])) ?></span></td>
                                    <td><?= htmlspecialchars($log['action']) ?></td>
                                    <td><?= htmlspecialchars($log['requested_value'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($log['allowed_value'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($log['current_usage'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

</div>

<!-- Create Plan Modal -->
<div class="modal fade" id="createPlanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="sa_action" value="create_plan">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="sa-label">Plan Name <span class="text-danger">*</span></label>
                            <input type="text" name="plan_name" class="form-control sa-input" placeholder="e.g. Professional" required>
                        </div>
                        <div class="col-md-6">
                            <label class="sa-label">Plan Slug <span class="text-danger">*</span></label>
                            <input type="text" name="plan_slug" class="form-control sa-input" placeholder="e.g. professional" required>
                            <div class="sa-hint">Lowercase, no spaces. Used for payment integration.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="sa-label">Max Users</label>
                            <input type="number" name="max_users" class="form-control sa-input" value="10" min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="sa-label">Max API Tokens</label>
                            <input type="number" name="max_api_tokens" class="form-control sa-input" value="5" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="sa-label">Max API Calls / Min</label>
                            <input type="number" name="max_api_calls_per_minute" class="form-control sa-input" value="60" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="sa-label">Max API Calls / Hour</label>
                            <input type="number" name="max_api_calls_per_hour" class="form-control sa-input" value="1000" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="sa-label">Max DB Records</label>
                            <input type="number" name="max_db_records" class="form-control sa-input" value="50000" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="sa-label">Max Storage (MB)</label>
                            <input type="number" name="max_storage_mb" class="form-control sa-input" value="500" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="sa-label">Monthly Price ($)</label>
                            <input type="number" step="0.01" name="price_monthly" class="form-control sa-input" placeholder="0.00">
                            <div class="sa-hint">0 for free plan</div>
                        </div>
                        <div class="col-md-6">
                            <label class="sa-label">Yearly Price ($)</label>
                            <input type="number" step="0.01" name="price_yearly" class="form-control sa-input" placeholder="0.00">
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="plan_active" checked>
                                <label class="form-check-label" for="plan_active">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="sa-btn-outline" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="sa-btn-primary"><i class="bi bi-plus-circle me-1"></i> Create Plan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
