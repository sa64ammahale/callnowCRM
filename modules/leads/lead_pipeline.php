<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/lead_common.php';

requirePermission('manage_leads');

ensureLeadModuleSchema($link);
mysqli_set_charset($link, 'utf8mb4');

$statusMeta = leadStatusMeta();
$pipelineOrder = ['LEAD', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT'];
$canMoveStage = canMoveLeadStage();

$stageColors = [
    'LEAD' => ['bg' => '#6D45F9', 'soft' => '#F0ECFF', 'border' => '#C4B5FD'],
    'FOLLOWUP' => ['bg' => '#0090DF', 'soft' => '#E8F7FF', 'border' => '#BAE6FD'],
    'INTERNAL_UNDERWRITING' => ['bg' => '#4D72E8', 'soft' => '#EEF3FF', 'border' => '#C7D2FE'],
    'LOGIN' => ['bg' => '#D89012', 'soft' => '#FFF6DF', 'border' => '#FDE68A'],
    'BANK_UNDERWRITING' => ['bg' => '#8C72FF', 'soft' => '#F0ECFF', 'border' => '#DDD6FE'],
    'SANCTIONED' => ['bg' => '#16A66A', 'soft' => '#E9F8F1', 'border' => '#A7F3D0'],
    'DISBURSED' => ['bg' => '#087A4B', 'soft' => '#E9F8F1', 'border' => '#86EFAC'],
    'REJECT' => ['bg' => '#E5484D', 'soft' => '#FFF0F0', 'border' => '#FECDD3'],
];

$where = '';
$accessibleUserIds = getAccessibleUserIds($link);
if (!isAdmin() && !isSuperAdmin() && !empty($accessibleUserIds)) {
    $where = 'WHERE l.assigned_to IN (' . implode(',', array_map('intval', $accessibleUserIds)) . ')';
}

// 1. Fetch exact counts per stage for accurate summary and badges
$countSql = "SELECT UPPER(COALESCE(NULLIF(l.lead_status_new, ''), 'LEAD')) as st, COUNT(*) as cnt FROM " . tn('TBL_LEADS') . " l {$where} GROUP BY st";
$cntRes = mysqli_query($link, $countSql);
$rawCounts = [];
if ($cntRes) {
    while ($cr = mysqli_fetch_assoc($cntRes)) {
        $rawCounts[$cr['st']] = (int)$cr['cnt'];
    }
}

$stageTotals = [];
$totalPipeline = 0;
$dbTotalAll = 0;
foreach ($pipelineOrder as $st) {
    $c = $rawCounts[$st] ?? 0;
    $stageTotals[$st] = $c;
    $dbTotalAll += $c;
    if ($st !== 'REJECT') {
        $totalPipeline += $c;
    }
}

// 2. Fetch initial chunk (up to 20 leads) per stage for balanced loading
$cardsPerStage = 20;
$columns = [];
$stageRemaining = [];
$initialLoadedTotal = 0;

foreach ($pipelineOrder as $st) {
    $stWhere = $where ? "{$where} AND l.lead_status_new = '{$st}'" : "WHERE l.lead_status_new = '{$st}'";
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
        {$stWhere}
        ORDER BY l.updated_at DESC, l.lead_id DESC
        LIMIT {$cardsPerStage}
    ";
    $result = mysqli_query($link, $sql);
    $rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
    $columns[$st] = $rows;
    $stageRemaining[$st] = max(0, $stageTotals[$st] - count($rows));
    $initialLoadedTotal += count($rows);
}
?>
<?php $pageTitle = 'Pipeline Board'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-2">
        <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
        <li class="breadcrumb-item"><a href="<?= url('modules/leads/leads_dashboard.php') ?>">Leads</a></li>
        <li class="breadcrumb-item active" aria-current="page">Pipeline Board</li>
    </ol>
</nav>

<div class="crm-module-header">
    <div>
        <div class="crm-module-header-title">
            <i class="bi bi-kanban text-primary"></i>
            <h1>Lead Pipeline Board</h1>
        </div>
        <p class="crm-module-header-desc"><span id="plActivePipelineCount"><?= number_format($totalPipeline) ?></span> active leads across <?= count($pipelineOrder) - 1 ?> pipeline stages</p>
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
        $cnt = $stageTotals[$status] ?? 0;
        $c = $stageColors[$status] ?? ['bg' => '#64748b', 'soft' => '#f8fafc'];
        $meta = $statusMeta[$status] ?? ['label' => $status, 'icon' => 'bi-circle'];
        $pct = $dbTotalAll > 0 ? round(($cnt / $dbTotalAll) * 100) : 0;
        ?>
        <div class="col-xl-3 col-md-6 col-sm-6 col-12">
            <div class="p-2 border rounded bg-surface d-flex align-items-center gap-2 stage-summary-card" style="border-left: 4px solid <?= $c['bg'] ?> !important;">
                <div class="stage-icon-pill" style="background-color: <?= $c['soft'] ?>; color: <?= $c['bg'] ?>;">
                    <i class="bi <?= htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                </div>
                <div class="min-w-0 flex-grow-1">
                    <div class="text-muted text-uppercase" style="font-size:0.625rem; font-weight:600;"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="d-flex align-items-baseline gap-1">
                        <span class="fs-6 fw-bold text-dark stage-summary-total" data-stage="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= number_format($cnt) ?></span>
                        <span class="text-muted stage-summary-pct" data-stage="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" style="font-size:0.625rem;">(<?= $pct ?>%)</span>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Toolbar & Search Strip -->
<div class="crm-toolbar mb-3">
    <div class="crm-toolbar-left d-flex align-items-center gap-2 flex-wrap">
        <div class="input-group" style="width: 320px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" id="plSearchInput" class="form-control" placeholder="Search by name, company, mobile...">
        </div>
        <span class="text-muted small">Showing <span id="plVisibleCount" class="fw-semibold text-dark"><?= number_format($initialLoadedTotal) ?></span> of <span id="plTotalAllCount" class="fw-semibold text-dark"><?= number_format($dbTotalAll) ?></span> leads</span>
    </div>
    <div class="crm-toolbar-right d-flex align-items-center gap-2">
        <?php if ($canMoveStage): ?>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="bi bi-hand-index-thumb me-1"></i>Drag cards between columns to change stage</span>
        <?php else: ?>
            <span class="badge bg-secondary"><i class="bi bi-lock me-1"></i>Stage movement view only</span>
        <?php endif; ?>
    </div>
</div>

<!-- Board Horizontal Scroll View -->
<div class="pl-board-scroll">
    <div class="pl-board">
        <?php foreach ($pipelineOrder as $status): ?>
            <?php
            $c = $stageColors[$status] ?? ['bg' => '#64748b', 'soft' => '#f8fafc', 'border' => '#e2e8f0'];
            $meta = $statusMeta[$status] ?? ['label' => $status, 'description' => '', 'icon' => 'bi-circle'];
            $leads = $columns[$status] ?? [];
            $totalInStage = $stageTotals[$status] ?? 0;
            $remaining = $stageRemaining[$status] ?? 0;
            ?>
            <section class="pl-col" data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                <div class="pl-col-head" style="border-top: 3px solid <?= $c['bg'] ?>;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-2 fw-semibold text-dark small">
                            <span class="pl-head-icon" style="background-color: <?= $c['soft'] ?>; color: <?= $c['bg'] ?>;">
                                <i class="bi <?= htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                            </span>
                            <span><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <span class="badge pl-stage-count" data-stage="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" style="background-color: <?= $c['soft'] ?>; color: <?= $c['bg'] ?>; border: 1px solid <?= $c['border'] ?>;">
                            <?= number_format($totalInStage) ?>
                        </span>
                    </div>
                    <?php if (!empty($meta['description'])): ?>
                        <div class="text-muted small mt-1" style="font-size:0.6875rem;"><?= htmlspecialchars($meta['description'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>

                <div class="pl-col-body" data-stage="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="pl-cards-container">
                        <?php if (!empty($leads)): ?>
                            <?php foreach ($leads as $lead): ?>
                                <?= renderPipelineCard($lead, $status, $canMoveStage) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="pl-empty" style="<?= empty($leads) ? '' : 'display:none;' ?>">
                        <i class="bi bi-inbox text-muted"></i>
                        <span>No leads in this stage</span>
                    </div>

                    <?php if ($remaining > 0): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 pl-load-more-btn mt-2" data-stage="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" data-offset="<?= count($leads) ?>" data-limit="<?= $cardsPerStage ?>">
                            <i class="bi bi-chevron-double-down me-1"></i>Load More (<span class="pl-remaining-count"><?= number_format($remaining) ?></span> left)
                        </button>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</div>

<!-- Floating Toast Container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 9999;">
    <div id="plToast" class="toast align-items-center border-0 text-white shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2">
                <i id="plToastIcon" class="bi bi-check-circle fs-5"></i>
                <span id="plToastMsg">Notification</span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

<style>
.stage-summary-card {
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.stage-summary-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}
.stage-icon-pill {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.pl-head-icon {
    width: 24px;
    height: 24px;
    border-radius: var(--radius-sm);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8125rem;
}
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
    width: 295px;
    background: #F8FAFC;
    border: 1px solid var(--border-default);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xs);
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 240px);
}
.pl-col-head {
    padding: 0.75rem 0.875rem;
    border-bottom: 1px solid var(--border-subtle);
    background: #FFFFFF;
    border-top-left-radius: var(--radius-lg);
    border-top-right-radius: var(--radius-lg);
}
.pl-col-body {
    flex: 1;
    overflow-y: auto;
    padding: 0.625rem;
    display: flex;
    flex-direction: column;
    min-height: 120px;
    transition: background-color 0.15s ease, outline 0.15s ease;
}
.pl-col-body.is-drop-target {
    background-color: #EEF2FF !important;
    outline: 2px dashed #6D45F9 !important;
    outline-offset: -3px;
    border-radius: var(--radius-md);
}
.pl-cards-container {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
}
.pl-card {
    background: #FFFFFF;
    border: 1px solid var(--border-default);
    border-left: 4px solid #6D45F9;
    border-radius: var(--radius-md);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
    transition: transform 0.14s ease, box-shadow 0.14s ease, border-color 0.14s ease, opacity 0.14s ease;
    overflow: hidden;
    user-select: none;
}
.pl-card[draggable="true"] {
    cursor: grab;
}
.pl-card[draggable="true"]:active {
    cursor: grabbing;
}
.pl-card.is-dragging {
    opacity: 0.35 !important;
    transform: scale(0.97) !important;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12) !important;
}
.pl-card:hover {
    border-color: #CBD5E1;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
    transform: translateY(-2px);
}
.pl-drag-handle {
    cursor: grab;
    color: #94A3B8;
    padding: 2px 2px;
    display: inline-flex;
    align-items: center;
    border-radius: 4px;
    font-size: 0.875rem;
    line-height: 1;
}
.pl-drag-handle:hover {
    color: #475569;
    background: #F1F5F9;
}
.pl-card.status-card-lead {
    background: #FFFFFF;
    border-color: #E2E8F0;
    border-left: 4px solid #6D45F9 !important;
}
.pl-card.status-card-reject {
    background: linear-gradient(180deg, #FEF2F2 0%, #FFFFFF 85%) !important;
    border-color: #FECDD3 !important;
    border-left: 4px solid #DC2626 !important;
}
.pl-card.status-card-reject:hover {
    border-color: #F87171 !important;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.14) !important;
}
.pl-card.status-card-disbursed {
    background: linear-gradient(180deg, #F0FDF4 0%, #FFFFFF 85%) !important;
    border-color: #BBF7D0 !important;
    border-left: 4px solid #16A34A !important;
}
.pl-card.status-card-disbursed:hover {
    border-color: #4ADE80 !important;
    box-shadow: 0 4px 16px rgba(22, 163, 74, 0.14) !important;
}
.pl-card.status-card-rework {
    background: linear-gradient(180deg, #FFFBEB 0%, #FFFFFF 85%) !important;
    border-color: #FDE68A !important;
    border-left: 4px solid #D97706 !important;
}
.pl-card.status-card-rework:hover {
    border-color: #F59E0B !important;
    box-shadow: 0 4px 16px rgba(217, 119, 6, 0.14) !important;
}
.pl-card.status-card-followup {
    background: linear-gradient(180deg, #F0F9FF 0%, #FFFFFF 85%) !important;
    border-color: #BAE6FD !important;
    border-left: 4px solid #0284C7 !important;
}
.pl-card.status-card-internal_underwriting {
    background: linear-gradient(180deg, #F8FAFC 0%, #FFFFFF 85%) !important;
    border-color: #CBD5E1 !important;
    border-left: 4px solid #4D72E8 !important;
}
.pl-card.status-card-login {
    background: linear-gradient(180deg, #FFFDF0 0%, #FFFFFF 85%) !important;
    border-color: #FEF08A !important;
    border-left: 4px solid #D89012 !important;
}
.pl-card.status-card-bank_underwriting {
    background: linear-gradient(180deg, #FAF5FF 0%, #FFFFFF 85%) !important;
    border-color: #E9D5FF !important;
    border-left: 4px solid #8C72FF !important;
}
.pl-card.status-card-sanctioned {
    background: linear-gradient(180deg, #ECFDF5 0%, #FFFFFF 85%) !important;
    border-color: #99F6E4 !important;
    border-left: 4px solid #16A66A !important;
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
.pl-load-more-btn {
    font-size: 0.75rem;
    padding: 0.35rem 0.5rem;
    border-style: dashed;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>';
    var ajaxUrl = '<?= url("modules/leads/php_scripts/lead_pipeline_ajax.php") ?>';
    var canMoveStage = <?= $canMoveStage ? 'true' : 'false' ?>;

    function showToast(msg, isSuccess) {
        var toastEl = document.getElementById('plToast');
        var toastBody = document.getElementById('plToastMsg');
        var toastIcon = document.getElementById('plToastIcon');
        if (!toastEl) return;
        toastEl.className = 'toast align-items-center border-0 text-white shadow-lg ' + (isSuccess ? 'bg-success' : 'bg-danger');
        toastIcon.className = 'bi ' + (isSuccess ? 'bi-check-circle fs-5' : 'bi-exclamation-triangle fs-5');
        toastBody.textContent = msg;
        var bsToast = bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4000 });
        bsToast.show();
    }

    function initExpandButtons(scope) {
        var root = scope || document;
        root.querySelectorAll('.pl-expand-btn').forEach(function(btn) {
            if (btn.__bound) return;
            btn.__bound = true;
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                this.closest('.pl-card').classList.toggle('expanded');
            });
        });
    }
    initExpandButtons();

    // Drag and Drop Logic
    var draggedCard = null;
    var sourceColBody = null;
    var sourceStage = null;

    function initDraggables(scope) {
        if (!canMoveStage) return;
        var root = scope || document;
        root.querySelectorAll('.pl-card[draggable="true"]').forEach(function(card) {
            if (card.__dragBound) return;
            card.__dragBound = true;

            card.addEventListener('dragstart', function(e) {
                draggedCard = this;
                sourceColBody = this.closest('.pl-col-body');
                sourceStage = this.getAttribute('data-stage') || sourceColBody.getAttribute('data-stage');
                this.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', JSON.stringify({
                    leadId: this.getAttribute('data-lead-id'),
                    fromStage: sourceStage
                }));
            });

            card.addEventListener('dragend', function() {
                this.classList.remove('is-dragging');
                document.querySelectorAll('.pl-col-body').forEach(function(col) {
                    col.classList.remove('is-drop-target');
                });
                draggedCard = null;
                sourceColBody = null;
                sourceStage = null;
            });
        });
    }
    initDraggables();

    // Drop zone listeners for columns
    document.querySelectorAll('.pl-col-body').forEach(function(colBody) {
        colBody.addEventListener('dragover', function(e) {
            if (!canMoveStage || !draggedCard) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (!this.classList.contains('is-drop-target')) {
                this.classList.add('is-drop-target');
            }
        });

        colBody.addEventListener('dragleave', function(e) {
            if (e.relatedTarget && this.contains(e.relatedTarget)) return;
            this.classList.remove('is-drop-target');
        });

        colBody.addEventListener('drop', function(e) {
            if (!canMoveStage || !draggedCard) return;
            e.preventDefault();
            this.classList.remove('is-drop-target');

            var targetStage = this.getAttribute('data-stage');
            var cardLeadId = draggedCard.getAttribute('data-lead-id');
            var fromStage = sourceStage;

            if (fromStage === targetStage) return;

            var cardsContainer = this.querySelector('.pl-cards-container');
            var targetEmpty = this.querySelector('.pl-empty');
            var origContainer = sourceColBody ? sourceColBody.querySelector('.pl-cards-container') : null;
            var origEmpty = sourceColBody ? sourceColBody.querySelector('.pl-empty') : null;

            // Optimistic DOM move
            cardsContainer.prepend(draggedCard);
            draggedCard.setAttribute('data-stage', targetStage);

            // Update card styling classes
            draggedCard.className = draggedCard.className.replace(/status-card-[a-z_]+/g, '').trim();
            draggedCard.classList.add('status-card-' + targetStage.toLowerCase());

            if (targetEmpty) targetEmpty.style.display = 'none';
            if (origContainer && origContainer.children.length === 0 && origEmpty) {
                origEmpty.style.display = '';
            }

            // Send AJAX update
            var formData = new FormData();
            formData.append('action', 'move_stage');
            formData.append('lead_id', cardLeadId);
            formData.append('new_stage', targetStage);
            formData.append('csrf_token', csrfToken);

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) {
                    throw new Error(data.error || 'Failed to update stage');
                }
                showToast(data.message || 'Lead moved successfully', true);

                // Update column badge counters
                if (data.counts) {
                    for (var st in data.counts) {
                        var badge = document.querySelector('.pl-stage-count[data-stage="' + st + '"]');
                        if (badge) badge.textContent = Number(data.counts[st]).toLocaleString();

                        var summaryTotal = document.querySelector('.stage-summary-total[data-stage="' + st + '"]');
                        if (summaryTotal) summaryTotal.textContent = Number(data.counts[st]).toLocaleString();

                        var summaryPct = document.querySelector('.stage-summary-pct[data-stage="' + st + '"]');
                        if (summaryPct && data.total_all > 0) {
                            var pct = Math.round((data.counts[st] / data.total_all) * 100);
                            summaryPct.textContent = '(' + pct + '%)';
                        }
                    }
                    var activeHeader = document.getElementById('plActivePipelineCount');
                    if (activeHeader && data.total_active !== undefined) {
                        activeHeader.textContent = Number(data.total_active).toLocaleString();
                    }
                }
            })
            .catch(function(err) {
                // Rollback DOM move
                if (origContainer && draggedCard) {
                    origContainer.prepend(draggedCard);
                    draggedCard.setAttribute('data-stage', fromStage);
                    draggedCard.className = draggedCard.className.replace(/status-card-[a-z_]+/g, '').trim();
                    draggedCard.classList.add('status-card-' + fromStage.toLowerCase());
                    if (origEmpty) origEmpty.style.display = 'none';
                    if (cardsContainer.children.length === 0 && targetEmpty) {
                        targetEmpty.style.display = '';
                    }
                }
                showToast(err.message || 'Error moving lead', false);
            });
        });
    });

    // Pagination: Load More per column
    function attachLoadMore() {
        document.querySelectorAll('.pl-load-more-btn').forEach(function(btn) {
            if (btn.__bound) return;
            btn.__bound = true;

            btn.addEventListener('click', function() {
                var btnEl = this;
                var stage = btnEl.getAttribute('data-stage');
                var offset = parseInt(btnEl.getAttribute('data-offset'), 10) || 0;
                var limit = parseInt(btnEl.getAttribute('data-limit'), 10) || 20;
                var search = document.getElementById('plSearchInput') ? document.getElementById('plSearchInput').value.trim() : '';

                var origHtml = btnEl.innerHTML;
                btnEl.disabled = true;
                btnEl.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading...';

                var formData = new FormData();
                formData.append('action', 'load_more');
                formData.append('stage', stage);
                formData.append('offset', offset);
                formData.append('limit', limit);
                formData.append('search', search);
                formData.append('csrf_token', csrfToken);

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    btnEl.disabled = false;
                    btnEl.innerHTML = origHtml;

                    if (!data.success) {
                        showToast(data.error || 'Failed to load more leads', false);
                        return;
                    }

                    var colBody = btnEl.closest('.pl-col-body');
                    var container = colBody.querySelector('.pl-cards-container');
                    if (container && data.html) {
                        var temp = document.createElement('div');
                        temp.innerHTML = data.html;
                        while (temp.firstChild) {
                            container.appendChild(temp.firstChild);
                        }
                        initExpandButtons(container);
                        initDraggables(container);
                    }

                    var newOffset = offset + data.count;
                    btnEl.setAttribute('data-offset', newOffset);

                    var remEl = btnEl.querySelector('.pl-remaining-count');
                    if (remEl) remEl.textContent = Number(data.remaining).toLocaleString();

                    if (data.remaining <= 0) {
                        btnEl.style.display = 'none';
                    }

                    // Update visible count in toolbar
                    var countAll = document.querySelectorAll('.pl-card:not(.hidden)').length;
                    var visEl = document.getElementById('plVisibleCount');
                    if (visEl) visEl.textContent = countAll.toLocaleString();
                })
                .catch(function(err) {
                    btnEl.disabled = false;
                    btnEl.innerHTML = origHtml;
                    showToast('Network error loading cards', false);
                });
            });
        });
    }
    attachLoadMore();

    // Client-side quick filter
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
            var visEl = document.getElementById('plVisibleCount');
            if (visEl) visEl.textContent = visible.toLocaleString();
        });
    }
});
</script>
