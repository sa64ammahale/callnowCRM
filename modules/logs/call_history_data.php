<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
require_once __DIR__ . '/../../php_scripts/team_auth.php';
requirePermission('view_reports');

if (!function_exists('ensureCallHistorySchema')) {
    function ensureCallHistorySchema(mysqli $link): void {
        ensureApiSchema($link);
        $res = mysqli_query($link, "SHOW COLUMNS FROM " . tn('TBL_CALL_LOGS'));
        $existing = [];
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $existing[strtolower($r['Field'])] = true;
            }
        }
        if (!isset($existing['recording_url'])) {
            @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN recording_url VARCHAR(500) NULL AFTER notes");
        }
        if (!isset($existing['next_followup'])) {
            @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN next_followup DATETIME NULL AFTER recording_url");
        }
        if (!isset($existing['call_time'])) {
            @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN call_time DATETIME NULL AFTER next_followup");
        }
        if (!isset($existing['record_id'])) {
            if (isset($existing['lead_id'])) {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " CHANGE COLUMN lead_id record_id INT NOT NULL DEFAULT 0");
            } else {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN record_id INT NOT NULL DEFAULT 0 AFTER id");
            }
        }
        if (!isset($existing['source'])) {
            if (isset($existing['call_type'])) {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " CHANGE COLUMN call_type source ENUM('TEMP','MAIN','LEADS') NOT NULL DEFAULT 'MAIN'");
            } else {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN source ENUM('TEMP','MAIN','LEADS') NOT NULL DEFAULT 'MAIN' AFTER record_id");
            }
        }
        if (!isset($existing['call_status'])) {
            if (isset($existing['status'])) {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " CHANGE COLUMN status call_status VARCHAR(50) NOT NULL DEFAULT 'Connected'");
            } else {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN call_status VARCHAR(50) NOT NULL DEFAULT 'Connected' AFTER user_id");
            }
        }
        if (!isset($existing['call_duration'])) {
            if (isset($existing['duration_seconds'])) {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " CHANGE COLUMN duration_seconds call_duration INT NOT NULL DEFAULT 0");
            } else {
                @mysqli_query($link, "ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN call_duration INT NOT NULL DEFAULT 0 AFTER call_status");
            }
        }
    }
}
ensureCallHistorySchema($link);

