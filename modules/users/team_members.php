<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php';

requirePermission('manage_TBL_TEAMS');

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

if ($_POST['action'] === 'assign_member') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = "Invalid session token"; $msg_type = "danger";
    } else {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $team_id = ($_POST['team_id'] ?? '') !== '' ? (int)$_POST['team_id'] : null;
        if ($user_id <= 0) { $msg = "Invalid member."; $msg_type = "danger"; }
        else {
            $stmt = $link->prepare("SELECT TEAM_ID FROM TBL_USERS WHERE ID = ?");
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
                        ? $link->prepare("UPDATE TBL_USERS SET TEAM_ID = NULL WHERE ID = ?")
                        : $link->prepare("UPDATE TBL_USERS SET TEAM_ID = ? WHERE ID = ?");
                    if ($team_id === null) $upd->bind_param("i", $user_id);
                    else $upd->bind_param("ii", $team_id, $user_id);
                    if ($upd->execute()) { $msg = "Member assignment updated."; $msg_type = "success"; }
                    else { $msg = "Failed to update."; $msg_type = "danger"; }
                }
            }
        }
    }
}

if ($_POST['action'] === 'change_supervisor') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = "Invalid session token"; $msg_type = "danger";
    } else {
        $team_id = (int)($_POST['team_id'] ?? 0);
        $supervisor_id = isset($_POST['supervisor_id']) && $_POST['supervisor_id'] !== '' ? (int)$_POST['supervisor_id'] : null;
        if ($team_id <= 0) { $msg = "Invalid team."; $msg_type = "danger"; }
        elseif (isManager() && !in_array($team_id, $managerTeamIds, true)) { $msg = "Not allowed."; $msg_type = "danger"; }
        else {
            if ($supervisor_id !== null) {
                $check = $link->prepare("SELECT ID FROM TBL_USERS WHERE ID = ? AND ROLE IN ('Supervisor','Manager') AND STATUS = 'Active'");
                $check->bind_param("i", $supervisor_id); $check->execute();
                if ($check->get_result()->num_rows === 0) { $msg = "User is not an active Supervisor/Manager."; $msg_type = "danger"; }
                if (empty($msg)) {
                    $checkTeam = $link->prepare("SELECT ID, NAME FROM TBL_TEAMS WHERE SUPERVISOR_ID = ? AND ID != ? LIMIT 1");
                    $checkTeam->bind_param("ii", $supervisor_id, $team_id); $checkTeam->execute();
                    $existing = $checkTeam->get_result()->fetch_assoc();
                    if ($existing) { $msg = "Supervisor already assigned to \"{$existing['NAME']}\"."; $msg_type = "danger"; }
                }
            }
            if (empty($msg)) {
                if ($supervisor_id === null) {
                    $sql = "UPDATE TBL_TEAMS SET SUPERVISOR_ID = NULL WHERE ID = ?";
                    if (isManager()) $sql .= " AND ID IN (" . implode(',', array_map('intval', $managerTeamIds)) . ")";
                    $stmt = $link->prepare($sql); $stmt->bind_param("i", $team_id);
                } else {
                    $sql = "UPDATE TBL_TEAMS SET SUPERVISOR_ID = ? WHERE ID = ?";
                    if (isManager()) $sql .= " AND ID IN (" . implode(',', array_map('intval', $managerTeamIds)) . ")";
                    $stmt = $link->prepare($sql); $stmt->bind_param("ii", $supervisor_id, $team_id);
                }
                if ($stmt->execute() && $stmt->affected_rows >= 0) {
                    if ($supervisor_id !== null) {
                        $upd = $link->prepare("UPDATE TBL_USERS SET TEAM_ID = ? WHERE ID = ?");
                        $upd->bind_param("ii", $team_id, $supervisor_id); $upd->execute();
                    }
                    $msg = "Supervisor updated."; $msg_type = "success";
                } else { $msg = "Failed to update supervisor."; $msg_type = "danger"; }
            }
        }
    }
}

