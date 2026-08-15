<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use Utopia\Auth\OAuth2\Exception;
use Utopia\Auth\OAuth2\HttpClient;

final class FakeHttpClient implements HttpClient
{
    /**
     * @var array<int, array{method: string, url: string, headers: array<int, string>, payload: string}>
     */
    public array $calls = [];

    public function __construct(
        private readonly string $response = '{"access_token":"access-token"}',
        private readonly int $code = 200,
    ) {}

    /**
     * @param array<int, string> $headers
     */
    public function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'payload' => $payload,
        ];

        if ($this->code >= 400) {
            throw new Exception($this->response, $this->code);
        }

        return $this->response;
    }
}
