<?php
// === ONLY ONE AUTH FILE ===
require_once 'php_scripts/auth.php';
require_once 'php_scripts/team_auth.php';

$msg = $msg_type = "";

// ==================== REASSIGN MEMBERS (NEW) ====================
if (($_POST['action'] ?? '') === 'reassign_members') {
    $from_team_id = isset($_POST['from_team_id']) ? (int)$_POST['from_team_id'] : 0;
    $to_team_id   = isset($_POST['to_team_id']) ? (int)$_POST['to_team_id'] : 0;

    if ($from_team_id <= 0 || $to_team_id <= 0 || $from_team_id === $to_team_id) {
        $msg = "Please select a valid target team for reassignment.";
        $msg_type = "warning";
    } else {
        // Check both teams exist
        $check = $link->prepare("SELECT COUNT(*) FROM TEAMS WHERE ID IN (?, ?)");
        $check->bind_param("ii", $from_team_id, $to_team_id);
        $check->execute();
        $count = $check->get_result()->fetch_row()[0] ?? 0;

        if ($count < 2) {
            $msg = "Invalid team selection.";
            $msg_type = "danger";
        } else {
            // Move all users from from_team_id to to_team_id
            $stmt = $link->prepare("UPDATE USERS SET TEAM_ID = ? WHERE TEAM_ID = ?");
            $stmt->bind_param("ii", $to_team_id, $from_team_id);
            $stmt->execute();
            $affected = $stmt->affected_rows;

            if ($affected > 0) {
                $msg = "$affected member(s) reassigned successfully to the selected team.";
                $msg_type = "success";
            } else {
                $msg = "No members were reassigned (no users in this team).";
                $msg_type = "info";
            }
        }
    }
}

// ==================== ADD TEAM ====================
if (($_POST['action'] ?? '') === 'add') {
    $team_name     = trim($_POST['team_name']);
    $supervisor_id = $_POST['supervisor_id'] ?: null;
    $manager_id    = $_POST['manager_id'] ?: null;

    if (empty($team_name)) {
        $msg = "Team name is required!"; 
        $msg_type = "danger";
    } else {
        $check = $link->prepare("SELECT ID FROM TEAMS WHERE NAME = ?");
        $check->bind_param("s", $team_name);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $msg = "Team name already exists!"; 
            $msg_type = "warning";
        } else {
            $stmt = $link->prepare("INSERT INTO TEAMS (NAME, SUPERVISOR_ID, MANAGER_ID) VALUES (?, ?, ?)");
            $stmt->bind_param("sii", $team_name, $supervisor_id, $manager_id);
            if ($stmt->execute()) {
                $msg = "Team created successfully!"; 
                $msg_type = "success";
            } else {
                $msg = "Database error!"; 
                $msg_type = "danger";
            }
        }
    }
}

// ==================== EDIT TEAM ====================
if (($_POST['action'] ?? '') === 'edit') {
    $id            = (int)$_POST['team_id'];
    $team_name     = trim($_POST['team_name']);
    $supervisor_id = $_POST['supervisor_id'] ?: null;
    $manager_id    = $_POST['manager_id'] ?: null;

    $stmt = $link->prepare("UPDATE TEAMS SET NAME = ?, SUPERVISOR_ID = ?, MANAGER_ID = ? WHERE ID = ?");
    $stmt->bind_param("siii", $team_name, $supervisor_id, $manager_id, $id);
    if ($stmt->execute()) {
        $msg = "Team updated!"; 
        $msg_type = "success";
    } else {
        $msg = "Update failed!"; 
        $msg_type = "danger";
    }
}

// ==================== DELETE TEAM ====================
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    // Remove team assignment from users first
    $link->query("UPDATE USERS SET TEAM_ID = NULL WHERE TEAM_ID = $id");

    // Delete team
    if ($link->query("DELETE FROM TEAMS WHERE ID = $id")) {
        $msg = "Team deleted!"; 
        $msg_type = "success";
    } else {
        $msg = "Cannot delete team (in use?)"; 
        $msg_type = "danger";
    }
    // Refresh page without ?delete param
    header("Location: teams_dashboard.php");
    exit;
}

// Fetch all teams with supervisor name, manager name & member count
$teams_query = "
    SELECT 
        t.ID, 
        t.NAME as TEAM_NAME, 
        t.SUPERVISOR_ID, 
        t.MANAGER_ID,
        sup.NAME as SUP_NAME,
        mgr.NAME as MANAGER_NAME,
        (SELECT COUNT(*) FROM USERS WHERE TEAM_ID = t.ID) as MEMBER_COUNT
    FROM TEAMS t
    LEFT JOIN USERS sup ON t.SUPERVISOR_ID = sup.ID
    LEFT JOIN USERS mgr ON t.MANAGER_ID = mgr.ID
    ORDER BY t.NAME
