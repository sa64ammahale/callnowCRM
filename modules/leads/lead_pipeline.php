<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/lead_common.php';

$allowedTBL_ROLES = ['Admin', 'Manager', 'Supervisor', 'Officer', 'Super Admin'];
if (!in_array(USER_ROLE, $allowedTBL_ROLES, true) && !isSuperAdmin()) {
    header('Location: ../../dashboard.php');
    exit;
}

ensureLeadModuleSchema($link);
mysqli_set_charset($link, 'utf8mb4');

$statusMeta = leadStatusMeta();
$pipelineOrder = ['LEAD', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT'];

$stageColors = [
    'LEAD' => ['bg' => '#5e6ad2', 'soft' => 'rgba(94,106,210,0.1)'],
    'FOLLOWUP' => ['bg' => '#0ea5e9', 'soft' => 'rgba(14,165,233,0.1)'],
    'INTERNAL_UNDERWRITING' => ['bg' => '#8b8fa3', 'soft' => 'rgba(139,143,163,0.1)'],
    'LOGIN' => ['bg' => '#f59e0b', 'soft' => 'rgba(245,158,11,0.1)'],
    'BANK_UNDERWRITING' => ['bg' => '#7c3aed', 'soft' => 'rgba(124,58,237,0.1)'],
    'SANCTIONED' => ['bg' => '#10b981', 'soft' => 'rgba(16,185,129,0.1)'],
    'DISBURSED' => ['bg' => '#059669', 'soft' => 'rgba(5,150,105,0.1)'],
    'REJECT' => ['bg' => '#ef4444', 'soft' => 'rgba(239,68,68,0.1)'],
];

$where = '';
$accessibleUserIds = getAccessibleUserIds($link);
if (!isAdmin() && !isSuperAdmin() && !empty($accessibleUserIds)) {
    $where = 'WHERE l.assigned_to IN (' . implode(',', array_map('intval', $accessibleUserIds)) . ')';
}

$sql = "
    SELECT
        l.lead_id, l.lead_status_new, l.login_mode, l.loan_type, l.loan_amount,
        l.loan_app_no, l.login_bank_name, l.login_date, l.net_salary, l.remarks,
        l.next_followup_at, l.created_at, l.updated_at, l.loan_tenure,
        l.salary_account, l.bank_name, l.bank_rm_name, l.bt_details, l.dsa_name,
        l.login_location, l.promo_code, l.followup_count, l.last_contacted_at,
        l.rework_flag, l.rework_stage, l.login_status,
        COALESCE(NULLIF(m.MAINDATABASE_NAME, ''), NULLIF(l.NAME, ''), 'Lead #' . l.lead_id) AS customer_name,
        COALESCE(NULLIF(m.MAINDATABASE_COMPANY, ''), NULLIF(l.COMPANY_NAME, ''), '') AS company_name,
        COALESCE(NULLIF(m.MAINDATABASE_MOBILE, ''), NULLIF(l.MOBILE, ''), 'N/A') AS mobile,
        COALESCE(u.NAME, 'Unassigned') AS assigned_name
    FROM " . tn('TBL_LEADS') . " l
    LEFT JOIN " . tn('TBL_MAIN') . " m ON m.ID = l.cust_id
    LEFT JOIN " . tn('TBL_USERS') . " u ON u.ID = l.assigned_to
    {$where}
    ORDER BY FIELD(l.lead_status_new, 'LEAD', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT'),
             l.updated_at DESC, l.lead_id DESC
";
$result = mysqli_query($link, $sql);
if (!$result) {
    $rows = [];
} else {
    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
}

$columns = [];
foreach ($pipelineOrder as $status) {
    $columns[$status] = [];
}
$totalPipeline = 0;
foreach ($rows as $row) {
    $statusKey = strtoupper((string)($row['lead_status_new'] ?? 'LEAD'));
    if (!isset($columns[$statusKey])) {
        $columns[$statusKey] = [];
    }
    $columns[$statusKey][] = $row;
    if ($statusKey !== 'REJECT') {
        $totalPipeline++;
    }
}
$totalAll = count($rows);
?>
<?php $pageTitle = 'Lead Pipeline Board - CallNow'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<div class="pl-wrap">
    <!-- Header -->
    <div class="pl-header">
        <div class="pl-header-left">
            <div class="pl-header-icon"><i class="bi bi-kanban-fill"></i></div>
            <div>
                <h1 class="pl-title">Pipeline Board</h1>
                <p class="pl-sub"><?= number_format($totalPipeline) ?> active leads across <?= count($pipelineOrder) - 1 ?> stages</p>
            </div>
        </div>
        <div class="pl-header-right">
            <a href="<?= url('modules/leads/leads_dashboard.php') ?>" class="btn btn-outline-accent btn-sm"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a>
            <a href="<?= url('modules/leads/lead_list.php') ?>" class="btn btn-outline-accent btn-sm"><i class="bi bi-table me-1"></i> Register</a>
            <a href="<?= url('modules/leads/lead_insert.php') ?>" class="btn btn-accent-solid btn-sm"><i class="bi bi-plus-circle me-1"></i> Add Lead</a>
        </div>
    </div>

    <!-- Stats Bar -->
    <div class="pl-stats">
        <?php foreach ($pipelineOrder as $status): ?>
            <?php
            $cnt = count($columns[$status] ?? []);
            $c = $stageColors[$status] ?? ['bg' => '#8b8fa3', 'soft' => 'rgba(139,143,163,0.1)'];
            $meta = $statusMeta[$status] ?? ['label' => $status, 'icon' => 'bi-circle'];
            $pct = $totalAll > 0 ? round(($cnt / $totalAll) * 100) : 0;
            ?>
            <div class="pl-stat" style="--stat-color: <?= $c['bg'] ?>">
                <div class="pl-stat-dot" style="background:<?= $c['bg'] ?>"></div>
                <div class="pl-stat-info">
                    <div class="pl-stat-label"><?= htmlspecialchars($meta['label']) ?></div>
                    <div class="pl-stat-value"><?= number_format($cnt) ?></div>
                </div>
                <div class="pl-stat-pct"><?= $pct ?>%</div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter Bar -->
    <div class="pl-filter">
        <div class="pl-filter-input">
            <i class="bi bi-search"></i>
            <input type="text" id="plSearchInput" placeholder="Filter cards by name, company, mobile, assigned…">
        </div>
        <div class="pl-filter-total">
            <span id="plVisibleCount"><?= number_format($totalAll) ?></span> of <?= number_format($totalAll) ?> leads
        </div>
    </div>

    <!-- Board -->
    <div class="pl-board-scroll">
        <div class="pl-board">
            <?php foreach ($pipelineOrder as $status): ?>
                <?php
                $c = $stageColors[$status] ?? ['bg' => '#8b8fa3', 'soft' => 'rgba(139,143,163,0.1)'];
                $meta = $statusMeta[$status] ?? ['label' => $status, 'description' => '', 'icon' => 'bi-circle'];
                $leads = $columns[$status] ?? [];
                ?>
                <section class="pl-col" data-status="<?= htmlspecialchars($status) ?>" style="--col-color: <?= $c['bg'] ?>">
                    <div class="pl-col-head" style="border-bottom-color: <?= $c['bg'] ?>">
                        <div class="pl-col-title">
                            <i class="bi <?= htmlspecialchars($meta['icon']) ?>"></i>
                            <span><?= htmlspecialchars($meta['label']) ?></span>
                            <span class="pl-col-count"><?= number_format(count($leads)) ?></span>
                        </div>
                        <p class="pl-col-desc"><?= htmlspecialchars($meta['description']) ?></p>
                    </div>
                    <div class="pl-col-body">
                        <?php if (!empty($leads)): ?>
                            <?php foreach ($leads as $lead): ?>
                                <?php
                                $daysInStage = $lead['updated_at'] ? floor((time() - strtotime($lead['updated_at'])) / 86400) : 0;
                                $created = $lead['created_at'] ? date('d M', strtotime($lead['created_at'])) : '—';
                                $followup = $lead['next_followup_at'] ? date('d M', strtotime($lead['next_followup_at'])) : '';
                                $isOverdue = $lead['next_followup_at'] && strtotime($lead['next_followup_at']) < time();
                                $isToday = $lead['next_followup_at'] && date('Y-m-d') === date('Y-m-d', strtotime($lead['next_followup_at']));
                                ?>
                                <article class="pl-card" id="lead-<?= (int)$lead['lead_id'] ?>" data-lead-id="<?= (int)$lead['lead_id'] ?>">
                                    <div class="pl-card-main">
                                        <div class="pl-card-top">
                                            <div>
                                                <h3 class="pl-card-name"><?= htmlspecialchars($lead['customer_name']) ?></h3>
                                                <p class="pl-card-assigned">
                                                    <i class="bi bi-person-badge"></i> <?= htmlspecialchars($lead['assigned_name']) ?>
                                                    <?php if ($lead['company_name']): ?>
                                                        &middot; <?= htmlspecialchars($lead['company_name']) ?>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                            <?php if ($daysInStage > 3): ?>
                                                <span class="pl-days-badge" style="background:<?= $daysInStage > 7 ? 'rgba(239,68,68,0.12)' : 'rgba(245,158,11,0.12)' ?>;color:<?= $daysInStage > 7 ? '#ef4444' : '#f59e0b' ?>">
                                                    <?= $daysInStage ?>d
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="pl-card-meta">
                                            <a href="tel:<?= htmlspecialchars($lead['mobile']) ?>" class="pl-meta-pill text-decoration-none">
                                                <i class="bi bi-telephone-fill"></i> <?= htmlspecialchars($lead['mobile']) ?>
                                            </a>
                                            <?php if ($lead['loan_type']): ?>
                                                <span class="pl-meta-pill"><i class="bi bi-briefcase"></i> <?= htmlspecialchars($lead['loan_type']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($lead['loan_amount']): ?>
                                                <span class="pl-meta-pill"><i class="bi bi-cash"></i> <?= htmlspecialchars($lead['loan_amount']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($lead['loan_app_no']): ?>
                                                <span class="pl-meta-pill"><i class="bi bi-hash"></i> <?= htmlspecialchars($lead['loan_app_no']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($lead['rework_flag'])): ?>
                                                <span class="pl-meta-pill pl-rework-pill" title="Rework pending">
                                                    <i class="bi bi-exclamation-triangle-fill"></i> Rework<?= $lead['rework_stage'] ? ' (' . htmlspecialchars($lead['rework_stage'] === 'BANK' ? 'Bank' : 'Internal') . ')' : '' ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($lead['login_status'])): ?>
                                                <span class="pl-meta-pill pl-login-pill" title="Login status">
                                                    <i class="bi bi-shield-check"></i> <?= htmlspecialchars($lead['login_status']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="pl-card-footer">
                                            <div class="pl-footer-left">
                                                <?php if ($followup): ?>
                                                    <span class="pl-footer-item <?= $isOverdue ? 'overdue' : ($isToday ? 'today' : '') ?>">
                                                        <i class="bi bi-alarm"></i> <?= $followup ?>
                                                    </span>
                                                <?php endif; ?>
                                                <span class="pl-footer-item"><i class="bi bi-plus-circle"></i> <?= $created ?></span>
                                            </div>
                                            <div class="pl-footer-right">
                                                <a href="<?= url('modules/leads/lead_view.php') ?>?id=<?= (int)$lead['lead_id'] ?>" class="pl-card-btn" title="Manage">
                                                    <i class="bi bi-pencil-square"></i>
                                                </a>
                                                <button type="button" class="pl-card-btn pl-expand-btn" title="Details">
                                                    <i class="bi bi-chevron-down"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="pl-card-detail">
                                        <div class="pl-detail-grid">
                                            <?php if ($lead['login_mode']): ?>
                                                <div class="pl-detail-item"><span class="pl-dl">Login</span><span class="pl-dv"><?= htmlspecialchars($lead['login_mode']) ?></span></div>
                                            <?php endif; ?>
                                            <?php if ($lead['login_bank_name']): ?>
                                                <div class="pl-detail-item"><span class="pl-dl">Bank</span><span class="pl-dv"><?= htmlspecialchars($lead['login_bank_name']) ?></span></div>
                                            <?php endif; ?>
                                            <?php if ($lead['login_date']): ?>
                                                <div class="pl-detail-item"><span class="pl-dl">Login Date</span><span class="pl-dv"><?= date('d M Y', strtotime($lead['login_date'])) ?></span></div>
                                            <?php endif; ?>
                                            <?php if ($lead['net_salary']): ?>
                                                <div class="pl-detail-item"><span class="pl-dl">Salary</span><span class="pl-dv"><?= htmlspecialchars($lead['net_salary']) ?></span></div>
                                            <?php endif; ?>
                                            <?php if ($lead['loan_tenure']): ?>
                                                <div class="pl-detail-item"><span class="pl-dl">Tenure</span><span class="pl-dv"><?= htmlspecialchars($lead['loan_tenure']) ?></span></div>
                                            <?php endif; ?>
                                            <?php if ($lead['bank_name']): ?>
                                                <div class="pl-detail-item"><span class="pl-dl">Sal Bank</span><span class="pl-dv"><?= htmlspecialchars($lead['bank_name']) ?></span></div>
                                            <?php endif; ?>
                                            <?php if ($lead['bank_rm_name']): ?>
                                                <div class="pl-detail-item"><span class="pl-dl">Bank RM</span><span class="pl-dv"><?= htmlspecialchars($lead['bank_rm_name']) ?></span></div>
                                            <?php endif; ?>
                                            <?php if ($lead['dsa_name']): ?>
                                                <div class="pl-detail-item"><span class="pl-dl">DSA</span><span class="pl-dv"><?= htmlspecialchars($lead['dsa_name']) ?></span></div>
                                            <?php endif; ?>
                                            <?php if ($lead['followup_count'] > 0): ?>
                                                <div class="pl-detail-item"><span class="pl-dl">Follow-ups</span><span class="pl-dv"><?= (int)$lead['followup_count'] ?></span></div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($lead['remarks']): ?>
                                            <div class="pl-remark">
                                                <i class="bi bi-chat-left-text"></i> <?= htmlspecialchars($lead['remarks']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="pl-empty">
                                <i class="bi bi-inboxes"></i>
                                <span>No leads</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

<style>
/* ── Pipeline Layout ── */
.pl-wrap {
    max-width: 100%;
    padding: 1.25rem 1.5rem 2rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

/* ── Header ── */
.pl-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}
.pl-header-left {
    display: flex;
    align-items: center;
    gap: 0.875rem;
}
.pl-header-icon {
    width: 42px;
    height: 42px;
    border-radius: var(--radius-lg);
    background: linear-gradient(135deg, #5e6ad2, #3b82f6);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(94,106,210,0.3);
}
.pl-title {
    font-size: 1.375rem;
    font-weight: 700;
    margin: 0 0 0.125rem;
    color: var(--ink);
    letter-spacing: -0.02em;
}
.pl-sub {
    font-size: 0.8125rem;
    color: var(--ink-muted);
    margin: 0;
}
.pl-header-right {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.btn-accent-solid {
    background: var(--accent);
    color: #fff;
    border: none;
    font-weight: 600;
    border-radius: var(--radius-lg);
    padding: 0.375rem 0.875rem;
    font-size: 0.8125rem;
    transition: background 0.15s ease;
}
.btn-accent-solid:hover { background: var(--accent-hover); color: #fff; }

/* ── Stats Bar ── */
.pl-stats {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 0.5rem;
}
.pl-stat {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    padding: 0.75rem 0.875rem;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    transition: all 0.15s ease;
}
.pl-stat:hover {
    border-color: var(--stat-color);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--stat-color) 15%, transparent);
}
.pl-stat-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}
.pl-stat-info {
    flex: 1;
    min-width: 0;
}
.pl-stat-label {
    font-size: 0.625rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--ink-muted);
    margin-bottom: 0.125rem;
}
.pl-stat-value {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--ink);
    line-height: 1;
    letter-spacing: -0.02em;
}
.pl-stat-pct {
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--ink-muted);
    flex-shrink: 0;
}

/* ── Filter ── */
.pl-filter {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.pl-filter-input {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    max-width: 420px;
    padding: 0.4375rem 0.75rem;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.pl-filter-input:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
}
.pl-filter-input i { color: var(--ink-muted); font-size: 0.875rem; flex-shrink: 0; }
.pl-filter-input input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 0.25rem 0;
    font-size: 0.8125rem;
    color: var(--ink);
    outline: none;
    min-width: 0;
}
.pl-filter-input input::placeholder { color: var(--ink-muted); }
.pl-filter-total {
    font-size: 0.75rem;
    color: var(--ink-muted);
    white-space: nowrap;
}
.pl-filter-total span { font-weight: 600; color: var(--ink-soft); }

/* ── Board ── */
.pl-board-scroll {
    overflow-x: auto;
    overflow-y: visible;
    padding-bottom: 0.5rem;
    margin: 0 -0.5rem;
    padding: 0 0.5rem 0.5rem;
}
.pl-board-scroll::-webkit-scrollbar { height: 6px; }
.pl-board-scroll::-webkit-scrollbar-track { background: transparent; }
.pl-board-scroll::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
.pl-board-scroll::-webkit-scrollbar-thumb:hover { background: var(--border-strong); }
.pl-board {
    display: flex;
    gap: 1rem;
    min-width: max-content;
    align-items: flex-start;
}

/* ── Column ── */
.pl-col {
    width: 280px;
    min-width: 260px;
    flex-shrink: 0;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 240px);
    box-shadow: var(--shadow);
}
.pl-col-head {
    padding: 0.875rem 1rem;
    border-bottom: 3px solid var(--border);
    flex-shrink: 0;
}
.pl-col-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--ink);
}
.pl-col-title i { font-size: 0.9375rem; }
.pl-col-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    border-radius: 999px;
    background: var(--surface);
    border: 1px solid var(--border);
    font-size: 0.6875rem;
    font-weight: 700;
    color: var(--ink-soft);
    margin-left: auto;
}
.pl-col-desc {
    font-size: 0.6875rem;
    color: var(--ink-muted);
    margin: 0.25rem 0 0;
}
.pl-col-body {
    flex: 1;
    overflow-y: auto;
    padding: 0.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.pl-col-body::-webkit-scrollbar { width: 4px; }
.pl-col-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

/* ── Card ── */
.pl-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    transition: all 0.15s ease;
    overflow: hidden;
}
.pl-card:hover {
    border-color: var(--border-strong);
    box-shadow: var(--shadow-md);
}
.pl-card-main {
    padding: 0.75rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.pl-card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.5rem;
}
.pl-card-name {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--ink);
    margin: 0 0 0.125rem;
    line-height: 1.2;
}
.pl-card-assigned {
    font-size: 0.6875rem;
    color: var(--ink-muted);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.25rem;
    flex-wrap: wrap;
}
.pl-card-assigned i { font-size: 0.625rem; }
.pl-days-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.125rem 0.4375rem;
    border-radius: 999px;
    font-size: 0.625rem;
    font-weight: 700;
    flex-shrink: 0;
    white-space: nowrap;
}
.pl-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
}
.pl-meta-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.1875rem;
    padding: 0.125rem 0.4375rem;
    border-radius: 999px;
    background: var(--surface-2);
    color: var(--ink-soft);
    font-size: 0.625rem;
    font-weight: 500;
    border: 1px solid var(--border);
    transition: all 0.12s ease;
    white-space: nowrap;
}
.pl-rework-pill { background: #f5f3ff; color: #7c3aed; border-color: #ddd6fe; }
.pl-login-pill { background: #ecfdf5; color: #059669; border-color: #a7f3d0; }.pl-meta-pill:hover {
    background: var(--accent-soft);
    color: var(--accent);
    border-color: var(--accent-soft-strong);
}
.pl-card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.5rem;
    padding-top: 0.375rem;
    border-top: 1px solid var(--border);
}
.pl-footer-left {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
    align-items: center;
}
.pl-footer-item {
    display: inline-flex;
    align-items: center;
    gap: 0.1875rem;
    font-size: 0.625rem;
    color: var(--ink-muted);
    font-weight: 500;
}
.pl-footer-item i { font-size: 0.5625rem; }
.pl-footer-item.overdue { color: #ef4444; font-weight: 600; }
.pl-footer-item.today { color: #f59e0b; font-weight: 600; }
.pl-footer-right {
    display: flex;
    gap: 0.25rem;
    flex-shrink: 0;
}
.pl-card-btn {
    width: 26px;
    height: 26px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    background: transparent;
    color: var(--ink-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all 0.12s ease;
    text-decoration: none;
    padding: 0;
}
.pl-card-btn:hover {
    background: var(--accent-soft);
    color: var(--accent);
    border-color: var(--accent-soft-strong);
}

/* ── Card Detail Expand ── */
.pl-card-detail {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.25s ease;
    background: var(--surface-2);
    border-top: 1px solid transparent;
}
.pl-card.expanded .pl-card-detail {
    max-height: 400px;
    border-top-color: var(--border);
}
.pl-card.expanded .pl-expand-btn i {
    transform: rotate(180deg);
}
.pl-expand-btn { transition: background 0.12s ease; }
.pl-expand-btn i { transition: transform 0.2s ease; }
.pl-detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.375rem;
    padding: 0.625rem 0.75rem;
}
.pl-detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.0625rem;
}
.pl-dl {
    font-size: 0.5625rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--ink-muted);
}
.pl-dv {
    font-size: 0.6875rem;
    font-weight: 500;
    color: var(--ink-soft);
}
.pl-remark {
    padding: 0 0.75rem 0.625rem;
    font-size: 0.6875rem;
    color: var(--ink-soft);
    display: flex;
    gap: 0.375rem;
    align-items: flex-start;
}
.pl-remark i { color: var(--ink-muted); font-size: 0.625rem; margin-top: 0.125rem; flex-shrink: 0; }

