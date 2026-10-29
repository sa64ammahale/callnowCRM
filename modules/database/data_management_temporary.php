<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../config.php';
requirePermission('manage_database');

$DEFAULT_PER_PAGE = 1000;
$MAX_PER_PAGE = 1000;
$EXPORT_MAX = 20000;

function respond_json($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$current_user_id = 0;
if (defined('USER_ID')) $current_user_id = USER_ID;
elseif (!empty($_SESSION['user_id'])) $current_user_id = intval($_SESSION['user_id']);
elseif (!empty($GLOBALS['USER_ID'])) $current_user_id = intval($GLOBALS['USER_ID']);

function valid_date($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        respond_json(['error' => 'Invalid CSRF token']);
    }
    $action = $_POST['action'];

    if ($action === 'view' && !empty($_POST['id'])) {
        $id = intval($_POST['id']);
        $stmt = mysqli_prepare($link, "SELECT * FROM " . tn('TBL_TEMP') . " WHERE ID = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
        respond_json(['row' => $row]);
    }

    if ($action === 'update' && !empty($_POST['id'])) {
        if (!isAdmin() && !isSuperAdmin() && !isManager()) {
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

        $sql = "UPDATE " . tn('TBL_TEMP') . " SET CUST_NAME = ?, CUST_MOBILE = ?, CUST_COMPANY = ?, CUST_PACKAGE = ?, CUST_OTHER_INFO = ?, CALL_DIALED_STATUS = ?, CALL_DIALED_TELECALLER = ?, LAST_DIALED_DATE_TIME = ?, LAST_CONNECTED_PERIOD = ? WHERE ID = ?";
        $stmt = mysqli_prepare($link, $sql);
        mysqli_stmt_bind_param($stmt, "ssssssissi", $name, $mobile, $company, $package, $other, $status, $tele, $last_dialed, $last_conn, $id);
        $ok = mysqli_stmt_execute($stmt);
        if ($ok) {
            logActivity($link, $current_user_id, 'UPDATE', "Updated row $id", (string)$id, tn('TBL_TEMP'));
            respond_json(['success' => true, 'message' => 'Row updated']);
        } else {
            respond_json(['error' => 'Update failed: ' . mysqli_stmt_error($stmt)]);
        }
    }

    if ($action === 'delete' && !empty($_POST['id'])) {
        if (!isAdmin() && !isSuperAdmin() && !isManager()) {
            respond_json(['error' => 'Admin or Manager access required']);
        }
        $id = intval($_POST['id']);
        $stmt = mysqli_prepare($link, "DELETE FROM " . tn('TBL_TEMP') . " WHERE ID = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        $ok = mysqli_stmt_execute($stmt);
        if ($ok) {
            logActivity($link, $current_user_id, 'DELETE', "Deleted row $id", (string)$id, tn('TBL_TEMP'));
            respond_json(['success' => true]);
        } else {
            respond_json(['error' => 'Delete failed: ' . mysqli_stmt_error($stmt)]);
        }
    }

    if ($action === 'delete_selected' && !empty($_POST['ids']) && is_array($_POST['ids'])) {
        if (!isAdmin() && !isSuperAdmin() && !isManager()) {
            respond_json(['error' => 'Admin or Manager access required']);
        }
        $ids = array_map('intval', $_POST['ids']);
        if (!count($ids)) respond_json(['error' => 'No IDs provided']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "DELETE FROM " . tn('TBL_TEMP') . " WHERE ID IN ($placeholders)";
        $stmt = mysqli_prepare($link, $sql);
        if (!$stmt) {
            respond_json(['error' => 'Prepare failed: ' . mysqli_error($link)]);
        }
        $bind_names = array_merge([$types], $ids);
        $refs = [];
        foreach ($bind_names as $k => $v) $refs[$k] = &$bind_names[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $ok = mysqli_stmt_execute($stmt);
        if ($ok) {
            logActivity($link, $current_user_id, 'BULK_DELETE', 'Deleted selected rows', implode(',', $ids), tn('TBL_TEMP'));
            respond_json(['success' => true, 'affected' => mysqli_stmt_affected_rows($stmt)]);
        } else {
            respond_json(['error' => 'Delete selected failed: ' . mysqli_stmt_error($stmt)]);
        }
    }

    if ($action === 'delete_all') {
        if (!isAdmin() && !isSuperAdmin()) {
            respond_json(['error' => 'Admin access required']);
        }
        $confirm = $_POST['confirm'] ?? '';
        if ($confirm !== 'YES_DELETE_ALL') respond_json(['error' => 'Operation not confirmed.']);
        $ok = mysqli_query($link, "TRUNCATE TABLE " . tn('TBL_TEMP'));
        if ($ok) {
            logActivity($link, $current_user_id, 'BULK_DELETE', 'Truncated TBL_TEMP', 'ALL', tn('TBL_TEMP'));
            respond_json(['success' => true]);
        } else respond_json(['error' => 'Truncate failed: ' . mysqli_error($link)]);
    }

    if ($action === 'transfer_selected' && !empty($_POST['ids']) && is_array($_POST['ids'])) {
        if (!isAdmin() && !isSuperAdmin() && !isManager()) {
            respond_json(['error' => 'Admin or Manager access required']);
        }
        $ids = array_map('intval', $_POST['ids']);
        if (!count($ids)) respond_json(['error' => 'No IDs provided']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD, MAINDATABASE_UPLOAD_DATETIME)
                SELECT CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD, NOW()
                FROM " . tn('TBL_TEMP') . " AS td WHERE td.ID IN ($placeholders)
                ON DUPLICATE KEY UPDATE
                    MAINDATABASE_NAME = VALUES(MAINDATABASE_NAME),
                    MAINDATABASE_COMPANY = VALUES(MAINDATABASE_COMPANY),
                    MAINDATABASE_PACKAGE = VALUES(MAINDATABASE_PACKAGE),
                    MAINDATABASE_OTHER_INFO = VALUES(MAINDATABASE_OTHER_INFO),
                    MAINDATABASE_CALL_DIALED_STATUS = VALUES(MAINDATABASE_CALL_DIALED_STATUS),
                    LAST_DIALED_DATE_TIME = VALUES(LAST_DIALED_DATE_TIME),
                    LAST_CONNECTED_PERIOD = VALUES(LAST_CONNECTED_PERIOD),
                    MAINDATABASE_UPLOAD_DATETIME = VALUES(MAINDATABASE_UPLOAD_DATETIME)";
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
            logActivity($link, $current_user_id, 'TRANSFER', 'Transferred to TBL_MAIN', implode(',', $ids), tn('TBL_TEMP'));
            respond_json(['success' => true, 'affected' => $affected]);
        } else {
            respond_json(['error' => 'Transfer failed: ' . mysqli_stmt_error($stmt)]);
        }
    }

    if ($action === 'transfer_all') {
        if (!isAdmin() && !isSuperAdmin() && !isManager()) {
            respond_json(['error' => 'Admin or Manager access required']);
        }

        $transferId = $_POST['transfer_id'] ?? '';
        $progressFile = sys_get_temp_dir() . '/callnow_xfr_' . md5($transferId) . '.json';

        if (!$transferId || !file_exists($progressFile)) {
            $totalRes = mysqli_query($link, "SELECT COUNT(*) FROM " . tn('TBL_TEMP'));
            $total = ($totalRes && $row = mysqli_fetch_row($totalRes)) ? (int)$row[0] : 0;
            if ($total === 0) {
                respond_json(['success' => false, 'error' => 'Temporary database is empty']);
            }
            $transferId = uniqid('xfr_', true);
            $progressFile = sys_get_temp_dir() . '/callnow_xfr_' . md5($transferId) . '.json';
            file_put_contents($progressFile, json_encode([
                'transfer_id' => $transferId,
                'status' => 'running',
                'percent' => 0,
                'processed' => 0,
                'total' => $total,
                'last_id' => 0,
                'message' => 'Starting transfer...'
            ]));
            respond_json(['transfer_id' => $transferId, 'total' => $total, 'status' => 'started']);
        }

        $progress = json_decode(file_get_contents($progressFile), true) ?: [];
        if (($progress['status'] ?? '') === 'complete') {
            respond_json(['status' => 'complete', 'percent' => 100, 'result' => $progress['result']]);
        }

        $batchSize = 500;
        $lastId = (int)($progress['last_id'] ?? 0);

        $idsResult = mysqli_query($link, "SELECT ID FROM " . tn('TBL_TEMP') . " WHERE ID > $lastId ORDER BY ID LIMIT $batchSize");
        $ids = [];
        while ($row = mysqli_fetch_row($idsResult)) {
            $ids[] = $row[0];
        }

        if (empty($ids)) {
            mysqli_autocommit($link, false);
            try {
                $callLogsMigrated = 0;
                $tblCallLogs = tn('TBL_CALL_LOGS');
                $checkTable = mysqli_query($link, "SHOW TABLES LIKE '" . mysqli_real_escape_string($link, $tblCallLogs) . "'");
                if ($checkTable && mysqli_num_rows($checkTable) > 0) {
                    $migrateSql = "UPDATE " . $tblCallLogs . " cl
                        JOIN " . tn('TBL_TEMP') . " t ON cl.record_id = t.ID AND cl.source = 'TEMP'
                        JOIN " . tn('TBL_MAIN') . " m ON t.CUST_MOBILE = m.MAINDATABASE_MOBILE
                        SET cl.record_id = m.ID, cl.source = 'MAIN'";
                    $migrateStmt = mysqli_prepare($link, $migrateSql);
                    if ($migrateStmt) {
                        mysqli_stmt_execute($migrateStmt);
                        $callLogsMigrated = mysqli_stmt_affected_rows($migrateStmt);
                        mysqli_stmt_close($migrateStmt);
                    }
                }

                $delSql = "DELETE FROM " . tn('TBL_TEMP');
                $delStmt = mysqli_prepare($link, $delSql);
                if (!$delStmt) throw new Exception('Delete prepare failed: ' . mysqli_error($link));
                mysqli_stmt_execute($delStmt);
                $deleted = mysqli_stmt_affected_rows($delStmt);
                mysqli_stmt_close($delStmt);

                mysqli_commit($link);

                $result = [
                    'affected' => $progress['processed'],
                    'call_logs_migrated' => $callLogsMigrated,
                    'deleted' => $deleted,
                    'message' => "Transferred {$progress['total']} records. Updated {$progress['processed']} rows in main DB. Migrated $callLogsMigrated call logs."
                ];

                logActivity($link, $current_user_id, 'TRANSFER_ALL',
                    "Transferred all from TBL_TEMP to TBL_MAIN. Affected: {$progress['processed']}, Call logs migrated: $callLogsMigrated, Deleted: $deleted",
                    'ALL', tn('TBL_TEMP'));

                file_put_contents($progressFile, json_encode([
                    'status' => 'complete',
                    'percent' => 100,
                    'result' => $result
                ]));

                respond_json(['status' => 'complete', 'percent' => 100, 'result' => $result]);

            } catch (Exception $e) {
                mysqli_rollback($link);
                file_put_contents($progressFile, json_encode([
                    'status' => 'error',
                    'percent' => $progress['percent'],
                    'error' => $e->getMessage()
                ]));
                respond_json(['status' => 'error', 'error' => 'Transfer failed: ' . $e->getMessage()]);
            }
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "INSERT INTO " . tn('TBL_MAIN') . " (
                MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY,
                MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO,
                MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_CALL_DIALED_USER,
                LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD,
                MAINDATABASE_UPLOAD_DATETIME, MAINDATABASE_CALL_DIAL_TIME
            ) SELECT
                td.CUST_NAME, td.CUST_MOBILE, td.CUST_COMPANY, td.CUST_PACKAGE, td.CUST_OTHER_INFO,
                td.CALL_DIALED_STATUS, td.CALL_DIALED_TELECALLER,
                td.LAST_DIALED_DATE_TIME, td.LAST_CONNECTED_PERIOD,
                td.TEMP_UPLOAD_DATETIME, NOW()
            FROM " . tn('TBL_TEMP') . " AS td
            WHERE td.ID IN ($placeholders)
            ON DUPLICATE KEY UPDATE
                MAINDATABASE_NAME = VALUES(MAINDATABASE_NAME),
                MAINDATABASE_COMPANY = VALUES(MAINDATABASE_COMPANY),
                MAINDATABASE_PACKAGE = VALUES(MAINDATABASE_PACKAGE),
                MAINDATABASE_OTHER_INFO = VALUES(MAINDATABASE_OTHER_INFO),
                MAINDATABASE_CALL_DIALED_STATUS = VALUES(MAINDATABASE_CALL_DIALED_STATUS),
                MAINDATABASE_CALL_DIALED_USER = VALUES(MAINDATABASE_CALL_DIALED_USER),
                LAST_DIALED_DATE_TIME = VALUES(LAST_DIALED_DATE_TIME),
                LAST_CONNECTED_PERIOD = VALUES(LAST_CONNECTED_PERIOD),
                MAINDATABASE_UPLOAD_DATETIME = VALUES(MAINDATABASE_UPLOAD_DATETIME),
                MAINDATABASE_CALL_DIAL_TIME = NOW()";

        $stmt = mysqli_prepare($link, $sql);
        if (!$stmt) {
            file_put_contents($progressFile, json_encode([
                'status' => 'error',
                'percent' => $progress['percent'],
                'error' => 'Prepare failed: ' . mysqli_error($link)
            ]));
            respond_json(['status' => 'error', 'error' => 'Prepare failed: ' . mysqli_error($link)]);
        }

        $types = str_repeat('i', count($ids));
        $bind_names = array_merge([$types], $ids);
        $refs = [];
        foreach ($bind_names as $k => $v) $refs[$k] = &$bind_names[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);

        if (!mysqli_stmt_execute($stmt)) {
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            file_put_contents($progressFile, json_encode([
                'status' => 'error',
                'percent' => $progress['percent'],
                'error' => 'Execute failed: ' . $err
            ]));
            respond_json(['status' => 'error', 'error' => 'Transfer failed: ' . $err]);
        }

        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        $newLastId = max($ids);
        $processed = $progress['processed'] + count($ids);
        $percent = min(100, (int) round($processed / max(1, $progress['total']) * 100));

        file_put_contents($progressFile, json_encode([
            'transfer_id' => $transferId,
            'status' => 'running',
            'percent' => $percent,
            'processed' => $processed,
            'total' => $progress['total'],
            'last_id' => $newLastId,
            'message' => "Processed $processed of {$progress['total']} records..."
        ]));

        respond_json([
            'status' => 'running',
            'percent' => $percent,
            'processed' => $processed,
            'total' => $progress['total'],
            'has_more' => count($ids) >= $batchSize
        ]);
    }

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
                FROM " . tn('TBL_TEMP') . " $where_sql ORDER BY TEMP_UPLOAD_DATETIME DESC LIMIT ?";

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
                $r['ID'], $r['CUST_NAME'], $r['CUST_MOBILE'], $r['CUST_COMPANY'], $r['CUST_PACKAGE'],
                $r['CUST_OTHER_INFO'], $r['TEMP_UPLOAD_DATETIME'], $r['CALL_DIALED_STATUS'],
                $r['CALL_DIALED_TELECALLER'], $r['LAST_DIALED_DATE_TIME'], $r['LAST_CONNECTED_PERIOD']
            ]);
        }
        fclose($out);
        exit;
    }

    if ($action === 'fetch_activity') {
        $res = mysqli_query($link, "SELECT LOG_ID, USER_ID, ACTION_TYPE, ACTION_DETAILS, AFFECTED_IDS, TARGET_TABLE, LOG_TIME, IP_ADDRESS FROM " . tn('TBL_ACTIVITY_LOG') . " ORDER BY LOG_TIME DESC LIMIT 200");
        $arr = [];
        while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;
        respond_json(['activities' => $arr]);
    }

    respond_json(['error' => 'Unknown action']);
}

