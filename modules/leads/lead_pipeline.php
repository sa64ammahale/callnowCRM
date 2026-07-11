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

$statusMeta = leadStatusMeta();
$pipelineOrder = ['LEAD', 'FOLLOWUP', 'LOGIN', 'UNDERWRTING', 'SANCTIONED', 'DISBURSED', 'REJECT'];

$where = '';
$accessibleUserIds = getAccessibleUserIds($link);
if (!isAdmin() && !empty($accessibleUserIds)) {
    $where = 'WHERE l.assigned_to IN (' . implode(',', array_map('intval', $accessibleUserIds)) . ')';
}

$sql = "
    SELECT
        l.lead_id,
        l.lead_status_new,
        l.login_mode,
        l.loan_type,
        l.loan_amount,
        l.next_followup_at,
        l.login_bank_name,
        COALESCE(m.MAINDATABASE_NAME, 'Unknown Name') AS customer_name,
        COALESCE(m.MAINDATABASE_COMPANY, 'No Company') AS company_name,
        COALESCE(m.MAINDATABASE_MOBILE, 'N/A') AS mobile,
        COALESCE(u.NAME, 'Unassigned') AS assigned_name
    FROM LEADS_TABLE l
    LEFT JOIN MAIN_DATABASE m ON m.ID = l.cust_id
    LEFT JOIN USERS u ON u.ID = l.assigned_to
    {$where}
    ORDER BY FIELD(l.lead_status_new, 'LEAD', 'FOLLOWUP', 'LOGIN', 'UNDERWRTING', 'SANCTIONED', 'DISBURSED', 'REJECT'),
             l.updated_at DESC,
             l.lead_id DESC
";
$rows = mysqli_fetch_all(mysqli_query($link, $sql), MYSQLI_ASSOC);

$columns = [];
foreach ($pipelineOrder as $status) {
    $columns[$status] = [];
}
foreach ($rows as $row) {
    $statusKey = strtoupper((string)($row['lead_status_new'] ?? 'LEAD'));
    if (!isset($columns[$statusKey])) {
        $columns[$statusKey] = [];
    }
    $columns[$statusKey][] = $row;
}
?>
<?php $pageTitle = 'Lead Pipeline Board - CallNow'; include __DIR__ . '/../../php_scripts/header.php'; ?>

<div class="container-fluid page-shell px-3 px-lg-4">
    <section class="hero">
        <h1 class="hero-title">Lead Pipeline Board</h1>
        <p class="hero-copy">Use this stage-based board to see exactly where each case sits in the company workflow, from qualification through disbursal and closure.</p>
        <div class="hero-actions">
            <a href="leads_dashboard.php" class="btn btn-outline-primary"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a>
            <a href="lead_list.php" class="btn btn-primary"><i class="bi bi-table me-1"></i> Lead Register</a>
            <a href="lead_insert.php" class="btn btn-outline-dark"><i class="bi bi-plus-circle me-1"></i> Add Lead</a>
        </div>
        <form method="get" action="lead_list.php" class="d-flex gap-2 flex-wrap mt-3">
            <input type="text" name="q" class="form-control form-control-sm" style="max-width: 320px;" placeholder="Search customer, mobile, app id, company">
            <button type="submit" class="btn btn-dark btn-sm rounded-pill px-3">
                <i class="bi bi-search me-1"></i> Search Leads
            </button>
        </form>
    </section>

    <div class="board-scroll">
        <div class="board-grid">
            <?php foreach ($pipelineOrder as $status): ?>
                <?php $meta = $statusMeta[$status] ?? ['label' => $status, 'description' => '', 'icon' => 'bi-circle']; ?>
                <section class="stage-column">
                    <div class="stage-head">
                        <h2 class="stage-title">
                            <span><i class="bi <?= htmlspecialchars($meta['icon']) ?> me-1"></i><?= htmlspecialchars($meta['label']) ?></span>
                            <span class="stage-count"><?= number_format(count($columns[$status] ?? [])) ?></span>
                        </h2>
                        <p class="stage-note"><?= htmlspecialchars($meta['description']) ?></p>
                    </div>
                    <div class="stage-body">
                        <?php if (!empty($columns[$status])): ?>
                            <?php foreach ($columns[$status] as $lead): ?>
                                <article class="lead-card" id="lead-<?= (int)$lead['lead_id'] ?>">
                                    <h3 class="lead-title"><?= htmlspecialchars($lead['customer_name']) ?></h3>
                                    <p class="lead-sub"><?= htmlspecialchars($lead['company_name']) ?> · Assigned to <?= htmlspecialchars($lead['assigned_name']) ?></p>
                                    <div class="lead-pills">
                                        <a href="tel:<?= htmlspecialchars($lead['mobile']) ?>" class="lead-pill text-decoration-none">
                                            <i class="bi bi-telephone-fill"></i> <?= htmlspecialchars($lead['mobile']) ?>
                                        </a>
                                        <?php if (!empty($lead['loan_type'])): ?>
                                            <span class="lead-pill"><i class="bi bi-briefcase"></i> <?= htmlspecialchars($lead['loan_type']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($lead['login_mode'])): ?>
                                            <span class="lead-pill"><i class="bi bi-send-check"></i> <?= htmlspecialchars($lead['login_mode']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($lead['loan_amount'])): ?>
                                            <span class="lead-pill"><i class="bi bi-cash"></i> <?= htmlspecialchars($lead['loan_amount']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($lead['login_bank_name'])): ?>
                                            <span class="lead-pill"><i class="bi bi-bank"></i> <?= htmlspecialchars($lead['login_bank_name']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($lead['next_followup_at'])): ?>
                                            <span class="lead-pill"><i class="bi bi-alarm"></i> <?= date('d M', strtotime($lead['next_followup_at'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="lead-actions">
                                        <a href="lead_view.php?id=<?= (int)$lead['lead_id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-pencil-square me-1"></i> Manage
                                        </a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-card">
                                <i class="bi bi-inboxes d-block mb-2"></i>
                                No leads in this stage right now.
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
