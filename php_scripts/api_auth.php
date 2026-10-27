<?php
/**
 * API Authentication Middleware
 * Validates Bearer tokens for API requests
 */
require_once __DIR__ . '/../config.php';

function apiRequireAuth(): array {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] 
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
        ?? getenv('HTTP_AUTHORIZATION') 
        ?? '';

    if (!$authHeader && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        apiError(401, 'Missing or invalid Authorization header. Use: Bearer <token>');
    }
    
    $token = trim(substr($authHeader, 7));
    
    global $link;
    $stmt = $link->prepare("
        SELECT t.*, u.NAME as user_name, u.ROLE as user_role, u.TEAM_ID as user_team_id, u.STATUS as user_status
        FROM " . tn('TBL_API_TOKENS') . " t
        JOIN " . tn('TBL_USERS') . " u ON t.user_id = u.ID
        WHERE t.token = ? AND t.is_active = 1
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $tokenData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$tokenData) {
        apiError(401, 'Invalid or inactive API token');
    }
    
    // Check expiry
    if ($tokenData['expires_at'] && strtotime($tokenData['expires_at']) < time()) {
        apiError(401, 'API token has expired');
    }
    
    // Check user status
    if ($tokenData['user_status'] !== 'Active') {
        apiError(403, 'User account is inactive');
    }
    
    // Update last used
    $link->query("UPDATE " . tn('TBL_API_TOKENS') . " SET last_used_at = NOW() WHERE id = " . (int)$tokenData['id']);
    
    return $tokenData;
}

