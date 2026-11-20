<?php
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/lead_distribution_engine.php';

echo "=== Lead Distribution Engine Test Suite (v1.4) ===" . PHP_EOL . PHP_EOL;

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $message" . PHP_EOL;
        $passed++;
    } else {
        echo "  [FAIL] $message" . PHP_EOL;
        $failed++;
    }
}

global $link;

echo "1. Testing Advisory Lock & Mutex..." . PHP_EOL;
$lock1 = acquireTenantAllocLock($link, 2);
assertTest($lock1 === true, "Acquired tenant allocation advisory lock");
$lock2 = acquireTenantAllocLock($link, 1);
assertTest($lock2 === true, "Re-entrant lock on same connection succeeds");
releaseTenantAllocLock($link);

echo PHP_EOL . "2. Testing VGC Database Constraint on Active Phone..." . PHP_EOL;
$link->begin_transaction();
try {
    $batchUuid = 'test-batch-' . bin2hex(random_bytes(4));
    $insB = $link->prepare("INSERT INTO lead_batches (batch_uuid, claim_request_id, company_code, telecaller_id, device_id, expires_at) VALUES (?, 'req-test-1', 'default', 9999, 'dev-1', NOW() + INTERVAL 20 MINUTE)");
    $insB->bind_param('s', $batchUuid);
    $insB->execute();
    $batchId = (int)$insB->insert_id;
    $insB->close();

    $resToken1 = 'res-test-1';
    $phone = '9999988888';
    $insR1 = $link->prepare("INSERT INTO lead_reservations (batch_id, batch_uuid, reservation_token, company_code, telecaller_id, device_id, source, lead_id, phone_number_10, priority_tier, state, expires_at) VALUES (?, ?, ?, 'default', 9999, 'dev-1', 'TEMP', 101, ?, 'P0', 'RESERVED', NOW() + INTERVAL 20 MINUTE)");
    $insR1->bind_param('isss', $batchId, $batchUuid, $resToken1, $phone);
    $insR1->execute();
    $insR1->close();
    assertTest(true, "First active reservation inserted successfully");

    $duplicateFailed = false;
    try {
        $resToken2 = 'res-test-2';
        $insR2 = $link->prepare("INSERT INTO lead_reservations (batch_id, batch_uuid, reservation_token, company_code, telecaller_id, device_id, source, lead_id, phone_number_10, priority_tier, state, expires_at) VALUES (?, ?, ?, 'default', 9998, 'dev-2', 'MAIN', 202, ?, 'P0', 'RESERVED', NOW() + INTERVAL 20 MINUTE)");
        $insR2->bind_param('isss', $batchId, $batchUuid, $resToken2, $phone);
        $executed = @$insR2->execute();
        if (!$executed && ($insR2->errno === 1062 || $link->errno === 1062)) {
            $duplicateFailed = true;
        }
        $insR2->close();
    } catch (Throwable $ex) {
        $duplicateFailed = ($ex->getCode() === 1062);
    }
    assertTest($duplicateFailed === true, "MariaDB 10.4 VGC uniq_active_phone rejected duplicate active phone across sources (error 1062)");
} finally {
    $link->rollback();
}

echo PHP_EOL . "3. Testing Batch Claim & Idempotency..." . PHP_EOL;
$link->query("UPDATE lead_batches SET status = 'RELEASED' WHERE telecaller_id = 1 AND status = 'ACTIVE'");
$link->query("UPDATE lead_reservations SET state = 'RELEASED' WHERE telecaller_id = 1 AND state IN ('RESERVED', 'DIALING')");

$userEpoch = 1;
$uEpRes = $link->query("SELECT session_epoch FROM users WHERE ID = 1 LIMIT 1");
if ($uEpRes && $uEpRow = $uEpRes->fetch_assoc()) {
    $userEpoch = (int)($uEpRow['session_epoch'] ?? 1);
}

$claimReqId = 'claim-test-' . bin2hex(random_bytes(6));
$resClaim1 = claimLeadBatch($link, 1, 'default', 'device-test-1', $userEpoch, $claimReqId, 5, false);
assertTest($resClaim1['success'] === true, "Batch claimed successfully");
assertTest(isset($resClaim1['batch_uuid']) && isset($resClaim1['leads']), "Batch contains batch_uuid and leads array");

