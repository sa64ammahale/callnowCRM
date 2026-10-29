<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php';

requirePermission('manage_teams');

$msg = $msg_type = "";
$currentUserId = (int)($_SESSION['id'] ?? 0);

$managerTeamIds = [];
if (isManager()) {
    $managerTeamIds = getManagerTeamIds($link, (int)$currentUserId);
    if (empty($managerTeamIds)) $managerTeamIds = [-1];
}

$filter_team_id = isset($_GET['team_id']) && ctype_digit($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if (isManager() && $filter_team_id > 0 && !in_array($filter_team_id, $managerTeamIds, true)) $filter_team_id = 0;
$search_name = trim($_GET['search_name'] ?? '');
$filter_role = $_GET['role'] ?? '';
$filter_status = $_GET['status'] ?? '';

if (($_POST['action'] ?? '') === 'assign_member') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = "Invalid session token"; $msg_type = "danger";
    } else {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $team_id = ($_POST['team_id'] ?? '') !== '' ? (int)$_POST['team_id'] : null;
        if ($user_id <= 0) { $msg = "Invalid member."; $msg_type = "danger"; }
        else {
            $stmt = $link->prepare("SELECT TEAM_ID FROM " . tn('TBL_USERS') . " WHERE ID = ?");
            $stmt->bind_param("i", $user_id); $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();
            if (!$userRow) { $msg = "User not found."; $msg_type = "danger"; }
            else {
                $current_team_id = $userRow['TEAM_ID'] !== null ? (int)$userRow['TEAM_ID'] : null;
                if (isManager()) {
                    if ($current_team_id !== null && !in_array($current_team_id, $managerTeamIds, true)) {
                        $msg = "Not allowed to modify this member."; $msg_type = "danger";
                    }
                    if ($team_id !== null && !in_array($team_id, $managerTeamIds, true)) {
                        $msg = "Not allowed to assign to this team."; $msg_type = "danger";
                    }
                }
                if (empty($msg)) {
                    $upd = $team_id === null
                        ? $link->prepare("UPDATE " . tn('TBL_USERS') . " SET TEAM_ID = NULL WHERE ID = ?")
                        : $link->prepare("UPDATE " . tn('TBL_USERS') . " SET TEAM_ID = ? WHERE ID = ?");
                    if ($team_id === null) $upd->bind_param("i", $user_id);
                    else $upd->bind_param("ii", $team_id, $user_id);
                    if ($upd->execute()) { $msg = "Member assignment updated successfully."; $msg_type = "success"; }
                    else { $msg = "Failed to update team assignment."; $msg_type = "danger"; }
                }
            }
        }
    }
}

