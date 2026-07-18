<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../../../config.php';

requirePermission('manage_database');

header('Content-Type: application/json; charset=utf-8');
if (!$link) {
    $error = 'Database connection failed';
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $error = 'Database connection failed: ' . mysqli_connect_error();
    }
    die(json_encode(['error' => 'DB Failed', 'message' => $error]));
}

$draw   = intval($_GET['draw'] ?? 0);
$start  = intval($_GET['start'] ?? 0);
$length = intval($_GET['length'] ?? 10);
$length = min($length, 2000);
$search = $_GET['search']['value'] ?? '';
$order  = $_GET['order'][0] ?? [];
$col    = $order['column'] ?? 1;
$dir    = $order['dir'] ?? 'desc';

// Map DataTables column index → DB column (for temporary_database)
$columns = [
    0 => 'ID', // hidden checkbox
    1 => 'CUST_MOBILE',
    2 => 'CUST_NAME',
    3 => 'CUST_COMPANY',
    4 => 'CUST_PACKAGE',
    5 => 'CALL_DIALED_STATUS',
    6 => 'TEMP_UPLOAD_DATETIME'
];

// Build WHERE
$where = "WHERE 1=1";
$params = [];
if ($search !== '') {
    $where .= " AND (CUST_MOBILE LIKE ? 
                OR CUST_NAME LIKE ? 
                OR CUST_COMPANY LIKE ? 
                OR CUST_PACKAGE LIKE ? 
                OR CUST_OTHER_INFO LIKE ?)";
    $like = "%$search%";
    $params = array_fill(0, 5, $like);
}

// ORDER BY
$orderby = $columns[$col] ?? 'TEMP_UPLOAD_DATETIME';
$orderby .= " " . ($dir === 'asc' ? 'ASC' : 'DESC');

// Total records (without filter)
$total = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) AS c FROM temporary_database"))['c'];

// Filtered records count
$countQuery = "SELECT COUNT(*) AS c FROM temporary_database $where";
$stmt = mysqli_prepare($link, $countQuery);
if ($params) mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
mysqli_stmt_execute($stmt);
$filtered = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];

// Data query
$sql = "SELECT ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, 
               CUST_OTHER_INFO, TEMP_UPLOAD_DATETIME, CALL_DIALED_STATUS, 
               CALL_DIALED_TELECALLER, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD
        FROM temporary_database 
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

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data[] = [
        '<input type="checkbox" class="row-checkbox" value="'.$row['ID'].'">', // 0 – checkbox
        $row['CUST_MOBILE'],                                                    // 1
        $row['CUST_NAME'] ?: '-',                                               // 2
        $row['CUST_COMPANY'] ?: '-',                                            // 3
        $row['CUST_PACKAGE'] ?: '-',                                            // 4
        $row['CALL_DIALED_STATUS'] ?: 'Not Called',                             // 5
        $row['TEMP_UPLOAD_DATETIME'],                                           // 6
        '',                                                                     // 7 – empty actions column
        $row['ID']                                                              // 8 – raw ID for JS
    ];
}

echo json_encode([
    "draw"            => $draw,
    "recordsTotal"    => $total,
    "recordsFiltered" => $filtered,
    "data"            => $data
]);
?>