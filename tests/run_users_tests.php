<?php
/**
 * Master test runner for CallNow CRM Users Section
 */

// Start session before any output
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__.'/../php_scripts/config.php';

$testScripts = [
    'test_users_view.php' => 'User Listing & Filtering (users_view.php)',
    'test_users_add_comprehensive.php' => 'User Creation & Editing (users_add.php)',
    'test_teams_dashboard.php' => 'Team Management (teams_dashboard.php)',
    'test_team_members.php' => 'Member Assignment & Supervisor (team_members.php)',
    'test_users_rbac_boundary.php' => 'RBAC Permission Boundaries',
];

$totalPassed = 0;
$totalFailed = 0;
$totalTests = 0;
$results = [];

echo str_repeat('=', 70) . "\n";
echo "CALLNOW CRM — USERS SECTION TEST SUITE\n";
echo str_repeat('=', 70) . "\n\n";

foreach ($testScripts as $script => $label) {
    $scriptPath = __DIR__ . '/' . $script;
    if (!file_exists($scriptPath)) {
        echo "[SKIP] {$label} — script not found\n";
        continue;
    }

    echo str_repeat('-', 70) . "\n";
    echo "Running: {$label}\n";
    echo str_repeat('-', 70) . "\n";

    ob_start();
    include $scriptPath;
    $output = ob_get_clean();

    if (preg_match('/TEST SUMMARY: (\d+)\/(\d+) passed, (\d+) failed/', $output, $m)) {
        $passed = (int)$m[1];
        $count = (int)$m[2];
        $failed = (int)$m[3];
        $totalPassed += $passed;
        $totalFailed += $failed;
        $totalTests += $count;
        $results[$script] = ['passed' => $passed, 'failed' => $failed, 'total' => $count];
        echo "\n>>> {$label}: {$passed}/{$count} passed, {$failed} failed\n";
    } else {
        echo "\n>>> {$label}: no summary found\n";
        $results[$script] = ['passed' => 0, 'failed' => 0, 'total' => 0, 'error' => 'no summary'];
    }
    echo "\n";
}

echo str_repeat('=', 70) . "\n";
echo "CONSOLIDATED RESULTS\n";
echo str_repeat('=', 70) . "\n";
printf("%-50s %10s %10s %10s\n", 'Test Suite', 'Passed', 'Failed', 'Total');
echo str_repeat('-', 70) . "\n";
foreach ($results as $script => $r) {
    printf("%-50s %10d %10d %10d\n", $script, $r['passed'] ?? 0, $r['failed'] ?? 0, $r['total'] ?? 0);
}
echo str_repeat('-', 70) . "\n";
printf("%-50s %10d %10d %10d\n", 'TOTAL', $totalPassed, $totalFailed, $totalTests);
echo str_repeat('=', 70) . "\n";

$overallPassRate = $totalTests > 0 ? round(($totalPassed / $totalTests) * 100, 1) : 0;
echo "Overall Pass Rate: {$overallPassRate}%\n";
echo $totalFailed > 0 ? "RECOMMENDATION: Review and fix failing tests before production.\n" : "All tests passed. Users section is functionally sound.\n";
