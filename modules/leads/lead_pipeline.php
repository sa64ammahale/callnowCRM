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

$statusMeta = leadStatusMeta();
$pipelineOrder = ['LEAD', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT'];

$stageColors = [
    'LEAD' => ['bg' => '#2563eb', 'soft' => '#eff6ff'],
    'FOLLOWUP' => ['bg' => '#0284c7', 'soft' => '#f0f9ff'],
    'INTERNAL_UNDERWRITING' => ['bg' => '#64748b', 'soft' => '#f8fafc'],
    'LOGIN' => ['bg' => '#d97706', 'soft' => '#fffbeb'],
    'BANK_UNDERWRITING' => ['bg' => '#7c3aed', 'soft' => '#faf5ff'],
    'SANCTIONED' => ['bg' => '#16a34a', 'soft' => '#f0fdf4'],
    'DISBURSED' => ['bg' => '#059669', 'soft' => '#ecfdf5'],
    'REJECT' => ['bg' => '#dc2626', 'soft' => '#fef2f2'],
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
        COALESCE(NULLIF(m.MAINDATABASE_NAME, ''), NULLIF(l.NAME, ''), CONCAT('Lead #', l.lead_id)) AS customer_name,
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
<?php $pageTitle = 'Pipeline Board'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<div class="crm-module-header">
    <div>
        <div class="crm-module-header-title">
            <i class="bi bi-kanban text-primary"></i>
            <h1>Lead Pipeline Board</h1>
        </div>
        <p class="crm-module-header-desc"><?= number_format($totalPipeline) ?> active leads across <?= count($pipelineOrder) - 1 ?> pipeline stages</p>
    </div>
    <div class="crm-module-header-actions">
        <a href="<?= url('modules/leads/leads_dashboard.php') ?>" class="btn btn-secondary btn-sm"><i class="bi bi-bar-chart me-1"></i> Analytics</a>
        <a href="<?= url('modules/leads/lead_list.php') ?>" class="btn btn-secondary btn-sm"><i class="bi bi-table me-1"></i> Register</a>
        <?php if (can('manage_leads')): ?>
        <a href="<?= url('modules/leads/lead_insert.php') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Add Lead</a>
        <?php endif; ?>
    </div>
</div>

<!-- Stage Summary Strip -->
<div class="row g-2 mb-3">
    <?php foreach ($pipelineOrder as $status): ?>
        <?php
        $cnt = count($columns[$status] ?? []);
        $c = $stageColors[$status] ?? ['bg' => '#64748b', 'soft' => '#f8fafc'];
        $meta = $statusMeta[$status] ?? ['label' => $status, 'icon' => 'bi-circle'];
        $pct = $totalAll > 0 ? round(($cnt / $totalAll) * 100) : 0;
        ?>
        <div class="col">
            <div class="p-2 border rounded bg-surface d-flex align-items-center gap-2" style="border-left: 3px solid <?= $c['bg'] ?> !important;">
                <div class="min-w-0 flex-grow-1">
                    <div class="text-muted text-uppercase" style="font-size:0.625rem; font-weight:600;"><?= htmlspecialchars($meta['label']) ?></div>
                    <div class="d-flex align-items-baseline gap-1">
                        <span class="fs-6 fw-bold text-dark"><?= number_format($cnt) ?></span>
                        <span class="text-muted" style="font-size:0.625rem;">(<?= $pct ?>%)</span>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Toolbar & Search Strip -->
<div class="crm-toolbar mb-3">
    <div class="crm-toolbar-left">
        <div class="input-group" style="width: 320px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" id="plSearchInput" class="form-control" placeholder="Search pipeline cards by name, company, mobile...">
        </div>
        <span class="text-muted small">Showing <span id="plVisibleCount" class="fw-semibold text-dark"><?= number_format($totalAll) ?></span> of <?= number_format($totalAll) ?> leads</span>
    </div>
    <div class="crm-toolbar-right">
        <span class="badge bg-secondary"><i class="bi bi-shield-check me-1"></i>Drag or Click to inspect</span>
    </div>
</div>

<!-- Board Horizontal Scroll View -->
<div class="pl-board-scroll">
    <div class="pl-board">
        <?php foreach ($pipelineOrder as $status): ?>
            <?php
            $c = $stageColors[$status] ?? ['bg' => '#64748b', 'soft' => '#f8fafc'];
            $meta = $statusMeta[$status] ?? ['label' => $status, 'description' => '', 'icon' => 'bi-circle'];
            $leads = $columns[$status] ?? [];
            ?>
            <section class="pl-col" data-status="<?= htmlspecialchars($status) ?>">
                <div class="pl-col-head" style="border-top: 3px solid <?= $c['bg'] ?>;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-1 fw-semibold text-dark small">
                            <i class="bi <?= htmlspecialchars($meta['icon']) ?>" style="color:<?= $c['bg'] ?>"></i>
                            <span><?= htmlspecialchars($meta['label']) ?></span>
                        </div>
                        <span class="badge bg-secondary"><?= number_format(count($leads)) ?></span>
                    </div>
                    <?php if (!empty($meta['description'])): ?>
                        <div class="text-muted small mt-1" style="font-size:0.6875rem;"><?= htmlspecialchars($meta['description']) ?></div>
                    <?php endif; ?>
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
                                    <div class="d-flex justify-content-between align-items-start gap-1">
                                        <div class="min-w-0">
                                            <a href="<?= url('modules/leads/lead_view.php') ?>?id=<?= (int)$lead['lead_id'] ?>" class="fw-semibold text-dark small text-truncate d-block text-decoration-none">
                                                <?= htmlspecialchars($lead['customer_name']) ?>
                                            </a>
                                            <div class="text-muted small" style="font-size:0.6875rem;">
                                                <i class="bi bi-person me-1"></i><?= htmlspecialchars($lead['assigned_name']) ?>
                                                <?php if ($lead['company_name']): ?>
                                                    &bull; <?= htmlspecialchars($lead['company_name']) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($daysInStage > 3): ?>
                                            <span class="badge <?= $daysInStage > 7 ? 'bg-danger' : 'bg-warning' ?> ms-auto" style="font-size:0.625rem;">
                                                <?= $daysInStage ?>d
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="d-flex flex-wrap gap-1 my-1">
                                        <a href="tel:<?= htmlspecialchars($lead['mobile']) ?>" class="badge bg-secondary text-decoration-none">
                                            <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($lead['mobile']) ?>
                                        </a>
                                        <?php if ($lead['loan_amount']): ?>
                                            <span class="badge bg-primary"><i class="bi bi-cash me-1"></i><?= htmlspecialchars($lead['loan_amount']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($lead['loan_type']): ?>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($lead['loan_type']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($lead['rework_flag'])): ?>
                                            <span class="badge bg-danger" title="Rework pending"><i class="bi bi-exclamation-triangle me-1"></i>Rework</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="d-flex align-items-center justify-content-between pt-1 border-top" style="font-size:0.6875rem;">
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($followup): ?>
                                                <span class="<?= $isOverdue ? 'text-danger fw-semibold' : ($isToday ? 'text-warning fw-semibold' : 'text-muted') ?>">
                                                    <i class="bi bi-clock me-1"></i><?= $followup ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="text-muted"><?= $created ?></span>
                                        </div>
                                        <div class="d-flex align-items-center gap-1">
                                            <a href="<?= url('modules/leads/lead_view.php') ?>?id=<?= (int)$lead['lead_id'] ?>" class="btn btn-ghost btn-sm p-1" title="Manage Record">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            <button type="button" class="btn btn-ghost btn-sm p-1 pl-expand-btn" title="Toggle Details">
                                                <i class="bi bi-chevron-down"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="pl-card-detail">
                                    <div class="p-2 bg-subtle border-top small" style="font-size:0.6875rem;">
                                        <div class="row g-1">
                                            <?php if ($lead['login_bank_name']): ?>
                                                <div class="col-6"><span class="text-muted">Bank:</span> <?= htmlspecialchars($lead['login_bank_name']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($lead['net_salary']): ?>
                                                <div class="col-6"><span class="text-muted">Salary:</span> <?= htmlspecialchars($lead['net_salary']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($lead['loan_tenure']): ?>
                                                <div class="col-6"><span class="text-muted">Tenure:</span> <?= htmlspecialchars($lead['loan_tenure']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($lead['followup_count'] > 0): ?>
                                                <div class="col-6"><span class="text-muted">Follow-ups:</span> <?= (int)$lead['followup_count'] ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($lead['remarks']): ?>
                                            <div class="mt-1 pt-1 border-top text-secondary">
                                                <i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($lead['remarks']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="pl-empty">
                            <i class="bi bi-inbox text-muted"></i>
                            <span>No leads</span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

<style>
.pl-board-scroll {
    overflow-x: auto;
    padding-bottom: 0.75rem;
}
.pl-board {
    display: flex;
    gap: 0.75rem;
    min-width: max-content;
    align-items: flex-start;
}
.pl-col {
    width: 270px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-xs);
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 220px);
}
.pl-col-head {
    padding: 0.625rem 0.75rem;
    border-bottom: 1px solid var(--border);
    background: var(--bg-subtle);
}
.pl-col-body {
    flex: 1;
    overflow-y: auto;
    padding: 0.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.pl-card {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-xs);
    transition: border-color 0.12s ease;
}
.pl-card:hover {
    border-color: var(--border-strong);
}
.pl-card-main {
    padding: 0.625rem;
}
.pl-card-detail {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.2s ease;
}
.pl-card.expanded .pl-card-detail {
    max-height: 250px;
}
.pl-card.expanded .pl-expand-btn i {
    transform: rotate(180deg);
}
.pl-empty {
    text-align: center;
    padding: 2rem 0.5rem;
    color: var(--text-muted);
    font-size: 0.75rem;
}
.pl-empty i {
    font-size: 1.5rem;
    display: block;
    margin-bottom: 0.25rem;
}
.pl-card.hidden {
    display: none;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.pl-expand-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            this.closest('.pl-card').classList.toggle('expanded');
        });
    });

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
