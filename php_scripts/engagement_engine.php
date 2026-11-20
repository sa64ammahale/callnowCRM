<?php
/**
 * Telecaller Engagement Engine — Lean MVP
 * Handles real-time XP calculation, Caller Mastery Levels, Target Streaks, and Daily Rollups.
 */

if (!defined('APP_VERSION')) {
    require_once __DIR__ . '/config.php';
}

/**
 * Ensures telecaller_engagement table exists.
 */
function ensureEngagementSchema(mysqli $link): void {
    static $ensured = false;
    if ($ensured) return;

    $table = tn('TBL_TELECALLER_ENGAGEMENT');
    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `record_type` ENUM('daily', 'xp') NOT NULL,
        `event_date` DATE NOT NULL,
        `calls_dialed` INT DEFAULT 0,
        `connected_calls` INT DEFAULT 0,
        `talk_time_seconds` INT DEFAULT 0,
        `daily_target` INT DEFAULT 0,
        `xp_earned` INT DEFAULT 0,
        `total_xp` INT DEFAULT 0,
        `level` INT DEFAULT 1,
        `streak_count` INT DEFAULT 0,
        `notes` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        INDEX `idx_user_date` (`user_id`, `event_date`),
        INDEX `idx_user_type_date` (`user_id`, `record_type`, `event_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    @$link->query($sql);
    $ensured = true;
}

/**
 * Caller Mastery Rank & Titles
 * Level 1: Bronze Caller 🥉 (0 – 999 XP)
 * Level 2: Silver Caller 🥈 (1,000 – 4,999 XP)
 * Level 3: Gold Caller 🥇 (5,000 – 14,999 XP)
 * Level 4: Platinum Caller 💎 (15,000 – 29,999 XP)
 * Level 5: Diamond Champion 👑 (30,000+ XP)
 */
function getCallerMasteryRank(int $totalXp): array {
    if ($totalXp >= 30000) {
        return ['level' => 5, 'title' => 'Diamond Champion 👑', 'badge_theme' => 'info', 'next_xp' => null];
    }
    if ($totalXp >= 15000) {
        return ['level' => 4, 'title' => 'Platinum Caller 💎', 'badge_theme' => 'primary', 'next_xp' => 30000];
    }
    if ($totalXp >= 5000) {
        return ['level' => 3, 'title' => 'Gold Caller 🥇', 'badge_theme' => 'warning', 'next_xp' => 15000];
    }
    if ($totalXp >= 1000) {
        return ['level' => 2, 'title' => 'Silver Caller 🥈', 'badge_theme' => 'secondary', 'next_xp' => 5000];
    }
    return ['level' => 1, 'title' => 'Bronze Caller 🥉', 'badge_theme' => 'dark', 'next_xp' => 1000];
}

/**
 * Calculate historical consecutive-day target streak prior to today
 */
function getTelecallerStreak(mysqli $link, int $userId, ?string $todayDate = null): int {
    ensureEngagementSchema($link);
    $table = tn('TBL_TELECALLER_ENGAGEMENT');
    $today = $todayDate ?: date('Y-m-d');

    $stmt = $link->prepare("
        SELECT `event_date`, `connected_calls`, `daily_target` 
        FROM `{$table}` 
        WHERE `user_id` = ? AND `record_type` = 'daily' AND `event_date` < ? 
        ORDER BY `event_date` DESC 
        LIMIT 60
    ");
    if (!$stmt) return 0;

    $stmt->bind_param('is', $userId, $today);
    $stmt->execute();
    $res = $stmt->get_result();

    $streak = 0;
    $expectedDate = new DateTime($today);
    $expectedDate->modify('-1 day');

    while ($row = $res->fetch_assoc()) {
        $rowDate = $row['event_date'];
        $connected = (int)$row['connected_calls'];
        $target = max(1, (int)$row['daily_target']);

        if ($rowDate !== $expectedDate->format('Y-m-d')) {
            break; // Missed a calendar day
        }

        if ($connected >= $target) {
            $streak++;
            $expectedDate->modify('-1 day');
        } else {
            break; // Failed to hit target on that day
        }
    }
    $stmt->close();

    return $streak;
}

/**
 * Records engagement for a single call action and calculates instant XP.
 */
function recordCallEngagement(
    mysqli $link,
    int $userId,
    int $durationSeconds,
    string $timingClassification = '',
    bool $isDnd = false
): array {
    ensureEngagementSchema($link);
    $table = tn('TBL_TELECALLER_ENGAGEMENT');
    $today = date('Y-m-d');

    $isConversation = ($durationSeconds > 20 || strtoupper($timingClassification) === 'CONNECTED_CONVERSATION');
    $isConnected = ($durationSeconds > 0 || in_array(strtoupper($timingClassification), ['CONNECTED_SHORT', 'CONNECTED']) || $isConversation);

    // 1. Calculate Call XP
    if ($isConversation) {
        $callXp = 50;
        $callNote = 'Quality conversation (>20s)';
    } elseif ($isConnected) {
        $callXp = 20;
        $callNote = 'Connected call';
    } else {
        $callXp = 5;
        $callNote = 'Call attempted';
    }

    // 2. Fetch today's daily record
    $qDaily = $link->prepare("
        SELECT `id`, `calls_dialed`, `connected_calls`, `talk_time_seconds`, `daily_target`, `xp_earned` 
        FROM `{$table}` 
        WHERE `user_id` = ? AND `record_type` = 'daily' AND `event_date` = ? 
        LIMIT 1
    ");
    $qDaily->bind_param('is', $userId, $today);
    $qDaily->execute();
    $dailyRow = $qDaily->get_result()->fetch_assoc();
    $qDaily->close();

    $prevConnected = $dailyRow ? (int)$dailyRow['connected_calls'] : 0;
    $prevDials = $dailyRow ? (int)$dailyRow['calls_dialed'] : 0;
    $prevTalk = $dailyRow ? (int)$dailyRow['talk_time_seconds'] : 0;
    $prevXp = $dailyRow ? (int)$dailyRow['xp_earned'] : 0;
    $dailyId = $dailyRow ? (int)$dailyRow['id'] : null;

    // Resolve daily connected target (preserve existing today's target or resolve default 30)
    if ($dailyRow && (int)$dailyRow['daily_target'] > 0) {
        $dailyConnectedTarget = (int)$dailyRow['daily_target'];
    } else {
        $targetRes = $link->query("SELECT `daily_calling_limit` FROM " . tn('TBL_USERS') . " WHERE `ID` = {$userId} LIMIT 1");
        $targetRow = $targetRes ? $targetRes->fetch_assoc() : [];
        $uLimit = isset($targetRow['daily_calling_limit']) ? (int)$targetRow['daily_calling_limit'] : 0;
        $dailyConnectedTarget = ($uLimit > 0 && $uLimit <= 50) ? $uLimit : 30;
    }
    $dailyId = $dailyRow ? (int)$dailyRow['id'] : null;

    $newDials = $prevDials + 1;
    $newConnected = $prevConnected + ($isConnected ? 1 : 0);
    $newTalk = $prevTalk + max(0, $durationSeconds);

    // 4. Check if Daily Target Met for the first time today
    $wasTargetMet = ($prevConnected >= $dailyConnectedTarget);
    $isTargetMetNow = ($newConnected >= $dailyConnectedTarget);
    $targetBonusXp = (!$wasTargetMet && $isTargetMetNow) ? 100 : 0;

    $totalAwardXp = $callXp + $targetBonusXp;

    // 5. Calculate prior lifetime XP from XP ledger
    $qLifetime = $link->prepare("SELECT COALESCE(SUM(`xp_earned`), 0) as `total` FROM `{$table}` WHERE `user_id` = ? AND `record_type` = 'xp'");
    $qLifetime->bind_param('i', $userId);
    $qLifetime->execute();
    $lifeRow = $qLifetime->get_result()->fetch_assoc();
    $qLifetime->close();

    $priorTotalXp = (int)($lifeRow['total'] ?? 0);
    $newTotalXp = $priorTotalXp + $totalAwardXp;

    // 6. Rank & Streak
    $rankInfo = getCallerMasteryRank($newTotalXp);
    $pastStreak = getTelecallerStreak($link, $userId, $today);
    $effectiveStreak = $isTargetMetNow ? ($pastStreak + 1) : $pastStreak;

    // 7. Upsert today's Daily Rollup record
    $now = date('Y-m-d H:i:s');
    if ($dailyId) {
        $upDaily = $link->prepare("
            UPDATE `{$table}` 
            SET `calls_dialed` = ?, 
                `connected_calls` = ?, 
                `talk_time_seconds` = ?, 
                `daily_target` = ?, 
                `xp_earned` = `xp_earned` + ?, 
                `total_xp` = ?, 
                `level` = ?, 
                `streak_count` = ?, 
                `updated_at` = ? 
            WHERE `id` = ?
        ");
        $upDaily->bind_param('iiiiiiiisi', $newDials, $newConnected, $newTalk, $dailyConnectedTarget, $totalAwardXp, $newTotalXp, $rankInfo['level'], $effectiveStreak, $now, $dailyId);
        $upDaily->execute();
        $upDaily->close();
    } else {
        $insDaily = $link->prepare("
            INSERT INTO `{$table}` 
                (`user_id`, `record_type`, `event_date`, `calls_dialed`, `connected_calls`, `talk_time_seconds`, `daily_target`, `xp_earned`, `total_xp`, `level`, `streak_count`, `notes`, `created_at`, `updated_at`) 
            VALUES (?, 'daily', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Today Rollup', ?, ?)
        ");
        $insDaily->bind_param('isiiiiiiiiss', $userId, $today, $newDials, $newConnected, $newTalk, $dailyConnectedTarget, $totalAwardXp, $newTotalXp, $rankInfo['level'], $effectiveStreak, $now, $now);
        $insDaily->execute();
        $insDaily->close();
    }

    // 8. Insert XP Ledger entry for call
    $insXp = $link->prepare("
        INSERT INTO `{$table}` 
            (`user_id`, `record_type`, `event_date`, `xp_earned`, `total_xp`, `level`, `streak_count`, `notes`, `created_at`, `updated_at`) 
        VALUES (?, 'xp', ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insXp->bind_param('isiiiisss', $userId, $today, $callXp, $newTotalXp, $rankInfo['level'], $effectiveStreak, $callNote, $now, $now);
    $insXp->execute();
    $insXp->close();

    // 9. If target hit bonus was unlocked, insert distinct XP ledger entry
    if ($targetBonusXp > 0) {
        $targetNote = 'Daily target completed! (+100 XP Bonus)';
        $insBonus = $link->prepare("
            INSERT INTO `{$table}` 
                (`user_id`, `record_type`, `event_date`, `xp_earned`, `total_xp`, `level`, `streak_count`, `notes`, `created_at`, `updated_at`) 
            VALUES (?, 'xp', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insBonus->bind_param('isiiiisss', $userId, $today, $targetBonusXp, $newTotalXp, $rankInfo['level'], $effectiveStreak, $targetNote, $now, $now);
        $insBonus->execute();
        $insBonus->close();
    }

    return [
        'xp_earned' => $totalAwardXp,
        'daily_xp' => ($dailyRow ? (int)$dailyRow['xp_earned'] : 0) + $totalAwardXp,
        'call_xp' => $callXp,
        'target_bonus_xp' => $targetBonusXp,
        'total_xp' => $newTotalXp,
        'level' => $rankInfo['level'],
        'level_title' => $rankInfo['title'],
        'badge_theme' => $rankInfo['badge_theme'],
        'streak_days' => $effectiveStreak,
        'connected_today' => $newConnected,
        'target_today' => $dailyConnectedTarget,
        'target_met' => $isTargetMetNow,
    ];
}

/**
 * Fast lookup of telecaller's current engagement state.
 */
function getTelecallerEngagementSummary(mysqli $link, int $userId): array {
    ensureEngagementSchema($link);
    $table = tn('TBL_TELECALLER_ENGAGEMENT');
    $today = date('Y-m-d');

    // Total lifetime XP
    $qLife = $link->prepare("SELECT COALESCE(SUM(`xp_earned`), 0) as `tot` FROM `{$table}` WHERE `user_id` = ? AND `record_type` = 'xp'");
    $qLife->bind_param('i', $userId);
    $qLife->execute();
    $lifeRow = $qLife->get_result()->fetch_assoc();
    $qLife->close();
    $totalXp = (int)($lifeRow['tot'] ?? 0);

    $qTodayXp = $link->prepare("SELECT COALESCE(SUM(`xp_earned`), 0) as `today_tot` FROM `{$table}` WHERE `user_id` = ? AND `record_type` = 'xp' AND `event_date` = ?");
    $qTodayXp->bind_param('is', $userId, $today);
    $qTodayXp->execute();
    $todayXpRow = $qTodayXp->get_result()->fetch_assoc();
    $qTodayXp->close();
    $dailyXp = (int)($todayXpRow['today_tot'] ?? 0);

    $qD = $link->prepare("SELECT `calls_dialed`, `connected_calls`, `daily_target`, `streak_count`, `xp_earned` FROM `{$table}` WHERE `user_id` = ? AND `record_type` = 'daily' AND `event_date` = ? LIMIT 1");
    $qD->bind_param('is', $userId, $today);
    $qD->execute();
    $dRow = $qD->get_result()->fetch_assoc();
    $qD->close();

    if ($dailyXp === 0 && $dRow && isset($dRow['xp_earned']) && (int)$dRow['xp_earned'] > 0) {
        $dailyXp = (int)$dRow['xp_earned'];
    }

    $connectedToday = $dRow ? (int)$dRow['connected_calls'] : 0;
    $dialsToday = $dRow ? (int)$dRow['calls_dialed'] : 0;

    $targetRes = $link->query("SELECT `daily_calling_limit` FROM " . tn('TBL_USERS') . " WHERE `ID` = {$userId} LIMIT 1");
    $targetRow = $targetRes ? $targetRes->fetch_assoc() : [];
    $dailyTarget = (isset($targetRow['daily_calling_limit']) && (int)$targetRow['daily_calling_limit'] > 0)
        ? (int)$targetRow['daily_calling_limit']
        : ($dRow && (int)$dRow['daily_target'] > 0 ? (int)$dRow['daily_target'] : 250);
    $pastStreak = getTelecallerStreak($link, $userId, $today);
    $isTargetMet = ($connectedToday >= $dailyTarget && $connectedToday > 0);
    $streak = $isTargetMet ? ($pastStreak + 1) : $pastStreak;

    $rankInfo = getCallerMasteryRank($totalXp);

    return [
        'total_xp' => $totalXp,
        'daily_xp' => $dailyXp,
        'level' => $rankInfo['level'],
        'level_title' => $rankInfo['title'],
        'badge_theme' => $rankInfo['badge_theme'],
        'next_xp' => $rankInfo['next_xp'],
        'streak_days' => $streak,
        'connected_today' => $connectedToday,
        'dials_today' => $dialsToday,
        'target_today' => $dailyTarget,
        'target_met' => ($connectedToday >= $dailyTarget),
    ];
}
