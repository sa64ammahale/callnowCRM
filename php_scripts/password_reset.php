<?php
function ensurePasswordResetSchema(mysqli $link): void {
    $link->query('CREATE TABLE IF NOT EXISTS password_reset_tokens (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_reset_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function consumePasswordReset(mysqli $link, string $token, string $password): bool {
    if (!preg_match('/^[a-f0-9]{64}$/', $token) || strlen($password) < 8) return false;
    ensurePasswordResetSchema($link);
    $hash = hash('sha256', $token);
    $link->begin_transaction();
    try {
        $stmt = $link->prepare("SELECT r.id, r.user_id FROM password_reset_tokens r JOIN " . tn('TBL_USERS') . " u ON u.ID = r.user_id WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND u.STATUS = 'Active' FOR UPDATE");
        $stmt->bind_param('s', $hash);
        $stmt->execute(); $reset = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$reset) { $link->rollback(); return false; }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $link->prepare('UPDATE ' . tn('TBL_USERS') . ' SET PASSWORD = ? WHERE ID = ?');
        $stmt->bind_param('si', $passwordHash, $reset['user_id']);
        if (!$stmt->execute()) throw new RuntimeException('Password update failed');
        $stmt->close();
        $stmt = $link->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        $stmt->bind_param('i', $reset['user_id']);
        if (!$stmt->execute()) throw new RuntimeException('Token update failed');
        $stmt->close();
        $stmt = $link->prepare('UPDATE ' . tn('TBL_API_TOKENS') . ' SET is_active = 0 WHERE user_id = ?');
        $stmt->bind_param('i', $reset['user_id']);
        if (!$stmt->execute()) throw new RuntimeException('Session update failed');
        $stmt->close(); $link->commit();
        return true;
    } catch (Throwable $e) { $link->rollback(); error_log('Password reset failed: ' . $e->getMessage()); return false; }
}
