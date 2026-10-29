<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php';
requirePermission('manage_teams');

$msg = $msg_type = "";

// ==================== REASSIGN MEMBERS ====================
if (($_POST['action'] ?? '') === 'reassign_members') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = "Invalid session token";
        $msg_type = "danger";
    } else {
        $from_team_id = isset($_POST['from_team_id']) ? (int)$_POST['from_team_id'] : 0;
        $to_team_id   = isset($_POST['to_team_id']) ? (int)$_POST['to_team_id'] : 0;
        if ($from_team_id <= 0 || $to_team_id <= 0 || $from_team_id === $to_team_id) {
            $msg = "Please select a valid target team for reassignment.";
            $msg_type = "warning";
        } else {
            $check = $link->prepare("SELECT COUNT(*) FROM " . tn('TBL_TEAMS') . " WHERE ID IN (?, ?)");
            $check->bind_param("ii", $from_team_id, $to_team_id);
            $check->execute();
            $count = $check->get_result()->fetch_row()[0] ?? 0;
            if ($count < 2) {
                $msg = "Invalid team selection.";
                $msg_type = "danger";
            } else {
                $stmt = $link->prepare("UPDATE " . tn('TBL_USERS') . " SET TEAM_ID = ? WHERE TEAM_ID = ?");
                $stmt->bind_param("ii", $to_team_id, $from_team_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                if ($affected > 0) {
                    $msg = "$affected member(s) reassigned successfully.";
                    $msg_type = "success";
                } else {
                    $msg = "No members were reassigned (no users in this team).";
                    $msg_type = "info";
                }
            }
        }
    }
}

// ==================== ADD TEAM ====================
if (($_POST['action'] ?? '') === 'add') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = "Invalid session token";
        $msg_type = "danger";
    } else {
        $team_name     = trim($_POST['team_name']);
        $supervisor_id = !empty($_POST['supervisor_id']) ? (int)$_POST['supervisor_id'] : null;
        $manager_id    = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;
        if (empty($team_name)) {
            $msg = "Team name is required!";
            $msg_type = "danger";
        } else {
            $check = $link->prepare("SELECT ID FROM " . tn('TBL_TEAMS') . " WHERE NAME = ?");
            $check->bind_param("s", $team_name);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $msg = "Team name already exists!";
                $msg_type = "warning";
            } else {
                $stmt = $link->prepare("INSERT INTO " . tn('TBL_TEAMS') . " (NAME, SUPERVISOR_ID, MANAGER_ID) VALUES (?, ?, ?)");
                $stmt->bind_param("sii", $team_name, $supervisor_id, $manager_id);
                if ($stmt->execute()) {
                    $msg = "Team created successfully!";
                    $msg_type = "success";
                } else {
                    $msg = "Database error creating team.";
                    $msg_type = "danger";
                }
            }
        }
    }
}

// ==================== EDIT TEAM ====================
if (($_POST['action'] ?? '') === 'edit') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = "Invalid session token";
        $msg_type = "danger";
    } else {
        $id            = (int)$_POST['team_id'];
        $team_name     = trim($_POST['team_name']);
        $supervisor_id = !empty($_POST['supervisor_id']) ? (int)$_POST['supervisor_id'] : null;
        $manager_id    = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;
        $stmt = $link->prepare("UPDATE " . tn('TBL_TEAMS') . " SET NAME = ?, SUPERVISOR_ID = ?, MANAGER_ID = ? WHERE ID = ?");
        $stmt->bind_param("siii", $team_name, $supervisor_id, $manager_id, $id);
        if ($stmt->execute()) {
            $msg = "Team updated successfully!";
            $msg_type = "success";
        } else {
            $msg = "Update failed!";
            $msg_type = "danger";
        }
    }
}

