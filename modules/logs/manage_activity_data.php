<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
requirePermission('view_activity');

/* DataTables params */
$draw    = intval($_GET['draw'] ?? 1);
$start   = intval($_GET['start'] ?? 0);
$length  = intval($_GET['length'] ?? 15);
$search  = trim($_GET['search']['value'] ?? '');
$orderCol = intval($_GET['order'][0]['column'] ?? 0);
$orderDir = strtoupper($_GET['order'][0]['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

/* Existing page filters */
$filter_user = $_GET['filter_user'] ?? '';
$filter_action = $_GET['filter_action'] ?? '';
$filter_table = $_GET['filter_table'] ?? '';
$q = trim($_GET['q'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$userRole = defined('USER_ROLE') ? USER_ROLE : ($_SESSION['role'] ?? '');
$userId = intval($_SESSION['id'] ?? 0);

$where = [];
$types = '';
$params = [];

if ($filter_user !== '') { $where[] = "a.USER_ID = ?"; $types .= 'i'; $params[] = intval($filter_user); }
if ($filter_action !== '') { $where[] = "a.ACTION_TYPE = ?"; $types .= 's'; $params[] = $filter_action; }
if ($filter_table !== '') { $where[] = "a.TARGET_TABLE = ?"; $types .= 's'; $params[] = $filter_table; }
if ($q !== '') {
    $where[] = "(a.ACTION_DETAILS LIKE ? OR a.AFFECTED_IDS LIKE ? OR a.TARGET_TABLE LIKE ? OR a.ACTION_TYPE LIKE ? OR u.NAME LIKE ?)";
    $types .= 'sssss';
    $like = "%$q%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($date_from !== '') { $where[] = "a.LOG_TIME >= ?"; $types .= 's'; $params[] = $date_from . " 00:00:00"; }
if ($date_to !== '') { $where[] = "a.LOG_TIME <= ?"; $types .= 's'; $params[] = $date_to . " 23:59:59"; }

if (isOfficer()) {
    $where[] = "a.USER_ID = ?";
    $types .= 'i';
    $params[] = $userId;
} elseif (!isAdmin() && !isSuperAdmin()) {
    $accIds = getAccessibleUserIds($link);
    if (!empty($accIds)) {
        $where[] = "a.USER_ID IN (" . implode(',', array_map('intval', $accIds)) . ")";
    } else {
        $where[] = "a.USER_ID = ?";
        $types .= 'i';
        $params[] = $userId;
    }
}

$where_sql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

/* Total (unfiltered) count */
$count_sql = "SELECT COUNT(*) FROM " . tn('TBL_ACTIVITY_LOG') . " a LEFT JOIN " . tn('TBL_USERS') . " u ON a.USER_ID = u.ID";
$total = 0;
if ($stmt = mysqli_prepare($link, $count_sql)) {
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
}

/* Filtered count */
$filtered_sql = "SELECT COUNT(*) FROM " . tn('TBL_ACTIVITY_LOG') . " a LEFT JOIN " . tn('TBL_USERS') . " u ON a.USER_ID = u.ID $where_sql";
$filtered = 0;
if ($stmt = mysqli_prepare($link, $filtered_sql)) {
    if ($types !== '') {
        $bind_names = []; $bind_names[] = $types; foreach ($params as $p) $bind_names[] = $p;
        $refs = []; foreach ($bind_names as $k => $v) $refs[$k] = &$bind_names[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $filtered);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
}

/* Order column whitelist */
$allowedSort = ['LOG_TIME','USER_ID','ACTION_TYPE','LOG_ID'];
$sortMap = [
    0 => 'a.LOG_ID',
    1 => 'u.NAME',
    2 => 'a.ACTION_TYPE',
    3 => 'a.TARGET_TABLE',
    4 => 'a.ACTION_DETAILS',
    5 => 'a.LOG_TIME',
    6 => 'a.IP_ADDRESS',
];
$orderBy = isset($sortMap[$orderCol]) ? $sortMap[$orderCol] : 'a.LOG_TIME';
if (!in_array(str_replace('a.', '', $orderBy), $allowedSort, true)) {
    $orderBy = 'a.LOG_TIME';
}
$orderSql = 'ORDER BY ' . $orderBy . ' ' . $orderDir;

/* Data fetch */
$select_sql = "SELECT a.LOG_ID, a.USER_ID, u.NAME AS USER_NAME, u.ROLE AS USER_ROLE,
                      a.ACTION_TYPE, a.ACTION_DETAILS, a.AFFECTED_IDS, a.TARGET_TABLE, a.LOG_TIME, a.IP_ADDRESS
               FROM " . tn('TBL_ACTIVITY_LOG') . " a
               LEFT JOIN " . tn('TBL_USERS') . " u ON a.USER_ID = u.ID
               $where_sql
               $orderSql";

$dataParams = $params;
$dataTypes = $types;
if ($length > 0) {
    $select_sql .= ' LIMIT ?, ?';
    $dataParams[] = $start;
    $dataParams[] = $length;
    $dataTypes .= 'ii';
}

$dataStmt = mysqli_prepare($link, $select_sql);
if ($dataStmt === false) {
    $err = mysqli_error($link);
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed: ' . htmlspecialchars($err ?: 'Unknown database error')]);
    exit;
}
if ($dataTypes !== '') {
    $bind_names = []; $bind_names[] = $dataTypes; foreach ($dataParams as $p) $bind_names[] = $p;
    $refs = []; foreach ($bind_names as $k => $v) $refs[$k] = &$bind_names[$k];
    call_user_func_array([$dataStmt, 'bind_param'], $refs);
}
mysqli_stmt_execute($dataStmt);
$result = mysqli_stmt_get_result($dataStmt);

$rows = [];
while ($r = mysqli_fetch_assoc($result)) {
    $rows[] = [
        (int)$r['LOG_ID'],
        $r['USER_NAME'] ?: ('User #' . (int)$r['USER_ID']),
        $r['USER_ROLE'] ?: '—',
        $r['ACTION_TYPE'],
        $r['TARGET_TABLE'] ?: '—',
        $r['ACTION_DETAILS'] ?: '—',
        $r['LOG_TIME'],
        $r['IP_ADDRESS'] ?: '—',
    ];
}
mysqli_stmt_close($dataStmt);

header('Content-Type: application/json');
echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $total,
    'recordsFiltered' => $filtered,
    'data' => $rows
]);
