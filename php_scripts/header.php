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

// Resolve the uploaded application logo for the favicon / shortcut icon
$appLogoUrl = '';
if (isset($link) && $link) {
    try {
        $logoRes = $link->query("SELECT setting_value FROM " . tn('TBL_APP_SETTINGS') . " WHERE setting_key = 'logo_path'");
        if ($logoRes && $row = $logoRes->fetch_assoc()) {
            $appLogoUrl = (defined('APP_BASE') ? APP_BASE : '') . '/' . ltrim($row['setting_value'], '/');
        }
    } catch (\Throwable $e) {
        $appLogoUrl = '';
    }
}
$faviconUrl = $appLogoUrl ?: 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="%235e6ad2"/><path fill="%23fff" d="M11 9a9 9 0 0 0 0 14l1.4-1.4A7 7 0 0 1 12.4 10.4L11 9zm10 0-1.4 1.4A7 7 0 0 1 19.6 21.6L21 23a9 9 0 0 0 0-14zM16 13a3 3 0 0 0-3 3 1 1 0 0 0 2 0 1 1 0 0 1 1-1 1 1 0 0 0 0-2z"/></svg>');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $themeAttr ?>">
<head>
    <base href="<?= defined('APP_BASE') ? APP_BASE . '/' : '/' ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard', ENT_QUOTES, 'UTF-8') ?> &bull; CallNow</title>
    <link rel="icon" href="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>" type="image/x-icon">
    <link href="<?= vnd('css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= vnd('css/bootstrap-icons.css') ?>" rel="stylesheet">
    <link href="<?= defined('APP_BASE') ? APP_BASE . '/assets/css/app-theme.css' : 'assets/css/app-theme.css' ?>" rel="stylesheet">
    <script src="<?= vnd('js/jquery.min.js') ?>"></script>
</head>
<body>
<div class="app-shell">
<?php include __DIR__ . '/sidebar.php'; ?>

<div class="app-main">
<?php include __DIR__ . '/topbar.php'; ?>
<main class="app-content">
