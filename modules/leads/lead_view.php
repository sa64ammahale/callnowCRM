<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/lead_common.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: lead_list.php");
    exit;
}

ensureLeadModuleSchema($link);
mysqli_set_charset($link, 'utf8mb4');

$leadId = (int)$_GET['id'];
$statusOptions = leadStatusOptions();
$loginModeOptions = loginModeOptions();
$loanTypeOptions = loanTypeOptions();
$bankOptions = indianBankOptions();

$accessibleUserIds = getAccessibleUserIds($link);
$accessWhere = '';
if (!isAdmin() && !empty($accessibleUserIds)) {
    $accessWhere = ' AND l.assigned_to IN (' . implode(',', array_map('intval', $accessibleUserIds)) . ')';
}

$leadSql = "
    SELECT
        l.*,
        m.MAINDATABASE_NAME AS customer_name,
        m.MAINDATABASE_MOBILE AS mobile,
        m.MAINDATABASE_COMPANY AS company_name,
        m.MAINDATABASE_OTHER_INFO AS other_info,
        u.NAME AS assigned_name
    FROM LEADS_TABLE l
    LEFT JOIN MAIN_DATABASE m ON m.ID = l.cust_id
    LEFT JOIN USERS u ON u.ID = l.assigned_to
    WHERE l.lead_id = ? {$accessWhere}
    LIMIT 1
";
$stmt = mysqli_prepare($link, $leadSql);
mysqli_stmt_bind_param($stmt, 'i', $leadId);
mysqli_stmt_execute($stmt);
$lead = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$lead) {
    $_SESSION['success_message'] = 'Lead not found or access denied.';
    $_SESSION['flash_class'] = 'danger';
    header('Location: lead_list.php');
    exit;
}

$users = getLeadAssignableUsers($link);
$canEditAssignment = canEditLeadAssignment();

$flashMessage = $_SESSION['success_message'] ?? '';
$flashClass = $_SESSION['flash_class'] ?? 'success';
unset($_SESSION['success_message'], $_SESSION['flash_class']);

$workflowSteps = leadJourneySteps();

