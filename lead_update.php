<?php
require_once 'php_scripts/auth.php';
require_once 'php_scripts/team_auth.php';

$allowed_roles = ['Admin', 'Manager', 'Supervisor', 'Officer'];
if (!in_array(USER_ROLE, $allowed_roles)) {
    header("Location: leads_dashboard.php");
    exit;
}

// Process the form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lead_id'])) {
    $lead_id = (int)$_POST['lead_id'];
    $action = $_POST['action'] ?? '';
    $user_id = USER_ID; 

    if ($action === 'update_status') {
        $new_status = $_POST['lead_status'] ?? '';
        $next_followup = $_POST['next_followup_at'] ?? null;

        $stmt = mysqli_prepare($link, "
            UPDATE LEADS_TABLE 
            SET lead_status = ?, next_followup_at = ?, updated_at = NOW(), updated_by = ?
            WHERE lead_id = ?
        ");
        mysqli_stmt_bind_param($stmt, "ssii", $new_status, $next_followup, $user_id, $lead_id);
        mysqli_stmt_execute($stmt);

        // Log it!
        $details = "Updated status to '$new_status'. Next follow-up: " . ($next_followup ?: 'None');
        logActivity($link, USER_ID, 'Status Update', $details, (string)$lead_id, 'LEADS_TABLE');

        mysqli_stmt_close($stmt);
    } elseif ($action === 'add_remark') {
        $remarks = trim($_POST['remarks'] ?? '');

        $stmt = mysqli_prepare($link, "
            UPDATE LEADS_TABLE 
            SET remarks = ?, updated_at = NOW(), updated_by = ?
            WHERE lead_id = ?
        ");
        mysqli_stmt_bind_param($stmt, "sii", $remarks, USER_ID, $lead_id);
        mysqli_stmt_execute($stmt);

        // Log it!
        $details = "Added/Updated remark: '$remarks'";
        logActivity($link, USER_ID, 'Added Remark', $details, (string)$lead_id, 'LEADS_TABLE');

        mysqli_stmt_close($stmt);
    }
}

// Redirect back to view with success
$_SESSION['success_message'] = "Lead updated successfully!";
header("Location: lead_view.php?id=$lead_id");
exit;
?>