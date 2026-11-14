<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../lead_common.php';

api_init();
requirePermission('manage_leads');
ensureLeadModuleSchema($link);

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($csrfToken)) {
    api_error('Invalid session token', 403);
}

$pipelineStages = ['LEAD', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT'];
$statusMeta = leadStatusMeta();
$canMoveStage = canMoveLeadStage();

if ($action === 'move_stage') {
    if (!$canMoveStage) {
        api_error('Only Super Admin, Admin, and Supervisor can move lead stages.', 403);
    }

    $leadId = (int)($_POST['lead_id'] ?? 0);
    $newStage = strtoupper(trim((string)($_POST['new_stage'] ?? '')));

    if ($leadId <= 0 || !in_array($newStage, $pipelineStages, true)) {
        api_error('Invalid lead or target stage', 400);
    }

    $stmt = mysqli_prepare($link, "SELECT lead_id, assigned_to, team_id, lead_status_new FROM " . tn('TBL_LEADS') . " WHERE lead_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $leadId);
    mysqli_stmt_execute($stmt);
    $lead = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if (!$lead || !canViewLead($link, $lead)) {
        api_error('Lead not found or access denied', 404);
    }
    if (!canEditLead($lead)) {
        api_error('This lead is not in your accessible tray', 403);
    }

    $prevStage = strtoupper(trim((string)($lead['lead_status_new'] ?? 'LEAD')));
    if ($prevStage !== $newStage) {
        $upd = mysqli_prepare($link, "UPDATE " . tn('TBL_LEADS') . " SET lead_status_new = ?, updated_at = NOW() WHERE lead_id = ?");
        mysqli_stmt_bind_param($upd, 'si', $newStage, $leadId);
        if (!mysqli_stmt_execute($upd)) {
            api_error('Failed to update lead stage', 500);
        }
        mysqli_stmt_close($upd);

        logActivity($link, USER_ID, 'UPDATE', "Moved lead #{$leadId} from {$prevStage} to {$newStage} via Pipeline Board", (string)$leadId, tn('TBL_LEADS'));
    }

    // Return updated stage counts
    $scopeWhere = '';
    $accessibleUserIds = getAccessibleUserIds($link);
    if (!isAdmin() && !isSuperAdmin()) {
        if (!empty($accessibleUserIds)) {
            $scopeWhere = 'WHERE assigned_to IN (' . implode(',', array_map('intval', $accessibleUserIds)) . ')';
        } else {
            $scopeWhere = 'WHERE assigned_to = ' . (int)USER_ID;
        }
    }
    $cntRes = mysqli_query($link, "SELECT lead_status_new, COUNT(*) as cnt FROM " . tn('TBL_LEADS') . " {$scopeWhere} GROUP BY lead_status_new");
    $stageCounts = [];
    $totalActive = 0;
    $totalAll = 0;
    while ($r = mysqli_fetch_assoc($cntRes)) {
        $st = strtoupper((string)$r['lead_status_new']);
        $c = (int)$r['cnt'];
        $stageCounts[$st] = $c;
        $totalAll += $c;
        if ($st !== 'REJECT') {
            $totalActive += $c;
        }
    }

    api_send_json([
        'success' => true,
        'lead_id' => $leadId,
        'prev_stage' => $prevStage,
        'new_stage' => $newStage,
        'stage_label' => $statusMeta[$newStage]['label'] ?? $newStage,
        'counts' => $stageCounts,
        'total_active' => $totalActive,
        'total_all' => $totalAll,
        'message' => "Lead #{$leadId} moved to " . ($statusMeta[$newStage]['label'] ?? $newStage)
    ]);
}

if ($action === 'load_more') {
    $stage = strtoupper(trim((string)($_POST['stage'] ?? $_GET['stage'] ?? 'LEAD')));
    if (!in_array($stage, $pipelineStages, true)) {
        api_error('Invalid stage parameter', 400);
    }

    $offset = max(0, (int)($_POST['offset'] ?? $_GET['offset'] ?? 0));
    $limit = min(50, max(1, (int)($_POST['limit'] ?? $_GET['limit'] ?? 20)));
    $search = trim((string)($_POST['search'] ?? $_GET['search'] ?? ''));

    $whereClauses = ["l.lead_status_new = ?"];
    $types = 's';
    $params = [$stage];

    $accessibleUserIds = getAccessibleUserIds($link);
    if (!isAdmin() && !isSuperAdmin()) {
        if (!empty($accessibleUserIds)) {
            $inU = implode(',', array_map('intval', $accessibleUserIds));
            $whereClauses[] = "l.assigned_to IN ({$inU})";
        } else {
            $whereClauses[] = "l.assigned_to = " . (int)USER_ID;
        }
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $whereClauses[] = "(m.MAINDATABASE_NAME LIKE ? OR l.NAME LIKE ? OR m.MAINDATABASE_MOBILE LIKE ? OR l.MOBILE LIKE ? OR m.MAINDATABASE_COMPANY LIKE ? OR l.COMPANY_NAME LIKE ? OR l.remarks LIKE ? OR CAST(l.lead_id AS CHAR) = ?)";
        $types .= 'ssssssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $search;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

    // Get total count matching stage + search
    $countSql = "SELECT COUNT(*) FROM " . tn('TBL_LEADS') . " l LEFT JOIN " . tn('TBL_MAIN') . " m ON m.ID = l.cust_id {$whereSql}";
    $stmtCnt = mysqli_prepare($link, $countSql);
    mysqli_stmt_bind_param($stmtCnt, $types, ...$params);
    mysqli_stmt_execute($stmtCnt);
    $totalCount = (int)(mysqli_stmt_get_result($stmtCnt)->fetch_row()[0] ?? 0);
    mysqli_stmt_close($stmtCnt);

    // Fetch batch rows
    $sql = "
        SELECT
            l.lead_id, l.lead_status_new, l.login_mode, l.loan_type, l.loan_amount,
            l.loan_app_no, l.login_bank_name, l.login_date, l.net_salary, l.remarks,
            l.next_followup_at, l.created_at, l.updated_at, l.loan_tenure,
            l.salary_account, l.bank_name, l.bank_rm_name, l.bt_details, l.dsa_name,
            l.login_location, l.promo_code, l.followup_count, l.last_contacted_at,
            l.rework_flag, l.rework_stage, l.login_status,
            COALESCE(NULLIF(m.MAINDATABASE_NAME, ''), NULLIF(l.NAME, ''), CONCAT('Lead #', l.lead_id)) AS customer_name,
            COALESCE(NULLIF(m.MAINDATABASE_COMPANY, ''), NULLIF(l.COMPANY_NAME, ''), '') AS company_name,
            COALESCE(NULLIF(m.MAINDATABASE_MOBILE, ''), NULLIF(l.MOBILE, ''), 'N/A') AS mobile,
            COALESCE(u.NAME, 'Unassigned') AS assigned_name
        FROM " . tn('TBL_LEADS') . " l
        LEFT JOIN " . tn('TBL_MAIN') . " m ON m.ID = l.cust_id
        LEFT JOIN " . tn('TBL_USERS') . " u ON u.ID = l.assigned_to
        {$whereSql}
        ORDER BY l.updated_at DESC, l.lead_id DESC
        LIMIT ? OFFSET ?
    ";
    $typesWithPaging = $types . 'ii';
    $paramsWithPaging = array_merge($params, [$limit, $offset]);
    $stmtRows = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmtRows, $typesWithPaging, ...$paramsWithPaging);
    mysqli_stmt_execute($stmtRows);
    $res = mysqli_stmt_get_result($stmtRows);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmtRows);

    $html = '';
    foreach ($rows as $row) {
        $html .= renderPipelineCard($row, $stage, $canMoveStage);
    }

    $fetchedCount = count($rows);
    $remaining = max(0, $totalCount - ($offset + $fetchedCount));

    api_send_json([
        'success' => true,
        'stage' => $stage,
        'html' => $html,
        'count' => $fetchedCount,
        'offset' => $offset,
        'limit' => $limit,
        'remaining' => $remaining,
        'total' => $totalCount
    ]);
}

api_error('Invalid action', 400);

