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
                $msg = 'Plan limits updated successfully!';
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
                $msg = 'Subscription plan switched successfully!';
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
                    $msg = 'Subscription plan created successfully!';
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
                $msg = 'Plan removed successfully.';
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
            $msg = 'Feature access setting updated.';
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
<?php $pageTitle = 'Super Admin Control Center - CallNow CRM'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<div class="container-fluid px-3 py-3" style="max-width: 1200px;">

    <!-- Zoho CRM Module Header -->
    <div class="module-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/settings/settings.php') ?>">Administration</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Super Admin Control</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2">
                <h1 class="page-title mb-0">Super Admin Panel</h1>
                <span class="badge bg-primary-subtle text-primary border">Plan: <?= htmlspecialchars($summary['plan']['plan_name'] ?? 'Default') ?></span>
            </div>
            <p class="text-muted small mb-0">Manage multi-tier subscription quotas, hard system limits, and global feature master switches</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= url('modules/settings/settings.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-sliders me-1"></i> System Settings
            </a>
            <a href="<?= url('modules/settings/permissions_manager.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-shield-lock me-1"></i> Access Control
            </a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= htmlspecialchars($msg_type ?: 'info'); ?> alert-dismissible fade show mb-3" role="alert">
            <i class="bi <?= $msg_type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
            <?= htmlspecialchars($msg); ?>
            <button class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Zoho Navigation Tabs -->
    <ul class="nav nav-tabs mb-3 border-bottom">
        <li class="nav-item">
            <a class="nav-link <?= $tab==='overview'?'active fw-semibold text-primary':'' ?>" href="<?= url('modules/settings/super_admin_panel.php') ?>?tab=overview">
                <i class="bi bi-speedometer2 me-1"></i> Quota Overview
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='plans'?'active fw-semibold text-primary':'' ?>" href="<?= url('modules/settings/super_admin_panel.php') ?>?tab=plans">
                <i class="bi bi-box-seam me-1"></i> Subscription Plans
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='limits'?'active fw-semibold text-primary':'' ?>" href="<?= url('modules/settings/super_admin_panel.php') ?>?tab=limits">
                <i class="bi bi-sliders2 me-1"></i> Plan Limits
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='access'?'active fw-semibold text-primary':'' ?>" href="<?= url('modules/settings/super_admin_panel.php') ?>?tab=access">
                <i class="bi bi-shield-check me-1"></i> Master Feature Toggles
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='logs'?'active fw-semibold text-primary':'' ?>" href="<?= url('modules/settings/super_admin_panel.php') ?>?tab=logs">
                <i class="bi bi-journal-text me-1"></i> Limit Violation Logs
            </a>
        </li>
    </ul>

    <?php if ($tab === 'overview'): ?>
    <!-- ═══ Overview Dashboard ═══ -->
    <div class="crm-card mb-3">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <span class="fw-semibold text-dark">Tenant Resource Consumption</span>
            <a href="<?= url('modules/settings/super_admin_panel.php') ?>?tab=limits" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-sliders2 me-1"></i> Adjust Thresholds
            </a>
        </div>
        <div class="card-body p-3">
            <div class="row g-2 mb-4">
                <div class="col-6 col-md-3">
                    <div class="metric-card p-3">
                        <div class="metric-label">Users Registered</div>
                        <div class="metric-value text-dark"><?= number_format($summary['users']['current']) ?> <span class="fs-6 text-muted font-monospace">/ <?= number_format($summary['users']['max']) ?></span></div>
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar <?= $summary['users']['pct'] >= 90 ? 'bg-danger' : ($summary['users']['pct'] >= 70 ? 'bg-warning' : 'bg-primary') ?>" style="width: <?= min(100, $summary['users']['pct']) ?>%"></div>
                        </div>
                        <div class="text-muted small mt-1"><?= $summary['users']['pct'] ?>% of plan quota used</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="metric-card p-3">
                        <div class="metric-label">Mobile API Tokens</div>
                        <div class="metric-value text-dark"><?= number_format($summary['api_tokens']['current']) ?> <span class="fs-6 text-muted font-monospace">/ <?= number_format($summary['api_tokens']['max']) ?></span></div>
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar <?= $summary['api_tokens']['pct'] >= 90 ? 'bg-danger' : ($summary['api_tokens']['pct'] >= 70 ? 'bg-warning' : 'bg-success') ?>" style="width: <?= min(100, $summary['api_tokens']['pct']) ?>%"></div>
                        </div>
                        <div class="text-muted small mt-1"><?= $summary['api_tokens']['pct'] ?>% of token quota used</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="metric-card p-3">
                        <div class="metric-label">Total DB Records</div>
                        <div class="metric-value text-dark"><?= number_format($summary['storage_records']['current']) ?> <span class="fs-6 text-muted font-monospace">/ <?= number_format($summary['storage_records']['max']) ?></span></div>
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar <?= $summary['storage_records']['pct'] >= 90 ? 'bg-danger' : ($summary['storage_records']['pct'] >= 70 ? 'bg-warning' : 'bg-info') ?>" style="width: <?= min(100, $summary['storage_records']['pct']) ?>%"></div>
                        </div>
                        <div class="text-muted small mt-1"><?= $summary['storage_records']['pct'] ?>% capacity</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="metric-card p-3">
                        <div class="metric-label">Storage Allocated</div>
                        <div class="metric-value text-dark"><?= $summary['storage_mb']['current'] ?> MB <span class="fs-6 text-muted font-monospace">/ <?= $summary['storage_mb']['max'] ?> MB</span></div>
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar <?= $summary['storage_mb']['pct'] >= 90 ? 'bg-danger' : ($summary['storage_mb']['pct'] >= 70 ? 'bg-warning' : 'bg-secondary') ?>" style="width: <?= min(100, $summary['storage_mb']['pct']) ?>%"></div>
                        </div>
                        <div class="text-muted small mt-1"><?= $summary['storage_mb']['pct'] ?>% capacity</div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="p-3 border rounded bg-light">
                        <h6 class="fw-bold text-dark small mb-3"><i class="bi bi-building text-primary me-2"></i>Account &amp; Billing Profile</h6>
                        <table class="table table-sm crm-table align-middle mb-0">
                            <tbody>
                                <tr>
                                    <th class="text-muted" style="width: 40%;">Company Entity</th>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($account['company_name'] ?? 'CallNow') ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Billing Email</th>
                                    <td class="font-monospace small"><?= htmlspecialchars($account['billing_email'] ?? '—') ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Payment Status</th>
                                    <td>
                                        <?php $ps = $account['payment_status'] ?? 'trial'; ?>
                                        <span class="badge <?= $ps==='active'?'bg-success-subtle text-success':($ps==='trial'?'bg-info-subtle text-info':($ps==='overdue'?'bg-danger-subtle text-danger':'bg-secondary-subtle text-secondary')) ?> border">
                                            <?= ucfirst($ps) ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Active Plan</th>
                                    <td><span class="badge bg-primary-subtle text-primary border"><?= htmlspecialchars($currentPlan['plan_name'] ?? 'Free') ?></span></td>
                                </tr>
                                <?php if ($account['trial_ends_at']): ?>
                                <tr>
                                    <th class="text-muted">Trial Expiry</th>
                                    <td class="small text-muted"><?= date('d M Y', strtotime($account['trial_ends_at'])) ?></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-3 border rounded bg-light">
                        <h6 class="fw-bold text-dark small mb-3"><i class="bi bi-lightning-charge text-primary me-2"></i>API Gateway Thresholds</h6>
                        <table class="table table-sm crm-table align-middle mb-0">
                            <tbody>
                                <tr>
                                    <th class="text-muted" style="width: 45%;">Requests / minute</th>
                                    <td class="fw-bold font-monospace"><?= number_format($summary['api_calls']['max_per_min']) ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Requests / hour</th>
                                    <td class="fw-bold font-monospace"><?= number_format($summary['api_calls']['max_per_hour']) ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Active Tokens / Allowed</th>
                                    <td class="font-monospace"><?= number_format($summary['api_tokens']['current']) ?> / <?= number_format($summary['api_tokens']['max']) ?></td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Rate Enforced</th>
                                    <td><span class="badge bg-success-subtle text-success border">Protected</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($tab === 'plans'): ?>
    <!-- ═══ Plans Management ═══ -->
    <div class="crm-card mb-3">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-semibold text-dark">Subscription Tier Offerings</span>
                <span class="text-muted small ms-2">Configure feature plans and tier limits</span>
            </div>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createPlanModal">
                <i class="bi bi-plus-circle me-1"></i> New Plan
            </button>
        </div>
        <div class="card-body p-3">
            <div class="row g-3">
                <?php foreach ($plans as $p): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="crm-card p-3 h-100 <?= ($currentPlan && $currentPlan['id'] == $p['id']) ? 'border-primary' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="fw-bold text-dark mb-0 small"><?= htmlspecialchars($p['plan_name']) ?></h5>
                            <?php if ($p['is_default']): ?>
                                <span class="badge bg-light text-secondary border">Default</span>
                            <?php elseif ($currentPlan && $currentPlan['id'] == $p['id']): ?>
                                <span class="badge bg-success-subtle text-success border">Active</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted font-monospace small mb-2"><?= htmlspecialchars($p['plan_slug']) ?></div>
                        <div class="fw-bold fs-5 text-dark mb-3">
                            <?php if ($p['price_monthly'] > 0): ?>
                                $<?= number_format($p['price_monthly'], 2) ?><small class="text-muted fs-6">/mo</small>
                            <?php else: ?>
                                Free
                            <?php endif; ?>
                        </div>
                        <ul class="list-unstyled small text-muted mb-3 d-flex flex-column gap-1">
                            <li><i class="bi bi-check2 text-primary me-1"></i> <?= number_format($p['max_users']) ?> Users</li>
                            <li><i class="bi bi-check2 text-primary me-1"></i> <?= number_format($p['max_api_tokens']) ?> API Tokens</li>
                            <li><i class="bi bi-check2 text-primary me-1"></i> <?= number_format($p['max_db_records']) ?> Records</li>
                            <li><i class="bi bi-check2 text-primary me-1"></i> <?= number_format($p['max_storage_mb']) ?> MB Storage</li>
                        </ul>
                        <div class="d-flex flex-column gap-1 pt-2 border-top mt-auto">
                            <form method="POST" onsubmit="return confirm('Switch active tier to «<?= htmlspecialchars($p['plan_name']) ?>»?');">
                                <input type="hidden" name="sa_action" value="switch_plan">
                                <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="company_name" value="<?= htmlspecialchars($account['company_name'] ?? 'CallNow') ?>">
                                <input type="hidden" name="billing_email" value="<?= htmlspecialchars($account['billing_email'] ?? '') ?>">
                                <input type="hidden" name="payment_status" value="<?= htmlspecialchars($account['payment_status'] ?? 'trial') ?>">
                                <button type="submit" class="btn btn-sm <?= $currentPlan && $currentPlan['id'] == $p['id'] ? 'btn-light text-muted border' : 'btn-primary' ?> w-100" <?= $currentPlan && $currentPlan['id'] == $p['id'] ? 'disabled' : '' ?>>
                                    <?= $currentPlan && $currentPlan['id'] == $p['id'] ? 'Current Plan' : 'Activate Plan' ?>
                                </button>
                            </form>
                            <?php if (!$p['is_default']): ?>
                            <form method="POST" onsubmit="return confirm('Permanently remove plan «<?= htmlspecialchars($p['plan_name']) ?>»?');">
                                <input type="hidden" name="sa_action" value="delete_plan">
                                <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100 py-0.5">Delete</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Create Plan Modal -->
    <div class="modal fade" id="createPlanModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="sa_action" value="create_plan">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold"><i class="bi bi-box-seam text-primary me-2"></i> Define New Subscription Tier</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-3">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Plan Display Name <span class="text-danger">*</span></label>
                                <input type="text" name="plan_name" class="form-control form-control-sm" placeholder="e.g. Professional Growth" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Plan Slug <span class="text-danger">*</span></label>
                                <input type="text" name="plan_slug" class="form-control form-control-sm font-monospace" placeholder="e.g. professional_growth" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Max Users</label>
                                <input type="number" name="max_users" class="form-control form-control-sm" value="10" min="1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Max API Tokens</label>
                                <input type="number" name="max_api_tokens" class="form-control form-control-sm" value="5" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Max API Calls / Min</label>
                                <input type="number" name="max_api_calls_per_minute" class="form-control form-control-sm" value="60" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Max API Calls / Hour</label>
                                <input type="number" name="max_api_calls_per_hour" class="form-control form-control-sm" value="1000" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Max DB Records</label>
                                <input type="number" name="max_db_records" class="form-control form-control-sm" value="50000" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Max Storage (MB)</label>
                                <input type="number" name="max_storage_mb" class="form-control form-control-sm" value="500" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Monthly Price ($)</label>
                                <input type="number" step="0.01" name="price_monthly" class="form-control form-control-sm" placeholder="0.00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Yearly Price ($)</label>
                                <input type="number" step="0.01" name="price_yearly" class="form-control form-control-sm" placeholder="0.00">
                            </div>
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="plan_active" checked>
                                    <label class="form-check-label small" for="plan_active">Enable this plan for subscription</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i> Create Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php elseif ($tab === 'limits'): ?>
    <!-- ═══ Limits Configuration ═══ -->
    <div class="crm-card mb-3">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-semibold text-dark">Configure Threshold Limits</span>
                <span class="text-muted small ms-2">Currently Editing: <strong><?= htmlspecialchars($currentPlan['plan_name'] ?? 'Free') ?></strong></span>
            </div>
        </div>
        <div class="card-body p-3">
            <form method="POST">
                <input type="hidden" name="sa_action" value="update_limits">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="plan_id" value="<?= (int)($currentPlan['id'] ?? 0) ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Maximum User Accounts</label>
                        <input type="number" name="max_users" class="form-control form-control-sm" value="<?= (int)($currentPlan['max_users'] ?? 10) ?>" min="1" max="99999">
                        <div class="text-muted small mt-1">Ceiling on registered agent logins</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Maximum API Tokens</label>
                        <input type="number" name="max_api_tokens" class="form-control form-control-sm" value="<?= (int)($currentPlan['max_api_tokens'] ?? 5) ?>" min="0" max="9999">
                        <div class="text-muted small mt-1">Max concurrent mobile app bearer tokens</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">API Requests / Minute</label>
                        <input type="number" name="max_api_calls_per_minute" class="form-control form-control-sm" value="<?= (int)($currentPlan['max_api_calls_per_minute'] ?? 60) ?>" min="0" max="99999">
                        <div class="text-muted small mt-1">Set 0 for unlimited calls</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">API Requests / Hour</label>
                        <input type="number" name="max_api_calls_per_hour" class="form-control form-control-sm" value="<?= (int)($currentPlan['max_api_calls_per_hour'] ?? 1000) ?>" min="0" max="999999">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Maximum Database Records</label>
                        <input type="number" name="max_db_records" class="form-control form-control-sm" value="<?= (int)($currentPlan['max_db_records'] ?? 50000) ?>" min="0" max="99999999">
                        <div class="text-muted small mt-1">Combined total across Temporary, Main, and Leads pools</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Maximum Database Storage (MB)</label>
                        <input type="number" name="max_storage_mb" class="form-control form-control-sm" value="<?= (int)($currentPlan['max_storage_mb'] ?? 500) ?>" min="0" max="999999">
                    </div>
                </div>

                <div class="d-flex justify-content-end pt-2 border-top">
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-check-lg me-1"></i> Save Plan Thresholds
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($tab === 'access'): ?>
    <!-- ═══ Access Control ═══ -->
    <div class="crm-card mb-3">
        <div class="card-header bg-white py-2 px-3 border-bottom">
            <span class="fw-semibold text-dark">Global Module Master Switches</span>
        </div>
        <div class="card-body p-3">
            <div class="row g-3">
                <?php foreach ($featureFlags as $feature => $enabled): ?>
                <div class="col-md-6 col-lg-3">
                    <div class="p-3 border rounded bg-light d-flex align-items-center justify-content-between">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark small">
                                <i class="bi bi-<?= $feature==='users'?'people':($feature==='leads'?'journal-text':($feature==='database'?'database':($feature==='reports'?'bar-chart':($feature==='api'?'phone':($feature==='teams'?'diagram-3':($feature==='settings'?'gear':'cloud-upload')))))) ?> text-primary me-1"></i>
                                <?= ucfirst($feature) ?>
                            </h6>
                            <span class="text-muted" style="font-size: 0.72rem;"><?= $enabled ? 'Module Enabled' : 'Module Blocked' ?></span>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="sa_action" value="toggle_access">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="feature" value="<?= $feature ?>">
                            <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" <?= $enabled ? 'checked' : '' ?> onchange="this.form.submit()">
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="alert alert-info py-2 px-3 mt-4 mb-0 small">
                <i class="bi bi-info-circle me-1"></i>
                Disabling a module blocks access for all non-super-admin users platform-wide. Super Admin always retains unrestricted root access.
            </div>
        </div>
    </div>

    <?php elseif ($tab === 'logs'): ?>
    <!-- ═══ Limit Logs ═══ -->
    <div class="crm-card mb-3">
        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
            <span class="fw-semibold text-dark">Quota Enforcement Log</span>
            <span class="badge bg-secondary-subtle text-secondary border"><?= count($recentLogs) ?> Violations</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($recentLogs)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-shield-check fs-2 d-block mb-2 text-success"></i>
                    No limit violations recorded. Platform operating smoothly within designated quotas.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table crm-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 140px;">Timestamp</th>
                                <th style="width: 120px;">Limit Type</th>
                                <th>Action Attempted</th>
                                <th style="width: 90px;">Requested</th>
                                <th style="width: 90px;">Threshold</th>
                                <th style="width: 110px;">Current Usage</th>
                                <th>User</th>
                                <th style="width: 110px;">IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td class="small text-muted font-monospace"><?= date('d M H:i', strtotime($log['created_at'])) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $log['limit_type']==='users'?'info':($log['limit_type']==='api_tokens'?'warning':($log['limit_type']==='storage'?'danger':'secondary')) ?>-subtle text-dark border">
                                            <?= ucfirst(str_replace('_', ' ', $log['limit_type'])) ?>
                                        </span>
                                    </td>
                                    <td class="small text-dark fw-medium"><?= htmlspecialchars($log['action']) ?></td>
                                    <td class="small font-monospace"><?= htmlspecialchars($log['requested_value'] ?? '—') ?></td>
                                    <td class="small font-monospace"><?= htmlspecialchars($log['allowed_value'] ?? '—') ?></td>
                                    <td class="small font-monospace"><?= htmlspecialchars($log['current_usage'] ?? '—') ?></td>
                                    <td class="small"><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td>
                                    <td class="small text-muted font-monospace"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
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

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
