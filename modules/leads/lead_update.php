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

$leadSql = "
    SELECT l.lead_id, l.cust_id, l.assigned_to, m.MAINDATABASE_MOBILE
    FROM LEADS_TABLE l
    LEFT JOIN main_database m ON m.ID = l.cust_id
    WHERE l.lead_id = ?
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
if (!isAdmin() && !empty($accessibleUserIds) && !in_array((int)$leadRow['assigned_to'], $accessibleUserIds, true)) {
    $_SESSION['success_message'] = 'You do not have access to update this lead.';
    $_SESSION['flash_class'] = 'danger';
    header("Location: lead_list.php");
    exit;
}

$transactionStarted = false;

try {
    if ($action === 'add_remark') {
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        $stmt = mysqli_prepare(
            $link,
            "UPDATE LEADS_TABLE SET remarks = ?, updated_by = ?, updated_at = NOW() WHERE lead_id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'sii', $remarks, USER_ID, $leadId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        logActivity($link, USER_ID, 'REMARK', "Updated remark on lead {$leadId}", (string)$leadId, 'LEADS_TABLE');
        $_SESSION['success_message'] = 'Remark updated successfully.';
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
        'next_followup_at' => normalizeFollowupDate($_POST['next_followup_at'] ?? null)
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

    if (!in_array($payload['lead_status_new'], $statusOptions, true)) {
        $payload['lead_status_new'] = 'LEAD';
    }
    if ($payload['login_mode'] !== '' && !in_array($payload['login_mode'], $loginModeOptions, true)) {
        $payload['login_mode'] = '';
    }

    $custIdExclude = (int)$leadRow['cust_id'];
    $stmtDup = mysqli_prepare($link, "SELECT ID FROM main_database WHERE MAINDATABASE_MOBILE = ? AND ID <> ? LIMIT 1");
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
        "UPDATE main_database
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
        "UPDATE LEADS_TABLE
         SET assigned_to = ?, login_date = ?, net_salary = ?, salary_account = ?, bank_name = ?,
             loan_amount = ?, loan_tenure = ?, promo_code = ?, login_bank_name = ?, lead_status_new = ?,
             login_mode = ?, loan_type = ?, loan_app_no = ?, login_location = ?, bank_rm_name = ?,
             bt_details = ?, remarks = ?, dsa_name = ?, next_followup_at = ?, lead_status = ?,
             updated_by = ?, updated_at = NOW()
         WHERE lead_id = ?"
    );
    $assignedTo = $payload['assigned_to'];
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
    $updatedBy = USER_ID;
    mysqli_stmt_bind_param(
        $stmt,
        'isssssssssssssssssssii',
        $assignedTo,
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
        'LEADS_TABLE'
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