if (($_POST['action'] ?? '') === 'change_supervisor') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = "Invalid session token"; $msg_type = "danger";
    } else {
        $team_id = (int)($_POST['team_id'] ?? 0);
        $supervisor_id = isset($_POST['supervisor_id']) && $_POST['supervisor_id'] !== '' ? (int)$_POST['supervisor_id'] : null;
        if ($team_id <= 0) { $msg = "Invalid team."; $msg_type = "danger"; }
        elseif (isManager() && !in_array($team_id, $managerTeamIds, true)) { $msg = "Not allowed."; $msg_type = "danger"; }
        else {
            if ($supervisor_id !== null) {
                $check = $link->prepare("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID = ? AND ROLE IN ('Supervisor','Manager') AND STATUS = 'Active'");
                $check->bind_param("i", $supervisor_id); $check->execute();
                if ($check->get_result()->num_rows === 0) { $msg = "User is not an active Supervisor/Manager."; $msg_type = "danger"; }
                if (empty($msg)) {
                    $checkTeam = $link->prepare("SELECT ID, NAME FROM " . tn('TBL_TEAMS') . " WHERE SUPERVISOR_ID = ? AND ID != ? LIMIT 1");
                    $checkTeam->bind_param("ii", $supervisor_id, $team_id); $checkTeam->execute();
                    $existing = $checkTeam->get_result()->fetch_assoc();
                    if ($existing) { $msg = "Supervisor already assigned to team \"{$existing['NAME']}\"."; $msg_type = "danger"; }
                }
            }
            if (empty($msg)) {
                if ($supervisor_id === null) {
                    $sql = "UPDATE " . tn('TBL_TEAMS') . " SET SUPERVISOR_ID = NULL WHERE ID = ?";
                    if (isManager()) $sql .= " AND ID IN (" . implode(',', array_map('intval', $managerTeamIds)) . ")";
                    $stmt = $link->prepare($sql); $stmt->bind_param("i", $team_id);
                } else {
                    $sql = "UPDATE " . tn('TBL_TEAMS') . " SET SUPERVISOR_ID = ? WHERE ID = ?";
                    if (isManager()) $sql .= " AND ID IN (" . implode(',', array_map('intval', $managerTeamIds)) . ")";
                    $stmt = $link->prepare($sql); $stmt->bind_param("ii", $supervisor_id, $team_id);
                }
                if ($stmt->execute() && $stmt->affected_rows >= 0) {
                    if ($supervisor_id !== null) {
                        $upd = $link->prepare("UPDATE " . tn('TBL_USERS') . " SET TEAM_ID = ? WHERE ID = ?");
                        $upd->bind_param("ii", $team_id, $supervisor_id); $upd->execute();
                    }
                    $msg = "Supervisor updated successfully."; $msg_type = "success";
                } else { $msg = "Failed to update supervisor."; $msg_type = "danger"; }
            }
        }
    }
}

// Fetch teams
if (isAdmin()) {
    $teams_sql = "SELECT t.ID, t.NAME, t.SUPERVISOR_ID, sup.NAME AS SUP_NAME, mgr.NAME AS MANAGER_NAME
        FROM " . tn('TBL_TEAMS') . " t LEFT JOIN " . tn('TBL_USERS') . " sup ON t.SUPERVISOR_ID = sup.ID LEFT JOIN " . tn('TBL_USERS') . " mgr ON t.MANAGER_ID = mgr.ID ORDER BY t.NAME";
} elseif (isManager()) {
    $ids_str = implode(',', array_map('intval', $managerTeamIds));
    $teams_sql = "SELECT t.ID, t.NAME, t.SUPERVISOR_ID, sup.NAME AS SUP_NAME, mgr.NAME AS MANAGER_NAME
        FROM " . tn('TBL_TEAMS') . " t LEFT JOIN " . tn('TBL_USERS') . " sup ON t.SUPERVISOR_ID = sup.ID LEFT JOIN " . tn('TBL_USERS') . " mgr ON t.MANAGER_ID = mgr.ID
        WHERE t.ID IN ($ids_str) ORDER BY t.NAME";
} else { // Supervisor
    $teams_sql = "SELECT t.ID, t.NAME, t.SUPERVISOR_ID, sup.NAME AS SUP_NAME, mgr.NAME AS MANAGER_NAME
        FROM " . tn('TBL_TEAMS') . " t LEFT JOIN " . tn('TBL_USERS') . " sup ON t.SUPERVISOR_ID = sup.ID LEFT JOIN " . tn('TBL_USERS') . " mgr ON t.MANAGER_ID = mgr.ID
        WHERE t.ID = " . (int)USER_TEAM_ID . " ORDER BY t.NAME";
}
$teamsList = mysqli_fetch_all(mysqli_query($link, $teams_sql), MYSQLI_ASSOC);

