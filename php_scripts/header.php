<?php
require_once __DIR__ . '/auth.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: " . (defined('APP_BASE') ? APP_BASE . '/' : '') . "index.php");
    exit;
}

function url(string $path): string {
    $path = ltrim($path, '/');
    return (defined('APP_BASE') ? APP_BASE : '') . '/' . $path;
}

$themeAttr = (($_SESSION['theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $themeAttr ?>">
<head>
    <base href="<?= defined('APP_BASE') ? APP_BASE . '/' : '/' ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard', ENT_QUOTES, 'UTF-8') ?> &bull; CallNow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= defined('APP_BASE') ? APP_BASE . '/assets/css/app-theme.css' : 'assets/css/app-theme.css' ?>" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
<div class="app-shell">
<?php include __DIR__ . '/sidebar.php'; ?>

<div class="app-main">
<?php include __DIR__ . '/topbar.php'; ?>
<main class="app-content">
