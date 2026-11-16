<?php
/**
 * Automated Test Suite: Telecaller Engagement Lean Engine (XP, Levels, Streaks, Daily Rollup)
 * Run via: & "C:\xampp\php\php.exe" tests/test_lean_engagement.php
 */

require_once __DIR__ . '/../php_scripts/config.php';
require_once __DIR__ . '/../php_scripts/reporting_schema.php';
require_once __DIR__ . '/../php_scripts/engagement_engine.php';

global $link;
if (!$link) {
    die("❌ Fatal: No database connection available.\n");
}

$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$desc}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$desc}\n";
        $failed++;
    }
}

echo "=== Telecaller Engagement Lean Engine Test Suite ===\n\n";

// 1. Schema check
echo "1. Testing Schema Initialization...\n";
ensureEngagementSchema($link);
$checkTable = $link->query("SHOW TABLES LIKE '" . tn('TBL_TELECALLER_ENGAGEMENT') . "'");
assertTest("Table telecaller_engagement exists", $checkTable && $checkTable->num_rows === 1);

// 2. Caller Mastery Rank Thresholds
echo "\n2. Testing Caller Mastery Ranks & Titles...\n";
$r1 = getCallerMasteryRank(0);
assertTest("0 XP -> Level 1 (Bronze Caller 🥉)", $r1['level'] === 1 && str_contains($r1['title'], 'Bronze Caller'));

$r2 = getCallerMasteryRank(1000);
assertTest("1,000 XP -> Level 2 (Silver Caller 🥈)", $r2['level'] === 2 && str_contains($r2['title'], 'Silver Caller'));

$r3 = getCallerMasteryRank(5000);
assertTest("5,000 XP -> Level 3 (Gold Caller 🥇)", $r3['level'] === 3 && str_contains($r3['title'], 'Gold Caller'));

$r4 = getCallerMasteryRank(15000);
assertTest("15,000 XP -> Level 4 (Platinum Caller 💎)", $r4['level'] === 4 && str_contains($r4['title'], 'Platinum Caller'));

$r5 = getCallerMasteryRank(30000);
assertTest("30,000 XP -> Level 5 (Diamond Champion 👑)", $r5['level'] === 5 && str_contains($r5['title'], 'Diamond Champion'));

// 3. XP Calculation on Calls
echo "\n3. Testing Call XP Awards...\n";
$testUserId = 999999;
$today = date('Y-m-d');
$link->query("DELETE FROM " . tn('TBL_TELECALLER_ENGAGEMENT') . " WHERE user_id = {$testUserId}");

// Dial Attempt (0s) -> +5 XP
$res1 = recordCallEngagement($link, $testUserId, 0, 'NOT_CONNECTED');
assertTest("Dial Attempt awards +5 XP", $res1['xp_earned'] === 5 && $res1['total_xp'] === 5);

// Connected Call (15s) -> +20 XP
$res2 = recordCallEngagement($link, $testUserId, 15, 'CONNECTED_SHORT');
assertTest("Connected call (15s) awards +20 XP", $res2['call_xp'] === 20 && $res2['total_xp'] === 25);

// Quality Conversation (45s) -> +50 XP
$res3 = recordCallEngagement($link, $testUserId, 45, 'CONNECTED_CONVERSATION');
assertTest("Quality conversation (45s) awards +50 XP", $res3['call_xp'] === 50 && $res3['total_xp'] === 75);

// 4. Target Bonus & Deduplication
echo "\n4. Testing Target Bonus (+100 XP) and Deduplication...\n";
// Update daily record so connected calls is 29 out of 30
$link->query("UPDATE " . tn('TBL_TELECALLER_ENGAGEMENT') . " SET connected_calls = 29, daily_target = 30 WHERE user_id = {$testUserId} AND record_type = 'daily' AND event_date = '{$today}'");

// Call 30: Connects -> triggers target bonus
$resTarget = recordCallEngagement($link, $testUserId, 25, 'CONNECTED_CONVERSATION');
assertTest("Hitting target awards +100 XP bonus (+50 call XP = 150)", $resTarget['target_bonus_xp'] === 100 && $resTarget['target_met'] === true);

// Next call: Connects again (31st connect) -> should NOT award bonus again
$resNext = recordCallEngagement($link, $testUserId, 25, 'CONNECTED_CONVERSATION');
assertTest("Next call does not re-award +100 XP bonus", $resNext['target_bonus_xp'] === 0 && $resNext['call_xp'] === 50);

// 5. Streak Calculation
echo "\n5. Testing Target Streaks...\n";
// Backfill 3 consecutive days prior to today with target met
$d1 = date('Y-m-d', strtotime('-1 day'));
$d2 = date('Y-m-d', strtotime('-2 day'));
$d3 = date('Y-m-d', strtotime('-3 day'));
$d4 = date('Y-m-d', strtotime('-4 day'));

$link->query("INSERT INTO " . tn('TBL_TELECALLER_ENGAGEMENT') . " (user_id, record_type, event_date, calls_dialed, connected_calls, daily_target, created_at, updated_at) VALUES 
({$testUserId}, 'daily', '{$d1}', 50, 30, 30, NOW(), NOW()),
({$testUserId}, 'daily', '{$d2}', 60, 35, 30, NOW(), NOW()),
({$testUserId}, 'daily', '{$d3}', 70, 32, 30, NOW(), NOW()),
({$testUserId}, 'daily', '{$d4}', 40, 15, 30, NOW(), NOW())"); // Missed on day 4

$streak = getTelecallerStreak($link, $testUserId, $today);
assertTest("Calculates 3-day consecutive target streak correctly", $streak === 3);

// 6. Testing Leaderboard Rankings & Scores Integration
echo "\n6. Testing Reporting Engine calculateProductivityRankings...\n";
require_once __DIR__ . '/../php_scripts/reporting_engine.php';
$sampleEmpRows = [
    [
        'employee_id' => $testUserId,
        'employee_name' => 'Test Telecaller',
        'team_name' => 'Alpha Team',
        'total_dials' => 85,
        'connected' => 28,
        'unique_called' => 70,
        'interested' => 5,
        'total_duration' => 3600,
        'fo_created' => 6,
        'fo_completed' => 5,
        'converted_leads' => 2,
        'disbursed_loans' => 1,
        'disbursed_amount' => 250000.0,
    ]
];
$targets = ['daily_call_target' => 100, 'daily_connected_target' => 30, 'daily_talk_time_minutes' => 120, 'daily_lead_target' => 3];
$rankedOutput = calculateProductivityRankings($sampleEmpRows, $targets, $link);
assertTest("calculateProductivityRankings returns non-empty array", !empty($rankedOutput));
assertTest("calculateProductivityRankings has calling_score", isset($rankedOutput[0]['calling_score']));
assertTest("calculateProductivityRankings has level and level_title", isset($rankedOutput[0]['level']) && isset($rankedOutput[0]['level_title']));
assertTest("calculateProductivityRankings has streak_days", isset($rankedOutput[0]['streak_days']));

// Clean up test user
$link->query("DELETE FROM " . tn('TBL_TELECALLER_ENGAGEMENT') . " WHERE user_id = {$testUserId}");

echo "\n===================================================\n";
echo "Results: {$passed} Passed, {$failed} Failed\n";
echo "===================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
