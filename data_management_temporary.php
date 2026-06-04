<?php
// temporary_manage.php
require_once 'php_scripts/auth.php';   // must create $link (mysqli) and session/auth
require_once 'php_scripts/team_auth.php'; // optional

ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
 Compact temporary DB manager
 - Keyset pagination (fast)
 - Per page default 1000
 - Actions: fetch, view, update, delete, delete_selected, delete_all, transfer_selected, export_csv, fetch_activity
 - Activity logging into ACTIVITY_LOG (existing schema)
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

// activity logger using your ACTIVITY_LOG table
function activity_log($link, $user_id, $action_type, $details = '', $affected_ids = '', $target_table = 'TEMPORARY_DATABASE') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $sql = "INSERT INTO ACTIVITY_LOG (USER_ID, ACTION_TYPE, ACTION_DETAILS, AFFECTED_IDS, TARGET_TABLE, IP_ADDRESS)
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($link, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "isssss", $user_id, $action_type, $details, $affected_ids, $target_table, $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return true;
    } else {
        // logging failed; don't break the flow
        return false;
    }
}

/* validate simple date */
function valid_date($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }

/* ---------- AJAX handlers ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ---------------- FETCH (keyset pagination)
// ---------------- FETCH (keyset pagination – safe version)
// ---------------- FETCH (robust, defensive)
if ($action === 'fetch') {
    
    mysqli_set_charset($link, 'utf8mb4');
mysqli_query($link, "SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
    
    // sanitize per-page
    $per_page = isset($_POST['per_page']) ? (int)$_POST['per_page'] : $DEFAULT_PER_PAGE;
    if ($per_page < 1) $per_page = $DEFAULT_PER_PAGE;
    if ($per_page > $MAX_PER_PAGE) $per_page = $MAX_PER_PAGE;

    $last_dt = $_POST['last_dt'] ?? null;
    $last_id = isset($_POST['last_id']) ? (int)$_POST['last_id'] : null;

    $q = trim((string)($_POST['q'] ?? ''));
    $status = trim((string)($_POST['status'] ?? ''));
    $telecaller = isset($_POST['telecaller']) && $_POST['telecaller'] !== '' ? (int)$_POST['telecaller'] : null;
    $from = $_POST['from'] ?? null;
    $to   = $_POST['to'] ?? null;

    $where_parts = [];
    $types = '';
    $params = [];

    if ($q !== '') {
        // use only 3 fields for export & faster search (you can expand to 5 if needed)
            $where_parts[] = "(
        CUST_NAME COLLATE utf8mb4_general_ci LIKE CONCAT('%',?,'%') COLLATE utf8mb4_general_ci
        OR CUST_MOBILE COLLATE utf8mb4_general_ci LIKE CONCAT('%',?,'%') COLLATE utf8mb4_general_ci
        OR CUST_COMPANY COLLATE utf8mb4_general_ci LIKE CONCAT('%',?,'%') COLLATE utf8mb4_general_ci
        OR CUST_PACKAGE COLLATE utf8mb4_general_ci LIKE CONCAT('%',?,'%') COLLATE utf8mb4_general_ci
        OR CUST_OTHER_INFO COLLATE utf8mb4_general_ci LIKE CONCAT('%',?,'%') COLLATE utf8mb4_general_ci
        )";
        $types .= str_repeat('s', 5);
        $params = array_merge($params, [$q, $q, $q, $q, $q]);
    }

    if ($status !== '') {
        $where_parts[] = "CALL_DIALED_STATUS = ?";
        $types .= 's';
        $params[] = $status;
    }

    if ($telecaller !== null) {
        $where_parts[] = "CALL_DIALED_TELECALLER = ?";
        $types .= 'i';
        $params[] = $telecaller;
    }

    if (!empty($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where_parts[] = "DATE(TEMP_UPLOAD_DATETIME) >= ?";
        $types .= 's';
        $params[] = $from;
    }
    if (!empty($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where_parts[] = "DATE(TEMP_UPLOAD_DATETIME) <= ?";
        $types .= 's';
        $params[] = $to;
    }

    if ($last_dt && $last_id) {
        $where_parts[] = "(TEMP_UPLOAD_DATETIME < ? OR (TEMP_UPLOAD_DATETIME = ? AND ID < ?))";
        $types .= 'ssi';
        $params[] = $last_dt;
        $params[] = $last_dt;
        $params[] = $last_id;
    }

    // final WHERE
    $where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

    $sql = "SELECT ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE,
                   CUST_OTHER_INFO, TEMP_UPLOAD_DATETIME, CALL_DIALED_STATUS,
                   CALL_DIALED_TELECALLER, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD
            FROM TEMPORARY_DATABASE
            $where_sql
            ORDER BY TEMP_UPLOAD_DATETIME DESC, ID DESC
            LIMIT ?";

    // add limit param
    $types_full = $types . 'i';
    $params[] = $per_page;

    $stmt = mysqli_prepare($link, $sql);
    if (!$stmt) {
        respond_json(['error' => 'DB prepare failed', 'debug' => mysqli_error($link)]);
    }

    // helper to bind dynamically and safely (creates references required by bind_param)
    $bind_params = array_merge([$types_full], $params);
    $refs = [];
    foreach ($bind_params as $k => $v) $refs[$k] = &$bind_params[$k];

    if (!call_user_func_array([$stmt, 'bind_param'], $refs)) {
        // binding failed
        respond_json(['error' => 'Bind failed', 'debug' => mysqli_stmt_error($stmt)]);
    }

    if (!mysqli_stmt_execute($stmt)) {
        respond_json(['error' => 'Execute failed', 'debug' => mysqli_stmt_error($stmt)]);
    }

    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($stmt);

    $next_dt = null; $next_id = null;
    if (!empty($rows)) {
        $lastrow = end($rows);
        $next_dt = $lastrow['TEMP_UPLOAD_DATETIME'];
        $next_id = (int)$lastrow['ID'];
    }

    respond_json(['data' => $rows, 'next_last_dt' => $next_dt, 'next_last_id' => $next_id, 'per_page' => $per_page]);
}



    // ---------------- VIEW
    if ($action === 'view' && !empty($_POST['id'])) {
        $id = intval($_POST['id']);
        $stmt = mysqli_prepare($link, "SELECT * FROM TEMPORARY_DATABASE WHERE ID = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        respond_json(['row' => $row]);
    }

    // ---------------- UPDATE
    if ($action === 'update' && !empty($_POST['id'])) {
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

        $sql = "UPDATE TEMPORARY_DATABASE SET CUST_NAME = ?, CUST_MOBILE = ?, CUST_COMPANY = ?, CUST_PACKAGE = ?, CUST_OTHER_INFO = ?, CALL_DIALED_STATUS = ?, CALL_DIALED_TELECALLER = ?, LAST_DIALED_DATE_TIME = ?, LAST_CONNECTED_PERIOD = ? WHERE ID = ?";
        $stmt = mysqli_prepare($link, $sql);
        mysqli_stmt_bind_param($stmt, "ssssssissi", $name, $mobile, $company, $package, $other, $status, $tele, $last_dialed, $last_conn, $id);
        $ok = mysqli_stmt_execute($stmt);
        if ($ok) {
            activity_log($link, $current_user_id, 'UPDATE_ROW', "Updated row $id", (string)$id, 'TEMPORARY_DATABASE');
            respond_json(['success' => true, 'message' => 'Row updated']);
        } else {
            respond_json(['error' => 'Update failed: ' . mysqli_stmt_error($stmt)]);
        }
    }

    // ---------------- DELETE single
    if ($action === 'delete' && !empty($_POST['id'])) {
        $id = intval($_POST['id']);
        $stmt = mysqli_prepare($link, "DELETE FROM TEMPORARY_DATABASE WHERE ID = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        $ok = mysqli_stmt_execute($stmt);
        if ($ok) {
            activity_log($link, $current_user_id, 'DELETE_ROW', "Deleted row $id", (string)$id, 'TEMPORARY_DATABASE');
            respond_json(['success' => true]);
        } else {
            respond_json(['error' => 'Delete failed: ' . mysqli_stmt_error($stmt)]);
        }
    }

    // ---------------- DELETE SELECTED
    if ($action === 'delete_selected' && !empty($_POST['ids']) && is_array($_POST['ids'])) {
        $ids = array_map('intval', $_POST['ids']);
        if (!count($ids)) respond_json(['error' => 'No IDs provided']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "DELETE FROM TEMPORARY_DATABASE WHERE ID IN ($placeholders)";
        $stmt = mysqli_prepare($link, $sql);
        $bind_names = array_merge([$types], $ids);
        $refs = [];
        foreach ($bind_names as $k => $v) $refs[$k] = &$bind_names[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $ok = mysqli_stmt_execute($stmt);
        if ($ok) {
            activity_log($link, $current_user_id, 'DELETE_SELECTED', 'Deleted selected rows', implode(',', $ids), 'TEMPORARY_DATABASE');
            respond_json(['success' => true, 'affected' => mysqli_stmt_affected_rows($stmt)]);
        } else {
            respond_json(['error' => 'Delete selected failed: ' . mysqli_stmt_error($stmt)]);
        }
    }

    // ---------------- DELETE ALL (confirm)
    if ($action === 'delete_all') {
        $confirm = $_POST['confirm'] ?? '';
        if ($confirm !== 'YES_DELETE_ALL') respond_json(['error' => 'Operation not confirmed.']);
        $ok = mysqli_query($link, "TRUNCATE TABLE TEMPORARY_DATABASE");
        if ($ok) {
            activity_log($link, $current_user_id, 'DELETE_ALL', 'Truncated TEMPORARY_DATABASE', 'ALL', 'TEMPORARY_DATABASE');
            respond_json(['success' => true]);
        } else respond_json(['error' => 'Truncate failed: ' . mysqli_error($link)]);
    }

    // ---------------- TRANSFER SELECTED
    if ($action === 'transfer_selected' && !empty($_POST['ids']) && is_array($_POST['ids'])) {
        $ids = array_map('intval', $_POST['ids']);
        if (!count($ids)) respond_json(['error' => 'No IDs provided']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "INSERT INTO MAIN_DATABASE (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD, MAINDATABASE_UPLOAD_DATETIME)
                SELECT CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD, NOW()
                FROM TEMPORARY_DATABASE WHERE ID IN ($placeholders)
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
            activity_log($link, $current_user_id, 'TRANSFER_SELECTED', 'Transferred to MAIN_DATABASE', implode(',', $ids), 'TEMPORARY_DATABASE');
            respond_json(['success' => true, 'affected' => $affected]);
        } else {
            respond_json(['error' => 'Transfer failed: ' . mysqli_stmt_error($stmt)]);
        }
    }

    // ---------------- EXPORT CSV
    if ($action === 'export_csv') {
        // reuse filters from fetch but return CSV stream
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
                FROM TEMPORARY_DATABASE $where_sql ORDER BY TEMP_UPLOAD_DATETIME DESC LIMIT ?";

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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Temporary Database — Manager</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{ --accent:#0b5cff; --muted:#6b7280; --row-h:34px; }
    body{background:#f4f8ff; font-size:13px;}
    .container-compact{max-width:1200px;margin:18px auto;}
    .card-compact{border-radius:10px;padding:10px;background:#fff;box-shadow:0 6px 18px rgba(11,92,255,0.04);}
    .filters .form-control, .filters .form-select{height:36px;padding:.25rem .5rem;font-size:.9rem;}
    .table-compact{--bs-table-cell-padding:.3rem .5rem;font-size:12px;}
    table thead th{position:sticky;top:0;background:linear-gradient(90deg,var(--accent),#2ea2ff);color:#fff;font-weight:600;font-size:12px;}
    .table-wrapper{overflow:auto;max-height:66vh;border-radius:8px;background:white;border:1px solid rgba(15,60,201,0.06);}
    table tbody tr{height:var(--row-h);}
    /* status colors */
    tr.status-Connected td{background:#e6ffea!important;}
    tr.status-Dialed td{background:#fff9e6!important;}
    tr.status-Busy td{background:#fff0f0!important;}
    tr.status-Pending td{background:#eef4ff!important;}
    tr.status-DoNotCall td{background:#ffecec!important;}
    tr.status-NoAnswer td{background:#fff7f0!important;}
    .btn-small{padding:.25rem .5rem;font-size:.82rem;}
    .spinner-inline{display:inline-block;width:18px;height:18px;border:2px solid rgba(0,0,0,0.08);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
    @media (max-width:768px){ .container-compact{padding:0 10px} }
  </style>
</head>
<body>
<?php include 'php_scripts/header.php'; ?>

<div class="container-compact">

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div>
      <h5 class="mb-0">Temporary Database Management</h5>
      <div class="text-muted small">Fast, compact view — default 1000 rows per page</div>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <button id="activityBtn" class="btn btn-outline-info btn-small" data-bs-toggle="modal" data-bs-target="#activityModal"><i class="bi bi-list-check"></i> Activity</button>
    </div>
  </div>

<!-- ====== ACTION ROW + FILTER ROW (2-ROW clean layout) ====== -->
<div class="card-compact mb-3 p-2">

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



  <!-- table -->
  <div class="card-compact">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="small text-muted" id="infoText">Loading…</div>
      <div><button id="loadMore" class="btn btn-sm btn-outline-secondary btn-small">Load more</button></div>
    </div>

    <div class="table-wrapper">
      <table class="table table-sm table-compact mb-0">
        <thead>
          <tr>
            <th style="width:36px"><input type="checkbox" id="selectAll"></th>
            <th style="width:60px">ID</th>
            <th>Name</th>
            <th style="width:120px">Mobile</th>
            <th>Company</th>
            <th style="width:100px">Package</th>
            <th>Other</th>
            <th style="width:120px">Status</th>
            <th style="width:160px">Upload Time</th>
            <th style="width:110px">Actions</th>
          </tr>
        </thead>
        <tbody id="tableBody"></tbody>
      </table>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-2">
      <div class="small text-muted" id="pagerSummary"></div>
      <div class="small text-muted">Showing <span id="rowsShown">0</span> rows</div>
    </div>
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
      <div class="modal-footer"><button type="button" class="btn btn-secondary btn-small" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary btn-small">Save</button></div>
    </form>
  </div>
</div>

<!-- Activity Modal -->
<div class="modal fade" id="activityModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Activity Log (latest 200)</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div id="activityList" style="max-height:60vh;overflow:auto;font-size:13px">Loading…</div></div>
      <div class="modal-footer"><button class="btn btn-secondary btn-small" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<?php include 'php_scripts/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(function(){
  const perPageDefault = parseInt($('#perPage').val());
  let perPage = perPageDefault;
  let next_last_dt = null, next_last_id = null;
  let rowsShown = 0;
  let selected = new Set();
  let loading = false;
  let qTimer = null;

  function setLoading(state) {
    loading = state;
    $('#refreshBtn').prop('disabled', state);
    $('#loadMore').prop('disabled', state);
    if (state) $('#infoText').html('<span class="spinner-inline"></span> Loading...');
    else $('#infoText').text('Loaded');
  }

  function renderRow(r) {
    const statusClass = 'status-' + (r.CALL_DIALED_STATUS ? r.CALL_DIALED_STATUS.replaceAll(' ','') : 'NotCalled');
    return `<tr data-id="${r.ID}" class="${statusClass}">
      <td><input type="checkbox" class="rowChk" data-id="${r.ID}"></td>
      <td>${r.ID}</td>
      <td>${escapeHtml(r.CUST_NAME)}</td>
      <td>${escapeHtml(r.CUST_MOBILE)}</td>
      <td>${escapeHtml(r.CUST_COMPANY)}</td>
      <td>${escapeHtml(r.CUST_PACKAGE)}</td>
      <td>${escapeHtml(r.CUST_OTHER_INFO)}</td>
      <td>${escapeHtml(r.CALL_DIALED_STATUS)}</td>
      <td>${escapeHtml(r.TEMP_UPLOAD_DATETIME)}</td>
      <td>
        <div class="btn-group">
          <button class="btn btn-sm btn-outline-primary btn-edit" data-id="${r.ID}"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-sm btn-outline-danger btn-del" data-id="${r.ID}"><i class="bi bi-trash"></i></button>
        </div>
      </td>
    </tr>`;
  }

  function escapeHtml(s){ if(s===null||s===undefined) return ''; return $('<div>').text(s).html(); }

  function resetTable() {
    $('#tableBody').empty();
    next_last_dt = null; next_last_id = null;
    rowsShown = 0;
    selected.clear();
    $('#rowsShown').text('0');
    $('#selectAll').prop('checked', false);
    $('#deleteSelected, #transferSelected').prop('disabled', true);
  }

  function loadMore() {
    if (loading) return;
    setLoading(true);
    perPage = parseInt($('#perPage').val());
    const data = {
      action: 'fetch',
      per_page: perPage,
      q: $('#searchQ').val().trim(),
      status: $('#status').val(),
      from: $('#from').val(),
      to: $('#to').val()
    };
    if (next_last_dt && next_last_id) {
      data.last_dt = next_last_dt;
      data.last_id = next_last_id;
    }
    $.post(location.href, data, function(resp){
      if (resp.error) { alert(resp.error); setLoading(false); return; }
      const rows = resp.data || [];
      for (const r of rows) $('#tableBody').append(renderRow(r));
      rowsShown += rows.length;
      $('#rowsShown').text(rowsShown);
      // update next cursor
      next_last_dt = resp.next_last_dt;
      next_last_id = resp.next_last_id;
      // if returned rows less than per_page => no more pages
      if (!rows.length || rows.length < perPage) { $('#loadMore').prop('disabled', true); $('#pagerSummary').text('All loaded or fewer rows than per page'); }
      else { $('#loadMore').prop('disabled', false); $('#pagerSummary').text('More pages available'); }
      setLoading(false);
      updateSelectState();
    }, 'json').fail(function(){ alert('Server error'); setLoading(false); });
  }

  // initial load
  resetTable();
  loadMore();

  // refresh button
  $('#refreshBtn').on('click', function(){ resetTable(); loadMore(); });

  // debounced search
  $('#searchQ').on('input', function(){
    clearTimeout(qTimer);
    qTimer = setTimeout(function(){ resetTable(); loadMore(); }, 300);
  });
  $('#status, #from, #to').on('change', function(){ resetTable(); loadMore(); });

  // per page change
  $('#perPage').on('change', function(){ resetTable(); loadMore(); });

  // load more click
  $('#loadMore').on('click', function(){ loadMore(); });

  // select row
  $(document).on('change', '.rowChk', function(){
    const id = $(this).data('id');
    if ($(this).is(':checked')) selected.add(id); else selected.delete(id);
    updateSelectState();
  });

  // select all visible
  $('#selectAll').on('change', function(){
    const checked = $(this).is(':checked');
    $('.rowChk').prop('checked', checked).trigger('change');
  });

  function updateSelectState() {
    const count = selected.size;
    $('#deleteSelected, #transferSelected').prop('disabled', count === 0);
    $('#infoText').text(count ? (count + ' selected') : ''); // simple indicator
  }

  // delete single
  $(document).on('click', '.btn-del', function(){
    const id = $(this).data('id');
    if (!confirm('Delete row ' + id + '?')) return;
    $.post(location.href, { action:'delete', id: id }, function(resp){
      if (resp.success) { resetTable(); loadMore(); alert('Deleted'); }
      else alert('Error: ' + (resp.error || 'unknown'));
    }, 'json');
  });

  // edit flow
  $(document).on('click', '.btn-edit', function(){
    const id = $(this).data('id');
    $.post(location.href, { action:'view', id: id }, function(resp){
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
    $.post(location.href, data, function(resp){
      if (resp.success) {
        bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
        resetTable(); loadMore();
      } else {
        $('#editError').show().text(resp.error || 'Update failed');
      }
    }, 'json').fail(function(){ $('#editError').show().text('Server error'); });
  });

  // delete selected
  $('#deleteSelected').on('click', function(){
    if (!selected.size) return;
    if (!confirm('Delete ' + selected.size + ' selected rows?')) return;
    $.post(location.href, { action:'delete_selected', ids: Array.from(selected) }, function(resp){
      if (resp.success) { alert('Deleted ' + (resp.affected || selected.size)); resetTable(); loadMore(); }
      else alert('Error: ' + (resp.error || 'unknown'));
    }, 'json');
  });

  // transfer selected
  $('#transferSelected').on('click', function(){
    if (!selected.size) return;
    if (!confirm('Transfer ' + selected.size + ' selected rows to MAIN_DATABASE?')) return;
    $.post(location.href, { action:'transfer_selected', ids: Array.from(selected) }, function(resp){
      if (resp.success) { alert('Transferred (affected: ' + (resp.affected || 'unknown') + ')'); resetTable(); loadMore(); }
      else alert('Error: ' + (resp.error || 'unknown'));
    }, 'json');
  });

  // delete all
  $('#deleteAllBtn').on('click', function(){
    if (!confirm('This will DELETE ALL rows in TEMPORARY_DATABASE - are you sure?')) return;
    const token = prompt('Type YES_DELETE_ALL to confirm');
    if (token !== 'YES_DELETE_ALL') return alert('Not confirmed');
    $.post(location.href, { action:'delete_all', confirm: token }, function(resp){
      if (resp.success) { alert('All deleted'); resetTable(); loadMore(); }
      else alert('Error: ' + (resp.error || 'unknown'));
    }, 'json');
  });

  // export csv (server-side)
  $('#exportBtn').on('click', function(){
    const form = $('<form method="post" action="' + location.href + '"></form>');
    form.append('<input type="hidden" name="action" value="export_csv">');
    form.append('<input type="hidden" name="q" value="' + encodeURIComponent($('#searchQ').val().trim()) + '">');
    form.append('<input type="hidden" name="status" value="' + encodeURIComponent($('#status').val()) + '">');
    form.append('<input type="hidden" name="from" value="' + encodeURIComponent($('#from').val()) + '">');
    form.append('<input type="hidden" name="to" value="' + encodeURIComponent($('#to').val()) + '">');
    form.append('<input type="hidden" name="limit" value="20000">');
    form.appendTo('body').submit().remove();
  });

  // activity modal load
  $('#activityModal').on('show.bs.modal', function(){
    $('#activityList').html('<div class="small text-muted">Loading…</div>');
    $.post(location.href, { action:'fetch_activity' }, function(resp){
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

});
</script>
</body>
</html>
