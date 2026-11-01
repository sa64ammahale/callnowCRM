<?php
define('API_SKIP_AUTO_AUTH', true);
require_once __DIR__ . '/../../../php_scripts/api_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ensureApiSchema($GLOBALS['link']);

// Normalize endpoint path to support any base path (e.g. /callnowCRM/api/v1/auth/login or /api/v1/auth/login)
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$pos = strpos($uriPath, '/api/v1/auth');
$endpoint = ($pos !== false) ? substr($uriPath, $pos + strlen('/api/v1/auth')) : $uriPath;
$endpoint = '/' . trim($endpoint, '/');
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Allow GET for /me, /profile, and /refresh endpoints
$isGetEndpoint = in_array($endpoint, ['/me', '/profile', '/refresh']);
if ($method === 'GET' && !$isGetEndpoint) {
    apiError(405, 'Method not allowed');
}
if (!in_array($method, ['GET', 'POST', 'PUT', 'OPTIONS'])) {
    apiError(405, 'Method not allowed');
}

$rawBody = file_get_contents('php://input');
if (!$rawBody && php_sapi_name() === 'cli') {
    $rawBody = file_get_contents('php://stdin');
}
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    $input = !empty($_POST) ? $_POST : [];
}

global $link;

// -------------------------------------------------------------
// 1. LOGIN
// -------------------------------------------------------------
if ($endpoint === '/login') {
    if ($method !== 'POST') {
        apiError(405, 'Method not allowed');
    }
    
    $email = trim($input['email'] ?? ($input['username'] ?? ''));
    $password = $input['password'] ?? '';
    
    if (!$email || !$password) {
        apiError(400, 'Email/username and password required');
    }
    
    $stmt = $link->prepare("SELECT ID, NAME, EMAIL, LOGIN_ID, PASSWORD, ROLE, TEAM_ID, STATUS FROM " . tn('TBL_USERS') . " WHERE EMAIL = ? OR LOGIN_ID = ? LIMIT 1");
    $stmt->bind_param('ss', $email, $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user || !password_verify($password, $user['PASSWORD'])) {
        apiError(401, 'Invalid credentials');
    }
    
    if ($user['STATUS'] !== 'Active') {
        apiError(403, 'Account is not active');
    }

    $userId = (int)$user['ID'];
    
    // Update last login timestamp
    $upd = $link->prepare("UPDATE " . tn('TBL_USERS') . " SET LAST_LOGIN = NOW() WHERE ID = ?");
    $upd->bind_param('i', $userId);
    $upd->execute();
    $upd->close();
    
    // Generate API token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days
    
    $stmt = $link->prepare("INSERT INTO " . tn('TBL_API_TOKENS') . " (user_id, token, name, expires_at, is_active) VALUES (?, ?, 'Mobile App', ?, 1)");
    $stmt->bind_param('iss', $userId, $token, $expiresAt);
    $stmt->execute();
    $stmt->close();

    // Auto-grant default DB access so the API is usable immediately
    grantDefaultApiAccess($userId);
    
    // Get user's database access and teams
    $dbAccess = getUserDatabaseAccess($userId);
    $teamIds = getUserAssignedTeamIds($userId);
    $fullProfile = getFullUserProfileData($link, $userId);

    logActivity($link, $userId, 'LOGIN', 'User logged in via mobile app', (string)$userId, 'TBL_USERS');
    
    apiSuccess([
        'token' => $token,
        'expires_at' => $expiresAt,
        'user' => $fullProfile,
        'profile' => $fullProfile,
        'database_access' => $dbAccess,
        'assigned_teams' => $teamIds,
    ]);
    
// -------------------------------------------------------------
// 2. LOGOUT
// -------------------------------------------------------------
} elseif ($endpoint === '/logout') {
    $tokenData = apiRequireAuth();
    
    $stmt = $link->prepare("UPDATE " . tn('TBL_API_TOKENS') . " SET is_active = 0 WHERE id = ?");
    $stmt->bind_param('i', $tokenData['id']);
    $stmt->execute();
    $stmt->close();
    
    logActivity($link, (int)$tokenData['user_id'], 'LOGOUT', 'User logged out via mobile app', (string)$tokenData['user_id'], 'TBL_USERS');

    apiSuccess(['message' => 'Logged out successfully']);
    
// -------------------------------------------------------------
// 3. REFRESH TOKEN
// -------------------------------------------------------------
} elseif ($endpoint === '/refresh') {
    $tokenData = apiRequireAuth();
    $userId = (int)$tokenData['user_id'];
    
    // Extend expiry by 30 days
    $newExpiry = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
    
    $stmt = $link->prepare("UPDATE " . tn('TBL_API_TOKENS') . " SET expires_at = ?, last_used_at = NOW() WHERE id = ?");
    $stmt->bind_param('si', $newExpiry, $tokenData['id']);
    $stmt->execute();
    $stmt->close();
    
    $dbAccess = getUserDatabaseAccess($userId);
    $teamIds = getUserAssignedTeamIds($userId);
    $fullProfile = getFullUserProfileData($link, $userId);
    
    apiSuccess([
        'expires_at' => $newExpiry,
        'user' => $fullProfile,
        'profile' => $fullProfile,
        'database_access' => $dbAccess,
        'assigned_teams' => $teamIds,
    ]);
    
// -------------------------------------------------------------
// 4. GET / UPDATE PROFILE (/me and /profile)
// -------------------------------------------------------------
} elseif ($endpoint === '/me' || $endpoint === '/profile') {
    $tokenData = apiRequireAuth();
    $userId = (int)$tokenData['user_id'];

    if ($method === 'GET') {
        $fullProfile = getFullUserProfileData($link, $userId);
        if (!$fullProfile) {
            apiError(404, 'User profile not found');
        }

        $dbAccess = getUserDatabaseAccess($userId);
        $teamIds = getUserAssignedTeamIds($userId);

        $response = array_merge($fullProfile, [
            'user' => $fullProfile,
            'profile' => $fullProfile,
            'database_access' => $dbAccess,
            'assigned_teams' => $teamIds,
        ]);
        apiSuccess($response);
    } else {
        // POST or PUT updates profile
        handleMobileProfileUpdate($link, $userId, $input);
    }

// -------------------------------------------------------------
// 5. EXPLICIT PROFILE UPDATE ENDPOINTS
// -------------------------------------------------------------
} elseif ($endpoint === '/profile/update' || $endpoint === '/update-profile') {
    $tokenData = apiRequireAuth();
    $userId = (int)$tokenData['user_id'];
    handleMobileProfileUpdate($link, $userId, $input);

// -------------------------------------------------------------
// 6. PASSWORD CHANGE ENDPOINTS (/change-password and /password)
// -------------------------------------------------------------
} elseif ($endpoint === '/change-password' || $endpoint === '/password') {
    $tokenData = apiRequireAuth();
    $userId = (int)$tokenData['user_id'];
    handleMobilePasswordChange($link, $userId, $input);

} else {
    apiError(404, 'Endpoint not found');
}

