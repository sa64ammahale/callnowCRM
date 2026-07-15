<?php
require_once '../../../php_scripts/auth.php';
require_once __DIR__ . '/../lead_common.php';

ensureLeadModuleSchema($link);

header('Content-Type: application/json; charset=utf-8');

$mobile = trim((string)($_GET['mobile'] ?? ''));
if ($mobile === '') {
    echo json_encode(['exists' => false]);
    exit;
}

$stmt = mysqli_prepare(
    $link,
    "SELECT ID, MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_OTHER_INFO
     FROM main_database
     WHERE MAINDATABASE_MOBILE = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 's', $mobile);
mysqli_stmt_execute($stmt);
$row = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

echo json_encode([
    'exists' => (bool)$row,
    'name' => $row['MAINDATABASE_NAME'] ?? '',
    'mobile' => $row['MAINDATABASE_MOBILE'] ?? '',
    'company' => $row['MAINDATABASE_COMPANY'] ?? '',
    'other_info' => $row['MAINDATABASE_OTHER_INFO'] ?? ''
]);
