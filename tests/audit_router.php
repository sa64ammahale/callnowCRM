<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/callnowCRM#', '', $path);
if (preg_match('#^/api/(?:v1/)?(auth|telecaller|admin)(?:/|$)#', $path, $match)) {
    require dirname(__DIR__) . '/api/v1/' . $match[1] . '/index.php';
    return true;
}
$file = realpath(dirname(__DIR__) . $path);
if (!$file && pathinfo($path, PATHINFO_EXTENSION) === '') $file = realpath(dirname(__DIR__) . $path . '.php');
if ($file && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', dirname(__DIR__)) . '/')) {
    if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        require $file;
        return true;
    }
}
http_response_code(404);
echo 'Not found';
