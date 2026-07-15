<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../../../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (!isAdmin() && !isManager()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin or Manager access required']);
    exit;
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'No IDs provided']);
    exit;
}

$ids = array_map('intval', $ids);
$placeholders = implode(',', array_fill(0, count($ids), '?'));

$sql = "DELETE FROM temporary_database WHERE ID IN ($placeholders)";
$stmt = mysqli_prepare($link, $sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . mysqli_error($link)]);
    exit;
}

$types = str_repeat('i', count($ids));
mysqli_stmt_bind_param($stmt, $types, ...$ids);

$ok = mysqli_stmt_execute($stmt);
$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);

if ($ok) {
    echo json_encode(['success' => true, 'affected' => $affected]);
} else {
    echo json_encode(['success' => false, 'error' => 'Execute failed: ' . mysqli_stmt_error($stmt)]);
}
?>