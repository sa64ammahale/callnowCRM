<?php

require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/super_admin.php';

requirePermission('manage_database');

$userId = intval($_SESSION["id"]);

$isEdit = isset($_GET['ID']);
$id = $isEdit ? intval($_GET['ID']) : 0;

if (!$isEdit) {
    $storageCheck = checkStorageLimit($link, 1, USER_ID);
    if ($storageCheck) {
        $Message = $storageCheck;
        $type = "danger";
    }
}

$Message = "";
$type = "";

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $Message = "New record added successfully!";
    $type = "success";
}

$rowData = null;
if ($isEdit) {
    $sql = "SELECT * FROM " . tn('TBL_MAIN') . " WHERE ID = ?";
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rowData = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$rowData) {
            $Message = "Record not found!";
            $type = "danger";
            $isEdit = false;
        }
    } else {
        $Message = "Database error: " . mysqli_error($link);
        $type = "danger";
    }
}

if (isset($_POST['submit'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $Message = "Error: Invalid session token. Please try again.";
        $type = "danger";
    } else {
        $name       = trim($_POST['name'] ?? '');
        $mobile     = trim($_POST['mobile'] ?? '');
        $company    = trim($_POST['company'] ?? '');
        $package    = trim($_POST['package'] ?? '');
        $other_info = trim($_POST['other_info'] ?? '');
        $upload_to  = $_POST['upload_to'] ?? 'main';

        if (!preg_match('/^[6-9][0-9]{9}$/', $mobile)) {
            $Message = "Invalid mobile number! Must be 10 digits starting with 6-9.";
            $type = "danger";
        } else {
            $error = false;

            if ($isEdit) {
                $sql_update = "UPDATE " . tn('TBL_MAIN') . " SET 
                    MAINDATABASE_NAME = ?, 
                    MAINDATABASE_MOBILE = ?, 
                    MAINDATABASE_COMPANY = ?, 
                    MAINDATABASE_PACKAGE = ?, 
                    MAINDATABASE_OTHER_INFO = ?,
                    MAINDATABASE_CALL_DIALED_USER = ?,
                    MAINDATABASE_CALL_DIAL_TIME = NOW()
                    WHERE ID = ?";

                if ($stmt = mysqli_prepare($link, $sql_update)) {
                    mysqli_stmt_bind_param($stmt, "ssssiii", $name, $mobile, $company, $package, $other_info, $userId, $id);
                    if (mysqli_stmt_execute($stmt)) {
                        $Message = "Record updated successfully!";
                        $type = "success";

                        logActivity($link, $userId, "UPDATE", "Updated MAIN record (ID: $id)", (string)$id, tn('TBL_MAIN'));

                        $stmt2 = mysqli_prepare($link, "SELECT * FROM " . tn('TBL_MAIN') . " WHERE ID = ?");
                        mysqli_stmt_bind_param($stmt2, "i", $id);
                        mysqli_stmt_execute($stmt2);
                        $res2 = mysqli_stmt_get_result($stmt2);
                        $rowData = mysqli_fetch_assoc($res2);
                        mysqli_stmt_close($stmt2);
                    } else {
                        $Message = "Database error while updating main: " . mysqli_stmt_error($stmt);
                        $type = "danger";
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    $Message = "Database prepare error: " . mysqli_error($link);
                    $type = "danger";
                }

            } else {
                $useTransaction = ($upload_to === 'both');

                if ($useTransaction) {
                    mysqli_begin_transaction($link);
                }

                $insertedMainId = null;
                $insertedTempId = null;

                if ($upload_to === 'main' || $upload_to === 'both') {
                    $chk_main_sql = "SELECT ID FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = ?";
                    if ($chk_stmt = mysqli_prepare($link, $chk_main_sql)) {
                        mysqli_stmt_bind_param($chk_stmt, "s", $mobile);
                        mysqli_stmt_execute($chk_stmt);
                        $resChkMain = mysqli_stmt_get_result($chk_stmt);
                        if (mysqli_num_rows($resChkMain) > 0) {
                            $Message = "This mobile number already exists in Main Database!";
                            $type = "warning";
                            $error = true;
                        }
                        mysqli_stmt_close($chk_stmt);
                    } else {
                        $Message = "Database error: " . mysqli_error($link);
                        $type = "danger";
                        $error = true;
                    }

                    if (!$error) {
                        $ins_main_sql = "INSERT INTO " . tn('TBL_MAIN') . " 
                            (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_USER, MAINDATABASE_CALL_DIAL_TIME)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())";
                        if ($stmtMain = mysqli_prepare($link, $ins_main_sql)) {
                            mysqli_stmt_bind_param($stmtMain, "sssssi", $name, $mobile, $company, $package, $other_info, $userId);
                            if (mysqli_stmt_execute($stmtMain)) {
                                $insertedMainId = mysqli_insert_id($link);
                            } else {
                                $Message = "Database error inserting into Main: " . mysqli_stmt_error($stmtMain);
                                $type = "danger";
                                $error = true;
                            }
                            mysqli_stmt_close($stmtMain);
                        } else {
                            $Message = "Prepare error (main insert): " . mysqli_error($link);
                            $type = "danger";
                            $error = true;
                        }
                    }
                }

                if (!$error && ($upload_to === 'temp' || $upload_to === 'both')) {
                    $chk_temp_sql = "SELECT ID FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = ?";
                    if ($chk_stmt = mysqli_prepare($link, $chk_temp_sql)) {
                        mysqli_stmt_bind_param($chk_stmt, "s", $mobile);
                        mysqli_stmt_execute($chk_stmt);
                        $resChkTemp = mysqli_stmt_get_result($chk_stmt);
                        if (mysqli_num_rows($resChkTemp) > 0) {
                            $Message = "This mobile number already exists in Temporary Database!";
                            $type = "warning";
                            $error = true;
                        }
                        mysqli_stmt_close($chk_stmt);
                    } else {
                        $Message = "Database error: " . mysqli_error($link);
                        $type = "danger";
                        $error = true;
                    }

                    if (!$error) {
                        $ins_temp_sql = "INSERT INTO " . tn('TBL_TEMP') . " 
                            (CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, CALL_DIALED_TELECALLER)
                            VALUES (?, ?, ?, ?, ?, ?)";
                        if ($stmtTemp = mysqli_prepare($link, $ins_temp_sql)) {
                            mysqli_stmt_bind_param($stmtTemp, "sssssi", $name, $mobile, $company, $package, $other_info, $userId);
                            if (mysqli_stmt_execute($stmtTemp)) {
                                $insertedTempId = mysqli_insert_id($link);
                            } else {
                                $Message = "Database error inserting into Temp: " . mysqli_stmt_error($stmtTemp);
                                $type = "danger";
                                $error = true;
                            }
                            mysqli_stmt_close($stmtTemp);
                        } else {
                            $Message = "Prepare error (temp insert): " . mysqli_error($link);
                            $type = "danger";
                            $error = true;
                        }
                    }
                }

                if ($useTransaction) {
                    if ($error) {
                        mysqli_rollback($link);
                    } else {
                        mysqli_commit($link);
                    }
                }

                if (!$error) {
                    if ($insertedMainId) {
                        logActivity($link, $userId, "INSERT", "Inserted into TBL_MAIN", (string)$insertedMainId, tn('TBL_MAIN'));
                    }
                    if ($insertedTempId) {
                        logActivity($link, $userId, "INSERT", "Inserted into TBL_TEMP", (string)$insertedTempId, tn('TBL_TEMP'));
                    }

                    $Message = "New record added successfully!";
                    $type = "success";
                    header("Location: add_single_number?success=1");
                    exit;
                }
            }
        }
    }
}

$pageTitle = ($isEdit ? 'Edit Customer' : 'Add Single Number') . ' • CallNow CRM';
include '../../php_scripts/header.php';
?>

<div class="container-fluid px-3 py-3">
    <!-- Module Header -->
    <div class="module-header mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 fs-xs">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/database/data_management_main.php') ?>">Data Management</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? 'Edit Customer' : 'Add Single Number' ?></li>
                </ol>
            </nav>
            <h1 class="h4 fw-bold text-dark mb-0"><?= $isEdit ? 'Edit Customer Record' : 'Add Single Number' ?></h1>
            <div class="text-muted fs-xs">Manual entry and instant registration of customer phone numbers into CRM repository</div>
        </div>
        <div class="module-header-actions">
            <a href="<?= url('modules/database/data_management_main.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-database me-1"></i> Main Database
            </a>
            <a href="<?= url('modules/database/upload_data.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-cloud-upload me-1"></i> Bulk CSV Upload
            </a>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8 col-12">
            <?php if (!empty($Message)): ?>
                <div class="alert alert-<?= htmlspecialchars($type ?: 'info'); ?> alert-dismissible fade show mb-3" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="bi <?= ($type === 'success') ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2 fs-5"></i>
                        <div class="fs-xs"><?= htmlspecialchars($Message) ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="crm-card">
                <div class="crm-card-header">
                    <div class="crm-card-title">
                        <i class="bi <?= $isEdit ? 'bi-pencil-square' : 'bi-person-plus'; ?> text-primary me-2"></i>
                        <?= $isEdit ? 'Customer Record Information' : 'New Customer Details' ?>
                    </div>
                </div>
                <div class="crm-card-body">
                    <form method="POST" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="name">Customer Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="name" class="form-control" placeholder="e.g. Ramesh Kumar" required
                                       value="<?php echo htmlspecialchars($rowData['MAINDATABASE_NAME'] ?? ''); ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="mobile">Mobile Number <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                    <input type="text" name="mobile" id="mobile" class="form-control" placeholder="10-digit mobile"
                                           pattern="[6-9][0-9]{9}" title="10-digit mobile starting with 6-9" required
                                           value="<?php echo htmlspecialchars($rowData['MAINDATABASE_MOBILE'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="company">Company / Organization</label>
                                <input type="text" name="company" id="company" class="form-control" placeholder="e.g. Tata Consultancy"
                                       value="<?php echo htmlspecialchars($rowData['MAINDATABASE_COMPANY'] ?? ''); ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="package">Package / Product Tag</label>
                                <input type="text" name="package" id="package" class="form-control" placeholder="e.g. Personal Loan / Premium"
                                       value="<?php echo htmlspecialchars($rowData['MAINDATABASE_PACKAGE'] ?? ''); ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="upload_to">Target Repository</label>
                                <select name="upload_to" id="upload_to" class="form-select" <?php echo $isEdit ? 'disabled' : ''; ?>>
                                    <option value="main" <?php if(!isset($_POST['upload_to']) && !isset($rowData) || (isset($_POST['upload_to']) && $_POST['upload_to']=='main')) echo 'selected'; ?>>Main Database</option>
                                    <option value="temp" <?php if(isset($_POST['upload_to']) && $_POST['upload_to']=='temp') echo 'selected'; ?>>Temporary Staging Database</option>
                                    <option value="both" <?php if(isset($_POST['upload_to']) && $_POST['upload_to']=='both') echo 'selected'; ?>>Both (Synchronized)</option>
                                </select>
                                <?php if ($isEdit): ?>
                                    <div class="form-text fs-xs">Target database cannot be changed while updating an existing record.</div>
                                <?php endif; ?>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="other_info">Additional Notes & Remarks</label>
                                <textarea name="other_info" id="other_info" class="form-control" rows="3" placeholder="Lead background, customer timing preference, or call notes..."><?php echo htmlspecialchars($rowData['MAINDATABASE_OTHER_INFO'] ?? ''); ?></textarea>
                            </div>

                            <div class="col-12 pt-2 border-top d-flex align-items-center justify-content-between gap-2">
                                <a href="<?= url('modules/database/data_management_main.php') ?>" class="btn btn-secondary">
                                    <i class="bi bi-x-lg me-1"></i> Cancel
                                </a>
                                <button type="submit" name="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-1"></i> <?php echo $isEdit ? 'Update Customer Record' : 'Save & Register Number'; ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>
