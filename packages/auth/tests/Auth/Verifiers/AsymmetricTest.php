<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Verifiers;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Issuers\Asymmetric\AccessToken;
use Utopia\Auth\Issuers\Symmetric\RefreshToken;
use Utopia\Auth\Verifiers\Asymmetric;
use Utopia\Auth\Verifiers\VerificationException;

final class AsymmetricTest extends TestCase
{
    protected AccessToken $issuer;

    protected Asymmetric $verifier;

    protected string $iss = 'https://example.com/v1/oauth2/test';

    protected function setUp(): void
    {
        [$privateKey, $publicKey] = AccessToken::generateKeyPair();
        $this->issuer = new AccessToken($privateKey, $publicKey, $this->iss);
        $this->verifier = new Asymmetric($publicKey);
    }

    public function testVerifiesIssuedToken(): void
    {
        $token = $this->issuer->issue('user-123', ['https://api.example.com'], 'client-abc', 1000, 3600, ['read', 'write']);
        $claims = $this->verifier->verify($token);

        $this->assertEquals('user-123', $claims['sub']);
        $this->assertEquals($this->iss, $claims['iss']);
        $this->assertEquals(['https://api.example.com'], $claims['aud']);
        $this->assertEquals('read write', $claims['scope']);
    }

    public function testKeyIdMatchesIssuer(): void
    {
        // Issuer and verifier must agree on the "kid" for the same key.
        $this->assertSame($this->issuer->getKeyId(), $this->verifier->getKeyId());
    }

    public function testIssuerCheckPasses(): void
    {
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, 3600);
        $claims = $this->verifier->setIssuer($this->iss)->verify($token);

        $this->assertEquals($this->iss, $claims['iss']);
    }

    public function testIssuerMismatchRejected(): void
    {
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Unexpected token issuer');
        $this->verifier->setIssuer('https://evil.example.com')->verify($token);
    }

    public function testAudienceMembershipPasses(): void
    {
        $token = $this->issuer->issue('u', ['https://a.example.com', 'https://b.example.com'], 'c', 1000, 3600);
        $claims = $this->verifier->setAudience('https://b.example.com')->verify($token);

        $this->assertContains('https://b.example.com', $claims['aud']);
    }

    public function testAudienceMismatchRejected(): void
    {
        $token = $this->issuer->issue('u', ['https://a.example.com'], 'c', 1000, 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Unexpected token audience');
        $this->verifier->setAudience('https://other.example.com')->verify($token);
    }

    public function testExpiredTokenRejected(): void
    {
        // A negative duration puts "exp" in the past.
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, -3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Token has expired');
        $this->verifier->verify($token);
    }

    public function testExpiredTokenAcceptedWhenAllowed(): void
    {
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, -3600);
        $claims = $this->verifier->allowExpired()->verify($token);

        $this->assertEquals('u', $claims['sub']);
    }

    public function testTamperedSignatureRejected(): void
    {
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, 3600);
        $parts = explode('.', $token);
        $sig = $parts[2];
        // Flip the first base64url char: it encodes the high bits of the first
        // signature byte and is always significant, so the corruption is
        // deterministic (unlike the last char, whose low bits are padding).
        $first = $sig[0];
        $parts[2] = ($first === 'A' ? 'B' : 'A') . substr($sig, 1);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $this->verifier->verify(implode('.', $parts));
    }

    public function testTamperedClaimsRejected(): void
    {
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, 3600);
        $parts = explode('.', $token);
        // Swap the payload for a forged one while keeping the original signature.
        $parts[1] = rtrim(strtr(base64_encode((string) json_encode(['sub' => 'attacker'])), '+/', '-_'), '=');

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $this->verifier->verify(implode('.', $parts));
    }

    public function testWrongKeyRejected(): void
    {
        [, $otherPublic] = AccessToken::generateKeyPair();
        $token = $this->issuer->issue('u', ['aud'], 'c', 1000, 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Signature verification failed');
        (new Asymmetric($otherPublic))->verify($token);
    }

    public function testAlgorithmMismatchRejected(): void
    {
        // An HS256 token must never be accepted by the RS256 verifier.
        $hsToken = (new RefreshToken('a-shared-secret', $this->iss))->issue('u', 'aud', 'c', 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Unexpected token algorithm');
        $this->verifier->verify($hsToken);
    }

    public function testMalformedTokenRejected(): void
    {
        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Token must have three segments');
        $this->verifier->verify('not-a-jwt');
    }
}
