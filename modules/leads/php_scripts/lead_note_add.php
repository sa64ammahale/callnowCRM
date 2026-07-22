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
$note = trim((string)($_POST['note'] ?? ''));
if ($leadId <= 0 || $note === '') {
    api_error('Lead and note are required', 422);
}

$stmt = mysqli_prepare($link, "SELECT lead_id, assigned_to, team_id FROM " . tn('TBL_LEADS') . " WHERE lead_id = ? LIMIT 1");
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

$stmt = mysqli_prepare($link, "INSERT INTO " . tn('TBL_LEAD_NOTES') . " (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
mysqli_stmt_bind_param($stmt, 'iis', $leadId, USER_ID, $note);
if (!mysqli_stmt_execute($stmt)) {
    api_error('Failed to save note', 500);
}

$logLink = $link;
logActivity($logLink, USER_ID, 'REMARK', "Added note to lead #$leadId", (string)$leadId, tn('TBL_LEADS'));
api_send_json(['success' => true, 'message' => 'Note added']);