$totalCount = 0;
$tc = mysqli_query($link, "SELECT COUNT(*) FROM " . tn('TBL_TEMP'));
if ($tc) $totalCount = (int)mysqli_fetch_row($tc)[0];
$statusCounts = [];
$sc = mysqli_query($link, "SELECT CALL_DIALED_STATUS, COUNT(*) as cnt FROM " . tn('TBL_TEMP') . " GROUP BY CALL_DIALED_STATUS ORDER BY cnt DESC");
if ($sc) while ($s = mysqli_fetch_assoc($sc)) $statusCounts[] = $s;

$telecallers = [];
$tu = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_USERS') . " WHERE STATUS = 'Active' AND ROLE != 'Super Admin' ORDER BY NAME");
if ($tu) while ($u = mysqli_fetch_assoc($tu)) $telecallers[$u['ID']] = $u['NAME'];

$pageTitle = 'Temporary Database • CallNow CRM';
include __DIR__ . '/../../php_scripts/header.php';
?>

<div class="container-fluid px-3 py-3">
    <!-- Module Header -->
    <div class="module-header mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 fs-xs">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard.php') ?>">Home</a></li>
                    <li class="breadcrumb-item"><a href="#">Data Management</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Temporary Database</li>
                </ol>
            </nav>
            <h1 class="h4 fw-bold text-dark mb-0">Temporary Database (Staging)</h1>
            <div class="text-muted fs-xs">Isolated staging workbench for lead scrubs, validations, and bulk updates before committing to Main DB</div>
        </div>
        <div class="module-header-actions">
            <a href="<?= url('modules/database/data_management_main.php') ?>" class="btn btn-secondary btn-sm">
                <i class="bi bi-database me-1"></i> Main Database
            </a>
            <a href="<?= url('modules/database/upload_data.php') ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-cloud-upload me-1"></i> Upload Staging CSV
            </a>
            <button id="activityBtn" class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#activityModal">
                <i class="bi bi-clock-history me-1"></i> Staging Logs
            </button>
        </div>
    </div>

    <!-- Alert / Sandbox Banner -->
    <div class="alert alert-warning d-flex align-items-center mb-3 py-2 px-3 border" role="alert">
        <i class="bi bi-shield-check text-warning fs-5 me-2"></i>
        <div class="fs-xs">
            <strong>Staging Isolation:</strong> Records modified or dialed in this workspace do not update the Main Database until explicitly executed via <strong>Transfer Selected</strong> or <strong>Transfer All</strong>.
        </div>
    </div>

    <!-- Metric KPI Cards -->
    <div class="row g-2 mb-3">
        <div class="col-sm-6 col-md-3">
            <div class="metric-card">
                <div class="metric-card-title">Staged Records</div>
                <div class="metric-card-value text-primary"><?= number_format($totalCount) ?></div>
                <div class="metric-card-subtitle text-muted">Awaiting transfer</div>
            </div>
        </div>
        <?php foreach (array_slice($statusCounts, 0, 2) as $s): ?>
            <div class="col-sm-6 col-md-3">
                <div class="metric-card">
                    <div class="metric-card-title"><?= htmlspecialchars($s['CALL_DIALED_STATUS'] ?: 'Unknown') ?></div>
                    <div class="metric-card-value text-dark"><?= number_format((int)$s['cnt']) ?></div>
                    <div class="metric-card-subtitle text-muted">Staged disposition</div>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="col-sm-6 col-md-3">
            <div class="metric-card">
                <div class="metric-card-title">Status Classes</div>
                <div class="metric-card-value text-secondary"><?= count($statusCounts) ?></div>
                <div class="metric-card-subtitle text-muted">Unique statuses</div>
            </div>
        </div>
    </div>

    <!-- Action & Filter Toolbar -->
    <div class="crm-card mb-3">
        <div class="crm-card-body py-2">
            <!-- Row 1: Actions -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2 pb-2 border-bottom">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button id="transferSelected" class="btn btn-primary btn-sm" disabled>
                        <i class="bi bi-arrow-right-circle me-1"></i> Transfer Selected (<span id="selectedCount">0</span>)
                    </button>
                    <button id="transferAllBtn" class="btn btn-success btn-sm" <?= $totalCount > 0 ? '' : 'disabled' ?>>
                        <i class="bi bi-box-arrow-in-down-right me-1"></i> Transfer All to Main DB
                    </button>
                    <button id="deleteSelected" class="btn btn-outline-danger btn-sm" disabled>
                        <i class="bi bi-trash3 me-1"></i> Delete Selected
                    </button>
                    <button id="deleteAllBtn" class="btn btn-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i> Wipe Staging Table
                    </button>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button id="refreshBtn" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                    </button>
                    <button id="exportBtn" class="btn btn-secondary btn-sm">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Filtered
                    </button>
                    <button id="exportFullBtn" class="btn btn-secondary btn-sm">
                        <i class="bi bi-cloud-arrow-down me-1"></i> Export Full DB
                    </button>
                </div>
            </div>

            <!-- Row 2: Search & Filters -->
            <div class="row g-2 align-items-center">
                <div class="col-md-4 col-12">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input id="searchQ" class="form-control" placeholder="Search name, mobile, company, or package...">
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <select id="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <option>Not Called</option>
                        <option>Dialed</option>
                        <option>Connected</option>
                        <option>Busy</option>
                        <option>No Answer</option>
                        <option>Do Not Call</option>
                        <option>Pending</option>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <input type="date" id="from" class="form-control form-control-sm" placeholder="Uploaded From">
                </div>
                <div class="col-md-2 col-6">
                    <input type="date" id="to" class="form-control form-control-sm" placeholder="Uploaded To">
                </div>
                <div class="col-md-2 col-6">
                    <select id="perPage" class="form-select form-select-sm">
                        <option value="250">250 per page</option>
                        <option value="500">500 per page</option>
                        <option value="1000" selected>1000 per page</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="crm-card">
        <div class="crm-card-body p-0">
            <div class="table-responsive">
                <table id="tempTable" class="table table-hover align-middle mb-0" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:36px; text-align:center;"><input type="checkbox" id="selectAll" class="form-check-input"></th>
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

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form id="editForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Staged Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editId" name="id">
                
                <div class="p-2 mb-3 bg-light rounded border">
                    <div class="row g-2 fs-xs">
                        <div class="col-md-2 col-4"><span class="text-muted d-block">ID</span><strong id="infoId" class="text-dark">-</strong></div>
                        <div class="col-md-3 col-8"><span class="text-muted d-block">Upload Time</span><strong id="infoUpload" class="text-dark">-</strong></div>
                        <div class="col-md-3 col-6"><span class="text-muted d-block">Last Dialed</span><strong id="infoLastDialed" class="text-dark">-</strong></div>
                        <div class="col-md-2 col-6"><span class="text-muted d-block">Duration</span><strong id="infoLastConnected" class="text-dark">-</strong></div>
                        <div class="col-md-2 col-6"><span class="text-muted d-block">Source</span><strong id="infoSource" class="text-dark">-</strong></div>
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label" for="editName">Name</label>
                        <input class="form-control" name="CUST_NAME" id="editName" placeholder="Full name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="editMobile">Mobile Number</label>
                        <input class="form-control" name="CUST_MOBILE" id="editMobile" placeholder="10-digit mobile">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="editCompany">Company</label>
                        <input class="form-control" name="CUST_COMPANY" id="editCompany" placeholder="Company name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="editPackage">Package / Salary</label>
                        <input class="form-control" name="CUST_PACKAGE" id="editPackage" placeholder="Package">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="editOther">Other Info</label>
                        <textarea class="form-control" name="CUST_OTHER_INFO" id="editOther" rows="2" placeholder="Additional notes"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="editStatus">Status / Disposition</label>
                        <select class="form-select" name="CALL_DIALED_STATUS" id="editStatus">
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
                        <label class="form-label" for="editTelecaller">Assigned Telecaller</label>
                        <select class="form-select" name="CALL_DIALED_TELECALLER" id="editTelecaller">
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