// ─── Fetch data ───
if (isAdmin()) {
    $TBL_TEAMS_sql = "SELECT t.ID, t.NAME, t.SUPERVISOR_ID, sup.NAME AS SUP_NAME, mgr.NAME AS MANAGER_NAME
        FROM TBL_TEAMS t LEFT JOIN TBL_USERS sup ON t.SUPERVISOR_ID = sup.ID LEFT JOIN TBL_USERS mgr ON t.MANAGER_ID = mgr.ID ORDER BY t.NAME";
} elseif (isManager()) {
    $ids_str = implode(',', array_map('intval', $managerTeamIds));
    $TBL_TEAMS_sql = "SELECT t.ID, t.NAME, t.SUPERVISOR_ID, sup.NAME AS SUP_NAME, mgr.NAME AS MANAGER_NAME
        FROM TBL_TEAMS t LEFT JOIN TBL_USERS sup ON t.SUPERVISOR_ID = sup.ID LEFT JOIN TBL_USERS mgr ON t.MANAGER_ID = mgr.ID
        WHERE t.ID IN ($ids_str) ORDER BY t.NAME";
} else { // Supervisor
    $TBL_TEAMS_sql = "SELECT t.ID, t.NAME, t.SUPERVISOR_ID, sup.NAME AS SUP_NAME, mgr.NAME AS MANAGER_NAME
        FROM TBL_TEAMS t LEFT JOIN TBL_USERS sup ON t.SUPERVISOR_ID = sup.ID LEFT JOIN TBL_USERS mgr ON t.MANAGER_ID = mgr.ID
        WHERE t.ID = " . (int)USER_TEAM_ID . " ORDER BY t.NAME";
}
$TBL_TEAMS = mysqli_fetch_all(mysqli_query($link, $TBL_TEAMS_sql), MYSQLI_ASSOC);

$supervisors = mysqli_fetch_all(mysqli_query($link, "SELECT ID, NAME FROM TBL_USERS WHERE ROLE IN ('Supervisor','Manager') AND STATUS = 'Active' ORDER BY NAME"), MYSQLI_ASSOC);

// Member count per team
$memberCounts = [];
$mc = mysqli_query($link, "SELECT TEAM_ID, COUNT(*) as cnt FROM TBL_USERS WHERE TEAM_ID IS NOT NULL GROUP BY TEAM_ID");
if ($mc) while ($m = mysqli_fetch_assoc($mc)) $memberCounts[(int)$m['TEAM_ID']] = (int)$m['cnt'];
$totalMembers = 0;

