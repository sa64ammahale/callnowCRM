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
$toUserId = (int)($_POST['to_user_id'] ?? 0);
$toTeamId = (int)($_POST['to_team_id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));

if ($leadId <= 0 || ($toUserId <= 0 && $toTeamId <= 0)) {
    api_error('Lead and a target (user or team) are required', 422);
}
if (!canPullToTray(['assigned_to' => 0])) {
    api_error('You are not allowed to assign leads', 403);
}

$stmt = mysqli_prepare($link, "SELECT lead_id, assigned_to, team_id, assigned_by FROM " . tn('TBL_LEADS') . " WHERE lead_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $leadId);
mysqli_stmt_execute($stmt);
$lead = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);
if (!$lead) {
    api_error('Lead not found', 404);
}

$fromUserId = (int)$lead['assigned_to'];
$fromTeamId = (int)$lead['team_id'];

$realToUser = $toUserId;
$realToTeam = $toTeamId;
if ($toUserId > 0) {
    $uStmt = mysqli_prepare($link, "SELECT ID, TEAM_ID FROM " . tn('TBL_USERS') . " WHERE ID = ? LIMIT 1");
    mysqli_stmt_bind_param($uStmt, 'i', $toUserId);
    mysqli_stmt_execute($uStmt);
    $u = mysqli_stmt_get_result($uStmt)->fetch_assoc();
    mysqli_stmt_close($uStmt);
    if (!$u) {
        api_error('Target user not found', 404);
    }
    $realToTeam = (int)$u['TEAM_ID'];
}

$stmt = mysqli_prepare($link, "UPDATE " . tn('TBL_LEADS') . " SET assigned_to = ?, team_id = ?, assigned_by = ?, updated_at = NOW() WHERE lead_id = ?");
mysqli_stmt_bind_param($stmt, 'iiii', $realToUser, $realToTeam, USER_ID, $leadId);
if (!mysqli_stmt_execute($stmt)) {
    api_error('Failed to assign lead', 500);
}

$ins = mysqli_prepare($link, "INSERT INTO " . tn('TBL_LEAD_ASSIGNMENTS') . " (lead_id, from_user_id, to_user_id, from_team_id, to_team_id, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
mysqli_stmt_bind_param($ins, 'iiiiis', $leadId, $fromUserId, $realToUser, $fromTeamId, $realToTeam, $reason);
mysqli_stmt_execute($ins);

logActivity($link, USER_ID, 'ASSIGN', "Reassigned lead #$leadId to user #$realToUser" . ($reason ? " ($reason)" : ''), (string)$leadId, tn('TBL_LEADS'));
api_send_json(['success' => true, 'message' => 'Lead assigned']);
