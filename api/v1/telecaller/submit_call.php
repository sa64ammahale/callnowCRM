<?php
require_once __DIR__ . '/../../../php_scripts/api_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ensureApiSchema($GLOBALS['link']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Method not allowed');
}

$tokenData = apiRequireAuth();
$userId = (int)$tokenData['user_id'];
$userRole = $tokenData['user_role'] ?? 'Telecaller';

$input = apiReadJson();
if (isset($callCompletionRecordId)) {
    $input['record_id'] = $callCompletionRecordId;
    $input['source'] = $input['source'] ?? 'MAIN';
    $input['call_status'] = $input['call_status'] ?? ($input['status'] ?? 'Connected');
}

global $link;

// Resolve phone number if record_id is a phone number or explicit phone fields are provided
$rawRecordId = $input['record_id'] ?? null;
$rawPhone = $input['phone_number'] ?? ($input['mobile'] ?? ($input['phone'] ?? ($input['customer_mobile'] ?? null)));

// If record_id is a phone string (e.g. 10+ digits, "+91...", or > 2147483647), treat it as phone number
if ($rawRecordId !== null) {
    if (is_string($rawRecordId) && preg_match('/^[0-9+ ]{10,}$/', trim($rawRecordId))) {
        if (!$rawPhone) $rawPhone = trim($rawRecordId);
        unset($input['record_id']);
    } elseif (is_numeric($rawRecordId) && (float)$rawRecordId > 2147483647) {
        if (!$rawPhone) $rawPhone = (string)$rawRecordId;
        unset($input['record_id']);
    }
}

if ((empty($input['record_id']) || (int)$input['record_id'] <= 0) && !empty($rawPhone)) {
    $cleanPhone = preg_replace('/[^0-9]/', '', (string)$rawPhone);
    if (strlen($cleanPhone) > 10) {
        $cleanPhone = substr($cleanPhone, -10);
    }
    if (strlen($cleanPhone) >= 10) {
        $qM = $link->prepare("SELECT ID FROM " . tn('TBL_MAIN') . " WHERE MAINDATABASE_MOBILE = ? LIMIT 1");
        $qM->bind_param('s', $cleanPhone);
        $qM->execute();
        $mRow = $qM->get_result()->fetch_assoc();
        $qM->close();

        if ($mRow) {
            $input['record_id'] = (int)$mRow['ID'];
            $input['source'] = 'MAIN';
        } else {
            $qT = $link->prepare("SELECT ID FROM " . tn('TBL_TEMP') . " WHERE CUST_MOBILE = ? LIMIT 1");
            $qT->bind_param('s', $cleanPhone);
            $qT->execute();
            $tRow = $qT->get_result()->fetch_assoc();
            $qT->close();

            if ($tRow) {
                $input['record_id'] = (int)$tRow['ID'];
                $input['source'] = 'TEMP';
            } else {
                $custName = trim($input['customer_name'] ?? ($input['name'] ?? 'Direct Dialed'));
                $insM = $link->prepare("INSERT INTO " . tn('TBL_MAIN') . " (MAINDATABASE_NAME, MAINDATABASE_MOBILE, MAINDATABASE_CALL_DIALED_USER, MAINDATABASE_UPLOAD_DATETIME) VALUES (?, ?, ?, NOW())");
                $insM->bind_param('ssi', $custName, $cleanPhone, $userId);
                $insM->execute();
                $input['record_id'] = (int)$insM->insert_id;
                $input['source'] = 'MAIN';
                $insM->close();
            }
        }
    }
}

if (!empty($input['status']) && empty($input['call_status'])) {
    $input['call_status'] = $input['status'];
}
if (empty($input['source'])) {
    $input['source'] = 'MAIN';
}

foreach (['call_duration', 'duration_seconds', 'total_duration', 'record_id'] as $field) {
    if (isset($input[$field]) && (filter_var($input[$field], FILTER_VALIDATE_INT) === false || (int)$input[$field] < 0 || (int)$input[$field] > 2147483647)) {
        apiError(400, 'Invalid nonnegative integer: ' . $field);
    }
}
foreach (['call_time', 'dial_time', 'call_end_time', 'next_followup', 'next_followup_at'] as $field) {
    if (!empty($input[$field]) && (strtotime((string)$input[$field]) === false || (int)date('Y', strtotime((string)$input[$field])) < 1970)) {
        apiError(400, 'Invalid date: ' . $field);
    }
}
foreach (['call_status' => 50, 'sim_slot' => 50, 'timing_classification' => 30, 'recording_url' => 500, 'notes' => 16000] as $field => $max) {
    if (isset($input[$field]) && mb_strlen((string)$input[$field], 'UTF-8') > $max) apiError(400, 'Value too long: ' . $field);
}
if (!empty($input['recording_url']) && (!filter_var($input['recording_url'], FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($input['recording_url'], PHP_URL_SCHEME) ?? ''), ['http', 'https'], true))) {
    apiError(400, 'Recording URL must use HTTP or HTTPS');
}

