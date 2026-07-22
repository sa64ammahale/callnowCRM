<?php
/**
 * Admin API Settings Management
 * POST: Create/Update API settings, tokens, assignments
 * GET: List settings, tokens, assignments
 */
require_once __DIR__ . '/../../../php_scripts/api_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$tokenData = apiRequireAuth();
apiRequirePermission('manage_api');

global $link;

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/api/v1/admin', '', $path);
$segments = array_filter(explode('/', $path));

if ($segments[0] === 'settings') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $res = $link->query("SELECT setting_key, setting_value, description FROM " . tn('TBL_API_SETTINGS') . " ORDER BY setting_key");
        $settings = [];
        while ($row = $res->fetch_assoc()) {
            $val = $row['setting_value'];
            // Try to decode JSON
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE) $val = $decoded;
            $settings[$row['setting_key']] = [
                'value' => $val,
                'description' => $row['description'],
            ];
        }
        apiSuccess($settings);
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['settings']) || !is_array($input['settings'])) {
            apiError(400, 'Invalid input: expected "settings" array');
        }
        
        foreach ($input['settings'] as $key => $value) {
            $desc = $input['descriptions'][$key] ?? null;
            $val = is_array($value) || is_object($value) ? json_encode($value) : (string)$value;
            $stmt = $link->prepare("INSERT INTO " . tn('TBL_API_SETTINGS') . " (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = COALESCE(VALUES(description), description)");
            $stmt->bind_param('sss', $key, $val, $desc);
            $stmt->execute();
        }
        apiSuccess(['message' => 'Settings updated']);
    }
}

elseif ($segments[0] === 'tokens') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = (int)($_GET['page'] ?? 1);
        $limit = (int)($_GET['limit'] ?? 20);
        $offset = ($page - 1) * $limit;
        
        $where = "WHERE 1=1";
        $params = [];
        $types = '';
        
        if (!empty($_GET['user_id'])) {
            $where .= " AND t.user_id = ?";
            $params[] = (int)$_GET['user_id'];
            $types .= 'i';
        }
        if (!empty($_GET['is_active'])) {
            $where .= " AND t.is_active = ?";
            $params[] = (int)$_GET['is_active'];
            $types .= 'i';
        }
        
        $sql = "SELECT t.*, u.NAME as user_name, u.EMAIL as user_email, u.ROLE as user_role
                FROM " . tn('TBL_API_TOKENS') . " t
                JOIN " . tn('TBL_USERS') . " u ON t.user_id = u.ID
                $where
                ORDER BY t.created_at DESC
                LIMIT ? OFFSET ?";
        $stmt = $link->prepare($sql);
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $tokens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Total count
        $countSql = "SELECT COUNT(*) as total FROM " . tn('TBL_API_TOKENS') . " t $where";
        $stmt2 = $link->prepare($countSql);
        if ($params) $stmt2->bind_param($types, ...$params);
        $stmt2->execute();
        $total = $stmt2->get_result()->fetch_assoc()['total'];
        
        apiSuccess([
            'data' => $tokens,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'total_pages' => ceil($total / $limit),
            ],
        ]);
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = (int)($input['user_id'] ?? 0);
        $name = $input['name'] ?? 'Mobile App';
        $expiryDays = (int)($input['expiry_days'] ?? 90);
        
        if ($userId <= 0) {
            apiError(400, 'user_id is required');
        }
        
        // Check user exists and is active
        $stmt = $link->prepare("SELECT ID, NAME, ROLE, STATUS FROM " . tn('TBL_USERS') . " WHERE ID = ? AND STATUS = 'Active'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user) apiError(404, 'User not found or inactive');
        
        // Generate token
        $token = bin2hex(random_bytes(32));
        $expiry = $expiryDays > 0 ? date('Y-m-d H:i:s', strtotime("+$expiryDays days")) : null;
        
        $stmt = $link->prepare("INSERT INTO " . tn('TBL_API_TOKENS') . " (user_id, token, name, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isss', $userId, $token, $name, $expiry);
        $stmt->execute();
        $tokenId = $link->insert_id;

        // Auto-grant default DB access so the API is usable immediately
        grantDefaultApiAccess($userId);
        
        // Log
        logActivity($link, $tokenData['user_id'] ?? 0, 'API_TOKEN_CREATED', "Created API token for user #$userId ($user[NAME])", (string)$tokenId, 'TBL_API_TOKENS');
        
        apiSuccess([
            'token_id' => $tokenId,
            'token' => $token, // Only returned once!
            'name' => $name,
            'expires_at' => $expiry,
        ]);
    }
    
    if (count($segments) > 1 && is_numeric($segments[1])) {
        $tokenId = (int)$segments[1];
        
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $stmt = $link->prepare("UPDATE " . tn('TBL_API_TOKENS') . " SET is_active = 0 WHERE id = ?");
            $stmt->bind_param('i', $tokenId);
            $stmt->execute();
            
            logActivity($link, $tokenData['user_id'] ?? 0, 'API_TOKEN_REVOKED', "Revoked API token #$tokenId", (string)$tokenId, 'TBL_API_TOKENS');
            
            apiSuccess(['message' => 'Token revoked']);
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            $input = json_decode(file_get_contents('php://input'), true);
            $updates = [];
            $params = [];
            $types = '';
            
            if (isset($input['is_active'])) {
                $updates[] = 'is_active = ?';
                $params[] = (int)$input['is_active'];
                $types .= 'i';
            }
            if (isset($input['name'])) {
                $updates[] = 'name = ?';
                $params[] = $input['name'];
                $types .= 's';
            }
            if (isset($input['expiry_days'])) {
                $expiry = (int)$input['expiry_days'] > 0 ? date('Y-m-d H:i:s', strtotime("+" . (int)$input['expiry_days'] . " days")) : null;
                $updates[] = 'expires_at = ?';
                $params[] = $expiry;
                $types .= 's';
            }
            
            if ($updates) {
                $params[] = $tokenId;
                $types .= 'i';
                $stmt = $link->prepare("UPDATE " . tn('TBL_API_TOKENS') . " SET " . implode(', ', $updates) . " WHERE id = ?");
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
            }
            
            apiSuccess(['message' => 'Token updated']);
        }
    }
}

