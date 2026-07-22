<?php
require_once __DIR__ . '/../../../php_scripts/api_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Allow GET for /me and /refresh endpoints
$isGetEndpoint = in_array($_SERVER['REQUEST_URI'], ['/api/v1/auth/me', '/api/v1/auth/refresh']);
if (!$isGetEndpoint && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Method not allowed');
}

$input = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_URI'] === '/api/v1/auth/login') {
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    
    if (!$email || !$password) {
        apiError(400, 'Email and password required');
    }
    
    global $link;
    $stmt = $link->prepare("SELECT ID, NAME, EMAIL, PASSWORD, ROLE, TEAM_ID, STATUS FROM " . tn('TBL_USERS') . " WHERE EMAIL = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user || !password_verify($password, $user['PASSWORD'])) {
        apiError(401, 'Invalid credentials');
    }
    
    if ($user['STATUS'] !== 'Active') {
        apiError(403, 'Account is not active');
    }
    
    // Generate API token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days
    
    $stmt = $link->prepare("INSERT INTO " . tn('TBL_API_TOKENS') . " (user_id, token, name, expires_at, is_active) VALUES (?, ?, 'Mobile App', ?, 1)");
    $stmt->bind_param('iss', $user['ID'], $token, $expiresAt);
    $stmt->execute();
    $tokenId = $link->insert_id;
    $stmt->close();

    // Auto-grant default DB access so the API is usable immediately
    grantDefaultApiAccess($user['ID']);
    
    // Get user's database access
    $dbAccess = getUserDatabaseAccess($user['ID']);
    $teamIds = getUserAssignedTeamIds($user['ID']);
    
    apiSuccess([
        'token' => $token,
        'expires_at' => $expiresAt,
        'user' => [
            'id' => (int)$user['ID'],
            'name' => $user['NAME'],
            'email' => $user['EMAIL'],
            'role' => $user['ROLE'],
            'team_id' => $user['TEAM_ID'] ? (int)$user['TEAM_ID'] : null,
        ],
        'database_access' => $dbAccess,
        'assigned_TBL_TEAMS' => $teamIds,
    ]);
    
} elseif ($_SERVER['REQUEST_URI'] === '/api/v1/auth/logout') {
    $tokenData = apiRequireAuth();
    
    global $link;
    $stmt = $link->prepare("UPDATE " . tn('TBL_API_TOKENS') . " SET is_active = 0 WHERE id = ?");
    $stmt->bind_param('i', $tokenData['id']);
    $stmt->execute();
    $stmt->close();
    
    apiSuccess(['message' => 'Logged out successfully']);
    
} elseif ($_SERVER['REQUEST_URI'] === '/api/v1/auth/refresh') {
    $tokenData = apiRequireAuth();
    
    // Extend expiry by 30 days
    $newExpiry = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
    
    global $link;
    $stmt = $link->prepare("UPDATE " . tn('TBL_API_TOKENS') . " SET expires_at = ?, last_used_at = NOW() WHERE id = ?");
    $stmt->bind_param('si', $newExpiry, $tokenData['id']);
    $stmt->execute();
    $stmt->close();
    
    $dbAccess = getUserDatabaseAccess($tokenData['user_id']);
    $teamIds = getUserAssignedTeamIds($tokenData['user_id']);
    
    apiSuccess([
        'expires_at' => $newExpiry,
        'database_access' => $dbAccess,
        'assigned_TBL_TEAMS' => $teamIds,
    ]);
    
} elseif ($_SERVER['REQUEST_URI'] === '/api/v1/auth/me') {
    $tokenData = apiRequireAuth();
    
    $dbAccess = getUserDatabaseAccess($tokenData['user_id']);
    $teamIds = getUserAssignedTeamIds($tokenData['user_id']);
    
    apiSuccess([
        'user' => [
            'id' => (int)$tokenData['user_id'],
            'name' => $tokenData['user_name'],
            'role' => $tokenData['user_role'],
            'team_id' => $tokenData['TEAM_ID'] ? (int)$tokenData['TEAM_ID'] : null,
        ],
        'database_access' => $dbAccess,
        'assigned_TBL_TEAMS' => $teamIds,
    ]);
    
} else {
    apiError(404, 'Endpoint not found');
}