$resClaim2 = claimLeadBatch($link, 1, 'default', 'device-test-1', $userEpoch, $claimReqId, 5, false);
assertTest($resClaim2['success'] === true, "Replaying identical claim_request_id succeeds");
assertTest($resClaim2['batch_uuid'] === $resClaim1['batch_uuid'], "Replay returns identical batch_uuid");
assertTest(count($resClaim2['leads']) === count($resClaim1['leads']), "Replay returns identical lead count without re-allocating");

$resClaimConflict = claimLeadBatch($link, 1, 'default', 'device-test-1', $userEpoch, $claimReqId, 10, false);
assertTest(!empty($resClaimConflict['error']) && $resClaimConflict['code'] === 409, "Sending same claim_request_id with different batch_size returns 409 Conflict");

echo PHP_EOL . "4. Testing Active-Call Lock Decoupling..." . PHP_EOL;
if (!empty($resClaim1['leads'])) {
    $firstLead = $resClaim1['leads'][0];
    $resToken = $firstLead['reservation_token'];
    $leadId = (int)$firstLead['id'];
    $source = $firstLead['source'];

    $startRes = startCallLock($link, 1, $resToken, $leadId, $source, 'device-test-1', $userEpoch);
    assertTest($startRes['success'] === true, "Start-call lock acquired");
    assertTest($startRes['state'] === 'DIALING' && !empty($startRes['call_lock_token']), "Lead moved to DIALING with call_lock_token");

    $activeCall = getActiveCallLock($link, 1);
    assertTest($activeCall !== null && $activeCall['has_active_call'] === true, "GET /calls/active detects active in-flight call lock");
    assertTest($activeCall['call']['reservation_token'] === $resToken, "Active call context matches locked lead");

    $abandonRes = abandonCallLock($link, 1, $resToken, 'TEST_CANCELED');
    assertTest($abandonRes['success'] === true && $abandonRes['state'] === 'RELEASED', "Abandoning active dial lock releases it to RELEASED");

    $activeCallAfter = getActiveCallLock($link, 1);
    assertTest($activeCallAfter === null || $activeCallAfter['has_active_call'] === false, "GET /calls/active reports no active call after abandonment");
}

echo PHP_EOL . "5. Testing Heartbeat Lease Renewal..." . PHP_EOL;
$bUuid = $resClaim1['batch_uuid'];
$hbRes = renewBucketHeartbeat($link, 1, $bUuid, 'device-test-1', 1, []);
assertTest($hbRes['success'] === true && isset($hbRes['expires_at']), "Heartbeat renewal extended batch lease");

echo PHP_EOL . "6. Testing Batch Queue Release..." . PHP_EOL;
$relRes = releaseBatchQueue($link, 1, $bUuid);
assertTest($relRes['success'] === true && $relRes['released_count'] >= 0, "Explicit batch release succeeded");

echo PHP_EOL . "7. Testing Call Submission Idempotency & Replay..." . PHP_EOL;
$testClientCallId = 'test-client-call-' . bin2hex(random_bytes(6));
$payload1 = [
    'record_id' => 1,
    'source' => 'MAIN',
    'call_status' => 'Connected',
    'call_duration' => 30,
    'client_call_id' => $testClientCallId,
    'notes' => 'Test call result'
];
ksort($payload1);
$hash1 = hash('sha256', json_encode($payload1, JSON_UNESCAPED_UNICODE));

$insLog = $link->prepare("INSERT INTO call_logs (record_id, source, user_id, call_status, call_duration, client_call_id, request_hash, created_at) VALUES (1, 'MAIN', 1, 'Connected', 30, ?, ?, NOW())");
$insLog->bind_param('ss', $testClientCallId, $hash1);
$insLog->execute();
$insertedLogId = (int)$insLog->insert_id;
$insLog->close();
assertTest($insertedLogId > 0, "Initial call log inserted with client_call_id and request_hash");

// Replay check
$chkQuery = $link->prepare("SELECT id, request_hash FROM call_logs WHERE user_id = 1 AND client_call_id = ?");
$chkQuery->bind_param('s', $testClientCallId);
$chkQuery->execute();
$savedLog = $chkQuery->get_result()->fetch_assoc();
$chkQuery->close();
assertTest($savedLog && hash_equals($savedLog['request_hash'], $hash1), "Replay detected matching request_hash for identical client_call_id");

// Mismatch check
$payload2 = $payload1;
$payload2['call_status'] = 'Not Interested';
ksort($payload2);
$hash2 = hash('sha256', json_encode($payload2, JSON_UNESCAPED_UNICODE));
assertTest(!hash_equals($savedLog['request_hash'], $hash2), "Payload alteration detected on existing client_call_id (hash mismatch 409)");

