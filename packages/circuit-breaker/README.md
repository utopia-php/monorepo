# Utopia Circuit Breaker

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/circuit-breaker`](https://github.com/utopia-php/monorepo/tree/main/packages/circuit-breaker) — please open issues and pull requests there.

[![Build Status](https://github.com/utopia-php/circuit-breaker/actions/workflows/tests.yml/badge.svg)](https://github.com/utopia-php/circuit-breaker/actions/workflows/tests.yml)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/circuit-breaker.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Circuit Breaker is a simple and lite library for protecting PHP applications from cascading failures when a downstream dependency misbehaves. The breaker tracks failures, short-circuits calls when a service is unhealthy, and gradually probes recovery — with optional shared state (Redis / Swoole Table) and native telemetry via [`utopia-php/telemetry`](https://github.com/utopia-php/telemetry). This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:

```bash
composer require utopia-php/circuit-breaker
```

Init in your PHP code:

```php
require_once __DIR__ . '/vendor/autoload.php';

use Utopia\CircuitBreaker\CircuitBreaker;

$breaker = new CircuitBreaker(
    threshold: 3,         // Open circuit after 3 failures
    timeout: 30,          // Try half-open after 30 seconds
    successThreshold: 2   // Require 2 successes to close circuit
);

$result = $breaker->call(
    open: fn () => 'Service unavailable - circuit is open',
    close: fn () => makeExternalApiCall(),
    halfOpen: fn () => makeExternalApiCall() // Optional: called during recovery testing
);
```

## How it Works

The circuit breaker operates in three states:

1. **CLOSED** (normal operation) — calls pass through to the protected service. Failures are counted; once they reach `threshold`, the circuit transitions to **OPEN**.
2. **OPEN** (blocking) — calls are immediately short-circuited to the `open` callback (your fallback). After `timeout` seconds the circuit transitions to **HALF_OPEN**.
3. **HALF_OPEN** (probing recovery) — the next calls execute the `halfOpen` callback (or `close` if not provided). After `successThreshold` consecutive successes the circuit transitions back to **CLOSED**; any failure immediately re-opens it.

The optional `halfOpen` callback lets you apply different behaviour while probing (shorter timeouts, smaller payloads, extra logging).

## Examples

### Using all three states

```php
use Utopia\CircuitBreaker\CircuitBreaker;

$breaker = new CircuitBreaker(threshold: 3, timeout: 30, successThreshold: 2);

$result = $breaker->call(
    open: function () {
        // Circuit is OPEN — service is down
        logger()->warning('Circuit breaker is OPEN - using fallback');
        return getCachedData() ?? ['error' => 'Service unavailable'];
    },
    close: function () {
        // Circuit is CLOSED — normal operation
        return apiClient()->fetchData();
    },
    halfOpen: function () {
        // Circuit is HALF_OPEN — testing recovery
        logger()->info('Circuit breaker testing recovery...');
        return apiClient()->fetchData(['timeout' => 5]);
    }
);
```

### Wrapping a real HTTP call

```php
use Utopia\CircuitBreaker\CircuitBreaker;

$breaker = new CircuitBreaker(threshold: 5, timeout: 60, successThreshold: 2);

$data = $breaker->call(
    open: fn () => cache()->get('user_data') ?? ['error' => 'Service temporarily unavailable'],
    close: function () {
        $response = Http::get('https://api.example.com/users');

        if (!$response->successful()) {
            throw new \Exception('API request failed');
        }

        return $response->json();
    }
);
```

### Shared cache state

By default, each `CircuitBreaker` instance keeps state in memory. To share circuit state between PHP workers, pass a cache adapter and a stable `key`.

#### Redis

```php
use Utopia\CircuitBreaker\Adapter\Redis as RedisAdapter;
use Utopia\CircuitBreaker\CircuitBreaker;

$redis = new \Redis();
$redis->connect('127.0.0.1');

$breaker = new CircuitBreaker(
    threshold: 5,
    timeout: 60,
    successThreshold: 2,
    cache: new RedisAdapter($redis),
    key: 'users-api'
);
```

#### Swoole Table

Use the Swoole adapter when workers need to share state through Swoole shared memory.

```php
use Utopia\CircuitBreaker\Adapter\SwooleTable;
use Utopia\CircuitBreaker\CircuitBreaker;

