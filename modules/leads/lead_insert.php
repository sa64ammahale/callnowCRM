<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../php_scripts/team_auth.php';
require_once __DIR__ . '/lead_common.php';

$allowed_roles = ['Admin', 'Manager', 'Supervisor', 'Officer'];
if (!in_array(USER_ROLE, $allowed_roles, true)) {
    header("Location: leads_dashboard.php");
    exit;
}

ensureLeadModuleSchema($link);
mysqli_set_charset($link, 'utf8mb4');

$statusOptions = leadStatusOptions();
$loginModeOptions = loginModeOptions();
$loanTypeOptions = loanTypeOptions();
$bankOptions = indianBankOptions();

$users = getLeadAssignableUsers($link);

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
            $stmtDup = mysqli_prepare($link, "SELECT ID FROM MAIN_DATABASE WHERE MAINDATABASE_MOBILE = ? LIMIT 1");
            mysqli_stmt_bind_param($stmtDup, "s", $form['mobile']);
            mysqli_stmt_execute($stmtDup);
            $resDup = mysqli_stmt_get_result($stmtDup);
            $existingMain = mysqli_fetch_assoc($resDup);
            mysqli_stmt_close($stmtDup);

            if ($existingMain) {
                $custId = (int)$existingMain['ID'];
                $stmt = mysqli_prepare(
                    $link,
                    "UPDATE MAIN_DATABASE
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
                    "INSERT INTO MAIN_DATABASE
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

            $existingLead = mysqli_fetch_assoc(
                mysqli_query($link, "SELECT lead_id FROM LEADS_TABLE WHERE cust_id = {$custId} LIMIT 1")
            );
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
                "INSERT INTO LEADS_TABLE (
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
                'iiisiisssssssssssssssssss',
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
                'LEADS_TABLE'
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
?>
<?php $pageTitle = 'Add New Lead - CallNow'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<datalist id="loanTypeOptions">
    <?php foreach ($loanTypeOptions as $loanType): ?>
        <option value="<?= htmlspecialchars($loanType) ?>">
    <?php endforeach; ?>
</datalist>
<datalist id="bankOptions">
    <?php foreach ($bankOptions as $bank): ?>
        <option value="<?= htmlspecialchars($bank) ?>">
    <?php endforeach; ?>
</datalist>

<div class="container page-wrapper">
    <div class="page-header-bar">
        <div>
            <p class="page-header-title mb-1">
                <i class="bi bi-person-plus-fill text-primary me-1"></i>
                Add New Lead
            </p>
            <p class="page-header-desc mb-0">
                Create and manage leads directly inside CallNow.
            </p>
        </div>
        <div class="page-header-actions d-flex gap-2">
            <a href="lead_list.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list-ul"></i> View Leads
            </a>
            <a href="leads_dashboard.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-grid"></i> Dashboard
            </a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3 py-2">
            <?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-3 py-2">
            <?= $error ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" class="form-card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="section-card">
                    <div class="section-title"><i class="bi bi-person-lines-fill me-1"></i>Customer Details</div>
                    <div class="mb-3">
                        <label class="form-label">Login Date</label>
                        <input type="date" name="login_date" class="form-control form-control-sm" value="<?= htmlspecialchars($form['login_date']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="name" class="form-control form-control-sm" value="<?= htmlspecialchars($form['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
                        <input type="text" name="mobile" id="mobile" class="form-control form-control-sm" value="<?= htmlspecialchars($form['mobile']) ?>" maxlength="15" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company" id="company" class="form-control form-control-sm" value="<?= htmlspecialchars($form['company']) ?>">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Other Info</label>
                        <textarea name="other_info" id="other_info" class="form-control form-control-sm" rows="4"><?= htmlspecialchars($form['other_info']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="section-card">
                    <div class="section-title"><i class="bi bi-bank me-1"></i>Loan & Banking</div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">Net Salary</label>
                            <input type="text" name="net_salary" class="form-control form-control-sm" value="<?= htmlspecialchars($form['net_salary']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Salary A/C</label>
                            <input type="text" name="salary_account" class="form-control form-control-sm" value="<?= htmlspecialchars($form['salary_account']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control form-control-sm" list="bankOptions" value="<?= htmlspecialchars($form['bank_name']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Loan Amount</label>
                            <input type="text" name="loan_amount" class="form-control form-control-sm" value="<?= htmlspecialchars($form['loan_amount']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Loan Tenure</label>
                            <input type="text" name="loan_tenure" class="form-control form-control-sm" value="<?= htmlspecialchars($form['loan_tenure']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Promo Code</label>
                            <input type="text" name="promo_code" class="form-control form-control-sm" value="<?= htmlspecialchars($form['promo_code']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Login Bank Name</label>
                            <input type="text" name="login_bank_name" class="form-control form-control-sm" list="bankOptions" value="<?= htmlspecialchars($form['login_bank_name']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Loan Type</label>
                            <input type="text" name="loan_type" class="form-control form-control-sm" list="loanTypeOptions" value="<?= htmlspecialchars($form['loan_type']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Loan Application No</label>
                            <input type="text" name="loan_app_no" class="form-control form-control-sm" value="<?= htmlspecialchars($form['loan_app_no']) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="section-card">
                    <div class="section-title"><i class="bi bi-diagram-3 me-1"></i>Process & Assignment</div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Assigned Employee</label>
                            <select name="assigned_to" class="form-select form-select-sm" required>
                                <option value="">Choose Employee</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= (int)$user['ID'] ?>" <?= (string)$user['ID'] === $form['assigned_to'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($user['NAME']) ?><?= (int)$user['ID'] === USER_ID ? ' (You)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Status</label>
                            <select name="lead_status_new" class="form-select form-select-sm">
                                <?php foreach ($statusOptions as $status): ?>
                                    <option value="<?= htmlspecialchars($status) ?>" <?= $form['lead_status_new'] === $status ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Login Mode</label>
                            <select name="login_mode" class="form-select form-select-sm">
                                <option value="">Select Mode</option>
                                <?php foreach ($loginModeOptions as $mode): ?>
                                    <option value="<?= htmlspecialchars($mode) ?>" <?= $form['login_mode'] === $mode ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mode) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Login Location</label>
                            <input type="text" name="login_location" class="form-control form-control-sm" value="<?= htmlspecialchars($form['login_location']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Bank RM Name</label>
                            <input type="text" name="bank_rm_name" class="form-control form-control-sm" value="<?= htmlspecialchars($form['bank_rm_name']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">DSA Name</label>
                            <input type="text" name="dsa_name" class="form-control form-control-sm" value="<?= htmlspecialchars($form['dsa_name']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">BT Details</label>
                            <textarea name="bt_details" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($form['bt_details']) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Remark</label>
                            <textarea name="remarks" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($form['remarks']) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Next Follow-up</label>
                            <input type="date" name="next_followup_at" class="form-control form-control-sm" value="<?= htmlspecialchars($form['next_followup_at']) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 d-flex justify-content-end gap-2 pt-2">
                <a href="lead_list.php" class="btn btn-outline-secondary submit-btn">Cancel</a>
                <button type="submit" class="btn btn-primary submit-btn">
                    <i class="bi bi-check-circle me-1"></i> Create Lead
                </button>
            </div>
        </div>
    </form>
</div>

<script>
document.getElementById('mobile').addEventListener('blur', function () {
    const mobile = this.value.trim();
    if (mobile.length < 10) {
        return;
    }

    fetch('php_scripts/lead_ajax_check_mobile.php?mobile=' + encodeURIComponent(mobile))
        .then((response) => response.json())
        .then((data) => {
            if (!data.exists) {
                return;
            }
            if (!document.getElementById('name').value.trim()) {
                document.getElementById('name').value = data.name || '';
            }
            if (!document.getElementById('company').value.trim()) {
                document.getElementById('company').value = data.company || '';
            }
            if (!document.getElementById('other_info').value.trim()) {
                document.getElementById('other_info').value = data.other_info || '';
            }
        })
        .catch(() => {});
});
</script>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
