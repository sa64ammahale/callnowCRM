<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../php_scripts/team_auth.php';
require_once __DIR__ . '/lead_common.php';

requirePermission('manage_leads');

ensureLeadModuleSchema($link);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['lead_id'])) {
    header("Location: lead_list.php");
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['success_message'] = 'Invalid session token';
    $_SESSION['flash_class'] = 'danger';
    header("Location: lead_list.php");
    exit;
}

$leadId = (int)$_POST['lead_id'];
$action = trim((string)($_POST['action'] ?? 'save_lead'));

$accessibleUserIds = getAccessibleUserIds($link);
$accessWhere = '';
if (!isAdmin() && !isSuperAdmin() && !empty($accessibleUserIds)) {
    $accessWhere = ' AND l.assigned_to IN (' . implode(',', array_map('intval', $accessibleUserIds)) . ')';
}

$leadSql = "
    SELECT l.lead_id, l.cust_id, l.assigned_to, l.team_id, m.MAINDATABASE_MOBILE
    FROM " . tn('TBL_LEADS') . " l
    LEFT JOIN " . tn('TBL_MAIN') . " m ON m.ID = l.cust_id
    WHERE l.lead_id = ?{$accessWhere}
    LIMIT 1
";
$stmt = mysqli_prepare($link, $leadSql);
mysqli_stmt_bind_param($stmt, 'i', $leadId);
mysqli_stmt_execute($stmt);
$leadRow = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$leadRow) {
    $_SESSION['success_message'] = 'Lead not found.';
    header("Location: lead_list.php");
    exit;
}

$accessibleUserIds = getAccessibleUserIds($link);
$leadForCheck = ['assigned_to' => (int)$leadRow['assigned_to'], 'team_id' => (int)($leadRow['team_id'] ?? 0)];
if (!canEditLead($leadForCheck)) {
    $_SESSION['success_message'] = 'This lead is not in your tray. Pull it into your tray first.';
    $_SESSION['flash_class'] = 'danger';
    header("Location: lead_view.php?id={$leadId}");
    exit;
}

$transactionStarted = false;

