<?php
require_once '../../php_scripts/auth.php';
requirePermission('manage_database');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$userId = intval($_SESSION['id'] ?? 0);
$Message = "";
$type = "";

$allowedStatuses = [
    'Not Called', 'Dialed', 'Connected', 'Busy', 'No Answer', 'Do Not Call', 'Pending'
];

function status_class_name($s) {
    $s = (string)$s;
    $class = strtolower($s);
    $class = str_replace([' ', '/'], ['-', '-'], $class);
    $class = preg_replace('/[^a-z0-9\-]/', '', $class);
    return 'status-' . $class;
}

function log_activity($link, $userId, $actionType, $actionDetails, $affectedIds, $targetTable = TBL_TEMP) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $actionType = normalizeActivityType($actionType);
    $sql = "INSERT INTO " . TBL_ACTIVITY_LOG . " (USER_ID, ACTION_TYPE, ACTION_DETAILS, AFFECTED_IDS, TARGET_TABLE, IP_ADDRESS)
            VALUES (?, ?, ?, ?, ?, ?)";
    if ($stmt = mysqli_prepare($link, $sql)) {
        $detailsJson = is_string($actionDetails) ? $actionDetails : json_encode($actionDetails, JSON_UNESCAPED_UNICODE);
        mysqli_stmt_bind_param($stmt, "isssss", $userId, $actionType, $detailsJson, $affectedIds, $targetTable, $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

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
            $sql = "INSERT INTO " . TBL_TEMP . "
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
                $affected = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);

                if ($ok) {
                    $id = 0;
                    if ($s2 = mysqli_prepare($link, "SELECT ID FROM " . TBL_TEMP . " WHERE CUST_MOBILE = ? LIMIT 1")) {
                        mysqli_stmt_bind_param($s2, "s", $dndNumber);
                        mysqli_stmt_execute($s2);
                        $res2 = mysqli_stmt_get_result($s2);
                        if ($row2 = mysqli_fetch_assoc($res2)) $id = intval($row2['ID']);
                        mysqli_stmt_close($s2);
                    }

                    $actionType = 'UPDATE';
                    if ($affected === 1) $actionType = 'INSERT';
                    elseif ($affected === 2) $actionType = 'UPDATE';

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
            $sql = "UPDATE " . TBL_TEMP . " SET CALL_DIALED_STATUS = ?, TEMP_UPLOAD_DATETIME = NOW() WHERE ID BETWEEN ? AND ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "sii", $status, $minID, $maxID);
                $ok = mysqli_stmt_execute($stmt);
                $affected = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);

                if ($ok) {
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

$sampleRows = [];
$res = mysqli_query($link, "SELECT ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CALL_DIALED_STATUS, TEMP_UPLOAD_DATETIME
                            FROM " . TBL_TEMP . "
                            ORDER BY ID DESC LIMIT 8");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) $sampleRows[] = $r;
}

$totalTempRecords = (int)mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM " . TBL_TEMP))[0];
$todayInserts = (int)mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM " . TBL_TEMP . " WHERE DATE(TEMP_UPLOAD_DATETIME) = CURDATE()"))[0];

$pageTitle = 'Update Status • CallNow CRM';
include '../../php_scripts/header.php';
?>

<div class="container-fluid px-3 py-3">
    <!-- Module Header -->
    <div class="module-header mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 fs-xs">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/database/data_management_temporary.php') ?>">Data Management</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Update Status</li>
                </ol>
            </nav>
            <h1 class="h4 fw-bold text-dark mb-0">Update Database Status</h1>
            <div class="text-muted fs-xs">Assign call dispositions to individual numbers or execute bulk range updates in Temporary Staging</div>
        </div>
        <div class="module-header-actions">
            <a href="<?= url('modules/database/data_management_temporary.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-database me-1"></i> Temporary DB
            </a>
            <a href="<?= url('modules/database/upload_data.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-cloud-upload me-1"></i> Upload CSV
            </a>
        </div>
    </div>

    <!-- Metric KPI Cards -->
    <div class="row g-2 mb-3">
        <div class="col-sm-6 col-md-4">
            <div class="metric-card">
                <div class="metric-card-title">Total Staging Records</div>
                <div class="metric-card-value text-primary"><?= number_format($totalTempRecords) ?></div>
                <div class="metric-card-subtitle text-muted">Awaiting migration</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-4">
            <div class="metric-card">
                <div class="metric-card-title">Today's Records</div>
                <div class="metric-card-value text-success"><?= number_format($todayInserts) ?></div>
                <div class="metric-card-subtitle text-muted">Added / modified today</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-4">
            <div class="metric-card">
                <div class="metric-card-title">Update Utilities</div>
                <div class="metric-card-value text-dark">2 Modes</div>
                <div class="metric-card-subtitle text-muted">Single + Range bulk</div>
            </div>
        </div>
    </div>

    <?php if ($Message): ?>
        <div class="alert alert-<?= htmlspecialchars($type ?: 'info') ?> alert-dismissible fade show mb-3" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi <?= $type === 'success' ? 'bi-check-circle-fill' : ($type === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill') ?> me-2 fs-5"></i>
                <div class="fs-xs"><?= htmlspecialchars($Message) ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <!-- Single Record Card -->
        <div class="col-lg-6">
            <div class="crm-card h-100">
                <div class="crm-card-header">
                    <div class="crm-card-title"><i class="bi bi-person-check text-primary me-2"></i>Add / Update Single Record</div>
                </div>
                <div class="crm-card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label" for="dndNumber">Mobile Number <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                    <input name="dndNumber" id="dndNumber" type="tel" class="form-control" placeholder="10-digit mobile" pattern="[6-9][0-9]{9}" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="cname">Customer Name</label>
                                <input name="cname" id="cname" type="text" class="form-control" placeholder="e.g. Ramesh Kumar">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="ccompany">Company</label>
                                <input name="ccompany" id="ccompany" type="text" class="form-control" placeholder="e.g. Tata Motors">
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="cpackage">Package / Description</label>
                                <input name="cpackage" id="cpackage" type="text" class="form-control" placeholder="e.g. Premium / Personal Loan">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Call Status / Disposition <span class="text-danger">*</span></label>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($allowedStatuses as $s):
                                        $idAttr = 'st_' . preg_replace('/[^a-z0-9]/i','_', $s);
                                    ?>
                                        <div class="form-check form-check-inline m-0">
                                            <input class="btn-check" type="radio" name="status" value="<?= htmlspecialchars($s) ?>" id="<?= $idAttr ?>" required>
                                            <label class="btn btn-outline-secondary btn-sm fs-xs py-1 px-2" for="<?= $idAttr ?>">
                                                <?= htmlspecialchars($s) ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="col-12 pt-2 border-top">
                                <button type="submit" name="submitDND" class="btn btn-primary btn-sm w-100">
                                    <i class="bi bi-check-lg me-1"></i> Save to Temporary Database
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Bulk Range Update Card -->
        <div class="col-lg-6">
            <div class="crm-card h-100">
                <div class="crm-card-header">
                    <div class="crm-card-title"><i class="bi bi-arrow-repeat text-primary me-2"></i>Bulk Update by ID Range</div>
                </div>
                <div class="crm-card-body">
                    <form method="POST" onsubmit="return confirm('Update all records in this ID range? This action modifies all matching rows.');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label" for="minID">Start ID <span class="text-danger">*</span></label>
                                <input name="minID" id="minID" type="number" class="form-control" placeholder="e.g. 100" min="1" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="maxID">End ID <span class="text-danger">*</span></label>
                                <input name="maxID" id="maxID" type="number" class="form-control" placeholder="e.g. 250" min="1" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="status_bulk">Target Call Status <span class="text-danger">*</span></label>
                                <select name="status_bulk" id="status_bulk" class="form-select" required>
                                    <option value="" disabled selected>-- Choose Status --</option>
                                    <?php foreach ($allowedStatuses as $s): ?>
                                        <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <div class="alert alert-secondary py-2 px-3 border fs-xs mb-0">
                                    <i class="bi bi-info-circle me-1 text-primary"></i> Updates the <code>CALL_DIALED_STATUS</code> for every record within the specified range in the Temporary Database.
                                </div>
                            </div>

                            <div class="col-12 pt-2 border-top">
                                <button type="submit" name="minMaxIdChange" class="btn btn-danger btn-sm w-100">
                                    <i class="bi bi-lightning-charge me-1"></i> Execute Range Update
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Recently Updated Staging Records -->
    <div class="crm-card">
        <div class="crm-card-header">
            <div class="crm-card-title"><i class="bi bi-clock-history text-primary me-2"></i>Recently Updated Staging Records</div>
            <span class="badge badge-secondary fs-xs"><?= count($sampleRows) ?> records</span>
        </div>
        <div class="crm-card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:70px;">ID</th>
                            <th>Customer Name</th>
                            <th>Mobile</th>
                            <th>Company</th>
                            <th>Package</th>
                            <th>Status</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sampleRows)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted fs-xs">No records available.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sampleRows as $r): 
                                $ts = strtotime($r['TEMP_UPLOAD_DATETIME'] ?? '');
                            ?>
                                <tr>
                                    <td><span class="font-monospace text-muted fs-xs">#<?= (int)$r['ID'] ?></span></td>
                                    <td><strong class="text-dark fs-xs"><?= htmlspecialchars($r['CUST_NAME'] ?: '—') ?></strong></td>
                                    <td><span class="badge badge-secondary font-monospace fs-xs"><?= htmlspecialchars($r['CUST_MOBILE']) ?></span></td>
                                    <td><span class="text-muted fs-xs"><?= htmlspecialchars($r['CUST_COMPANY'] ?: '—') ?></span></td>
                                    <td><span class="text-muted fs-xs"><?= htmlspecialchars($r['CUST_PACKAGE'] ?: '—') ?></span></td>
                                    <td>
                                        <span class="badge badge-primary fs-xs"><?= htmlspecialchars($r['CALL_DIALED_STATUS'] ?? 'Not Called') ?></span>
                                    </td>
                                    <td><span class="text-muted fs-xs"><?= $ts ? date('d M, h:i A', $ts) : '—' ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>