$supervisors = mysqli_fetch_all(mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_USERS') . " WHERE ROLE IN ('Supervisor','Manager') AND STATUS = 'Active' AND ROLE != 'Super Admin' ORDER BY NAME"), MYSQLI_ASSOC);

// Member count per team
$memberCounts = [];
$mc = mysqli_query($link, "SELECT TEAM_ID, COUNT(*) as cnt FROM " . tn('TBL_USERS') . " WHERE TEAM_ID IS NOT NULL AND ROLE != 'Super Admin' GROUP BY TEAM_ID");
if ($mc) while ($m = mysqli_fetch_assoc($mc)) $memberCounts[(int)$m['TEAM_ID']] = (int)$m['cnt'];

$where = '';
if (isAdmin() || isSuperAdmin()) {
    $where = "1=1";
} elseif (isManager()) {
    $ids_str = implode(',', array_map('intval', $managerTeamIds));
    $where = "(u.TEAM_ID IS NULL OR u.TEAM_ID IN ($ids_str))";
} else { // Supervisor
    $where = "(u.TEAM_ID IS NULL OR u.TEAM_ID = " . (int)USER_TEAM_ID . ")";
}
$types = ''; $params = [];
if ($filter_team_id > 0) { $where .= " AND u.TEAM_ID = ?"; $types .= 'i'; $params[] = $filter_team_id; }
if ($search_name !== '') { $where .= " AND u.NAME LIKE ?"; $types .= 's'; $params[] = "%$search_name%"; }
if ($filter_role !== '') { $where .= " AND u.ROLE = ?"; $types .= 's'; $params[] = $filter_role; }
if ($filter_status !== '') { $where .= " AND u.STATUS = ?"; $types .= 's'; $params[] = $filter_status; }
$users_sql = "SELECT u.ID, u.NAME, u.MOBILE, u.LOGIN_ID, u.ROLE, u.STATUS, u.TEAM_ID, t.NAME AS TEAM_NAME
    FROM " . tn('TBL_USERS') . " u LEFT JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID WHERE $where AND u.ROLE != 'Super Admin' ORDER BY t.NAME, u.NAME";
$stmt = $link->prepare($users_sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$usersRes = $stmt->get_result();
$membersList = $usersRes->fetch_all(MYSQLI_ASSOC);
$totalMembers = count($membersList);

$unassignedCount = 0;
$teamSupervisedCount = 0;
foreach ($teamsList as $t) { if ($t['SUPERVISOR_ID']) $teamSupervisedCount++; }
foreach ($membersList as $u) { if (!$u['TEAM_ID']) $unassignedCount++; }

// Role description lookup
$roleDesc = [];
$rd = mysqli_query($link, "SELECT role_name, description FROM " . tn('TBL_ROLES'));
if ($rd) while ($r = mysqli_fetch_assoc($rd)) $roleDesc[$r['role_name']] = $r['description'];
?>
<?php $pageTitle = 'Team Members - CallNow CRM'; include '../../php_scripts/header.php'; ?>

<div class="container-fluid px-3 py-3">

    <!-- Zoho CRM Module Header -->
    <div class="module-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= url('modules/users/teams_dashboard.php') ?>">Teams Hub</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Team Members</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2">
                <h1 class="page-title mb-0">Team Member Assignments</h1>
                <span class="badge bg-secondary-subtle text-secondary border"><?= $totalMembers ?> Members</span>
            </div>
            <p class="text-muted small mb-0">Assign personnel to squads, transfer between teams, and manage assigned supervisors</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= url('modules/users/teams_dashboard.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-diagram-3 me-1"></i> Teams Hub
            </a>
            <a href="<?= url('modules/users/users_view.php') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-person-lines-fill me-1"></i> User Directory
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

    <!-- KPI Metric Summary -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Active Members</div>
                <div class="metric-value text-primary"><?= $totalMembers ?></div>
                <div class="text-muted small">Assigned & unassigned</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Operational Teams</div>
                <div class="metric-value text-dark"><?= count($teamsList) ?></div>
                <div class="text-muted small">Managed squads</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Unassigned Personnel</div>
                <div class="metric-value <?= $unassignedCount > 0 ? 'text-warning' : 'text-success' ?>"><?= $unassignedCount ?></div>
                <div class="text-muted small">Awaiting squad allocation</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="metric-card">
                <div class="metric-label">Supervised Teams</div>
                <div class="metric-value text-success"><?= $teamSupervisedCount ?> / <?= count($teamsList) ?></div>
                <div class="text-muted small">Teams with active leader</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- LEFT: Team Supervisors Card -->
        <div class="col-lg-4">
            <div class="crm-card h-100">
                <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                    <span class="fw-semibold text-dark">
                        <i class="bi bi-person-badge text-primary me-1"></i> Team Supervisors
                    </span>
                    <span class="badge bg-secondary-subtle text-secondary border"><?= count($teamsList) ?></span>
                </div>
                <div class="card-body p-3">
                    <p class="text-muted small mb-3">Assign a designated supervisor to lead each team's calling workflow.</p>

                    <?php if (empty($teamsList)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-diagram-3 fs-3 d-block mb-1 text-secondary"></i>
                            No teams found. Create teams first in the Teams Hub.
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($teamsList as $t): ?>
                                <div class="p-2 border rounded bg-light">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <h6 class="fw-bold mb-0 text-dark small">
                                            <i class="bi bi-diagram-3 text-primary me-1"></i>
                                            <?= htmlspecialchars($t['NAME']) ?>
                                        </h6>
                                        <span class="badge bg-secondary-subtle text-secondary border" style="font-size: 0.68rem;">
                                            <?= $memberCounts[(int)$t['ID']] ?? 0 ?> members
                                        </span>
                                    </div>
                                    <div class="text-muted small mb-2" style="font-size: 0.75rem;">
                                        Manager: <?= !empty($t['MANAGER_NAME']) ? htmlspecialchars($t['MANAGER_NAME']) : 'None' ?>
                                    </div>
                                    <form method="POST" class="d-flex gap-1 align-items-center">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="change_supervisor">
                                        <input type="hidden" name="team_id" value="<?= (int)$t['ID'] ?>">
                                        <select name="supervisor_id" class="form-select form-select-sm" style="flex: 1; font-size: 0.75rem;">
                                            <option value="">No Supervisor</option>
                                            <?php foreach ($supervisors as $s): ?>
                                                <option value="<?= (int)$s['ID'] ?>" <?= ($t['SUPERVISOR_ID'] == $s['ID']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($s['NAME']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-primary btn-sm px-2" title="Save Supervisor">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT: Member Assignments Table -->
        <div class="col-lg-8">
            <div class="crm-card h-100">
                <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="fw-semibold text-dark">
                        <i class="bi bi-people text-primary me-1"></i> Member Roster &amp; Team Allocations
                    </span>
                    <span class="text-muted small">Instant assignment updates</span>
                </div>
                <div class="p-3 border-bottom bg-light">
                    <!-- Filter Toolbar -->
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label small text-muted mb-1">Search Name</label>
                            <input type="text" name="search_name" class="form-control form-control-sm" placeholder="Member name..." value="<?= htmlspecialchars($search_name) ?>">
                        </div>
                        <div class="col-6 col-sm-3 col-md-3">
                            <label class="form-label small text-muted mb-1">Team</label>
                            <select name="team_id" class="form-select form-select-sm">
                                <option value="0">All Teams</option>
                                <?php foreach ($teamsList as $t): ?>
                                    <option value="<?= (int)$t['ID'] ?>" <?= $filter_team_id == $t['ID'] ? 'selected' : '' ?>><?= htmlspecialchars($t['NAME']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-sm-3 col-md-2">
                            <label class="form-label small text-muted mb-1">Role</label>
                            <select name="role" class="form-select form-select-sm">
                                <option value="">All Roles</option>
                                <option value="Admin" <?= $filter_role==='Admin'?'selected':'' ?>>Admin</option>
                                <option value="Manager" <?= $filter_role==='Manager'?'selected':'' ?>>Manager</option>
                                <option value="Supervisor" <?= $filter_role==='Supervisor'?'selected':'' ?>>Supervisor</option>
                                <option value="Officer" <?= $filter_role==='Officer'?'selected':'' ?>>Officer</option>
                            </select>
                        </div>
                        <div class="col-6 col-sm-3 col-md-2">
                            <label class="form-label small text-muted mb-1">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All Statuses</option>
                                <option value="Active" <?= $filter_status==='Active'?'selected':'' ?>>Active</option>
                                <option value="Inactive" <?= $filter_status==='Inactive'?'selected':'' ?>>Inactive</option>
                                <option value="Suspended" <?= $filter_status==='Suspended'?'selected':'' ?>>Suspended</option>
                            </select>
                        </div>
                        <div class="col-6 col-sm-3 col-md-2">
                            <div class="d-flex gap-1">
                                <button type="submit" class="btn btn-primary btn-sm w-100" title="Apply Filter">
                                    <i class="bi bi-funnel"></i>
                                </button>
                                <?php if ($search_name !== '' || $filter_team_id > 0 || $filter_role !== '' || $filter_status !== ''): ?>
                                    <a href="team_members.php" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                                        <i class="bi bi-x-lg"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table crm-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="min-width: 170px;">Member</th>
                                    <th style="width: 110px;">Role</th>
                                    <th style="width: 120px;">Mobile</th>
                                    <th style="width: 110px;">Current Team</th>
                                    <th style="min-width: 220px;" class="text-end">Assign / Transfer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($membersList)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-5">
                                            <i class="bi bi-people fs-2 d-block mb-2 text-secondary"></i>
                                            No personnel found matching filter criteria.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($membersList as $u):
                                        $initial = strtoupper(substr($u['NAME'], 0, 1));
                                        $stClass = 'bg-secondary-subtle text-secondary';
                                        if ($u['STATUS'] === 'Active') $stClass = 'bg-success-subtle text-success';
                                        elseif ($u['STATUS'] === 'Suspended') $stClass = 'bg-warning-subtle text-warning';
                                        elseif ($u['STATUS'] === 'Inactive') $stClass = 'bg-danger-subtle text-danger';
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-primary bg-primary-subtle flex-shrink-0" style="width: 30px; height: 30px; font-size: 0.78rem;">
                                                        <?= $initial ?>
                                                    </div>
                                                    <div class="text-truncate" style="max-width: 140px;">
                                                        <div class="fw-medium text-dark small text-truncate"><?= htmlspecialchars($u['NAME']) ?></div>
                                                        <div class="text-muted font-monospace" style="font-size: 0.7rem;"><?= htmlspecialchars($u['LOGIN_ID'] ?? '—') ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-info-subtle text-primary border" title="<?= htmlspecialchars($roleDesc[$u['ROLE']] ?? '') ?>"><?= htmlspecialchars($u['ROLE']) ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($u['MOBILE'])): ?>
                                                    <a href="tel:<?= htmlspecialchars($u['MOBILE']) ?>" class="text-primary small font-monospace text-decoration-none"><?= htmlspecialchars($u['MOBILE']) ?></a>
                                                <?php else: ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($u['TEAM_ID']): ?>
                                                    <span class="badge bg-primary-subtle text-primary border"><?= htmlspecialchars($u['TEAM_NAME']) ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted border">Unassigned</span>
                                                <?php endif; ?>
                                                <div class="mt-1">
                                                    <span class="badge <?= $stClass ?> border" style="font-size: 0.65rem;"><i class="bi bi-circle-fill me-1" style="font-size: 0.35rem;"></i><?= htmlspecialchars($u['STATUS']) ?></span>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <form method="POST" class="d-inline-flex gap-1 align-items-center justify-content-end">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="assign_member">
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['ID'] ?>">
                                                    <select name="team_id" class="form-select form-select-sm" style="width: auto; min-width: 140px; font-size: 0.78rem;">
                                                        <option value="">No Team</option>
                                                        <?php foreach ($teamsList as $t): ?>
                                                            <option value="<?= (int)$t['ID'] ?>" <?= ($u['TEAM_ID'] == $t['ID']) ? 'selected' : '' ?>><?= htmlspecialchars($t['NAME']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn btn-outline-primary btn-sm px-2" title="Update Assignment">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../php_scripts/footer.php'; ?>
