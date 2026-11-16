<?php
/**
 * Comprehensive End-to-End Verification Suite for Telecaller Engagement & Motivation Engine
 * Run via: & "C:\xampp\php\php.exe" tests/test_full_engagement_suite.php
 */

@ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/reporting_schema.php';
require_once __DIR__ . '/../php_scripts/engagement_engine.php';
require_once __DIR__ . '/../php_scripts/reporting_engine.php';

global $link;
if (!$link) {
    die("❌ Fatal: Database connection unavailable.\n");
}

$passed = 0;
$failed = 0;

function assertSuite(string $name, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name}" . ($detail ? " — {$detail}" : "") . "\n";
        $failed++;
    }
}

echo "=================================================================\n";
echo " TELECALLER ENGAGEMENT COMPREHENSIVE END-TO-END TEST SUITE\n";
echo "=================================================================\n\n";

// -------------------------------------------------------------
// SECTION 1: Database Table & Schema Validation
// -------------------------------------------------------------
echo "SECTION 1: Database Schema Validation\n";
ensureEngagementSchema($link);
$table = tn('TBL_TELECALLER_ENGAGEMENT');

$tCheck = $link->query("SHOW TABLES LIKE '{$table}'");
assertSuite("Table `{$table}` exists in database", $tCheck && $tCheck->num_rows === 1);

// Verify columns
$colsExpected = ['id', 'user_id', 'record_type', 'event_date', 'calls_dialed', 'connected_calls', 'talk_time_seconds', 'daily_target', 'xp_earned', 'total_xp', 'level', 'streak_count', 'notes', 'created_at', 'updated_at'];
$colRes = $link->query("SHOW COLUMNS FROM `{$table}`");
$existingCols = [];
while ($c = $colRes->fetch_assoc()) {
    $existingCols[] = $c['Field'];
}
$missing = array_diff($colsExpected, $existingCols);
assertSuite("All expected columns exist in `{$table}`", empty($missing), "Missing: " . implode(', ', $missing));

// Verify indexes
$idxRes = $link->query("SHOW INDEX FROM `{$table}`");
$indices = [];
while ($idx = $idxRes->fetch_assoc()) {
    $indices[$idx['Key_name']] = true;
}
assertSuite("Index `idx_user_date` exists", isset($indices['idx_user_date']));
assertSuite("Index `idx_user_type_date` exists", isset($indices['idx_user_type_date']));


// -------------------------------------------------------------
// SECTION 2: Caller Mastery Progression & XP Rules
// -------------------------------------------------------------
echo "\nSECTION 2: Caller Mastery Progression & Level Math\n";
$m1 = getCallerMasteryRank(0);
assertSuite("Level 1: 0 XP is Bronze Caller 🥉", $m1['level'] === 1 && str_contains($m1['title'], 'Bronze Caller'));

$m1_edge = getCallerMasteryRank(999);
assertSuite("Level 1 Edge: 999 XP is Bronze Caller 🥉", $m1_edge['level'] === 1);

$m2 = getCallerMasteryRank(1000);
assertSuite("Level 2: 1,000 XP is Silver Caller 🥈", $m2['level'] === 2 && str_contains($m2['title'], 'Silver Caller'));

$m3 = getCallerMasteryRank(5000);
assertSuite("Level 3: 5,000 XP is Gold Caller 🥇", $m3['level'] === 3 && str_contains($m3['title'], 'Gold Caller'));

$m4 = getCallerMasteryRank(15000);
assertSuite("Level 4: 15,000 XP is Platinum Caller 💎", $m4['level'] === 4 && str_contains($m4['title'], 'Platinum Caller'));

$m5 = getCallerMasteryRank(30000);
assertSuite("Level 5: 30,000 XP is Diamond Champion 👑", $m5['level'] === 5 && str_contains($m5['title'], 'Diamond Champion'));


// -------------------------------------------------------------
// SECTION 3: Live XP Awards, Target Bonus & Streak Logic
// -------------------------------------------------------------
echo "\nSECTION 3: Live Call XP Awards & Daily Rollup Tracking\n";
$testUid = 888123;
$link->query("DELETE FROM `{$table}` WHERE user_id = {$testUid}");

// Call 1: Attempt (0 sec duration)
$c1 = recordCallEngagement($link, $testUid, 0, 'NOT_CONNECTED');
assertSuite("Call 1: Attempt awards +5 XP", $c1['xp_earned'] === 5 && $c1['total_xp'] === 5);
assertSuite("Call 1: Level is Bronze Caller 🥉", $c1['level'] === 1);

// Call 2: Connected Short (15 sec duration)
$c2 = recordCallEngagement($link, $testUid, 15, 'CONNECTED_SHORT');
assertSuite("Call 2: Connected awards +20 XP", $c2['call_xp'] === 20 && $c2['total_xp'] === 25);

// Call 3: Quality Conversation (40 sec duration)
$c3 = recordCallEngagement($link, $testUid, 40, 'CONNECTED_CONVERSATION');
assertSuite("Call 3: Quality conversation awards +50 XP", $c3['call_xp'] === 50 && $c3['total_xp'] === 75);

// Verify database state for today's daily record
$today = date('Y-m-d');
$dRow = $link->query("SELECT * FROM `{$table}` WHERE user_id = {$testUid} AND record_type = 'daily' AND event_date = '{$today}'")->fetch_assoc();
assertSuite("Daily rollup row created with 3 dials", (int)$dRow['calls_dialed'] === 3);
assertSuite("Daily rollup row has 2 connected calls", (int)$dRow['connected_calls'] === 2);
assertSuite("Daily rollup row accumulated talk time (15s + 40s = 55s)", (int)$dRow['talk_time_seconds'] === 55);

