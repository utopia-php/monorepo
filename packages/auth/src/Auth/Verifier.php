<?php

namespace Utopia\Auth;

use Utopia\Auth\Enums\Claim;
use Utopia\Auth\Enums\Header;
use Utopia\Auth\Verifiers\VerificationException;

/**
 * Base class for verifying tokens carried as a compact JWS (RFC 7515).
 *
 * The mirror image of {@see Issuer}: it owns everything independent of the
 * signing algorithm — splitting the compact form, base64url/JSON decoding,
 * the "alg" guard and the standard claim checks ("exp"/"nbf"/"iat"/"iss"/"aud")
 * — and delegates the signature check itself to a subclass. The two families
 * live one level down: {@see \Utopia\Auth\Verifiers\Asymmetric} (RS256) and
 * {@see \Utopia\Auth\Verifiers\Symmetric} (HS256).
 *
 * Expected claim values are opt-in and configured fluently: a check only runs
 * once you supply what to compare against ({@see setIssuer()},
 * {@see setAudience()}). Time validation is on by default and can be relaxed
 * with {@see allowExpired()} (e.g. an OIDC `id_token_hint`) or
 * {@see setLeeway()} for clock skew.
 */
abstract class Verifier
{
    /**
     * Expected "iss" claim. When null the issuer is not checked.
     */
    protected ?string $issuer = null;

    /**
     * Acceptable "aud" values. A token passes when any of these appears in its
     * audience. When null the audience is not checked.
     *
     * @var array<int, string>|null
     */
    protected ?array $audience = null;

    /**
     * Whether the time-based claims ("exp"/"nbf"/"iat") are enforced.
     */
    protected bool $validateTime = true;

    /**
     * Clock-skew tolerance in seconds applied to the time-based claims.
     */
    protected int $leeway = 0;

    /**
     * The JWS "alg" header the token must carry (e.g. "RS256", "HS256").
     */
    abstract protected function getAlgorithm(): string;

    /**
     * Check the raw (binary) signature against the signing input.
     */
    abstract protected function verifySignature(string $signingInput, string $signature): bool;

    /**
     * Require the token's "iss" claim to equal $issuer.
     */
    public function setIssuer(string $issuer): static
    {
        $this->issuer = $issuer;

        return $this;
    }

    /**
     * Require the token's "aud" claim to contain at least one of these values.
     *
     * @param  string|array<int, string>  $audience
     */
    public function setAudience(string|array $audience): static
    {
        $this->audience = \is_array($audience) ? array_values($audience) : [$audience];

        return $this;
    }

    /**
     * Accept tokens whose lifetime has lapsed by skipping the time-based
     * claims. Useful for an OIDC `id_token_hint`, where the spec requires the
     * OP to accept an expired hint for a current or recent session.
     */
    public function allowExpired(bool $allow = true): static
    {
        $this->validateTime = !$allow;

        return $this;
    }

    /**
     * Allow up to $seconds of clock skew when checking the time-based claims.
     */
    public function setLeeway(int $seconds): static
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Leeway cannot be negative');
        }

        $this->leeway = $seconds;

        return $this;
    }

    /**
     * Verify a compact JWS and return its claims.
     *
     * The signature is checked first (so claims from a forged token are never
     * trusted), then the "alg" header, then the configured claim expectations.
     *
     * @return array<string, mixed>
     *
     * @throws VerificationException When the token is malformed, the signature
     *                               is invalid, or a claim fails validation.
     */
    public function verify(string $token): array
    {
        $segments = explode('.', $token);
        if (\count($segments) !== 3) {
            throw new VerificationException('Token must have three segments');
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $segments;

        $header = $this->decodeSegment($encodedHeader, 'header');
        $claims = $this->decodeSegment($encodedClaims, 'claims');

        $signature = $this->base64UrlDecode($encodedSignature);
        if ($signature === false) {
            throw new VerificationException('Signature is not valid base64url');
        }

        // Reject "none" and any algorithm other than ours before touching the
        // key, closing the classic algorithm-confusion hole.
        if (($header[Header::Algorithm->value] ?? null) !== $this->getAlgorithm()) {
            throw new VerificationException('Unexpected token algorithm');
        }

        if (!$this->verifySignature("{$encodedHeader}.{$encodedClaims}", $signature)) {
            throw new VerificationException('Signature verification failed');
        }

        $this->validateClaims($claims);

        return $claims;
    }

    /**
     * Validate the registered claims against the configured expectations.
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws VerificationException
     */
    protected function validateClaims(array $claims): void
    {
        $now = time();

        if ($this->validateTime) {
            $exp = $claims[Claim::Expiration->value] ?? null;
            if ($exp !== null) {
                if (!is_numeric($exp)) {
                    throw new VerificationException('Invalid "exp" claim');
                }
                if ($now >= (int) $exp + $this->leeway) {
                    throw new VerificationException('Token has expired');
                }
            }

            $nbf = $claims[Claim::NotBefore->value] ?? null;
            if ($nbf !== null) {
                if (!is_numeric($nbf)) {
                    throw new VerificationException('Invalid "nbf" claim');
                }
                if ($now + $this->leeway < (int) $nbf) {
                    throw new VerificationException('Token is not yet valid');
                }
            }

            $iat = $claims[Claim::IssuedAt->value] ?? null;
            if ($iat !== null) {
                if (!is_numeric($iat)) {
                    throw new VerificationException('Invalid "iat" claim');
                }
                if ($now + $this->leeway < (int) $iat) {
                    throw new VerificationException('Token was issued in the future');
                }
            }
        }

        if ($this->issuer !== null && ($claims[Claim::Issuer->value] ?? null) !== $this->issuer) {
            throw new VerificationException('Unexpected token issuer');
        }

        if ($this->audience !== null && !$this->audienceMatches($claims[Claim::Audience->value] ?? null)) {
            throw new VerificationException('Unexpected token audience');
        }
    }

    /**
     * Whether the token's "aud" claim (a string or list per RFC 7519 §4.1.3)
     * contains any of the configured acceptable audiences.
     */
    private function audienceMatches(mixed $aud): bool
    {
        $tokenAudiences = \is_array($aud) ? $aud : [$aud];

        foreach ($this->audience ?? [] as $expected) {
            if (\in_array($expected, $tokenAudiences, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Base64url-decode then JSON-decode a token segment into an object.
     *
     * @return array<string, mixed>
     *
     * @throws VerificationException When the segment is not base64url, not JSON,
     *                               or not a JSON object.
     */
    private function decodeSegment(string $segment, string $name): array
    {
        $label = ucfirst($name);

        $decoded = $this->base64UrlDecode($segment);
        if ($decoded === false) {
            throw new VerificationException("{$label} is not valid base64url");
        }

        try {
            $data = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new VerificationException("{$label} is not valid JSON");
        }

        if (!\is_array($data)) {
            throw new VerificationException("{$label} must be a JSON object");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Base64url-decode without requiring padding (RFC 7515 §2). Returns false
     * on input outside the base64url alphabet.
     */
    protected function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
