<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/phone_helper.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

verifyCsrfToken($_POST['csrf_token'] ?? '');

$recordId = (int)($_POST['record_id'] ?? 0);
$source = strtoupper(trim($_POST['source'] ?? ''));
$table = trim($_POST['table'] ?? '');

if ($recordId <= 0 || !in_array($source, ['MAIN', 'TEMP', 'LEADS'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

$tableMap = [
    'MAIN' => tn('TBL_MAIN'),
    'TEMP' => tn('TBL_TEMP'),
    'LEADS' => tn('TBL_LEADS'),
];
$tbl = $tableMap[$source];

$mobileCol = '';
$ownerCol = '';
$idCol = 'ID';
switch ($source) {
    case 'MAIN':
        $mobileCol = 'MAINDATABASE_MOBILE';
        $ownerCol = 'MAINDATABASE_CALL_DIALED_USER';
        $idCol = 'ID';
        break;
    case 'TEMP':
        $mobileCol = 'CUST_MOBILE';
        $ownerCol = 'CALL_DIALED_TELECALLER';
        $idCol = 'ID';
        break;
    case 'LEADS':
        $mobileCol = 'MOBILE';
        $ownerCol = 'assigned_to';
        $idCol = 'lead_id';
        break;
}

$canViewAll = canViewFullPhone();

$sql = "SELECT {$mobileCol} AS mobile FROM {$tbl} WHERE {$idCol} = ?";
$params = [$recordId];
$types = 'i';

if (!$canViewAll) {
    $accessibleIds = getAccessibleUserIds($link);
    if (isOfficer()) {
        $sql .= " AND {$ownerCol} = ?";
        $params[] = USER_ID;
        $types .= 'i';
    } elseif (!empty($accessibleIds)) {
        $in = implode(',', array_map('intval', $accessibleIds));
        $sql .= " AND {$ownerCol} IN ({$in})";
    } else {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
}

$stmt = mysqli_prepare($link, $sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$row || empty(trim((string)($row['mobile'] ?? '')))) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Number not found']);
    exit;
}

$fullMobile = trim((string)$row['mobile']);

// Log the phone view activity
logActivity($link, USER_ID, 'PHONE_VIEW', "Viewed mobile number for record #{$recordId} ({$source})", (string)$recordId, $tbl);

echo json_encode(['success' => true, 'mobile' => $fullMobile]);
exit;