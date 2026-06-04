<?php

require_once 'php_scripts/auth.php';
/*if (!isSupervisor() || !isAdmin() || !isManager())
{
    header("location: dashboard.php"); exit;
}*/


// Get parameters
$user_id     = $_GET['ID'] ?? 0;
$report_type = $_GET['type'] ?? $_GET['REQUEST'] ?? 'daily';
$from        = $_GET['from'] ?? '';
$to          = $_GET['to'] ?? '';

// Build date condition (supports your new Reports.php links)
$date_condition = "";
if ($report_type === 'custom' && $from && $to) {
    $date_condition = "DATE(CALL_TIME) BETWEEN '$from' AND '$to'";
} elseif ($report_type === 'daily' || $report_type === 'Daily') {
    $date_condition = "DATE(CALL_TIME) = CURDATE()";
} elseif ($report_type === 'monthly' || $report_type === 'Monthly') {
    $date_condition = "MONTH(CALL_TIME) = MONTH(CURDATE()) AND YEAR(CALL_TIME) = YEAR(CURDATE())";
} elseif ($report_type === 'yearly' || $report_type === 'Yearly') {
    $date_condition = "YEAR(CALL_TIME) = YEAR(CURDATE())";
} else {
    $date_condition = "DATE(CALL_TIME) = CURDATE()";
}

// Secure query using prepared statement
$sql = "SELECT m.*, u.NAME as CALLER_NAME 
        FROM MAINDATABASE m 
        LEFT JOIN USERS u ON m.CALL_BY = u.ID 
        WHERE m.CALL_BY = ? AND $date_condition 
        ORDER BY m.CALL_TIME DESC";

$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$dial_data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $dial_data[] = $row;
}

$caller_name = $dial_data[0]['CALLER_NAME'] ?? "User #$user_id";
$total_calls = count($dial_data);

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dial List - <?= htmlspecialchars($caller_name) ?> - CallNow</title>
    
    <!-- Bootstrap 5 + Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <!-- Table Export -->
    <script src="https://cdn.jsdelivr.net/npm/table2excel@1.0.4/dist/table2excel.min.js"></script>
    
    <style>
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%); min-height: 100vh; }
        .page-header { background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: white; padding: 3rem 0; border-radius: 0 0 2rem 2rem; box-shadow: 0 10px 30px rgba(30,60,120,0.4); }
        .table-container { background: white; border-radius: 1.2rem; box-shadow: 0 15px 35px rgba(0,0,0,0.1); overflow: hidden; }
        .table thead { background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: white; }
        .status-dnd { background-color: #fee2e2; color: #991b1b; }
        .status-connected { background-color: #dcfce7; color: #166534; }
        .search-box { max-width: 400px; }
    </style>
</head>
<body>

<?php include 'php_scripts/header.php'; ?>

<div class="page-header text-center">
    <div class="container">
        <h1 class="display-5 fw-bold mb-2">
            <i class="bi bi-list-check"></i> Dial List - <?= htmlspecialchars($caller_name) ?>
        </h1>
        <p class="lead opacity-90">
            <?= ucfirst($report_type) ?> Report 
            <?php if ($report_type === 'custom'): ?>
                • <?= date("d M Y", strtotime($from)) ?> to <?= date("d M Y", strtotime($to)) ?>
            <?php else: ?>
                • <?= $report_type === 'daily' ? 'Today' : ($report_type === 'monthly' ? 'This Month' : 'This Year') ?>
            <?php endif; ?>
            <span class="badge bg-light text-dark ms-3 fs-6"><?= $total_calls ?> Calls</span>
        </p>
    </div>
</div>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-11">

            <!-- Search & Export Bar -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
                <div class="search-box position-relative">
                    <i class="bi bi-search position-absolute top-50 start-3 translate-middle-y text-muted"></i>
                    <input type="text" id="searchInput" class="form-control form-control-lg ps-5" placeholder="Search by name, mobile, company...">
                </div>
                <div class="d-flex gap-2">
                    <button id="exportBtn" class="btn btn-success btn-lg">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                    <a href="Reports.php?type=<?= $report_type ?>" class="btn btn-outline-primary btn-lg">
                        <i class="bi bi-arrow-left"></i> Back to Reports
                    </a>
                </div>
            </div>

            <!-- Dial Data Table -->
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="dialTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Mobile</th>
                                <th>Company</th>
                                <th>Package</th>
                                <th>Status</th>
                                <th>Upload Date</th>
                                <th>Call Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dial_data as $row): 
                                $status_class = (in_array($row['STATUS'], ['DND','NOI','BSY','ENQ','DEL'])) ? 'status-connected' : 'status-dnd';
                            ?>
                            <tr>
                                <td><strong>#<?= $row['ID'] ?></strong></td>
                                <td><?= htmlspecialchars($row['NAME'] ?? 'N/A') ?></td>
                                <td><a href="tel:<?= $row['MOBILE'] ?>"><?= $row['MOBILE'] ?></a></td>
                                <td><?= htmlspecialchars($row['COMPANY'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['PACKAGE'] ?? '-') ?></td>
                                <td><span class="badge <?= $status_class ?> px-3 py-2"><?= $row['STATUS'] ?></span></td>
                                <td><?= date("d M Y", strtotime($row['UPLOAD_DATE'])) ?></td>
                                <td><?= date("d M Y h:i A", strtotime($row['CALL_TIME'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($dial_data)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted fs-4">
                                    <i class="bi bi-telephone-x fs-1"></i><br>
                                    No calls found for this period
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'php_scripts/footer.php'; ?>

<script>
// Live Search
document.getElementById('searchInput').addEventListener('keyup', function() {
    const filter = this.value.toLowerCase();
    const rows = document.querySelectorAll('#dialTable tbody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
});

// Export to Excel
document.getElementById('exportBtn').addEventListener('click', function() {
    const table2excel = new Table2Excel();
    table2excel.export(document.querySelector('#dialTable'), 
        "DialList_<?= htmlspecialchars($caller_name) ?>_<?= date('d-m-Y') ?>.xlsx");
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>