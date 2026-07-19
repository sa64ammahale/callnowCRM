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

function log_activity($link, $userId, $actionType, $actionDetails, $affectedIds, $targetTable = 'temporary_database') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $actionType = normalizeActivityType($actionType);
    $sql = "INSERT INTO activity_log (USER_ID, ACTION_TYPE, ACTION_DETAILS, AFFECTED_IDS, TARGET_TABLE, IP_ADDRESS)
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
            $sql = "INSERT INTO temporary_database
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
                    if ($s2 = mysqli_prepare($link, "SELECT ID FROM temporary_database WHERE CUST_MOBILE = ? LIMIT 1")) {
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
            $sql = "UPDATE temporary_database SET CALL_DIALED_STATUS = ?, TEMP_UPLOAD_DATETIME = NOW() WHERE ID BETWEEN ? AND ?";
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
$sampleSql = "SELECT ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CALL_DIALED_STATUS, TEMP_UPLOAD_DATETIME
              FROM temporary_database
              ORDER BY TEMP_UPLOAD_DATETIME DESC
              LIMIT 12";
if ($res = mysqli_query($link, $sampleSql)) {
    while ($r = mysqli_fetch_assoc($res)) $sampleRows[] = $r;
}

$totalTempRecords = (int)mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM temporary_database"))[0];
$todayInserts = (int)mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM temporary_database WHERE DATE(TEMP_UPLOAD_DATETIME) = CURDATE()"))[0];
?>
<?php $pageTitle = 'Update Status - CallNow'; include '../../php_scripts/header.php'; ?>

<div class="page-container">
    <!-- Page Header -->
    <div class="page-header-section">
        <div class="header-content">
            <div class="header-icon">
                <i class="bi bi-arrow-repeat"></i>
            </div>
            <div class="header-text">
                <h1 class="page-title">Update Temporary Database</h1>
                <p class="page-subtitle">Manage and update call status records efficiently</p>
            </div>
        </div>
        <div class="header-actions">
            <a href="<?= url('modules/database/data_management_temporary.php') ?>" class="btn btn-primary">
                <i class="bi bi-table me-2"></i>View All Records
            </a>
            <a href="<?= url('modules/database/upload_data.php') ?>" class="btn btn-outline-primary">
                <i class="bi bi-cloud-upload me-2"></i>Upload CSV
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card stat-blue">
            <div class="stat-icon-wrapper">
                <i class="bi bi-database-fill"></i>
            </div>
            <div class="stat-content">
                <div class="stat-label">Total Records</div>
                <div class="stat-value"><?= number_format($totalTempRecords) ?></div>
                <div class="stat-description">In temporary database</div>
            </div>
        </div>
        
        <div class="stat-card stat-green">
            <div class="stat-icon-wrapper">
                <i class="bi bi-calendar-check-fill"></i>
            </div>
            <div class="stat-content">
                <div class="stat-label">Today's Inserts</div>
                <div class="stat-value"><?= number_format($todayInserts) ?></div>
                <div class="stat-description">Added today</div>
            </div>
        </div>
        
        <div class="stat-card stat-orange">
            <div class="stat-icon-wrapper">
                <i class="bi bi-lightning-charge-fill"></i>
            </div>
            <div class="stat-content">
                <div class="stat-label">Quick Actions</div>
                <div class="stat-value">2</div>
                <div class="stat-description">Single + Bulk update</div>
            </div>
        </div>
    </div>

    <?php if ($Message): ?>
        <div class="alert-custom alert-<?= htmlspecialchars($type ?: 'info') ?>">
            <div class="alert-icon">
                <i class="bi bi-<?= $type === 'success' ? 'check-circle-fill' : ($type === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?>"></i>
            </div>
            <div class="alert-content">
                <?= htmlspecialchars($Message) ?>
            </div>
            <button type="button" class="alert-close" data-bs-dismiss="alert" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- Action Cards -->
    <div class="action-grid">
        <!-- Single Record Card -->
        <div class="action-card">
            <div class="action-card-header action-purple">
                <div class="action-icon">
                    <i class="bi bi-person-plus-fill"></i>
                </div>
                <div class="action-title">
                    <h2>Add / Update Single Record</h2>
                    <p>Insert new or update existing record by mobile number</p>
                </div>
            </div>
            <div class="action-card-body">
                <form method="POST" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    
                    <div class="form-row">
                        <div class="form-group form-group-full">
                            <label class="form-label">
                                <i class="bi bi-phone me-1"></i>
                                Mobile Number <span class="required">*</span>
                            </label>
                            <input name="dndNumber" type="tel" class="form-input form-input-lg" 
                                   placeholder="9876543210" pattern="[6-9][0-9]{9}" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-person me-1"></i>
                                Customer Name
                            </label>
                            <input name="cname" type="text" class="form-input" placeholder="Rahul Sharma">
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-building me-1"></i>
                                Company
                            </label>
                            <input name="ccompany" type="text" class="form-input" placeholder="ABC Corp">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-group-full">
                            <label class="form-label">
                                <i class="bi bi-box me-1"></i>
                                Package
                            </label>
                            <input name="cpackage" type="text" class="form-input" placeholder="Premium / Basic">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-group-full">
                            <label class="form-label">
                                <i class="bi bi-telephone me-1"></i>
                                Call Status <span class="required">*</span>
                            </label>
                            <div class="status-options">
                                <?php foreach ($allowedStatuses as $s):
                                    $idAttr = 'st_' . preg_replace('/[^a-z0-9]/i','_', $s);
                                    $cls = status_class_name($s);
                                ?>
                                    <label class="status-option <?= $cls ?>" for="<?= $idAttr ?>">
                                        <input type="radio" name="status" value="<?= htmlspecialchars($s) ?>" id="<?= $idAttr ?>" required>
                                        <span class="status-option-text"><?= htmlspecialchars($s) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button name="submitDND" class="btn btn-primary btn-lg btn-block">
                            <i class="bi bi-save me-2"></i>Save to Temporary DB
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bulk Update Card -->
        <div class="action-card">
            <div class="action-card-header action-red">
                <div class="action-icon">
                    <i class="bi bi-arrow-repeat"></i>
                </div>
                <div class="action-title">
                    <h2>Bulk Update by ID Range</h2>
                    <p>Update call status for multiple records at once</p>
                </div>
            </div>
            <div class="action-card-body">
                <form method="POST" class="form-grid">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-hash me-1"></i>
                                From ID
                            </label>
                            <input name="minID" type="number" class="form-input form-input-lg" 
                                   placeholder="100" min="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-hash me-1"></i>
                                To ID
                            </label>
                            <input name="maxID" type="number" class="form-input form-input-lg" 
                                   placeholder="200" min="1" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-group-full">
                            <label class="form-label">
                                <i class="bi bi-telephone me-1"></i>
                                Set Status
                            </label>
                            <select name="status_bulk" class="form-select form-select-lg" required>
                                <option value="" disabled selected>Choose status</option>
                                <?php foreach ($allowedStatuses as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button name="minMaxIdChange" class="btn btn-danger btn-lg btn-block" 
                                onclick="return confirm('Update selected ID range? This cannot be undone.');">
                            <i class="bi bi-arrow-repeat me-2"></i>Update Records
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Recent Records -->
    <div class="recent-section">
        <div class="section-header">
            <div class="section-title-wrapper">
                <i class="bi bi-clock-history"></i>
                <h2>Recently Updated / Inserted</h2>
            </div>
            <span class="badge-count"><?= count($sampleRows) ?> records</span>
        </div>
        
        <div class="section-content">
            <?php if (empty($sampleRows)): ?>
                <div class="empty-state">
                    <i class="bi bi-inboxes"></i>
                    <p>No recent records yet.</p>
                </div>
            <?php else: ?>
                <div class="records-grid">
                    <?php foreach ($sampleRows as $r): 
                        $pillClass = status_class_name($r['CALL_DIALED_STATUS'] ?? '');
                        $ts = strtotime($r['TEMP_UPLOAD_DATETIME'] ?? '');
                    ?>
                        <div class="record-card">
                            <div class="record-header">
                                <div class="record-info">
                                    <div class="record-name"><?= htmlspecialchars($r['CUST_NAME'] ?: '—') ?></div>
                                    <div class="record-company"><?= htmlspecialchars($r['CUST_COMPANY'] ?: '—') ?></div>
                                </div>
                                <span class="status-badge <?= $pillClass ?>">
                                    <?= htmlspecialchars($r['CALL_DIALED_STATUS'] ?? '') ?>
                                </span>
                            </div>
                            <div class="record-footer">
                                <div class="record-mobile">
                                    <i class="bi bi-phone"></i>
                                    <?= htmlspecialchars($r['CUST_MOBILE']) ?>
                                </div>
                                <div class="record-meta">
                                    <span class="record-id">ID: <?= (int)$r['ID'] ?></span>
                                    <span class="record-time"><?= $ts ? date('d M, h:i A', $ts) : '-' ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>

<style>
/* Page Container */
.page-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem 1.5rem;
}

/* Page Header */
.page-header-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1.5rem;
    border-bottom: 2px solid var(--border);
}

