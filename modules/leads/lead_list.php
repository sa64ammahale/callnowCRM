<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/lead_common.php';

$allowedRoles = ['Admin', 'Manager', 'Supervisor', 'Officer'];
if (!in_array(USER_ROLE, $allowedRoles, true)) {
    header('Location: leads_dashboard.php');
    exit;
}

ensureLeadModuleSchema($link);
ensureLeadFilterPreferenceSchema($link);
mysqli_set_charset($link, 'utf8mb4');

$prefillSearch = trim((string)($_GET['q'] ?? ''));
$defaultPipelineStatuses = ['LEAD', 'LOGIN', 'UNDERWRTING', 'FOLLOWUP', 'SANCTIONED'];
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
    "SELECT COUNT(*) FROM LEADS_TABLE l {$baseWhere}"
))[0];

$loginMonths = [];
$followupMonths = [];
$assignedOptions = [];
$loanTypeOptionsAvailable = [];

$loginMonthResult = mysqli_query(
    $link,
    "SELECT DISTINCT COALESCE(DATE_FORMAT(l.login_date, '%Y-%m'), DATE_FORMAT(l.next_followup_at, '%Y-%m'), DATE_FORMAT(l.created_at, '%Y-%m')) AS month_key
     FROM LEADS_TABLE l
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
     FROM LEADS_TABLE l
     {$baseWhere}
     HAVING month_key IS NOT NULL AND month_key <> ''
     ORDER BY month_key DESC"
);
while ($followupMonthResult && ($row = mysqli_fetch_assoc($followupMonthResult))) {
    $monthKey = (string)$row['month_key'];
    $followupMonths[$monthKey] = date('M Y', strtotime($monthKey . '-01'));
}

$assignedResult = mysqli_query(
    $link,
    "SELECT DISTINCT COALESCE(u.NAME, 'Unassigned') AS assigned_name
     FROM LEADS_TABLE l
     LEFT JOIN USERS u ON u.ID = l.assigned_to
     {$baseWhere}
     ORDER BY assigned_name"
);
while ($assignedResult && ($row = mysqli_fetch_assoc($assignedResult))) {
    $assignedOptions[(string)$row['assigned_name']] = (string)$row['assigned_name'];
}

