<?php
require_once "../php_scripts/auth.php";


$offset = intval($_POST['offset'] ?? 0);
$limit  = intval($_POST['limit'] ?? 50000);

$sql = "SELECT 
    MAINDATABASE_MOBILE, MAINDATABASE_NAME, MAINDATABASE_COMPANY, 
    MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS,
    u.NAME AS assigned_to,
    DATE_FORMAT(MAINDATABASE_UPLOAD_DATETIME, '%d-%m-%Y %H:%i') AS uploaded
    FROM MAIN_DATABASE 
    LEFT JOIN USERS u ON MAINDATABASE_CALL_DIALED_USER = u.ID
    LIMIT $limit OFFSET $offset";

$result = mysqli_query($link, $sql);
if (!$result) {
    echo json_encode(['success' => false, 'message' => mysqli_error($link)]);
    exit;
}

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