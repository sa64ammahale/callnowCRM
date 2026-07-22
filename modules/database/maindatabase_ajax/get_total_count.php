<?php
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../php_scripts/auth.php';
    
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        echo json_encode(['error' => 'Not logged in', 'count' => 0]);
        exit;
    }
    
    requirePermission('manage_database');
    
    if (!isset($link) || !$link) {
        echo json_encode(['error' => 'No database connection', 'count' => 0]);
        exit;
    }
    
    $result = mysqli_query($link, "SELECT COUNT(*) AS c FROM " . tn('TBL_MAIN'));
    if (!$result) {
        echo json_encode(['error' => 'Query failed: ' . mysqli_error($link), 'count' => 0]);
        exit;
    }
    
    $row = mysqli_fetch_assoc($result);
    $count = isset($row['c']) ? (int)$row['c'] : 0;
    echo json_encode(['error' => null, 'count' => $count]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Exception: ' . $e->getMessage(), 'count' => 0]);
}
?>
