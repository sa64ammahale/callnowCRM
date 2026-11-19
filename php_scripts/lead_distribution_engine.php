<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/phone_helper.php';

function cnGenerateUuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function acquireTenantAllocLock(mysqli $link, int $timeoutSeconds = 5): bool {
    $dbName = '';
    $res = $link->query("SELECT DATABASE()");
    if ($res && ($row = $res->fetch_array())) {
        $dbName = (string)$row[0];
    }
    $lockName = 'cn_alloc_' . substr(md5($dbName), 0, 16);
    $stmt = $link->prepare("SELECT GET_LOCK(?, ?)");
    $stmt->bind_param('si', $lockName, $timeoutSeconds);
    $stmt->execute();
    $stmt->bind_result($acquired);
    $stmt->fetch();
    $stmt->close();
    return (int)$acquired === 1;
}

function releaseTenantAllocLock(mysqli $link): void {
    $dbName = '';
    $res = $link->query("SELECT DATABASE()");
    if ($res && ($row = $res->fetch_array())) {
        $dbName = (string)$row[0];
    }
    $lockName = 'cn_alloc_' . substr(md5($dbName), 0, 16);
    $stmt = $link->prepare("SELECT RELEASE_LOCK(?)");
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $stmt->close();
}

function hasLeadEngineColumn(mysqli $link, string $table, string $column): bool {
    static $colCache = [];
    $key = "$table.$column";
    if (isset($colCache[$key])) return $colCache[$key];
    $res = @mysqli_query($link, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    $colCache[$key] = ($res && mysqli_num_rows($res) > 0);
    return $colCache[$key];
}

function ensureLeadEngineColumn(mysqli $link, string $table, string $column, string $definition): void {
    if (!hasLeadEngineColumn($link, $table, $column)) {
        @mysqli_query($link, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function ensureLeadEngineSchema(mysqli $link): void {
    static $ensured = false;
    if ($ensured) return;

    mysqli_query($link, "CREATE TABLE IF NOT EXISTS `lead_batches` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `batch_uuid` VARCHAR(64) NOT NULL,
        `claim_request_id` VARCHAR(64) NOT NULL,
        `company_code` VARCHAR(50) NOT NULL DEFAULT 'default',
        `telecaller_id` INT NOT NULL,
        `device_id` VARCHAR(100) NOT NULL,
        `session_epoch` INT NOT NULL DEFAULT 1,
        `allocated_count` INT NOT NULL DEFAULT 0,
        `lease_duration_seconds` INT NOT NULL DEFAULT 1200,
        `expires_at` DATETIME NOT NULL,
        `status` ENUM('ACTIVE', 'EXPIRED', 'RELEASED', 'COMPLETED') NOT NULL DEFAULT 'ACTIVE',
        `request_params_json` TEXT NULL,
        `request_hash` CHAR(64) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_batch_uuid` (`batch_uuid`),
        UNIQUE KEY `uniq_claim_request` (`company_code`, `telecaller_id`, `claim_request_id`),
        KEY `idx_batch_caller_status` (`telecaller_id`, `status`, `expires_at`),
        KEY `idx_batch_expiry` (`expires_at`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    mysqli_query($link, "CREATE TABLE IF NOT EXISTS `lead_reservations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `batch_id` INT NOT NULL,
        `batch_uuid` VARCHAR(64) NOT NULL,
        `reservation_token` VARCHAR(64) NOT NULL,
        `call_lock_token` VARCHAR(64) NULL,
        `company_code` VARCHAR(50) NOT NULL DEFAULT 'default',
        `telecaller_id` INT NOT NULL,
        `device_id` VARCHAR(100) NOT NULL,
        `session_epoch` INT NOT NULL DEFAULT 1,
        `source` ENUM('TEMP', 'MAIN') NOT NULL,
        `lead_id` INT NOT NULL,
        `phone_number_10` VARCHAR(15) NOT NULL,
        `priority_tier` ENUM('P0', 'P1', 'P2', 'P3') NOT NULL,
        `followup_id` INT DEFAULT NULL,
        `state` ENUM('RESERVED', 'DIALING', 'COMPLETED', 'EXPIRED', 'RELEASED') NOT NULL DEFAULT 'RESERVED',
        `reserved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `dialing_started_at` DATETIME DEFAULT NULL,
        `last_heartbeat_at` DATETIME DEFAULT NULL,
        `expires_at` DATETIME NOT NULL,
        `completed_at` DATETIME DEFAULT NULL,
        `call_log_id` INT DEFAULT NULL,
        `client_call_id` VARCHAR(128) DEFAULT NULL,
        `reconciliation_needed` TINYINT(1) NOT NULL DEFAULT 0,
        `active_phone_key` VARCHAR(30) GENERATED ALWAYS AS (
            CASE WHEN `state` IN ('RESERVED', 'DIALING') THEN `phone_number_10` ELSE NULL END
        ) VIRTUAL,
        UNIQUE KEY `uniq_reservation_token` (`reservation_token`),
        UNIQUE KEY `uniq_call_lock_token` (`call_lock_token`),
        UNIQUE KEY `uniq_active_phone` (`active_phone_key`),
        KEY `idx_res_batch` (`batch_id`),
        KEY `idx_res_caller_state_exp` (`telecaller_id`, `state`, `expires_at`),
        KEY `idx_res_lead_source` (`source`, `lead_id`, `state`),
        KEY `idx_res_phone` (`phone_number_10`),
        KEY `idx_res_dialing_reconciliation` (`state`, `dialing_started_at`, `reconciliation_needed`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    @mysqli_query($link, "ALTER TABLE `lead_reservations` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    @mysqli_query($link, "ALTER TABLE `lead_batches` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    ensureLeadEngineColumn($link, 'users', 'session_epoch', 'INT NOT NULL DEFAULT 1');
    ensureLeadEngineColumn($link, tn('TBL_TEMP'), 'CALL_COUNT', 'INT NOT NULL DEFAULT 0');
    ensureLeadEngineColumn($link, tn('TBL_TEMP'), 'BATCH_ASSIGNED_AT', 'DATETIME NULL');
    ensureLeadEngineColumn($link, tn('TBL_TEMP'), 'TEMP_LAST_CALLED_AT', 'DATETIME NULL');
    ensureLeadEngineColumn($link, tn('TBL_MAIN'), 'CALL_COUNT', 'INT NOT NULL DEFAULT 0');
    ensureLeadEngineColumn($link, tn('TBL_MAIN'), 'BATCH_ASSIGNED_AT', 'DATETIME NULL');
    ensureLeadEngineColumn($link, 'lead_batches', 'allocated_count', 'INT NOT NULL DEFAULT 0');
    ensureLeadEngineColumn($link, 'lead_batches', 'request_params_json', 'TEXT NULL');
    ensureLeadEngineColumn($link, 'lead_batches', 'request_hash', 'CHAR(64) NULL');
    ensureLeadEngineColumn($link, 'lead_batches', 'session_epoch', 'INT NOT NULL DEFAULT 1');
    ensureLeadEngineColumn($link, 'lead_batches', 'lease_duration_seconds', 'INT NOT NULL DEFAULT 1200');
    ensureLeadEngineColumn($link, 'lead_reservations', 'reconciliation_needed', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureLeadEngineColumn($link, 'lead_reservations', 'client_call_id', 'VARCHAR(128) DEFAULT NULL');
    ensureLeadEngineColumn($link, 'lead_reservations', 'call_log_id', 'INT DEFAULT NULL');
    ensureLeadEngineColumn($link, 'lead_reservations', 'completed_at', 'DATETIME DEFAULT NULL');
    ensureLeadEngineColumn($link, 'lead_reservations', 'dialing_started_at', 'DATETIME DEFAULT NULL');
    ensureLeadEngineColumn($link, 'lead_reservations', 'last_heartbeat_at', 'DATETIME DEFAULT NULL');
    ensureLeadEngineColumn($link, 'lead_reservations', 'call_lock_token', 'VARCHAR(64) NULL');
    ensureLeadEngineColumn($link, 'lead_reservations', 'session_epoch', 'INT NOT NULL DEFAULT 1');
    ensureLeadEngineColumn($link, 'lead_reservations', 'priority_tier', "ENUM('P0', 'P1', 'P2', 'P3') NOT NULL DEFAULT 'P0'");
    ensureLeadEngineColumn($link, 'lead_reservations', 'followup_id', 'INT DEFAULT NULL');

    $ensured = true;
}

function sweepExpiredReservations(mysqli $link): void {
    ensureLeadEngineSchema($link);

    $link->query("UPDATE " . tn('TBL_TEMP') . " t
        INNER JOIN lead_reservations lr ON lr.lead_id = t.ID AND lr.source = 'TEMP'
        SET t.BATCH_ASSIGNED_AT = NULL, t.CALL_DIALED_TELECALLER = NULL
        WHERE lr.state = 'RESERVED' AND lr.expires_at <= NOW()
          AND (t.CALL_DIALED_STATUS IS NULL OR t.CALL_DIALED_STATUS = '' OR t.CALL_DIALED_STATUS = 'Not Called' OR t.CALL_DIALED_STATUS = 'Pending')");

    $link->query("UPDATE " . tn('TBL_MAIN') . " m
        INNER JOIN lead_reservations lr ON lr.lead_id = m.ID AND lr.source = 'MAIN'
        SET m.BATCH_ASSIGNED_AT = NULL, m.MAINDATABASE_CALL_DIALED_USER = NULL
        WHERE lr.state = 'RESERVED' AND lr.expires_at <= NOW()
          AND (m.MAINDATABASE_CALL_DIALED_STATUS IS NULL OR m.MAINDATABASE_CALL_DIALED_STATUS = '' OR m.MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR m.MAINDATABASE_CALL_DIALED_STATUS = 'Pending')");

    $link->query("UPDATE lead_reservations SET state = 'EXPIRED' WHERE state = 'RESERVED' AND expires_at <= NOW()");
    $link->query("UPDATE lead_batches SET status = 'EXPIRED' WHERE status = 'ACTIVE' AND expires_at <= NOW()");
}

function getActiveCallLock(mysqli $link, int $userId): ?array {
    ensureLeadEngineSchema($link);
    $stmt = $link->prepare("SELECT id, batch_uuid, reservation_token, call_lock_token, lead_id, source, phone_number_10, dialing_started_at, session_epoch, device_id FROM lead_reservations WHERE telecaller_id = ? AND state = 'DIALING' LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $custName = '';
    $source = $row['source'];
    $leadId = (int)$row['lead_id'];
    if ($source === 'TEMP') {
        $q = $link->prepare("SELECT CUST_NAME FROM " . tn('TBL_TEMP') . " WHERE ID = ? LIMIT 1");
    } else {
        $q = $link->prepare("SELECT MAINDATABASE_NAME FROM " . tn('TBL_MAIN') . " WHERE ID = ? LIMIT 1");
    }
    if ($q) {
        $q->bind_param('i', $leadId);
        $q->execute();
        $cRow = $q->get_result()->fetch_array();
        $q->close();
        if ($cRow) {
            $custName = (string)$cRow[0];
        }
    }

    return [
        'has_active_call' => true,
        'call' => [
            'batch_uuid' => $row['batch_uuid'],
            'reservation_token' => $row['reservation_token'],
            'call_lock_token' => $row['call_lock_token'],
            'lead_id' => $leadId,
            'source' => $source,
            'customer_name' => $custName,
            'phone_number_10' => $row['phone_number_10'],
            'dialing_started_at' => $row['dialing_started_at'],
            'session_epoch' => (int)$row['session_epoch'],
            'device_id' => $row['device_id']
        ]
    ];
}

function startCallLock(mysqli $link, int $userId, string $reservationToken, int $leadId, string $source, string $deviceId, int $sessionEpoch): array {
    $stmt = $link->prepare("SELECT id, state, telecaller_id, session_epoch, expires_at FROM lead_reservations WHERE reservation_token = ? LIMIT 1");
    $stmt->bind_param('s', $reservationToken);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$res) {
        return ['error' => 'RESERVATION_NOT_FOUND', 'code' => 404, 'message' => 'Reservation token not found'];
    }

    if ((int)$res['telecaller_id'] !== $userId) {
        return ['error' => 'FORBIDDEN', 'code' => 403, 'message' => 'Reservation belongs to another telecaller'];
    }

    if ($res['state'] === 'DIALING') {
        $stmtLock = $link->prepare("SELECT call_lock_token, dialing_started_at FROM lead_reservations WHERE id = ?");
        $stmtLock->bind_param('i', $res['id']);
        $stmtLock->execute();
        $lRow = $stmtLock->get_result()->fetch_assoc();
        $stmtLock->close();
        return [
            'success' => true,
            'call_lock_token' => $lRow['call_lock_token'],
            'reservation_token' => $reservationToken,
            'state' => 'DIALING',
            'dialing_started_at' => $lRow['dialing_started_at'],
            'decoupled_call_lock' => true
        ];
    }

    if ($res['state'] !== 'RESERVED') {
        return ['error' => 'INVALID_STATE', 'code' => 409, 'message' => 'Reservation is already ' . $res['state']];
    }

    if (strtotime($res['expires_at']) <= time()) {
        return ['error' => 'RESERVATION_EXPIRED', 'code' => 409, 'message' => 'Reservation lease has expired'];
    }

    if ($sessionEpoch < (int)$res['session_epoch']) {
        return ['error' => 'STALE_SESSION', 'code' => 409, 'message' => 'Session epoch is stale. Telecaller active on another device'];
    }

    $callLockToken = 'lock_' . cnGenerateUuid();
    $up = $link->prepare("UPDATE lead_reservations SET state = 'DIALING', call_lock_token = ?, dialing_started_at = NOW(), device_id = ?, session_epoch = ? WHERE id = ?");
    $up->bind_param('ssii', $callLockToken, $deviceId, $sessionEpoch, $res['id']);
    $up->execute();
    $up->close();

    return [
        'success' => true,
        'call_lock_token' => $callLockToken,
        'reservation_token' => $reservationToken,
        'state' => 'DIALING',
        'dialing_started_at' => date('Y-m-d H:i:s'),
        'expires_at' => date('Y-m-d H:i:s', time() + 1200),
        'decoupled_call_lock' => true
    ];
}

function abandonCallLock(mysqli $link, int $userId, string $reservationToken, string $reason): array {
    $stmt = $link->prepare("SELECT id, state, telecaller_id FROM lead_reservations WHERE reservation_token = ? LIMIT 1");
    $stmt->bind_param('s', $reservationToken);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['error' => 'NOT_FOUND', 'code' => 404, 'message' => 'Reservation not found'];
    }

    if ((int)$row['telecaller_id'] !== $userId) {
        return ['error' => 'FORBIDDEN', 'code' => 403, 'message' => 'Reservation belongs to another telecaller'];
    }

    if ($row['state'] !== 'DIALING' && $row['state'] !== 'RESERVED') {
        return ['error' => 'INVALID_STATE', 'code' => 409, 'message' => 'Cannot abandon reservation in state: ' . $row['state']];
    }

    $up = $link->prepare("UPDATE lead_reservations SET state = 'RELEASED' WHERE id = ?");
    $up->bind_param('i', $row['id']);
    $up->execute();
    $up->close();

    return [
        'success' => true,
        'reservation_token' => $reservationToken,
        'state' => 'RELEASED',
        'message' => 'Call lock abandoned successfully'
    ];
}

function renewBucketHeartbeat(mysqli $link, int $userId, string $batchUuid, string $deviceId, int $sessionEpoch, array $reservationTokens): array {
    $stmt = $link->prepare("SELECT id, expires_at, status FROM lead_batches WHERE batch_uuid = ? AND telecaller_id = ? LIMIT 1");
    $stmt->bind_param('si', $batchUuid, $userId);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$batch) {
        return ['error' => 'BATCH_NOT_FOUND', 'code' => 404, 'message' => 'Batch not found for caller'];
    }

    $newExpiresAt = date('Y-m-d H:i:s', time() + 1200);

    $upBatch = $link->prepare("UPDATE lead_batches SET expires_at = ?, status = 'ACTIVE' WHERE id = ?");
    $upBatch->bind_param('si', $newExpiresAt, $batch['id']);
    $upBatch->execute();
    $upBatch->close();

    $renewedCount = 0;
    if (!empty($reservationTokens)) {
        $placeholders = implode(',', array_fill(0, count($reservationTokens), '?'));
        $types = 's' . str_repeat('s', count($reservationTokens));
        $params = array_merge([$newExpiresAt], $reservationTokens);

        $sql = "UPDATE lead_reservations SET expires_at = ?, last_heartbeat_at = NOW() WHERE state = 'RESERVED' AND reservation_token IN ($placeholders)";
        $upRes = $link->prepare($sql);
        $upRes->bind_param($types, ...$params);
        $upRes->execute();
        $renewedCount = $upRes->affected_rows;
        $upRes->close();
    }

    return [
        'success' => true,
        'batch_uuid' => $batchUuid,
        'bucket_id' => $batchUuid,
        'renewed_count' => $renewedCount,
        'expires_at' => $newExpiresAt,
        'server_time' => date('Y-m-d H:i:s')
    ];
}

function releaseBatchQueue(mysqli $link, int $userId, string $batchUuid): array {
    $stmt = $link->prepare("SELECT id FROM lead_batches WHERE batch_uuid = ? AND telecaller_id = ? LIMIT 1");
    $stmt->bind_param('si', $batchUuid, $userId);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$batch) {
        return ['error' => 'BATCH_NOT_FOUND', 'code' => 404, 'message' => 'Batch not found'];
    }

    $upRes = $link->prepare("UPDATE lead_reservations SET state = 'RELEASED' WHERE batch_id = ? AND telecaller_id = ? AND state = 'RESERVED'");
    $upRes->bind_param('ii', $batch['id'], $userId);
    $upRes->execute();
    $releasedCount = $upRes->affected_rows;
    $upRes->close();

    $batchIdInt = (int)$batch['id'];
    $link->query("UPDATE " . tn('TBL_TEMP') . " t
        INNER JOIN lead_reservations lr ON lr.lead_id = t.ID AND lr.source = 'TEMP' AND lr.batch_id = $batchIdInt
        SET t.BATCH_ASSIGNED_AT = NULL, t.CALL_DIALED_TELECALLER = NULL
        WHERE lr.state = 'RELEASED' AND (t.CALL_DIALED_STATUS IS NULL OR t.CALL_DIALED_STATUS = '' OR t.CALL_DIALED_STATUS = 'Not Called' OR t.CALL_DIALED_STATUS = 'Pending')");

    $link->query("UPDATE " . tn('TBL_MAIN') . " m
        INNER JOIN lead_reservations lr ON lr.lead_id = m.ID AND lr.source = 'MAIN' AND lr.batch_id = $batchIdInt
        SET m.BATCH_ASSIGNED_AT = NULL, m.MAINDATABASE_CALL_DIALED_USER = NULL
        WHERE lr.state = 'RELEASED' AND (m.MAINDATABASE_CALL_DIALED_STATUS IS NULL OR m.MAINDATABASE_CALL_DIALED_STATUS = '' OR m.MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR m.MAINDATABASE_CALL_DIALED_STATUS = 'Pending')");

    $upBatch = $link->prepare("UPDATE lead_batches SET status = 'RELEASED' WHERE id = ?");
    $upBatch->bind_param('i', $batch['id']);
    $upBatch->execute();
    $upBatch->close();

    return [
        'success' => true,
        'batch_uuid' => $batchUuid,
        'released_count' => $releasedCount,
        'message' => 'Uncalled reservations released successfully'
    ];
}

function claimLeadBatch(
    mysqli $link,
    int $userId,
    string $companyCode,
    string $deviceId,
    int $sessionEpoch,
    string $claimRequestId,
    int $batchSize = 15,
    bool $isPrefetch = false
): array {
    ensureLeadEngineSchema($link);
    if ($batchSize < 1) $batchSize = 15;
    if ($batchSize > 30) $batchSize = 30;

    $canonicalParams = json_encode(['batch_size' => $batchSize, 'prefetch' => $isPrefetch]);
    $requestHash = hash('sha256', $canonicalParams);

    $checkStmt = $link->prepare("SELECT id, batch_uuid, request_hash, expires_at, status FROM lead_batches WHERE company_code = ? AND telecaller_id = ? AND claim_request_id = ? LIMIT 1");
    if ($checkStmt) {
        $checkStmt->bind_param('sis', $companyCode, $userId, $claimRequestId);
        $checkStmt->execute();
        $existingBatch = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
    } else {
        $existingBatch = null;
    }

    if ($existingBatch) {
        if (!hash_equals((string)$existingBatch['request_hash'], $requestHash)) {
            return ['error' => 'CONFLICT', 'code' => 409, 'message' => 'claim_request_id already used with different parameters'];
        }

        $resStmt = $link->prepare("SELECT reservation_token, priority_tier, source, lead_id, phone_number_10, followup_id FROM lead_reservations WHERE batch_id = ? ORDER BY id ASC");
        if ($resStmt) {
            $resStmt->bind_param('i', $existingBatch['id']);
            $resStmt->execute();
            $savedRows = $resStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $resStmt->close();
        } else {
            $savedRows = [];
        }

        $leadsPayload = [];
        foreach ($savedRows as $r) {
            $leadsPayload[] = formatLeadForClaimPayload($link, $r);
        }

        return [
            'success' => true,
            'batch_uuid' => $existingBatch['batch_uuid'],
            'bucket_id' => $existingBatch['batch_uuid'],
            'lease_duration_seconds' => 1200,
            'expires_at' => $existingBatch['expires_at'],
            'heartbeat_interval_seconds' => 600,
            'prefetch_threshold' => 3,
            'session_epoch' => $sessionEpoch,
            'total_allocated' => count($leadsPayload),
            'leads' => $leadsPayload
        ];
    }

    $currentEpoch = 1;
    if (hasLeadEngineColumn($link, 'users', 'session_epoch')) {
        $epochStmt = $link->prepare("SELECT session_epoch FROM users WHERE ID = ? LIMIT 1");
        if ($epochStmt) {
            $epochStmt->bind_param('i', $userId);
            $epochStmt->execute();
            $uRow = $epochStmt->get_result()->fetch_assoc();
            $epochStmt->close();
            $currentEpoch = (int)($uRow['session_epoch'] ?? 1);
        }
    }

    if ($sessionEpoch > $currentEpoch) {
        if (hasLeadEngineColumn($link, 'users', 'session_epoch')) {
            $upEpoch = $link->prepare("UPDATE users SET session_epoch = ? WHERE ID = ?");
            if ($upEpoch) {
                $upEpoch->bind_param('ii', $sessionEpoch, $userId);
                $upEpoch->execute();
                $upEpoch->close();
            }
        }

        $relStmt = $link->prepare("UPDATE lead_reservations SET state = 'RELEASED' WHERE telecaller_id = ? AND state = 'RESERVED'");
        if ($relStmt) {
            $relStmt->bind_param('i', $userId);
            $relStmt->execute();
            $relStmt->close();
        }
    }

    if (!acquireTenantAllocLock($link, 5)) {
        return ['error' => 'SERVICE_UNAVAILABLE', 'code' => 503, 'message' => 'Server busy allocating leads, please retry'];
    }

    try {
        sweepExpiredReservations($link);

        if (!$isPrefetch) {
            $actBatchStmt = $link->prepare("SELECT id, batch_uuid, request_hash, expires_at, status FROM lead_batches WHERE company_code = ? AND telecaller_id = ? AND status = 'ACTIVE' AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
            if ($actBatchStmt) {
                $actBatchStmt->bind_param('si', $companyCode, $userId);
                $actBatchStmt->execute();
                $activeBatch = $actBatchStmt->get_result()->fetch_assoc();
                $actBatchStmt->close();

                if ($activeBatch) {
                    $actResStmt = $link->prepare("SELECT reservation_token, priority_tier, source, lead_id, phone_number_10, followup_id FROM lead_reservations WHERE batch_id = ? AND telecaller_id = ? AND state IN ('RESERVED', 'DIALING') ORDER BY id ASC");
                    if ($actResStmt) {
                        $actResStmt->bind_param('ii', $activeBatch['id'], $userId);
                        $actResStmt->execute();
                        $savedRows = $actResStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $actResStmt->close();

                        if (!empty($savedRows)) {
                            $leadsPayload = [];
                            foreach ($savedRows as $r) {
                                $leadsPayload[] = formatLeadForClaimPayload($link, $r);
                            }
                            releaseTenantAllocLock($link);
                            return [
                                'success' => true,
                                'batch_uuid' => $activeBatch['batch_uuid'],
                                'bucket_id' => $activeBatch['batch_uuid'],
                                'lease_duration_seconds' => 1200,
                                'expires_at' => $activeBatch['expires_at'],
                                'heartbeat_interval_seconds' => 600,
                                'prefetch_threshold' => 3,
                                'session_epoch' => $sessionEpoch,
                                'total_allocated' => count($leadsPayload),
                                'leads' => $leadsPayload
                            ];
                        } else {
                            $upComp = $link->prepare("UPDATE lead_batches SET status = 'COMPLETED' WHERE id = ?");
                            if ($upComp) {
                                $upComp->bind_param('i', $activeBatch['id']);
                                $upComp->execute();
                                $upComp->close();
                            }
                        }
                    }
                }
            }
        }

        $allocatedLeads = [];
        $allocatedPhones = [];

        $allocatedP1 = queryWaterfallP1($link, $userId, $batchSize, $allocatedPhones);
        foreach ($allocatedP1 as $lead) {
            $allocatedLeads[] = $lead;
            $allocatedPhones[] = $lead['phone_number_10'];
        }

        if (count($allocatedLeads) < $batchSize) {
            $needed = $batchSize - count($allocatedLeads);
            $allocatedP0 = queryWaterfallP0($link, $userId, $needed, $allocatedPhones);
            foreach ($allocatedP0 as $lead) {
                $allocatedLeads[] = $lead;
                $allocatedPhones[] = $lead['phone_number_10'];
            }
        }

        if (count($allocatedLeads) < $batchSize) {
            $needed = $batchSize - count($allocatedLeads);
            $allocatedP2 = queryWaterfallP2($link, $userId, $needed, $allocatedPhones);
            foreach ($allocatedP2 as $lead) {
                $allocatedLeads[] = $lead;
                $allocatedPhones[] = $lead['phone_number_10'];
            }
        }

        if (count($allocatedLeads) < $batchSize) {
            $needed = $batchSize - count($allocatedLeads);
            $allocatedP3 = queryWaterfallP3($link, $userId, $needed, $allocatedPhones);
            foreach ($allocatedP3 as $lead) {
                $allocatedLeads[] = $lead;
                $allocatedPhones[] = $lead['phone_number_10'];
            }
        }

        $batchUuid = cnGenerateUuid();
        $expiresAt = date('Y-m-d H:i:s', time() + 1200);
        $totalAllocated = count($allocatedLeads);

        $batchCols = ['batch_uuid', 'claim_request_id', 'company_code', 'telecaller_id', 'device_id', 'status', 'expires_at'];
        $batchPlaceholders = ['?', '?', '?', '?', '?', "'ACTIVE'", '?'];
        $batchTypes = 'sssiss';
        $batchParams = [$batchUuid, $claimRequestId, $companyCode, $userId, $deviceId, $expiresAt];

        if (hasLeadEngineColumn($link, 'lead_batches', 'created_at')) {
            $batchCols[] = 'created_at';
            $batchPlaceholders[] = 'NOW()';
        } elseif (hasLeadEngineColumn($link, 'lead_batches', 'allocated_at')) {
            $batchCols[] = 'allocated_at';
            $batchPlaceholders[] = 'NOW()';
        }

        if (hasLeadEngineColumn($link, 'lead_batches', 'session_epoch')) {
            $batchCols[] = 'session_epoch';
            $batchPlaceholders[] = '?';
            $batchTypes .= 'i';
            $batchParams[] = $sessionEpoch;
        }
        if (hasLeadEngineColumn($link, 'lead_batches', 'allocated_count')) {
            $batchCols[] = 'allocated_count';
            $batchPlaceholders[] = '?';
            $batchTypes .= 'i';
            $batchParams[] = $totalAllocated;
        }
        if (hasLeadEngineColumn($link, 'lead_batches', 'lease_duration_seconds')) {
            $batchCols[] = 'lease_duration_seconds';
            $batchPlaceholders[] = '1200';
        }
        if (hasLeadEngineColumn($link, 'lead_batches', 'request_params_json')) {
            $batchCols[] = 'request_params_json';
            $batchPlaceholders[] = '?';
            $batchTypes .= 's';
            $batchParams[] = $canonicalParams;
        }
        if (hasLeadEngineColumn($link, 'lead_batches', 'request_hash')) {
            $batchCols[] = 'request_hash';
            $batchPlaceholders[] = '?';
            $batchTypes .= 's';
            $batchParams[] = $requestHash;
        }

        $batchSql = "INSERT INTO lead_batches (" . implode(', ', $batchCols) . ") VALUES (" . implode(', ', $batchPlaceholders) . ")";
        $insBatch = $link->prepare($batchSql);
        if (!$insBatch) {
            throw new Exception("Failed to prepare insert into lead_batches: " . $link->error . " (SQL: $batchSql)");
        }
        $insBatch->bind_param($batchTypes, ...$batchParams);
        $insBatch->execute();
        $batchId = (int)$insBatch->insert_id;
        $insBatch->close();

        $leadsPayload = [];
        foreach ($allocatedLeads as $lead) {
            $resToken = 'res_' . cnGenerateUuid();
            $source = $lead['source'];
            $leadId = (int)$lead['lead_id'];
            $phone10 = $lead['phone_number_10'];
            $tier = $lead['priority_tier'];
            $followupId = isset($lead['followup_id']) ? (int)$lead['followup_id'] : null;

            $resCols = ['batch_id', 'batch_uuid', 'reservation_token', 'company_code', 'telecaller_id', 'device_id', 'source', 'lead_id', 'phone_number_10', 'state', 'reserved_at', 'expires_at'];
            $resPlaceholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?', "'RESERVED'", 'NOW()', '?'];
            $resTypes = 'isssississ';
            $resParams = [$batchId, $batchUuid, $resToken, $companyCode, $userId, $deviceId, $source, $leadId, $phone10, $expiresAt];

            if (hasLeadEngineColumn($link, 'lead_reservations', 'session_epoch')) {
                $resCols[] = 'session_epoch';
                $resPlaceholders[] = '?';
                $resTypes .= 'i';
                $resParams[] = $sessionEpoch;
            }
            if (hasLeadEngineColumn($link, 'lead_reservations', 'priority_tier')) {
                $resCols[] = 'priority_tier';
                $resPlaceholders[] = '?';
                $resTypes .= 's';
                $resParams[] = $tier;
            }
            if (hasLeadEngineColumn($link, 'lead_reservations', 'followup_id')) {
                $resCols[] = 'followup_id';
                $resPlaceholders[] = '?';
                $resTypes .= 'i';
                $resParams[] = $followupId;
            }

            $resSql = "INSERT INTO lead_reservations (" . implode(', ', $resCols) . ") VALUES (" . implode(', ', $resPlaceholders) . ")";
            $insRes = $link->prepare($resSql);
            if (!$insRes) {
                throw new Exception("Failed to prepare insert into lead_reservations: " . $link->error . " (SQL: $resSql)");
            }
            $insRes->bind_param($resTypes, ...$resParams);
            $insRes->execute();
            $insRes->close();

            if ($source === 'TEMP') {
                $upT = $link->prepare("UPDATE " . tn('TBL_TEMP') . " SET BATCH_ASSIGNED_AT = NOW(), CALL_DIALED_TELECALLER = ? WHERE ID = ?");
                if ($upT) {
                    $upT->bind_param('ii', $userId, $leadId);
                    $upT->execute();
                    $upT->close();
                }
            } else {
                $upM = $link->prepare("UPDATE " . tn('TBL_MAIN') . " SET BATCH_ASSIGNED_AT = NOW(), MAINDATABASE_CALL_DIALED_USER = ? WHERE ID = ?");
                if ($upM) {
                    $upM->bind_param('ii', $userId, $leadId);
                    $upM->execute();
                    $upM->close();
                }
            }

            $lead['reservation_token'] = $resToken;
            $leadsPayload[] = formatLeadForClaimPayload($link, $lead);
        }

        return [
            'success' => true,
            'batch_uuid' => $batchUuid,
            'bucket_id' => $batchUuid,
            'lease_duration_seconds' => 1200,
            'expires_at' => $expiresAt,
            'heartbeat_interval_seconds' => 600,
            'prefetch_threshold' => 3,
            'session_epoch' => $sessionEpoch,
            'total_allocated' => $totalAllocated,
            'leads' => $leadsPayload
        ];
    } finally {
        releaseTenantAllocLock($link);
    }
}

function queryWaterfallP1(mysqli $link, int $userId, int $limit, array $excludedPhones): array {
    $candidates = [];
    $sql = "SELECT f.id AS followup_id, f.lead_id, f.client_contact_id, f.contact_source, f.followup_at, f.note
            FROM lead_followups f
            WHERE f.status = 'OPEN' AND f.followup_at <= NOW() AND (f.user_id = ? OR f.user_id IS NULL)
            ORDER BY f.followup_at ASC, f.id ASC
            LIMIT 50";
    $stmt = $link->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare queryWaterfallP1: " . $link->error);
        return [];
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $r) {
        if (count($candidates) >= $limit) break;
        $source = strtoupper((string)($r['contact_source'] ?? 'MAIN')) === 'TEMP' ? 'TEMP' : 'MAIN';
        $leadId = (int)($r['client_contact_id'] ?: $r['lead_id']);
        if ($leadId <= 0) continue;

        $phone = fetchLeadPhone($link, $source, $leadId);
        $cleanPhone = normalize_indian_phone($phone, true);
        if (!$cleanPhone) continue;

        if (in_array($cleanPhone, $excludedPhones, true)) continue;
        if (isPhoneReservedOrDnd($link, $cleanPhone)) continue;

        $candidates[] = [
            'source' => $source,
            'lead_id' => $leadId,
            'phone_number_10' => $cleanPhone,
            'priority_tier' => 'P1',
            'followup_id' => (int)$r['followup_id']
        ];
        $excludedPhones[] = $cleanPhone;
    }
    return $candidates;
}

function queryWaterfallP0(mysqli $link, int $userId, int $limit, array $excludedPhones): array {
    $candidates = [];
    $fetchLimit = max(150, $limit * 10);

    $hasTempCallCount = hasLeadEngineColumn($link, tn('TBL_TEMP'), 'CALL_COUNT');
    $callCountClause = $hasTempCallCount ? " AND t.CALL_COUNT = 0" : "";
    $sqlTemp = "SELECT t.ID, t.CUST_MOBILE FROM " . tn('TBL_TEMP') . " t
        WHERE (t.CALL_DIALED_STATUS IS NULL OR t.CALL_DIALED_STATUS = '' OR t.CALL_DIALED_STATUS = 'Not Called' OR t.CALL_DIALED_STATUS = 'Pending')
          AND t.LAST_DIALED_DATE_TIME IS NULL" . $callCountClause . "
          AND (t.CALL_DIALED_TELECALLER IS NULL OR t.CALL_DIALED_TELECALLER = ? OR t.BATCH_ASSIGNED_AT IS NULL OR t.BATCH_ASSIGNED_AT < NOW() - INTERVAL 20 MINUTE)
          AND NOT EXISTS (SELECT 1 FROM lead_followups f WHERE f.status = 'OPEN' AND f.client_contact_id = t.ID AND f.contact_source = 'TEMP')
          AND NOT EXISTS (
              SELECT 1 FROM lead_reservations lr 
              WHERE lr.phone_number_10 = RIGHT(REGEXP_REPLACE(t.CUST_MOBILE, '[^0-9]', ''), 10)
                AND lr.state IN ('RESERVED', 'DIALING')
                AND (lr.state = 'DIALING' OR lr.expires_at > NOW())
          )
          AND NOT EXISTS (
              SELECT 1 FROM " . tn('TBL_DND_REGISTRY') . " dr 
              WHERE dr.phone_number COLLATE utf8mb4_general_ci = RIGHT(REGEXP_REPLACE(t.CUST_MOBILE, '[^0-9]', ''), 10) COLLATE utf8mb4_general_ci
          )
        ORDER BY t.TEMP_UPLOAD_DATETIME ASC, t.ID ASC
        LIMIT ?";
    $stmtT = $link->prepare($sqlTemp);
    if ($stmtT) {
        $stmtT->bind_param('ii', $userId, $fetchLimit);
        $stmtT->execute();
        $tRows = $stmtT->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtT->close();
    } else {
        error_log("Failed to prepare queryWaterfallP0 Temp: " . $link->error);
        $tRows = [];
    }

    foreach ($tRows as $r) {
        if (count($candidates) >= $limit) break;
        $cleanPhone = normalize_indian_phone((string)$r['CUST_MOBILE'], true);
        if (!$cleanPhone) continue;
        if (in_array($cleanPhone, $excludedPhones, true)) continue;
        if (isPhoneReservedOrDnd($link, $cleanPhone)) continue;

        $candidates[] = [
            'source' => 'TEMP',
            'lead_id' => (int)$r['ID'],
            'phone_number_10' => $cleanPhone,
            'priority_tier' => 'P0'
        ];
        $excludedPhones[] = $cleanPhone;
    }

    if (count($candidates) < $limit) {
        $needed = $limit - count($candidates);
        $fetchLimitM = max(150, $needed * 10);
        $hasMainCallCount = hasLeadEngineColumn($link, tn('TBL_MAIN'), 'CALL_COUNT');
        $callCountClauseM = $hasMainCallCount ? " AND m.CALL_COUNT = 0" : "";
        $sqlMain = "SELECT m.ID, m.MAINDATABASE_MOBILE FROM " . tn('TBL_MAIN') . " m
            WHERE (m.MAINDATABASE_CALL_DIALED_STATUS IS NULL OR m.MAINDATABASE_CALL_DIALED_STATUS = '' OR m.MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR m.MAINDATABASE_CALL_DIALED_STATUS = 'Pending')
              AND m.LAST_DIALED_DATE_TIME IS NULL" . $callCountClauseM . "
              AND (m.MAINDATABASE_CALL_DIALED_USER IS NULL OR m.MAINDATABASE_CALL_DIALED_USER = ? OR m.BATCH_ASSIGNED_AT IS NULL OR m.BATCH_ASSIGNED_AT < NOW() - INTERVAL 20 MINUTE)
              AND NOT EXISTS (SELECT 1 FROM lead_followups f WHERE f.status = 'OPEN' AND f.client_contact_id = m.ID AND f.contact_source = 'MAIN')
              AND NOT EXISTS (
                  SELECT 1 FROM lead_reservations lr 
                  WHERE lr.phone_number_10 = RIGHT(REGEXP_REPLACE(m.MAINDATABASE_MOBILE, '[^0-9]', ''), 10)
                    AND lr.state IN ('RESERVED', 'DIALING')
                    AND (lr.state = 'DIALING' OR lr.expires_at > NOW())
              )
              AND NOT EXISTS (
                  SELECT 1 FROM " . tn('TBL_DND_REGISTRY') . " dr 
                  WHERE dr.phone_number COLLATE utf8mb4_general_ci = RIGHT(REGEXP_REPLACE(m.MAINDATABASE_MOBILE, '[^0-9]', ''), 10) COLLATE utf8mb4_general_ci
              )
            ORDER BY m.MAINDATABASE_UPLOAD_DATETIME ASC, m.ID ASC
            LIMIT ?";
        $stmtM = $link->prepare($sqlMain);
        if ($stmtM) {
            $stmtM->bind_param('ii', $userId, $fetchLimitM);
            $stmtM->execute();
            $mRows = $stmtM->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtM->close();
        } else {
            error_log("Failed to prepare queryWaterfallP0 Main: " . $link->error);
            $mRows = [];
        }

        foreach ($mRows as $r) {
            if (count($candidates) >= $limit) break;
            $cleanPhone = normalize_indian_phone((string)$r['MAINDATABASE_MOBILE'], true);
            if (!$cleanPhone) continue;
            if (in_array($cleanPhone, $excludedPhones, true)) continue;
            if (isPhoneReservedOrDnd($link, $cleanPhone)) continue;

            $candidates[] = [
                'source' => 'MAIN',
                'lead_id' => (int)$r['ID'],
                'phone_number_10' => $cleanPhone,
                'priority_tier' => 'P0'
            ];
            $excludedPhones[] = $cleanPhone;
        }
    }

    return $candidates;
}

function queryWaterfallP2(mysqli $link, int $userId, int $limit, array $excludedPhones): array {
    $candidates = [];
    $fetchLimit = max(150, $limit * 10);

    $hasTempCallCount = hasLeadEngineColumn($link, tn('TBL_TEMP'), 'CALL_COUNT');
    $callCountClause = $hasTempCallCount ? " AND t.CALL_COUNT BETWEEN 1 AND 4" : "";
    $sqlTemp = "SELECT t.ID, t.CUST_MOBILE FROM " . tn('TBL_TEMP') . " t
        WHERE t.LAST_DIALED_DATE_TIME IS NOT NULL
          AND t.LAST_DIALED_DATE_TIME >= NOW() - INTERVAL 90 DAY
          AND t.LAST_DIALED_DATE_TIME < NOW() - INTERVAL 4 HOUR" . $callCountClause . "
          AND t.CALL_DIALED_STATUS IN ('Ringing', 'Busy', 'Switch Off', 'Not Reachable', 'Call Later', 'Callback / Busy')
          AND (t.CALL_DIALED_TELECALLER IS NULL OR t.CALL_DIALED_TELECALLER = ? OR t.BATCH_ASSIGNED_AT IS NULL OR t.BATCH_ASSIGNED_AT < NOW() - INTERVAL 20 MINUTE)
          AND NOT EXISTS (SELECT 1 FROM lead_followups f WHERE f.status = 'OPEN' AND f.client_contact_id = t.ID)
          AND NOT EXISTS (
              SELECT 1 FROM lead_reservations lr 
              WHERE lr.phone_number_10 = RIGHT(REGEXP_REPLACE(t.CUST_MOBILE, '[^0-9]', ''), 10)
                AND lr.state IN ('RESERVED', 'DIALING')
                AND (lr.state = 'DIALING' OR lr.expires_at > NOW())
          )
          AND NOT EXISTS (
              SELECT 1 FROM " . tn('TBL_DND_REGISTRY') . " dr 
              WHERE dr.phone_number COLLATE utf8mb4_general_ci = RIGHT(REGEXP_REPLACE(t.CUST_MOBILE, '[^0-9]', ''), 10) COLLATE utf8mb4_general_ci
          )
        ORDER BY t.LAST_DIALED_DATE_TIME ASC, t.ID ASC
        LIMIT ?";
    $stmtT = $link->prepare($sqlTemp);
    if ($stmtT) {
        $stmtT->bind_param('ii', $userId, $fetchLimit);
        $stmtT->execute();
        $tRows = $stmtT->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtT->close();
    } else {
        error_log("Failed to prepare queryWaterfallP2 Temp: " . $link->error);
        $tRows = [];
    }

    foreach ($tRows as $r) {
        if (count($candidates) >= $limit) break;
        $cleanPhone = normalize_indian_phone((string)$r['CUST_MOBILE'], true);
        if (!$cleanPhone) continue;
        if (in_array($cleanPhone, $excludedPhones, true)) continue;
        if (isPhoneReservedOrDnd($link, $cleanPhone)) continue;

        $candidates[] = [
            'source' => 'TEMP',
            'lead_id' => (int)$r['ID'],
            'phone_number_10' => $cleanPhone,
            'priority_tier' => 'P2'
        ];
        $excludedPhones[] = $cleanPhone;
    }

    if (count($candidates) < $limit) {
        $needed = $limit - count($candidates);
        $fetchLimitM = max(150, $needed * 10);
        $hasMainCallCount = hasLeadEngineColumn($link, tn('TBL_MAIN'), 'CALL_COUNT');
        $callCountClauseM = $hasMainCallCount ? " AND m.CALL_COUNT BETWEEN 1 AND 4" : "";
        $sqlMain = "SELECT m.ID, m.MAINDATABASE_MOBILE FROM " . tn('TBL_MAIN') . " m
            WHERE m.LAST_DIALED_DATE_TIME IS NOT NULL
              AND m.LAST_DIALED_DATE_TIME >= NOW() - INTERVAL 90 DAY
              AND m.LAST_DIALED_DATE_TIME < NOW() - INTERVAL 4 HOUR" . $callCountClauseM . "
              AND m.MAINDATABASE_CALL_DIALED_STATUS IN ('Ringing', 'Busy', 'Switch Off', 'Not Reachable', 'Call Later', 'Callback / Busy')
              AND (m.MAINDATABASE_CALL_DIALED_USER IS NULL OR m.MAINDATABASE_CALL_DIALED_USER = ? OR m.BATCH_ASSIGNED_AT IS NULL OR m.BATCH_ASSIGNED_AT < NOW() - INTERVAL 20 MINUTE)
              AND NOT EXISTS (SELECT 1 FROM lead_followups f WHERE f.status = 'OPEN' AND f.client_contact_id = m.ID)
              AND NOT EXISTS (
                  SELECT 1 FROM lead_reservations lr 
                  WHERE lr.phone_number_10 = RIGHT(REGEXP_REPLACE(m.MAINDATABASE_MOBILE, '[^0-9]', ''), 10)
                    AND lr.state IN ('RESERVED', 'DIALING')
                    AND (lr.state = 'DIALING' OR lr.expires_at > NOW())
              )
              AND NOT EXISTS (
                  SELECT 1 FROM " . tn('TBL_DND_REGISTRY') . " dr 
                  WHERE dr.phone_number COLLATE utf8mb4_general_ci = RIGHT(REGEXP_REPLACE(m.MAINDATABASE_MOBILE, '[^0-9]', ''), 10) COLLATE utf8mb4_general_ci
              )
            ORDER BY m.LAST_DIALED_DATE_TIME ASC, m.ID ASC
            LIMIT ?";
        $stmtM = $link->prepare($sqlMain);
        if ($stmtM) {
            $stmtM->bind_param('ii', $userId, $fetchLimitM);
            $stmtM->execute();
            $mRows = $stmtM->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtM->close();
        } else {
            error_log("Failed to prepare queryWaterfallP2 Main: " . $link->error);
            $mRows = [];
        }

        foreach ($mRows as $r) {
            if (count($candidates) >= $limit) break;
            $cleanPhone = normalize_indian_phone((string)$r['MAINDATABASE_MOBILE'], true);
            if (!$cleanPhone) continue;
            if (in_array($cleanPhone, $excludedPhones, true)) continue;
            if (isPhoneReservedOrDnd($link, $cleanPhone)) continue;

            $candidates[] = [
                'source' => 'MAIN',
                'lead_id' => (int)$r['ID'],
                'phone_number_10' => $cleanPhone,
                'priority_tier' => 'P2'
            ];
            $excludedPhones[] = $cleanPhone;
        }
    }

    return $candidates;
}

function queryWaterfallP3(mysqli $link, int $userId, int $limit, array $excludedPhones): array {
    $candidates = [];
    $fetchLimit = max(150, $limit * 10);

    $sqlTemp = "SELECT t.ID, t.CUST_MOBILE FROM " . tn('TBL_TEMP') . " t
        WHERE t.LAST_DIALED_DATE_TIME IS NOT NULL
          AND t.LAST_DIALED_DATE_TIME < NOW() - INTERVAL 90 DAY
          AND (t.CALL_DIALED_STATUS IS NULL OR t.CALL_DIALED_STATUS NOT IN ('Lead', 'Interested', 'Enquiry', 'Sanctioned', 'Disbursed', 'Login', 'Do Not Call', 'DND', 'Wrong Number', 'Not Interested', 'Lost', 'Rejected'))
          AND (t.CALL_DIALED_TELECALLER IS NULL OR t.CALL_DIALED_TELECALLER = ? OR t.BATCH_ASSIGNED_AT IS NULL OR t.BATCH_ASSIGNED_AT < NOW() - INTERVAL 20 MINUTE)
          AND NOT EXISTS (SELECT 1 FROM lead_followups f WHERE f.status = 'OPEN' AND f.client_contact_id = t.ID)
          AND NOT EXISTS (
              SELECT 1 FROM lead_reservations lr 
              WHERE lr.phone_number_10 = RIGHT(REGEXP_REPLACE(t.CUST_MOBILE, '[^0-9]', ''), 10)
                AND lr.state IN ('RESERVED', 'DIALING')
                AND (lr.state = 'DIALING' OR lr.expires_at > NOW())
          )
          AND NOT EXISTS (
              SELECT 1 FROM " . tn('TBL_DND_REGISTRY') . " dr 
              WHERE dr.phone_number COLLATE utf8mb4_general_ci = RIGHT(REGEXP_REPLACE(t.CUST_MOBILE, '[^0-9]', ''), 10) COLLATE utf8mb4_general_ci
          )
        ORDER BY t.LAST_DIALED_DATE_TIME ASC, t.ID ASC
        LIMIT ?";
    $stmtT = $link->prepare($sqlTemp);
    if ($stmtT) {
        $stmtT->bind_param('ii', $userId, $fetchLimit);
        $stmtT->execute();
        $tRows = $stmtT->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtT->close();
    } else {
        error_log("Failed to prepare queryWaterfallP3 Temp: " . $link->error);
        $tRows = [];
    }

    foreach ($tRows as $r) {
        if (count($candidates) >= $limit) break;
        $cleanPhone = normalize_indian_phone((string)$r['CUST_MOBILE'], true);
        if (!$cleanPhone) continue;
        if (in_array($cleanPhone, $excludedPhones, true)) continue;
        if (isPhoneReservedOrDnd($link, $cleanPhone)) continue;

        $candidates[] = [
            'source' => 'TEMP',
            'lead_id' => (int)$r['ID'],
            'phone_number_10' => $cleanPhone,
            'priority_tier' => 'P3'
        ];
        $excludedPhones[] = $cleanPhone;
    }

    if (count($candidates) < $limit) {
        $needed = $limit - count($candidates);
        $fetchLimitM = max(150, $needed * 10);
        $sqlMain = "SELECT m.ID, m.MAINDATABASE_MOBILE FROM " . tn('TBL_MAIN') . " m
            WHERE m.LAST_DIALED_DATE_TIME IS NOT NULL
              AND m.LAST_DIALED_DATE_TIME < NOW() - INTERVAL 90 DAY
              AND (m.MAINDATABASE_CALL_DIALED_STATUS IS NULL OR m.MAINDATABASE_CALL_DIALED_STATUS NOT IN ('Lead', 'Interested', 'Enquiry', 'Sanctioned', 'Disbursed', 'Login', 'Do Not Call', 'DND', 'Wrong Number', 'Not Interested', 'Lost', 'Rejected'))
              AND (m.MAINDATABASE_CALL_DIALED_USER IS NULL OR m.MAINDATABASE_CALL_DIALED_USER = ? OR m.BATCH_ASSIGNED_AT IS NULL OR m.BATCH_ASSIGNED_AT < NOW() - INTERVAL 20 MINUTE)
              AND NOT EXISTS (SELECT 1 FROM lead_followups f WHERE f.status = 'OPEN' AND f.client_contact_id = m.ID)
              AND NOT EXISTS (
                  SELECT 1 FROM lead_reservations lr 
                  WHERE lr.phone_number_10 = RIGHT(REGEXP_REPLACE(m.MAINDATABASE_MOBILE, '[^0-9]', ''), 10)
                    AND lr.state IN ('RESERVED', 'DIALING')
                    AND (lr.state = 'DIALING' OR lr.expires_at > NOW())
              )
              AND NOT EXISTS (
                  SELECT 1 FROM " . tn('TBL_DND_REGISTRY') . " dr 
                  WHERE dr.phone_number COLLATE utf8mb4_general_ci = RIGHT(REGEXP_REPLACE(m.MAINDATABASE_MOBILE, '[^0-9]', ''), 10) COLLATE utf8mb4_general_ci
              )
            ORDER BY m.LAST_DIALED_DATE_TIME ASC, m.ID ASC
            LIMIT ?";
        $stmtM = $link->prepare($sqlMain);
        if ($stmtM) {
            $stmtM->bind_param('ii', $userId, $fetchLimitM);
            $stmtM->execute();
            $mRows = $stmtM->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtM->close();
        } else {
            error_log("Failed to prepare queryWaterfallP3 Main: " . $link->error);
            $mRows = [];
        }

        foreach ($mRows as $r) {
            if (count($candidates) >= $limit) break;
            $cleanPhone = normalize_indian_phone((string)$r['MAINDATABASE_MOBILE'], true);
            if (!$cleanPhone) continue;
            if (in_array($cleanPhone, $excludedPhones, true)) continue;
            if (isPhoneReservedOrDnd($link, $cleanPhone)) continue;

            $candidates[] = [
                'source' => 'MAIN',
                'lead_id' => (int)$r['ID'],
                'phone_number_10' => $cleanPhone,
                'priority_tier' => 'P3'
            ];
            $excludedPhones[] = $cleanPhone;
        }
    }

    return $candidates;
}

function fetchLeadPhone(mysqli $link, string $source, int $leadId): ?string {
    if ($source === 'TEMP') {
        $q = $link->prepare("SELECT CUST_MOBILE FROM " . tn('TBL_TEMP') . " WHERE ID = ? LIMIT 1");
    } else {
        $q = $link->prepare("SELECT MAINDATABASE_MOBILE FROM " . tn('TBL_MAIN') . " WHERE ID = ? LIMIT 1");
    }
    if (!$q) return null;
    $q->bind_param('i', $leadId);
    $q->execute();
    $row = $q->get_result()->fetch_array();
    $q->close();
    return $row ? (string)$row[0] : null;
}

function isPhoneReservedOrDnd(mysqli $link, string $cleanPhone): bool {
    $dndQ = $link->prepare("SELECT id FROM " . tn('TBL_DND_REGISTRY') . " WHERE phone_number COLLATE utf8mb4_general_ci = ? LIMIT 1");
    if ($dndQ) {
        $dndQ->bind_param('s', $cleanPhone);
        $dndQ->execute();
        $isDnd = ($dndQ->get_result()->fetch_assoc() !== null);
        $dndQ->close();
        if ($isDnd) return true;
    }

    $resQ = $link->prepare("SELECT id FROM lead_reservations WHERE phone_number_10 COLLATE utf8mb4_general_ci = ? AND state IN ('RESERVED', 'DIALING') AND (state = 'DIALING' OR expires_at > NOW()) LIMIT 1");
    if ($resQ) {
        $resQ->bind_param('s', $cleanPhone);
        $resQ->execute();
        $isReserved = ($resQ->get_result()->fetch_assoc() !== null);
        $resQ->close();
        if ($isReserved) return true;
    }

    return false;
}

function formatLeadForClaimPayload(mysqli $link, array $lead): array {
    $source = $lead['source'];
    $leadId = (int)$lead['lead_id'];
    $name = '';
    $company = '';
    $package = '';
    $otherInfo = '';
    $lastStatus = 'Not Called';
    $scheduledTime = null;

    if ($source === 'TEMP') {
        $q = $link->prepare("SELECT CUST_NAME, CUST_COMPANY, CUST_PACKAGE, CUST_OTHER_INFO, CALL_DIALED_STATUS FROM " . tn('TBL_TEMP') . " WHERE ID = ? LIMIT 1");
    } else {
        $q = $link->prepare("SELECT MAINDATABASE_NAME, MAINDATABASE_COMPANY, MAINDATABASE_PACKAGE, MAINDATABASE_OTHER_INFO, MAINDATABASE_CALL_DIALED_STATUS FROM " . tn('TBL_MAIN') . " WHERE ID = ? LIMIT 1");
    }

    if ($q) {
        $q->bind_param('i', $leadId);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        if ($row) {
            $name = (string)($source === 'TEMP' ? $row['CUST_NAME'] : $row['MAINDATABASE_NAME']);
            $company = (string)($source === 'TEMP' ? $row['CUST_COMPANY'] : $row['MAINDATABASE_COMPANY']);
            $package = (string)($source === 'TEMP' ? $row['CUST_PACKAGE'] : $row['MAINDATABASE_PACKAGE']);
            $otherInfo = (string)($source === 'TEMP' ? $row['CUST_OTHER_INFO'] : $row['MAINDATABASE_OTHER_INFO']);
            $lastStatus = (string)($source === 'TEMP' ? ($row['CALL_DIALED_STATUS'] ?: 'Not Called') : ($row['MAINDATABASE_CALL_DIALED_STATUS'] ?: 'Not Called'));
        }
    }

    if (!empty($lead['followup_id'])) {
        $fId = (int)$lead['followup_id'];
        $fq = $link->prepare("SELECT followup_at FROM lead_followups WHERE id = ? LIMIT 1");
        if ($fq) {
            $fq->bind_param('i', $fId);
            $fq->execute();
            $fRow = $fq->get_result()->fetch_assoc();
            $fq->close();
            if ($fRow && !empty($fRow['followup_at'])) {
                $scheduledTime = (string)$fRow['followup_at'];
            }
        }
    }

    $rawPhone = $lead['phone_number_10'];
    $tier = $lead['priority_tier'];
    $tierLabels = [
        'P0' => 'Fresh Never-Called',
        'P1' => 'Due Follow-up',
        'P2' => 'Attempted Unconnected',
        'P3' => 'Recycled Pool'
    ];

    return [
        'id' => $leadId,
        'record_id' => $leadId,
        'lead_id' => $leadId,
        'reservation_token' => $lead['reservation_token'] ?? '',
        'priority' => $tier,
        'priority_group' => $tier,
        'priority_label' => $tierLabels[$tier] ?? $tier,
        'source' => $source,
        'name' => $name,
        'mobile' => $rawPhone,
        'formatted_mobile' => format_indian_phone($rawPhone, 'display'),
        'dial_phone' => format_indian_phone($rawPhone, 'e164'),
        'dial_uri' => format_indian_phone($rawPhone, 'dial_uri'),
        'company' => $company,
        'package' => $package,
        'other_info' => $otherInfo,
        'status' => $lastStatus,
        'last_status' => $lastStatus,
        'scheduled_time' => $scheduledTime,
        'followup_id' => $lead['followup_id'] ?? null
    ];
}
