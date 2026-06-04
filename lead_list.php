<?php
require_once 'php_scripts/auth.php';
$allowed_roles = ['Admin', 'Manager', 'Supervisor', 'Officer'];
if (!in_array(USER_ROLE, $allowed_roles)) { header("Location: leads_dashboard.php"); exit; }

// Fix charset
mysqli_set_charset($link, 'utf8mb4');

$limit = 50;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$where = "WHERE 1=1";
$params = []; $types = "";

$status   = $_GET['status']   ?? '';
$stage    = $_GET['stage']    ?? '';
$priority = $_GET['priority'] ?? '';
$search   = trim($_GET['search'] ?? '');

if ($status)  { $where .= " AND l.lead_status = ?";  $params[] = $status;  $types .= "s"; }
if ($stage)   { $where .= " AND l.lead_stage = ?";   $params[] = $stage;   $types .= "s"; }
if ($priority){ $where .= " AND l.priority = ?";     $params[] = $priority;$types .= "s"; }
if ($search) {
    $where .= " AND (m.MAINDATABASE_NAME LIKE ? OR m.MAINDATABASE_MOBILE LIKE ? OR m.MAINDATABASE_COMPANY LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s;
    $types .= "sss";
}

// Total count
$countSql = "SELECT COUNT(*) FROM LEADS_TABLE l 
             LEFT JOIN MAIN_DATABASE m ON m.ID = l.cust_id $where";
$stmt = mysqli_prepare($link, $countSql);
if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$totalRecords = mysqli_stmt_get_result($stmt)->fetch_row()[0];
$totalPages   = max(1, ceil($totalRecords / $limit));

// Fetch leads
$sql = "SELECT 
            l.lead_id, l.lead_status, l.lead_stage, l.priority, l.next_followup_at, l.followup_count,
            l.last_contacted_at, l.remarks,
            COALESCE(m.MAINDATABASE_NAME, 'Unknown') as name,
            COALESCE(m.MAINDATABASE_MOBILE, 'N/A') as mobile,
            COALESCE(m.MAINDATABASE_COMPANY, '-') as company,
            u.NAME as assigned_name
        FROM LEADS_TABLE l
        LEFT JOIN MAIN_DATABASE m ON m.ID = l.cust_id
        LEFT JOIN USERS u ON l.assigned_to = u.ID
        $where
        ORDER BY 
            CASE WHEN l.next_followup_at IS NOT NULL THEN 0 ELSE 1 END,
            l.next_followup_at ASC,
            l.updated_at DESC
        LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($link, $sql);