/**
 * Handle user profile update from mobile app with strict contact field protection
 */
function handleMobileProfileUpdate(mysqli $link, int $userId, array $input): void {
    $stmt = $link->prepare("SELECT ID, NAME, EMAIL, LOGIN_ID, MOBILE, COMPANY_NAME, COMPANY, PASSWORD FROM " . tn('TBL_USERS') . " WHERE ID = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $currentUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$currentUser) {
        apiError(404, 'User not found');
    }

    // 1. RESTRICTION: Email / login_id cannot be changed from mobile app
    $submittedEmail = null;
    if (isset($input['email'])) {
        $submittedEmail = trim((string)$input['email']);
    } elseif (isset($input['login_id'])) {
        $submittedEmail = trim((string)$input['login_id']);
    } elseif (isset($input['mail'])) {
        $submittedEmail = trim((string)$input['mail']);
    } elseif (isset($input['user_email'])) {
        $submittedEmail = trim((string)$input['user_email']);
    } elseif (isset($input['username']) && str_contains((string)$input['username'], '@')) {
        $submittedEmail = trim((string)$input['username']);
    }

    if ($submittedEmail !== null && $submittedEmail !== '') {
        $curEmail = strtolower(trim((string)$currentUser['EMAIL']));
        $curLogin = strtolower(trim((string)$currentUser['LOGIN_ID']));
        $normSub  = strtolower($submittedEmail);
        if ($normSub !== $curEmail && $normSub !== $curLogin) {
            apiError(400, 'Changing registered email address from the mobile app is restricted. Please contact your CRM administrator to update your email.');
        }
    }

    // 2. RESTRICTION: Mobile / phone number cannot be changed from mobile app
    $submittedMobile = null;
    if (isset($input['mobile'])) {
        $submittedMobile = trim((string)$input['mobile']);
    } elseif (isset($input['phone'])) {
        $submittedMobile = trim((string)$input['phone']);
    } elseif (isset($input['phone_number'])) {
        $submittedMobile = trim((string)$input['phone_number']);
    } elseif (isset($input['contact_number'])) {
        $submittedMobile = trim((string)$input['contact_number']);
    }

    if ($submittedMobile !== null && $submittedMobile !== '') {
        $cleanSubmitted = preg_replace('/\D/', '', $submittedMobile);
        $cleanCurrent   = preg_replace('/\D/', '', (string)$currentUser['MOBILE']);
        if ($cleanSubmitted !== $cleanCurrent) {
            apiError(400, 'Changing registered mobile number from the mobile app is restricted. Please contact your CRM administrator to update your mobile number.');
        }
    }

    $updates = [];
    $params = [];
    $types = '';
    $passwordChanged = false;

    // Name update
    if (isset($input['name']) || isset($input['full_name'])) {
        $name = trim($input['name'] ?? ($input['full_name'] ?? ''));
        if ($name === '') {
            apiError(400, 'Name cannot be empty');
        }
        if (!preg_match("/^[a-zA-Z\s\.\,\'-]+$/u", $name)) {
            apiError(400, 'Name must contain only letters and spaces');
        }
        $updates[] = "NAME = ?";
        $params[] = $name;
        $types .= 's';
    }

    // Company / Organization update
    if (isset($input['company_name']) || isset($input['company'])) {
        $company = trim($input['company_name'] ?? ($input['company'] ?? ''));
        $updates[] = "COMPANY_NAME = ?";
        $params[] = $company;
        $types .= 's';
        $updates[] = "COMPANY = ?";
        $params[] = $company;
        $types .= 's';
    }

    // Optional profile photo via base64
    if (!empty($input['profile_photo_base64'])) {
        $photoData = $input['profile_photo_base64'];
        if (preg_match('/^data:image\/(jpeg|png|webp|gif);base64,/', $photoData, $m)) {
            $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
            $photoData = substr($photoData, strpos($photoData, ',') + 1);
        } else {
            $ext = 'jpg';
        }
        $decoded = base64_decode($photoData);
        if ($decoded !== false && strlen($decoded) <= 2 * 1024 * 1024) {
            $uploadDir = __DIR__ . '/../../../uploads/profile_photos/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $fileName = 'user_' . $userId . '_' . time() . '.' . $ext;
            if (@file_put_contents($uploadDir . $fileName, $decoded)) {
                $updates[] = "profile_photo = ?";
                $params[] = $fileName;
                $types .= 's';
            }
        }
    }

    // Optional password update submitted within profile form
    $newPassword = $input['new_password'] ?? ($input['password'] ?? '');
    if ($newPassword !== '') {
        $currentPassword = $input['current_password'] ?? ($input['old_password'] ?? '');
        if (!$currentPassword) {
            apiError(400, 'Current password is required to change password');
        }
        if (!password_verify($currentPassword, $currentUser['PASSWORD'])) {
            apiError(400, 'Current password is incorrect');
        }
        if (strlen($newPassword) < 6) {
            apiError(400, 'New password must be at least 6 characters long');
        }
        if (isset($input['confirm_password']) && $input['confirm_password'] !== $newPassword) {
            apiError(400, 'Password confirmation does not match new password');
        }
        $updates[] = "PASSWORD = ?";
        $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
        $types .= 's';
        $passwordChanged = true;
    }

    if (empty($updates)) {
        apiError(400, 'No editable fields provided to update');
    }

    $updates[] = "UPDATED_AT = NOW()";
    $sql = "UPDATE " . tn('TBL_USERS') . " SET " . implode(', ', $updates) . " WHERE ID = ?";
    $params[] = $userId;
    $types .= 'i';

    $stmt = $link->prepare($sql);
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        apiError(500, 'Failed to update user profile: ' . $err);
    }
    $stmt->close();

    logActivity($link, $userId, 'UPDATE', 'User updated profile via mobile app' . ($passwordChanged ? ' (including password)' : ''), (string)$userId, 'TBL_USERS');

    $refreshed = getFullUserProfileData($link, $userId);
    apiSuccess(array_merge([
        'message' => 'Profile updated successfully',
        'user' => $refreshed,
        'profile' => $refreshed,
    ], $refreshed));
}

