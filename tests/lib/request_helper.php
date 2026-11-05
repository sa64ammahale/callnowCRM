<?php
function appRequest(string $file, int $userId, array $post = [], array $get = [], string $method = 'POST'): array {
    $root = dirname(__DIR__, 2);
    $payload = base64_encode(json_encode([$post, $get]));
    $code = 'chdir(' . var_export($root, true) . '); session_id("lp" . bin2hex(random_bytes(12))); session_start(); $_SESSION=["loggedin"=>true,"id"=>' . $userId . ',"csrf_token"=>"lp-test-token","LAST_ACTIVITY"=>time()]; '
        . '[$_POST,$_GET]=json_decode(base64_decode(' . var_export($payload, true) . '),true); $_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . '; $_SERVER["HTTP_HOST"]="localhost"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; '
        . 'register_shutdown_function(function(){echo "\\nLP_RESULT:".json_encode(["status"=>http_response_code()?:200,"flash"=>$_SESSION["success_message"]??""]); session_destroy();}); require ' . var_export($root . '/' . $file, true) . ';';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $parts = explode("\nLP_RESULT:", $output);
    return ['body' => $parts[0], 'json' => json_decode($parts[0], true), 'result' => json_decode($parts[1] ?? '{}', true), 'exit' => $exit, 'errors' => $errors];
}

