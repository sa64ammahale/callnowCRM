<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../lead_common.php';

header('Content-Type: application/json; charset=utf-8');

$allowedRoles = ['Admin', 'Manager', 'Supervisor', 'Officer'];
if (!in_array(USER_ROLE, $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode([
        'draw' => (int)($_POST['draw'] ?? 0),
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'Access denied.'
    ]);
    exit;
}

ensureLeadModuleSchema($link);
mysqli_set_charset($link, 'utf8mb4');

$draw = (int)($_POST['draw'] ?? 0);
$start = max(0, (int)($_POST['start'] ?? 0));
$length = (int)($_POST['length'] ?? 25);
if ($length < 1 || $length > 200) {
    $length = 25;
}

$globalSearch = trim((string)($_POST['search']['value'] ?? ''));
$columnSearches = [];
foreach ((array)($_POST['columns'] ?? []) as $index => $column) {
    $columnSearches[(int)$index] = trim((string)($column['search']['value'] ?? ''));
}

$quickStatus = trim((string)($_POST['quickStatus'] ?? 'PIPELINE'));
$loginMonth = trim((string)($_POST['loginMonth'] ?? 'current_previous'));
$followupMonth = trim((string)($_POST['followupMonth'] ?? ''));
$quickLoginMode = strtoupper(trim((string)($_POST['quickLoginMode'] ?? '')));
$quickAssigned = trim((string)($_POST['quickAssigned'] ?? ''));
$quickLoanType = trim((string)($_POST['quickLoanType'] ?? ''));

$currentMonth = date('Y-m');
$previousMonth = date('Y-m', strtotime('first day of last month'));
$pipelineStatuses = ['LEAD', 'LOGIN', 'UNDERWRTING', 'FOLLOWUP', 'SANCTIONED'];
$allowedStatuses = leadStatusOptions();
$allowedLoginModes = loginModeOptions();

$baseFrom = "
    FROM LEADS_TABLE l
    LEFT JOIN main_database m ON m.ID = l.cust_id
    LEFT JOIN users u ON u.ID = l.assigned_to
";

$conditions = [];
$accessCondition = getLeadAccessCondition($link, 'l');
if ($accessCondition !== null) {
    $conditions[] = $accessCondition;
}

$effectiveLoginMonthExpr = "COALESCE(DATE_FORMAT(l.login_date, '%Y-%m'), DATE_FORMAT(l.next_followup_at, '%Y-%m'), DATE_FORMAT(l.created_at, '%Y-%m'))";
$followupMonthExpr = "DATE_FORMAT(l.next_followup_at, '%Y-%m')";

$monthCondition = static function (string $expr, string $filterValue, string $currentMonth, string $previousMonth): ?string {
    if ($filterValue === '') {
        return null;
    }
    if ($filterValue === 'current_previous') {
        return "({$expr} = '{$currentMonth}' OR {$expr} = '{$previousMonth}')";
    }
    if ($filterValue === 'current') {
        return "{$expr} = '{$currentMonth}'";
    }
    if ($filterValue === 'previous') {
        return "{$expr} = '{$previousMonth}'";
    }
    if (preg_match('/^\d{4}-\d{2}$/', $filterValue)) {
        return "{$expr} = '" . mysqli_real_escape_string($link, $filterValue) . "'";
    }
    return null;
};

$quickStatusUpper = strtoupper($quickStatus);
if ($quickStatusUpper === 'PIPELINE') {
    $conditions[] = "l.lead_status_new IN ('" . implode("','", $pipelineStatuses) . "')";
} elseif ($quickStatusUpper !== '' && in_array($quickStatusUpper, $allowedStatuses, true)) {
    $conditions[] = "l.lead_status_new = '" . mysqli_real_escape_string($link, $quickStatusUpper) . "'";
}

$loginMonthCondition = $monthCondition($effectiveLoginMonthExpr, $loginMonth, $currentMonth, $previousMonth);
if ($loginMonthCondition) {
    $conditions[] = $loginMonthCondition;
}

$followupMonthCondition = $monthCondition($followupMonthExpr, $followupMonth, $currentMonth, $previousMonth);
if ($followupMonthCondition) {
    $conditions[] = $followupMonthCondition;
}

if ($quickLoginMode !== '' && in_array($quickLoginMode, $allowedLoginModes, true)) {
    $conditions[] = "l.login_mode = '" . mysqli_real_escape_string($link, $quickLoginMode) . "'";
}
if ($quickAssigned !== '') {
    $conditions[] = "COALESCE(u.NAME, 'Unassigned') = '" . mysqli_real_escape_string($link, $quickAssigned) . "'";
}
if ($quickLoanType !== '') {
    $conditions[] = "COALESCE(l.loan_type, '') = '" . mysqli_real_escape_string($link, $quickLoanType) . "'";
}

if ($globalSearch !== '') {
    $like = '%' . escapeLikeValue($link, $globalSearch) . '%';
    $conditions[] = "("
        . "COALESCE(m.MAINDATABASE_NAME, '') LIKE '{$like}'"
        . " OR COALESCE(m.MAINDATABASE_MOBILE, '') LIKE '{$like}'"
        . " OR COALESCE(m.MAINDATABASE_COMPANY, '') LIKE '{$like}'"
        . " OR COALESCE(l.loan_type, '') LIKE '{$like}'"
        . " OR COALESCE(l.loan_app_no, '') LIKE '{$like}'"
        . " OR COALESCE(l.promo_code, '') LIKE '{$like}'"
        . " OR COALESCE(u.NAME, '') LIKE '{$like}'"
        . " OR COALESCE(l.login_bank_name, '') LIKE '{$like}'"
        . " OR COALESCE(l.remarks, '') LIKE '{$like}'"
        . " OR COALESCE(l.login_location, '') LIKE '{$like}'"
        . ")";
}

$columnMap = [
    0 => [
        "DATE_FORMAT(l.login_date, '%d %b %Y')",
        "DATE_FORMAT(l.next_followup_at, '%d %b %Y')"
    ],
    1 => [
        "COALESCE(m.MAINDATABASE_NAME, '')",
        "COALESCE(m.MAINDATABASE_MOBILE, '')",
        "COALESCE(m.MAINDATABASE_COMPANY, '')"
    ],
    2 => [
        "COALESCE(l.net_salary, '')",
        "COALESCE(l.salary_account, '')",
        "COALESCE(l.bank_name, '')"
    ],
    3 => [
        "COALESCE(l.loan_amount, '')",
        "COALESCE(l.loan_tenure, '')",
        "COALESCE(l.loan_type, '')"
    ],
    4 => [
        "COALESCE(l.promo_code, '')",
        "COALESCE(l.loan_app_no, '')"
    ],
    5 => [
        "COALESCE(u.NAME, 'Unassigned')",
        "COALESCE(l.dsa_name, '')"
    ],
    6 => [
        "COALESCE(l.login_bank_name, '')",
        "COALESCE(l.bank_rm_name, '')",
        "COALESCE(l.bt_details, '')"
    ],
    7 => [
        "COALESCE(l.lead_status_new, '')",
        "COALESCE(l.login_mode, '')"
    ],
    8 => [
        "COALESCE(l.login_location, '')",
        "COALESCE(l.remarks, '')",
        "COALESCE(m.MAINDATABASE_OTHER_INFO, '')"
    ],
];

foreach ($columnSearches as $index => $value) {
    if ($value === '' || !isset($columnMap[$index])) {
        continue;
    }
    $like = '%' . escapeLikeValue($link, $value) . '%';
    $parts = [];
    foreach ($columnMap[$index] as $expr) {
        $parts[] = "{$expr} LIKE '{$like}'";
    }
    $conditions[] = '(' . implode(' OR ', $parts) . ')';
}

$whereSql = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';

$totalConditions = [];
if ($accessCondition !== null) {
    $totalConditions[] = $accessCondition;
}
$totalWhereSql = $totalConditions ? (' WHERE ' . implode(' AND ', $totalConditions)) : '';

$totalSql = "SELECT COUNT(*) {$baseFrom} {$totalWhereSql}";
$filteredSql = "SELECT COUNT(*) {$baseFrom} {$whereSql}";

$recordsTotal = (int)mysqli_fetch_row(mysqli_query($link, $totalSql))[0];
$recordsFiltered = (int)mysqli_fetch_row(mysqli_query($link, $filteredSql))[0];

$orderable = [
    0 => "COALESCE(l.login_date, l.created_at)",
    1 => "COALESCE(m.MAINDATABASE_NAME, '')",
    2 => "COALESCE(l.net_salary, '')",
    3 => "COALESCE(l.loan_amount, '')",
    4 => "COALESCE(l.promo_code, '')",
    5 => "COALESCE(u.NAME, 'Unassigned')",
    6 => "COALESCE(l.login_bank_name, '')",
    7 => "COALESCE(l.lead_status_new, '')",
    8 => "COALESCE(l.next_followup_at, l.created_at)",
];
$orderColumnIndex = (int)($_POST['order'][0]['column'] ?? 0);
$orderDir = strtolower((string)($_POST['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$orderExpr = $orderable[$orderColumnIndex] ?? "COALESCE(l.login_date, l.created_at)";

$dataSql = "
    SELECT
        l.lead_id,
        l.created_at,
        l.login_date,
        l.net_salary,
        l.salary_account,
        l.bank_name,
        l.loan_amount,
        l.loan_tenure,
        l.promo_code,
        l.login_bank_name,
        l.lead_status_new,
        l.login_mode,
        l.loan_type,
        l.loan_app_no,
        l.login_location,
        l.bank_rm_name,
        l.bt_details,
        l.remarks,
        l.dsa_name,
        l.next_followup_at,
        COALESCE(m.MAINDATABASE_NAME, '-') AS customer_name,
        COALESCE(m.MAINDATABASE_MOBILE, '-') AS mobile,
        COALESCE(m.MAINDATABASE_COMPANY, '-') AS company_name,
        COALESCE(m.MAINDATABASE_OTHER_INFO, '-') AS other_info,
        COALESCE(u.NAME, 'Unassigned') AS assigned_name
    {$baseFrom}
    {$whereSql}
    ORDER BY {$orderExpr} {$orderDir}, l.lead_id DESC
    LIMIT {$start}, {$length}
";
$result = mysqli_query($link, $dataSql);
$rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

$data = [];
foreach ($rows as $row) {
    $status = (string)($row['lead_status_new'] ?? 'LEAD');
    $data[] = [
        '<div class="grid-cell">'
            . '<span class="grid-kv"><b>LOGIN DATE:</b>' . htmlspecialchars($row['login_date'] ? date('d M Y', strtotime($row['login_date'])) : '-') . '</span>'
            . '<span class="grid-kv"><b>NEXT FOLLOW-UP:</b>' . htmlspecialchars($row['next_followup_at'] ? date('d M Y', strtotime($row['next_followup_at'])) : 'Not set') . '</span>'
            . '</div>',
        '<div class="grid-cell">'
            . '<span class="grid-strong customer-name line-clamp-2">' . htmlspecialchars($row['customer_name']) . '</span>'
            . '<a href="tel:' . htmlspecialchars($row['mobile']) . '" class="customer-mobile"><i class="bi bi-telephone-fill"></i>' . htmlspecialchars($row['mobile']) . '</a>'
            . '<span class="customer-company-line line-clamp-2"><b>COMPANY:</b>' . htmlspecialchars($row['company_name']) . '</span>'
            . '</div>',
        '<div class="grid-cell">'
            . '<span class="grid-kv"><b>NET SALARY:</b>' . htmlspecialchars((string)($row['net_salary'] ?: '-')) . '</span>'
            . '<span class="grid-kv"><b>SALARY A/C:</b>' . htmlspecialchars((string)($row['salary_account'] ?: '-')) . '</span>'
            . '<span class="grid-kv"><b>BANK NAME:</b>' . htmlspecialchars((string)($row['bank_name'] ?: '-')) . '</span>'
            . '</div>',
        '<div class="grid-cell">'
            . '<span class="grid-kv"><b>LOAN AMOUNT:</b>' . htmlspecialchars((string)($row['loan_amount'] ?: '-')) . '</span>'
            . '<span class="grid-kv"><b>LOAN TENURE:</b>' . htmlspecialchars((string)($row['loan_tenure'] ?: '-')) . '</span>'
            . '<span class="grid-kv line-clamp-2"><b>LOAN TYPE:</b>' . htmlspecialchars((string)($row['loan_type'] ?: '-')) . '</span>'
            . '</div>',
        '<div class="grid-cell">'
            . '<span class="grid-kv line-clamp-2"><b>PROMO CODE:</b>' . htmlspecialchars((string)($row['promo_code'] ?: '-')) . '</span>'
            . '<span class="grid-kv line-clamp-2"><b>APP ID:</b>' . htmlspecialchars((string)($row['loan_app_no'] ?: '-')) . '</span>'
            . '</div>',
        '<div class="grid-cell">'
            . '<span class="grid-kv line-clamp-2"><b>ASSIGNED TO:</b>' . htmlspecialchars($row['assigned_name']) . '</span>'
            . '<span class="grid-kv line-clamp-2"><b>DSA NAME:</b>' . htmlspecialchars((string)($row['dsa_name'] ?: '-')) . '</span>'
            . '</div>',
        '<div class="grid-cell">'
            . '<span class="grid-kv line-clamp-2"><b>LOGIN BANK:</b>' . htmlspecialchars((string)($row['login_bank_name'] ?: '-')) . '</span>'
            . '<span class="grid-kv line-clamp-2"><b>BANK RM:</b>' . htmlspecialchars((string)($row['bank_rm_name'] ?: '-')) . '</span>'
            . '<span class="grid-kv line-clamp-2"><b>BT DETAILS:</b>' . htmlspecialchars((string)($row['bt_details'] ?: '-')) . '</span>'
            . '</div>',
        '<div class="status-stack">'
            . '<span class="badge ' . htmlspecialchars(leadStatusBadgeClass($status)) . ' badge-status">' . htmlspecialchars($status) . '</span>'
            . '<span class="status-caption">Login Mode</span>'
            . '<span class="badge-mode">' . htmlspecialchars((string)($row['login_mode'] ?: '-')) . '</span>'
            . '</div>',
        '<div class="grid-cell">'
            . '<span class="grid-kv line-clamp-2"><b>LOCATION:</b>' . htmlspecialchars((string)($row['login_location'] ?: '-')) . '</span>'
            . '<span class="grid-kv line-clamp-2"><b>REMARK:</b>' . htmlspecialchars((string)($row['remarks'] ?: '-')) . '</span>'
            . '<span class="grid-kv line-clamp-2"><b>OTHER INFO:</b>' . htmlspecialchars((string)($row['other_info'] ?: '-')) . '</span>'
            . '</div>',
        '<a href="lead_view.php?id=' . (int)$row['lead_id'] . '" class="btn btn-outline-primary btn-edit" title="Edit Lead">'
            . '<i class="bi bi-pencil-square"></i>'
            . '</a>'
    ];
}

echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data' => $data,
]);
