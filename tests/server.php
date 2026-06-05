<?php

declare(strict_types=1);

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($requestUri) ? parse_url($requestUri, PHP_URL_PATH) : '/';
$path = is_string($path) ? $path : '/';

if ($path === '/not-found') {
    http_response_code(404);
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'missing';

    return;
}

if ($path === '/server-error') {
    http_response_code(500);
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'failed';

    return;
}

if ($path === '/redirect') {
    http_response_code(302);
    header('Location: /final');
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'redirect';

    return;
}

if ($path === '/headers') {
    http_response_code(204);
    header('X-Trace: one', false);
    header('X-Trace: two', false);
    header('x-Mixed-Case: Value');

    return;
}

if ($path === '/binary') {
    http_response_code(200);
    header('Content-Type: application/octet-stream');
    echo "\x00\x01hello\xff";

    return;
}

http_response_code(202);
header('Content-Type: text/plain;charset=UTF-8');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$method = is_string($requestMethod) ? $requestMethod : '';
$customHeader = $_SERVER['HTTP_X_CUSTOM'] ?? '';
$customHeader = is_string($customHeader) ? $customHeader : '';
$body = file_get_contents('php://input');

echo $method . ':' . $path . ':' . $customHeader . ':' . ($body === false ? '' : $body);
