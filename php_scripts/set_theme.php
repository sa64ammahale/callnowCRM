<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit;
}

$theme = $_POST['theme'] ?? 'light';
$theme = ($theme === 'dark') ? 'dark' : 'light';

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['theme'] = $theme;
}

http_response_code(204);
