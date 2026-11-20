<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lead_distribution_engine.php';

ensureLeadEngineSchema($link);

$link->query("UPDATE lead_reservations SET state = 'RELEASED' WHERE state IN ('RESERVED', 'DIALING')");
$link->query("UPDATE lead_batches SET status = 'RELEASED' WHERE status = 'ACTIVE'");

$link->query("UPDATE " . tn('TBL_TEMP') . " 
    SET BATCH_ASSIGNED_AT = NULL, CALL_DIALED_TELECALLER = NULL 
    WHERE (CALL_DIALED_STATUS IS NULL OR CALL_DIALED_STATUS = '' OR CALL_DIALED_STATUS = 'Not Called' OR CALL_DIALED_STATUS = 'Pending')");

$link->query("UPDATE " . tn('TBL_MAIN') . " 
    SET BATCH_ASSIGNED_AT = NULL, MAINDATABASE_CALL_DIALED_USER = NULL 
    WHERE (MAINDATABASE_CALL_DIALED_STATUS IS NULL OR MAINDATABASE_CALL_DIALED_STATUS = '' OR MAINDATABASE_CALL_DIALED_STATUS = 'Not Called' OR MAINDATABASE_CALL_DIALED_STATUS = 'Pending')");

$link->query("UPDATE users SET session_epoch = session_epoch + 1 WHERE status = 'Active'");

$officersQuery = $link->query("SELECT ID, name, email, role FROM users WHERE status = 'Active' AND (role = 'Officer' OR role = 'Super Admin' OR role = 'Admin') ORDER BY ID ASC");
$callingEmployees = [];
if ($officersQuery) {
    while ($row = $officersQuery->fetch_assoc()) {
        $callingEmployees[] = $row;
    }
}

$results = [];
$assignedPhones = [];
$duplicateCount = 0;

foreach ($callingEmployees as $emp) {
    $uid = (int)$emp['ID'];
    $claimId = 'reassign_' . time() . '_' . $uid;
    $epoch = 1;
    $epStmt = $link->prepare("SELECT session_epoch FROM users WHERE ID = ? LIMIT 1");
    if ($epStmt) {
        $epStmt->bind_param('i', $uid);
        $epStmt->execute();
        $epRow = $epStmt->get_result()->fetch_assoc();
        $epStmt->close();
        if ($epRow && isset($epRow['session_epoch'])) {
            $epoch = (int)$epRow['session_epoch'];
        }
    }

    $claim = claimLeadBatch($link, $uid, 'default', 'reassign_script', $epoch, $claimId, 15, false);
    $leads = $claim['leads'] ?? [];
    $employeePhones = [];

    foreach ($leads as $l) {
        $phone = $l['mobile'] ?? '';
        $cleanPhone = normalize_indian_phone($phone, true);
        if ($cleanPhone) {
            if (isset($assignedPhones[$cleanPhone])) {
                $duplicateCount++;
                error_log("DUPLICATE PHONE DETECTED: $cleanPhone already assigned to User " . $assignedPhones[$cleanPhone] . ", attempted for User $uid");
            } else {
                $assignedPhones[$cleanPhone] = $uid;
            }
            $employeePhones[] = $cleanPhone;
        }
    }

    $results[] = [
        'user_id' => $uid,
        'name' => $emp['name'],
        'role' => $emp['role'],
        'batch_uuid' => $claim['batch_uuid'] ?? null,
        'allocated_count' => count($leads),
        'phones' => $employeePhones
    ];
}

$response = [
    'success' => true,
    'total_employees_assigned' => count($results),
    'total_unique_phones_allocated' => count($assignedPhones),
    'duplicate_phone_collisions' => $duplicateCount,
    'assignments' => $results
];

if (php_sapi_name() === 'cli') {
    echo json_encode($response, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    header('Content-Type: application/json');
    echo json_encode($response);
}
