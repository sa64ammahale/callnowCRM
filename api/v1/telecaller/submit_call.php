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

$input = json_decode(file_get_contents('php://input'), true) ?? [];

global $link;

$required = ['record_id', 'source', 'call_status'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        apiError(400, "Missing required field: $field");
    }
}

$recordId = (int)$input['record_id'];
$rawSource = strtoupper(trim($input['source']));
$source = ($rawSource === 'TEMPORARY' || $rawSource === 'TEMP') ? 'TEMP' : 'MAIN';
$callStatus = trim($input['call_status']);
$callDuration = isset($input['call_duration']) ? (int)$input['call_duration'] : (isset($input['duration_seconds']) ? (int)$input['duration_seconds'] : 0);
$notes = trim($input['notes'] ?? '');
$nextFollowup = !empty($input['next_followup']) ? date('Y-m-d H:i:s', strtotime($input['next_followup'])) : (!empty($input['next_followup_at']) ? date('Y-m-d H:i:s', strtotime($input['next_followup_at'])) : null);
$recordingUrl = !empty($input['recording_url']) ? trim($input['recording_url']) : null;
$callTime = !empty($input['call_time']) ? date('Y-m-d H:i:s', strtotime($input['call_time'])) : date('Y-m-d H:i:s');
$timingTier = trim($input['timing_classification'] ?? '');
if (empty($timingTier)) {
    $timingTier = ($callDuration === 0) ? 'NOT_CONNECTED' : (($callDuration <= 20) ? 'CONNECTED_SHORT' : 'CONNECTED_CONVERSATION');
}
$isConnected = ($callDuration > 0 || in_array(strtolower($callStatus), ['connected', 'interested'])) ? 1 : 0;

// Verify record exists
if ($source === 'TEMP') {
    $stmt = $link->prepare("SELECT ID, CALL_DIALED_TELECALLER FROM " . tn('TBL_TEMP') . " WHERE ID = ?");
    $stmt->bind_param('i', $recordId);
} else {
    $stmt = $link->prepare("SELECT ID, MAINDATABASE_CALL_DIALED_USER FROM " . tn('TBL_MAIN') . " WHERE ID = ?");
    $stmt->bind_param('i', $recordId);
}
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    apiError(404, 'Record not found');
}

$recordUserId = (int)($record['CALL_DIALED_TELECALLER'] ?? $record['MAINDATABASE_CALL_DIALED_USER']);
if ($recordUserId > 0 && $recordUserId !== $userId && $userRole !== 'Admin' && $userRole !== 'Manager') {
    apiError(403, 'Not authorized to update this record');
}

// 1. Update master table based on source
if ($source === 'MAIN') {
    $sql = "UPDATE " . tn('TBL_MAIN') . " SET 
        MAINDATABASE_CALL_DIALED_STATUS = ?,
        MAINDATABASE_CALL_DIALED_USER = ?,
        MAINDATABASE_OTHER_INFO = CASE WHEN ? != '' THEN ? ELSE MAINDATABASE_OTHER_INFO END,
        LAST_DIALED_DATE_TIME = NOW(),
        LAST_CONNECTED_PERIOD = CASE WHEN ? = 1 THEN NOW() ELSE LAST_CONNECTED_PERIOD END,
        CALL_COUNT = COALESCE(CALL_COUNT, 0) + 1,
        BATCH_ASSIGNED_AT = NULL
        WHERE ID = ?";
    $stmt = $link->prepare($sql);
    $stmt->bind_param('sissii', $callStatus, $userId, $notes, $notes, $isConnected, $recordId);
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

// 2. Insert into call logs
$logStmt = $link->prepare("
    INSERT INTO " . tn('TBL_CALL_LOGS') . "
        (record_id, source, user_id, call_status, call_duration, timing_classification, notes, next_followup, recording_url, call_time, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$logStmt->bind_param(
    'isisisssss',
    $recordId,
    $source,
    $userId,
    $callStatus,
    $callDuration,
    $timingTier,
    $notes,
    $nextFollowup,
    $recordingUrl,
    $callTime
);
$logStmt->execute();
$logId = $logStmt->insert_id;
$logStmt->close();

$table = $source === 'TEMP' ? 'TBL_TEMP' : 'TBL_MAIN';
logActivity($link, $userId, 'CALL_SUBMITTED', "Submitted call result for $source record #$recordId", (string)$recordId, $table);

apiSuccess([
    'message' => 'Call result submitted successfully',
    'log_id' => $logId,
    'record_id' => $recordId,
    'timing_classification' => $timingTier,
    'duration' => $callDuration,
]);