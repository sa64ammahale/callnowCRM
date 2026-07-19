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
$lead = mysqli_fetch_assoc(mysqli_query($link, "SELECT lead_id, assigned_to, team_id, sent_backward_flag FROM TBL_LEADS WHERE lead_id = $leadId"));
if (!$lead || !canViewLead($link, $lead)) {
    api_error('Lead not found or access denied', 404);
}
if (!canEditLead($lead)) {
    api_error('This lead is not in your tray', 403);
}

$newVal = ((int)$lead['sent_backward_flag'] === 1) ? 0 : 1;
$link->query("UPDATE TBL_LEADS SET sent_backward_flag = $newVal, updated_at = NOW() WHERE lead_id = $leadId");

$msg = $newVal ? 'Marked as sent backward to previous owner' : 'Send-backward flag cleared';
logActivity($link, USER_ID, 'UPDATE', "$msg (lead #$leadId)", (string)$leadId, 'TBL_LEADS');
api_send_json(['success' => true, 'sent_backward_flag' => $newVal, 'message' => $msg]);
