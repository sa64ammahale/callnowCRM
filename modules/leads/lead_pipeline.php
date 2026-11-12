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
    'LEAD' => ['bg' => '#713BFF', 'soft' => '#F5F3FF', 'border' => '#DDD6FE', 'accent' => '#713BFF'],
    'FOLLOWUP' => ['bg' => '#0EA5E9', 'soft' => '#F0F9FF', 'border' => '#BAE6FD', 'accent' => '#0EA5E9'],
    'INTERNAL_UNDERWRITING' => ['bg' => '#4F6BFF', 'soft' => '#EEF2FF', 'border' => '#C7D2FE', 'accent' => '#4F6BFF'],
    'LOGIN' => ['bg' => '#F59E0B', 'soft' => '#FFFBEB', 'border' => '#FDE68A', 'accent' => '#F59E0B'],
    'BANK_UNDERWRITING' => ['bg' => '#8B5CF6', 'soft' => '#F5F3FF', 'border' => '#DDD6FE', 'accent' => '#8B5CF6'],
    'SANCTIONED' => ['bg' => '#10B981', 'soft' => '#ECFDF5', 'border' => '#A7F3D0', 'accent' => '#10B981'],
    'DISBURSED' => ['bg' => '#059669', 'soft' => '#ECFDF5', 'border' => '#6EE7B7', 'accent' => '#059669'],
    'REJECT' => ['bg' => '#F43F5E', 'soft' => '#FFF1F2', 'border' => '#FECDD3', 'accent' => '#F43F5E'],
];

$emptyIcons = [
    'LEAD' => 'bi-person-badge',
    'FOLLOWUP' => 'bi-arrow-repeat',
    'INTERNAL_UNDERWRITING' => 'bi-building-check',
    'LOGIN' => 'bi-box-arrow-in-right',
    'BANK_UNDERWRITING' => 'bi-bank',
    'SANCTIONED' => 'bi-shield-check',
    'DISBURSED' => 'bi-cash-stack',
    'REJECT' => 'bi-clipboard-x',
];

$where = '';
$accessibleUserIds = getAccessibleUserIds($link);
if (!isAdmin() && !isSuperAdmin() && !empty($accessibleUserIds)) {
    $where = 'WHERE l.assigned_to IN (' . implode(',', array_map('intval', $accessibleUserIds)) . ')';
}