$where = '';
if (isAdmin()) {
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
$TBL_USERS_sql = "SELECT u.ID, u.NAME, u.MOBILE, u.LOGIN_ID, u.ROLE, u.STATUS, u.TEAM_ID, t.NAME AS TEAM_NAME
    FROM TBL_USERS u LEFT JOIN TBL_TEAMS t ON u.TEAM_ID = t.ID WHERE $where ORDER BY t.NAME, u.NAME";
$stmt = $link->prepare($TBL_USERS_sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$TBL_USERS_res = $stmt->get_result();
$TBL_USERSList = $TBL_USERS_res->fetch_all(MYSQLI_ASSOC);
$totalMembers = count($TBL_USERSList);

$unassignedCount = 0;
$TBL_TEAMSupervisedCount = 0;
foreach ($TBL_TEAMS as $t) { if ($t['SUPERVISOR_ID']) $TBL_TEAMSupervisedCount++; }
foreach ($TBL_USERSList as $u) { if (!$u['TEAM_ID']) $unassignedCount++; }

// Role description lookup
$roleDesc = [];
$rd = mysqli_query($link, "SELECT role_name, description FROM TBL_ROLES");
if ($rd) while ($r = mysqli_fetch_assoc($rd)) $roleDesc[$r['role_name']] = $r['description'];
?>
<?php $pageTitle = 'Team Members - CallNow'; include '../../php_scripts/header.php'; ?>

<style>
:root {
    --tm-accent: var(--accent);
    --tm-accent-dark: var(--accent-hover, #4f46e5);
    --tm-ink: #1e1b4b;
    --tm-ink-soft: #6b6890;
    --tm-soft: #f0f2ff;
    --tm-border: #e2e4f0;
    --tm-success: #10b981;
    --tm-warning: #f59e0b;
    --tm-danger: #ef4444;
}

.tm-header {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
    border-radius: 1rem; padding: 1.5rem 2rem; margin-bottom: 1.5rem;
    position: relative; overflow: hidden;
}
.tm-header::before {
    content: ''; position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.3;
}
.tm-header-content { position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
.tm-header-left h1 { font-size: 1.35rem; font-weight: 700; color: #fff; margin: 0 0 0.2rem 0; letter-spacing: -0.02em; display: flex; align-items: center; gap: 0.5rem; }
.tm-header-left h1 i { font-size: 1.4rem; }
.tm-header-left p { color: rgba(255,255,255,0.7); font-size: 0.8125rem; margin: 0; }
.tm-header-actions { display: flex; gap: 0.5rem; }
.tm-header-actions .tm-btn-glass {
    background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2);
    color: #fff; backdrop-filter: blur(4px); font-size: 0.75rem; padding: 0.4rem 0.9rem;
    border-radius: 0.5rem; transition: all 0.15s ease; text-decoration: none; display: flex; align-items: center; gap: 0.35rem;
}
.tm-header-actions .tm-btn-glass:hover { background: rgba(255,255,255,0.25); border-color: rgba(255,255,255,0.35); color: #fff; transform: translateY(-1px); }

.tm-stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem; }
.tm-stat-card { background: var(--tm-soft); border: 1px solid var(--tm-border); border-radius: 0.75rem; padding: 0.875rem 1.125rem; }
.tm-stat-card .num { font-size: 1.35rem; font-weight: 700; color: var(--tm-ink); line-height: 1.2; }
.tm-stat-card .lbl { font-size: 0.7rem; color: var(--tm-ink-soft); margin: 0; }
.tm-stat-card .num i { font-size: 0.9rem; margin-right: 0.25rem; }

.tm-card { background: #fff; border: 1px solid var(--tm-border); border-radius: 0.875rem; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
.tm-card-body { padding: 1.25rem 1.5rem; }

.tm-alert { border-radius: 0.625rem; font-size: 0.8125rem; padding: 0.65rem 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }

.tm-label { font-size: 0.7rem; font-weight: 600; color: var(--tm-ink); margin-bottom: 0.25rem; }

.tm-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.8125rem; }
.tm-table thead th {
    background: #fafbff; color: var(--tm-ink-soft); font-weight: 600; font-size: 0.6875rem;
    text-transform: uppercase; letter-spacing: 0.04em; padding: 0.625rem 0.75rem;
    border-bottom: 2px solid var(--tm-border); white-space: nowrap;
}
.tm-table tbody td { padding: 0.55rem 0.75rem; border-bottom: 1px solid #f0f1f8; vertical-align: middle; }
.tm-table tbody tr:hover { background: #f8f9ff; }
.tm-table tbody tr:last-child td { border-bottom: none; }

.tm-avatar { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700; color: #fff; flex-shrink: 0; }

.tm-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.55rem; border-radius: 0.375rem; font-size: 0.6875rem; font-weight: 600; }
.tm-badge-role { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; }
.tm-badge-active { background: #d1fae5; color: #065f46; }
.tm-badge-inactive { background: #fef3c7; color: #92400e; }
.tm-badge-suspended { background: #fee2e2; color: #991b1b; }
.tm-badge-team { background: #dbeafe; color: #1e40af; }
.tm-badge-noteam { background: #f5f5f5; color: #a3a3a3; }

.tm-input, .tm-select { border: 1px solid var(--tm-border) !important; border-radius: 0.5rem !important; font-size: 0.75rem !important; color: var(--tm-ink) !important; padding: 0.35rem 0.65rem !important; background: #fff !important; }
.tm-input:focus, .tm-select:focus { border-color: var(--tm-accent) !important; box-shadow: 0 0 0 3px rgba(99,102,241,0.12) !important; outline: none; }
.tm-btn-primary { background: linear-gradient(135deg, var(--tm-accent), var(--tm-accent-dark)) !important; border: none !important; color: #fff !important; border-radius: 0.5rem !important; font-size: 0.75rem !important; font-weight: 600 !important; padding: 0.4rem 1rem !important; transition: all 0.15s ease !important; box-shadow: 0 2px 6px rgba(99,102,241,0.2) !important; }
.tm-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,102,241,0.3) !important; }
.tm-btn-sm { padding: 0.25rem 0.6rem !important; font-size: 0.6875rem !important; border-radius: 0.375rem !important; }
.tm-btn-outline { border: 1px solid var(--tm-border) !important; background: #fff !important; color: var(--tm-ink-soft) !important; border-radius: 0.5rem !important; font-size: 0.75rem !important; padding: 0.35rem 0.85rem !important; transition: all 0.12s ease !important; }
.tm-btn-outline:hover { border-color: var(--tm-accent) !important; color: var(--tm-accent) !important; }
.tm-member-info { display: flex; align-items: center; gap: 0.6rem; }
.tm-member-info .name { font-weight: 600; color: var(--tm-ink); font-size: 0.8125rem; }
.tm-member-info .meta { font-size: 0.65rem; color: var(--tm-ink-soft); }

/* Supervisor card */
.tm-sup-grid { display: flex; flex-direction: column; gap: 0.625rem; }
.tm-sup-item { background: #fafbff; border: 1px solid var(--tm-border); border-radius: 0.625rem; padding: 0.75rem; }
.tm-sup-item h6 { font-size: 0.8125rem; font-weight: 700; color: var(--tm-ink); margin: 0 0 0.15rem 0; }
.tm-sup-item .sup-meta { font-size: 0.65rem; color: var(--tm-ink-soft); margin-bottom: 0.35rem; display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
.tm-sup-item .sup-meta i { font-size: 0.6rem; }

@media (max-width: 991px) {
    .tm-card-body { padding: 1rem; }
    .tm-stat-grid { grid-template-columns: repeat(3, 1fr); }
}
</style>

<div class="container page-wrapper">

    <div class="tm-header">
        <div class="tm-header-content">
            <div class="tm-header-left">
                <h1><i class="bi bi-people-fill"></i> Team Members</h1>
                <p>Assign members to TBL_TEAMS, transfer between TBL_TEAMS, and manage supervisors</p>
            </div>
            <div class="tm-header-actions">
                <a href="<?= url('modules/TBL_USERS/TBL_TEAMS_dashboard.php') ?>" class="tm-btn-glass"><i class="bi bi-diagram-3"></i> TBL_TEAMS</a>
                <a href="<?= url('modules/TBL_USERS/TBL_USERS_view.php') ?>" class="tm-btn-glass"><i class="bi bi-person-gear"></i> TBL_USERS</a>
                <a href="<?= url('dashboard.php') ?>" class="tm-btn-glass"><i class="bi bi-speedometer2"></i> Dashboard</a>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="tm-alert" style="background:<?= $msg_type==='success'?'#d1fae5':'#fee2e2' ?>;border:1px solid <?= $msg_type==='success'?'#6ee7b7':'#fca5a5' ?>;color:<?= $msg_type==='success'?'#065f46':'#991b1b' ?>;">
            <i class="bi <?= $msg_type==='success'?'bi-check-circle-fill':'bi-x-circle-fill' ?>"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="font-size:0.65rem;"></button>
        </div>
    <?php endif; ?>

    <div class="tm-stat-grid">
        <div class="tm-stat-card">
            <div class="num"><?= $totalMembers ?></div>
            <p class="lbl"><i class="bi bi-people"></i> Total Members</p>
        </div>
        <div class="tm-stat-card">
            <div class="num"><?= count($TBL_TEAMS) ?></div>
            <p class="lbl"><i class="bi bi-diagram-3"></i> TBL_TEAMS</p>
        </div>
        <div class="tm-stat-card">
            <div class="num"><?= $unassignedCount ?></div>
            <p class="lbl"><i class="bi bi-person-dash"></i> Unassigned</p>
        </div>
        <div class="tm-stat-card">
            <div class="num"><?= $TBL_TEAMSupervisedCount ?>/<?= count($TBL_TEAMS) ?></div>
            <p class="lbl"><i class="bi bi-person-badge"></i> TBL_TEAMS with Supervisor</p>
        </div>
    </div>

    <div class="row g-3">

        <!-- LEFT: Team Supervisors -->
        <div class="col-lg-4">
            <div class="tm-card h-100">
                <div class="tm-card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 style="font-size:0.85rem;font-weight:700;color:var(--tm-ink);margin:0;">
                            <i class="bi bi-person-badge" style="color:var(--tm-accent);"></i> Team Supervisors
                        </h6>
                    </div>
                    <p style="font-size:0.7rem;color:var(--tm-ink-soft);margin-bottom:0.75rem;">Assign a supervisor to each team. A supervisor can oversee only one team.</p>

                    <?php if (empty($TBL_TEAMS)): ?>
                        <div style="text-align:center;padding:2rem 0;color:var(--tm-ink-soft);font-size:0.8125rem;">
                            <i class="bi bi-diagram-3" style="font-size:1.5rem;display:block;margin-bottom:0.5rem;"></i>
                            No TBL_TEAMS found. Create TBL_TEAMS first.
                        </div>
                    <?php else: ?>
                        <div class="tm-sup-grid">
                            <?php foreach ($TBL_TEAMS as $t): ?>
                                <div class="tm-sup-item">
                                    <h6><i class="bi bi-people" style="color:var(--tm-accent);font-size:0.7rem;"></i> <?= htmlspecialchars($t['NAME']) ?></h6>
                                    <div class="sup-meta">
                                        <?php if (!empty($t['MANAGER_NAME'])): ?>
                                            <span><i class="bi bi-person-workspace"></i> <?= htmlspecialchars($t['MANAGER_NAME']) ?></span>
                                        <?php else: ?>
                                            <span style="color:#a3a3a3;">No Manager</span>
                                        <?php endif; ?>
                                        <span><i class="bi bi-people"></i> <?= $memberCounts[(int)$t['ID']] ?? 0 ?> members</span>
                                    </div>
                                    <form method="POST" class="d-flex gap-1 align-items-center">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="change_supervisor">
                                        <input type="hidden" name="team_id" value="<?= (int)$t['ID'] ?>">
                                        <select name="supervisor_id" class="form-select tm-select" style="flex:1;min-width:0;">
                                            <option value="">No Supervisor</option>
                                            <?php foreach ($supervisors as $s): ?>
                                                <option value="<?= (int)$s['ID'] ?>" <?= ($t['SUPERVISOR_ID'] == $s['ID']) ? 'selected' : '' ?>><?= htmlspecialchars($s['NAME']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="tm-btn-primary tm-btn-sm"><i class="bi bi-check-lg"></i></button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT: Members & Assignment -->
        <div class="col-lg-8">
            <div class="tm-card h-100">
                <div class="tm-card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <h6 style="font-size:0.85rem;font-weight:700;color:var(--tm-ink);margin:0;">
                                <i class="bi bi-people" style="color:var(--tm-accent);"></i> Members &amp; Team Assignment
                            </h6>
                            <p style="font-size:0.7rem;color:var(--tm-ink-soft);margin:0;">Assign or transfer members between TBL_TEAMS</p>
                        </div>
                    </div>

                    <form method="GET" class="d-flex align-items-center gap-2 flex-wrap mb-3" style="background:var(--tm-soft);border:1px solid var(--tm-border);border-radius:0.625rem;padding:0.5rem 0.75rem;">
                        <div class="input-group input-group-sm" style="max-width:180px;">
                            <span class="input-group-text" style="background:#fff;border-color:var(--tm-border);font-size:0.7rem;"><i class="bi bi-search"></i></span>
                            <input type="text" name="search_name" class="form-control tm-input" placeholder="Search name..." value="<?= htmlspecialchars($search_name) ?>">
                        </div>
                        <select name="team_id" class="form-select tm-select" style="width:auto;min-width:130px;">
                            <option value="0">All TBL_TEAMS</option>
                            <?php foreach ($TBL_TEAMS as $t): ?>
                                <option value="<?= (int)$t['ID'] ?>" <?= $filter_team_id == $t['ID'] ? 'selected' : '' ?>><?= htmlspecialchars($t['NAME']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="role" class="form-select tm-select" style="width:auto;min-width:110px;">
                            <option value="">All TBL_ROLES</option>
                            <option value="Admin" <?= $filter_role==='Admin'?'selected':'' ?>>Admin</option>
                            <option value="Manager" <?= $filter_role==='Manager'?'selected':'' ?>>Manager</option>
                            <option value="Supervisor" <?= $filter_role==='Supervisor'?'selected':'' ?>>Supervisor</option>
                            <option value="Officer" <?= $filter_role==='Officer'?'selected':'' ?>>Officer</option>
                        </select>
                        <select name="status" class="form-select tm-select" style="width:auto;min-width:110px;">
                            <option value="">All Status</option>
                            <option value="Active" <?= $filter_status==='Active'?'selected':'' ?>>Active</option>
                            <option value="Inactive" <?= $filter_status==='Inactive'?'selected':'' ?>>Inactive</option>
                            <option value="Suspended" <?= $filter_status==='Suspended'?'selected':'' ?>>Suspended</option>
                        </select>
                        <button type="submit" class="tm-btn-primary tm-btn-sm"><i class="bi bi-funnel"></i> Filter</button>
                        <a href="<?= url('modules/TBL_USERS/team_members.php') ?>" class="tm-btn-outline tm-btn-sm"><i class="bi bi-x-lg"></i></a>
                    </form>

                    <div class="table-responsive">
                        <table class="tm-table">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Role</th>
                                    <th>Mobile</th>
                                    <th>Team</th>
                                    <th class="text-end" style="min-width:200px;">Assign / Transfer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($TBL_USERSList)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4" style="color:var(--tm-ink-soft);font-size:0.8125rem;">
                                            <i class="bi bi-info-circle"></i> No members found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($TBL_USERSList as $u):
                                        $initial = strtoupper(substr($u['NAME'], 0, 1));
                                        $colors = ['#6366f1','#8b5cf6','#a855f7','#ec4899','#f43f5e','#10b981','#14b8a6','#06b6d4','#0ea5e9','#2563eb'];
                                        $colorIdx = (int)$u['ID'] % count($colors);
                                        $badgeClass = $u['STATUS'] === 'Active' ? 'tm-badge-active' : ($u['STATUS'] === 'Suspended' ? 'tm-badge-suspended' : 'tm-badge-inactive');
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="tm-member-info">
                                                    <div class="tm-avatar" style="background:<?= $colors[$colorIdx] ?>;"><?= $initial ?></div>
                                                    <div>
                                                        <div class="name"><?= htmlspecialchars($u['NAME']) ?></div>
                                                        <div class="meta"><?= htmlspecialchars($u['LOGIN_ID'] ?? '-') ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="tm-badge tm-badge-role" title="<?= htmlspecialchars($roleDesc[$u['ROLE']] ?? '') ?>"><?= htmlspecialchars($u['ROLE']) ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($u['MOBILE'])): ?>
                                                    <a href="tel:<?= htmlspecialchars($u['MOBILE']) ?>" style="color:var(--tm-accent);text-decoration:none;font-size:0.75rem;"><?= htmlspecialchars($u['MOBILE']) ?></a>
                                                <?php else: ?>
                                                    <span style="color:var(--tm-ink-soft);font-size:0.75rem;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="tm-badge <?= $badgeClass ?>" style="display:inline-flex;align-items:center;gap:0.3rem;"><i class="bi bi-circle-fill" style="font-size:0.4rem;"></i> <?= htmlspecialchars($u['STATUS']) ?></span>
                                                <br>
                                                <?php if ($u['TEAM_ID']): ?>
                                                    <span class="tm-badge tm-badge-team" style="margin-top:0.2rem;"><?= htmlspecialchars($u['TEAM_NAME']) ?></span>
                                                <?php else: ?>
                                                    <span class="tm-badge tm-badge-noteam" style="margin-top:0.2rem;">No Team</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <form method="POST" class="d-inline-flex gap-1 align-items-center">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="assign_member">
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['ID'] ?>">
                                                    <select name="team_id" class="form-select tm-select" style="width:auto;min-width:130px;">
                                                        <option value="">No Team</option>
                                                        <?php foreach ($TBL_TEAMS as $t): ?>
                                                            <option value="<?= (int)$t['ID'] ?>" <?= ($u['TEAM_ID'] == $t['ID']) ? 'selected' : '' ?>><?= htmlspecialchars($t['NAME']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="tm-btn-primary tm-btn-sm"><i class="bi bi-check-lg"></i> Save</button>
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
