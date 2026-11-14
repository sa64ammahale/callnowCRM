<?php
require_once __DIR__ . '/../../php_scripts/auth.php';
requirePermission('manage_database');

ensureCsrfToken();
$csrf = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(16));
$_SESSION['csrf_token'] = $csrf;
$csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . '">';

/* DataTables params */
$draw    = intval($_GET['draw'] ?? 1);
$start   = intval($_GET['start'] ?? 0);
$length  = intval($_GET['length'] ?? 25);
$search  = trim($_GET['search']['value'] ?? '');
$dbFilter = strtoupper(trim($_GET['source'] ?? 'ALL'));
if (!in_array($dbFilter, ['ALL', 'TEMP', 'MAIN'], true)) {
    $dbFilter = 'ALL';
}

/* Build union query source filters (same as ViewDND.php) */
$unionQueries = [];
$dndStatuses = "('do not call', 'Do Not Call', 'dnd', 'DND', 'dnc', 'DNC', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')";

if ($dbFilter === 'ALL' || $dbFilter === 'TEMP') {
    $unionQueries[] = "
        SELECT 
            ID, 
            CUST_NAME AS name, 
            CUST_MOBILE AS mobile, 
            CUST_COMPANY AS company, 
            CALL_DIALED_STATUS AS status,
            'TEMP' AS source, 
            COALESCE(LAST_DIALED_DATE_TIME, TEMP_UPLOAD_DATETIME) AS marked_at 
        FROM " . tn('TBL_TEMP') . " 
        WHERE CALL_DIALED_STATUS IN $dndStatuses
    ";
}
if ($dbFilter === 'ALL' || $dbFilter === 'MAIN') {
    $unionQueries[] = "
        SELECT 
            ID, 
            MAINDATABASE_NAME AS name, 
            MAINDATABASE_MOBILE AS mobile, 
            MAINDATABASE_COMPANY AS company, 
            MAINDATABASE_CALL_DIALED_STATUS AS status,
            'MAIN' AS source, 
            COALESCE(MAINDATABASE_CALL_DIAL_TIME, LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME) AS marked_at 
        FROM " . tn('TBL_MAIN') . " 
        WHERE MAINDATABASE_CALL_DIALED_STATUS IN $dndStatuses
    ";
}

if (empty($unionQueries)) {
    header('Content-Type: application/json');
    echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
    exit;
}

$baseUnionSql = implode(" UNION ALL ", $unionQueries);

/* Search condition (same as ViewDND.php) */
$searchCondition = "";
$searchParams = [];
$searchTypes = "";

if ($search !== '') {
    $cleanDigits = preg_replace('/\D/', '', $search);
    if ($cleanDigits !== '') {
        $searchCondition = "AND (mobile LIKE ? OR name LIKE ? OR company LIKE ?)";
        $searchParams = ["%$cleanDigits%", "%$search%", "%$search%"];
        $searchTypes = "sss";
    } else {
        $searchCondition = "AND (name LIKE ? OR company LIKE ?)";
        $searchParams = ["%$search%", "%$search%"];
        $searchTypes = "ss";
    }
}

/* Total (unfiltered) count */
$totalSql = "SELECT COUNT(*) FROM ($baseUnionSql) AS combined WHERE 1=1";
$totalStmt = mysqli_prepare($link, $totalSql);
mysqli_stmt_execute($totalStmt);
$total = (int)mysqli_stmt_get_result($totalStmt)->fetch_row()[0];
mysqli_stmt_close($totalStmt);

/* Filtered count */
$filteredSql = "SELECT COUNT(*) FROM ($baseUnionSql) AS combined WHERE 1=1 $searchCondition";
$filteredStmt = mysqli_prepare($link, $filteredSql);
if (!empty($searchParams)) {
    mysqli_stmt_bind_param($filteredStmt, $searchTypes, ...$searchParams);
}
mysqli_stmt_execute($filteredStmt);
$filtered = (int)mysqli_stmt_get_result($filteredStmt)->fetch_row()[0];
mysqli_stmt_close($filteredStmt);

/* Data fetch */
$dataSql = "
    SELECT ID, name, mobile, company, status, source, marked_at 
    FROM ($baseUnionSql) AS combined 
    WHERE 1=1 $searchCondition 
    ORDER BY marked_at DESC, ID DESC
";
$dataParams = $searchParams;
$dataTypes = $searchTypes;
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
    $bindNames = [$dataTypes];
    foreach ($dataParams as $p) $bindNames[] = $p;
    $refs = []; foreach ($bindNames as $k => $v) $refs[$k] = &$bindNames[$k];
    call_user_func_array([$dataStmt, 'bind_param'], $refs);
}
mysqli_stmt_execute($dataStmt);
$result = mysqli_stmt_get_result($dataStmt);

$rows = [];
$isAdmin = isAdmin() || isSuperAdmin();
while ($r = mysqli_fetch_assoc($result)) {
    /* Actions column HTML (same forms as ViewDND.php) */
    $id = intval($r['ID']);
    $mobile = htmlspecialchars($r['mobile'] ?? '', ENT_QUOTES, 'UTF-8');
    $source = htmlspecialchars($r['source'] ?? '', ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars($r['name'] ?? '', ENT_QUOTES, 'UTF-8');

    $actions = '<div class="d-inline-flex align-items-center gap-1">';
    $actions .= '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 fs-xs"><i class="bi bi-shield-lock me-1"></i>Permanent DND</span>';
    /* Delete form (Admin only) */
    if ($isAdmin) {
        $actions .= '<form method="POST" class="d-inline" onsubmit="return confirm(\'Delete this DND record permanently?\');">';
        $actions .= $csrfField;
        $actions .= '<input type="hidden" name="action" value="delete">';
        $actions .= '<input type="hidden" name="id" value="' . $id . '">';
        $actions .= '<input type="hidden" name="source" value="' . $source . '">';
        $actions .= '<button type="submit" class="btn btn-outline-danger btn-sm py-1 px-2 fs-xs" title="Delete DND Record"><i class="bi bi-trash3 me-1 text-danger"></i> Delete</button>';
        $actions .= '</form>';
    }
    $actions .= '</div>';

    /* Date formatting */
    $marked = '-';
    if (!empty($r['marked_at']) && $r['marked_at'] !== '0000-00-00 00:00:00') {
        $t = strtotime($r['marked_at']);
        if ($t) {
            $marked = '<span class="fw-semibold text-dark fs-xs">' . date('d M Y', $t) . '</span> <span class="text-muted fs-xs">' . date('h:i A', $t) . '</span>';
        } else {
            $marked = htmlspecialchars($r['marked_at']);
        }
    }

    $rows[] = [
        $r['name'] ?: '—',
        '<span class="font-monospace fs-xs">' . ($r['mobile'] ?: '—') . '</span>',
        htmlspecialchars($r['company'] ?: '—', ENT_QUOTES, 'UTF-8'),
        $r['source'],
        $marked,
        $actions,
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
