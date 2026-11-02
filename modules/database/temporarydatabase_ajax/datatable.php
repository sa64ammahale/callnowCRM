<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../../../config.php';

requirePermission('manage_database');

if (!function_exists('dt_fatal')) {
    function dt_fatal($msg) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Server Error', 'message' => $msg, 'data' => []]);
        exit;
    }
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
$start  = max(0, intval($_GET['start'] ?? 0));
$length = intval($_GET['length'] ?? 10);
$length = ($length <= 0) ? 250 : min($length, 2000);
$search = trim($_GET['search']['value'] ?? '');
$order  = $_GET['order'][0] ?? [];
$col    = (int)($order['column'] ?? 1);
$dir    = $order['dir'] ?? 'desc';

// Map DataTables column index → DB column (for TBL_TEMP)
$columns = [
    0 => 'ID',
    1 => 'ID',
    2 => 'CUST_MOBILE',
    3 => 'CUST_NAME',
    4 => 'CUST_COMPANY',
    5 => 'CUST_PACKAGE',
    6 => 'CALL_DIALED_STATUS',
    7 => 'TEMP_UPLOAD_DATETIME'
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
$totalRes = mysqli_query($link, "SELECT COUNT(*) AS c FROM " . tn('TBL_TEMP'));
$total = ($totalRes && $row = mysqli_fetch_assoc($totalRes)) ? (int)$row['c'] : 0;

// Filtered records count
if ($search === '') {
    $filtered = $total;
} else {
    $countQuery = "SELECT COUNT(*) AS c FROM " . tn('TBL_TEMP') . " $where";
    $stmt = mysqli_prepare($link, $countQuery);
    if ($params) mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    mysqli_stmt_execute($stmt);
    $filteredRes = mysqli_stmt_get_result($stmt);
    $filtered = ($filteredRes && $row = mysqli_fetch_assoc($filteredRes)) ? (int)$row['c'] : 0;
}

// Data query
$sql = "SELECT ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, 
               CUST_OTHER_INFO, TEMP_UPLOAD_DATETIME, CALL_DIALED_STATUS, 
               CALL_DIALED_TELECALLER, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD
        FROM " . tn('TBL_TEMP') . " 
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
    $nameEsc = htmlspecialchars($row['CUST_NAME'] ?? '', ENT_QUOTES, 'UTF-8');
    $compEsc = htmlspecialchars($row['CUST_COMPANY'] ?? '', ENT_QUOTES, 'UTF-8');
    $packEsc = htmlspecialchars($row['CUST_PACKAGE'] ?? '', ENT_QUOTES, 'UTF-8');
    $statEsc = htmlspecialchars($row['CALL_DIALED_STATUS'] ?? 'Not Called', ENT_QUOTES, 'UTF-8');

    $nameHtml = $nameEsc !== '' ? '<span class="d-inline-block text-truncate" style="max-width: 180px;" title="'.$nameEsc.'">'.$nameEsc.'</span>' : '-';
    $compHtml = $compEsc !== '' ? '<span class="d-inline-block text-truncate" style="max-width: 180px;" title="'.$compEsc.'">'.$compEsc.'</span>' : '-';

    $data[] = [
        '<input type="checkbox" class="row-checkbox" value="'.$row['ID'].'">', // 0 – checkbox
        $row['ID'],                                                            // 1 – ID
        htmlspecialchars($row['CUST_MOBILE'] ?? '', ENT_QUOTES, 'UTF-8'),      // 2 – Mobile
        $nameHtml,                                                             // 3 – Name
        $compHtml,                                                             // 4 – Company
        $packEsc ?: '-',                                                       // 5 – Package
        $statEsc ?: 'Not Called',                                              // 6 – Status
        $row['TEMP_UPLOAD_DATETIME'] ?? '-',                                   // 7 – Uploaded
        ''                                                                     // 8 – Actions
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
