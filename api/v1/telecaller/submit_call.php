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
$userRole = $tokenData['user_role'];

$input = json_decode(file_get_contents('php://input'), true);

global $link;

$required = ['record_id', 'source', 'call_status'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        apiError(400, "Missing required field: $field");
    }
}

$recordId = (int)$input['record_id'];
$source = $input['source']; // 'TEMP' or 'MAIN'
$callStatus = $input['call_status'];
$callDuration = isset($input['call_duration']) ? (int)$input['call_duration'] : null;
$notes = $input['notes'] ?? '';
$nextFollowup = $input['next_followup'] ?? null;
$recordingUrl = $input['recording_url'] ?? null;

// Validate source
if (!in_array($source, ['TEMP', 'MAIN'])) {
    apiError(400, 'Invalid source. Must be TEMP or MAIN');
}

// Validate call status
$validStatuses = ['Connected', 'Dialed', 'Busy', 'No Answer', 'Do Not Call', 'Pending', 'Not Called', 'Follow Up', 'Interested', 'Call Back'];
if (!in_array($callStatus, $validStatuses)) {
    apiError(400, 'Invalid call status');
}

// Verify record belongs to user
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
if ($recordUserId !== $userId && $userRole !== 'Admin' && $userRole !== 'Manager') {
    apiError(403, 'Not authorized to update this record');
}

$updateFields = [
    'CALL_DIALED_STATUS' => $callStatus,
    'LAST_DIALED_DATE_TIME' => date('Y-m-d H:i:s'),
];
if ($callDuration !== null) $updateFields['CALL_DURATION'] = $callDuration;
if ($notes) $updateFields['CALL_NOTES'] = $notes;
if ($nextFollowup) $updateFields['NEXT_FOLLOWUP_DATE'] = $nextFollowup;
if ($recordingUrl) $updateFields['RECORDING_URL'] = $recordingUrl;

$setClause = [];
$types = '';
$values = [];
foreach ($updateFields as $col => $val) {
    $setClause[] = "$col = ?";
    $types .= 's';
    $values[] = $val;
}
$types .= 'i';
$values[] = $recordId;

$table = $source === 'TEMP' ? tn('TBL_TEMP') : tn('TBL_MAIN');
$sql = "UPDATE $table SET " . implode(', ', $setClause) . " WHERE ID = ?";

$stmt = $link->prepare($sql);
$stmt->bind_param($types, ...$values);
$success = $stmt->execute();
$stmt->close();

if (!$success) {
    apiError(500, 'Failed to update record');
}

// Log activity
logActivity($link, $userId, 'CALL_SUBMITTED', "Submitted call result for $source record #$recordId", (string)$recordId, $table);

apiSuccess([
    'message' => 'Call result submitted successfully',
    'record_id' => $recordId,
    'updated_fields' => array_keys($updateFields),
]);