$finalParams = $params;
$finalParams[] = $limit; 
$finalParams[] = $offset;
$types .= "ii";
mysqli_stmt_bind_param($stmt, $types, ...$finalParams);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>All Leads - CallNow</title>

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
            font-size: 1.1rem;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
        }
        .page-header-desc {
            font-size: 0.78rem;
            color: #6b7280;
            margin: 0.1rem 0 0;
        }
        .page-header-meta {
            font-size: 0.78rem;
            color: #4b5563;
        }

        .page-header-actions .btn {
            font-size: 0.78rem;
            padding: 0.25rem 0.7rem;
            border-radius: 999px;
        }

        /* Filters – more compact */
        .filter-card {
            border-radius: 0.9rem;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
        }
        .filter-card .card-body {
            padding: 0.75rem 0.9rem;
        }
        .filter-label {
            font-size: 0.75rem;
            color: #6b7280;
            margin-bottom: 0.2rem;
        }
        .form-control-sm, .form-select-sm {
            font-size: 0.78rem;
        }

        /* Table – compact & striped */
        .table-wrapper {
            background: #ffffff;
            border-radius: 0.9rem;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }
        .table-sm th, 
        .table-sm td {
            padding: 0.3rem 0.5rem;
            font-size: 0.78rem;
            vertical-align: middle;
        }
        thead th {
            background: #1e40af;
            color: #ffffff;
            border-bottom: none;
        }
        .badge-status {
            font-size: 0.7rem;
            padding: 0.25em 0.6em;
            border-radius: 999px;
        }

        .btn-icon-sm {
            padding: 0.15rem 0.4rem;
            font-size: 0.75rem;
            border-radius: 999px;
        }

        .table-title-row {
            font-size: 0.75rem;
            color: #6b7280;
            padding: 0.4rem 0.9rem;
        }

        .pagination-sm .page-link {
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
        }

        @media (max-width: 576px) {
            .page-header-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            .page-header-actions {
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
    <!-- Compact header bar -->
    <div class="page-header-bar">
        <div>
            <p class="page-header-title mb-1">
                <i class="bi bi-people-fill text-primary me-1"></i>
                All Leads
            </p>
            <p class="page-header-desc mb-0">
                Total: <strong><?= number_format($totalRecords) ?></strong> • Page <?= $page ?> of <?= $totalPages ?>
            </p>
        </div>
        <div class="page-header-actions d-flex align-items-center gap-2">
            <span class="page-header-meta d-none d-sm-inline">
                Filter & manage all customer leads in one view.
            </span>
            <a href="leads_dashboard.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-grid me-1"></i> Go to Dashboard
            </a>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="card filter-card mb-3 border-0">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="filter-label">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Name / Mobile / Company"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="filter-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option <?= $status=='New'?'selected':'' ?>>New</option>
                        <option <?= $status=='In_Progress'?'selected':'' ?>>In Progress</option>
                        <option <?= $status=='Follow_Up'?'selected':'' ?>>Follow Up</option>
                        <option <?= $status=='Converted'?'selected':'' ?>>Converted</option>
                        <option <?= $status=='Lost'?'selected':'' ?>>Lost</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="filter-label">Stage</label>
                    <select name="stage" class="form-select form-select-sm">
                        <option value="">All Stage</option>
                        <option <?= $stage=='Hot'?'selected':'' ?>>Hot</option>
                        <option <?= $stage=='Warm'?'selected':'' ?>>Warm</option>
                        <option <?= $stage=='Cold'?'selected':'' ?>>Cold</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="filter-label">Priority</label>
                    <select name="priority" class="form-select form-select-sm">
                        <option value="">All Priority</option>
                        <option <?= $priority=='High'?'selected':'' ?>>High</option>
                        <option <?= $priority=='Medium'?'selected':'' ?>>Medium</option>
                        <option <?= $priority=='Low'?'selected':'' ?>>Low</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-funnel me-1"></i> Apply
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- Table -->
    <div class="table-wrapper">
        <div class="table-title-row d-flex justify-content-between">
            <span><i class="bi bi-list-ul me-1"></i> Leads List</span>
            <span class="d-none d-sm-inline">Showing <?= $limit ?> per page</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name / Mobile</th>
                        <th>Company</th>
                        <th>Status</th>
                        <th>Stage</th>
                        <th>Priority</th>
                        <th>Next Follow-up</th>
                        <th>Assigned</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($result) === 0): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-3">
                                <i class="bi bi-inbox me-1"></i> No leads found with current filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                        <tr>
                            <td><span class="text-muted">#<?= $row['lead_id'] ?></span></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($row['name']) ?></div>
                                <a href="tel:<?= htmlspecialchars($row['mobile']) ?>" class="text-decoration-none">
                                    <small><i class="bi bi-telephone text-success"></i> <?= htmlspecialchars($row['mobile']) ?></small>
                                </a>
                            </td>
                            <td>
                                <small><?= htmlspecialchars($row['company']) ?></small>
                            </td>
                            <td>
                                <?php
                                $status_color = match($row['lead_status']) {
                                    'New'        => 'primary',
                                    'In_Progress'=> 'warning',
                                    'Follow_Up'  => 'info',
                                    'Converted'  => 'success',
                                    'Lost'       => 'danger',
                                    default      => 'secondary'
                                };
                                ?>
                                <span class="badge bg-<?= $status_color ?> badge-status">
                                    <?= str_replace('_', ' ', $row['lead_status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['lead_stage']): ?>
                                    <span class="badge bg-info-subtle text-info badge-status">
                                        <?= htmlspecialchars($row['lead_stage']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $prio = $row['priority'];
                                $prio_color = $prio=='High' ? 'danger' : ($prio=='Medium' ? 'warning' : 'secondary');
                                ?>
                                <?php if ($prio): ?>
                                    <span class="badge bg-<?= $prio_color ?> badge-status"><?= htmlspecialchars($prio) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['next_followup_at']): ?>
                                    <?php 
                                        $isToday = (date('Y-m-d') == date('Y-m-d', strtotime($row['next_followup_at'])));
                                        $cls = $isToday ? 'text-danger' : 'text-muted';
                                    ?>
                                    <small class="<?= $cls ?>">
                                        <i class="bi bi-clock"></i>
                                        <?= date("d M Y h:i A", strtotime($row['next_followup_at'])) ?>
                                    </small>
                                <?php else: ?>
                                    <small class="text-muted">Not Set</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?= htmlspecialchars($row['assigned_name'] ?: 'Unassigned') ?></small>
                            </td>
                            <td class="text-center">
                                <a href="lead_view.php?id=<?= $row['lead_id'] ?>"
                                   class="btn btn-outline-primary btn-icon-sm" title="View Lead">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <?php
            // Build base query without 'page'
            $baseQuery = $_GET;
            unset($baseQuery['page']);
            $q = http_build_query($baseQuery);
            $q = $q ? '&'.$q : '';
        ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=1<?= $q ?>" aria-label="First">
                        <span aria-hidden="true"><i class="bi bi-chevron-double-left"></i></span>
                    </a>
                </li>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= max(1, $page-1) . $q ?>" aria-label="Previous">
                        <span aria-hidden="true"><i class="bi bi-chevron-left"></i></span>
                    </a>
                </li>

                <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i . $q ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>

                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= min($totalPages, $page+1) . $q ?>" aria-label="Next">
                        <span aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                    </a>
                </li>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $totalPages . $q ?>" aria-label="Last">
                        <span aria-hidden="true"><i class="bi bi-chevron-double-right"></i></span>
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php include 'php_scripts/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