function apiRequirePermission(string $permission): void {
    $tokenData = $GLOBALS['api_token_data'] ?? null;
    if (!$tokenData) apiError(401, 'Authentication required');

    if ($tokenData['user_role'] === 'Admin' || (int)$tokenData['user_id'] === 1) {
        return;
    }

    global $link;
    $stmt = $link->prepare("
        SELECT 1 FROM " . tn('TBL_ROLE_PERMISSIONS') . " rp
        JOIN " . tn('TBL_USERS') . " u ON rp.role = u.ROLE
        WHERE u.ID = ? AND rp.permission_key = ? AND rp.permission_value = 1
    ");
    $stmt->bind_param('is', $tokenData['user_id'], $permission);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        // Check user override
        $stmt2 = $link->prepare("
            SELECT 1 FROM " . tn('TBL_USER_PERMISSIONS') . " WHERE user_id = ? AND permission_key = ? AND permission_value = 1
        ");
        $stmt2->bind_param('is', $tokenData['user_id'], $permission);
        $stmt2->execute();
        if (!$stmt2->get_result()->fetch_assoc()) {
            apiError(403, "Permission denied: $permission required");
        }
    }
}

function getApiSettings(): array {
    global $link;
    static $cache = null;
    if ($cache !== null) return $cache;
    
    $res = $link->query("SELECT setting_key, setting_value FROM " . tn('TBL_API_SETTINGS'));
    $settings = [];
    while ($row = $res->fetch_assoc()) {
        $val = $row['setting_value'];
        $decoded = json_decode($val, true);
        if (json_last_error() === JSON_ERROR_NONE) $val = $decoded;
        $settings[$row['setting_key']] = $val;
    }
    $cache = $settings;
    return $settings;
}

function getUserDatabaseAccess(int $userId): array {
    global $link;
    $settings = getApiSettings();
    $allowedRaw = $settings['allowed_databases'] ?? '["temporary","leads"]';
    if (is_string($allowedRaw)) {
        $allowed = json_decode($allowedRaw, true) ?: [];
    } else {
        $allowed = (array) $allowedRaw;
    }
    
    $access = [
        'temporary' => in_array('temporary', $allowed),
        'main' => in_array('main', $allowed),
        'leads' => in_array('leads', $allowed),
    ];
    
    // Check assignments
    $stmt = $link->prepare("
        SELECT database_type FROM " . tn('TBL_API_DB_ASSIGNMENTS') . " 
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if (isset($access[$row['database_type']])) {
            $access[$row['database_type']] = true;
        }
    }
    
    return $access;
}

/**
 * Auto-grant DB access based on the global `allowed_databases` API setting.
 * Called when an API token is created so the telecaller can use the API
 * immediately without a manual assignment. Admins can still refine access
 * later via the API Access → Database Assignments UI.
 */
function grantDefaultApiAccess(int $userId): void {
    global $link;
    $settings = getApiSettings();
    $allowedRaw = $settings['allowed_databases'] ?? '["temporary","leads"]';
    if (is_string($allowedRaw)) {
        $allowed = json_decode($allowedRaw, true) ?: [];
    } else {
        $allowed = (array) $allowedRaw;
    }
    if (!is_array($allowed) || empty($allowed)) {
        $allowed = ['temporary', 'leads'];
    }

    foreach ($allowed as $dbType) {
        if (!in_array($dbType, ['temporary', 'main', 'leads'], true)) continue;
        $stmt = $link->prepare("
            INSERT INTO " . tn('TBL_API_DB_ASSIGNMENTS') . " (user_id, database_type, team_id, assigned_by, is_active)
            VALUES (?, ?, NULL, NULL, 1)
            ON DUPLICATE KEY UPDATE is_active = 1
        ");
        $stmt->bind_param('is', $userId, $dbType);
        $stmt->execute();
        $stmt->close();
    }
}

function getUserAssignedTeamIds(int $userId): array {
    global $link;
    $stmt = $link->prepare("
        SELECT DISTINCT team_id FROM " . tn('TBL_API_DB_ASSIGNMENTS') . " 
        WHERE user_id = ? AND is_active = 1 AND team_id IS NOT NULL
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $TBL_TEAMS = [];
    while ($row = $res->fetch_assoc()) {
        $TBL_TEAMS[] = (int)$row['team_id'];
    }
    return $TBL_TEAMS;
}

function apiSuccess(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function apiError(int $code, string $message, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message] + $extra], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('logActivity')) {
    function logActivity(mysqli $link, int $userId, string $actionType, string $details, string $affectedIds = '', string $targetTable = ''): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $link->prepare("INSERT INTO " . tn('TBL_ACTIVITY_LOG') . "(USER_ID, ACTION_TYPE, ACTION_DETAILS, AFFECTED_IDS, TARGET_TABLE, LOG_TIME, IP_ADDRESS)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)");
        $stmt->bind_param('isssss', $userId, $actionType, $details, $affectedIds, $targetTable, $ip);
        $stmt->execute();
        $stmt->close();
    }
} else {
    // If auth.php's logActivity is already loaded (different signature), provide compatibility wrapper
    // but keep the name available; PHP won't allow redeclare, so we rely on function_exists
}

// Check rate limit
function checkRateLimit(array $tokenData): void {
    $settings = getApiSettings();
    $limitPerMin = (int)($settings['rate_limit_per_minute'] ?? 60);
    $limitPerHour = (int)($settings['rate_limit_per_hour'] ?? 1000);
    
    if ($limitPerMin <= 0 && $limitPerHour <= 0) return;
    
    global $link;
    $tokenId = (int)$tokenData['id'];
    
    $stmt = $link->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN created_at >= NOW() - INTERVAL 1 MINUTE THEN 1 ELSE 0 END), 0) as count_min,
            COUNT(*) as count_hour
        FROM " . tn('TBL_API_ACCESS_LOGS') . " 
        WHERE token_id = ? AND created_at >= NOW() - INTERVAL 1 HOUR
    ");
    $stmt->bind_param('i', $tokenId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $countMin = (int)($row['count_min'] ?? 0);
    $countHour = (int)($row['count_hour'] ?? 0);
    
    if ($limitPerMin > 0 && $countMin >= $limitPerMin) {
        apiError(429, "Rate limit exceeded: $limitPerMin requests per minute");
    }
    if ($limitPerHour > 0 && $countHour >= $limitPerHour) {
        apiError(429, "Rate limit exceeded: $limitPerHour requests per hour");
    }
}

