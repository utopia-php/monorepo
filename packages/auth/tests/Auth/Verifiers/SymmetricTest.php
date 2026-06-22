<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Verifiers;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Issuers\Symmetric\RefreshToken;
use Utopia\Auth\Verifiers\Symmetric;
use Utopia\Auth\Verifiers\VerificationException;

final class SymmetricTest extends TestCase
{
    protected RefreshToken $issuer;

    protected Symmetric $verifier;

    protected string $iss = 'https://example.com/v1/oauth2/test';

    protected function setUp(): void
    {
        $secret = RefreshToken::generateSecret();
        $this->issuer = new RefreshToken($secret, $this->iss);
        $this->verifier = new Symmetric($secret);
    }

    public function testVerifiesIssuedToken(): void
    {
        $token = $this->issuer->issue('user-123', 'https://example.com/token', 'client-abc', 3600, ['offline_access']);
        $claims = $this->verifier
            ->setIssuer($this->iss)
            ->setAudience('https://example.com/token')
            ->verify($token);

        $this->assertEquals('user-123', $claims['sub']);
        $this->assertEquals('client-abc', $claims['client_id']);
        $this->assertEquals('offline_access', $claims['scope']);
    }

    public function testWrongSecretRejected(): void
    {
        $token = $this->issuer->issue('u', 'aud', 'c', 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Signature verification failed');
        (new Symmetric(RefreshToken::generateSecret()))->verify($token);
    }

    public function testExpiredTokenRejected(): void
    {
        $token = $this->issuer->issue('u', 'aud', 'c', -3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Token has expired');
        $this->verifier->verify($token);
    }

    public function testAudienceMismatchRejected(): void
    {
        $token = $this->issuer->issue('u', 'aud', 'c', 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Unexpected token audience');
        $this->verifier->setAudience('other')->verify($token);
    }

    public function testLeewayAllowsRecentlyExpired(): void
    {
        // Expired 10 seconds ago, but a 60s leeway tolerates the skew.
        $token = $this->issuer->issue('u', 'aud', 'c', -10);
        $claims = $this->verifier->setLeeway(60)->verify($token);

        $this->assertEquals('u', $claims['sub']);
    }
}
