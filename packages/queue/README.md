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

## Idempotent publication

Redis publishers implement `Utopia\Queue\Publisher\Idempotent`. Use
`enqueueOnce()` when a producer can retry after losing the publish response:

```php
use Utopia\Queue\Publisher\Result;
use Utopia\Queue\Queue;

$queue = new Queue('my-queue');
$result = $publisher->enqueueOnce(
    $queue,
    messageId: $operationId,
    payload: ['operationId' => $operationId],
);

if ($result === Result::Existing) {
    // The same envelope was already accepted.
}
```

`Result::Enqueued` and `Result::Existing` both acknowledge success. Message IDs
are retained per queue without an implicit expiry. Reusing an ID with a
different canonical payload or priority throws
`Utopia\Queue\Exception\Conflict`.

## Visibility leases

Redis claims atomically move a pending envelope into processing state.
Visibility reclaim is disabled by default to preserve existing long-running
workers. Set an application-specific timeout on the queue to recover messages
when a worker loses its acknowledgement:

```php
$queue = new Queue('my-queue', visibilityTimeout: $leaseSeconds);
$message = $consumer->receive($queue, timeout: 2);

if ($message !== null) {
    $consumer->renew($queue, $message);
    $consumer->commit($queue, $message);
}
```

Call `renew()` before the deadline while processing valid long-running work.
Each redelivery receives a new receipt, so an acknowledgement from an older
delivery cannot complete the active claim.

## System requirements

Utopia Queue requires PHP 8.5 or later.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
