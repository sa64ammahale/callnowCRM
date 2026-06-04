<?php
// ViewDND.php - DND list with pagination, search, edit, delete (Admin-only)

require_once 'php_scripts/auth.php';
requireRole('Admin'); // ensures only Admin can access

// CSRF token for delete forms
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$userRole = $_SESSION['role'] ?? '';
$userId = intval($_SESSION['id'] ?? 0);

// Handle deletion (POST)
$alertMsg = $alertType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' ) {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $alertMsg = "CSRF validation failed.";
        $alertType = "danger";
    } else {
        if ($userRole !== 'Admin') {
            $alertMsg = "You don't have permission to delete records.";
            $alertType = "danger";
        } else {
            $delId = intval($_POST['id'] ?? 0);
            if ($delId > 0) {
                $delSql = "DELETE FROM MAIN_DATABASE WHERE ID = ?";
                if ($ds = mysqli_prepare($link, $delSql)) {
                    mysqli_stmt_bind_param($ds, "i", $delId);
                    if (mysqli_stmt_execute($ds)) {
                        $alertMsg = "Record deleted successfully.";
                        $alertType = "success";
                    } else {
                        $alertMsg = "Database error while deleting.";
                        $alertType = "danger";
                    }
                    mysqli_stmt_close($ds);
                } else {
                    $alertMsg = "Database prepare error: " . mysqli_error($link);
                    $alertType = "danger";
                }
            } else {
                $alertMsg = "Invalid record id.";
                $alertType = "warning";
            }
        }
    }
}

// --- Pagination & Search setup
$limit = 25; // items per page
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');

// Build WHERE clause and params
$whereClauses = ["MAINDATABASE_CALL_DIALED_STATUS = 'Do Not Call'"];
$params = []; // values
$types = '';  // types string

if ($search !== '') {
    // we'll search mobile, name, company
    $whereClauses[] = "(MAINDATABASE_MOBILE LIKE ? OR MAINDATABASE_NAME LIKE ? OR MAINDATABASE_COMPANY LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= 'sss';
}

$whereSql = implode(' AND ', $whereClauses);

// --- Count total
$countSql = "SELECT COUNT(*) AS cnt FROM MAIN_DATABASE WHERE $whereSql";
$totalRecords = 0;
if ($stmt = mysqli_prepare($link, $countSql)) {
    if ($types !== '') {
        // bind dynamically
        $bind_names = [];
        $bind_names[] = $types;
        foreach ($params as $k => $v) $bind_names[] = &$params[$k];
        call_user_func_array([$stmt, 'bind_param'], $bind_names);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $totalRecords = intval($row['cnt'] ?? 0);
    }
    mysqli_stmt_close($stmt);
}
$totalPages = max(1, (int)ceil($totalRecords / $limit));
if ($page > $totalPages) $page = $totalPages; // guard

// --- Fetch current page data
$selectSql = "SELECT ID, MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_CALL_DIAL_TIME
              FROM MAIN_DATABASE
              WHERE $whereSql
              ORDER BY MAINDATABASE_CALL_DIAL_TIME DESC
              LIMIT ? OFFSET ?";

$dndRecords = [];
if ($stmt = mysqli_prepare($link, $selectSql)) {

    // prepare bind arguments
    if ($types === '') {
        // only limit & offset
        mysqli_stmt_bind_param($stmt, "ii", $limit, $offset);
    } else {
        // types + 'ii' for limit/offset
        $bindTypes = $types . "ii";
        $bind_values = $params;
        $bind_values[] = $limit;
        $bind_values[] = $offset;

        // call_user_func_array expects references
        $bind_names = [];
        $bind_names[] = $bindTypes;
        foreach ($bind_values as $k => $v) $bind_names[] = &$bind_values[$k];
        call_user_func_array([$stmt, 'bind_param'], $bind_names);
    }

    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) $dndRecords[] = $r;
    }
    mysqli_stmt_close($stmt);
} else {
    $alertMsg = "Failed to prepare list query: " . mysqli_error($link);
    $alertType = "danger";
}

// close connection (optional)
 // mysqli_close($link); // keep open if footer needs it

