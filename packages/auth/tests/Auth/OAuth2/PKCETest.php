<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\Provider;
use Utopia\Auth\OAuth2\Providers\Appwrite as AppwriteProvider;
use Utopia\Auth\OAuth2\Providers\Etsy;
use Utopia\Auth\OAuth2\Providers\Kick;
use Utopia\Auth\OAuth2\Providers\X;

final class PKCETest extends TestCase
{
    private const APP_ID = 'client-id';
    private const APP_SECRET = 'client-secret';
    private const CALLBACK = 'https://example.com/callback';
    private const KEY = 'unit-test-openssl-key';

    /**
     * @return \Iterator<string, array{class-string<Provider>}>
     */
    public static function providers(): \Iterator
    {
        yield 'etsy' => [Etsy::class];
        yield 'x' => [X::class];
        yield 'kick' => [Kick::class];
        yield 'appwrite' => [AppwriteProvider::class];
    }

    #[DataProvider('providers')]
    public function testChallengeMatchesVerifierSentAtTokenExchange(string $provider): void
    {
        $login = $this->queryOf($this->make($provider)->getLoginURL());
        $verifier = $this->exchangeAndCaptureVerifier($provider, $login['state'] ?? '');

        $this->assertNotSame('', $verifier, 'A code_verifier must be sent at token exchange.');
        $this->assertSame($this->s256($verifier), $login['code_challenge'] ?? null);
    }

    #[DataProvider('providers')]
    public function testChallengeIsNotTheRawVerifier(string $provider): void
    {
        $login = $this->queryOf($this->make($provider)->getLoginURL());
        $verifier = $this->exchangeAndCaptureVerifier($provider, $login['state'] ?? '');

        $this->assertNotSame($verifier, $login['code_challenge'] ?? null);
    }

    #[DataProvider('providers')]
    public function testLoginUrlDeclaresS256(string $provider): void
    {
        $login = $this->queryOf($this->make($provider)->getLoginURL());

        $this->assertSame('S256', $login['code_challenge_method'] ?? null);
        $this->assertNotEmpty($login['code_challenge'] ?? '');
    }

    #[DataProvider('providers')]
    public function testVerifierMatchesRfc7636(string $provider): void
    {
        $login = $this->queryOf($this->make($provider)->getLoginURL());
        $verifier = $this->exchangeAndCaptureVerifier($provider, $login['state'] ?? '');

        $this->assertGreaterThanOrEqual(43, \strlen($verifier));
        $this->assertLessThanOrEqual(128, \strlen($verifier));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]+$/', $verifier);
    }

    #[DataProvider('providers')]
    public function testVerifierIsNotExposedInLoginUrl(string $provider): void
    {
        $url = $this->make($provider)->getLoginURL();
        $verifier = $this->exchangeAndCaptureVerifier($provider, $this->queryOf($url)['state'] ?? '');

        $this->assertStringNotContainsString($verifier, (string) $url);
        $this->assertStringNotContainsString(rawurlencode($verifier), (string) $url);
    }

    #[DataProvider('providers')]
    public function testEachAuthorizationUsesAFreshVerifier(string $provider): void
    {
        $first = $this->queryOf($this->make($provider)->getLoginURL());
        $second = $this->queryOf($this->make($provider)->getLoginURL());

        $this->assertNotSame($first['code_challenge'] ?? null, $second['code_challenge'] ?? null);
        $this->assertNotSame(
            $this->exchangeAndCaptureVerifier($provider, $first['state'] ?? ''),
            $this->exchangeAndCaptureVerifier($provider, $second['state'] ?? ''),
        );
    }

    #[DataProvider('providers')]
    public function testCallerStateIsPreservedAndPkceEntryStripped(string $provider): void
    {
        $url = $this->make($provider, [
            'success' => 'https://example.com/ok',
            'failure' => 'https://example.com/no',
        ])->getLoginURL();

        $parsed = $this->make($provider)->parseState($this->queryOf($url)['state'] ?? '');

        $this->assertIsArray($parsed);
        $this->assertSame('https://example.com/ok', $parsed['success'] ?? null);
        $this->assertSame('https://example.com/no', $parsed['failure'] ?? null);
        $this->assertArrayNotHasKey('_pkce', $parsed);
    }

    #[DataProvider('providers')]
    public function testMalformedPkceStateIsRejectedWithoutError(string $provider): void
    {
        $malformed = [
            ['data' => ['nested'], 'iv' => 'aa', 'tag' => 'bb'],
            ['data' => 'zz', 'iv' => 123, 'tag' => 'bb'],
            ['data' => 'zz', 'iv' => 'aa', 'tag' => ['nested']],
            ['data' => '', 'iv' => '', 'tag' => ''],
            ['data' => 'not-hex', 'iv' => 'not-hex', 'tag' => 'not-hex'],
        ];

        foreach ($malformed as $pkce) {
            $state = $this->encodeState($provider, ['success' => 'https://example.com', '_pkce' => $pkce]);
            $parsed = $this->make($provider)->parseState($state);

            $this->assertIsArray($parsed);
            $this->assertArrayNotHasKey('_pkce', $parsed);
            $this->assertSame('https://example.com', $parsed['success'] ?? null);

            $verifier = $this->exchangeAndCaptureVerifier($provider, $state);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier);
        }
    }

    /**
     * @param class-string<Provider> $provider
     * @param array<string, mixed> $state
     */
    private function make(string $provider, array $state = [], ?FakeHttpClient $http = null): Provider
    {
        return new $provider(
            self::APP_ID,
            self::APP_SECRET,
            self::CALLBACK,
            $state,
            [],
            $http ?? new FakeHttpClient(),
            self::KEY,
        );
    }

    /**
     * @param class-string<Provider> $provider
     */
    private function exchangeAndCaptureVerifier(string $provider, string $state): string
    {
        $http = new FakeHttpClient();
        $oauth = $this->make($provider, http: $http);
        $oauth->parseState($state);
        $oauth->getAccessToken('authorization-code');

        parse_str($http->calls[0]['payload'] ?? '', $params);

        return \is_string($params['code_verifier'] ?? null) ? $params['code_verifier'] : '';
    }

    private function s256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param class-string<Provider> $provider
     * @param array<string, mixed> $state
     */
    private function encodeState(string $provider, array $state): string
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);

        return $provider === X::class
            ? rtrim(strtr(base64_encode($json), '+/', '-_'), '=')
            : $json;
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

        return $query;
    }
}
