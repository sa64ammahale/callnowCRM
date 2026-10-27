<?php
/**
 * Comprehensive API Module Test Suite
 * Tests Auth, Telecaller, Admin endpoints, DB Lease Locking, Call Submission, and Reporting
 */
require_once __DIR__ . '/../config.php';

$baseUrl = 'http://localhost/callnowCRM/api/v1';

function callApi(string $method, string $url, array $data = [], ?string $token = null): array {
    $ch = curl_init();
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $opt = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ];

    if (!empty($data) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
        $opt[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($ch, $opt);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $json = json_decode($response, true);
    return [
        'code' => $httpCode,
        'response' => $response,
        'json' => $json,
        'error' => $error,
    ];
}

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $name\n";
        $passed++;
    } else {
        echo "  [FAIL] $name" . ($detail ? " - $detail" : "") . "\n";
        $failed++;
    }
}

echo "====================================================\n";
echo "       RUNNING CALLNOW CRM API MODULE TESTS\n";
echo "====================================================\n\n";

global $link;

// 1. Find or create an active Admin user for testing
$userRes = $link->query("SELECT ID, NAME, EMAIL, LOGIN_ID, ROLE, PASSWORD FROM " . tn('TBL_USERS') . " WHERE STATUS = 'Active' ORDER BY ID ASC LIMIT 1");
$testUser = $userRes->fetch_assoc();

if (!$testUser) {
    die("Error: No active user found in database to run API tests.\n");
}

echo "Using active test user #{$testUser['ID']} ({$testUser['NAME']}, Role: {$testUser['ROLE']})\n\n";

// Set a known temporary password for login test
$testPassword = 'TestPassword123!';
$hashed = password_hash($testPassword, PASSWORD_DEFAULT);
$oldHash = $testUser['PASSWORD'];
$userId = (int)$testUser['ID'];
$link->query("UPDATE " . tn('TBL_USERS') . " SET PASSWORD = '$hashed' WHERE ID = $userId");

