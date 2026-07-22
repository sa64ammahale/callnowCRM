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

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    api_error('Follow-up id required', 422);
}

$fRow = mysqli_fetch_assoc(mysqli_query($link, "SELECT f.*, l.assigned_to, l.team_id FROM " . tn('TBL_LEAD_FOLLOWUPS') . " f JOIN " . tn('TBL_LEADS') . " l ON l.lead_id = f.lead_id WHERE f.id = $id"));
if (!$fRow) {
    api_error('Follow-up not found', 404);
}
if (!canEditLead(['assigned_to' => $fRow['assigned_to'], 'team_id' => $fRow['team_id']])) {
    api_error('This lead is not in your tray', 403);
}

$link->query("UPDATE " . tn('TBL_LEAD_FOLLOWUPS') . " SET status = 'DONE' WHERE id = $id");
$link->query("UPDATE " . tn('TBL_LEADS') . " SET followup_count = (SELECT COUNT(*) FROM " . tn('TBL_LEAD_FOLLOWUPS') . " WHERE lead_id = {$fRow['lead_id']} AND status = 'DONE'), last_contacted_at = NOW(), updated_at = NOW() WHERE lead_id = {$fRow['lead_id']}");

logActivity($link, USER_ID, 'UPDATE', "Completed follow-up #$id on lead #{$fRow['lead_id']}", (string)$fRow['lead_id'], tn('TBL_LEADS'));
api_send_json(['success' => true, 'message' => 'Follow-up marked done']);
