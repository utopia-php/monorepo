<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\Exception;
use Utopia\Auth\OAuth2\Providers\Github;

final class GithubTest extends TestCase
{
    public function testAccessToken(): void
    {
        $http = new FakeHttpClient(json_encode([
            'access_token' => 'access-token',
            'scope' => 'user:email',
            'token_type' => 'bearer',
        ], JSON_THROW_ON_ERROR));

        $github = new Github('client-id', 'client-secret', 'https://example.com/callback', http: $http);

        $this->assertSame('access-token', $github->getAccessToken('authorization-code'));
        $this->assertSame('POST', $http->calls[0]['method']);
        $this->assertSame('https://github.com/login/oauth/access_token', $http->calls[0]['url']);
    }

    public function testProviderError(): void
    {
        $http = new FakeHttpClient(json_encode([
            'error' => 'bad_verification_code',
            'error_description' => 'The code passed is incorrect or expired.',
        ], JSON_THROW_ON_ERROR), 400);

        $github = new Github('client-id', 'client-secret', 'https://example.com/callback', http: $http);

        try {
            $github->getAccessToken('expired-code');
            $this->fail('Expected the GitHub OAuth2 provider error to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(400, $exception->getCode());
            $this->assertSame('bad_verification_code', $exception->getError());
            $this->assertSame('The code passed is incorrect or expired.', $exception->getErrorDescription());
        }
    }

    public function testMissingAccessToken(): void
    {
        $http = new FakeHttpClient('{}');
        $github = new Github('client-id', 'client-secret', 'https://example.com/callback', http: $http);

        try {
            $github->getAccessToken('authorization-code');
            $this->fail('Expected a missing access token error to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(400, $exception->getCode());
            $this->assertSame('access_token_missing', $exception->getError());
        }
    }

    public function testCreateRepository(): void
    {
        $http = new FakeHttpClient(json_encode([
            'id' => 1,
            'name' => 'demo',
            'private' => true,
        ], JSON_THROW_ON_ERROR));

        $github = new Github('client-id', 'client-secret', 'https://example.com/callback', http: $http);
        $repository = $github->createRepository('access-token', 'demo', true);

        $this->assertSame('demo', $repository['name']);
        $this->assertSame('https://api.github.com/user/repos', $http->calls[0]['url']);
    }
}
