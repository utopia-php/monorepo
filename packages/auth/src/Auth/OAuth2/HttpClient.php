<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

interface HttpClient
{
    /**
     * @param array<int, string> $headers
     *
     * @throws Exception
     */
    public function request(string $method, string $url = '', array $headers = [], string $payload = ''): string;
}
