<?php
/**
 * API Authentication Middleware
 * Validates Bearer tokens for API requests
 */
require_once __DIR__ . '/../config.php';
foreach ($_GET as $queryKey => $queryValue) {
    if (is_array($queryValue)) apiError(400, 'Invalid query parameter: ' . $queryKey);
}

function apiReadJson(): array {
    $raw = $GLOBALS['mock_api_input'] ?? file_get_contents('php://input');
    if (trim($raw) === '') return [];
    $input = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input) || substr(ltrim($raw), 0, 1) !== '{') {
        apiError(400, 'Request body must be a valid JSON object');
    }
    $allowedArrayKeys = ['metadata', 'loan_qualification', 'loan_details', 'qualification'];
    foreach ($input as $key => $value) {
        if (!in_array($key, $allowedArrayKeys, true) && (is_array($value) || is_object($value))) {
            apiError(400, 'Invalid value for ' . $key);
        }
    }
    return $input;
}

function apiRequireAuth(): array {
    $companyCode = $_SERVER['HTTP_X_COMPANY_CODE'] ?? '';
    if (!$companyCode && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $companyCode = $headers['X-Company-Code'] ?? $headers['x-company-code'] ?? '';
    }
    if ($companyCode) {
        $cleanCode = sanitizeCompanyCode($companyCode);
        if (!$cleanCode || !resolveTenant($cleanCode)) {
            apiError(400, 'Invalid or inactive company code provided in X-Company-Code');
        }
        $GLOBALS['link'] = getTenantConnection($cleanCode);
    }

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
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/telecaller') && !(getApiSettings()['api_enabled'] ?? true)) {
        apiError(503, 'Mobile API is temporarily disabled');
    }
    
    // Update last used
    $link->query("UPDATE " . tn('TBL_API_TOKENS') . " SET last_used_at = NOW() WHERE id = " . (int)$tokenData['id']);
    
    return $tokenData;
}

function apiRequirePermission(string $permission): void {
    $tokenData = $GLOBALS['api_token_data'] ?? null;
    if (!$tokenData) apiError(401, 'Authentication required');

    if (in_array($tokenData['user_role'], ['Admin', 'Super Admin'], true) || (int)$tokenData['user_id'] === 1) {
        return;
    }

    global $link;
    $stmt = $link->prepare("SELECT permission_value FROM " . tn('TBL_USER_PERMISSIONS') . " WHERE user_id = ? AND permission_key = ? LIMIT 1");
    $stmt->bind_param('is', $tokenData['user_id'], $permission);
    $stmt->execute();
    $override = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($override !== null) {
        if ((int)$override['permission_value'] === 1) return;
        apiError(403, "Permission denied: $permission required");
    }
    $stmt = $link->prepare("SELECT permission_value FROM " . tn('TBL_ROLE_PERMISSIONS') . " WHERE role = ? AND permission_key = ? LIMIT 1");
    $stmt->bind_param('ss', $tokenData['user_role'], $permission);
    $stmt->execute();
    $rolePermission = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$rolePermission || (int)$rolePermission['permission_value'] !== 1) apiError(403, "Permission denied: $permission required");
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
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function apiError(int $code, string $message, array $extra = []): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
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
            $stmt->bind_param('iissssii', $tokenId, $uId, $endpoint, $method, $ip, $ua, $responseCode, $execTimeMs);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $t) {}
}

