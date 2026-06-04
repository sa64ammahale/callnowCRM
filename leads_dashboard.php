<?php
require_once 'php_scripts/auth.php';

$allowed_roles = ['Admin', 'Manager', 'Supervisor', 'Officer'];
if (!in_array(USER_ROLE, $allowed_roles)) {
    header("Location: ../dashboard.php");
    exit;
}

// Fix charset
mysqli_set_charset($link, 'utf8mb4');

// =============== QUERIES ===============
$total          = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM LEADS_TABLE"))[0];
$new            = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM LEADS_TABLE WHERE lead_status = 'New'"))[0];
$in_progress    = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM LEADS_TABLE WHERE lead_status = 'In_Progress'"))[0];
$converted      = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM LEADS_TABLE WHERE lead_status = 'Converted'"))[0];
$lost           = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM LEADS_TABLE WHERE lead_status = 'Lost'"))[0];
$today_followups= mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM LEADS_TABLE WHERE DATE(next_followup_at) = CURDATE()"))[0];

// Today's Follow-ups
$followup_sql = "
    SELECT 
        l.lead_id,
        l.next_followup_at,
        COALESCE(m.MAINDATABASE_NAME, 'Unknown Name') as name,
        COALESCE(m.MAINDATABASE_MOBILE, 'N/A') as mobile
    FROM LEADS_TABLE l
    LEFT JOIN MAIN_DATABASE m ON m.ID = l.cust_id
    WHERE DATE(l.next_followup_at) = CURDATE()
    ORDER BY l.next_followup_at ASC
    LIMIT 15
";

