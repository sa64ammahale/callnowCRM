<?php
require_once __DIR__ . '/../../php_scripts/auth.php';      // creates $link, user auth, session
require_once __DIR__ . '/../../config.php';     // CRITICAL: establishes database connection ($link)
requirePermission('manage_database');

// Get total count for header
$total_records = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) as total FROM TBL_MAIN"))['total'] ?? 0;
$unused = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) as c FROM TBL_MAIN WHERE MAINDATABASE_CALL_DIALED_STATUS = 'Not Called'"))['c'] ?? 0;

$statusCounts = [];
$sc = mysqli_query($link, "SELECT MAINDATABASE_CALL_DIALED_STATUS, COUNT(*) as cnt FROM TBL_MAIN GROUP BY MAINDATABASE_CALL_DIALED_STATUS ORDER BY cnt DESC");
if ($sc) while ($s = mysqli_fetch_assoc($sc)) $statusCounts[] = $s;

// Get all active assignees for assign dropdown
$TBL_USERS_result = mysqli_query($link, "SELECT ID, NAME FROM TBL_USERS WHERE STATUS = 'Active' ORDER BY NAME");
$telecallers = [];
while ($u = mysqli_fetch_assoc($TBL_USERS_result)) {
    $telecallers[$u['ID']] = $u['NAME'];
}
?>

<?php
// POST handlers for view/update single record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    $action = $_POST['action'];

    if ($action === 'view' && !empty($_POST['id'])) {
        $id = intval($_POST['id']);
        $stmt = mysqli_prepare($link, "SELECT * FROM TBL_MAIN WHERE ID = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        header('Content-Type: application/json');
        echo json_encode(['row' => $row]);
        exit;
    }

    if ($action === 'update' && !empty($_POST['id'])) {
        if (!isAdmin() && !isManager()) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Admin or Manager access required']);
            exit;
        }
        $id = intval($_POST['id']);
        $name = isset($_POST['MAINDATABASE_NAME']) ? trim($_POST['MAINDATABASE_NAME']) : '';
        $mobile = isset($_POST['MAINDATABASE_MOBILE']) ? preg_replace('/\D/', '', $_POST['MAINDATABASE_MOBILE']) : '';
        $company = isset($_POST['MAINDATABASE_COMPANY']) ? trim($_POST['MAINDATABASE_COMPANY']) : '';
        $package = isset($_POST['MAINDATABASE_PACKAGE']) ? trim($_POST['MAINDATABASE_PACKAGE']) : '';
        $other = isset($_POST['MAINDATABASE_OTHER_INFO']) ? trim($_POST['MAINDATABASE_OTHER_INFO']) : '';
        $status = isset($_POST['MAINDATABASE_CALL_DIALED_STATUS']) ? trim($_POST['MAINDATABASE_CALL_DIALED_STATUS']) : 'Not Called';
        $assigned = isset($_POST['MAINDATABASE_CALL_DIALED_USER']) && $_POST['MAINDATABASE_CALL_DIALED_USER'] !== '' ? intval($_POST['MAINDATABASE_CALL_DIALED_USER']) : null;

        if ($mobile === '' || !preg_match('/^[6-9]\d{9}$/', substr($mobile, -10))) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid mobile (Indian 10 digits required).']);
            exit;
        }
        $mobile = substr($mobile, -10);

        $sql = "UPDATE TBL_MAIN SET MAINDATABASE_NAME = ?, MAINDATABASE_MOBILE = ?, MAINDATABASE_COMPANY = ?, MAINDATABASE_PACKAGE = ?, MAINDATABASE_OTHER_INFO = ?, MAINDATABASE_CALL_DIALED_STATUS = ?, MAINDATABASE_CALL_DIALED_USER = ? WHERE ID = ?";
        $stmt = mysqli_prepare($link, $sql);
        mysqli_stmt_bind_param($stmt, "ssssssii", $name, $mobile, $company, $package, $other, $status, $assigned, $id);
        $ok = mysqli_stmt_execute($stmt);
        if ($ok) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Row updated']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Update failed: ' . mysqli_stmt_error($stmt)]);
        }
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unknown action']);
    exit;
}
?>

