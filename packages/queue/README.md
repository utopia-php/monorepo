# Utopia Queue

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/queue`](https://github.com/utopia-php/monorepo/tree/main/packages/queue) — please open issues and pull requests there.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/queue.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Queue is a powerful Queue library. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting started

Install using Composer:

```bash
composer require utopia-php/queue
```

Init in your application:

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

// Create a worker using the Swoole adapter
use Utopia\Queue;
use Utopia\Queue\Message;

$connection = new Queue\Connection\Redis('redis');

if ($connection->ping()) {
    var_dump('Connection is ready.');
} else {
    var_dump('Connection is not ready.');
}

$adapter = new Queue\Adapter\Swoole($connection, 12, 'my-queue');
$server = new Queue\Server($adapter);

// Bare job() inherits the adapter's queue ('my-queue') and maxCoroutines.
$server
    ->job()
    ->inject('message')
    ->action(function (Message $message) {
        var_dump($message);
    });

$server
    ->error()
    ->inject('error')
    ->action(function ($error) {
        echo $error->getMessage() . PHP_EOL;
    });

$server
    ->workerStart()
    ->action(function () {
        echo "Worker Started" . PHP_EOL;
    });

$server->start();


// Enqueue messages to the worker using the Redis adapter
$connection = new Redis('redis', 6379);
$client = new Client('my-queue', $connection);

$client->enqueue([
    'type' => 'test_number',
    'value' => 123
]);
```

## NATS JetStream broker

`Broker\Nats` runs the queue on [NATS JetStream](https://docs.nats.io/nats-concepts/jetstream) instead of Redis, giving durable, server-persisted jobs and native at-least-once redelivery. It implements the same `Publisher` + `Consumer` interfaces as `Broker\Redis`, so it drops into the same `Server` and adapter setup.

```php
use Utopia\NATS\Connection;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Queue;

// Pass a Closure so each forked worker / pooled lease resolves its own connection —
// a NATS connection is single-owner and must not be shared across coroutines.
$broker = new Nats(
    fn (): Connection => Connection::connect('nats://127.0.0.1:4222'),
    ackWait: 30.0,   // redelivery window if a worker dies before commit()
    maxDeliver: 5,   // delivery attempts before a message is dead-lettered
);

$broker->enqueue(new Queue('my-queue'), ['type' => 'test_number', 'value' => 123]);
```

Each queue is a WorkQueue-retention stream (a message is removed once acknowledged) with a companion dead stream. `commit()` acknowledges a message, `reject()` schedules redelivery until `maxDeliver` and then dead-letters, `retry()` re-drives the dead stream onto the queue, and `getQueueSize()` reports pending (consumer `num_pending`) or failed (dead stream) counts. `reap()` is a no-op — redelivery after `ackWait` reclaims jobs stranded by a dead worker. Requires [`utopia-php/nats`](https://github.com/utopia-php/nats).

> A NATS connection is single-owner. Run one message at a time per connection (the Swoole adapter with `maxCoroutines: 1`) or lease one connection per coroutine via `Broker\Pool` / `Utopia\Pools`.

## Multiple queues in one process

The getting-started example runs **one** queue. The adapter holds the default name (`'my-queue'`) and concurrency (`maxCoroutines`); a bare `job()` inherits both.

To run **several** queues in the same process, leave the adapter without a default queue and call `job($queue, $maxCoroutines)` once per queue. Each call is the source of truth for that queue's name, handler, and concurrency. The Swoole adapter starts a separate consume loop per job so the caps stay isolated — `v1-functions` at 8 does not share a pool with `database_db_main` at 1.

```php
use Utopia\Queue;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;

// Fresh consumer per loop: blocking receive must not share a connection
// across queues/coroutines. Share a lock-guarded connection for acks if needed.
$createConsumer = static function (): Consumer {
    return new Queue\Broker\Redis(
        receive: new Queue\Connection\Redis('redis'),
        commands: new Queue\Connection\Redis('redis'),
    );
};

// No queue on the adapter — job() owns what to consume.
$adapter = new Queue\Adapter\Swoole($createConsumer(), workerNum: 1);
$server = new Queue\Server($adapter);

$server
    ->job('v1-functions', 8)
    ->inject('message')
    ->action(function (Message $message) {
        // Handle a functions job
    });

$server
    ->job('database_db_main', 1)
    ->inject('message')
    ->action(function (Message $message) {
        // Handle a databases job
    });

$server->consumer(fn (string $queue): Consumer => $createConsumer());

$server->error()->inject('error')->action(function ($error) {
    echo $error->getMessage() . PHP_EOL;
});

$server->start();
```

| | Single queue | Multiple queues |
| --- | --- | --- |
| Queue name | Adapter default; bare `job()` inherits it | Each `job('…')` |
| Concurrency | Adapter `maxCoroutines`; bare `job()` inherits it | Each `job('…', n)` |
| Handlers | One bare `job()` | One `job($queue, $n)` per queue |
| Receive connection | Adapter's consumer | `consumer()` factory — one consumer per queue |

Publishers are unchanged: enqueue to each queue by name (`new Client('v1-functions', $connection)`, etc.).

With [`utopia-php/platform`](https://github.com/utopia-php/platform), the same shape is `Platform::init(Service::TYPE_WORKER, …)` with `workers` (`['all']` or a list) and `jobs` keyed by action name (`queue` / `maxCoroutines`). A single `workerName` still registers one queue, as before.

## System requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
