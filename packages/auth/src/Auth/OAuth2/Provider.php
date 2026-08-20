<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

/**
 * OAuth2 relying-party client. Subclasses speak a single identity provider's
 * authorization-code flow: login URL, token exchange, refresh, and user info.
 */
abstract class Provider
{
    protected string $appID;

    protected string $appSecret;

    protected string $callback;

    /**
     * @var array<string, mixed>
     */
    protected array $state;

    /**
     * @var array<int, string>
     */
    protected array $scopes = [];

    protected HttpClient $http;

    protected string $stateEncryptionKey = '';

    /**
     * @param array<string, mixed> $state
     * @param array<int, string> $scopes
     */
    public function __construct(
        string $appId,
        string $appSecret,
        string $callback,
        array $state = [],
        array $scopes = [],
        ?HttpClient $http = null,
        string $stateEncryptionKey = '',
        string $userAgent = 'Utopia OAuth2',
    ) {
        $this->appID = $appId;
        $this->appSecret = $appSecret;
        $this->callback = $callback;
        $this->state = $state;
        $this->http = $http ?? new CurlHttpClient($userAgent);
        $this->stateEncryptionKey = $stateEncryptionKey;
        foreach ($scopes as $scope) {
            $this->addScope($scope);
        }
    }

    abstract public function getName(): string;

    abstract public function getLoginURL(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function getTokens(string $code): array;

    /**
     * @return array<string, mixed>
     */
    abstract public function refreshTokens(string $refreshToken): array;

    abstract public function getUserID(string $accessToken): string;

    abstract public function getUserEmail(string $accessToken): string;

    abstract public function isEmailVerified(string $accessToken): bool;

    abstract public function getUserName(string $accessToken): string;

    protected function addScope(string $scope): self
    {
        if (!\in_array($scope, $this->scopes, true)) {
            $this->scopes[] = $scope;
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    protected function getScopes(): array
    {
        return $this->scopes;
    }

    public function getAccessToken(string $code): string
    {
        $tokens = $this->getTokens($code);

        return $tokens['access_token'] ?? '';
    }

    public function getRefreshToken(string $code): string
    {
        $tokens = $this->getTokens($code);

        return $tokens['refresh_token'] ?? '';
    }

    public function getAccessTokenExpiry(string $code): int
    {
        $tokens = $this->getTokens($code);

        return $tokens['expires_in'] ?? 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parseState(string $state)
    {
        return json_decode($state, true);
    }

    /**
     * @param array<int, string> $headers
     */
    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        return $this->http->request($method, $url, $headers, $payload);
    }
}
