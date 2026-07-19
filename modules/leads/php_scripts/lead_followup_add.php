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
$at = trim((string)($_POST['followup_at'] ?? ''));
$note = trim((string)($_POST['note'] ?? ''));
if ($leadId <= 0 || $at === '') {
    api_error('Lead and follow-up date are required', 422);
}

$ts = strtotime($at);
if ($ts === false) {
    api_error('Invalid follow-up date', 422);
}
$atSql = date('Y-m-d H:i:s', $ts);

$lead = mysqli_fetch_assoc(mysqli_query($link, "SELECT lead_id, assigned_to, team_id FROM TBL_LEADS WHERE lead_id = $leadId"));
if (!$lead || !canViewLead($link, $lead)) {
    api_error('Lead not found or access denied', 404);
}
if (!canEditLead($lead)) {
    api_error('This lead is not in your tray', 403);
}

$stmt = mysqli_prepare($link, "INSERT INTO TBL_LEAD_FOLLOWUPS (lead_id, user_id, followup_at, note, status, created_at) VALUES (?, ?, ?, ?, 'OPEN', NOW())");
mysqli_stmt_bind_param($stmt, 'iiss', $leadId, USER_ID, $atSql, $note);
if (!mysqli_stmt_execute($stmt)) {
    api_error('Failed to schedule follow-up', 500);
}

$link->query("UPDATE TBL_LEADS SET next_followup_at = '$atSql', updated_at = NOW() WHERE lead_id = $leadId");

logActivity($link, USER_ID, 'UPDATE', "Scheduled follow-up for lead #$leadId", (string)$leadId, 'TBL_LEADS');
api_send_json(['success' => true, 'message' => 'Follow-up scheduled']);
