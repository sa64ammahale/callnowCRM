<?php
require_once 'php_scripts/auth.php';
require_once 'php_scripts/team_auth.php';

$allowed_roles = ['Admin', 'Manager', 'Supervisor', 'Officer'];

if (!in_array(USER_ROLE, $allowed_roles)) {
    header("Location: leads_dashboard.php");
    exit;
}

if (!defined('USER_ID')) {
    define('USER_ID', $_SESSION['id'] ?? 0);
}

mysqli_set_charset($link, 'utf8mb4');

// Fetch active users for assignment
$user_query = "SELECT ID, NAME FROM USERS WHERE STATUS = 'Active' ORDER BY NAME";
if (USER_ROLE === 'Officer') {
    $user_query = "SELECT ID, NAME FROM USERS WHERE ID = " . (int)USER_ID;
}
$users_result = mysqli_query($link, $user_query);
$users = mysqli_fetch_all($users_result, MYSQLI_ASSOC);

// Process form
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $mobile      = trim($_POST['mobile'] ?? '');
    $company     = trim($_POST['company'] ?? '');
    $assigned_to = (int)($_POST['assigned_to'] ?? 0);

    if (empty($mobile) || strlen($mobile) < 10) {
        $error = "Valid mobile number is required.";
    } elseif ($assigned_to <= 0) {
        $error = "Please select a user to assign.";
    } else {
        // Check if mobile already exists in MAIN_DATABASE
        $check_main = mysqli_fetch_assoc(mysqli_query(
            $link,
            "SELECT ID FROM MAIN_DATABASE WHERE MAINDATABASE_MOBILE = '" . mysqli_real_escape_string($link, $mobile) . "'"
        ));

        $cust_id = 0;
        if ($check_main) {
            $cust_id = $check_main['ID'];
        } else {
            // Insert into MAIN_DATABASE first
            $stmt = mysqli_prepare($link, "
                INSERT INTO MAIN_DATABASE 
                (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_UPLOAD_DATETIME)
                VALUES (?, ?, ?, NOW())
            ");
            mysqli_stmt_bind_param($stmt, "sss", $name, $mobile, $company);
            mysqli_stmt_execute($stmt);
            $cust_id = mysqli_insert_id($link);
            mysqli_stmt_close($stmt);
        }

        // Check if lead already exists
        $check_lead = mysqli_fetch_row(mysqli_query($link, "SELECT 1 FROM LEADS_TABLE WHERE cust_id = $cust_id"));
        if ($check_lead) {
            $error = "This mobile number is already assigned as a lead.";
        } else {
            // Create Lead - 100% WORKING
            $stmt = mysqli_prepare($link, "
                INSERT INTO LEADS_TABLE 
                (cust_id, assigned_to, assigned_by, lead_status, lead_stage, priority, created_by, created_at, updated_at)
                VALUES (?, ?, ?, 'New', 'Cold', 'Medium', ?, NOW(), NOW())
            ");

            $user_id_val = (int)USER_ID;

            mysqli_stmt_bind_param($stmt, "iiii", $cust_id, $assigned_to, $user_id_val, $user_id_val);
            $executed = mysqli_stmt_execute($stmt);

            if ($executed) {
                $lead_id = mysqli_insert_id($link);

                $details = "Manual lead created (Mobile: $mobile) → Assigned to User ID: $assigned_to";
                $log_stmt = mysqli_prepare($link, "
                    INSERT INTO ACTIVITY_LOG 
                    (USER_ID, ACTION_TYPE, ACTION_DETAILS, AFFECTED_IDS, TARGET_TABLE, IP_ADDRESS)
                    VALUES (?, 'Manual Lead Created', ?, ?, 'LEADS_TABLE', ?)
                ");
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                mysqli_stmt_bind_param($log_stmt, "isis", $user_id_val, $details, $lead_id, $ip);
                mysqli_stmt_execute($log_stmt);
                mysqli_stmt_close($log_stmt);

                $success = "Lead created successfully! <a href='lead_view.php?id=$lead_id' class='alert-link'>View Lead</a>";
            } else {
                $error = "Failed to save lead. Please try again.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add New Lead - CallNow</title>
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
        .page-header-actions .btn {
            font-size: 0.78rem;
            padding: 0.25rem 0.7rem;
            border-radius: 999px;
        }

        /* Compact card form */
        .card-custom {
            background: #ffffff;
            border-radius: 0.9rem;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.09);
            padding: 1.25rem 1.1rem;
        }

        .form-label {
            font-size: 0.8rem;
            margin-bottom: 0.2rem;
        }
        .form-control-sm, .form-select-sm {
            font-size: 0.8rem;
        }

        .auto-fill {
            transition: all 0.3s;
        }

        .help-text {
            font-size: 0.7rem;
        }

        .submit-btn {
            font-size: 0.85rem;
            padding: 0.45rem 1.8rem;
            border-radius: 999px;
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
                <i class="bi bi-person-plus-fill text-primary me-1"></i>
                Add New Lead
            </p>
            <p class="page-header-desc mb-0">
                Enter customer mobile, auto-fill details if they exist, then assign to a team member.
            </p>
        </div>
        <div class="page-header-actions">
            <a href="leads_dashboard.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-grid"></i> Go to Dashboard
            </a>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3 py-2">
            <?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-3 py-2">
            <?= $error ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Form card -->
    <div class="card-custom">
        <form method="POST" id="leadForm">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">
                        Customer Mobile Number <span class="text-danger">*</span>
                    </label>
                    <input type="text"
                           name="mobile"
                           id="mobile"
                           class="form-control form-control-sm"
                           placeholder="Enter 10-digit mobile"
                           maxlength="15"
                           required
                           value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>">
                    <div class="help-text text-muted">
                        Type full number, system will auto-fetch existing data (if any).
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">
                        Assign To <span class="text-danger">*</span>
                    </label>
                    <select name="assigned_to"
                            class="form-select form-select-sm"
                            required>
                        <option value="">Choose Team Member</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['ID'] ?>"
                                <?= (isset($_POST['assigned_to']) && $_POST['assigned_to'] == $user['ID']) || (!isset($_POST['assigned_to']) && $user['ID'] == USER_ID) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['NAME']) ?> (You)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Customer Name</label>
                    <input type="text"
                           name="name"
                           id="name"
                           class="form-control form-control-sm auto-fill"
                           placeholder="Auto-filled if exists"
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Company / Source</label>
                    <input type="text"
                           name="company"
                           id="company"
                           class="form-control form-control-sm auto-fill"
                           placeholder="Optional"
                           value="<?= htmlspecialchars($_POST['company'] ?? '') ?>">
                </div>

                <div class="col-12 text-end mt-2">
                    <button type="submit" class="btn btn-primary submit-btn">
                        <i class="bi bi-check-circle me-1"></i>
                        Create & Assign Lead
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Auto-fetch customer when mobile is typed
document.getElementById('mobile').addEventListener('blur', function() {
    const mobile = this.value.trim();
    if (mobile.length >= 10) {
        fetch('php_scripts/lead_ajax_check_mobile.php?mobile=' + encodeURIComponent(mobile))
            .then(r => r.json())
            .then(data => {
                if (data.exists) {
                    document.getElementById('name').value = data.name || '';
                    document.getElementById('company').value = data.company || '';
                    document.querySelectorAll('.auto-fill').forEach(el => {
                        el.style.backgroundColor = '#e8f5e8';
                        setTimeout(() => el.style.backgroundColor = '', 800);
                    });
                } else {
                    document.getElementById('name').value = '';
                    document.getElementById('company').value = '';
                }
            })
            .catch(() => console.log("Auto-fetch failed"));
    }
});
</script>

<?php include 'php_scripts/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
