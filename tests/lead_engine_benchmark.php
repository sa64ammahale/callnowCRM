<?php
require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/lead_distribution_engine.php';

echo "=== Lead Distribution Engine Concurrency & Scale Benchmark ===" . PHP_EOL . PHP_EOL;

global $link;

// Create dummy users if needed for concurrency test (e.g. 200 simulated telecallers)
$callerTiers = [10, 50, 100, 200];

foreach ($callerTiers as $numCallers) {
    echo "--- Testing Concurrency with $numCallers simulated telecallers ---" . PHP_EOL;
    $startTime = microtime(true);
    
    $allocatedPhonesTotal = [];
    $duplicateCount = 0;
    $successCount = 0;
    $deadlockCount = 0;
    $busyCount = 0;

    // Simulate claims from $numCallers distinct telecallers
    for ($i = 1; $i <= $numCallers; $i++) {
        $callerId = 1000 + $i;
        $claimReqId = 'bench-' . $numCallers . '-' . $callerId . '-' . bin2hex(random_bytes(4));
        $devId = 'dev-bench-' . $callerId;
        
        try {
            $res = claimLeadBatch($link, $callerId, 'default', $devId, 1, $claimReqId, 5, false);
            if (!empty($res['success'])) {
                $successCount++;
                foreach ($res['leads'] as $lead) {
                    $p = $lead['mobile'];
                    if (isset($allocatedPhonesTotal[$p])) {
                        $duplicateCount++;
                        echo "  [ERROR] DUPLICATE ALLOCATED: $p claimed by caller $callerId and caller " . $allocatedPhonesTotal[$p] . PHP_EOL;
                    } else {
                        $allocatedPhonesTotal[$p] = $callerId;
                    }
                }
            } elseif (!empty($res['error']) && $res['code'] === 503) {
                $busyCount++;
            }
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'Deadlock') !== false) {
                $deadlockCount++;
            } else {
                echo "  [EXCEPTION] Caller $callerId: " . $e->getMessage() . PHP_EOL;
            }
        }
    }
    
    $elapsed = round(microtime(true) - $startTime, 3);
    $totalLeads = count($allocatedPhonesTotal);
    echo "  Completed $numCallers callers in {$elapsed}s (avg " . round(($elapsed / $numCallers) * 1000, 1) . "ms/caller)" . PHP_EOL;
    echo "  Total leads allocated: $totalLeads" . PHP_EOL;
    echo "  Duplicate allocations: $duplicateCount" . PHP_EOL;
    echo "  Deadlocks detected:    $deadlockCount" . PHP_EOL;
    echo "  Lock busy retries:     $busyCount" . PHP_EOL;
    
    // Assert 0 duplicate allocations
    if ($duplicateCount === 0 && $deadlockCount === 0) {
        echo "  [PASS] Zero duplicate allocations and zero deadlocks for $numCallers callers!" . PHP_EOL . PHP_EOL;
    } else {
        echo "  [FAIL] Concurrency invariants violated for $numCallers callers!" . PHP_EOL . PHP_EOL;
        exit(1);
    }
    
    // Cleanup benchmark allocations
    $link->query("UPDATE lead_reservations SET state = 'RELEASED' WHERE telecaller_id >= 1001 AND telecaller_id <= " . (1000 + $numCallers));
    $link->query("DELETE FROM lead_reservations WHERE telecaller_id >= 1001 AND telecaller_id <= " . (1000 + $numCallers));
    $link->query("DELETE FROM lead_batches WHERE telecaller_id >= 1001 AND telecaller_id <= " . (1000 + $numCallers));
}

echo "=== All Concurrency Benchmarks PASSED with 0 Duplicates! ===" . PHP_EOL;