.header-content {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.header-icon {
    width: 56px;
    height: 56px;
    border-radius: var(--radius-lg);
    background: linear-gradient(135deg, var(--accent) 0%, var(--accent-hover) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    color: #fff;
    box-shadow: var(--shadow-md);
}

.page-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--ink);
    margin: 0 0 0.25rem 0;
    letter-spacing: -0.02em;
}

.page-subtitle {
    font-size: 0.875rem;
    color: var(--ink-soft);
    margin: 0;
}

.header-actions {
    display: flex;
    gap: 0.75rem;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    padding: 1.5rem;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-md);
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 150px;
    height: 150px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
    transform: translate(30%, -30%);
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}

.stat-icon-wrapper {
    width: 56px;
    height: 56px;
    border-radius: var(--radius-lg);
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}

.stat-content {
    flex: 1;
    min-width: 0;
    position: relative;
    z-index: 1;
}

.stat-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.5rem;
    opacity: 0.9;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 0.5rem;
    letter-spacing: -0.02em;
}

.stat-description {
    font-size: 0.8125rem;
    opacity: 0.85;
}

.stat-blue {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #fff;
}

.stat-green {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #fff;
}

.stat-orange {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: #fff;
}

/* Alert */
.alert-custom {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-radius: var(--radius-lg);
    margin-bottom: 2rem;
    animation: slideInDown 0.3s ease;
}

