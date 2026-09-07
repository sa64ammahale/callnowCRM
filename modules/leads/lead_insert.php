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
?>
<?php $pageTitle = 'Add New Lead - CallNow'; include __DIR__ . '/../../php_scripts/header.php'; ?>

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

<div class="li-wrap">
    <!-- Header -->
    <div class="li-header">
        <div class="li-header-left">
            <div class="li-header-icon"><i class="bi bi-person-plus-fill"></i></div>
            <div>
                <h1 class="li-title">Add New Lead</h1>
                <p class="li-sub">Create and manage leads directly inside CallNow.</p>
            </div>
        </div>
        <div class="li-header-actions">
            <a href="<?= url('modules/leads/lead_list.php') ?>" class="btn btn-outline-accent btn-sm"><i class="bi bi-list-ul me-1"></i> View Leads</a>
            <a href="<?= url('modules/leads/leads_dashboard.php') ?>" class="btn btn-outline-accent btn-sm"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show mb-0 py-2 px-3 rounded-4 border-0 shadow-sm" style="background:#ecfdf5;color:#065f46;">
            <?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-0 py-2 px-3 rounded-4 border-0 shadow-sm" style="background:#fef2f2;color:#991b1b;">
            <?= $error ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" class="li-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="li-grid">
            <!-- Customer Details -->
            <div class="li-card li-card-customer">
                <div class="li-card-head"><i class="bi bi-person-badge"></i> Customer Details</div>
                <div class="li-card-body">
                    <div class="mb-2">
                        <label class="li-label">Login Date</label>
                        <input type="date" name="login_date" class="li-input" value="<?= htmlspecialchars($form['login_date']) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Customer Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="name" class="li-input" value="<?= htmlspecialchars($form['name']) ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Mobile Number <span class="text-danger">*</span></label>
                        <input type="text" name="mobile" id="mobile" class="li-input" value="<?= htmlspecialchars($form['mobile']) ?>" maxlength="15" required>
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Company Name</label>
                        <input type="text" name="company" id="company" class="li-input" value="<?= htmlspecialchars($form['company']) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Net Salary</label>
                        <input type="text" name="net_salary" class="li-input" value="<?= htmlspecialchars($form['net_salary']) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Salary A/C</label>
                        <input type="text" name="salary_account" class="li-input" value="<?= htmlspecialchars($form['salary_account']) ?>">
                    </div>
                    <div class="mb-0">
                        <label class="li-label">Other Info</label>
                        <textarea name="other_info" id="other_info" class="li-input" rows="3"><?= htmlspecialchars($form['other_info']) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Login & Loan -->
            <div class="li-card li-card-login">
                <div class="li-card-head"><i class="bi bi-box-arrow-in-right"></i> Login & Loan</div>
                <div class="li-card-body">
                    <div class="mb-2">
                        <label class="li-label">Login Mode</label>
                        <select name="login_mode" class="li-select">
                            <option value="">Select Mode</option>
                            <?php foreach ($loginModeOptions as $mode): ?>
                                <option value="<?= htmlspecialchars($mode) ?>" <?= $form['login_mode'] === $mode ? 'selected' : '' ?>><?= htmlspecialchars($mode) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Login Location</label>
                        <input type="text" name="login_location" class="li-input" value="<?= htmlspecialchars($form['login_location']) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Bank Name</label>
                        <input type="text" name="bank_name" class="li-input" list="bankOptions" value="<?= htmlspecialchars($form['bank_name']) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Loan Amount</label>
                        <input type="text" name="loan_amount" class="li-input" value="<?= htmlspecialchars($form['loan_amount']) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Loan Tenure</label>
                        <input type="text" name="loan_tenure" class="li-input" value="<?= htmlspecialchars($form['loan_tenure']) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Loan Type</label>
                        <input type="text" name="loan_type" class="li-input" list="loanTypeOptions" value="<?= htmlspecialchars($form['loan_type']) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Promo Code</label>
                        <input type="text" name="promo_code" class="li-input" value="<?= htmlspecialchars($form['promo_code']) ?>">
                    </div>
                    <div class="mb-0">
                        <label class="li-label">Bank RM Name</label>
                        <input type="text" name="bank_rm_name" class="li-input" value="<?= htmlspecialchars($form['bank_rm_name']) ?>">
                    </div>
                </div>
            </div>

            <!-- Assignment & Process -->
            <div class="li-card li-card-process">
                <div class="li-card-head"><i class="bi bi-diagram-3"></i> Assignment & Process</div>
                <div class="li-card-body">
                    <div class="mb-2">
                        <label class="li-label">Assigned Employee <span class="text-danger">*</span></label>
                        <select name="assigned_to" class="li-select" required>
                            <option value="">Choose Employee</option>
                            <?php foreach ($TBL_USERS as $user): ?>
                                <option value="<?= (int)$user['ID'] ?>" <?= (string)$user['ID'] === $form['assigned_to'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['NAME']) ?><?= (int)$user['ID'] === USER_ID ? ' (You)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Status</label>
                        <select name="lead_status_new" class="li-select">
                            <?php foreach ($statusOptions as $status): ?>
                                <option value="<?= htmlspecialchars($status) ?>" <?= $form['lead_status_new'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Next Follow-up</label>
                        <input type="date" name="next_followup_at" class="li-input" value="<?= htmlspecialchars($form['next_followup_at']) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="li-label">Remark</label>
                        <textarea name="remarks" class="li-input" rows="2"><?= htmlspecialchars($form['remarks']) ?></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="li-label">DSA Name</label>
                        <input type="text" name="dsa_name" class="li-input" value="<?= htmlspecialchars($form['dsa_name']) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="li-label">BT Details</label>
                        <textarea name="bt_details" class="li-input" rows="2"><?= htmlspecialchars($form['bt_details']) ?></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="li-label">App No</label>
                        <input type="text" name="loan_app_no" class="li-input" value="<?= htmlspecialchars($form['loan_app_no']) ?>">
                    </div>
                </div>
            </div>

            <!-- Disbursal -->
            <div class="li-card li-card-disbursal">
                <div class="li-card-head"><i class="bi bi-cash-coin"></i> Disbursal / Sanction</div>
                <div class="li-card-body">
                    <div class="mb-2">
                        <label class="li-label">Disbursed Bank</label>
                        <input type="text" name="login_bank_name" class="li-input" list="bankOptions" value="<?= htmlspecialchars($form['login_bank_name']) ?>">
                    </div>
                    <div>
                        <label class="li-label">BT Details</label>
                        <textarea name="bt_details" class="li-input" rows="2"><?= htmlspecialchars($form['bt_details']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="li-footer">
            <a href="<?= url('modules/leads/lead_list.php') ?>" class="li-cancel-btn"><i class="bi bi-x-lg me-1"></i> Cancel</a>
            <button type="submit" class="li-save-btn"><i class="bi bi-check-circle me-2"></i> Create Lead</button>
        </div>
    </form>
</div>

<script>
document.getElementById('mobile').addEventListener('blur', function () {
    const mobile = this.value.trim();
    if (mobile.length < 10) return;
    fetch('php_scripts/lead_ajax_check_mobile.php?mobile=' + encodeURIComponent(mobile))
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

<style>
.li-wrap {
    max-width: 1200px; margin: 0 auto; padding: 1.5rem 1.5rem 2rem;
    display: flex; flex-direction: column; gap: 1rem;
}
.li-header {
    display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;
}
.li-header-left { display: flex; align-items: center; gap: 0.875rem; }
.li-header-icon {
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; flex-shrink: 0;
    box-shadow: 0 3px 12px rgba(99,102,241,0.3);
}
.li-title { font-size: 1.5rem; font-weight: 800; margin: 0 0 0.125rem; color: #1e1b4b; letter-spacing: -0.03em; }
.li-sub { font-size: 0.8125rem; color: #7c7caa; margin: 0; font-weight: 500; }
.li-header-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.btn-outline-accent {
    border: 1px solid #d4d6f0; color: #4f46b5; font-weight: 600;
    border-radius: 10px; padding: 0.375rem 0.875rem; font-size: 0.8125rem;
    transition: all 0.15s ease; text-decoration: none;
}
.btn-outline-accent:hover { border-color: #6366f1; color: #4338ca; background: #eef1ff; }
.li-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
.li-card {
    background: #fff; border: 1px solid #e8eaf5; border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04); overflow: hidden;
    transition: box-shadow 0.2s ease;
}
.li-card:hover { box-shadow: 0 4px 16px rgba(99,102,241,0.06); }
.li-card-head {
    padding: 0.75rem 1rem; font-size: 0.8125rem; font-weight: 700;
    display: flex; align-items: center; gap: 0.5rem;
    border-bottom: 1px solid #eceef5; text-transform: uppercase; letter-spacing: 0.03em;
}
.li-card-customer .li-card-head { background: linear-gradient(135deg, rgba(99,102,241,0.06), rgba(139,92,246,0.06)); color: #6366f1; }
.li-card-login .li-card-head { background: linear-gradient(135deg, rgba(245,158,11,0.06), rgba(239,68,68,0.06)); color: #d97706; }
.li-card-process .li-card-head { background: linear-gradient(135deg, rgba(14,165,233,0.06), rgba(99,102,241,0.06)); color: #0ea5e9; }
.li-card-disbursal .li-card-head { background: linear-gradient(135deg, rgba(16,185,129,0.06), rgba(5,150,105,0.06)); color: #059669; }
.li-card-body { padding: 0.75rem 1rem; }
.li-label {
    font-size: 0.625rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.05em; color: #7c7caa; display: block; margin-bottom: 0.25rem;
}
.li-input, .li-select {
    width: 100%; padding: 0.5rem 0.625rem;
    border: 1px solid #d4d6f0; border-radius: 10px;
    background: #fafafe; color: #1e1b4b; font-size: 0.8125rem;
    outline: none; transition: all 0.15s ease;
    font-family: inherit;
}
.li-input:focus, .li-select:focus {
    border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
    background: #fff;
}
.li-select { cursor: pointer; }
.li-footer {
    display: flex; justify-content: flex-end; align-items: center; gap: 0.75rem;
    padding-top: 0.5rem;
}
.li-cancel-btn {
    display: inline-flex; align-items: center;
    padding: 0.625rem 1.25rem; font-size: 0.8125rem; font-weight: 600;
    border: 1px solid #d4d6f0; border-radius: 10px;
    background: #fff; color: #4f46b5; cursor: pointer;
    text-decoration: none; transition: all 0.12s ease;
}
.li-cancel-btn:hover { background: #f4f5fb; border-color: #bcc0e8; }
.li-save-btn {
    display: inline-flex; align-items: center;
    padding: 0.625rem 1.5rem; font-size: 0.875rem; font-weight: 700;
    border: none; border-radius: 10px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff; cursor: pointer;
    box-shadow: 0 3px 12px rgba(99,102,241,0.3);
    transition: all 0.15s ease; text-transform: uppercase; letter-spacing: 0.04em;
}
.li-save-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(99,102,241,0.4); }
.li-save-btn:active { transform: translateY(0); }

@media (max-width: 768px) {
    .li-wrap { padding: 0.75rem; gap: 0.75rem; }
    .li-header { flex-direction: column; align-items: flex-start; }
    .li-header-actions { width: 100%; }
    .li-header-actions .btn { flex: 1; text-align: center; }
    .li-grid { grid-template-columns: 1fr; }
}
</style>




