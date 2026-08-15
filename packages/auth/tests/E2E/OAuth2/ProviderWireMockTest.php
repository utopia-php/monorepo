<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\E2E\OAuth2;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\Providers\Appwrite;
use Utopia\Auth\OAuth2\Providers\Bitbucket;
use Utopia\Auth\OAuth2\Providers\Gitea;
use Utopia\Auth\OAuth2\Providers\Github;
use Utopia\Auth\OAuth2\Providers\Gitlab;
use Utopia\Auth\OAuth2\Providers\Google;

final class ProviderWireMockTest extends TestCase
{
    private WireMockHttpClient $http;

    protected function setUp(): void
    {
        $base = getenv('WIREMOCK_URL') ?: 'http://127.0.0.1:18080';
        $this->http = new WireMockHttpClient($base);
    }

    public function testGithubLoginExchangeAndRepository(): void
    {
        $github = new Github('client-id', 'client-secret', 'https://example.com/callback', http: $this->http);

        $this->assertSame('gho-access', $github->getAccessToken('code'));
        $this->assertSame('42', $github->getUserID('gho-access'));
        $this->assertSame('octocat@example.com', $github->getUserEmail('gho-access'));
        $this->assertTrue($github->isEmailVerified('gho-access'));
        $this->assertSame('The Octocat', $github->getUserName('gho-access'));

        $repository = $github->createRepository('gho-access', 'demo', true);
        $this->assertSame('demo', $repository['name']);
        $this->assertTrue($repository['private']);
    }

    public function testGoogleLoginExchange(): void
    {
        $google = new Google('client-id', 'client-secret', 'https://example.com/callback', http: $this->http);

        $this->assertSame('ya29-access', $google->getAccessToken('code'));
        $this->assertSame('google-user-1', $google->getUserID('ya29-access'));
        $this->assertSame('user@example.com', $google->getUserEmail('ya29-access'));
        $this->assertTrue($google->isEmailVerified('ya29-access'));
        $this->assertSame('Ada Lovelace', $google->getUserName('ya29-access'));
    }

    public function testAppwriteProvider(): void
    {
        $appwrite = new Appwrite(
            'client-id',
            'client-secret',
            'https://example.com/callback',
            http: $this->http,
            stateEncryptionKey: 'e2e-openssl-key',
        );

        $url = $appwrite->getLoginURL();
        $this->assertStringContainsString('code_challenge', $url);
        $this->assertStringContainsString('https://cloud.appwrite.io/v1/oauth2/console/authorize', $url);

        $query = [];
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $appwrite->parseState($query['state'] ?? '');

        $this->assertSame('aw-access', $appwrite->getAccessToken('code'));
        $this->assertSame('user-console', $appwrite->getUserID('aw-access'));
        $this->assertSame('owner@appwrite.io', $appwrite->getUserEmail('aw-access'));
    }

    public function testVcsCreateRepository(): void
    {
        $gitlab = new Gitlab(
            'client-id',
            json_encode(['clientSecret' => 'secret', 'endpoint' => 'https://gitlab.com'], JSON_THROW_ON_ERROR),
            'https://example.com/callback',
            http: $this->http,
        );
        $this->assertSame(7, $gitlab->createRepository('token', 'demo', true)['id']);

        $bitbucket = new Bitbucket('client-id', 'secret', 'https://example.com/callback', http: $this->http);
        $created = $bitbucket->createRepository('token', 'demo', true, 'workspace');
        $this->assertSame('workspace/demo', $created['id']);
        $this->assertTrue($created['private']);

        $gitea = new Gitea('client-id', 'secret', 'https://example.com/callback', http: $this->http);
        $gitea->setEndpoint('https://gitea.example.com');
        $this->assertSame('demo', $gitea->createRepository('token', 'demo', true)['name']);
    }
}
