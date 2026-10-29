<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/lead_common.php';

$allowedTBL_ROLES = ['Admin', 'Manager', 'Supervisor', 'Officer', 'Super Admin'];
if (!in_array(USER_ROLE, $allowedTBL_ROLES, true) && !isSuperAdmin()) {
    header('Location: leads_dashboard');
    exit;
}

ensureLeadModuleSchema($link);
ensureLeadFilterPreferenceSchema($link);
mysqli_set_charset($link, 'utf8mb4');

$prefillSearch = trim((string)($_GET['q'] ?? ''));
$defaultPipelineStatuses = ['LEAD', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT'];
$currentMonthKey = date('Y-m');
$previousMonthKey = date('Y-m', strtotime('first day of last month'));

$baseConditions = [];
$accessCondition = getLeadAccessCondition($link, 'l');
if ($accessCondition !== null) {
    $baseConditions[] = $accessCondition;
}
$baseWhere = $baseConditions ? ('WHERE ' . implode(' AND ', $baseConditions)) : '';

$totalRecords = (int)mysqli_fetch_row(mysqli_query(
    $link,
    "SELECT COUNT(*) FROM " . tn('TBL_LEADS') . " l {$baseWhere}"
))[0];

$loginMonths = [];
$followupMonths = [];
$assignedOptions = [];
$loanTypeOptionsAvailable = [];

$loginMonthResult = mysqli_query(
    $link,
    "SELECT DISTINCT COALESCE(DATE_FORMAT(l.login_date, '%Y-%m'), DATE_FORMAT(l.next_followup_at, '%Y-%m'), DATE_FORMAT(l.created_at, '%Y-%m')) AS month_key
     FROM " . tn('TBL_LEADS') . " l
     {$baseWhere}
     HAVING month_key IS NOT NULL AND month_key <> ''
     ORDER BY month_key DESC"
);
while ($loginMonthResult && ($row = mysqli_fetch_assoc($loginMonthResult))) {
    $monthKey = (string)$row['month_key'];
    $loginMonths[$monthKey] = date('M Y', strtotime($monthKey . '-01'));
}

$followupMonthResult = mysqli_query(
    $link,
    "SELECT DISTINCT DATE_FORMAT(l.next_followup_at, '%Y-%m') AS month_key
     FROM " . tn('TBL_LEADS') . " l
     {$baseWhere}" . ($baseWhere ? ' AND ' : ' WHERE ') . "l.next_followup_at IS NOT NULL
     ORDER BY month_key DESC"
);
while ($followupMonthResult && ($row = mysqli_fetch_assoc($followupMonthResult))) {
    $monthKey = (string)$row['month_key'];
    $followupMonths[$monthKey] = date('M Y', strtotime($monthKey . '-01'));
}

$assignedResult = mysqli_query(
    $link,
    "SELECT DISTINCT COALESCE(u.NAME, 'Unassigned') AS assigned_name
     FROM " . tn('TBL_LEADS') . " l
     LEFT JOIN " . tn('TBL_USERS') . " u ON u.ID = l.assigned_to
     {$baseWhere}
     ORDER BY assigned_name"
);
while ($assignedResult && ($row = mysqli_fetch_assoc($assignedResult))) {
    $assignedOptions[(string)$row['assigned_name']] = (string)$row['assigned_name'];
}

$loanTypeResult = mysqli_query(
    $link,
    "SELECT DISTINCT l.loan_type
     FROM " . tn('TBL_LEADS') . " l
     {$baseWhere}" . ($baseWhere ? ' AND ' : ' WHERE ') . "l.loan_type IS NOT NULL AND l.loan_type <> ''
     ORDER BY l.loan_type"
);
while ($loanTypeResult && ($row = mysqli_fetch_assoc($loanTypeResult))) {
    $loanType = trim((string)$row['loan_type']);
    if ($loanType !== '') {
        $loanTypeOptionsAvailable[$loanType] = $loanType;
    }
}

krsort($loginMonths);
krsort($followupMonths);
ksort($assignedOptions, SORT_NATURAL | SORT_FLAG_CASE);
$loanTypeOptionsAvailable = array_replace(array_fill_keys(loanTypeOptions(), null), $loanTypeOptionsAvailable);
$loanTypeOptionsAvailable = array_keys(array_filter(
    $loanTypeOptionsAvailable,
    static fn ($key) => is_string($key),
    ARRAY_FILTER_USE_KEY
));

$savedFilterState = getUserPageFilterPreference($link, USER_ID, 'lead_list');
if (!is_array($savedFilterState)) {
    $savedFilterState = [];
}
if ($prefillSearch !== '') {
    $savedFilterState['globalSearch'] = $prefillSearch;
}
$reworkParam = strtoupper(trim((string)($_GET['rework'] ?? '')));
if ($reworkParam === 'YES' || $reworkParam === 'INTERNAL' || $reworkParam === 'BANK') {
    $savedFilterState['quickRework'] = $reworkParam;
}
if (trim((string)($_GET['followup'] ?? '')) === 'today') {
    $savedFilterState['followupMonth'] = date('Y-m');
}
?>
<?php $pageTitle = 'Leads Register'; include __DIR__ . '/../../php_scripts/header.php';
$canDelete = isAdmin() || isManager() || isSupervisor() || isSuperAdmin();
?>

<div class="crm-module-header">
    <div>
        <div class="crm-module-header-title">
            <i class="bi bi-person-lines-fill text-primary"></i>
            <h1>Lead Register</h1>
        </div>
        <p class="crm-module-header-desc">Search, filter, inspect and manage converted and telecaller leads (<?= number_format($totalRecords) ?> total)</p>
    </div>
    <div class="crm-module-header-actions">
        <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="btn btn-secondary btn-sm">
            <i class="bi bi-kanban"></i> Pipeline Board
        </a>
        <a href="<?= url('modules/leads/leads_dashboard.php') ?>" class="btn btn-secondary btn-sm">
            <i class="bi bi-bar-chart"></i> Analytics
        </a>
        <?php if (can('manage_leads')): ?>
        <a href="<?= url('modules/leads/lead_insert.php') ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> + Add Lead
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Search + Quick Controls Toolbar -->
<div class="crm-toolbar">
    <div class="crm-toolbar-left">
        <div class="input-group" style="width: 320px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input id="universalSearch" type="text" class="form-control" placeholder="Search customer, mobile, company, bank..." value="<?= htmlspecialchars($prefillSearch) ?>">
        </div>
        <span class="text-muted small">Visible: <b id="totalLeadsCount" class="text-dark"><?= number_format($totalRecords) ?></b> leads</span>
    </div>
    <div class="crm-toolbar-right">
        <div class="d-flex align-items-center gap-1">
            <span class="text-muted small">Show:</span>
            <select id="perPageSelect" class="form-select form-select-sm" style="width: 100px;">
                <option value="12">12 / page</option>
                <option value="24">24 / page</option>
                <option value="48">48 / page</option>
            </select>
        </div>
        <button type="button" id="resetFilters" class="btn btn-secondary btn-sm" title="Reset all filters">
            <i class="bi bi-arrow-counterclockwise"></i> Reset
        </button>
    </div>
</div>

<!-- Multi-condition Filter Bar -->
<div class="card mb-3">
    <div class="card-body p-2 bg-subtle">
        <div class="row g-2">
            <div class="col-md-2 col-sm-4 col-6">
                <label class="form-label mb-1" style="font-size:0.6875rem;"><i class="bi bi-funnel me-1"></i>Status</label>
                <select id="quickStatusFilter" class="form-select form-select-sm">
                    <option value="PIPELINE">Pipeline Cases</option>
                    <option value="">All Statuses</option>
                    <?php foreach (leadStatusOptions() as $status): ?>
                        <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <label class="form-label mb-1" style="font-size:0.6875rem;"><i class="bi bi-calendar2-check me-1"></i>Login Month</label>
                <select id="loginMonthFilter" class="form-select form-select-sm">
                    <option value="current_previous">Current + Prev</option>
                    <option value="">All Months</option>
                    <option value="current">Current Month</option>
                    <option value="previous">Previous Month</option>
                    <?php foreach ($loginMonths as $mv => $ml): ?>
                        <option value="<?= htmlspecialchars($mv) ?>"><?= htmlspecialchars($ml) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <label class="form-label mb-1" style="font-size:0.6875rem;"><i class="bi bi-clock-history me-1"></i>Follow-up</label>
                <select id="followupMonthFilter" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="current">Current Month</option>
                    <option value="previous">Previous Month</option>
                    <option value="current_previous">Current + Prev</option>
                    <?php foreach ($followupMonths as $mv => $ml): ?>
                        <option value="<?= htmlspecialchars($mv) ?>"><?= htmlspecialchars($ml) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <label class="form-label mb-1" style="font-size:0.6875rem;"><i class="bi bi-send-check me-1"></i>Login Mode</label>
                <select id="quickLoginModeFilter" class="form-select form-select-sm">
                    <option value="">All Modes</option>
                    <?php foreach (loginModeOptions() as $mode): ?>
                        <option value="<?= htmlspecialchars($mode) ?>"><?= htmlspecialchars($mode) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <label class="form-label mb-1" style="font-size:0.6875rem;"><i class="bi bi-person-badge me-1"></i>Assigned</label>
                <select id="quickAssignedFilter" class="form-select form-select-sm">
                    <option value="">All Assignees</option>
                    <?php foreach ($assignedOptions as $al): ?>
                        <option value="<?= htmlspecialchars($al) ?>"><?= htmlspecialchars($al) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 col-sm-4 col-6">
                <label class="form-label mb-1" style="font-size:0.6875rem;"><i class="bi bi-briefcase me-1"></i>Loan</label>
                <select id="quickLoanTypeFilter" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($loanTypeOptionsAvailable as $lt): ?>
                        <option value="<?= htmlspecialchars($lt) ?>"><?= htmlspecialchars($lt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 col-sm-4 col-6">
                <label class="form-label mb-1" style="font-size:0.6875rem;"><i class="bi bi-exclamation-triangle me-1"></i>Rework</label>
                <select id="quickReworkFilter" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="YES">Any</option>
                    <option value="INTERNAL">Internal</option>
                    <option value="BANK">Bank</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Active Summary State -->
<div class="d-flex justify-content-between align-items-center mb-2 px-1">
    <span id="cardsSummary" class="text-muted small">Loading leads…</span>
</div>

<!-- Leads Grid Container -->
<div id="leadCards" class="row g-3"></div>

<!-- Pagination Controls -->
<div id="leadPagination" class="d-flex justify-content-center align-items-center gap-1 mt-4"></div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

<style>
.crm-lead-card {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-xs);
    transition: border-color 0.12s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
}
.crm-lead-card:hover {
    border-color: var(--border-strong);
}
.crm-lead-card-header {
    padding: 0.75rem 0.875rem 0.5rem;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.5rem;
}
.crm-lead-card-body {
    padding: 0 0.875rem 0.625rem;
    flex: 1;
}
.crm-lead-card-footer {
    padding: 0.5rem 0.875rem;
    border-top: 1px solid var(--border);
    background: var(--bg-subtle);
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 0 0 var(--radius-md) var(--radius-md);
}
.crm-lead-extras {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.2s ease;
    background: var(--bg-subtle);
    border-top: 1px solid var(--border);
}
.crm-lead-card.expanded .crm-lead-extras {
    max-height: 300px;
}
.crm-lead-card.expanded .expand-toggle i {
    transform: rotate(180deg);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const saveUrl = <?= json_encode(APP_BASE . '/modules/leads/php_scripts/lead_list_filter_state.php') ?>;
    const dataUrl = <?= json_encode(APP_BASE . '/modules/leads/php_scripts/lead_cards_data.php') ?>;
    const deleteUrl = <?= json_encode(APP_BASE . '/modules/leads/php_scripts/lead_delete.php') ?>;
    const canDelete = <?= $canDelete ? 'true' : 'false' ?>;
    const csrfToken = '<?= $_SESSION['csrf_token'] ?? '' ?>';
    const viewBase = <?= json_encode(APP_BASE . '/modules/leads') ?>;
    const localStorageKey = 'lead_list_filters_user_<?= (int)USER_ID ?>';
    const serverState = <?= json_encode($savedFilterState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const totalLeadsCount = document.getElementById('totalLeadsCount');
    const cardsSummary = document.getElementById('cardsSummary');
    const leadCards = document.getElementById('leadCards');
    const leadPagination = document.getElementById('leadPagination');
    const searchInput = document.getElementById('universalSearch');
    const perPageSelect = document.getElementById('perPageSelect');
    const filterIds = [
        'quickStatusFilter', 'loginMonthFilter', 'followupMonthFilter',
        'quickLoginModeFilter', 'quickAssignedFilter', 'quickLoanTypeFilter', 'quickReworkFilter'
    ];

    const defaultState = {
        quickStatus: 'PIPELINE', loginMonth: 'current_previous', followupMonth: '',
        quickLoginMode: '', quickAssigned: '', quickLoanType: '', quickRework: '',
        globalSearch: '', perPage: 12, page: 1, columnFilters: {}
    };

    let state = normalizeState(resolveInitialState());
    let fetchTimer = null;
    let saveTimer = null;

    function normalizeState(source) {
        const data = source && typeof source === 'object' ? source : {};
        return {
            ...defaultState,
            ...data,
            perPage: [12, 24, 48].includes(Number(data.perPage)) ? Number(data.perPage) : 12,
            page: Math.max(1, Number(data.page || 1))
        };
    }

    function resolveInitialState() {
        if (serverState && Object.keys(serverState).length > 0) return serverState;
        try {
            const local = JSON.parse(localStorage.getItem(localStorageKey) || 'null');
            return local && typeof local === 'object' ? local : defaultState;
        } catch (e) { return defaultState; }
    }

    function cacheState() { localStorage.setItem(localStorageKey, JSON.stringify(state)); }

    function persistState() {
        cacheState();
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            fetch(saveUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(state) }).catch(() => {});
        }, 250);
    }

    function syncInputsFromState() {
        searchInput.value = state.globalSearch || '';
        perPageSelect.value = String(state.perPage);
        filterIds.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = state[id.replace('Filter', '')] || '';
        });
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function statusBadgeClass(status) {
        var cls = {
            'LEAD': 'bg-primary', 'FOLLOWUP': 'bg-info', 'LOGIN': 'bg-warning',
            'INTERNAL_UNDERWRITING': 'bg-secondary', 'BANK_UNDERWRITING': 'bg-purple', 'SANCTIONED': 'bg-success', 'DISBURSED': 'bg-dark', 'REJECT': 'bg-danger'
        };
        return cls[status] || 'bg-secondary';
    }

    function renderCards(cards) {
        if (!cards || !cards.length) {
            leadCards.innerHTML = '<div class="col-12"><div class="empty-state p-5 bg-surface border rounded"><i class="bi bi-search text-muted fs-2 mb-2 d-block"></i><div class="fw-semibold text-dark">No leads found</div><div class="text-muted small">No customer records match your current filters.</div></div></div>';
            return;
        }
        leadCards.innerHTML = cards.map(function(card) {
            var statusClass = statusBadgeClass(card.status);
            var statusIcon = card.status_icon || 'bi-circle';
            var hasExpander = (card.timeline && card.timeline.length) || (card.financial && card.financial.length) || (card.banking && card.banking.length) || (card.source && card.source.length) || (card.notes && card.notes.length);

            function fieldList(arr) {
                if (!arr || !arr.length) return '';
                return arr.map(function(f) {
                    return '<div class="col-6 mb-1"><span class="text-muted" style="font-size:0.6875rem;">' + escapeHtml(f.label) + ':</span> <span class="fw-medium text-dark" style="font-size:0.75rem;">' + escapeHtml(f.value) + '</span></div>';
                }).join('');
            }

            var extras = '';
            if (hasExpander) {
                extras += '<div class="crm-lead-extras"><div class="p-2"><div class="row g-1">';
                extras += fieldList(card.timeline);
                extras += fieldList(card.financial);
                extras += fieldList(card.banking);
                extras += fieldList(card.source);
                extras += fieldList(card.notes);
                extras += '</div></div></div>';
            }

            var followupDate = '';
            var followupBadge = '';
            if (card.timeline) {
                var f = card.timeline.find(function(t) { return t.label === 'Next Follow-up'; });
                if (f) {
                    followupDate = f.value;
                    if (card.next_followup_raw) {
                        var todayStr = new Date().toISOString().slice(0,10);
                        if (card.next_followup_raw < todayStr) followupBadge = 'bg-danger';
                        else if (card.next_followup_raw === todayStr) followupBadge = 'bg-warning text-dark';
                        else followupBadge = 'bg-secondary';
                    }
                }
            }

            return '<div class="col-xl-4 col-md-6">'
                + '<div class="crm-lead-card" data-id="' + card.lead_id + '">'
                + '<div class="crm-lead-card-header">'
                + '<div class="min-w-0">'
                + '<a href="' + viewBase + '/lead_view.php?id=' + card.lead_id + '" class="fw-semibold text-dark text-decoration-none d-block text-truncate">' + escapeHtml(card.customer_name) + '</a>'
                + '<div class="text-muted small" style="font-size:0.6875rem;"><i class="bi bi-person me-1"></i>'
                + escapeHtml((card.source || []).find(function(s) { return s.label === 'Assigned To'; })?.value || 'Unassigned')
                + (card.identity ? (card.identity.find(function(i) { return i.label === 'Company'; })?.value ? ' &bull; ' + escapeHtml(card.identity.find(function(i) { return i.label === 'Company'; }).value) : '') : '')
                + '</div>'
                + '</div>'
                + '<span class="badge ' + statusClass + '"><i class="bi ' + statusIcon + ' me-1"></i>' + escapeHtml(card.status_label) + '</span>'
                + '</div>'
                + '<div class="crm-lead-card-body">'
                + '<div class="d-flex flex-wrap gap-1 mb-2">'
                + (card.mobile && card.mobile !== 'N/A' ? '<a href="tel:' + escapeHtml(card.mobile) + '" class="badge bg-secondary text-decoration-none"><i class="bi bi-telephone me-1"></i>' + escapeHtml(card.mobile) + '</a>' : '')
                + (card.identity ? card.identity.filter(function(i) { return i.label === 'Loan Type'; }).map(function(i) { return '<span class="badge bg-primary">' + escapeHtml(i.value) + '</span>'; }).join('') : '')
                + (followupDate ? '<span class="badge ' + (followupBadge || 'bg-secondary') + '"><i class="bi bi-alarm me-1"></i>' + escapeHtml(followupDate) + '</span>' : '')
                + (card.rework_flag ? '<span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Rework</span>' : '')
                + (card.login_status ? '<span class="badge bg-success">' + escapeHtml(card.login_status) + '</span>' : '')
                + '</div>'
                + '</div>'
                + '<div class="crm-lead-card-footer">'
                + '<div class="d-flex align-items-center gap-1">'
                + '<a href="' + viewBase + '/lead_view.php?id=' + card.lead_id + '" class="btn btn-primary btn-sm py-1 px-2" style="font-size:0.75rem;"><i class="bi bi-pencil-square me-1"></i>Manage</a>'
                + (hasExpander ? '<button type="button" class="btn btn-secondary btn-sm py-1 px-2 expand-toggle" style="font-size:0.75rem;"><i class="bi bi-chevron-down"></i> Details</button>' : '')
                + '</div>'
                + (canDelete ? '<button type="button" class="btn btn-ghost btn-sm text-danger p-1 delete-btn" data-id="' + card.lead_id + '" title="Delete lead"><i class="bi bi-trash3"></i></button>' : '')
                + '</div>'
                + extras
                + '</div>'
                + '</div>';
        }).join('');

        leadCards.querySelectorAll('.expand-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
                this.closest('.crm-lead-card').classList.toggle('expanded');
            });
        });

        if (canDelete) {
            leadCards.querySelectorAll('.delete-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    var id = this.getAttribute('data-id');
                    if (!id) return;
                    if (!confirm('Are you sure you want to delete lead #' + id + '? This action cannot be undone.')) return;
                    var body = new URLSearchParams({ lead_id: id, csrf_token: csrfToken });
                    var card = this.closest('.crm-lead-card');
                    if (card) card.style.opacity = '0.4';
                    fetch(deleteUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body })
                        .then(function(r) { return r.json(); })
                        .then(function(resp) {
                            if (resp.ok) {
                                if (card) card.parentElement.remove();
                                if (!leadCards.querySelector('.crm-lead-card')) {
                                    fetchCards();
                                }
                            } else {
                                alert('Delete failed: ' + (resp.message || 'Unknown error'));
                                if (card) card.style.opacity = '1';
                            }
                        })
                        .catch(function() {
                            alert('Network error while deleting.');
                            if (card) card.style.opacity = '1';
                        });
                });
            });
        }
    }

    function buildPageButton(label, page, isActive, isDisabled) {
        return '<button type="button" class="btn btn-sm ' + (isActive ? 'btn-primary' : 'btn-secondary') + (isDisabled ? ' disabled' : '') + '" data-page="' + page + '">' + label + '</button>';
    }

    function renderPagination(p) {
        if (!p || p.totalPages <= 1) { leadPagination.innerHTML = ''; return; }
        var buttons = [];
        buttons.push(buildPageButton('<i class="bi bi-chevron-left"></i>', p.page - 1, false, p.page <= 1));
        var start = Math.max(1, p.page - 2);
        var end = Math.min(p.totalPages, p.page + 2);
        if (start > 1) {
            buttons.push(buildPageButton('1', 1, p.page === 1));
            if (start > 2) buttons.push('<span class="btn btn-sm btn-secondary disabled">…</span>');
        }
        for (var i = start; i <= end; i++) buttons.push(buildPageButton(String(i), i, i === p.page));
        if (end < p.totalPages) {
            if (end < p.totalPages - 1) buttons.push('<span class="btn btn-sm btn-secondary disabled">…</span>');
            buttons.push(buildPageButton(String(p.totalPages), p.totalPages, p.page === p.totalPages));
        }
        buttons.push(buildPageButton('<i class="bi bi-chevron-right"></i>', p.page + 1, false, p.page >= p.totalPages));
        leadPagination.innerHTML = buttons.join('');
    }

    function updateSummary(p) {
        totalLeadsCount.textContent = new Intl.NumberFormat().format(p.totalRecords || 0);
        cardsSummary.textContent = p.totalRecords
            ? 'Showing ' + p.from + ' to ' + p.to + ' of ' + p.totalRecords + ' leads'
            : 'No leads found for the selected filters';
    }

    function fetchCards() {
        cardsSummary.textContent = 'Loading leads…';
        var body = new URLSearchParams({
            csrf_token: csrfToken,
            page: String(state.page), perPage: String(state.perPage),
            search: state.globalSearch, quickStatus: state.quickStatus,
            loginMonth: state.loginMonth, followupMonth: state.followupMonth,
            quickLoginMode: state.quickLoginMode, quickAssigned: state.quickAssigned,
            quickLoanType: state.quickLoanType, quickRework: state.quickRework
        });
        fetch(dataUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body })
            .then(function(r) { return r.json(); })
            .then(function(payload) {
                if (!payload || payload.ok === false) throw new Error(payload && payload.message ? payload.message : 'Unable to load leads.');
                renderCards(payload.cards || []);
                renderPagination(payload.pagination || {});
                updateSummary(payload.pagination || {});
                persistState();
            })
            .catch(function(error) {
                leadCards.innerHTML = '<div class="col-12"><div class="empty-state p-4 text-center text-danger"><i class="bi bi-exclamation-circle fs-3 mb-2 d-block"></i>' + escapeHtml(error.message || 'Unable to load leads.') + '</div></div>';
                leadPagination.innerHTML = '';
                cardsSummary.textContent = 'Unable to load leads.';
            });
    }

    function queueFetch(resetPage) {
        if (resetPage) state.page = 1;
        clearTimeout(fetchTimer);
        fetchTimer = setTimeout(fetchCards, 220);
    }

    syncInputsFromState();
    fetchCards();

    searchInput.addEventListener('input', function() { state.globalSearch = searchInput.value.trim(); queueFetch(true); });
    perPageSelect.addEventListener('change', function() { state.perPage = Number(perPageSelect.value) || 12; queueFetch(true); });
    filterIds.forEach(function(id) {
        var el = document.getElementById(id);
        el.addEventListener('change', function() {
            var key = id.replace('Filter', '');
            state[key] = el.value;
            queueFetch(true);
        });
    });
    document.getElementById('resetFilters').addEventListener('click', function() {
        state = Object.assign({}, defaultState);
        syncInputsFromState();
        fetchCards();
        persistState();
    });
    leadPagination.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-page]');
        if (!btn || btn.classList.contains('disabled')) return;
        var np = Number(btn.getAttribute('data-page'));
        if (!np || np === state.page) return;
        state.page = np;
        fetchCards();
    });
});
</script>