elseif ($segments[0] === 'assignments') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sql = "SELECT a.*, u.NAME as user_name, u.ROLE as user_role, t.NAME as team_name
                FROM " . tn('TBL_API_DB_ASSIGNMENTS') . " a
                JOIN " . tn('TBL_USERS') . " u ON a.user_id = u.ID
                LEFT JOIN " . tn('TBL_TEAMS') . " t ON a.team_id = t.ID
                ORDER BY a.assigned_at DESC";
        $res = $link->query($sql);
        $data = $res->fetch_all(MYSQLI_ASSOC);
        apiSuccess($data);
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = (int)($input['user_id'] ?? 0);
        $databaseType = $input['database_type'] ?? '';
        $teamId = isset($input['team_id']) ? (int)$input['team_id'] : null;
        
        if (!$userId || !$databaseType) apiError(400, 'user_id and database_type required');
        if (!in_array($databaseType, ['temporary', 'main', 'leads'])) apiError(400, 'Invalid database_type');
        
        // Check user exists
        $stmt = $link->prepare("SELECT ID FROM " . tn('TBL_USERS') . " WHERE ID = ? AND STATUS = 'Active'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) apiError(404, 'User not found or inactive');
        
        // Check team if provided
        if ($teamId) {
            $stmt = $link->prepare("SELECT ID FROM " . tn('TBL_TEAMS') . " WHERE ID = ?");
            $stmt->bind_param('i', $teamId);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) apiError(404, 'Team not found');
        }
        
        $stmt = $link->prepare("INSERT INTO " . tn('TBL_API_DB_ASSIGNMENTS') . " (user_id, database_type, team_id, assigned_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE is_active = 1");
        $stmt->bind_param('isii', $userId, $databaseType, $teamId, $tokenData['user_id'] ?? 0);
        $stmt->execute();
        
        logActivity($link, $tokenData['user_id'] ?? 0, 'API_ASSIGNMENT_CREATED', "Assigned $databaseType to user #$userId", '', 'TBL_API_DB_ASSIGNMENTS');
        
        apiSuccess(['message' => 'Assignment created']);
    }
    
    if (count($segments) > 1 && is_numeric($segments[1])) {
        $assignId = (int)$segments[1];
        
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $stmt = $link->prepare("DELETE FROM " . tn('TBL_API_DB_ASSIGNMENTS') . " WHERE id = ?");
            $stmt->bind_param('i', $assignId);
            $stmt->execute();
            apiSuccess(['message' => 'Assignment removed']);
        }
    }
}

else {
    apiError(404, 'Admin endpoint not found');
}