try {
    if ($action === 'add_remark') {
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        if ($remarks !== '') {
            $stmt = mysqli_prepare(
                $link,
                "INSERT INTO " . tn('TBL_LEAD_NOTES') . " (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())"
            );
            mysqli_stmt_bind_param($stmt, 'iis', $leadId, USER_ID, $remarks);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            logActivity($link, USER_ID, 'REMARK', "Added remark on lead {$leadId}", (string)$leadId, tn('TBL_LEADS'));
        }
        $_SESSION['success_message'] = 'Remark added successfully.';
        $_SESSION['flash_class'] = 'success';
        header("Location: lead_view.php?id={$leadId}");
        exit;
    }

    $statusOptions = leadStatusOptions();
    $loginModeOptions = loginModeOptions();

    $payload = [
        'login_date' => normalizeLeadDate($_POST['login_date'] ?? null),
        'customer_name' => trim((string)($_POST['customer_name'] ?? '')),
        'mobile' => trim((string)($_POST['mobile'] ?? '')),
        'company_name' => trim((string)($_POST['company_name'] ?? '')),
        'net_salary' => trim((string)($_POST['net_salary'] ?? '')),
        'salary_account' => trim((string)($_POST['salary_account'] ?? '')),
        'bank_name' => trim((string)($_POST['bank_name'] ?? '')),
        'loan_amount' => trim((string)($_POST['loan_amount'] ?? '')),
        'loan_tenure' => trim((string)($_POST['loan_tenure'] ?? '')),
        'promo_code' => trim((string)($_POST['promo_code'] ?? '')),
        'assigned_to' => (int)($_POST['assigned_to'] ?? 0),
        'login_bank_name' => trim((string)($_POST['login_bank_name'] ?? '')),
        'lead_status_new' => trim((string)($_POST['lead_status_new'] ?? 'LEAD')),
        'login_mode' => trim((string)($_POST['login_mode'] ?? '')),
        'loan_type' => trim((string)($_POST['loan_type'] ?? '')),
        'loan_app_no' => trim((string)($_POST['loan_app_no'] ?? '')),
        'login_location' => trim((string)($_POST['login_location'] ?? '')),
        'bank_rm_name' => trim((string)($_POST['bank_rm_name'] ?? '')),
        'bt_details' => trim((string)($_POST['bt_details'] ?? '')),
        'remarks' => trim((string)($_POST['remarks'] ?? '')),
        'dsa_name' => trim((string)($_POST['dsa_name'] ?? '')),
        'other_info' => trim((string)($_POST['other_info'] ?? '')),
        'next_followup_at' => normalizeFollowupDate($_POST['next_followup_at'] ?? null),
        'login_status' => trim((string)($_POST['login_status'] ?? '')),
        'rework_flag' => (int)($_POST['rework_flag'] ?? 0),
        'rework_stage' => trim((string)($_POST['rework_stage'] ?? ''))
    ];

    if ($payload['customer_name'] === '' || $payload['mobile'] === '') {
        throw new RuntimeException('Customer name and mobile number are required.');
    }
    if (!preg_match('/^[0-9]{10,15}$/', $payload['mobile'])) {
        throw new RuntimeException('Enter a valid mobile number.');
    }
    if ($payload['assigned_to'] <= 0) {
        throw new RuntimeException('Please select an assigned employee.');
    }
    if (!canEditLeadAssignment()) {
        $payload['assigned_to'] = (int)$leadRow['assigned_to'];
    } elseif (!canAssignLeadToUserId($link, $payload['assigned_to'])) {
        throw new RuntimeException('You do not have permission to assign this lead to the selected employee.');
    }

    $payload['team_id'] = 0;
    if ($payload['assigned_to'] > 0) {
        $tu = mysqli_fetch_assoc(mysqli_query($link, "SELECT TEAM_ID FROM " . tn('TBL_USERS') . " WHERE ID = {$payload['assigned_to']}"));
        if ($tu) {
            $payload['team_id'] = (int)$tu['TEAM_ID'];
        }
    }

    if (!in_array($payload['lead_status_new'], $statusOptions, true)) {
        $payload['lead_status_new'] = 'LEAD';
    }
    if ($payload['login_mode'] !== '' && !in_array($payload['login_mode'], $loginModeOptions, true)) {
        $payload['login_mode'] = '';
    }

    // Stage gating for the loan workflow
    $stageOrder = array_flip(['LEAD', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT']);
    $newStage = $payload['lead_status_new'];
    $prevStage = $leadRow['lead_status_new'] ?? 'LEAD';
    $newRank = $stageOrder[$newStage] ?? 0;
    $prevRank = $stageOrder[$prevStage] ?? 0;
    // Cannot advance past INTERNAL_UNDERWRITING unless already there or beyond
    if ($newRank > $stageOrder['INTERNAL_UNDERWRITING'] && $prevRank < $stageOrder['INTERNAL_UNDERWRITING']) {
        throw new RuntimeException('Complete Internal Underwriting before Bank Login.');
    }
    // BANK_UNDERWRITING requires successful bank login
    if ($newStage === 'BANK_UNDERWRITING' && $payload['login_status'] !== 'SUCCESS') {
        throw new RuntimeException('Set Login Status to Success before Bank Underwriting.');
    }
    // Validate login_status / rework_stage enums
    if ($payload['login_status'] !== '' && !in_array($payload['login_status'], ['PENDING', 'SUCCESS', 'REWORK_PENDING', 'REJECTED'], true)) {
        $payload['login_status'] = '';
    }
    if ($payload['rework_stage'] !== '' && !in_array($payload['rework_stage'], ['INTERNAL', 'BANK'], true)) {
        $payload['rework_stage'] = '';
    }
    if ($payload['rework_flag'] !== 1) {
        $payload['rework_flag'] = 0;
        $payload['rework_stage'] = '';
    }

    $custIdExclude = (int)$leadRow['cust_id'];
    $stmtDup = mysqli_prepare($link, "SELECT ID FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = ? AND ID <> ? LIMIT 1");
    mysqli_stmt_bind_param($stmtDup, "si", $payload['mobile'], $custIdExclude);
    mysqli_stmt_execute($stmtDup);
    $resDup = mysqli_stmt_get_result($stmtDup);
    $checkDuplicate = mysqli_fetch_assoc($resDup);
    mysqli_stmt_close($stmtDup);
    if ($checkDuplicate) {
        throw new RuntimeException('Another customer already uses this mobile number in the main database.');
    }

    mysqli_begin_transaction($link);
    $transactionStarted = true;

    $stmt = mysqli_prepare(
        $link,
        "UPDATE " . tn('TBL_MAIN') . "
         SET MAINDATABASE_NAME = ?, MAINDATABASE_MOBILE = ?, MAINDATABASE_COMPANY = ?, MAINDATABASE_OTHER_INFO = ?
         WHERE ID = ?"
    );
    $mainCustomerName = $payload['customer_name'];
    $mainMobile = $payload['mobile'];
    $mainCompany = $payload['company_name'];
    $mainOtherInfo = $payload['other_info'];
    $mainCustId = (int)$leadRow['cust_id'];
    mysqli_stmt_bind_param(
        $stmt,
        'ssssi',
        $mainCustomerName,
        $mainMobile,
        $mainCompany,
        $mainOtherInfo,
        $mainCustId
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $legacyStatus = match ($payload['lead_status_new']) {
        'DISBURSED' => 'Converted',
        'FOLLOWUP' => 'Follow_Up',
        'REJECT' => 'Lost',
        default => 'New',
    };
    $stmt = mysqli_prepare(
        $link,
        "UPDATE " . tn('TBL_LEADS') . "
         SET assigned_to = ?, team_id = ?, login_date = ?, net_salary = ?, salary_account = ?, bank_name = ?,
              loan_amount = ?, loan_tenure = ?, promo_code = ?, login_bank_name = ?, lead_status_new = ?,
              login_mode = ?, loan_type = ?, loan_app_no = ?, login_location = ?, bank_rm_name = ?,
              bt_details = ?, remarks = ?, dsa_name = ?, next_followup_at = ?, lead_status = ?,
              login_status = ?, rework_flag = ?, rework_stage = ?, updated_by = ?, updated_at = NOW()
          WHERE lead_id = ?"
    );
    $assignedTo = $payload['assigned_to'];
    $teamId = $payload['team_id'];
    $loginDate = $payload['login_date'];
    $netSalary = $payload['net_salary'];
    $salaryAccount = $payload['salary_account'];
    $bankName = $payload['bank_name'];
    $loanAmount = $payload['loan_amount'];
    $loanTenure = $payload['loan_tenure'];
    $promoCode = $payload['promo_code'];
    $loginBankName = $payload['login_bank_name'];
    $leadStatusNew = $payload['lead_status_new'];
    $loginMode = $payload['login_mode'];
    $loanType = $payload['loan_type'];
    $loanAppNo = $payload['loan_app_no'];
    $loginLocation = $payload['login_location'];
    $bankRmName = $payload['bank_rm_name'];
    $btDetails = $payload['bt_details'];
    $remarks = $payload['remarks'];
    $dsaName = $payload['dsa_name'];
    $nextFollowupAt = $payload['next_followup_at'];
    $loginStatus = $payload['login_status'];
    $reworkFlag = $payload['rework_flag'];
    $reworkStage = $payload['rework_stage'];
    $updatedBy = USER_ID;
    mysqli_stmt_bind_param(
        $stmt,
        'iisssssssssssssssssssisiii',
        $assignedTo,
        $teamId,
        $loginDate,
        $netSalary,
        $salaryAccount,
        $bankName,
        $loanAmount,
        $loanTenure,
        $promoCode,
        $loginBankName,
        $leadStatusNew,
        $loginMode,
        $loanType,
        $loanAppNo,
        $loginLocation,
        $bankRmName,
        $btDetails,
        $remarks,
        $dsaName,
        $nextFollowupAt,
        $legacyStatus,
        $loginStatus,
        $reworkFlag,
        $reworkStage,
        $updatedBy,
        $leadId
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    logActivity(
        $link,
        USER_ID,
        'UPDATE',
        "Updated lead {$leadId} with status {$payload['lead_status_new']}",
        (string)$leadId,
        tn('TBL_LEADS')
    );

    mysqli_commit($link);
    $transactionStarted = false;
    $_SESSION['success_message'] = 'Lead updated successfully.';
    $_SESSION['flash_class'] = 'success';
} catch (Throwable $e) {
    if ($transactionStarted) {
        mysqli_rollback($link);
    }
    $_SESSION['success_message'] = 'Update failed: ' . $e->getMessage();
    $_SESSION['flash_class'] = 'danger';
}

header("Location: lead_view.php?id={$leadId}");
exit;
