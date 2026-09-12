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
<?php $pageTitle = 'Leads - CallNow'; include __DIR__ . '/../../php_scripts/header.php';
$canDelete = isAdmin() || isManager() || isSupervisor() || isSuperAdmin();
?>

<div class="ll-wrap">
    <!-- Header -->
    <div class="ll-header">
        <div class="ll-header-left">
            <div class="ll-header-icon"><i class="bi bi-grid-3x3-gap-fill"></i></div>
            <div>
                <h1 class="ll-title">Lead Register</h1>
                <p class="ll-sub"><?= number_format($totalRecords) ?> total leads in your scope</p>
            </div>
        </div>
        <div class="ll-header-right">
            <a href="<?= url('modules/leads/lead_insert.php') ?>" class="btn btn-accent-solid btn-sm"><i class="bi bi-plus-circle me-1"></i> Add Lead</a>
            <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="btn btn-outline-accent btn-sm"><i class="bi bi-kanban me-1"></i> Pipeline</a>
            <a href="<?= url('modules/leads/leads_dashboard.php') ?>" class="btn btn-outline-accent btn-sm"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a>
        </div>
    </div>

    <!-- Search + Filters -->
    <div class="ll-toolbar">
        <div class="ll-search">
            <i class="bi bi-search"></i>
            <input id="universalSearch" type="text" placeholder="Search by name, mobile, company, loan type, bank, app no…" value="<?= htmlspecialchars($prefillSearch) ?>">
        </div>
        <div class="ll-toolbar-right">
            <span class="ll-result-count">Visible: <b id="totalLeadsCount"><?= number_format($totalRecords) ?></b></span>
            <select id="perPageSelect" class="ll-perpage">
                <option value="12">12/page</option>
                <option value="24">24/page</option>
                <option value="48">48/page</option>
            </select>
            <button type="button" id="resetFilters" class="ll-reset-btn" title="Reset filters"><i class="bi bi-arrow-counterclockwise"></i></button>
        </div>
    </div>

    <div class="ll-filters">
        <div class="ll-filter-group">
            <label><i class="bi bi-diagram-3"></i> Status</label>
            <select id="quickStatusFilter" class="ll-select">
                <option value="PIPELINE">Pipeline Cases</option>
                <option value="">All Status</option>
                <?php foreach (leadStatusOptions() as $status): ?>
                    <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ll-filter-group">
            <label><i class="bi bi-calendar2-check"></i> Login</label>
            <select id="loginMonthFilter" class="ll-select">
                <option value="current_previous">Curr + Prev Month</option>
                <option value="">All</option>
                <option value="current">Current</option>
                <option value="previous">Previous</option>
                <?php foreach ($loginMonths as $mv => $ml): ?>
                    <option value="<?= htmlspecialchars($mv) ?>"><?= htmlspecialchars($ml) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ll-filter-group">
            <label><i class="bi bi-alarm"></i> Follow-up</label>
            <select id="followupMonthFilter" class="ll-select">
                <option value="">All</option>
                <option value="current">Current</option>
                <option value="previous">Previous</option>
                <option value="current_previous">Curr + Prev</option>
                <?php foreach ($followupMonths as $mv => $ml): ?>
                    <option value="<?= htmlspecialchars($mv) ?>"><?= htmlspecialchars($ml) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ll-filter-group">
            <label><i class="bi bi-send-check"></i> Mode</label>
            <select id="quickLoginModeFilter" class="ll-select">
                <option value="">All Modes</option>
                <?php foreach (loginModeOptions() as $mode): ?>
                    <option value="<?= htmlspecialchars($mode) ?>"><?= htmlspecialchars($mode) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ll-filter-group">
            <label><i class="bi bi-person-badge"></i> Assigned</label>
            <select id="quickAssignedFilter" class="ll-select">
                <option value="">All</option>
                <?php foreach ($assignedOptions as $al): ?>
                    <option value="<?= htmlspecialchars($al) ?>"><?= htmlspecialchars($al) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ll-filter-group">
            <label><i class="bi bi-briefcase"></i> Loan</label>
            <select id="quickLoanTypeFilter" class="ll-select">
                <option value="">All Types</option>
                <?php foreach ($loanTypeOptionsAvailable as $lt): ?>
                    <option value="<?= htmlspecialchars($lt) ?>"><?= htmlspecialchars($lt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ll-filter-group">
            <label><i class="bi bi-exclamation-triangle"></i> Rework</label>
            <select id="quickReworkFilter" class="ll-select">
                <option value="">All</option>
                <option value="YES">Any Rework</option>
                <option value="INTERNAL">Internal Rework</option>
                <option value="BANK">Bank Rework</option>
            </select>
        </div>
    </div>

    <!-- Summary bar -->
    <div class="ll-summary">
        <span id="cardsSummary">Loading leads…</span>
    </div>

    <!-- Cards grid -->
    <div id="leadCards" class="ll-grid"></div>

    <!-- Pagination -->
    <div id="leadPagination" class="ll-pagination"></div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

<style>
/* ── Layout ── */
.ll-wrap {
    max-width: 1400px; margin: 0 auto;
    padding: 1.5rem 1.5rem 2.5rem;
    display: flex; flex-direction: column; gap: 1.25rem;
}

/* ── Header ── */
.ll-header {
    display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;
}
.ll-header-left { display: flex; align-items: center; gap: 0.875rem; }
.ll-header-icon {
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; flex-shrink: 0;
    box-shadow: 0 3px 12px rgba(99,102,241,0.3);
}
.ll-title {
    font-size: 1.5rem; font-weight: 800; margin: 0 0 0.125rem;
    color: #1e1b4b; letter-spacing: -0.03em;
}
.ll-sub { font-size: 0.8125rem; color: #7c7caa; margin: 0; font-weight: 500; }
.ll-header-right { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.btn-accent-solid {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff; border: none; font-weight: 600;
    border-radius: 10px; padding: 0.4375rem 1rem; font-size: 0.8125rem;
    transition: all 0.15s ease; text-decoration: none;
    box-shadow: 0 2px 8px rgba(99,102,241,0.2);
}
.btn-accent-solid:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(99,102,241,0.3); color: #fff; }
.btn-outline-accent {
    border: 1px solid #d4d6f0; color: #4f46b5; font-weight: 600;
    border-radius: 10px; padding: 0.375rem 0.875rem; font-size: 0.8125rem;
    transition: all 0.15s ease; text-decoration: none;
}
.btn-outline-accent:hover { border-color: #6366f1; color: #4338ca; background: #eef1ff; }

/* ── Search + Toolbar ── */
.ll-toolbar {
    display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;
    background: linear-gradient(135deg, #f8f9ff, #f0f2ff);
    border: 1px solid #e0e4f0; border-radius: 16px;
    padding: 0.75rem 1.125rem;
}
.ll-search {
    display: flex; align-items: center; gap: 0.5rem;
    flex: 1; min-width: 200px; max-width: 480px;
    padding: 0.5rem 0.75rem;
    background: #fff; border: 1px solid #d4d6f0; border-radius: 10px;
    transition: all 0.2s ease;
}
.ll-search:focus-within { border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99,102,241,0.1); }
.ll-search i { color: #9498c0; font-size: 0.875rem; flex-shrink: 0; }
.ll-search input {
    flex: 1; border: none; background: transparent; padding: 0.125rem 0;
    font-size: 0.8125rem; color: #1e1b4b; outline: none; min-width: 0;
}
.ll-search input::placeholder { color: #9498c0; }
.ll-toolbar-right { display: flex; align-items: center; gap: 0.75rem; flex-shrink: 0; }
.ll-result-count { font-size: 0.75rem; color: #7c7caa; white-space: nowrap; font-weight: 500; }
.ll-result-count b { color: #4f46b5; font-weight: 700; }
.ll-perpage {
    padding: 0.375rem 0.625rem;
    border: 1px solid #d4d6f0; border-radius: 8px;
    background: #fff; color: #4f46b5;
    font-size: 0.75rem; font-weight: 500; cursor: pointer; outline: none;
}
.ll-perpage:focus { border-color: #6366f1; }
.ll-reset-btn {
    width: 34px; height: 34px; border-radius: 8px;
    border: 1px solid #d4d6f0; background: #fff;
    color: #9498c0; display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.12s ease; font-size: 0.875rem;
}
.ll-reset-btn:hover { background: #fef2f2; color: #ef4444; border-color: #fecaca; }

/* ── Filters ── */
.ll-filters {
    display: flex; flex-wrap: wrap; gap: 0.5rem;
    background: linear-gradient(135deg, #f8f9ff, #f0f2ff);
    border: 1px solid #e0e4f0; border-radius: 16px;
    padding: 0.75rem 1rem;
}
.ll-filter-group {
    display: flex; flex-direction: column; gap: 0.1875rem; min-width: 130px; flex: 1;
}
.ll-filter-group label {
    font-size: 0.5625rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.05em; color: #7c7caa;
    display: flex; align-items: center; gap: 0.25rem;
}
.ll-filter-group label i { font-size: 0.5625rem; color: #6366f1; }
.ll-select {
    padding: 0.5rem 0.5rem;
    border: 1px solid #d4d6f0; border-radius: 8px;
    background: #fff; color: #1e1b4b;
    font-size: 0.75rem; font-weight: 500; cursor: pointer; outline: none;
    transition: all 0.12s ease; min-height: 34px;
}
.ll-select:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
.ll-select option { font-size: 0.75rem; }

/* ── Summary ── */
.ll-summary { font-size: 0.75rem; color: #7c7caa; padding: 0.125rem 0; font-weight: 500; }

/* ── Cards Grid ── */
.ll-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
}
.ll-card {
    background: #fff;
    border: 1px solid #e8eaf5;
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: all 0.25s ease;
    overflow: hidden; position: relative;
}
.ll-card::before {
    content: ''; position: absolute; top: 0; left: 0; bottom: 0;
    width: 4px; border-radius: 16px 0 0 16px;
    transition: width 0.2s ease;
}
.ll-card.status-LEAD::before { background: linear-gradient(180deg, #6366f1, #818cf8); }
.ll-card.status-FOLLOWUP::before { background: linear-gradient(180deg, #0ea5e9, #38bdf8); }
.ll-card.status-LOGIN::before { background: linear-gradient(180deg, #f59e0b, #fbbf24); }
.ll-card.status-INTERNAL_UNDERWRITING::before { background: linear-gradient(180deg, #6b7280, #9ca3af); }
.ll-card.status-BANK_UNDERWRITING::before { background: linear-gradient(180deg, #7c3aed, #a78bfa); }
.ll-card.status-SANCTIONED::before { background: linear-gradient(180deg, #10b981, #34d399); }
.ll-card.status-DISBURSED::before { background: linear-gradient(180deg, #059669, #10b981); }
.ll-card.status-REJECT::before { background: linear-gradient(180deg, #ef4444, #f87171); }
.ll-card:hover {
    border-color: #d4d6f0;
    box-shadow: 0 8px 28px rgba(99,102,241,0.08);
    transform: translateY(-3px);
}
.ll-card:hover::before { width: 5px; }
.ll-card-main { padding: 1rem 1.125rem; display: flex; flex-direction: column; gap: 0.625rem; }
.ll-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.625rem; }
.ll-card-name {
    font-size: 0.9375rem; font-weight: 700; color: #1e1b4b;
    margin: 0 0 0.125rem; line-height: 1.2;
}
.ll-card-name small { font-weight: 500; color: #9498c0; font-size: 0.6875rem; }
.ll-card-badge {
    display: inline-flex; align-items: center; gap: 0.25rem;
    padding: 0.1875rem 0.625rem; border-radius: 999px;
    font-size: 0.625rem; font-weight: 700; white-space: nowrap; flex-shrink: 0;
    letter-spacing: 0.02em;
}
.ll-badge-lead { background: linear-gradient(135deg, #eef1ff, #dce0f8); color: #6366f1; border: 1px solid #cdd0f0; }
.ll-badge-followup { background: linear-gradient(135deg, #ecfeff, #d5f5f8); color: #0891b2; border: 1px solid #b2e2e8; }
.ll-badge-login { background: linear-gradient(135deg, #fffbeb, #fef3c7); color: #d97706; border: 1px solid #fcd34d; }
.ll-badge-underwriting { background: linear-gradient(135deg, #f3f4f6, #e5e7eb); color: #4b5563; border: 1px solid #d1d5db; }
.ll-badge-sanctioned { background: linear-gradient(135deg, #ecfdf5, #d1fae5); color: #059669; border: 1px solid #a7f3d0; }
.ll-badge-disbursed { background: linear-gradient(135deg, #ecfdf5, #c6f6d5); color: #047857; border: 1px solid #6ee7b7; }
.ll-badge-reject { background: linear-gradient(135deg, #fef2f2, #fee2e2); color: #dc2626; border: 1px solid #fecaca; }
.ll-card-sub { font-size: 0.6875rem; color: #7c7caa; margin: 0; display: flex; align-items: center; gap: 0.375rem; flex-wrap: wrap; font-weight: 500; }
.ll-card-sub i { font-size: 0.625rem; color: #6366f1; }
.ll-card-pills { display: flex; flex-wrap: wrap; gap: 0.3125rem; }
.ll-pill {
    display: inline-flex; align-items: center; gap: 0.25rem;
    padding: 0.1875rem 0.5rem; border-radius: 999px;
    background: #f4f5fb; color: #4f46b5;
    font-size: 0.625rem; font-weight: 600;
    border: 1px solid #dce0f0; transition: all 0.12s ease; white-space: nowrap;
}
a.ll-pill:hover { background: #eef1ff; color: #4338ca; border-color: #bcc0e8; }
.ll-pill.overdue { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
.ll-pill.today { background: #fffbeb; color: #d97706; border-color: #fde68a; }
.ll-pill-rework { background: #f5f3ff; color: #7c3aed; border-color: #ddd6fe; }
.ll-pill-login { background: #ecfdf5; color: #059669; border-color: #a7f3d0; }
.ll-card-footer {
    display: flex; justify-content: space-between; align-items: center;
    gap: 0.5rem; padding-top: 0.625rem; border-top: 1px solid #eceef5;
}
.ll-card-actions { display: flex; gap: 0.5rem; }
.ll-action-btn {
    display: inline-flex; align-items: center; gap: 0.3125rem;
    padding: 0.3125rem 0.75rem; border-radius: 8px;
    font-size: 0.6875rem; font-weight: 600;
    border: 1px solid #dce0f0; background: transparent;
    color: #4f46b5; cursor: pointer;
    transition: all 0.12s ease; text-decoration: none;
}
.ll-action-btn:hover { background: #eef1ff; color: #4338ca; border-color: #bcc0e8; }
.ll-action-btn.primary {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff; border-color: transparent;
    box-shadow: 0 2px 6px rgba(99,102,241,0.25);
}
.ll-action-btn.primary:hover { background: linear-gradient(135deg, #4f46e5, #7c3aed); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,102,241,0.3); }
.delete-btn { color: #9498c0; border-color: transparent; }
.delete-btn:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
.ll-card-extras {
    max-height: 0; overflow: hidden; transition: max-height 0.25s ease;
    background: linear-gradient(180deg, #f8f9ff, #f4f5fb);
    border-top: 1px solid transparent;
}
.ll-card.expanded .ll-card-extras { max-height: 400px; border-top-color: #eceef5; }
.ll-extras-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 0.25rem 0.75rem; padding: 0.75rem 1.125rem;
}
.ll-ex-item { display: flex; flex-direction: column; gap: 0.0625rem; }
.ll-ex-label {
    font-size: 0.5625rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.05em; color: #9498c0;
}
.ll-ex-value { font-size: 0.6875rem; color: #1e1b4b; font-weight: 600; }

/* ── Empty State ── */
.ll-empty {
    grid-column: 1 / -1; text-align: center; padding: 3rem 1rem; color: #7c7caa;
}
.ll-empty i { display: block; font-size: 2.5rem; margin-bottom: 0.75rem; opacity: 0.4; }
.ll-empty p { font-size: 0.875rem; margin: 0; font-weight: 500; }

/* ── Pagination ── */
.ll-pagination {
    display: flex; justify-content: center; align-items: center;
    gap: 0.3125rem; padding: 0.75rem 0;
}
.ll-page-btn {
    min-width: 36px; height: 36px; border-radius: 10px;
    border: 1px solid #dce0f0; background: #fff;
    color: #4f46b5; font-size: 0.75rem; font-weight: 600;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.12s ease; padding: 0 0.625rem;
}
.ll-page-btn:hover { border-color: #bcc0e8; color: #4338ca; background: #eef1ff; }
.ll-page-btn.active {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff; border-color: transparent;
    box-shadow: 0 2px 8px rgba(99,102,241,0.3);
}
.ll-page-btn.disabled { opacity: 0.35; pointer-events: none; }

/* ── Responsive ── */
@media (max-width: 768px) {
    .ll-wrap { padding: 0.75rem; gap: 0.75rem; }
    .ll-header { flex-direction: column; align-items: flex-start; }
    .ll-header-right { width: 100%; }
    .ll-header-right .btn { flex: 1; text-align: center; }
    .ll-toolbar { flex-direction: column; align-items: stretch; }
    .ll-search { max-width: none; }
    .ll-toolbar-right { justify-content: space-between; }
    .ll-filters { flex-direction: column; }
    .ll-filter-group { min-width: 0; }
    .ll-grid { grid-template-columns: 1fr; }
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
        var cls = { 'LEAD': 'll-badge-lead', 'FOLLOWUP': 'll-badge-followup', 'LOGIN': 'll-badge-login',
            'INTERNAL_UNDERWRITING': 'll-badge-internal', 'BANK_UNDERWRITING': 'll-badge-bank', 'SANCTIONED': 'll-badge-sanctioned', 'DISBURSED': 'll-badge-disbursed', 'REJECT': 'll-badge-reject' };
        return cls[status] || 'll-badge-lead';
    }

    function renderCards(cards) {
        if (!cards || !cards.length) {
            leadCards.innerHTML = '<div class="ll-empty"><i class="bi bi-search-heart"></i><p>No leads match your current search and filters.</p></div>';
            return;
        }
        leadCards.innerHTML = cards.map(function(card) {
            var statusClass = statusBadgeClass(card.status);
            var statusIcon = card.status_icon || 'bi-circle';
            var hasExpander = (card.timeline && card.timeline.length) || (card.financial && card.financial.length) || (card.banking && card.banking.length) || (card.source && card.source.length) || (card.notes && card.notes.length);

            function fieldList(arr) {
                if (!arr || !arr.length) return '';
                return arr.map(function(f) { return '<div class="ll-ex-item"><span class="ll-ex-label">' + escapeHtml(f.label) + '</span><span class="ll-ex-value">' + escapeHtml(f.value) + '</span></div>'; }).join('');
            }

            var extras = '';
            if (hasExpander) {
                extras += '<div class="ll-card-extras"><div class="ll-extras-grid">';
                extras += fieldList(card.timeline);
                extras += fieldList(card.financial);
                extras += fieldList(card.banking);
                extras += fieldList(card.source);
                extras += fieldList(card.notes);
                extras += '</div></div>';
            }

            var followupDate = '';
            var followupClass = '';
            if (card.timeline) {
                var f = card.timeline.find(function(t) { return t.label === 'Next Follow-up'; });
                if (f) {
                    followupDate = f.value;
                    if (card.next_followup_raw) {
                        var todayStr = new Date().toISOString().slice(0,10);
                        if (card.next_followup_raw < todayStr) followupClass = 'overdue';
                        else if (card.next_followup_raw === todayStr) followupClass = 'today';
                    }
                }
            }

            return '<div class="ll-card status-' + card.status + '" data-id="' + card.lead_id + '">'
                + '<div class="ll-card-main">'
                + '<div class="ll-card-top">'
                + '<div>'
                + '<div class="ll-card-name">' + escapeHtml(card.customer_name) + ' <small>#' + card.lead_id + '</small></div>'
                + '<div class="ll-card-sub"><i class="bi bi-person-badge"></i> '
                + escapeHtml((card.source || []).find(function(s) { return s.label === 'Assigned To'; })?.value || 'Unassigned')
                + (card.identity ? (card.identity.find(function(i) { return i.label === 'Company'; })?.value ? ' &middot; ' + escapeHtml(card.identity.find(function(i) { return i.label === 'Company'; }).value) : '') : '')
                + '</div>'
                + '</div>'
                + '<span class="ll-card-badge ' + statusClass + '"><i class="bi ' + statusIcon + ' me-1"></i>' + escapeHtml(card.status_label) + '</span>'
                + '</div>'
                + '<div class="ll-card-pills">'
                + (card.mobile && card.mobile !== 'N/A' ? '<a href="tel:' + escapeHtml(card.mobile) + '" class="ll-pill text-decoration-none"><i class="bi bi-telephone-fill"></i> ' + escapeHtml(card.mobile) + '</a>' : '')
                + (card.identity ? card.identity.filter(function(i) { return i.label === 'Loan Type'; }).map(function(i) { return '<span class="ll-pill"><i class="bi bi-briefcase"></i> ' + escapeHtml(i.value) + '</span>'; }).join('') : '')
                + (card.identity ? card.identity.filter(function(i) { return i.label === 'Application'; }).map(function(i) { return '<span class="ll-pill"><i class="bi bi-hash"></i> ' + escapeHtml(i.value) + '</span>'; }).join('') : '')
                + (followupDate ? '<span class="ll-pill' + (followupClass ? ' ' + followupClass : '') + '"><i class="bi bi-alarm"></i> ' + escapeHtml(followupDate) + '</span>' : '')
                + (card.rework_flag ? '<span class="ll-pill ll-pill-rework" title="Rework pending"><i class="bi bi-exclamation-triangle-fill"></i> Rework' + (card.rework_stage ? ' (' + escapeHtml(card.rework_stage === 'BANK' ? 'Bank' : 'Internal') + ')' : '') + '</span>' : '')
                + (card.login_status ? '<span class="ll-pill ll-pill-login" title="Login status"><i class="bi bi-shield-check"></i> ' + escapeHtml(card.login_status) + '</span>' : '')
                + '</div>'
                + '<div class="ll-card-footer">'
                + '<div class="ll-card-actions">'
                + '<a href="' + viewBase + '/lead_view.php?id=' + card.lead_id + '" class="ll-action-btn primary"><i class="bi bi-pencil-square"></i> Manage</a>'
                + (hasExpander ? '<button type="button" class="ll-action-btn ll-expand-btn"><i class="bi bi-chevron-down"></i> Details</button>' : '')
                + (canDelete ? '<button type="button" class="ll-action-btn delete-btn" data-id="' + card.lead_id + '"><i class="bi bi-trash3"></i></button>' : '')
                + '</div>'
                + '</div>'
                + '</div>'
                + extras
                + '</div>';
        }).join('');

        // Wire expand buttons
        leadCards.querySelectorAll('.ll-expand-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                this.closest('.ll-card').classList.toggle('expanded');
                var icon = this.querySelector('i');
                if (icon) icon.className = icon.className.indexOf('chevron-down') !== -1 ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
            });
        });

        // Wire delete buttons
        if (canDelete) {
            leadCards.querySelectorAll('.delete-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    var id = this.getAttribute('data-id');
                    if (!id) return;
                    if (!confirm('Are you sure you want to delete lead #' + id + '? This action cannot be undone.')) return;
                    var body = new URLSearchParams({ lead_id: id, csrf_token: csrfToken });
                    var card = this.closest('.ll-card');
                    if (card) card.style.opacity = '0.4';
                    fetch(deleteUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body })
                        .then(function(r) { return r.json(); })
                        .then(function(resp) {
                            if (resp.ok) {
                                if (card) card.remove();
                                if (!leadCards.querySelector('.ll-card')) {
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
        return '<button type="button" class="ll-page-btn' + (isActive ? ' active' : '') + (isDisabled ? ' disabled' : '') + '" data-page="' + page + '">' + label + '</button>';
    }

    function renderPagination(p) {
        if (!p || p.totalPages <= 1) { leadPagination.innerHTML = ''; return; }
        var buttons = [];
        buttons.push(buildPageButton('<i class="bi bi-chevron-left"></i>', p.page - 1, false, p.page <= 1));
        var start = Math.max(1, p.page - 2);
        var end = Math.min(p.totalPages, p.page + 2);
        if (start > 1) {
            buttons.push(buildPageButton('1', 1, p.page === 1));
            if (start > 2) buttons.push('<span class="ll-page-btn disabled">…</span>');
        }
        for (var i = start; i <= end; i++) buttons.push(buildPageButton(String(i), i, i === p.page));
        if (end < p.totalPages) {
            if (end < p.totalPages - 1) buttons.push('<span class="ll-page-btn disabled">…</span>');
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
                leadCards.innerHTML = '<div class="ll-empty"><i class="bi bi-exclamation-circle"></i><p>' + escapeHtml(error.message || 'Unable to load leads.') + '</p></div>';
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