// helper to format datetime safely
function fmtDateTime($raw) {
    if (empty($raw) || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($raw);
    if ($ts === false) return '-';
    return date("d M Y", $ts) . "<br><small class='text-muted'>" . date("h:i A", $ts) . "</small>";
}

// build base query params for links
function buildQuery($overrides = []) {
    $q = array_merge($_GET, $overrides);
    return http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DND Numbers List - CallNow</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    :root{
        --danger:#dc3545;
        --muted:#6b7280;
    }
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #fff5f5 0%, #ffeef0 100%);
        min-height: 100vh;
        margin: 0;
    }
    .header-dnd {
        background: linear-gradient(135deg, var(--danger), #c82333);
        color: white;
        padding: 1.6rem 0.8rem;
        text-align: center;
        box-shadow: 0 8px 25px rgba(220, 53, 69, 0.16);
    }
    .table-container {
        background: white;
        border-radius: 0.75rem;
        box-shadow: 0 10px 30px rgba(15,23,42,0.04);
        overflow: hidden;
        margin: 1.25rem auto;
        max-width: 1100px;
    }
    .table thead th {
        background: rgba(220,53,69,0.95);
        color: #fff;
        position: sticky;
        top: 0;
        z-index: 10;
        border: 0;
    }
    .table td, .table th { vertical-align: middle; font-size: 0.94rem; padding: 0.6rem 0.8rem; }
    .badge-mobile { background: var(--danger); color: #fff; padding: .45rem .6rem; border-radius: .55rem; font-weight:600; }
    .small-muted { color: var(--muted); font-size: .89rem; }
    .search-box { max-width: 720px; }
    .pagination .page-link { color: var(--danger); padding: .35rem .65rem; border-radius: 8px; }
    .pagination .page-item.active .page-link { background: var(--danger); color: #fff; border-color: var(--danger); }
    @media (max-width: 768px) {
        .table-container { margin: 0.6rem; }
        .table td, .table th { padding: .45rem .5rem; font-size: .82rem; }
        .header-dnd h1 { font-size: 1.15rem; }
    }
</style>
</head>
<body>

<?php include 'php_scripts/header.php'; ?>

<div class="header-dnd">
    <div class="container">
        <h1 class="h4 fw-bold mb-1">DND Numbers List</h1>
        <div class="small-muted text-white">Total DND Records: <strong><?= number_format($totalRecords) ?></strong>
            &nbsp;•&nbsp; Page <?= $page ?> of <?= $totalPages ?></div>
    </div>
</div>

<div class="container py-3">
    <?php if ($alertMsg): ?>
        <div class="alert alert-<?php echo htmlspecialchars($alertType ?: 'info'); ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($alertMsg); ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search -->
    <form method="GET" class="mb-3">
        <div class="row justify-content-center">
            <div class="col-12 col-md-9 col-lg-8">
                <div class="input-group search-box shadow-sm">
                    <span class="input-group-text bg-white border-end-0"> <i class="bi bi-search me-1"></i> Search</span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Mobile, Name or Company" value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-danger" type="submit">Go</button>
                    <?php if ($search): ?>
                        <a class="btn btn-outline-secondary" href="ViewDND.php">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>

    <!-- Table -->
    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:30%;">Name</th>
                        <th style="width:18%;">Mobile</th>
                        <th style="width:32%;">Company</th>
                        <th style="width:20%;">Marked DND On</th>
                        <th style="width:0;" class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($dndRecords)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">
                            <div class="h5 mb-1">No DND records found</div>
                            <?php if ($search): ?><div class="small-muted">Try searching with different keywords.</div><?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dndRecords as $r): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['MAINDATABASE_NAME'] ?: '—') ?></strong></td>
                            <td><span class="badge-mobile"><?= htmlspecialchars($r['MAINDATABASE_MOBILE'] ?: '—') ?></span></td>
                            <td class="small"><?= htmlspecialchars($r['MAINDATABASE_COMPANY'] ?: '—') ?></td>
                            <td><?= fmtDateTime($r['MAINDATABASE_CALL_DIAL_TIME']) ?></td>
                            <td class="text-end pe-3">
                                <!-- Edit and Delete -->
                                <a href="add_single_number.php?ID=<?= intval($r['ID']) ?>" class="btn btn-sm btn-outline-primary me-1" title="Edit"><i class="bi bi-pencil"></i></a>

                                <?php if ($userRole === 'Admin'): ?>
                                    <form method="POST" class="d-inline-block" onsubmit="return confirm('Delete this record?');" style="display:inline;">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= intval($r['ID']) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav aria-label="DND Pagination" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= buildQuery(['page' => $page - 1]) ?>">Previous</a>
                </li>

                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);

                if ($start > 1): ?>
                    <li class="page-item"><a class="page-link" href="?<?= buildQuery(['page' => 1]) ?>">1</a></li>
                    <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= buildQuery(['page' => $i]) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?<?= buildQuery(['page' => $totalPages]) ?>"><?= $totalPages ?></a></li>
                <?php endif; ?>

                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= buildQuery(['page' => $page + 1]) ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>

</div>

<?php include 'php_scripts/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
