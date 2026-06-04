<?php
require_once "../php_scripts/auth.php";


if (!$link) die(json_encode(['error' => 'DB Failed']));

$draw   = intval($_GET['draw']);
$start  = intval($_GET['start']);
$length = intval($_GET['length']);
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
$total = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) AS c FROM MAIN_DATABASE"))['c'];

// Filtered records count
$countQuery = "SELECT COUNT(*) AS c FROM MAIN_DATABASE 
               LEFT JOIN USERS u ON MAIN_DATABASE.MAINDATABASE_CALL_DIALED_USER = u.ID 
               $where";
$stmt = mysqli_prepare($link, $countQuery);
if ($params) mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
mysqli_stmt_execute($stmt);
$filtered = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];

// Data query
$sql = "SELECT MAIN_DATABASE.ID, 
               MAINDATABASE_MOBILE,
               MAINDATABASE_NAME,
               MAINDATABASE_COMPANY,
               MAINDATABASE_PACKAGE,
               MAINDATABASE_CALL_DIALED_STATUS,
               u.NAME AS assigned_name,
               DATE_FORMAT(MAINDATABASE_UPLOAD_DATETIME, '%d-%b-%Y %h:%i %p') AS upload_dt
        FROM MAIN_DATABASE 
        LEFT JOIN USERS u ON MAIN_DATABASE.MAINDATABASE_CALL_DIALED_USER = u.ID
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
        $row['ID'], // 0 – hidden ID
        $row['MAINDATABASE_MOBILE'],
        $row['MAINDATABASE_NAME'] ?: '-',
        $row['MAINDATABASE_COMPANY'] ?: '-',
        $row['MAINDATABASE_PACKAGE'] ?: '-',
        $row['MAINDATABASE_CALL_DIALED_STATUS'] ?: 'Not Called',
        $row['assigned_name'] ?: '<small class="text-muted">Not Assigned</small>',
        $row['upload_dt'],
        '<input type="checkbox" class="row-checkbox" value="'.$row['ID'].'">'
    ];
}

echo json_encode([
    "draw"            => $draw,
    "recordsTotal"    => $total,
    "recordsFiltered" => $filtered,
    "data"            => $data
]);
?>