# Utopia Auth

[![Build Status](https://travis-ci.org/utopia-php/auth.svg?branch=master)](https://travis-ci.org/utopia-php/auth)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/auth.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Auth library is a simple and lite library for handling authentication and authorization in PHP applications. This library provides a collection of secure hashing algorithms and authentication proofs for building robust authentication systems. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/auth
```

## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Features

### Supported Hashing Hashes

- **Argon2** - Modern, secure, and recommended password hashing algorithm
- **Bcrypt** - Well-established and secure password hashing
- **Scrypt** - Memory-hard password hashing algorithm
- **ScryptModified** - Modified version of Scrypt with additional features
- **SHA** - Various SHA hash implementations
- **PHPass** - Portable password hashing framework
- **MD5** (Not recommended for passwords, legacy support only)

### Token Issuers

A generic framework for minting signed [JWS](https://datatracker.ietf.org/doc/html/rfc7515) tokens. The base `Issuer` is **not** tied to any particular protocol — it owns the JWS mechanics (header assembly, `jti` generation, base64url encoding and the header/payload/signature structure) and delegates only the signing algorithm and claim set to a subclass.

## Usage

### Data Store

```php
<?php

use Utopia\Auth\Store;

// Create a new store
$store = new Store();

// Set various types of data
$store->set('userId', '12345')
      ->set('name', 'John Doe')
      ->set('isActive', true)
      ->set('preferences', ['theme' => 'dark', 'notifications' => true]);

// Get values with optional defaults
$userId = $store->get('userId');
$missing = $store->get('missing', 'default value');

// Encode store data to a base64 string
$encoded = $store->encode();

// Later, decode the string back into a store
$newStore = new Store();
$newStore->decode($encoded);

// Access the decoded data
echo $newStore->get('name'); // Outputs: John Doe
```

### Password Hashing

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Utopia\Auth\Proofs\Password;
use Utopia\Auth\Hashes\Argon2;
use Utopia\Auth\Hashes\Bcrypt;

// Initialize password authentication with default algorithms
$password = new Password();

// Hash a password (uses Argon2 by default)
$hash = $password->hash('user-password');

// Verify the password
$isValid = $password->verify('user-password', $hash);

// Use a specific algorithm with custom parameters
$bcrypt = new Bcrypt();
$bcrypt->setCost(12); // Increase cost factor for better security

$password->setHash($bcrypt);
$hash = $password->hash('user-password');
```

### Authentication Tokens

```php
<?php

use Utopia\Auth\Proofs\Token;

// Generate secure authentication tokens
$token = new Token(32); // 32 characters length
$authToken = $token->generate(); // Random token
$hashedToken = $token->hash($authToken); // Store this in database

// Later, verify the token
$isValid = $token->verify($authToken, $hashedToken);
```

### One-Time Codes

```php
<?php

use Utopia\Auth\Proofs\Code;

// Generate verification codes (e.g., for 2FA)
$code = new Code(6); // 6-digit code
$verificationCode = $code->generate();
$hashedCode = $code->hash($verificationCode);

// Verify the code
$isValid = $code->verify($verificationCode, $hashedCode);
```

### Human-Readable Phrases

```php
<?php

use Utopia\Auth\Proofs\Phrase;

// Generate memorable authentication phrases
$phrase = new Phrase();
$authPhrase = $phrase->generate(); // e.g., "Brave cat"
$hashedPhrase = $phrase->hash($authPhrase);

// Verify the phrase
$isValid = $phrase->verify($authPhrase, $hashedPhrase);
```

### Advanced Hash Configuration

```php
<?php

use Utopia\Auth\Hashes\Scrypt;
use Utopia\Auth\Hashes\Argon2;

// Configure Scrypt parameters
$scrypt = new Scrypt();
$scrypt
    ->setCpuCost(16)      // CPU/Memory cost parameter
    ->setMemoryCost(14)   // Memory cost parameter
    ->setParallelCost(2)  // Parallelization parameter
    ->setLength(64)       // Output length in bytes
    ->setSalt('randomsalt123'); // Custom salt

// Configure Argon2 parameters
$argon2 = new Argon2();
$argon2
    ->setMemoryCost(65536)  // Memory cost in KiB
    ->setTimeCost(4)        // Number of iterations
    ->setThreads(3);        // Number of threads
```

### Issuing Tokens

#### OAuth2 Access Tokens (RFC 9068)

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
    subject: 'user-123',                  // "sub" — the resource owner
    audience: ['https://api.example.com'], // "aud" — the resource server
    clientId: 'client-abc',               // "client_id" — the client it was issued to
    authTime: time(),                     // "auth_time" — when the user authenticated
    duration: 3600,                       // Lifetime in seconds ("exp")
    scopes: ['openid', 'profile', 'email']
);

$jwt = $accessToken->issue(
    subject: 'user-123',
    audience: ['https://api.example.com', 'https://mcp.example.com'],
    clientId: 'client-abc',
    authTime: time(),
    duration: 3600,
    scopes: ['openid', 'profile']
);

// Publish the public key as a JWK so resource servers can verify tokens
$jwk = $accessToken->getPublicJwk();
$keyId = $accessToken->getKeyId();
```

#### OAuth2 Refresh Tokens (HS256)

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
    subject: 'user-123',                            // "sub"
    audience: 'https://example.com/v1/oauth2/token', // "aud" — the token endpoint
    clientId: 'client-abc',                         // "client_id"
    duration: 1209600,                              // Lifetime in seconds (e.g. 14 days)
    scopes: ['openid', 'profile']
);
```

#### ID Tokens (OpenID Connect)

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
    accessToken: $jwt,            // Optional co-issued access_token (adds "at_hash")
    code: null                    // Optional co-issued authorization code (adds "c_hash")
);
```

> Both asymmetric and symmetric issuers accept an optional `keyId` constructor argument (the JWS `kid` header) for key rotation. For asymmetric issuers it is derived deterministically from the public key when omitted.

#### OAuth2 Resource Indicators (RFC 8707)

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

## Tests

To run all unit tests, use the following Docker command:

```bash
docker compose exec tests vendor/bin/phpunit --configuration phpunit.xml tests
```

To run static code analysis, use the following command:

```bash
docker compose exec tests composer check
```

## Security

We take security seriously. If you discover any security-related issues, please email security@appwrite.io instead of using the issue tracker.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](CONTRIBUTING.md).

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