<?php $pageTitle = 'Main Database • CallNow'; include '../../php_scripts/header.php'; ?>

<div class="container page-wrapper py-2">

    <div class="md-header">
        <div class="md-header-content">
            <div class="md-header-left">
                <h1><i class="bi bi-database-fill"></i> Main Database</h1>
                <p>Manage, search, and export main records</p>
            </div>
            <div class="md-header-actions">
                <a href="<?= url('modules/database/data_management_temporary.php') ?>" class="md-btn-glass"><i class="bi bi-database-gear"></i> Temp DB</a>
                <a href="<?= url('modules/database/upload_data.php') ?>" class="md-btn-glass"><i class="bi bi-cloud-upload"></i> Upload</a>
            </div>
        </div>
    </div>

    <div class="md-stat-grid">
        <div class="md-stat-card">
            <div class="num"><i class="bi bi-database"></i><?= number_format($total_records) ?></div>
            <p class="lbl">Total Records</p>
        </div>
        <div class="md-stat-card">
            <div class="num"><i class="bi bi-telephone-x"></i><?= number_format($unused) ?></div>
            <p class="lbl">Not Called</p>
        </div>
        <?php foreach (array_slice($statusCounts, 0, 2) as $s): ?>
            <div class="md-stat-card">
                <div class="num"><i class="bi bi-tag"></i><?= number_format((int)$s['cnt']) ?></div>
                <p class="lbl"><?= htmlspecialchars($s['MAINDATABASE_CALL_DIALED_STATUS'] ?: 'Unknown') ?></p>
            </div>
        <?php endforeach; ?>
        <div class="md-stat-card">
            <div class="num"><i class="bi bi-layers"></i><?= count($statusCounts) ?></div>
            <p class="lbl">Status Types</p>
        </div>
    </div>

    <div class="md-toolbar">
        <div class="md-toolbar-row">
            <div class="btn-group" role="group">
                <a href="<?= url('modules/database/add_single_number.php') ?>" class="md-btn-success md-btn-sm"><i class="bi bi-upload"></i> Upload Single</a>
                <button id="bulkAssignBtn" class="md-btn-primary md-btn-sm" data-bs-toggle="modal" data-bs-target="#assignModal"><i class="bi bi-people"></i> Assign Selected</button>
                <button id="bulkDeleteBtn" class="md-btn-danger md-btn-sm"><i class="bi bi-trash"></i> Delete Selected</button>
            </div>
            <div class="ms-auto d-flex gap-2 align-items-center">
                <button id="refreshBtn" class="md-btn-outline md-btn-sm"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
            </div>
        </div>
    </div>

    <div class="md-card">
        <div class="md-card-body" style="padding:0;">
            <table id="mainTable" class="table table-hover table-sm md-dt-table" style="width:100%;margin:0;">
                <thead>
                    <tr>
                        <th style="width:36px;"><input type="checkbox" id="selectAll"></th>
                        <th>Mobile</th>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Package</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th>Uploaded</th>
                        <th>ID</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container"></div>