if (!function_exists('syncCallToLeadsAndPipeline')) {
    function syncCallToLeadsAndPipeline(mysqli $link, string $source, int $recordId, int $userId, string $status, string $notes, ?string $nextFollowup, ?array $loanQualification = null, ?int $callLogId = null): ?int {
        $statusLower = strtolower(trim($status));
        $isNegative = str_contains($statusLower, 'not interested') 
            || str_contains($statusLower, 'not intrested') 
            || str_contains($statusLower, 'not_interested');
        $isLead = !$isNegative && (
            str_contains($statusLower, 'interested') 
            || str_contains($statusLower, 'intrested')
            || str_contains($statusLower, 'enquiry')
            || str_contains($statusLower, 'converted')
            || (str_contains($statusLower, 'lead') && !str_contains($statusLower, 'not a lead'))
            || in_array($statusLower, ['hot', 'hot lead', 'sales', 'positive'])
        );
        $isCallback = in_array($statusLower, ['call back', 'callback', 'follow-up', 'follow up', 'followup', 'callback / follow-up', 'follow-up needed', 'callback/ busy', 'callback / busy', 'busy']);

        if (!$isLead && !$isCallback) {
            return null;
        }

        $leadContact = null;
        $mainCustId = $recordId;

        if ($source === 'MAIN') {
            $q = $link->prepare("SELECT ID, MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO FROM " . tn('TBL_MAIN') . " WHERE ID = ?");
            if ($q) {
                $q->bind_param('i', $recordId);
                $q->execute();
                $leadContact = $q->get_result()->fetch_assoc();
                $q->close();
            }
        } else {
            $q = $link->prepare("SELECT ID, CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO FROM " . tn('TBL_TEMP') . " WHERE ID = ?");
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
                            $insM = $link->prepare("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_CALL_DIALED_USER, LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME) VALUES (?, ?, ?, ?, ?, 'Interested', ?, NOW(), NOW())");
                            $insM->bind_param('sssssi', $tRow['CUST_NAME'], $tRow['CUST_MOBILE'], $tRow['CUST_COMPANY'], $tRow['CUST_PACKAGE'], $tRow['CUST_OTHER_INFO'], $userId);
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
                        'MAINDATABASE_PACKAGE' => $tRow['CUST_PACKAGE'] ?? '',
                        'MAINDATABASE_OTHER_INFO' => $tRow['CUST_OTHER_INFO'] ?? '',
                    ];
                }
            }
        }

        if (!$leadContact) return null;

        $cName = $leadContact['MAINDATABASE_NAME'] ?? '';
        $cMobile = $leadContact['MAINDATABASE_MOBILE'] ?? '';
        $cCompany = $leadContact['MAINDATABASE_COMPANY'] ?? '';
        $cPackage = $leadContact['MAINDATABASE_PACKAGE'] ?? '';
        $cOther = $notes ?: ($leadContact['MAINDATABASE_OTHER_INFO'] ?? '');

        // Extract loan qualification details if provided
        $loanType = !empty($loanQualification['loan_type']) ? trim((string)$loanQualification['loan_type']) : 'Personal Loan';
        $loanAmount = !empty($loanQualification['loan_amount']) ? trim((string)$loanQualification['loan_amount']) : $cPackage;
        $netSalary = !empty($loanQualification['net_salary']) ? trim((string)$loanQualification['net_salary']) : null;
        $salaryAccount = !empty($loanQualification['salary_account']) ? trim((string)$loanQualification['salary_account']) : null;
        $bankName = !empty($loanQualification['bank_name']) ? trim((string)$loanQualification['bank_name']) : null;
        $loanTenure = !empty($loanQualification['loan_tenure']) ? trim((string)$loanQualification['loan_tenure']) : null;

        $chkL = $link->prepare("SELECT lead_id FROM " . tn('TBL_LEADS') . " WHERE cust_id = ? OR (MOBILE IS NOT NULL AND MOBILE != '' AND MOBILE = ?) LIMIT 1");
        $chkL->bind_param('is', $mainCustId, $cMobile);
        $chkL->execute();
        $existingL = $chkL->get_result()->fetch_assoc();
        $chkL->close();

        $leadId = null;

        if ($isLead) {
            if (!$existingL) {
                $insLead = $link->prepare("INSERT INTO " . tn('TBL_LEADS') . " 
                    (cust_id, NAME, MOBILE, COMPANY_NAME, loan_amount, loan_type, net_salary, salary_account, bank_name, loan_tenure, OTHER_INFO, assigned_to, assigned_by, created_by, updated_by, lead_status, lead_status_new, lead_stage, priority, remarks, next_followup_at, last_contacted_at, converted_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'New', 'LEAD', 'Hot', 'High', ?, ?, NOW(), NOW(), NOW(), NOW())");
                if ($insLead) {
                    $insLead->bind_param('issssssssssiiiiss', $mainCustId, $cName, $cMobile, $cCompany, $loanAmount, $loanType, $netSalary, $salaryAccount, $bankName, $loanTenure, $cOther, $userId, $userId, $userId, $userId, $notes, $nextFollowup);
                    $insLead->execute();
                    $leadId = (int)$insLead->insert_id;
                    $insLead->close();

                    // Log audit trail in contact_lead_conversions
                    $convStmt = $link->prepare("
                        INSERT INTO " . tn('TBL_CONTACT_LEAD_CONVERSIONS') . "
                            (client_contact_id, contact_source, lead_id, converted_by, source_call_id, conversion_date, loan_type, loan_amount, notes, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW())
                    ");
                    if ($convStmt) {
                        $convStmt->bind_param('isiissss', $mainCustId, $source, $leadId, $userId, $callLogId, $loanType, $loanAmount, $notes);
                        $convStmt->execute();
                        $convStmt->close();
                    }
                } else {
                    error_log("Failed to prepare INSERT INTO TBL_LEADS: " . $link->error);
                }

                if ($nextFollowup && $leadId > 0) {
                    $insFollow = $link->prepare("INSERT INTO " . tn('TBL_LEAD_FOLLOWUPS') . " (lead_id, client_contact_id, contact_source, source_call_id, user_id, followup_at, note, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'OPEN', NOW())");
                    if ($insFollow) {
                        $insFollow->bind_param('iisiiss', $leadId, $mainCustId, $source, $callLogId, $userId, $nextFollowup, $notes);
                        $insFollow->execute();
                        $insFollow->close();
                    }
                }
            } else {
                $leadId = (int)$existingL['lead_id'];
                $updLead = $link->prepare("UPDATE " . tn('TBL_LEADS') . " SET 
                    assigned_to = CASE WHEN assigned_to IS NULL OR assigned_to = 0 THEN ? ELSE assigned_to END,
                    lead_status_new = CASE WHEN lead_status_new IN ('LEAD', 'FOLLOWUP') OR lead_status_new IS NULL THEN 'LEAD' ELSE lead_status_new END,
                    lead_stage = CASE WHEN lead_status_new = 'LEAD' THEN 'Hot' ELSE lead_stage END,
                    priority = CASE WHEN lead_status_new = 'LEAD' THEN 'High' ELSE priority END,
                    lead_status = CASE WHEN lead_status_new = 'LEAD' THEN 'New' ELSE lead_status END,
                    loan_amount = CASE WHEN ? != '' AND ? IS NOT NULL THEN ? ELSE loan_amount END,
                    loan_type = CASE WHEN ? != '' AND ? IS NOT NULL THEN ? ELSE loan_type END,
                    remarks = CASE WHEN ? != '' THEN CONCAT(COALESCE(remarks, ''), '\n', ?) ELSE remarks END, 
                    next_followup_at = COALESCE(?, next_followup_at), 
                    last_contacted_at = NOW(), 
                    updated_by = ?,
                    updated_at = NOW() 
                    WHERE lead_id = ?");
                $updLead->bind_param('isssssssssii', $userId, $loanAmount, $loanAmount, $loanAmount, $loanType, $loanType, $loanType, $notes, $notes, $nextFollowup, $userId, $leadId);
                $updLead->execute();
                $updLead->close();

                if ($nextFollowup && $leadId > 0) {
                    $insFollow = $link->prepare("INSERT INTO " . tn('TBL_LEAD_FOLLOWUPS') . " (lead_id, client_contact_id, contact_source, source_call_id, user_id, followup_at, note, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'OPEN', NOW())");
                    if ($insFollow) {
                        $insFollow->bind_param('iisiiss', $leadId, $mainCustId, $source, $callLogId, $userId, $nextFollowup, $notes);
                        $insFollow->execute();
                        $insFollow->close();
                    }
                }
            }
        } elseif ($isCallback) {
            // Callback: preserve Client Contact, do NOT create Lead, but record scheduled follow-up
            $existingLeadId = $existingL ? (int)$existingL['lead_id'] : null;
            if ($existingLeadId) {
                $updLead = $link->prepare("UPDATE " . tn('TBL_LEADS') . " SET lead_status_new = CASE WHEN lead_status_new IN ('LEAD', 'FOLLOWUP') THEN 'FOLLOWUP' ELSE lead_status_new END, lead_status = CASE WHEN lead_status_new = 'FOLLOWUP' THEN 'Follow_Up' ELSE lead_status END, next_followup_at = COALESCE(?, next_followup_at), last_contacted_at = NOW(), updated_by = ?, updated_at = NOW() WHERE lead_id = ?");
                $updLead->bind_param('sii', $nextFollowup, $userId, $existingLeadId);
                $updLead->execute();
                $updLead->close();
            }

            if ($nextFollowup) {
                $insFollow = $link->prepare("INSERT INTO " . tn('TBL_LEAD_FOLLOWUPS') . " (lead_id, client_contact_id, contact_source, source_call_id, user_id, followup_at, note, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'OPEN', NOW())");
                if ($insFollow) {
                    $insFollow->bind_param('iisiiss', $existingLeadId, $recordId, $source, $callLogId, $userId, $nextFollowup, $notes);
                    $insFollow->execute();
                    $insFollow->close();
                }
            }
            $leadId = $existingLeadId;
        }

        return $leadId;
    }
}

function getFullUserProfileData(mysqli $link, int $userId): ?array {
    $stmt = $link->prepare("
        SELECT 
            u.ID as id,
            u.NAME as name,
            u.EMAIL as email,
            u.LOGIN_ID as login_id,
            u.MOBILE as mobile,
            u.COMPANY_NAME as company_name,
            u.COMPANY as company,
            u.PACKAGE as package,
            u.STATUS as status,
            u.ROLE as role,
            u.role_id,
            u.TEAM_ID as team_id,
            COALESCE(t.NAME, t.TEAM_NAME, '') as team_name,
            u.JOIN_DATE as join_date,
            u.CREATED_AT as created_at,
            u.UPDATED_AT as updated_at,
            u.LAST_LOGIN as last_login,
            u.profile_photo,
            u.profile_picture,
            u.profile_picture_blob,
            u.current_calling_db
        FROM " . tn('TBL_USERS') . " u
        LEFT JOIN " . tn('TBL_TEAMS') . " t ON u.TEAM_ID = t.ID
        WHERE u.ID = ? LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$user) {
        $stmt2 = @$link->prepare("SELECT * FROM " . tn('TBL_USERS') . " WHERE ID = ? LIMIT 1");
        if ($stmt2) {
            $stmt2->bind_param('i', $userId);
            $stmt2->execute();
            $raw = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
            if ($raw) {
                $user = [
                    'id' => (int)($raw['ID'] ?? $raw['id'] ?? $userId),
                    'name' => $raw['NAME'] ?? $raw['name'] ?? '',
                    'email' => $raw['EMAIL'] ?? $raw['email'] ?? '',
                    'login_id' => $raw['LOGIN_ID'] ?? $raw['login_id'] ?? '',
                    'mobile' => $raw['MOBILE'] ?? $raw['mobile'] ?? '',
                    'company_name' => $raw['COMPANY_NAME'] ?? $raw['company_name'] ?? ($raw['COMPANY'] ?? $raw['company'] ?? 'CallNow'),
                    'company' => $raw['COMPANY'] ?? $raw['company'] ?? 'CallNow',
                    'package' => $raw['PACKAGE'] ?? $raw['package'] ?? 'Standard',
                    'status' => $raw['STATUS'] ?? $raw['status'] ?? 'Active',
                    'role' => $raw['ROLE'] ?? $raw['role'] ?? 'Telecaller',
                    'role_id' => isset($raw['role_id']) ? (int)$raw['role_id'] : null,
                    'team_id' => isset($raw['TEAM_ID']) ? (int)$raw['TEAM_ID'] : (isset($raw['team_id']) ? (int)$raw['team_id'] : null),
                    'team_name' => '',
                    'join_date' => $raw['JOIN_DATE'] ?? $raw['join_date'] ?? ($raw['CREATED_AT'] ?? $raw['created_at'] ?? ''),
                    'created_at' => $raw['CREATED_AT'] ?? $raw['created_at'] ?? '',
                    'updated_at' => $raw['UPDATED_AT'] ?? $raw['updated_at'] ?? '',
                    'last_login' => $raw['LAST_LOGIN'] ?? $raw['last_login'] ?? '',
                    'profile_photo' => $raw['profile_photo'] ?? '',
                    'profile_picture' => $raw['profile_picture'] ?? '',
                    'profile_picture_blob' => $raw['profile_picture_blob'] ?? null,
                    'current_calling_db' => $raw['current_calling_db'] ?? 'temporary',
                    'daily_calling_limit' => isset($raw['daily_calling_limit']) ? (int)$raw['daily_calling_limit'] : 250,
                    'monthly_disbursement_limit' => isset($raw['monthly_disbursement_limit']) ? (float)$raw['monthly_disbursement_limit'] : 1500000.00,
                ];

                if (!empty($user['team_id'])) {
                    $teamId = (int)$user['team_id'];
                    $tRes = @$link->query("SELECT * FROM " . tn('TBL_TEAMS') . " WHERE ID = $teamId LIMIT 1");
                    if ($tRes && ($tRow = $tRes->fetch_assoc())) {
                        $user['team_name'] = $tRow['NAME'] ?? $tRow['TEAM_NAME'] ?? $tRow['name'] ?? '';
                    }
                }
            }
        }
    }

    if (!$user) return null;

    $resolvedEmail = $user['email'] ?: ($user['login_id'] ?? '');
    $resolvedPhone = $user['mobile'] ?? '';
    $resolvedCompany = $user['company_name'] ?: ($user['company'] ?? '');
    $resolvedTeam = $user['team_name'] ?: 'General Telecalling';

    // Construct photo URL and ensure sync between MySQL and disk storage
    $photoUrl = '';
    $pictureBase64 = !empty($user['profile_picture']) ? (string)$user['profile_picture'] : '';
    $uploadDir = function_exists('getTenantUploadDir') ? getTenantUploadDir('profile_photos') : (__DIR__ . '/../uploads/profile_photos/');
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $photoName = !empty($user['profile_photo']) ? (string)$user['profile_photo'] : '';
    if ($photoName === '') {
        $matches = @glob($uploadDir . 'user_' . $user['id'] . '_*');
        if (!empty($matches)) {
            usort($matches, function($a, $b) { return filemtime($b) - filemtime($a); });
            $photoName = basename($matches[0]);
            @$link->query("UPDATE " . tn('TBL_USERS') . " SET profile_photo = '$photoName' WHERE ID = " . (int)$user['id']);
        }
    }
    if ($photoName !== '') {
        $diskPath = $uploadDir . $photoName;
        // Recreate disk file from MySQL if missing
        if (!file_exists($diskPath)) {
            if (!empty($user['profile_picture_blob'])) {
                @file_put_contents($diskPath, $user['profile_picture_blob']);
            } elseif ($pictureBase64 !== '' && str_starts_with($pictureBase64, 'data:image/')) {
                $rawParts = explode(',', $pictureBase64, 2);
                if (count($rawParts) === 2) {
                    @file_put_contents($diskPath, base64_decode($rawParts[1]));
                }
            }
        }
    } elseif (!empty($user['profile_picture_blob']) || $pictureBase64 !== '') {
        // Picture exists in MySQL but no filename set: create file
        $photoName = 'user_' . $user['id'] . '_mysql.jpg';
        $diskPath = $uploadDir . $photoName;
        if (!empty($user['profile_picture_blob'])) {
            @file_put_contents($diskPath, $user['profile_picture_blob']);
        } elseif ($pictureBase64 !== '' && str_starts_with($pictureBase64, 'data:image/')) {
            $rawParts = explode(',', $pictureBase64, 2);
            if (count($rawParts) === 2) {
                @file_put_contents($diskPath, base64_decode($rawParts[1]));
            }
        }
    }

    if ($photoName !== '') {
        $base = defined('APP_BASE') && APP_BASE !== '' ? APP_BASE : '';
        if (!$base || str_contains($base, 'localhost')) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $reqHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $appPath = defined('APP_PATH') ? APP_PATH : '/callnowCRM';
            $base = rtrim($scheme . '://' . $reqHost . $appPath, '/');
        }
        $photoUrl = function_exists('getTenantUploadUrl') ? (getTenantUploadUrl('profile_photos') . $photoName) : ($base . '/uploads/profile_photos/' . $photoName);

        // Populate base64 if not yet generated
        if ($pictureBase64 === '' && file_exists($uploadDir . $photoName)) {
            $mime = @mime_content_type($uploadDir . $photoName) ?: 'image/jpeg';
            $pictureBase64 = 'data:' . $mime . ';base64,' . base64_encode(@file_get_contents($uploadDir . $photoName));
        }
    }

    return [
        'id' => (int)$user['id'],
        'name' => $user['name'] ?? '',
        'full_name' => $user['name'] ?? '',
        'email' => $resolvedEmail,
        'user_email' => $resolvedEmail,
        'login_id' => $user['login_id'] ?? '',
        'username' => $user['login_id'] ?: $resolvedEmail,
        'mobile' => $resolvedPhone,
        'phone' => $resolvedPhone,
        'phone_number' => $resolvedPhone,
        'company_name' => $resolvedCompany,
        'company' => $resolvedCompany,
        'package' => $user['package'] ?? 'Standard',
        'status' => $user['status'] ?? 'Active',
        'role' => $user['role'] ?? 'Telecaller',
        'role_id' => $user['role_id'] ? (int)$user['role_id'] : null,
        'team_id' => $user['team_id'] ? (int)$user['team_id'] : null,
        'team_name' => $resolvedTeam,
        'department' => $resolvedTeam,
        'join_date' => $user['join_date'] ?: ($user['created_at'] ?? ''),
        'created_at' => $user['created_at'] ?? '',
        'updated_at' => $user['updated_at'] ?? '',
        'last_login' => $user['last_login'] ?? '',
        'profile_photo' => $photoName,
        'profile_photo_url' => $photoUrl,
        'avatar_url' => $photoUrl,
        'profile_picture' => $pictureBase64 ?: $photoUrl,
        'profile_picture_url' => $photoUrl,
        'photo' => $photoUrl,
        'current_calling_db' => $user['current_calling_db'] ?? 'temporary',
        'daily_calling_limit' => (int)($user['daily_calling_limit'] ?? 250),
        'monthly_disbursement_limit' => (float)($user['monthly_disbursement_limit'] ?? 1500000.00),
        'daily_call_target' => (int)($user['daily_calling_limit'] ?? 250),
        'monthly_disbursement_target' => (float)($user['monthly_disbursement_limit'] ?? 1500000.00),
        'can_move_lead_stage' => in_array(strtolower(trim((string)($user['role'] ?? ''))), ['super admin', 'admin', 'manager', 'supervisor'], true) || (int)$user['id'] === 1,
        'can_change_lead_status' => in_array(strtolower(trim((string)($user['role'] ?? ''))), ['super admin', 'admin', 'manager', 'supervisor'], true) || (int)$user['id'] === 1,
    ];
}

// Auto-execute middleware for protected API endpoints.
// Auth routers (login/logout/refresh/me) define API_SKIP_AUTO_AUTH
// before including this file so public endpoints stay reachable.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-Company-Code');
    http_response_code(204);
    exit;
}
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
