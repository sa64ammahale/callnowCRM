<?php
require_once '../php_scripts/auth.php';
header('Content-Type: application/json');

$mobile = trim($_GET['mobile'] ?? '');
if (strlen($mobile) < 10) {
    echo json_encode(['exists' => false]);
    exit;
}

$result = mysqli_query($link, "SELECT MAINDATABASE_NAME, MAINDATABASE_COMPANY 
                              FROM MAIN_DATABASE 
                              WHERE MAINDATABASE_MOBILE = '" . mysqli_real_escape_string($link, $mobile) . "' 
                              LIMIT 1");

if ($row = mysqli_fetch_assoc($result)) {
    echo json_encode([
        'exists' => true,
        'name' => $row['MAINDATABASE_NAME'],
        'company' => $row['MAINDATABASE_COMPANY']
    ]);
} else {
    echo json_encode(['exists' => false]);
}
?>