$currentStatus = (string)($lead['lead_status_new'] ?? 'LEAD');
$stepKeys = array_column($workflowSteps, 'key');
$currentStepIndex = array_search($currentStatus, $stepKeys, true);
if ($currentStepIndex === false) {
    $currentStepIndex = 0;
}
?>
<?php $pageTitle = 'Lead #' . $leadId . ' - ' . htmlspecialchars($lead['customer_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); include __DIR__ . '/../../php_scripts/header.php'; ?>

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
                <i class="bi bi-person-lines-fill text-primary me-1"></i>
                Lead #<?= $leadId ?> - <?= htmlspecialchars($lead['customer_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
            </p>
            <p class="page-header-desc mb-0">
                Visible to all users within their allowed scope. Assigned to
                <strong><?= htmlspecialchars($lead['assigned_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></strong>
                ·
                <span class="badge <?= leadStatusBadgeClass($currentStatus) ?> status-badge">
                    <?= htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </p>
        </div>
        <div class="page-header-actions d-flex gap-2">
            <a href="lead_list.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list-ul"></i> Lead List
            </a>
            <a href="lead_pipeline.php" class="btn btn-outline-dark btn-sm">
                <i class="bi bi-kanban"></i> Pipeline
            </a>
            <a href="leads_dashboard.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-grid"></i> Dashboard
            </a>
        </div>
    </div>

    <div class="workflow-strip">
        <div class="workflow-grid">
            <?php foreach ($workflowSteps as $stepIndex => $step): ?>
                <?php
                $stateClass = '';
                if ($stepIndex < $currentStepIndex) {
                    $stateClass = 'done';
                } elseif ($stepIndex === $currentStepIndex) {
                    $stateClass = 'active';
                }
                ?>
                <div class="workflow-card <?= $stateClass ?>">
                    <div class="workflow-icon"><i class="bi <?= htmlspecialchars($step['icon']) ?>"></i></div>
                    <div class="workflow-label"><?= htmlspecialchars($step['label']) ?></div>
                    <div class="workflow-sub"><?= $stepIndex < $currentStepIndex ? 'Completed' : ($stepIndex === $currentStepIndex ? 'Current stage' : 'Upcoming') ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <div class="alert alert-<?= htmlspecialchars($flashClass) ?> alert-dismissible fade show mb-3 py-2">
            <?= htmlspecialchars($flashMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="lead-shell">
        <div class="summary-grid">
            <div class="summary-box">
                <div class="summary-label">Customer</div>
                <div class="summary-value"><?= htmlspecialchars($lead['customer_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Mobile</div>
                <div class="summary-value">
                    <a href="tel:<?= htmlspecialchars($lead['mobile'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none text-success">
                        <?= htmlspecialchars($lead['mobile'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Next Follow-up</div>
                <div class="summary-value"><?= $lead['next_followup_at'] ? htmlspecialchars(date('d M Y', strtotime($lead['next_followup_at'])), ENT_QUOTES, 'UTF-8') : 'Not Set' ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Loan Status</div>
                <div class="summary-value"><?= htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <form method="POST" action="lead_update.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="lead_id" value="<?= $leadId ?>">
            <input type="hidden" name="action" value="save_lead">

            <div class="row g-3">
                <div class="col-12">
                    <div class="section-card">
                        <div class="section-title"><i class="bi bi-arrow-repeat"></i> Follow-up Stage</div>
                        <div class="section-note">This is the first section your team should use while handling callbacks and ongoing follow-ups.</div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="lead_status_new" class="form-select form-select-sm">
                                    <?php foreach ($statusOptions as $status): ?>
                                        <option value="<?= htmlspecialchars($status) ?>" <?= $currentStatus === $status ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($status) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Assigned Employee</label>
                                <select name="assigned_to" class="form-select form-select-sm" <?= $canEditAssignment ? '' : 'disabled' ?>>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= (int)$user['ID'] ?>" <?= (int)$user['ID'] === (int)($lead['assigned_to'] ?? 0) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($user['NAME'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!$canEditAssignment): ?>
                                    <input type="hidden" name="assigned_to" value="<?= (int)($lead['assigned_to'] ?? USER_ID) ?>">
                                    <div class="form-text">Assignment changes are restricted to supervisor-level roles.</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Next Follow-up</label>
                                <input type="date" name="next_followup_at" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['next_followup_at'] ? date('Y-m-d', strtotime($lead['next_followup_at'])) : '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Remark</label>
                                <textarea name="remarks" class="form-control form-control-sm" rows="1"><?= htmlspecialchars($lead['remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="section-card">
                        <div class="section-title"><i class="bi bi-person-badge"></i> Lead Stage</div>
                        <div class="section-note">Core customer and sourcing details that help the team qualify the lead.</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Customer Name</label>
                                <input type="text" name="customer_name" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['customer_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Mobile Number</label>
                                <input type="text" name="mobile" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['mobile'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Company Name</label>
                                <input type="text" name="company_name" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['company_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">DSA Name</label>
                                <input type="text" name="dsa_name" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['dsa_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Loan Type</label>
                                <input type="text" name="loan_type" list="loanTypeOptions" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['loan_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Promo Code</label>
                                <input type="text" name="promo_code" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['promo_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Other Info</label>
                                <textarea name="other_info" class="form-control form-control-sm" rows="3"><?= htmlspecialchars($lead['other_info'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="section-card">
                        <div class="section-title"><i class="bi bi-box-arrow-in-right"></i> Login Stage</div>
                        <div class="section-note">Use this section once the lead moves from discussion into actual login processing.</div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Login Date</label>
                                <input type="date" name="login_date" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($lead['login_date'] ?? '')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Login Mode</label>
                                <select name="login_mode" class="form-select form-select-sm">
                                    <option value="">Select Mode</option>
                                    <?php foreach ($loginModeOptions as $mode): ?>
                                        <option value="<?= htmlspecialchars($mode) ?>" <?= ($lead['login_mode'] ?? '') === $mode ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($mode) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Login Location</label>
                                <input type="text" name="login_location" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['login_location'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Net Salary</label>
                                <input type="text" name="net_salary" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['net_salary'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Salary A/C</label>
                                <input type="text" name="salary_account" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['salary_account'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Bank Name</label>
                                <input type="text" name="bank_name" list="bankOptions" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['bank_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Loan Amount</label>
                                <input type="text" name="loan_amount" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['loan_amount'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Loan Tenure</label>
                                <input type="text" name="loan_tenure" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['loan_tenure'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Loan Application No</label>
                                <input type="text" name="loan_app_no" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['loan_app_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="section-card">
                        <div class="section-title"><i class="bi bi-cash-coin"></i> Sanction / Disbursal Stage</div>
                        <div class="section-note">Final banking and closing details to capture after login progresses toward sanction and payout.</div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Login Bank Name</label>
                                <input type="text" name="login_bank_name" list="bankOptions" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['login_bank_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Bank RM Name</label>
                                <input type="text" name="bank_rm_name" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['bank_rm_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">BT Details</label>
                                <input type="text" name="bt_details" class="form-control form-control-sm" value="<?= htmlspecialchars($lead['bt_details'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 pt-3">
                <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill">
                    <i class="bi bi-save me-1"></i> Save Lead
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
