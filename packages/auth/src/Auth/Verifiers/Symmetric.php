<?php

namespace Utopia\Auth\Verifiers;

use Utopia\Auth\Verifier;

/**
 * Verifies tokens signed symmetrically with HS256.
 *
 * Holds the same shared secret used to sign the token, so it can validate
 * tokens minted by {@see \Utopia\Auth\Issuers\Symmetric} (e.g. a refresh
 * token). The secret never leaves the issuing party, so these tokens cannot
 * be verified by third parties.
 */
class Symmetric extends Verifier
{
    /**
     * @param  string  $secret  The shared signing secret used to verify the signature.
     *
     * @throws \Exception When the secret is missing.
     */
    public function __construct(protected readonly string $secret)
    {
        if (empty($secret)) {
            throw new \Exception('A signing secret is required');
        }
    }

    protected function getAlgorithm(): string
    {
        return 'HS256';
    }

    protected function verifySignature(string $signingInput, string $signature): bool
    {
        $expected = hash_hmac('sha256', $signingInput, $this->secret, true);

        return hash_equals($expected, $signature);
    }
}