$required = ['record_id', 'source', 'call_status'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        apiError(400, "Missing required field: $field");
    }
}

$recordId = (int)$input['record_id'];
$rawSource = strtoupper(trim($input['source']));
if (!in_array($rawSource, ['MAIN', 'TEMP', 'TEMPORARY'], true)) {
    apiError(400, 'Invalid source. Use MAIN or TEMP');
}
$source = ($rawSource === 'TEMPORARY' || $rawSource === 'TEMP') ? 'TEMP' : 'MAIN';
$rawCallStatus = trim($input['call_status']);
if ($rawCallStatus === '') apiError(400, 'Call status is required');
$rawStatusLow = strtolower($rawCallStatus);
$isDnd = in_array($rawStatusLow, ['dnd', 'do not call', 'dnc', 'dnd number', 'do not disturb', 'do_not_call', 'do_not_disturb', 'not_connected_dnd'])
    || str_contains($rawStatusLow, 'dnd')
    || str_contains($rawStatusLow, 'disturb')
    || str_contains($rawStatusLow, 'not call');
$callStatus = $isDnd ? 'Do Not Call' : $rawCallStatus;

$callDuration = isset($input['call_duration']) ? (int)$input['call_duration'] : (isset($input['duration_seconds']) ? (int)$input['duration_seconds'] : 0);
$notes = trim($input['notes'] ?? '');
$nextFollowup = !empty($input['next_followup']) ? date('Y-m-d H:i:s', strtotime($input['next_followup'])) : (!empty($input['next_followup_at']) ? date('Y-m-d H:i:s', strtotime($input['next_followup_at'])) : null);
$recordingUrl = !empty($input['recording_url']) ? trim($input['recording_url']) : null;
$callTime = !empty($input['call_time']) ? date('Y-m-d H:i:s', strtotime($input['call_time'])) : date('Y-m-d H:i:s');
$dialTime = !empty($input['dial_time']) ? date('Y-m-d H:i:s', strtotime($input['dial_time'])) : null;
$callEndTime = !empty($input['call_end_time']) ? date('Y-m-d H:i:s', strtotime($input['call_end_time'])) : null;
$totalDuration = isset($input['total_duration']) ? (int)$input['total_duration'] : $callDuration;
$simSlot = !empty($input['sim_slot']) ? trim((string)$input['sim_slot']) : null;

// Store extra payload fields or explicit metadata JSON
if (isset($input['metadata'])) {
    $metadata = is_array($input['metadata']) ? json_encode($input['metadata'], JSON_UNESCAPED_UNICODE) : (string)$input['metadata'];
} else {
    $knownKeys = ['record_id', 'lead_id', 'id', 'source', 'call_status', 'status', 'notes', 'call_duration', 'duration_seconds', 'timing_classification', 'next_followup', 'next_followup_at', 'recording_url', 'call_time', 'dial_time', 'call_end_time', 'total_duration', 'sim_slot'];
    $extra = array_diff_key($input, array_flip($knownKeys));
    $metadata = !empty($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null;
}

$timingTier = trim($input['timing_classification'] ?? '');
if (empty($timingTier)) {
    $timingTier = ($callDuration === 0) ? 'NOT_CONNECTED' : (($callDuration <= 20) ? 'CONNECTED_SHORT' : 'CONNECTED_CONVERSATION');
}
$isConnected = ($callDuration > 0 || in_array(strtolower($callStatus), ['connected', 'interested', 'lead'])) ? 1 : 0;
$clientCallId = isset($input['client_call_id']) ? trim((string)$input['client_call_id']) : null;
if ($clientCallId === '') $clientCallId = null;
if ($clientCallId !== null && strlen($clientCallId) > 128) apiError(400, 'client_call_id is too long');
ksort($input);
$requestHash = hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE));
$replay = static function () use ($link, $userId, $clientCallId, $requestHash): ?array {
    if ($clientCallId === null) return null;
    $query = $link->prepare('SELECT id, record_id, source, request_hash, call_duration, total_duration, timing_classification FROM ' . tn('TBL_CALL_LOGS') . ' WHERE user_id = ? AND client_call_id = ?');
    $query->bind_param('is', $userId, $clientCallId);
    $query->execute();
    $saved = $query->get_result()->fetch_assoc();
    $query->close();
    if (!$saved) return null;
    if (!hash_equals((string)$saved['request_hash'], $requestHash)) apiError(409, 'client_call_id already used for a different call payload');
    return ['message' => 'Call result already submitted', 'log_id' => (int)$saved['id'], 'record_id' => (int)$saved['record_id'], 'source' => $saved['source'], 'duration' => (int)$saved['call_duration'], 'total_duration' => (int)$saved['total_duration'], 'timing_classification' => $saved['timing_classification'], 'replayed' => true];
};
if ($saved = $replay()) apiSuccess($saved);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$link->begin_transaction();
try {

// Verify record exists and get customer phone number
if ($source === 'TEMP') {
    $stmt = $link->prepare("SELECT ID, CUST_MOBILE, CALL_DIALED_TELECALLER FROM " . tn('TBL_TEMP') . " WHERE ID = ? FOR UPDATE");
    $stmt->bind_param('i', $recordId);
} else {
    $stmt = $link->prepare("SELECT ID, MAINDATABASE_MOBILE, MAINDATABASE_CALL_DIALED_USER FROM " . tn('TBL_MAIN') . " WHERE ID = ? FOR UPDATE");
    $stmt->bind_param('i', $recordId);
}
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    apiError(404, 'Record not found');
}

