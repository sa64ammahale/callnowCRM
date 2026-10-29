<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../php_scripts/team_auth.php';
require_once __DIR__ . '/lead_common.php';

requirePermission('manage_leads');

ensureLeadModuleSchema($link);
mysqli_set_charset($link, 'utf8mb4');

$statusOptions = leadStatusOptions();
$loginModeOptions = loginModeOptions();
$loanTypeOptions = loanTypeOptions();
$bankOptions = indianBankOptions();

$TBL_USERS = getLeadAssignableTBL_USERS($link);

$form = [
    'login_date' => '',
    'name' => '',
    'mobile' => '',
    'company' => '',
    'net_salary' => '',
    'salary_account' => '',
    'bank_name' => '',
    'loan_amount' => '',
    'loan_tenure' => '',
    'promo_code' => '',
    'assigned_to' => (string)USER_ID,
    'login_bank_name' => '',
    'lead_status_new' => 'LEAD',
    'login_mode' => '',
    'loan_type' => '',
    'loan_app_no' => '',
    'login_location' => '',
    'bank_rm_name' => '',
    'bt_details' => '',
    'remarks' => '',
    'dsa_name' => '',
    'other_info' => '',
    'next_followup_at' => ''
];

$success = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid session token";
    } else {
        foreach ($form as $key => $value) {
            $form[$key] = trim((string)($_POST[$key] ?? $value));
        }

        $form['lead_status_new'] = in_array($form['lead_status_new'], $statusOptions, true) ? $form['lead_status_new'] : 'LEAD';
        $form['login_mode'] = in_array($form['login_mode'], $loginModeOptions, true) ? $form['login_mode'] : '';

        $assignedTo = (int)$form['assigned_to'];
        $loginDate = normalizeLeadDate($form['login_date']);
        $nextFollowup = normalizeFollowupDate($form['next_followup_at']);

        if ($form['name'] === '' || $form['mobile'] === '') {
            $error = 'Customer name and mobile number are required.';
        } elseif (!preg_match('/^[0-9]{10,15}$/', $form['mobile'])) {
            $error = 'Enter a valid mobile number.';
        } elseif ($assignedTo <= 0) {
            $error = 'Please choose an employee for assignment.';
        } elseif (!canAssignLeadToUserId($link, $assignedTo)) {
            $error = 'You do not have permission to assign this lead to the selected employee.';
        } else {
            mysqli_begin_transaction($link);

            try {
                $stmtDup = mysqli_prepare($link, "SELECT ID FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = ? LIMIT 1");
                mysqli_stmt_bind_param($stmtDup, "s", $form['mobile']);
                mysqli_stmt_execute($stmtDup);
                $resDup = mysqli_stmt_get_result($stmtDup);
                $existingMain = mysqli_fetch_assoc($resDup);
                mysqli_stmt_close($stmtDup);

                if ($existingMain) {
                    $custId = (int)$existingMain['ID'];
                    $stmt = mysqli_prepare(
                        $link,
                        "UPDATE " . tn('TBL_MAIN') . "
                         SET MAINDATABASE_NAME = ?, MAINDATABASE_COMPANY = ?, MAINDATABASE_OTHER_INFO = ?
                         WHERE ID = ?"
                    );
                    $existingName = $form['name'];
                    $existingCompany = $form['company'];
                    $existingOtherInfo = $form['other_info'];
                    mysqli_stmt_bind_param($stmt, 'sssi', $existingName, $existingCompany, $existingOtherInfo, $custId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                } else {
                    $stmt = mysqli_prepare(
                        $link,
                        "INSERT INTO " . tn('TBL_MAIN') . "
                         (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_OTHER_INFO, MAINDATABASE_UPLOAD_DATETIME)
                         VALUES (?, ?, ?, ?, NOW())"
                    );
                    $newName = $form['name'];
                    $newMobile = $form['mobile'];
                    $newCompany = $form['company'];
                    $newOtherInfo = $form['other_info'];
                    mysqli_stmt_bind_param($stmt, 'ssss', $newName, $newMobile, $newCompany, $newOtherInfo);
                    mysqli_stmt_execute($stmt);
                    $custId = (int)mysqli_insert_id($link);
                    mysqli_stmt_close($stmt);
                }

                $dupStmt = mysqli_prepare($link, "SELECT lead_id FROM " . tn('TBL_LEADS') . " WHERE cust_id = ? LIMIT 1");
                mysqli_stmt_bind_param($dupStmt, 'i', $custId);
                mysqli_stmt_execute($dupStmt);
                $existingLead = mysqli_stmt_get_result($dupStmt)->fetch_assoc();
                mysqli_stmt_close($dupStmt);
                if ($existingLead) {
                    throw new RuntimeException(
                        "This mobile number already exists as a lead. <a href='lead_view.php?id=" . (int)$existingLead['lead_id'] . "' class='alert-link'>Open lead</a>"
                    );
                }

                $legacyStatus = match ($form['lead_status_new']) {
                    'DISBURSED' => 'Converted',
                    'FOLLOWUP' => 'Follow_Up',
                    'REJECT' => 'Lost',
                    default => 'New',
                };
                $stmt = mysqli_prepare(
                    $link,
                    "INSERT INTO " . tn('TBL_LEADS') . " (
                        cust_id, assigned_to, assigned_by, lead_status, lead_stage, priority, created_by, updated_by,
                        login_date, net_salary, salary_account, bank_name, loan_amount, loan_tenure, promo_code,
                        login_bank_name, lead_status_new, login_mode, loan_type, loan_app_no, login_location,
                        bank_rm_name, bt_details, remarks, dsa_name, next_followup_at, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, 'Cold', 'Medium', ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                    )"
                );
                $assignedBy = USER_ID;
                $createdBy = USER_ID;
                $updatedBy = USER_ID;
                $netSalary = $form['net_salary'];
                $salaryAccount = $form['salary_account'];
                $bankName = $form['bank_name'];
                $loanAmount = $form['loan_amount'];
                $loanTenure = $form['loan_tenure'];
                $promoCode = $form['promo_code'];
                $loginBankName = $form['login_bank_name'];
                $leadStatusNew = $form['lead_status_new'];
                $loginMode = $form['login_mode'];
                $loanType = $form['loan_type'];
                $loanAppNo = $form['loan_app_no'];
                $loginLocation = $form['login_location'];
                $bankRmName = $form['bank_rm_name'];
                $btDetails = $form['bt_details'];
                $remarks = $form['remarks'];
                $dsaName = $form['dsa_name'];
                mysqli_stmt_bind_param(
                    $stmt,
                    'iiisisssssssssssssssssss',
                    $custId,
                    $assignedTo,
                    $assignedBy,
                    $legacyStatus,
                    $createdBy,
                    $updatedBy,
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
                    $nextFollowup
                );
                mysqli_stmt_execute($stmt);
                $leadId = (int)mysqli_insert_id($link);
                mysqli_stmt_close($stmt);

                logActivity(
                    $link,
                    USER_ID,
                    'INSERT',
                    "Created lead for {$form['name']} ({$form['mobile']}) with status {$form['lead_status_new']}",
                    (string)$leadId,
                    tn('TBL_LEADS')
                );

                mysqli_commit($link);
                $success = "Lead created successfully. <a href='lead_view.php?id={$leadId}' class='alert-link'>Open lead</a>";

                foreach ($form as $key => $value) {
                    $form[$key] = $key === 'assigned_to' ? (string)USER_ID : ($key === 'lead_status_new' ? 'LEAD' : '');
                }
            } catch (Throwable $e) {
                mysqli_rollback($link);
                $error = $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Create Lead • CallNow CRM';
include __DIR__ . '/../../php_scripts/header.php';
?>

<datalist id="loanTypeOptions">
    <?php foreach ($loanTypeOptions as $loanType): ?>
        <option value="<?= htmlspecialchars($loanType) ?>"></option>
    <?php endforeach; ?>
</datalist>
<datalist id="bankOptions">
    <?php foreach ($bankOptions as $bank): ?>
        <option value="<?= htmlspecialchars($bank) ?>"></option>
    <?php endforeach; ?>
</datalist>

<div class="container-fluid px-3 py-3">
    <!-- Module Header -->
    <div class="module-header mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 fs-xs">
                    <li class="breadcrumb-item"><a href="<?= url('modules/leads/leads_dashboard.php') ?>">Leads</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/leads/lead_list.php') ?>">All Leads</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Create Lead</li>
                </ol>
            </nav>
            <h1 class="h4 fw-bold text-dark mb-0">Create Lead</h1>
        </div>
        <div class="module-header-actions">
            <a href="<?= url('modules/leads/lead_list.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-list-ul me-1"></i> Lead Register
            </a>
            <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-kanban me-1"></i> Pipeline
            </a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                <div><?= $success ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                <div><?= $error ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" id="createLeadForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="row g-3">
            <!-- Left Column: Customer & Loan Details -->
            <div class="col-lg-7">
                <!-- Customer Information -->
                <div class="crm-card mb-3">
                    <div class="crm-card-header">
                        <div class="crm-card-title"><i class="bi bi-person-vcard text-primary me-2"></i>Customer Information</div>
                    </div>
                    <div class="crm-card-body">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label" for="mobile">Mobile Number <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                    <input type="text" name="mobile" id="mobile" class="form-control" placeholder="10-digit mobile" value="<?= htmlspecialchars($form['mobile']) ?>" maxlength="15" required>
                                </div>
                                <div class="form-text fs-xs">Entering mobile checks existing customer profile.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="name">Customer Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="name" class="form-control" placeholder="Full name" value="<?= htmlspecialchars($form['name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="company">Company / Employer</label>
                                <input type="text" name="company" id="company" class="form-control" placeholder="Organization name" value="<?= htmlspecialchars($form['company']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="net_salary">Net Monthly Salary</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="text" name="net_salary" id="net_salary" class="form-control" placeholder="e.g. 45000" value="<?= htmlspecialchars($form['net_salary']) ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="salary_account">Salary Account Bank</label>
                                <input type="text" name="salary_account" id="salary_account" class="form-control" list="bankOptions" placeholder="Bank name" value="<?= htmlspecialchars($form['salary_account']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="login_date">Application / Login Date</label>
                                <input type="date" name="login_date" id="login_date" class="form-control" value="<?= htmlspecialchars($form['login_date']) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="other_info">Customer Remarks & Notes</label>
                                <textarea name="other_info" id="other_info" class="form-control" rows="2" placeholder="Additional customer details, alternative numbers, or specific requirements..."><?= htmlspecialchars($form['other_info']) ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loan & Application Details -->
                <div class="crm-card mb-3">
                    <div class="crm-card-header">
                        <div class="crm-card-title"><i class="bi bi-cash-stack text-primary me-2"></i>Loan & Application Details</div>
                    </div>
                    <div class="crm-card-body">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label" for="loan_type">Loan Type</label>
                                <input type="text" name="loan_type" id="loan_type" class="form-control" list="loanTypeOptions" placeholder="Personal / Business / Home" value="<?= htmlspecialchars($form['loan_type']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="loan_amount">Requested Loan Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="text" name="loan_amount" id="loan_amount" class="form-control" placeholder="e.g. 500000" value="<?= htmlspecialchars($form['loan_amount']) ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="loan_tenure">Tenure (Months / Years)</label>
                                <input type="text" name="loan_tenure" id="loan_tenure" class="form-control" placeholder="e.g. 48 Months" value="<?= htmlspecialchars($form['loan_tenure']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="login_mode">Login Mode</label>
                                <select name="login_mode" id="login_mode" class="form-select">
                                    <option value="">Select Mode</option>
                                    <?php foreach ($loginModeOptions as $mode): ?>
                                        <option value="<?= htmlspecialchars($mode) ?>" <?= $form['login_mode'] === $mode ? 'selected' : '' ?>><?= htmlspecialchars($mode) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="bank_name">Applied Bank</label>
                                <input type="text" name="bank_name" id="bank_name" class="form-control" list="bankOptions" placeholder="Lender bank name" value="<?= htmlspecialchars($form['bank_name']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="loan_app_no">Bank Application / File No</label>
                                <input type="text" name="loan_app_no" id="loan_app_no" class="form-control" placeholder="LAN / App reference" value="<?= htmlspecialchars($form['loan_app_no']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="login_location">Login Location / Branch</label>
                                <input type="text" name="login_location" id="login_location" class="form-control" placeholder="City or branch" value="<?= htmlspecialchars($form['login_location']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="bank_rm_name">Bank RM / Coordinator</label>
                                <input type="text" name="bank_rm_name" id="bank_rm_name" class="form-control" placeholder="Bank official name" value="<?= htmlspecialchars($form['bank_rm_name']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="promo_code">Promo / Referral Code</label>
                                <input type="text" name="promo_code" id="promo_code" class="form-control" placeholder="Offer or promo tag" value="<?= htmlspecialchars($form['promo_code']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="dsa_name">DSA / Channel Partner</label>
                                <input type="text" name="dsa_name" id="dsa_name" class="form-control" placeholder="DSA Name if any" value="<?= htmlspecialchars($form['dsa_name']) ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Assignment, Stage & Sanction -->
            <div class="col-lg-5">
                <!-- Assignment & Workflow Stage -->
                <div class="crm-card mb-3">
                    <div class="crm-card-header">
                        <div class="crm-card-title"><i class="bi bi-diagram-3 text-primary me-2"></i>Assignment & Workflow</div>
                    </div>
                    <div class="crm-card-body">
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label" for="assigned_to">Assigned Telecaller / Officer <span class="text-danger">*</span></label>
                                <select name="assigned_to" id="assigned_to" class="form-select" required>
                                    <option value="">Select Assignee</option>
                                    <?php foreach ($TBL_USERS as $user): ?>
                                        <option value="<?= (int)$user['ID'] ?>" <?= (string)$user['ID'] === $form['assigned_to'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($user['NAME']) ?><?= (int)$user['ID'] === USER_ID ? ' (You)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="lead_status_new">Initial Pipeline Status</label>
                                <select name="lead_status_new" id="lead_status_new" class="form-select">
                                    <?php foreach ($statusOptions as $status): ?>
                                        <option value="<?= htmlspecialchars($status) ?>" <?= $form['lead_status_new'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="next_followup_at">Next Follow-up Date</label>
                                <input type="date" name="next_followup_at" id="next_followup_at" class="form-control" value="<?= htmlspecialchars($form['next_followup_at']) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="remarks">Lead Status Remark</label>
                                <textarea name="remarks" id="remarks" class="form-control" rows="3" placeholder="Notes on conversation, customer willingness, or next action step..."><?= htmlspecialchars($form['remarks']) ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sanction & Balance Transfer -->
                <div class="crm-card mb-3">
                    <div class="crm-card-header">
                        <div class="crm-card-title"><i class="bi bi-check2-circle text-primary me-2"></i>Sanction / Disbursal Details</div>
                    </div>
                    <div class="crm-card-body">
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label" for="login_bank_name">Sanctioning / Disbursing Bank</label>
                                <input type="text" name="login_bank_name" id="login_bank_name" class="form-control" list="bankOptions" placeholder="Final disbursed bank" value="<?= htmlspecialchars($form['login_bank_name']) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="bt_details">BT (Balance Transfer) Details</label>
                                <textarea name="bt_details" id="bt_details" class="form-control" rows="2" placeholder="Existing loan details, tenure left, or outstanding amount..."><?= htmlspecialchars($form['bt_details']) ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Card -->
                <div class="crm-card">
                    <div class="crm-card-body">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <a href="<?= url('modules/leads/lead_list.php') ?>" class="btn btn-secondary w-50">
                                <i class="bi bi-x-lg me-1"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary w-50">
                                <i class="bi bi-check-lg me-1"></i> Save Lead
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
document.getElementById('mobile').addEventListener('blur', function () {
    const mobile = this.value.trim();
    if (mobile.length < 10) return;
    fetch('lead_ajax_check_mobile.php?mobile=' + encodeURIComponent(mobile))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.exists) return;
            if (!document.getElementById('name').value.trim()) document.getElementById('name').value = data.name || '';
            if (!document.getElementById('company').value.trim()) document.getElementById('company').value = data.company || '';
            if (!document.getElementById('other_info').value.trim()) document.getElementById('other_info').value = data.other_info || '';
        })
        .catch(function() {});
});
</script>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
