<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/lead_common.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: lead_list");
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
if (!isAdmin() && !isSuperAdmin() && !empty($accessibleUserIds)) {
    $accessWhere = ' AND l.assigned_to IN (' . implode(',', array_map('intval', $accessibleUserIds)) . ')';
}

$leadSql = "
    SELECT
        l.*,
        COALESCE(NULLIF(m.MAINDATABASE_NAME, ''), NULLIF(l.NAME, ''), CONCAT('Lead #', l.lead_id)) AS customer_name,
        COALESCE(NULLIF(m.MAINDATABASE_MOBILE, ''), NULLIF(l.MOBILE, ''), 'N/A') AS mobile,
        COALESCE(NULLIF(m.MAINDATABASE_COMPANY, ''), NULLIF(l.COMPANY_NAME, ''), '') AS company_name,
        m.MAINDATABASE_OTHER_INFO AS other_info,
        u.NAME AS assigned_name,
        t.TEAM_NAME AS team_name
    FROM " . tn('TBL_LEADS') . " l
    LEFT JOIN " . tn('TBL_MAIN') . " m ON m.ID = l.cust_id
    LEFT JOIN " . tn('TBL_USERS') . " u ON u.ID = l.assigned_to
    LEFT JOIN " . tn('TBL_TEAMS') . " t ON t.ID = l.team_id
    WHERE l.lead_id = ?{$accessWhere}
    LIMIT 1
";
$stmt = mysqli_prepare($link, $leadSql);
mysqli_stmt_bind_param($stmt, 'i', $leadId);
mysqli_stmt_execute($stmt);
$lead = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$lead || !canViewLead($link, $lead)) {
    $_SESSION['success_message'] = 'Lead not found or access denied.';
    $_SESSION['flash_class'] = 'danger';
    header('Location: lead_list');
    exit;
}

$canEdit = canEditLead($lead);
$canPull = canPullToTray($lead);

$TBL_USERS = getLeadAssignableTBL_USERS($link);
$canEditAssignment = canEditLeadAssignment();

// Threaded notes
$notes = [];
$ns = mysqli_prepare($link, "SELECT n.*, u.NAME AS author FROM " . tn('TBL_LEAD_NOTES') . " n LEFT JOIN " . tn('TBL_USERS') . " u ON u.ID = n.user_id WHERE n.lead_id = ? ORDER BY n.created_at ASC");
if ($ns) {
    mysqli_stmt_bind_param($ns, 'i', $leadId);
    mysqli_stmt_execute($ns);
    $nr = mysqli_stmt_get_result($ns);
    while ($nr && $row = mysqli_fetch_assoc($nr)) $notes[] = $row;
    mysqli_stmt_close($ns);
}

// Follow-ups
$followups = [];
$fs = mysqli_prepare($link, "SELECT * FROM " . tn('TBL_LEAD_FOLLOWUPS') . " WHERE lead_id = ? ORDER BY followup_at ASC");
if ($fs) {
    mysqli_stmt_bind_param($fs, 'i', $leadId);
    mysqli_stmt_execute($fs);
    $fr = mysqli_stmt_get_result($fs);
    while ($fr && $row = mysqli_fetch_assoc($fr)) $followups[] = $row;
    mysqli_stmt_close($fs);
}

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
$daysInStage = $lead['updated_at'] ? max(0, (int)((time() - strtotime($lead['updated_at'])) / 86400)) : 0;
?>
<?php $pageTitle = 'Lead #' . $leadId . ' - ' . htmlspecialchars($lead['customer_name'] ?? 'Lead #' . $leadId, ENT_QUOTES, 'UTF-8'); include __DIR__ . '/../../php_scripts/header.php'; ?>

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

