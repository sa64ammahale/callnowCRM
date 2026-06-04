<?php
require_once 'php_scripts/auth.php';


if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}


?>

<!-- Bootstrap 5 + Bootstrap Icons CDN (MUST BE IN <head> OF EVERY PAGE) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<!-- YOUR PERFECT NAVBAR STARTS HERE -->
<nav class="navbar navbar-expand-lg shadow-sm" 
     style="background: linear-gradient(135deg, #1e3a8a, #3b82f6);">
    <div class="container-fluid px-4 py-1">

        <!-- Brand with Phone Icon -->
        <a class="navbar-brand fw-bold fs-4 text-white d-flex align-items-center gap-2" href="dashboard.php">
            <i class="bi bi-telephone-fill text-warning fs-3"></i>
            CallNow
        </a>

        <!-- Mobile Toggle Button -->
        <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Menu Items -->
        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav ms-auto align-items-center gap-3">

                <?php if (isAdmin()): ?>
                <!-- MY DATABASE -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white fw-semibold d-flex align-items-center gap-2" href="#" 
                       role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-database-fill text-info"></i> My Database
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg">
                        <li><a class="dropdown-item" href="data_management_temporary.php"><i class="bi bi-table me-2 text-primary"></i> Temporary Database</a></li>
                        <li><a class="dropdown-item" href="data_management_main.php"><i class="bi bi-table me-2 text-primary"></i> Main Database</a></li>
                        <li><a class="dropdown-item" href="UploadData.php"><i class="bi bi-cloud-upload-fill  me-2 text-success"></i> Upload Data </a></li>
                        <li><a class="dropdown-item" href="add_update_status.php"><i class="bi bi-slash-circle me-2 text-warning"></i> Add Update Status</a></li>
                        <li><a class="dropdown-item" href="ViewDND.php"><i class="bi bi-slash-circle me-2 text-danger"></i> View DND Numbers</a></li>
                    </ul>
                </li>
                <?php endif; ?>
                
                
                <?php if (isAdmin() || isManager()): ?>
                <?php endif; ?>
                
                <?php if (isSupervisor() || isAdmin() || isManager()): ?>
                <!-- ENQUIRIES -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white fw-semibold d-flex align-items-center gap-2" href="leads_dashboard.php" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-bookmark-heart text-cyan"></i> Leads Management
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg">
                         <li><a class="dropdown-item" href="leads_dashboard.php"><i class="bi bi-bookmark-heart me-2 text-info"></i> Leads Dashboard</a></li>
                        <li><a class="dropdown-item" href="lead_list.php"><i class="bi bi-list-ul me-2 text-info"></i> View Leads</a></li>
                        <li><a class="dropdown-item" href="lead_insert.php"><i class="bi bi-plus-square me-2 text-success"></i> Add New Leds</a></li>
                    </ul>
                </li>
                <?php endif; ?>
                <?php if (isSupervisor() || isAdmin() || isManager()): ?>
                <!-- APP USERS -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white fw-semibold d-flex align-items-center gap-2" href="#" 
                       role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-people-fill text-light"></i> App Users
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg">
                        <li><a class="dropdown-item" href="users_view.php"><i class="bi bi-person-lines-fill me-2 text-primary"></i> View Users</a></li>
                        <li><a class="dropdown-item" href="users_add.php"><i class="bi bi-person-plus-fill me-2 text-success"></i> Add User</a></li>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white fw-semibold d-flex align-items-center gap-2" href="#" 
                       role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-people-fill text-light"></i> Teams
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg">
                        <li><a class="dropdown-item" href="teams_dashboard.php"><i class="bi bi-person-lines-fill me-2 text-primary"></i> Manage Teams</a></li>
                        <li><a class="dropdown-item" href="team_members.php"><i class="bi bi-person me-2 text-primary"></i> Team Members</a></li>
                        
                    </ul>
                </li>
                
                
                <?php endif; ?>
                
                <!-- REPORTS -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white fw-semibold d-flex align-items-center gap-2" href="#" 
                       role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-graph-up-arrow text-light"></i> Reports
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg">
                        <li><a class="dropdown-item" href="Reports.php?type=daily"><i class="bi bi-calendar-day me-2 text-primary"></i> Daily Report</a></li>
                        <li><a class="dropdown-item" href="Reports.php?type=monthly"><i class="bi bi-calendar-month me-2 text-info"></i> Monthly Report</a></li>
                        <li><a class="dropdown-item" href="Reports.php?type=yearly"><i class="bi bi-calendar-month me-2 text-warning"></i> Yearly Report</a></li>
                    </ul>
                </li>


                <!-- REPORTS -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white fw-semibold d-flex align-items-center gap-2" href="#" 
                       role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear-wide-connected text-light"></i> Settings
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg">
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2 text-primary"></i> My Profile</a></li>
                        <li><a class="dropdown-item" href="manage_activity.php"><i class="bi bi-activity me-2 text-info"></i> Activity Log</a></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2 text-danger"></i> Logout</a></li>
                    </ul>
                </li>


                <!-- LOGOUT -->
                <li class="nav-item">

                </li>

            </ul>
        </div>
    </div>
</nav>

