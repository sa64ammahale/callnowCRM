<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/lead_common.php';

$allowedTBL_ROLES = ['Admin', 'Manager', 'Supervisor', 'Officer'];
if (!in_array(USER_ROLE, $allowedTBL_ROLES, true)) {
    header('Location: ../../dashboard.php');
    exit;
}

ensureLeadModuleSchema($link);
mysqli_set_charset($link, 'utf8mb4');

$where = '';
$accessibleUserIds = getAccessibleUserIds($link);
if (!isAdmin() && !empty($accessibleUserIds)) {
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
    FROM TBL_LEADS l
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
    ['key' => 'LEAD',         'icon' => 'bi-person-plus',   'color' => 'var(--accent)'],
    ['key' => 'FOLLOWUP',     'icon' => 'bi-arrow-repeat',  'color' => '#0ea5e9'],
    ['key' => 'INTERNAL_UNDERWRITING', 'icon' => 'bi-building-check', 'color' => '#8b8fa3'],
    ['key' => 'LOGIN',        'icon' => 'bi-box-arrow-in-right', 'color' => '#f59e0b'],
    ['key' => 'BANK_UNDERWRITING', 'icon' => 'bi-bank',     'color' => '#7c3aed'],
    ['key' => 'SANCTIONED',   'icon' => 'bi-check-circle',  'color' => '#10b981'],
    ['key' => 'DISBURSED',    'icon' => 'bi-cash-coin',     'color' => '#059669'],
];
$pipelineMax = max(1, max(array_map(function($s) use ($statusCounts) { return $statusCounts[$s['key']] ?? 0; }, $pipelineSteps)));
$userName = (is_array($user) && isset($user['NAME'])) ? $user['NAME'] : 'User';
?>
<?php $pageTitle = 'Lead Dashboard - CallNow'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<div class="dash-wrap">
    <!-- Hero Section -->
    <div class="dash-hero">
        <div class="dash-hero-bg"></div>
        <div class="dash-hero-content">
            <div class="dash-hero-left">
                <div class="dash-hero-greeting">
                    <span class="dash-hero-avatar"><?= strtoupper(substr($userName, 0, 1)) ?></span>
                    <div>
                        <h1 class="dash-hero-title">Welcome back, <?= htmlspecialchars($userName) ?></h1>
                        <p class="dash-hero-sub">
                            <span class="role-badge"><?= htmlspecialchars(USER_ROLE) ?></span>
                            <span class="dash-hero-meta"><?= number_format($totalLeads) ?> leads in scope</span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="dash-hero-right">
                <a href="<?= url('modules/leads/lead_insert.php') ?>" class="btn btn-light btn-lg">
                    <i class="bi bi-plus-circle me-2"></i>Add Lead
                </a>
                <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="btn btn-outline-light btn-lg">
                    <i class="bi bi-kanban me-2"></i>Pipeline
                </a>
                <a href="<?= url('modules/leads/lead_list.php') ?>" class="btn btn-accent btn-lg">
                    <i class="bi bi-grid-3x3-gap-fill me-2"></i>All Leads
                </a>
            </div>
        </div>
        <form method="get" action="<?= url('modules/leads/lead_list.php') ?>" class="dash-hero-search">
            <i class="bi bi-search"></i>
            <input type="text" name="q" placeholder="Search by name, mobile, company, loan app no…">
            <button type="submit">Search</button>
        </form>
    </div>

    <!-- KPI Row -->
    <div class="dash-kpi-row">
        <div class="kpi-card kpi-total">
            <div class="kpi-bg"></div>
            <div class="kpi-top">
                <div class="kpi-icon"><i class="bi bi-diagram-3-fill"></i></div>
                <span class="kpi-trend up"><i class="bi bi-arrow-up-short"></i><?= number_format($todayNew) ?> today</span>
            </div>
            <div class="kpi-value"><?= number_format($totalLeads) ?></div>
            <div class="kpi-label">Total Leads</div>
            <div class="kpi-trail">
                <span></span><span></span><span></span><span></span><span></span>
            </div>
        </div>
        <div class="kpi-card kpi-pipeline">
            <div class="kpi-bg"></div>
            <div class="kpi-top">
                <div class="kpi-icon"><i class="bi bi-arrow-up-right-circle-fill"></i></div>
                <span class="kpi-trend up"><i class="bi bi-arrow-up-short"></i><?= round(($activePipeline / max(1, $totalLeads)) * 100, 0) ?>% active</span>
            </div>
            <div class="kpi-value"><?= number_format($activePipeline) ?></div>
            <div class="kpi-label">Active Pipeline</div>
            <div class="kpi-trail">
                <span></span><span></span><span></span><span></span><span></span>
            </div>
        </div>
        <div class="kpi-card kpi-followup">
            <div class="kpi-bg"></div>
            <div class="kpi-top">
                <div class="kpi-icon"><i class="bi bi-bell-fill"></i></div>
                <?php if ($todayFollowups > 0): ?>
                    <span class="kpi-trend danger"><i class="bi bi-exclamation-circle-fill"></i>due today</span>
                <?php else: ?>
                    <span class="kpi-trend flat"><i class="bi bi-check-lg"></i>clear</span>
                <?php endif; ?>
            </div>
            <div class="kpi-value"><?= number_format($todayFollowups) ?></div>
            <div class="kpi-label">Today's Follow-ups</div>
            <div class="kpi-trail">
                <span></span><span></span><span></span><span></span><span></span>
            </div>
        </div>
        <div class="kpi-card kpi-logins">
            <div class="kpi-bg"></div>
            <div class="kpi-top">
                <div class="kpi-icon"><i class="bi bi-calendar-check-fill"></i></div>
                <span class="kpi-trend up"><i class="bi bi-arrow-up-short"></i>this month</span>
            </div>
            <div class="kpi-value"><?= number_format($thisMonthLogins) ?></div>
            <div class="kpi-label">Monthly Logins</div>
            <div class="kpi-trail">
                <span></span><span></span><span></span><span></span><span></span>
            </div>
        </div>
        <div class="kpi-card kpi-conversion">
            <div class="kpi-bg"></div>
            <div class="kpi-top">
                <div class="kpi-icon"><i class="bi bi-graph-up-arrow"></i></div>
                <span class="kpi-trend <?= $conversionRate >= 5 ? 'up' : 'flat' ?>"><i class="bi bi-arrow-up-short"></i>overall</span>
            </div>
            <div class="kpi-value"><?= number_format($conversionRate, 1) ?>%</div>
            <div class="kpi-label">Conversion Rate</div>
            <div class="kpi-trail">
                <span class="active"></span><span class="active"></span><span class="active"></span><span></span><span></span>
            </div>
        </div>
    </div>

    <!-- Pipeline Funnel -->
    <div class="dash-section">
        <div class="section-head">
            <div class="section-head-left">
                <i class="bi bi-diagram-3-fill section-icon"></i>
                <div>
                    <h2 class="section-title">Pipeline Funnel</h2>
                    <p class="section-desc">Lead progression across stages</p>
                </div>
            </div>
            <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="btn btn-outline-accent btn-sm">
                <i class="bi bi-kanban me-1"></i>Full Board
            </a>
        </div>
        <div class="funnel-wrap">
            <?php foreach ($pipelineSteps as $i => $step): ?>
                <?php
                $cnt = $statusCounts[$step['key']] ?? 0;
                $pct = $pipelineMax > 0 ? round(($cnt / $pipelineMax) * 100) : 0;
                $width = max(20, $pct);
                ?>
                <div class="funnel-step">
                    <div class="funnel-bar-wrap">
                        <div class="funnel-bar" style="width:<?= $width ?>%;background:<?= $step['color'] ?>">
                            <span class="funnel-count"><?= number_format($cnt) ?></span>
                        </div>
                    </div>
                    <div class="funnel-info">
                        <div class="funnel-icon" style="color:<?= $step['color'] ?>"><i class="bi <?= $step['icon'] ?>"></i></div>
                        <div class="funnel-label"><?= htmlspecialchars($step['key']) ?></div>
                    </div>
                    <?php if ($i < count($pipelineSteps) - 1): ?>
                        <div class="funnel-arrow"><i class="bi bi-chevron-down"></i></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Rework + Login Status -->
    <div class="dash-single-col">
        <div class="dash-card">
            <div class="dash-card-header">
                <div class="dash-card-header-left">
                    <i class="bi bi-exclamation-triangle-fill card-header-icon rework-icon"></i>
                    <div>
                        <h3 class="dash-card-title">Rework & Login Status</h3>
                        <p class="dash-card-sub">Pending rework and bank login breakdown</p>
                    </div>
                </div>
                <a href="<?= url('modules/leads/lead_list.php') ?>?rework=yes" class="btn btn-sm btn-outline-accent">View Leads</a>
            </div>
            <div class="dash-subrow">
                <div class="mini-stat">
                    <div class="mini-value"><?= number_format($reworkAll) ?></div>
                    <div class="mini-label">Rework Pending</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-value"><?= number_format($reworkInternal) ?></div>
                    <div class="mini-label">Internal Rework</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-value"><?= number_format($reworkBank) ?></div>
                    <div class="mini-label">Bank Rework</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-value login-success"><?= number_format($loginSuccess) ?></div>
                    <div class="mini-label">Login Success</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-value login-pending"><?= number_format($loginPending) ?></div>
                    <div class="mini-label">Login Pending</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-value login-rejected"><?= number_format($loginRejected) ?></div>
                    <div class="mini-label">Login Rejected</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Today's Follow-ups -->
    <div class="dash-single-col">
        <div class="dash-card">
            <div class="dash-card-header">
                <div class="dash-card-header-left">
                    <i class="bi bi-bell-fill card-header-icon followup-icon"></i>
                    <div>
                        <h3 class="dash-card-title">Today's Follow-ups</h3>
                        <p class="dash-card-sub"><?= number_format($todayFollowups) ?> items requiring attention</p>
                    </div>
                </div>
                <a href="<?= url('modules/leads/lead_list.php') ?>?followup=today" class="btn btn-sm btn-outline-accent">View All</a>
            </div>
            <div id="todayFollowupsList" class="dash-card-body">
                <div class="loading-pulse"><div class="loader"></div><span>Loading follow-ups…</span></div>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="dash-single-col">
        <div class="dash-card">
            <div class="dash-card-header">
                <div class="dash-card-header-left">
                    <i class="bi bi-clock-history card-header-icon recent-icon"></i>
                    <div>
                        <h3 class="dash-card-title">Recent Activity</h3>
                        <p class="dash-card-sub">Latest <span id="recentTotal"><?= number_format(min(3, $totalLeads)) ?></span> records from <?= number_format($totalLeads) ?> total leads</p>
                    </div>
                </div>
                <a href="<?= url('modules/leads/lead_list.php') ?>" class="btn btn-sm btn-outline-accent">View All</a>
            </div>
            <div id="recentLeadsList" class="dash-card-body">
                <div class="loading-pulse"><div class="loader"></div><span>Loading recent leads…</span></div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