$targetMobile = (string)($source === 'TEMP' ? ($record['CUST_MOBILE'] ?? '') : ($record['MAINDATABASE_MOBILE'] ?? ''));
$cleanTargetMobile = preg_replace('/\D/', '', $targetMobile);
if (strlen($cleanTargetMobile) > 10) $cleanTargetMobile = substr($cleanTargetMobile, -10);

if ($cleanTargetMobile !== '') {
    $qDndCheck = $link->prepare("SELECT id FROM " . tn('TBL_DND_REGISTRY') . " WHERE phone_number = ? LIMIT 1");
    if ($qDndCheck) {
        $qDndCheck->bind_param('s', $cleanTargetMobile);
        $qDndCheck->execute();
        $isAlreadyDnd = ($qDndCheck->get_result()->fetch_assoc() !== null);
        $qDndCheck->close();
        if ($isAlreadyDnd && !$isDnd) {
            $link->rollback();
            apiError(403, 'This number is registered on Do-Not-Disturb (DND) and cannot be called under any condition.');
        }
    }
}

$recordUserId = (int)($record['CALL_DIALED_TELECALLER'] ?? $record['MAINDATABASE_CALL_DIALED_USER']);
if ($recordUserId > 0 && $recordUserId !== $userId && !in_array($userRole, ['Admin', 'Manager', 'Super Admin'], true) && $userId !== 1) {
    apiError(403, 'Not authorized to update this record');
}

// 1. Update master table based on source
if ($source === 'MAIN') {
    $sql = "UPDATE " . tn('TBL_MAIN') . " SET 
        MAINDATABASE_CALL_DIALED_STATUS = ?,
        MAINDATABASE_CALL_DIALED_USER = ?,
        MAINDATABASE_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_OTHER_INFO END,
        MAINDATABASE_CALL_DIAL_TIME = COALESCE(?, NOW()),
        LAST_DIALED_DATE_TIME = NOW(),
        LAST_CONNECTED_PERIOD = CASE WHEN ? = 1 THEN NOW() ELSE LAST_CONNECTED_PERIOD END,
        CALL_COUNT = COALESCE(CALL_COUNT, 0) + 1,
        BATCH_ASSIGNED_AT = NULL
        WHERE ID = ?";
    $stmt = $link->prepare($sql);
    $stmt->bind_param('sisssii', $callStatus, $userId, $notes, $notes, $dialTime, $isConnected, $recordId);
    $stmt->execute();
    $stmt->close();
} else {
    $sql = "UPDATE " . tn('TBL_TEMP') . " SET 
        CALL_DIALED_STATUS = ?,
        CALL_DIALED_TELECALLER = ?,
        CUST_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE CUST_OTHER_INFO END,
        LAST_DIALED_DATE_TIME = NOW(),
        LAST_CONNECTED_PERIOD = CASE WHEN ? = 1 THEN NOW() ELSE LAST_CONNECTED_PERIOD END,
        BATCH_ASSIGNED_AT = NULL
        WHERE ID = ?";
    $stmt = $link->prepare($sql);
    $stmt->bind_param('sissii', $callStatus, $userId, $notes, $notes, $isConnected, $recordId);
    $stmt->execute();
    $stmt->close();
}

