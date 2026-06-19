<?php

namespace Utopia\Auth\Issuers;

use Utopia\Auth\Issuer;

/**
 * Base class for tokens signed symmetrically with HS256.
 *
 * The signing key is a single server-held secret. These tokens can only be
 * verified by a party that holds the same secret — typically the issuing
 * authorization server itself — and are therefore never advertised on a JWKS
 * endpoint. {@see Symmetric\RefreshToken} builds on this.
 */
abstract class Symmetric extends Issuer
{
    /**
     * The shared secret used to sign and verify tokens.
     */
    protected string $secret;

    /**
     * Optional JWS "kid" header, useful when rotating secrets. Omitted when null.
     */
    protected ?string $keyId;

    /**
     * @param  string  $secret  The shared signing secret, generate using {@see generateSecret()}.
     * @param  string  $issuer  The "iss" claim value.
     * @param  string|null  $keyId  Optional "kid" header; omitted when null.
     *
     * @throws \Exception When the secret or the issuer is missing.
     */
    public function __construct(
        string $secret,
        string $issuer,
        ?string $keyId = null,
    ) {
        parent::__construct($issuer);

        if (empty($secret)) {
            throw new \Exception('A signing secret is required');
        }

        $this->secret = $secret;
        $this->keyId = $keyId;
    }

    /**
     * Generate a cryptographically strong secret suitable for HS256 signing,
     * as a random hex string.
     *
     * @param  int<1, max>  $bytes
     *
     * @throws \Exception When sufficient randomness is unavailable.
     */
    public static function generateSecret(int $bytes = 32): string
    {
        return \bin2hex(\random_bytes($bytes));
    }

    /**
     * Get the configured JWS "kid", or null when none was supplied.
     */
    public function getKeyId(): ?string
    {
        return $this->keyId;
    }

    protected function getAlgorithm(): string
    {
        return 'HS256';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getHeaders(): array
    {
        return $this->keyId !== null ? ['kid' => $this->keyId] : [];
    }

    protected function signInput(string $signingInput): string
    {
        return \hash_hmac('sha256', $signingInput, $this->secret, true);
    }
}
