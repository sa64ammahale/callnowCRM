<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../config.php';
requirePermission('manage_database');

$total_records = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) as total FROM " . tn('TBL_MAIN')))['total'] ?? 0;
$unused = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) as c FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_CALL_DIALED_STATUS = 'Not Called'"))['c'] ?? 0;

$statusCounts = [];
$sc = mysqli_query($link, "SELECT MAINDATABASE_CALL_DIALED_STATUS, COUNT(*) as cnt FROM " . tn('TBL_MAIN') . " GROUP BY MAINDATABASE_CALL_DIALED_STATUS ORDER BY cnt DESC");
if ($sc) while ($s = mysqli_fetch_assoc($sc)) $statusCounts[] = $s;

$TBL_USERS_result = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_USERS') . " WHERE STATUS = 'Active' AND ROLE != 'Super Admin' ORDER BY NAME");
$telecallers = [];
while ($u = mysqli_fetch_assoc($TBL_USERS_result)) {
    $telecallers[$u['ID']] = $u['NAME'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    $action = $_POST['action'];

    if ($action === 'view' && !empty($_POST['id'])) {
        $id = intval($_POST['id']);
        $stmt = mysqli_prepare($link, "SELECT * FROM " . tn('TBL_MAIN') . " WHERE ID = ? LIMIT 1");
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
        if (!isAdmin() && !isSuperAdmin() && !isManager()) {
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

        $sql = "UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_NAME = ?, MAINDATABASE_MOBILE = ?, MAINDATABASE_COMPANY = ?, MAINDATABASE_PACKAGE = ?, MAINDATABASE_OTHER_INFO = ?, MAINDATABASE_CALL_DIALED_STATUS = ?, MAINDATABASE_CALL_DIALED_USER = ? WHERE ID = ?";
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

$pageTitle = 'Main Database • CallNow CRM';
include '../../php_scripts/header.php';
?>

<div class="container-fluid px-3 py-3">
    <!-- Module Header -->
    <div class="module-header mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 fs-xs">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="#">Data Management</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Main Database</li>
                </ol>
            </nav>
            <h1 class="h4 fw-bold text-dark mb-0">Main Database</h1>
            <div class="text-muted fs-xs">Enterprise repository for active calling records, assignments, and telecaller logs</div>
        </div>
        <div class="module-header-actions">
            <a href="<?= url('modules/database/add_single_number.php') ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Add Single Number
            </a>
            <a href="<?= url('modules/database/upload_data.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-cloud-upload me-1"></i> Upload CSV
            </a>
            <a href="<?= url('modules/database/data_management_temporary.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-database-gear me-1"></i> Temporary DB
            </a>
        </div>
    </div>

    <!-- Metric KPI Cards -->
    <div class="row g-2 mb-3">
        <div class="col-sm-6 col-md-3">
            <div class="metric-card">
                <div class="metric-card-title">Total Records</div>
                <div class="metric-card-value text-primary"><?= number_format($total_records) ?></div>
                <div class="metric-card-subtitle text-muted">Active repository size</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="metric-card">
                <div class="metric-card-title">Fresh / Not Called</div>
                <div class="metric-card-value text-info"><?= number_format($unused) ?></div>
                <div class="metric-card-subtitle text-muted">Ready for allocation</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="metric-card">
                <div class="metric-card-title"><?= htmlspecialchars($statusCounts[0]['MAINDATABASE_CALL_DIALED_STATUS'] ?? 'Top Disposition') ?></div>
                <div class="metric-card-value text-success"><?= number_format((int)($statusCounts[0]['cnt'] ?? 0)) ?></div>
                <div class="metric-card-subtitle text-muted">Dominant status</div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="metric-card">
                <div class="metric-card-title">Disposition Types</div>
                <div class="metric-card-value text-dark"><?= count($statusCounts) ?></div>
                <div class="metric-card-subtitle text-muted">Configured stages</div>
            </div>
        </div>
    </div>

    <!-- Action Toolbar -->
    <div class="crm-card mb-3">
        <div class="crm-card-body py-2">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <button id="bulkAssignBtn" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#assignModal">
                        <i class="bi bi-person-check me-1"></i> Assign Selected (<span id="selectedCount">0</span>)
                    </button>
                    <button id="bulkDeleteBtn" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-trash3 me-1"></i> Delete Selected
                    </button>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button id="refreshBtn" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="crm-card">
        <div class="crm-card-body p-0">
            <div class="table-responsive">
                <table id="mainTable" class="table table-hover align-middle mb-0" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:36px; text-align:center;"><input type="checkbox" id="selectAll" class="form-check-input"></th>
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
</div>

<!-- Notification Modal -->
<div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <div id="notifIcon" class="mb-2" style="font-size:2rem;"></div>
                <h6 id="notifTitle" class="fw-bold mb-1"></h6>
                <p id="notifMessage" class="text-muted fs-xs mb-3"></p>
                <button type="button" class="btn btn-primary btn-sm px-4" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus text-primary me-2"></i>Bulk Assign Leads</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="assignUser">Assign Selected (<span id="modalSelectedCount">0</span>) to Telecaller</label>
                    <select class="form-select" id="assignUser">
                        <option value="">-- Choose User / Telecaller --</option>
                        <?php foreach($telecallers as $id => $name): ?>
                            <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="doAssign"><i class="bi bi-check2 me-1"></i> Assign Now</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form id="editForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Customer Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editId" name="id">
                
                <div class="p-2 mb-3 bg-light rounded border">
                    <div class="row g-2 fs-xs">
                        <div class="col-md-2 col-4"><span class="text-muted d-block">Record ID</span><strong id="infoId" class="text-dark">-</strong></div>
                        <div class="col-md-3 col-8"><span class="text-muted d-block">Uploaded</span><strong id="infoUpload" class="text-dark">-</strong></div>
                        <div class="col-md-3 col-6"><span class="text-muted d-block">Last Dialed</span><strong id="infoLastDialed" class="text-dark">-</strong></div>
                        <div class="col-md-2 col-6"><span class="text-muted d-block">Duration</span><strong id="infoLastConnected" class="text-dark">-</strong></div>
                        <div class="col-md-2 col-6"><span class="text-muted d-block">Caller</span><strong id="infoCallBy" class="text-dark">-</strong></div>
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label" for="editName">Customer Name</label>
                        <input class="form-control" name="MAINDATABASE_NAME" id="editName" placeholder="Full name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="editMobile">Mobile Number</label>
                        <input class="form-control" name="MAINDATABASE_MOBILE" id="editMobile" placeholder="10-digit mobile">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="editCompany">Company / Employer</label>
                        <input class="form-control" name="MAINDATABASE_COMPANY" id="editCompany" placeholder="Company name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="editPackage">Package / Salary</label>
                        <input class="form-control" name="MAINDATABASE_PACKAGE" id="editPackage" placeholder="Package or income">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="editOther">Other Info / Notes</label>
                        <textarea class="form-control" name="MAINDATABASE_OTHER_INFO" id="editOther" rows="2" placeholder="Additional details"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="editStatus">Call Status / Disposition</label>
                        <select class="form-select" name="MAINDATABASE_CALL_DIALED_STATUS" id="editStatus">
                            <option>Not Called</option>
                            <option>Dialed</option>
                            <option>Connected</option>
                            <option>Busy</option>
                            <option>No Answer</option>
                            <option>Do Not Call</option>
                            <option>Pending</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="editAssigned">Assigned Telecaller</label>
                        <select class="form-select" name="MAINDATABASE_CALL_DIALED_USER" id="editAssigned">
                            <option value="">-- Unassigned --</option>
                            <?php foreach($telecallers as $tid => $tname): ?>
                                <option value="<?= $tid ?>"><?= htmlspecialchars($tname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mt-2 text-danger fs-xs" id="editError" style="display:none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

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
function showNotification(title, message, type = 'success') {
    const iconMap = {
        success: '<i class="bi bi-check-circle-fill text-success"></i>',
        danger: '<i class="bi bi-x-circle-fill text-danger"></i>',
        warning: '<i class="bi bi-exclamation-triangle-fill text-warning"></i>',
        info: '<i class="bi bi-info-circle-fill text-info"></i>'
    };
    const icon = iconMap[type] || iconMap.info;
    $('#notifIcon').html(icon);
    $('#notifTitle').text(title);
    $('#notifMessage').text(message || '');
    new bootstrap.Modal(document.getElementById('notificationModal')).show();
}

const APP_BASE = <?= json_encode(APP_BASE) ?>;
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

$(document).ready(function() {
    const table = $('#mainTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        destroy: true,
        searchDelay: 400,
        ajax: {
            url: APP_BASE + '/modules/database/maindatabase_ajax/datatable',
            type: 'GET',
            dataSrc: function (json) {
                if (json === null || typeof json !== 'object' || !json.data) {
                    console.error('DataTables: invalid response from server', json);
                    throw new Error('Invalid server response. Check that the API endpoint is reachable.');
                }
                return json.data;
            }
        },
        pageLength: 250,
        lengthMenu: [[100, 250, 500, 1000], [100, 250, 500, 1000]],
        order: [[7, 'desc']],
        dom: '<"p-2 d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom"<"d-flex align-items-center gap-2"lB><"ms-auto"f>>rt<"p-2 d-flex flex-wrap justify-content-between align-items-center gap-2 border-top"ip>',
        buttons: [
            { extend: 'copy', text: '<i class="bi bi-copy me-1"></i> Copy', className: 'btn btn-secondary btn-sm' },
            { extend: 'csv', text: '<i class="bi bi-file-earmark-spreadsheet me-1"></i> CSV', className: 'btn btn-secondary btn-sm', title: 'MainDatabase_Export_' + new Date().toISOString().slice(0,10) },
            { extend: 'excel', text: '<i class="bi bi-file-excel me-1"></i> Excel', className: 'btn btn-secondary btn-sm' },
            {
                text: '<i class="bi bi-cloud-download me-1"></i> Export Full DB',
                className: 'btn btn-primary btn-sm',
                action: function () {
                    $.get(APP_BASE + '/modules/database/maindatabase_ajax/get_total_count', function (total) {
                        total = parseInt(total);
                        if (total === 0) return showNotification('Empty', 'No data found', 'info');
            
                        if (total > 100000 && !confirm(`Notice: ${total.toLocaleString()} records.\nThis will download in multiple CSV parts.\n\nContinue?`)) {
                            return;
                        }
            
                        const win = window.open(APP_BASE + '/modules/database/maindatabase_ajax/download_bach', '_blank');
                        if (win) {
                            showNotification('Export Started', `${total.toLocaleString()} records downloading`, 'success');
                        } else {
                            showNotification('Popup Blocked!', 'Please allow popups', 'danger');
                        }
                    });
                }
            }
        ],
        columnDefs: [
            { orderable: false, targets: 0, className: 'text-center' },
            { visible: false, targets: 8 },
            { width: '120px', targets: 1 },
            {
                targets: 5,
                render: function(data) {
                    const badge = {
                        'Not Called': 'badge-secondary',
                        'Dialed': 'badge-info',
                        'Connected': 'badge-success',
                        'Busy': 'badge-warning',
                        'No Answer': 'badge-warning',
                        'Do Not Call': 'badge-danger',
                        'Pending': 'badge-primary'
                    }[data] || 'badge-secondary';
                    return `<span class="badge ${badge}">${data || 'Not Called'}</span>`;
                }
            }
        ],
        language: {
            processing: "<div class='spinner-border spinner-border-sm text-primary' role='status'></div> Processing...",
            lengthMenu: "Show _MENU_",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            paginate: {
                next: '<i class="bi bi-chevron-right"></i>',
                previous: '<i class="bi bi-chevron-left"></i>'
            }
        }
    });

    $('#selectAll').on('click', function() {
        const checked = this.checked;
        table.rows({ page: 'current' }).nodes().to$().find('input[type="checkbox"]').prop('checked', checked);
        updateSelectedCount();
    });

    $('#mainTable tbody').on('change', 'input[type="checkbox"]', updateSelectedCount);

    function updateSelectedCount() {
        const count = $('#mainTable tbody input[type="checkbox"]:checked').length;
        $('#selectedCount').text(count);
        $('#modalSelectedCount').text(count);
    }

    $('#doAssign').on('click', function() {
        const userId = $('#assignUser').val();
        if (!userId) return showNotification('Required', 'Please select a telecaller', 'warning');

        const ids = [];
        $('#mainTable tbody input[type="checkbox"]:checked').each(function() {
            const row = table.row($(this).closest('tr')).data();
            if (row && row[8]) ids.push(row[8]);
        });

        if (ids.length === 0) return showNotification('Warning', 'No records selected', 'warning');

        $.post(APP_BASE + '/modules/database/maindatabase_ajax/bulk_assign', { ids: ids, user_id: userId, csrf_token: CSRF_TOKEN }, function(res) {
            if (res.success) {
                table.ajax.reload();
                $('#assignModal').modal('hide');
                showNotification('Success', `${ids.length} leads assigned successfully`, 'success');
                $('#selectAll').prop('checked', false);
                updateSelectedCount();
            } else {
                showNotification('Error', res.message || 'Assignment failed', 'danger');
            }
        }, 'json');
    });

    window.bulkDelete = function() {
        const ids = [];
        $('#mainTable tbody input[type="checkbox"]:checked').each(function() {
            const row = table.row($(this).closest('tr')).data();
            if (row && row[8]) ids.push(row[8]);
        });

        if (ids.length === 0) return showNotification('Warning', 'No records selected', 'warning');
        if (!confirm(`Delete ${ids.length} selected records permanently?`)) return;

        $.post(APP_BASE + '/modules/database/maindatabase_ajax/bulk_delete', { ids: ids, csrf_token: CSRF_TOKEN }, function(res) {
            if (res.success) {
                table.ajax.reload();
                showNotification('Deleted', `${ids.length} records removed`, 'danger');
                $('#selectAll').prop('checked', false);
                updateSelectedCount();
            } else {
                showNotification('Error', 'Delete operation failed', 'danger');
            }
        }, 'json');
    };

    function loadEditModal(id) {
        $.post(location.href, { action:'view', id: id, csrf_token: CSRF_TOKEN }, function(resp){
            if (!resp.row) return showNotification('Error', 'Record not found', 'danger');
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
                showNotification('Updated', 'Record saved successfully', 'success');
            } else {
                $('#editError').show().text(resp.error || 'Update failed');
            }
        }, 'json').fail(function(){ $('#editError').show().text('Server error occurred'); });
    });

    $('#bulkDeleteBtn').on('click', function() {
        window.bulkDelete();
    });

    $('#refreshBtn').on('click', function() {
        table.ajax.reload();
        showNotification('Refreshed', 'Table data updated', 'info');
    });
});
</script>

<?php include '../../php_scripts/footer.php'; ?>
