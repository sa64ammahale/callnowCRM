<?php

// Require authentication file (should create $link mysqli connection and maybe check user)
require_once 'php_scripts/auth.php';

$userId = intval($_SESSION["id"]);

// ---- Initialize -------------------------------------------------------------
$isEdit = isset($_GET['ID']);
$id = $isEdit ? intval($_GET['ID']) : 0;

$Message = "";
$type = "";

// Table name constants (change if your actual names differ)
define('TBL_MAIN', 'MAIN_DATABASE');
define('TBL_TEMP', 'TEMPORARY_DATABASE');
define('TBL_ACTIVITY', 'ACTIVITY_LOG');

// Show success message (redirect pattern)
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $Message = "New record added successfully!";
    $type = "success";
}

// If edit, load the main database row into $rowData
$rowData = null;
if ($isEdit) {
    $sql = "SELECT * FROM " . TBL_MAIN . " WHERE ID = ?";
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $rowData = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$rowData) {
            $Message = "Record not found!";
            $type = "danger";
            $isEdit = false; // fallback
        }
    } else {
        $Message = "Database error: " . mysqli_error($link);
        $type = "danger";
    }
}

// ---- Form submit handling ---------------------------------------------------
if (isset($_POST['submit'])) {

    // Collect and sanitize
    $name       = trim($_POST['name'] ?? '');
    $mobile     = trim($_POST['mobile'] ?? '');
    $company    = trim($_POST['company'] ?? '');
    $package    = trim($_POST['package'] ?? '');
    $other_info = trim($_POST['other_info'] ?? '');
    $upload_to  = $_POST['upload_to'] ?? 'main'; // main / temp / both

    // Basic mobile validation
    if (!preg_match('/^[6-9][0-9]{9}$/', $mobile)) {
        $Message = "Invalid mobile number! Must be 10 digits starting with 6-9.";
        $type = "danger";
    } else {
        // Start logic
        $error = false;

        // If editing: update MAIN_DATABASE record only
        if ($isEdit) {
            $sql_update = "UPDATE " . TBL_MAIN . " SET 
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

                    // log activity
                    logActivity($link, $userId, "UPDATE", "Updated MAIN record (ID: $id)", (string)$id, TBL_MAIN);

                    // reload rowData
                    $stmt2 = mysqli_prepare($link, "SELECT * FROM " . TBL_MAIN . " WHERE ID = ?");
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
            // INSERT mode (not edit) -> insert into target(s)

            // We will use transaction when inserting into both to ensure consistency
            $useTransaction = ($upload_to === 'both');

            if ($useTransaction) {
                mysqli_begin_transaction($link);
            }

            $insertedMainId = null;
            $insertedTempId = null;

            // If MAIN or BOTH chosen -> check duplicate in MAIN then insert
            if ($upload_to === 'main' || $upload_to === 'both') {

                // Duplicate check in MAIN
                $chk_main_sql = "SELECT ID FROM " . TBL_MAIN . " WHERE MAINDATABASE_MOBILE = ?";
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

                // Insert MAIN if no error
                if (!$error) {
                    $ins_main_sql = "INSERT INTO " . TBL_MAIN . " 
                        (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_USER, MAINDATABASE_CALL_DIAL_TIME)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())";
                    if ($stmtMain = mysqli_prepare($link, $ins_main_sql)) {
                        mysqli_stmt_bind_param($stmtMain, "sssssi", $name, $mobile, $company, $package, $other_info, $userId);
                        if (mysqli_stmt_execute($stmtMain)) {
                            $insertedMainId = mysqli_insert_id($link);
                            // log later (or immediately)
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

            // If TEMP or BOTH chosen -> check duplicate in TEMP then insert
            if (!$error && ($upload_to === 'temp' || $upload_to === 'both')) {

                // Duplicate check in TEMP
                $chk_temp_sql = "SELECT ID FROM " . TBL_TEMP . " WHERE CUST_MOBILE = ?";
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
                    $ins_temp_sql = "INSERT INTO " . TBL_TEMP . " 
                        (CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, CALL_DIALED_TELECALLER)
                        VALUES (?, ?, ?, ?, ?, ?)";
                    if ($stmtTemp = mysqli_prepare($link, $ins_temp_sql)) {
                        mysqli_stmt_bind_param($stmtTemp, "sssssi", $name, $mobile, $company, $package, $other_info, $userId);
                        if (mysqli_stmt_execute($stmtTemp)) {
                            $insertedTempId = mysqli_insert_id($link);
                            // log later (or immediately)
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

            // If using transaction: commit or rollback based on $error
            if ($useTransaction) {
                if ($error) {
                    mysqli_rollback($link);
                } else {
                    mysqli_commit($link);
                }
            }

            // If no error, write activity logs for inserted records
            if (!$error) {
                if ($insertedMainId) {
                    logActivity($link, $userId, "INSERT", "Inserted into MAIN_DATABASE", (string)$insertedMainId, TBL_MAIN);
                }
                if ($insertedTempId) {
                    logActivity($link, $userId, "INSERT", "Inserted into TEMPORARY_DATABASE", (string)$insertedTempId, TBL_TEMP);
                }

                // Success message and redirect (preserve original behavior)
                $Message = "New record added successfully!";
                $type = "success";
                header("Location: add_single_number.php?success=1");
                exit;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo $isEdit ? 'Edit' : 'Add'; ?> Customer - CallNow</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            padding: 2rem 0;
        }
        .form-card {
            background: white;
            border-radius: 1.2rem;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 600px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 1.2rem 1.2rem 0 0 !important;
            padding: 1.2rem;
            text-align: center;
        }
        .btn-gradient {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            padding: 0.65rem 2rem;
            border-radius: 50px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<?php include 'php_scripts/header.php'; ?>

<div class="main-content">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7 col-md-9 col-11">
                <div class="form-card">
                    <div class="card-header">
                        <h4 class="mb-0 fw-bold">
                            <i class="bi <?php echo $isEdit ? 'bi-pencil-square' : 'bi-person-plus-fill'; ?> me-2"></i>
                            <?php echo $isEdit ? 'Edit Customer' : 'Add New Customer'; ?>
                        </h4>
                    </div>

                    <div class="card-body p-4">
                        <?php if (!empty($Message)): ?>
                            <div class="alert alert-<?php echo htmlspecialchars($type ?: 'info'); ?> alert-dismissible fade show mb-4">
                                <i class="bi <?php echo ($type === 'success') ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
                                <strong><?php echo ucfirst($type ?: 'Note'); ?>!</strong> <?php echo $Message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" novalidate>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label"><i class="bi bi-person"></i> Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" placeholder="Rahul Sharma" required
                                           value="<?php echo htmlspecialchars($rowData['MAINDATABASE_NAME'] ?? ''); ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label"><i class="bi bi-phone"></i> Mobile Number <span class="text-danger">*</span></label>
                                    <input type="text" name="mobile" class="form-control" placeholder="9876543210"
                                           pattern="[6-9][0-9]{9}" title="10-digit mobile starting with 6-9" required
                                           value="<?php echo htmlspecialchars($rowData['MAINDATABASE_MOBILE'] ?? ''); ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label"><i class="bi bi-building"></i> Company</label>
                                    <input type="text" name="company" class="form-control" placeholder="ABC Corp"
                                           value="<?php echo htmlspecialchars($rowData['MAINDATABASE_COMPANY'] ?? ''); ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label"><i class="bi bi-box"></i> Package</label>
                                    <input type="text" name="package" class="form-control" placeholder="Premium / Basic"
                                           value="<?php echo htmlspecialchars($rowData['MAINDATABASE_PACKAGE'] ?? ''); ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label"><i class="bi bi-cloud-upload"></i> Upload To</label>
                                    <select name="upload_to" class="form-select" <?php echo $isEdit ? 'disabled' : ''; ?>>
                                        <option value="temp" <?php if(isset($_POST['upload_to']) && $_POST['upload_to']=='temp') echo 'selected'; ?>>Temporary Database</option>
                                        <option value="main" <?php if(!isset($_POST['upload_to']) && !isset($rowData) || (isset($_POST['upload_to']) && $_POST['upload_to']=='main')) echo 'selected'; ?>>Main Database</option>
                                        
                                        <option value="both" <?php if(isset($_POST['upload_to']) && $_POST['upload_to']=='both') echo 'selected'; ?>>Both (Main + Temporary)</option>
                                    </select>
                                    <?php if ($isEdit): ?>
                                        <div class="form-text">Upload choice disabled in Edit mode (editing MAIN record).</div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-12">
                                    <label class="form-label"><i class="bi bi-info-circle"></i> Other Info</label>
                                    <textarea name="other_info" class="form-control" rows="3" placeholder="Extra details..."><?php echo htmlspecialchars($rowData['MAINDATABASE_OTHER_INFO'] ?? ''); ?></textarea>
                                </div>

                                <div class="col-12 d-grid gap-2">
                                    <button type="submit" name="submit" class="btn btn-gradient text-white btn-lg">
                                        <i class="bi bi-save"></i> <?php echo $isEdit ? 'Update Record' : 'Save Customer'; ?>
                                    </button>
                                    <a href="dashboard.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                                    </a>
                                </div>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'php_scripts/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
