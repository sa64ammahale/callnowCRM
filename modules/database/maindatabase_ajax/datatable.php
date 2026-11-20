<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../../../php_scripts/phone_helper.php';

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
$contactScope = getHierarchyContactFilterSQL($link, (int)USER_ID, USER_ROLE, 'm', defined('USER_TEAM_ID') ? (int)USER_TEAM_ID : null);
$where = "WHERE $contactScope";
$params = [];
if ($search !== '') {
    $where .= " AND (m.MAINDATABASE_MOBILE LIKE ? 
                OR m.MAINDATABASE_NAME LIKE ? 
                OR m.MAINDATABASE_COMPANY LIKE ? 
                OR m.MAINDATABASE_OTHER_INFO LIKE ?)";
    $like = "%$search%";
    $params = array_fill(0, 4, $like);
}

// ORDER BY
$orderby = $columns[$col] ?? 'm.MAINDATABASE_UPLOAD_DATETIME';
if (strpos($orderby, 'm.') !== 0 && strpos($orderby, 'u.') !== 0) {
    $orderby = 'm.' . $orderby;
}
$orderby .= " " . ($dir === 'asc' ? 'ASC' : 'DESC');

// Total records (without filter)
$totalRes = mysqli_query($link, "SELECT COUNT(*) AS c FROM " . tn('TBL_MAIN') . " m WHERE $contactScope");
$total = ($totalRes && $row = mysqli_fetch_assoc($totalRes)) ? (int)$row['c'] : 0;

// Filtered records count
if ($search === '') {
    $filtered = $total;
} else {
    $countQuery = "SELECT COUNT(*) AS c FROM " . tn('TBL_MAIN') . " m 
                   LEFT JOIN " . tn('TBL_USERS') . " u ON m.MAINDATABASE_CALL_DIALED_USER = u.ID 
                   $where";
    $stmt = mysqli_prepare($link, $countQuery);
    if ($params) mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    mysqli_stmt_execute($stmt);
    $filteredRes = mysqli_stmt_get_result($stmt);
    $filtered = ($filteredRes && $row = mysqli_fetch_assoc($filteredRes)) ? (int)$row['c'] : 0;
}

// Data query
$sql = "SELECT m.ID, 
               m.MAINDATABASE_MOBILE,
               m.MAINDATABASE_NAME,
               m.MAINDATABASE_COMPANY,
               m.MAINDATABASE_PACKAGE,
               m.MAINDATABASE_CALL_DIALED_STATUS,
               u.NAME AS assigned_name,
               DATE_FORMAT(m.MAINDATABASE_UPLOAD_DATETIME, '%d-%b-%Y %h:%i %p') AS upload_dt
        FROM " . tn('TBL_MAIN') . " m
        LEFT JOIN " . tn('TBL_USERS') . " u ON m.MAINDATABASE_CALL_DIALED_USER = u.ID
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
    $nameEsc = htmlspecialchars($row['MAINDATABASE_NAME'] ?? '', ENT_QUOTES, 'UTF-8');
    $compEsc = htmlspecialchars($row['MAINDATABASE_COMPANY'] ?? '', ENT_QUOTES, 'UTF-8');
    $packEsc = htmlspecialchars($row['MAINDATABASE_PACKAGE'] ?? '', ENT_QUOTES, 'UTF-8');
    $statEsc = htmlspecialchars($row['MAINDATABASE_CALL_DIALED_STATUS'] ?? 'Not Called', ENT_QUOTES, 'UTF-8');
    $userEsc = $row['assigned_name'] ? htmlspecialchars($row['assigned_name'], ENT_QUOTES, 'UTF-8') : '<small class="text-muted">Not Assigned</small>';

    $nameHtml = $nameEsc !== '' ? '<span class="d-inline-block text-truncate" style="max-width: 180px;" title="'.$nameEsc.'">'.$nameEsc.'</span>' : '-';
    $compHtml = $compEsc !== '' ? '<span class="d-inline-block text-truncate" style="max-width: 180px;" title="'.$compEsc.'">'.$compEsc.'</span>' : '-';

    $mdCanView = canViewFullPhone();
    $mdMobile = $row['MAINDATABASE_MOBILE'] ?? '';
    $mdMobileHtml = '';
    if ($mdCanView || empty($mdMobile)) {
        $mdMobileHtml = !empty($mdMobile)
            ? '<a href="tel:' . htmlspecialchars($mdMobile, ENT_QUOTES, 'UTF-8') . '" class="text-decoration-none font-monospace">' . htmlspecialchars($mdMobile, ENT_QUOTES, 'UTF-8') . '</a>'
            : '<span class="text-muted font-monospace">—</span>';
    } else {
        $mdMobileHtml = '<span class="phone-masked d-inline-block font-monospace" style="cursor:pointer;" data-record-id="' . (int)$row['ID'] . '" data-source="MAIN" data-table="' . tn('TBL_MAIN') . '" title="Click to reveal number">' . mask_phone($mdMobile) . '</span> <button type="button" class="btn btn-sm btn-outline-secondary phone-reveal-btn" data-record-id="' . (int)$row['ID'] . '" data-source="MAIN" data-table="' . tn('TBL_MAIN') . '" title="Reveal number"><i class="bi bi-eye"></i></button>';
    }

    $data[] = [
        '<input type="checkbox" class="row-checkbox" value="'.$row['ID'].'">', // 0 – checkbox
        $mdMobileHtml, // 1
        $nameHtml,                                                             // 2
        $compHtml,                                                             // 3
        $packEsc ?: '-',                                                       // 4
        $statEsc ?: 'Not Called',                                              // 5
        $userEsc,                                                              // 6
        $row['upload_dt'] ?? '-',                                              // 7
        $row['ID']                                                             // 8 – raw ID for JS
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