// Create temporary leads for testing in TBL_TEMP and TBL_MAIN if empty
$tempCheck = $link->query("SELECT ID FROM " . tn('TBL_TEMP') . " LIMIT 1");
if ($tempCheck->num_rows === 0) {
    $link->query("INSERT INTO " . tn('TBL_TEMP') . " (CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CALL_DIALED_STATUS, TEMP_UPLOAD_DATETIME) 
        VALUES ('Test Temp Lead', '9999988888', 'Acme Corp', 'Gold', 'Not Called', NOW())");
}

$mainCheck = $link->query("SELECT ID FROM " . tn('TBL_MAIN') . " LIMIT 1");
if ($mainCheck->num_rows === 0) {
    $link->query("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_CALL_DIALED_STATUS, CALL_COUNT, MAINDATABASE_UPLOAD_DATETIME) 
        VALUES ('Test Main Lead', '9999977777', 'Beta Ltd', 'Silver', 'Not Called', 0, NOW())");
}

// -------------------------------------------------------------
// GROUP 1: AUTHENTICATION ENDPOINTS
// -------------------------------------------------------------
echo "--- GROUP 1: AUTHENTICATION ---\n";

// Test 1.1: Missing credentials
$r = callApi('POST', "$baseUrl/auth/login", []);
assertTest("Login with empty body returns 400", $r['code'] === 400);

// Test 1.2: Invalid credentials
$r = callApi('POST', "$baseUrl/auth/login", [
    'email' => $testUser['EMAIL'] ?: $testUser['LOGIN_ID'],
    'password' => 'WrongPassword123',
]);
assertTest("Login with invalid password returns 401", $r['code'] === 401 && ($r['json']['success'] ?? null) === false);

// Test 1.3: Successful login
$loginIdentifier = $testUser['EMAIL'] ?: $testUser['LOGIN_ID'];
$r = callApi('POST', "$baseUrl/auth/login", [
    'email' => $loginIdentifier,
    'password' => $testPassword,
]);
assertTest("Login with valid credentials returns 200 & token", $r['code'] === 200 && !empty($r['json']['data']['token']));

$authToken = $r['json']['data']['token'] ?? '';
$tokenUserId = $r['json']['data']['user']['id'] ?? 0;
assertTest("Login returns user profile and DB access", $tokenUserId === $userId && isset($r['json']['data']['database_access']));

// Test 1.4: Protected endpoint without auth returns 401
$r = callApi('GET', "$baseUrl/auth/me");
assertTest("/auth/me without token returns 401", $r['code'] === 401);

// Test 1.5: Protected endpoint with invalid token returns 401
$r = callApi('GET', "$baseUrl/auth/me", [], 'invalid_token_xyz');
assertTest("/auth/me with invalid token returns 401", $r['code'] === 401);

// Test 1.6: Authenticated /auth/me returns 200
$r = callApi('GET', "$baseUrl/auth/me", [], $authToken);
assertTest("/auth/me with valid Bearer token returns 200", $r['code'] === 200 && ($r['json']['data']['user']['id'] ?? 0) === $userId);

// Test 1.7: Token refresh returns 200
$r = callApi('POST', "$baseUrl/auth/refresh", [], $authToken);
assertTest("/auth/refresh extends token expiry returns 200", $r['code'] === 200 && !empty($r['json']['data']['expires_at']));

// -------------------------------------------------------------
// GROUP 2: TELECALLER ENDPOINTS
// -------------------------------------------------------------
echo "\n--- GROUP 2: TELECALLER QUEUE & DIALING ---\n";

// Test 2.1: Telecaller dashboard stats
$r = callApi('GET', "$baseUrl/telecaller/dashboard", [], $authToken);
assertTest("/telecaller/dashboard returns 200 with stats structure", 
    $r['code'] === 200 && isset($r['json']['data']['today_calls'], $r['json']['data']['today_connected'], $r['json']['data']['today_talk_time'], $r['json']['data']['today_pending'])
);

// Test 2.2: Batch leads fetch with 20-minute lease
$r = callApi('GET', "$baseUrl/telecaller/batch-leads?limit=5", [], $authToken);
assertTest("/telecaller/batch-leads returns 200", $r['code'] === 200);
$currentDb = $r['json']['data']['current_database'] ?? '';
$leads = $r['json']['data']['leads'] ?? [];
assertTest("/telecaller/batch-leads returns current_database and leads array", 
    in_array($currentDb, ['TEMPORARY', 'MAIN', 'MIXED']) && is_array($leads)
);
assertTest("/telecaller/batch-leads confirms 20-min lease duration", 
    ($r['json']['data']['lease_duration_minutes'] ?? 0) === 20
);

// Test 2.3: Verify database records leased by user have BATCH_ASSIGNED_AT set
$leasedTemp = $link->query("SELECT ID, BATCH_ASSIGNED_AT FROM " . tn('TBL_TEMP') . " WHERE CALL_DIALED_TELECALLER = $userId AND BATCH_ASSIGNED_AT IS NOT NULL LIMIT 1")->fetch_assoc();
$leasedMain = $link->query("SELECT ID, BATCH_ASSIGNED_AT FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_CALL_DIALED_USER = $userId AND BATCH_ASSIGNED_AT IS NOT NULL LIMIT 1")->fetch_assoc();
assertTest("Batch acquisition locked lease timestamps in DB", !empty($leasedTemp) || !empty($leasedMain));

// Test 2.4: Single next-call fallback
$r = callApi('GET', "$baseUrl/telecaller/next-call", [], $authToken);
assertTest("/telecaller/next-call returns 200", $r['code'] === 200);

// Test 2.5: Submit call completion via /calls/{id}/complete
$targetRecId = !empty($leads[0]['id']) ? $leads[0]['id'] : ($leasedTemp['ID'] ?? ($leasedMain['ID'] ?? 1));
$targetSource = !empty($leads[0]['source']) ? $leads[0]['source'] : (!empty($leasedTemp) ? 'TEMP' : 'MAIN');

$r = callApi('POST', "$baseUrl/telecaller/calls/$targetRecId/complete", [
    'source' => $targetSource,
    'call_status' => 'Connected',
    'call_duration' => 45,
    'notes' => 'Customer was interested, discussed pricing.',
    'next_followup' => date('Y-m-d H:i:s', strtotime('+2 days')),
], $authToken);
assertTest("POST /telecaller/calls/{id}/complete logs call successfully", 
    $r['code'] === 200 && ($r['json']['data']['timing_classification'] ?? '') === 'CONNECTED_CONVERSATION'
);

$logId = $r['json']['data']['log_id'] ?? 0;

// Test 2.6: Verify log entry in TBL_CALL_LOGS using exact column names
$logEntry = $link->query("SELECT * FROM " . tn('TBL_CALL_LOGS') . " WHERE id = $logId")->fetch_assoc();
assertTest("TBL_CALL_LOGS saved record_id, source, call_duration, call_status", 
    !empty($logEntry) && (int)$logEntry['record_id'] === (int)$targetRecId && (int)$logEntry['call_duration'] === 45 && $logEntry['call_status'] === 'Connected'
);

// Test 2.7: Verify master table lease cleared and LAST_CONNECTED_PERIOD updated to datetime
if ($targetSource === 'MAIN') {
    $mRec = $link->query("SELECT BATCH_ASSIGNED_AT, LAST_CONNECTED_PERIOD, MAINDATABASE_CALL_DIALED_STATUS FROM " . tn('TBL_MAIN') . " WHERE ID = $targetRecId")->fetch_assoc();
    assertTest("TBL_MAIN lease cleared (BATCH_ASSIGNED_AT is NULL)", $mRec['BATCH_ASSIGNED_AT'] === null);
    assertTest("TBL_MAIN LAST_CONNECTED_PERIOD is valid datetime", !empty($mRec['LAST_CONNECTED_PERIOD']) && $mRec['LAST_CONNECTED_PERIOD'] !== '0000-00-00 00:00:00');
} else {
    $tRec = $link->query("SELECT BATCH_ASSIGNED_AT, LAST_CONNECTED_PERIOD, CALL_DIALED_STATUS FROM " . tn('TBL_TEMP') . " WHERE ID = $targetRecId")->fetch_assoc();
    assertTest("TBL_TEMP lease cleared (BATCH_ASSIGNED_AT is NULL)", $tRec['BATCH_ASSIGNED_AT'] === null);
    assertTest("TBL_TEMP LAST_CONNECTED_PERIOD is valid datetime", !empty($tRec['LAST_CONNECTED_PERIOD']) && $tRec['LAST_CONNECTED_PERIOD'] !== '0000-00-00 00:00:00');
}

// Test 2.8: Compatibility submit via /submit_call endpoint
$r = callApi('POST', "$baseUrl/telecaller/submit_call", [
    'record_id' => $targetRecId,
    'source' => $targetSource,
    'call_status' => 'Busy',
    'call_duration' => 0,
    'notes' => 'Line was busy',
], $authToken);
assertTest("POST /telecaller/submit_call compatibility works", 
    $r['code'] === 200 && ($r['json']['data']['timing_classification'] ?? '') === 'NOT_CONNECTED'
);

// Test 2.9: Reports summary
$r = callApi('GET', "$baseUrl/telecaller/reports/summary?period=today", [], $authToken);
assertTest("/telecaller/reports/summary returns aggregate metrics", 
    $r['code'] === 200 && ($r['json']['data']['total_calls'] ?? 0) >= 1 && isset($r['json']['data']['connection_rate_percent'], $r['json']['data']['timing_tiers'])
);

// Test 2.10: Calls history list with joined customer info
$r = callApi('GET', "$baseUrl/telecaller/calls?period=all", [], $authToken);
assertTest("/telecaller/calls returns rich joined call list", 
    $r['code'] === 200 && is_array($r['json']['data']['data'] ?? null) && count($r['json']['data']['data']) >= 1
);

// Test 2.11: Specific record call history
$r = callApi('GET', "$baseUrl/telecaller/call-history/$targetRecId?source=$targetSource", [], $authToken);
assertTest("/telecaller/call-history/{id} returns historical logs for record", 
    $r['code'] === 200 && ($r['json']['data']['total'] ?? 0) >= 1
);

// Test 2.12: Voluntary release active queue
$r = callApi('POST', "$baseUrl/telecaller/release-queue", [], $authToken);
assertTest("POST /telecaller/release-queue releases leased numbers", 
    $r['code'] === 200 && isset($r['json']['data']['total_released'])
);

// -------------------------------------------------------------
// GROUP 3: ADMIN SETTINGS & MANAGEMENT
// -------------------------------------------------------------
echo "\n--- GROUP 3: ADMIN SETTINGS & MANAGEMENT ---\n";

// Test 3.1: Get admin API settings
$r = callApi('GET', "$baseUrl/admin/settings", [], $authToken);
assertTest("GET /admin/settings returns settings object", $r['code'] === 200 && is_array($r['json']['data'] ?? null));

// Test 3.2: Update admin API settings
$r = callApi('POST', "$baseUrl/admin/settings", [
    'settings' => [
        'rate_limit_per_minute' => '120',
        'rate_limit_per_hour' => '2000',
    ]
], $authToken);
assertTest("POST /admin/settings updates settings successfully", $r['code'] === 200);

// Test 3.3: Token management - List tokens
$r = callApi('GET', "$baseUrl/admin/tokens", [], $authToken);
assertTest("GET /admin/tokens lists tokens with pagination", 
    $r['code'] === 200 && isset($r['json']['data']['data'], $r['json']['data']['pagination'])
);

// Test 3.4: Token management - Create token
$r = callApi('POST', "$baseUrl/admin/tokens", [
    'user_id' => $userId,
    'name' => 'Automated Test Token',
    'expiry_days' => 30,
], $authToken);
assertTest("POST /admin/tokens creates new token", $r['code'] === 200 && !empty($r['json']['data']['token']));
$newCreatedTokenId = $r['json']['data']['token_id'] ?? 0;

// Test 3.5: Token management - Update token
$r = callApi('PUT', "$baseUrl/admin/tokens/$newCreatedTokenId", [
    'name' => 'Renamed Test Token',
], $authToken);
assertTest("PUT /admin/tokens/{id} updates token metadata", $r['code'] === 200);

// Test 3.6: Token management - Delete/Revoke token
$r = callApi('DELETE', "$baseUrl/admin/tokens/$newCreatedTokenId", [], $authToken);
assertTest("DELETE /admin/tokens/{id} revokes token", $r['code'] === 200);

// Test 3.7: Assignments - List assignments
$r = callApi('GET', "$baseUrl/admin/assignments", [], $authToken);
assertTest("GET /admin/assignments returns assignments list", $r['code'] === 200 && is_array($r['json']['data'] ?? null));

// Test 3.8: Assignments - Create assignment
$r = callApi('POST', "$baseUrl/admin/assignments", [
    'user_id' => $userId,
    'database_type' => 'temporary',
], $authToken);
assertTest("POST /admin/assignments assigns database", $r['code'] === 200);

// -------------------------------------------------------------
// GROUP 4: LOGOUT & TOKEN INVALIDATION
// -------------------------------------------------------------
echo "\n--- GROUP 4: LOGOUT & TOKEN INVALIDATION ---\n";

// Test 4.1: Logout successfully
$r = callApi('POST', "$baseUrl/auth/logout", [], $authToken);
assertTest("POST /auth/logout invalidates session", $r['code'] === 200);

// Test 4.2: Using invalidated token now fails with 401
$r = callApi('GET', "$baseUrl/auth/me", [], $authToken);
assertTest("Revoked token now returns 401 Unauthorized", $r['code'] === 401);

// Restore original user password
$link->query("UPDATE " . tn('TBL_USERS') . " SET PASSWORD = '$oldHash' WHERE ID = $userId");

echo "\n====================================================\n";
echo "SUMMARY: Passed: $passed, Failed: $failed\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
}
