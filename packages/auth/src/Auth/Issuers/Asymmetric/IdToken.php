<?php

namespace Utopia\Auth\Issuers\Asymmetric;

use Utopia\Auth\Issuers\Asymmetric;

/**
 * Issues a signed OIDC id_token (OpenID Connect Core 1.0 §2).
 *
 * One concrete {@see Asymmetric}: it inherits the RSA key handling and
 * RS256 signing and adds the id_token's claim set (sub/aud/auth_time, plus the
 * optional nonce, at_hash and c_hash).
 */
class IdToken extends Asymmetric
{
    /**
     * {@inheritdoc}
     *
     * An OIDC id_token is a plain JWT.
     */
    protected function getType(): string
    {
        return 'JWT';
    }

    /**
     * Build a signed OIDC id_token (OpenID Connect Core 1.0 §2).
     *
     * Pass $accessToken when an access_token is co-issued in the same
     * response (OIDC §3.1.3.6 — adds at_hash). Pass $code when an authorization
     * code is co-issued in the same response (OIDC §3.3.2.11, Hybrid Flow —
     * adds c_hash). Either, neither, or both may be set.
     *
     * @param  string  $subject  The "sub" claim (the authenticated user).
     * @param  string  $audience  The "aud" claim (the client the token is for).
     * @param  int  $authTime  Time the end-user authenticated ("auth_time"), as a Unix timestamp.
     * @param  int  $duration  Lifetime of the token in seconds (used for "exp").
     * @param  string|null  $nonce  The "nonce" value sent in the authentication request.
     * @param  string|null  $accessToken  Co-issued access_token; adds "at_hash" when set.
     * @param  string|null  $code  Co-issued authorization code; adds "c_hash" when set.
     * @param  array<string, mixed>  $claims  Additional claims to merge into the payload.
     *
     * @throws \Exception When signing fails.
     */
    public function issue(
        string $subject,
        string $audience,
        int $authTime,
        int $duration,
        ?string $nonce = null,
        ?string $accessToken = null,
        ?string $code = null,
        array $claims = [],
    ): string {
        $now = time();

        // nonce/at_hash/c_hash are issuer-controlled; drop any caller-supplied
        // values so they cannot be injected through $claims when the matching
        // parameter is absent (e.g. a forged at_hash binding the id_token to an
        // access token that was never co-issued).
        unset($claims['nonce'], $claims['at_hash'], $claims['c_hash']);

        $claims = array_merge($claims, [
            'iss' => $this->issuer,
            'sub' => $subject,
            'aud' => $audience,
            'exp' => $now + $duration,
            'iat' => $now,
            'auth_time' => $authTime,
        ]);

        if (!empty($nonce)) {
            $claims['nonce'] = $nonce;
        }

        if (!empty($accessToken)) {
            $claims['at_hash'] = $this->leftHalfHash($accessToken);
        }

        if (!empty($code)) {
            $claims['c_hash'] = $this->leftHalfHash($code);
        }

        return $this->sign($claims);
    }

    /**
     * OIDC §3.1.3.6 / §3.3.2.11: hash with the same algorithm family as the
     * id_token signature (SHA-256 for RS256), take the left-most half
     * (16 bytes / 128 bits), base64url-encode without padding.
     */
    protected function leftHalfHash(string $value): string
    {
        return $this->base64UrlEncode(substr(hash('sha256', $value, true), 0, 16));
    }
}
