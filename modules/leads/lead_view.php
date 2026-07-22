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
    WHERE l.lead_id = ?
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
    header('Location: lead_list.php');
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

<div class="lv-wrap">
    <!-- Header -->
    <div class="lv-header">
        <div class="lv-header-left">
            <div class="lv-header-icon"><i class="bi bi-person-lines-fill"></i></div>
            <div class="lv-header-info">
                <h1 class="lv-title"><?= htmlspecialchars($lead['customer_name'] ?? 'Lead #' . $leadId) ?></h1>
                <p class="lv-sub">
                    Lead #<?= $leadId ?>
                    &middot; Assigned to <strong><?= htmlspecialchars($lead['assigned_name'] ?? 'Unassigned') ?></strong>
                    &middot; <span class="badge <?= leadStatusBadgeClass($currentStatus) ?>"><?= htmlspecialchars($currentStatus) ?></span>
                    <?php if ($daysInStage > 0): ?>
                        &middot; <?= $daysInStage ?>d in stage
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="lv-header-actions">
            <a href="<?= url('modules/leads/lead_list.php') ?>" class="btn btn-outline-accent btn-sm"><i class="bi bi-list-ul me-1"></i> List</a>
            <a href="<?= url('modules/leads/lead_pipeline.php') ?>" class="btn btn-outline-accent btn-sm"><i class="bi bi-kanban me-1"></i> Pipeline</a>
            <a href="<?= url('modules/leads/leads_dashboard.php') ?>" class="btn btn-outline-accent btn-sm"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a>
        </div>
    </div>

    <!-- Action row: manual flags + multi-bank clone -->
    <div class="lv-actionrow">
        <button type="button" class="btn btn-sm <?= ((int)($lead['forwarded_flag'] ?? 0) === 1) ? 'btn-success' : 'btn-outline-secondary' ?>" id="forwardBtn">
            <i class="bi bi-send"></i> Forward to Back Office: <strong><?= ((int)($lead['forwarded_flag'] ?? 0) === 1) ? 'Sent' : 'Not sent' ?></strong>
        </button>
        <button type="button" class="btn btn-sm <?= ((int)($lead['sent_backward_flag'] ?? 0) === 1) ? 'btn-warning' : 'btn-outline-secondary' ?>" id="backwardBtn">
            <i class="bi bi-arrow-return-left"></i> Send Backward: <strong><?= ((int)($lead['sent_backward_flag'] ?? 0) === 1) ? 'Sent' : 'N/A' ?></strong>
        </button>
        <?php if ($canEdit): ?>
        <button type="button" class="btn btn-sm btn-outline-primary" id="cloneBankBtn"><i class="bi bi-bank2"></i> Log in another bank</button>
        <?php endif; ?>
    </div>
    </div>

    <!-- Workflow -->
    <div class="lv-workflow">
        <div class="lv-workflow-inner">
            <?php foreach ($workflowSteps as $stepIndex => $step): ?>
                <?php
                $stateClass = '';
                if ($stepIndex < $currentStepIndex) $stateClass = 'done';
                elseif ($stepIndex === $currentStepIndex) $stateClass = 'active';
                ?>
                <div class="lv-wf-step <?= $stateClass ?>">
                    <div class="lv-wf-dot"><i class="bi <?= htmlspecialchars($step['icon']) ?>"></i></div>
                    <div class="lv-wf-label"><?= htmlspecialchars($step['label']) ?></div>
                    <div class="lv-wf-sub"><?= $stepIndex < $currentStepIndex ? 'Done' : ($stepIndex === $currentStepIndex ? 'Current' : '') ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <div class="alert alert-<?= htmlspecialchars($flashClass) ?> alert-dismissible fade show mb-3 py-2 px-3 rounded-4">
            <?= htmlspecialchars($flashMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Tray / owner + rework banner -->
    <div class="lv-traybar">
        <div class="lv-tray-info">
            <span class="lv-tray-chip"><i class="bi bi-person"></i> Owner: <strong><?= htmlspecialchars($lead['assigned_name'] ?? 'Unassigned') ?></strong></span>
            <?php if (!empty($lead['team_name'])): ?>
                <span class="lv-tray-chip"><i class="bi bi-diagram-3"></i> Team: <strong><?= htmlspecialchars($lead['team_name']) ?></strong></span>
            <?php endif; ?>
            <?php if ((int)($lead['rework_flag'] ?? 0) === 1): ?>
                <span class="lv-tray-chip lv-chip-rework"><i class="bi bi-exclamation-triangle"></i> Rework &mdash; <?= $lead['rework_stage'] === 'BANK' ? 'Bank' : 'Internal' ?></span>
            <?php endif; ?>
            <?php if (!empty($lead['login_status'])): ?>
                <span class="lv-tray-chip"><i class="bi bi-bank"></i> Login: <strong><?= htmlspecialchars(loginStatusLabel($lead['login_status'])) ?></strong></span>
            <?php endif; ?>
            <?php if (!empty($lead['parent_lead_id'])): ?>
                <span class="lv-tray-chip"><i class="bi bi-link-45deg"></i> Duplicate of #<?= (int)$lead['parent_lead_id'] ?></span>
            <?php endif; ?>
        </div>
        <div class="lv-tray-actions">
            <?php if (!$canEdit && $canPull): ?>
                <button type="button" class="btn btn-warning btn-sm" id="pullBtn"><i class="bi bi-inbox"></i> Pull into my tray</button>
            <?php elseif (!$canEdit): ?>
                <span class="lv-tray-chip lv-chip-readonly"><i class="bi bi-eye"></i> Read-only (not in your tray)</span>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!$canEdit): ?>
        <div class="alert alert-warning py-2 px-3 rounded-4 mb-0"><i class="bi bi-lock"></i> This lead is in someone else's tray. <?= $canPull ? 'Pull it into your tray to edit.' : 'You have view-only access.' ?></div>
    <?php endif; ?>

    <!-- Summary Bar -->
    <div class="lv-summary">
        <div class="lv-summary-item">
            <span class="lv-summary-label">Mobile</span>
            <span class="lv-summary-value"><a href="tel:<?= htmlspecialchars($lead['mobile'] ?? '') ?>"><?= htmlspecialchars($lead['mobile'] ?? '-') ?></a></span>
        </div>
        <div class="lv-summary-item">
            <span class="lv-summary-label">Company</span>
            <span class="lv-summary-value"><?= htmlspecialchars($lead['company_name'] ?: '-') ?></span>
        </div>
        <div class="lv-summary-item">
            <span class="lv-summary-label">Loan Type</span>
            <span class="lv-summary-value"><?= htmlspecialchars($lead['loan_type'] ?: '-') ?></span>
        </div>
        <div class="lv-summary-item">
            <span class="lv-summary-label">Next Follow-up</span>
            <span class="lv-summary-value"><?= $lead['next_followup_at'] ? htmlspecialchars(date('d M Y', strtotime($lead['next_followup_at']))) : 'Not set' ?></span>
        </div>
        <div class="lv-summary-item">
            <span class="lv-summary-label">App No</span>
            <span class="lv-summary-value"><?= htmlspecialchars($lead['loan_app_no'] ?: '-') ?></span>
        </div>
    </div>

    <!-- Main Form -->
    <form method="POST" action="lead_update.php" class="lv-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="lead_id" value="<?= $leadId ?>">
        <input type="hidden" name="action" value="save_lead">

        <div class="lv-grid">
            <!-- Follow-up -->
            <div class="lv-card lv-card-followup">
                <div class="lv-card-head"><i class="bi bi-arrow-repeat"></i> Follow-up</div>
                <div class="lv-card-body">
                    <div class="mb-2">
                        <label class="lv-label">Status</label>
                        <select name="lead_status_new" class="lv-select">
                            <?php foreach ($statusOptions as $status): ?>
                                <option value="<?= htmlspecialchars($status) ?>" <?= $currentStatus === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Assigned To</label>
                        <select name="assigned_to" class="lv-select" <?= $canEditAssignment ? '' : 'disabled' ?>>
                            <?php foreach ($TBL_USERS as $user): ?>
                                <option value="<?= (int)$user['ID'] ?>" <?= (int)$user['ID'] === (int)($lead['assigned_to'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($user['NAME']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$canEditAssignment): ?>
                            <input type="hidden" name="assigned_to" value="<?= (int)($lead['assigned_to'] ?? USER_ID) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Next Follow-up</label>
                        <input type="date" name="next_followup_at" class="lv-input" value="<?= htmlspecialchars($lead['next_followup_at'] ? date('Y-m-d', strtotime($lead['next_followup_at'])) : '') ?>">
                    </div>
                    <div>
                        <label class="lv-label">Remark</label>
                        <textarea name="remarks" class="lv-input" rows="2"><?= htmlspecialchars($lead['remarks'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Customer -->
            <div class="lv-card lv-card-customer">
                <div class="lv-card-head"><i class="bi bi-person-badge"></i> Customer</div>
                <div class="lv-card-body">
                    <div class="mb-2">
                        <label class="lv-label">Name</label>
                        <input type="text" name="customer_name" class="lv-input" value="<?= htmlspecialchars($lead['customer_name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Mobile</label>
                        <input type="text" name="mobile" class="lv-input" value="<?= htmlspecialchars($lead['mobile'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Company</label>
                        <input type="text" name="company_name" class="lv-input" value="<?= htmlspecialchars($lead['company_name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Net Salary</label>
                        <input type="text" name="net_salary" class="lv-input" value="<?= htmlspecialchars($lead['net_salary'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Salary A/C</label>
                        <input type="text" name="salary_account" class="lv-input" value="<?= htmlspecialchars($lead['salary_account'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="lv-label">Other Info</label>
                        <textarea name="other_info" class="lv-input" rows="3"><?= htmlspecialchars($lead['other_info'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Login -->
            <div class="lv-card lv-card-login">
                <div class="lv-card-head"><i class="bi bi-box-arrow-in-right"></i> Login</div>
                <div class="lv-card-body">
                    <div class="mb-2">
                        <label class="lv-label">Date</label>
                        <input type="date" name="login_date" class="lv-input" value="<?= htmlspecialchars((string)($lead['login_date'] ?? '')) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Mode</label>
                        <select name="login_mode" class="lv-select">
                            <option value="">Select</option>
                            <?php foreach ($loginModeOptions as $mode): ?>
                                <option value="<?= htmlspecialchars($mode) ?>" <?= ($lead['login_mode'] ?? '') === $mode ? 'selected' : '' ?>><?= htmlspecialchars($mode) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Location</label>
                        <input type="text" name="login_location" class="lv-input" value="<?= htmlspecialchars($lead['login_location'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Bank Name</label>
                        <input type="text" name="bank_name" list="bankOptions" class="lv-input" value="<?= htmlspecialchars($lead['bank_name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Loan Amount</label>
                        <input type="text" name="loan_amount" class="lv-input" value="<?= htmlspecialchars($lead['loan_amount'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Tenure</label>
                        <input type="text" name="loan_tenure" class="lv-input" value="<?= htmlspecialchars($lead['loan_tenure'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Loan Type</label>
                        <input type="text" name="loan_type" list="loanTypeOptions" class="lv-input" value="<?= htmlspecialchars($lead['loan_type'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="lv-label">Promo Code</label>
                        <input type="text" name="promo_code" class="lv-input" value="<?= htmlspecialchars($lead['promo_code'] ?? '') ?>">
                    </div>
                    <div class="lv-rework-box">
                        <div class="mb-2">
                            <label class="lv-label">Login Status</label>
                            <select name="login_status" class="lv-select">
                                <option value="">—</option>
                                <option value="PENDING" <?= ($lead['login_status'] ?? '') === 'PENDING' ? 'selected' : '' ?>>Login Pending</option>
                                <option value="SUCCESS" <?= ($lead['login_status'] ?? '') === 'SUCCESS' ? 'selected' : '' ?>>Login Success</option>
                                <option value="REWORK_PENDING" <?= ($lead['login_status'] ?? '') === 'REWORK_PENDING' ? 'selected' : '' ?>>Rework Pending</option>
                                <option value="REJECTED" <?= ($lead['login_status'] ?? '') === 'REJECTED' ? 'selected' : '' ?>>Login Rejected</option>
                            </select>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="rework_flag" id="reworkFlag" value="1" <?= ((int)($lead['rework_flag'] ?? 0) === 1) ? 'checked' : '' ?>>
                            <label class="form-check-label lv-label" for="reworkFlag">Rework required</label>
                        </div>
                        <div class="mb-2">
                            <label class="lv-label">Rework Stage</label>
                            <select name="rework_stage" class="lv-select">
                                <option value="" <?= ($lead['rework_stage'] ?? '') === '' ? 'selected' : '' ?>>—</option>
                                <option value="INTERNAL" <?= ($lead['rework_stage'] ?? '') === 'INTERNAL' ? 'selected' : '' ?>>Internal</option>
                                <option value="BANK" <?= ($lead['rework_stage'] ?? '') === 'BANK' ? 'selected' : '' ?>>Bank</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sanction -->
            <div class="lv-card lv-card-sanction">
                <div class="lv-card-head"><i class="bi bi-cash-coin"></i> Sanction / Disbursal</div>
                <div class="lv-card-body">
                    <div class="mb-2">
                        <label class="lv-label">Disbursed Bank</label>
                        <input type="text" name="login_bank_name" list="bankOptions" class="lv-input" value="<?= htmlspecialchars($lead['login_bank_name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">Bank RM</label>
                        <input type="text" name="bank_rm_name" class="lv-input" value="<?= htmlspecialchars($lead['bank_rm_name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">BT Details</label>
                        <textarea name="bt_details" class="lv-input" rows="3"><?= htmlspecialchars($lead['bt_details'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="lv-label">App No</label>
                        <input type="text" name="loan_app_no" class="lv-input" value="<?= htmlspecialchars($lead['loan_app_no'] ?? '') ?>">
                    </div>
                <div>
                    <label class="lv-label">DSA Name</label>
                    <input type="text" name="dsa_name" class="lv-input" value="<?= htmlspecialchars($lead['dsa_name'] ?? '') ?>">
                </div>
            </div>

            <!-- Activity Notes -->
            <div class="lv-card lv-card-notes">
                <div class="lv-card-head"><i class="bi bi-chat-left-text"></i> Activity Notes</div>
                <div class="lv-card-body">
                    <div class="lv-notes-list" id="notesList">
                        <?php if (empty($notes)): ?>
                            <div class="lv-note-empty">No notes yet.</div>
                        <?php else: ?>
                            <?php foreach ($notes as $n): ?>
                                <div class="lv-note">
                                    <div class="lv-note-meta"><strong><?= htmlspecialchars($n['author'] ?? 'System') ?></strong> &middot; <?= htmlspecialchars(date('d M Y, h:i A', strtotime($n['created_at']))) ?></div>
                                    <div class="lv-note-text"><?= nl2br(htmlspecialchars($n['note'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($canEdit): ?>
                    <form id="noteForm" class="lv-note-form mt-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="lead_id" value="<?= $leadId ?>">
                        <textarea name="note" class="lv-input" rows="2" placeholder="Add a note..." required></textarea>
                        <button type="submit" class="lv-mini-btn"><i class="bi bi-send"></i> Add Note</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Follow-ups -->
            <div class="lv-card lv-card-followups">
                <div class="lv-card-head"><i class="bi bi-calendar-check"></i> Follow-ups</div>
                <div class="lv-card-body">
                    <div class="lv-followup-list" id="followupList">
                        <?php if (empty($followups)): ?>
                            <div class="lv-note-empty">No follow-ups scheduled.</div>
                        <?php else: ?>
                            <?php foreach ($followups as $f): ?>
                                <div class="lv-followup <?= $f['status'] === 'DONE' ? 'done' : '' ?>">
                                    <div class="lv-followup-meta"><strong><?= htmlspecialchars(date('d M Y, h:i A', strtotime($f['followup_at']))) ?></strong> &middot; <?= htmlspecialchars($f['status']) ?></div>
                                    <div class="lv-note-text"><?= nl2br(htmlspecialchars($f['note'] ?? '')) ?></div>
                                    <?php if ($canEdit && $f['status'] === 'OPEN'): ?>
                                        <button type="button" class="lv-mini-btn lv-mini-done" data-id="<?= (int)$f['id'] ?>"><i class="bi bi-check-lg"></i> Mark Done</button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($canEdit): ?>
                    <form id="followupForm" class="lv-note-form mt-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="lead_id" value="<?= $leadId ?>">
                        <div class="row g-2">
                            <div class="col-7"><input type="datetime-local" name="followup_at" class="lv-input" required></div>
                            <div class="col-5"><button type="submit" class="lv-mini-btn w-100"><i class="bi bi-plus-lg"></i> Schedule</button></div>
                        </div>
                        <textarea name="note" class="lv-input mt-2" rows="2" placeholder="Follow-up note..."></textarea>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </div>

        <div class="lv-footer">
            <button type="submit" class="lv-save-btn"><i class="bi bi-save me-2"></i> Save Lead</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
<script>
(function () {
    var canEdit = <?= $canEdit ? 'true' : 'false' ?>;

    // Read-only: disable all form fields when not in tray
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

    // Add note
    var noteForm = document.getElementById('noteForm');
    if (noteForm) {
        noteForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var f = e.target;
            lvPost('php_scripts/lead_note_add.php', {
                csrf_token: f.csrf_token.value, lead_id: f.lead_id.value, note: f.note.value
            }, function () { location.reload(); });
        });
    }

    // Schedule follow-up
    var fuForm = document.getElementById('followupForm');
    if (fuForm) {
        fuForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var f = e.target;
            lvPost('php_scripts/lead_followup_add.php', {
                csrf_token: f.csrf_token.value, lead_id: f.lead_id.value,
                followup_at: f.followup_at.value, note: f.note.value
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

    // Forward to Back Office toggle (manual flag, no email)
    var forwardBtn = document.getElementById('forwardBtn');
    if (forwardBtn) {
        forwardBtn.addEventListener('click', function () {
            lvPost('php_scripts/lead_forward_toggle.php', {
                csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>', lead_id: <?= $leadId ?>
            }, function (j) { location.reload(); });
        });
    }

    // Send Backward toggle (manual flag)
    var backwardBtn = document.getElementById('backwardBtn');
    if (backwardBtn) {
        backwardBtn.addEventListener('click', function () {
            lvPost('php_scripts/lead_send_backward_toggle.php', {
                csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>', lead_id: <?= $leadId ?>
            }, function () { location.reload(); });
        });
    }

    // Log in another bank (clone)
    var cloneBtn = document.getElementById('cloneBankBtn');
    if (cloneBtn) {
        cloneBtn.addEventListener('click', function () {
            if (!confirm('Create a new lead record for logging into another bank? The customer will be linked.')) return;
            lvPost('php_scripts/lead_clone_bank.php', {
                csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>', lead_id: <?= $leadId ?>
            }, function (j) {
                if (j.new_lead_id) location.href = 'lead_view.php?id=' + j.new_lead_id;
                else location.reload();
            });
        });
    }
})();
</script>

<style>
.lv-wrap {
    max-width: 1400px; margin: 0 auto; padding: 1.25rem 1.5rem 2rem;
    display: flex; flex-direction: column; gap: 1rem;
}
.lv-header {
    display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;
}
.lv-header-left { display: flex; align-items: center; gap: 0.875rem; }
.lv-header-icon {
    width: 44px; height: 44px; border-radius: var(--radius-lg);
    background: linear-gradient(135deg, #5e6ad2, #3b82f6); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem; flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(94,106,210,0.3);
}
.lv-title { font-size: 1.25rem; font-weight: 700; margin: 0 0 0.125rem; color: var(--ink); }
.lv-sub { font-size: 0.75rem; color: var(--ink-muted); margin: 0; display: flex; align-items: center; gap: 0.375rem; flex-wrap: wrap; }
.lv-header-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }

.lv-workflow {
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-xl);
    padding: 0.75rem 1.25rem; overflow-x: auto;
}
.lv-workflow-inner { display: flex; gap: 0; min-width: max-content; }
.lv-wf-step {
    display: flex; flex-direction: column; align-items: center; gap: 0.25rem;
    position: relative; padding: 0.5rem 1rem; min-width: 80px;
}
.lv-wf-step::after {
    content: ''; position: absolute; top: 50%; left: 100%; width: 100%;
    height: 2px; background: var(--border); transform: translateY(-50%); z-index: 0;
}
.lv-wf-step:last-child::after { display: none; }
.lv-wf-step.done::after { background: #10b981; }
.lv-wf-step.active::after { background: linear-gradient(90deg, var(--accent) 50%, var(--border) 50%); }
.lv-wf-dot {
    width: 32px; height: 32px; border-radius: 50%;
    border: 2px solid var(--border); background: var(--surface);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8125rem; color: var(--ink-muted); position: relative; z-index: 1;
    transition: all 0.2s ease;
}
.lv-wf-step.done .lv-wf-dot { border-color: #10b981; background: #10b981; color: #fff; }
.lv-wf-step.active .lv-wf-dot { border-color: var(--accent); background: var(--accent); color: #fff; box-shadow: 0 0 0 4px var(--accent-soft); }
.lv-wf-label { font-size: 0.6875rem; font-weight: 600; color: var(--ink-soft); white-space: nowrap; }
.lv-wf-sub { font-size: 0.5625rem; color: var(--ink-muted); }
.lv-wf-step.done .lv-wf-label { color: #10b981; }
.lv-wf-step.active .lv-wf-label { color: var(--accent); font-weight: 700; }

.lv-summary {
    display: flex; gap: 0.75rem; flex-wrap: wrap;
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-xl);
    padding: 0.75rem 1.25rem;
}
.lv-summary-item { display: flex; flex-direction: column; gap: 0.125rem; min-width: 100px; flex: 1; }
.lv-summary-label { font-size: 0.5625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-muted); }
.lv-summary-value { font-size: 0.8125rem; font-weight: 600; color: var(--ink); }
.lv-summary-value a { color: var(--accent); text-decoration: none; }
.lv-summary-value a:hover { text-decoration: underline; }

.lv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1rem; }
.lv-card {
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-xl);
    box-shadow: var(--shadow-sm); overflow: hidden; transition: box-shadow 0.15s ease;
}
.lv-card:hover { box-shadow: var(--shadow-md); }
.lv-card-head {
    padding: 0.625rem 1rem; font-size: 0.8125rem; font-weight: 700;
    display: flex; align-items: center; gap: 0.5rem;
    border-bottom: 1px solid var(--border); text-transform: uppercase; letter-spacing: 0.03em;
}
.lv-card-followup .lv-card-head { background: linear-gradient(135deg, rgba(14,165,233,0.06), rgba(99,102,241,0.06)); color: #0ea5e9; }
.lv-card-customer .lv-card-head { background: linear-gradient(135deg, rgba(94,106,210,0.06), rgba(59,130,246,0.06)); color: #5e6ad2; }
.lv-card-login .lv-card-head { background: linear-gradient(135deg, rgba(245,158,11,0.06), rgba(239,68,68,0.06)); color: #f59e0b; }
.lv-rework-box { margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px dashed var(--border); }
.lv-card-sanction .lv-card-head { background: linear-gradient(135deg, rgba(16,185,129,0.06), rgba(5,150,105,0.06)); color: #10b981; }
.lv-card-body { padding: 0.75rem 1rem; }
.lv-label { font-size: 0.625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-muted); display: block; margin-bottom: 0.1875rem; }
.lv-input, .lv-select {
    width: 100%; padding: 0.4375rem 0.5625rem;
    border: 1px solid var(--border); border-radius: var(--radius-lg);
    background: var(--surface); color: var(--ink); font-size: 0.8125rem;
    outline: none; transition: border-color 0.12s ease;
}
.lv-input:focus, .lv-select:focus { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent-soft); }
.lv-select { cursor: pointer; }
.lv-footer {
    display: flex; justify-content: flex-end; padding-top: 0.75rem;
    position: sticky; bottom: 1rem; z-index: 10;
}
.lv-save-btn {
    display: inline-flex; align-items: center;
    padding: 0.75rem 2rem; font-size: 0.9375rem; font-weight: 700;
    border: none; border-radius: var(--radius-xl);
    background: linear-gradient(135deg, var(--accent), #4f46e5);
    color: #fff; cursor: pointer;
    box-shadow: 0 4px 14px rgba(94,106,210,0.35);
    transition: all 0.15s ease; text-transform: uppercase; letter-spacing: 0.04em;
}
.lv-save-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(94,106,210,0.45);
    background: linear-gradient(135deg, #4f46e5, var(--accent));
}
.lv-save-btn:active { transform: translateY(0); box-shadow: 0 2px 8px rgba(94,106,210,0.3); }

.lv-traybar { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-xl); padding: 0.625rem 1rem; }
.lv-actionrow { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.lv-actionrow .btn { font-weight: 600; }
.lv-tray-info { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
.lv-tray-chip { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.6875rem; font-weight: 600; background: var(--surface-2, #f1f3f9); color: var(--ink-soft); padding: 0.25rem 0.6rem; border-radius: 999px; border: 1px solid var(--border); }
.lv-chip-rework { background: rgba(239,68,68,0.12); color: #ef4444; border-color: #fca5a5; }
.lv-chip-readonly { background: rgba(245,158,11,0.12); color: #f59e0b; border-color: #fcd34d; }
.lv-card-notes .lv-card-head { background: linear-gradient(135deg, rgba(124,58,237,0.06), rgba(99,102,241,0.06)); color: #7c3aed; }
.lv-card-followups .lv-card-head { background: linear-gradient(135deg, rgba(14,165,233,0.06), rgba(16,185,129,0.06)); color: #0ea5e9; }
.lv-notes-list { display: flex; flex-direction: column; gap: 0.5rem; max-height: 260px; overflow-y: auto; }
.lv-note-empty { font-size: 0.75rem; color: var(--ink-muted); padding: 0.5rem 0; }
.lv-note { background: var(--surface-2, #f7f8fc); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 0.5rem 0.625rem; }
.lv-note-meta { font-size: 0.625rem; color: var(--ink-muted); margin-bottom: 0.15rem; }
.lv-note-text { font-size: 0.8125rem; color: var(--ink); white-space: pre-wrap; word-break: break-word; }
.lv-followup { background: var(--surface-2, #f7f8fc); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 0.5rem 0.625rem; margin-bottom: 0.5rem; }
.lv-followup.done { opacity: 0.6; }
.lv-followup-meta { font-size: 0.625rem; color: var(--ink-muted); margin-bottom: 0.15rem; }
.lv-note-form textarea { margin-bottom: 0.4rem; }
.lv-mini-btn { display: inline-flex; align-items: center; gap: 0.35rem; margin-top: 0.4rem; padding: 0.35rem 0.8rem; font-size: 0.75rem; font-weight: 600; border: none; border-radius: var(--radius-lg); background: var(--accent); color: #fff; cursor: pointer; }
.lv-mini-btn:hover { background: var(--accent-hover, #4f46e5); }
.lv-mini-done { background: #10b981; }
.lv-mini-done:hover { background: #059669; }

@media (max-width: 768px) {
    .lv-wrap { padding: 0.75rem; gap: 0.75rem; }
    .lv-header { flex-direction: column; align-items: flex-start; }
    .lv-header-actions { width: 100%; }
    .lv-header-actions .btn { flex: 1; text-align: center; }
    .lv-grid { grid-template-columns: 1fr; }
    .lv-workflow { padding: 0.5rem 0.75rem; }
}
</style>