/* ── Empty State ── */
.pl-empty {
    text-align: center;
    padding: 1.5rem 0.5rem;
    color: var(--ink-muted);
}
.pl-empty i {
    display: block;
    font-size: 1.5rem;
    margin-bottom: 0.375rem;
    opacity: 0.4;
}
.pl-empty span {
    font-size: 0.75rem;
    font-weight: 500;
}

/* ── Hidden card filter ── */
.pl-card.hidden {
    display: none;
}

/* ── Responsive ── */
@media (max-width: 1200px) {
    .pl-stats { grid-template-columns: repeat(4, 1fr); }
}
@media (max-width: 768px) {
    .pl-wrap { padding: 0.75rem 0.75rem 1.5rem; gap: 0.75rem; }
    .pl-header { flex-direction: column; align-items: flex-start; }
    .pl-header-right { width: 100%; }
    .pl-header-right .btn { flex: 1; text-align: center; }
    .pl-stats { grid-template-columns: repeat(3, 1fr); gap: 0.375rem; }
    .pl-stat { padding: 0.5rem 0.625rem; }
    .pl-stat-value { font-size: 0.9375rem; }
    .pl-stat-pct { display: none; }
    .pl-col { width: 240px; min-width: 220px; }
}
@media (max-width: 480px) {
    .pl-stats { grid-template-columns: repeat(2, 1fr); }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Expand/collapse detail
    document.querySelectorAll('.pl-expand-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            this.closest('.pl-card').classList.toggle('expanded');
        });
    });

    // Filter cards
    var searchInput = document.getElementById('plSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();
            var visible = 0;
            document.querySelectorAll('.pl-card').forEach(function(card) {
                var text = card.textContent.toLowerCase();
                var match = !q || text.indexOf(q) !== -1;
                card.classList.toggle('hidden', !match);
                if (match) visible++;
            });
            // Also hide empty columns
            document.querySelectorAll('.pl-col').forEach(function(col) {
                var cards = col.querySelectorAll('.pl-card:not(.hidden)');
                var empty = col.querySelector('.pl-empty');
                if (empty) {
                    empty.style.display = cards.length ? 'none' : '';
                }
            });
            document.getElementById('plVisibleCount').textContent = visible.toLocaleString();
        });
    }
});
</script>