/**
 * Handle password change from mobile app
 */
function handleMobilePasswordChange(mysqli $link, int $userId, array $input): void {
    $currentPassword = $input['current_password'] ?? ($input['old_password'] ?? '');
    $newPassword     = $input['new_password'] ?? ($input['password'] ?? '');
    $confirmPassword = $input['confirm_password'] ?? ($input['password_confirmation'] ?? '');

    if (!$currentPassword) {
        apiError(400, 'Current password is required');
    }
    if (!$newPassword) {
        apiError(400, 'New password is required');
    }
    if (strlen($newPassword) < 6) {
        apiError(400, 'New password must be at least 6 characters long');
    }
    if ($confirmPassword !== '' && $confirmPassword !== $newPassword) {
        apiError(400, 'Confirm password does not match new password');
    }

    $stmt = $link->prepare("SELECT PASSWORD FROM " . tn('TBL_USERS') . " WHERE ID = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($currentPassword, $row['PASSWORD'])) {
        apiError(400, 'Current password is incorrect');
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $upd = $link->prepare("UPDATE " . tn('TBL_USERS') . " SET PASSWORD = ?, UPDATED_AT = NOW() WHERE ID = ?");
    $upd->bind_param('si', $newHash, $userId);
    if (!$upd->execute()) {
        $err = $upd->error;
        $upd->close();
        apiError(500, 'Failed to update password: ' . $err);
    }
    $upd->close();

    logActivity($link, $userId, 'UPDATE', 'Password changed via mobile app', (string)$userId, 'TBL_USERS');

    apiSuccess([
        'message' => 'Password changed successfully'
    ]);
}
