<?php
require_once "../php_scripts/auth.php";


if (!isset($_POST['ids']) || !is_array($_POST['ids']) || empty($_POST['ids'])) {
    echo json_encode(['success' => false]);
    exit;
}

$ids = array_map('intval', $_POST['ids']);
$placeholders = str_repeat('?,', count($ids)-1) . '?';

$sql = "DELETE FROM MAIN_DATABASE WHERE ID IN ($placeholders)";
$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param($stmt, str_repeat('i', count($ids)), ...$ids);

echo json_encode(['success' => mysqli_stmt_execute($stmt)]);
?>