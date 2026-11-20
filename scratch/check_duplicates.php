<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
putenv('APP_DEBUG=1');
$_SERVER['APP_DEBUG'] = '1';
require_once __DIR__ . '/../php_scripts/config.php';

$res = $link->query("
    SELECT lr.batch_id, lr.telecaller_id, lr.phone_number_10, lr.source, lr.lead_id, lr.state, lr.reserved_at
    FROM lead_reservations lr
    WHERE lr.batch_id >= 1509
    ORDER BY lr.phone_number_10, lr.id ASC
");

$byPhone = [];
while ($row = $res->fetch_assoc()) {
    $byPhone[$row['phone_number_10']][] = $row;
}

echo "Total distinct phones in today's batches: " . count($byPhone) . "\n";
$duplicatePhones = [];
foreach ($byPhone as $phone => $rows) {
    if (count($rows) > 1) {
        $duplicatePhones[$phone] = $rows;
    }
}
echo "DUPLICATE PHONES ACROSS BATCHES: " . count($duplicatePhones) . "\n";
foreach ($duplicatePhones as $phone => $rows) {
    echo "Phone: $phone (count: " . count($rows) . ")\n";
    foreach ($rows as $r) {
        echo "  -> Batch: {$r['batch_id']}, Telecaller: {$r['telecaller_id']}, State: {$r['state']}, ReservedAt: {$r['reserved_at']}\n";
    }
}