// If marked as DND, strictly register and synchronize across ALL occurrences of this phone number in both tables
if ($isDnd && $cleanTargetMobile !== '') {
    $dndReason = $notes !== '' ? $notes : 'Customer Requested DND';
    $insDnd = $link->prepare("INSERT IGNORE INTO " . tn('TBL_DND_REGISTRY') . " (phone_number, reason, source, created_by, created_at) VALUES (?, ?, 'CALL_RESULT', ?, NOW())");
    if ($insDnd) {
        $insDnd->bind_param('ssi', $cleanTargetMobile, $dndReason, $userId);
        $insDnd->execute();
        $insDnd->close();
    }

    foreach ([
        "UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_CALL_DIALED_STATUS = 'Do Not Call', BATCH_ASSIGNED_AT = NULL, LAST_DIALED_DATE_TIME = NOW(), MAINDATABASE_CALL_DIAL_TIME = NOW() WHERE MAINDATABASE_MOBILE LIKE ?",
        "UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_STATUS = 'Do Not Call', BATCH_ASSIGNED_AT = NULL, LAST_DIALED_DATE_TIME = NOW() WHERE CUST_MOBILE LIKE ?"
    ] as $dndSql) {
        $dndStmt = $link->prepare($dndSql);
        $likeMobile = "%" . $cleanTargetMobile;
        $dndStmt->bind_param('s', $likeMobile);
        $dndStmt->execute();
        $dndStmt->close();
    }

    $link->query("UPDATE " . tn('TBL_LEAD_FOLLOWUPS') . " SET status = 'CANCELLED', note = CONCAT(COALESCE(note,''), ' [DND Registered]') WHERE status = 'OPEN' AND (client_contact_id = $recordId OR lead_id IN (SELECT lead_id FROM " . tn('TBL_LEADS') . " WHERE MOBILE LIKE '%$cleanTargetMobile'))");
}

// 2. Insert into call logs
$logStmt = $link->prepare("
    INSERT INTO " . tn('TBL_CALL_LOGS') . "
        (record_id, client_contact_id, source, user_id, call_status, call_duration, total_duration, timing_classification, notes, metadata, next_followup, recording_url, sim_slot, call_time, dial_time, call_end_time, client_call_id, request_hash, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$logStmt->bind_param(
    'iisisiisssssssssss',
    $recordId,
    $recordId,
    $source,
    $userId,
    $callStatus,
    $callDuration,
    $totalDuration,
    $timingTier,
    $notes,
    $metadata,
    $nextFollowup,
    $recordingUrl,
    $simSlot,
    $callTime,
    $dialTime,
    $callEndTime,
    $clientCallId,
    $requestHash
);
$logStmt->execute();
$logId = $logStmt->insert_id;
$logStmt->close();

$table = $source === 'TEMP' ? 'TBL_TEMP' : 'TBL_MAIN';
logActivity($link, $userId, 'CALL_SUBMITTED', "Submitted call result for $source record #$recordId", (string)$recordId, $table);

// Convert to CRM Leads table (TBL_LEADS) if status is Lead or Follow-up (strictly never for DND)
$syncedLeadId = $isDnd ? null : syncCallToLeadsAndPipeline($link, $source, $recordId, $userId, $callStatus, $notes, $nextFollowup, $input['loan_qualification'] ?? ($input['loan_details'] ?? null), $logId);
if ($syncedLeadId && $syncedLeadId > 0) {
    $updLog = $link->prepare("UPDATE " . tn('TBL_CALL_LOGS') . " SET lead_id = ? WHERE id = ?");
    if ($updLog) {
        $updLog->bind_param('ii', $syncedLeadId, $logId);
        $updLog->execute();
        $updLog->close();
    }
}
$link->commit();
} catch (Throwable $e) {
    $link->rollback();
    if ((int)$e->getCode() === 1062 && ($saved = $replay())) apiSuccess($saved);
    error_log('Call submission failed: ' . $e->getMessage());
    apiError(500, 'Unable to save call result');
}

apiSuccess([
    'message' => 'Call result submitted successfully',
    'log_id' => $logId,
    'record_id' => $recordId,
    'source' => $source,
    'lead_id' => $syncedLeadId,
    'is_lead_generated' => $syncedLeadId !== null && $syncedLeadId > 0,
    'timing_classification' => $timingTier,
    'duration' => $callDuration,
    'total_duration' => $totalDuration,
]);
