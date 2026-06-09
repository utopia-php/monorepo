# Configuration

Timeout and TLS helpers are immutable and portable across both adapters — each
`with*()` returns a configured clone and maps to the right native cURL option or
Swoole setting for you. For anything the helpers don't cover, pass native options
to the adapter constructor.

## Timeouts

Timeout values are seconds.

```php
<?php

$client = $client
    ->withTimeout(5)
    ->withConnectTimeout(1);
```

The cURL adapter maps these to `CURLOPT_TIMEOUT_MS` and `CURLOPT_CONNECTTIMEOUT_MS`. The Swoole adapter maps them to `timeout` and `connect_timeout`.

## TLS

```php
<?php

use Utopia\Client\Tls;

$client = $client
    ->withCustomCA('/etc/ssl/internal-ca.pem')                      // trust a private CA bundle
    ->withCertificate('/etc/ssl/client.pem', '/etc/ssl/client.key') // mutual TLS (optional passphrase as 3rd arg)
    ->withMinTlsVersion(Tls::V1_2);                                 // refuse anything older than TLS 1.2
```

Peer verification is on by default. `withSslVerification(false)` disables certificate verification entirely — it is insecure and intended only for local development against self-signed servers. To trust a self-signed certificate *while keeping verification on*, point `withCustomCA()` at it instead.

```php
<?php

$client = $client->withSslVerification(false); // insecure: disables certificate checks
```

## Connection reuse

Off by default, each request opens a fresh connection. `withConnectionReuse()`
keeps the underlying connection alive and reuses it for further requests to the
same origin, so the TCP/TLS handshake is paid once.

```php
<?php

$client = $client->withConnectionReuse();        // or ->withConnectionReuse(false)
```

It maps to the right transport primitive on each adapter: the cURL adapter keeps
a single persisted handle (reset between requests, connection cache preserved),
and the Swoole adapter keeps a kept-alive coroutine client. A connection is bound
to its origin, so a request to a different host transparently gets a new one.

Reuse is most useful when one adapter sends many requests to the same host — see
[pooling](pooling.md) for spreading a bounded set of reused connections across
concurrent callers.

## Native cURL options

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

## Swoole coroutine adapter

The Swoole adapter must run inside a coroutine. Pass native client settings with the `settings` constructor argument.

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
