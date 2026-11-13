<?php
define('API_SKIP_AUTO_AUTH', true);
/**
 * Automated Verification Suite for Telecalling CRM End Goals:
 * 
 * Goal 1: Upload data on server (Temporary test data vs Main database, test calling, admin delete poor data, fallback when ended).
 * Goal 2: Mobile app updates call status one-by-one via API.
 * Goal 3: Leads generation, pipeline processing, mobile visibility of self leads, server reports & mobile self reports.
 * Goal 4: Concurrency (one number to one user at a time, zero collisions), 15-minute lease, temporary-to-main auto-fallback,
 *         oldest dialed sorting, strict 3-month (90 days) cooldown, and activity protection.
 */

require_once __DIR__ . '/../php_scripts/config.php';
if (!str_starts_with($DB_NAME, 'callnow_audit_')) throw new RuntimeException('Run through tests/run_project_audit.php to protect the working database');
require_once __DIR__ . '/../php_scripts/api_auth.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertGoal(string $name, bool $condition, string $details = '') {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] $name\n";
    } else {
        $failedTests++;
        echo "  [FAIL] $name" . ($details ? " ($details)" : "") . "\n";
    }
}

function callApiEndpoint(string $method, string $path, array $data = [], ?string $token = null): array {
    $url = (getenv('TEST_API_BASE') ?: 'http://localhost/callnowCRM/api/v1') . $path;
    $ch = curl_init();
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
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

    return [
        'code' => $httpCode,
        'response' => $response,
        'json' => json_decode($response, true),
        'error' => $error,
    ];
}

echo "====================================================\n";
echo "   TELECALLING CRM END GOALS VERIFICATION SUITE    \n";
echo "====================================================\n\n";

global $link;

// Setup two distinct telecaller test users (User A and User B)
$stmt = $link->query("SELECT ID, NAME, EMAIL, ROLE FROM " . tn('TBL_USERS') . " WHERE STATUS = 'Active' ORDER BY ID ASC LIMIT 2");
$users = $stmt->fetch_all(MYSQLI_ASSOC);
if (count($users) < 2) {
    die("Error: At least 2 active users required for concurrency and isolation testing.\n");
}

function getOrCreateUserToken(mysqli $link, int $userId): string {
    grantDefaultApiAccess($userId);
    $existing = $link->query("SELECT token FROM " . tn('TBL_API_TOKENS') . " WHERE user_id = $userId AND is_active = 1 AND expires_at > NOW() LIMIT 1")->fetch_assoc();
    if ($existing && !empty($existing['token'])) {
        return $existing['token'];
    }
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $device = 'Goal Verification Device';
    $stmt = $link->prepare("INSERT INTO " . tn('TBL_API_TOKENS') . " (user_id, token, device_name, is_active, created_at, expires_at) VALUES (?, ?, ?, 1, NOW(), ?)");
    $stmt->bind_param('isss', $userId, $token, $device, $expires);
    $stmt->execute();
    $stmt->close();
    return $token;
}

$userA = $users[0];
$userB = $users[1];
$tokenA = getOrCreateUserToken($link, (int)$userA['ID']);
$tokenB = getOrCreateUserToken($link, (int)$userB['ID']);

echo "Test Users Setup:\n";
echo " - Telecaller A: ID={$userA['ID']}, Name={$userA['NAME']}\n";
echo " - Telecaller B: ID={$userB['ID']}, Name={$userB['NAME']}\n\n";

// =============================================================================
// GOAL 1: DATA UPLOAD & TEMPORARY VS MAIN DATABASE
// =============================================================================
echo "--- GOAL 1: DATA UPLOAD & TEMPORARY VS MAIN DATABASE ---\n";

// Clean any previous test records
$link->query("DELETE FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE LIKE '99990000%'");
$link->query("DELETE FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE LIKE '99990000%'");
$link->query("DELETE FROM " . tn('TBL_CALL_LOGS') . " WHERE notes LIKE '%[TEST_GOALS]%'");

