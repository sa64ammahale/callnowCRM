<?php
/**
 * Web Authentication Rate Limiter
 * Protects login and password-reset endpoints against brute-force attacks.
 */

if (!function_exists('ensureRateLimiterSchema')) {
    function ensureRateLimiterSchema(mysqli $link): void {
        static $checked = false;
        if ($checked) return;

        $sql = "CREATE TABLE IF NOT EXISTS `login_attempts` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `ip_address` varchar(45) NOT NULL,
            `action_type` varchar(32) NOT NULL,
            `identifier` varchar(150) NOT NULL DEFAULT '',
            `attempted_at` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_lookup` (`ip_address`, `action_type`, `attempted_at`),
            KEY `idx_identifier` (`identifier`, `action_type`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        @mysqli_query($link, $sql);
        $checked = true;
    }
}

if (!function_exists('getClientIpAddress')) {
    function getClientIpAddress(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        // Validate IPv4/IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return '127.0.0.1';
    }
}

if (!function_exists('checkWebRateLimit')) {
    /**
     * Checks if the request should be rate-limited.
     * Returns an error message string if blocked, or null if allowed.
     */
    function checkWebRateLimit(mysqli $link, string $action, string $identifier = '', int $maxAttempts = 5, int $decaySeconds = 900): ?string {
        if (!$link) return null;
        ensureRateLimiterSchema($link);

        $ip = getClientIpAddress();
        $now = time();
        $cutoff = $now - $decaySeconds;

        // Probabilistic cleanup of stale records (1% chance)
        if (random_int(1, 100) === 1) {
            $cleanupCutoff = $now - 86400;
            @mysqli_query($link, "DELETE FROM `login_attempts` WHERE `attempted_at` < $cleanupCutoff");
        }

        $stmt = mysqli_prepare($link, "SELECT COUNT(*) as attempts, MAX(attempted_at) as last_attempt FROM `login_attempts` WHERE (ip_address = ? OR (identifier = ? AND identifier != '')) AND action_type = ? AND attempted_at >= ?");
        if (!$stmt) return null;

        mysqli_stmt_bind_param($stmt, "sssi", $ip, $identifier, $action, $cutoff);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        if ($row && (int)$row['attempts'] >= $maxAttempts) {
            $lastAttempt = (int)$row['last_attempt'];
            $remainingSeconds = max(1, ($lastAttempt + $decaySeconds) - $now);
            $remainingMinutes = ceil($remainingSeconds / 60);
            return "Too many failed attempts. Please wait {$remainingMinutes} minute(s) before trying again.";
        }

        return null;
    }
}

if (!function_exists('recordFailedAttemptWeb')) {
    function recordFailedAttemptWeb(mysqli $link, string $action, string $identifier = ''): void {
        if (!$link) return;
        ensureRateLimiterSchema($link);

        $ip = getClientIpAddress();
        $now = time();

        $stmt = mysqli_prepare($link, "INSERT INTO `login_attempts` (ip_address, action_type, identifier, attempted_at) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sssi", $ip, $action, $identifier, $now);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

if (!function_exists('clearWebRateLimit')) {
    function clearWebRateLimit(mysqli $link, string $action, string $identifier = ''): void {
        if (!$link) return;
        ensureRateLimiterSchema($link);

        $ip = getClientIpAddress();
        $stmt = mysqli_prepare($link, "DELETE FROM `login_attempts` WHERE (ip_address = ? OR (identifier = ? AND identifier != '')) AND action_type = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sss", $ip, $identifier, $action);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}