$followup_result = mysqli_query($link, $followup_sql);
$today_leads = mysqli_fetch_all($followup_result, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lead Dashboard - CallNow</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f7ff 0%, #e0f2fe 100%);
            min-height: 100vh;
        }

        /* Compact main container spacing */
        .page-wrapper {
            padding-top: 0.75rem;
            padding-bottom: 1.5rem;
        }

        /* Compact dashboard header */
        .dashboard-header {
            background: #ffffff;
            border-radius: 0.9rem;
            padding: 0.75rem 1.25rem;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .dashboard-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
        }
        .dashboard-subtitle {
            font-size: 0.8rem;
            color: #6b7280;
            margin: 0.1rem 0 0;
        }
        .dashboard-meta {
            font-size: 0.8rem;
            color: #4b5563;
        }

        .dashboard-actions .btn {
            font-size: 0.8rem;
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
        }

        /* Stat cards – more compact */
        .stat-card {
            background: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08);
            transition: all 0.25s;
            padding: 1.05rem 0.9rem;
            text-align: center;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
        }
        .icon-circle {
            width: 46px;
            height: 46px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin: 0 auto 0.5rem;
        }
        .stat-card h3 {
            font-size: 1.2rem;
            margin-bottom: 0.1rem;
        }
        .stat-card p {
            font-size: 0.75rem;
            margin: 0;
        }

        /* Follow-up list – slightly tighter */
        .followup-card {
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08);
        }
        .followup-item {
            border-left: 4px solid #3b82f6;
            background: #f9fafb;
            transition: all 0.2s;
            padding: 0.9rem 1rem;
        }
        .followup-item:hover {
            background: #e5f0ff;
            transform: translateX(3px);
        }
        .followup-name {
            font-size: 0.95rem;
        }
        .followup-meta {
            font-size: 0.75rem;
        }
        .followup-call-btn {
            font-size: 0.8rem;
            padding: 0.25rem 0.7rem;
        }

        .no-followup-card {
            border-radius: 1rem;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08);
            padding: 1.75rem 1rem;
        }

        .view-all-btn-bottom {
            font-size: 0.85rem;
            padding: 0.4rem 1.2rem;
            border-radius: 999px;
        }

        @media (max-width: 576px) {
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .dashboard-actions {
                width: 100%;
                display: flex;
                justify-content: flex-start;
                gap: 0.4rem;
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
<?php include 'php_scripts/header.php'; ?>

<div class="container page-wrapper">
    <!-- Compact Dashboard Header -->
    <div class="dashboard-header">
        <div>
            <p class="dashboard-title mb-1">
                <i class="bi bi-database-fill text-primary me-1"></i>
                Lead Management Dashboard
            </p>
            <p class="dashboard-subtitle">
                Welcome, 
                <strong><?= htmlspecialchars($_SESSION['name'] ?? USER_ROLE) ?></strong>
                <span class="text-muted">• <?= date("d M Y") ?></span>
            </p>
        </div>

        <div class="dashboard-actions d-flex align-items-center gap-2">
            <span class="dashboard-meta d-none d-sm-inline">
                Total Leads: <strong><?= number_format($total) ?></strong>
            </span>
            <a href="lead_list.php" class="btn btn-sm btn-success">
                <i class="bi bi-list-task me-1"></i> View Leads List
            </a>
            <a href="lead_insert.php" class="btn btn-sm btn-warning">
                <i class="bi bi-list-task me-1"></i> Add New Lead
            </a>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="stat-card">
                <div class="icon-circle bg-primary text-white"><i class="bi bi-people"></i></div>
                <h3 class="fw-bold text-primary mb-1"><?= number_format($total) ?></h3>
                <p class="text-muted">Total Leads</p>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="stat-card">
                <div class="icon-circle bg-info text-white"><i class="bi bi-clock"></i></div>
                <h3 class="fw-bold text-info mb-1"><?= number_format($new) ?></h3>
                <p class="text-muted">New</p>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="stat-card">
                <div class="icon-circle bg-warning text-white"><i class="bi bi-hourglass-split"></i></div>
                <h3 class="fw-bold text-warning mb-1"><?= number_format($in_progress) ?></h3>
                <p class="text-muted">In Progress</p>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="stat-card">
                <div class="icon-circle bg-success text-white"><i class="bi bi-check2-circle"></i></div>
                <h3 class="fw-bold text-success mb-1"><?= number_format($converted) ?></h3>
                <p class="text-muted">Converted</p>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="stat-card">
                <div class="icon-circle bg-danger text-white"><i class="bi bi-x-circle"></i></div>
                <h3 class="fw-bold text-danger mb-1"><?= number_format($lost) ?></h3>
                <p class="text-muted">Lost</p>
            </div>
        </div>
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="stat-card">
                <div class="icon-circle text-white" style="background:#8b5cf6;">
                    <i class="bi bi-bell"></i>
                </div>
                <h3 class="fw-bold mb-1" style="color:#8b5cf6;"><?= number_format($today_followups) ?></h3>
                <p class="text-muted">Today's Follow-ups</p>
            </div>
        </div>
    </div>

    <!-- Today's Follow-ups -->
    <?php if ($today_followups > 0): ?>
        <div class="card followup-card border-0 mt-2">
            <div class="card-header text-white py-2"
                 style="background: linear-gradient(135deg, #1e40af, #3b82f6);">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="bi bi-bell-fill me-1"></i>
                        Today's Follow-up Reminders (<?= $today_followups ?>)
                    </h6>
                    <small class="text-white-50 d-none d-sm-inline">
                        Showing latest <?= count($today_leads) ?> leads
                    </small>
                </div>
            </div>
            <div class="card-body p-0">
                <?php foreach ($today_leads as $lead): ?>
                    <div class="border-bottom followup-item">
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <div class="me-3">
                                <strong class="d-block followup-name">
                                    <?= htmlspecialchars($lead['name']) ?>
                                </strong>
                                <a href="tel:<?= htmlspecialchars($lead['mobile']) ?>" class="text-decoration-none">
                                    <span class="badge bg-light text-success border">
                                        <i class="bi bi-telephone-fill"></i>
                                        <?= htmlspecialchars($lead['mobile']) ?>
                                    </span>
                                </a>
                                <div class="followup-meta text-muted mt-1">
                                    <i class="bi bi-clock"></i>
                                    Follow-up at <?= date("h:i A", strtotime($lead['next_followup_at'])) ?>
                                </div>
                            </div>
                            <div class="mt-2 mt-sm-0">
                                <a href="lead_view.php?id=<?= $lead['lead_id'] ?>"
                                   class="btn btn-sm btn-primary followup-call-btn">
                                    <i class="bi bi-eye"></i> View Lead
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="no-followup-card text-center bg-white mt-3">
            <i class="bi bi-check2-all text-success" style="font-size: 3rem;"></i>
            <h5 class="mt-3 text-success mb-1">Great job! No pending follow-ups today.</h5>
            <p class="text-muted mb-0">All leads are up to date.</p>
        </div>
    <?php endif; ?>

    <!-- Optional: keep a smaller button at the bottom -->
    <div class="text-center mt-4">
        <a href="lead_list.php" class="btn btn-outline-primary view-all-btn-bottom">
            <i class="bi bi-list-check me-1"></i> Open Full Leads List
        </a>
    </div>
</div>

<?php include 'php_scripts/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
