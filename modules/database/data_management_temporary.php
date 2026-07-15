<?php
// temporary_manage.php - TEMPORARY DATABASE MANAGER (REWRITTEN WITH DATATABLES)
require_once __DIR__ . '/../../php_scripts/auth.php';      // creates $link, user auth, session
require_once __DIR__ . '/../../config.php';     // CRITICAL: establishes database connection ($link)

/*
 Temporary Database Manager
 - DataTables server-side processing (like main database)
 - Per page default 1000
 - Actions: fetch, view, update, delete, delete_selected, delete_all, transfer_selected, export_csv, fetch_activity
 - Activity logging into activity_log (existing schema)
*/

// ---------- CONFIG ----------
$DEFAULT_PER_PAGE = 1000;
$MAX_PER_PAGE = 1000;
$EXPORT_MAX = 20000; // safe limit
// ----------------------------

function respond_json($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* Determine current user id for logs - try multiple fallbacks */
$current_user_id = 0;
if (defined('USER_ID')) $current_user_id = USER_ID;
elseif (!empty($_SESSION['user_id'])) $current_user_id = intval($_SESSION['user_id']);
elseif (!empty($GLOBALS['USER_ID'])) $current_user_id = intval($GLOBALS['USER_ID']);

/* validate simple date */
function valid_date($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }

/* ---------- AJAX handlers ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        respond_json(['error' => 'Invalid CSRF token']);
    }
    $action = $_POST['action'];

    // ---------------- VIEW
    if ($action === 'view' && !empty($_POST['id'])) {
        $id = intval($_POST['id']);
        $stmt = mysqli_prepare($link, "SELECT * FROM temporary_database WHERE ID = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        respond_json(['row' => $row]);
    }

    // ---------------- UPDATE
    if ($action === 'update' && !empty($_POST['id'])) {
        if (!isAdmin() && !isManager()) {
            respond_json(['error' => 'Admin or Manager access required']);
        }
        $id = intval($_POST['id']);
        $name = isset($_POST['CUST_NAME']) ? trim($_POST['CUST_NAME']) : null;
        $mobile = isset($_POST['CUST_MOBILE']) ? preg_replace('/\D/','', $_POST['CUST_MOBILE']) : null;
        $company = isset($_POST['CUST_COMPANY']) ? trim($_POST['CUST_COMPANY']) : null;
        $package = isset($_POST['CUST_PACKAGE']) ? trim($_POST['CUST_PACKAGE']) : null;
        $other = isset($_POST['CUST_OTHER_INFO']) ? trim($_POST['CUST_OTHER_INFO']) : null;
        $status = isset($_POST['CALL_DIALED_STATUS']) ? trim($_POST['CALL_DIALED_STATUS']) : null;
        $tele = isset($_POST['CALL_DIALED_TELECALLER']) && $_POST['CALL_DIALED_TELECALLER'] !== '' ? intval($_POST['CALL_DIALED_TELECALLER']) : null;
        $last_dialed = isset($_POST['LAST_DIALED_DATE_TIME']) && $_POST['LAST_DIALED_DATE_TIME'] !== '' ? $_POST['LAST_DIALED_DATE_TIME'] : null;
        $last_conn = isset($_POST['LAST_CONNECTED_PERIOD']) ? trim($_POST['LAST_CONNECTED_PERIOD']) : null;

        if ($mobile === null || !preg_match('/^[6-9]\d{9}$/', substr($mobile, -10))) {
            respond_json(['error' => 'Invalid mobile (Indian 10 digits required).']);
        }
        $mobile = substr($mobile, -10);

        $sql = "UPDATE temporary_database SET CUST_NAME = ?, CUST_MOBILE = ?, CUST_COMPANY = ?, CUST_PACKAGE = ?, CUST_OTHER_INFO = ?, CALL_DIALED_STATUS = ?, CALL_DIALED_TELECALLER = ?, LAST_DIALED_DATE_TIME = ?, LAST_CONNECTED_PERIOD = ? WHERE ID = ?";
        $stmt = mysqli_prepare($link, $sql);
        mysqli_stmt_bind_param($stmt, "ssssssissi", $name, $mobile, $company, $package, $other, $status, $tele, $last_dialed, $last_conn, $id);
        $ok = mysqli_stmt_execute($stmt);
        if ($ok) {
            activity_log($link, $current_user_id, 'UPDATE', "Updated row $id", (string)$id, 'temporary_database');
            respond_json(['success' => true, 'message' => 'Row updated']);
        } else {
            respond_json(['error' => 'Update failed: ' . mysqli_stmt_error($stmt)]);
        }
    }

    // ---------------- DELETE single
    if ($action === 'delete' && !empty($_POST['id'])) {
        if (!isAdmin() && !isManager()) {
            respond_json(['error' => 'Admin or Manager access required']);
        }
        $id = intval($_POST['id']);
        $stmt = mysqli_prepare($link, "DELETE FROM temporary_database WHERE ID = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        $ok = mysqli_stmt_execute($stmt);
        if ($ok) {
            activity_log($link, $current_user_id, 'DELETE', "Deleted row $id", (string)$id, 'temporary_database');
            respond_json(['success' => true]);
        } else {
            respond_json(['error' => 'Delete failed: ' . mysqli_stmt_error($stmt)]);
        }
    }

    // ---------------- DELETE SELECTED
    if ($action === 'delete_selected' && !empty($_POST['ids']) && is_array($_POST['ids'])) {
        if (!isAdmin() && !isManager()) {
            respond_json(['error' => 'Admin or Manager access required']);
        }
        $ids = array_map('intval', $_POST['ids']);
        if (!count($ids)) respond_json(['error' => 'No IDs provided']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "DELETE FROM temporary_database WHERE ID IN ($placeholders)";
        $stmt = mysqli_prepare($link, $sql);
        $bind_names = array_merge([$types], $ids);
        $refs = [];
        foreach ($bind_names as $k => $v) $refs[$k] = &$bind_names[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $ok = mysqli_stmt_execute($stmt);
        if ($ok) {
            activity_log($link, $current_user_id, 'BULK_DELETE', 'Deleted selected rows', implode(',', $ids), 'temporary_database');
            respond_json(['success' => true, 'affected' => mysqli_stmt_affected_rows($stmt)]);
        } else {
            respond_json(['error' => 'Delete selected failed: ' . mysqli_stmt_error($stmt)]);
        }
    }

    // ---------------- DELETE ALL (confirm)
    if ($action === 'delete_all') {
        if (!isAdmin()) {
            respond_json(['error' => 'Admin access required']);
        }
        $confirm = $_POST['confirm'] ?? '';
        if ($confirm !== 'YES_DELETE_ALL') respond_json(['error' => 'Operation not confirmed.']);
        $ok = mysqli_query($link, "TRUNCATE TABLE temporary_database");
        if ($ok) {
            activity_log($link, $current_user_id, 'BULK_DELETE', 'Truncated temporary_database', 'ALL', 'temporary_database');
            respond_json(['success' => true]);
        } else respond_json(['error' => 'Truncate failed: ' . mysqli_error($link)]);
    }

    // ---------------- TRANSFER SELECTED
    if ($action === 'transfer_selected' && !empty($_POST['ids']) && is_array($_POST['ids'])) {
        if (!isAdmin() && !isManager()) {
            respond_json(['error' => 'Admin or Manager access required']);
        }
        $ids = array_map('intval', $_POST['ids']);
        if (!count($ids)) respond_json(['error' => 'No IDs provided']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "INSERT INTO main_database (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD, MAINDATABASE_UPLOAD_DATETIME)
                SELECT CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD, NOW()
                FROM temporary_database WHERE ID IN ($placeholders)
                ON DUPLICATE KEY UPDATE ID = ID";
        $stmt = mysqli_prepare($link, $sql);
        if (!$stmt) respond_json(['error' => 'Prepare failed: ' . mysqli_error($link)]);
        $types = str_repeat('i', count($ids));
        $bind_names = array_merge([$types], $ids);
        $refs = [];
        foreach ($bind_names as $k => $v) $refs[$k] = &$bind_names[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $ok = mysqli_stmt_execute($stmt);
        if ($ok) {
            $affected = mysqli_stmt_affected_rows($stmt);
            activity_log($link, $current_user_id, 'TRANSFER', 'Transferred to MAIN_DATABASE', implode(',', $ids), 'temporary_database');
            respond_json(['success' => true, 'affected' => $affected]);
        } else {
            respond_json(['error' => 'Transfer failed: ' . mysqli_stmt_error($stmt)]);
        }
    }

    // ---------------- EXPORT CSV
    if ($action === 'export_csv') {
        $limit = min(intval($_POST['limit'] ?? $EXPORT_MAX), $EXPORT_MAX);

        $where = [];
        $types = '';
        $params = [];
        $q = trim($_POST['q'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $telecaller = isset($_POST['telecaller']) && $_POST['telecaller'] !== '' ? intval($_POST['telecaller']) : null;
        $from = $_POST['from'] ?? null;
        $to = $_POST['to'] ?? null;

        if ($q !== '') { $where[] = "(CUST_NAME LIKE CONCAT('%',?,'%') OR CUST_MOBILE LIKE CONCAT('%',?,'%') OR CUST_COMPANY LIKE CONCAT('%',?,'%'))"; $types .= 'sss'; $params = array_merge($params, [$q,$q,$q]); }
        if ($status !== '') { $where[] = "CALL_DIALED_STATUS = ?"; $types .= 's'; $params[] = $status; }
        if ($telecaller !== null) { $where[] = "CALL_DIALED_TELECALLER = ?"; $types .= 'i'; $params[] = $telecaller; }
        if (!empty($from) && valid_date($from)) { $where[] = "DATE(TEMP_UPLOAD_DATETIME) >= ?"; $types .= 's'; $params[] = $from; }
        if (!empty($to) && valid_date($to)) { $where[] = "DATE(TEMP_UPLOAD_DATETIME) <= ?"; $types .= 's'; $params[] = $to; }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, TEMP_UPLOAD_DATETIME, CALL_DIALED_STATUS, CALL_DIALED_TELECALLER, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD
                FROM temporary_database $where_sql ORDER BY TEMP_UPLOAD_DATETIME DESC LIMIT ?";

        $stmt = mysqli_prepare($link, $sql);
        if (!$stmt) respond_json(['error' => 'Prepare failed: ' . mysqli_error($link)]);

        if ($types === '') mysqli_stmt_bind_param($stmt, "i", $limit);
        else {
            $full_types = $types . 'i';
            $bind_vals = array_merge($params, [$limit]);
            $bind_names = array_merge([$full_types], $bind_vals);
            $refs = [];
            foreach ($bind_names as $k => $v) $refs[$k] = &$bind_names[$k];
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="temporary_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Name','Mobile','Company','Package','Other Info','Upload Time','Status','Telecaller','Last Dialed','Last Connected']);
        while ($r = mysqli_fetch_assoc($res)) {
            fputcsv($out, [
                $r['ID'],
                $r['CUST_NAME'],
                $r['CUST_MOBILE'],
                $r['CUST_COMPANY'],
                $r['CUST_PACKAGE'],
                $r['CUST_OTHER_INFO'],
                $r['TEMP_UPLOAD_DATETIME'],
                $r['CALL_DIALED_STATUS'],
                $r['CALL_DIALED_TELECALLER'],
                $r['LAST_DIALED_DATE_TIME'],
                $r['LAST_CONNECTED_PERIOD']
            ]);
        }
        fclose($out);
        exit;
    }

    // ---------------- FETCH ACTIVITY (latest 200)
    if ($action === 'fetch_activity') {
        $res = mysqli_query($link, "SELECT LOG_ID, USER_ID, ACTION_TYPE, ACTION_DETAILS, AFFECTED_IDS, TARGET_TABLE, LOG_TIME, IP_ADDRESS FROM ACTIVITY_LOG ORDER BY LOG_TIME DESC LIMIT 200");
        $arr = [];
        while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;
        respond_json(['activities' => $arr]);
    }

    // unknown
    respond_json(['error' => 'Unknown action']);
}

/* ---------- Render UI ---------- */
$pageTitle = 'Temporary Database';
include __DIR__ . '/../../php_scripts/header.php';
?>

<div class="container py-3" style="max-width:1200px">

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div>
      <h5 class="mb-0">Temporary Database Management</h5>
      <div class="text-muted small">Fast, compact view — default 1000 rows per page</div>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <button id="activityBtn" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#activityModal"><i class="bi bi-list-check"></i> Activity</button>
    </div>
  </div>

<!-- ====== ACTION ROW + FILTER ROW (2-ROW clean layout) ====== -->
<div class="card mb-3 p-2">

  <!-- ROW 1: ACTION BUTTONS -->
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <div class="btn-group" role="group">
      <a href="add_single_number.php" class="btn btn-success btn-sm" disabled>
          <i class="bi bi-upload"></i> Upload Single Data
      </a>
      <button id="deleteSelected" class="btn btn-danger btn-sm" disabled>
        <i class="bi bi-trash"></i> Delete Selected
      </button>
      <button id="transferSelected" class="btn btn-success btn-sm" disabled>
        <i class="bi bi-arrow-right-circle"></i> Transfer Selected
      </button>
      <button id="deleteAllBtn" class="btn btn-outline-danger btn-sm">
        <i class="bi bi-x-circle"></i> Delete All
      </button>
    </div>

    <button id="refreshBtn" class="btn btn-outline-secondary btn-sm ms-auto">
      <i class="bi bi-arrow-clockwise"></i> Refresh
    </button>

    <button id="exportBtn" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-download"></i> Export CSV
    </button>
  </div>

  <!-- ROW 2: FILTERS -->
  <div class="d-flex flex-wrap align-items-center gap-2">

    <input id="searchQ" class="form-control form-control-sm" 
           placeholder="Search name / mobile / company / package"
           style="flex:1; min-width:220px;">

    <select id="status" class="form-select form-select-sm" style="width:150px;">
      <option value="">All Status</option>
      <option>Not Called</option>
      <option>Dialed</option>
      <option>Connected</option>
      <option>Busy</option>
      <option>No Answer</option>
      <option>Do Not Call</option>
      <option>Pending</option>
    </select>

    <input type="date" id="from" class="form-control form-control-sm" style="width:150px;">
    <input type="date" id="to" class="form-control form-control-sm" style="width:150px;">

    <select id="perPage" class="form-select form-select-sm" style="width:110px;">
      <option value="1000">1000 / page</option>
      <option value="500">500 / page</option>
      <option value="250">250 / page</option>
    </select>
  </div>

</div>
<!-- ====== END OF 2-ROW BLOCK ====== -->

  <!-- DataTable -->
  <div class="card">
    <table id="tempTable" class="table table-hover table-striped table-sm" style="width:100%">
        <thead class="table-dark">
            <tr>
                <th><input type="checkbox" id="selectAll"></th>
                <th>ID</th>
                <th>Mobile</th>
                <th>Name</th>
                <th>Company</th>
                <th>Package</th>
                <th>Status</th>
                <th>Uploaded</th>
                <th>Actions</th>
            </tr>
        </thead>
    </table>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form id="editForm" class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Edit row</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="editId" name="id">
        <div class="row g-2">
          <div class="col-md-6"><input class="form-control form-control-sm" name="CUST_NAME" id="editName" placeholder="Name"></div>
          <div class="col-md-6"><input class="form-control form-control-sm" name="CUST_MOBILE" id="editMobile" placeholder="Mobile"></div>
          <div class="col-md-6"><input class="form-control form-control-sm" name="CUST_COMPANY" id="editCompany" placeholder="Company"></div>
          <div class="col-md-6"><input class="form-control form-control-sm" name="CUST_PACKAGE" id="editPackage" placeholder="Package"></div>
          <div class="col-12"><textarea class="form-control form-control-sm" name="CUST_OTHER_INFO" id="editOther" rows="2" placeholder="Other"></textarea></div>
          <div class="col-md-6"><select class="form-select form-select-sm" name="CALL_DIALED_STATUS" id="editStatus"><option>Not Called</option><option>Dialed</option><option>Connected</option><option>Busy</option><option>No Answer</option><option>Do Not Call</option><option>Pending</option></select></div>
          <div class="col-md-6"><input class="form-control form-control-sm" name="CALL_DIALED_TELECALLER" id="editTelecaller" placeholder="Telecaller ID"></div>
        </div>
        <div class="mt-2 text-danger small" id="editError" style="display:none"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary btn-sm">Save</button></div>
    </form>
  </div>
</div>

<!-- Activity Modal -->
<div class="modal fade" id="activityModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Activity Log (latest 200)</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div id="activityList" style="max-height:60vh;overflow:auto;font-size:13px">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/3.0.2/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.print.min.js"></script>

<script>
const TEMP_URL = (window.APP_BASE || '') + '/modules/database/temporarydatabase_ajax/datatable.php';
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

$(document).ready(function() {
    
    const table = $('#tempTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        ajax: {
            url: TEMP_URL,
            type: 'GET'
        },
        pageLength: 2500,
        lengthMenu: [ 2500, 5000, 10000, 15000, 20000, [10000, -1], [10000, 'All'] ],
        order: [[6, 'desc']],
        dom: '<"row"<"col-sm-12 col-md-4"l><"col-sm-12 col-md-4 text-center"B><"col-sm-12 col-md-4"f>>rtip',
        buttons: [
            { extend: 'copy', text: '<i class="bi bi-copy"></i> Copy', className: 'btn btn-outline-secondary btn-sm text-white' },
            { extend: 'csv', text: '<i class="bi bi-file-earmark-spreadsheet"></i> CSV', className: 'btn btn-success btn-sm', title: 'TemporaryDatabase_Export_' + new Date().toISOString().slice(0,10) },
            { extend: 'excel', text: '<i class="bi bi-file-excel"></i> Excel', className: 'btn btn-info btn-sm' },
            { text: '<i class="bi bi-trash3"></i> Delete Selected', className: 'btn btn-danger btn-sm', action: function() { bulkDelete(); }},
            {
                text: '<i class="bi bi-cloud-download"></i> Export Full DB (in Parts)',
                className: 'btn btn-primary btn-sm shadow-sm fw-bold',
                action: function () {
                    $.get((window.APP_BASE || '') + '/modules/database/temporarydatabase_ajax/get_total_count.php', function (total) {
                        total = parseInt(total);
                        if (total === 0) return showToast('Empty', 'No data found', 'info');
        
                        if (total > 100000 && !confirm(`Warning: ${total.toLocaleString()} records!\n\nThis will download in multiple large CSV files.\n\nContinue?`)) {
                            return;
                        }
        
                        const win = window.open((window.APP_BASE || '') + '/modules/database/temporarydatabase_ajax/download_bach.php', '_blank');
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

    $('#tempTable tbody').on('change', 'input[type="checkbox"]', updateSelectedCount);

    function updateSelectedCount() {
        const count = $('#tempTable input[type="checkbox"]:checked').length - ($('#selectAll').is(':checked') ? 1 : 0);
        $('#selectedCount').text(count);
    }

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
        $('.toast').last()[0].show();
        setTimeout(() => $('.toast').last().remove(), 5000);
    }

    // Bulk Assign
    $('#doAssign').on('click', function() {
        const userId = $('#assignUser').val();
        if (!userId) return showToast('Error', 'Please select a user', 'danger');

        const ids = [];
        $('#tempTable input[type="checkbox"]:checked').each(function() {
            if (!$(this).is('#selectAll')) {
                const row = table.row($(this).closest('tr')).data();
                ids.push(row[0]);
            }
        });

        if (ids.length === 0) return showToast('Warning', 'No records selected', 'warning');

        $.post((window.APP_BASE || '') + '/modules/database/temporarydatabase_ajax/bulk_assign.php', { ids: ids, user_id: userId }, function(res) {
            if (res.success) {
                table.ajax.reload();
                showToast('Success!', `${ids.length} records assigned successfully`, 'success');
            } else {
                showToast('Error', res.message || 'Failed', 'danger');
            }
        }, 'json');
    });

    // Bulk Delete
    window.bulkDelete = function() {
        const ids = [];
        $('#tempTable input[type="checkbox"]:checked').each(function() {
            if (!$(this).is('#selectAll')) {
                const row = table.row($(this).closest('tr')).data();
                ids.push(row[0]);
            }
        });
        if (!confirm('Delete selected records permanently?')) return;
        $.post((window.APP_BASE || '') + '/modules/database/temporarydatabase_ajax/bulk_delete.php', { ids: ids }, function(res) {
            if (res.success) {
                table.ajax.reload();
                showToast('Deleted!', `${ids.length} records removed`, 'danger');
            }
        }, 'json');
    };

    // Edit flow
    $(document).on('click', '.btn-edit', function(){
        const id = $(this).data('id');
        $.post(location.href, { action:'view', id: id, csrf_token: CSRF_TOKEN }, function(resp){
            if (!resp.row) return alert('Row not found');
            const r = resp.row;
            $('#editId').val(r.ID);
            $('#editName').val(r.CUST_NAME);
            $('#editMobile').val(r.CUST_MOBILE);
            $('#editCompany').val(r.CUST_COMPANY);
            $('#editPackage').val(r.CUST_PACKAGE);
            $('#editOther').val(r.CUST_OTHER_INFO);
            $('#editStatus').val(r.CALL_DIALED_STATUS);
            $('#editTelecaller').val(r.CALL_DIALED_TELECALLER);
            $('#editError').hide().text('');
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }, 'json');
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
            } else {
                $('#editError').show().text(resp.error || 'Update failed');
            }
        }, 'json').fail(function(){ $('#editError').show().text('Server error'); });
    });

    // Delete single
    $(document).on('click', '.btn-del', function(){
        const id = $(this).data('id');
        if (!confirm('Delete row ' + id + '?')) return;
        $.post(location.href, { action:'delete', id: id, csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.success) { table.ajax.reload(); alert('Deleted'); }
            else alert('Error: ' + (resp.error || 'unknown'));
        }, 'json');
    });

    // Delete selected
    $('#deleteSelected').on('click', function(){
        if (!confirm('Delete ' + selected.size + ' selected rows?')) return;
        $.post(location.href, { action:'delete_selected', ids: Array.from(selected), csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.success) { alert('Deleted ' + (resp.affected || selected.size)); table.ajax.reload(); }
            else alert('Error: ' + (resp.error || 'unknown'));
        }, 'json');
    });

    // Transfer selected
    $('#transferSelected').on('click', function(){
        if (!confirm('Transfer ' + selected.size + ' selected rows to MAIN_DATABASE?')) return;
        $.post(location.href, { action:'transfer_selected', ids: Array.from(selected), csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.success) { alert('Transferred (affected: ' + (resp.affected || 'unknown') + ')'); table.ajax.reload(); }
            else alert('Error: ' + (resp.error || 'unknown'));
        }, 'json');
    });

    // Delete all
    $('#deleteAllBtn').on('click', function(){
        if (!confirm('This will DELETE ALL rows in TEMPORARY_DATABASE - are you sure?')) return;
        const token = prompt('Type YES_DELETE_ALL to confirm');
        if (token !== 'YES_DELETE_ALL') return alert('Not confirmed');
        $.post(location.href, { action:'delete_all', confirm: token, csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.success) { alert('All deleted'); table.ajax.reload(); }
            else alert('Error: ' + (resp.error || 'unknown'));
        }, 'json');
    });

    // Export CSV
    $('#exportBtn').on('click', function(){
        const form = $('<form method="post" action="' + location.href + '"></form>');
        form.append('<input type="hidden" name="action" value="export_csv">');
        form.append('<input type="hidden" name="q" value="' + encodeURIComponent($('#searchQ').val().trim()) + '">');
        form.append('<input type="hidden" name="status" value="' + encodeURIComponent($('#status').val()) + '">');
        form.append('<input type="hidden" name="from" value="' + encodeURIComponent($('#from').val()) + '">');
        form.append('<input type="hidden" name="to" value="' + encodeURIComponent($('#to').val()) + '">');
        form.append('<input type="hidden" name="limit" value="20000">');
        form.append('<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">');
        form.appendTo('body').submit().remove();
    });

    // Activity modal load
    $('#activityModal').on('show.bs.modal', function(){
        $('#activityList').html('<div class="small text-muted">Loading…</div>');
        $.post(location.href, { action:'fetch_activity', csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.error) { $('#activityList').html('<div class="text-danger small">' + resp.error + '</div>'); return; }
            let out = '<div class="list-group">';
            for (const a of resp.activities || []) {
                out += `<div class="list-group-item small">
                  <div><strong>${escapeHtml(a.ACTION_TYPE)}</strong> — <span class="text-muted">${escapeHtml(a.LOG_TIME)}</span></div>
                  <div class="text-muted small">User: ${escapeHtml(a.USER_ID)} | IP: ${escapeHtml(a.IP_ADDRESS)} | Target: ${escapeHtml(a.TARGET_TABLE)}</div>
                  <div class="mt-1">${escapeHtml(a.ACTION_DETAILS)} <span class="text-muted">[IDs: ${escapeHtml(a.AFFECTED_IDS||'')}]</span></div>
                </div>`;
            }
            out += '</div>';
            $('#activityList').html(out);
        }, 'json');
    });

    // Helper
    function escapeHtml(s){ if(s===null||s===undefined) return ''; return $('<div>').text(s).html(); }

    // Debounced search
    let qTimer = null;
    $('#searchQ').on('input', function(){
        clearTimeout(qTimer);
        qTimer = setTimeout(function(){ table.ajax.reload(); }, 300);
    });
    $('#status, #from, #to').on('change', function(){ table.ajax.reload(); });
    $('#perPage').on('change', function(){ table.page.len(parseInt($(this).val())).draw(); });

});
</script>

</body>
</html>