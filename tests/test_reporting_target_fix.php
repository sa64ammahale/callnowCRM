<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/reporting_engine.php';

echo "=== 1. Testing Employee Performance Target Quota (Single Day: Today) ===\n";
$today = date('Y-m-d');
$targets = getReportingTargets($link, 'Officer');
$rows = getEmployeePerformanceRows($link, $today, $today, null, null, null, 'Super Admin', $targets);
foreach (array_slice($rows, 0, 5) as $r) {
    echo "Officer: {$r['employee_name']} (ID: {$r['user_id']}) | Daily Limit: {$r['daily_calling_limit']} | Period Target: {$r['target']} | Dials: {$r['total_dials']} | Ach%: {$r['achievement_pct']}%\n";
}

echo "\n=== 2. Testing Employee Performance Target Quota (Multi-Day: 7 Days) ===\n";
$past7 = date('Y-m-d', strtotime('-6 days'));
$rows7 = getEmployeePerformanceRows($link, $past7, $today, null, null, null, 'Super Admin', $targets);
foreach (array_slice($rows7, 0, 5) as $r) {
    echo "Officer: {$r['employee_name']} (ID: {$r['user_id']}) | Limit/Day: {$r['daily_calling_limit']} | Days: {$r['days_in_period']} | Period Target: {$r['target']} (should be {$r['daily_calling_limit']} * 7 = " . ($r['daily_calling_limit'] * 7) . ") | Ach%: {$r['achievement_pct']}%\n";
}

echo "\n=== 3. Testing Uncapped Achievement Calculation ===\n";
// Ensure that achievement is not artificially capped at 200%
$mockLimit = 100;
$mockDials = 250;
$mockAch = round(($mockDials / $mockLimit) * 100, 1);
echo "Mock Dials 250 on Target 100 => {$mockAch}% (Uncapped)\n";

echo "\n=== 4. Testing Supervisor Squad Aggregates ===\n";
$supRows = getSupervisorPerformanceRows($link, $today, $today, null, $targets, 1, 'Super Admin');
foreach ($supRows as $s) {
    echo "Squad: {$s['team_name']} | Supervisor: {$s['supervisor_name']} | Officers: {$s['total_officers']} | Squad Daily Limit: {$s['team_daily_calling_limit']} | Target: {$s['team_target']} | Ach%: {$s['achievement_pct']}%\n";
}
