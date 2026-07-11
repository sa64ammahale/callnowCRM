<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/lead_common.php';

$allowedRoles = ['Admin', 'Manager', 'Supervisor', 'Officer'];
if (!in_array(USER_ROLE, $allowedRoles, true)) {
    header('Location: ../../dashboard.php');
    exit;
}

ensureLeadModuleSchema($link);
mysqli_set_charset($link, 'utf8mb4');

$where = '';
$accessibleUserIds = getAccessibleUserIds($link);
if (!isAdmin() && !empty($accessibleUserIds)) {
    $where = 'WHERE l.assigned_to IN (' . implode(',', array_map('intval', $accessibleUserIds)) . ')';
}

$statusMeta = leadStatusMeta();
$statusCounts = array_fill_keys(array_keys($statusMeta), 0);

$countSql = "
    SELECT l.lead_status_new, COUNT(*) AS total_count
    FROM LEADS_TABLE l
    {$where}
    GROUP BY l.lead_status_new
";
$countResult = mysqli_query($link, $countSql);
while ($countResult && ($row = mysqli_fetch_assoc($countResult))) {
    $statusKey = strtoupper((string)$row['lead_status_new']);
    if (isset($statusCounts[$statusKey])) {
        $statusCounts[$statusKey] = (int)$row['total_count'];
    }
}

