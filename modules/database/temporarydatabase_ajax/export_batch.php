<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../../../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

requirePermission('export_data');
api_init();

$offset = intval($_POST['offset'] ?? 0);
$limit  = intval($_POST['limit'] ?? 10000);

$sql = "SELECT 
    CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, TEMP_UPLOAD_DATETIME, CALL_DIALED_STATUS,
    CALL_DIALED_TELECALLER, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD
    FROM " . tn('TBL_TEMP') . " 
    ORDER BY TEMP_UPLOAD_DATETIME DESC, ID DESC
    LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param($stmt, "ii", $limit, $offset);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$csv = "";
$first = true;
while ($row = mysqli_fetch_assoc($result)) {
    if ($first) {
        $csv .= implode(',', array_keys($row)) . "\n";
        $first = false;
    }
    $csv .= implode(',', array_map('strval', $row)) . "\n";
}

echo json_encode([
    'success' => true,
    'count'   => mysqli_num_rows($result),
    'csv'     => base64_encode($csv)
]);
?>
