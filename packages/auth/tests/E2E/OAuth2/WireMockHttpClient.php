<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\E2E\OAuth2;

use Utopia\Auth\OAuth2\CurlHttpClient;
use Utopia\Auth\OAuth2\HttpClient;

final class WireMockHttpClient implements HttpClient
{
    public function __construct(
        private readonly string $base = 'http://127.0.0.1:18080',
        private readonly HttpClient $inner = new CurlHttpClient('Utopia OAuth2 e2e'),
    ) {}

    /**
     * @param array<int, string> $headers
     */
    public function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $this->inner->request($method, rtrim($this->base, '/') . $path . $query, $headers, $payload);
    }
}
