<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../lead_common.php';
api_init();

header('Content-Type: application/json; charset=utf-8');

$allowedTBL_ROLES = ['Admin', 'Manager', 'Supervisor', 'Officer'];
if (!in_array(USER_ROLE, $allowedTBL_ROLES, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Access denied.']);
    exit;
}

ensureLeadModuleSchema($link);
mysqli_set_charset($link, 'utf8mb4');

$page = max(1, (int)($_POST['page'] ?? 1));
$perPage = max(1, min(100, (int)($_POST['perPage'] ?? 12)));
$offset = ($page - 1) * $perPage;

$search = trim((string)($_POST['search'] ?? ''));
$quickStatus = strtoupper(trim((string)($_POST['quickStatus'] ?? 'PIPELINE')));
$loginMonth = trim((string)($_POST['loginMonth'] ?? 'current_previous'));
$followupMonth = trim((string)($_POST['followupMonth'] ?? ''));
$quickLoginMode = strtoupper(trim((string)($_POST['quickLoginMode'] ?? '')));
$quickAssigned = trim((string)($_POST['quickAssigned'] ?? ''));
$quickLoanType = trim((string)($_POST['quickLoanType'] ?? ''));
$quickRework = strtoupper(trim((string)($_POST['quickRework'] ?? '')));

$currentMonth = date('Y-m');
$previousMonth = date('Y-m', strtotime('first day of last month'));
$pipelineStatuses = ['LEAD', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT'];
$allowedStatuses = leadStatusOptions();
$allowedLoginModes = loginModeOptions();

$baseFrom = "
    FROM " . tn('TBL_LEADS') . " l
    LEFT JOIN " . tn('TBL_MAIN') . " m ON m.ID = l.cust_id
    LEFT JOIN " . tn('TBL_USERS') . " u ON u.ID = l.assigned_to
";

$conditions = [];
$accessCondition = getLeadAccessCondition($link, 'l');
if ($accessCondition !== null) {
    $conditions[] = $accessCondition;
}

$effectiveLoginMonthExpr = "COALESCE(DATE_FORMAT(l.login_date, '%Y-%m'), DATE_FORMAT(l.next_followup_at, '%Y-%m'), DATE_FORMAT(l.created_at, '%Y-%m'))";
$followupMonthExpr = "DATE_FORMAT(l.next_followup_at, '%Y-%m')";

$buildMonthCondition = static function (string $expr, string $value, string $currentMonth, string $previousMonth) use ($link): ?string {
    if ($value === '') {
        return null;
    }
    if ($value === 'current_previous') {
        return "({$expr} = '{$currentMonth}' OR {$expr} = '{$previousMonth}')";
    }
    if ($value === 'current') {
        return "{$expr} = '{$currentMonth}'";
    }
    if ($value === 'previous') {
        return "{$expr} = '{$previousMonth}'";
    }
    if (preg_match('/^\d{4}-\d{2}$/', $value)) {
        return "{$expr} = '" . mysqli_real_escape_string($link, $value) . "'";
    }
    return null;
};

if ($quickStatus === 'PIPELINE') {
    $conditions[] = "l.lead_status_new IN ('" . implode("','", $pipelineStatuses) . "')";
} elseif ($quickStatus !== '' && in_array($quickStatus, $allowedStatuses, true)) {
    $conditions[] = "l.lead_status_new = '" . mysqli_real_escape_string($link, $quickStatus) . "'";
}

$loginMonthCondition = $buildMonthCondition($effectiveLoginMonthExpr, $loginMonth, $currentMonth, $previousMonth);
if ($loginMonthCondition) {
    $conditions[] = $loginMonthCondition;
}

$followupMonthCondition = $buildMonthCondition($followupMonthExpr, $followupMonth, $currentMonth, $previousMonth);
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
if ($quickRework === 'YES') {
    $conditions[] = "l.rework_flag = 1";
} elseif ($quickRework === 'INTERNAL') {
    $conditions[] = "l.rework_flag = 1 AND l.rework_stage = 'INTERNAL'";
} elseif ($quickRework === 'BANK') {
    $conditions[] = "l.rework_flag = 1 AND l.rework_stage = 'BANK'";
}

if ($search !== '') {
    $like = '%' . escapeLikeValue($link, $search) . '%';
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
        . " OR COALESCE(l.dsa_name, '') LIKE '{$like}'"
        . ")";
}

$whereSql = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';
$totalSql = "SELECT COUNT(*) {$baseFrom} {$whereSql}";
$totalRecords = (int)mysqli_fetch_row(mysqli_query($link, $totalSql))[0];
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

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
        l.rework_flag,
        l.rework_stage,
        l.login_status,
        l.forwarded_flag,
        l.sent_backward_flag,
        l.parent_lead_id,
        COALESCE(NULLIF(m.MAINDATABASE_NAME, ''), NULLIF(l.NAME, ''), CONCAT('Lead #', l.lead_id)) AS customer_name,
        COALESCE(NULLIF(m.MAINDATABASE_MOBILE, ''), NULLIF(l.MOBILE, ''), 'N/A') AS mobile,
        COALESCE(NULLIF(m.MAINDATABASE_COMPANY, ''), NULLIF(l.COMPANY_NAME, ''), '') AS company_name,
        COALESCE(m.MAINDATABASE_OTHER_INFO, '') AS other_info,
        COALESCE(u.NAME, 'Unassigned') AS assigned_name
    {$baseFrom}
    {$whereSql}
    ORDER BY COALESCE(l.login_date, l.created_at) DESC, l.updated_at DESC, l.lead_id DESC
    LIMIT {$offset}, {$perPage}
";
$result = mysqli_query($link, $dataSql);
$rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

$statusMeta = leadStatusMeta();
$cards = [];

$addField = static function (array &$bucket, string $label, string $value): void {
    $value = trim($value);
    if ($value !== '') {
        $bucket[] = ['label' => $label, 'value' => $value];
    }
};

foreach ($rows as $row) {
    $status = strtoupper((string)($row['lead_status_new'] ?: 'LEAD'));
    $meta = $statusMeta[$status] ?? ['label' => $status, 'icon' => 'bi-circle', 'description' => ''];

    $timeline = [];
    $identity = [];
    $financial = [];
    $banking = [];
    $source = [];

    $addField($timeline, 'Login Date', $row['login_date'] ? date('d M Y', strtotime($row['login_date'])) : '');
    $addField($timeline, 'Next Follow-up', $row['next_followup_at'] ? date('d M Y', strtotime($row['next_followup_at'])) : '');
    $addField($timeline, 'Location', (string)$row['login_location']);

    $addField($identity, 'Company', (string)$row['company_name']);
    $addField($identity, 'Loan Type', (string)$row['loan_type']);
    $addField($identity, 'Application', (string)$row['loan_app_no']);

    $addField($financial, 'Net Salary', (string)$row['net_salary']);
    $addField($financial, 'Salary A/C', (string)$row['salary_account']);
    $addField($financial, 'Loan Amount', (string)$row['loan_amount']);
    $addField($financial, 'Loan Tenure', (string)$row['loan_tenure']);

    $addField($banking, 'Bank', (string)$row['bank_name']);
    $addField($banking, 'Login Bank', (string)$row['login_bank_name']);
    $addField($banking, 'Bank RM', (string)$row['bank_rm_name']);
    $addField($banking, 'BT Details', (string)$row['bt_details']);

    $addField($source, 'Assigned To', (string)$row['assigned_name']);
    $addField($source, 'DSA', (string)$row['dsa_name']);
    $addField($source, 'Promo Code', (string)$row['promo_code']);
    $addField($source, 'Login Mode', (string)$row['login_mode']);

    $notes = [];
    $addField($notes, 'Remark', (string)$row['remarks']);
    $addField($notes, 'Other Info', (string)$row['other_info']);

    $nextFollowupDate = $row['next_followup_at'] ? date('Y-m-d', strtotime($row['next_followup_at'])) : '';

    $cards[] = [
        'lead_id' => (int)$row['lead_id'],
        'customer_name' => $row['customer_name'] ?: 'Unknown',
        'mobile' => $row['mobile'],
        'status' => $status,
        'status_label' => $meta['label'],
        'status_badge' => leadStatusBadgeClass($status),
        'status_icon' => $meta['icon'],
        'rework_flag' => (int)$row['rework_flag'],
        'rework_stage' => $row['rework_stage'],
        'login_status' => $row['login_status'],
        'forwarded_flag' => (int)$row['forwarded_flag'],
        'sent_backward_flag' => (int)$row['sent_backward_flag'],
        'parent_lead_id' => (int)$row['parent_lead_id'],
        'next_followup_raw' => $nextFollowupDate,
        'timeline' => $timeline,
        'identity' => $identity,
        'financial' => $financial,
        'banking' => $banking,
        'source' => $source,
        'notes' => $notes,
    ];
}

echo json_encode([
    'ok' => true,
    'cards' => $cards,
    'pagination' => [
        'page' => $page,
        'perPage' => $perPage,
        'totalRecords' => $totalRecords,
        'totalPages' => $totalPages,
        'from' => $totalRecords ? ($offset + 1) : 0,
        'to' => min($offset + $perPage, $totalRecords),
    ],
]);
