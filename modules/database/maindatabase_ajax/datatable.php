<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../../../config.php';

requirePermission('manage_database');

function dt_fatal($msg) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Server Error', 'message' => $msg, 'data' => []]);
    exit;
}
set_exception_handler(function ($e) { dt_fatal($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); });

header('Content-Type: application/json; charset=utf-8');
while (ob_get_level()) ob_end_clean();
if (!$link) {
    $error = 'Database connection failed';
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $error = 'Database connection failed: ' . mysqli_connect_error();
    }
    dt_fatal($error);
}

$draw   = intval($_GET['draw'] ?? 0);
$start  = intval($_GET['start'] ?? 0);
$length = intval($_GET['length'] ?? 10);
$length = min($length, 2000);
$search = $_GET['search']['value'] ?? '';
$order  = $_GET['order'][0] ?? [];
$col    = $order['column'] ?? 1;
$dir    = $order['dir'] ?? 'desc';

// Map DataTables column index → DB column
$columns = [
    0 => 'ID', // hidden checkbox
    1 => 'MAINDATABASE_MOBILE',
    2 => 'MAINDATABASE_NAME',
    3 => 'MAINDATABASE_COMPANY',
    4 => 'MAINDATABASE_PACKAGE',
    5 => 'MAINDATABASE_CALL_DIALED_STATUS',
    6 => 'u.NAME', // assigned user
    7 => 'MAINDATABASE_UPLOAD_DATETIME'
];

// Build WHERE
$where = "WHERE 1=1";
$params = [];
if ($search !== '') {
    $where .= " AND (MAINDATABASE_MOBILE LIKE ? 
                OR MAINDATABASE_NAME LIKE ? 
                OR MAINDATABASE_COMPANY LIKE ? 
                OR MAINDATABASE_OTHER_INFO LIKE ?)";
    $like = "%$search%";
    $params = array_fill(0, 4, $like);
}

// ORDER BY
$orderby = $columns[$col] ?? 'MAINDATABASE_UPLOAD_DATETIME';
$orderby .= " " . ($dir === 'asc' ? 'ASC' : 'DESC');

// Total records (without filter)
$totalRes = mysqli_query($link, "SELECT COUNT(*) AS c FROM " . tn('TBL_MAIN'));
$total = ($totalRes && $row = mysqli_fetch_assoc($totalRes)) ? (int)$row['c'] : 0;

// Filtered records count
$countQuery = "SELECT COUNT(*) AS c FROM " . tn('TBL_MAIN') . " 
               LEFT JOIN " . tn('TBL_USERS') . " u ON " . tn('TBL_MAIN') . ".MAINDATABASE_CALL_DIALED_USER = u.ID 
               $where";
$stmt = mysqli_prepare($link, $countQuery);
if ($params) mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
mysqli_stmt_execute($stmt);
$filteredRes = mysqli_stmt_get_result($stmt);
$filtered = ($filteredRes && $row = mysqli_fetch_assoc($filteredRes)) ? (int)$row['c'] : 0;

// Data query
$sql = "SELECT maindb.ID, 
               MAINDATABASE_MOBILE,
               MAINDATABASE_NAME,
               MAINDATABASE_COMPANY,
               MAINDATABASE_PACKAGE,
               MAINDATABASE_CALL_DIALED_STATUS,
               u.NAME AS assigned_name,
               DATE_FORMAT(MAINDATABASE_UPLOAD_DATETIME, '%d-%b-%Y %h:%i %p') AS upload_dt
        FROM " . tn('TBL_MAIN') . " maindb
        LEFT JOIN " . tn('TBL_USERS') . " u ON maindb.MAINDATABASE_CALL_DIALED_USER = u.ID
        $where
        ORDER BY $orderby
        LIMIT ?, ?";

$stmt = mysqli_prepare($link, $sql);
$params[] = $start;
$params[] = $length;
$types = str_repeat('s', count($params)-2) . 'ii';
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
if ($result === false) {
    dt_fatal('Data query failed: ' . mysqli_stmt_error($stmt));
}

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data[] = [
        '<input type="checkbox" class="row-checkbox" value="'.$row['ID'].'">', // 0 – checkbox
        $row['MAINDATABASE_MOBILE'],                                             // 1
        $row['MAINDATABASE_NAME'] ?: '-',                                        // 2
        $row['MAINDATABASE_COMPANY'] ?: '-',                                     // 3
        $row['MAINDATABASE_PACKAGE'] ?: '-',                                     // 4
        $row['MAINDATABASE_CALL_DIALED_STATUS'] ?: 'Not Called',                 // 5
        $row['assigned_name'] ?: '<small class="text-muted">Not Assigned</small>', // 6
        $row['upload_dt'],                                                       // 7
        $row['ID']                                                               // 8 – raw ID for JS
    ];
}

while (ob_get_level()) ob_end_clean();
echo json_encode([
    "draw"            => $draw,
    "recordsTotal"    => $total,
    "recordsFiltered" => $filtered,
    "data"            => $data
]);
?>
