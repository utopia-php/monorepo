# Utopia Client

A small PSR-18 HTTP client for PHP 8.4+.

It provides:

- `Utopia\Client`, a PSR-18 client wrapper with immutable timeout helpers.
- `Utopia\Client\Adapter\Curl\Client`, a cURL transport for regular PHP runtimes.
- `Utopia\Client\Adapter\SwooleCoroutine\Client`, a Swoole coroutine transport.
- `Utopia\Psr7\*` PSR-7 messages and PSR-17 factories.
- Request builders for JSON, forms, query strings, raw bodies, and multipart uploads.
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
use Utopia\Psr7\RequestFactory;
use Utopia\Psr7\ResponseFactory;
use Utopia\Psr7\StreamFactory;

require __DIR__ . '/vendor/autoload.php';

$requestFactory = new RequestFactory();
$responseFactory = new ResponseFactory();
$streamFactory = new StreamFactory();

$client = new Client(
    new CurlAdapter($responseFactory, $streamFactory),
);

$response = $client->sendRequest(
    $requestFactory->createRequest('GET', 'https://example.com'),
);

echo $response->getStatusCode();
echo $response->getBody();
```

`Utopia\Client` implements `Psr\Http\Client\ClientInterface`, so it can be passed anywhere a PSR-18 client is expected.

## Request Builder

```php
<?php

use Utopia\Client\Request\Builder;
use Utopia\Client\Request\Multipart\Part;
use Utopia\Psr7\RequestFactory;
use Utopia\Psr7\StreamFactory;

$builder = new Builder(new RequestFactory(), new StreamFactory());

$json = $builder->json('POST', 'https://api.example.com/users', [
    'name' => 'Ada',
]);

$form = $builder->form('POST', 'https://api.example.com/sessions', [
    'email' => 'ada@example.com',
    'password' => 'secret',
]);

$query = $builder->query('GET', 'https://api.example.com/users?active=1', [
    'page' => 2,
    'search' => 'Ada Lovelace',
]);

$upload = $builder->multipart('POST', 'https://api.example.com/uploads', [
    'name' => 'Ada',
    'avatar' => Part::file('avatar', '/tmp/avatar.png', 'avatar.png', 'image/png'),
]);
```

Header overrides are explicit:

```php
<?php

$request = $builder->json('PATCH', 'https://api.example.com/users/1', [
    'name' => 'Ada',
], [
    'Accept' => 'application/vnd.api+json',
    'Content-Type' => 'application/merge-patch+json',
]);
```

## Response Decoder

```php
<?php

use Utopia\Client\Response\Decoder;

$decoder = new Decoder();

$data = $decoder->json($response);
$form = $decoder->form($response);
$parts = $decoder->multipart($response);

foreach ($parts as $part) {
    $name = $part->name();
    $filename = $part->filename();
    $contentType = $part->contentType();
    $body = $part->body();
}
```

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

Pass native cURL options as the third constructor argument. Options override adapter defaults when keys overlap.

```php
<?php

use Utopia\Client\Adapter\Curl\Client as CurlAdapter;

$adapter = new CurlAdapter($responseFactory, $streamFactory, [
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
use Utopia\Psr7\RequestFactory;
use Utopia\Psr7\ResponseFactory;
use Utopia\Psr7\StreamFactory;

require __DIR__ . '/vendor/autoload.php';

Coroutine\run(static function (): void {
    $requestFactory = new RequestFactory();
    $responseFactory = new ResponseFactory();
    $streamFactory = new StreamFactory();

    $client = new Client(
        new SwooleAdapter($responseFactory, $streamFactory, [
            'timeout' => 5,
            'connect_timeout' => 1,
        ]),
    );

    $response = $client->sendRequest(
        $requestFactory->createRequest('GET', 'https://example.com'),
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

try {
    $response = $client->sendRequest($request);
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
composer format:check
composer refactor:check
composer analyze
composer test
```

CI runs the same checks on pull requests and pushes to `main` for PHP 8.4 and 8.5.
