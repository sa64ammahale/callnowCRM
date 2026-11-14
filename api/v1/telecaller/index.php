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
    switch (strtolower(trim($period))) {
        case 'yesterday':
            return "($col >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND $col < CURDATE())";
        case 'week':
        case 'this_week':
            return "YEARWEEK($col, 1) = YEARWEEK(CURDATE(), 1)";
        case 'month':
        case 'this_month':
            return "($col >= DATE_FORMAT(CURDATE(), '%Y-%m-01 00:00:00') AND $col < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01 00:00:00'), INTERVAL 1 MONTH))";
        case 'year':
        case 'this_year':
            return "YEAR($col) = YEAR(CURDATE())";
        case 'custom':
            if ($dateFrom && $dateTo) {
                $f = preg_replace('/[^0-9\-]/', '', $dateFrom);
                $t = preg_replace('/[^0-9\-]/', '', $dateTo);
                return "($col >= '$f 00:00:00' AND $col <= '$t 23:59:59')";
            } elseif ($dateFrom) {
                $f = preg_replace('/[^0-9\-]/', '', $dateFrom);
                return "($col >= '$f 00:00:00')";
            }
            return "($col >= CURDATE() AND $col < CURDATE() + INTERVAL 1 DAY)";
        case 'all':
            return "1=1";
        case 'today':
        default:
            return "($col >= CURDATE() AND $col < CURDATE() + INTERVAL 1 DAY)";
    }
}

