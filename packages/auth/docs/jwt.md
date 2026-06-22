# JSON Web Tokens

The library mints and verifies signed [JWS](https://datatracker.ietf.org/doc/html/rfc7515)
tokens for OAuth2 and OpenID Connect. Issuers and verifiers share a common base
that owns the JWS mechanics and delegates only the signing algorithm:

- **Issuers** — `Issuer` owns header assembly, `jti` generation, base64url
  encoding and the header/payload/signature structure.
- **Verifiers** — `Verifier` owns splitting the compact form, base64url/JSON
  decoding, the `alg` guard and the standard `exp`/`nbf`/`iat`/`iss`/`aud`
  claim checks.

The two signing families are `Asymmetric` (RS256, RSA keypair) and `Symmetric`
(HS256, shared secret).

## Issuing tokens

### OAuth2 access tokens (RFC 9068)

```php
<?php

use Utopia\Auth\Issuers\Asymmetric\AccessToken;

// Generate an RSA key pair (do this once and persist the keys)
[$privateKey, $publicKey] = AccessToken::generateKeyPair();

$accessToken = new AccessToken(
    $privateKey,
    $publicKey,
    'https://example.com/v1/oauth2/my-app' // The "iss" claim (authorization server)
);

// Issue a signed RS256 access token
$jwt = $accessToken->issue(
    subject: 'user-123',                   // "sub" — the resource owner
    audience: ['https://api.example.com'], // "aud" — the resource server
    clientId: 'client-abc',                // "client_id" — the client it was issued to
    authTime: time(),                      // "auth_time" — when the user authenticated
    duration: 3600,                        // Lifetime in seconds ("exp")
    scopes: ['openid', 'profile', 'email']
);

// Publish the public key as a JWK so resource servers can verify tokens
$jwk = $accessToken->getPublicJwk();
$keyId = $accessToken->getKeyId();
```

### OAuth2 refresh tokens (HS256)

```php
<?php

use Utopia\Auth\Issuers\Symmetric\RefreshToken;

// Generate a signing secret (do this once and keep it server-side)
$secret = RefreshToken::generateSecret();

$refreshToken = new RefreshToken(
    $secret,
    'https://example.com/v1/oauth2/my-app'
);

// Issue a signed HS256 refresh token
$jwt = $refreshToken->issue(
    subject: 'user-123',                             // "sub"
    audience: 'https://example.com/v1/oauth2/token', // "aud" — the token endpoint
    clientId: 'client-abc',                          // "client_id"
    duration: 1209600,                               // Lifetime in seconds (e.g. 14 days)
    scopes: ['openid', 'profile']
);
```

### ID tokens (OpenID Connect)

```php
<?php

use Utopia\Auth\Issuers\Asymmetric\IdToken;

[$privateKey, $publicKey] = IdToken::generateKeyPair();

$idToken = new IdToken(
    $privateKey,
    $publicKey,
    'https://example.com/v1/oauth2/my-app'
);

// Issue a signed OIDC id_token
$jwt = $idToken->issue(
    subject: 'user-123',          // "sub" — the authenticated user
    audience: 'client-abc',       // "aud" — the client the token is for
    authTime: time(),             // "auth_time"
    duration: 3600,               // Lifetime in seconds ("exp")
    nonce: 'n-0S6_WzA2Mj',        // Optional "nonce" from the auth request
    accessToken: null,            // Optional co-issued access_token (adds "at_hash")
    code: null                    // Optional co-issued authorization code (adds "c_hash")
);
```

> Both asymmetric and symmetric issuers accept an optional `keyId` constructor argument (the JWS `kid` header) for key rotation. For asymmetric issuers it is derived deterministically from the public key when omitted.

### OAuth2 resource indicators (RFC 8707)

```php
<?php

use Utopia\Auth\OAuth2\ResourceIndicators;

$resources = ResourceIndicators::from([
    'https://api.example.com/',
    'https://mcp.example.com/',
]);
$previouslyGrantedResources = ResourceIndicators::from(['https://api.example.com/']);

$isAllowed = $resources->isSubsetOf($previouslyGrantedResources);
$unchanged = $resources->equals($previouslyGrantedResources);
$audience = $resources->audience('https://cloud.example.com/v1/project');
$serialized = $resources->toArray();
```

## Verifying tokens

Verify a token minted by one of the issuers (or any compliant JWS). The
signature is checked first, then the `alg` header, then whatever claim
expectations you configure. `verify()` returns the decoded claims or throws a
`VerificationException`.

```php
<?php

use Utopia\Auth\Verifiers\Asymmetric;
use Utopia\Auth\Verifiers\VerificationException;

// $publicKey is the PEM advertised on the issuer's JWKS endpoint.
$verifier = (new Asymmetric($publicKey))
    ->setIssuer('https://example.com/v1/oauth2/project')
    ->setAudience('https://example.com/v1/project')
    ->setLeeway(30); // tolerate 30s of clock skew

try {
    $claims = $verifier->verify($accessToken);
} catch (VerificationException) {
    // malformed, bad signature, wrong alg, expired, or a claim mismatch
}
```

For an OpenID Connect `id_token_hint` (which must be accepted even after it
expires), relax the time checks with `allowExpired()`:

```php
$claims = (new Asymmetric($publicKey))
    ->setIssuer($issuer)
    ->allowExpired()
    ->verify($idToken);
```

HS256 tokens (e.g. refresh tokens) are verified the same way with the shared
secret:

```php
use Utopia\Auth\Verifiers\Symmetric;

$claims = (new Symmetric($secret))
    ->setIssuer($issuer)
    ->setAudience('https://example.com/token')
    ->verify($refreshToken);
```