/* DataTables params */
$draw    = intval($_GET['draw'] ?? 1);
$start   = intval($_GET['start'] ?? 0);
$length  = intval($_GET['length'] ?? 25);
$search  = trim($_GET['search']['value'] ?? '');
$orderCol = intval($_GET['order'][0]['column'] ?? 0);
$orderDir = strtoupper($_GET['order'][0]['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

/* Existing page filters */
$sourceFilter = $_GET['source'] ?? 'all';
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';

$whereParts = ['1=1'];
$params = [];
$types = '';

if (in_array($sourceFilter, ['TEMP', 'MAIN', 'LEADS'])) {
    $whereParts[] = 'cl.source = ?';
    $params[] = $sourceFilter;
    $types .= 's';
}
if ($statusFilter !== '') {
    $whereParts[] = 'cl.call_status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}
if ($dateFrom) {
    $whereParts[] = 'DATE(cl.created_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo) {
    $whereParts[] = 'DATE(cl.created_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}
if ($search !== '') {
    $whereParts[] = '(COALESCE(t.CUST_NAME, m.MAINDATABASE_NAME, l.NAME, "") LIKE ? OR COALESCE(t.CUST_MOBILE, m.MAINDATABASE_MOBILE, l.MOBILE, "") LIKE ? OR cl.notes LIKE ?)';
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if (isOfficer()) {
    $whereParts[] = 'u.ID = ?';
    $params[] = USER_ID;
    $types .= 'i';
} elseif (!isAdmin() && !isSuperAdmin()) {
    $accIds = getAccessibleUserIds($link);
    if (!empty($accIds)) {
        $whereParts[] = 'u.ID IN (' . implode(',', array_map('intval', $accIds)) . ')';
    } else {
        $whereParts[] = 'u.ID = ?';
        $params[] = USER_ID;
        $types .= 'i';
    }
}
if (!isSuperAdmin()) {
    $whereParts[] = "u.ROLE != 'Super Admin'";
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

/* Total (unfiltered) count */
$totalSql = "SELECT COUNT(*) AS c FROM " . tn('TBL_CALL_LOGS') . " cl JOIN " . tn('TBL_USERS') . " u ON cl.user_id = u.ID";
$totalStmt = mysqli_prepare($link, $totalSql);
mysqli_stmt_execute($totalStmt);
$total = (int)mysqli_stmt_get_result($totalStmt)->fetch_assoc()['c'];
mysqli_stmt_close($totalStmt);

/* Filtered count */
$filteredSql = "
    SELECT COUNT(*) AS c
    FROM " . tn('TBL_CALL_LOGS') . " cl
    JOIN " . tn('TBL_USERS') . " u ON cl.user_id = u.ID
    $whereSql
";
$filteredStmt = mysqli_prepare($link, $filteredSql);
if ($types !== '') {
    mysqli_stmt_bind_param($filteredStmt, $types, ...$params);
}
mysqli_stmt_execute($filteredStmt);
$filtered = (int)mysqli_stmt_get_result($filteredStmt)->fetch_assoc()['c'];
mysqli_stmt_close($filteredStmt);

/* Order column whitelist mapped to DB column */
$orderMap = [
    0 => 'cl.created_at',
    1 => 'cust_name',
    2 => 'cl.source',
    3 => 'cl.call_status',
    4 => 'cl.call_duration',
    5 => 'cl.notes',
    6 => 'u.NAME',
    7 => 'cl.next_followup',
    8 => 'cl.recording_url',
];
$orderBy = isset($orderMap[$orderCol]) ? $orderMap[$orderCol] : 'cl.created_at';
$orderSql = 'ORDER BY ' . $orderBy . ' ' . $orderDir;

/* Data fetch */
$dataSql = "
    SELECT
        cl.id,
        cl.record_id,
        cl.source,
        cl.call_status,
        cl.call_duration,
        cl.total_duration,
        cl.timing_classification,
        cl.notes,
        cl.metadata,
        cl.next_followup,
        cl.recording_url,
        cl.sim_slot,
        cl.call_time,
        cl.dial_time,
        cl.call_end_time,
        cl.created_at,
        u.NAME AS user_name,
        COALESCE(t.CUST_NAME, m.MAINDATABASE_NAME, l.NAME) AS cust_name,
        COALESCE(t.CUST_MOBILE, m.MAINDATABASE_MOBILE, l.MOBILE) AS cust_mobile,
        COALESCE(t.CUST_COMPANY, m.MAINDATABASE_COMPANY, l.COMPANY_NAME) AS cust_company
    FROM " . tn('TBL_CALL_LOGS') . " cl
    JOIN " . tn('TBL_USERS') . " u ON cl.user_id = u.ID
    LEFT JOIN " . tn('TBL_TEMP') . " t ON cl.source = 'TEMP' AND cl.record_id = t.ID
    LEFT JOIN " . tn('TBL_MAIN') . " m ON cl.source = 'MAIN' AND cl.record_id = m.ID
    LEFT JOIN " . tn('TBL_LEADS') . " l ON cl.source = 'LEADS' AND cl.record_id = l.lead_id
    $whereSql
    $orderSql
";

$dataParams = $params;
$dataTypes = $types;
if ($length > 0) {
    $dataSql .= ' LIMIT ?, ?';
    $dataParams[] = $start;
    $dataParams[] = $length;
    $dataTypes .= 'ii';
}

$dataStmt = mysqli_prepare($link, $dataSql);
if ($dataStmt === false) {
    $err = mysqli_error($link);
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed: ' . htmlspecialchars($err ?: 'Unknown database error')]);
    exit;
}
if ($dataTypes !== '') {
    $bindNames = []; $bindNames[] = $dataTypes;
    foreach ($dataParams as $p) $bindNames[] = $p;
    $refs = []; foreach ($bindNames as $k => $v) $refs[$k] = &$bindNames[$k];
    call_user_func_array([$dataStmt, 'bind_param'], $refs);
}
mysqli_stmt_execute($dataStmt);
$result = mysqli_stmt_get_result($dataStmt);

$rows = [];
while ($r = mysqli_fetch_assoc($result)) {
    $rows[] = [
        $r['created_at'],
        $r['cust_name'],
        $r['source'],
        $r['call_status'],
        $r['call_duration'],
        $r['notes'],
        $r['user_name'],
        $r['next_followup'],
        $r['recording_url'],
        $r['cust_mobile'],
        $r['cust_company'],
        $r['timing_classification'],
        $r['total_duration'],
        $r['sim_slot'],
        $r['dial_time'],
        $r['call_end_time'],
        $r['metadata'],
        $r['id'],
    ];
}
mysqli_stmt_close($dataStmt);

header('Content-Type: application/json');
echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $total,
    'recordsFiltered' => $filtered,
    'data' => $rows
]);