$loanTypeResult = mysqli_query(
    $link,
    "SELECT DISTINCT l.loan_type
     FROM LEADS_TABLE l
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
?>
<?php $pageTitle = 'Leads - CallNow'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<div class="container-fluid page-shell px-3 px-lg-4">
    <section class="filter-shell">
        <div class="search-bar">
            <div class="search-input-wrap">
                <i class="bi bi-search"></i>
                <input id="universalSearch" class="search-input" type="text" placeholder="Search customer, mobile, company, app id, loan type, bank, DSA">
            </div>
            <div class="top-stat">Visible Leads <b id="totalLeadsCount"><?= number_format($totalRecords) ?></b></div>
            <select id="perPageSelect" class="form-select form-select-sm" style="min-height:50px;border-radius:999px;min-width:130px;">
                <option value="12">12 / page</option>
                <option value="24">24 / page</option>
                <option value="48">48 / page</option>
            </select>
            <button type="button" id="resetFilters" class="action-btn action-dark">
                <i class="bi bi-arrow-counterclockwise"></i> Reset
            </button>
        </div>

        <div class="filters-grid">
            <div class="filter-card">
                <label class="filter-label" for="quickStatusFilter">Status View</label>
                <select id="quickStatusFilter" class="form-select form-select-sm">
                    <option value="PIPELINE">Pipeline Cases</option>
                    <option value="">All Status</option>
                    <?php foreach (leadStatusOptions() as $status): ?>
                        <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-card">
                <label class="filter-label" for="loginMonthFilter">Login Month</label>
                <select id="loginMonthFilter" class="form-select form-select-sm">
                    <option value="current_previous">Current + Previous Month</option>
                    <option value="">All Months</option>
                    <option value="current">Current Month</option>
                    <option value="previous">Previous Month</option>
                    <?php foreach ($loginMonths as $monthValue => $monthLabel): ?>
                        <option value="<?= htmlspecialchars($monthValue) ?>"><?= htmlspecialchars($monthLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-card">
                <label class="filter-label" for="followupMonthFilter">Follow-up Month</label>
                <select id="followupMonthFilter" class="form-select form-select-sm">
                    <option value="">All Months</option>
                    <option value="current">Current Month</option>
                    <option value="previous">Previous Month</option>
                    <option value="current_previous">Current + Previous Month</option>
                    <?php foreach ($followupMonths as $monthValue => $monthLabel): ?>
                        <option value="<?= htmlspecialchars($monthValue) ?>"><?= htmlspecialchars($monthLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-card">
                <label class="filter-label" for="quickLoginModeFilter">Login Mode</label>
                <select id="quickLoginModeFilter" class="form-select form-select-sm">
                    <option value="">All Login Modes</option>
                    <?php foreach (loginModeOptions() as $mode): ?>
                        <option value="<?= htmlspecialchars($mode) ?>"><?= htmlspecialchars($mode) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-card">
                <label class="filter-label" for="quickAssignedFilter">Assigned To</label>
                <select id="quickAssignedFilter" class="form-select form-select-sm">
                    <option value="">All Assignees</option>
                    <?php foreach ($assignedOptions as $assignedLabel): ?>
                        <option value="<?= htmlspecialchars($assignedLabel) ?>"><?= htmlspecialchars($assignedLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-card">
                <label class="filter-label" for="quickLoanTypeFilter">Loan Type</label>
                <select id="quickLoanTypeFilter" class="form-select form-select-sm">
                    <option value="">All Loan Types</option>
                    <?php foreach ($loanTypeOptionsAvailable as $loanType): ?>
                        <option value="<?= htmlspecialchars($loanType) ?>"><?= htmlspecialchars($loanType) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </section>

    <section class="cards-shell">
        <div class="cards-toolbar">
            <div class="cards-summary" id="cardsSummary">Loading leads...</div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="lead_insert.php" class="action-btn action-primary">
                    <i class="bi bi-plus-circle"></i> Add Lead
                </a>
                <a href="lead_pipeline.php" class="action-btn action-dark">
                    <i class="bi bi-kanban"></i> Pipeline
                </a>
            </div>
        </div>
        <div id="leadCards" class="cards-grid"></div>
        <div id="leadPagination" class="pagination-shell"></div>
    </section>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const saveUrl = <?= json_encode(APP_BASE . '/modules/leads/php_scripts/lead_list_filter_state.php') ?>;
    const dataUrl = <?= json_encode(APP_BASE . '/modules/leads/php_scripts/lead_cards_data.php') ?>;
    const localStorageKey = 'lead_list_filters_user_<?= (int)USER_ID ?>';
    const serverState = <?= json_encode($savedFilterState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const totalLeadsCount = document.getElementById('totalLeadsCount');
    const cardsSummary = document.getElementById('cardsSummary');
    const leadCards = document.getElementById('leadCards');
    const leadPagination = document.getElementById('leadPagination');
    const searchInput = document.getElementById('universalSearch');
    const perPageSelect = document.getElementById('perPageSelect');
    const filterIds = [
        'quickStatusFilter',
        'loginMonthFilter',
        'followupMonthFilter',
        'quickLoginModeFilter',
        'quickAssignedFilter',
        'quickLoanTypeFilter'
    ];

    const defaultState = {
        quickStatus: 'PIPELINE',
        loginMonth: 'current_previous',
        followupMonth: '',
        quickLoginMode: '',
        quickAssigned: '',
        quickLoanType: '',
        globalSearch: '',
        perPage: 12,
        page: 1,
        columnFilters: {}
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
        if (serverState && Object.keys(serverState).length > 0) {
            return serverState;
        }
        try {
            const local = JSON.parse(localStorage.getItem(localStorageKey) || 'null');
            return local && typeof local === 'object' ? local : defaultState;
        } catch (error) {
            return defaultState;
        }
    }

    function cacheState() {
        localStorage.setItem(localStorageKey, JSON.stringify(state));
    }

    function persistState() {
        cacheState();
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            fetch(saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(state)
            }).catch(() => {});
        }, 250);
    }

    function syncInputsFromState() {
        searchInput.value = state.globalSearch || '';
        perPageSelect.value = String(state.perPage);
        document.getElementById('quickStatusFilter').value = state.quickStatus;
        document.getElementById('loginMonthFilter').value = state.loginMonth;
        document.getElementById('followupMonthFilter').value = state.followupMonth;
        document.getElementById('quickLoginModeFilter').value = state.quickLoginMode;
        document.getElementById('quickAssignedFilter').value = state.quickAssigned;
        document.getElementById('quickLoanTypeFilter').value = state.quickLoanType;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderFieldGroup(title, fields) {
        if (!fields || !fields.length) {
            return '';
        }
        const rows = fields.map((field) => `
            <div class="field-row">
                <b>${escapeHtml(field.label)}:</b>
                <span>${escapeHtml(field.value)}</span>
            </div>
        `).join('');
        return `
            <section class="card-section">
                <div class="section-kicker">${escapeHtml(title)}</div>
                <div class="field-list">${rows}</div>
            </section>
        `;
    }

    function renderNotes(notes) {
        if (!notes || !notes.length) {
            return '';
        }
        return `
            <section class="card-section">
                <div class="section-kicker">Notes</div>
                <div class="note-chip-wrap">
                    ${notes.map((note) => `
                        <span class="note-chip">
                            <strong>${escapeHtml(note.label)}:</strong>
                            <span>${escapeHtml(note.value)}</span>
                        </span>
                    `).join('')}
                </div>
            </section>
        `;
    }

    function renderCards(cards) {
        if (!cards.length) {
            leadCards.innerHTML = `
                <div class="empty-state" style="width:100%;">
                    <i class="bi bi-search-heart"></i>
                    No leads match your current search and filters.
                </div>
            `;
            return;
        }

        leadCards.innerHTML = cards.map((card) => `
            <article class="lead-card">
                <div class="lead-head">
                    <div class="lead-head-top">
                        <div>
                            <h2 class="lead-title">${escapeHtml(card.customer_name)}</h2>
                            ${(card.identity || []).find((field) => field.label === 'Company')?.value ? `
                                <div class="lead-company">${escapeHtml((card.identity || []).find((field) => field.label === 'Company').value)}</div>
                            ` : ''}
                        </div>
                        <span class="lead-status ${escapeHtml(card.status_badge)}">
                            <i class="bi ${escapeHtml(card.status_icon)}"></i>
                            ${escapeHtml(card.status_label)}
                        </span>
                    </div>
                    ${card.mobile ? `
                        <a href="tel:${escapeHtml(card.mobile)}" class="lead-mobile">
                            <i class="bi bi-telephone-fill"></i> ${escapeHtml(card.mobile)}
                        </a>
                    ` : ''}
                    <span class="lead-id">Lead #${escapeHtml(card.lead_id)}</span>
                </div>
                ${renderFieldGroup('Timeline', card.timeline)}
                ${renderFieldGroup('Loan Snapshot', [
                    ...(card.identity || []).filter((field) => field.label !== 'Company'),
                    ...(card.source || []).filter((field) => field.label === 'Promo Code' || field.label === 'Login Mode')
                ])}
                ${renderFieldGroup('Financials', card.financial)}
                ${renderFieldGroup('Banking', card.banking)}
                <div>
                    ${renderFieldGroup('Owner & Source', [
                        ...(card.source || []).filter((field) => field.label !== 'Promo Code' && field.label !== 'Login Mode'),
                        ...(card.notes || [])
                    ])}
                    <a href="lead_view.php?id=${encodeURIComponent(card.lead_id)}" class="manage-btn">
                        <i class="bi bi-pencil-square"></i> Manage Lead
                    </a>
                </div>
            </article>
        `).join('');
    }

    function buildPageButton(label, page, isActive = false, isDisabled = false) {
        const activeClass = isActive ? ' active' : '';
        const disabledClass = isDisabled ? ' disabled' : '';
        return `<button type="button" class="page-btn${activeClass}${disabledClass}" data-page="${page}">${label}</button>`;
    }

    function renderPagination(pagination) {
        if (!pagination || pagination.totalPages <= 1) {
            leadPagination.innerHTML = '';
            return;
        }

        const buttons = [];
        buttons.push(buildPageButton('<i class="bi bi-chevron-left"></i>', pagination.page - 1, false, pagination.page <= 1));

        const startPage = Math.max(1, pagination.page - 2);
        const endPage = Math.min(pagination.totalPages, pagination.page + 2);

        if (startPage > 1) {
            buttons.push(buildPageButton('1', 1, pagination.page === 1));
            if (startPage > 2) {
                buttons.push('<span class="page-btn disabled">...</span>');
            }
        }

        for (let p = startPage; p <= endPage; p++) {
            buttons.push(buildPageButton(String(p), p, p === pagination.page));
        }

        if (endPage < pagination.totalPages) {
            if (endPage < pagination.totalPages - 1) {
                buttons.push('<span class="page-btn disabled">...</span>');
            }
            buttons.push(buildPageButton(String(pagination.totalPages), pagination.totalPages, pagination.page === pagination.totalPages));
        }

        buttons.push(buildPageButton('<i class="bi bi-chevron-right"></i>', pagination.page + 1, false, pagination.page >= pagination.totalPages));
        leadPagination.innerHTML = buttons.join('');
    }

    function updateSummary(pagination) {
        totalLeadsCount.textContent = new Intl.NumberFormat().format(pagination.totalRecords || 0);
        cardsSummary.textContent = pagination.totalRecords
            ? `Showing ${pagination.from} to ${pagination.to} of ${pagination.totalRecords} leads`
            : 'No leads found for the selected filters';
    }

    function fetchCards() {
        cardsSummary.textContent = 'Loading leads...';
        const body = new URLSearchParams({
            page: String(state.page),
            perPage: String(state.perPage),
            search: state.globalSearch,
            quickStatus: state.quickStatus,
            loginMonth: state.loginMonth,
            followupMonth: state.followupMonth,
            quickLoginMode: state.quickLoginMode,
            quickAssigned: state.quickAssigned,
            quickLoanType: state.quickLoanType
        });

        fetch(dataUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body
        })
            .then((response) => response.json())
            .then((payload) => {
                if (!payload || payload.ok === false) {
                    throw new Error(payload && payload.message ? payload.message : 'Unable to load leads.');
                }
                renderCards(payload.cards || []);
                renderPagination(payload.pagination || {});
                updateSummary(payload.pagination || {});
                persistState();
            })
            .catch((error) => {
                leadCards.innerHTML = `
                    <div class="empty-state" style="grid-column:1 / -1;">
                        <i class="bi bi-exclamation-circle"></i>
                        ${escapeHtml(error.message || 'Unable to load leads right now.')}
                    </div>
                `;
                leadPagination.innerHTML = '';
                cardsSummary.textContent = 'Unable to load leads.';
            });
    }

    function queueFetch(resetPage = false) {
        if (resetPage) {
            state.page = 1;
        }
        clearTimeout(fetchTimer);
        fetchTimer = setTimeout(fetchCards, 220);
    }

    syncInputsFromState();
    fetchCards();

    searchInput.addEventListener('input', () => {
        state.globalSearch = searchInput.value.trim();
        queueFetch(true);
    });

    perPageSelect.addEventListener('change', () => {
        state.perPage = Number(perPageSelect.value) || 12;
        queueFetch(true);
    });

    filterIds.forEach((id) => {
        const element = document.getElementById(id);
        element.addEventListener('change', () => {
            if (id === 'quickStatusFilter') state.quickStatus = element.value;
            if (id === 'loginMonthFilter') state.loginMonth = element.value;
            if (id === 'followupMonthFilter') state.followupMonth = element.value;
            if (id === 'quickLoginModeFilter') state.quickLoginMode = element.value;
            if (id === 'quickAssignedFilter') state.quickAssigned = element.value;
            if (id === 'quickLoanTypeFilter') state.quickLoanType = element.value;
            queueFetch(true);
        });
    });

    document.getElementById('resetFilters').addEventListener('click', () => {
        state = { ...defaultState };
        syncInputsFromState();
        fetchCards();
        persistState();
    });

    leadPagination.addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');
        if (!button || button.classList.contains('disabled')) {
            return;
        }
        const nextPage = Number(button.getAttribute('data-page'));
        if (!nextPage || nextPage === state.page) {
            return;
        }
        state.page = nextPage;
        fetchCards();
    });
});
</script>
