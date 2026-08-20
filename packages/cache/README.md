# Utopia Cache

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/cache`](https://github.com/utopia-php/monorepo/tree/main/packages/cache) — please open issues and pull requests there.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/cache.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia framework cache library is simple and lite library for managing application cache storing, loading and purging. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting started

Install using Composer:

```bash
composer require utopia-php/cache
```

**File System Adapter**

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Filesystem;

$cache  = new Cache(new Filesystem('/cache-dir'));
$key    = 'data-from-example.com';

$data   = $cache->load($key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

if(!$data) {
    $data = file_get_contents('https://example.com');
    
    $cache->save($key, $data);
}

echo $data;
```

## Adapters

| Adapter | Notes |
| --- | --- |
| `Filesystem` | Files on disk, with optional streaming reads. |
| `Memory` | In-process array, useful in tests. |
| `None` | Stores nothing, for turning caching off. |
| `Redis` | phpredis, with reconnect and leases. |
| `Redis\Multiplexing` | Many Swoole coroutines over one Redis connection — see [the multiplexing guide](docs/multiplexing.md). |
| `RedisCluster` | phpredis against a Redis cluster. |
| `Memcached` | Memcached over the binary protocol. |
| `Hazelcast` | Hazelcast over its Memcached protocol. |
| `Sharding` | Spreads keys across several adapters. |
| `Pool` | Checks an adapter out of a `utopia-php/pools` pool per call. |
| `CircuitBreaker` | Wraps an adapter so a failing cache stops being read — see [what an open circuit sheds](#what-an-open-circuit-sheds). |

## What an open circuit sheds

`CircuitBreaker` sheds reads while its circuit is open, and still lets writes through.

Shedding reads is the point: the dependency is sick, the read would fail anyway, and refusing it early costs nothing. A write is not the same thing, because a cache write is the repair. Refusing it holds the miss rate at 100% for as long as the circuit stays open, so the traffic the breaker diverted keeps arriving at whatever it was diverted to well after the cache itself is healthy. A cache cannot warm up while it is forbidden to remember anything.

So `save()`, `saveWithLease()` and `touch()` go to the adapter even while the circuit is open, and return their usual fallback if they fail. `load()`, `list()`, `getSize()` and `ping()` are shed as before.

Two details keep that from costing anything:

**The write bypasses the breaker while open, rather than reporting to it.** The verdict is already made, so one more data point cannot change it, and what decides when the circuit closes should be the probes half-open schedules rather than repair traffic arriving at whatever rate the fallback path happens to generate. While the circuit is *not* open, a write reports normally, so a failing cache can still open the circuit.

**One failed repair ends the attempts for that open episode.** A repair is worth one timeout to learn whether the cache accepts writes and worth nothing after that: against an adapter that is not answering, retrying on every request would add its timeout to every request, which is the cost an open circuit exists to avoid. The first failure suppresses the rest of the episode, and the guard clears as soon as the circuit is no longer open — so a cache that refused writes is tried again next time, not written off for the life of the process.

## System requirements

The library requires PHP 8.4 or later. The Redis, Memcached and Hazelcast adapters need the matching extension — see the `suggest` block in `composer.json`.

## Tests

The unit tier needs nothing running:

```bash
composer test
```

The end-to-end tier runs on the host against this package's services:

```bash
docker compose up -d --wait
composer test:e2e
docker compose down -v
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