// Simulate Target Achievement (+100 XP Bonus)
// Set connected calls to 29 out of 30 target
$link->query("UPDATE `{$table}` SET connected_calls = 29, daily_target = 30 WHERE id = " . (int)$dRow['id']);

$cTarget = recordCallEngagement($link, $testUid, 30, 'CONNECTED_CONVERSATION');
assertSuite("Call 30: Hitting daily target awards +100 bonus (+50 call = 150)", $cTarget['target_bonus_xp'] === 100 && $cTarget['xp_earned'] === 150);
assertSuite("Call 30: target_met flag is true", $cTarget['target_met'] === true);

// Verify idempotency: next call on same day should NOT award +100 XP again
$cExtra = recordCallEngagement($link, $testUid, 30, 'CONNECTED_CONVERSATION');
assertSuite("Call 31: Next call does NOT double-award bonus (+50 call only)", $cExtra['target_bonus_xp'] === 0 && $cExtra['xp_earned'] === 50);


// -------------------------------------------------------------
// SECTION 4: Multi-Day Streak Calculation
// -------------------------------------------------------------
echo "\nSECTION 4: Multi-Day Streak Calculation\n";
// Backfill 4 consecutive previous days with target met
$d1 = date('Y-m-d', strtotime('-1 day'));
$d2 = date('Y-m-d', strtotime('-2 day'));
$d3 = date('Y-m-d', strtotime('-3 day'));
$d4 = date('Y-m-d', strtotime('-4 day'));
$d5 = date('Y-m-d', strtotime('-5 day')); // target not met

$link->query("INSERT INTO `{$table}` (user_id, record_type, event_date, calls_dialed, connected_calls, daily_target, created_at, updated_at) VALUES 
({$testUid}, 'daily', '{$d1}', 60, 32, 30, NOW(), NOW()),
({$testUid}, 'daily', '{$d2}', 55, 30, 30, NOW(), NOW()),
({$testUid}, 'daily', '{$d3}', 70, 35, 30, NOW(), NOW()),
({$testUid}, 'daily', '{$d4}', 65, 31, 30, NOW(), NOW()),
({$testUid}, 'daily', '{$d5}', 40, 10, 30, NOW(), NOW())");

$streakCalc = getTelecallerStreak($link, $testUid, $today);
assertSuite("Calculates 4-day consecutive streak correctly", $streakCalc === 4);

$summary = getTelecallerEngagementSummary($link, $testUid);
assertSuite("Engagement summary includes streak_days >= 4", $summary['streak_days'] >= 4);
assertSuite("Engagement summary includes level_title", !empty($summary['level_title']));
assertSuite("Engagement summary includes total_xp > 0", $summary['total_xp'] > 0);


// -------------------------------------------------------------
// SECTION 5: Reporting Engine Leaderboard Integration
// -------------------------------------------------------------
echo "\nSECTION 5: Reporting Engine Leaderboard Integration\n";
$dummyStaff = [
    [
        'employee_id' => $testUid,
        'employee_name' => 'John Doe Telecaller',
        'team_name' => 'Credit Squad',
        'total_dials' => 120,
        'connected' => 35,
        'unique_called' => 100,
        'interested' => 6,
        'total_duration' => 4200,
        'fo_created' => 8,
        'fo_completed' => 7,
        'converted_leads' => 3,
        'disbursed_loans' => 2,
        'disbursed_amount' => 500000.0,
        'connect_rate' => 29.2,
    ]
];
$targets = ['daily_call_target' => 100, 'daily_connected_target' => 30, 'daily_talk_time_minutes' => 120, 'daily_lead_target' => 3];

$rankings = calculateProductivityRankings($dummyStaff, $targets, $link);
assertSuite("Rankings returned 1 record", count($rankings) === 1);
assertSuite("Ranking row contains calling_score", isset($rankings[0]['calling_score']) && $rankings[0]['calling_score'] > 0);
assertSuite("Ranking row contains sales_score", isset($rankings[0]['sales_score']) && $rankings[0]['sales_score'] > 0);
assertSuite("Ranking row contains level and level_title", isset($rankings[0]['level']) && !empty($rankings[0]['level_title']));
assertSuite("Ranking row contains streak_days", isset($rankings[0]['streak_days']));
assertSuite("Ranking row contains total_xp", isset($rankings[0]['total_xp']));


// -------------------------------------------------------------
// SECTION 6: API Replay & Idempotency Check
// -------------------------------------------------------------
echo "\nSECTION 6: API Replay & Response Structure Validation\n";
// Clean up test user
$link->query("DELETE FROM `{$table}` WHERE user_id = {$testUid}");

// Test calling recordCallEngagement directly with simulated API inputs
$apiSim = recordCallEngagement($link, $testUid, 45, 'CONNECTED_CONVERSATION', false);
assertSuite("API engagement response has 'xp_earned'", isset($apiSim['xp_earned']));
assertSuite("API engagement response has 'call_xp'", isset($apiSim['call_xp']));
assertSuite("API engagement response has 'target_bonus_xp'", isset($apiSim['target_bonus_xp']));
assertSuite("API engagement response has 'total_xp'", isset($apiSim['total_xp']));
assertSuite("API engagement response has 'level'", isset($apiSim['level']));
assertSuite("API engagement response has 'level_title'", isset($apiSim['level_title']));
assertSuite("API engagement response has 'streak_days'", isset($apiSim['streak_days']));
assertSuite("API engagement response has 'connected_today'", isset($apiSim['connected_today']));
assertSuite("API engagement response has 'target_today'", isset($apiSim['target_today']));
assertSuite("API engagement response has 'target_met'", isset($apiSim['target_met']));

// Final cleanup
$link->query("DELETE FROM `{$table}` WHERE user_id = {$testUid}");

echo "\n=================================================================\n";
echo " TEST SUITE SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
