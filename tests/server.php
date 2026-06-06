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

if ($path === '/request-headers') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = is_string($host) ? $host : '';
    $trace = $_SERVER['HTTP_X_TRACE'] ?? '';
    $trace = is_string($trace) ? $trace : '';

    echo $host . ':' . $trace;

    return;
}

if ($path === '/request-target') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo is_string($requestUri) ? $requestUri : '';

    return;
}

if ($path === '/space%20name') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo is_string($requestUri) ? $requestUri : '';

    return;
}

if ($path === '/' && is_string($requestUri) && str_contains($requestUri, 'ping=1')) {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo $requestUri;

    return;
}

if ($path === '/method') {
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
    $method = is_string($requestMethod) ? $requestMethod : '';

    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');
    header('X-Request-Method: ' . $method);

    echo $method;

    return;
}

if ($path === '/body-info') {
    $body = file_get_contents('php://input');
    $body = $body === false ? '' : $body;

    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo strlen($body) . ':' . hash('sha256', $body);

    return;
}

if ($path === '/selected-headers') {
    $comma = $_SERVER['HTTP_X_COMMA'] ?? '';
    $comma = is_string($comma) ? $comma : '';
    $zero = $_SERVER['HTTP_X_ZERO'] ?? '';
    $zero = is_string($zero) ? $zero : '';
    $mixed = $_SERVER['HTTP_X_MIXED_REQUEST'] ?? '';
    $mixed = is_string($mixed) ? $mixed : '';

    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo $comma . ':' . $zero . ':' . $mixed;

    return;
}

if ($path === '/large-response') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo str_repeat('abcd', 65_536);

    return;
}

if ($path === '/stream') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    for ($i = 0; $i < 5; $i++) {
        echo 'chunk' . $i . "\n";
        flush();
        usleep(20_000);
    }

    return;
}

if ($path === '/stream-large') {
    $chunkSize = 65_536;
    $chunkCount = 128;

    http_response_code(200);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . ($chunkSize * $chunkCount));

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    $chunk = str_repeat('a', $chunkSize);

    for ($i = 0; $i < $chunkCount; $i++) {
        echo $chunk;
        flush();
    }

    return;
}

if ($path === '/slow') {
    sleep(1);
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'slow';

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
