<?php
require_once __DIR__ . '/../php_scripts/config.php';

$phone = '8971594009';
$userId = 8;
$dialTime = '2026-11-20 10:57:00';

echo "Updating record 392286 for Shekhar Auti call at 10:57 AM...\n";
$up = $link->prepare("UPDATE " . tn('TBL_MAIN') . " SET 
    MAINDATABASE_CALL_DIALED_STATUS = 'Ringing',
    MAINDATABASE_CALL_DIALED_USER = ?,
    MAINDATABASE_CALL_DIAL_TIME = ?,
    LAST_DIALED_DATE_TIME = ?,
    CALL_COUNT = 1,
    BATCH_ASSIGNED_AT = NULL
    WHERE ID = 392286");
$up->bind_param('iss', $userId, $dialTime, $dialTime);
$up->execute();
echo "Affected rows in MAIN: " . $up->affected_rows . "\n";
$up->close();

$insLog = $link->prepare("INSERT INTO " . tn('TBL_CALL_LOGS') . " (record_id, client_contact_id, source, user_id, call_status, call_duration, total_duration, timing_classification, dial_time, call_time, created_at) VALUES (392286, 392286, 'MAIN', ?, 'Ringing', 0, 0, 'NOT_CONNECTED', ?, ?, ?)");
$insLog->bind_param('isss', $userId, $dialTime, $dialTime, $dialTime);
$insLog->execute();
echo "Inserted call log ID: " . $insLog->insert_id . "\n";
$insLog->close();

echo "Done!\n";
