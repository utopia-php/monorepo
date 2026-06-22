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
signature is checked first, then the `alg` header, then the claim expectations
you pass to the constructor. `verify()` returns the decoded claims or throws a
`VerificationException`.

Expectations are passed at construction (not fluent setters) and held
read-only, so a verifier instance is immutable and safe to share across
coroutines. By default a bounded lifetime is enforced: `exp` is **required** and
must be in the future, and `nbf`/`iat` are rejected if the token isn't valid yet
or claims a future issuance. The `issuer`, `audience` and `type` checks are
opt-in — supply `type` to pin the `typ` header (e.g. `at+jwt`) so one token kind
can't be accepted in place of another.

```php
<?php

use Utopia\Auth\Verifiers\Asymmetric;
use Utopia\Auth\Verifiers\VerificationException;

// $publicKey is the PEM advertised on the issuer's JWKS endpoint.
$verifier = new Asymmetric(
    $publicKey,
    issuer: 'https://example.com/v1/oauth2/project',
    audience: 'https://example.com/v1/project',
    type: 'at+jwt', // require an RFC 9068 access token
    leeway: 30,     // tolerate 30s of clock skew
);

try {
    $claims = $verifier->verify($accessToken);
} catch (VerificationException) {
    // malformed, bad signature, wrong alg/type, expired, or a claim mismatch
}
```

For an OpenID Connect `id_token_hint` (which must be accepted even after it
expires), relax only the expiry check with `allowExpired: true` (`nbf`/`iat` are
still enforced):

```php
$claims = (new Asymmetric($publicKey, issuer: $issuer, allowExpired: true))
    ->verify($idToken);
```

HS256 tokens (e.g. refresh tokens) are verified the same way with the shared
secret:

```php
use Utopia\Auth\Verifiers\Symmetric;

$claims = (new Symmetric($secret, issuer: $issuer, audience: 'https://example.com/token'))
    ->verify($refreshToken);
```

`Verifiers\Asymmetric` also exposes `getKeyId()`, which derives the JWS `kid`
deterministically from the public key the same way the issuer does — useful for
matching a token's `kid` header or selecting the right key from a JWKS:

```php
$verifier = new Asymmetric($publicKey);
$kid = $verifier->getKeyId(); // matches the issuer's getKeyId() for the same key
```

## Claim and header names

The claim and header names used above are also available as string-backed enums,
so you can reference them without magic strings when reading verified claims:

```php
<?php

use Utopia\Auth\Enums\Claim;
use Utopia\Auth\Enums\Header;

$subject = $claims[Claim::Subject->value]; // 'sub'
$algorithm = Header::Algorithm->value;      // 'alg'
```

`Claim` covers the RFC 7519 registered claims plus the OAuth2 (RFC 9068) and
OpenID Connect claims this library issues; `Header` covers the RFC 7515 JOSE
header parameters (`typ`, `alg`, `kid`).
