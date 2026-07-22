<?php
/**
 * API Authentication Middleware
 * Validates Bearer tokens for API requests
 */
require_once __DIR__ . '/../config.php';

function apiRequireAuth(): array {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? getenv('HTTP_AUTHORIZATION') ?? '';
    error_log("Auth header (SERVER): " . ($_SERVER['HTTP_AUTHORIZATION'] ?? 'NOT SET'));
    error_log("Auth header (getenv): " . (getenv('HTTP_AUTHORIZATION') ?? 'NOT SET'));
    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        apiError(401, 'Missing or invalid Authorization header. Use: Bearer <token>');
    }
    
    $token = substr($authHeader, 7);
    
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

function logActivity(mysqli $link, int $userId, string $actionType, string $details, string $affectedIds = '', string $targetTable = ''): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $link->prepare("INSERT INTO " . tn('TBL_ACTIVITY_LOG') . "(USER_ID, ACTION_TYPE, ACTION_DETAILS, AFFECTED_IDS, TARGET_TABLE, LOG_TIME, IP_ADDRESS)
        VALUES (?, ?, ?, ?, ?, NOW(), ?)");
    $stmt->bind_param('isssss', $userId, $actionType, $details, $affectedIds, $targetTable, $ip);
    $stmt->execute();
    $stmt->close();
}

// Check rate limit
function checkRateLimit(array $tokenData): void {
    $settings = getApiSettings();
    $limitPerMin = (int)($settings['rate_limit_per_minute'] ?? 60);
    $limitPerHour = (int)($settings['rate_limit_per_hour'] ?? 1000);
    
    if ($limitPerMin <= 0 && $limitPerHour <= 0) return;
    
    global $link;
    $tokenId = (int)$tokenData['id'];
    $now = time();
    $minuteAgo = $now - 60;
    $hourAgo = $now - 3600;
    
    $stmt = $link->prepare("
        SELECT 
            SUM(created_at >= ?) as count_min,
            SUM(created_at >= ?) as count_hour
        FROM " . tn('TBL_API_ACCESS_LOGS') . " 
        WHERE token_id = ?
    ");
    $stmt->bind_param('iii', $minuteAgo, $hourAgo, $tokenId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($limitPerMin > 0 && $row['count_min'] >= $limitPerMin) {
        apiError(429, "Rate limit exceeded: $limitPerMin requests per minute");
    }
    if ($limitPerHour > 0 && $row['count_hour'] >= $limitPerHour) {
        apiError(429, "Rate limit exceeded: $limitPerHour requests per hour");
    }
}

function logApiAccess(array $tokenData, string $endpoint, string $method, int $responseCode, int $execTimeMs): void {
    $settings = getApiSettings();
    if (!($settings['log_requests'] ?? true)) return;
    
    global $link;
    $stmt = $link->prepare("
        INSERT INTO " . tn('TBL_API_ACCESS_LOGS') . " (token_id, user_id, endpoint, method, ip_address, user_agent, response_code, execution_time_ms)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('iisssiii', 
        $tokenData['id'],
        $tokenData['user_id'],
        $endpoint,
        $method,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $responseCode,
        $execTimeMs
    );
    $stmt->execute();
    $stmt->close();
}

// Auto-execute middleware
$GLOBALS['api_token_data'] = apiRequireAuth();
checkRateLimit($GLOBALS['api_token_data']);
$startTime = microtime(true);

register_shutdown_function(function() use ($startTime) {
    $execTime = round((microtime(true) - $startTime) * 1000);
    $responseCode = http_response_code();
    logApiAccess($GLOBALS['api_token_data'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $responseCode, $execTime);
});