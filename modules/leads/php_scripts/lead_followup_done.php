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

$stmt = mysqli_prepare($link, "SELECT f.id, f.lead_id, l.assigned_to, l.team_id FROM " . tn('TBL_LEAD_FOLLOWUPS') . " f JOIN " . tn('TBL_LEADS') . " l ON l.lead_id = f.lead_id WHERE f.id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$fRow = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);
if (!$fRow) {
    api_error('Follow-up not found', 404);
}
if (!canEditLead(['assigned_to' => $fRow['assigned_to'], 'team_id' => $fRow['team_id']])) {
    api_error('This lead is not in your tray', 403);
}

$upd = mysqli_prepare($link, "UPDATE " . tn('TBL_LEAD_FOLLOWUPS') . " SET status = 'DONE' WHERE id = ?");
mysqli_stmt_bind_param($upd, 'i', $id);
mysqli_stmt_execute($upd);

$leadId = (int)$fRow['lead_id'];
$cntStmt = mysqli_prepare($link, "SELECT COUNT(*) FROM " . tn('TBL_LEAD_FOLLOWUPS') . " WHERE lead_id = ? AND status = 'DONE'");
mysqli_stmt_bind_param($cntStmt, 'i', $leadId);
mysqli_stmt_execute($cntStmt);
$doneCount = (int)mysqli_fetch_row(mysqli_stmt_get_result($cntStmt))[0];
mysqli_stmt_close($cntStmt);

$leadUpd = mysqli_prepare($link, "UPDATE " . tn('TBL_LEADS') . " SET followup_count = ?, last_contacted_at = NOW(), updated_at = NOW() WHERE lead_id = ?");
mysqli_stmt_bind_param($leadUpd, 'ii', $doneCount, $leadId);
mysqli_stmt_execute($leadUpd);

logActivity($link, USER_ID, 'UPDATE', "Completed follow-up #$id on lead #{$fRow['lead_id']}", (string)$fRow['lead_id'], tn('TBL_LEADS'));
api_send_json(['success' => true, 'message' => 'Follow-up marked done']);