@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert-icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.alert-content {
    flex: 1;
    font-size: 0.875rem;
    font-weight: 500;
}

.alert-close {
    width: 32px;
    height: 32px;
    border-radius: var(--radius);
    border: none;
    background: transparent;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s ease;
    font-size: 1rem;
}

.alert-close:hover {
    background: rgba(0,0,0,0.1);
}

.alert-success {
    background: var(--success-soft);
    color: var(--success);
    border: 1px solid var(--success);
}

.alert-success .alert-icon {
    background: var(--success);
    color: #fff;
}

.alert-danger {
    background: var(--danger-soft);
    color: var(--danger);
    border: 1px solid var(--danger);
}

.alert-danger .alert-icon {
    background: var(--danger);
    color: #fff;
}

.alert-warning {
    background: var(--warning-soft);
    color: var(--warning);
    border: 1px solid var(--warning);
}

.alert-warning .alert-icon {
    background: var(--warning);
    color: #fff;
}

.alert-info {
    background: var(--info-soft);
    color: var(--info);
    border: 1px solid var(--info);
}

.alert-info .alert-icon {
    background: var(--info);
    color: #fff;
}

/* Action Grid */
.action-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.action-card {
    background: var(--surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow);
    overflow: hidden;
    transition: box-shadow 0.2s ease;
}

