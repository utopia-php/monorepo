<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

final class CurlHttpClient implements HttpClient
{
    public function __construct(private readonly string $userAgent = 'Utopia OAuth2') {}

    /**
     * @param array<int, string> $headers
     */
    public function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);

        if (!empty($payload)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $headers[] = 'Content-length: ' . \strlen($payload);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 400) {
            throw new Exception(\is_string($response) ? $response : '', $code);
        }

        return \is_string($response) ? $response : '';
    }
}
