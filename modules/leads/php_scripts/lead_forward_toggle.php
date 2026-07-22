<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../lead_common.php';
api_init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Invalid request', 405);
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    api_error('Invalid session token', 403);
}

$leadId = (int)($_POST['lead_id'] ?? 0);
$stmt = mysqli_prepare($link, "SELECT lead_id, assigned_to, team_id, forwarded_flag FROM " . tn('TBL_LEADS') . " WHERE lead_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $leadId);
mysqli_stmt_execute($stmt);
$lead = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);
if (!$lead || !canViewLead($link, $lead)) {
    api_error('Lead not found or access denied', 404);
}
if (!canEditLead($lead)) {
    api_error('This lead is not in your tray', 403);
}

$newVal = ((int)$lead['forwarded_flag'] === 1) ? 0 : 1;
$upd = mysqli_prepare($link, "UPDATE " . tn('TBL_LEADS') . " SET forwarded_flag = ?, updated_at = NOW() WHERE lead_id = ?");
mysqli_stmt_bind_param($upd, 'ii', $newVal, $leadId);
mysqli_stmt_execute($upd);

$msg = $newVal ? 'Marked as forwarded to Back Office' : 'Forwarded flag cleared';
logActivity($link, USER_ID, 'UPDATE', "$msg (lead #$leadId)", (string)$leadId, tn('TBL_LEADS'));
api_send_json(['success' => true, 'forwarded_flag' => $newVal, 'message' => $msg]);