";
$teams_result = mysqli_query($link, $teams_query);
$total_teams  = mysqli_num_rows($teams_result);

// Get supervisors for dropdown
$supervisors = mysqli_query($link, "
    SELECT ID, NAME 
    FROM USERS 
    WHERE ROLE IN ('Supervisor','Manager') 
    ORDER BY NAME
");

// Get managers for dropdown
$managers_res = mysqli_query($link, "
    SELECT ID, NAME 
    FROM USERS 
    WHERE ROLE = 'Manager' 
    ORDER BY NAME
");

// Get ALL teams list (for reassign dropdown)
$all_teams_res = mysqli_query($link, "SELECT ID, NAME FROM TEAMS ORDER BY NAME");
$all_teams = [];
while ($r = mysqli_fetch_assoc($all_teams_res)) {
    $all_teams[] = $r;
}

// Get members grouped by team for modal
$team_members = [];
$members_res = mysqli_query($link, "
    SELECT 
        u.ID,
        u.NAME,
        u.MOBILE,
        u.ROLE,
        u.TEAM_ID
    FROM USERS u
    WHERE u.TEAM_ID IS NOT NULL
    ORDER BY u.TEAM_ID, u.NAME
");
while ($m = mysqli_fetch_assoc($members_res)) {
    $team_members[$m['TEAM_ID']][] = $m;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Teams • CallNow</title>
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

        /* Compact header bar */
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

        .badge-count {
            background: var(--primary-soft);
            color: #1D4ED8;
            font-size: 0.78rem;
            border-radius: 999px;
        }

        .badge-role {
            background: #E0F2FE;
            color: #0369A1;
            font-size: 0.72rem;
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

        /* Modal styling */
        .modal-content {
            background: #FFFFFF;
            border-radius: 0.75rem;
            border: 1px solid #E5E7EB;
        }
        .modal-header {
            border-bottom-color: #E5E7EB;
            background: #F9FAFB;
        }
        .modal-title {
            font-size: 0.95rem;
            font-weight: 600;
        }

        .table-members thead th {
            background: #F9FAFB;
            border-bottom: 1px solid #E5E7EB;
            font-size: 0.72rem;
        }
        .table-members tbody td {
            border-color: #E5E7EB;
            font-size: 0.78rem;
        }
    </style>
</head>
<body>

<?php include 'php_scripts/header.php'; ?>

<!-- Compact header bar -->
<div class="page-header-bar">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <p class="page-header-title mb-0">
                <i class="bi bi-people-fill me-1 text-primary"></i> Manage Teams
            </p>
            <p class="page-header-subtitle mb-0">
                Create teams, assign supervisors & managers, and manage team members.
            </p>
        </div>
        <div class="d-flex gap-2">
                <a href="team_members.php" class="btn btn-outline-secondary btn-sm btn-icon">
                    <i class="bi bi-people"></i>
                    <span>Members</span>
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
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show small py-2 px-3 mt-2 mb-3">
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- Add / Edit Form -->
        <div class="col-lg-4">
            <div class="card-main p-3">
                <?php
                $edit_mode = false;
                $edit_team = null;
                if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
                    $edit_id = (int)$_GET['edit'];
                    $res = mysqli_query($link, "SELECT * FROM TEAMS WHERE ID = $edit_id");
                    $edit_team = mysqli_fetch_assoc($res);
                    $edit_mode = true;
                }
                ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0 fw-semibold text-primary">
                        <i class="bi <?= $edit_mode ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-1"></i>
                        <?= $edit_mode ? 'Edit Team' : 'Add New Team' ?>
                    </h6>
                    <?php if ($edit_mode): ?>
                        <span class="chip-pill">
                            <i class="bi bi-hash"></i> <?= $edit_team['ID'] ?>
                        </span>
                    <?php endif; ?>
                </div>
                <hr class="my-2">

                <form method="POST" class="mt-2">
                    <input type="hidden" name="action" value="<?= $edit_mode ? 'edit' : 'add' ?>">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="team_id" value="<?= $edit_team['ID'] ?>">
                    <?php endif; ?>

                    <div class="mb-2">
                        <label class="form-label fw-semibold">Team Name <span class="text-danger">*</span></label>
                        <input type="text" name="team_name" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($edit_team['NAME'] ?? '') ?>" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold">Team Supervisor</label>
                        <select name="supervisor_id" class="form-select form-select-sm">
                            <option value="">No Supervisor</option>
                            <?php
                            mysqli_data_seek($supervisors, 0);
                            while ($s = mysqli_fetch_assoc($supervisors)) {
                                $selected = ($edit_team['SUPERVISOR_ID'] ?? '') == $s['ID'] ? 'selected' : '';
                                echo "<option value='{$s['ID']}' $selected>".htmlspecialchars($s['NAME'])."</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Team Manager</label>
                        <select name="manager_id" class="form-select form-select-sm">
                            <option value="">No Manager</option>
                            <?php
                            mysqli_data_seek($managers_res, 0);
                            while ($m = mysqli_fetch_assoc($managers_res)) {
                                $selected = ($edit_team['MANAGER_ID'] ?? '') == $m['ID'] ? 'selected' : '';
                                echo "<option value='{$m['ID']}' $selected>".htmlspecialchars($m['NAME'])."</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm w-100 btn-icon">
                        <i class="bi <?= $edit_mode ? 'bi-save' : 'bi-check2-circle' ?>"></i>
                        <span><?= $edit_mode ? 'Update Team' : 'Create Team' ?></span>
                    </button>
                    <?php if ($edit_mode): ?>
                        <a href="teams_dashboard.php" class="btn btn-outline-secondary btn-sm w-100 mt-2 btn-icon">
                            <i class="bi bi-x-circle"></i>
                            <span>Cancel</span>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Teams List -->
        <div class="col-lg-8">
            <div class="card-main-light p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <h6 class="mb-0 fw-semibold">
                            <i class="bi bi-people me-1 text-primary"></i> All Teams
                        </h6>
                        <small class="text-muted-soft">Click on any row to view full team details & members.</small>
                    </div>
                    <span class="badge badge-count text-primary">
                        <i class="bi bi-collection me-1"></i><?= $total_teams ?> Teams
                    </span>
                </div>
                <hr class="my-2">

                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th>Supervisor</th>
                                <th>Manager</th>
                                <th class="text-center">Members</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($total_teams > 0): ?>
                                <?php while ($t = mysqli_fetch_assoc($teams_result)): 
                                    $members = $team_members[$t['ID']] ?? [];
                                    $members_json = htmlspecialchars(json_encode($members), ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr class="team-row"
                                        data-team-id="<?= $t['ID'] ?>"
                                        data-team-name="<?= htmlspecialchars($t['TEAM_NAME']) ?>"
                                        data-supervisor="<?= htmlspecialchars($t['SUP_NAME'] ?: 'None') ?>"
                                        data-manager="<?= htmlspecialchars($t['MANAGER_NAME'] ?: 'None') ?>"
                                        data-member-count="<?= (int)$t['MEMBER_COUNT'] ?>"
                                        data-members="<?= $members_json ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($t['TEAM_NAME']) ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($t['SUP_NAME']): ?>
                                                <span class="badge badge-role text-danger">
                                                    <i class="bi bi-person-badge"></i>
                                                    <?= htmlspecialchars($t['SUP_NAME']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($t['MANAGER_NAME']): ?>
                                                <span class="badge badge-role" style="background:#DCFCE7;color:#166534;">
                                                    <i class="bi bi-person-workspace"></i>
                                                    <?= htmlspecialchars($t['MANAGER_NAME']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary-subtle text-primary-emphasis">
                                                <?= (int)$t['MEMBER_COUNT'] ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <a href="?edit=<?= $t['ID'] ?>" 
                                               class="btn btn-warning btn-sm btn-icon me-1 team-action-btn" 
                                               title="Edit team">
                                                <i class="bi bi-pencil-square"></i>
                                                <span>Edit</span>
                                            </a>
                                            <a href="?delete=<?= $t['ID'] ?>"
                                               onclick="return confirm('Delete team «<?= htmlspecialchars($t['TEAM_NAME']) ?>»? All members will be unassigned.')"
                                               class="btn btn-danger btn-sm btn-icon team-action-btn"
                                               title="Delete team">
                                                <i class="bi bi-trash"></i>
                                                <span>Delete</span>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted small">
                                        <i class="bi bi-info-circle"></i> No teams found yet. Create your first team on the left.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Team Details Modal -->
<div class="modal fade" id="teamDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <div>
            <h5 class="modal-title mb-0">
                <i class="bi bi-people-fill text-primary me-1"></i>
                <span id="mdlTeamName">Team</span>
            </h5>
            <small id="mdlSubInfo" class="text-muted"></small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-3">
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <div class="small text-muted">Supervisor</div>
                <div id="mdlSupervisor" class="fw-semibold"></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Manager</div>
                <div id="mdlManager" class="fw-semibold"></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Members</div>
                <div id="mdlMemberCount" class="fw-semibold"></div>
            </div>
        </div>

        <hr class="my-2">

        <h6 class="mb-2">
            <i class="bi bi-people me-1 text-primary"></i>
            Team Members
        </h6>
        <div id="mdlNoMembers" class="text-muted small d-none">
            No members are currently assigned to this team.
        </div>

        <div class="table-responsive" id="mdlMembersTableWrapper">
            <table class="table table-sm table-members mb-0">
                <thead>
                    <tr>
                        <th style="width:10%;">ID</th>
                        <th style="width:35%;">Name</th>
                        <th style="width:20%;">Role</th>
                        <th style="width:35%;">Mobile</th>
                    </tr>
                </thead>
                <tbody id="mdlMembersBody">
                    <!-- Filled by JS -->
                </tbody>
            </table>
        </div>

        <!-- Quick Reassign Members -->
        <hr class="my-3">
        <h6 class="mb-2">
            <i class="bi bi-arrow-left-right text-primary me-1"></i>
            Quick Reassign Members
        </h6>
        <p class="small text-muted mb-2">
            Move all members of this team to another team. Their manager will follow the target team's manager.
        </p>

        <form method="POST" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="reassign_members">
            <input type="hidden" id="reassign_from_team_id" name="from_team_id">

            <div class="col-md-6">
                <label class="form-label small mb-1">Target Team</label>
                <select name="to_team_id" id="reassign_to_team" class="form-select form-select-sm" required>
                    <option value="">Select target team</option>
                    <?php foreach ($all_teams as $t): ?>
                        <option value="<?= $t['ID'] ?>"><?= htmlspecialchars($t['NAME']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100 btn-icon">
                    <i class="bi bi-arrow-repeat"></i>
                    <span>Reassign</span>
                </button>
            </div>
        </form>

      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
            <i class="bi bi-x-circle"></i> Close
        </button>
      </div>
    </div>
  </div>
</div>

<?php include 'php_scripts/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('teamDetailsModal');
    const teamModal = new bootstrap.Modal(modalEl);

    const mdlTeamName     = document.getElementById('mdlTeamName');
    const mdlSubInfo      = document.getElementById('mdlSubInfo');
    const mdlSupervisor   = document.getElementById('mdlSupervisor');
    const mdlManager      = document.getElementById('mdlManager');
    const mdlMemberCount  = document.getElementById('mdlMemberCount');
    const mdlMembersBody  = document.getElementById('mdlMembersBody');
    const mdlNoMembers    = document.getElementById('mdlNoMembers');
    const mdlTableWrapper = document.getElementById('mdlMembersTableWrapper');

    const reassignFromInput = document.getElementById('reassign_from_team_id');
    const reassignToSelect  = document.getElementById('reassign_to_team');

    // Prevent row click when action buttons are clicked
    document.querySelectorAll('.team-action-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    });

    document.querySelectorAll('.team-row').forEach(row => {
        row.addEventListener('click', function () {
            const teamId     = this.dataset.teamId || '';
            const teamName   = this.dataset.teamName || 'Team';
            const supervisor = this.dataset.supervisor || 'None';
            const manager    = this.dataset.manager || 'None';
            const count      = this.dataset.memberCount || '0';
            const membersRaw = this.dataset.members || '[]';

            let members = [];
            try {
                members = JSON.parse(membersRaw);
            } catch (e) {
                members = [];
            }

            mdlTeamName.textContent = teamName;
            mdlSubInfo.textContent = "Team ID: " + (teamId || '-') + " • Total Members: " + count;
            mdlSupervisor.textContent = supervisor;
            mdlManager.textContent = manager;
            mdlMemberCount.textContent = count;

            // Populate members table
            mdlMembersBody.innerHTML = '';
            if (!members.length) {
                mdlNoMembers.classList.remove('d-none');
                mdlTableWrapper.classList.add('d-none');
            } else {
                mdlNoMembers.classList.add('d-none');
                mdlTableWrapper.classList.remove('d-none');

                members.forEach(m => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>#${m.ID}</td>
                        <td>${escapeHtml(m.NAME || '')}</td>
                        <td><span class="badge badge-role">${escapeHtml(m.ROLE || '')}</span></td>
                        <td>
                            ${m.MOBILE 
                                ? '<a href="tel:' + encodeURIComponent(m.MOBILE) + '" class="text-primary text-decoration-none"><i class="bi bi-telephone-outbound"></i> ' + escapeHtml(m.MOBILE) + '</a>'
                                : '<span class="text-muted small">N/A</span>'}
                        </td>
                    `;
                    mdlMembersBody.appendChild(tr);
                });
            }

            // Setup reassign form
            reassignFromInput.value = teamId;

            // Reset target select and disable same team option
            Array.from(reassignToSelect.options).forEach(opt => {
                opt.disabled = false;
                if (teamId && opt.value === teamId) {
                    opt.disabled = true;
                }
            });
            reassignToSelect.value = '';

            teamModal.show();
        });
    });

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>"']/g, function (m) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            })[m];
        });
    }
});
</script>
</body>
</html>

<?php mysqli_close($link); ?>