<!-- Activity Log Modal -->
<div class="modal fade" id="activityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-list-check text-primary me-2"></i>Staging Activity Log (Latest 200)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div id="activityList" style="max-height:60vh; overflow-y:auto;">Loading…</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Progress Modal -->
<div class="modal fade" id="transferProgressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-box-arrow-in-down-right text-primary me-2"></i>Transfer to Main Database</h5>
                <button type="button" class="btn-close close-modal-btn" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between mb-1">
                    <span class="fs-xs text-muted">Migration Progress</span>
                    <span id="transferPercent" class="fs-xs fw-semibold text-dark">0%</span>
                </div>
                <div class="progress mb-3" style="height: 16px;">
                    <div id="transferProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%"></div>
                </div>
                <p class="mb-2 fs-xs text-muted" id="transferMessage">Initializing transfer...</p>
                <div id="transferResult" class="mt-2 alert fs-xs" style="display:none"></div>
            </div>
        </div>
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
const APP_BASE = <?= json_encode(APP_BASE) ?>;
const TEMP_URL = APP_BASE + '/modules/database/temporarydatabase_ajax/datatable';
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

$(document).ready(function() {
    const table = $('#tempTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        destroy: true,
        ajax: {
            url: TEMP_URL,
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
        lengthMenu: [ 100, 250, 500, 1000 ],
        order: [[6, 'desc']],
        dom: '<"p-2 d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom"<"d-flex align-items-center gap-2"lB><"ms-auto"f>>rt<"p-2 d-flex flex-wrap justify-content-between align-items-center gap-2 border-top"ip>',
        buttons: [
            {
                text: '<i class="bi bi-download me-1"></i> Quick Export',
                className: 'btn btn-secondary btn-sm dropdown-toggle',
                extend: 'collection',
                buttons: [
                    { extend: 'copy', text: '<i class="bi bi-clipboard me-1"></i> Copy', className: 'btn btn-secondary btn-sm dropdown-item' },
                    { extend: 'csv', text: '<i class="bi bi-file-earmark-spreadsheet me-1"></i> CSV', className: 'btn btn-secondary btn-sm dropdown-item', title: 'TemporaryDatabase_Export_' + new Date().toISOString().slice(0,10) },
                    { extend: 'excel', text: '<i class="bi bi-file-excel me-1"></i> Excel', className: 'btn btn-secondary btn-sm dropdown-item' }
                ]
            }
        ],
        columnDefs: [
            { orderable: false, targets: 0, className: 'text-center' },
            { width: '70px', targets: 1 },
            { width: '120px', targets: 2 },
            {
                targets: 6,
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

    $('#tempTable tbody').on('change', 'input[type="checkbox"]', updateSelectedCount);

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

    function getSelectedCount() {
        return $('#tempTable tbody input[type="checkbox"]:checked').length;
    }

    function updateSelectedCount() {
        const count = getSelectedCount();
        $('#selectedCount').text(count);
        $('#deleteSelected, #transferSelected').prop('disabled', count === 0);
    }

    function getSelectedIds() {
        const ids = [];
        $('#tempTable tbody input[type="checkbox"]:checked').each(function() {
            ids.push($(this).val());
        });
        return ids;
    }

    function loadEditModal(id) {
        $.post(location.href, { action:'view', id: id, csrf_token: CSRF_TOKEN }, function(resp){
            if (!resp.row) return showNotification('Error', 'Record not found', 'danger');
            const r = resp.row;
            $('#editId').val(r.ID);
            $('#editName').val(r.CUST_NAME);
            $('#editMobile').val(r.CUST_MOBILE);
            $('#editCompany').val(r.CUST_COMPANY);
            $('#editPackage').val(r.CUST_PACKAGE);
            $('#editOther').val(r.CUST_OTHER_INFO);
            $('#editStatus').val(r.CALL_DIALED_STATUS);
            $('#editTelecaller').val(r.CALL_DIALED_TELECALLER);
            $('#infoId').text(r.ID);
            $('#infoUpload').text(r.TEMP_UPLOAD_DATETIME || '-');
            $('#infoLastDialed').text(r.LAST_DIALED_DATE_TIME || '-');
            $('#infoLastConnected').text(r.LAST_CONNECTED_PERIOD || '-');
            $('#infoSource').text(r.SOURCE || '-');
            $('#editError').hide().text('');
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }, 'json');
    }

    $(document).on('click', '.btn-edit', function(){
        loadEditModal($(this).data('id'));
    });

    $('#tempTable tbody').on('click', 'td', function() {
        if ($(this).find('input[type="checkbox"]').length) return;
        if ($(this).find('.btn-edit, .btn-del').length) return;
        const row = table.row($(this).closest('tr')).data();
        if (row && row[1]) loadEditModal(row[1]);
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

    $(document).on('click', '.btn-del', function(){
        const id = $(this).data('id');
        if (!confirm('Delete row ' + id + '?')) return;
        $.post(location.href, { action:'delete', id: id, csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.success) { table.ajax.reload(); showNotification('Deleted', 'Record removed', 'success'); }
            else showNotification('Error', resp.error || 'unknown', 'danger');
        }, 'json');
    });

    $('#deleteSelected').on('click', function(){
        const count = getSelectedCount();
        if (!confirm('Delete ' + count + ' selected records permanently?')) return;
        const btn = $(this);
        const originalText = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Deleting...');
        const ids = getSelectedIds();
        $.post(location.href, { action:'delete_selected', ids: ids, csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.success) {
                showNotification('Deleted', 'Deleted ' + (resp.affected || count) + ' records', 'success');
                table.ajax.reload();
                $('#selectAll').prop('checked', false);
                updateSelectedCount();
            } else {
                showNotification('Error', resp.error || 'unknown', 'danger');
            }
        }, 'json').fail(function() {
            showNotification('Network Error', 'Please try again.', 'danger');
        }).always(function() {
            btn.prop('disabled', false).html(originalText);
        });
    });

    $('#transferSelected').on('click', function(){
        const count = getSelectedCount();
        if (count === 0) return showNotification('No Selection', 'Please select at least one record to transfer.', 'warning');
        if (!confirm('Transfer ' + count + ' selected rows to Main Database?')) return;
        const btn = $(this);
        const originalText = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Transferring...');
        const ids = getSelectedIds();
        $.post(location.href, { action:'transfer_selected', ids: ids, csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.success) {
                showNotification('Transferred', 'Transferred ' + (resp.affected || 'unknown') + ' records to Main DB', 'success');
                table.ajax.reload();
                $('#selectAll').prop('checked', false);
                updateSelectedCount();
            } else {
                showNotification('Error', resp.error || 'unknown', 'danger');
            }
        }, 'json').fail(function() {
            showNotification('Network Error', 'Please try again.', 'danger');
        }).always(function() {
            btn.prop('disabled', false).html(originalText);
        });
    });

    const TOTAL_TEMP_COUNT = <?= (int)$totalCount ?>;
    $('#transferAllBtn').on('click', function(){
        if (TOTAL_TEMP_COUNT === 0) return showNotification('Empty', 'No records to transfer', 'warning');

        const modal = new bootstrap.Modal(document.getElementById('transferProgressModal'), { backdrop: 'static', keyboard: false });
        const modalEl = document.getElementById('transferProgressModal');
        const progressBar = $('#transferProgressBar');
        const percentText = $('#transferPercent');
        const messageText = $('#transferMessage');
        const resultDiv = $('#transferResult');

        const btn = $(this);
        const originalText = btn.html();

        $(modalEl).on('hidden.bs.modal', function() {
            resultDiv.hide().removeClass('alert-success alert-danger').empty();
            progressBar.css('width', '0%').removeClass('bg-success bg-danger').addClass('bg-primary');
            percentText.text('0%');
            messageText.text('Initializing transfer...');
            btn.prop('disabled', false).html(originalText);
        });

        modal.show();
        progressBar.css('width', '0%').removeClass('bg-success bg-danger').addClass('bg-primary');
        percentText.text('0%');
        messageText.text('Initializing transfer...');
        resultDiv.hide();

        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Transferring...');

        $.post(location.href, { action:'transfer_all', csrf_token: CSRF_TOKEN }, function(resp){
            if (!resp.transfer_id) {
                modal.hide();
                btn.prop('disabled', false).html(originalText);
                showNotification('Error', resp.error || 'Failed to start transfer', 'danger');
                return;
            }

            const transferId = resp.transfer_id;

            function processNextBatch() {
                $.post(location.href, {
                    action: 'transfer_all',
                    transfer_id: transferId,
                    csrf_token: CSRF_TOKEN
                }, function(resp){
                    if (resp.status === 'complete') {
                        progressBar.css('width', '100%').removeClass('bg-primary bg-danger').addClass('bg-success');
                        percentText.text('100%');
                        messageText.text('Transfer complete!');
                        resultDiv.show().addClass('alert-success').html(
                            '<strong>Success!</strong> ' + (resp.result?.message || 'All records transferred.') +
                            '<br><button class="btn btn-sm btn-secondary mt-2 close-modal-btn">Close</button>'
                        );
                        btn.prop('disabled', false).html(originalText);
                        table.ajax.reload();
                    } else if (resp.status === 'error') {
                        progressBar.css('width', resp.percent + '%').removeClass('bg-primary bg-success').addClass('bg-danger');
                        messageText.text('Transfer failed');
                        resultDiv.show().addClass('alert-danger').html(
                            '<strong>Error:</strong> ' + (resp.error || 'Unknown error') +
                            '<br><button class="btn btn-sm btn-secondary mt-2 close-modal-btn">Close</button>'
                        );
                        btn.prop('disabled', false).html(originalText);
                    } else {
                        progressBar.css('width', resp.percent + '%').removeClass('bg-success bg-danger').addClass('bg-primary');
                        percentText.text(resp.percent + '%');
                        messageText.text(resp.message || 'Processing...');
                        setTimeout(processNextBatch, 10);
                    }
                }, 'json').fail(function(){
                    progressBar.addClass('bg-danger');
                    messageText.text('Network error');
                    resultDiv.show().addClass('alert-danger').html('<strong>Error:</strong> Network error during transfer.<br><button class="btn btn-sm btn-secondary mt-2 close-modal-btn">Close</button>');
                    btn.prop('disabled', false).html(originalText);
                });
            }

            processNextBatch();
        }, 'json').fail(function(){
            modal.hide();
            btn.prop('disabled', false).html(originalText);
            showNotification('Error', 'Failed to start transfer', 'danger');
        });
    });

    $(document).on('click', '.close-modal-btn', function() {
        bootstrap.Modal.getInstance(document.getElementById('transferProgressModal'))?.hide();
    });

    $('#deleteAllBtn').on('click', function(){
        if (!confirm('This will DELETE ALL rows in staging - are you sure?')) return;
        const token = prompt('Type YES_DELETE_ALL to confirm');
        if (token !== 'YES_DELETE_ALL') return showNotification('Cancelled', 'Operation not confirmed.', 'warning');
        const btn = $(this);
        const originalText = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Wiping...');
        $.post(location.href, { action:'delete_all', confirm: token, csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.success) { showNotification('Deleted', 'All staging records removed', 'success'); table.ajax.reload(); }
            else showNotification('Error', resp.error || 'unknown', 'danger');
        }, 'json').fail(function() {
            showNotification('Network Error', 'Please try again.', 'danger');
        }).always(function() {
            btn.prop('disabled', false).html(originalText);
        });
    });

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

    $('#exportFullBtn').on('click', function(){
        $.get(APP_BASE + '/modules/database/temporarydatabase_ajax/get_total_count', function (total) {
            total = parseInt(total);
            if (total === 0) return showNotification('Empty', 'No data found', 'info');

            if (total > 100000 && !confirm(`Warning: ${total.toLocaleString()} records!\nThis will download in multiple CSV parts.\n\nContinue?`)) {
                return;
            }

            const win = window.open(APP_BASE + '/modules/database/temporarydatabase_ajax/download_bach', '_blank');
            if (win) {
                showNotification('Export Started', `${total.toLocaleString()} records downloading`, 'success');
            } else {
                showNotification('Popup Blocked!', 'Please allow popups', 'danger');
            }
        });
    });

    $('#activityModal').on('show.bs.modal', function(){
        $('#activityList').html('<div class="text-center py-3 text-muted fs-xs">Loading activity logs...</div>');
        $.post(location.href, { action:'fetch_activity', csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.error) { $('#activityList').html('<div class="text-danger fs-xs">' + resp.error + '</div>'); return; }
            let out = '<div class="list-group list-group-flush">';
            for (const a of resp.activities || []) {
                out += `<div class="list-group-item px-2 py-2 fs-xs border-bottom">
                  <div class="d-flex justify-content-between"><strong>${escapeHtml(a.ACTION_TYPE)}</strong><span class="text-muted">${escapeHtml(a.LOG_TIME)}</span></div>
                  <div class="text-muted">User: ${escapeHtml(a.USER_ID)} | IP: ${escapeHtml(a.IP_ADDRESS)} | Target: ${escapeHtml(a.TARGET_TABLE)}</div>
                  <div class="text-dark mt-1">${escapeHtml(a.ACTION_DETAILS)} <span class="text-muted">[IDs: ${escapeHtml(a.AFFECTED_IDS||'')}]</span></div>
                </div>`;
            }
            out += '</div>';
            $('#activityList').html(out);
        }, 'json');
    });

    function escapeHtml(s){ if(s===null||s===undefined) return ''; return $('<div>').text(s).html(); }

    let qTimer = null;
    $('#searchQ').on('input', function(){
        clearTimeout(qTimer);
        qTimer = setTimeout(function(){ table.ajax.reload(); }, 300);
    });
    $('#status, #from, #to').on('change', function(){ table.ajax.reload(); });
    $('#perPage').on('change', function(){ table.page.len(parseInt($(this).val())).draw(); });

    $('#refreshBtn').on('click', function() {
        table.ajax.reload();
        showNotification('Refreshed', 'Staging table reloaded', 'info');
    });
});
</script>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
