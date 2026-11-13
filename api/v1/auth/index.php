<?php
define('API_SKIP_AUTO_AUTH', true);
require_once __DIR__ . '/../../../php_scripts/api_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-Company-Code');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ensureApiSchema($GLOBALS['link']);

// Normalize endpoint path to support any base path, subfolder, index.php or double slash
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$uriPath = preg_replace('#/+#', '/', $uriPath);
$uriPath = str_replace('/index.php', '', $uriPath);

// Locate /auth or /api/v1
if (($pos = strpos($uriPath, '/auth')) !== false) {
    $endpoint = substr($uriPath, $pos + strlen('/auth'));
} elseif (($pos = strpos($uriPath, '/api/v1')) !== false) {
    $endpoint = substr($uriPath, $pos + strlen('/api/v1'));
} else {
    $endpoint = $uriPath;
}
$endpoint = '/' . trim($endpoint, '/');
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// If empty endpoint, check query param or default to /me for GET
if ($endpoint === '/' || $endpoint === '') {
    if (!empty($_GET['endpoint'])) {
        $endpoint = '/' . trim($_GET['endpoint'], '/');
    } elseif (!empty($_GET['action'])) {
        $endpoint = '/' . trim($_GET['action'], '/');
    } else {
        $endpoint = ($method === 'GET') ? '/me' : '/';
    }
}

// Allow GET for /me, /profile, /user, and /refresh endpoints
$isGetEndpoint = in_array($endpoint, ['/me', '/profile', '/user', '/refresh', '/photo', '/avatar']);
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
if (trim($rawBody) !== '' && (json_last_error() !== JSON_ERROR_NONE || !is_array($input) || substr(ltrim($rawBody), 0, 1) !== '{')) apiError(400, 'Request body must be a valid JSON object');
if (!is_array($input)) {
    $input = !empty($_POST) ? $_POST : [];
}
foreach ($input as $field => $value) {
    if (is_array($value) || is_object($value)) apiError(400, 'Invalid value for ' . $field);
}

global $link;

