-- Migration: Telecaller Engagement Lean Engine (XP, Levels, Streaks, Daily Rollup)
-- Table: telecaller_engagement

CREATE TABLE IF NOT EXISTS `telecaller_engagement` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
