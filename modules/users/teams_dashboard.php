<?php
require_once '../../php_scripts/auth.php';
require_once '../../php_scripts/team_auth.php';
requirePermission('manage_TBL_TEAMS');

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
            $check = $link->prepare("SELECT COUNT(*) FROM TBL_TEAMS WHERE ID IN (?, ?)");
            $check->bind_param("ii", $from_team_id, $to_team_id);
            $check->execute();
            $count = $check->get_result()->fetch_row()[0] ?? 0;
            if ($count < 2) {
                $msg = "Invalid team selection.";
                $msg_type = "danger";
            } else {
                $stmt = $link->prepare("UPDATE TBL_USERS SET TEAM_ID = ? WHERE TEAM_ID = ?");
                $stmt->bind_param("ii", $to_team_id, $from_team_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                if ($affected > 0) {
                    $msg = "$affected member(s) reassigned successfully.";
                    $msg_type = "success";
                } else {
                    $msg = "No members were reassigned (no TBL_USERS in this team).";
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
        $supervisor_id = $_POST['supervisor_id'] ?: null;
        $manager_id    = $_POST['manager_id'] ?: null;
        if (empty($team_name)) {
            $msg = "Team name is required!";
            $msg_type = "danger";
        } else {
            $check = $link->prepare("SELECT ID FROM TBL_TEAMS WHERE NAME = ?");
            $check->bind_param("s", $team_name);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $msg = "Team name already exists!";
                $msg_type = "warning";
            } else {
                $stmt = $link->prepare("INSERT INTO TBL_TEAMS (NAME, SUPERVISOR_ID, MANAGER_ID) VALUES (?, ?, ?)");
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
}

// ==================== EDIT TEAM ====================
if (($_POST['action'] ?? '') === 'edit') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $msg = "Invalid session token";
        $msg_type = "danger";
    } else {
        $id            = (int)$_POST['team_id'];
        $team_name     = trim($_POST['team_name']);
        $supervisor_id = $_POST['supervisor_id'] ?: null;
        $manager_id    = $_POST['manager_id'] ?: null;
        $stmt = $link->prepare("UPDATE TBL_TEAMS SET NAME = ?, SUPERVISOR_ID = ?, MANAGER_ID = ? WHERE ID = ?");
        $stmt->bind_param("siii", $team_name, $supervisor_id, $manager_id, $id);
        if ($stmt->execute()) {
            $msg = "Team updated!";
            $msg_type = "success";
        } else {
            $msg = "Update failed!";
            $msg_type = "danger";
        }
    }
}

// ==================== DELETE TEAM ====================
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $link->query("UPDATE TBL_USERS SET TEAM_ID = NULL WHERE TEAM_ID = $id");
    if ($link->query("DELETE FROM TBL_TEAMS WHERE ID = $id")) {
        $msg = "Team deleted!";
        $msg_type = "success";
    } else {
        $msg = "Cannot delete team (in use?)";
        $msg_type = "danger";
    }
    header("Location: TBL_TEAMS_dashboard.php");
    exit;
}

// Fetch all TBL_TEAMS with supervisor name, manager name & member count
$TBL_TEAMS_query = "
    SELECT
        t.ID,
        t.NAME as TEAM_NAME,
        t.SUPERVISOR_ID,
        t.MANAGER_ID,
        sup.NAME as SUP_NAME,
        mgr.NAME as MANAGER_NAME,
        (SELECT COUNT(*) FROM TBL_USERS WHERE TEAM_ID = t.ID) as MEMBER_COUNT
    FROM TBL_TEAMS t
    LEFT JOIN TBL_USERS sup ON t.SUPERVISOR_ID = sup.ID
    LEFT JOIN TBL_USERS mgr ON t.MANAGER_ID = mgr.ID
    ORDER BY t.NAME
";
$TBL_TEAMS_result = mysqli_query($link, $TBL_TEAMS_query);
$total_TBL_TEAMS  = mysqli_num_rows($TBL_TEAMS_result);

// Fetch supervisors & managers into arrays once
$all_supervisors = [];
$sup_res = mysqli_query($link, "SELECT ID, NAME FROM TBL_USERS WHERE ROLE IN ('Supervisor','Manager') ORDER BY NAME");
while ($s = mysqli_fetch_assoc($sup_res)) $all_supervisors[] = $s;

