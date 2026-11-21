<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';

if (!canViewFullPhone()) {
    logActivity($link, USER_ID, 'EXPORT_BLOCKED', "Attempted to export Main Database CSV without admin privileges", (string)USER_ID, 'TBL_MAIN');
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Export restricted to Admin / Super Admin']);
    exit;
}

requirePermission('export_db');
api_init();

$search   = trim($_REQUEST['q'] ?? ($_REQUEST['search']['value'] ?? ''));
$status   = trim($_REQUEST['status'] ?? '');
$dateType = trim($_REQUEST['date_type'] ?? 'upload');
$from     = trim($_REQUEST['from'] ?? '');
$to       = trim($_REQUEST['to'] ?? '');

$contactScope = getHierarchyContactFilterSQL($link, (int)USER_ID, USER_ROLE, 'm', defined('USER_TEAM_ID') ? (int)USER_TEAM_ID : null);
$where = "WHERE $contactScope";
$params = [];

if ($search !== '') {
    $where .= " AND (m.MAINDATABASE_MOBILE LIKE ? 
                OR m.MAINDATABASE_NAME LIKE ? 
                OR m.MAINDATABASE_COMPANY LIKE ? 
                OR m.MAINDATABASE_PACKAGE LIKE ? 
                OR m.MAINDATABASE_OTHER_INFO LIKE ?)";
    $like = "%$search%";
    for ($i = 0; $i < 5; $i++) {
        $params[] = $like;
    }
}
if ($status !== '') {
    $where .= " AND TRIM(m.MAINDATABASE_CALL_DIALED_STATUS) = ?";
    $params[] = $status;
}

$dateCol = ($dateType === 'dialed') ? 'm.LAST_DIALED_DATE_TIME' : 'm.MAINDATABASE_UPLOAD_DATETIME';
if ($from !== '') {
    $where .= " AND DATE($dateCol) >= ?";
    $params[] = $from;
}
if ($to !== '') {
    $where .= " AND DATE($dateCol) <= ?";
    $params[] = $to;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="MainDatabase_Filtered_'.date('Y-m-d_His').'.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Mobile', 'Name', 'Company', 'Package', 'Status', 'Assigned To', 'Uploaded On']);

$sql = "SELECT m.MAINDATABASE_MOBILE,
               m.MAINDATABASE_NAME,
               m.MAINDATABASE_COMPANY,
               m.MAINDATABASE_PACKAGE,
               m.MAINDATABASE_CALL_DIALED_STATUS,
               u.NAME AS assigned,
               DATE_FORMAT(m.MAINDATABASE_UPLOAD_DATETIME, '%d-%m-%Y %H:%i') AS dt
        FROM " . tn('TBL_MAIN') . " m
        LEFT JOIN " . tn('TBL_USERS') . " u ON m.MAINDATABASE_CALL_DIALED_USER = u.ID
        $where
        ORDER BY m.MAINDATABASE_UPLOAD_DATETIME DESC";

$stmt = mysqli_prepare($link, $sql);
if (!empty($params)) {
    $types = str_repeat('s', count($params));
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($res)) {
    $rowClean = [
        $row['MAINDATABASE_MOBILE'],
        $row['MAINDATABASE_NAME'] ?: '',
        $row['MAINDATABASE_COMPANY'] ?: '',
        $row['MAINDATABASE_PACKAGE'] ?: '',
        $row['MAINDATABASE_CALL_DIALED_STATUS'] ?: 'Not Called',
        $row['assigned'] ?: '',
        $row['dt']
    ];
    $sanitized = [];
    foreach ($rowClean as $val) {
        $val = strval($val ?? '');
        if ($val !== '' && in_array($val[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $val = "'" . $val;
        }
        $sanitized[] = $val;
    }
    fputcsv($output, $sanitized);
}
fclose($output);
exit;
?>