$totalLeads = array_sum($statusCounts);
$todayFollowups = (int)mysqli_fetch_row(mysqli_query(
    $link,
    "SELECT COUNT(*)
     FROM LEADS_TABLE l
     {$where}" . ($where ? ' AND ' : ' WHERE ') . "DATE(l.next_followup_at) = CURDATE()"
))[0];
$thisMonthLogins = (int)mysqli_fetch_row(mysqli_query(
    $link,
    "SELECT COUNT(*)
     FROM LEADS_TABLE l
     {$where}" . ($where ? ' AND ' : ' WHERE ') . "DATE_FORMAT(l.login_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"
))[0];
$activePipeline = $statusCounts['LEAD'] + $statusCounts['FOLLOWUP'] + $statusCounts['LOGIN'] + $statusCounts['UNDERWRTING'] + $statusCounts['SANCTIONED'];
$conversionBase = max(1, $statusCounts['DISBURSED'] + $activePipeline + $statusCounts['REJECT']);
$conversionRate = round(($statusCounts['DISBURSED'] / $conversionBase) * 100, 1);

$followupSql = "
    SELECT
        l.lead_id,
        l.next_followup_at,
        l.lead_status_new,
        l.remarks,
        COALESCE(m.MAINDATABASE_NAME, 'Unknown Name') AS customer_name,
        COALESCE(m.MAINDATABASE_MOBILE, 'N/A') AS mobile,
        COALESCE(u.NAME, 'Unassigned') AS assigned_name
    FROM LEADS_TABLE l
    LEFT JOIN MAIN_DATABASE m ON m.ID = l.cust_id
    LEFT JOIN USERS u ON u.ID = l.assigned_to
    {$where}
    " . ($where ? ' AND ' : ' WHERE ') . "DATE(l.next_followup_at) = CURDATE()
    ORDER BY l.next_followup_at ASC, l.updated_at DESC
    LIMIT 8
";
$todayLeads = mysqli_fetch_all(mysqli_query($link, $followupSql), MYSQLI_ASSOC);

$recentSql = "
    SELECT
        l.lead_id,
        l.lead_status_new,
        l.loan_type,
        l.login_mode,
        l.login_date,
        l.next_followup_at,
        COALESCE(m.MAINDATABASE_NAME, 'Unknown Name') AS customer_name,
        COALESCE(m.MAINDATABASE_COMPANY, 'No Company') AS company_name,
        COALESCE(m.MAINDATABASE_MOBILE, 'N/A') AS mobile,
        COALESCE(u.NAME, 'Unassigned') AS assigned_name
    FROM LEADS_TABLE l
    LEFT JOIN MAIN_DATABASE m ON m.ID = l.cust_id
    LEFT JOIN USERS u ON u.ID = l.assigned_to
    {$where}
    ORDER BY l.updated_at DESC, l.lead_id DESC
    LIMIT 12
";
$recentLeads = mysqli_fetch_all(mysqli_query($link, $recentSql), MYSQLI_ASSOC);

$journeySteps = leadJourneySteps();
?>
<?php $pageTitle = 'Lead Dashboard - CallNow'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<div class="container-fluid page-shell px-3 px-lg-4">
    <section class="top-overview">
        <div class="summary-row">
            <div class="metric-card metric-blue">
                <div class="metric-label">Total Leads</div>
                <div class="metric-value"><?= number_format($totalLeads) ?></div>
                <div class="metric-sub">Visible in <?= htmlspecialchars(USER_ROLE) ?> scope</div>
            </div>
            <div class="metric-card metric-mint">
                <div class="metric-label">Today Follow-ups</div>
                <div class="metric-value"><?= number_format($todayFollowups) ?></div>
                <div class="metric-sub">Immediate action queue</div>
            </div>
            <div class="metric-card metric-peach">
                <div class="metric-label">This Month Logins</div>
                <div class="metric-value"><?= number_format($thisMonthLogins) ?></div>
                <div class="metric-sub">Applications logged this month</div>
            </div>
            <div class="metric-card metric-lilac">
                <div class="metric-label">Conversion Rate</div>
                <div class="metric-value"><?= number_format($conversionRate, 1) ?>%</div>
                <div class="metric-sub">Disbursed vs total tracked outcomes</div>
            </div>
        </div>
        <div class="summary-actions">
            <div>
                <p class="summary-title">Lead Management Dashboard</p>
                <p class="summary-copy">Quick actions, softer colors, and your pipeline snapshot in one place.</p>
            </div>
            <div class="summary-action-group">
                <a href="lead_list.php" class="btn btn-primary">
                    <i class="bi bi-grid-3x3-gap-fill me-1"></i> Open Leads
                </a>
                <a href="lead_pipeline.php" class="btn btn-outline-primary">
                    <i class="bi bi-kanban me-1"></i> Pipeline Board
                </a>
                <a href="lead_insert.php" class="btn btn-outline-dark">
                    <i class="bi bi-plus-circle me-1"></i> Add New Lead
                </a>
            </div>
            <form method="get" action="lead_list.php" class="summary-search">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search customer, mobile, app id, company">
                <button type="submit" class="btn btn-dark btn-sm">
                    <i class="bi bi-search me-1"></i> Search Leads
                </button>
            </form>
        </div>
    </section>

    <section class="pipeline-band">
        <h2 class="section-title"><i class="bi bi-diagram-3-fill"></i> Lead Journey Snapshot</h2>
        <div class="journey-grid">
            <?php foreach ($journeySteps as $step): ?>
                <?php $meta = $statusMeta[$step['key']] ?? ['description' => '']; ?>
                <div class="journey-card">
                    <div class="journey-icon"><i class="bi <?= htmlspecialchars($step['icon']) ?>"></i></div>
                    <div class="journey-name"><?= htmlspecialchars($step['label']) ?></div>
                    <div class="journey-count"><?= number_format($statusCounts[$step['key']] ?? 0) ?></div>
                    <div class="journey-note"><?= htmlspecialchars($meta['description'] ?? '') ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="row g-3">
        <div class="col-xl-5">
            <section class="panel">
                <h2 class="section-title"><i class="bi bi-bell-fill"></i> Today's Follow-up Queue</h2>
                <?php if ($todayLeads): ?>
                    <div class="followup-list">
                        <?php foreach ($todayLeads as $lead): ?>
                            <article class="followup-item">
                                <div class="item-head">
                                    <div>
                                        <h3 class="item-title"><?= htmlspecialchars($lead['customer_name']) ?></h3>
                                        <p class="item-sub">
                                            <?= htmlspecialchars($lead['assigned_name']) ?> ·
                                            <?= $lead['next_followup_at'] ? date('d M Y h:i A', strtotime($lead['next_followup_at'])) : 'No follow-up time' ?>
                                        </p>
                                    </div>
                                    <span class="badge <?= leadStatusBadgeClass((string)$lead['lead_status_new']) ?>">
                                        <?= htmlspecialchars((string)$lead['lead_status_new']) ?>
                                    </span>
                                </div>
                                <div class="meta-pills">
                                    <a class="meta-pill text-decoration-none" href="tel:<?= htmlspecialchars($lead['mobile']) ?>">
                                        <i class="bi bi-telephone-fill"></i> <?= htmlspecialchars($lead['mobile']) ?>
                                    </a>
                                    <?php if (!empty($lead['remarks'])): ?>
                                        <span class="meta-pill"><i class="bi bi-chat-left-text"></i> <?= htmlspecialchars($lead['remarks']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-3">
                                    <a href="lead_view.php?id=<?= (int)$lead['lead_id'] ?>" class="btn btn-sm btn-primary rounded-pill px-3">
                                        <i class="bi bi-pencil-square me-1"></i> Open Lead
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-check2-all"></i>
                        No pending follow-ups for today in your scope.
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-xl-7">
            <section class="panel">
                <h2 class="section-title"><i class="bi bi-clock-history"></i> Recently Updated Leads</h2>
                <?php if ($recentLeads): ?>
                    <div class="recent-list">
                        <?php foreach ($recentLeads as $lead): ?>
                            <article class="recent-item">
                                <div class="item-head">
                                    <div>
                                        <h3 class="item-title"><?= htmlspecialchars($lead['customer_name']) ?></h3>
                                        <p class="item-sub">
                                            <?= htmlspecialchars($lead['company_name']) ?> · Assigned to <?= htmlspecialchars($lead['assigned_name']) ?>
                                        </p>
                                    </div>
                                    <span class="badge <?= leadStatusBadgeClass((string)$lead['lead_status_new']) ?>">
                                        <?= htmlspecialchars((string)$lead['lead_status_new']) ?>
                                    </span>
                                </div>
                                <div class="meta-pills">
                                    <a class="meta-pill text-decoration-none" href="tel:<?= htmlspecialchars($lead['mobile']) ?>">
                                        <i class="bi bi-telephone-fill"></i> <?= htmlspecialchars($lead['mobile']) ?>
                                    </a>
                                    <?php if (!empty($lead['loan_type'])): ?>
                                        <span class="meta-pill"><i class="bi bi-briefcase"></i> <?= htmlspecialchars($lead['loan_type']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($lead['login_mode'])): ?>
                                        <span class="meta-pill"><i class="bi bi-send-check"></i> <?= htmlspecialchars($lead['login_mode']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($lead['login_date'])): ?>
                                        <span class="meta-pill"><i class="bi bi-calendar2-event"></i> Login <?= date('d M Y', strtotime($lead['login_date'])) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($lead['next_followup_at'])): ?>
                                        <span class="meta-pill"><i class="bi bi-alarm"></i> Follow-up <?= date('d M Y', strtotime($lead['next_followup_at'])) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-3 d-flex gap-2 flex-wrap">
                                    <a href="lead_view.php?id=<?= (int)$lead['lead_id'] ?>" class="btn btn-sm btn-primary rounded-pill px-3">
                                        <i class="bi bi-pencil-square me-1"></i> Manage Lead
                                    </a>
                                    <a href="lead_pipeline.php#lead-<?= (int)$lead['lead_id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                        <i class="bi bi-kanban me-1"></i> View in Pipeline
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-inboxes"></i>
                        No lead records found in your current scope.
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
