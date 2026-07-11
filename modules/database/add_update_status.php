<?php
// update_temporary_database.php — with activity logging & fixed status pills
require_once '../../php_scripts/auth.php';
requireRole('Admin');

if (session_status() === PHP_SESSION_NONE) session_start();
/* CSRF token */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$userId = intval($_SESSION['id'] ?? 0);
$Message = "";
$type = "";

/* Allowed status values (must match TEMPORARY_DATABASE.CALL_DIALED_STATUS enum) */
$allowedStatuses = [
    'Not Called', 'Dialed', 'Connected', 'Busy', 'No Answer', 'Do Not Call', 'Pending'
];

/* Helper: sanitize status -> css class */
function status_class_name($s) {
    $s = (string)$s;
    $class = strtolower($s);
    $class = str_replace([' ', '/'], ['-', '-'], $class);
    $class = preg_replace('/[^a-z0-9\-]/', '', $class);
    return 'status-' . $class;
}

/* Helper: log activity */
function log_activity($link, $userId, $actionType, $actionDetails, $affectedIds, $targetTable = 'TEMPORARY_DATABASE') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $actionType = normalizeActivityType($actionType);
    $sql = "INSERT INTO ACTIVITY_LOG (USER_ID, ACTION_TYPE, ACTION_DETAILS, AFFECTED_IDS, TARGET_TABLE, IP_ADDRESS)
            VALUES (?, ?, ?, ?, ?, ?)";
    if ($stmt = mysqli_prepare($link, $sql)) {
        $detailsJson = is_string($actionDetails) ? $actionDetails : json_encode($actionDetails, JSON_UNESCAPED_UNICODE);
        mysqli_stmt_bind_param($stmt, "isssss", $userId, $actionType, $detailsJson, $affectedIds, $targetTable, $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/* ---------------------------
 Single number insert/update
----------------------------*/
if (isset($_POST['submitDND'])) {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $Message = "Invalid form submission (CSRF).";
        $type = "danger";
    } else {
        $dndNumber = trim($_POST['dndNumber'] ?? '');
        $name      = trim($_POST['cname'] ?? '');
        $company   = trim($_POST['ccompany'] ?? '');
        $package   = trim($_POST['cpackage'] ?? '');
        $status    = trim($_POST['status'] ?? '');

        if ($dndNumber === '' || $status === '') {
            $Message = "Please fill required fields (mobile and status).";
            $type = "warning";
        } elseif (!preg_match('/^[6-9][0-9]{9}$/', $dndNumber)) {
            $Message = "Invalid mobile number. Must be 10 digits starting with 6-9.";
            $type = "danger";
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $Message = "Invalid status selected.";
            $type = "danger";
        } else {
            // Upsert into TEMPORARY_DATABASE (CUST_MOBILE is UNIQUE)
            $sql = "INSERT INTO TEMPORARY_DATABASE
                        (CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CALL_DIALED_STATUS, TEMP_UPLOAD_DATETIME)
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        CUST_NAME = VALUES(CUST_NAME),
                        CUST_COMPANY = VALUES(CUST_COMPANY),
                        CUST_PACKAGE = VALUES(CUST_PACKAGE),
                        CALL_DIALED_STATUS = VALUES(CALL_DIALED_STATUS),
                        TEMP_UPLOAD_DATETIME = NOW()";

            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "sssss", $name, $dndNumber, $company, $package, $status);
                $ok = mysqli_stmt_execute($stmt);
                $affected = mysqli_stmt_affected_rows($stmt); // 1 = insert, 2 = update for ON DUPLICATE
                mysqli_stmt_close($stmt);

                if ($ok) {
                    // retrieve the ID of the row (select by mobile)
                    $id = 0;
                    if ($s2 = mysqli_prepare($link, "SELECT ID FROM TEMPORARY_DATABASE WHERE CUST_MOBILE = ? LIMIT 1")) {
                        mysqli_stmt_bind_param($s2, "s", $dndNumber);
                        mysqli_stmt_execute($s2);
                        $res2 = mysqli_stmt_get_result($s2);
                        if ($row2 = mysqli_fetch_assoc($res2)) $id = intval($row2['ID']);
                        mysqli_stmt_close($s2);
                    }

                    // determine action type
                    $actionType = 'UPDATE';
                    if ($affected === 1) $actionType = 'INSERT';
                    elseif ($affected === 2) $actionType = 'UPDATE';

                    // log activity
                    $details = [
                        'name' => $name,
                        'mobile' => $dndNumber,
                        'company' => $company,
                        'package' => $package,
                        'status' => $status
                    ];
                    $affectedIds = $id > 0 ? (string)$id : $dndNumber;
                    log_activity($link, $userId, $actionType, $details, $affectedIds);

                    $Message = "Number saved/updated successfully.";
                    $type = "success";
                } else {
                    $Message = "Database error: " . htmlspecialchars(mysqli_error($link));
                    $type = "danger";
                }
            } else {
                $Message = "Database prepare error: " . htmlspecialchars(mysqli_error($link));
                $type = "danger";
            }
        }
    }
}

