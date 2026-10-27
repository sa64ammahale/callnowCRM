<?php
/**
 * Telecaller API Endpoints
 * GET /api/v1/telecaller/dashboard - Get telecaller dashboard stats
 * GET /api/v1/telecaller/batch-leads - Get batch of leads (e.g. 15) for calling queue
 * GET /api/v1/telecaller/next-call - Get next call to make (fallback single lead)
 * GET /api/v1/telecaller/reports/summary - Get telecaller performance analytics & metrics
 * GET /api/v1/telecaller/calls - List dialed call history with rich details
 * POST /api/v1/telecaller/calls/{id}/complete - Submit call result & full analytics
 * GET /api/v1/telecaller/call-history/{id} - Call history for specific record
 */
require_once __DIR__ . '/../../../php_scripts/api_auth.php';

header('Content-Type: application/json; charset=utf-8');

ensureApiSchema($GLOBALS['link']);

// Self-heal: ensure timing_classification column exists on TBL_CALL_LOGS
try {
    $resCol = @$GLOBALS['link']->query("SHOW COLUMNS FROM " . tn('TBL_CALL_LOGS') . " LIKE 'timing_classification'");
    if ($resCol && $resCol->num_rows === 0) {
        @$GLOBALS['link']->query("ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN timing_classification VARCHAR(30) NULL DEFAULT NULL AFTER call_duration");
    }
} catch (Throwable $t) {}

$tokenData = $GLOBALS['api_token_data'];
$userId = (int)$tokenData['user_id'];
$userRole = $tokenData['user_role'] ?? 'Telecaller';
$teamId = !empty($tokenData['user_team_id']) ? (int)$tokenData['user_team_id'] : null;

global $link;

