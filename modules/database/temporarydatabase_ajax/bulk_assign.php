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
$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

if (!is_array($ids) || count($ids) === 0 || $user_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

$ids = array_map('intval', $ids);
$placeholders = implode(',', array_fill(0, count($ids), '?'));

$sql = "UPDATE temporary_database SET CALL_DIALED_TELECALLER = ? WHERE ID IN ($placeholders)";
$stmt = mysqli_prepare($link, $sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . mysqli_error($link)]);
    exit;
}

$types = 'i' . str_repeat('i', count($ids));
$bindParams = array_merge([$types, $user_id], $ids);
$refs = [];
foreach ($bindParams as $k => $v) $refs[$k] = &$bindParams[$k];

call_user_func_array([$stmt, 'bind_param'], $refs);
$ok = mysqli_stmt_execute($stmt);
$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);

if ($ok) {
    logActivity($link, 'ASSIGN', "Assigned $affected records to user $user_id");
    echo json_encode(['success' => true, 'affected' => $affected]);
} else {
    echo json_encode(['success' => false, 'error' => 'Execute failed: ' . mysqli_stmt_error($stmt)]);
}
?>