$salespeople = [];
$spSql = "SELECT ID, NAME FROM " . tn('TBL_USERS') . " WHERE STATUS = 1 ORDER BY NAME ASC";
$spRes = mysqli_query($link, $spSql);
if ($spRes) {
    while ($sp = mysqli_fetch_assoc($spRes)) {
        $salespeople[] = $sp;
    }
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

<link rel="stylesheet" href="<?= (defined('APP_BASE') && APP_BASE !== '' ? APP_BASE . '/' : '') ?>assets/css/pipeline-premium.css?v=<?= file_exists(__DIR__ . '/../../assets/css/pipeline-premium.css') ? filemtime(__DIR__ . '/../../assets/css/pipeline-premium.css') : '1.0' ?>">

<!-- Breadcrumb -->
<div class="pl-breadcrumb">
    <a href="<?= url('dashboard.php') ?>">Home</a>
    <i class="bi bi-chevron-right pl-breadcrumb-divider"></i>
    <a href="<?= url('modules/leads/leads_dashboard.php') ?>">Leads</a>
    <i class="bi bi-chevron-right pl-breadcrumb-divider"></i>
    <span class="pl-breadcrumb-active">Pipeline Board</span>
</div>

<!-- Header Hero Banner -->
<div class="pl-hero-header">
    <div class="pl-header-left">
        <div class="pl-header-icon-box">
            <i class="bi bi-kanban"></i>
        </div>
        <div>
            <h1 class="pl-header-title">Lead Pipeline Board</h1>
            <p class="pl-header-subtitle">
                <span id="plActivePipelineCount"><?= number_format($totalPipeline) ?></span> active leads across <?= count($pipelineOrder) - 1 ?> pipeline stages
            </p>
        </div>
    </div>
    <div class="pl-header-actions">
        <a href="<?= url('modules/leads/leads_dashboard.php') ?>" class="pl-btn-secondary">
            <i class="bi bi-bar-chart"></i>
            <span>Analytics</span>
        </a>
        <a href="<?= url('modules/leads/lead_list.php') ?>" class="pl-btn-secondary">
            <i class="bi bi-table"></i>
            <span>Register</span>
        </a>
        <?php if (can('manage_leads')): ?>
        <a href="<?= url('modules/leads/lead_insert.php') ?>" class="pl-btn-primary">
            <i class="bi bi-plus-lg"></i>
            <span>Add Lead</span>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Pipeline Summary Strip (8 Horizontal KPI Cards) -->
<div class="pl-summary-grid">
    <?php foreach ($pipelineOrder as $status): ?>
        <?php
        $cnt = $stageTotals[$status] ?? 0;
        $c = $stageColors[$status] ?? ['bg' => '#64748b', 'soft' => '#F8FAFC', 'border' => '#E2E8F0'];
        $meta = $statusMeta[$status] ?? ['label' => $status, 'icon' => 'bi-circle'];
        $pct = $dbTotalAll > 0 ? round(($cnt / $dbTotalAll) * 100) : 0;
        ?>
        <div class="pl-summary-card">
            <div class="pl-summary-icon-pill" style="background-color: <?= $c['soft'] ?>; color: <?= $c['bg'] ?>;">
                <i class="bi <?= htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
            </div>
            <div class="pl-summary-content">
                <div class="pl-summary-label"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="pl-summary-metric">
                    <span class="pl-summary-total stage-summary-total" data-stage="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= number_format($cnt) ?></span>
                    <span class="pl-summary-pct stage-summary-pct" data-stage="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">(<?= $pct ?>%)</span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Search & Controls Toolbar -->
<div class="pl-toolbar">
    <div class="pl-toolbar-left">
        <div class="pl-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="plSearchInput" class="pl-search-input" placeholder="Search by name, company, mobile...">
        </div>
        <span class="pl-showing-text">
            Showing <span id="plVisibleCount" class="pl-showing-count"><?= number_format($initialLoadedTotal) ?></span> of <span id="plTotalAllCount" class="pl-showing-count"><?= number_format($dbTotalAll) ?></span> leads
        </span>
    </div>
    <div class="pl-toolbar-right">
        <?php if ($canMoveStage): ?>
            <span class="badge pl-drag-instruction">
                <i class="bi bi-hand-index-thumb"></i>
                <span>Drag cards between columns to change stage</span>
            </span>
        <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary border">
                <i class="bi bi-lock me-1"></i>Stage movement view only
            </span>
        <?php endif; ?>

        <button type="button" class="pl-tool-btn" id="plFilterToggleBtn" title="Toggle Filters">
            <i class="bi bi-funnel"></i>
            <span>Filter</span>
        </button>

        <div class="dropdown d-inline-block">
            <button class="pl-tool-btn dropdown-toggle" type="button" id="plViewSelectorBtn" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-kanban"></i>
                <span>View</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="plViewSelectorBtn">
                <li><a class="dropdown-item active" href="<?= url('modules/leads/lead_pipeline.php') ?>"><i class="bi bi-kanban me-2 text-primary"></i>Kanban Board</a></li>
                <li><a class="dropdown-item" href="<?= url('modules/leads/lead_list.php') ?>"><i class="bi bi-table me-2 text-muted"></i>List View</a></li>
            </ul>
        </div>
    </div>
</div>

<!-- Collapsible Filter Panel -->
<div class="pl-filter-drawer" id="plFilterDrawer">
    <div class="row g-3 align-items-end">
        <div class="col-md-3 col-sm-6">
            <label class="form-label small fw-semibold text-secondary mb-1">Pipeline Stage</label>
            <select id="plStageFilter" class="form-select form-select-sm rounded-3">
                <option value="ALL">All Stages</option>
                <?php foreach ($pipelineOrder as $st): ?>
                    <option value="<?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta[$st]['label'] ?? $st, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label small fw-semibold text-secondary mb-1">Assigned Salesperson</label>
            <select id="plUserFilter" class="form-select form-select-sm rounded-3">
                <option value="ALL">All Salespersons</option>
                <?php foreach ($salespeople as $sp): ?>
                    <option value="<?= htmlspecialchars(strtolower($sp['NAME']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sp['NAME'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label small fw-semibold text-secondary mb-1">Aging / Days in Stage</label>
            <select id="plAgingFilter" class="form-select form-select-sm rounded-3">
                <option value="ALL">All Durations</option>
                <option value="CRITICAL">Critical (&gt; 7 days)</option>
                <option value="WARNING">Attention (4 - 7 days)</option>
                <option value="FRESH">Fresh (&le; 3 days)</option>
            </select>
        </div>
        <div class="col-md-3 col-sm-6 d-flex gap-2">
            <button type="button" class="btn btn-sm btn-primary flex-grow-1 rounded-3" id="plApplyFilterBtn">
                <i class="bi bi-check-lg me-1"></i>Apply Filter
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-3" id="plResetFilterBtn" title="Reset Filters">
                <i class="bi bi-arrow-counterclockwise"></i>
            </button>
        </div>
    </div>
</div>

<!-- Board Horizontal Scroll View -->
<div class="pl-board-scroll">
    <div class="pl-board">
        <?php foreach ($pipelineOrder as $status): ?>
            <?php
            $c = $stageColors[$status] ?? ['bg' => '#64748b', 'soft' => '#F8FAFC', 'border' => '#E2E8F0'];
            $meta = $statusMeta[$status] ?? ['label' => $status, 'description' => '', 'icon' => 'bi-circle'];
            $leads = $columns[$status] ?? [];
            $totalInStage = $stageTotals[$status] ?? 0;
            $remaining = $stageRemaining[$status] ?? 0;
            $emptyIcon = $emptyIcons[$status] ?? 'bi-inbox';
            ?>
            <section class="pl-col" data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                <div class="pl-col-head" style="border-top-color: <?= $c['bg'] ?>;">
                    <div class="pl-col-head-top">
                        <div class="pl-col-title-wrap">
                            <span class="pl-col-icon" style="background-color: <?= $c['soft'] ?>; color: <?= $c['bg'] ?>;">
                                <i class="bi <?= htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                            </span>
                            <span class="pl-col-title"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <span class="pl-col-count-badge pl-stage-count" data-stage="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" style="background-color: <?= $c['soft'] ?>; color: <?= $c['bg'] ?>; border: 1px solid <?= $c['border'] ?>;">
                            <?= number_format($totalInStage) ?>
                        </span>
                    </div>
                    <?php if (!empty($meta['description'])): ?>
                        <div class="pl-col-desc"><?= htmlspecialchars($meta['description'], ENT_QUOTES, 'UTF-8') ?></div>
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
                        <div class="pl-empty-bubble" style="background-color: <?= $c['soft'] ?>; color: <?= $c['bg'] ?>;">
                            <i class="bi <?= $emptyIcon ?>"></i>
                        </div>
                        <div class="pl-empty-text">No leads in this stage</div>
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

<!-- Floating Toast Notification -->
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

            cardsContainer.prepend(draggedCard);
            draggedCard.setAttribute('data-stage', targetStage);

            draggedCard.className = draggedCard.className.replace(/status-card-[a-z_]+/g, '').trim();
            draggedCard.classList.add('status-card-' + targetStage.toLowerCase());

            if (targetEmpty) targetEmpty.style.display = 'none';
            if (origContainer && origContainer.children.length === 0 && origEmpty) {
                origEmpty.style.display = '';
            }

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

                    applyCombinedFilters();
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

    var filterToggleBtn = document.getElementById('plFilterToggleBtn');
    var filterDrawer = document.getElementById('plFilterDrawer');
    if (filterToggleBtn && filterDrawer) {
        filterToggleBtn.addEventListener('click', function() {
            filterDrawer.classList.toggle('is-open');
        });
    }

    function applyCombinedFilters() {
        var q = document.getElementById('plSearchInput') ? document.getElementById('plSearchInput').value.toLowerCase().trim() : '';
        var stageFilter = document.getElementById('plStageFilter') ? document.getElementById('plStageFilter').value : 'ALL';
        var userFilter = document.getElementById('plUserFilter') ? document.getElementById('plUserFilter').value : 'ALL';
        var agingFilter = document.getElementById('plAgingFilter') ? document.getElementById('plAgingFilter').value : 'ALL';

        var visible = 0;
        document.querySelectorAll('.pl-card').forEach(function(card) {
            var text = card.textContent.toLowerCase();
            var cardStage = (card.getAttribute('data-stage') || '').toUpperCase();
            var searchMatch = !q || text.indexOf(q) !== -1;
            var stageMatch = (stageFilter === 'ALL') || (cardStage === stageFilter);
            var userMatch = (userFilter === 'ALL') || (text.indexOf(userFilter) !== -1);

            var agingMatch = true;
            if (agingFilter === 'CRITICAL') {
                agingMatch = card.querySelector('.pl-aging-danger') !== null;
            } else if (agingFilter === 'WARNING') {
                agingMatch = card.querySelector('.pl-aging-warning') !== null;
            } else if (agingFilter === 'FRESH') {
                agingMatch = (card.querySelector('.pl-aging-danger') === null && card.querySelector('.pl-aging-warning') === null);
            }

            var match = searchMatch && stageMatch && userMatch && agingMatch;
            card.classList.toggle('hidden', !match);
            if (match) visible++;
        });

        document.querySelectorAll('.pl-col').forEach(function(col) {
            var colStatus = (col.getAttribute('data-status') || '').toUpperCase();
            if (stageFilter !== 'ALL' && colStatus !== stageFilter) {
                col.style.display = 'none';
            } else {
                col.style.display = '';
                var cards = col.querySelectorAll('.pl-card:not(.hidden)');
                var empty = col.querySelector('.pl-empty');
                if (empty) {
                    empty.style.display = cards.length ? 'none' : '';
                }
            }
        });

        var visEl = document.getElementById('plVisibleCount');
        if (visEl) visEl.textContent = visible.toLocaleString();
    }

    var searchInput = document.getElementById('plSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', applyCombinedFilters);
    }

    var applyFilterBtn = document.getElementById('plApplyFilterBtn');
    if (applyFilterBtn) {
        applyFilterBtn.addEventListener('click', applyCombinedFilters);
    }

    var resetFilterBtn = document.getElementById('plResetFilterBtn');
    if (resetFilterBtn) {
        resetFilterBtn.addEventListener('click', function() {
            if (document.getElementById('plStageFilter')) document.getElementById('plStageFilter').value = 'ALL';
            if (document.getElementById('plUserFilter')) document.getElementById('plUserFilter').value = 'ALL';
            if (document.getElementById('plAgingFilter')) document.getElementById('plAgingFilter').value = 'ALL';
            if (document.getElementById('plSearchInput')) document.getElementById('plSearchInput').value = '';
            applyCombinedFilters();
        });
    }
});
</script>