<div class="crm-module-header">
    <div>
        <div class="crm-module-header-title">
            <i class="bi bi-person-badge text-primary"></i>
            <h1><?= htmlspecialchars($lead['customer_name'] ?? 'Lead #' . $leadId) ?> <span class="text-muted fw-normal fs-6">#<?= $leadId ?></span></h1>
            <span class="badge <?= leadStatusBadgeClass($currentStatus) ?> ms-2"><?= htmlspecialchars($currentStatus) ?></span>
        </div>
        <p class="crm-module-header-desc">
            Assigned to <strong><?= htmlspecialchars($lead['assigned_name'] ?? 'Unassigned') ?></strong>
            <?php if ($daysInStage > 0): ?> &bull; <?= $daysInStage ?> days in stage<?php endif; ?>
            <?php if (!empty($lead['team_name'])): ?> &bull; Team: <?= htmlspecialchars($lead['team_name']) ?><?php endif; ?>
        </p>
    </div>
    <div class="crm-module-header-actions">
        <a href="<?= url('modules/leads/lead_list.php') ?>" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Register</a>
        <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="btn btn-secondary btn-sm"><i class="bi bi-kanban me-1"></i> Pipeline</a>
        <a href="<?= url('modules/leads/leads_dashboard.php') ?>" class="btn btn-secondary btn-sm"><i class="bi bi-bar-chart me-1"></i> Analytics</a>
    </div>
</div>