<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:0.75rem;border:1px solid var(--md-border);">
            <div class="modal-header" style="background:linear-gradient(135deg,var(--md-accent),var(--md-accent-dark));color:#fff;border-radius:0.75rem 0.75rem 0 0;">
                <h5 class="modal-title fw-bold"><i class="bi bi-people"></i> Bulk Assign Selected ( <span id="selectedCount">0</span> )</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:1.25rem;">
                <label style="font-size:0.7rem;font-weight:600;color:var(--md-ink);margin-bottom:0.2rem;">Assign to Telecaller</label>
                <select class="form-select main-input" id="assignUser">
                    <option value="">-- Select User --</option>
                    <?php foreach($telecallers as $id => $name): ?>
                        <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer d-flex justify-content-end gap-2" style="border-top:1px solid var(--md-border);padding:0.75rem 1.25rem;">
                <button type="button" class="md-btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="md-btn-success" id="doAssign"><i class="bi bi-check2"></i> Assign Now</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form id="editForm" class="modal-content" style="border-radius:0.75rem;border:1px solid #e2e4f0;">
            <div class="modal-header" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border-radius:0.75rem 0.75rem 0 0;">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square"></i> Edit Record</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:1.25rem;">
                <input type="hidden" id="editId" name="id">
                <div class="info-grid" id="infoSection">
                    <div class="info-item"><strong>ID</strong><span id="infoId">-</span></div>
                    <div class="info-item"><strong>Upload Time</strong><span id="infoUpload">-</span></div>
                    <div class="info-item"><strong>Last Dialed</strong><span id="infoLastDialed">-</span></div>
                    <div class="info-item"><strong>Last Connected</strong><span id="infoLastConnected">-</span></div>
                    <div class="info-item"><strong>Call Time</strong><span id="infoCallTime">-</span></div>
                    <div class="info-item"><strong>Call By</strong><span id="infoCallBy">-</span></div>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label style="font-size:0.7rem;font-weight:600;color:#1e1b4b;margin-bottom:0.2rem;">Name</label>
                        <input class="form-control main-input" name="MAINDATABASE_NAME" id="editName" placeholder="Full name">
                    </div>
                    <div class="col-md-6">
                        <label style="font-size:0.7rem;font-weight:600;color:#1e1b4b;margin-bottom:0.2rem;">Mobile</label>
                        <input class="form-control main-input" name="MAINDATABASE_MOBILE" id="editMobile" placeholder="10-digit mobile">
                    </div>
                    <div class="col-md-6">
                        <label style="font-size:0.7rem;font-weight:600;color:#1e1b4b;margin-bottom:0.2rem;">Company</label>
                        <input class="form-control main-input" name="MAINDATABASE_COMPANY" id="editCompany" placeholder="Company name">
                    </div>
                    <div class="col-md-6">
                        <label style="font-size:0.7rem;font-weight:600;color:#1e1b4b;margin-bottom:0.2rem;">Package</label>
                        <input class="form-control main-input" name="MAINDATABASE_PACKAGE" id="editPackage" placeholder="Package">
                    </div>
                    <div class="col-12">
                        <label style="font-size:0.7rem;font-weight:600;color:#1e1b4b;margin-bottom:0.2rem;">Other Info</label>
                        <textarea class="form-control main-input" name="MAINDATABASE_OTHER_INFO" id="editOther" rows="2" placeholder="Additional notes"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label style="font-size:0.7rem;font-weight:600;color:#1e1b4b;margin-bottom:0.2rem;">Status</label>
                        <select class="form-select main-input" name="MAINDATABASE_CALL_DIALED_STATUS" id="editStatus">
                            <option>Not Called</option><option>Dialed</option><option>Connected</option><option>Busy</option><option>No Answer</option><option>Do Not Call</option><option>Pending</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label style="font-size:0.7rem;font-weight:600;color:#1e1b4b;margin-bottom:0.2rem;">Assigned To</label>
                        <select class="form-select main-input" name="MAINDATABASE_CALL_DIALED_USER" id="editAssigned">
                            <option value="">-- Unassigned --</option>
                            <?php foreach($telecallers as $tid => $tname): ?>
                                <option value="<?= $tid ?>"><?= htmlspecialchars($tname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mt-2 text-danger small" id="editError" style="display:none"></div>
            </div>
            <div class="modal-footer d-flex justify-content-end gap-2" style="border-top:1px solid #e2e4f0;padding:0.75rem 1.25rem;">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm" style="background:linear-gradient(135deg,#6366f1,#4f46e5);border:none;"><i class="bi bi-check-lg"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<style>
:root {
    --md-accent: var(--accent);
    --md-accent-dark: var(--accent-hover, #4f46e5);
    --md-ink: #1e1b4b;
    --md-ink-soft: #6b6890;
    --md-soft: #eef2ff;
    --md-border: #e2e4f0;
}
.md-header {
    background: linear-gradient(135deg, #4f46e5 0%, #6366f1 50%, #818cf8 100%);
    border-radius: 1rem; padding: 1.5rem 2rem; margin-bottom: 1.5rem;
    position: relative; overflow: hidden;
}
.md-header::before {
    content: ''; position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.3;
}
.md-header-content { position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
.md-header-left h1 { font-size: 1.35rem; font-weight: 700; color: #fff; margin: 0 0 0.2rem 0; letter-spacing: -0.02em; display: flex; align-items: center; gap: 0.5rem; }
.md-header-left h1 i { font-size: 1.4rem; }
.md-header-left p { color: rgba(255,255,255,0.7); font-size: 0.8125rem; margin: 0; }
.md-header-actions { display: flex; gap: 0.5rem; }
.md-btn-glass {
    background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2);
    color: #fff; backdrop-filter: blur(4px); font-size: 0.75rem; padding: 0.4rem 0.9rem;
    border-radius: 0.5rem; transition: all 0.15s ease; text-decoration: none; display: flex; align-items: center; gap: 0.35rem;
}
.md-btn-glass:hover { background: rgba(255,255,255,0.25); border-color: rgba(255,255,255,0.35); color: #fff; transform: translateY(-1px); }

.md-stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem; }
.md-stat-card { background: var(--md-soft); border: 1px solid var(--md-border); border-radius: 0.75rem; padding: 0.875rem 1.125rem; }
.md-stat-card .num { font-size: 1.35rem; font-weight: 700; color: var(--md-ink); line-height: 1.2; }
.md-stat-card .lbl { font-size: 0.7rem; color: var(--md-ink-soft); margin: 0; }
.md-stat-card .num i { font-size: 0.9rem; margin-right: 0.25rem; }

.md-toolbar {
    background: #fff; border: 1px solid var(--md-border); border-radius: 0.75rem;
    padding: 0.75rem 1rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.md-toolbar-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }

.md-card { background: #fff; border: 1px solid var(--md-border); border-radius: 0.875rem; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
.md-card-body { padding: 1.25rem; }

.md-btn-primary {
    background: linear-gradient(135deg, var(--md-accent), var(--md-accent-dark)) !important;
    border: none !important; color: #fff !important; border-radius: 0.5rem !important;
    font-size: 0.75rem !important; font-weight: 600 !important; padding: 0.4rem 1rem !important;
    transition: all 0.15s ease !important; box-shadow: 0 2px 6px rgba(79,70,229,0.2) !important;
}
.md-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(79,70,229,0.3) !important; }
.md-btn-sm { padding: 0.3rem 0.65rem !important; font-size: 0.6875rem !important; border-radius: 0.375rem !important; }
.md-btn-success { background: linear-gradient(135deg, #10b981, #059669) !important; border: none !important; color: #fff !important; border-radius: 0.5rem !important; font-size: 0.75rem !important; font-weight: 600 !important; padding: 0.3rem 0.65rem !important; transition: all 0.15s ease !important; }
.md-btn-success:hover { transform: translateY(-1px); }
.md-btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626) !important; border: none !important; color: #fff !important; border-radius: 0.5rem !important; font-size: 0.75rem !important; font-weight: 600 !important; padding: 0.3rem 0.65rem !important; transition: all 0.15s ease !important; }
.md-btn-danger:hover { transform: translateY(-1px); }
.md-btn-outline { border: 1px solid var(--md-border) !important; background: #fff !important; color: var(--md-ink-soft) !important; border-radius: 0.5rem !important; font-size: 0.75rem !important; padding: 0.3rem 0.65rem !important; transition: all 0.12s ease !important; }
.md-btn-outline:hover { border-color: var(--md-accent) !important; color: var(--md-accent) !important; }

.md-dt-table thead th { background: #fafbff; color: #6b6890; font-weight: 600; font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #e2e4f0; padding: 0.625rem 0.5rem; }
.md-dt-table tbody td { padding: 0.4rem 0.5rem; font-size: 0.8rem; border-bottom: 1px solid #f0f1f8; vertical-align: middle; }
.md-dt-table tbody tr { cursor: pointer; }
.md-dt-table tbody tr:hover { background: #f8f9ff; }
.badge-status { padding: 0.2rem 0.5rem; font-size: 0.68rem; font-weight: 600; border-radius: 0.3rem; }
.dataTables_wrapper .dataTables_length, .dataTables_wrapper .dataTables_filter { margin-bottom: 0.4rem; }
.dataTables_wrapper .dataTables_length select { border: 1px solid #e2e4f0; border-radius: 0.5rem; padding: 0.2rem 0.5rem; font-size: 0.75rem; }
.dataTables_wrapper .dataTables_filter input { border: 1px solid #e2e4f0; border-radius: 0.5rem; padding: 0.2rem 0.5rem; font-size: 0.75rem; }
.dataTables_wrapper .dataTables_info { font-size: 0.75rem; color: #6b6890; padding-top: 0.4rem; }
.dataTables_wrapper .dataTables_paginate { padding-top: 0.4rem; }
.dataTables_wrapper .dataTables_paginate .paginate_button { padding: 0.2rem 0.6rem; font-size: 0.75rem; border-radius: 0.3rem; }
.dataTables_wrapper .dataTables_paginate .paginate_button.current { background: #4f46e5 !important; border-color: #4f46e5 !important; color: #fff !important; }
.dataTables_wrapper .dataTables_paginate .paginate_button:hover { background: #eef2ff; border-color: #d4d6e8; }
.buttons-copy, .buttons-csv, .buttons-excel { font-size: 0.75rem !important; padding: 0.3rem 0.65rem !important; border-radius: 0.5rem !important; }

.info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 0.4rem; background: #f8f9ff; border: 1px solid #e2e4f0; border-radius: 0.5rem; padding: 0.75rem; margin-bottom: 0.75rem; }
.info-item { font-size: 0.75rem; }
.info-item strong { color: #6b6890; display: block; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.1rem; }
.info-item span { color: #1e1b4b; }
.main-input { border: 1px solid #e2e4f0 !important; border-radius: 0.5rem !important; font-size: 0.75rem !important; color: #1e1b4b !important; padding: 0.35rem 0.65rem !important; background: #fff !important; }
.main-input:focus { border-color: #4f46e5 !important; box-shadow: 0 0 0 3px rgba(79,70,229,0.12) !important; outline: none; }
</style>

<!-- Scripts -->
<script src="<?= vnd('dt/dataTables.min.js') ?>"></script>
<script src="<?= vnd('dt/dataTables.bootstrap5.min.js') ?>"></script>
<script src="<?= vnd('dt/buttons.min.js') ?>"></script>
<script src="<?= vnd('dt/buttons.bootstrap5.min.js') ?>"></script>
<script src="<?= vnd('dt/responsive.bootstrap5.min.js') ?>"></script>
<script src="<?= vnd('dt/jszip.min.js') ?>"></script>
<script src="<?= vnd('dt/pdfmake.min.js') ?>"></script>
<script src="<?= vnd('dt/vfs_fonts.js') ?>"></script>
<script src="<?= vnd('dt/buttons.html5.min.js') ?>"></script>
<script src="<?= vnd('dt/buttons.print.min.js') ?>"></script>

<script>
// Toast Function
function showToast(title, message, type = 'success') {
    const toast = `
    <div class="toast align-items-center text-white bg-${type} border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body">
                <strong>${title}</strong><br><small>${message}</small>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>`;
    $('.toast-container').append(toast);
    const toastEl = $('.toast').last()[0];
    const bsToast = new bootstrap.Toast(toastEl, { delay: 5000 });
    bsToast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

$(document).ready(function() {
    
    const table = $('#mainTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        destroy: true,
        searchDelay: 400,
        ajax: {
            url: (window.APP_BASE || '') + '/modules/database/maindatabase_ajax/datatable.php',
            type: 'GET',
            dataSrc: function (json) {
                if (json === null || typeof json !== 'object' || !json.data) {
                    console.error('DataTables: invalid response from server', json);
                    throw new Error('Invalid server response. Check that the API endpoint is reachable.');
                }
                return json.data;
            }
        },
        pageLength: 2500,
        lengthMenu: [[250, 500, 1000, 1500, 2000], [250, 500, 1000, 1500, 2000]],
        order: [[7, 'desc']],
        dom: '<"row"<"col-sm-12 col-md-4"l><"col-sm-12 col-md-4 text-center"B><"col-sm-12 col-md-4"f>>rtip',
        buttons: [
            { extend: 'copy', text: '<i class="bi bi-copy"></i> Copy', className: 'btn btn-outline-secondary btn-sm' },
            { extend: 'csv', text: '<i class="bi bi-file-earmark-spreadsheet"></i> CSV', className: 'btn btn-success btn-sm', title: 'MainDatabase_Export_' + new Date().toISOString().slice(0,10) },
            { extend: 'excel', text: '<i class="bi bi-file-excel"></i> Excel', className: 'btn btn-info btn-sm' },
            { text: '<i class="bi bi-trash3"></i> Delete Selected', className: 'btn btn-danger btn-sm', action: function() { bulkDelete(); }},
            {
                text: '<i class="bi bi-cloud-download"></i> Export Full DB (in Parts)',
                className: 'btn btn-primary btn-sm shadow-sm fw-bold',
                action: function () {
                    $.get((window.APP_BASE || '') + '/modules/database/maindatabase_ajax/get_total_count.php', function (total) {
                        total = parseInt(total);
                        if (total === 0) return showToast('Empty', 'No data found', 'info');
            
                        if (total > 100000 && !confirm(`Warning: ${total.toLocaleString()} records!\n\nThis will download in multiple large CSV files.\n\nContinue?`)) {
                            return;
                        }
            
                        const win = window.open((window.APP_BASE || '') + '/modules/database/maindatabase_ajax/download_bach.php', '_blank');
                        if (win) {
                            showToast('Export Started', `${total.toLocaleString()} records → downloading in parts`, 'success');
                        } else {
                            showToast('Popup Blocked!', 'Please allow popups', 'danger');
                        }
                    });
                }
            }
        ],
        columnDefs: [
            { orderable: false, targets: 0 },
            { visible: false, targets: 8 },
            { width: '100px', targets: 1 },
            {
                targets: 5,
                render: function(data) {
                    const badge = {
                        'Not Called': 'bg-secondary',
                        'Dialed': 'bg-info',
                        'Connected': 'bg-success',
                        'Busy': 'bg-warning',
                        'No Answer': 'bg-orange',
                        'Do Not Call': 'bg-danger',
                        'Pending': 'bg-primary'
                    }[data] || 'bg-dark';
                    return `<span class="badge ${badge} badge-status">${data || 'Not Called'}</span>`;
                }
            }
        ],
        language: {
            processing: "<div class='spinner-border text-primary' role='status'><span class='visually-hidden'>Loading...</span></div>",
            lengthMenu: "Show _MENU_ records",
            info: "Showing _START_ to _END_ of _TOTAL_ leads",
            paginate: {
                next: '<i class="bi bi-chevron-right"></i>',
                previous: '<i class="bi bi-chevron-left"></i>'
            }
        }
    });

    // Select All
    $('#selectAll').on('click', function() {
        const checked = this.checked;
        table.rows({ page: 'current' }).nodes().to$().find('input[type="checkbox"]').prop('checked', checked);
        updateSelectedCount();
    });

    $('#mainTable tbody').on('change', 'input[type="checkbox"]', updateSelectedCount);

    function updateSelectedCount() {
        const count = $('#mainTable input[type="checkbox"]:checked').length - ($('#selectAll').is(':checked') ? 1 : 0);
        $('#selectedCount').text(count);
    }

    // Bulk Assign
    $('#doAssign').on('click', function() {
        const userId = $('#assignUser').val();
        if (!userId) return showToast('Error', 'Please select a user', 'danger');

        const ids = [];
        $('#mainTable input[type="checkbox"]:checked').each(function() {
            if (!$(this).is('#selectAll')) {
                const row = table.row($(this).closest('tr')).data();
                ids.push(row[8]); // ID is last data index (hidden column)
            }
        });

        if (ids.length === 0) return showToast('Warning', 'No records selected', 'warning');

        $.post((window.APP_BASE || '') + '/modules/database/maindatabase_ajax/bulk_assign.php', { ids: ids, user_id: userId, csrf_token: CSRF_TOKEN }, function(res) {
            if (res.success) {
                table.ajax.reload();
                $('#assignModal').modal('hide');
                showToast('Success!', `${ids.length} leads assigned successfully`, 'success');
            } else {
                showToast('Error', res.message || 'Failed', 'danger');
            }
        }, 'json');
    });

    // Bulk Delete
    window.bulkDelete = function() {
        if (!confirm('Delete selected records permanently?')) return;
        const ids = [];
        $('#mainTable input[type="checkbox"]:checked').each(function() {
            if (!$(this).is('#selectAll')) {
                const row = table.row($(this).closest('tr')).data();
                ids.push(row[8]);
            }
        });
        $.post((window.APP_BASE || '') + '/modules/database/maindatabase_ajax/bulk_delete.php', { ids: ids, csrf_token: CSRF_TOKEN }, function(res) {
            if (res.success) {
                table.ajax.reload();
                showToast('Deleted!', `${ids.length} records removed`, 'danger');
            }
        }, 'json');
    };

    function loadEditModal(id) {
        $.post(location.href, { action:'view', id: id, csrf_token: CSRF_TOKEN }, function(resp){
            if (!resp.row) return alert('Row not found');
            const r = resp.row;
            $('#editId').val(r.ID);
            $('#editName').val(r.MAINDATABASE_NAME);
            $('#editMobile').val(r.MAINDATABASE_MOBILE);
            $('#editCompany').val(r.MAINDATABASE_COMPANY);
            $('#editPackage').val(r.MAINDATABASE_PACKAGE);
            $('#editOther').val(r.MAINDATABASE_OTHER_INFO);
            $('#editStatus').val(r.MAINDATABASE_CALL_DIALED_STATUS);
            $('#editAssigned').val(r.MAINDATABASE_CALL_DIALED_USER);
            $('#infoId').text(r.ID);
            $('#infoUpload').text(r.MAINDATABASE_UPLOAD_DATETIME || '-');
            $('#infoLastDialed').text(r.LAST_DIALED_DATE_TIME || '-');
            $('#infoLastConnected').text(r.LAST_CONNECTED_PERIOD || '-');
            $('#infoCallTime').text(r.MAINDATABASE_CALL_DIAL_TIME || '-');
            $('#infoCallBy').text(r.CALL_BY || '-');
            $('#editError').hide().text('');
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }, 'json');
    }

    $('#mainTable tbody').on('click', 'td', function() {
        if ($(this).find('input[type="checkbox"]').length) return;
        const row = table.row($(this).closest('tr')).data();
        if (row && row[8]) loadEditModal(row[8]);
    });

    $('#editForm').on('submit', function(e){
        e.preventDefault();
        const data = $(this).serializeArray();
        data.push({ name: 'action', value: 'update' });
        data.push({ name: 'csrf_token', value: CSRF_TOKEN });
        $.post(location.href, data, function(resp){
            if (resp.success) {
                bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
                table.ajax.reload();
                showToast('Updated', 'Record saved', 'success');
            } else {
                $('#editError').show().text(resp.error || 'Update failed');
            }
        }, 'json').fail(function(){ $('#editError').show().text('Server error'); });
    });

    $('#bulkDeleteBtn').on('click', function() {
        window.bulkDelete();
    });

    $('#refreshBtn').on('click', function() {
        table.ajax.reload();
        showToast('Refreshed', 'Table data reloaded', 'info');
    });
});
</script>

<?php include '../../php_scripts/footer.php'; ?>
