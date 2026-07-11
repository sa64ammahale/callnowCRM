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
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lead Pipeline Board - CallNow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/app-theme.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(96, 165, 250, 0.22), transparent 24%),
                radial-gradient(circle at top right, rgba(45, 212, 191, 0.16), transparent 18%),
                linear-gradient(180deg, #f7fbff 0%, #e9f3ff 100%);
        }
        .page-shell {
            padding-top: 1rem;
            padding-bottom: 1.5rem;
        }
        .hero {
            background: rgba(255,255,255,0.94);
            border: 1px solid rgba(255,255,255,0.88);
            border-radius: 1.2rem;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
            padding: 1.1rem 1.2rem;
            margin-bottom: 1rem;
        }
        .hero-title {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 800;
        }
        .hero-copy {
            margin: 0.35rem 0 0;
            color: #64748b;
            font-size: 0.84rem;
        }
        .hero-actions {
            margin-top: 0.9rem;
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .hero-actions .btn {
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .board-scroll {
            overflow-x: auto;
            padding-bottom: 0.4rem;
        }
        .board-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(300px, 1fr));
            gap: 0.85rem;
            min-width: 2140px;
        }
        .stage-column {
            background: rgba(255,255,255,0.92);
            border: 1px solid rgba(255,255,255,0.84);
            border-radius: 1.15rem;
            box-shadow: 0 16px 38px rgba(15, 23, 42, 0.06);
            display: flex;
            flex-direction: column;
            min-height: 640px;
        }
        .stage-head {
            padding: 0.9rem;
            border-bottom: 1px solid #deebfb;
            background: linear-gradient(180deg, #ffffff, #f7fbff);
            border-top-left-radius: 1.15rem;
            border-top-right-radius: 1.15rem;
        }
        .stage-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.6rem;
            margin: 0;
            font-size: 0.85rem;
            font-weight: 800;
        }
        .stage-note {
            margin: 0.34rem 0 0;
            color: #64748b;
            font-size: 0.72rem;
            line-height: 1.35;
        }
        .stage-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.8rem;
            height: 1.8rem;
            border-radius: 999px;
            background: #eaf2ff;
            color: #1246b2;
            font-size: 0.73rem;
            font-weight: 800;
            padding: 0 0.45rem;
        }
        .stage-body {
            padding: 0.8rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            flex: 1;
        }
        .lead-card {
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            border: 1px solid #dbe7f6;
            border-radius: 1rem;
            padding: 0.85rem;
        }
        .lead-title {
            margin: 0;
            font-size: 0.82rem;
            font-weight: 800;
        }
        .lead-sub {
            margin: 0.18rem 0 0;
            color: #64748b;
            font-size: 0.72rem;
        }
        .lead-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            margin-top: 0.6rem;
        }
        .lead-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.32rem;
            padding: 0.28rem 0.56rem;
            border-radius: 999px;
            border: 1px solid #dbe7f6;
            background: #fff;
            color: #334155;
            font-size: 0.68rem;
            font-weight: 700;
        }
        .lead-actions {
            margin-top: 0.75rem;
        }
        .lead-actions .btn {
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
        }
        .empty-card {
            border: 1px dashed #d8e5f6;
            border-radius: 1rem;
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            padding: 1.2rem 1rem;
            color: #64748b;
            text-align: center;
            font-size: 0.76rem;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../../php_scripts/header.php'; ?>

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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
