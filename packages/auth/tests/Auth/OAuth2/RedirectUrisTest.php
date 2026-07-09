<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\RedirectUris;

final class RedirectUrisTest extends TestCase
{
    /**
     * @param array<int, mixed> $registered
     */
    #[DataProvider('matchingProvider')]
    public function testMatches(array $registered, string $presented, bool $expected): void
    {
        $this->assertSame($expected, RedirectUris::from($registered)->matches($presented));
    }

    public function testFromFiltersMalformedEntries(): void
    {
        $uris = RedirectUris::from(['https://example.com/cb', '', 42, null, ['nested']]);

        $this->assertSame(['https://example.com/cb'], $uris->toArray());
        $this->assertTrue($uris->matches('https://example.com/cb'));
    }

    /**
     * @return \Iterator<string, array{registered: array<int, mixed>, presented: string, expected: bool}>
     */
    public static function matchingProvider(): \Iterator
    {
        // Exact matching.
        yield 'exact match' => [
            'registered' => ['https://example.com/cb'],
            'presented' => 'https://example.com/cb',
            'expected' => true,
        ];
        yield 'exact miss' => [
            'registered' => ['https://example.com/cb'],
            'presented' => 'https://example.com/other',
            'expected' => false,
        ];
        yield 'empty presented' => [
            'registered' => ['https://example.com/cb'],
            'presented' => '',
            'expected' => false,
        ];
        yield 'empty registered list' => [
            'registered' => [],
            'presented' => 'https://example.com/cb',
            'expected' => false,
        ];

        // RFC 8252 loopback port variance.
        yield 'loopback different port' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/callback',
            'expected' => true,
        ];
        yield 'loopback portless registered, ported presented' => [
            'registered' => ['http://localhost/callback'],
            'presented' => 'http://localhost:54155/callback',
            'expected' => true,
        ];
        yield 'loopback ported registered, portless presented' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost/callback',
            'expected' => true,
        ];
        yield 'loopback IPv4 different port' => [
            'registered' => ['http://127.0.0.1:3118/callback'],
            'presented' => 'http://127.0.0.1:54155/callback',
            'expected' => true,
        ];
        yield 'loopback IPv6 different port' => [
            'registered' => ['http://[::1]:3118/callback'],
            'presented' => 'http://[::1]:54155/callback',
            'expected' => true,
        ];
        yield 'loopback with matching query' => [
            'registered' => ['http://localhost:3118/callback?flow=cli'],
            'presented' => 'http://localhost:54155/callback?flow=cli',
            'expected' => true,
        ];
        yield 'loopback empty path normalizes to root' => [
            'registered' => ['http://localhost:3118'],
            'presented' => 'http://localhost:54155/',
            'expected' => true,
        ];
        yield 'loopback host is case-insensitive' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://LOCALHOST:54155/callback',
            'expected' => true,
        ];
        yield 'loopback match among multiple registered' => [
            'registered' => ['https://example.com/cb', 'http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/callback',
            'expected' => true,
        ];

        // Host strictness.
        yield 'localhost does not match 127.0.0.1' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://127.0.0.1:54155/callback',
            'expected' => false,
        ];
        yield 'loopback lookalike host' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost.evil.com:3118/callback',
            'expected' => false,
        ];

        // Scheme strictness.
        yield 'https loopback stays exact-only' => [
            'registered' => ['https://localhost:3118/callback'],
            'presented' => 'https://localhost:54155/callback',
            'expected' => false,
        ];
        yield 'custom scheme stays exact-only' => [
            'registered' => ['myapp://localhost:3118/callback'],
            'presented' => 'myapp://localhost:54155/callback',
            'expected' => false,
        ];

        // Component strictness.
        yield 'loopback different path' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/other',
            'expected' => false,
        ];
        yield 'loopback path is case-sensitive' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/Callback',
            'expected' => false,
        ];
        yield 'loopback different query' => [
            'registered' => ['http://localhost:3118/callback?flow=cli'],
            'presented' => 'http://localhost:54155/callback?flow=web',
            'expected' => false,
        ];
        yield 'loopback missing registered query' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/callback?flow=cli',
            'expected' => false,
        ];
        yield 'loopback presented fragment stays exact-only' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/callback#fragment',
            'expected' => false,
        ];
        yield 'loopback registered fragment stays exact-only' => [
            'registered' => ['http://localhost:3118/callback#fragment'],
            'presented' => 'http://localhost:54155/callback#fragment',
            'expected' => false,
        ];
        yield 'loopback userinfo stays exact-only' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://user@localhost:54155/callback',
            'expected' => false,
        ];

        // Robustness.
        yield 'malformed presented URI' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://',
            'expected' => false,
        ];
        yield 'malformed registered URI never matches loopback' => [
            'registered' => ['http://'],
            'presented' => 'http://localhost:54155/callback',
            'expected' => false,
        ];
    }
}