// ==================== DELETE TEAM ====================
if (($_POST['action'] ?? '') === 'delete') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = "Invalid session token";
        $msg_type = "danger";
    } else {
        $id = (int)($_POST['team_id'] ?? 0);
        if ($id > 0) {
            $link->query("UPDATE " . tn('TBL_USERS') . " SET TEAM_ID = NULL WHERE TEAM_ID = " . $id);
            if ($link->query("DELETE FROM " . tn('TBL_TEAMS') . " WHERE ID = " . $id)) {
                $msg = "Team deleted successfully!";
                $msg_type = "success";
            } else {
                $msg = "Cannot delete team (currently in use).";
                $msg_type = "danger";
            }
        }
    }
    header("Location: teams_dashboard.php");
    exit;
}

// Fetch all teams with supervisor, manager & member count
$teams_query = "
    SELECT
        t.ID,
        t.NAME as TEAM_NAME,
        t.SUPERVISOR_ID,
        t.MANAGER_ID,
        sup.NAME as SUP_NAME,
        mgr.NAME as MANAGER_NAME,
        (SELECT COUNT(*) FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = t.ID AND ROLE != 'Super Admin') as MEMBER_COUNT
    FROM " . tn('TBL_TEAMS') . " t
    LEFT JOIN " . tn('TBL_USERS') . " sup ON t.SUPERVISOR_ID = sup.ID
    LEFT JOIN " . tn('TBL_USERS') . " mgr ON t.MANAGER_ID = mgr.ID
    ORDER BY t.NAME
";
$teams_result = mysqli_query($link, $teams_query);
$total_teams  = mysqli_num_rows($teams_result);

// Fetch supervisors & managers
$all_supervisors = [];
$sup_res = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_USERS') . " WHERE ROLE IN ('Supervisor','Manager') AND ROLE != 'Super Admin' ORDER BY NAME");
while ($s = mysqli_fetch_assoc($sup_res)) $all_supervisors[] = $s;

$all_managers = [];
$mgr_res = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_USERS') . " WHERE ROLE = 'Manager' AND ROLE != 'Super Admin' ORDER BY NAME");
while ($m = mysqli_fetch_assoc($mgr_res)) $all_managers[] = $m;

// All teams for reassign dropdown
$all_teams = [];
$at_res = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " ORDER BY NAME");
while ($r = mysqli_fetch_assoc($at_res)) $all_teams[] = $r;

// Members grouped by team
$team_members = [];
$total_assigned_members = 0;
$members_res = mysqli_query($link, "SELECT u.ID, u.NAME, u.MOBILE, u.ROLE, u.TEAM_ID FROM " . tn('TBL_USERS') . " u WHERE u.TEAM_ID IS NOT NULL AND u.ROLE != 'Super Admin' ORDER BY u.TEAM_ID, u.NAME");
while ($m = mysqli_fetch_assoc($members_res)) {
    $team_members[$m['TEAM_ID']][] = $m;
    $total_assigned_members++;
}
?>
<?php $pageTitle = 'Teams & Squad Management - CallNow CRM'; include '../../php_scripts/header.php'; ?>

