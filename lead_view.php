<?php
require_once 'php_scripts/auth.php';
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { 
    header("Location: lead_list.php"); 
    exit; 
}
$lead_id = (int)$_GET['id'];

mysqli_set_charset($link, 'utf8mb4');

$lead_q = mysqli_query($link, "SELECT l.*, m.*, u.NAME as assigned_name 
                               FROM LEADS_TABLE l 
                               LEFT JOIN MAIN_DATABASE m ON m.ID = l.cust_id 
                               LEFT JOIN USERS u ON l.assigned_to = u.ID 
                               WHERE l.lead_id = $lead_id");
if (mysqli_num_rows($lead_q) == 0) { 
    die("Lead not found"); 
}
$lead = mysqli_fetch_assoc($lead_q);

// Activity Log
$log_q = mysqli_query($link, "SELECT * FROM ACTIVITY_LOG WHERE AFFECTED_IDS LIKE '%$lead_id%' ORDER BY LOG_TIME DESC LIMIT 20");
$logs = mysqli_fetch_all($log_q, MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lead #<?= $lead_id ?> - <?= htmlspecialchars($lead['MAINDATABASE_NAME'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f7ff 0%, #e0f2fe 100%);
            min-height: 100vh;
        }

        .page-wrapper {
            padding-top: 0.75rem;
            padding-bottom: 1.5rem;
        }

        /* Compact header bar */
        .page-header-bar {
            background: #ffffff;
            border-radius: 0.9rem;
            padding: 0.75rem 1.1rem;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.9rem;
        }
        .page-header-title {
            font-size: 1.05rem;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
        }
        .page-header-desc {
            font-size: 0.78rem;
            color: #6b7280;
            margin: 0.15rem 0 0;
        }
        .page-header-actions .btn {
            font-size: 0.78rem;
            padding: 0.25rem 0.7rem;
            border-radius: 999px;
        }

        /* Compact lead / side cards */
        .lead-card-main,
        .lead-card-side {
            background: #ffffff;
            border-radius: 0.9rem;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.09);
            padding: 1.1rem 1rem;
        }

        .status-badge {
            font-size: 0.8rem;
            padding: 0.25em 0.7em;
            border-radius: 999px;
        }

        .info-box {
            padding: 0.8rem 0.85rem;
            background: #f8fafc;
            border-radius: 0.75rem;
            border: 1px solid #e5e7eb;
        }

        .info-label {
            font-size: 0.78rem;
            color: #6b7280;
        }
        .info-value {
            font-size: 0.9rem;
        }

        /* Quick update + remarks compact */
        .section-title {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.35rem;
        }

        .form-select-sm, .form-control-sm {
            font-size: 0.8rem;
        }

        .btn-sm-wide {
            font-size: 0.8rem;
            padding: 0.35rem 0.8rem;
            border-radius: 999px;
        }

        /* Activity table */
        .activity-container {
            max-height: 500px;
            overflow-y: auto;
        }

        .table-sm th,
        .table-sm td {
            padding: 0.3rem 0.5rem;
            font-size: 0.78rem;
            vertical-align: top;
        }
        .table thead th {
            background: #1e40af;
            color: #ffffff;
            border-bottom: none;
        }

        @media (max-width: 576px) {
            .page-header-bar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<?php include 'php_scripts/header.php'; ?>

<div class="container page-wrapper">

    <!-- Compact header bar -->
    <div class="page-header-bar">
        <div>
            <p class="page-header-title mb-1">
                <i class="bi bi-person-lines-fill text-primary me-1"></i>
                Lead #<?= $lead_id ?> – <?= htmlspecialchars($lead['MAINDATABASE_NAME'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
            </p>
            <p class="page-header-desc mb-0">
                Status: 
                <strong><?= htmlspecialchars(str_replace('_', ' ', $lead['lead_status'] ?? 'New'), ENT_QUOTES, 'UTF-8') ?></strong>
                · Assigned to:
                <strong><?= htmlspecialchars($lead['assigned_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></strong>
            </p>
        </div>
        <div class="page-header-actions d-flex gap-2">
            <a href="lead_list.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list-ul"></i> Lead List
            </a>
            <a href="leads_dashboard.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-grid"></i> Go to Dashboard
            </a>
        </div>
    </div>

    <!-- Success Message -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3 py-2">
            <?= $_SESSION['success_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="lead-card-main">
                <!-- Title row inside card (more compact than before) -->
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <div class="fw-semibold">
                            <?= htmlspecialchars($lead['MAINDATABASE_NAME'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="text-muted small">
                            Company: <?= htmlspecialchars($lead['MAINDATABASE_COMPANY'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                    <div>
                        <?php
                        $status_color = match($lead['lead_status']) {
                            'Converted'   => 'success',
                            'Lost'        => 'danger',
                            'In_Progress' => 'warning',
                            'Follow_Up'   => 'info',
                            default       => 'primary'
                        };
                        ?>
                        <span class="badge bg-<?= $status_color ?> status-badge">
                            <?= htmlspecialchars(str_replace('_', ' ', $lead['lead_status'] ?? 'New'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                </div>

                <!-- Info boxes -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="info-box">
                            <div class="info-label mb-1">
                                <i class="bi bi-telephone-fill text-success"></i> Mobile
                            </div>
                            <div class="info-value">
                                <a href="tel:<?= htmlspecialchars($lead['MAINDATABASE_MOBILE'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   class="text-success text-decoration-none fw-semibold">
                                    <?= htmlspecialchars($lead['MAINDATABASE_MOBILE'] ?? 'Not Available', ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-box">
                            <div class="info-label mb-1">
                                <i class="bi bi-person-check"></i> Assigned To
                            </div>
                            <div class="info-value fw-semibold">
                                <?= htmlspecialchars($lead['assigned_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Update Form -->
                <div class="mt-3 p-3 border rounded bg-light">
                    <div class="section-title">
                        <i class="bi bi-lightning-charge-fill text-warning"></i> Quick Update
                    </div>
                    <form method="POST" action="lead_update.php">
                        <input type="hidden" name="lead_id" value="<?= $lead_id ?>">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Status</label>
                                <select name="lead_status" class="form-select form-select-sm" required>
                                    <option value="">Change Status</option>
                                    <option value="In_Progress" <?= ($lead['lead_status'] ?? '')=='In_Progress'?'selected':'' ?>>In Progress</option>
                                    <option value="Follow_Up"   <?= ($lead['lead_status'] ?? '')=='Follow_Up'  ?'selected':'' ?>>Follow Up</option>
                                    <option value="Converted"   <?= ($lead['lead_status'] ?? '')=='Converted'  ?'selected':'' ?>>Converted</option>
                                    <option value="Lost"        <?= ($lead['lead_status'] ?? '')=='Lost'       ?'selected':'' ?>>Lost</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small mb-1">Next Follow-up</label>
                                <input type="datetime-local"
                                       name="next_followup_at"
                                       class="form-control form-control-sm"
                                       value="<?= $lead['next_followup_at'] ? date('Y-m-d\TH:i', strtotime($lead['next_followup_at'])) : '' ?>">
                            </div>
                            <div class="col-md-3">
                                <button type="submit"
                                        name="action"
                                        value="update_status"
                                        class="btn btn-primary btn-sm-wide w-100 mt-2 mt-md-0">
                                    <i class="bi bi-save"></i> Update
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Remarks -->
                <div class="mt-3">
                    <div class="section-title">
                        <i class="bi bi-chat-left-dots"></i> Add Remark
                    </div>
                    <form method="POST" action="lead_update.php">
                        <input type="hidden" name="lead_id" value="<?= $lead_id ?>">
                        <textarea name="remarks"
                                  class="form-control form-control-sm"
                                  rows="3"
                                  placeholder="Type your notes here..."><?= htmlspecialchars($lead['remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        <button type="submit"
                                name="action"
                                value="add_remark"
                                class="btn btn-success btn-sm-wide mt-2">
                            <i class="bi bi-check-circle"></i> Save Remark
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Activity Log -->
        <div class="col-lg-4">
            <div class="lead-card-side">
                <div class="section-title mb-2">
                    <i class="bi bi-clock-history"></i> Activity History
                </div>
                <div class="activity-container">
                    <?php if (empty($logs)): ?>
                        <p class="text-muted small mb-0">No activity yet.</p>
                    <?php else: ?>
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 34%;">Time</th>
                                    <th style="width: 28%;">Action</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td>
                                            <small class="text-muted">
                                                <?= date("d M Y h:i A", strtotime($log['LOG_TIME'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small class="fw-semibold">
                                                <?= htmlspecialchars($log['ACTION_TYPE'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($log['ACTION_DETAILS'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'php_scripts/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