<?php if ($flashMessage): ?>
    <div class="alert alert-<?= htmlspecialchars($flashClass) ?> alert-dismissible fade show mb-3" role="alert">
        <?= htmlspecialchars($flashMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Quick Actions & Handover Toolbar -->
<div class="crm-toolbar mb-3">
    <div class="crm-toolbar-left">
        <button type="button" class="btn btn-sm <?= ((int)($lead['forwarded_flag'] ?? 0) === 1) ? 'btn-success' : 'btn-secondary' ?>" id="forwardBtn">
            <i class="bi bi-send me-1"></i> Forward to Back Office: <strong><?= ((int)($lead['forwarded_flag'] ?? 0) === 1) ? 'Sent' : 'Not sent' ?></strong>
        </button>
        <button type="button" class="btn btn-sm <?= ((int)($lead['sent_backward_flag'] ?? 0) === 1) ? 'btn-warning' : 'btn-secondary' ?>" id="backwardBtn">
            <i class="bi bi-arrow-return-left me-1"></i> Send Backward: <strong><?= ((int)($lead['sent_backward_flag'] ?? 0) === 1) ? 'Sent' : 'N/A' ?></strong>
        </button>
        <?php if ($canEdit): ?>
        <button type="button" class="btn btn-sm btn-outline-primary" id="cloneBankBtn"><i class="bi bi-bank2 me-1"></i> Log in another bank</button>
        <?php endif; ?>
    </div>
    <div class="crm-toolbar-right">
        <?php if (!$canEdit && $canPull): ?>
            <button type="button" class="btn btn-warning btn-sm" id="pullBtn"><i class="bi bi-inbox me-1"></i> Pull into my tray</button>
        <?php elseif (!$canEdit): ?>
            <span class="badge bg-secondary"><i class="bi bi-lock me-1"></i> Read-only mode</span>
        <?php endif; ?>
    </div>
</div>

<!-- Zoho Workflow Progression Bar -->
<div class="card mb-3">
    <div class="card-body p-2 bg-subtle">
        <div class="d-flex align-items-center justify-content-between overflow-x-auto gap-2">
            <?php foreach ($workflowSteps as $stepIndex => $step): ?>
                <?php
                $isDone = $stepIndex < $currentStepIndex;
                $isActive = $stepIndex === $currentStepIndex;
                ?>
                <div class="d-flex align-items-center gap-2 p-2 border rounded bg-surface flex-grow-1" style="<?= $isActive ? 'border-color:var(--primary)!important; background-color:var(--primary-soft)!important;' : '' ?>">
                    <span class="metric-card-icon sm <?= $isDone ? 'success' : ($isActive ? '' : 'text-muted') ?>" style="width:24px; height:24px; font-size:0.75rem;">
                        <i class="bi <?= $isDone ? 'bi-check-lg' : htmlspecialchars($step['icon']) ?>"></i>
                    </span>
                    <div class="min-w-0">
                        <div class="fw-semibold small text-truncate <?= $isActive ? 'text-primary' : 'text-dark' ?>" style="font-size:0.75rem;"><?= htmlspecialchars($step['label']) ?></div>
                        <div class="text-muted" style="font-size:0.625rem;"><?= $isDone ? 'Completed' : ($isActive ? 'Current Stage' : 'Pending') ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Business Card Key Information Bar -->
<div class="row g-2 mb-3">
    <div class="col-md col-sm-4 col-6">
        <div class="p-2 border rounded bg-surface">
            <div class="text-muted text-uppercase" style="font-size:0.625rem; font-weight:600;">Customer Mobile</div>
            <div class="fw-bold text-dark small"><a href="tel:<?= htmlspecialchars($lead['mobile'] ?? '') ?>" class="text-decoration-none text-dark"><i class="bi bi-telephone text-primary me-1"></i><?= htmlspecialchars($lead['mobile'] ?? '-') ?></a></div>
        </div>
    </div>
    <div class="col-md col-sm-4 col-6">
        <div class="p-2 border rounded bg-surface">
            <div class="text-muted text-uppercase" style="font-size:0.625rem; font-weight:600;">Employer / Company</div>
            <div class="fw-bold text-dark small text-truncate"><?= htmlspecialchars($lead['company_name'] ?: '-') ?></div>
        </div>
    </div>
    <div class="col-md col-sm-4 col-6">
        <div class="p-2 border rounded bg-surface">
            <div class="text-muted text-uppercase" style="font-size:0.625rem; font-weight:600;">Loan Type</div>
            <div class="fw-bold text-dark small"><?= htmlspecialchars($lead['loan_type'] ?: '-') ?></div>
        </div>
    </div>
    <div class="col-md col-sm-4 col-6">
        <div class="p-2 border rounded bg-surface">
            <div class="text-muted text-uppercase" style="font-size:0.625rem; font-weight:600;">Requested Amount</div>
            <div class="fw-bold text-dark small"><?= htmlspecialchars($lead['loan_amount'] ?: '-') ?></div>
        </div>
    </div>
    <div class="col-md col-sm-4 col-6">
        <div class="p-2 border rounded bg-surface">
            <div class="text-muted text-uppercase" style="font-size:0.625rem; font-weight:600;">Next Follow-up</div>
            <div class="fw-bold text-dark small"><?= $lead['next_followup_at'] ? htmlspecialchars(date('d M Y', strtotime($lead['next_followup_at']))) : 'Not scheduled' ?></div>
        </div>
    </div>
    <div class="col-md col-sm-4 col-6">
        <div class="p-2 border rounded bg-surface">
            <div class="text-muted text-uppercase" style="font-size:0.625rem; font-weight:600;">App Number</div>
            <div class="fw-bold text-dark small"><?= htmlspecialchars($lead['loan_app_no'] ?: '-') ?></div>
        </div>
    </div>
</div>

<!-- Main Edit Form -->
<form method="POST" action="lead_update.php" class="lv-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" name="lead_id" value="<?= $leadId ?>">
    <input type="hidden" name="action" value="save_lead">

    <div class="row g-3">
        <!-- Col 1: Customer & Follow-up Details -->
        <div class="col-lg-6">
            <!-- Stage & Assignment -->
            <div class="card mb-3">
                <div class="card-header">
                    <span><i class="bi bi-arrow-repeat text-primary me-2"></i>Stage & Ownership</span>
                    <span class="badge bg-secondary"><?= htmlspecialchars($currentStatus) ?></span>
                </div>
                <div class="card-body p-3">
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label">Current Pipeline Status <span class="req">*</span></label>
                            <select name="lead_status_new" class="form-select">
                                <?php foreach ($statusOptions as $status): ?>
                                    <option value="<?= htmlspecialchars($status) ?>" <?= $currentStatus === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Assigned Agent</label>
                            <select name="assigned_to" class="form-select" <?= $canEditAssignment ? '' : 'disabled' ?>>
                                <?php foreach ($TBL_USERS as $user): ?>
                                    <option value="<?= (int)$user['ID'] ?>" <?= (int)$user['ID'] === (int)($lead['assigned_to'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($user['NAME']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$canEditAssignment): ?>
                                <input type="hidden" name="assigned_to" value="<?= (int)($lead['assigned_to'] ?? USER_ID) ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Next Scheduled Follow-up</label>
                            <input type="date" name="next_followup_at" class="form-control" value="<?= htmlspecialchars($lead['next_followup_at'] ? date('Y-m-d', strtotime($lead['next_followup_at'])) : '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Login Status</label>
                            <select name="login_status" class="form-select">
                                <option value="">—</option>
                                <option value="PENDING" <?= ($lead['login_status'] ?? '') === 'PENDING' ? 'selected' : '' ?>>Login Pending</option>
                                <option value="SUCCESS" <?= ($lead['login_status'] ?? '') === 'SUCCESS' ? 'selected' : '' ?>>Login Success</option>
                                <option value="REWORK_PENDING" <?= ($lead['login_status'] ?? '') === 'REWORK_PENDING' ? 'selected' : '' ?>>Rework Pending</option>
                                <option value="REJECTED" <?= ($lead['login_status'] ?? '') === 'REJECTED' ? 'selected' : '' ?>>Login Rejected</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Disposition Remark</label>
                            <textarea name="remarks" class="form-control" rows="2" placeholder="Lead status notes..."><?= htmlspecialchars($lead['remarks'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customer Personal & Financial Profile -->
            <div class="card mb-3">
                <div class="card-header">
                    <span><i class="bi bi-person text-primary me-2"></i>Customer & Employment Info</span>
                </div>
                <div class="card-body p-3">
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label">Customer Name</label>
                            <input type="text" name="customer_name" class="form-control" value="<?= htmlspecialchars($lead['customer_name'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Mobile Number</label>
                            <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($lead['mobile'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Employer / Company Name</label>
                            <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($lead['company_name'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Net Monthly Salary</label>
                            <input type="text" name="net_salary" class="form-control" value="<?= htmlspecialchars($lead['net_salary'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Salary Account Bank</label>
                            <input type="text" name="salary_account" class="form-control" value="<?= htmlspecialchars($lead['salary_account'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">DSA Channel Partner</label>
                            <input type="text" name="dsa_name" class="form-control" value="<?= htmlspecialchars($lead['dsa_name'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Other Customer Information</label>
                            <textarea name="other_info" class="form-control" rows="2"><?= htmlspecialchars($lead['other_info'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Col 2: Bank Login, Loan Details & Notes -->
        <div class="col-lg-6">
            <!-- Bank Login & Loan Parameters -->
            <div class="card mb-3">
                <div class="card-header">
                    <span><i class="bi bi-bank text-primary me-2"></i>Bank Login & Loan Terms</span>
                </div>
                <div class="card-body p-3">
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label">Login Date</label>
                            <input type="date" name="login_date" class="form-control" value="<?= htmlspecialchars((string)($lead['login_date'] ?? '')) ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Submission Mode</label>
                            <select name="login_mode" class="form-select">
                                <option value="">Select Mode</option>
                                <?php foreach ($loginModeOptions as $mode): ?>
                                    <option value="<?= htmlspecialchars($mode) ?>" <?= ($lead['login_mode'] ?? '') === $mode ? 'selected' : '' ?>><?= htmlspecialchars($mode) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Target Bank Name</label>
                            <input type="text" name="bank_name" list="bankOptions" class="form-control" value="<?= htmlspecialchars($lead['bank_name'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Login Location / Branch</label>
                            <input type="text" name="login_location" class="form-control" value="<?= htmlspecialchars($lead['login_location'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Sanction / Disbursed Bank</label>
                            <input type="text" name="login_bank_name" list="bankOptions" class="form-control" value="<?= htmlspecialchars($lead['login_bank_name'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Bank RM Name</label>
                            <input type="text" name="bank_rm_name" class="form-control" value="<?= htmlspecialchars($lead['bank_rm_name'] ?? '') ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label">Loan Amount</label>
                            <input type="text" name="loan_amount" class="form-control" value="<?= htmlspecialchars($lead['loan_amount'] ?? '') ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label">Tenure (Years/Months)</label>
                            <input type="text" name="loan_tenure" class="form-control" value="<?= htmlspecialchars($lead['loan_tenure'] ?? '') ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label">Loan Type</label>
                            <input type="text" name="loan_type" list="loanTypeOptions" class="form-control" value="<?= htmlspecialchars($lead['loan_type'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Loan Application No</label>
                            <input type="text" name="loan_app_no" class="form-control" value="<?= htmlspecialchars($lead['loan_app_no'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Promo / Scheme Code</label>
                            <input type="text" name="promo_code" class="form-control" value="<?= htmlspecialchars($lead['promo_code'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Balance Transfer (BT) Details</label>
                            <textarea name="bt_details" class="form-control" rows="2" placeholder="Existing loan transfer details..."><?= htmlspecialchars($lead['bt_details'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes & Follow-ups Accordion / Tabs -->
            <div class="card mb-3">
                <div class="card-header">
                    <span><i class="bi bi-chat-left-text text-primary me-2"></i>Activity Notes & Follow-up Log</span>
                </div>
                <div class="card-body p-3">
                    <!-- Threaded Notes -->
                    <div class="mb-3">
                        <div class="form-section-title"><i class="bi bi-sticky text-primary"></i> Team Notes</div>
                        <div class="p-2 border rounded bg-subtle mb-2" style="max-height: 180px; overflow-y: auto;">
                            <?php if (empty($notes)): ?>
                                <div class="text-muted small text-center py-2">No notes recorded yet.</div>
                            <?php else: ?>
                                <?php foreach ($notes as $n): ?>
                                    <div class="p-2 bg-surface border rounded mb-1">
                                        <div class="d-flex justify-content-between small text-muted mb-1" style="font-size:0.6875rem;">
                                            <strong><?= htmlspecialchars($n['author'] ?? 'System') ?></strong>
                                            <span><?= htmlspecialchars(date('d M Y, h:i A', strtotime($n['created_at']))) ?></span>
                                        </div>
                                        <div class="small text-dark"><?= nl2br(htmlspecialchars($n['note'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($canEdit): ?>
                        <div class="d-flex gap-2" id="noteFormContainer">
                            <input type="text" id="quickNoteText" class="form-control form-control-sm" placeholder="Write a quick note...">
                            <button type="button" id="quickNoteBtn" class="btn btn-secondary btn-sm"><i class="bi bi-send"></i> Add</button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Scheduled Follow-ups -->
                    <div>
                        <div class="form-section-title"><i class="bi bi-clock-history text-primary"></i> Follow-up Tasks</div>
                        <div class="p-2 border rounded bg-subtle mb-2" style="max-height: 180px; overflow-y: auto;">
                            <?php if (empty($followups)): ?>
                                <div class="text-muted small text-center py-2">No scheduled follow-ups.</div>
                            <?php else: ?>
                                <?php foreach ($followups as $f): ?>
                                    <div class="p-2 bg-surface border rounded mb-1 d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-semibold small text-dark"><i class="bi bi-alarm text-primary me-1"></i><?= htmlspecialchars(date('d M Y, h:i A', strtotime($f['followup_at']))) ?></div>
                                            <div class="text-muted" style="font-size:0.6875rem;"><?= htmlspecialchars($f['note'] ?: 'Follow-up call') ?></div>
                                        </div>
                                        <div>
                                            <?php if ($f['status'] === 'DONE'): ?>
                                                <span class="badge bg-success">Done</span>
                                            <?php elseif ($canEdit): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success lv-mini-done py-0 px-2" style="font-size:0.75rem;" data-id="<?= (int)$f['id'] ?>"><i class="bi bi-check-lg"></i> Done</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sticky Bottom Actions -->
    <div class="p-3 border rounded bg-surface d-flex justify-content-between align-items-center mt-3 position-sticky bottom-0 shadow-sm" style="z-index: 100;">
        <span class="text-muted small"><i class="bi bi-info-circle me-1"></i>Changes will be saved and recorded in the audit history.</span>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= url('modules/leads/lead_list.php') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary lv-save-btn"><i class="bi bi-check2-circle me-1"></i> Save Lead Changes</button>
        </div>
    </div>
</form>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

<script>
(function () {
    var canEdit = <?= $canEdit ? 'true' : 'false' ?>;

    if (!canEdit) {
        document.querySelectorAll('form.lv-form input, form.lv-form select, form.lv-form textarea').forEach(function (el) {
            el.disabled = true;
        });
        var saveBtn = document.querySelector('.lv-save-btn');
        if (saveBtn) saveBtn.style.display = 'none';
    }

    function lvPost(url, data, done) {
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j.error) { alert(j.message); }
            else if (done) { done(j); }
        })
        .catch(function (e) { alert('Request failed: ' + e.message); });
    }

    // Quick Add note
    var quickNoteBtn = document.getElementById('quickNoteBtn');
    if (quickNoteBtn) {
        quickNoteBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var input = document.getElementById('quickNoteText');
            if (!input || !input.value.trim()) return;
            lvPost('php_scripts/lead_note_add.php', {
                csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>',
                lead_id: <?= $leadId ?>,
                note: input.value.trim()
            }, function () { location.reload(); });
        });
    }

    // Mark follow-up done
    document.querySelectorAll('.lv-mini-done').forEach(function (btn) {
        btn.addEventListener('click', function () {
            lvPost('php_scripts/lead_followup_done.php', {
                csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>',
                id: btn.getAttribute('data-id')
            }, function () { location.reload(); });
        });
    });

    // Pull into my tray
    var pullBtn = document.getElementById('pullBtn');
    if (pullBtn) {
        pullBtn.addEventListener('click', function () {
            lvPost('php_scripts/lead_assign.php', {
                csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>',
                lead_id: <?= $leadId ?>, to_user_id: <?= (int)USER_ID ?>, reason: 'Pulled into my tray'
            }, function () { location.reload(); });
        });
    }

    // Forward to Back Office toggle
    var forwardBtn = document.getElementById('forwardBtn');
    if (forwardBtn) {
        forwardBtn.addEventListener('click', function () {
            lvPost('php_scripts/lead_forward_toggle.php', {
                csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>', lead_id: <?= $leadId ?>
            }, function (j) { location.reload(); });
        });
    }

    // Send Backward toggle
    var backwardBtn = document.getElementById('backwardBtn');
    if (backwardBtn) {
        backwardBtn.addEventListener('click', function () {
            lvPost('php_scripts/lead_send_backward_toggle.php', {
                csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>', lead_id: <?= $leadId ?>
            }, function () { location.reload(); });
        });
    }

    // Clone Bank
    var cloneBtn = document.getElementById('cloneBankBtn');
    if (cloneBtn) {
        cloneBtn.addEventListener('click', function () {
            if (!confirm('Create a new lead record for logging into another bank? The customer will be linked.')) return;
            lvPost('php_scripts/lead_clone_bank.php', {
                csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>', lead_id: <?= $leadId ?>
            }, function (j) {
                if (j.new_lead_id) location.href = 'lead_view?id=' + j.new_lead_id;
                else location.reload();
            });
        });
    }
})();
</script>
