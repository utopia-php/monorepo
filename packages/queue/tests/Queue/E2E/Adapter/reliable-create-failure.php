<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Option\Reliable;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$stage = $argv[1] ?? throw new InvalidArgumentException('Creation failure stage is required.');
$queue = $argv[2] ?? throw new InvalidArgumentException('Queue name is required.');
$maximum = match ($stage) {
    'recovery' => 1,
    'handler' => 2,
    'heartbeat' => 3,
    'legacy' => 1,
    default => throw new InvalidArgumentException("Unknown creation failure stage: {$stage}"),
};
$reliable = new Reliable(visibility: 2, heartbeat: 1, scan: 1, batch: 100);
$broker = new RedisBroker(
    new RedisConnection('127.0.0.1', 16379, connectTimeout: 1.0, readTimeout: 2.0),
    new Locking(new RedisConnection('127.0.0.1', 16379, connectTimeout: 1.0, readTimeout: 2.0)),
);
$adapter = new Swoole(
    $broker,
    1,
    $queue,
    'reliable-swoole-tests',
    maxCoroutines: 1,
    reliable: $stage === 'legacy' ? null : $reliable,
);

Coroutine::set([
    'hook_flags' => SWOOLE_HOOK_ALL,
    'max_coroutine' => $maximum,
]);
Coroutine\run(function () use ($adapter): void {
    try {
        $adapter->consume(
            static function () use ($adapter): void {
                echo "processed\n";
                $adapter->stop();
            },
            static fn(): null => null,
            static fn(): null => null,
        );
    } catch (RuntimeException) {
    }

    echo "handled\n";
});
