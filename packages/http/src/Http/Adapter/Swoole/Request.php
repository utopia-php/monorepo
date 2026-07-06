<?php

declare(strict_types=1);

namespace Utopia\Http\Adapter\Swoole;

use Swoole\Http\Request as SwooleRequest;
use Utopia\Psr7\ServerRequest;
use Utopia\Psr7\Stream;
use Utopia\Psr7\UploadedFile;
use Utopia\Psr7\Uri;

class Request extends ServerRequest
{
    public function __construct(private readonly SwooleRequest $swoole)
    {
        $rawBody = $swoole->rawContent() ?: '';
        $headers = $this->headersFromSwoole();
        $server = $swoole->server ?? [];
        $method = $server['request_method'] ?? 'UNKNOWN';

        parent::__construct(
            method: (string) $method,
            uri: $this->uriFromSwoole($headers),
            serverParams: $server,
            cookieParams: $swoole->cookie ?? [],
            queryParams: $swoole->get ?? [],
            uploadedFiles: UploadedFile::normalizeFiles($swoole->files ?? []),
            parsedBody: $this->parsedBody((string) $method, $headers, $rawBody),
            body: new Stream($rawBody),
            headers: $headers,
        );
    }

    public function getSwooleRequest(): SwooleRequest
    {
        return $this->swoole;
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

        $contentType = $headers['content-type'] ?? $headers['Content-Type'] ?? '';
        $contentType = \is_array($contentType) ? ($contentType[0] ?? '') : $contentType;
        $contentType = trim(explode(';', (string) $contentType)[0]);

        if ($contentType === 'application/json') {
            $decoded = json_decode($rawBody, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return $this->swoole->post ?? [];
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    private function uriFromSwoole(array $headers): Uri
    {
        $server = $this->swoole->server ?? [];
        $requestUri = (string) ($server['request_uri'] ?? '/');
        $query = (string) ($server['query_string'] ?? '');

        if ($query !== '' && !str_contains($requestUri, '?')) {
            $requestUri .= '?' . $query;
        }

        $host = $headers['host'] ?? $headers['Host'] ?? '';
        $host = \is_array($host) ? ($host[0] ?? '') : $host;

        if ($host === '') {
            return Uri::parse($requestUri);
        }

        return Uri::parse($this->schemeFromSwoole($headers) . '://' . $host . $requestUri);
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    private function schemeFromSwoole(array $headers): string
    {
        $forwarded = $headers['x-forwarded-proto'] ?? $headers['X-Forwarded-Proto'] ?? null;
        $forwarded = \is_array($forwarded) ? ($forwarded[0] ?? null) : $forwarded;

        if (\in_array($forwarded, ['http', 'https', 'ws', 'wss'], true)) {
            return (string) $forwarded;
        }

        return ($this->swoole->server['server_protocol'] ?? '') === 'HTTP/1.1' ? 'http' : 'https';
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    private function headersFromSwoole(): array
    {
        $headers = [];

        foreach ($this->swoole->header ?? [] as $name => $value) {
            $headers[(string) $name] = \is_array($value)
                ? array_values(array_map(strval(...), $value))
                : (string) $value;
        }

        return $headers;
    }
}