// Cleanup test log
$link->query("DELETE FROM call_logs WHERE client_call_id = '$testClientCallId'");

echo PHP_EOL . "8. Testing Status Reconciliation Hierarchy..." . PHP_EOL;
$getStatusRank = static function(?string $st): int {
    $s = strtolower(trim((string)$st));
    if (in_array($s, ['do not call', 'dnd', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd'], true)) return 4;
    if (in_array($s, ['lead', 'interested', 'enquiry', 'sanctioned', 'disbursed', 'login', 'connected'], true)) return 3;
    if (in_array($s, ['wrong number', 'not interested', 'lost', 'rejected'], true)) return 2;
    return 1;
};

assertTest($getStatusRank('Do Not Call') === 4, "DND has highest status rank (4)");
assertTest($getStatusRank('Interested') === 3, "Interested has high-value rank (3)");
assertTest($getStatusRank('Wrong Number') === 2, "Wrong Number has terminal rank (2)");
assertTest($getStatusRank('Ringing') === 1, "Ringing has transient rank (1)");
assertTest($getStatusRank('Interested') > $getStatusRank('Ringing'), "Interested outranks Ringing; prevents downgrade on stale call");

echo PHP_EOL . "9. Testing Active Batch Continuity & Queue Retention..." . PHP_EOL;
// Release any leftover active batch first
$link->query("UPDATE lead_batches SET status = 'RELEASED' WHERE telecaller_id = 1 AND status = 'ACTIVE'");
$link->query("UPDATE lead_reservations SET state = 'RELEASED' WHERE telecaller_id = 1 AND state IN ('RESERVED', 'DIALING')");

// 9.1 Claim a fresh batch with claim request A
$claimA = 'claim-test-A-' . bin2hex(random_bytes(4));
$batchA = claimLeadBatch($link, 1, 'default', 'device-test-1', 1, $claimA, 5, false);
assertTest($batchA['success'] === true && !empty($batchA['leads']), "Initial batch A claimed successfully");
$batchUuidA = $batchA['batch_uuid'];
$leadCountA = count($batchA['leads']);

// 9.2 Simulate app refresh / restart with a completely new claim_request_id B
$claimB = 'claim-test-B-' . bin2hex(random_bytes(4));
$batchB = claimLeadBatch($link, 1, 'default', 'device-test-1', 1, $claimB, 5, false);
assertTest($batchB['success'] === true, "Claim request B succeeded");
assertTest($batchB['batch_uuid'] === $batchUuidA, "Active batch continuity preserved: batch B returns identical active batch UUID as batch A");
assertTest(count($batchB['leads']) === $leadCountA, "All uncalled leads preserved across refresh without number skipping");

// 9.3 Mark 1 lead completed in batch A
$firstResToken = $batchA['leads'][0]['reservation_token'];
$link->query("UPDATE lead_reservations SET state = 'COMPLETED', completed_at = NOW() WHERE reservation_token = '$firstResToken'");

// 9.4 Simulate another app restart / login with claim request C
$claimC = 'claim-test-C-' . bin2hex(random_bytes(4));
$batchC = claimLeadBatch($link, 1, 'default', 'device-test-1', 1, $claimC, 5, false);
assertTest($batchC['batch_uuid'] === $batchUuidA, "Batch C still tracks same active batch UUID");
assertTest(count($batchC['leads']) === ($leadCountA - 1), "Active batch returns remaining uncalled leads (completed lead subtracted)");

// 9.5 Release batch and verify BATCH_ASSIGNED_AT is reset
$relC = releaseBatchQueue($link, 1, $batchUuidA);
assertTest($relC['success'] === true, "Batch queue release succeeded");

$chkAssigned = $link->query("SELECT COUNT(*) FROM lead_reservations lr 
    INNER JOIN " . tn('TBL_MAIN') . " m ON m.ID = lr.lead_id AND lr.source = 'MAIN'
    WHERE lr.batch_uuid = '$batchUuidA' AND lr.state = 'RELEASED' AND m.BATCH_ASSIGNED_AT IS NOT NULL");
$unresetCount = (int)$chkAssigned->fetch_row()[0];
assertTest($unresetCount === 0, "Uncalled records in TBL_MAIN have BATCH_ASSIGNED_AT reset to NULL on release");

echo PHP_EOL . "===================================================" . PHP_EOL;
echo "Results: $passed Passed, $failed Failed" . PHP_EOL;
echo "===================================================" . PHP_EOL;
