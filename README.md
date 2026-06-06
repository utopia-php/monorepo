# Utopia Client

A small PSR-18 HTTP client for PHP 8.4+.

It provides:

- `Utopia\Client`, a PSR-18 client wrapper with immutable headers, auth, base URI, and timeout helpers.
- `Utopia\Client\Adapter\Curl\Client`, a cURL transport for regular PHP runtimes.
- `Utopia\Client\Adapter\SwooleCoroutine\Client`, a Swoole coroutine transport.
- `streamRequest()`, which delivers the response body to a sink callback chunk-by-chunk so large downloads and event streams are consumed with bounded memory (see [Streaming Responses](#streaming-responses)).
- `Utopia\Psr7\*` PSR-7 messages and PSR-17 factories.
- Request factories for JSON, forms, query strings, raw bodies, and multipart uploads.
- Response decoders for JSON, form-encoded, and multipart payloads.

HTTP/1.1 is preferred by default. Redirects are disabled by default so PSR-18 callers receive the response returned by the server.

## Install

```bash
composer require utopia-php/client
```

Use `ext-curl` for the cURL adapter and `ext-swoole` for the Swoole coroutine adapter.

## Quick Start

```php
<?php

use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;

require __DIR__ . '/vendor/autoload.php';

$client = new Client(new CurlAdapter());
$requestFactory = new Request\Factory();

$request = $requestFactory->json(Method::POST, 'https://example.com/users', [
    'name' => 'Ada',
]);

$response = $client->sendRequest(
    $request,
);

echo $response->getStatusCode();
echo $response->json()['name'];
```

`Utopia\Client` implements `Psr\Http\Client\ClientInterface`, so it can be passed anywhere a PSR-18 client is expected.

## Client Defaults

Client defaults are immutable. Each `with*()` method returns a configured clone.

```php
<?php

use Utopia\Psr7\Header;
use Utopia\Psr7\ContentType;

$client = $client
    ->withBaseUri('https://api.example.com/v1')
    ->withHeaders([
        Header::ACCEPT => ContentType::JSON,
        Header::USER_AGENT => 'Acme API Client',
    ])
    ->withBearerAuth('token');
```

Configured headers are defaults. If a request already has the same header, the request header is preserved.

```php
<?php

use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;

$request = $requestFactory
    ->createRequest(Method::GET, 'users')
    ->withHeader(Header::ACCEPT, ContentType::XML);
```

Authentication helpers set the default `Authorization` header:

```php
<?php

$client = $client->withBasicAuth('username', 'password');
$client = $client->withBearerAuth('token');
```

`withBaseUri()` resolves relative request URIs before sending. Absolute request URIs are left unchanged.

## Request Factory

```php
<?php

use Utopia\Psr7\Request\Multipart\Part;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;

$requestFactory = new Request\Factory();

$json = $requestFactory->json(Method::POST, 'https://api.example.com/users', [
    'name' => 'Ada',
]);

$form = $requestFactory->form(Method::POST, 'https://api.example.com/sessions', [
    'email' => 'ada@example.com',
    'password' => 'secret',
]);

$query = $requestFactory->query(Method::GET, 'https://api.example.com/users?active=1', [
    'page' => 2,
    'search' => 'Ada Lovelace',
]);

$upload = $requestFactory->multipart(Method::POST, 'https://api.example.com/uploads', [
    'name' => 'Ada',
    'avatar' => Part::file('avatar', '/tmp/avatar.png', 'avatar.png', 'image/png'),
]);
```

Header overrides are explicit:

```php
<?php

use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;

$request = $requestFactory->json(Method::PATCH, 'https://api.example.com/users/1', [
    'name' => 'Ada',
], [
    Header::ACCEPT => 'application/vnd.api+json',
    Header::CONTENT_TYPE => ContentType::MERGE_PATCH_JSON,
]);
```

## Decode Responses

```php
<?php

$data = $response->json();
$form = $response->form();
$parts = $response->multipart();

foreach ($parts as $part) {
    $name = $part->name();
    $filename = $part->filename();
    $contentType = $part->contentType();
    $body = $part->body();
}
```

## Streaming Responses

`streamRequest()` delivers the response body to a sink callback chunk-by-chunk as
it arrives, so large downloads, Server-Sent Events, and LLM token streams are
consumed with bounded memory — the whole body is never held at once. It returns a
response carrying the status and headers; the body is empty because the body was
handed to the sink. Both adapters support it.

```php
<?php

$response = $client->streamRequest($request, function (string $chunk): void {
    echo $chunk;
});

echo $response->getStatusCode();
```

The sink runs as each chunk arrives, which means it also applies backpressure: the
transfer does not read ahead while the sink is still working. To stop early, throw
from the sink.

```php
<?php

// Parse a line-delimited (NDJSON / SSE) stream as it streams in.
$buffer = '';

$client->streamRequest($request, function (string $chunk) use (&$buffer): void {
    $buffer .= $chunk;

    while (($newline = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $newline);
        $buffer = substr($buffer, $newline + 1);
        // handle $line
    }
});
```

Notes:

- Use `sendRequest()` for normal requests — it buffers the body and returns a
  fully decodable response (`->json()`, `->form()`, `->multipart()`).
- `streamRequest()` returns only once the stream ends. For an unbounded stream
  (e.g. SSE), set the transport timeout to no-limit (`CURLOPT_TIMEOUT_MS => 0` on
  cURL, `timeout => -1` on Swoole) and stop by throwing from the sink.
- The Swoole adapter must run inside a coroutine, like `sendRequest()`.

## Timeouts

Timeout values are seconds. The helpers are immutable and delegate to the selected adapter.

```php
<?php

$client = $client
    ->withTimeout(5)
    ->withConnectTimeout(1);
```

The cURL adapter maps these to `CURLOPT_TIMEOUT_MS` and `CURLOPT_CONNECTTIMEOUT_MS`. The Swoole adapter maps them to `timeout` and `connect_timeout`.

## Configure cURL

Pass native cURL options with the `options` constructor argument. Options override adapter defaults when keys overlap.

```php
<?php

use Utopia\Client\Adapter\Curl\Client as CurlAdapter;

$adapter = new CurlAdapter(options: [
    CURLOPT_TIMEOUT_MS => 5_000,
    CURLOPT_CONNECTTIMEOUT_MS => 1_000,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
]);
```

The adapter defaults include:

- `CURLOPT_FOLLOWLOCATION => false`
- `CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1`

## Use Swoole Coroutines

The Swoole adapter must run inside a coroutine.

```php
<?php

use Swoole\Coroutine;
use Utopia\Client;
use Utopia\Client\Adapter\SwooleCoroutine\Client as SwooleAdapter;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;

require __DIR__ . '/vendor/autoload.php';

Coroutine\run(static function (): void {
    $requestFactory = new Request\Factory();

    $client = new Client(
        new SwooleAdapter(settings: [
            'timeout' => 5,
            'connect_timeout' => 1,
        ]),
    );

    $response = $client->sendRequest(
        $requestFactory->query(Method::GET, 'https://example.com', [
            'ping' => '1',
        ]),
    );

    echo $response->getStatusCode();
});
```

## Error Handling

Both adapters throw PSR-18 exceptions.

```php
<?php

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Utopia\Client\Exception\AdapterPreconditionException;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Client\Exception\DnsException;
use Utopia\Client\Exception\InvalidResponseException;
use Utopia\Client\Exception\InvalidUriException;
use Utopia\Client\Exception\ProtocolException;
use Utopia\Client\Exception\ProxyException;
use Utopia\Client\Exception\TlsException;
use Utopia\Client\Exception\TimeoutException;

try {
    $response = $client->sendRequest($request);
} catch (TimeoutException $error) {
    // Transport timeout.
} catch (DnsException $error) {
    // DNS resolution failure.
} catch (TlsException $error) {
    // TLS handshake or certificate failure.
} catch (ProxyException $error) {
    // Proxy transport failure.
} catch (ProtocolException $error) {
    // HTTP protocol transport failure.
} catch (ConnectionException $error) {
    // Connection refused, reset, unreachable, or broken.
} catch (InvalidResponseException $error) {
    // Malformed or invalid HTTP response.
} catch (InvalidUriException | AdapterPreconditionException $error) {
    // Request or runtime precondition failure.
} catch (NetworkExceptionInterface $error) {
    // DNS, connection, timeout, or transport failure.
} catch (RequestExceptionInterface $error) {
    // Invalid request or invalid response.
} catch (ClientExceptionInterface $error) {
    // Any other PSR-18 client error.
}
```

HTTP `4xx` and `5xx` responses are returned, not thrown, as required by PSR-18.

## Testing

The repository includes local copies of the relevant PSR and multipart RFC documents under `docs/`, with translated coverage requirements in `docs/testing-requirements.md`.

```bash
composer install
composer audit
composer format:check
composer refactor:check
composer analyze
composer test
```

CI runs the same checks on pull requests and pushes to `main` for PHP 8.4 and 8.5.
