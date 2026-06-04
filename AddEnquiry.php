<?php
require_once 'php_scripts/auth.php';

$Message = ""; $type = "";

if (isset($_POST["addEnqry"])) {
    $required_date = date("Y-m-d");
    $product       = trim($_POST['requirement']);
    $name          = trim($_POST['name']);
    $mobile        = trim($_POST['mobile']);
    $company       = trim($_POST['company']);
    $amount        = trim($_POST['amount']);
    $net_salary    = trim($_POST['salary']);
    $remark        = trim($_POST['remark']);
    $user_id       = $_POST['userid'];
    $res_type      = $_POST['HouseType'];
    $sal_acc       = trim($_POST['SalAcc']);

    // Duplicate check
    $check = mysqli_prepare($link, "SELECT ID FROM ENQUIRY WHERE MOBILE = ? AND REQ_PRODUCT = ?");
    mysqli_stmt_bind_param($check, "ss", $mobile, $product);
    mysqli_stmt_execute($check);
    if (mysqli_stmt_fetch($check)) {
        $Message = "Same enquiry already exists for this mobile & product!";
        $type = "warning";
    } else {
        $stmt = mysqli_prepare($link, "INSERT INTO ENQUIRY 
            (REQ_DATE, TSA_ID, RESIDENCE_TYPE, REQ_PRODUCT, CLIENT_NAME, MOBILE, COMPANY, LOAN_AMNT, NET_SAL, SAL_ACC, REMARK)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "sissssidsss", $required_date, $user_id, $res_type, $product, $name, $mobile, $company, $amount, $net_salary, $sal_acc, $remark);

        if (mysqli_stmt_execute($stmt)) {
            // Update main database status
            mysqli_query($link, "UPDATE MAINDATABASE SET STATUS='ENQ' WHERE MOBILE = '$mobile' LIMIT 1");
            $Message = "New enquiry added successfully!";
            $type = "success";
        } else {
            $Message = "Database error. Please try again.";
            $type = "danger";
        }
    }
}

// Fetch real user names
$users = mysqli_query($link, "SELECT ID, NAME FROM USERS WHERE NAME IS NOT NULL AND NAME != '' ORDER BY NAME ASC");
mysqli_close($link);
?>



    




<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add New Enquiry - CallNow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
        <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons (this was missing!) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    
    <style>
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f0f2f5 0%, #e6e9f0 100%); min-height: 100vh; }
        .page-header {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: white;
            padding: 3rem 0;
            border-radius: 0 0 2rem 2rem;
            box-shadow: 0 10px 30px rgba(30,60,114,0.4);
        }
        .form-card {
            background: white;
            border-radius: 1.5rem;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .btn-gradient {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            border: none;
            border-radius: 50px;
            padding: 0.85rem 3rem;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .btn-gradient:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px rgba(30,60,114,0.4);
        }
        .form-control:focus, .form-select:focus {
            border-color: #2a5298;
            box-shadow: 0 0 0 0.25rem rgba(42,82,152,0.25);
        }
    </style>
</head>
<body>

<?php include 'php_scripts/header.php'; ?>

<!-- Page Header -->
<div class="page-header text-center">
    <div class="container">
        <h1 class="display-5 fw-bold mb-2">
            Add New Loan Enquiry
        </h1>
        <p class="lead opacity-90">Fill in customer details below</p>
    </div>
</div>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-md-10">

            <!-- Alert -->
            <?php if ($Message): ?>
                <div class="alert alert-<?= $type ?> alert-dismissible fade show rounded-4 shadow-sm mb-4">
                    <i class="bi <?= $type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
                    <strong><?= ucfirst($type) ?>!</strong> <?= $Message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="form-card">
                <div class="card-body p-5">
                    <form method="POST" class="row g-4">
                        <!-- Client Name & Mobile -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Client Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control form-control-lg" placeholder="Rahul Sharma" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Mobile Number <span class="text-danger">*</span></label>
                            <input type="text" name="mobile" class="form-control form-control-lg" placeholder="9876543210" pattern="[6-9][0-9]{9}" required>
                        </div>

                        <!-- Company & Salary Account -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Company Name</label>
                            <input type="text" name="company" class="form-control form-control-lg" placeholder="ABC Pvt Ltd">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Salary Account Bank</label>
                            <input type="text" name="SalAcc" class="form-control form-control-lg" placeholder="HDFC Bank">
                        </div>

                        <!-- Residence & Product -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Residence Type</label>
                            <select name="HouseType" class="form-select form-select-lg">
                                <option>Own House</option>
                                <option>Rented-Family</option>
                                <option>Rented-Bachelor</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Loan Requirement <span class="text-danger">*</span></label>
                            <select name="requirement" class="form-select form-select-lg" required>
                                <option>Personal Loan</option>
                                <option>Home Loan</option>
                                <option>Business Loan</option>
                                <option>Credit Cards</option>
                            </select>
                        </div>

                        <!-- Amount & Salary -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Required Amount (₹) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control form-control-lg" placeholder="500000" min="10000" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Net Salary (₹)</label>
                            <input type="number" name="salary" class="form-control form-control-lg" placeholder="45000">
                        </div>

                        <!-- User & Remark -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Assigned To (App User)</label>
                            <select name="userid" class="form-select form-select-lg" required>
                                <option value="">Select User</option>
                                <?php while ($user = mysqli_fetch_assoc($users)): ?>
                                    <option value="<?= $user['ID'] ?>">
                                        <?= htmlspecialchars($user['NAME']) ?> (ID: <?= $user['ID'] ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Remark / Other Info</label>
                            <input type="text" name="remark" class="form-control form-control-lg" placeholder="CIBIL 720, 2 years ITR">
                        </div>

                        <!-- Submit -->
                        <div class="col-12 text-center mt-5">
                            <button type="submit" name="addEnqry" class="btn btn-gradient text-white btn-lg px-5">
                                Save Enquiry
                            </button>
                            <a href="ViewEnquiry.php" class="btn btn-outline-secondary btn-lg ms-3">
                                View All Enquiries
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'php_scripts/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>