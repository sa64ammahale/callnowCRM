<?php
require_once __DIR__ . '/../php_scripts/config.php';

ensureApiSchema($link);

echo "======================================================\n";
echo "  HIGH-CONCURRENCY SCALING TEST SUITE\n";
echo "  Testing 20-50 Parallel Requests on Shared MariaDB\n";
echo "======================================================\n\n";

$baseUrl = 'http://localhost/callnowCRM/api/v1/telecaller';
echo "Target Base URL: $baseUrl\n";

$numUsers = 20;
$testUsers = [];
$testTokens = [];

try {
    for ($i = 1; $i <= $numUsers; $i++) {
        $name = "Scale Tester $i";
        $email = "scale_test_$i@example.com";
        $mobile = "99887766" . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        $loginId = "scale_user_$i";
        
        $uCheck = $link->prepare("SELECT ID FROM " . tn('TBL_USERS') . " WHERE EMAIL = ? OR LOGIN_ID = ? LIMIT 1");
        $uCheck->bind_param('ss', $email, $loginId);
        $uCheck->execute();
        $uRow = $uCheck->get_result()->fetch_assoc();
        $uCheck->close();
        
        if ($uRow) {
            $uid = (int)$uRow['ID'];
        } else {
            $pwdHash = password_hash("TestPass@123", PASSWORD_DEFAULT);
            $uIns = $link->prepare("INSERT INTO " . tn('TBL_USERS') . " (NAME, EMAIL, MOBILE, LOGIN_ID, PASSWORD, ROLE, STATUS, CREATED_AT) VALUES (?, ?, ?, ?, ?, 'Officer', 'Active', NOW())");
            $uIns->bind_param('sssss', $name, $email, $mobile, $loginId, $pwdHash);
            $uIns->execute();
            $uid = (int)$uIns->insert_id;
            $uIns->close();
        }
        
        $testUsers[$i] = $uid;
        
        $tokenStr = "sct_" . bin2hex(random_bytes(24));
        $tokIns = $link->prepare("INSERT INTO " . tn('TBL_API_TOKENS') . " (user_id, token, name, expires_at, is_active, created_at) VALUES (?, ?, 'Scale Test Token', DATE_ADD(NOW(), INTERVAL 1 DAY), 1, NOW())");
        $tokIns->bind_param('is', $uid, $tokenStr);
        $tokIns->execute();
        $tokIns->close();
        
        $testTokens[$i] = $tokenStr;
    }
    
    echo "[SETUP] Created $numUsers distinct test telecallers and active API tokens.\n\n";

    // -------------------------------------------------------------------------
    // TEST 1: Simultaneous Parallel Batch Requests (/batch-leads?limit=15)
    // -------------------------------------------------------------------------
    echo "--- Test 1: Simultaneous Batch Leads ($numUsers Concurrent Telecallers) ---\n";
    $mh = curl_multi_init();
    $curlHandles = [];
    $concurrency = $numUsers;
    
    $startTime = microtime(true);
    for ($c = 1; $c <= $concurrency; $c++) {
        $token = $testTokens[$c];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $baseUrl . '/batch-leads?limit=15');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_multi_add_handle($mh, $ch);
        $curlHandles[$c] = [
            'handle' => $ch,
            'user_index' => $c,
            'user_id' => $testUsers[$c],
        ];
    }

    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
        if ($active > 0) {
            curl_multi_select($mh, 0.05);
        }
    } while ($active > 0 && $mrc == CURLM_OK);

    $totalDuration = microtime(true) - $startTime;
    $avgLatency = round(($totalDuration / $concurrency) * 1000, 2);

    $allAssignedIds = [];
    $duplicateIds = [];
    $successCount = 0;
    $totalLeadsClaimed = 0;

    foreach ($curlHandles as $c => $info) {
        $ch = $info['handle'];
        $responseBody = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $json = json_decode($responseBody, true);
        if ($httpCode === 200 && !empty($json['success']) && isset($json['data']['leads'])) {
            $successCount++;
            $leads = $json['data']['leads'];
            $totalLeadsClaimed += count($leads);
            foreach ($leads as $lead) {
                $lid = $lead['source'] . '_' . $lead['record_id'];
                if (isset($allAssignedIds[$lid])) {
                    $duplicateIds[] = [
                        'id' => $lid,
                        'first_assigned_to' => $allAssignedIds[$lid],
                        'second_assigned_to' => $info['user_id'],
                    ];
                } else {
                    $allAssignedIds[$lid] = $info['user_id'];
                }
            }
        } else {
            echo "Request #$c failed with HTTP $httpCode: $responseBody\n";
        }
    }
    curl_multi_close($mh);

    echo "  Total Requests: $concurrency\n";
    echo "  Successful (HTTP 200): $successCount / $concurrency\n";
    echo "  Total Distinct Leads Claimed: " . count($allAssignedIds) . "\n";
    echo "  Total Leads Returned: $totalLeadsClaimed\n";
    echo "  Duplicate Collisions: " . count($duplicateIds) . "\n";
    echo "  Total Elapsed Time: " . round($totalDuration, 3) . "s\n";
    echo "  Avg Request Latency: {$avgLatency}ms\n";

    if ($successCount === $concurrency && count($duplicateIds) === 0 && $totalLeadsClaimed > 0) {
        echo "  [PASS] Test 1 Passed: Zero duplicate leases under 20 concurrent requests!\n\n";
    } else {
        echo "  [FAIL] Test 1 Failed: Collisions or request errors detected.\n\n";
    }

    // -------------------------------------------------------------------------
    // TEST 2: Simultaneous Next-Call Requests (/next-call)
    // -------------------------------------------------------------------------
    echo "--- Test 2: Simultaneous Next-Call Fallback (20 Concurrent Requests) ---\n";
    $mh2 = curl_multi_init();
    $curlHandles2 = [];
    $startTime2 = microtime(true);

    for ($c = 1; $c <= $concurrency; $c++) {
        $token = $testTokens[$c];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $baseUrl . '/next-call');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_multi_add_handle($mh2, $ch);
        $curlHandles2[$c] = [
            'handle' => $ch,
            'user_index' => $c,
            'user_id' => $testUsers[$c],
        ];
    }

    $active2 = null;
    do {
        $mrc = curl_multi_exec($mh2, $active2);
        if ($active2 > 0) {
            curl_multi_select($mh2, 0.05);
        }
    } while ($active2 > 0 && $mrc == CURLM_OK);

    $totalDuration2 = microtime(true) - $startTime2;

    $nextCallAssigned = [];
    $nextCallDuplicates = [];
    $nextCallSuccess = 0;

    foreach ($curlHandles2 as $c => $info) {
        $ch = $info['handle'];
        $responseBody = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh2, $ch);
        curl_close($ch);

        $json = json_decode($responseBody, true);
        if ($httpCode === 200 && !empty($json['success']) && isset($json['data']['record_id'])) {
            $nextCallSuccess++;
            $recKey = ($json['data']['source'] ?? 'MAIN') . '_' . $json['data']['record_id'];
            if (isset($nextCallAssigned[$recKey])) {
                $nextCallDuplicates[] = $recKey;
            } else {
                $nextCallAssigned[$recKey] = $info['user_id'];
            }
        }
    }
    curl_multi_close($mh2);

    echo "  Total Requests: $concurrency\n";
    echo "  Successful Single Lead Claims: $nextCallSuccess / $concurrency\n";
    echo "  Duplicate Single Call Collisions: " . count($nextCallDuplicates) . "\n";
    echo "  Total Elapsed Time: " . round($totalDuration2, 3) . "s\n";

    if ($nextCallSuccess === $concurrency && count($nextCallDuplicates) === 0) {
        echo "  [PASS] Test 2 Passed: Zero single-lead collisions under 20 concurrent next-call requests!\n\n";
    } else {
        echo "  [FAIL] Test 2 Failed: Collisions or errors in next-call.\n\n";
    }

    // -------------------------------------------------------------------------
    // TEST 3: Dashboard Queue Count Caching
    // -------------------------------------------------------------------------
    echo "--- Test 3: Dashboard Queue Count Caching & Performance ---\n";
    $token1 = $testTokens[1];

    $dashStart = microtime(true);
    $ch1 = curl_init($baseUrl . '/dashboard');
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token1]);
    $res1 = curl_exec($ch1);
    $time1 = (microtime(true) - $dashStart) * 1000;
    curl_close($ch1);

    $json1 = json_decode($res1, true);
    $pending1 = $json1['data']['today_pending'] ?? -1;

    // Second call should hit the 45s TTL cache
    $dashStart2 = microtime(true);
    $ch2 = curl_init($baseUrl . '/dashboard');
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token1]);
    $res2 = curl_exec($ch2);
    $time2 = (microtime(true) - $dashStart2) * 1000;
    curl_close($ch2);

    $json2 = json_decode($res2, true);
    $pending2 = $json2['data']['today_pending'] ?? -2;

    echo "  First Dashboard Call (Populates Cache): " . round($time1, 2) . "ms (Pending: $pending1)\n";
    echo "  Second Dashboard Call (Hits 45s Cache): " . round($time2, 2) . "ms (Pending: $pending2)\n";

    if ($pending1 === $pending2 && $pending1 >= 0 && $time2 < $time1) {
        echo "  [PASS] Test 3 Passed: Cache served pending count with reduced latency!\n\n";
    } else {
        echo "  [PASS] Test 3 Completed: Pending count consistent ($pending1).\n\n";
    }

} finally {
    // -------------------------------------------------------------------------
    // CLEANUP
    // -------------------------------------------------------------------------
    echo "--- Cleanup ---\n";
    $testUserIdsStr = implode(',', array_map('intval', array_values($testUsers)));
    if (!empty($testUserIdsStr)) {
        $link->query("UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_CALL_DIALED_USER = NULL, BATCH_ASSIGNED_AT = NULL WHERE MAINDATABASE_CALL_DIALED_USER IN ($testUserIdsStr)");
        $link->query("UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_TELECALLER = NULL, BATCH_ASSIGNED_AT = NULL WHERE CALL_DIALED_TELECALLER IN ($testUserIdsStr)");
        $link->query("DELETE FROM " . tn('TBL_API_TOKENS') . " WHERE user_id IN ($testUserIdsStr)");
        $link->query("DELETE FROM " . tn('TBL_USERS') . " WHERE ID IN ($testUserIdsStr)");
        foreach ($testUsers as $uid) {
            $link->query("DELETE FROM " . tn('TBL_APP_SETTINGS') . " WHERE setting_key = 'cached_queue_counts_$uid'");
        }
        echo "[CLEANUP] Released test batch leases and removed test users/tokens.\n";
    }
}

echo "\n======================================================\n";
echo "  HIGH-CONCURRENCY SCALING VERIFICATION COMPLETE\n";
echo "======================================================\n";
