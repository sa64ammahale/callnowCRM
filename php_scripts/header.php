<?php
require_once __DIR__ . '/auth.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: " . (defined('APP_BASE') ? APP_BASE . '/' : '') . "index");
    exit;
}

if (!function_exists('url')) {
    function url(string $path): string {
        $path = ltrim($path, '/');
        $path = preg_replace('/\.php$/', '', $path);
        return (defined('APP_BASE') ? APP_BASE : '') . '/' . $path;
    }
}

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
$faviconUrl = $appLogoUrl ?: 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="%236D45F9"/><path fill="%23fff" d="M11 9a9 9 0 0 0 0 14l1.4-1.4A7 7 0 0 1 12.4 10.4L11 9zm10 0-1.4 1.4A7 7 0 0 1 19.6 21.6L21 23a9 9 0 0 0 0-14zM16 13a3 3 0 0 0-3 3 1 1 0 0 0 2 0 1 1 0 0 1 1-1 1 1 0 0 0 0-2z"/></svg>');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <base href="<?= defined('APP_BASE') ? APP_BASE . '/' : '/' ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard', ENT_QUOTES, 'UTF-8') ?> &bull; CallNow</title>
    <link rel="icon" href="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="<?= vnd('css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= vnd('css/bootstrap-icons.css') ?>" rel="stylesheet">
    <link href="<?= (defined('APP_BASE') && APP_BASE !== '' ? APP_BASE . '/' : '') ?>assets/css/app-theme.css?v=<?= file_exists(__DIR__ . '/../assets/css/app-theme.css') ? filemtime(__DIR__ . '/../assets/css/app-theme.css') : '5.1' ?>" rel="stylesheet">
    <script src="<?= vnd('js/jquery.min.js') ?>"></script>
<style>
/* Modern DataTables Styling (Clean White Canvas Style) */
table.dataTable thead .sorting,
table.dataTable thead th.sorting,
table.dataTable thead td.sorting {
    cursor: pointer;
    position: relative;
    padding-right: 1.75rem !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m7 15 5 5 5-5'/%3E%3Cpath d='m7 9 5-5 5 5'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 0.65rem center !important;
    background-size: 14px 14px !important;
}
table.dataTable thead .sorting_asc,
table.dataTable thead th.sorting_asc,
table.dataTable thead td.sorting_asc {
    cursor: pointer;
    position: relative;
    padding-right: 1.75rem !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236d45f9' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m18 15-6-6-6 6'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 0.65rem center !important;
    background-size: 14px 14px !important;
}
table.dataTable thead .sorting_desc,
table.dataTable thead th.sorting_desc,
table.dataTable thead td.sorting_desc {
    cursor: pointer;
    position: relative;
    padding-right: 1.75rem !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236d45f9' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 0.65rem center !important;
    background-size: 14px 14px !important;
}
table.dataTable thead .sorting::after,
table.dataTable thead .sorting_asc::after,
table.dataTable thead .sorting_desc::after,
table.dataTable thead .sorting::before,
table.dataTable thead .sorting_asc::before,
table.dataTable thead .sorting_desc::before {
    display: none !important;
    content: none !important;
}
</style>
</head>
<body>
<div class="app-shell">
<?php include __DIR__ . '/sidebar.php'; ?>

<div class="app-main">
<?php include __DIR__ . '/topbar.php'; ?>
<main class="app-content">