try {

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// Normalize path to support any root or subfolder prefix
$pos = strpos($path, '/api/v1/telecaller');
$relativePath = ($pos !== false) ? substr($path, $pos + strlen('/api/v1/telecaller')) : $path;
$relativePath = '/' . trim($relativePath, '/');

// Helper to build date condition for reports & calls
function buildDateCondition(string $period, ?string $dateFrom, ?string $dateTo, string $col = 'created_at'): string {
    switch (strtolower($period)) {
        case 'yesterday':
            return "DATE($col) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        case 'this_week':
            return "YEARWEEK($col, 1) = YEARWEEK(CURDATE(), 1)";
        case 'this_month':
            return "YEAR($col) = YEAR(CURDATE()) AND MONTH($col) = MONTH(CURDATE())";
        case 'custom':
            if ($dateFrom && $dateTo) {
                $f = preg_replace('/[^0-9\-]/', '', $dateFrom);
                $t = preg_replace('/[^0-9\-]/', '', $dateTo);
                return "(DATE($col) >= '$f' AND DATE($col) <= '$t')";
            } elseif ($dateFrom) {
                $f = preg_replace('/[^0-9\-]/', '', $dateFrom);
                return "DATE($col) >= '$f'";
            }
            return "DATE($col) = CURDATE()";
        case 'all':
            return "1=1";
        case 'today':
        default:
            return "DATE($col) = CURDATE()";
    }
}

// -----------------------------------------------------------------------------
// 1. DASHBOARD STATS
// -----------------------------------------------------------------------------
if ($relativePath === '/dashboard' || $relativePath === '/dashboard/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    // Calculate today's stats from call_logs for this user
    $sqlLogs = "SELECT 
        COUNT(*) as total_calls,
        SUM(CASE WHEN call_duration > 0 THEN 1 ELSE 0 END) as connected_calls,
        SUM(call_duration) as total_talk_time
    FROM " . tn('TBL_CALL_LOGS') . "
    WHERE user_id = ? AND DATE(created_at) = CURDATE()";
    
    $stmt = $link->prepare($sqlLogs);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $logRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $todayCalls = (int)($logRow['total_calls'] ?? 0);
    $todayConnected = (int)($logRow['connected_calls'] ?? 0);
    $todayTalkTime = (int)($logRow['total_talk_time'] ?? 0);
    
    // Calculate pending queue count
    $pendingCount = 0;
    
    // Check temporary table pending
    $sqlTemp = "SELECT COUNT(*) as c FROM " . tn('TBL_TEMP') . " 
        WHERE (CALL_DIALED_TELECALLER = ? OR CALL_DIALED_TELECALLER IS NULL)
        AND (CALL_DIALED_STATUS IS NULL OR CALL_DIALED_STATUS = '' OR CALL_DIALED_STATUS = 'Pending' OR CALL_DIALED_STATUS = 'Not Called')";
    $stmt = $link->prepare($sqlTemp);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $tempRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $pendingCount += (int)($tempRow['c'] ?? 0);
    
    // Check main table pending
    $sqlMain = "SELECT COUNT(*) as c FROM " . tn('TBL_MAIN') . " 
        WHERE (MAINDATABASE_CALL_DIALED_USER = ? OR MAINDATABASE_CALL_DIALED_USER IS NULL)
        AND (MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR MAINDATABASE_CALL_DIALED_STATUS = 'Pending' OR MAINDATABASE_CALL_DIALED_STATUS IS NULL OR MAINDATABASE_CALL_DIALED_STATUS = '')";
    $stmt = $link->prepare($sqlMain);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $mainRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $pendingCount += (int)($mainRow['c'] ?? 0);
    
    apiSuccess([
        'today_calls' => $todayCalls,
        'today_connected' => $todayConnected,
        'today_talk_time' => $todayTalkTime,
        'today_pending' => $pendingCount,
        'total_assigned' => $todayCalls + $pendingCount,
    ]);

// -----------------------------------------------------------------------------
// 2. BATCH LEADS FETCH (e.g. 15 leads for calling queue)
// -----------------------------------------------------------------------------
} elseif ($relativePath === '/batch-leads' || $relativePath === '/batch-leads/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 15)));
    $leads = [];
    
    // 1. Try temporary database first
    $sqlTemp = "SELECT 
        ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO,
        CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, TEMP_UPLOAD_DATETIME
    FROM " . tn('TBL_TEMP') . " 
    WHERE (CALL_DIALED_TELECALLER = ? OR CALL_DIALED_TELECALLER IS NULL)
    AND (CALL_DIALED_STATUS IS NULL OR CALL_DIALED_STATUS = '' OR CALL_DIALED_STATUS = 'Pending' OR CALL_DIALED_STATUS = 'Not Called')
    ORDER BY TEMP_UPLOAD_DATETIME ASC, ID ASC
    LIMIT ?";
    
    $stmt = $link->prepare($sqlTemp);
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    $tempRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $tempIdsToLock = [];
    foreach ($tempRows as $row) {
        $leads[] = [
            'id' => (int)$row['ID'],
            'record_id' => (int)$row['ID'],
            'source' => 'TEMP',
            'name' => $row['CUST_NAME'] ?? '',
            'mobile' => $row['CUST_MOBILE'] ?? '',
            'company' => $row['CUST_COMPANY'] ?? '',
            'package' => $row['CUST_PACKAGE'] ?? '',
            'other_info' => $row['CUST_OTHER_INFO'] ?? '',
            'last_status' => $row['CALL_DIALED_STATUS'] ?? 'Not Called',
            'last_call_time' => $row['LAST_DIALED_DATE_TIME'] ?? null,
            'upload_time' => $row['TEMP_UPLOAD_DATETIME'] ?? null,
        ];
        $tempIdsToLock[] = (int)$row['ID'];
    }
    
    // Lock temp records to current user
    if (!empty($tempIdsToLock)) {
        $inClause = implode(',', $tempIdsToLock);
        $link->query("UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_TELECALLER = $userId WHERE ID IN ($inClause)");
    }
    
    // 2. If temporary database yielded fewer than $limit, fill the remainder from main_database
    $remaining = $limit - count($leads);
    if ($remaining > 0) {
        $sqlMain = "SELECT 
            ID, MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, 
            MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS,
            LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME
        FROM " . tn('TBL_MAIN') . "
        WHERE (MAINDATABASE_CALL_DIALED_USER = ? OR MAINDATABASE_CALL_DIALED_USER IS NULL)
        AND (MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR MAINDATABASE_CALL_DIALED_STATUS = 'Pending' OR MAINDATABASE_CALL_DIALED_STATUS IS NULL OR MAINDATABASE_CALL_DIALED_STATUS = '')
        ORDER BY ID ASC
        LIMIT ?";
        
        $stmt = $link->prepare($sqlMain);
        $stmt->bind_param('ii', $userId, $remaining);
        $stmt->execute();
        $mainRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $mainIdsToLock = [];
        foreach ($mainRows as $row) {
            $leads[] = [
                'id' => (int)$row['ID'],
                'record_id' => (int)$row['ID'],
                'source' => 'MAIN',
                'name' => $row['MAINDATABASE_NAME'] ?? '',
                'mobile' => $row['MAINDATABASE_MOBILE'] ?? '',
                'company' => $row['MAINDATABASE_COMPANY'] ?? '',
                'package' => $row['MAINDATABASE_PACKAGE'] ?? '',
                'other_info' => $row['MAINDATABASE_OTHER_INFO'] ?? '',
                'last_status' => $row['MAINDATABASE_CALL_DIALED_STATUS'] ?? 'Not Called',
                'last_call_time' => $row['LAST_DIALED_DATE_TIME'] ?? null,
                'upload_time' => $row['MAINDATABASE_UPLOAD_DATETIME'] ?? null,
            ];
            $mainIdsToLock[] = (int)$row['ID'];
        }
        
        // Atomically lock main records to current user
        if (!empty($mainIdsToLock)) {
            $inClause = implode(',', $mainIdsToLock);
            $link->query("UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_CALL_DIALED_USER = $userId WHERE ID IN ($inClause)");
        }
    }
    
    apiSuccess([
        'leads' => $leads,
        'count' => count($leads),
        'limit' => $limit,
    ]);

// -----------------------------------------------------------------------------
// 3. NEXT-CALL (Single Lead Fallback)
// -----------------------------------------------------------------------------
} elseif ($relativePath === '/next-call' || $relativePath === '/next-call/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    // Check temp first
    $sqlTemp = "SELECT 
        ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO,
        TEMP_UPLOAD_DATETIME, CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME
    FROM " . tn('TBL_TEMP') . " 
    WHERE (CALL_DIALED_TELECALLER = ? OR CALL_DIALED_TELECALLER IS NULL)
    AND (CALL_DIALED_STATUS IS NULL OR CALL_DIALED_STATUS = '' OR CALL_DIALED_STATUS = 'Pending' OR CALL_DIALED_STATUS = 'Not Called')
    ORDER BY TEMP_UPLOAD_DATETIME ASC, ID ASC
    LIMIT 1";
    $stmt = $link->prepare($sqlTemp);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($row) {
        $recId = (int)$row['ID'];
        $link->query("UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_TELECALLER = $userId WHERE ID = $recId");
        apiSuccess([
            'source' => 'TEMP',
            'record_id' => $recId,
            'customer' => [
                'name' => $row['CUST_NAME'] ?? '',
                'mobile' => $row['CUST_MOBILE'] ?? '',
                'company' => $row['CUST_COMPANY'] ?? '',
                'package' => $row['CUST_PACKAGE'] ?? '',
                'other_info' => $row['CUST_OTHER_INFO'] ?? '',
            ],
            'upload_time' => $row['TEMP_UPLOAD_DATETIME'],
            'last_status' => $row['CALL_DIALED_STATUS'] ?? 'Not Called',
            'last_call_time' => $row['LAST_DIALED_DATE_TIME'],
        ]);
    }
    
    // Try main database next
    $sqlMain = "SELECT 
        ID, MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY,
        MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS,
        LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME
    FROM " . tn('TBL_MAIN') . " 
    WHERE (MAINDATABASE_CALL_DIALED_USER = ? OR MAINDATABASE_CALL_DIALED_USER IS NULL)
    AND (MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR MAINDATABASE_CALL_DIALED_STATUS = 'Pending' OR MAINDATABASE_CALL_DIALED_STATUS IS NULL OR MAINDATABASE_CALL_DIALED_STATUS = '')
    ORDER BY ID ASC
    LIMIT 1";
    $stmt = $link->prepare($sqlMain);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $mRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($mRow) {
        $recId = (int)$mRow['ID'];
        $link->query("UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_CALL_DIALED_USER = $userId WHERE ID = $recId");
        apiSuccess([
            'source' => 'MAIN',
            'record_id' => $recId,
            'customer' => [
                'name' => $mRow['MAINDATABASE_NAME'] ?? '',
                'mobile' => $mRow['MAINDATABASE_MOBILE'] ?? '',
                'company' => $mRow['MAINDATABASE_COMPANY'] ?? '',
                'package' => $mRow['MAINDATABASE_PACKAGE'] ?? '',
                'other_info' => $mRow['MAINDATABASE_OTHER_INFO'] ?? '',
            ],
            'upload_time' => $mRow['MAINDATABASE_UPLOAD_DATETIME'],
            'last_status' => $mRow['MAINDATABASE_CALL_DIALED_STATUS'] ?? 'Not Called',
            'last_call_time' => $mRow['LAST_DIALED_DATE_TIME'],
        ]);
    }
    
    apiSuccess(['message' => 'No calls available at this time']);

// -----------------------------------------------------------------------------
// 4. SUBMIT CALL RESULT & DETAILED ANALYTICS
// -----------------------------------------------------------------------------
} elseif (preg_match('#^/calls/(\d+)/complete$#', $relativePath, $matches)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'Method not allowed');
    
    $recordId = (int)$matches[1];
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    $rawSource = strtoupper(trim($input['source'] ?? 'MAIN'));
    $source = ($rawSource === 'TEMPORARY' || $rawSource === 'TEMP') ? 'TEMP' : (($rawSource === 'LEADS') ? 'LEADS' : 'MAIN');
    
    $status = trim($input['call_status'] ?? ($input['status'] ?? 'Connected'));
    $notes = trim($input['notes'] ?? '');
    $duration = (int)($input['call_duration'] ?? ($input['duration_seconds'] ?? 0));
    $timingTier = trim($input['timing_classification'] ?? '');
    if (empty($timingTier)) {
        $timingTier = ($duration === 0) ? 'NOT_CONNECTED' : (($duration <= 20) ? 'CONNECTED_SHORT' : 'CONNECTED_CONVERSATION');
    }
    
    $nextFollowup = !empty($input['next_followup']) ? $input['next_followup'] : (!empty($input['next_followup_at']) ? $input['next_followup_at'] : null);
    $callStartTime = !empty($input['call_start_time']) ? date('Y-m-d H:i:s', strtotime($input['call_start_time'])) : null;
    $callTime = $callStartTime ?: date('Y-m-d H:i:s');
    
    // 1. Update master table based on source
    if ($source === 'MAIN') {
        $sql = "UPDATE " . tn('TBL_MAIN') . " SET 
            MAINDATABASE_CALL_DIALED_STATUS = ?,
            MAINDATABASE_CALL_DIALED_USER = ?,
            MAINDATABASE_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_OTHER_INFO END,
            LAST_DIALED_DATE_TIME = NOW(),
            LAST_CONNECTED_PERIOD = ?,
            CALL_COUNT = COALESCE(CALL_COUNT, 0) + 1
            WHERE ID = ?";
        $stmt = $link->prepare($sql);
        $durationStr = (string)$duration . "s";
        $stmt->bind_param('sissii', $status, $userId, $notes, $notes, $durationStr, $recordId);
        $stmt->execute();
        $stmt->close();
        
    } elseif ($source === 'TEMP') {
        $sql = "UPDATE " . tn('TBL_TEMP') . " SET 
            CALL_DIALED_STATUS = ?,
            CALL_DIALED_TELECALLER = ?,
            CUST_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE CUST_OTHER_INFO END,
            LAST_DIALED_DATE_TIME = NOW(),
            LAST_CONNECTED_PERIOD = ?
            WHERE ID = ?";
        $stmt = $link->prepare($sql);
        $durationStr = (string)$duration . "s";
        $stmt->bind_param('sissii', $status, $userId, $notes, $notes, $durationStr, $recordId);
        $stmt->execute();
        $stmt->close();
    }
    
    // 2. Insert rich record into call_logs
    $sqlLog = "INSERT INTO " . tn('TBL_CALL_LOGS') . " 
        (record_id, source, user_id, call_status, call_duration, timing_classification, notes, next_followup, call_time, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
    $stmt = $link->prepare($sqlLog);
    $stmt->bind_param('isisissss', $recordId, $source, $userId, $status, $duration, $timingTier, $notes, $nextFollowup, $callTime);
    $stmt->execute();
    $logId = $stmt->insert_id;
    $stmt->close();
    
    logActivity($link, $userId, 'API_CALL_COMPLETED', "Logged call for record #$recordId ($source) duration: {$duration}s, status: $status, tier: $timingTier", (string)$recordId, $source === 'TEMP' ? 'TBL_TEMP' : 'TBL_MAIN');
    
    apiSuccess([
        'message' => 'Call report submitted successfully',
        'log_id' => $logId,
        'record_id' => $recordId,
        'timing_classification' => $timingTier,
        'duration' => $duration,
    ]);

// -----------------------------------------------------------------------------
// 5. SHARP CALL REPORTING SUMMARY ANALYTICS
// -----------------------------------------------------------------------------
} elseif ($relativePath === '/reports/summary' || $relativePath === '/reports/summary/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    $period = $_GET['period'] ?? 'today';
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    
    $dateCond = buildDateCondition($period, $dateFrom, $dateTo, 'created_at');
    
    // Calculate aggregate metrics
    $sql = "SELECT 
        COUNT(*) as total_calls,
        COALESCE(SUM(call_duration), 0) as total_duration,
        COALESCE(AVG(call_duration), 0) as avg_duration,
        COALESCE(SUM(CASE WHEN call_duration > 0 THEN 1 ELSE 0 END), 0) as connected_calls,
        COALESCE(SUM(CASE WHEN call_duration = 0 OR timing_classification = 'NOT_CONNECTED' THEN 1 ELSE 0 END), 0) as not_connected_calls,
        COALESCE(SUM(CASE WHEN (call_duration > 0 AND call_duration <= 20) OR timing_classification = 'CONNECTED_SHORT' THEN 1 ELSE 0 END), 0) as connected_short_calls,
        COALESCE(SUM(CASE WHEN call_duration > 20 OR timing_classification = 'CONNECTED_CONVERSATION' THEN 1 ELSE 0 END), 0) as connected_conversation_calls
    FROM " . tn('TBL_CALL_LOGS') . "
    WHERE user_id = ? AND $dateCond";
    
    $stmt = $link->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $agg = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $totalCalls = (int)($agg['total_calls'] ?? 0);
    $totalDuration = (int)($agg['total_duration'] ?? 0);
    $avgDuration = round((float)($agg['avg_duration'] ?? 0), 1);
    $connectedCalls = (int)($agg['connected_calls'] ?? 0);
    $connectionRate = $totalCalls > 0 ? round(($connectedCalls / $totalCalls) * 100, 1) : 0.0;
    
    // Status breakdown counts
    $sqlStatus = "SELECT call_status, COUNT(*) as cnt 
        FROM " . tn('TBL_CALL_LOGS') . " 
        WHERE user_id = ? AND $dateCond 
        GROUP BY call_status";
    $stmt = $link->prepare($sqlStatus);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $statusRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $statusMap = [];
    foreach ($statusRows as $sr) {
        $st = $sr['call_status'] ?: 'Unknown';
        $statusMap[$st] = (int)$sr['cnt'];
    }
    
    apiSuccess([
        'period' => $period,
        'total_calls' => $totalCalls,
        'total_duration_seconds' => $totalDuration,
        'avg_duration_seconds' => $avgDuration,
        'connected_calls' => $connectedCalls,
        'connection_rate_percent' => $connectionRate,
        'timing_tiers' => [
            'not_connected' => (int)($agg['not_connected_calls'] ?? 0),
            'connected_short' => (int)($agg['connected_short_calls'] ?? 0),
            'connected_conversation' => (int)($agg['connected_conversation_calls'] ?? 0),
        ],
        'status_breakdown' => $statusMap,
    ]);

// -----------------------------------------------------------------------------
// 6. DETAILED CALLS LIST (Dialed History joined with customer data)
// -----------------------------------------------------------------------------
} elseif ($relativePath === '/calls' || $relativePath === '/calls/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
    $offset = ($page - 1) * $limit;
    
    $period = $_GET['period'] ?? 'today';
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $statusFilter = $_GET['status'] ?? null;
    $timingFilter = $_GET['timing_tier'] ?? null;
    $search = trim($_GET['search'] ?? '');
    
    $dateCond = buildDateCondition($period, $dateFrom, $dateTo, 'cl.created_at');
    
    $whereParts = ["cl.user_id = $userId", $dateCond];
    if ($statusFilter && $statusFilter !== 'all') {
        $escStatus = $link->real_escape_string($statusFilter);
        $whereParts[] = "cl.call_status = '$escStatus'";
    }
    if ($timingFilter && $timingFilter !== 'all') {
        $escTiming = $link->real_escape_string($timingFilter);
        $whereParts[] = "cl.timing_classification = '$escTiming'";
    }
    
    $whereSql = implode(' AND ', $whereParts);
    
    // Total count query
    $countSql = "SELECT COUNT(*) as c FROM " . tn('TBL_CALL_LOGS') . " cl WHERE $whereSql";
    $cRes = $link->query($countSql);
    $totalCount = $cRes ? (int)$cRes->fetch_assoc()['c'] : 0;
    
    // Rich joined query
    $sql = "SELECT 
        cl.id as log_id,
        cl.record_id,
        cl.source,
        cl.call_status,
        cl.call_duration,
        cl.timing_classification,
        cl.notes,
        cl.next_followup,
        cl.call_time,
        cl.created_at,
        CASE 
            WHEN cl.source = 'MAIN' THEN COALESCE(m.MAINDATABASE_NAME, '')
            WHEN cl.source = 'TEMP' THEN COALESCE(t.CUST_NAME, '')
            ELSE ''
        END as customer_name,
        CASE 
            WHEN cl.source = 'MAIN' THEN COALESCE(m.MAINDATABASE_MOBILE, '')
            WHEN cl.source = 'TEMP' THEN COALESCE(t.CUST_MOBILE, '')
            ELSE ''
        END as customer_mobile,
        CASE 
            WHEN cl.source = 'MAIN' THEN COALESCE(m.MAINDATABASE_COMPANY, '')
            WHEN cl.source = 'TEMP' THEN COALESCE(t.CUST_COMPANY, '')
            ELSE ''
        END as customer_company,
        CASE 
            WHEN cl.source = 'MAIN' THEN COALESCE(m.MAINDATABASE_PACKAGE, '')
            WHEN cl.source = 'TEMP' THEN COALESCE(t.CUST_PACKAGE, '')
            ELSE ''
        END as customer_package
    FROM " . tn('TBL_CALL_LOGS') . " cl
    LEFT JOIN " . tn('TBL_MAIN') . " m ON cl.source = 'MAIN' AND cl.record_id = m.ID
    LEFT JOIN " . tn('TBL_TEMP') . " t ON cl.source = 'TEMP' AND cl.record_id = t.ID
    WHERE $whereSql
    ORDER BY cl.created_at DESC
    LIMIT $offset, $limit";
    
    $res = $link->query($sql);
    $calls = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            if ($search !== '') {
                $sLow = strtolower($search);
                if (strpos(strtolower($r['customer_name']), $sLow) === false &&
                    strpos($r['customer_mobile'], $search) === false &&
                    strpos(strtolower($r['customer_company']), $sLow) === false) {
                    continue;
                }
            }
            $calls[] = [
                'log_id' => (int)$r['log_id'],
                'record_id' => (int)$r['record_id'],
                'source' => $r['source'],
                'customer_name' => $r['customer_name'],
                'customer_mobile' => $r['customer_mobile'],
                'customer_company' => $r['customer_company'],
                'customer_package' => $r['customer_package'],
                'call_status' => $r['call_status'],
                'call_duration' => (int)$r['call_duration'],
                'timing_classification' => $r['timing_classification'] ?: (($r['call_duration'] == 0) ? 'NOT_CONNECTED' : (($r['call_duration'] <= 20) ? 'CONNECTED_SHORT' : 'CONNECTED_CONVERSATION')),
                'notes' => $r['notes'] ?? '',
                'next_followup' => $r['next_followup'],
                'call_time' => $r['call_time'] ?? $r['created_at'],
                'created_at' => $r['created_at'],
            ];
        }
    }
    
    apiSuccess([
        'data' => $calls,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalCount,
            'total_pages' => ceil($totalCount / $limit),
        ]
    ]);

// -----------------------------------------------------------------------------
// 7. RECORD CALL HISTORY
// -----------------------------------------------------------------------------
} elseif (preg_match('#^/call-history/(\d+)$#', $relativePath, $matches)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    $recordId = (int)$matches[1];
    $rawSource = strtoupper(trim($_GET['source'] ?? 'MAIN'));
    $source = ($rawSource === 'TEMP' || $rawSource === 'TEMPORARY') ? 'TEMP' : 'MAIN';
    
    $sql = "SELECT id, record_id, source, user_id, call_status, call_duration, timing_classification, notes, next_followup, call_time, created_at
            FROM " . tn('TBL_CALL_LOGS') . "
            WHERE record_id = ? AND source = ?
            ORDER BY created_at DESC";
    $stmt = $link->prepare($sql);
    $stmt->bind_param('is', $recordId, $source);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    apiSuccess(['data' => $rows, 'total' => count($rows)]);
    
} else {
    apiError(404, 'Telecaller endpoint not found');
}
} catch (Throwable $e) {
    apiError(500, 'Server Exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}