$all_managers = [];
$mgr_res = mysqli_query($link, "SELECT ID, NAME FROM TBL_USERS WHERE ROLE = 'Manager' ORDER BY NAME");
while ($m = mysqli_fetch_assoc($mgr_res)) $all_managers[] = $m;

// All TBL_TEAMS for reassign dropdown
$all_TBL_TEAMS = [];
$at_res = mysqli_query($link, "SELECT ID, NAME FROM TBL_TEAMS ORDER BY NAME");
while ($r = mysqli_fetch_assoc($at_res)) $all_TBL_TEAMS[] = $r;

// Members grouped by team
$team_members = [];
$members_res = mysqli_query($link, "SELECT u.ID, u.NAME, u.MOBILE, u.ROLE, u.TEAM_ID FROM TBL_USERS u WHERE u.TEAM_ID IS NOT NULL ORDER BY u.TEAM_ID, u.NAME");
while ($m = mysqli_fetch_assoc($members_res)) {
    $team_members[$m['TEAM_ID']][] = $m;
}
?>
<?php $pageTitle = 'Manage TBL_TEAMS - CallNow'; include '../../php_scripts/header.php'; ?>

<style>
:root {
    --td-accent: var(--accent);
    --td-accent-dark: var(--accent-hover, #4f46e5);
    --td-ink: #1e1b4b;
    --td-ink-soft: #6b6890;
    --td-soft: #f0f2ff;
    --td-border: #e2e4f0;
}

/* ── Header ── */
.td-header {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
    border-radius: 1rem;
    padding: 1.5rem 2rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.td-header::before {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.3;
}
.td-header-content {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}
.td-header-left h1 {
    font-size: 1.35rem;
    font-weight: 700;
    color: #fff;
    margin: 0 0 0.2rem 0;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.td-header-left h1 i { font-size: 1.4rem; }
.td-header-left p { color: rgba(255,255,255,0.7); font-size: 0.8125rem; margin: 0; }
.td-header-actions { display: flex; gap: 0.5rem; }
.td-header-actions .btn {
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.2);
    color: #fff;
    backdrop-filter: blur(4px);
    font-size: 0.75rem;
    padding: 0.4rem 0.9rem;
    border-radius: 0.5rem;
    transition: all 0.15s ease;
    text-decoration: none;
}
.td-header-actions .btn:hover {
    background: rgba(255,255,255,0.25);
    border-color: rgba(255,255,255,0.35);
    color: #fff;
    transform: translateY(-1px);
}

/* ── Alert ── */
.td-alert {
    border-radius: 0.625rem;
    font-size: 0.8125rem;
    padding: 0.75rem 1rem;
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* ── Cards ── */
.td-card {
    background: #fff;
    border: 1px solid var(--td-border);
    border-radius: 0.875rem;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.td-card-body { padding: 1.5rem; }

.td-card-title {
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--td-ink);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.td-card-title i {
    width: 28px; height: 28px;
    border-radius: 0.5rem;
    background: var(--td-soft);
    color: var(--td-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8125rem;
}
.td-card-subtitle {
    font-size: 0.75rem;
    color: var(--td-ink-soft);
    margin: 0;
}

/* ── Form ── */
.td-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--td-ink);
    margin-bottom: 0.3rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}
.td-label .required { color: #ef4444; }
.td-input, .td-select {
    border: 1px solid var(--td-border) !important;
    border-radius: 0.5rem !important;
    font-size: 0.8125rem !important;
    color: var(--td-ink) !important;
    padding: 0.4rem 0.75rem !important;
    background: #fff !important;
}
.td-input:focus, .td-select:focus {
    border-color: var(--td-accent) !important;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.12) !important;
    outline: none;
}
.td-btn-primary {
    background: linear-gradient(135deg, var(--td-accent), var(--td-accent-dark)) !important;
    border: none !important;
    color: #fff !important;
    border-radius: 0.5rem !important;
    font-size: 0.8125rem !important;
    font-weight: 600 !important;
    padding: 0.45rem 1.25rem !important;
    transition: all 0.15s ease !important;
    box-shadow: 0 2px 8px rgba(99,102,241,0.25) !important;
}
.td-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(99,102,241,0.35) !important;
}
.td-btn-outline {
    border: 1px solid var(--td-border) !important;
    border-radius: 0.5rem !important;
    font-size: 0.75rem !important;
    font-weight: 500 !important;
    padding: 0.4rem 1rem !important;
    color: var(--td-ink-soft) !important;
    background: #fff !important;
    transition: all 0.12s ease !important;
    text-decoration: none !important;
}
.td-btn-outline:hover {
    border-color: var(--td-accent) !important;
    color: var(--td-accent) !important;
    background: var(--td-soft) !important;
}
.td-btn-sm-icon {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.3rem 0.65rem;
    border-radius: 0.375rem;
    font-size: 0.6875rem;
    font-weight: 600;
    border: 1px solid var(--td-border);
    background: #fff;
    color: var(--td-ink-soft);
    text-decoration: none;
    transition: all 0.12s ease;
}
.td-btn-sm-icon:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(99,102,241,0.12);
}

/* ── Table ── */
.td-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.8125rem;
    margin: 0;
}
.td-table thead th {
    background: #fafbff;
    color: var(--td-ink-soft);
    font-weight: 600;
    font-size: 0.6875rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0.625rem 0.875rem;
    border-bottom: 1px solid var(--td-border);
    white-space: nowrap;
}
.td-table tbody td {
    padding: 0.625rem 0.875rem;
    border-bottom: 1px solid #f0f1f8;
    color: var(--td-ink);
    vertical-align: middle;
}
.td-table tbody tr:last-child td { border-bottom: none; }
.td-table tbody tr {
    transition: background 0.1s ease;
    cursor: pointer;
}
.td-table tbody tr:hover { background: #f8f9ff; }

.td-badge-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.2rem 0.7rem;
    border-radius: 999px;
    font-size: 0.6875rem;
    font-weight: 600;
    white-space: nowrap;
}
.td-badge-supervisor {
    background: linear-gradient(135deg, #ede9fe, #ddd6fe);
    color: #5b21b6;
    border: 1px solid #c4b5fd;
}
.td-badge-manager {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
    border: 1px solid #6ee7b7;
}
.td-badge-member {
    background: linear-gradient(135deg, var(--td-soft), #e2e5ff);
    color: var(--td-accent-dark);
    border: 1px solid #c7cbf5;
}
.td-badge-role {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
    border: 1px solid #93c5fd;
}

/* ── Empty state ── */
.td-empty {
    text-align: center;
    padding: 2.5rem 1rem;
    color: var(--td-ink-soft);
}
.td-empty-icon { font-size: 2.5rem; color: #d4d6e8; margin-bottom: 0.5rem; }
.td-empty-text { font-size: 0.875rem; margin-bottom: 0; }

/* ── Stat bar ── */
.td-stats {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
    margin-top: 1rem;
}
.td-stat {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 0.625rem;
    padding: 0.45rem 1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    backdrop-filter: blur(4px);
}
.td-stat-icon {
    width: 32px; height: 32px;
    border-radius: 0.5rem;
    background: rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 0.875rem;
}
.td-stat-num {
    font-size: 1rem; font-weight: 700; color: #fff; line-height: 1.2;
}
.td-stat-label {
    font-size: 0.6875rem; color: rgba(255,255,255,0.7); line-height: 1;
}

/* ── Modal ── */
.td-modal-header {
    background: linear-gradient(135deg, #f8f9ff, #f0f2ff);
    border-bottom: 1px solid var(--td-border);
    padding: 1rem 1.25rem;
}
.td-modal-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--td-ink);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.td-modal-sub {
    font-size: 0.75rem;
    color: var(--td-ink-soft);
    margin: 0;
}

@media (max-width: 767px) {
    .td-header { padding: 1.25rem; }
    .td-header-content { flex-direction: column; align-items: stretch; }
    .td-header-actions { justify-content: stretch; }
    .td-header-actions .btn { flex: 1; justify-content: center; }
    .td-stats { gap: 0.5rem; }
    .td-stat { flex: 1; min-width: 0; padding: 0.35rem 0.75rem; }
}
</style>

<div class="container page-wrapper">

    <!-- ─── Header ─── -->
    <div class="td-header">
        <div class="td-header-content">
            <div class="td-header-left">
                <h1><i class="bi bi-diagram-3-fill"></i> Manage TBL_TEAMS</h1>
                <p>Create TBL_TEAMS, assign supervisors &amp; managers, and manage team members</p>
            </div>
            <div class="td-header-actions">
                <a href="<?= url('modules/TBL_USERS/team_members.php') ?>" class="btn"><i class="bi bi-people"></i> Members</a>
                <a href="<?= url('dashboard.php') ?>" class="btn"><i class="bi bi-grid"></i> Dashboard</a>
            </div>
        </div>
        <div class="td-stats">
            <div class="td-stat">
                <div class="td-stat-icon"><i class="bi bi-collection"></i></div>
                <div class="td-stat-info">
                    <div class="td-stat-num"><?= $total_TBL_TEAMS ?></div>
                    <div class="td-stat-label">TBL_TEAMS</div>
                </div>
            </div>
            <div class="td-stat">
                <div class="td-stat-icon"><i class="bi bi-person-check"></i></div>
                <div class="td-stat-info">
                    <div class="td-stat-num"><?= count($all_supervisors) ?></div>
                    <div class="td-stat-label">Supervisors</div>
                </div>
            </div>
            <div class="td-stat">
                <div class="td-stat-icon"><i class="bi bi-person-workspace"></i></div>
                <div class="td-stat-info">
                    <div class="td-stat-num"><?= count($all_managers) ?></div>
                    <div class="td-stat-label">Managers</div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="td-alert" style="background:<?= $msg_type==='success'?'#d1fae5':($msg_type==='warning'||$msg_type==='info'?'#fef3c7':'#fee2e2') ?>;border:1px solid <?= $msg_type==='success'?'#6ee7b7':($msg_type==='warning'||$msg_type==='info'?'#fcd34d':'#fca5a5') ?>;color:<?= $msg_type==='success'?'#065f46':($msg_type==='warning'||$msg_type==='info'?'#92400e':'#991b1b') ?>;">
            <i class="bi <?= $msg_type==='success'?'bi-check-circle-fill':($msg_type==='warning'||$msg_type==='info'?'bi-exclamation-triangle-fill':'bi-x-circle-fill') ?>"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="font-size:0.65rem;"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3">

        <!-- ─── Left: Add / Edit Form ─── -->
        <div class="col-lg-4">
            <?php
            $edit_mode = false;
            $edit_team = null;
            if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
                $edit_id = (int)$_GET['edit'];
                $res = mysqli_query($link, "SELECT * FROM TBL_TEAMS WHERE ID = $edit_id");
                $edit_team = mysqli_fetch_assoc($res);
                $edit_mode = true;
            }
            ?>
            <div class="td-card">
                <div class="td-card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="td-card-title">
                                <i class="bi <?= $edit_mode ? 'bi-pencil-square' : 'bi-plus-circle' ?>"></i>
                                <?= $edit_mode ? 'Edit Team' : 'Add New Team' ?>
                            </div>
                            <div class="td-card-subtitle"><?= $edit_mode ? 'Update team details' : 'Create a new team' ?></div>
                        </div>
                        <?php if ($edit_mode): ?>
                            <span style="font-size:0.75rem;font-weight:600;color:var(--td-accent);background:var(--td-soft);padding:0.15rem 0.55rem;border-radius:0.375rem;border:1px solid #dde0f5;">#<?= $edit_team['ID'] ?></span>
                        <?php endif; ?>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="<?= $edit_mode ? 'edit' : 'add' ?>">
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="team_id" value="<?= $edit_team['ID'] ?>">
                        <?php endif; ?>

                        <div class="mb-2">
                            <label class="td-label">Team Name <span class="required">*</span></label>
                            <input type="text" name="team_name" class="form-control td-input"
                                   placeholder="e.g. Sales Team Alpha"
                                   value="<?= htmlspecialchars($edit_team['NAME'] ?? '') ?>" required>
                        </div>
                        <div class="mb-2">
                            <label class="td-label">Team Supervisor</label>
                            <select name="supervisor_id" class="form-select td-select">
                                <option value="">— No Supervisor —</option>
                                <?php foreach ($all_supervisors as $s): ?>
                                    <option value="<?= $s['ID'] ?>" <?= ($edit_team['SUPERVISOR_ID'] ?? '') == $s['ID'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['NAME']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="td-label">Team Manager</label>
                            <select name="manager_id" class="form-select td-select">
                                <option value="">— No Manager —</option>
                                <?php foreach ($all_managers as $m): ?>
                                    <option value="<?= $m['ID'] ?>" <?= ($edit_team['MANAGER_ID'] ?? '') == $m['ID'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($m['NAME']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="td-btn-primary w-100">
                            <i class="bi <?= $edit_mode ? 'bi-save' : 'bi-check2-circle' ?>"></i>
                            <?= $edit_mode ? 'Update Team' : 'Create Team' ?>
                        </button>
                        <?php if ($edit_mode): ?>
                            <a href="<?= url('modules/TBL_USERS/TBL_TEAMS_dashboard.php') ?>" class="td-btn-outline w-100 mt-2 d-block text-center">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <!-- ─── Right: TBL_TEAMS List ─── -->
        <div class="col-lg-8">
            <div class="td-card">
                <div class="td-card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="td-card-title">
                                <i class="bi bi-people"></i> All TBL_TEAMS
                            </div>
                            <div class="td-card-subtitle">Click any row to view details &amp; members</div>
                        </div>
                        <span style="font-size:0.75rem;font-weight:600;color:var(--td-accent-dark);background:var(--td-soft);padding:0.25rem 0.75rem;border-radius:999px;border:1px solid #dde0f5;display:flex;align-items:center;gap:0.35rem;">
                            <i class="bi bi-collection"></i> <?= $total_TBL_TEAMS ?> TBL_TEAMS
                        </span>
                    </div>

                    <?php if ($total_TBL_TEAMS > 0): ?>
                        <div class="table-responsive">
                            <table class="td-table" id="TBL_TEAMSTable">
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
                                    <?php while ($t = mysqli_fetch_assoc($TBL_TEAMS_result)):
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
                                            <td><strong style="color:var(--td-ink);"><?= htmlspecialchars($t['TEAM_NAME']) ?></strong></td>
                                            <td>
                                                <?php if ($t['SUP_NAME']): ?>
                                                    <span class="td-badge-pill td-badge-supervisor"><i class="bi bi-person-badge"></i> <?= htmlspecialchars($t['SUP_NAME']) ?></span>
                                                <?php else: ?>
                                                    <span style="font-size:0.75rem;color:var(--td-ink-soft);">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($t['MANAGER_NAME']): ?>
                                                    <span class="td-badge-pill td-badge-manager"><i class="bi bi-person-workspace"></i> <?= htmlspecialchars($t['MANAGER_NAME']) ?></span>
                                                <?php else: ?>
                                                    <span style="font-size:0.75rem;color:var(--td-ink-soft);">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="td-badge-pill td-badge-member"><?= (int)$t['MEMBER_COUNT'] ?></span>
                                            </td>
                                            <td class="text-end">
                                                <a href="<?= url('modules/TBL_USERS/TBL_TEAMS_dashboard.php') ?>?edit=<?= $t['ID'] ?>" class="td-btn-sm-icon team-action-btn" title="Edit team" style="border-color:#fde68a;color:#92400e;">
                                                    <i class="bi bi-pencil-square"></i> Edit
                                                </a>
                                                <a href="<?= url('modules/TBL_USERS/TBL_TEAMS_dashboard.php') ?>?delete=<?= $t['ID'] ?>"
                                                   onclick="return confirm('Delete team «<?= htmlspecialchars($t['TEAM_NAME']) ?>»? All members will be unassigned.')"
                                                   class="td-btn-sm-icon team-action-btn"
                                                   title="Delete team"
                                                   style="border-color:#fecaca;color:#991b1b;">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="td-empty">
                            <div class="td-empty-icon"><i class="bi bi-diagram-3"></i></div>
                            <p class="td-empty-text">No TBL_TEAMS found. Create your first team using the form on the left.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ─── Team Details Modal ─── -->
<div class="modal fade" id="teamDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content" style="border:0;border-radius:0.875rem;overflow:hidden;">
            <div class="td-modal-header">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="td-modal-title">
                            <i class="bi bi-people-fill" style="color:var(--td-accent);"></i>
                            <span id="mdlTeamName">Team</span>
                        </h5>
                        <p class="td-modal-sub" id="mdlSubInfo"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-3">
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <div style="font-size:0.6875rem;color:var(--td-ink-soft);text-transform:uppercase;letter-spacing:0.04em;font-weight:600;">Supervisor</div>
                        <div id="mdlSupervisor" style="font-size:0.875rem;font-weight:600;color:var(--td-ink);"></div>
                    </div>
                    <div class="col-md-4">
                        <div style="font-size:0.6875rem;color:var(--td-ink-soft);text-transform:uppercase;letter-spacing:0.04em;font-weight:600;">Manager</div>
                        <div id="mdlManager" style="font-size:0.875rem;font-weight:600;color:var(--td-ink);"></div>
                    </div>
                    <div class="col-md-4">
                        <div style="font-size:0.6875rem;color:var(--td-ink-soft);text-transform:uppercase;letter-spacing:0.04em;font-weight:600;">Members</div>
                        <div id="mdlMemberCount" style="font-size:0.875rem;font-weight:600;color:var(--td-ink);"></div>
                    </div>
                </div>

                <hr style="border-color:var(--td-border);margin:0.75rem 0;">

                <h6 style="font-size:0.8125rem;font-weight:700;color:var(--td-ink);margin:0 0 0.5rem 0;display:flex;align-items:center;gap:0.4rem;">
                    <i class="bi bi-people" style="color:var(--td-accent);"></i> Team Members
                </h6>
                <div id="mdlNoMembers" class="td-empty d-none" style="padding:1.5rem 1rem;">
                    <p class="td-empty-text">No members are currently assigned to this team.</p>
                </div>

                <div class="table-responsive" id="mdlMembersTableWrapper">
                    <table class="td-table">
                        <thead>
                            <tr>
                                <th style="width:12%;">ID</th>
                                <th style="width:38%;">Name</th>
                                <th style="width:22%;">Role</th>
                                <th style="width:28%;">Mobile</th>
                            </tr>
                        </thead>
                        <tbody id="mdlMembersBody"></tbody>
                    </table>
                </div>

                <!-- Quick Reassign -->
                <hr style="border-color:var(--td-border);margin:1rem 0;">
                <h6 style="font-size:0.8125rem;font-weight:700;color:var(--td-ink);margin:0 0 0.25rem 0;display:flex;align-items:center;gap:0.4rem;">
                    <i class="bi bi-arrow-left-right" style="color:var(--td-accent);"></i> Quick Reassign Members
                </h6>
                <p style="font-size:0.75rem;color:var(--td-ink-soft);margin:0 0 0.75rem 0;">
                    Move all members of this team to another team.
                </p>

                <form method="POST" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="reassign_members">
                    <input type="hidden" id="reassign_from_team_id" name="from_team_id">
                    <div class="col-md-6">
                        <label style="font-size:0.6875rem;font-weight:600;color:var(--td-ink-soft);margin-bottom:0.2rem;">Target Team</label>
                        <select name="to_team_id" id="reassign_to_team" class="form-select td-select" required>
                            <option value="">— Select target team —</option>
                            <?php foreach ($all_TBL_TEAMS as $t): ?>
                                <option value="<?= $t['ID'] ?>"><?= htmlspecialchars($t['NAME']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="td-btn-primary w-100" style="font-size:0.75rem!important;">
                            <i class="bi bi-arrow-repeat"></i> Reassign
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--td-border);padding:0.75rem 1rem;">
                <button type="button" class="td-btn-outline" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Close</button>
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
            mdlSubInfo.textContent = "Team ID: " + teamId + "  ·  Total Members: " + count;
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
                    tr.innerHTML = '<td style="font-weight:600;">#' + m.ID + '</td>'
                        + '<td>' + escapeHtml(m.NAME || '') + '</td>'
                        + '<td><span class="td-badge-pill td-badge-role">' + escapeHtml(m.ROLE || '') + '</span></td>'
                        + '<td>' + (m.MOBILE ? '<a href="tel:' + encodeURIComponent(m.MOBILE) + '" style="color:var(--td-accent);text-decoration:none;font-weight:500;"><i class="bi bi-telephone-outbound"></i> ' + escapeHtml(m.MOBILE) + '</a>' : '<span style="color:var(--td-ink-soft);font-size:0.75rem;">N/A</span>') + '</td>';
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
<?php mysqli_close($link); ?>
