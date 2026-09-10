<?php

declare(strict_types=1);

namespace Utopia\Auth\Issuers\Symmetric;

use Utopia\Auth\Enums\Claim;
use Utopia\Auth\Issuers\Symmetric;

/**
 * Issues application-defined HS256 JWTs without an OAuth2 or OIDC token profile.
 *
 * Applications own their custom claims and must validate them after signature
 * verification, including any purpose or resource binding used to grant access.
 */
class Jwt extends Symmetric
{
    protected function getType(): string
    {
        return 'JWT';
    }

    /**
     * Build a signed JWT with an issuer, audience and bounded lifetime.
     *
     * @param  string|array<string>  $audience  The "aud" claim (the intended recipients).
     * @param  int  $duration  Positive lifetime in seconds (used for "exp").
     * @param  array<string, mixed>  $claims  Application claims; cannot override "iss", "aud", "iat" or "exp".
     *
     * @throws \InvalidArgumentException When the duration is not positive.
     * @throws \JsonException When claims cannot be JSON-encoded.
     * @throws \Exception When signing fails.
     */
    public function issue(string|array $audience, int $duration, array $claims = []): string
    {
        if ($duration < 1) {
            throw new \InvalidArgumentException('Token duration must be greater than zero');
        }

        $now = time();

        return $this->sign([
            ...$claims,
            Claim::Issuer->value => $this->issuer,
            Claim::Audience->value => $audience,
            Claim::IssuedAt->value => $now,
            Claim::Expiration->value => $now + $duration,
        ]);
    }
}
