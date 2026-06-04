<?php
// === AUTH & COMMONS ===
require_once 'php_scripts/auth.php';
require_once 'php_scripts/team_auth.php'; // has isAdmin(), isManager(), getManagerTeamIds()


// Only Admin & Manager are allowed here
if (!isAdmin() && !isManager()) {
    header("HTTP/1.1 403 Forbidden");
    echo "Access denied.";
    exit;
}

$msg = $msg_type = "";

// Current user
$currentUserId = $_SESSION['user_id'] ?? 0;

// Manager's allowed teams
$managerTeamIds = [];
if (isManager()) {
    $managerTeamIds = getManagerTeamIds($link, (int)$currentUserId);
    if (empty($managerTeamIds)) {
        $managerTeamIds = [-1]; // so IN() never matches anything
    }
}

// === FILTERS (GET) ===
$filter_team_id = isset($_GET['team_id']) && ctype_digit($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if (isManager() && $filter_team_id > 0 && !in_array($filter_team_id, $managerTeamIds, true)) {
    // Manager cannot filter to teams he doesn't own
    $filter_team_id = 0;
}

$search_name = trim($_GET['search_name'] ?? '');

$allowedRoles = ['Admin','Manager','Supervisor','Officer'];
$filter_role = $_GET['role'] ?? '';
if (!in_array($filter_role, $allowedRoles, true)) {
    $filter_role = '';
}

$allowedStatuses = ['Active','Inactive','Suspended'];
$filter_status = $_GET['status'] ?? '';
if (!in_array($filter_status, $allowedStatuses, true)) {
    $filter_status = '';
}

// ==================== ACTION: ASSIGN / TRANSFER MEMBER ====================
if (($_POST['action'] ?? '') === 'assign_member') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $team_id = ($_POST['team_id'] ?? '') !== '' ? (int)$_POST['team_id'] : null; // NULL = unassign

    if ($user_id <= 0) {
        $msg = "Invalid member selected.";
        $msg_type = "danger";
    } else {
        // Fetch current team of user
        $stmt = $link->prepare("SELECT TEAM_ID FROM USERS WHERE ID = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $userRow = $res->fetch_assoc();
        if (!$userRow) {
            $msg = "User not found.";
            $msg_type = "danger";
        } else {
            $current_team_id = $userRow['TEAM_ID'] !== null ? (int)$userRow['TEAM_ID'] : null;

            // Permission checks for Manager
            if (isManager()) {
                // Manager can only move users that are currently unassigned or in his teams
                if ($current_team_id !== null && !in_array($current_team_id, $managerTeamIds, true)) {
                    $msg = "You are not allowed to modify this member.";
                    $msg_type = "danger";
                }

                // Manager can only assign to his own teams OR unassign to NULL
                if ($team_id !== null && !in_array($team_id, $managerTeamIds, true)) {
                    $msg = "You are not allowed to assign members to this team.";
                    $msg_type = "danger";
                }
            }

            if (empty($msg)) {
                if ($team_id === null) {
                    $upd = $link->prepare("UPDATE USERS SET TEAM_ID = NULL WHERE ID = ?");
                    $upd->bind_param("i", $user_id);
                } else {
                    $upd = $link->prepare("UPDATE USERS SET TEAM_ID = ? WHERE ID = ?");
                    $upd->bind_param("ii", $team_id, $user_id);
                }

                if ($upd->execute()) {
                    $msg = "Member assignment updated successfully.";
                    $msg_type = "success";
                } else {
                    $msg = "Failed to update member assignment.";
                    $msg_type = "danger";
                }
            }
        }
    }
}

// ==================== ACTION: CHANGE SUPERVISOR ===========================
if (($_POST['action'] ?? '') === 'change_supervisor') {
    $team_id = (int)($_POST['team_id'] ?? 0);
    $supervisor_id = isset($_POST['supervisor_id']) && $_POST['supervisor_id'] !== ''
        ? (int)$_POST['supervisor_id']
        : null; // null = No Supervisor

    if ($team_id <= 0) {
        $msg = "Invalid team selected.";
        $msg_type = "danger";
    } else {
        // Permission: Manager must own the team
        if (isManager() && !in_array($team_id, $managerTeamIds, true)) {
            $msg = "You are not allowed to change supervisor of this team.";
            $msg_type = "danger";
        } else {
            if ($supervisor_id !== null) {
                // Validate supervisor user (role+status)
                $check = $link->prepare("
                    SELECT ID, ROLE, STATUS
                    FROM USERS
                    WHERE ID = ?
                      AND ROLE IN ('Supervisor','Manager')
                      AND STATUS = 'Active'
                ");
                $check->bind_param("i", $supervisor_id);
                $check->execute();
                $res = $check->get_result();
                if ($res->num_rows === 0) {
                    $msg = "Selected user is not an active Supervisor or Manager.";
                    $msg_type = "danger";
                }

                // Extra rule: one supervisor can supervise only ONE team at a time
                if (empty($msg)) {
                    $checkTeam = $link->prepare("
                        SELECT ID, NAME 
                        FROM TEAMS 
                        WHERE SUPERVISOR_ID = ? AND ID != ?
                        LIMIT 1
                    ");
                    $checkTeam->bind_param("ii", $supervisor_id, $team_id);
                    $checkTeam->execute();
                    $existing = $checkTeam->get_result()->fetch_assoc();
                    if ($existing) {
                        $msg = "This supervisor is already assigned to team \""
                             . $existing['NAME']
                             . "\" (ID " . (int)$existing['ID']
                             . "). One supervisor can manage only one team at a time.";
                        $msg_type = "danger";
                    }
                }
            }

            if (empty($msg)) {
                // Update TEAMS supervisor (ensures one supervisor per team)
                if ($supervisor_id === null) {
                    $sql = "UPDATE TEAMS SET SUPERVISOR_ID = NULL WHERE ID = ?";
                    if (isManager()) {
                        $sql .= " AND ID IN (" . implode(',', array_map('intval', $managerTeamIds)) . ")";
                    }
                    $stmt = $link->prepare($sql);
                    $stmt->bind_param("i", $team_id);
                } else {
                    $sql = "UPDATE TEAMS SET SUPERVISOR_ID = ? WHERE ID = ?";
                    if (isManager()) {
                        $sql .= " AND ID IN (" . implode(',', array_map('intval', $managerTeamIds)) . ")";
                    }
                    $stmt = $link->prepare($sql);
                    $stmt->bind_param("ii", $supervisor_id, $team_id);
                }

                if ($stmt->execute() && $stmt->affected_rows >= 0) {
                    // Optional: ensure supervisor is also assigned to that team as MEMBER
                    if ($supervisor_id !== null) {
                        $upd = $link->prepare("UPDATE USERS SET TEAM_ID = ? WHERE ID = ?");
                        $upd->bind_param("ii", $team_id, $supervisor_id);
                        $upd->execute();
                    }

                    $msg = "Team supervisor updated successfully.";
                    $msg_type = "success";
                } else {
                    $msg = "Failed to update supervisor.";
                    $msg_type = "danger";
                }
            }
        }
    }
}

// ==================== FETCH TEAMS (FOR DROPDOWNS & SUPERVISOR TABLE) ======
if (isAdmin()) {
    $teams_sql = "
        SELECT 
            t.ID,
            t.NAME,
            t.SUPERVISOR_ID,
            sup.NAME AS SUP_NAME,
            mgr.NAME AS MANAGER_NAME
        FROM TEAMS t
        LEFT JOIN USERS sup ON t.SUPERVISOR_ID = sup.ID
        LEFT JOIN USERS mgr ON t.MANAGER_ID = mgr.ID
        ORDER BY t.NAME
    ";
} else {
    $ids_str = implode(',', array_map('intval', $managerTeamIds));
    $teams_sql = "
        SELECT 
            t.ID,
            t.NAME,
            t.SUPERVISOR_ID,
            sup.NAME AS SUP_NAME,
            mgr.NAME AS MANAGER_NAME
        FROM TEAMS t
        LEFT JOIN USERS sup ON t.SUPERVISOR_ID = sup.ID
        LEFT JOIN USERS mgr ON t.MANAGER_ID = mgr.ID
        WHERE t.ID IN ($ids_str)
        ORDER BY t.NAME
    ";
}
$teams_res = mysqli_query($link, $teams_sql);
$teams = [];
while ($row = mysqli_fetch_assoc($teams_res)) {
    $teams[] = $row;
}

// ==================== FETCH SUPERVISORS LIST =============================
$supervisors_res = mysqli_query($link, "
    SELECT ID, NAME
    FROM USERS
    WHERE ROLE IN ('Supervisor','Manager')
      AND STATUS = 'Active'
    ORDER BY NAME
");
$supervisors = [];
while ($row = mysqli_fetch_assoc($supervisors_res)) {
    $supervisors[] = $row;
}

// ==================== FETCH MEMBERS (USERS) WITH FILTERS ==================
// Use prepared statement for search filters

if (isAdmin()) {
    $users_sql = "
        SELECT 
            u.ID,
            u.NAME,
            u.MOBILE,
            u.ROLE,
            u.STATUS,
            u.TEAM_ID,
            t.NAME AS TEAM_NAME
        FROM USERS u
        LEFT JOIN TEAMS t ON u.TEAM_ID = t.ID
        WHERE 1=1
    ";
    $types = '';
    $params = [];

    if ($filter_team_id > 0) {
        $users_sql .= " AND u.TEAM_ID = ?";
        $types      .= 'i';
        $params[]    = $filter_team_id;
    }

    if ($search_name !== '') {
        $users_sql .= " AND u.NAME LIKE ?";
        $types      .= 's';
        $params[]    = '%' . $search_name . '%';
    }

    if ($filter_role !== '') {
        $users_sql .= " AND u.ROLE = ?";
        $types      .= 's';
        $params[]    = $filter_role;
    }

    if ($filter_status !== '') {
        $users_sql .= " AND u.STATUS = ?";
        $types      .= 's';
        $params[]    = $filter_status;
    }

    $users_sql .= " ORDER BY t.NAME, u.NAME";

    $stmt = $link->prepare($users_sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $users_res = $stmt->get_result();

} else { // Manager
    $ids_str = implode(',', array_map('intval', $managerTeamIds));
    $users_sql = "
        SELECT 
            u.ID,
            u.NAME,
            u.MOBILE,
            u.ROLE,
            u.STATUS,
            u.TEAM_ID,
            t.NAME AS TEAM_NAME
        FROM USERS u
        LEFT JOIN TEAMS t ON u.TEAM_ID = t.ID
        WHERE (u.TEAM_ID IS NULL OR u.TEAM_ID IN ($ids_str))
    ";

    $types = '';
    $params = [];

    if ($filter_team_id > 0) {
        $users_sql .= " AND u.TEAM_ID = ?";
        $types      .= 'i';
        $params[]    = $filter_team_id;
    }

    if ($search_name !== '') {
        $users_sql .= " AND u.NAME LIKE ?";
        $types      .= 's';
        $params[]    = '%' . $search_name . '%';
    }

    if ($filter_role !== '') {
        $users_sql .= " AND u.ROLE = ?";
        $types      .= 's';
        $params[]    = $filter_role;
    }

    if ($filter_status !== '') {
        $users_sql .= " AND u.STATUS = ?";
        $types      .= 's';
        $params[]    = $filter_status;
    }

    $users_sql .= " ORDER BY t.NAME, u.NAME";

    $stmt = $link->prepare($users_sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $users_res = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Team Members • CallNow</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563EB;
            --primary-soft: #EFF6FF;
            --primary-border: #BFDBFE;
            --accent: #0F766E;
            --danger: #DC2626;
            --bg-page: #F3F4F6;
            --card-bg: #FFFFFF;
            --text-main: #111827;
            --text-muted: #6B7280;
        }

        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg-page);
            color: var(--text-main);
            min-height: 100vh;
        }

        .page-header-bar {
            background: #FFFFFF;
            border-bottom: 1px solid #E5E7EB;
            padding: 0.7rem 0;
            box-shadow: 0 4px 10px rgba(15,23,42,0.04);
        }
        .page-header-title {
            font-size: 1rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 0;
        }
        .page-header-subtitle {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0;
        }

        .card-main,
        .card-main-light {
            background: var(--card-bg);
            border-radius: 0.75rem;
            border: 1px solid #E5E7EB;
            box-shadow: 0 10px 25px rgba(15,23,42,0.06);
        }

        .card-main h6,
        .card-main-light h6 {
            letter-spacing: 0.02em;
            font-size: 0.9rem;
        }

        .form-label {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .form-control,
        .form-select {
            font-size: 0.85rem;
            border-radius: 0.4rem;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            font-size: 0.85rem;
        }
        .btn-primary:hover {
            background: #1D4ED8;
            border-color: #1D4ED8;
        }

        .btn-outline-secondary {
            font-size: 0.8rem;
        }

        .btn-icon {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .table-sm td,
        .table-sm th {
            padding: 0.4rem 0.5rem;
            font-size: 0.78rem;
        }

        .table thead th {
            background: #F1F5F9;
            color: #374151;
            border-bottom: 1px solid #E5E7EB;
            text-transform: uppercase;
            font-size: 0.72rem;
        }

        .table-striped > tbody > tr:nth-of-type(odd) {
            --bs-table-accent-bg: #F9FAFB;
        }

        .badge-role {
            background: #E0F2FE;
            color: #0369A1;
            font-size: 0.72rem;
            border-radius: 999px;
        }

        .badge-status-active {
            background: #DCFCE7;
            color: #166534;
            font-size: 0.7rem;
            border-radius: 999px;
        }
        .badge-status-inactive {
            background: #FEF9C3;
            color: #92400E;
            font-size: 0.7rem;
            border-radius: 999px;
        }
        .badge-status-suspended {
            background: #FEE2E2;
            color: #B91C1C;
            font-size: 0.7rem;
            border-radius: 999px;
        }

        .badge-count {
            background: var(--primary-soft);
            color: #1D4ED8;
            font-size: 0.78rem;
            border-radius: 999px;
        }

        .text-muted-soft {
            color: var(--text-muted);
        }

        .chip-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            padding: 0.15rem 0.5rem;
            border-radius: 999px;
            font-size: 0.7rem;
            background: #F3F4F6;
            border: 1px solid #E5E7EB;
            color: #4B5563;
        }
        .filter-toolbar {
            flex-wrap: nowrap;
        }
        
        .filter-toolbar .filter-search {
            max-width: 210px;
        }
        
        .filter-toolbar .filter-team {
            max-width: 180px;
        }
        
        /* On small screens, allow wrapping nicely */
        @media (max-width: 768px) {
            .filter-toolbar {
                flex-wrap: wrap;
                align-items: stretch;
            }
            .filter-toolbar .filter-search,
            .filter-toolbar .filter-team {
                flex: 1 1 100%;
            }
        }
                
        
    </style>
</head>
<body>

<?php include 'php_scripts/header.php'; ?>

<!-- Header bar -->
<div class="page-header-bar">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <p class="page-header-title mb-0">
                <i class="bi bi-people-fill me-1 text-primary"></i> Team Members Management
            </p>
            <p class="page-header-subtitle mb-0">
                Assign members to teams, transfer between teams, and manage team supervisors.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="teams_dashboard.php" class="btn btn-outline-secondary btn-sm btn-icon">
                <i class="bi bi-diagram-3"></i>
                <span>Teams</span>
            </a>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm btn-icon">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
        </div>
    </div>
</div>

<div class="container py-3">
    <?php if ($msg): ?>
        <div class="alert alert-<?= htmlspecialchars($msg_type) ?> alert-dismissible fade show small py-2 px-3 mt-2 mb-3">
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- LEFT: Team Supervisors -->
        <div class="col-lg-4">
            <div class="card-main p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0 fw-semibold text-primary">
                        <i class="bi bi-person-badge me-1"></i> Team Supervisors
                    </h6>
                    <span class="chip-pill">
                        <i class="bi bi-collection"></i>
                        <?= count($teams) ?> Teams
                    </span>
                </div>
                <hr class="my-2">

                <?php if (empty($teams)): ?>
                    <div class="text-muted small">
                        No teams found. Please create teams first.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Team</th>
                                    <th>Supervisor</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teams as $t): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($t['NAME']) ?></strong><br>
                                            <small class="text-muted-soft">
                                                <?php if (!empty($t['MANAGER_NAME'])): ?>
                                                    <i class="bi bi-person-workspace"></i>
                                                    Manager: <?= htmlspecialchars($t['MANAGER_NAME']) ?>
                                                <?php else: ?>
                                                    <span class="text-muted small">No Manager</span>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-flex gap-1 align-items-center">
                                                <input type="hidden" name="action" value="change_supervisor">
                                                <input type="hidden" name="team_id" value="<?= (int)$t['ID'] ?>">

                                                <select name="supervisor_id" class="form-select form-select-sm">
                                                    <option value="">No Supervisor</option>
                                                    <?php foreach ($supervisors as $s): ?>
                                                        <option value="<?= (int)$s['ID'] ?>"
                                                            <?= ($t['SUPERVISOR_ID'] == $s['ID']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($s['NAME']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-primary btn-sm">
                                                    <i class="bi bi-save"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT: Members & Assignment -->
        <div class="col-lg-8">
            <div class="card-main-light p-3">
                <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
                    <div>
                        <h6 class="mb-0 fw-semibold">
                            <i class="bi bi-people me-1 text-primary"></i> Members & Team Assignment
                        </h6>
                        <small class="text-muted-soft">
                            Assign or transfer members between teams. Unassigned members will show with “No Team”.
                        </small>
                    </div>
                    <!-- FILTER FORM -->
                    <!-- FILTER FORM - COMPACT -->
                    <form method="GET" class="filter-toolbar d-flex align-items-center gap-2">
                        <!-- Small search box always visible -->
                        <div class="input-group input-group-sm filter-search">
                            <span class="input-group-text">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text"
                                   name="search_name"
                                   class="form-control"
                                   placeholder="Search name..."
                                   value="<?= htmlspecialchars($search_name) ?>">
                        </div>
                    
                        <!-- Quick team filter (optional, still inline) -->
                        <select name="team_id" class="form-select form-select-sm filter-team">
                            <option value="0">All Teams</option>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?= (int)$t['ID'] ?>"
                                    <?= ($filter_team_id == $t['ID']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['NAME']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    
                        <!-- Dropdown for advanced filters: Role + Status -->
                        <div class="dropdown ms-auto">
                            <button type="button"
                                    class="btn btn-outline-secondary btn-sm btn-icon"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false">
                                <i class="bi bi-funnel"></i>
                                <span>More</span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end p-2 small" style="min-width:220px;">
                                <div class="mb-2">
                                    <label class="form-label mb-1">Role</label>
                                    <select name="role" class="form-select form-select-sm">
                                        <option value="">All Roles</option>
                                        <?php foreach ($allowedRoles as $r): ?>
                                            <option value="<?= htmlspecialchars($r) ?>"
                                                <?= ($filter_role === $r ? 'selected' : '') ?>>
                                                <?= htmlspecialchars($r) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                    
                                <div class="mb-2">
                                    <label class="form-label mb-1">Status</label>
                                    <select name="status" class="form-select form-select-sm">
                                        <option value="">All Status</option>
                                        <?php foreach ($allowedStatuses as $st): ?>
                                            <option value="<?= htmlspecialchars($st) ?>"
                                                <?= ($filter_status === $st ? 'selected' : '') ?>>
                                                <?= htmlspecialchars($st) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                    
                                <div class="d-flex gap-1 mt-2">
                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                        Apply
                                    </button>
                                    <a href="team_members.php" class="btn btn-outline-secondary btn-sm w-100">
                                        Reset
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>

                </div>
                <hr class="my-2">

                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Mobile</th>
                                <th>Team</th>
                                <th class="text-end">Assign / Transfer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($users_res->num_rows === 0): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted small">
                                        <i class="bi bi-info-circle"></i>
                                        No members found for the selected filters.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php while ($u = $users_res->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($u['NAME']) ?></strong><br>
                                            <small class="text-muted-soft">
                                                ID: <?= (int)$u['ID'] ?> • Login: <?= htmlspecialchars($u['MOBILE'] ?? '-') ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge badge-role text-primary">
                                                <?= htmlspecialchars($u['ROLE']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $status = $u['STATUS'];
                                            $badgeClass = 'badge-status-inactive';
                                            if ($status === 'Active') $badgeClass = 'badge-status-active';
                                            elseif ($status === 'Suspended') $badgeClass = 'badge-status-suspended';
                                            ?>
                                            <span class="text-secondary badge <?= $badgeClass ?>">
                                                <?= htmlspecialchars($status) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($u['MOBILE'])): ?>
                                                <a href="tel:<?= htmlspecialchars($u['MOBILE']) ?>" class="text-decoration-none">
                                                    <i class="bi bi-telephone-outbound"></i>
                                                    <?= htmlspecialchars($u['MOBILE']) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($u['TEAM_ID']): ?>
                                                <span class="chip-pill">
                                                    <i class="bi bi-people"></i>
                                                    <?= htmlspecialchars($u['TEAM_NAME']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">No Team</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline-flex gap-1 align-items-center">
                                                <input type="hidden" name="action" value="assign_member">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['ID'] ?>">

                                                <select name="team_id" class="form-select form-select-sm">
                                                    <option value="">No Team</option>
                                                    <?php foreach ($teams as $t): ?>
                                                        <option value="<?= (int)$t['ID'] ?>"
                                                            <?= ($u['TEAM_ID'] == $t['ID']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($t['NAME']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-primary btn-sm btn-icon">
                                                    <i class="bi bi-check2-circle"></i>
                                                    <span>Save</span>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>

<?php include 'php_scripts/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php mysqli_close($link); ?>
