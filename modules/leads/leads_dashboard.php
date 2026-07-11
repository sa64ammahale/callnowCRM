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
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lead Dashboard - CallNow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/app-theme.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --dash-bg: #f3f7f5;
            --dash-panel: rgba(255, 255, 255, 0.95);
            --dash-border: #d9e6e2;
            --dash-muted: #68778d;
            --dash-ink: #162033;
            --dash-accent: #3f7cff;
            --dash-accent-deep: #3653a6;
            --dash-soft: #f7fbfa;
            --dash-mint: #dff5eb;
            --dash-peach: #fff1e7;
            --dash-lilac: #eef0ff;
            --dash-sky: #e9f5ff;
        }
        body {
            font-family: 'Inter', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(120, 167, 255, 0.14), transparent 24%),
                radial-gradient(circle at top right, rgba(92, 205, 182, 0.14), transparent 20%),
                linear-gradient(180deg, #fbfdfc 0%, var(--dash-bg) 100%);
            min-height: 100vh;
            color: var(--dash-ink);
        }
        .page-shell {
            padding-top: 0.85rem;
            padding-bottom: 1.1rem;
        }
        .top-overview,
        .panel,
        .pipeline-band {
            background: var(--dash-panel);
            border: 1px solid rgba(255,255,255,0.84);
            border-radius: 1.05rem;
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07);
            backdrop-filter: blur(14px);
        }
        .top-overview {
            padding: 0.8rem 0.9rem;
            margin-bottom: 0.8rem;
        }
        .summary-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.65rem;
            align-items: stretch;
        }
        .metric-card {
            border-radius: 0.9rem;
            border: 1px solid var(--dash-border);
            background: linear-gradient(180deg, #ffffff, #fafcfe);
            padding: 0.72rem 0.82rem;
            min-height: 104px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .metric-label {
            color: var(--dash-muted);
            font-size: 0.66rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 800;
        }
        .metric-value {
            font-size: 1.55rem;
            font-weight: 800;
            margin-top: 0.22rem;
            line-height: 1.05;
        }
        .metric-sub {
            color: #334155;
            font-size: 0.7rem;
            margin-top: 0.18rem;
            line-height: 1.3;
        }
        .metric-card.metric-blue {
            background: linear-gradient(180deg, #ffffff, var(--dash-sky));
        }
        .metric-card.metric-mint {
            background: linear-gradient(180deg, #ffffff, var(--dash-mint));
        }
        .metric-card.metric-peach {
            background: linear-gradient(180deg, #ffffff, var(--dash-peach));
        }
        .metric-card.metric-lilac {
            background: linear-gradient(180deg, #ffffff, var(--dash-lilac));
        }
        .summary-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.85rem;
            margin-top: 0.7rem;
            flex-wrap: wrap;
        }
        .summary-title {
            margin: 0;
            font-size: 0.84rem;
            font-weight: 800;
            color: var(--dash-accent-deep);
        }
        .summary-copy {
            margin: 0.1rem 0 0;
            color: var(--dash-muted);
            font-size: 0.72rem;
        }
        .summary-action-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .summary-action-group .btn,
        .summary-search .btn {
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 800;
            padding: 0.5rem 0.82rem;
        }
        .summary-search {
            display: flex;
            gap: 0.45rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .summary-search .form-control {
            min-width: 230px;
            border-radius: 999px;
            border-color: var(--dash-border);
            background: rgba(255,255,255,0.92);
            padding: 0.5rem 0.85rem;
            font-size: 0.76rem;
        }
        .pipeline-band {
            padding: 0.72rem 0.82rem;
            margin-bottom: 0.8rem;
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(246,250,248,0.96));
        }
        .section-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.82rem;
            font-weight: 800;
            color: var(--dash-accent-deep);
            margin: 0 0 0.55rem;
        }
        .journey-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 0.55rem;
        }
        .journey-card {
            border-radius: 0.85rem;
            border: 1px solid var(--dash-border);
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            padding: 0.62rem 0.68rem;
        }
        .journey-icon {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e9f2ff;
            color: var(--dash-accent);
            font-size: 0.85rem;
            margin-bottom: 0.35rem;
        }
        .journey-name {
            font-size: 0.72rem;
            font-weight: 800;
        }
        .journey-count {
            font-size: 0.94rem;
            font-weight: 800;
            margin-top: 0.12rem;
        }
        .journey-note {
            color: var(--dash-muted);
            font-size: 0.64rem;
            margin-top: 0.12rem;
            line-height: 1.25;
        }
        .panel {
            padding: 0.78rem;
            height: 100%;
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(248,251,250,0.96));
        }
        .followup-list,
        .recent-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.6rem;
        }
        .followup-item,
        .recent-item {
            border-radius: 0.9rem;
            border: 1px solid var(--dash-border);
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            padding: 0.72rem 0.78rem;
        }
        .item-head {
            display: flex;
            justify-content: space-between;
            gap: 0.55rem;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .item-title {
            font-size: 0.78rem;
            font-weight: 800;
            margin: 0;
            line-height: 1.2;
        }
        .item-sub {
            font-size: 0.68rem;
            color: var(--dash-muted);
            margin: 0.12rem 0 0;
            line-height: 1.3;
        }
        .meta-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.42rem;
        }
        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.36rem;
            padding: 0.24rem 0.5rem;
            border-radius: 999px;
            border: 1px solid var(--dash-border);
            background: #ffffff;
            color: #334155;
            font-size: 0.64rem;
            font-weight: 700;
        }
        .empty-state {
            text-align: center;
            padding: 1.35rem 0.8rem;
            border: 1px dashed var(--dash-border);
            border-radius: 1rem;
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            color: var(--dash-muted);
            font-size: 0.74rem;
        }
        .empty-state i {
            font-size: 1.55rem;
            color: #22c55e;
            display: block;
            margin-bottom: 0.3rem;
        }
        @media (max-width: 1399px) {
            .summary-row,
            .journey-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (max-width: 991px) {
            .summary-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .journey-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .followup-list,
            .recent-list {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 767px) {
            .summary-row,
            .journey-grid {
                grid-template-columns: 1fr;
            }
            .summary-actions {
                align-items: flex-start;
            }
            .summary-search .form-control {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../../php_scripts/header.php'; ?>

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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
