<?php
/**
 * Telecaller API Endpoints
 * GET /api/v1/telecaller/dashboard - Get telecaller dashboard stats
 * GET /api/v1/telecaller/batch-leads - Get batch of leads (e.g. 15) for calling queue
 * GET /api/v1/telecaller/next-call - Get next call to make (fallback single lead)
 * GET /api/v1/telecaller/reports/summary - Get telecaller performance analytics & metrics
 * GET /api/v1/telecaller/calls - List dialed call history with rich customer details
 * POST /api/v1/telecaller/calls/{id}/complete - Submit call result & full analytics
 * GET /api/v1/telecaller/call-history/{id} - Call history for specific record
 */
@ini_set('display_errors', '0');
error_reporting(0);
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../php_scripts/api_auth.php';

// Ensure call_logs schema has required columns
try {
    @$GLOBALS['link']->query("ALTER TABLE " . tn('TBL_CALL_LOGS') . " MODIFY COLUMN call_type VARCHAR(50) DEFAULT 'MAIN'");
    @$GLOBALS['link']->query("ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN timing_classification VARCHAR(30) NULL DEFAULT NULL AFTER duration_seconds");
    @$GLOBALS['link']->query("ALTER TABLE " . tn('TBL_CALL_LOGS') . " ADD COLUMN next_followup DATETIME NULL DEFAULT NULL AFTER notes");
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
    
    // Check temporary table pending strictly excluding DND
    $sqlTemp = "SELECT COUNT(*) as c FROM " . tn('TBL_TEMP') . " 
        WHERE (CALL_DIALED_TELECALLER = ? OR CALL_DIALED_TELECALLER IS NULL)
        AND (CALL_DIALED_STATUS IS NULL OR CALL_DIALED_STATUS = '' OR CALL_DIALED_STATUS = 'Pending' OR CALL_DIALED_STATUS = 'Not Called')
        AND LOWER(COALESCE(CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_MAIN') . " m 
            WHERE m.MAINDATABASE_MOBILE = " . tn('TBL_TEMP') . ".CUST_MOBILE 
            AND LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        )
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_TEMP') . " t2 
            WHERE t2.CUST_MOBILE = " . tn('TBL_TEMP') . ".CUST_MOBILE 
            AND LOWER(t2.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        )";
    $stmt = $link->prepare($sqlTemp);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $tempRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $pendingCount += (int)($tempRow['c'] ?? 0);
    
    // Check main table pending strictly excluding DND
    $sqlMain = "SELECT COUNT(*) as c FROM " . tn('TBL_MAIN') . " 
        WHERE (MAINDATABASE_CALL_DIALED_USER = ? OR MAINDATABASE_CALL_DIALED_USER IS NULL)
        AND (MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR MAINDATABASE_CALL_DIALED_STATUS = 'Pending' OR MAINDATABASE_CALL_DIALED_STATUS IS NULL OR MAINDATABASE_CALL_DIALED_STATUS = '')
        AND LOWER(COALESCE(MAINDATABASE_CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_MAIN') . " m2 
            WHERE m2.MAINDATABASE_MOBILE = " . tn('TBL_MAIN') . ".MAINDATABASE_MOBILE 
            AND LOWER(m2.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        )
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_TEMP') . " t 
            WHERE t.CUST_MOBILE = " . tn('TBL_MAIN') . ".MAINDATABASE_MOBILE 
            AND LOWER(t.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        )";
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
    
    // 1. Try temporary database first (strictly excluding any DND numbers)
    $sqlTemp = "SELECT 
        ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO,
        CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, TEMP_UPLOAD_DATETIME
    FROM " . tn('TBL_TEMP') . " 
    WHERE (CALL_DIALED_TELECALLER = ? OR CALL_DIALED_TELECALLER IS NULL)
    AND (CALL_DIALED_STATUS IS NULL OR CALL_DIALED_STATUS = '' OR CALL_DIALED_STATUS = 'Pending' OR CALL_DIALED_STATUS = 'Not Called')
    AND LOWER(COALESCE(CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_MAIN') . " m 
        WHERE m.MAINDATABASE_MOBILE = " . tn('TBL_TEMP') . ".CUST_MOBILE 
        AND LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
    )
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_TEMP') . " t2 
        WHERE t2.CUST_MOBILE = " . tn('TBL_TEMP') . ".CUST_MOBILE 
        AND LOWER(t2.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
    )
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
        $link->query("UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_TELECALLER = $userId, BATCH_ASSIGNED_AT = NOW() WHERE ID IN ($inClause)");
    }
    
    // 2. If temporary database yielded fewer than $limit, fill remainder from main_database (strictly excluding any DND numbers)
    $remaining = $limit - count($leads);
    if ($remaining > 0) {
        $sqlMain = "SELECT 
            ID, MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, 
            MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS,
            LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME, CALL_COUNT
        FROM " . tn('TBL_MAIN') . "
        WHERE (MAINDATABASE_CALL_DIALED_USER = ? OR MAINDATABASE_CALL_DIALED_USER IS NULL)
        AND (MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR MAINDATABASE_CALL_DIALED_STATUS = 'Pending' OR MAINDATABASE_CALL_DIALED_STATUS IS NULL OR MAINDATABASE_CALL_DIALED_STATUS = '')
        AND LOWER(COALESCE(MAINDATABASE_CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_MAIN') . " m2 
            WHERE m2.MAINDATABASE_MOBILE = " . tn('TBL_MAIN') . ".MAINDATABASE_MOBILE 
            AND LOWER(m2.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        )
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_TEMP') . " t 
            WHERE t.CUST_MOBILE = " . tn('TBL_MAIN') . ".MAINDATABASE_MOBILE 
            AND LOWER(t.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        )
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
                'call_count' => (int)($row['CALL_COUNT'] ?? 0),
                'last_status' => $row['MAINDATABASE_CALL_DIALED_STATUS'] ?? 'Not Called',
                'last_call_time' => $row['LAST_DIALED_DATE_TIME'] ?? null,
                'upload_time' => $row['MAINDATABASE_UPLOAD_DATETIME'] ?? null,
            ];
            $mainIdsToLock[] = (int)$row['ID'];
        }
        
        // Atomically lock main records to current user
        if (!empty($mainIdsToLock)) {
            $inClause = implode(',', $mainIdsToLock);
            $link->query("UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_CALL_DIALED_USER = $userId, BATCH_ASSIGNED_AT = NOW() WHERE ID IN ($inClause)");
        }
    }
    
    $currentDb = empty($tempRows) ? 'MAIN' : (count($leads) > count($tempRows) ? 'MIXED' : 'TEMPORARY');
    apiSuccess([
        'current_database' => $currentDb,
        'lease_duration_minutes' => 20,
        'leads' => $leads,
        'count' => count($leads),
        'limit' => $limit,
    ]);

// -----------------------------------------------------------------------------
// 3. NEXT-CALL (Single Lead Fallback)
// -----------------------------------------------------------------------------
} elseif ($relativePath === '/next-call' || $relativePath === '/next-call/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    // Check temp first (strictly excluding DND)
    $sqlTemp = "SELECT 
        ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO,
        TEMP_UPLOAD_DATETIME, CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME
    FROM " . tn('TBL_TEMP') . " 
    WHERE (CALL_DIALED_TELECALLER = ? OR CALL_DIALED_TELECALLER IS NULL)
    AND (CALL_DIALED_STATUS IS NULL OR CALL_DIALED_STATUS = '' OR CALL_DIALED_STATUS = 'Pending' OR CALL_DIALED_STATUS = 'Not Called')
    AND LOWER(COALESCE(CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_MAIN') . " m 
        WHERE m.MAINDATABASE_MOBILE = " . tn('TBL_TEMP') . ".CUST_MOBILE 
        AND LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
    )
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_TEMP') . " t2 
        WHERE t2.CUST_MOBILE = " . tn('TBL_TEMP') . ".CUST_MOBILE 
        AND LOWER(t2.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
    )
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
    
    // Try main database next (strictly excluding DND)
    $sqlMain = "SELECT 
        ID, MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY,
        MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS,
        LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME
    FROM " . tn('TBL_MAIN') . " 
    WHERE (MAINDATABASE_CALL_DIALED_USER = ? OR MAINDATABASE_CALL_DIALED_USER IS NULL)
    AND (MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR MAINDATABASE_CALL_DIALED_STATUS = 'Pending' OR MAINDATABASE_CALL_DIALED_STATUS IS NULL OR MAINDATABASE_CALL_DIALED_STATUS = '')
    AND LOWER(COALESCE(MAINDATABASE_CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_MAIN') . " m2 
        WHERE m2.MAINDATABASE_MOBILE = " . tn('TBL_MAIN') . ".MAINDATABASE_MOBILE 
        AND LOWER(m2.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
    )
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_TEMP') . " t 
        WHERE t.CUST_MOBILE = " . tn('TBL_MAIN') . ".MAINDATABASE_MOBILE 
        AND LOWER(t.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
    )
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
    $source = ($rawSource === 'TEMPORARY' || $rawSource === 'TEMP') ? 'TEMP' : 'MAIN';
    
    $rawStatus = trim($input['call_status'] ?? ($input['status'] ?? 'Connected'));
    $rawStatusLow = strtolower($rawStatus);
    $isDnd = in_array($rawStatusLow, ['dnd', 'do not call', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd'])
        || str_contains($rawStatusLow, 'dnd')
        || str_contains($rawStatusLow, 'disturb')
        || str_contains($rawStatusLow, 'not call');
    $status = $isDnd ? 'Do Not Call' : $rawStatus;
    
    $notes = trim($input['notes'] ?? '');
    $duration = (int)($input['call_duration'] ?? ($input['duration_seconds'] ?? 0));
    $timingTier = trim($input['timing_classification'] ?? '');
    if (empty($timingTier)) {
        $timingTier = ($duration === 0) ? 'NOT_CONNECTED' : (($duration <= 20) ? 'CONNECTED_SHORT' : 'CONNECTED_CONVERSATION');
    }
    
    $nextFollowup = !empty($input['next_followup']) ? date('Y-m-d H:i:s', strtotime($input['next_followup'])) : (!empty($input['next_followup_at']) ? date('Y-m-d H:i:s', strtotime($input['next_followup_at'])) : null);
    
    // Retrieve phone number to guarantee cross-database DND synchronization
    $targetMobile = '';
    if ($source === 'MAIN') {
        $mStmt = $link->prepare("SELECT MAINDATABASE_MOBILE FROM " . tn('TBL_MAIN') . " WHERE ID = ?");
        $mStmt->bind_param('i', $recordId);
        $mStmt->execute();
        $targetMobile = (string)($mStmt->get_result()->fetch_assoc()['MAINDATABASE_MOBILE'] ?? '');
        $mStmt->close();
    } else {
        $mStmt = $link->prepare("SELECT CUST_MOBILE FROM " . tn('TBL_TEMP') . " WHERE ID = ?");
        $mStmt->bind_param('i', $recordId);
        $mStmt->execute();
        $targetMobile = (string)($mStmt->get_result()->fetch_assoc()['CUST_MOBILE'] ?? '');
        $mStmt->close();
    }

    // 1. Update master table based on source
    $isConnected = ($duration > 0 || in_array(strtolower($status), ['connected', 'lead', 'interested'])) ? 1 : 0;
    if ($source === 'MAIN') {
        $sql = "UPDATE " . tn('TBL_MAIN') . " SET 
            MAINDATABASE_CALL_DIALED_STATUS = ?,
            MAINDATABASE_CALL_DIALED_USER = ?,
            MAINDATABASE_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_OTHER_INFO END,
            LAST_DIALED_DATE_TIME = NOW(),
            LAST_CONNECTED_PERIOD = CASE WHEN ? = 1 THEN NOW() ELSE LAST_CONNECTED_PERIOD END,
            CALL_COUNT = COALESCE(CALL_COUNT, 0) + 1,
            BATCH_ASSIGNED_AT = NULL
            WHERE ID = ?";
        $stmt = $link->prepare($sql);
        $stmt->bind_param('sissii', $status, $userId, $notes, $notes, $isConnected, $recordId);
        $stmt->execute();
        $stmt->close();
        
    } elseif ($source === 'TEMP') {
        $sql = "UPDATE " . tn('TBL_TEMP') . " SET 
            CALL_DIALED_STATUS = ?,
            CALL_DIALED_TELECALLER = ?,
            CUST_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE CUST_OTHER_INFO END,
            LAST_DIALED_DATE_TIME = NOW(),
            LAST_CONNECTED_PERIOD = CASE WHEN ? = 1 THEN NOW() ELSE LAST_CONNECTED_PERIOD END,
            BATCH_ASSIGNED_AT = NULL
            WHERE ID = ?";
        $stmt = $link->prepare($sql);
        $stmt->bind_param('sissii', $status, $userId, $notes, $notes, $isConnected, $recordId);
        $stmt->execute();
        $stmt->close();
    }
    
    // If marked as DND, strictly synchronize across ALL occurrences of this phone number in both tables
    if ($isDnd && $targetMobile !== '') {
        $targetMobileSafe = $link->real_escape_string($targetMobile);
        $link->query("UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_CALL_DIALED_STATUS = 'Do Not Call', BATCH_ASSIGNED_AT = NULL, LAST_DIALED_DATE_TIME = NOW(), MAINDATABASE_CALL_DIAL_TIME = NOW() WHERE MAINDATABASE_MOBILE = '$targetMobileSafe'");
        $link->query("UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_STATUS = 'Do Not Call', BATCH_ASSIGNED_AT = NULL, LAST_DIALED_DATE_TIME = NOW() WHERE CUST_MOBILE = '$targetMobileSafe'");
    }
    
    // 2. Insert record into call_logs using exact remote column names
    $sqlLog = "INSERT INTO " . tn('TBL_CALL_LOGS') . " 
        (user_id, record_id, source, call_status, call_duration, timing_classification, notes, next_followup, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $link->prepare($sqlLog);
    if (!$stmt) {
        // Fallback without timing_classification or next_followup if remote schema restricted
        $sqlLogFallback = "INSERT INTO " . tn('TBL_CALL_LOGS') . " 
            (user_id, record_id, source, call_status, call_duration, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $link->prepare($sqlLogFallback);
        $stmt->bind_param('iissis', $userId, $recordId, $source, $status, $duration, $notes);
    } else {
        $stmt->bind_param('iississs', $userId, $recordId, $source, $status, $duration, $timingTier, $notes, $nextFollowup);
    }
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
    
    // Calculate aggregate metrics using duration_seconds
    $sql = "SELECT 
        COUNT(*) as total_calls,
        COALESCE(SUM(call_duration), 0) as total_duration,
        COALESCE(AVG(call_duration), 0) as avg_duration,
        COALESCE(SUM(CASE WHEN call_duration > 0 THEN 1 ELSE 0 END), 0) as connected_calls,
        COALESCE(SUM(CASE WHEN call_duration = 0 THEN 1 ELSE 0 END), 0) as not_connected_calls,
        COALESCE(SUM(CASE WHEN call_duration > 0 AND call_duration <= 20 THEN 1 ELSE 0 END), 0) as connected_short_calls,
        COALESCE(SUM(CASE WHEN call_duration > 20 THEN 1 ELSE 0 END), 0) as connected_conversation_calls
    FROM " . tn('TBL_CALL_LOGS') . "
    WHERE user_id = ? AND $dateCond";
    
    $stmt = $link->prepare($sql);
    if (!$stmt) throw new Exception("Prepare failed for summary: " . $link->error);
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
    $sqlStatus = "SELECT call_status as status, COUNT(*) as cnt 
        FROM " . tn('TBL_CALL_LOGS') . " 
        WHERE user_id = ? AND $dateCond 
        GROUP BY status";
    $stmt = $link->prepare($sqlStatus);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $statusRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $statusMap = [];
    foreach ($statusRows as $sr) {
        $st = $sr['status'] ?: 'Unknown';
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
        if ($timingFilter === 'NOT_CONNECTED') {
            $whereParts[] = "cl.call_duration = 0";
        } elseif ($timingFilter === 'CONNECTED_SHORT') {
            $whereParts[] = "(cl.call_duration > 0 AND cl.call_duration <= 20)";
        } elseif ($timingFilter === 'CONNECTED_CONVERSATION') {
            $whereParts[] = "cl.call_duration > 20";
        }
    }
    
    $whereSql = implode(' AND ', $whereParts);
    
    // Total count query
    $countSql = "SELECT COUNT(*) as c FROM " . tn('TBL_CALL_LOGS') . " cl WHERE $whereSql";
    $cRes = $link->query($countSql);
    $totalCount = $cRes ? (int)$cRes->fetch_assoc()['c'] : 0;
    
    // Rich joined query
    $sql = "SELECT 
        cl.id as log_id,
        cl.record_id as record_id,
        cl.source as source,
        cl.call_status as call_status,
        cl.call_duration as call_duration,
        CASE 
            WHEN cl.call_duration = 0 THEN 'NOT_CONNECTED'
            WHEN cl.call_duration <= 20 THEN 'CONNECTED_SHORT'
            ELSE 'CONNECTED_CONVERSATION'
        END as timing_classification,
        cl.notes,
        cl.created_at,
        COALESCE(m.MAINDATABASE_NAME, t.CUST_NAME, '') as customer_name,
        COALESCE(m.MAINDATABASE_MOBILE, t.CUST_MOBILE, '') as customer_mobile,
        COALESCE(m.MAINDATABASE_COMPANY, t.CUST_COMPANY, '') as customer_company,
        COALESCE(m.MAINDATABASE_PACKAGE, t.CUST_PACKAGE, '') as customer_package
    FROM " . tn('TBL_CALL_LOGS') . " cl
    LEFT JOIN " . tn('TBL_MAIN') . " m ON (cl.source != 'TEMP') AND cl.record_id = m.ID
    LEFT JOIN " . tn('TBL_TEMP') . " t ON (cl.source = 'TEMP') AND cl.record_id = t.ID
    WHERE $whereSql
    ORDER BY cl.created_at DESC
    LIMIT $offset, $limit";
    
    $res = $link->query($sql);
    if (!$res) throw new Exception("Query failed for calls: " . $link->error . " | SQL: " . $sql);
    
    $calls = [];
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
            'timing_classification' => $r['timing_classification'],
            'notes' => $r['notes'] ?? '',
            'next_followup' => $r['next_followup'] ?? null,
            'call_time' => $r['created_at'],
            'created_at' => $r['created_at'],
        ];
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
    
    $sql = "SELECT id, record_id, source, user_id, call_status, call_duration, timing_classification, notes, next_followup, created_at
            FROM " . tn('TBL_CALL_LOGS') . "
            WHERE record_id = ? AND source = ?
            ORDER BY created_at DESC";
    $stmt = $link->prepare($sql);
    $stmt->bind_param('is', $recordId, $source);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    apiSuccess(['data' => $rows, 'total' => count($rows)]);
    
} elseif ($relativePath === '/submit_call' || $relativePath === '/submit_call/') {
    require __DIR__ . '/submit_call.php';
    exit;

} elseif ($relativePath === '/release-queue' || $relativePath === '/release-queue/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'Method not allowed');
    $link->query("UPDATE " . tn('TBL_TEMP') . " SET BATCH_ASSIGNED_AT = NULL, CALL_DIALED_TELECALLER = NULL WHERE CALL_DIALED_TELECALLER = $userId AND BATCH_ASSIGNED_AT IS NOT NULL");
    $relTemp = $link->affected_rows;
    $link->query("UPDATE " . tn('TBL_MAIN') . " SET BATCH_ASSIGNED_AT = NULL, MAINDATABASE_CALL_DIALED_USER = NULL WHERE MAINDATABASE_CALL_DIALED_USER = $userId AND BATCH_ASSIGNED_AT IS NOT NULL");
    $relMain = $link->affected_rows;
    apiSuccess(['total_released' => $relTemp + $relMain]);

} elseif ($relativePath === '/leads' || $relativePath === '/leads/') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');
        $statusFilter = trim($_GET['status'] ?? 'all');
        $sourceFilter = strtoupper(trim($_GET['source'] ?? 'all'));

        $whereParts = [];
        $params = [];
        $types = '';

        if ($userRole === 'Admin' || $userRole === 'Manager' || $userRole === 'Super Admin') {
            if (!empty($_GET['user_id'])) {
                $whereParts[] = "m.MAINDATABASE_CALL_DIALED_USER = ?";
                $params[] = (int)$_GET['user_id'];
                $types .= 'i';
            } else {
                $whereParts[] = "1=1";
            }
        } elseif ($userRole === 'Supervisor' && $teamId) {
            $whereParts[] = "(u.TEAM_ID = ? OR m.MAINDATABASE_CALL_DIALED_USER = ?)";
            $params[] = $teamId;
            $params[] = $userId;
            $types .= 'ii';
        } else {
            $whereParts[] = "m.MAINDATABASE_CALL_DIALED_USER = ?";
            $params[] = $userId;
            $types .= 'i';
        }

        if ($statusFilter !== '' && $statusFilter !== 'all') {
            if (strtolower($statusFilter) === 'not_called' || strtolower($statusFilter) === 'not called') {
                $whereParts[] = "(m.MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR m.MAINDATABASE_CALL_DIALED_STATUS IS NULL OR m.MAINDATABASE_CALL_DIALED_STATUS = '')";
            } elseif (strtolower($statusFilter) === 'called') {
                $whereParts[] = "(m.MAINDATABASE_CALL_DIALED_STATUS != 'Not Called' AND m.MAINDATABASE_CALL_DIALED_STATUS IS NOT NULL AND m.MAINDATABASE_CALL_DIALED_STATUS != '')";
            } else {
                $whereParts[] = "m.MAINDATABASE_CALL_DIALED_STATUS = ?";
                $params[] = $statusFilter;
                $types .= 's';
            }
        }

        if ($search !== '') {
            $whereParts[] = "(m.MAINDATABASE_NAME LIKE ? OR m.MAINDATABASE_MOBILE LIKE ? OR m.MAINDATABASE_COMPANY LIKE ? OR m.MAINDATABASE_OTHER_INFO LIKE ?)";
            $like = "%$search%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= 'ssss';
        }

        $whereSql = "WHERE " . implode(" AND ", $whereParts);

        $countSql = "SELECT COUNT(*) as c 
                     FROM " . tn('TBL_MAIN') . " m 
                     LEFT JOIN " . tn('TBL_USERS') . " u ON m.MAINDATABASE_CALL_DIALED_USER = u.ID 
                     $whereSql";
        $stmtC = $link->prepare($countSql);
        if ($types !== '') {
            $stmtC->bind_param($types, ...$params);
        }
        $stmtC->execute();
        $total = (int)$stmtC->get_result()->fetch_assoc()['c'];
        $stmtC->close();

        $dataSql = "SELECT 
                        m.ID,
                        m.MAINDATABASE_NAME as name,
                        m.MAINDATABASE_MOBILE as mobile,
                        m.MAINDATABASE_COMPANY as company,
                        m.MAINDATABASE_PACKAGE as package,
                        m.MAINDATABASE_OTHER_INFO as other_info,
                        m.MAINDATABASE_CALL_DIALED_STATUS as last_status,
                        m.LAST_DIALED_DATE_TIME as last_call_time,
                        m.MAINDATABASE_UPLOAD_DATETIME as upload_time,
                        m.CALL_COUNT as call_count,
                        u.NAME as assigned_user_name
                    FROM " . tn('TBL_MAIN') . " m
                    LEFT JOIN " . tn('TBL_USERS') . " u ON m.MAINDATABASE_CALL_DIALED_USER = u.ID
                    $whereSql
                    ORDER BY COALESCE(m.LAST_DIALED_DATE_TIME, m.MAINDATABASE_UPLOAD_DATETIME) DESC
                    LIMIT ? OFFSET ?";
        
        $paramsData = $params;
        $typesData = $types . 'ii';
        $paramsData[] = $limit;
        $paramsData[] = $offset;

        $stmtD = $link->prepare($dataSql);
        $stmtD->bind_param($typesData, ...$paramsData);
        $stmtD->execute();
        $rows = $stmtD->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtD->close();

        $leadsList = [];
        foreach ($rows as $r) {
            $leadsList[] = [
                'id' => (int)$r['ID'],
                'record_id' => (int)$r['ID'],
                'source' => 'MAIN',
                'name' => $r['name'] ?? '',
                'mobile' => $r['mobile'] ?? '',
                'company' => $r['company'] ?? '',
                'package' => $r['package'] ?? '',
                'other_info' => $r['other_info'] ?? '',
                'last_status' => $r['last_status'] ?? 'Not Called',
                'last_call_time' => $r['last_call_time'],
                'upload_time' => $r['upload_time'],
                'call_count' => (int)($r['call_count'] ?? 0),
                'assigned_user' => $r['assigned_user_name'] ?? null,
            ];
        }

        apiSuccess([
            'leads' => $leadsList,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit),
            ]
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $name = trim($input['name'] ?? ($input['customer_name'] ?? ''));
        $rawMobile = trim($input['mobile'] ?? ($input['customer_mobile'] ?? ''));
        $mobile = preg_replace('/[^0-9]/', '', $rawMobile);
        if (strlen($mobile) > 10 && substr($mobile, 0, 2) === '91') {
            $mobile = substr($mobile, 2);
        }

        if (empty($name)) {
            apiError(400, 'Customer name is required');
        }
        if (empty($mobile) || strlen($mobile) < 10 || strlen($mobile) > 15) {
            apiError(400, 'A valid 10-digit mobile number is required');
        }

        $company = trim($input['company'] ?? ($input['customer_company'] ?? ''));
        $package = trim($input['package'] ?? ($input['customer_package'] ?? ($input['loan_amount'] ?? '')));
        $otherInfo = trim($input['other_info'] ?? ($input['notes'] ?? ($input['remarks'] ?? '')));
        $status = trim($input['status'] ?? ($input['call_status'] ?? 'Not Called'));
        $source = strtoupper(trim($input['source'] ?? 'MAIN'));
        if ($source !== 'TEMP' && $source !== 'TEMPORARY') {
            $source = 'MAIN';
        }

        $dndSql = "SELECT ID, 'MAIN' as src FROM " . tn('TBL_MAIN') . " 
                   WHERE MAINDATABASE_MOBILE = ? 
                   AND LOWER(MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'dnd requested', 'do not disturb', 'not_connected_dnd')
                   UNION
                   SELECT ID, 'TEMP' as src FROM " . tn('TBL_TEMP') . " 
                   WHERE CUST_MOBILE = ? 
                   AND LOWER(CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'dnd requested', 'do not disturb', 'not_connected_dnd')
                   LIMIT 1";
        $dStmt = $link->prepare($dndSql);
        $dStmt->bind_param('ss', $mobile, $mobile);
        $dStmt->execute();
        $isDnd = $dStmt->get_result()->fetch_assoc();
        $dStmt->close();

        if ($isDnd) {
            apiError(422, 'Cannot create lead: This mobile number is registered in the Do Not Call (DND) registry.');
        }

        $createdId = 0;
        $isNew = true;

        if ($source === 'MAIN') {
            $chkStmt = $link->prepare("SELECT ID, MAINDATABASE_CALL_DIALED_USER FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = ? LIMIT 1");
            $chkStmt->bind_param('s', $mobile);
            $chkStmt->execute();
            $exist = $chkStmt->get_result()->fetch_assoc();
            $chkStmt->close();

            if ($exist) {
                $createdId = (int)$exist['ID'];
                $isNew = false;
                $uStmt = $link->prepare("UPDATE " . tn('TBL_MAIN') . " SET 
                    MAINDATABASE_NAME = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_NAME END,
                    MAINDATABASE_COMPANY = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_COMPANY END,
                    MAINDATABASE_PACKAGE = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_PACKAGE END,
                    MAINDATABASE_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_OTHER_INFO END,
                    MAINDATABASE_CALL_DIALED_USER = COALESCE(MAINDATABASE_CALL_DIALED_USER, ?),
                    MAINDATABASE_CALL_DIALED_STATUS = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_CALL_DIALED_STATUS END
                    WHERE ID = ?");
                $uStmt->bind_param('ssssssssssii', 
                    $name, $name, 
                    $company, $company, 
                    $package, $package, 
                    $otherInfo, $otherInfo, 
                    $userId, 
                    $status, $status, 
                    $createdId
                );
                $uStmt->execute();
                $uStmt->close();
            } else {
                $iStmt = $link->prepare("INSERT INTO " . tn('TBL_MAIN') . " (
                    MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, 
                    MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, 
                    MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_CALL_DIALED_USER, 
                    MAINDATABASE_UPLOAD_DATETIME
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $iStmt->bind_param('ssssssi', $name, $mobile, $company, $package, $otherInfo, $status, $userId);
                $iStmt->execute();
                $createdId = (int)$iStmt->insert_id;
                $iStmt->close();
            }
        } else {
            $chkStmt = $link->prepare("SELECT ID FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = ? LIMIT 1");
            $chkStmt->bind_param('s', $mobile);
            $chkStmt->execute();
            $exist = $chkStmt->get_result()->fetch_assoc();
            $chkStmt->close();

            if ($exist) {
                $createdId = (int)$exist['ID'];
                $isNew = false;
                $uStmt = $link->prepare("UPDATE " . tn('TBL_TEMP') . " SET 
                    CUST_NAME = CASE WHEN ? != '' THEN ? ELSE CUST_NAME END,
                    CUST_COMPANY = CASE WHEN ? != '' THEN ? ELSE CUST_COMPANY END,
                    CUST_PACKAGE = CASE WHEN ? != '' THEN ? ELSE CUST_PACKAGE END,
                    CUST_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE CUST_OTHER_INFO END,
                    CALL_DIALED_TELECALLER = COALESCE(CALL_DIALED_TELECALLER, ?),
                    CALL_DIALED_STATUS = CASE WHEN ? != '' THEN ? ELSE CALL_DIALED_STATUS END
                    WHERE ID = ?");
                $uStmt->bind_param('ssssssssssii', 
                    $name, $name, 
                    $company, $company, 
                    $package, $package, 
                    $otherInfo, $otherInfo, 
                    $userId, 
                    $status, $status, 
                    $createdId
                );
                $uStmt->execute();
                $uStmt->close();
            } else {
                $iStmt = $link->prepare("INSERT INTO " . tn('TBL_TEMP') . " (
                    CUST_NAME, CUST_MOBILE, CUST_COMPANY, 
                    CUST_PACKAGE, CUST_OTHER_INFO, 
                    CALL_DIALED_STATUS, CALL_DIALED_TELECALLER, 
                    TEMP_UPLOAD_DATETIME
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $iStmt->bind_param('ssssssi', $name, $mobile, $company, $package, $otherInfo, $status, $userId);
                $iStmt->execute();
                $createdId = (int)$iStmt->insert_id;
                $iStmt->close();
            }
        }

        if ($source === 'MAIN' && $createdId > 0) {
            $lChk = $link->prepare("SELECT lead_id FROM " . tn('TBL_LEADS') . " WHERE cust_id = ? LIMIT 1");
            if ($lChk) {
                $lChk->bind_param('i', $createdId);
                $lChk->execute();
                $lExist = $lChk->get_result()->fetch_assoc();
                $lChk->close();

                if (!$lExist) {
                    $lStmt = $link->prepare("INSERT INTO " . tn('TBL_LEADS') . " (
                        cust_id, assigned_to, assigned_by, lead_status, lead_status_new,
                        loan_amount, remarks, created_by, updated_by, created_at, updated_at
                    ) VALUES (?, ?, ?, 'New', 'LEAD', ?, ?, ?, ?, NOW(), NOW())");
                    if ($lStmt) {
                        $lStmt->bind_param('iiisssii', $createdId, $userId, $userId, $package, $otherInfo, $userId, $userId);
                        $lStmt->execute();
                        $lStmt->close();
                    }
                }
            }
        }

        logActivity($link, $userId, $isNew ? 'API_LEAD_CREATED' : 'API_LEAD_UPDATED', 
            ($isNew ? "Created lead" : "Updated existing lead") . " for $name ($mobile) in $source", 
            (string)$createdId, 
            $source === 'TEMP' ? 'TBL_TEMP' : 'TBL_MAIN'
        );

        apiSuccess([
            'message' => $isNew ? 'Lead created successfully' : 'Lead updated successfully',
            'is_new' => $isNew,
            'lead' => [
                'id' => $createdId,
                'record_id' => $createdId,
                'source' => $source,
                'name' => $name,
                'mobile' => $mobile,
                'company' => $company,
                'package' => $package,
                'other_info' => $otherInfo,
                'last_status' => $status,
                'created_at' => date('Y-m-d H:i:s'),
            ]
        ], $isNew ? 201 : 200);
    } else {
        apiError(405, 'Method not allowed');
    }

} elseif (preg_match('#^/leads/(\d+)$#', $relativePath, $matches)) {
    $leadId = (int)$matches[1];
    $rawSource = strtoupper(trim($_GET['source'] ?? 'MAIN'));
    $source = ($rawSource === 'TEMP' || $rawSource === 'TEMPORARY') ? 'TEMP' : 'MAIN';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($source === 'TEMP') {
            $stmt = $link->prepare("SELECT ID, CUST_NAME as name, CUST_MOBILE as mobile, CUST_COMPANY as company, CUST_PACKAGE as package, CUST_OTHER_INFO as other_info, CALL_DIALED_STATUS as last_status, LAST_DIALED_DATE_TIME as last_call_time, TEMP_UPLOAD_DATETIME as upload_time, CALL_DIALED_TELECALLER FROM " . tn('TBL_TEMP') . " WHERE ID = ?");
        } else {
            $stmt = $link->prepare("SELECT ID, MAINDATABASE_NAME as name, MAINDATABASE_MOBILE as mobile, MAINDATABASE_COMPANY as company, MAINDATABASE_PACKAGE as package, MAINDATABASE_OTHER_INFO as other_info, MAINDATABASE_CALL_DIALED_STATUS as last_status, LAST_DIALED_DATE_TIME as last_call_time, MAINDATABASE_UPLOAD_DATETIME as upload_time, MAINDATABASE_CALL_DIALED_USER FROM " . tn('TBL_MAIN') . " WHERE ID = ?");
        }
        $stmt->bind_param('i', $leadId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) apiError(404, 'Lead not found');

        apiSuccess([
            'lead' => [
                'id' => (int)$row['ID'],
                'record_id' => (int)$row['ID'],
                'source' => $source,
                'name' => $row['name'] ?? '',
                'mobile' => $row['mobile'] ?? '',
                'company' => $row['company'] ?? '',
                'package' => $row['package'] ?? '',
                'other_info' => $row['other_info'] ?? '',
                'last_status' => $row['last_status'] ?? 'Not Called',
                'last_call_time' => $row['last_call_time'],
                'upload_time' => $row['upload_time'],
            ]
        ]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($input['name'] ?? ($input['customer_name'] ?? ''));
        $company = trim($input['company'] ?? ($input['customer_company'] ?? ''));
        $package = trim($input['package'] ?? ($input['customer_package'] ?? ''));
        $otherInfo = trim($input['other_info'] ?? ($input['notes'] ?? ''));
        $status = trim($input['status'] ?? ($input['call_status'] ?? ''));

        if ($source === 'TEMP') {
            $stmt = $link->prepare("UPDATE " . tn('TBL_TEMP') . " SET 
                CUST_NAME = CASE WHEN ? != '' THEN ? ELSE CUST_NAME END,
                CUST_COMPANY = CASE WHEN ? != '' THEN ? ELSE CUST_COMPANY END,
                CUST_PACKAGE = CASE WHEN ? != '' THEN ? ELSE CUST_PACKAGE END,
                CUST_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE CUST_OTHER_INFO END,
                CALL_DIALED_STATUS = CASE WHEN ? != '' THEN ? ELSE CALL_DIALED_STATUS END
                WHERE ID = ?");
            $stmt->bind_param('ssssssssssi', $name, $name, $company, $company, $package, $package, $otherInfo, $otherInfo, $status, $status, $leadId);
        } else {
            $stmt = $link->prepare("UPDATE " . tn('TBL_MAIN') . " SET 
                MAINDATABASE_NAME = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_NAME END,
                MAINDATABASE_COMPANY = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_COMPANY END,
                MAINDATABASE_PACKAGE = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_PACKAGE END,
                MAINDATABASE_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_OTHER_INFO END,
                MAINDATABASE_CALL_DIALED_STATUS = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_CALL_DIALED_STATUS END
                WHERE ID = ?");
            $stmt->bind_param('ssssssssssi', $name, $name, $company, $company, $package, $package, $otherInfo, $otherInfo, $status, $status, $leadId);
        }
        $stmt->execute();
        $stmt->close();

        apiSuccess(['message' => 'Lead updated successfully']);
    } else {
        apiError(405, 'Method not allowed');
    }

} else {
    apiError(404, 'Telecaller endpoint not found');
}
} catch (Throwable $e) {
    apiError(500, 'Server Exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}
