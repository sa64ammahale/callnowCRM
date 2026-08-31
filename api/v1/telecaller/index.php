<?php
/**
 * Telecaller API Endpoints
 * GET /api/v1/telecaller/dashboard - Get telecaller dashboard stats
 * GET /api/v1/telecaller/next-call - Get next call to make
 * GET /api/v1/telecaller/calls - List calls with filters
 * POST /api/v1/telecaller/calls/{id}/complete - Submit call result
 * GET /api/v1/telecaller/history - Call history
 */
require_once __DIR__ . '/../../../php_scripts/api_auth.php';

header('Content-Type: application/json; charset=utf-8');

ensureApiSchema($GLOBALS['link']);

$tokenData = $GLOBALS['api_token_data'];
$userId = (int)$tokenData['user_id'];
$userRole = $tokenData['user_role'];
$teamId = $tokenData['user_team_id'] ? (int)$tokenData['user_team_id'] : null;

global $link;

$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Normalize path
$basePath = '/api/v1/telecaller';
$relativePath = substr($path, strlen($basePath));

// /history is the same as /calls but with a larger default limit window
if ($relativePath === '/history' || $relativePath === '/history/') {
    $relativePath = '/calls';
    $_GET['limit'] = $_GET['limit'] ?? 50;
}

if ($relativePath === '/dashboard' || $relativePath === '/dashboard/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    $dbAccess = getUserDatabaseAccess($tokenData['user_id']);
    $teamIds = getUserAssignedTeamIds($tokenData['user_id']);
    
    $stats = [
        'today_calls' => 0,
        'today_connected' => 0,
        'today_pending' => 0,
        'total_assigned' => 0,
    ];
    
    // Temporary DB stats
    if ($dbAccess['temporary']) {
        $sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN CALL_DIALED_STATUS = 'Connected' THEN 1 ELSE 0 END) as connected,
            SUM(CASE WHEN CALL_DIALED_STATUS = 'Pending' OR CALL_DIALED_STATUS IS NULL THEN 1 ELSE 0 END) as pending
        FROM " . tn('TBL_TEMP') . " 
        WHERE CALL_DIALED_TELECALLER = ? 
        AND DATE(LAST_DIALED_DATE_TIME) = CURDATE()";
        $stmt = $link->prepare($sql);
        $stmt->bind_param('i', $tokenData['user_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stats['today_calls'] += (int)($row['total'] ?? 0);
        $stats['today_connected'] += (int)($row['connected'] ?? 0);
        $stats['today_pending'] += (int)($row['pending'] ?? 0);
    }
    
    // Leads stats
    if ($dbAccess['leads']) {
        $sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN lead_status_new IN ('LOGIN','INTERNAL_UNDERWRITING','BANK_UNDERWRITING','SANCTIONED','DISBURSED') THEN 1 ELSE 0 END) as converted
        FROM " . tn('TBL_LEADS') . " 
        WHERE assigned_to = ?";
        $stmt = $link->prepare($sql);
        $stmt->bind_param('i', $tokenData['user_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stats['today_calls'] += (int)($row['total'] ?? 0);
        $stats['today_connected'] += (int)($row['converted'] ?? 0);
    }
    
    apiSuccess($stats);
    
} elseif ($relativePath === '/next-call' || $relativePath === '/next-call/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    $dbAccess = getUserDatabaseAccess($tokenData['user_id']);
    
    // Try temporary database first
    if ($dbAccess['temporary']) {
        $sql = "SELECT 
            ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO,
            TEMP_UPLOAD_DATETIME, CALL_DIALED_STATUS, CALL_DIALED_TELECALLER,
            LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD
        FROM " . tn('TBL_TEMP') . " 
        WHERE CALL_DIALED_TELECALLER = ?
        AND (CALL_DIALED_STATUS IS NULL OR CALL_DIALED_STATUS = '' OR CALL_DIALED_STATUS = 'Pending' OR CALL_DIALED_STATUS = 'Not Called')
        ORDER BY TEMP_UPLOAD_DATETIME ASC
        LIMIT 1";
        $stmt = $link->prepare($sql);
        $stmt->bind_param('i', $tokenData['user_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        
        if ($row) {
            apiSuccess([
                'source' => 'temporary',
                'record_id' => (int)$row['ID'],
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
                'last_connected' => $row['LAST_CONNECTED_PERIOD'],
            ]);
        }
    }
    
    // Try leads table
    if ($dbAccess['leads']) {
        $sql = "SELECT 
            lead_id, NAME, MOBILE, COMPANY_NAME, lead_status_new, pipeline_status,
            next_followup_at, OTHER_INFO, assigned_by, created_at
        FROM " . tn('TBL_LEADS') . " 
        WHERE assigned_to = ?
        AND lead_status_new IN ('LEAD','FOLLOWUP','LOGIN')
        ORDER BY next_followup_at ASC, created_at ASC
        LIMIT 1";
        $stmt = $link->prepare($sql);
        $stmt->bind_param('i', $tokenData['user_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        
        if ($row) {
            apiSuccess([
                'source' => 'leads',
                'record_id' => (int)$row['lead_id'],
                'customer' => [
                    'name' => $row['NAME'] ?? '',
                    'mobile' => $row['MOBILE'] ?? '',
                    'company' => $row['COMPANY_NAME'] ?? '',
                    'pipeline_status' => $row['pipeline_status'] ?? '',
                    'other_info' => $row['OTHER_INFO'] ?? '',
                ],
                'lead_status' => $row['lead_status_new'] ?? 'LEAD',
                'next_followup' => $row['next_followup_at'],
                'assigned_by' => (int)($row['assigned_by'] ?? 0),
                'created_at' => $row['created_at'],
            ]);
        }
    }
    
    apiSuccess(['message' => 'No calls available at this time']);
    
} elseif (preg_match('#^/calls/(\d+)/complete$#', $relativePath, $matches)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'Method not allowed');
    
    $recordId = (int)$matches[1];
    $input = json_decode(file_get_contents('php://input'), true);
    
    $source = $input['source'] ?? '';
    if (!in_array($source, ['temporary', 'leads'])) apiError(400, 'Invalid source');
    
    $status = $input['status'] ?? '';
    $notes = $input['notes'] ?? '';
    $duration = (int)($input['duration_seconds'] ?? 0);
    $nextFollowup = $input['next_followup_at'] ?? null;
    
    if ($source === 'temporary') {
        $dbAccess = getUserDatabaseAccess($tokenData['user_id']);
        if (!$dbAccess['temporary']) apiError(403, 'No access to temporary database');
        
        // Verify ownership
        $stmt = $link->prepare("SELECT ID FROM " . tn('TBL_TEMP') . " WHERE ID = ? AND CALL_DIALED_TELECALLER = ?");
        $stmt->bind_param('ii', $recordId, $tokenData['user_id']);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) apiError(404, 'Record not found or not assigned');
        
        $updateFields = [];
        $params = [];
        $types = '';
        
        if ($status) {
            $updateFields[] = 'CALL_DIALED_STATUS = ?';
            $params[] = $status;
            $types .= 's';
        }
        if ($notes) {
            $updateFields[] = 'CUST_OTHER_INFO = ?';
            $params[] = $notes;
            $types .= 's';
        }
        $updateFields[] = 'LAST_DIALED_DATE_TIME = NOW()';
        if ($status === 'Connected') {
            $updateFields[] = 'LAST_CONNECTED_PERIOD = NOW()';
        }
        
        $params[] = $recordId;
        $types .= 'i';
        
        $sql = "UPDATE " . tn('TBL_TEMP') . " SET " . implode(', ', $updateFields) . " WHERE ID = ?";
        $stmt = $link->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        
    } elseif ($source === 'leads') {
        $dbAccess = getUserDatabaseAccess($tokenData['user_id']);
        if (!$dbAccess['leads']) apiError(403, 'No access to leads');
        
        $stmt = $link->prepare("SELECT lead_id FROM " . tn('TBL_LEADS') . " WHERE lead_id = ? AND assigned_to = ?");
        $stmt->bind_param('ii', $recordId, $tokenData['user_id']);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) apiError(404, 'Lead not found or not assigned');
        
        $updateFields = [];
        $params = [];
        $types = '';
        
        if ($status) {
            $updateFields[] = 'lead_status_new = ?';
            $params[] = $status;
            $types .= 's';
        }
        if ($notes) {
            $updateFields[] = 'REMARKS = ?';
            $params[] = $notes;
            $types .= 's';
        }
        if ($nextFollowup) {
            $updateFields[] = 'next_followup_at = ?';
            $params[] = $nextFollowup;
            $types .= 's';
        }
        $updateFields[] = 'updated_at = NOW()';
        $updateFields[] = 'updated_by = ?';
        $params[] = $tokenData['user_id'];
        $types .= 'i';
        
        $sql = "UPDATE " . tn('TBL_LEADS') . " SET " . implode(', ', $updateFields) . " WHERE lead_id = ?";
        $params[] = $recordId;
        $types .= 'i';
        
        $stmt = $link->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
    }
    
    logActivity($link, $tokenData['user_id'], 'API_CALL_COMPLETED', "Completed call #$recordId from $source with status $status", (string)$recordId, $source === 'temporary' ? 'TBL_TEMP' : 'TBL_LEADS');
    
    apiSuccess(['message' => 'Call result saved successfully']);
    
} elseif ($relativePath === '/calls' || $relativePath === '/calls/') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'Method not allowed');
    
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $source = $_GET['source'] ?? 'all';
    $status = $_GET['status'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $search = $_GET['search'] ?? '';
    
    $dbAccess = getUserDatabaseAccess($tokenData['user_id']);
    $allCalls = [];
    $totalCount = 0;
    
    // Temporary DB
    if (in_array($source, ['all', 'temporary']) && $dbAccess['temporary']) {
        $where = ["CALL_DIALED_TELECALLER = ?"];
        $params = [$tokenData['user_id']];
        $types = 'i';
        
        if ($status) { $where[] = "CALL_DIALED_STATUS = ?"; $params[] = $status; $types .= 's'; }
        if ($dateFrom) { $where[] = "DATE(LAST_DIALED_DATE_TIME) >= ?"; $params[] = $dateFrom; $types .= 's'; }
        if ($dateTo) { $where[] = "DATE(LAST_DIALED_DATE_TIME) <= ?"; $params[] = $dateTo; $types .= 's'; }
        if ($search) { $where[] = "(CUST_NAME LIKE ? OR CUST_MOBILE LIKE ?)"; $like = "%$search%"; $params[] = $like; $params[] = $like; $types .= 'ss'; }
        
        $whereSql = implode(' AND ', $where);
        
        $sql = "SELECT COUNT(*) as c FROM " . tn('TBL_TEMP') . " WHERE $whereSql";
        $stmt = $link->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $cnt = $stmt->get_result()->fetch_assoc()['c'];
        $totalCount += (int)$cnt;
        
        if ($cnt > 0) {
            $sql = "SELECT 
                ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE,
                CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, LAST_CONNECTED_PERIOD,
                TEMP_UPLOAD_DATETIME, CALL_DIALED_TELECALLER
            FROM " . tn('TBL_TEMP') . " 
            WHERE $whereSql
            ORDER BY LAST_DIALED_DATE_TIME DESC
            LIMIT ?, ?";
            $params[] = $offset;
            $params[] = $limit;
            $types .= 'ii';
            
            $stmt = $link->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $r) {
                $r['source'] = 'temporary';
                $r['record_id'] = (int)$r['ID'];
                $allCalls[] = $r;
            }
        }
    }
    
    // Leads
    if (in_array($source, ['all', 'leads']) && $dbAccess['leads']) {
        $where = ["assigned_to = ?"];
        $params = [$tokenData['user_id']];
        $types = 'i';
        
        if ($status) { $where[] = "lead_status_new = ?"; $params[] = $status; $types .= 's'; }
        if ($dateFrom) { $where[] = "DATE(created_at) >= ?"; $params[] = $dateFrom; $types .= 's'; }
        if ($dateTo) { $where[] = "DATE(created_at) <= ?"; $params[] = $dateTo; $types .= 's'; }
        if ($search) { $where[] = "(NAME LIKE ? OR MOBILE LIKE ?)"; $like = "%$search%"; $params[] = $like; $params[] = $like; $types .= 'ss'; }
        
        $whereSql = implode(' AND ', $where);
        
        $sql = "SELECT COUNT(*) as c FROM " . tn('TBL_LEADS') . " WHERE $whereSql";
        $stmt = $link->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $cnt = $stmt->get_result()->fetch_assoc()['c'];
        $totalCount += (int)$cnt;
        
        if ($cnt > 0) {
            $sql = "SELECT 
                lead_id, NAME, MOBILE, COMPANY_NAME, lead_status_new, pipeline_status,
                next_followup_at, REMARKS, created_at
            FROM " . tn('TBL_LEADS') . " 
            WHERE $whereSql
            ORDER BY created_at DESC
            LIMIT ?, ?";
            $params[] = $offset;
            $params[] = $limit;
            $types .= 'ii';
            
            $stmt = $link->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $r) {
                $r['source'] = 'leads';
                $r['record_id'] = (int)$r['lead_id'];
                $allCalls[] = $r;
            }
        }
    }
    
    // Sort combined results by date
    usort($allCalls, function($a, $b) {
        $dateA = $a['source'] === 'temporary' ? ($a['LAST_DIALED_DATE_TIME'] ?? $a['TEMP_UPLOAD_DATETIME']) : ($a['next_followup_at'] ?? $a['created_at']);
        $dateB = $b['source'] === 'temporary' ? ($b['LAST_DIALED_DATE_TIME'] ?? $b['TEMP_UPLOAD_DATETIME']) : ($b['next_followup_at'] ?? $b['created_at']);
        return strtotime($dateB) - strtotime($dateA);
    });
    
    // Paginate combined results
    $allCalls = array_slice($allCalls, 0, $limit);
    
    apiSuccess([
        'data' => $allCalls,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalCount,
            'total_pages' => ceil($totalCount / $limit),
        ]
    ]);
    
} else {
    apiError(404, 'Telecaller endpoint not found');
}