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
$rawCallStatus = trim($input['call_status']);
$isDnd = in_array(strtolower($rawCallStatus), ['dnd', 'do not call', 'dnc']);
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
$isConnected = ($callDuration > 0 || in_array(strtolower($callStatus), ['connected', 'interested'])) ? 1 : 0;

// Verify record exists and get customer phone number
if ($source === 'TEMP') {
    $stmt = $link->prepare("SELECT ID, CUST_MOBILE, CALL_DIALED_TELECALLER FROM " . tn('TBL_TEMP') . " WHERE ID = ?");
    $stmt->bind_param('i', $recordId);
} else {
    $stmt = $link->prepare("SELECT ID, MAINDATABASE_MOBILE, MAINDATABASE_CALL_DIALED_USER FROM " . tn('TBL_MAIN') . " WHERE ID = ?");
    $stmt->bind_param('i', $recordId);
}
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    apiError(404, 'Record not found');
}

$targetMobile = (string)($source === 'TEMP' ? ($record['CUST_MOBILE'] ?? '') : ($record['MAINDATABASE_MOBILE'] ?? ''));

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

// If marked as DND, strictly synchronize across ALL occurrences of this phone number in both tables
if ($isDnd && $targetMobile !== '') {
    $targetMobileSafe = $link->real_escape_string($targetMobile);
    $link->query("UPDATE " . tn('TBL_MAIN') . " SET MAINDATABASE_CALL_DIALED_STATUS = 'Do Not Call', BATCH_ASSIGNED_AT = NULL, LAST_DIALED_DATE_TIME = NOW(), MAINDATABASE_CALL_DIAL_TIME = NOW() WHERE MAINDATABASE_MOBILE = '$targetMobileSafe'");
    $link->query("UPDATE " . tn('TBL_TEMP') . " SET CALL_DIALED_STATUS = 'Do Not Call', BATCH_ASSIGNED_AT = NULL, LAST_DIALED_DATE_TIME = NOW() WHERE CUST_MOBILE = '$targetMobileSafe'");
}

// 2. Insert into call logs
$logStmt = $link->prepare("
    INSERT INTO " . tn('TBL_CALL_LOGS') . "
        (record_id, source, user_id, call_status, call_duration, total_duration, timing_classification, notes, metadata, next_followup, recording_url, sim_slot, call_time, dial_time, call_end_time, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$logStmt->bind_param(
    'isisiisssssssss',
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
    $callEndTime
);
$logStmt->execute();
$logId = $logStmt->insert_id;
$logStmt->close();

$table = $source === 'TEMP' ? 'TBL_TEMP' : 'TBL_MAIN';
logActivity($link, $userId, 'CALL_SUBMITTED', "Submitted call result for $source record #$recordId", (string)$recordId, $table);

// Convert to CRM Leads table (TBL_LEADS) if status is Lead or Follow-up
$syncedLeadId = syncCallToLeadsAndPipeline($link, $source, $recordId, $userId, $callStatus, $notes, $nextFollowup);

apiSuccess([
    'message' => 'Call result submitted successfully',
    'log_id' => $logId,
    'record_id' => $recordId,
    'lead_id' => $syncedLeadId,
    'timing_classification' => $timingTier,
    'duration' => $callDuration,
    'total_duration' => $totalDuration,
]);