$table = SwooleTable::createTable(size: 1024);
$cache = new SwooleTable($table);

$breaker = new CircuitBreaker(
    threshold: 5,
    timeout: 60,
    successThreshold: 2,
    cache: $cache,
    key: 'users-api'
);
```

### Telemetry

Telemetry is opt-in. The `telemetry` constructor argument defaults to `null`, which emits no metrics and does not require `utopia-php/telemetry` at runtime. Install `utopia-php/telemetry` and pass any adapter to emit counters and gauges for calls, fallbacks, callback failures, transitions, state, failure counts, success counts, active calls, and transition/probe events.

```bash
composer require utopia-php/telemetry
```

```php
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\Telemetry\Adapter\OpenTelemetry;

$telemetry = new OpenTelemetry(
    'http://otel-collector:4318/v1/metrics',
    'backend',
    'orders',
    gethostname() ?: 'local'
);

$breaker = new CircuitBreaker(
    threshold: 5,
    timeout: 60,
    successThreshold: 2,
    key: 'orders-api',
    telemetry: $telemetry,
    metricPrefix: 'backend'
);

$result = $breaker->call(
    open: fn () => ['fallback' => true],
    close: fn () => $client->request('/orders')
);

$telemetry->collect();
```

By default, metrics are emitted as `breaker.*`. Pass `metricPrefix` to namespace those metric names for a host application; for example `metricPrefix: 'backend'` emits `backend.breaker.calls`.

You can also attach or replace the adapter after construction:

```php
$breaker = new CircuitBreaker(metricPrefix: 'backend');
$breaker->setTelemetry($telemetry);
```

## API

### Constructor parameters

- `threshold` (int, default `3`) — failures tolerated before opening the circuit
- `timeout` (int, default `30`) — seconds to wait before transitioning to half-open
- `successThreshold` (int, default `2`) — consecutive half-open successes required to close
- `cache` (`?Utopia\CircuitBreaker\Adapter`, default `null`) — optional shared cache adapter
- `key` (string, default `default`) — cache namespace for one circuit's state
- `telemetry` (`?Utopia\Telemetry\Adapter`, default `null`) — optional telemetry adapter
- `metricPrefix` (string, default `''`) — optional prefix for telemetry metric names (e.g. `edge`)

### `call()` parameters

```php
$breaker->call(
    open: callable,      // Required: Called when circuit is OPEN
    close: callable,     // Required: Called when circuit is CLOSED (or HALF_OPEN if no halfOpen callback)
    halfOpen: ?callable  // Optional: Called when circuit is HALF_OPEN
);
```

### State inspection

```php
$state = $breaker->getState();  // Utopia\CircuitBreaker\CircuitState enum

$breaker->isOpen();
$breaker->isClosed();
$breaker->isHalfOpen();

$breaker->getFailureCount();
$breaker->getSuccessCount();
```

## System Requirements

- PHP 8.2 or later
- Optional: `utopia-php/telemetry`, `ext-opentelemetry`, and `ext-protobuf` for OpenTelemetry metrics and the local telemetry demo
- Optional: `ext-redis` for `Utopia\CircuitBreaker\Adapter\Redis`
- Optional: `ext-swoole` for `Utopia\CircuitBreaker\Adapter\SwooleTable`

## Tests

Unit tests avoid Redis and Swoole runtime dependencies:

```bash
composer test
```

E2E tests run Redis and a PHP runtime with the Redis/Swoole extensions through Docker:

```bash
composer test:e2e:docker
```

### Local telemetry demo

Start Redis, an instrumented PHP demo server, OpenTelemetry Collector, Prometheus, and Grafana:

```bash
composer telemetry:up
```

- Demo UI: http://localhost:8080
- Grafana: http://localhost:3030/d/circuit-breaker/circuit-breaker-telemetry
- Prometheus: http://localhost:9090

Preview from a five-minute `checkout-api` scenario:

![Circuit breaker telemetry dashboard](docs/images/telemetry-dashboard.png)

Populate the dashboard with the same scenario:

```bash
composer telemetry:scenario
```

Stop the stack and remove local volumes:

```bash
composer telemetry:down
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
