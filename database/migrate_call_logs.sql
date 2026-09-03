-- ============================================================
--  CallNow CRM — Call History Migration
--  Adds call_logs table and TBL_CALL_LOGS constant target
-- ============================================================

CREATE TABLE IF NOT EXISTS `call_logs` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `record_id`    INT(11)      NOT NULL,
    `source`       ENUM('TEMP','MAIN','LEADS') NOT NULL,
    `user_id`      INT(11)      NOT NULL,
    `call_status`  VARCHAR(50)  NOT NULL,
    `call_duration` INT(11)     DEFAULT NULL,
    `notes`        TEXT         DEFAULT NULL,
    `next_followup` DATETIME    DEFAULT NULL,
    `recording_url` VARCHAR(500) DEFAULT NULL,
    `call_time`    DATETIME    DEFAULT NULL,
    `created_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_record_source` (`record_id`, `source`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_record` (`record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
