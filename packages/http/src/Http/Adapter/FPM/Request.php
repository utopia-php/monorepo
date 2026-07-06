<?php

declare(strict_types=1);

namespace Utopia\Http\Adapter\FPM;

use Utopia\Psr7\ServerRequest;
use Utopia\Psr7\Stream;
use Utopia\Psr7\UploadedFile;
use Utopia\Psr7\Uri;

class Request extends ServerRequest
{
    public function __construct()
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $headers = $this->headersFromGlobals();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';

        parent::__construct(
            method: $method,
            uri: $this->uriFromGlobals(),
            serverParams: $_SERVER,
            cookieParams: $_COOKIE,
            queryParams: $_GET,
            uploadedFiles: UploadedFile::normalizeFiles($_FILES),
            parsedBody: $this->parsedBody($method, $headers, $rawBody),
            body: new Stream($rawBody),
            headers: $headers,
        );
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     * @return array<string, mixed>|null
     */
    private function parsedBody(string $method, array $headers, string $rawBody): ?array
    {
        if (!\in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
        $contentType = \is_array($contentType) ? ($contentType[0] ?? '') : $contentType;
        $contentType = trim(explode(';', (string) $contentType)[0]);

        if ($contentType === 'application/json') {
            $decoded = json_decode($rawBody, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    private function uriFromGlobals(): Uri
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $host = $_SERVER['HTTP_HOST'] ?? '';

        if ($host === '') {
            return Uri::parse($requestUri);
        }

        return Uri::parse($this->schemeFromGlobals() . '://' . $host . $requestUri);
    }

    private function schemeFromGlobals(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return (string) $_SERVER['HTTP_X_FORWARDED_PROTO'];
        }

        if (!empty($_SERVER['REQUEST_SCHEME'])) {
            return (string) $_SERVER['REQUEST_SCHEME'];
        }

        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') {
            return 'https';
        }

        return 'http';
    }

    /**
     * @return array<string, string>
     */
    private function headersFromGlobals(): array
    {
        if (\function_exists('getallheaders')) {
            /** @var array<string, string> $headers */
            $headers = getallheaders();

            return $headers;
        }

        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$header] = (string) $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }
}
