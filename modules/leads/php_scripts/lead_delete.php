<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../lead_common.php';

header('Content-Type: application/json; charset=utf-8');

$allowedRoles = ['Admin', 'Manager', 'Supervisor'];
if (!in_array(USER_ROLE, $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

$leadId = (int)($_POST['lead_id'] ?? 0);
if ($leadId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid lead ID.']);
    exit;
}

$stmt = mysqli_prepare($link, "SELECT lead_id, assigned_to FROM LEADS_TABLE WHERE lead_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $leadId);
mysqli_stmt_execute($stmt);
$lead = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$lead) {
    echo json_encode(['ok' => false, 'message' => 'Lead not found.']);
    exit;
}

$accessibleUserIds = getAccessibleUserIds($link);
if (!isAdmin() && !empty($accessibleUserIds) && !in_array((int)$lead['assigned_to'], $accessibleUserIds, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Access denied.']);
    exit;
}

$stmt = mysqli_prepare($link, "DELETE FROM LEADS_TABLE WHERE lead_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $leadId);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

logActivity($link, USER_ID, 'DELETE', "Deleted lead #{$leadId}", (string)$leadId, 'LEADS_TABLE');

echo json_encode(['ok' => (bool)$ok, 'message' => $ok ? 'Lead deleted successfully.' : 'Delete failed.']);