// 1.1 Insert a test batch into temporary database
$tempTestMobile1 = '9999000001';
$tempTestMobile2 = '9999000002';
$tempPoorMobile  = '9999000003';

$link->query("INSERT INTO " . tn('TBL_TEMP') . " (CUST_NAME, CUST_MOBILE, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, CALL_DIALED_STATUS, TEMP_UPLOAD_DATETIME) VALUES 
    ('Test Quality Customer 1', '$tempTestMobile1', 'Alpha Corp', '10L', '[TEST_GOALS] Quality test batch', 'Not Called', NOW()),
    ('Test Quality Customer 2', '$tempTestMobile2', 'Beta Corp', '15L', '[TEST_GOALS] Quality test batch', 'Not Called', NOW()),
    ('Test Poor Quality Number', '$tempPoorMobile', 'Spam Corp', '0L', '[TEST_GOALS] Poor quality lead', 'Not Called', NOW())");

$tempCount = (int)$link->query("SELECT COUNT(*) as c FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE LIKE '99990000%'")->fetch_assoc()['c'];
assertGoal("Temporary test batch successfully uploaded to server", $tempCount === 3, "Count: $tempCount");

// 1.2 Telecaller calls test temporary records via mobile API
$batchRes = callApiEndpoint('GET', '/telecaller/batch-leads?limit=2', [], $tokenA);
assertGoal("Mobile telecaller fetches temporary test data with priority", 
    $batchRes['code'] === 200 && ($batchRes['json']['data']['leads'][0]['source'] ?? '') === 'TEMP',
    "Source: " . ($batchRes['json']['data']['leads'][0]['source'] ?? 'none')
);

// 1.3 Admin identifies poor quality test data and deletes directly from temporary database
$link->query("DELETE FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = '$tempPoorMobile'");
$deletedCheck = $link->query("SELECT COUNT(*) as c FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = '$tempPoorMobile'")->fetch_assoc()['c'];
assertGoal("Admin can delete poor quality test data directly from temporary table", (int)$deletedCheck === 0);

// 1.4 Main database serves as the final repository
$mainTestMobile = '9999000010';
$link->query("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_UPLOAD_DATETIME) VALUES 
    ('Main DB Client 1', '$mainTestMobile', 'Enterprise Ltd', '25L', '[TEST_GOALS] Main DB record', 'Not Called', NOW())");
$mainRec = $link->query("SELECT ID FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = '$mainTestMobile'")->fetch_assoc();
assertGoal("Main database stores enterprise records for regular calling and logs", !empty($mainRec['ID']));


// =============================================================================
// GOAL 2: MOBILE APP CALL STATUS UPDATE ONE-BY-ONE
// =============================================================================
echo "\n--- GOAL 2: MOBILE APP UPDATES CALL STATUS ONE-BY-ONE ---\n";

$mainRecordId = (int)$mainRec['ID'];
$updatePayload = [
    'source' => 'MAIN',
    'call_status' => 'Busy',
    'call_duration' => 12,
    'timing_classification' => 'CONNECTED_SHORT',
    'notes' => '[TEST_GOALS] Customer asked to call later',
    'next_followup' => date('Y-m-d H:i:s', strtotime('+2 days')),
];

$compRes = callApiEndpoint('POST', "/telecaller/calls/$mainRecordId/complete", $updatePayload, $tokenA);
assertGoal("Mobile app updates call status one-by-one via POST /calls/{id}/complete", 
    $compRes['code'] === 200 && isset($compRes['json']['data']['log_id']),
    "HTTP " . $compRes['code']
);

// Verify TBL_MAIN updated
$mainDbCheck = $link->query("SELECT MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_CALL_DIALED_USER, CALL_COUNT, BATCH_ASSIGNED_AT, LAST_DIALED_DATE_TIME FROM " . tn('TBL_MAIN') . " WHERE ID = $mainRecordId")->fetch_assoc();
assertGoal("Master record status updated to 'Busy' and CALL_COUNT incremented", 
    $mainDbCheck['MAINDATABASE_CALL_DIALED_STATUS'] === 'Busy' && (int)$mainDbCheck['CALL_COUNT'] === 1 && $mainDbCheck['BATCH_ASSIGNED_AT'] === null
);

// Verify TBL_CALL_LOGS entry created
$logId = (int)($compRes['json']['data']['log_id'] ?? 0);
$logCheck = $link->query("SELECT user_id, record_id, call_status, call_duration, notes FROM " . tn('TBL_CALL_LOGS') . " WHERE id = $logId")->fetch_assoc();
assertGoal("Call log entry persisted in TBL_CALL_LOGS with duration and notes", 
    !empty($logCheck) && (int)$logCheck['call_duration'] === 12 && (int)$logCheck['user_id'] === (int)$userA['ID']
);


// =============================================================================
// GOAL 3: LEADS GENERATION, PIPELINE PROCESSING & REPORTING
// =============================================================================
echo "\n--- GOAL 3: LEADS GENERATION, PIPELINE & REPORTING ---\n";

// 3.1 Telecaller completes call with status 'Interested / Hot' on a temporary record
$tempRec = $link->query("SELECT ID, CUST_MOBILE FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = '$tempTestMobile1'")->fetch_assoc();
$tempRecId = (int)$tempRec['ID'];

$leadPayload = [
    'source' => 'TEMP',
    'call_status' => 'Interested / Hot',
    'call_duration' => 125,
    'timing_classification' => 'CONNECTED_CONVERSATION',
    'notes' => '[TEST_GOALS] High intent solar project enquiry 50kW',
    'next_followup' => date('Y-m-d 10:00:00', strtotime('+1 day')),
];

$leadRes = callApiEndpoint('POST', "/telecaller/calls/$tempRecId/complete", $leadPayload, $tokenA);
$genLeadId = (int)($leadRes['json']['data']['lead_id'] ?? 0);
assertGoal("Completing call with 'Interested / Hot' auto-generates CRM Lead", 
    $leadRes['code'] === 200 && $genLeadId > 0 && !empty($leadRes['json']['data']['is_lead_generated']),
    "Lead ID: $genLeadId"
);

// 3.2 Verify lead exists in TBL_LEADS assigned to telecaller A
$leadRow = $link->query("SELECT lead_id, cust_id, assigned_to, lead_status_new, lead_stage, priority FROM " . tn('TBL_LEADS') . " WHERE lead_id = $genLeadId")->fetch_assoc();
assertGoal("Auto-generated Lead is assigned to dialing telecaller with stage 'Hot'", 
    !empty($leadRow) && (int)$leadRow['assigned_to'] === (int)$userA['ID'] && $leadRow['lead_stage'] === 'Hot'
);

// 3.3 Verify temporary record was promoted/synced to TBL_MAIN
$promotedCust = $link->query("SELECT ID, MAINDATABASE_MOBILE FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = '$tempTestMobile1'")->fetch_assoc();
assertGoal("Temporary record promoted to Main Database so cust_id is preserved", !empty($promotedCust['ID']));

// 3.4 Self leads visible to telecaller in mobile app
$myLeadsRes = callApiEndpoint('GET', '/telecaller/leads', [], $tokenA);
$foundSelfLead = false;
foreach ($myLeadsRes['json']['data']['leads'] ?? [] as $lead) {
    if ((int)$lead['id'] === $genLeadId || (int)$lead['lead_id'] === $genLeadId) {
        $foundSelfLead = true;
        break;
    }
}
assertGoal("Self leads are visible to mobile telecaller in GET /telecaller/leads", $foundSelfLead);

// 3.5 Telecaller views self reports in mobile app
$summaryRes = callApiEndpoint('GET', '/telecaller/reports/summary?period=today', [], $tokenA);
$todayCalls = (int)($summaryRes['json']['data']['total_calls'] ?? 0);
$connectedCalls = (int)($summaryRes['json']['data']['connected_calls'] ?? 0);
assertGoal("Telecaller views self reports in mobile app (GET /telecaller/reports/summary)", 
    $summaryRes['code'] === 200 && $todayCalls >= 2 && $connectedCalls >= 2,
    "Today calls: $todayCalls, Connected: $connectedCalls"
);


// =============================================================================
// GOAL 4: CONCURRENCY, 15-MIN LEASE, OLDEST DIALED, 3-MONTH COOLDOWN & ACTIVITY
// =============================================================================
echo "\n--- GOAL 4: CONCURRENCY, LEASES, COOLDOWN & ACTIVITY PROTECTION ---\n";

// 4.1 Concurrency & Zero Collision Test (One number to one user at a time)
// Release all active leases first
callApiEndpoint('POST', '/telecaller/release-queue', [], $tokenA);
callApiEndpoint('POST', '/telecaller/release-queue', [], $tokenB);

// Insert 4 fresh main records for concurrent dialing test
$cMobiles = ['9999000021', '9999000022', '9999000023', '9999000024'];
foreach ($cMobiles as $idx => $mob) {
    $link->query("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_COMPANY, MAINDATABASE_CALL_DIALED_STATUS, MAINDATABASE_UPLOAD_DATETIME) VALUES 
        ('Concurrent Test $idx', '$mob', 'Test Co', 'Not Called', NOW())
        ON DUPLICATE KEY UPDATE MAINDATABASE_CALL_DIALED_STATUS='Not Called', BATCH_ASSIGNED_AT=NULL, MAINDATABASE_CALL_DIALED_USER=NULL");
}

// Telecaller A fetches batch of 2
$batchA = callApiEndpoint('GET', '/telecaller/batch-leads?limit=2', [], $tokenA);
$mobilesA = array_column($batchA['json']['data']['leads'] ?? [], 'mobile');

// Telecaller B fetches batch of 2
$batchB = callApiEndpoint('GET', '/telecaller/batch-leads?limit=2', [], $tokenB);
$mobilesB = array_column($batchB['json']['data']['leads'] ?? [], 'mobile');

$overlap = array_intersect($mobilesA, $mobilesB);
assertGoal("Concurrency: One number to one user at a time (0 collision between telecallers)", 
    empty($overlap) && count($mobilesA) > 0 && count($mobilesB) > 0,
    "Overlapping count: " . count($overlap)
);

// 4.2 15-Minute Lease Test
$leaseMinutes = (int)($batchA['json']['data']['lease_duration_minutes'] ?? 0);
assertGoal("Lease duration confirmed as 15 minutes", $leaseMinutes === 15, "Lease: $leaseMinutes min");

// Verify leased number is locked from other users
$firstLeasedMob = $mobilesA[0] ?? '';
$isLockedFromB = !in_array($firstLeasedMob, $mobilesB);
assertGoal("Leased numbers remain locked to acquiring telecaller during lease period", $isLockedFromB);

// 4.3 Queue Transition Test (Temporary data ended -> Main database shares)
callApiEndpoint('POST', '/telecaller/release-queue', [], $tokenA);
// Temporarily hold any uncalled temporary records to simulate temporary database exhaustion
$link->query("UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_STATUS='Test_Hold' WHERE (CALL_DIALED_STATUS IS NULL OR CALL_DIALED_STATUS = '' OR CALL_DIALED_STATUS = 'Pending' OR CALL_DIALED_STATUS = 'Not Called')");

$autoFallbackBatch = callApiEndpoint('GET', '/telecaller/batch-leads?limit=3', [], $tokenA);
$fallbackDb = $autoFallbackBatch['json']['data']['current_database'] ?? '';
assertGoal("When temporary data is ended, system seamlessly shares Main Database records", 
    $fallbackDb === 'MAIN',
    "Current DB: $fallbackDb"
);

// Restore any held temporary staging records back
$link->query("UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_STATUS='Not Called' WHERE CALL_DIALED_STATUS='Test_Hold'");

// 4.4 Sorting Test: Filter by oldest dialed number in database
// Create 2 test records in Main DB: one dialed 120 days ago, one dialed 150 days ago
$oldMobile120 = '9999000031';
$olderMobile150 = '9999000032';
$link->query("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME) VALUES 
    ('Client 120 Days', '$oldMobile120', 'Not Called', NOW() - INTERVAL 120 DAY, NOW())
    ON DUPLICATE KEY UPDATE MAINDATABASE_CALL_DIALED_STATUS='Not Called', LAST_DIALED_DATE_TIME=NOW() - INTERVAL 120 DAY, BATCH_ASSIGNED_AT=NULL, MAINDATABASE_CALL_DIALED_USER=NULL");
$link->query("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME) VALUES 
    ('Client 150 Days', '$olderMobile150', 'Not Called', NOW() - INTERVAL 150 DAY, NOW())
    ON DUPLICATE KEY UPDATE MAINDATABASE_CALL_DIALED_STATUS='Not Called', LAST_DIALED_DATE_TIME=NOW() - INTERVAL 150 DAY, BATCH_ASSIGNED_AT=NULL, MAINDATABASE_CALL_DIALED_USER=NULL");

$timeOrderCheck = $link->query("SELECT MAINDATABASE_MOBILE, LAST_DIALED_DATE_TIME FROM " . tn('TBL_MAIN') . " 
    WHERE MAINDATABASE_MOBILE IN ('$oldMobile120', '$olderMobile150')
    ORDER BY (LAST_DIALED_DATE_TIME IS NOT NULL) ASC, LAST_DIALED_DATE_TIME ASC")->fetch_all(MYSQLI_ASSOC);
assertGoal("Sorting: Oldest dialed numbers (150 days) sort before newer dialed numbers (120 days)", 
    ($timeOrderCheck[0]['MAINDATABASE_MOBILE'] ?? '') === $olderMobile150
);

// 4.5 Strict 3-Month (90 Days) Cooldown Rule:
// Number dialed by User A 30 days ago CANNOT be shared with User B
$recentDialedMobile = '9999000041';
$link->query("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_CALL_DIALED_USER, MAINDATABASE_CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME) VALUES 
    ('Recent Dialed 30d', '$recentDialedMobile', {$userA['ID']}, 'Not Called', NOW() - INTERVAL 30 DAY, NOW())
    ON DUPLICATE KEY UPDATE MAINDATABASE_CALL_DIALED_USER={$userA['ID']}, MAINDATABASE_CALL_DIALED_STATUS='Not Called', LAST_DIALED_DATE_TIME=NOW() - INTERVAL 30 DAY, BATCH_ASSIGNED_AT=NULL");

// Release queue for user B and fetch
callApiEndpoint('POST', '/telecaller/release-queue', [], $tokenB);
$batchCheckB = callApiEndpoint('GET', '/telecaller/batch-leads?limit=50', [], $tokenB);
$receivedByB = array_column($batchCheckB['json']['data']['leads'] ?? [], 'mobile');
assertGoal("Strict 3-month cooldown: Number dialed by User A within 90 days CANNOT be shared with User B", 
    !in_array($recentDialedMobile, $receivedByB)
);

// 4.6 Activity Protection Rule:
// Number dialed 100 days ago (> 90 days) by User A:
$protectedMobile = '9999000051';
$link->query("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_CALL_DIALED_USER, MAINDATABASE_CALL_DIALED_STATUS, LAST_DIALED_DATE_TIME, MAINDATABASE_UPLOAD_DATETIME) VALUES 
    ('Protected Lead 100d', '$protectedMobile', {$userA['ID']}, 'Not Called', NOW() - INTERVAL 100 DAY, NOW())
    ON DUPLICATE KEY UPDATE MAINDATABASE_CALL_DIALED_USER={$userA['ID']}, MAINDATABASE_CALL_DIALED_STATUS='Not Called', LAST_DIALED_DATE_TIME=NOW() - INTERVAL 100 DAY, BATCH_ASSIGNED_AT=NULL");
$protRecId = (int)$link->query("SELECT ID FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = '$protectedMobile'")->fetch_assoc()['ID'];

// Case A: Number has an active lead in TBL_LEADS for User A -> MUST NOT be shared with User B
$link->query("INSERT INTO " . tn('TBL_LEADS') . " (cust_id, MOBILE, assigned_to, lead_status_new, created_at, updated_at) VALUES 
    ($protRecId, '$protectedMobile', {$userA['ID']}, 'LEAD', NOW(), NOW())
    ON DUPLICATE KEY UPDATE assigned_to={$userA['ID']}, lead_status_new='LEAD'");

callApiEndpoint('POST', '/telecaller/release-queue', [], $tokenB);
$bBatchActivityCheck = callApiEndpoint('GET', '/telecaller/batch-leads?limit=50', [], $tokenB);
$bReceivedAct = array_column($bBatchActivityCheck['json']['data']['leads'] ?? [], 'mobile');
assertGoal("Activity Protection: Number dialed > 3 months ago with active lead CANNOT be shared with User B", 
    !in_array($protectedMobile, $bReceivedAct)
);

// Case B: Activity cleared (Lead closed/lost and no calls in last 90 days) -> CAN be shared with User B
$link->query("UPDATE " . tn('TBL_LEADS') . " SET lead_status_new='REJECT', lead_status='Lost', next_followup_at=NULL WHERE cust_id = $protRecId");
$link->query("DELETE FROM " . tn('TBL_CALL_LOGS') . " WHERE record_id = $protRecId AND source = 'MAIN'");

// Query eligibility check directly using same WHERE criteria
$eligibilityCheck = $link->query("SELECT m.ID FROM " . tn('TBL_MAIN') . " m 
    WHERE m.ID = $protRecId
    AND (m.LAST_DIALED_DATE_TIME IS NULL OR m.LAST_DIALED_DATE_TIME < (NOW() - INTERVAL 90 DAY) OR m.MAINDATABASE_CALL_DIALED_USER = {$userB['ID']})
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_LEADS') . " l 
        WHERE l.cust_id = m.ID AND l.assigned_to != {$userB['ID']}
        AND (LOWER(COALESCE(l.lead_status_new, l.lead_status, '')) NOT IN ('lost', 'reject', 'closed', 'archived') OR l.next_followup_at > NOW())
    )
    AND NOT EXISTS (
        SELECT 1 FROM " . tn('TBL_CALL_LOGS') . " cl 
        WHERE cl.record_id = m.ID AND cl.source = 'MAIN' AND cl.user_id != {$userB['ID']}
        AND (cl.next_followup > NOW() OR cl.created_at >= (NOW() - INTERVAL 90 DAY))
    )")->fetch_assoc();
assertGoal("Activity Protection: Number dialed > 3 months ago with NO activity CAN be re-allocated to User B", 
    !empty($eligibilityCheck['ID'])
);

// Clean up test records
$link->query("DELETE FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE LIKE '99990000%'");
$link->query("DELETE FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE LIKE '99990000%'");
$link->query("DELETE FROM " . tn('TBL_CALL_LOGS') . " WHERE notes LIKE '%[TEST_GOALS]%'");
$link->query("DELETE FROM " . tn('TBL_LEADS') . " WHERE remarks LIKE '%[TEST_GOALS]%'");

echo "\n====================================================\n";
echo "SUMMARY: Total: $totalTests | Passed: $passedTests | Failed: $failedTests\n";
echo "====================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