/* ---------------------------
 Bulk update by ID range
----------------------------*/
if (isset($_POST['minMaxIdChange'])) {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $Message = "Invalid form submission (CSRF).";
        $type = "danger";
    } else {
        $minID = intval($_POST['minID'] ?? 0);
        $maxID = intval($_POST['maxID'] ?? 0);
        $status = trim($_POST['status_bulk'] ?? '');

        if ($minID <= 0 || $maxID <= 0 || $maxID < $minID) {
            $Message = "Please provide a valid ID range (min <= max).";
            $type = "warning";
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $Message = "Invalid status selected for bulk update.";
            $type = "danger";
        } else {
            $sql = "UPDATE TEMPORARY_DATABASE SET CALL_DIALED_STATUS = ?, TEMP_UPLOAD_DATETIME = NOW() WHERE ID BETWEEN ? AND ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "sii", $status, $minID, $maxID);
                $ok = mysqli_stmt_execute($stmt);
                $affected = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);

                if ($ok) {
                    // log bulk activity
                    $details = [
                        'status' => $status,
                        'range' => [$minID, $maxID],
                        'affected_count' => $affected
                    ];
                    $affectedIds = "{$minID}-{$maxID}";
                    log_activity($link, $userId, 'BULK_UPDATE', $details, $affectedIds);

                    $Message = "Bulk update complete. {$affected} row(s) updated.";
                    $type = "success";
                } else {
                    $Message = "Database error: " . htmlspecialchars(mysqli_error($link));
                    $type = "danger";
                }
            } else {
                $Message = "Database prepare error: " . htmlspecialchars(mysqli_error($link));
                $type = "danger";
            }
        }
    }
}

/* fetch a small sample to show recent uploads (optional) */
$sampleRows = [];
$sampleSql = "SELECT ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CALL_DIALED_STATUS, TEMP_UPLOAD_DATETIME
              FROM TEMPORARY_DATABASE
              ORDER BY TEMP_UPLOAD_DATETIME DESC
              LIMIT 12";
