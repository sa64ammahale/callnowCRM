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
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Leads - CallNow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/app-theme.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --lead-bg: #eef4ff;
            --lead-surface: rgba(255, 255, 255, 0.94);
            --lead-surface-strong: #ffffff;
            --lead-border: #dce8f7;
            --lead-muted: #64748b;
            --lead-ink: #0f172a;
            --lead-ink-soft: #334155;
            --lead-primary: #155eef;
            --lead-primary-deep: #123fae;
            --lead-cyan: #06b6d4;
            --lead-soft: #f8fbff;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: 'Manrope', sans-serif;
            color: var(--lead-ink);
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.20), transparent 24%),
                radial-gradient(circle at top right, rgba(34, 211, 238, 0.14), transparent 18%),
                linear-gradient(180deg, #f9fbff 0%, var(--lead-bg) 100%);
            min-height: 100vh;
        }
        .page-shell {
            padding-top: 1rem;
            padding-bottom: 1.6rem;
        }
        .filter-shell,
        .cards-shell {
            background: var(--lead-surface);
            border: 1px solid rgba(255, 255, 255, 0.88);
            border-radius: 1.35rem;
            box-shadow: 0 20px 48px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(14px);
        }
        .filter-shell {
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .search-bar {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) auto auto auto;
            gap: 0.75rem;
            align-items: center;
            margin-bottom: 0.9rem;
        }
        .search-input-wrap {
            position: relative;
        }
        .search-input-wrap i {
            position: absolute;
            left: 0.95rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--lead-muted);
            font-size: 0.9rem;
        }
        .search-input {
            width: 100%;
            min-height: 50px;
            padding: 0.8rem 1rem 0.8rem 2.8rem;
            border-radius: 999px;
            border: 1px solid var(--lead-border);
            background: rgba(255,255,255,0.95);
            font-size: 0.88rem;
            color: var(--lead-ink);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.72);
        }
        .search-input:focus {
            outline: none;
            border-color: #90baff;
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.12);
        }
        .top-stat {
            min-height: 50px;
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.7rem 1rem;
            border-radius: 999px;
            border: 1px solid var(--lead-border);
            background: linear-gradient(180deg, #ffffff, #f7fbff);
            color: var(--lead-ink-soft);
            font-size: 0.8rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .top-stat b {
            color: var(--lead-primary-deep);
            font-size: 0.9rem;
        }
        .action-btn {
            min-height: 50px;
            border: none;
            border-radius: 999px;
            padding: 0.78rem 1.1rem;
            font-size: 0.8rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            text-decoration: none;
            transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
        }
        .action-btn:hover {
            transform: translateY(-1px);
            opacity: 0.96;
        }
        .action-primary {
            color: #fff;
            background: linear-gradient(135deg, var(--lead-primary), var(--lead-cyan));
            box-shadow: 0 12px 26px rgba(21, 94, 239, 0.24);
        }
        .action-dark {
            color: var(--lead-ink);
            background: linear-gradient(180deg, #ffffff, #eef5ff);
            border: 1px solid var(--lead-border);
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.08);
        }
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 0.75rem;
        }
        .filter-card {
            padding: 0.82rem 0.86rem;
            border-radius: 1rem;
            border: 1px solid var(--lead-border);
            background: linear-gradient(180deg, #ffffff, #f8fbff);
        }
        .filter-label {
            display: block;
            margin-bottom: 0.42rem;
            color: var(--lead-muted);
            font-size: 0.69rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .form-select-sm {
            min-height: 44px;
            border-radius: 0.9rem;
            border-color: var(--lead-border);
            font-size: 0.8rem;
        }
        .form-select-sm:focus {
            border-color: #90baff;
            box-shadow: 0 0 0 0.18rem rgba(59, 130, 246, 0.12);
        }
        .cards-shell {
            padding: 1rem;
        }
        .cards-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .cards-summary {
            color: var(--lead-muted);
            font-size: 0.82rem;
            font-weight: 700;
        }
        .cards-grid {
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
        }
        .lead-card {
            width: 100%;
            background: linear-gradient(180deg, rgba(255,255,255,0.99), #f7fbff);
            border: 1px solid var(--lead-border);
            border-radius: 1.2rem;
            padding: 1rem 1.05rem;
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.06);
            display: grid;
            grid-template-columns: minmax(220px, 1.25fr) repeat(4, minmax(135px, 0.92fr)) minmax(200px, 1.1fr);
            gap: 0.9rem;
            align-items: start;
        }
        .lead-head {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.6rem;
            min-width: 0;
        }
        .lead-head-top {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.7rem;
        }
        .lead-title {
            margin: 0;
            font-family: 'Sora', 'Manrope', sans-serif;
            font-size: 1rem;
            line-height: 1.24;
            font-weight: 800;
            color: #0f172a;
        }
        .lead-company {
            margin-top: 0.18rem;
            color: var(--lead-ink-soft);
            font-size: 0.82rem;
            font-weight: 700;
            line-height: 1.35;
        }
        .lead-mobile {
            display: inline-flex;
            align-items: center;
            gap: 0.38rem;
            color: #0f766e;
            font-weight: 800;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .lead-status {
            display: inline-flex;
            align-items: center;
            gap: 0.38rem;
            padding: 0.48rem 0.8rem;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .card-section {
            min-width: 0;
            border-left: 1px solid #e5eef9;
            padding-left: 0.85rem;
        }
        .section-kicker {
            color: var(--lead-primary-deep);
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-bottom: 0.48rem;
        }
        .field-list {
            display: flex;
            flex-direction: column;
            gap: 0.38rem;
        }
        .field-row {
            display: flex;
            flex-direction: column;
            gap: 0.08rem;
            font-size: 0.77rem;
            line-height: 1.33;
            min-width: 0;
        }
        .field-row b {
            color: var(--lead-ink);
            font-weight: 800;
            font-size: 0.7rem;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .field-row span {
            color: var(--lead-ink-soft);
            font-weight: 700;
            overflow-wrap: anywhere;
        }
        .note-chip-wrap {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .note-chip {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.14rem;
            padding: 0.5rem 0.62rem;
            border-radius: 0.85rem;
            background: #fff;
            border: 1px solid var(--lead-border);
            color: var(--lead-ink-soft);
            font-size: 0.74rem;
            overflow-wrap: anywhere;
        }
        .note-chip strong {
            font-size: 0.68rem;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            color: var(--lead-ink);
        }
        .lead-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.7rem;
            margin-top: 0.75rem;
        }
        .lead-id {
            color: var(--lead-muted);
            font-size: 0.72rem;
            font-weight: 700;
        }
        .manage-btn {
            border: none;
            border-radius: 999px;
            padding: 0.74rem 0.95rem;
            background: linear-gradient(135deg, #101828, #334155);
            color: #fff;
            font-size: 0.77rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 0.42rem;
            text-decoration: none;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.18);
        }
        .empty-state {
            padding: 2.5rem 1rem;
            text-align: center;
            color: var(--lead-muted);
        }
        .empty-state i {
            font-size: 2rem;
            color: var(--lead-primary);
            display: block;
            margin-bottom: 0.6rem;
        }
        .pagination-shell {
            display: flex;
            justify-content: center;
            gap: 0.45rem;
            flex-wrap: wrap;
            margin-top: 1.1rem;
        }
        .page-btn {
            min-width: 42px;
            height: 42px;
            border-radius: 999px;
            border: 1px solid var(--lead-border);
            background: #fff;
            color: var(--lead-ink-soft);
            font-size: 0.78rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.16s ease, background 0.16s ease;
        }
        .page-btn:hover {
            transform: translateY(-1px);
        }
        .page-btn.active {
            background: linear-gradient(135deg, var(--lead-primary), var(--lead-cyan));
            color: #fff;
            border-color: transparent;
            box-shadow: 0 12px 24px rgba(21, 94, 239, 0.2);
        }
        .page-btn.disabled {
            opacity: 0.45;
            pointer-events: none;
        }
        @media (max-width: 1199px) {
            .search-bar,
            .filters-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .search-bar > :first-child {
                grid-column: 1 / -1;
            }
            .lead-card {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .lead-head {
                grid-column: 1 / -1;
            }
            .card-section {
                border-left: none;
                border-top: 1px solid #e5eef9;
                padding-left: 0;
                padding-top: 0.75rem;
            }
        }
        @media (max-width: 767px) {
            .search-bar,
            .filters-grid {
                grid-template-columns: 1fr;
            }
            .lead-card {
                grid-template-columns: 1fr;
                padding: 0.95rem;
            }
            .lead-head-top,
            .cards-toolbar {
                flex-direction: column;
                align-items: flex-start;
            }
            .card-section {
                border-left: none;
                border-top: 1px solid #e5eef9;
                padding-left: 0;
                padding-top: 0.75rem;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../../php_scripts/header.php'; ?>

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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
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
</body>
</html>