function logApiAccess(array $tokenData, string $endpoint, string $method, int $responseCode, int $execTimeMs): void {
    try {
        $settings = getApiSettings();
        if (!($settings['log_requests'] ?? true)) return;
        
        global $link;
        $tokenId = !empty($tokenData['id']) ? (int)$tokenData['id'] : 0;
        $uId = !empty($tokenData['user_id']) ? (int)$tokenData['user_id'] : 0;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
        
        $stmt = @$link->prepare("
            INSERT INTO " . tn('TBL_API_ACCESS_LOGS') . " (token_id, user_id, endpoint, method, ip_address, user_agent, response_code, execution_time_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param('iisssiii', $tokenId, $uId, $endpoint, $method, $ip, $ua, $responseCode, $execTimeMs);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $t) {}
}

if (!function_exists('syncCallToLeadsAndPipeline')) {
    function syncCallToLeadsAndPipeline(mysqli $link, string $source, int $recordId, int $userId, string $status, string $notes, ?string $nextFollowup): ?int {
        $statusLower = strtolower(trim($status));
        $isLead = in_array($statusLower, ['lead', 'interested']);
        $isCallback = in_array($statusLower, ['call back', 'callback', 'follow-up', 'follow up']);

        if (!$isLead && !$isCallback) {
            return null;
        }

        $leadContact = null;
        $mainCustId = $recordId;

        if ($source === 'MAIN') {
            $q = $link->prepare("SELECT ID, MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_OTHER_INFO FROM " . tn('TBL_MAIN') . " WHERE ID = ?");
            if ($q) {
                $q->bind_param('i', $recordId);
                $q->execute();
                $leadContact = $q->get_result()->fetch_assoc();
                $q->close();
            }
        } else {
            $q = $link->prepare("SELECT ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_OTHER_INFO FROM " . tn('TBL_TEMP') . " WHERE ID = ?");
            if ($q) {
                $q->bind_param('i', $recordId);
                $q->execute();
                $tRow = $q->get_result()->fetch_assoc();
                $q->close();
                if ($tRow) {
                    $mob = trim($tRow['CUST_MOBILE'] ?? '');
                    if ($mob !== '') {
                        $chkM = $link->prepare("SELECT ID FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = ? LIMIT 1");
                        $chkM->bind_param('s', $mob);
                        $chkM->execute();
                        $mFound = $chkM->get_result()->fetch_assoc();
                        $chkM->close();
                        if ($mFound) {
                            $mainCustId = (int)$mFound['ID'];
                        } else {
                            $insM = $link->prepare("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_CALL_DIALED_USER, LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME) VALUES (?, ?, ?, ?, 'Lead', ?, NOW(), NOW())");
                            $insM->bind_param('ssssi', $tRow['CUST_NAME'], $tRow['CUST_MOBILE'], $tRow['CUST_COMPANY'], $tRow['CUST_OTHER_INFO'], $userId);
                            $insM->execute();
                            $mainCustId = (int)$insM->insert_id;
                            $insM->close();
                        }
                    }
                    $leadContact = [
                        'ID' => $mainCustId,
                        'MAINDATABASE_NAME' => $tRow['CUST_NAME'] ?? '',
                        'MAINDATABASE_MOBILE' => $tRow['CUST_MOBILE'] ?? '',
                        'MAINDATABASE_COMPANY' => $tRow['CUST_COMPANY'] ?? '',
                        'MAINDATABASE_OTHER_INFO' => $tRow['CUST_OTHER_INFO'] ?? '',
                    ];
                }
            }
        }

        if (!$leadContact) return null;

        $cName = $leadContact['MAINDATABASE_NAME'] ?? '';
        $cMobile = $leadContact['MAINDATABASE_MOBILE'] ?? '';
        $cCompany = $leadContact['MAINDATABASE_COMPANY'] ?? '';
        $cOther = $notes ?: ($leadContact['MAINDATABASE_OTHER_INFO'] ?? '');

        $chkL = $link->prepare("SELECT lead_id FROM " . tn('TBL_LEADS') . " WHERE cust_id = ? OR (MOBILE IS NOT NULL AND MOBILE != '' AND MOBILE = ?) LIMIT 1");
        $chkL->bind_param('is', $mainCustId, $cMobile);
        $chkL->execute();
        $existingL = $chkL->get_result()->fetch_assoc();
        $chkL->close();

        $leadId = null;

        if ($isLead) {
            if (!$existingL) {
                $insLead = $link->prepare("INSERT INTO " . tn('TBL_LEADS') . " 
                    (cust_id, NAME, MOBILE, COMPANY_NAME, OTHER_INFO, assigned_to, assigned_by, created_by, updated_by, lead_status, lead_status_new, lead_stage, priority, remarks, next_followup_at, last_contacted_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'New', 'LEAD', 'Warm', 'Medium', ?, ?, NOW(), NOW(), NOW())");
                $insLead->bind_param('issssiiiiss', $mainCustId, $cName, $cMobile, $cCompany, $cOther, $userId, $userId, $userId, $userId, $notes, $nextFollowup);
                $insLead->execute();
                $leadId = (int)$insLead->insert_id;
                $insLead->close();

                if ($nextFollowup && $leadId > 0) {
                    $insFollow = $link->prepare("INSERT INTO " . tn('TBL_LEAD_FOLLOWUPS') . " (lead_id, user_id, followup_at, note, status, created_at) VALUES (?, ?, ?, ?, 'OPEN', NOW())");
                    $insFollow->bind_param('iiss', $leadId, $userId, $nextFollowup, $notes);
                    $insFollow->execute();
                    $insFollow->close();
                }
            } else {
                $leadId = (int)$existingL['lead_id'];
                $updLead = $link->prepare("UPDATE " . tn('TBL_LEADS') . " SET lead_status_new = 'LEAD', remarks = CASE WHEN ? != '' THEN ? ELSE remarks END, next_followup_at = COALESCE(?, next_followup_at), last_contacted_at = NOW(), updated_at = NOW() WHERE lead_id = ?");
                $updLead->bind_param('ssssi', $notes, $notes, $nextFollowup, $leadId);
                $updLead->execute();
                $updLead->close();
            }
        } elseif ($isCallback && $existingL) {
            $leadId = (int)$existingL['lead_id'];
            $updLead = $link->prepare("UPDATE " . tn('TBL_LEADS') . " SET lead_status_new = 'FOLLOWUP', next_followup_at = COALESCE(?, next_followup_at), last_contacted_at = NOW(), updated_at = NOW() WHERE lead_id = ?");
            $updLead->bind_param('si', $nextFollowup, $leadId);
            $updLead->execute();
            $updLead->close();

            if ($nextFollowup) {
                $insFollow = $link->prepare("INSERT INTO " . tn('TBL_LEAD_FOLLOWUPS') . " (lead_id, user_id, followup_at, note, status, created_at) VALUES (?, ?, ?, ?, 'OPEN', NOW())");
                $insFollow->bind_param('iiss', $leadId, $userId, $nextFollowup, $notes);
                $insFollow->execute();
                $insFollow->close();
            }
        }

        return $leadId;
    }
}

// Auto-execute middleware for protected API endpoints.
// Auth routers (login/logout/refresh/me) define API_SKIP_AUTO_AUTH
// before including this file so public endpoints stay reachable.
if (!defined('API_SKIP_AUTO_AUTH')) {
    $GLOBALS['api_token_data'] = apiRequireAuth();
    checkRateLimit($GLOBALS['api_token_data']);
    $startTime = microtime(true);

    register_shutdown_function(function() use ($startTime) {
        try {
            $execTime = round((microtime(true) - $startTime) * 1000);
            $responseCode = http_response_code();
            logApiAccess($GLOBALS['api_token_data'] ?? [], $_SERVER['REQUEST_URI'] ?? '', $_SERVER['REQUEST_METHOD'] ?? '', $responseCode, $execTime);
        } catch (Throwable $t) {}
    });
}