if ($res = mysqli_query($link, $sampleSql)) {
    while ($r = mysqli_fetch_assoc($res)) $sampleRows[] = $r;
}
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Temporary Database — Update</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../../assets/css/app-theme.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
:root{
    --bg1: #f0f7ff;
    --card: #ffffff;
    --accent1: #6f42c1;
    --accent2: #0ea5a4;
    --muted: #6b7280;
}
body{
    font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
    background: linear-gradient(180deg, var(--bg1) 0%, #fff 100%);
    margin: 0;
    padding-bottom: 3rem;
}
.hero {
    background: linear-gradient(90deg, rgba(111,66,193,0.95), rgba(14,165,164,0.95));
    color: white;
    padding: 2rem 1rem;
    border-bottom-left-radius: 18px;
    border-bottom-right-radius: 18px;
    box-shadow: 0 12px 40px rgba(15,23,42,0.08);
}
.container-narrow { max-width: 1100px; margin: 0 auto; }
.card-soft { background: var(--card); border-radius: 12px; box-shadow: 0 8px 26px rgba(15,23,42,0.04); }
.form-section { padding: 1.25rem; }
.legend-small { font-size: 0.85rem; color: var(--muted); }

/* status pill classes */
.status-pill { display:inline-block; padding:.28rem .55rem; border-radius:999px; font-weight:600; font-size:.78rem; color:white; }
.status-not-called { background:#94a3b8; } /* gray */
.status-dialed { background:#0ea5a4; } /* teal */
.status-connected { background:#10b981; } /* green */
.status-busy { background:#f59e0b; } /* amber */
.status-no-answer { background:#f97316; } /* orange */
.status-do-not-call { background:#ef4444; } /* red */
.status-pending { background:#6b7280; } /* muted */

.btn-gradient { background: linear-gradient(90deg,#6f42c1,#0ea5a4); color: white; border:0; border-radius: 999px; padding:.65rem 1.2rem; }
.btn-gradient:hover{ filter:brightness(.98); transform:translateY(-2px); }

@media (max-width:720px){
    .hero { padding: 1rem; text-align:center; border-radius:0 0 12px 12px;}
    .form-grid { gap:.6rem !important; }
    .status-grid { gap:.4rem !important; }
}
</style>
</head>
<body>
<?php include '../../php_scripts/header.php'; ?>

<section class="hero">
    <div class="container-narrow">
        <div class="d-flex align-items-center justify-content-between flex-wrap">
            <div>
                <h2 class="mb-0 fw-bold">Update Temporary Database</h2>
                <p class="mb-0 legend-small text-white">Insert or update a single record by mobile, or update a range of IDs.</p>
            </div>
            <div class="mt-2">
                <a href="../database/data_management_temporary.php" class="btn btn-light btn-sm">View All Temporary Records</a>
            </div>
        </div>
    </div>
</section>

<div class="container-narrow mt-4">
    <?php if ($Message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($type ?: 'info'); ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($Message); ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- Single insert/update -->
        <div class="col-12 col-lg-6">
            <div class="card-soft">
                <div class="form-section">
                    <h5 class="mb-2">Add / Update Single Record</h5>
                    <p class="legend-small mb-3">If the mobile exists the record will be updated; otherwise a new row will be created.</p>

                    <form method="POST" class="row g-2 form-grid">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">

                        <div class="col-12">
                            <label class="form-label small text-muted">Mobile (required)</label>
                            <input name="dndNumber" type="tel" class="form-control form-control-lg" placeholder="9876543210" pattern="[6-9][0-9]{9}" required>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label small text-muted">Customer Name</label>
                            <input name="cname" type="text" class="form-control" placeholder="Rahul Sharma">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label small text-muted">Company</label>
                            <input name="ccompany" type="text" class="form-control" placeholder="ABC Corp">
                        </div>

                        <div class="col-12">
                            <label class="form-label small text-muted">Package</label>
                            <input name="cpackage" type="text" class="form-control" placeholder="Premium / Basic">
                        </div>

                        <div class="col-12">
                            <label class="form-label small text-muted mb-1">Call Status</label>
                            <div class="row g-2 status-grid">
                                <?php foreach ($allowedStatuses as $s):
                                    $idAttr = 'st_' . preg_replace('/[^a-z0-9]/i','_', $s);
                                    $cls = status_class_name($s);
                                ?>
                                    <div class="col-6 col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="status" value="<?php echo htmlspecialchars($s); ?>" id="<?php echo $idAttr; ?>" required>
                                            <label class="form-check-label small d-flex align-items-center" for="<?php echo $idAttr; ?>">
                                                <span class="status-pill <?php echo $cls; ?>"><?php echo htmlspecialchars($s); ?></span>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="col-12 mt-3 d-grid">
                            <button name="submitDND" class="btn btn-gradient btn-lg">Save to Temporary DB</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Bulk by ID range -->
        <div class="col-12 col-lg-6">
            <div class="card-soft">
                <div class="form-section">
                    <h5 class="mb-2">Bulk Update by ID Range</h5>
                    <p class="legend-small mb-3">Update `CALL_DIALED_STATUS` for all records with ID between min and max.</p>

                    <form method="POST" class="row g-2">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">

                        <div class="col-6">
                            <label class="form-label small text-muted">From ID</label>
                            <input name="minID" type="number" class="form-control" placeholder="100" min="1" required>
                        </div>

                        <div class="col-6">
                            <label class="form-label small text-muted">To ID</label>
                            <input name="maxID" type="number" class="form-control" placeholder="200" min="1" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label small text-muted mb-1">Set Status</label>
                            <select name="status_bulk" class="form-select" required>
                                <option value="" disabled selected>Choose status</option>
                                <?php foreach ($allowedStatuses as $s): $cls = status_class_name($s); ?>
                                    <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 mt-3 d-grid">
                            <button name="minMaxIdChange" class="btn btn-outline-danger btn-lg" onclick="return confirm('Update selected ID range? This cannot be undone.');">Update Range</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- recent sample rows -->
    <div class="card-soft mt-4 p-3">
        <h6 class="mb-2">Recently updated / inserted (sample)</h6>
        <div class="row g-2">
            <?php if (empty($sampleRows)): ?>
                <div class="col-12 small-muted">No recent rows yet.</div>
            <?php else: foreach ($sampleRows as $r): 
                $pillClass = status_class_name($r['CALL_DIALED_STATUS'] ?? '');
            ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="p-2 border rounded">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($r['CUST_NAME'] ?: '—'); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($r['CUST_COMPANY'] ?: '—'); ?></div>
                            </div>
                            <div class="text-end">
                                <div class="mb-1"><span class="status-pill <?php echo $pillClass; ?>"><?php echo htmlspecialchars($r['CALL_DIALED_STATUS'] ?? ''); ?></span></div>
                                <div class="small-muted"><?php 
                                    $ts = strtotime($r['TEMP_UPLOAD_DATETIME'] ?? '');
                                    echo $ts ? htmlspecialchars(date('d M Y, h:i A', $ts)) : '-';
                                ?></div>
                            </div>
                        </div>
                        <div class="mt-2"><strong><?php echo htmlspecialchars($r['CUST_MOBILE']); ?></strong></div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="text-center mt-4 mb-5">
        <a href="../database/data_management_temporary.php" class="btn btn-outline-primary">Open Temporary Database Viewer</a>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
