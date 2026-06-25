# Utopia Queue

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/queue`](https://github.com/utopia-php/monorepo/tree/main/packages/queue) — please open issues and pull requests there.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/queue.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Queue is a powerful Queue library. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:

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

## Kubernetes Jobs

The `KubernetesJob` publisher triggers each queued message as a native [Kubernetes Job](https://kubernetes.io/docs/concepts/workloads/controllers/job/) instead of pushing it onto a broker. The cluster scheduler runs a pod to completion for every message, so no long-running worker process is required.

```php
<?php

use RenokiCo\PhpK8s\KubernetesCluster;
use Utopia\Queue\Broker\KubernetesJob;
use Utopia\Queue\Queue;

// Authenticate however you like — in-cluster, kubeconfig, EKS, etc.
$cluster = KubernetesCluster::inClusterConfiguration();

$publisher = new KubernetesJob(
    cluster: $cluster,
    image: 'registry.example.com/my-worker:1.0',
    kubernetesNamespace: 'queues',
);

$publisher->enqueue(new Queue('my-queue'), [
    'type' => 'test_number',
    'value' => 123,
]);
```

Each enqueued message is serialized into the `UTOPIA_QUEUE_MESSAGE` environment variable of the Job's container. Inside the worker image, rebuild the `Message` and run your job:

```php
<?php

use Utopia\Queue\Broker\KubernetesJob;

$message = KubernetesJob::message();

// ... process $message->getPayload() ...
```

`getQueueSize()` counts active (or failed) Jobs for the queue. Per-job retries are handled natively by the Job's `backoffLimit`, so `retry()` is a no-op. A queue's `jobTtl` (or the broker's `ttlSecondsAfterFinished`) controls how long finished Jobs are kept. Use `configureJob()` for advanced manifest tweaks (resource limits, image pull secrets, node selectors, volumes):

```php
$publisher->configureJob(function ($job) {
    $job->setSpec('activeDeadlineSeconds', 120);
});
```

> Note: the payload travels in an environment variable, so keep payloads small enough to fit inside the Job manifest (etcd objects are limited to ~1.5MB by default).

The end-to-end suite (`tests/e2e.sh`) provisions a [kind](https://kind.sigs.k8s.io/) cluster and uses the package `Tiltfile` to build and load the worker image — see `tests/Queue/servers/KubernetesJob`.

## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
