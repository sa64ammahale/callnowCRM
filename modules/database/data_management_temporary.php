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

// Stats
$totalCount = 0;
$tc = mysqli_query($link, "SELECT COUNT(*) FROM " . tn('TBL_TEMP'));
if ($tc) $totalCount = (int)mysqli_fetch_row($tc)[0];
$statusCounts = [];
$sc = mysqli_query($link, "SELECT CALL_DIALED_STATUS, COUNT(*) as cnt FROM " . tn('TBL_TEMP') . " GROUP BY CALL_DIALED_STATUS ORDER BY cnt DESC");
if ($sc) while ($s = mysqli_fetch_assoc($sc)) $statusCounts[] = $s;

$telecallers = [];
$tu = mysqli_query($link, "SELECT ID, NAME FROM " . tn('TBL_USERS') . " WHERE STATUS = 'Active' AND ROLE != 'Super Admin' ORDER BY NAME");
if ($tu) while ($u = mysqli_fetch_assoc($tu)) $telecallers[$u['ID']] = $u['NAME'];

$pageTitle = 'Temporary Database';
include __DIR__ . '/../../php_scripts/header.php';
?>

<style>
:root {
    --td-accent: var(--accent);
    --td-accent-dark: var(--accent-hover, #4f46e5);
    --td-ink: #1e1b4b;
    --td-ink-soft: #6b6890;
    --td-soft: #f0f2ff;
    --td-border: #e2e4f0;
}
.td-header {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
    border-radius: 1rem; padding: 1.5rem 2rem; margin-bottom: 1.5rem;
    position: relative; overflow: hidden;
}
.td-header::before {
    content: ''; position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.3;
}
.td-header-content { position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
.td-header-left h1 { font-size: 1.35rem; font-weight: 700; color: #fff; margin: 0 0 0.2rem 0; letter-spacing: -0.02em; display: flex; align-items: center; gap: 0.5rem; }
.td-header-left h1 i { font-size: 1.4rem; }
.td-header-left p { color: rgba(255,255,255,0.7); font-size: 0.8125rem; margin: 0; }
.td-header-actions { display: flex; gap: 0.5rem; }
.td-btn-glass {
    background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2);
    color: #fff; backdrop-filter: blur(4px); font-size: 0.75rem; padding: 0.4rem 0.9rem;
    border-radius: 0.5rem; transition: all 0.15s ease; text-decoration: none; display: flex; align-items: center; gap: 0.35rem;
}
.td-btn-glass:hover { background: rgba(255,255,255,0.25); border-color: rgba(255,255,255,0.35); color: #fff; transform: translateY(-1px); }

.td-stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem; }
.td-stat-card { background: var(--td-soft); border: 1px solid var(--td-border); border-radius: 0.75rem; padding: 0.875rem 1.125rem; }
.td-stat-card .num { font-size: 1.35rem; font-weight: 700; color: var(--td-ink); line-height: 1.2; }
.td-stat-card .lbl { font-size: 0.7rem; color: var(--td-ink-soft); margin: 0; }
.td-stat-card .num i { font-size: 0.9rem; margin-right: 0.25rem; }

.td-toolbar {
    background: #fff; border: 1px solid var(--td-border); border-radius: 0.75rem;
    padding: 0.75rem 1rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.td-toolbar-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
.td-toolbar-row + .td-toolbar-row { margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #f0f1f8; }

.td-card { background: #fff; border: 1px solid var(--td-border); border-radius: 0.875rem; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
.td-card-body { padding: 1.25rem; }

.td-input, .td-select {
    border: 1px solid var(--td-border) !important; border-radius: 0.5rem !important;
    font-size: 0.75rem !important; color: var(--td-ink) !important;
    padding: 0.35rem 0.65rem !important; background: #fff !important;
}
.td-input:focus, .td-select:focus {
    border-color: var(--td-accent) !important; box-shadow: 0 0 0 3px rgba(99,102,241,0.12) !important; outline: none;
}
.td-btn-primary {
    background: linear-gradient(135deg, var(--td-accent), var(--td-accent-dark)) !important;
    border: none !important; color: #fff !important; border-radius: 0.5rem !important;
    font-size: 0.75rem !important; font-weight: 600 !important; padding: 0.4rem 1rem !important;
    transition: all 0.15s ease !important; box-shadow: 0 2px 6px rgba(99,102,241,0.2) !important;
}
.td-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,102,241,0.3) !important; }
.td-btn-sm { padding: 0.3rem 0.65rem !important; font-size: 0.6875rem !important; border-radius: 0.375rem !important; }
.td-btn-success { background: linear-gradient(135deg, #10b981, #059669) !important; border: none !important; color: #fff !important; border-radius: 0.5rem !important; font-size: 0.75rem !important; font-weight: 600 !important; padding: 0.3rem 0.65rem !important; transition: all 0.15s ease !important; }
.td-btn-success:hover { transform: translateY(-1px); }
.td-btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626) !important; border: none !important; color: #fff !important; border-radius: 0.5rem !important; font-size: 0.75rem !important; font-weight: 600 !important; padding: 0.3rem 0.65rem !important; transition: all 0.15s ease !important; }
.td-btn-danger:hover { transform: translateY(-1px); }
.td-btn-outline { border: 1px solid var(--td-border) !important; background: #fff !important; color: var(--td-ink-soft) !important; border-radius: 0.5rem !important; font-size: 0.75rem !important; padding: 0.3rem 0.65rem !important; transition: all 0.12s ease !important; }
.td-btn-outline:hover { border-color: var(--td-accent) !important; color: var(--td-accent) !important; }

.td-alert { border-radius: 0.625rem; font-size: 0.8125rem; padding: 0.65rem 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
#notificationModal .modal-content { border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.12); }

.td-modal-header { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; border-radius: 0.75rem 0.75rem 0 0; padding: 1rem 1.25rem; }
.td-modal-header .btn-close { filter: brightness(0) invert(1); }
.td-modal-header h5 { font-size: 0.9rem; font-weight: 700; margin: 0; }
.td-modal-body { padding: 1.25rem; }
.td-modal-footer { border-top: 1px solid var(--td-border); padding: 0.75rem 1.25rem; }
.td-row-click { cursor: pointer; }
.td-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 0.4rem; background: #f8f9ff; border: 1px solid var(--td-border); border-radius: 0.5rem; padding: 0.75rem; margin-bottom: 0.75rem; }
.td-info-item { font-size: 0.75rem; }
.td-info-item strong { color: var(--td-ink-soft); display: block; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.1rem; }
.td-info-item span { color: var(--td-ink); }
</style>

<div class="container page-wrapper">

    <div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content" style="border-radius:0.75rem;border:1px solid var(--td-border);">
                <div class="modal-body text-center py-4">
                    <div id="notifIcon" class="mb-2" style="font-size:2.5rem;"></div>
                    <h5 id="notifTitle" class="fw-bold mb-1"></h5>
                    <p id="notifMessage" class="text-muted small mb-3"></p>
                    <button type="button" class="btn btn-primary btn-sm px-4" data-bs-dismiss="modal" style="border-radius:0.5rem;">OK</button>
                </div>
            </div>
        </div>
    </div>

    <div class="td-header">
        <div class="td-header-content">
            <div class="td-header-left">
                <h1><i class="bi bi-database-gear"></i> Temporary Database</h1>
                <p>Manage, search, transfer, and export temporary records</p>
            </div>
            <div class="td-header-actions">
                <a href="<?= url('modules/database/data_management_main.php') ?>" class="td-btn-glass"><i class="bi bi-database"></i> Main DB</a>
                <a href="<?= url('modules/database/upload_data.php') ?>" class="td-btn-glass"><i class="bi bi-cloud-upload"></i> Upload</a>
                <button id="activityBtn" class="td-btn-glass" data-bs-toggle="modal" data-bs-target="#activityModal"><i class="bi bi-list-check"></i> Activity</button>
            </div>
        </div>
    </div>

    <div class="td-stat-grid">
        <div class="td-stat-card">
            <div class="num"><i class="bi bi-database"></i><?= number_format($totalCount) ?></div>
            <p class="lbl">Total Records</p>
        </div>
        <?php foreach (array_slice($statusCounts, 0, 3) as $s): ?>
            <div class="td-stat-card">
                <div class="num"><i class="bi bi-tag"></i><?= number_format((int)$s['cnt']) ?></div>
                <p class="lbl"><?= htmlspecialchars($s['CALL_DIALED_STATUS'] ?: 'Unknown') ?></p>
            </div>
        <?php endforeach; ?>
        <div class="td-stat-card">
            <div class="num"><i class="bi bi-layers"></i><?= count($statusCounts) ?></div>
            <p class="lbl">Status Types</p>
        </div>
    </div>

    <div class="td-toolbar">
        <div class="td-toolbar-row">
            <div class="btn-group" role="group">
                <a href="<?= url('modules/database/add_single_number.php') ?>" class="td-btn-success td-btn-sm"><i class="bi bi-upload"></i> Upload Single</a>
                <button id="transferSelected" class="td-btn-success td-btn-sm" disabled><i class="bi bi-arrow-right-circle"></i> Transfer Selected</button>
                <button id="transferAllBtn" class="td-btn-primary td-btn-sm" <?= $totalCount > 0 ? '' : 'disabled' ?>><i class="bi bi-arrow-down-circle"></i> Transfer All</button>
            </div>
            <div class="btn-group ms-2" role="group">
                <button id="deleteSelected" class="td-btn-outline-danger td-btn-sm" disabled><i class="bi bi-trash"></i> Delete Selected (<span id="selectedCount">0</span>)</button>
                <button id="deleteAllBtn" class="td-btn-outline td-btn-sm"><i class="bi bi-x-circle"></i> Delete All</button>
            </div>
            <div class="ms-auto d-flex gap-2 align-items-center">
                <button id="refreshBtn" class="td-btn-outline td-btn-sm"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                <button id="exportBtn" class="td-btn-outline td-btn-sm"><i class="bi bi-download"></i> Export CSV</button>
                <button id="exportFullBtn" class="td-btn-primary td-btn-sm"><i class="bi bi-cloud-arrow-down"></i> Export Full DB</button>
            </div>
        </div>
        <div class="td-toolbar-row">
            <input id="searchQ" class="form-control td-input" placeholder="Search name / mobile / company / package" style="flex:1;min-width:200px;">
            <select id="status" class="form-select td-select" style="width:140px;">
                <option value="">All Status</option>
                <option>Not Called</option><option>Dialed</option><option>Connected</option><option>Busy</option><option>No Answer</option><option>Do Not Call</option><option>Pending</option>
            </select>
            <input type="date" id="from" class="form-control td-input" style="width:145px;" placeholder="From">
            <input type="date" id="to" class="form-control td-input" style="width:145px;" placeholder="To">
            <select id="perPage" class="form-select td-select" style="width:120px;">
                <option value="1000">1000 / page</option>
                <option value="500">500 / page</option>
                <option value="250">250 / page</option>
            </select>
        </div>
    </div>

    <div class="td-card">
        <div class="td-card-body" style="padding:0;">
            <table id="tempTable" class="table table-hover table-sm td-dt-table" style="width:100%;margin:0;">
                <thead>
                    <tr>
                        <th style="width:36px;"><input type="checkbox" id="selectAll"></th>
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

<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form id="editForm" class="modal-content" style="border-radius:0.75rem;border:1px solid var(--td-border);">
            <div class="td-modal-header"><h5><i class="bi bi-pencil-square"></i> Edit Record</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="td-modal-body">
                <input type="hidden" id="editId" name="id">
                <div class="td-info-grid" id="infoSection">
                    <div class="td-info-item"><strong>ID</strong><span id="infoId">-</span></div>
                    <div class="td-info-item"><strong>Upload Time</strong><span id="infoUpload">-</span></div>
                    <div class="td-info-item"><strong>Last Dialed</strong><span id="infoLastDialed">-</span></div>
                    <div class="td-info-item"><strong>Last Connected</strong><span id="infoLastConnected">-</span></div>
                    <div class="td-info-item"><strong>Source</strong><span id="infoSource">-</span></div>
                    <div class="td-info-item"><strong>Notes</strong><span id="infoNotes">-</span></div>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label style="font-size:0.7rem;font-weight:600;color:var(--td-ink);margin-bottom:0.2rem;">Name</label>
                        <input class="form-control td-input" name="CUST_NAME" id="editName" placeholder="Full name">
                    </div>
                    <div class="col-md-6">
                        <label style="font-size:0.7rem;font-weight:600;color:var(--td-ink);margin-bottom:0.2rem;">Mobile</label>
                        <input class="form-control td-input" name="CUST_MOBILE" id="editMobile" placeholder="10-digit mobile">
                    </div>
                    <div class="col-md-6">
                        <label style="font-size:0.7rem;font-weight:600;color:var(--td-ink);margin-bottom:0.2rem;">Company</label>
                        <input class="form-control td-input" name="CUST_COMPANY" id="editCompany" placeholder="Company name">
                    </div>
                    <div class="col-md-6">
                        <label style="font-size:0.7rem;font-weight:600;color:var(--td-ink);margin-bottom:0.2rem;">Package</label>
                        <input class="form-control td-input" name="CUST_PACKAGE" id="editPackage" placeholder="Package">
                    </div>
                    <div class="col-12">
                        <label style="font-size:0.7rem;font-weight:600;color:var(--td-ink);margin-bottom:0.2rem;">Other Info</label>
                        <textarea class="form-control td-input" name="CUST_OTHER_INFO" id="editOther" rows="2" placeholder="Additional notes"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label style="font-size:0.7rem;font-weight:600;color:var(--td-ink);margin-bottom:0.2rem;">Status</label>
                        <select class="form-select td-select" name="CALL_DIALED_STATUS" id="editStatus">
                            <option>Not Called</option><option>Dialed</option><option>Connected</option><option>Busy</option><option>No Answer</option><option>Do Not Call</option><option>Pending</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label style="font-size:0.7rem;font-weight:600;color:var(--td-ink);margin-bottom:0.2rem;">Assigned To</label>
                        <select class="form-select td-select" name="CALL_DIALED_TELECALLER" id="editTelecaller">
                            <option value="">-- Unassigned --</option>
                            <?php foreach($telecallers as $tid => $tname): ?>
                                <option value="<?= $tid ?>"><?= htmlspecialchars($tname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mt-2 text-danger small" id="editError" style="display:none"></div>
            </div>
            <div class="td-modal-footer d-flex justify-content-end gap-2">
                <button type="button" class="td-btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="td-btn-primary"><i class="bi bi-check-lg"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="activityModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="border-radius:0.75rem;border:1px solid var(--td-border);">
            <div class="td-modal-header"><h5><i class="bi bi-list-check"></i> Activity Log (latest 200)</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="td-modal-body"><div id="activityList" style="max-height:60vh;overflow:auto;font-size:13px">Loading…</div></div>
            <div class="td-modal-footer d-flex justify-content-end"><button class="td-btn-outline" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

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

<style>
.td-dt-table thead th { background: #fafbff; color: #6b6890; font-weight: 600; font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #e2e4f0; padding: 0.625rem 0.5rem; }
.td-dt-table tbody td { padding: 0.4rem 0.5rem; font-size: 0.8rem; border-bottom: 1px solid #f0f1f8; vertical-align: middle; }
.td-dt-table tbody tr { cursor: pointer; }
.td-dt-table tbody tr:hover { background: #f8f9ff; }
.badge-status { padding: 0.2rem 0.5rem; font-size: 0.68rem; font-weight: 600; border-radius: 0.3rem; }
.dataTables_wrapper .dataTables_length, .dataTables_wrapper .dataTables_filter { margin-bottom: 0.4rem; }
.dataTables_wrapper .dataTables_length { display: flex; align-items: center; gap: 0.5rem; white-space: nowrap; }
.dataTables_wrapper .dataTables_length label { margin-bottom: 0; }
.dataTables_wrapper .dataTables_filter { display: flex; align-items: center; justify-content: flex-end; }
.dataTables_wrapper .dataTables_filter label { margin-bottom: 0; display: flex; align-items: center; gap: 0.5rem; }
.dataTables_wrapper .dataTables_length select { border: 1px solid #e2e4f0; border-radius: 0.5rem; padding: 0.25rem 0.5rem; font-size: 0.75rem; }
.dataTables_wrapper .dataTables_filter input { border: 1px solid #e2e4f0; border-radius: 0.5rem; padding: 0.25rem 0.5rem; font-size: 0.75rem; }
.dataTables_wrapper .dataTables_info { font-size: 0.75rem; color: #6b6890; padding-top: 0.4rem; }
.dataTables_wrapper .dataTables_paginate { padding-top: 0.4rem; }
.dataTables_wrapper .dataTables_paginate .paginate_button { padding: 0.2rem 0.6rem; font-size: 0.75rem; border-radius: 0.3rem; }
.dataTables_wrapper .dataTables_paginate .paginate_button.current { background: #6366f1 !important; border-color: #6366f1 !important; color: #fff !important; }
.dataTables_wrapper .dataTables_paginate .paginate_button:hover { background: #f0f2ff; border-color: #d4d6e8; }
.buttons-collection { font-size: 0.75rem !important; padding: 0.3rem 0.65rem !important; border-radius: 0.5rem !important; }
.dropdown-menu .dropdown-item { font-size: 0.75rem; padding: 0.35rem 0.75rem; border-radius: 0.35rem; margin: 0.15rem 0.25rem; width: calc(100% - 0.5rem); }
.btn-group .btn { margin-right: 0.25rem; }
.btn-group .btn:last-child { margin-right: 0; }
.td-toolbar .btn-group + .btn-group { margin-left: 0.5rem; }
.td-toolbar .btn-group + .ms-auto { margin-left: auto; }
@media (max-width: 768px) {
    .td-toolbar .btn-group { margin-bottom: 0.5rem; }
    .td-toolbar .ms-auto { margin-left: 0 !important; width: 100%; justify-content: flex-start; }
}
</style>

<script>
const APP_BASE = <?= json_encode(APP_BASE) ?>;
const TEMP_URL = APP_BASE + '/modules/database/temporarydatabase_ajax/datatable.php';
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
        pageLength: 2500,
        lengthMenu: [ 250, 500, 1000, 1500, 2000 ],
        order: [[6, 'desc']],
        dom: '<"row align-items-center"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
        buttons: [
            {
                text: '<i class="bi bi-download"></i> Export',
                className: 'btn btn-outline-primary btn-sm dropdown-toggle',
                extend: 'collection',
                buttons: [
                    { extend: 'copy', text: '<i class="bi bi-clipboard me-1"></i> Copy', className: 'btn btn-outline-secondary btn-sm dropdown-item' },
                    { extend: 'csv', text: '<i class="bi bi-file-earmark-spreadsheet me-1"></i> CSV', className: 'btn btn-outline-success btn-sm dropdown-item', title: 'TemporaryDatabase_Export_' + new Date().toISOString().slice(0,10) },
                    { extend: 'excel', text: '<i class="bi bi-file-excel me-1"></i> Excel', className: 'btn btn-outline-info btn-sm dropdown-item' }
                ]
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
        return $('#tempTable input[type="checkbox"]:checked').length - ($('#selectAll').is(':checked') ? 1 : 0);
    }

    function updateSelectedCount() {
        const count = getSelectedCount();
        $('#selectedCount').text(count);
        $('#deleteSelected, #transferSelected').prop('disabled', count === 0);
    }

    function getSelectedIds() {
        const ids = [];
        $('#tempTable input[type="checkbox"]:checked').each(function() {
            if (!$(this).is('#selectAll')) {
                ids.push($(this).val());
            }
        });
        return ids;
    }

    $('#doAssign').on('click', function() {
        const userId = $('#assignUser').val();
        if (!userId) return showNotification('Error', 'Please select a user', 'danger');

        const ids = getSelectedIds();
        if (ids.length === 0) return showNotification('Warning', 'No records selected', 'warning');

        $.post(APP_BASE + '/modules/database/temporarydatabase_ajax/bulk_assign.php', { ids: ids, user_id: userId, csrf_token: CSRF_TOKEN }, function(res) {
            if (res.success) {
                table.ajax.reload();
                showNotification('Success!', `${ids.length} records assigned successfully`, 'success');
            } else {
                showNotification('Error', res.message || 'Failed', 'danger');
            }
        }, 'json');
    });

    function bulkDelete() {
        const ids = getSelectedIds();
        if (!ids.length) return showNotification('No Selection', 'Please select at least one record to delete.', 'warning');
        if (!confirm('Delete selected records permanently?')) return;
        const loadingToast = showNotification('Deleting...', 'Please wait while records are deleted.', 'info');
        $.post(APP_BASE + '/modules/database/temporarydatabase_ajax/bulk_delete.php', { ids: ids, csrf_token: CSRF_TOKEN }, function(res) {
            if (res.success) {
                table.ajax.reload();
                showNotification('Deleted!', `${ids.length} records removed`, 'danger');
            } else {
                showNotification('Error', res.error || 'Failed', 'danger');
            }
        }, 'json').fail(function() {
            showNotification('Error', 'Network error. Please try again.', 'danger');
        });
    }

    function loadEditModal(id) {
        $.post(location.href, { action:'view', id: id, csrf_token: CSRF_TOKEN }, function(resp){
            if (!resp.row) return showNotification('Error', 'Row not found', 'danger');
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
            $('#infoNotes').text(r.NOTES || '-');
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
            } else {
                $('#editError').show().text(resp.error || 'Update failed');
            }
        }, 'json').fail(function(){ $('#editError').show().text('Server error'); });
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
        if (!confirm('Delete ' + count + ' selected rows?')) return;
        const btn = $(this);
        const originalText = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Deleting...');
        const ids = getSelectedIds();
        $.post(location.href, { action:'delete_selected', ids: ids, csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.success) {
                showNotification('Deleted', 'Deleted ' + (resp.affected || count) + ' records', 'success');
                table.ajax.reload();
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
        if (!confirm('Transfer ' + count + ' selected rows to TBL_MAIN?')) return;
        const btn = $(this);
        const originalText = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Transferring...');
        const ids = getSelectedIds();
        $.post(location.href, { action:'transfer_selected', ids: ids, csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.success) { showNotification('Transferred', 'Affected: ' + (resp.affected || 'unknown'), 'success'); table.ajax.reload(); }
            else showNotification('Error', resp.error || 'unknown', 'danger');
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

        const btn = $(this);
        const originalText = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Transferring all...');

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
                            '<br><button class="btn btn-sm btn-outline-success mt-2 close-modal-btn">Close</button>'
                        );
                        btn.prop('disabled', false).html(originalText);
                        showNotification('Transfer Complete', resp.result?.message || 'All records transferred', 'success');
                    } else if (resp.status === 'error') {
                        progressBar.css('width', resp.percent + '%').removeClass('bg-primary bg-success').addClass('bg-danger');
                        messageText.text('Transfer failed');
                        resultDiv.show().addClass('alert-danger').html(
                            '<strong>Error:</strong> ' + (resp.error || 'Unknown error') +
                            '<br><button class="btn btn-sm btn-outline-danger mt-2 close-modal-btn">Close</button>'
                        );
                        btn.prop('disabled', false).html(originalText);
                        showNotification('Transfer Failed', resp.error || 'Unknown error', 'danger');
                    } else {
                        progressBar.css('width', resp.percent + '%').removeClass('bg-success bg-danger').addClass('bg-primary');
                        percentText.text(resp.percent + '%');
                        messageText.text(resp.message || 'Processing...');
                        setTimeout(processNextBatch, 10);
                    }
                }, 'json').fail(function(){
                    progressBar.addClass('bg-danger');
                    messageText.text('Network error');
                    resultDiv.show().addClass('alert-danger').html('<strong>Error:</strong> Network error during transfer.<br><button class="btn btn-sm btn-outline-danger mt-2 close-modal-btn">Close</button>');
                    btn.prop('disabled', false).html(originalText);
                    showNotification('Error', 'Network error during transfer', 'danger');
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
        if (!confirm('This will DELETE ALL rows in TBL_TEMP - are you sure?')) return;
        const token = prompt('Type YES_DELETE_ALL to confirm');
        if (token !== 'YES_DELETE_ALL') return showNotification('Cancelled', 'Operation not confirmed.', 'warning');
        const btn = $(this);
        const originalText = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Deleting all...');
        $.post(location.href, { action:'delete_all', confirm: token, csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.success) { showNotification('Deleted', 'All records removed', 'success'); table.ajax.reload(); }
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
        $.get(APP_BASE + '/modules/database/temporarydatabase_ajax/get_total_count.php', function (total) {
            total = parseInt(total);
            if (total === 0) return showNotification('Empty', 'No data found', 'info');

            if (total > 100000 && !confirm(`Warning: ${total.toLocaleString()} records!\n\nThis will download in multiple large CSV files.\n\nContinue?`)) {
                return;
            }

            const win = window.open(APP_BASE + '/modules/database/temporarydatabase_ajax/download_bach.php', '_blank');
            if (win) {
                showNotification('Export Started', `${total.toLocaleString()} records → downloading in parts`, 'success');
            } else {
                showNotification('Popup Blocked!', 'Please allow popups', 'danger');
            }
        });
    });

    $('#activityModal').on('show.bs.modal', function(){
        $('#activityList').html('<div class="small text-muted">Loading…</div>');
        $.post(location.href, { action:'fetch_activity', csrf_token: CSRF_TOKEN }, function(resp){
            if (resp.error) { $('#activityList').html('<div class="text-danger small">' + resp.error + '</div>'); return; }
            let out = '<div class="list-group">';
            for (const a of resp.activities || []) {
                out += `<div class="list-group-item small" style="border:1px solid #e2e4f0;border-radius:0.5rem;margin-bottom:0.3rem;">
                  <div><strong>${escapeHtml(a.ACTION_TYPE)}</strong> — <span class="text-muted">${escapeHtml(a.LOG_TIME)}</span></div>
                  <div class="text-muted small">User: ${escapeHtml(a.USER_ID)} | IP: ${escapeHtml(a.IP_ADDRESS)} | Target: ${escapeHtml(a.TARGET_TABLE)}</div>
                  <div class="mt-1">${escapeHtml(a.ACTION_DETAILS)} <span class="text-muted">[IDs: ${escapeHtml(a.AFFECTED_IDS||'')}]</span></div>
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

});
</script>

<div class="modal fade" id="transferProgressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-arrow-down-circle me-2"></i>Transfer to Main Database</h5>
                <button type="button" class="btn-close close-modal-btn" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between mb-1">
                    <span class="small text-muted">Progress</span>
                    <span id="transferPercent" class="small fw-semibold">0%</span>
                </div>
                <div class="progress mb-3" style="height: 20px;">
                    <div id="transferProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                </div>
                <p class="mb-2 small text-muted" id="transferMessage">Initializing transfer...</p>
                <div id="transferResult" class="mt-3 alert" style="display:none"></div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../php_scripts/footer.php'; ?>