<style>
/* ── Dashboard Layout ── */
.dash-wrap {
    max-width: 1440px;
    margin: 0 auto;
    padding: 1.5rem 1.5rem 2.5rem;
    display: flex;
    flex-direction: column;
    gap: 1.75rem;
}

/* ── Hero ── */
.dash-hero {
    position: relative;
    border-radius: var(--radius-xl);
    padding: 2rem 2rem 1.25rem;
    overflow: hidden;
    isolation: isolate;
}
.dash-hero-bg {
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, #5e6ad2 0%, #4a54c9 40%, #3b82f6 100%);
    z-index: 0;
}
.dash-hero-bg::after {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 500px;
    height: 500px;
    border-radius: 50%;
    background: rgba(255,255,255,0.06);
}
.dash-hero-bg::before {
    content: '';
    position: absolute;
    bottom: -30%;
    left: -10%;
    width: 400px;
    height: 400px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
}
.dash-hero-content {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 2rem;
}
.dash-hero-left { flex: 1; min-width: 0; }
.dash-hero-greeting {
    display: flex;
    align-items: center;
    gap: 1rem;
}
.dash-hero-avatar {
    width: 52px;
    height: 52px;
    border-radius: var(--radius-lg);
    background: rgba(255,255,255,0.2);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    font-weight: 700;
    flex-shrink: 0;
    backdrop-filter: blur(4px);
}
.dash-hero-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #fff;
    margin: 0 0 0.375rem;
    letter-spacing: -0.02em;
}
.dash-hero-sub {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: 0;
    font-size: 0.875rem;
    color: rgba(255,255,255,0.75);
}
.role-badge {
    display: inline-flex;
    padding: 0.125rem 0.625rem;
    border-radius: 999px;
    background: rgba(255,255,255,0.18);
    color: #fff;
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.dash-hero-meta { color: rgba(255,255,255,0.7); }
.dash-hero-right {
    display: flex;
    gap: 0.75rem;
    flex-shrink: 0;
    flex-wrap: wrap;
}
.dash-hero-right .btn {
    border: 1px solid rgba(255,255,255,0.2);
    color: #fff;
    font-weight: 600;
    font-size: 0.8125rem;
    padding: 0.5rem 1.125rem;
    border-radius: var(--radius-lg);
    backdrop-filter: blur(4px);
    transition: all 0.15s ease;
}
.dash-hero-right .btn-light {
    background: rgba(255,255,255,0.15);
    border-color: transparent;
}
.dash-hero-right .btn-light:hover {
    background: rgba(255,255,255,0.25);
    color: #fff;
}
.dash-hero-right .btn-outline-light:hover {
    background: rgba(255,255,255,0.12);
    color: #fff;
}
.dash-hero-right .btn-accent {
    background: rgba(255,255,255,0.22);
    border-color: transparent;
}
.dash-hero-right .btn-accent:hover {
    background: rgba(255,255,255,0.32);
    color: #fff;
}
.dash-hero-search {
    display: flex;
    align-items: center;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-lg);
    padding: 0.5rem 0.5rem 0.5rem 1.125rem;
    max-width: 520px;
    margin-top: 1rem;
    transition: box-shadow 0.2s ease;
}
.dash-hero-search:focus-within {
    box-shadow: 0 0 0 3px var(--accent-soft), var(--shadow-lg);
    border-color: var(--accent);
}
.dash-hero-search i {
    color: var(--ink-muted);
    font-size: 1rem;
    margin-right: 0.625rem;
    flex-shrink: 0;
}
.dash-hero-search input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 0.5rem 0;
    font-size: 0.875rem;
    color: var(--ink);
    outline: none;
    min-width: 0;
}
.dash-hero-search input::placeholder { color: var(--ink-muted); }
.dash-hero-search button {
    flex-shrink: 0;
    padding: 0.5rem 1.25rem;
    border: none;
    border-radius: var(--radius-lg);
    background: var(--accent);
    color: #fff;
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease;
}
.dash-hero-search button:hover { background: var(--accent-hover); }