.action-card:hover {
    box-shadow: var(--shadow-lg);
}

.action-card-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.5rem;
    color: #fff;
}

.action-purple {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
}

.action-red {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
}

.action-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius);
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.action-title h2 {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0 0 0.25rem 0;
    color: #fff;
}

.action-title p {
    font-size: 0.8125rem;
    margin: 0;
    opacity: 0.9;
}

.action-card-body {
    padding: 1.5rem;
    background: var(--surface);
}

/* Form Styles */
.form-grid {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group-full {
    grid-column: 1 / -1;
}

.form-label {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--ink-soft);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.form-label i {
    color: var(--accent);
}

.required {
    color: var(--danger);
}

.form-input,
.form-select {
    padding: 0.625rem 0.875rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    color: var(--ink);
    font-size: 0.875rem;
    transition: all 0.15s ease;
}

.form-input:focus,
.form-select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
}

.form-input-lg {
    padding: 0.75rem 1rem;
    font-size: 1rem;
}

.form-select-lg {
    padding: 0.75rem 1rem;
    font-size: 1rem;
}

/* Status Options */
.status-options {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 0.5rem;
}

.status-option {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1rem;
    border: 2px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
    transition: all 0.15s ease;
    background: var(--surface);
    font-size: 0.8125rem;
    font-weight: 500;
    text-align: center;
    position: relative;
}

.status-option:hover {
    border-color: var(--accent);
    background: var(--accent-soft);
    transform: translateY(-1px);
}

.status-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.status-option input[type="radio"]:checked + .status-option-text {
    color: var(--accent);
    font-weight: 600;
}

.status-option:has(input:checked) {
    border-color: var(--accent);
    background: var(--accent-soft);
    box-shadow: 0 0 0 3px var(--accent-soft);
}

.status-option-text {
    pointer-events: none;
}

/* Form Actions */
.form-actions {
    margin-top: 0.5rem;
}

.btn-block {
    width: 100%;
}

/* Recent Section */
.recent-section {
    background: var(--surface);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border);
    background: var(--surface-2);
}

.section-title-wrapper {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.section-title-wrapper i {
    font-size: 1.25rem;
    color: var(--accent);
}

.section-title-wrapper h2 {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0;
    color: var(--ink);
}

.badge-count {
    padding: 0.375rem 0.875rem;
    background: var(--accent-soft);
    color: var(--accent);
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
}

.section-content {
    padding: 1.5rem;
}

/* Records Grid */
.records-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
}

.record-card {
    padding: 1.25rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    transition: all 0.2s ease;
}

.record-card:hover {
    border-color: var(--accent);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.record-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
    gap: 0.75rem;
}

.record-info {
    flex: 1;
    min-width: 0;
}

.record-name {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--ink);
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.record-company {
    font-size: 0.8125rem;
    color: var(--ink-soft);
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 999px;
    font-size: 0.6875rem;
    font-weight: 600;
    white-space: nowrap;
    flex-shrink: 0;
}

.record-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 0.75rem;
    border-top: 1px solid var(--border);
}

.record-mobile {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--accent);
}

.record-meta {
    display: flex;
    gap: 0.75rem;
    font-size: 0.75rem;
    color: var(--ink-muted);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
    color: var(--ink-soft);
}

.empty-state i {
    font-size: 3rem;
    color: var(--ink-muted);
    margin-bottom: 1rem;
    display: block;
}

.empty-state p {
    font-size: 0.9375rem;
    margin: 0;
}

/* Responsive */
@media (max-width: 1024px) {
    .action-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .page-container {
        padding: 1rem;
    }

    .page-header-section {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }

    .header-actions {
        width: 100%;
    }

    .header-actions .btn {
        flex: 1;
    }

    .stats-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .records-grid {
        grid-template-columns: 1fr;
    }
}
</style>