<div class="container-fluid px-3 py-3">

    <!-- Zoho CRM Module Header -->
    <div class="module-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/users/users_view.php') ?>">User Accounts</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Teams Hub</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2">
                <h1 class="page-title mb-0">Teams &amp; Squads</h1>
                <span class="badge bg-secondary-subtle text-secondary border"><?= $total_teams ?> Teams</span>
            </div>
            <p class="text-muted small mb-0">Organize telecallers into functional teams, assign supervisors, and delegate calling campaigns</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= url('modules/users/team_members.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-people me-1"></i> Members Matrix
            </a>
            <a href="<?= url('modules/users/users_view.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-person-lines-fill me-1"></i> User Accounts
            </a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= htmlspecialchars($msg_type ?: 'info'); ?> alert-dismissible fade show mb-3" role="alert">
            <i class="bi <?= $msg_type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> me-2"></i>
            <?= htmlspecialchars($msg); ?>
            <button class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- KPI Metric Strip -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Operational Teams</div>
                <div class="metric-value text-primary"><?= $total_teams ?></div>
                <div class="text-muted small">Configured units</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Team Supervisors</div>
                <div class="metric-value text-success"><?= count($all_supervisors) ?></div>
                <div class="text-muted small">Lead officers</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Division Managers</div>
                <div class="metric-value text-dark"><?= count($all_managers) ?></div>
                <div class="text-muted small">Regional managers</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Assigned Personnel</div>
                <div class="metric-value text-secondary"><?= number_format($total_assigned_members) ?></div>
                <div class="text-muted small">Active members</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Left Column: Add / Edit Team Form -->
        <div class="col-lg-4">
            <?php
            $edit_mode = false;
            $edit_team = null;
            if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
                $edit_id = (int)$_GET['edit'];
                $res = mysqli_query($link, "SELECT * FROM " . tn('TBL_TEAMS') . " WHERE ID = $edit_id");
                $edit_team = mysqli_fetch_assoc($res);
                $edit_mode = true;
            }
            ?>
            <div class="crm-card">
                <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                    <span class="fw-semibold text-dark">
                        <i class="bi <?= $edit_mode ? 'bi-pencil-square text-primary' : 'bi-plus-circle text-primary' ?> me-1"></i>
                        <?= $edit_mode ? 'Edit Team #' . (int)$edit_team['ID'] : 'Create New Team' ?>
                    </span>
                    <?php if ($edit_mode): ?>
                        <a href="teams_dashboard.php" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Cancel Editing">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body p-3">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="<?= $edit_mode ? 'edit' : 'add' ?>">
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="team_id" value="<?= (int)$edit_team['ID'] ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Team Name <span class="text-danger">*</span></label>
                            <input type="text" name="team_name" class="form-control form-control-sm" placeholder="e.g. Sales Team Alpha" value="<?= htmlspecialchars($edit_team['NAME'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Supervisor</label>
                            <select name="supervisor_id" class="form-select form-select-sm">
                                <option value="">— No Supervisor —</option>
                                <?php foreach ($all_supervisors as $s): ?>
                                    <option value="<?= (int)$s['ID'] ?>" <?= ($edit_team['SUPERVISOR_ID'] ?? '') == $s['ID'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['NAME']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="text-muted small mt-1">Can monitor and assign leads to team agents</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted mb-1">Manager</label>
                            <select name="manager_id" class="form-select form-select-sm">
                                <option value="">— No Manager —</option>
                                <?php foreach ($all_managers as $m): ?>
                                    <option value="<?= (int)$m['ID'] ?>" <?= ($edit_team['MANAGER_ID'] ?? '') == $m['ID'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($m['NAME']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="d-flex gap-2 pt-2 border-top">
                            <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                                <i class="bi <?= $edit_mode ? 'bi-check-lg' : 'bi-plus-circle' ?> me-1"></i>
                                <?= $edit_mode ? 'Update Team' : 'Create Team' ?>
                            </button>
                            <?php if ($edit_mode): ?>
                                <a href="teams_dashboard.php" class="btn btn-outline-secondary btn-sm">
                                    Cancel
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right Column: Teams List -->
        <div class="col-lg-8">
            <div class="crm-card">
                <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <span class="fw-semibold text-dark">Teams Directory</span>
                        <span class="badge bg-secondary-subtle text-secondary border"><?= $total_teams ?> Total</span>
                    </div>
                    <span class="text-muted small">Click any team row to inspect members</span>
                </div>
                <div class="card-body p-0">
                    <?php if ($total_teams > 0): ?>
                        <div class="table-responsive">
                            <table class="table crm-table align-middle mb-0" id="teamsTable">
                                <thead>
                                    <tr>
                                        <th style="min-width: 160px;">Team Name</th>
                                        <th style="min-width: 140px;">Supervisor</th>
                                        <th style="min-width: 140px;">Manager</th>
                                        <th style="width: 90px;" class="text-center">Members</th>
                                        <th style="width: 120px;" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($t = mysqli_fetch_assoc($teams_result)):
                                        $members = $team_members[$t['ID']] ?? [];
                                        $members_json = htmlspecialchars(json_encode($members), ENT_QUOTES, 'UTF-8');
                                    ?>
                                        <tr class="team-row" style="cursor: pointer;"
                                            data-team-id="<?= (int)$t['ID'] ?>"
                                            data-team-name="<?= htmlspecialchars($t['TEAM_NAME']) ?>"
                                            data-supervisor="<?= htmlspecialchars($t['SUP_NAME'] ?: 'None') ?>"
                                            data-manager="<?= htmlspecialchars($t['MANAGER_NAME'] ?: 'None') ?>"
                                            data-member-count="<?= (int)$t['MEMBER_COUNT'] ?>"
                                            data-members="<?= $members_json ?>">
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="rounded bg-light text-primary d-flex align-items-center justify-content-center fw-bold" style="width: 28px; height: 28px; font-size: 0.75rem;">
                                                        <i class="bi bi-diagram-3"></i>
                                                    </div>
                                                    <span class="fw-semibold text-dark"><?= htmlspecialchars($t['TEAM_NAME']) ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($t['SUP_NAME']): ?>
                                                    <span class="badge bg-info-subtle text-primary border"><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($t['SUP_NAME']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($t['MANAGER_NAME']): ?>
                                                    <span class="badge bg-secondary-subtle text-dark border"><i class="bi bi-person-workspace me-1"></i><?= htmlspecialchars($t['MANAGER_NAME']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-primary-subtle text-primary border"><?= (int)$t['MEMBER_COUNT'] ?></span>
                                            </td>
                                            <td class="text-end">
                                                <a href="<?= url('modules/users/teams_dashboard.php') ?>?edit=<?= (int)$t['ID'] ?>" class="btn btn-sm btn-outline-secondary px-2 py-1 team-action-btn" title="Edit team">
                                                    <i class="bi bi-pencil-square"></i>
                                                </a>
                                                <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete team «<?= htmlspecialchars($t['TEAM_NAME']) ?>»? All members will be unassigned.');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="team_id" value="<?= (int)$t['ID'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-1 team-action-btn" title="Delete team">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-diagram-3 fs-2 d-block mb-2 text-secondary"></i>
                            No teams configured. Create your first operational team using the form on the left.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Team Details Modal -->
<div class="modal fade" id="teamDetailsModal" tabindex="-1" aria-labelledby="teamDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="teamDetailsModalLabel">
                        <i class="bi bi-diagram-3 text-primary me-2"></i>
                        <span id="mdlTeamName">Team Details</span>
                    </h5>
                    <p class="text-muted small mb-0" id="mdlSubInfo"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <div class="metric-card p-2 text-center">
                            <div class="metric-label" style="font-size: 0.7rem;">Supervisor</div>
                            <div id="mdlSupervisor" class="fw-semibold text-dark small text-truncate"></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="metric-card p-2 text-center">
                            <div class="metric-label" style="font-size: 0.7rem;">Manager</div>
                            <div id="mdlManager" class="fw-semibold text-dark small text-truncate"></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="metric-card p-2 text-center">
                            <div class="metric-label" style="font-size: 0.7rem;">Active Members</div>
                            <div id="mdlMemberCount" class="fw-bold text-primary fs-6"></div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0 text-dark small"><i class="bi bi-people text-primary me-1"></i> Assigned Team Members</h6>
                </div>

                <div id="mdlNoMembers" class="text-center text-muted py-4 d-none border rounded bg-light">
                    <i class="bi bi-person-x fs-3 d-block mb-1 text-secondary"></i>
                    No members are currently assigned to this team.
                </div>

                <div class="table-responsive border rounded" id="mdlMembersTableWrapper">
                    <table class="table crm-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 70px;">ID</th>
                                <th>Name</th>
                                <th style="width: 130px;">Role</th>
                                <th style="width: 140px;">Mobile</th>
                            </tr>
                        </thead>
                        <tbody id="mdlMembersBody"></tbody>
                    </table>
                </div>

                <!-- Quick Reassign Box -->
                <div class="crm-card mt-3 p-3 bg-light">
                    <h6 class="fw-bold text-dark small mb-1"><i class="bi bi-arrow-left-right text-primary me-1"></i> Quick Reassign All Members</h6>
                    <p class="text-muted small mb-2">Transfer all agents in this team to another target squad.</p>
                    <form method="POST" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="reassign_members">
                        <input type="hidden" id="reassign_from_team_id" name="from_team_id">
                        <div class="col-md-8">
                            <label class="form-label small text-muted mb-1">Destination Team</label>
                            <select name="to_team_id" id="reassign_to_team" class="form-select form-select-sm" required>
                                <option value="">— Select target team —</option>
                                <?php foreach ($all_teams as $t): ?>
                                    <option value="<?= (int)$t['ID'] ?>"><?= htmlspecialchars($t['NAME']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-arrow-repeat me-1"></i> Transfer All
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('teamDetailsModal');
    const teamModal = new bootstrap.Modal(modalEl);
    const mdlTeamName = document.getElementById('mdlTeamName');
    const mdlSubInfo = document.getElementById('mdlSubInfo');
    const mdlSupervisor = document.getElementById('mdlSupervisor');
    const mdlManager = document.getElementById('mdlManager');
    const mdlMemberCount = document.getElementById('mdlMemberCount');
    const mdlMembersBody = document.getElementById('mdlMembersBody');
    const mdlNoMembers = document.getElementById('mdlNoMembers');
    const mdlTableWrapper = document.getElementById('mdlMembersTableWrapper');
    const reassignFromInput = document.getElementById('reassign_from_team_id');
    const reassignToSelect = document.getElementById('reassign_to_team');

    document.querySelectorAll('.team-action-btn').forEach(btn => {
        btn.addEventListener('click', function (e) { e.stopPropagation(); });
    });

    document.querySelectorAll('.team-row').forEach(row => {
        row.addEventListener('click', function () {
            const teamId = this.dataset.teamId || '';
            const teamName = this.dataset.teamName || 'Team';
            const supervisor = this.dataset.supervisor || 'None';
            const manager = this.dataset.manager || 'None';
            const count = this.dataset.memberCount || '0';
            let members = [];
            try { members = JSON.parse(this.dataset.members || '[]'); } catch (e) {}

            mdlTeamName.textContent = teamName;
            mdlSubInfo.textContent = "Team ID: #" + teamId + " · Total Members: " + count;
            mdlSupervisor.textContent = supervisor;
            mdlManager.textContent = manager;
            mdlMemberCount.textContent = count;

            mdlMembersBody.innerHTML = '';
            if (!members.length) {
                mdlNoMembers.classList.remove('d-none');
                mdlTableWrapper.classList.add('d-none');
            } else {
                mdlNoMembers.classList.add('d-none');
                mdlTableWrapper.classList.remove('d-none');
                members.forEach(m => {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td class="fw-semibold text-secondary font-monospace">#' + m.ID + '</td>'
                        + '<td class="fw-medium text-dark">' + escapeHtml(m.NAME || '') + '</td>'
                        + '<td><span class="badge bg-secondary-subtle text-secondary border">' + escapeHtml(m.ROLE || '') + '</span></td>'
                        + '<td>' + (m.MOBILE ? '<a href="tel:' + encodeURIComponent(m.MOBILE) + '" class="text-primary small font-monospace text-decoration-none"><i class="bi bi-telephone me-1"></i>' + escapeHtml(m.MOBILE) + '</a>' : '<span class="text-muted small">N/A</span>') + '</td>';
                    mdlMembersBody.appendChild(tr);
                });
            }

            reassignFromInput.value = teamId;
            Array.from(reassignToSelect.options).forEach(opt => {
                opt.disabled = false;
                if (teamId && opt.value === teamId) opt.disabled = true;
            });
            reassignToSelect.value = '';
            teamModal.show();
        });
    });

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>"']/g, function(m) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m];
        });
    }
});
</script>
