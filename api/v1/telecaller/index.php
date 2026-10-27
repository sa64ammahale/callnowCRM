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
        AND LOWER(COALESCE(CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc')
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_MAIN') . " m 
            WHERE m.MAINDATABASE_MOBILE = " . tn('TBL_TEMP') . ".CUST_MOBILE 
            AND LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc')
        )
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_TEMP') . " t2 
            WHERE t2.CUST_MOBILE = " . tn('TBL_TEMP') . ".CUST_MOBILE 
            AND LOWER(t2.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc')
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
        AND LOWER(COALESCE(MAINDATABASE_CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc')
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_MAIN') . " m2 
            WHERE m2.MAINDATABASE_MOBILE = " . tn('TBL_MAIN') . ".MAINDATABASE_MOBILE 
            AND LOWER(m2.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc')
        )
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_TEMP') . " t 
            WHERE t.CUST_MOBILE = " . tn('TBL_MAIN') . ".MAINDATABASE_MOBILE 
            AND LOWER(t.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc')
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
    AND LOWER(COALESCE(CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc')
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_MAIN') . " m 
        WHERE m.MAINDATABASE_MOBILE = " . tn('TBL_TEMP') . ".CUST_MOBILE 
        AND LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc')
    )
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_TEMP') . " t2 
        WHERE t2.CUST_MOBILE = " . tn('TBL_TEMP') . ".CUST_MOBILE 
        AND LOWER(t2.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc')
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
        AND LOWER(COALESCE(MAINDATABASE_CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc')
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_MAIN') . " m2 
            WHERE m2.MAINDATABASE_MOBILE = " . tn('TBL_MAIN') . ".MAINDATABASE_MOBILE 
            AND LOWER(m2.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc')
        )
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_TEMP') . " t 
            WHERE t.CUST_MOBILE = " . tn('TBL_MAIN') . ".MAINDATABASE_MOBILE 
            AND LOWER(t.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc')
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
    AND LOWER(COALESCE(CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc')
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_MAIN') . " m 
        WHERE m.MAINDATABASE_MOBILE = " . tn('TBL_TEMP') . ".CUST_MOBILE 
        AND LOWER(m.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc')
    )
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_TEMP') . " t2 
        WHERE t2.CUST_MOBILE = " . tn('TBL_TEMP') . ".CUST_MOBILE 
        AND LOWER(t2.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc')
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
    AND LOWER(COALESCE(MAINDATABASE_CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc')
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_MAIN') . " m2 
        WHERE m2.MAINDATABASE_MOBILE = " . tn('TBL_MAIN') . ".MAINDATABASE_MOBILE 
        AND LOWER(m2.MAINDATABASE_CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc')
    )
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_TEMP') . " t 
        WHERE t.CUST_MOBILE = " . tn('TBL_MAIN') . ".MAINDATABASE_MOBILE 
        AND LOWER(t.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc')
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
    $isDnd = in_array(strtolower($rawStatus), ['dnd', 'do not call', 'dnc']);
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

} else {
    apiError(404, 'Telecaller endpoint not found');
}
} catch (Throwable $e) {
    apiError(500, 'Server Exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}
