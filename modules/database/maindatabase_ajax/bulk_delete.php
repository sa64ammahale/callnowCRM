<?php
require_once "../../../php_scripts/auth.php";

if (!isAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (!isset($_POST['ids']) || !is_array($_POST['ids']) || empty($_POST['ids'])) {
    echo json_encode(['success' => false]);
    exit;
}

$ids = array_map('intval', $_POST['ids']);
$placeholders = str_repeat('?,', count($ids)-1) . '?';

$sql = "DELETE FROM main_database WHERE ID IN ($placeholders)";
$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param($stmt, str_repeat('i', count($ids)), ...$ids);

echo json_encode(['success' => mysqli_stmt_execute($stmt)]);
?>
