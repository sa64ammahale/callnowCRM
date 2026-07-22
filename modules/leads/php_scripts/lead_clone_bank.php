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
$lead = mysqli_fetch_assoc(mysqli_query($link, "SELECT * FROM " . tn('TBL_LEADS') . " WHERE lead_id = $leadId"));
if (!$lead || !canViewLead($link, $lead)) {
    api_error('Lead not found or access denied', 404);
}
if (!canEditLead($lead)) {
    api_error('This lead is not in your tray', 403);
}

// Resolve Back Office team for assignment
$boTeam = mysqli_fetch_assoc(mysqli_query($link, "SELECT ID FROM " . tn('TBL_TEAMS') . " WHERE NAME = 'back_office' LIMIT 1"));
$boTeamId = $boTeam ? (int)$boTeam['ID'] : 0;
$boUser = 0;
if ($boTeamId > 0) {
    $bu = mysqli_fetch_assoc(mysqli_query($link, "SELECT ID FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = $boTeamId AND STATUS = 'Active' LIMIT 1"));
    if ($bu) $boUser = (int)$bu['ID'];
}

$cols = ['cust_id','NAME','MOBILE','COMPANY_NAME','OTHER_INFO','CALL_STATUS','PIPELINE_STATUS','NOTE',
    'lead_status','lead_stage','priority','created_by','login_date','net_salary','salary_account',
    'bank_name','loan_amount','loan_tenure','ADDED_BY','promo_code','login_bank_name','login_mode',
    'loan_type','loan_app_no','login_location','bank_rm_name','bt_details','dsa_name','remarks',
    'lost_reason','converted_at'];

$colList = '`' . implode('`,`', $cols) . '`';
$placeholders = rtrim(str_repeat('?,', count($cols)), ',');
$vals = [];
foreach ($cols as $c) {
    $vals[] = $lead[$c];
}

$stmt = mysqli_prepare($link, "INSERT INTO " . tn('TBL_LEADS') . " ($colList, lead_status_new, login_status, parent_lead_id, assigned_to, team_id, assigned_by, created_at, updated_at) VALUES ($placeholders, 'LOGIN', 'PENDING', ?, ?, ?, ?, NOW(), NOW())");
// Build types: all copied cols are strings except cust_id/created_by which may be int/null — treat as string-safe via 's' with nullable
$types = str_repeat('s', count($cols)) . 'iiiii';
$params = array_merge($vals, [$leadId, $boUser, $boTeamId, USER_ID]);
mysqli_stmt_bind_param($stmt, $types, ...$params);
if (!mysqli_stmt_execute($stmt)) {
    api_error('Failed to clone lead for another bank', 500);
}
$newId = mysqli_insert_id($link);

logActivity($link, USER_ID, 'INSERT', "Cloned lead #$leadId into new bank login #$newId", (string)$newId, tn('TBL_LEADS'));
api_send_json(['success' => true, 'new_lead_id' => $newId, 'message' => "Lead cloned for another bank login (#$newId)"]);
