<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/lead_common.php';

$allowedTBL_ROLES = ['Admin', 'Manager', 'Supervisor', 'Officer', 'Super Admin'];
if (!in_array(USER_ROLE, $allowedTBL_ROLES, true) && !isSuperAdmin()) {
    header('Location: ../../dashboard');
    exit;
}

ensureLeadModuleSchema($link);
mysqli_set_charset($link, 'utf8mb4');

$where = '';
$accessibleUserIds = getAccessibleUserIds($link);
if (!isAdmin() && !isSuperAdmin() && !empty($accessibleUserIds)) {
    $inIds = implode(',', array_map('intval', $accessibleUserIds));
    $where = "WHERE l.assigned_to IN ($inIds)";
}

$statusMeta = leadStatusMeta();
$statusCounts = array_fill_keys(array_keys($statusMeta), 0);

$countSql = "
    SELECT
        SUM(CASE WHEN l.lead_status_new = 'LEAD' THEN 1 ELSE 0 END) AS cnt_LEAD,
        SUM(CASE WHEN l.lead_status_new = 'FOLLOWUP' THEN 1 ELSE 0 END) AS cnt_FOLLOWUP,
        SUM(CASE WHEN l.lead_status_new = 'INTERNAL_UNDERWRITING' THEN 1 ELSE 0 END) AS cnt_INTERNAL_UNDERWRITING,
        SUM(CASE WHEN l.lead_status_new = 'LOGIN' THEN 1 ELSE 0 END) AS cnt_LOGIN,
        SUM(CASE WHEN l.lead_status_new = 'BANK_UNDERWRITING' THEN 1 ELSE 0 END) AS cnt_BANK_UNDERWRITING,
        SUM(CASE WHEN l.lead_status_new = 'SANCTIONED' THEN 1 ELSE 0 END) AS cnt_SANCTIONED,
        SUM(CASE WHEN l.lead_status_new = 'DISBURSED' THEN 1 ELSE 0 END) AS cnt_DISBURSED,
        SUM(CASE WHEN l.lead_status_new = 'REJECT' THEN 1 ELSE 0 END) AS cnt_REJECT,
        COUNT(*) AS total_all,
        SUM(CASE WHEN DATE(l.next_followup_at) = CURDATE() THEN 1 ELSE 0 END) AS cnt_today_followup,
        SUM(CASE WHEN DATE_FORMAT(l.login_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN 1 ELSE 0 END) AS cnt_month_logins,
        SUM(CASE WHEN DATE(l.created_at) = CURDATE() THEN 1 ELSE 0 END) AS cnt_today_new,
        SUM(CASE WHEN l.rework_flag = 1 THEN 1 ELSE 0 END) AS cnt_rework_all,
        SUM(CASE WHEN l.rework_flag = 1 AND l.rework_stage = 'INTERNAL' THEN 1 ELSE 0 END) AS cnt_rework_internal,
        SUM(CASE WHEN l.rework_flag = 1 AND l.rework_stage = 'BANK' THEN 1 ELSE 0 END) AS cnt_rework_bank,
        SUM(CASE WHEN l.login_status = 'PENDING' THEN 1 ELSE 0 END) AS cnt_login_pending,
        SUM(CASE WHEN l.login_status = 'SUCCESS' THEN 1 ELSE 0 END) AS cnt_login_success,
        SUM(CASE WHEN l.login_status = 'REJECTED' THEN 1 ELSE 0 END) AS cnt_login_rejected,
        SUM(CASE WHEN l.login_status NOT IN ('PENDING','SUCCESS','REJECTED') OR l.login_status IS NULL THEN 1 ELSE 0 END) AS cnt_login_none
    FROM " . tn('TBL_LEADS') . " l
    {$where}
";
$res = mysqli_query($link, $countSql);
$row = $res ? mysqli_fetch_assoc($res) : [];

$totalLeads = (int)($row['total_all'] ?? 0);
$todayFollowups = (int)($row['cnt_today_followup'] ?? 0);
$thisMonthLogins = (int)($row['cnt_month_logins'] ?? 0);
$todayNew = (int)($row['cnt_today_new'] ?? 0);
$reworkAll = (int)($row['cnt_rework_all'] ?? 0);
$reworkInternal = (int)($row['cnt_rework_internal'] ?? 0);
$reworkBank = (int)($row['cnt_rework_bank'] ?? 0);
$loginPending = (int)($row['cnt_login_pending'] ?? 0);
$loginSuccess = (int)($row['cnt_login_success'] ?? 0);
$loginRejected = (int)($row['cnt_login_rejected'] ?? 0);
$loginNone = (int)($row['cnt_login_none'] ?? 0);

foreach (['LEAD','FOLLOWUP','INTERNAL_UNDERWRITING','LOGIN','BANK_UNDERWRITING','SANCTIONED','DISBURSED','REJECT'] as $k) {
    $statusCounts[$k] = (int)($row["cnt_$k"] ?? 0);
}

$activePipeline = $statusCounts['LEAD'] + $statusCounts['FOLLOWUP'] + $statusCounts['INTERNAL_UNDERWRITING'] + $statusCounts['LOGIN'] + $statusCounts['BANK_UNDERWRITING'] + $statusCounts['SANCTIONED'];
$conversionBase = max(1, $statusCounts['DISBURSED'] + $activePipeline + $statusCounts['REJECT']);
$conversionRate = round(($statusCounts['DISBURSED'] / $conversionBase) * 100, 1);

$pipelineSteps = [
    ['key' => 'LEAD',         'icon' => 'bi-person-plus',   'color' => '#2563eb', 'label' => 'New Lead'],
    ['key' => 'FOLLOWUP',     'icon' => 'bi-arrow-repeat',  'color' => '#0284c7', 'label' => 'Follow-up'],
    ['key' => 'INTERNAL_UNDERWRITING', 'icon' => 'bi-building-check', 'color' => '#64748b', 'label' => 'Internal UW'],
    ['key' => 'LOGIN',        'icon' => 'bi-box-arrow-in-right', 'color' => '#d97706', 'label' => 'Bank Login'],
    ['key' => 'BANK_UNDERWRITING', 'icon' => 'bi-bank',     'color' => '#7c3aed', 'label' => 'Bank UW'],
    ['key' => 'SANCTIONED',   'icon' => 'bi-check-circle',  'color' => '#16a34a', 'label' => 'Sanctioned'],
    ['key' => 'DISBURSED',    'icon' => 'bi-cash-coin',     'color' => '#059669', 'label' => 'Disbursed'],
];
$pipelineMax = max(1, max(array_map(function($s) use ($statusCounts) { return $statusCounts[$s['key']] ?? 0; }, $pipelineSteps)));
$userName = $_SESSION['name'] ?? 'User';
?>
<?php $pageTitle = 'Leads Analytics'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<div class="crm-module-header">
    <div>
        <div class="crm-module-header-title">
            <i class="bi bi-bar-chart-line text-primary"></i>
            <h1>Leads Analytics & Operations</h1>
        </div>
        <p class="crm-module-header-desc">Pipeline velocity, conversion funnel, bank login performance and daily follow-up tracking</p>
    </div>
    <div class="crm-module-header-actions">
        <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="btn btn-secondary btn-sm">
            <i class="bi bi-kanban"></i> Pipeline Board
        </a>
        <a href="<?= url('modules/leads/lead_list.php') ?>" class="btn btn-secondary btn-sm">
            <i class="bi bi-person-lines-fill"></i> View Leads Register
        </a>
        <?php if (can('manage_leads')): ?>
        <a href="<?= url('modules/leads/lead_insert.php') ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> + Add Lead
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Welcome / Scope Strip -->
<div class="welcome-strip">
    <div>
        <h2 class="welcome-strip-title">Hello, <?= htmlspecialchars($userName) ?></h2>
        <p class="welcome-strip-desc">Access Scope: <span class="badge bg-secondary"><?= htmlspecialchars(USER_ROLE) ?></span> &bull; <?= number_format($totalLeads) ?> customer leads in your assigned portfolio.</p>
    </div>
    <form method="get" action="<?= url('modules/leads/lead_list.php') ?>" class="d-flex align-items-center gap-2">
        <div class="input-group" style="width: 280px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" name="q" class="form-control" placeholder="Search lead name, phone...">
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    </form>
</div>

<!-- KPI Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-xl col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon"><i class="bi bi-person-lines-fill"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Total Leads</div>
                <div class="metric-card-value"><?= number_format($totalLeads) ?></div>
                <div class="metric-card-sub"><span class="text-success fw-semibold">+<?= number_format($todayNew) ?></span> created today</div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon info"><i class="bi bi-kanban"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Active Pipeline</div>
                <div class="metric-card-value"><?= number_format($activePipeline) ?></div>
                <div class="metric-card-sub"><?= round(($activePipeline / max(1, $totalLeads)) * 100, 0) ?>% active ratio</div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon <?= $todayFollowups > 0 ? 'warning' : 'success' ?>"><i class="bi bi-clock-history"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Today's Follow-ups</div>
                <div class="metric-card-value"><?= number_format($todayFollowups) ?></div>
                <div class="metric-card-sub"><?= $todayFollowups > 0 ? 'Action required today' : 'All clear' ?></div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon warning"><i class="bi bi-bank"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Monthly Logins</div>
                <div class="metric-card-value"><?= number_format($thisMonthLogins) ?></div>
                <div class="metric-card-sub">Bank submissions this month</div>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-4 col-sm-6">
        <div class="metric-card">
            <div class="metric-card-icon success"><i class="bi bi-check2-circle"></i></div>
            <div class="metric-card-body">
                <div class="metric-card-label">Conversion Rate</div>
                <div class="metric-card-value"><?= number_format($conversionRate, 1) ?>%</div>
                <div class="metric-card-sub"><?= number_format($statusCounts['DISBURSED']) ?> disbursed</div>
            </div>
        </div>
    </div>
</div>

<!-- Pipeline Progression Funnel -->
<div class="card mb-4">
    <div class="card-header">
        <span><i class="bi bi-funnel text-primary me-2"></i>Pipeline Stage Progression</span>
        <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="btn btn-sm btn-ghost">Full Kanban Board <i class="bi bi-arrow-right ms-1"></i></a>
    </div>
    <div class="card-body p-3">
        <div class="row g-2 align-items-center">
            <?php foreach ($pipelineSteps as $step): ?>
                <?php
                $cnt = $statusCounts[$step['key']] ?? 0;
                $pct = $pipelineMax > 0 ? round(($cnt / $pipelineMax) * 100) : 0;
                ?>
                <div class="col">
                    <div class="p-2 border rounded bg-subtle text-center">
                        <div class="text-muted text-uppercase mb-1" style="font-size:0.625rem; font-weight:600;">
                            <i class="bi <?= $step['icon'] ?> me-1" style="color:<?= $step['color'] ?>"></i>
                            <?= htmlspecialchars($step['label']) ?>
                        </div>
                        <div class="fs-5 fw-bold text-dark"><?= number_format($cnt) ?></div>
                        <div class="progress mt-2" style="height: 4px;">
                            <div class="progress-bar" style="width:<?= max(10, $pct) ?>%; background-color:<?= $step['color'] ?>;"></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Bank Login & Rework Breakdown -->
<div class="card mb-4">
    <div class="card-header">
        <span><i class="bi bi-shield-check text-primary me-2"></i>Bank Underwriting & Rework Status</span>
        <a href="<?= url('modules/leads/lead_list.php') ?>?rework=yes" class="btn btn-sm btn-ghost">View Rework Queue</a>
    </div>
    <div class="card-body p-3">
        <div class="row g-3">
            <div class="col-sm-4 col-md-2">
                <div class="p-2 border rounded bg-subtle text-center">
                    <div class="text-muted text-uppercase small fw-semibold">Rework Pending</div>
                    <div class="fs-4 fw-bold text-danger"><?= number_format($reworkAll) ?></div>
                </div>
            </div>
            <div class="col-sm-4 col-md-2">
                <div class="p-2 border rounded bg-subtle text-center">
                    <div class="text-muted text-uppercase small fw-semibold">Internal Rework</div>
                    <div class="fs-4 fw-bold text-warning"><?= number_format($reworkInternal) ?></div>
                </div>
            </div>
            <div class="col-sm-4 col-md-2">
                <div class="p-2 border rounded bg-subtle text-center">
                    <div class="text-muted text-uppercase small fw-semibold">Bank Rework</div>
                    <div class="fs-4 fw-bold text-purple"><?= number_format($reworkBank) ?></div>
                </div>
            </div>
            <div class="col-sm-4 col-md-2">
                <div class="p-2 border rounded bg-subtle text-center">
                    <div class="text-muted text-uppercase small fw-semibold">Login Success</div>
                    <div class="fs-4 fw-bold text-success"><?= number_format($loginSuccess) ?></div>
                </div>
            </div>
            <div class="col-sm-4 col-md-2">
                <div class="p-2 border rounded bg-subtle text-center">
                    <div class="text-muted text-uppercase small fw-semibold">Login Pending</div>
                    <div class="fs-4 fw-bold text-primary"><?= number_format($loginPending) ?></div>
                </div>
            </div>
            <div class="col-sm-4 col-md-2">
                <div class="p-2 border rounded bg-subtle text-center">
                    <div class="text-muted text-uppercase small fw-semibold">Login Rejected</div>
                    <div class="fs-4 fw-bold text-danger"><?= number_format($loginRejected) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Two-Column Operational Activity Lists -->
<div class="row g-3">
    <!-- Today's Follow-ups -->
    <div class="col-lg-6">
        <div class="card h-100 mb-0">
            <div class="card-header">
                <span><i class="bi bi-clock-history text-primary me-2"></i>Today's Scheduled Follow-ups</span>
                <a href="<?= url('modules/leads/lead_list.php') ?>?followup=today" class="btn btn-sm btn-ghost">View All</a>
            </div>
            <div class="card-body p-0" id="todayFollowupsList">
                <div class="p-4 text-center text-muted">
                    <div class="spinner-border spinner-border-sm text-primary me-2"></div> Loading scheduled follow-ups...
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Leads -->
    <div class="col-lg-6">
        <div class="card h-100 mb-0">
            <div class="card-header">
                <span><i class="bi bi-person-lines-fill text-primary me-2"></i>Recent Leads Activity</span>
                <a href="<?= url('modules/leads/lead_list.php') ?>" class="btn btn-sm btn-ghost">View Register</a>
            </div>
            <div class="card-body p-0" id="recentLeadsList">
                <div class="p-4 text-center text-muted">
                    <div class="spinner-border spinner-border-sm text-primary me-2"></div> Loading recent leads...
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const APP_BASE_URL = '<?= defined('APP_BASE') ? APP_BASE : '' ?>';
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?? '' ?>';
const LEAD_VIEW_URL = '<?= url('modules/leads/lead_view.php') ?>';
const LEAD_PIPELINE_URL = '<?= url('modules/leads/lead_pipeline.php') ?>';

function escapeHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function leadStatusBadgeClass(status) {
    const cls = {
        'LEAD': 'bg-primary', 'FOLLOWUP': 'bg-info', 'LOGIN': 'bg-warning',
        'INTERNAL_UNDERWRITING': 'bg-secondary', 'BANK_UNDERWRITING': 'bg-purple', 'SANCTIONED': 'bg-success', 'DISBURSED': 'bg-dark', 'REJECT': 'bg-danger'
    };
    return cls[status] || 'bg-secondary';
}

function renderTodayFollowups(data) {
    const container = document.getElementById('todayFollowupsList');
    if (!data || !data.length) {
        container.innerHTML = '<div class="empty-state p-4 text-center text-muted"><i class="bi bi-check2-all fs-4 d-block mb-1"></i>No pending follow-ups for today.</div>';
        return;
    }
    container.innerHTML = '<div class="list-group list-group-flush">' + data.map(function(l) {
        var timeStr = l.next_followup_at ? new Date(l.next_followup_at).toLocaleString('en-GB', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : 'No time set';
        return '<div class="list-group-item px-3 py-2 border-bottom">'
            + '<div class="d-flex justify-content-between align-items-start gap-2">'
            + '<div>'
            + '<a href="' + LEAD_VIEW_URL + '?id=' + l.lead_id + '" class="fw-semibold text-dark small text-decoration-none">' + escapeHtml(l.customer_name) + '</a>'
            + '<div class="text-muted small" style="font-size:0.6875rem;">' + escapeHtml(l.assigned_name) + ' &bull; ' + timeStr + '</div>'
            + '</div>'
            + '<span class="badge ' + leadStatusBadgeClass(l.lead_status_new) + '">' + escapeHtml(l.lead_status_new) + '</span>'
            + '</div>'
            + '<div class="d-flex align-items-center justify-content-between mt-1">'
            + '<a href="tel:' + escapeHtml(l.mobile) + '" class="badge bg-secondary text-decoration-none"><i class="bi bi-telephone me-1"></i>' + escapeHtml(l.mobile) + '</a>'
            + '<a href="' + LEAD_VIEW_URL + '?id=' + l.lead_id + '" class="btn btn-sm btn-primary py-0 px-2" style="font-size:0.75rem;"><i class="bi bi-pencil-square me-1"></i>Open</a>'
            + '</div>'
            + '</div>';
    }).join('') + '</div>';
}

function renderRecentLeads(data) {
    var container = document.getElementById('recentLeadsList');
    if (!data || !data.length) {
        container.innerHTML = '<div class="empty-state p-4 text-center text-muted"><i class="bi bi-inbox fs-4 d-block mb-1"></i>No recent leads found.</div>';
        return;
    }
    container.innerHTML = '<div class="list-group list-group-flush">' + data.map(function(l) {
        return '<div class="list-group-item px-3 py-2 border-bottom">'
            + '<div class="d-flex justify-content-between align-items-start gap-2">'
            + '<div>'
            + '<a href="' + LEAD_VIEW_URL + '?id=' + l.lead_id + '" class="fw-semibold text-dark small text-decoration-none">' + escapeHtml(l.customer_name) + '</a>'
            + '<div class="text-muted small" style="font-size:0.6875rem;">' + escapeHtml(l.company_name || 'Individual') + ' &bull; ' + escapeHtml(l.assigned_name) + '</div>'
            + '</div>'
            + '<span class="badge ' + leadStatusBadgeClass(l.lead_status_new) + '">' + escapeHtml(l.lead_status_new) + '</span>'
            + '</div>'
            + '<div class="d-flex align-items-center justify-content-between mt-1">'
            + '<a href="tel:' + escapeHtml(l.mobile) + '" class="badge bg-secondary text-decoration-none"><i class="bi bi-telephone me-1"></i>' + escapeHtml(l.mobile) + '</a>'
            + '<div class="d-flex gap-1">'
            + '<a href="' + LEAD_VIEW_URL + '?id=' + l.lead_id + '" class="btn btn-sm btn-secondary py-0 px-2" style="font-size:0.75rem;">Manage</a>'
            + '<a href="' + LEAD_PIPELINE_URL + '#lead-' + l.lead_id + '" class="btn btn-sm btn-ghost py-0 px-2" style="font-size:0.75rem;"><i class="bi bi-kanban"></i></a>'
            + '</div>'
            + '</div>'
            + '</div>';
    }).join('') + '</div>';
}

async function loadDashboardLists() {
    const base = APP_BASE_URL + '/modules/leads/php_scripts/';
    try {
        const [todayRes, recentRes] = await Promise.all([
            fetch(base + 'lead_cards_data', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ csrf_token: CSRF_TOKEN, quickStatus: 'PIPELINE', followupMonth: 'current', perPage: '6', page: '1' }) }),
            fetch(base + 'lead_cards_data', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ csrf_token: CSRF_TOKEN, perPage: '5', page: '1' }) })
        ]);
        const today = await todayRes.json();
        const recent = await recentRes.json();
        renderTodayFollowups(today.cards || []);
        renderRecentLeads(recent.cards || []);
    } catch (e) {
        document.getElementById('todayFollowupsList').innerHTML = '<div class="p-3 text-center text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>Failed to load follow-ups</div>';
        document.getElementById('recentLeadsList').innerHTML = '<div class="p-3 text-center text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>Failed to load leads</div>';
    }
}

document.addEventListener('DOMContentLoaded', loadDashboardLists);
</script>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
