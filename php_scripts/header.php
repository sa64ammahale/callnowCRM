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
/* DataTables polish (no vendored DT CSS) */
table.dataTable thead .sorting,
table.dataTable thead .sorting_asc,
table.dataTable thead .sorting_desc {
    cursor: pointer;
    position: relative;
    padding-right: 1.5rem;
}
table.dataTable thead .sorting::after,
table.dataTable thead .sorting_asc::after,
table.dataTable thead .sorting_desc::after {
    content: "";
    position: absolute;
    top: 50%;
    right: 0.5rem;
    width: 0;
    height: 0;
    border-left: 5px solid transparent;
    border-right: 5px solid transparent;
    margin-top: -3px;
}
table.dataTable thead .sorting::after { border-bottom: 5px solid #adb5bd; }
table.dataTable thead .sorting_asc::after { border-bottom: 5px solid #6c757d; }
table.dataTable thead .sorting_desc::after { border-top: 5px solid #6c757d; }
table.dataTable .dataTables_wrapper .dt-buttons { display: flex; gap: 0.4rem; flex-wrap: wrap; }
table.dataTable .dataTables_length select { margin: 0 0.5rem; }
table.dataTable .dataTables_filter input { margin: 0 0.5rem; }
table.dataTable .dataTables_info { margin-top: 0.5rem; }
</style>
</head>
<body>
<div class="app-shell">
<?php include __DIR__ . '/sidebar.php'; ?>

<div class="app-main">
<?php include __DIR__ . '/topbar.php'; ?>
<main class="app-content">