/* ── KPI Cards ── */
.dash-kpi-row {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 1rem;
}
.kpi-card {
    position: relative;
    border-radius: var(--radius-xl);
    padding: 1.25rem 1.25rem 1rem;
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: all 0.2s ease;
    cursor: default;
    isolation: isolate;
}
.kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-lg);
}
.kpi-bg {
    position: absolute;
    inset: 0;
    z-index: 0;
    opacity: 0.04;
}
.kpi-total .kpi-bg { background: linear-gradient(135deg, #5e6ad2, #3b82f6); }
.kpi-pipeline .kpi-bg { background: linear-gradient(135deg, #0ea5e9, #06b6d4); }
.kpi-followup .kpi-bg { background: linear-gradient(135deg, #f59e0b, #f97316); }
.kpi-logins .kpi-bg { background: linear-gradient(135deg, #10b981, #059669); }
.kpi-conversion .kpi-bg { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }

.kpi-total { background: var(--surface); border: 1px solid var(--border); }
.kpi-pipeline { background: var(--surface); border: 1px solid var(--border); }
.kpi-followup { background: var(--surface); border: 1px solid var(--border); }
.kpi-logins { background: var(--surface); border: 1px solid var(--border); }
.kpi-conversion { background: var(--surface); border: 1px solid var(--border); }

.kpi-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
    position: relative;
    z-index: 1;
}
.kpi-icon {
    width: 38px;
    height: 38px;
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.0625rem;
}
.kpi-total .kpi-icon { background: rgba(94,106,210,0.1); color: #5e6ad2; }
.kpi-pipeline .kpi-icon { background: rgba(14,165,233,0.1); color: #0ea5e9; }
.kpi-followup .kpi-icon { background: rgba(245,158,11,0.1); color: #f59e0b; }
.kpi-logins .kpi-icon { background: rgba(16,185,129,0.1); color: #10b981; }
.kpi-conversion .kpi-icon { background: rgba(139,92,246,0.1); color: #8b5cf6; }

.kpi-trend {
    display: inline-flex;
    align-items: center;
    gap: 0.125rem;
    font-size: 0.6875rem;
    font-weight: 600;
    padding: 0.125rem 0.4375rem;
    border-radius: 999px;
}
.kpi-trend.up { background: rgba(16,185,129,0.1); color: #10b981; }
.kpi-trend.danger { background: rgba(239,68,68,0.1); color: #ef4444; }
.kpi-trend.flat { background: var(--surface-2); color: var(--ink-muted); }

.kpi-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--ink);
    line-height: 1;
    margin-bottom: 0.25rem;
    position: relative;
    z-index: 1;
    letter-spacing: -0.02em;
}
.kpi-label {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--ink-soft);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    position: relative;
    z-index: 1;
}
.kpi-trail {
    display: flex;
    gap: 4px;
    margin-top: 0.75rem;
    position: relative;
    z-index: 1;
}
.kpi-trail span {
    flex: 1;
    height: 3px;
    border-radius: 2px;
    background: var(--border);
    transition: background 0.2s ease;
}
.kpi-trail span.active {
    background: var(--ink-muted);
}

/* ── Pipeline Funnel ── */
.dash-section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    padding: 1.25rem 1.5rem 1.5rem;
    box-shadow: var(--shadow);
}
.section-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.25rem;
}
.section-head-left {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.section-icon {
    width: 36px;
    height: 36px;
    border-radius: var(--radius);
    background: var(--accent-soft);
    color: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.section-title {
    font-size: 1rem;
    font-weight: 600;
    margin: 0 0 0.125rem;
    color: var(--ink);
}
.section-desc {
    font-size: 0.75rem;
    color: var(--ink-muted);
    margin: 0;
}
.btn-outline-accent {
    border: 1px solid var(--border);
    color: var(--ink-soft);
    font-weight: 500;
    border-radius: var(--radius-lg);
    padding: 0.375rem 0.875rem;
    font-size: 0.8125rem;
    transition: all 0.15s ease;
}
.btn-outline-accent:hover {
    border-color: var(--accent);
    color: var(--accent);
    background: var(--accent-soft);
}
.funnel-wrap {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.funnel-step {
    display: grid;
    grid-template-columns: 1fr 96px;
    align-items: center;
    gap: 0.75rem;
}
.funnel-bar-wrap {
    position: relative;
    height: 40px;
    display: flex;
    align-items: center;
}
.funnel-bar {
    height: 32px;
    border-radius: 0 var(--radius-lg) var(--radius-lg) 0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 0 0.75rem;
    transition: width 0.4s ease;
    min-width: 48px;
}
.funnel-count {
    color: #fff;
    font-size: 0.8125rem;
    font-weight: 700;
    text-shadow: 0 1px 2px rgba(0,0,0,0.15);
}
.funnel-info {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.funnel-icon {
    font-size: 1.125rem;
    flex-shrink: 0;
}
.funnel-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--ink-soft);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    white-space: nowrap;
}
.funnel-arrow {
    display: none;
}

/* ── Bottom Cards ── */
.dash-single-col {
    min-width: 0;
}
.dash-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow);
    overflow: hidden;
}
.dash-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border);
    background: var(--surface-2);
}
.dash-card-header-left {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.card-header-icon {
    width: 34px;
    height: 34px;
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9375rem;
    flex-shrink: 0;
}
.followup-icon { background: rgba(245,158,11,0.1); color: #f59e0b; }
.recent-icon { background: rgba(94,106,210,0.1); color: #5e6ad2; }
.rework-icon { background: rgba(124,58,237,0.1); color: #7c3aed; }

/* ── Sub-row mini stats (Rework / Login) ── */
.dash-subrow {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 0.75rem;
    padding: 1.1rem 1.25rem 1.25rem;
}
.mini-stat {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 0.75rem 0.875rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}
.mini-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--ink);
    line-height: 1;
}
.mini-label {
    font-size: 0.6875rem;
    color: var(--ink-muted);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.mini-value.login-success { color: #10b981; }
.mini-value.login-pending { color: #f59e0b; }
.mini-value.login-rejected { color: #ef4444; }
.dash-card-title {
    font-size: 0.9375rem;
    font-weight: 600;
    margin: 0 0 0.125rem;
    color: var(--ink);
}
.dash-card-sub {
    font-size: 0.6875rem;
    color: var(--ink-muted);
    margin: 0;
}
.dash-card-body {
    padding: 0.5rem 0;
}

/* ── Follow-up / Recent Items ── */
.followup-item, .recent-item {
    padding: 0.875rem 1.25rem;
    border-bottom: 1px solid var(--border);
    transition: background 0.12s ease;
}
.followup-item:last-child, .recent-item:last-child {
    border-bottom: none;
}
.followup-item:hover, .recent-item:hover {
    background: var(--surface-2);
}
.item-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 0.5rem;
}
.item-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--ink);
    margin: 0 0 0.125rem;
}
.item-sub {
    font-size: 0.75rem;
    color: var(--ink-muted);
    margin: 0;
}
.meta-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
}
.meta-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.1875rem 0.5625rem;
    border-radius: 999px;
    background: var(--surface-2);
    color: var(--ink-soft);
    font-size: 0.6875rem;
    font-weight: 500;
    border: 1px solid var(--border);
    transition: all 0.12s ease;
}
.meta-pill:hover {
    background: var(--accent-soft);
    color: var(--accent);
    border-color: var(--accent-soft-strong);
}
.followup-badge {
    background: rgba(245,158,11,0.1);
    color: #f59e0b;
    border-color: transparent;
}
.empty-state {
    text-align: center;
    padding: 2rem 1.25rem;
    color: var(--ink-muted);
}
.empty-state i {
    display: block;
    font-size: 2rem;
    margin-bottom: 0.5rem;
    opacity: 0.5;
}
.loading-pulse {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 2rem 1.25rem;
    color: var(--ink-muted);
    font-size: 0.8125rem;
}
.loader {
    width: 20px;
    height: 20px;
    border: 2px solid var(--border);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

/* ── Responsive ── */
@media (max-width: 1200px) {
    .dash-kpi-row { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 768px) {
    .dash-wrap { padding: 1rem; gap: 1rem; }
    .dash-hero { padding: 1.25rem; }
    .dash-hero-content { flex-direction: column; gap: 1rem; }
    .dash-hero-right { width: 100%; }
    .dash-hero-right .btn { flex: 1; text-align: center; }
    .dash-hero-search { max-width: none; }
    .dash-kpi-row { grid-template-columns: repeat(2, 1fr); }
    .funnel-step { grid-template-columns: 1fr 72px; }
}
@media (max-width: 480px) {
    .dash-kpi-row { grid-template-columns: 1fr; }
}
</style>

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

function statusIcon(status) {
    const icons = { 'LEAD': 'bi-person-plus', 'FOLLOWUP': 'bi-arrow-repeat', 'LOGIN': 'bi-box-arrow-in-right',
        'INTERNAL_UNDERWRITING': 'bi-building-check', 'BANK_UNDERWRITING': 'bi-bank', 'SANCTIONED': 'bi-check-circle', 'DISBURSED': 'bi-cash', 'REJECT': 'bi-x-circle' };
    return icons[status] || 'bi-circle';
}

function renderTodayFollowups(data) {
    const container = document.getElementById('todayFollowupsList');
    if (!data || !data.length) {
        container.innerHTML = '<div class="empty-state"><i class="bi bi-check2-all"></i>No pending follow-ups for today.</div>';
        return;
    }
    container.innerHTML = data.map(function(l) {
        var timeStr = l.next_followup_at ? new Date(l.next_followup_at).toLocaleString('en-GB', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : 'No time set';
        return '<div class="followup-item">'
            + '<div class="item-head">'
            + '<div>'
            + '<div class="item-title">' + escapeHtml(l.customer_name) + '</div>'
            + '<p class="item-sub">' + escapeHtml(l.assigned_name) + ' &middot; ' + timeStr + '</p>'
            + '</div>'
            + '<span class="badge rounded-pill ' + leadStatusBadgeClass(l.lead_status_new) + '">' + escapeHtml(l.lead_status_new) + '</span>'
            + '</div>'
            + '<div class="meta-pills">'
            + '<a class="meta-pill text-decoration-none" href="tel:' + escapeHtml(l.mobile) + '"><i class="bi bi-telephone-fill"></i> ' + escapeHtml(l.mobile) + '</a>'
            + (l.remarks ? '<span class="meta-pill"><i class="bi bi-chat-left-text"></i> ' + escapeHtml(l.remarks) + '</span>' : '')
            + '</div>'
            + '<div class="mt-2"><a href="' + LEAD_VIEW_URL + '?id=' + l.lead_id + '" class="btn btn-sm btn-primary rounded-pill px-3"><i class="bi bi-pencil-square me-1"></i> Open</a></div>'
            + '</div>';
    }).join('');
}

function renderRecentLeads(data) {
    var container = document.getElementById('recentLeadsList');
    if (!data || !data.length) {
        container.innerHTML = '<div class="empty-state"><i class="bi bi-inboxes"></i>No recent leads.</div>';
        return;
    }
    container.innerHTML = data.map(function(l) {
        var loginDate = l.login_date ? new Date(l.login_date).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '';
        var followupDate = l.next_followup_at ? new Date(l.next_followup_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '';
        return '<div class="recent-item">'
            + '<div class="item-head">'
            + '<div>'
            + '<div class="item-title">' + escapeHtml(l.customer_name) + '</div>'
            + '<p class="item-sub">' + escapeHtml(l.company_name || 'No company') + ' &middot; ' + escapeHtml(l.assigned_name) + '</p>'
            + '</div>'
            + '<span class="badge rounded-pill ' + leadStatusBadgeClass(l.lead_status_new) + '">' + escapeHtml(l.lead_status_new) + '</span>'
            + '</div>'
            + '<div class="meta-pills">'
            + '<a class="meta-pill text-decoration-none" href="tel:' + escapeHtml(l.mobile) + '"><i class="bi bi-telephone-fill"></i> ' + escapeHtml(l.mobile) + '</a>'
            + (l.loan_type ? '<span class="meta-pill"><i class="bi bi-briefcase"></i> ' + escapeHtml(l.loan_type) + '</span>' : '')
            + (l.login_mode ? '<span class="meta-pill"><i class="bi bi-send-check"></i> ' + escapeHtml(l.login_mode) + '</span>' : '')
            + (loginDate ? '<span class="meta-pill"><i class="bi bi-calendar2-event"></i> ' + loginDate + '</span>' : '')
            + (followupDate ? '<span class="meta-pill followup-badge"><i class="bi bi-alarm"></i> ' + followupDate + '</span>' : '')
            + '</div>'
            + '<div class="mt-2 d-flex gap-2 flex-wrap">'
            + '<a href="' + LEAD_VIEW_URL + '?id=' + l.lead_id + '" class="btn btn-sm btn-primary rounded-pill px-3"><i class="bi bi-pencil-square me-1"></i> Manage</a>'
            + '<a href="' + LEAD_PIPELINE_URL + '#lead-' + l.lead_id + '" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="bi bi-kanban me-1"></i> Pipeline</a>'
            + '</div>'
            + '</div>';
    }).join('');
}

async function loadDashboardLists() {
    const base = APP_BASE_URL + '/modules/leads/php_scripts/';
    try {
        const [todayRes, recentRes] = await Promise.all([
            fetch(base + 'lead_cards_data.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ quickStatus: 'PIPELINE', followupMonth: 'current', perPage: '8', page: '1' }) }),
            fetch(base + 'lead_cards_data.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ perPage: '3', page: '1' }) })
        ]);
        const today = await todayRes.json();
        const recent = await recentRes.json();
        renderTodayFollowups(today.cards || []);
        renderRecentLeads(recent.cards || []);
    } catch (e) {
        document.getElementById('todayFollowupsList').innerHTML = '<div class="empty-state text-danger"><i class="bi bi-exclamation-circle"></i>Failed to load</div>';
        document.getElementById('recentLeadsList').innerHTML = '<div class="empty-state text-danger"><i class="bi bi-exclamation-circle"></i>Failed to load</div>';
    }
}

document.addEventListener('DOMContentLoaded', loadDashboardLists);
</script>