// -------------------------------------------------------------
// 1. LOGIN
// -------------------------------------------------------------
if ($endpoint === '/login') {
    if (!(getApiSettings()['api_enabled'] ?? true)) apiError(503, 'Mobile API is temporarily disabled');
    if ($method !== 'POST') {
        apiError(405, 'Method not allowed');
    }
    
    $email = trim($input['email'] ?? ($input['username'] ?? ''));
    $password = $input['password'] ?? '';
    
    if (!$email || !$password) {
        apiError(400, 'Email/username and password required');
    }

    $companyCode = trim($input['company_code'] ?? ($input['company'] ?? ($input['CompanyName'] ?? '')));
    $tenant = null;
    if ($companyCode !== '') {
        $tenant = resolveTenant($companyCode);
        if (!$tenant) {
            apiError(400, 'Invalid company code or organization account is inactive');
        }
        $link = getTenantConnection($tenant['company_code']);
        $GLOBALS['link'] = $link;
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
    
    apiSuccess(array_merge($fullProfile, [
        'token' => $token,
        'expires_at' => $expiresAt,
        'company_code' => $tenant['company_code'] ?? ($user['COMPANY'] ?? 'incredit'),
        'company_name' => $tenant['company_name'] ?? ($user['COMPANY_NAME'] ?? 'Incredit Financial Services'),
        'user' => $fullProfile,
        'profile' => $fullProfile,
        'database_access' => $dbAccess,
        'assigned_teams' => $teamIds,
    ]));
    
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
    
    apiSuccess(array_merge($fullProfile, [
        'token' => $tokenData['token'] ?? '',
        'expires_at' => $newExpiry,
        'user' => $fullProfile,
        'profile' => $fullProfile,
        'database_access' => $dbAccess,
        'assigned_teams' => $teamIds,
    ]));
    
// -------------------------------------------------------------
// 4. GET / UPDATE PROFILE (/me, /profile, /user)
// -------------------------------------------------------------
} elseif ($endpoint === '/me' || $endpoint === '/profile' || $endpoint === '/user') {
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

// -------------------------------------------------------------
// 6b. FORGOT PASSWORD (REQUEST OTP)
// -------------------------------------------------------------
} elseif ($endpoint === '/forgot-password' || $endpoint === '/send-otp' || $endpoint === '/reset-password/otp' || $endpoint === '/password/email-otp') {
    if ($method !== 'POST') {
        apiError(405, 'Method not allowed');
    }

    $email = trim((string)($input['email'] ?? ($input['username'] ?? '')));
    if ($email === '') {
        apiError(400, 'Email address or username is required');
    }

    require_once __DIR__ . '/../../../php_scripts/password_reset.php';
    ensurePasswordResetSchema($link);

    $stmt = $link->prepare("SELECT ID, LOGIN_ID, EMAIL, STATUS FROM " . tn('TBL_USERS') . " WHERE (EMAIL = ? OR LOGIN_ID = ?) AND STATUS = 'Active' LIMIT 1");
    $stmt->bind_param('ss', $email, $email);
    $stmt->execute();
    $targetUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $otp = sprintf('%06d', random_int(100000, 999999));
    $tokenHash = hash('sha256', $otp);
    $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 minutes

    if ($targetUser) {
        $uId = (int)$targetUser['ID'];
        // Invalidate older unused reset tokens for this user
        $inv = $link->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
        $inv->bind_param('i', $uId);
        $inv->execute();
        $inv->close();

        // Insert new OTP token
        $tokStmt = $link->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
        $tokStmt->bind_param('iss', $uId, $tokenHash, $expiresAt);
        $tokStmt->execute();
        $tokStmt->close();

        $destEmail = !empty($targetUser['EMAIL']) && filter_var($targetUser['EMAIL'], FILTER_VALIDATE_EMAIL) ? $targetUser['EMAIL'] : $targetUser['LOGIN_ID'];
        if (filter_var($destEmail, FILTER_VALIDATE_EMAIL)) {
            @mail($destEmail, 'CallNow CRM - Password Reset Verification Code', "Your verification code to reset your CallNow CRM password is:\n\n{$otp}\n\nThis code will expire in 15 minutes.\nIf you did not request this, please ignore this email.");
        }
        logActivity($link, $uId, 'PASSWORD_RESET_OTP', "Password reset OTP requested for {$destEmail}", (string)$uId, 'TBL_USERS');
    }

    apiSuccess([
        'success' => true,
        'message' => 'If an active account exists with this email, a verification code has been sent.',
        'debug_otp' => APP_DEBUG ? $otp : null,
    ]);

// -------------------------------------------------------------
// 6c. RESET PASSWORD WITH OTP
// -------------------------------------------------------------
} elseif ($endpoint === '/reset-password' || $endpoint === '/verify-otp-reset' || $endpoint === '/password/reset') {
    if ($method !== 'POST') {
        apiError(405, 'Method not allowed');
    }

    $email = trim((string)($input['email'] ?? ($input['username'] ?? '')));
    $otp   = trim((string)($input['otp'] ?? ($input['token'] ?? ($input['code'] ?? ''))));
    $newPass = (string)($input['new_password'] ?? ($input['password'] ?? ''));
    $confirmPass = (string)($input['password_confirmation'] ?? ($input['confirm_password'] ?? ''));

    if ($email === '') apiError(400, 'Email address is required');
    if ($otp === '') apiError(400, 'Verification code / OTP is required');
    if (strlen($newPass) < 6) apiError(400, 'Password must be at least 6 characters long');
    if ($confirmPass !== '' && $confirmPass !== $newPass) apiError(400, 'Password confirmation does not match');

    require_once __DIR__ . '/../../../php_scripts/password_reset.php';
    ensurePasswordResetSchema($link);

    $stmt = $link->prepare("SELECT ID, LOGIN_ID, STATUS FROM " . tn('TBL_USERS') . " WHERE (EMAIL = ? OR LOGIN_ID = ?) AND STATUS = 'Active' LIMIT 1");
    $stmt->bind_param('ss', $email, $email);
    $stmt->execute();
    $targetUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$targetUser) {
        apiError(400, 'Invalid request or user not found');
    }

    $uId = (int)$targetUser['ID'];
    $otpHash = hash('sha256', $otp);

    $chk = $link->prepare("SELECT id FROM password_reset_tokens WHERE user_id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $chk->bind_param('is', $uId, $otpHash);
    $chk->execute();
    $tokenRow = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$tokenRow) {
        apiError(400, 'Invalid or expired verification code');
    }

    $link->begin_transaction();
    try {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $uStmt = $link->prepare("UPDATE " . tn('TBL_USERS') . " SET PASSWORD = ?, UPDATED_AT = NOW() WHERE ID = ?");
        $uStmt->bind_param('si', $hash, $uId);
        $uStmt->execute();
        $uStmt->close();

        $tokUpd = $link->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
        $tokUpd->bind_param('i', $uId);
        $tokUpd->execute();
        $tokUpd->close();

        $tokRevoke = $link->prepare("UPDATE " . tn('TBL_API_TOKENS') . " SET is_active = 0 WHERE user_id = ?");
        $tokRevoke->bind_param('i', $uId);
        $tokRevoke->execute();
        $tokRevoke->close();

        $link->commit();
    } catch (Throwable $e) {
        $link->rollback();
        apiError(500, 'Failed to reset password: ' . $e->getMessage());
    }

    logActivity($link, $uId, 'PASSWORD_RESET', 'Password successfully reset via mobile app OTP', (string)$uId, 'TBL_USERS');

    apiSuccess([
        'success' => true,
        'message' => 'Password reset successfully. You can now login with your new password.',
    ]);

// -------------------------------------------------------------
// 7. GET PROFILE PHOTO STREAM DIRECTLY FROM MYSQL
// -------------------------------------------------------------
} elseif ($endpoint === '/photo' || $endpoint === '/avatar') {
    $targetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($targetId <= 0) {
        $tokenData = apiRequireAuth();
        $targetId = (int)$tokenData['user_id'];
    }

    $stmt = $link->prepare("SELECT profile_photo, profile_picture, profile_picture_blob FROM " . tn('TBL_USERS') . " WHERE ID = ? LIMIT 1");
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $pRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Photo not found']);
        exit;
    }

    if (!empty($pRow['profile_picture_blob'])) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        echo $pRow['profile_picture_blob'];
        exit;
    }

    if (!empty($pRow['profile_photo'])) {
        $tenantDir = function_exists('getTenantUploadDir') ? getTenantUploadDir('profile_photos') : (__DIR__ . '/../../../uploads/profile_photos/');
        $filePath = $tenantDir . $pRow['profile_photo'];
        if (!file_exists($filePath)) {
            $filePath = __DIR__ . '/../../../uploads/profile_photos/' . $pRow['profile_photo'];
        }
        if (file_exists($filePath)) {
            $mime = @mime_content_type($filePath) ?: 'image/jpeg';
            header('Content-Type: ' . $mime);
            header('Cache-Control: public, max-age=86400');
            readfile($filePath);
            exit;
        }
    }

    if (!empty($pRow['profile_picture']) && str_starts_with($pRow['profile_picture'], 'data:image/')) {
        $parts = explode(',', $pRow['profile_picture'], 2);
        if (count($parts) === 2) {
            header('Content-Type: image/jpeg');
            header('Cache-Control: public, max-age=86400');
            echo base64_decode($parts[1]);
            exit;
        }
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'No profile photo uploaded']);
    exit;

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

    // Daily Calling Limit update
    if (isset($input['daily_calling_limit'])) {
        $dailyLimit = max(0, (int)$input['daily_calling_limit']);
        $updates[] = "daily_calling_limit = ?";
        $params[] = $dailyLimit;
        $types .= 'i';
    }

    // Monthly Disbursement Limit update
    if (isset($input['monthly_disbursement_limit'])) {
        $monthlyLimit = max(0.0, (float)$input['monthly_disbursement_limit']);
        $updates[] = "monthly_disbursement_limit = ?";
        $params[] = $monthlyLimit;
        $types .= 'd';
    }

    // Optional profile photo via base64
    if (!empty($input['profile_photo_base64'])) {
        $photoData = $input['profile_photo_base64'];
        $mime = 'image/jpeg';
        if (preg_match('/^data:image\/(jpeg|png|webp|gif);base64,/', $photoData, $m)) {
            $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
            $mime = 'image/' . $m[1];
            $photoData = substr($photoData, strpos($photoData, ',') + 1);
        } else {
            $ext = 'jpg';
        }
        $decoded = base64_decode($photoData);
        if ($decoded !== false && strlen($decoded) <= 2 * 1024 * 1024) {
            $uploadDir = function_exists('getTenantUploadDir') ? getTenantUploadDir('profile_photos') : (__DIR__ . '/../../../uploads/profile_photos/');
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $fileName = 'user_' . $userId . '_' . time() . '.' . $ext;
            @file_put_contents($uploadDir . $fileName, $decoded);
            $base64Uri = 'data:' . $mime . ';base64,' . base64_encode($decoded);

            // Save in MySQL: filename, base64 data URI, and binary blob
            $updates[] = "profile_photo = ?";
            $params[] = $fileName;
            $types .= 's';

            $updates[] = "profile_picture = ?";
            $params[] = $base64Uri;
            $types .= 's';

            $updates[] = "profile_picture_blob = ?";
            $params[] = $decoded;
            $types .= 's';
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