// -----------------------------------------------------------------------------
// 0. TELECALLER PROFILE FALLBACK (/profile and /me)
// -----------------------------------------------------------------------------
if (in_array($relativePath, ['/profile', '/profile/', '/me', '/me/', '/user', '/user/'])) {
    $fullProfile = getFullUserProfileData($link, $userId);
    $dbAccess = getUserDatabaseAccess($userId);
    $teamIds = getUserAssignedTeamIds($userId);
    apiSuccess(array_merge($fullProfile ?: [], [
        'user' => $fullProfile,
        'profile' => $fullProfile,
        'database_access' => $dbAccess,
        'assigned_teams' => $teamIds,
    ]));
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
    WHERE user_id = ? AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY";
    
    $stmt = $link->prepare($sqlLogs);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $logRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $todayCalls = (int)($logRow['total_calls'] ?? 0);
    $todayConnected = (int)($logRow['connected_calls'] ?? 0);
    $todayTalkTime = (int)($logRow['total_talk_time'] ?? 0);
    
    $cacheKey = 'cached_queue_counts_' . $userId;
    $pendingCount = null;
    $stmtCache = $link->prepare("SELECT setting_value, UNIX_TIMESTAMP(updated_at) as ts FROM " . tn('TBL_APP_SETTINGS') . " WHERE setting_key = ? LIMIT 1");
    if ($stmtCache) {
        $stmtCache->bind_param('s', $cacheKey);
        $stmtCache->execute();
        $cacheRow = $stmtCache->get_result()->fetch_assoc();
        $stmtCache->close();
        if ($cacheRow && isset($cacheRow['ts']) && (time() - (int)$cacheRow['ts']) < 45) {
            $pendingCount = (int)$cacheRow['setting_value'];
        }
    }

    if ($pendingCount === null) {
        $pendingCount = 0;
        
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
        
        $sqlMain = "SELECT COUNT(*) as c FROM " . tn('TBL_MAIN') . " 
            WHERE (MAINDATABASE_CALL_DIALED_USER = ? OR MAINDATABASE_CALL_DIALED_USER IS NULL)
            AND (MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR MAINDATABASE_CALL_DIALED_STATUS = 'Pending' OR MAINDATABASE_CALL_DIALED_STATUS IS NULL OR MAINDATABASE_CALL_DIALED_STATUS = '')
            AND LOWER(COALESCE(MAINDATABASE_CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
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

        $upStmt = $link->prepare("INSERT INTO " . tn('TBL_APP_SETTINGS') . " (setting_key, setting_value, description, updated_at) VALUES (?, ?, 'Telecaller pending queue cache', NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
        if ($upStmt) {
            $pendingStr = (string)$pendingCount;
            $upStmt->bind_param('ss', $cacheKey, $pendingStr);
            $upStmt->execute();
            $upStmt->close();
        }
    }
    
    // Check user calling limit & monthly target
    $uLimitRes = $link->query("SELECT daily_calling_limit, monthly_disbursement_limit FROM " . tn('TBL_USERS') . " WHERE ID = $userId LIMIT 1");
    $uLimitRow = $uLimitRes ? $uLimitRes->fetch_assoc() : [];
    $dailyCallingLimit = isset($uLimitRow['daily_calling_limit']) ? (int)$uLimitRow['daily_calling_limit'] : 250;
    $monthlyDisbLimit = isset($uLimitRow['monthly_disbursement_limit']) ? (float)$uLimitRow['monthly_disbursement_limit'] : 1500000.00;

    apiSuccess([
        'today_calls' => $todayCalls,
        'today_connected' => $todayConnected,
        'today_talk_time' => $todayTalkTime,
        'today_pending' => $pendingCount,
        'total_assigned' => $todayCalls + $pendingCount,
        'daily_calling_limit' => $dailyCallingLimit,
        'monthly_disbursement_limit' => $monthlyDisbLimit,
        'daily_calls_target' => $dailyCallingLimit,
        'monthly_disbursement_target' => $monthlyDisbLimit,
        'calling_limit_progress_pct' => $dailyCallingLimit > 0 ? round(($todayConnected / $dailyCallingLimit) * 100, 1) : 0,
    ]);

// -----------------------------------------------------------------------------
// 2. BATCH LEADS FETCH (e.g. 15 leads for calling queue)
// -----------------------------------------------------------------------------
} elseif ($relativePath === '/batch-leads' || $relativePath === '/batch-leads/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 15)));
    $leads = [];
    
    $tempPoolLimit = max(30, $limit * 2);
    $sqlTemp = "SELECT ID FROM " . tn('TBL_TEMP') . " 
    WHERE (CALL_DIALED_STATUS IS NULL OR CALL_DIALED_STATUS = '' OR CALL_DIALED_STATUS = 'Pending' OR CALL_DIALED_STATUS = 'Not Called')
    AND (
        CALL_DIALED_TELECALLER = ? 
        OR CALL_DIALED_TELECALLER IS NULL 
        OR BATCH_ASSIGNED_AT IS NULL 
        OR BATCH_ASSIGNED_AT < (NOW() - INTERVAL 15 MINUTE)
    )
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
    ORDER BY (LAST_DIALED_DATE_TIME IS NOT NULL) ASC, LAST_DIALED_DATE_TIME ASC, TEMP_UPLOAD_DATETIME ASC, ID ASC
    LIMIT ?";
    
    $stmt = $link->prepare($sqlTemp);
    $stmt->bind_param('ii', $userId, $tempPoolLimit);
    $stmt->execute();
    $tempPoolRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $tempPoolIds = array_column($tempPoolRows, 'ID');
    if (!empty($tempPoolIds)) {
        shuffle($tempPoolIds);
        $tempSlice = array_slice($tempPoolIds, 0, $limit);
        $inClauseTemp = implode(',', array_map('intval', $tempSlice));
        
        $link->query("UPDATE " . tn('TBL_TEMP') . " 
            SET CALL_DIALED_TELECALLER = $userId, 
                BATCH_ASSIGNED_AT = NOW() 
            WHERE ID IN ($inClauseTemp) 
              AND (CALL_DIALED_TELECALLER IS NULL OR CALL_DIALED_TELECALLER = $userId OR BATCH_ASSIGNED_AT IS NULL OR BATCH_ASSIGNED_AT < (NOW() - INTERVAL 15 MINUTE))");
        
        $fetchTemp = $link->prepare("SELECT 
            ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO,
            CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, TEMP_UPLOAD_DATETIME
        FROM " . tn('TBL_TEMP') . "
        WHERE ID IN ($inClauseTemp)
          AND CALL_DIALED_TELECALLER = ?
          AND BATCH_ASSIGNED_AT >= (NOW() - INTERVAL 1 MINUTE)
        ORDER BY ID ASC");
        $fetchTemp->bind_param('i', $userId);
        $fetchTemp->execute();
        $claimedTempRows = $fetchTemp->get_result()->fetch_all(MYSQLI_ASSOC);
        $fetchTemp->close();
        
        foreach ($claimedTempRows as $row) {
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
        }
    }
    
    $remaining = $limit - count($leads);
    if ($remaining > 0) {
        $mainPoolLimit = max(150, $remaining * 10);
        $sqlMain = "SELECT m.ID
        FROM " . tn('TBL_MAIN') . " m
        WHERE (m.MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR m.MAINDATABASE_CALL_DIALED_STATUS = 'Pending' OR m.MAINDATABASE_CALL_DIALED_STATUS IS NULL OR m.MAINDATABASE_CALL_DIALED_STATUS = '')
        AND (
            m.MAINDATABASE_CALL_DIALED_USER = ? 
            OR m.MAINDATABASE_CALL_DIALED_USER IS NULL 
            OR m.BATCH_ASSIGNED_AT IS NULL 
            OR m.BATCH_ASSIGNED_AT < (NOW() - INTERVAL 15 MINUTE)
        )
        AND (
            m.LAST_DIALED_DATE_TIME IS NULL 
            OR m.LAST_DIALED_DATE_TIME < (NOW() - INTERVAL 90 DAY) 
            OR m.MAINDATABASE_CALL_DIALED_USER = ?
        )
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_LEADS') . " l 
            WHERE l.cust_id = m.ID
            AND l.assigned_to != ?
            AND (
                LOWER(COALESCE(l.lead_status_new, l.lead_status, '')) NOT IN ('lost', 'reject', 'closed', 'archived')
                OR l.next_followup_at > NOW()
            )
        )
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_CALL_LOGS') . " cl 
            WHERE cl.record_id = m.ID AND cl.source = 'MAIN'
            AND cl.user_id != ?
            AND (cl.next_followup > NOW() OR cl.created_at >= (NOW() - INTERVAL 90 DAY))
        )
        AND LOWER(COALESCE(m.MAINDATABASE_CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_TEMP') . " t 
            WHERE t.CUST_MOBILE = m.MAINDATABASE_MOBILE 
            AND LOWER(t.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        )
        ORDER BY (m.LAST_DIALED_DATE_TIME IS NOT NULL) ASC, m.LAST_DIALED_DATE_TIME ASC, m.ID ASC
        LIMIT ?";
        
        $stmt = $link->prepare($sqlMain);
        $stmt->bind_param('iiiii', $userId, $userId, $userId, $userId, $mainPoolLimit);
        $stmt->execute();
        $poolRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $poolIds = array_column($poolRows, 'ID');
        if (!empty($poolIds)) {
            shuffle($poolIds);
            $targetSlice = array_slice($poolIds, 0, $remaining);
            $inClause = implode(',', array_map('intval', $targetSlice));
            
            $link->query("UPDATE " . tn('TBL_MAIN') . " 
                SET MAINDATABASE_CALL_DIALED_USER = $userId, 
                    BATCH_ASSIGNED_AT = NOW() 
                WHERE ID IN ($inClause) 
                  AND (MAINDATABASE_CALL_DIALED_USER IS NULL OR MAINDATABASE_CALL_DIALED_USER = $userId OR BATCH_ASSIGNED_AT IS NULL OR BATCH_ASSIGNED_AT < (NOW() - INTERVAL 15 MINUTE))");
            
            $stmtFetch = $link->prepare("SELECT 
                m.ID, m.MAINDATABASE_NAME, m.MAINDATABASE_MOBILE, m.MAINDATABASE_COMPANY, 
                m.MAINDATABASE_PACKAGE, m.MAINDATABASE_OTHER_INFO, m.MAINDATABASE_CALL_DIALED_STATUS,
                m.LAST_DIALED_DATE_TIME, m.MAINDATABASE_UPLOAD_DATETIME, m.CALL_COUNT
            FROM " . tn('TBL_MAIN') . " m
            WHERE m.ID IN ($inClause)
              AND m.MAINDATABASE_CALL_DIALED_USER = ?
              AND m.BATCH_ASSIGNED_AT >= (NOW() - INTERVAL 1 MINUTE)
            ORDER BY m.ID ASC");
            $stmtFetch->bind_param('i', $userId);
            $stmtFetch->execute();
            $mainRows = $stmtFetch->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtFetch->close();
            
            $claimedCount = count($mainRows);
            if ($claimedCount < $remaining && count($poolIds) > count($targetSlice)) {
                $unattempted = array_values(array_diff($poolIds, $targetSlice));
                $needed = $remaining - $claimedCount;
                $slice2 = array_slice($unattempted, 0, $needed);
                if (!empty($slice2)) {
                    $inClause2 = implode(',', array_map('intval', $slice2));
                    $link->query("UPDATE " . tn('TBL_MAIN') . " 
                        SET MAINDATABASE_CALL_DIALED_USER = $userId, 
                            BATCH_ASSIGNED_AT = NOW() 
                        WHERE ID IN ($inClause2) 
                          AND (MAINDATABASE_CALL_DIALED_USER IS NULL OR MAINDATABASE_CALL_DIALED_USER = $userId OR BATCH_ASSIGNED_AT IS NULL OR BATCH_ASSIGNED_AT < (NOW() - INTERVAL 15 MINUTE))");
                    
                    $stmtFetch2 = $link->prepare("SELECT 
                        m.ID, m.MAINDATABASE_NAME, m.MAINDATABASE_MOBILE, m.MAINDATABASE_COMPANY, 
                        m.MAINDATABASE_PACKAGE, m.MAINDATABASE_OTHER_INFO, m.MAINDATABASE_CALL_DIALED_STATUS,
                        m.LAST_DIALED_DATE_TIME, m.MAINDATABASE_UPLOAD_DATETIME, m.CALL_COUNT
                    FROM " . tn('TBL_MAIN') . " m
                    WHERE m.ID IN ($inClause2)
                      AND m.MAINDATABASE_CALL_DIALED_USER = ?
                      AND m.BATCH_ASSIGNED_AT >= (NOW() - INTERVAL 1 MINUTE)
                    ORDER BY m.ID ASC");
                    $stmtFetch2->bind_param('i', $userId);
                    $stmtFetch2->execute();
                    $moreRows = $stmtFetch2->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmtFetch2->close();
                    foreach ($moreRows as $r) {
                        $mainRows[] = $r;
                    }
                }
            }
            
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
            }
        }
    }
    
    $hasTemp = false;
    $hasMain = false;
    foreach ($leads as $l) {
        if ($l['source'] === 'TEMP') $hasTemp = true;
        if ($l['source'] === 'MAIN') $hasMain = true;
    }
    $currentDb = ($hasTemp && $hasMain) ? 'MIXED' : ($hasTemp ? 'TEMPORARY' : 'MAIN');
    apiSuccess([
        'current_database' => $currentDb,
        'lease_duration_minutes' => 15,
        'leads' => $leads,
        'count' => count($leads),
        'limit' => $limit,
    ]);

// -----------------------------------------------------------------------------
// 3. NEXT-CALL (Single Lead Fallback with Atomic Locking)
// -----------------------------------------------------------------------------
} elseif ($relativePath === '/next-call' || $relativePath === '/next-call/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    $stmtTemp = $link->prepare("SELECT ID FROM " . tn('TBL_TEMP') . " 
    WHERE (CALL_DIALED_STATUS IS NULL OR CALL_DIALED_STATUS = '' OR CALL_DIALED_STATUS = 'Pending' OR CALL_DIALED_STATUS = 'Not Called')
    AND (
        CALL_DIALED_TELECALLER = ? 
        OR CALL_DIALED_TELECALLER IS NULL 
        OR BATCH_ASSIGNED_AT IS NULL 
        OR BATCH_ASSIGNED_AT < (NOW() - INTERVAL 15 MINUTE)
    )
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
    ORDER BY (LAST_DIALED_DATE_TIME IS NOT NULL) ASC, LAST_DIALED_DATE_TIME ASC, TEMP_UPLOAD_DATETIME ASC, ID ASC
    LIMIT 10");
    $stmtTemp->bind_param('i', $userId);
    $stmtTemp->execute();
    $tempCandidates = array_column($stmtTemp->get_result()->fetch_all(MYSQLI_ASSOC), 'ID');
    $stmtTemp->close();
    
    $claimedRow = null;
    $claimedSource = null;
    
    if (!empty($tempCandidates)) {
        shuffle($tempCandidates);
        foreach ($tempCandidates as $candId) {
            $candId = (int)$candId;
            $link->query("UPDATE " . tn('TBL_TEMP') . " 
                SET CALL_DIALED_TELECALLER = $userId, 
                    BATCH_ASSIGNED_AT = NOW() 
                WHERE ID = $candId 
                  AND (CALL_DIALED_TELECALLER IS NULL OR CALL_DIALED_TELECALLER = $userId OR BATCH_ASSIGNED_AT IS NULL OR BATCH_ASSIGNED_AT < (NOW() - INTERVAL 15 MINUTE))");
            if ($link->affected_rows > 0) {
                $fStmt = $link->prepare("SELECT 
                    ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO,
                    TEMP_UPLOAD_DATETIME, CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME
                FROM " . tn('TBL_TEMP') . " WHERE ID = ? LIMIT 1");
                $fStmt->bind_param('i', $candId);
                $fStmt->execute();
                $claimedRow = $fStmt->get_result()->fetch_assoc();
                $fStmt->close();
                if ($claimedRow) {
                    $claimedSource = 'TEMP';
                    break;
                }
            }
        }
    }
    
    if (!$claimedRow) {
        $stmtMain = $link->prepare("SELECT m.ID
        FROM " . tn('TBL_MAIN') . " m
        WHERE (m.MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR m.MAINDATABASE_CALL_DIALED_STATUS = 'Pending' OR m.MAINDATABASE_CALL_DIALED_STATUS IS NULL OR m.MAINDATABASE_CALL_DIALED_STATUS = '')
        AND (
            m.MAINDATABASE_CALL_DIALED_USER = ? 
            OR m.MAINDATABASE_CALL_DIALED_USER IS NULL 
            OR m.BATCH_ASSIGNED_AT IS NULL 
            OR m.BATCH_ASSIGNED_AT < (NOW() - INTERVAL 15 MINUTE)
        )
        AND (
            m.LAST_DIALED_DATE_TIME IS NULL 
            OR m.LAST_DIALED_DATE_TIME < (NOW() - INTERVAL 90 DAY) 
            OR m.MAINDATABASE_CALL_DIALED_USER = ?
        )
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_LEADS') . " l 
            WHERE l.cust_id = m.ID
            AND l.assigned_to != ?
            AND (
                LOWER(COALESCE(l.lead_status_new, l.lead_status, '')) NOT IN ('lost', 'reject', 'closed', 'archived')
                OR l.next_followup_at > NOW()
            )
        )
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_CALL_LOGS') . " cl 
            WHERE cl.record_id = m.ID AND cl.source = 'MAIN'
            AND cl.user_id != ?
            AND (cl.next_followup > NOW() OR cl.created_at >= (NOW() - INTERVAL 90 DAY))
        )
        AND LOWER(COALESCE(m.MAINDATABASE_CALL_DIALED_STATUS, '')) NOT IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        AND NOT EXISTS (
            SELECT 1 FROM " . tn('TBL_TEMP') . " t 
            WHERE t.CUST_MOBILE = m.MAINDATABASE_MOBILE 
            AND LOWER(t.CALL_DIALED_STATUS) IN ('do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd')
        )
        ORDER BY (m.LAST_DIALED_DATE_TIME IS NOT NULL) ASC, m.LAST_DIALED_DATE_TIME ASC, m.ID ASC
        LIMIT 20");
        $stmtMain->bind_param('iiii', $userId, $userId, $userId, $userId);
        $stmtMain->execute();
        $mainCandidates = array_column($stmtMain->get_result()->fetch_all(MYSQLI_ASSOC), 'ID');
        $stmtMain->close();
        
        if (!empty($mainCandidates)) {
            shuffle($mainCandidates);
            foreach ($mainCandidates as $candId) {
                $candId = (int)$candId;
                $link->query("UPDATE " . tn('TBL_MAIN') . " 
                    SET MAINDATABASE_CALL_DIALED_USER = $userId, 
                        BATCH_ASSIGNED_AT = NOW() 
                    WHERE ID = $candId 
                      AND (MAINDATABASE_CALL_DIALED_USER IS NULL OR MAINDATABASE_CALL_DIALED_USER = $userId OR BATCH_ASSIGNED_AT IS NULL OR BATCH_ASSIGNED_AT < (NOW() - INTERVAL 15 MINUTE))");
                if ($link->affected_rows > 0) {
                    $fStmt = $link->prepare("SELECT 
                        m.ID, m.MAINDATABASE_NAME, m.MAINDATABASE_MOBILE, m.MAINDATABASE_COMPANY,
                        m.MAINDATABASE_PACKAGE, m.MAINDATABASE_OTHER_INFO, m.MAINDATABASE_CALL_DIALED_STATUS,
                        m.LAST_DIALED_DATE_TIME, m.MAINDATABASE_UPLOAD_DATETIME
                    FROM " . tn('TBL_MAIN') . " m WHERE m.ID = ? LIMIT 1");
                    $fStmt->bind_param('i', $candId);
                    $fStmt->execute();
                    $claimedRow = $fStmt->get_result()->fetch_assoc();
                    $fStmt->close();
                    if ($claimedRow) {
                        $claimedSource = 'MAIN';
                        break;
                    }
                }
            }
        }
    }
    
    if ($claimedRow && $claimedSource === 'TEMP') {
        apiSuccess([
            'source' => 'TEMP',
            'record_id' => (int)$claimedRow['ID'],
            'customer' => [
                'name' => $claimedRow['CUST_NAME'] ?? '',
                'mobile' => $claimedRow['CUST_MOBILE'] ?? '',
                'company' => $claimedRow['CUST_COMPANY'] ?? '',
                'package' => $claimedRow['CUST_PACKAGE'] ?? '',
                'other_info' => $claimedRow['CUST_OTHER_INFO'] ?? '',
            ],
            'upload_time' => $claimedRow['TEMP_UPLOAD_DATETIME'],
            'last_status' => $claimedRow['CALL_DIALED_STATUS'] ?? 'Not Called',
            'last_call_time' => $claimedRow['LAST_DIALED_DATE_TIME'],
        ]);
    }
    
    if ($claimedRow && $claimedSource === 'MAIN') {
        apiSuccess([
            'source' => 'MAIN',
            'record_id' => (int)$claimedRow['ID'],
            'customer' => [
                'name' => $claimedRow['MAINDATABASE_NAME'] ?? '',
                'mobile' => $claimedRow['MAINDATABASE_MOBILE'] ?? '',
                'company' => $claimedRow['MAINDATABASE_COMPANY'] ?? '',
                'package' => $claimedRow['MAINDATABASE_PACKAGE'] ?? '',
                'other_info' => $claimedRow['MAINDATABASE_OTHER_INFO'] ?? '',
            ],
            'upload_time' => $claimedRow['MAINDATABASE_UPLOAD_DATETIME'],
            'last_status' => $claimedRow['MAINDATABASE_CALL_DIALED_STATUS'] ?? 'Not Called',
            'last_call_time' => $claimedRow['LAST_DIALED_DATE_TIME'],
        ]);
    }
    
    apiSuccess(['message' => 'No calls available at this time']);

// -----------------------------------------------------------------------------
// 4. SUBMIT CALL RESULT & DETAILED ANALYTICS
// -----------------------------------------------------------------------------
} elseif (preg_match('#^/calls/(\d+)/complete$#', $relativePath, $matches)) {
    $callCompletionRecordId = (int)$matches[1];
    require __DIR__ . '/submit_call.php';
    exit;
// -----------------------------------------------------------------------------
// 5. SHARP CALL REPORTING SUMMARY ANALYTICS
// -----------------------------------------------------------------------------
} elseif ($relativePath === '/reports/summary' || $relativePath === '/reports/summary/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    $period = $_GET['period'] ?? 'today';
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    
    $dateCond = buildDateCondition($period, $dateFrom, $dateTo, 'COALESCE(call_time, created_at)');
    
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
    
    // Fetch user limits for performance progress tracking
    $uLimitRes = $link->query("SELECT daily_calling_limit, monthly_disbursement_limit FROM " . tn('TBL_USERS') . " WHERE ID = $userId LIMIT 1");
    $uLimitRow = $uLimitRes ? $uLimitRes->fetch_assoc() : [];
    $userDailyLimit = isset($uLimitRow['daily_calling_limit']) ? (int)$uLimitRow['daily_calling_limit'] : 250;
    $userMonthlyDisb = isset($uLimitRow['monthly_disbursement_limit']) ? (float)$uLimitRow['monthly_disbursement_limit'] : 1500000.00;

    apiSuccess([
        'period' => $period,
        'total_calls' => $totalCalls,
        'total_duration_seconds' => $totalDuration,
        'avg_duration_seconds' => $avgDuration,
        'connected_calls' => $connectedCalls,
        'connection_rate_percent' => $connectionRate,
        'daily_calling_limit' => $userDailyLimit,
        'daily_call_target' => $userDailyLimit,
        'monthly_disbursement_limit' => $userMonthlyDisb,
        'monthly_disbursement_target' => $userMonthlyDisb,
        'calling_limit_progress_pct' => $userDailyLimit > 0 ? round(($connectedCalls / $userDailyLimit) * 100, 1) : 0,
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
    
    $dateCond = buildDateCondition($period, $dateFrom, $dateTo, 'COALESCE(cl.call_time, cl.created_at)');
    
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
    
    if ($search !== '') {
        $escSearch = $link->real_escape_string($search);
        $whereParts[] = "(m.MAINDATABASE_NAME LIKE '%$escSearch%' OR m.MAINDATABASE_MOBILE LIKE '%$escSearch%' OR m.MAINDATABASE_COMPANY LIKE '%$escSearch%' OR t.CUST_NAME LIKE '%$escSearch%' OR t.CUST_MOBILE LIKE '%$escSearch%' OR cl.notes LIKE '%$escSearch%')";
    }
    
    $whereSql = implode(' AND ', $whereParts);
    
    // Total count query
    if ($search !== '') {
        $countSql = "SELECT COUNT(*) as c FROM " . tn('TBL_CALL_LOGS') . " cl 
                     LEFT JOIN " . tn('TBL_MAIN') . " m ON (cl.source != 'TEMP') AND cl.record_id = m.ID
                     LEFT JOIN " . tn('TBL_TEMP') . " t ON (cl.source = 'TEMP') AND cl.record_id = t.ID
                     WHERE $whereSql";
    } else {
        $countSql = "SELECT COUNT(*) as c FROM " . tn('TBL_CALL_LOGS') . " cl WHERE $whereSql";
    }
    $cRes = $link->query($countSql);
    $totalCount = $cRes ? (int)$cRes->fetch_assoc()['c'] : 0;
    
    // Rich joined query
    $sql = "SELECT 
        cl.id as log_id,
        cl.record_id as record_id,
        cl.source as source,
        cl.call_status as call_status,
        cl.call_duration as call_duration,
        cl.total_duration,
        COALESCE(NULLIF(cl.timing_classification, ''), CASE
            WHEN cl.call_duration = 0 THEN 'NOT_CONNECTED'
            WHEN cl.call_duration <= 20 THEN 'CONNECTED_SHORT'
            ELSE 'CONNECTED_CONVERSATION'
        END) as timing_classification,
        cl.notes,
        cl.metadata,
        cl.next_followup,
        cl.recording_url,
        cl.sim_slot,
        cl.call_time,
        cl.dial_time,
        cl.call_end_time,
        cl.created_at,
        u.NAME as user_name,
        COALESCE(m.MAINDATABASE_NAME, t.CUST_NAME, '') as customer_name,
        COALESCE(m.MAINDATABASE_MOBILE, t.CUST_MOBILE, '') as customer_mobile,
        COALESCE(m.MAINDATABASE_COMPANY, t.CUST_COMPANY, '') as customer_company,
        COALESCE(m.MAINDATABASE_PACKAGE, t.CUST_PACKAGE, '') as customer_package
    FROM " . tn('TBL_CALL_LOGS') . " cl
    LEFT JOIN " . tn('TBL_USERS') . " u ON cl.user_id = u.ID
    LEFT JOIN " . tn('TBL_MAIN') . " m ON (cl.source != 'TEMP') AND cl.record_id = m.ID
    LEFT JOIN " . tn('TBL_TEMP') . " t ON (cl.source = 'TEMP') AND cl.record_id = t.ID
    WHERE $whereSql
    ORDER BY cl.created_at DESC
    LIMIT $offset, $limit";
    
    $res = $link->query($sql);
    if (!$res) throw new Exception("Query failed for calls: " . $link->error . " | SQL: " . $sql);
    
    $calls = [];
    while ($r = $res->fetch_assoc()) {
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
            'total_duration' => $r['total_duration'] !== null ? (int)$r['total_duration'] : null,
            'timing_classification' => $r['timing_classification'],
            'notes' => $r['notes'] ?? '',
            'metadata' => $r['metadata'] ?? null,
            'next_followup' => $r['next_followup'] ?? null,
            'recording_url' => $r['recording_url'] ?? null,
            'sim_slot' => $r['sim_slot'] ?? null,
            'call_time' => $r['call_time'] ?? $r['created_at'],
            'dial_time' => $r['dial_time'] ?? null,
            'call_end_time' => $r['call_end_time'] ?? null,
            'created_at' => $r['created_at'],
            'user_name' => $r['user_name'] ?? '',
        ];
    }
    
    $totalPages = $totalCount > 0 ? (int)ceil($totalCount / $limit) : 1;
    apiSuccess([
        'data' => $calls,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalCount,
            'total_pages' => $totalPages,
            'has_more' => $page < $totalPages,
        ]
    ]);

// -----------------------------------------------------------------------------
// 7. RECORD CALL HISTORY
// -----------------------------------------------------------------------------
} elseif (preg_match('#^/call-history/(\d+)$#', $relativePath, $matches)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    $recordId = (int)$matches[1];
    $rawSource = strtoupper(trim($_GET['source'] ?? 'MAIN'));
    if ($rawSource === 'TEMP' || $rawSource === 'TEMPORARY') {
        $source = 'TEMP';
    } elseif ($rawSource === 'LEADS' || $rawSource === 'LEAD') {
        $source = 'LEADS';
    } else {
        $source = 'MAIN';
    }
    
    if ($source === 'LEADS') {
        $whereRecord = "((cl.record_id = ? AND cl.source = 'LEADS') OR cl.lead_id = ?)";
        $scopeTypes = 'ii';
        $scopeParams = [$recordId, $recordId];
    } else {
        $whereRecord = "((cl.record_id = ? AND cl.source = ?) OR (cl.client_contact_id = ? AND cl.source = ?))";
        $scopeTypes = 'isis';
        $scopeParams = [$recordId, $source, $recordId, $source];
    }

    $scopeSql = '';
    if (in_array($userRole, ['Admin', 'Manager'], true)) {
        $scopeSql = '';
    } elseif ($userRole === 'Supervisor' && $teamId) {
        $scopeSql = ' AND EXISTS (SELECT 1 FROM ' . tn('TBL_USERS') . ' owner_u WHERE owner_u.ID = cl.user_id AND owner_u.TEAM_ID = ?)';
        $scopeTypes .= 'i';
        $scopeParams[] = $teamId;
    } else {
        $scopeSql = ' AND cl.user_id = ?';
        $scopeTypes .= 'i';
        $scopeParams[] = $userId;
    }

    $sql = "SELECT id, record_id, lead_id, client_contact_id, source, user_id, call_status, call_duration, total_duration, timing_classification, notes, metadata, next_followup, recording_url, sim_slot, call_time, dial_time, call_end_time, created_at
            FROM " . tn('TBL_CALL_LOGS') . " cl
            WHERE $whereRecord" . $scopeSql . "
            ORDER BY COALESCE(cl.call_time, cl.created_at) DESC";
    $stmt = $link->prepare($sql);
    if (!$stmt) throw new Exception('Query preparation failed: ' . $link->error);
    $stmt->bind_param($scopeTypes, ...$scopeParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    apiSuccess(['data' => $rows, 'total' => count($rows)]);
    
} elseif (in_array($relativePath, ['/submit_call', '/submit_call/', '/submit-call', '/submit-call/', '/calls/submit', '/calls/submit/', '/call/submit', '/call/submit/'], true)) {
    require __DIR__ . '/submit_call.php';
    exit;

} elseif ($relativePath === '/release-queue' || $relativePath === '/release-queue/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'Method not allowed');
    $link->query("UPDATE " . tn('TBL_TEMP') . " SET BATCH_ASSIGNED_AT = NULL, CALL_DIALED_TELECALLER = NULL WHERE CALL_DIALED_TELECALLER = $userId AND BATCH_ASSIGNED_AT IS NOT NULL");
    $relTemp = $link->affected_rows;
    $link->query("UPDATE " . tn('TBL_MAIN') . " SET BATCH_ASSIGNED_AT = NULL, MAINDATABASE_CALL_DIALED_USER = NULL WHERE MAINDATABASE_CALL_DIALED_USER = $userId AND BATCH_ASSIGNED_AT IS NOT NULL");
    $relMain = $link->affected_rows;
    apiSuccess(['total_released' => $relTemp + $relMain]);

} elseif ($relativePath === '/team-officers' || $relativePath === '/team-officers/' || $relativePath === '/teams-and-officers') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');

    $isManagerOrAdmin = in_array($userRole, ['Admin', 'Manager', 'Super Admin'], true) || $userId === 1;
    $isSupervisor = ($userRole === 'Supervisor');

    $teams = [];
    $officers = [];

    if ($isManagerOrAdmin) {
        $tRes = $link->query("SELECT ID, TEAM_NAME FROM " . tn('TBL_TEAMS') . " ORDER BY TEAM_NAME ASC");
        while ($t = $tRes->fetch_assoc()) {
            $teams[] = ['id' => (int)$t['ID'], 'name' => $t['TEAM_NAME']];
        }
        $uRes = $link->query("SELECT ID, NAME, TEAM_ID, ROLE FROM " . tn('TBL_USERS') . " WHERE STATUS = 'Active' AND ROLE != 'Super Admin' AND ID != 1 ORDER BY NAME ASC");
        while ($u = $uRes->fetch_assoc()) {
            $officers[] = [
                'id' => (int)$u['ID'],
                'name' => $u['NAME'],
                'team_id' => (int)($u['TEAM_ID'] ?? 0),
                'role' => $u['ROLE'],
            ];
        }
    } elseif ($isSupervisor && $teamId) {
        $tStmt = $link->prepare("SELECT ID, TEAM_NAME FROM " . tn('TBL_TEAMS') . " WHERE ID = ? LIMIT 1");
        $tStmt->bind_param('i', $teamId);
        $tStmt->execute();
        $tRow = $tStmt->get_result()->fetch_assoc();
        $tStmt->close();
        if ($tRow) {
            $teams[] = ['id' => (int)$tRow['ID'], 'name' => $tRow['TEAM_NAME']];
        }
        $uStmt = $link->prepare("SELECT ID, NAME, TEAM_ID, ROLE FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = ? AND STATUS = 'Active' AND ROLE != 'Super Admin' AND ID != 1 ORDER BY NAME ASC");
        $uStmt->bind_param('i', $teamId);
        $uStmt->execute();
        $uRes = $uStmt->get_result();
        while ($u = $uRes->fetch_assoc()) {
            $officers[] = [
                'id' => (int)$u['ID'],
                'name' => $u['NAME'],
                'team_id' => (int)($u['TEAM_ID'] ?? 0),
                'role' => $u['ROLE'],
            ];
        }
        $uStmt->close();
    } else {
        $officers[] = ['id' => $userId, 'name' => $tokenData['user_name'] ?? 'Me', 'team_id' => (int)$teamId, 'role' => $userRole];
    }

    apiSuccess([
        'teams' => $teams,
        'officers' => $officers,
        'can_filter_teams' => $isManagerOrAdmin || $isSupervisor,
    ]);

} elseif ($relativePath === '/lookup-contact' || $relativePath === '/lookup-contact/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    $rawPhone = trim($_GET['phone'] ?? ($_GET['mobile'] ?? ''));
    if ($rawPhone === '') apiError(400, 'Phone number is required');
    $cleanPhone = preg_replace('/[^0-9]/', '', $rawPhone);
    if (strlen($cleanPhone) > 10) {
        $cleanPhone = substr($cleanPhone, -10);
    }
    if (strlen($cleanPhone) < 10) {
        apiError(400, 'Invalid phone number: must have at least 10 digits');
    }

    $contactData = null;

    $stmtLead = $link->prepare("
        SELECT l.lead_id, l.cust_id, l.NAME, l.MOBILE, l.EMAIL, l.CITY, l.STATE, l.loan_type, l.loan_amount,
               l.lead_status_new, l.target_bank, l.assigned_to, l.NOTES, u.NAME as officer_name
        FROM " . tn('TBL_LEADS') . " l
        LEFT JOIN " . tn('TBL_USERS') . " u ON l.assigned_to = u.ID
        WHERE l.MOBILE LIKE ?
        ORDER BY l.lead_id DESC LIMIT 1
    ");
    $phoneParam = "%$cleanPhone";
    $stmtLead->bind_param('s', $phoneParam);
    $stmtLead->execute();
    $leadRow = $stmtLead->get_result()->fetch_assoc();
    $stmtLead->close();

    if ($leadRow) {
        $contactData = [
            'found' => true,
            'source_type' => 'LEAD',
            'lead_id' => (int)$leadRow['lead_id'],
            'record_id' => (int)($leadRow['cust_id'] ?? $leadRow['lead_id']),
            'name' => $leadRow['NAME'] ?? '',
            'mobile' => $leadRow['MOBILE'] ?? '',
            'email' => $leadRow['EMAIL'] ?? '',
            'city' => $leadRow['CITY'] ?? '',
            'state' => $leadRow['STATE'] ?? '',
            'status' => $leadRow['lead_status_new'] ?? 'Lead',
            'loan_type' => $leadRow['loan_type'] ?? '',
            'loan_amount' => $leadRow['loan_amount'] ?? '',
            'target_bank' => $leadRow['target_bank'] ?? '',
            'assigned_to' => (int)$leadRow['assigned_to'],
            'officer_name' => $leadRow['officer_name'] ?? '',
            'notes' => $leadRow['NOTES'] ?? '',
            'is_lead' => true,
        ];
    } else {
        $stmtMain = $link->prepare("
            SELECT m.ID, m.MAINDATABASE_NAME, m.MAINDATABASE_MOBILE, m.MAINDATABASE_EMAIL, m.MAINDATABASE_CITY,
                   m.MAINDATABASE_CALL_DIALED_STATUS, m.MAINDATABASE_CALL_DIALED_USER, m.MAINDATABASE_OTHER_INFO,
                   m.LAST_DIALED_DATE_TIME, m.LAST_CONNECTED_PERIOD, u.NAME as officer_name
            FROM " . tn('TBL_MAIN') . " m
            LEFT JOIN " . tn('TBL_USERS') . " u ON m.MAINDATABASE_CALL_DIALED_USER = u.ID
            WHERE m.MAINDATABASE_MOBILE LIKE ?
            ORDER BY m.ID DESC LIMIT 1
        ");
        $stmtMain->bind_param('s', $phoneParam);
        $stmtMain->execute();
        $mainRow = $stmtMain->get_result()->fetch_assoc();
        $stmtMain->close();

        if ($mainRow) {
            $contactData = [
                'found' => true,
                'source_type' => 'MAIN',
                'record_id' => (int)$mainRow['ID'],
                'name' => $mainRow['MAINDATABASE_NAME'] ?? '',
                'mobile' => $mainRow['MAINDATABASE_MOBILE'] ?? '',
                'email' => $mainRow['MAINDATABASE_EMAIL'] ?? '',
                'city' => $mainRow['MAINDATABASE_CITY'] ?? '',
                'status' => $mainRow['MAINDATABASE_CALL_DIALED_STATUS'] ?: 'Pending',
                'assigned_to' => (int)($mainRow['MAINDATABASE_CALL_DIALED_USER'] ?? 0),
                'officer_name' => $mainRow['officer_name'] ?? '',
                'notes' => $mainRow['MAINDATABASE_OTHER_INFO'] ?? '',
                'last_dialed' => $mainRow['LAST_DIALED_DATE_TIME'],
                'last_connected' => $mainRow['LAST_CONNECTED_PERIOD'],
                'is_lead' => false,
            ];
        } else {
            $stmtTemp = $link->prepare("
                SELECT t.ID, t.CUST_NAME, t.CUST_MOBILE, t.CUST_EMAIL, t.CUST_CITY,
                       t.CALL_DIALED_STATUS, t.CALL_DIALED_TELECALLER, t.CUST_OTHER_INFO,
                       t.LAST_DIALED_DATE_TIME, t.LAST_CONNECTED_PERIOD, u.NAME as officer_name
                FROM " . tn('TBL_TEMP') . " t
                LEFT JOIN " . tn('TBL_USERS') . " u ON t.CALL_DIALED_TELECALLER = u.ID
                WHERE t.CUST_MOBILE LIKE ?
                ORDER BY t.ID DESC LIMIT 1
            ");
            $stmtTemp->bind_param('s', $phoneParam);
            $stmtTemp->execute();
            $tempRow = $stmtTemp->get_result()->fetch_assoc();
            $stmtTemp->close();

            if ($tempRow) {
                $contactData = [
                    'found' => true,
                    'source_type' => 'TEMP',
                    'record_id' => (int)$tempRow['ID'],
                    'name' => $tempRow['CUST_NAME'] ?? '',
                    'mobile' => $tempRow['CUST_MOBILE'] ?? '',
                    'email' => $tempRow['CUST_EMAIL'] ?? '',
                    'city' => $tempRow['CUST_CITY'] ?? '',
                    'status' => $tempRow['CALL_DIALED_STATUS'] ?: 'Pending',
                    'assigned_to' => (int)($tempRow['CALL_DIALED_TELECALLER'] ?? 0),
                    'officer_name' => $tempRow['officer_name'] ?? '',
                    'notes' => $tempRow['CUST_OTHER_INFO'] ?? '',
                    'last_dialed' => $tempRow['LAST_DIALED_DATE_TIME'],
                    'last_connected' => $tempRow['LAST_CONNECTED_PERIOD'],
                    'is_lead' => false,
                ];
            } else {
                $contactData = [
                    'found' => false,
                    'source_type' => 'NEW',
                    'mobile' => $cleanPhone,
                    'status' => 'New Prospect',
                    'is_lead' => false,
                ];
            }
        }
    }

    apiSuccess($contactData);

} elseif ($relativePath === '/leads' || $relativePath === '/leads/') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');
        $statusFilter = trim($_GET['status'] ?? 'all');
        $sourceFilter = strtoupper(trim($_GET['source'] ?? 'all'));
        $period = $_GET['period'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;

        $whereParts = [];
        $params = [];
        $types = '';

        if ($period !== null && strtolower(trim($period)) !== 'all') {
            $whereParts[] = buildDateCondition($period, $dateFrom, $dateTo, 'COALESCE(l.created_at, l.updated_at)');
        }

        $scope = strtolower(trim($_GET['scope'] ?? 'all'));
        $filterUserId = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null;
        $filterTeamId = !empty($_GET['team_id']) ? (int)$_GET['team_id'] : null;

        if ($userRole === 'Admin' || $userRole === 'Manager' || $userRole === 'Super Admin' || $userId === 1) {
            if ($scope === 'mine') {
                $whereParts[] = "(l.assigned_to = ? OR l.created_by = ?)";
                $params[] = $userId;
                $params[] = $userId;
                $types .= 'ii';
            } elseif ($filterUserId) {
                $whereParts[] = "l.assigned_to = ?";
                $params[] = $filterUserId;
                $types .= 'i';
            } elseif ($filterTeamId) {
                $whereParts[] = "(u.TEAM_ID = ? OR l.team_id = ?)";
                $params[] = $filterTeamId;
                $params[] = $filterTeamId;
                $types .= 'ii';
            } else {
                $whereParts[] = "1=1";
            }
        } elseif ($userRole === 'Supervisor' && $teamId) {
            if ($scope === 'mine') {
                $whereParts[] = "(l.assigned_to = ? OR l.created_by = ?)";
                $params[] = $userId;
                $params[] = $userId;
                $types .= 'ii';
            } elseif ($filterUserId) {
                $whereParts[] = "l.assigned_to = ? AND (u.TEAM_ID = ? OR l.team_id = ?)";
                $params[] = $filterUserId;
                $params[] = $teamId;
                $params[] = $teamId;
                $types .= 'iii';
            } elseif ($filterTeamId && $filterTeamId === $teamId) {
                $whereParts[] = "(u.TEAM_ID = ? OR l.team_id = ?)";
                $params[] = $teamId;
                $params[] = $teamId;
                $types .= 'ii';
            } else {
                $whereParts[] = "(u.TEAM_ID = ? OR l.team_id = ? OR l.assigned_to = ? OR l.created_by = ?)";
                $params[] = $teamId;
                $params[] = $teamId;
                $params[] = $userId;
                $params[] = $userId;
                $types .= 'iiii';
            }
        } else {
            $whereParts[] = "(l.assigned_to = ? OR l.created_by = ?)";
            $params[] = $userId;
            $params[] = $userId;
            $types .= 'ii';
        }

        if ($statusFilter !== '' && $statusFilter !== 'all') {
            if (strtolower($statusFilter) === 'not_called' || strtolower($statusFilter) === 'not called') {
                $whereParts[] = "(m.MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR m.MAINDATABASE_CALL_DIALED_STATUS IS NULL OR m.MAINDATABASE_CALL_DIALED_STATUS = '' OR l.lead_status_new = 'LEAD')";
            } elseif (strtolower($statusFilter) === 'called') {
                $whereParts[] = "(m.MAINDATABASE_CALL_DIALED_STATUS != 'Not Called' AND m.MAINDATABASE_CALL_DIALED_STATUS IS NOT NULL AND m.MAINDATABASE_CALL_DIALED_STATUS != '')";
            } else {
                $whereParts[] = "(l.lead_status_new = ? OR l.lead_status = ? OR m.MAINDATABASE_CALL_DIALED_STATUS = ?)";
                $params[] = $statusFilter;
                $params[] = $statusFilter;
                $params[] = $statusFilter;
                $types .= 'sss';
            }
        }

        if ($search !== '') {
            $whereParts[] = "(m.MAINDATABASE_NAME LIKE ? OR m.MAINDATABASE_MOBILE LIKE ? OR m.MAINDATABASE_COMPANY LIKE ? OR l.NAME LIKE ? OR l.MOBILE LIKE ? OR l.COMPANY_NAME LIKE ? OR l.remarks LIKE ?)";
            $like = "%$search%";
            for ($i = 0; $i < 7; $i++) {
                $params[] = $like;
            }
            $types .= 'sssssss';
        }

        $whereSql = "WHERE " . implode(" AND ", $whereParts);

        $countSql = "SELECT COUNT(*) as c 
                     FROM " . tn('TBL_LEADS') . " l 
                     LEFT JOIN " . tn('TBL_MAIN') . " m ON l.cust_id = m.ID 
                     LEFT JOIN " . tn('TBL_USERS') . " u ON l.assigned_to = u.ID 
                     $whereSql";
        $stmtC = $link->prepare($countSql);
        if ($types !== '') {
            $stmtC->bind_param($types, ...$params);
        }
        $stmtC->execute();
        $total = (int)$stmtC->get_result()->fetch_assoc()['c'];
        $stmtC->close();

        $dataSql = "SELECT 
                        l.lead_id,
                        l.cust_id,
                        COALESCE(NULLIF(m.MAINDATABASE_NAME, ''), NULLIF(l.NAME, ''), CONCAT('Lead #', l.lead_id)) as name,
                        COALESCE(NULLIF(m.MAINDATABASE_MOBILE, ''), NULLIF(l.MOBILE, ''), '') as mobile,
                        COALESCE(NULLIF(m.MAINDATABASE_COMPANY, ''), NULLIF(l.COMPANY_NAME, ''), '') as company,
                        COALESCE(l.loan_amount, m.MAINDATABASE_PACKAGE, '') as package,
                        COALESCE(NULLIF(l.remarks, ''), NULLIF(l.OTHER_INFO, ''), m.MAINDATABASE_OTHER_INFO, '') as other_info,
                        COALESCE(l.lead_status_new, l.lead_status, 'LEAD') as status,
                        COALESCE(m.MAINDATABASE_CALL_DIALED_STATUS, 'Not Called') as last_status,
                        m.LAST_DIALED_DATE_TIME as last_call_time,
                        COALESCE(l.created_at, m.MAINDATABASE_UPLOAD_DATETIME) as upload_time,
                        COALESCE(m.CALL_COUNT, 0) as call_count,
                        l.assigned_to,
                        u.NAME as assigned_user_name,
                        COALESCE(l.team_id, u.TEAM_ID) as team_id,
                        t.TEAM_NAME as team_name,
                        l.loan_type,
                        l.lead_stage,
                        l.priority,
                        l.login_bank_name,
                        l.bank_name,
                        l.net_salary,
                        l.salary_account,
                        l.next_followup_at
                    FROM " . tn('TBL_LEADS') . " l
                    LEFT JOIN " . tn('TBL_MAIN') . " m ON l.cust_id = m.ID
                    LEFT JOIN " . tn('TBL_USERS') . " u ON l.assigned_to = u.ID
                    LEFT JOIN " . tn('TBL_TEAMS') . " t ON (COALESCE(l.team_id, u.TEAM_ID) = t.ID)
                    $whereSql
                    ORDER BY COALESCE(l.created_at, l.updated_at) DESC, l.lead_id DESC
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
                'id' => (int)$r['lead_id'],
                'record_id' => (int)($r['cust_id'] ?: $r['lead_id']),
                'lead_id' => (int)$r['lead_id'],
                'cust_id' => (int)$r['cust_id'],
                'source' => 'LEAD',
                'name' => $r['name'] ?? '',
                'mobile' => $r['mobile'] ?? '',
                'company' => $r['company'] ?? '',
                'package' => $r['package'] ?? '',
                'other_info' => $r['other_info'] ?? '',
                'status' => $r['status'] ?? 'LEAD',
                'last_status' => $r['last_status'] ?? 'Not Called',
                'last_call_time' => $r['last_call_time'],
                'upload_time' => $r['upload_time'],
                'call_count' => (int)($r['call_count'] ?? 0),
                'assigned_user' => $r['assigned_user_name'] ?? null,
                'assigned_user_name' => $r['assigned_user_name'] ?? null,
                'assigned_user_id' => (int)($r['assigned_to'] ?? 0),
                'team_id' => (int)($r['team_id'] ?? 0),
                'team_name' => $r['team_name'] ?? null,
                'is_own_lead' => ((int)($r['assigned_to'] ?? 0) === $userId || (int)($r['created_by'] ?? 0) === $userId),
                'loan_type' => $r['loan_type'] ?? 'Personal Loan',
                'lead_stage' => $r['lead_stage'] ?? 'Warm',
                'priority' => $r['priority'] ?? 'Medium',
                'login_bank_name' => $r['login_bank_name'] ?? null,
                'bank_name' => $r['bank_name'] ?? null,
                'net_salary' => $r['net_salary'] ?? null,
                'salary_account' => $r['salary_account'] ?? null,
                'next_followup_at' => $r['next_followup_at'] ?? null,
            ];
        }

        $totalPages = $total > 0 ? (int)ceil($total / $limit) : 1;
        apiSuccess([
            'leads' => $leadsList,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $page < $totalPages,
            ]
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = apiReadJson();

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
                if ((int)($exist['MAINDATABASE_CALL_DIALED_USER'] ?? 0) > 0 && (int)$exist['MAINDATABASE_CALL_DIALED_USER'] !== $userId && !in_array($userRole, ['Admin', 'Manager', 'Super Admin'], true) && $userId !== 1) apiError(403, 'Record belongs to another user');
                $isNew = false;
                $uStmt = $link->prepare("UPDATE " . tn('TBL_MAIN') . " SET 
                    MAINDATABASE_NAME = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_NAME END,
                    MAINDATABASE_COMPANY = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_COMPANY END,
                    MAINDATABASE_PACKAGE = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_PACKAGE END,
                    MAINDATABASE_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_OTHER_INFO END,
                    MAINDATABASE_CALL_DIALED_USER = COALESCE(MAINDATABASE_CALL_DIALED_USER, ?),
                    MAINDATABASE_CALL_DIALED_STATUS = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_CALL_DIALED_STATUS END
                    WHERE ID = ?");
                $uStmt->bind_param('ssssssssissi',
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
            $chkStmt = $link->prepare("SELECT ID, CALL_DIALED_TELECALLER FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = ? LIMIT 1");
            $chkStmt->bind_param('s', $mobile);
            $chkStmt->execute();
            $exist = $chkStmt->get_result()->fetch_assoc();
            $chkStmt->close();

            if ($exist) {
                $createdId = (int)$exist['ID'];
                $isNew = false;
                if ((int)($exist['CALL_DIALED_TELECALLER'] ?? 0) > 0 && (int)$exist['CALL_DIALED_TELECALLER'] !== $userId && !in_array($userRole, ['Admin', 'Manager', 'Super Admin'], true) && $userId !== 1) apiError(403, 'Record belongs to another user');
                $uStmt = $link->prepare("UPDATE " . tn('TBL_TEMP') . " SET 
                    CUST_NAME = CASE WHEN ? != '' THEN ? ELSE CUST_NAME END,
                    CUST_COMPANY = CASE WHEN ? != '' THEN ? ELSE CUST_COMPANY END,
                    CUST_PACKAGE = CASE WHEN ? != '' THEN ? ELSE CUST_PACKAGE END,
                    CUST_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE CUST_OTHER_INFO END,
                    CALL_DIALED_TELECALLER = COALESCE(CALL_DIALED_TELECALLER, ?),
                    CALL_DIALED_STATUS = CASE WHEN ? != '' THEN ? ELSE CALL_DIALED_STATUS END
                    WHERE ID = ?");
                $uStmt->bind_param('ssssssssissi',
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

        $loanType = trim($input['loan_type'] ?? '');
        $leadStage = trim($input['lead_stage'] ?? ($input['stage'] ?? 'Warm'));
        if (!in_array($leadStage, ['Hot', 'Warm', 'Cold'], true)) $leadStage = 'Warm';
        $priority = trim($input['priority'] ?? 'Medium');
        if (!in_array($priority, ['High', 'Medium', 'Low'], true)) $priority = 'Medium';
        $loginBank = trim($input['login_bank_name'] ?? ($input['bank_name'] ?? ''));
        $bankName = trim($input['bank_name'] ?? $loginBank);
        $netSalary = trim($input['net_salary'] ?? '');
        $salaryAccount = trim($input['salary_account'] ?? '');
        $leadStatusNew = strtoupper(trim($input['lead_status_new'] ?? ($input['status'] ?? 'LEAD')));
        $validPipeline = ['LEAD', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT'];
        if (!in_array($leadStatusNew, $validPipeline, true)) $leadStatusNew = 'LEAD';
        $nextFollowupAt = !empty($input['next_followup_at']) ? date('Y-m-d H:i:s', strtotime($input['next_followup_at'])) : null;

        $newLeadId = 0;
        if ($createdId > 0) {
            $lChk = $link->prepare("SELECT lead_id FROM " . tn('TBL_LEADS') . " WHERE cust_id = ? OR MOBILE = ? ORDER BY lead_id DESC LIMIT 1");
            if ($lChk) {
                $lChk->bind_param('is', $createdId, $mobile);
                $lChk->execute();
                $lExist = $lChk->get_result()->fetch_assoc();
                $lChk->close();

                if (!$lExist) {
                    $lStmt = $link->prepare("INSERT INTO " . tn('TBL_LEADS') . " (
                        cust_id, NAME, MOBILE, COMPANY_NAME, OTHER_INFO, assigned_to, assigned_by,
                        lead_status, lead_status_new, loan_amount, loan_type, login_bank_name, bank_name,
                        net_salary, salary_account, priority, lead_stage, next_followup_at, remarks,
                        created_by, updated_by, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'New', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    if ($lStmt) {
                        $lStmt->bind_param('issssiisssssssssssii',
                            $createdId, $name, $mobile, $company, $otherInfo, $userId, $userId,
                            $leadStatusNew, $package, $loanType, $loginBank, $bankName,
                            $netSalary, $salaryAccount, $priority, $leadStage, $nextFollowupAt, $otherInfo,
                            $userId, $userId
                        );
                        $lStmt->execute();
                        $newLeadId = (int)$lStmt->insert_id;
                        $lStmt->close();
                    }
                } else {
                    $newLeadId = (int)$lExist['lead_id'];
                    $lUpd = $link->prepare("UPDATE " . tn('TBL_LEADS') . " SET 
                        NAME = CASE WHEN ? != '' THEN ? ELSE NAME END,
                        MOBILE = CASE WHEN ? != '' THEN ? ELSE MOBILE END,
                        COMPANY_NAME = CASE WHEN ? != '' THEN ? ELSE COMPANY_NAME END,
                        OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE OTHER_INFO END,
                        loan_amount = CASE WHEN ? != '' THEN ? ELSE loan_amount END,
                        loan_type = CASE WHEN ? != '' THEN ? ELSE loan_type END,
                        login_bank_name = CASE WHEN ? != '' THEN ? ELSE login_bank_name END,
                        bank_name = CASE WHEN ? != '' THEN ? ELSE bank_name END,
                        net_salary = CASE WHEN ? != '' THEN ? ELSE net_salary END,
                        salary_account = CASE WHEN ? != '' THEN ? ELSE salary_account END,
                        priority = CASE WHEN ? != '' THEN ? ELSE priority END,
                        lead_stage = CASE WHEN ? != '' THEN ? ELSE lead_stage END,
                        next_followup_at = COALESCE(?, next_followup_at),
                        remarks = CASE WHEN ? != '' THEN ? ELSE remarks END,
                        updated_by = ?,
                        updated_at = NOW()
                        WHERE lead_id = ?");
                    if ($lUpd) {
                        $lUpd->bind_param('ssssssssssssssssssssssssssii',
                            $name, $name,
                            $mobile, $mobile,
                            $company, $company,
                            $otherInfo, $otherInfo,
                            $package, $package,
                            $loanType, $loanType,
                            $loginBank, $loginBank,
                            $bankName, $bankName,
                            $netSalary, $netSalary,
                            $salaryAccount, $salaryAccount,
                            $priority, $priority,
                            $leadStage, $leadStage,
                            $nextFollowupAt,
                            $otherInfo, $otherInfo,
                            $userId,
                            $newLeadId
                        );
                        $lUpd->execute();
                        $lUpd->close();
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
                'id' => $newLeadId > 0 ? $newLeadId : $createdId,
                'record_id' => $createdId,
                'lead_id' => $newLeadId > 0 ? $newLeadId : null,
                'source' => $source,
                'name' => $name,
                'mobile' => $mobile,
                'company' => $company,
                'package' => $package,
                'other_info' => $otherInfo,
                'status' => $status,
                'last_status' => $status,
                'created_at' => date('Y-m-d H:i:s'),
            ]
        ], $isNew ? 201 : 200);
    } else {
        apiError(405, 'Method not allowed');
    }

} elseif (preg_match('#^/leads/(\d+)/stage$#', $relativePath, $stageMatches)) {
    $leadParamId = (int)$stageMatches[1];
    $input = apiReadJson();

    // Permissions check: Super Admin, Admin, Manager, Supervisor can move any lead to next stage
    // Sales Officer / Telecaller cannot change the lead status
    $canMoveLeadStage = in_array(strtolower(trim($userRole)), ['super admin', 'admin', 'manager', 'supervisor'], true) || $userId === 1;
    if (!$canMoveLeadStage) {
        apiError(403, 'Permission denied: Sales Officers and Telecallers cannot change lead status. Only Super Admin, Admin, and Supervisor can move stages.');
    }

    $newStage = strtoupper(trim((string)($input['lead_status_new'] ?? ($input['status'] ?? ''))));
    $validStages = ['LEAD', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT'];
    if (!in_array($newStage, $validStages, true)) {
        apiError(422, 'Invalid pipeline stage: ' . $newStage);
    }

    // Resolve lead in TBL_LEADS (can be lead_id or cust_id)
    $stmt = $link->prepare("SELECT lead_id, cust_id, assigned_to, team_id, lead_status_new FROM " . tn('TBL_LEADS') . " WHERE lead_id = ? OR cust_id = ? ORDER BY lead_id DESC LIMIT 1");
    $stmt->bind_param('ii', $leadParamId, $leadParamId);
    $stmt->execute();
    $leadRecord = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$leadRecord) {
        apiError(404, 'Lead record not found in CRM');
    }

    $realLeadId = (int)$leadRecord['lead_id'];
    $prevStage = (string)($leadRecord['lead_status_new'] ?? 'LEAD');
    $remarks = trim((string)($input['other_info'] ?? ($input['remarks'] ?? ($input['notes'] ?? ''))));

    $legacyStatus = match ($newStage) {
        'DISBURSED' => 'Converted',
        'FOLLOWUP' => 'Follow_Up',
        'REJECT' => 'Lost',
        default => 'New',
    };

    $uStmt = $link->prepare("UPDATE " . tn('TBL_LEADS') . " SET lead_status_new = ?, lead_status = ?, updated_by = ?, updated_at = NOW() WHERE lead_id = ?");
    $uStmt->bind_param('ssii', $newStage, $legacyStatus, $userId, $realLeadId);
    $uStmt->execute();
    $uStmt->close();

    $custId = (int)($leadRecord['cust_id'] ?? 0);
    if ($custId > 0) {
        $rawSource = strtoupper(trim($_GET['source'] ?? 'MAIN'));
        if ($rawSource === 'TEMP') {
            @$link->query("UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_STATUS = '$newStage', LAST_DIALED_DATE_TIME = NOW() WHERE ID = $custId");
        } else {
            @$link->query("UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_CALL_DIALED_STATUS = '$newStage', LAST_DIALED_DATE_TIME = NOW() WHERE ID = $custId");
        }
    }

    if ($remarks !== '') {
        $nStmt = $link->prepare("INSERT INTO " . tn('TBL_LEAD_NOTES') . " (lead_id, user_id, note, created_at) VALUES (?, ?, ?, NOW())");
        if ($nStmt) {
            $fullNote = "Stage advanced from {$prevStage} to {$newStage}. Note: {$remarks}";
            $nStmt->bind_param('iis', $realLeadId, $userId, $fullNote);
            $nStmt->execute();
            $nStmt->close();
        }
    }

    $nextFollowup = !empty($input['next_followup']) ? date('Y-m-d H:i:s', strtotime($input['next_followup'])) : null;
    if ($nextFollowup) {
        $fStmt = $link->prepare("INSERT INTO " . tn('TBL_LEAD_FOLLOWUPS') . " (lead_id, client_contact_id, contact_source, user_id, followup_at, note, status, created_at) VALUES (?, ?, 'MAIN', ?, ?, ?, 'OPEN', NOW())");
        if ($fStmt) {
            $fNote = $remarks ?: "Follow-up for {$newStage} stage";
            $fStmt->bind_param('iiiss', $realLeadId, $custId, $userId, $nextFollowup, $fNote);
            $fStmt->execute();
            $fStmt->close();
        }
    }

    logActivity($link, $userId, 'STAGE_CHANGE', "Lead #{$realLeadId} moved from {$prevStage} to {$newStage} by {$userRole} #{$userId}", (string)$realLeadId, tn('TBL_LEADS'));

    apiSuccess([
        'message' => "Lead stage moved to {$newStage}",
        'lead_id' => $realLeadId,
        'previous_stage' => $prevStage,
        'new_stage' => $newStage,
        'updated_by' => $userId,
        'updated_at' => date('Y-m-d H:i:s')
    ]);

} elseif (preg_match('#^/leads/(\d+)$#', $relativePath, $matches)) {
    $leadId = (int)$matches[1];
    $rawSource = strtoupper(trim($_GET['source'] ?? 'MAIN'));
    if (in_array($rawSource, ['LEAD', 'LEADS'], true)) {
        $resolve = $link->prepare('SELECT cust_id, assigned_to, created_by FROM ' . tn('TBL_LEADS') . ' WHERE lead_id = ?');
        $resolve->bind_param('i', $leadId);
        $resolve->execute();
        $crmLead = $resolve->get_result()->fetch_assoc();
        $resolve->close();
        if (!$crmLead) apiError(404, 'Lead not found');
        if ((int)$crmLead['assigned_to'] !== $userId && (int)$crmLead['created_by'] !== $userId && !in_array($userRole, ['Admin', 'Manager', 'Super Admin'], true) && $userId !== 1) apiError(403, 'Not authorized to access this lead');
        $leadId = (int)$crmLead['cust_id'];
    }
    $source = ($rawSource === 'TEMP' || $rawSource === 'TEMPORARY') ? 'TEMP' : 'MAIN';
    $ownerColumn = $source === 'TEMP' ? 'CALL_DIALED_TELECALLER' : 'MAINDATABASE_CALL_DIALED_USER';
    $recordTable = $source === 'TEMP' ? 'TBL_TEMP' : 'TBL_MAIN';
    $guard = $link->prepare('SELECT ' . $ownerColumn . ' AS owner_id FROM ' . tn($recordTable) . ' WHERE ID = ?');
    $guard->bind_param('i', $leadId);
    $guard->execute();
    $owner = $guard->get_result()->fetch_assoc();
    $guard->close();
    if (!$owner) apiError(404, 'Lead not found');
    if ((int)($owner['owner_id'] ?? 0) !== $userId && !in_array($userRole, ['Admin', 'Manager', 'Super Admin'], true) && $userId !== 1) {
        apiError(403, 'Not authorized to access this record');
    }

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
        $input = apiReadJson();
        $name = trim($input['name'] ?? ($input['customer_name'] ?? ''));
        $company = trim($input['company'] ?? ($input['customer_company'] ?? ''));
        $package = trim($input['package'] ?? ($input['customer_package'] ?? ''));
        $otherInfo = trim($input['other_info'] ?? ($input['notes'] ?? ''));
        $status = trim($input['status'] ?? ($input['call_status'] ?? ''));
        $canMoveLeadStage = in_array(strtolower(trim($userRole)), ['super admin', 'admin', 'manager', 'supervisor'], true) || $userId === 1;
        $pipelineStages = ['LEAD', 'FOLLOWUP', 'INTERNAL_UNDERWRITING', 'LOGIN', 'BANK_UNDERWRITING', 'SANCTIONED', 'DISBURSED', 'REJECT'];
        if (!$canMoveLeadStage && in_array(strtoupper($status), $pipelineStages, true)) {
            $status = '';
        }

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

        $lUpd = $link->prepare("UPDATE " . tn('TBL_LEADS') . " SET 
            NAME = CASE WHEN ? != '' THEN ? ELSE NAME END,
            COMPANY_NAME = CASE WHEN ? != '' THEN ? ELSE COMPANY_NAME END,
            loan_amount = CASE WHEN ? != '' THEN ? ELSE loan_amount END,
            remarks = CASE WHEN ? != '' THEN ? ELSE remarks END,
            OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE OTHER_INFO END,
            updated_by = ?,
            updated_at = NOW()
            WHERE lead_id = ? OR cust_id = ?");
        if ($lUpd) {
            $lUpd->bind_param('sssssssssiii',
                $name, $name,
                $company, $company,
                $package, $package,
                $otherInfo, $otherInfo,
                $otherInfo,
                $userId,
                $leadId, $leadId
            );
            $lUpd->execute();
            $lUpd->close();
        }

        apiSuccess(['message' => 'Lead updated successfully']);
    } else {
        apiError(405, 'Method not allowed');
    }

} elseif ($relativePath === '/follow-ups' || $relativePath === '/follow-ups/' || $relativePath === '/followups' || $relativePath === '/followups/') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $status = $_GET['status'] ?? 'OPEN';
        $due = $_GET['due'] ?? 'all';
        $period = $_GET['period'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        $types = '';

        if ($period !== null && strtolower(trim($period)) !== 'all') {
            $where[] = buildDateCondition($period, $dateFrom, $dateTo, 'f.followup_at');
        }

        $scope = strtolower(trim($_GET['scope'] ?? 'mine'));
        if (!empty($_GET['user_id'])) {
            $targetUserId = (int)$_GET['user_id'];
            if ($targetUserId === $userId || in_array($userRole, ['Admin', 'Manager', 'Super Admin'], true) || ($userRole === 'Supervisor' && $teamId)) {
                $where[] = "(f.user_id = ? OR l.assigned_to = ?)";
                $params[] = $targetUserId;
                $params[] = $targetUserId;
                $types .= 'ii';
            } else {
                $where[] = "(f.user_id = ? OR l.assigned_to = ?)";
                $params[] = $userId;
                $params[] = $userId;
                $types .= 'ii';
            }
        } elseif ($scope === 'all' && (in_array($userRole, ['Admin', 'Manager', 'Super Admin'], true) || $userId === 1)) {
            // Admin/Manager viewing all follow-ups company-wide - no user filter
        } elseif ($scope === 'all' && $userRole === 'Supervisor' && $teamId) {
            // Supervisor viewing team-wide follow-ups
            $where[] = "(f.user_id IN (SELECT ID FROM " . tn('TBL_USERS') . " WHERE TEAM_ID = ?) OR l.team_id = ?)";
            $params[] = $teamId;
            $params[] = $teamId;
            $types .= 'ii';
        } else {
            // Default: 'mine' - strict personal follow-ups for the telecaller
            $where[] = "(f.user_id = ? OR l.assigned_to = ?)";
            $params[] = $userId;
            $params[] = $userId;
            $types .= 'ii';
        }

        if ($status !== 'all' && $status !== '') {
            $where[] = "f.status = ?";
            $params[] = strtoupper($status);
            $types .= 's';
        }

        if ($due === 'today') {
            $where[] = "f.followup_at >= CURDATE() AND f.followup_at < CURDATE() + INTERVAL 1 DAY";
        } elseif ($due === 'overdue') {
            $where[] = "f.followup_at < NOW() AND f.status = 'OPEN'";
        } elseif ($due === 'upcoming') {
            $where[] = "f.followup_at >= NOW()";
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $cntStmt = $link->prepare("SELECT COUNT(*) as c FROM " . tn('TBL_LEAD_FOLLOWUPS') . " f LEFT JOIN " . tn('TBL_LEADS') . " l ON f.lead_id = l.lead_id $whereSql");
        if (!empty($params)) $cntStmt->bind_param($types, ...$params);
        $cntStmt->execute();
        $total = (int)$cntStmt->get_result()->fetch_assoc()['c'];
        $cntStmt->close();

        $sql = "SELECT 
                    f.id, f.lead_id, f.client_contact_id, f.contact_source, f.source_call_id, f.user_id, f.followup_at, f.note, f.status, f.completed_at, f.created_at,
                    u.NAME as officer_name,
                    l.assigned_to as lead_assigned_to,
                    COALESCE(l.NAME, m.MAINDATABASE_NAME, t.CUST_NAME, '') as customer_name,
                    COALESCE(l.MOBILE, m.MAINDATABASE_MOBILE, t.CUST_MOBILE, '') as customer_mobile,
                    COALESCE(l.COMPANY_NAME, m.MAINDATABASE_COMPANY, t.CUST_COMPANY, '') as company_name,
                    l.loan_type, l.loan_amount, l.lead_status_new as pipeline_stage
                FROM " . tn('TBL_LEAD_FOLLOWUPS') . " f
                LEFT JOIN " . tn('TBL_USERS') . " u ON f.user_id = u.ID
                LEFT JOIN " . tn('TBL_LEADS') . " l ON f.lead_id = l.lead_id
                LEFT JOIN " . tn('TBL_MAIN') . " m ON (f.client_contact_id = m.ID AND f.contact_source = 'MAIN')
                LEFT JOIN " . tn('TBL_TEMP') . " t ON (f.client_contact_id = t.ID AND f.contact_source = 'TEMP')
                $whereSql
                ORDER BY f.followup_at ASC
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $link->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $totalPages = $total > 0 ? (int)ceil($total / $limit) : 1;
        apiSuccess([
            'data' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $totalPages,
            'has_more' => $page < $totalPages
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = apiReadJson();
        $leadId = !empty($input['lead_id']) ? (int)$input['lead_id'] : null;
        $contactId = !empty($input['client_contact_id']) ? (int)$input['client_contact_id'] : (!empty($input['record_id']) ? (int)$input['record_id'] : null);
        $contactSource = !empty($input['contact_source']) ? strtoupper(trim($input['contact_source'])) : 'MAIN';
        if (!in_array($contactSource, ['MAIN', 'TEMP'], true)) $contactSource = 'MAIN';
        $followupAt = !empty($input['followup_at']) ? date('Y-m-d H:i:s', strtotime($input['followup_at'])) : null;
        $note = trim($input['note'] ?? ($input['remarks'] ?? ''));

        if (!$followupAt) apiError(400, 'followup_at date/time is required');
        if (!$leadId && !$contactId) {
            $mobile = trim($input['customer_mobile'] ?? ($input['phone_number'] ?? ($input['mobile'] ?? ($input['phone'] ?? ''))));
            $mobile = preg_replace('/[^0-9]/', '', $mobile);
            if (strlen($mobile) > 10 && substr($mobile, 0, 2) === '91') $mobile = substr($mobile, 2);
            if (!empty($mobile) && strlen($mobile) >= 10) {
                $custName = trim($input['customer_name'] ?? ($input['name'] ?? 'Follow-up Contact'));
                $mStmt = $link->prepare("SELECT ID FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = ? LIMIT 1");
                $mStmt->bind_param('s', $mobile);
                $mStmt->execute();
                $mRow = $mStmt->get_result()->fetch_assoc();
                $mStmt->close();
                if ($mRow) {
                    $contactId = (int)$mRow['ID'];
                    $contactSource = 'MAIN';
                } else {
                    $tStmt = $link->prepare("SELECT ID FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = ? LIMIT 1");
                    $tStmt->bind_param('s', $mobile);
                    $tStmt->execute();
                    $tRow = $tStmt->get_result()->fetch_assoc();
                    $tStmt->close();
                    if ($tRow) {
                        $contactId = (int)$tRow['ID'];
                        $contactSource = 'TEMP';
                    } else {
                        $ins = $link->prepare("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_CALL_DIALED_USER, MAINDATABASE_UPLOAD_DATETIME) VALUES (?, ?, ?, NOW())");
                        $ins->bind_param('ssi', $custName, $mobile, $userId);
                        $ins->execute();
                        $contactId = (int)$ins->insert_id;
                        $contactSource = 'MAIN';
                        $ins->close();
                    }
                }
            }
        }
        if (!$leadId && !$contactId) apiError(400, 'Either lead_id, client_contact_id, or valid phone_number is required');

        $stmt = $link->prepare("INSERT INTO " . tn('TBL_LEAD_FOLLOWUPS') . " (lead_id, client_contact_id, contact_source, user_id, followup_at, note, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'OPEN', NOW())");
        $stmt->bind_param('iissis', $leadId, $contactId, $contactSource, $userId, $followupAt, $note);
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();

        apiSuccess(['message' => 'Follow-up created successfully', 'followup_id' => $newId]);
    } else {
        apiError(405, 'Method not allowed');
    }

} elseif (preg_match('#^/follow-ups/(\d+)/(complete|reschedule)$#', $relativePath, $fMatches)) {
    $fId = (int)$fMatches[1];
    $action = $fMatches[2];
    $input = apiReadJson();

    if ($action === 'complete') {
        $stmt = $link->prepare("UPDATE " . tn('TBL_LEAD_FOLLOWUPS') . " SET status = 'DONE', completed_at = NOW(), completed_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $userId, $fId);
        $stmt->execute();
        $stmt->close();
        apiSuccess(['message' => 'Follow-up marked as completed', 'followup_id' => $fId]);
    } elseif ($action === 'reschedule') {
        $newTime = !empty($input['followup_at']) ? date('Y-m-d H:i:s', strtotime($input['followup_at'])) : null;
        if (!$newTime) apiError(400, 'New followup_at date/time is required');
        $appendNote = trim($input['note'] ?? '');

        $stmt = $link->prepare("UPDATE " . tn('TBL_LEAD_FOLLOWUPS') . " SET followup_at = ?, status = 'OPEN', note = CASE WHEN ? != '' THEN CONCAT(COALESCE(note,''), '\nRescheduled: ', ?) ELSE note END WHERE id = ?");
        $stmt->bind_param('sssi', $newTime, $appendNote, $appendNote, $fId);
        $stmt->execute();
        $stmt->close();
        apiSuccess(['message' => 'Follow-up rescheduled successfully', 'followup_id' => $fId, 'new_time' => $newTime]);
    }

} else {
    apiError(404, 'Telecaller endpoint not found');
}
} catch (Throwable $e) {
    error_log('Telecaller API error: ' . $e->getMessage());
    apiError(500, 'Unable to process request');
}
