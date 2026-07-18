<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';

requirePermission('export_data');

header('Content-Type: application/json; charset=utf-8');

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (!isset($link) || !$link) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$offset = intval($_POST['offset'] ?? 0);
$limit  = intval($_POST['limit'] ?? 50000);

if ($limit <= 0 || $limit > 100000) {
    $limit = 50000;
}

if ($offset < 0) {
    $offset = 0;
}

$sql = "SELECT 
    md.ID, md.MAINDATABASE_MOBILE, md.MAINDATABASE_NAME, md.MAINDATABASE_COMPANY, 
    md.MAINDATABASE_PACKAGE, md.MAINDATABASE_OTHER_INFO, md.MAINDATABASE_CALL_DIALED_STATUS,
    u.NAME AS assigned_to,
    DATE_FORMAT(md.MAINDATABASE_UPLOAD_DATETIME, '%d-%m-%Y %H:%i') AS uploaded
    FROM main_database md
    LEFT JOIN users u ON md.MAINDATABASE_CALL_DIALED_USER = u.ID
    ORDER BY md.ID ASC
    LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($link, $sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Query preparation failed: ' . mysqli_error($link)]);
    exit;
}

mysqli_stmt_bind_param($stmt, "ii", $limit, $offset);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Query execution failed: ' . mysqli_error($link)]);
    exit;
}

$csv = "\xEF\xBB\xBF"; // UTF-8 BOM
$first = true;
$rowCount = 0;
while ($row = mysqli_fetch_assoc($result)) {
    if ($first) {
        $csv .= implode(',', array_keys($row)) . "\r\n";
        $first = false;
    }
    $fields = [];
    foreach ($row as $val) {
        $val = strval($val ?? '');
        if (strpos($val, ',') !== false || strpos($val, '"') !== false || strpos($val, "\n") !== false || strpos($val, "\r") !== false) {
            $fields[] = '"' . str_replace('"', '""', $val) . '"';
        } else {
            $fields[] = $val;
        }
    }
    $csv .= implode(',', $fields) . "\r\n";
    $rowCount++;
}

mysqli_stmt_close($stmt);

echo json_encode([
    'success' => true,
    'count'   => $rowCount,
    'csv'     => base64_encode($csv)
]);
?>
