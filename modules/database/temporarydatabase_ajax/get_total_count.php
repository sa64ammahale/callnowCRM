<?php
require_once __DIR__ . '/../../../php_scripts/auth.php';
require_once __DIR__ . '/../../../config.php';

requirePermission('manage_database');

$total = mysqli_fetch_assoc(mysqli_query($link, "SELECT COUNT(*) AS c FROM " . tn('TBL_TEMP')))['c'] ?? 0;
header('Content-Type: application/json');
echo json_encode(['error' => null, 'count' => (int)$total]);
?>