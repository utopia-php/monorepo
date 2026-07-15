<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Message;
use Utopia\Queue\Option\Reliable;
use Utopia\Queue\Queue;

final class ReliableSwooleTest extends TestCase
{
    private const int PORT = 16379;
    private const string NAMESPACE = 'reliable-swoole-tests';

    private \Redis $redis;
    private Queue $queue;

    #[DataProvider('capacityProvider')]
    public function testOutstandingClaimsNeverExceedCoroutineCapacity(int $maxCoroutines): void
    {
        $this->prepare();
        $publisher = $this->broker();
        $messages = $maxCoroutines * 3;
        for ($index = 0; $index < $messages; $index++) {
            $publisher->enqueue($this->queue, ['index' => $index]);
        }

        $peakClaims = 0;
        $processed = 0;

        Coroutine\run(function () use ($maxCoroutines, $messages, &$peakClaims, &$processed): void {
            $broker = $this->broker();
            $adapter = new Swoole(
                $broker,
                1,
                $this->queue->name,
                self::NAMESPACE,
                maxCoroutines: $maxCoroutines,
                reliable: $this->queue->reliable,
            );

            $adapter->consume(
                function () use ($adapter, $messages, &$peakClaims, &$processed): void {
                    $redis = new \Redis();
                    $redis->connect('127.0.0.1', self::PORT, 1.0);
                    $peakClaims = max($peakClaims, $redis->zCard($this->key('processing')));
                    $redis->close();
                    Coroutine::sleep(0.03);

                    if (++$processed === $messages) {
                        $adapter->stop();
                    }
                },
                static fn(): null => null,
                static fn(): null => null,
            );
        });

        $this->assertSame($messages, $processed);
        $this->assertLessThanOrEqual($maxCoroutines, $peakClaims);
        $this->assertSame(0, $this->redis->zCard($this->key('processing')));
    }

    public function testHeartbeatKeepsSlowHandlerOwnedUntilCommit(): void
    {
        $this->prepare(visibility: 2, heartbeat: 1, scan: 1);
        $this->broker()->enqueue($this->queue, ['slow' => true]);
        $processed = 0;
        $token = null;

        Coroutine\run(function () use (&$processed, &$token): void {
            $adapter = new Swoole(
                $this->broker(),
                1,
                $this->queue->name,
                self::NAMESPACE,
                maxCoroutines: 1,
                reliable: $this->queue->reliable,
            );

            $adapter->consume(
                function (Message $message) use ($adapter, &$processed, &$token): void {
                    $token = $message->getClaimedAt();
                    Coroutine::sleep(3.2);
                    $processed++;
                    $adapter->stop();
                },
                static fn(): null => null,
                static fn(): null => null,
            );
        });

        $this->assertNotNull($token);
        $this->assertSame(1, $processed);
        $this->assertSame(0, $this->redis->lLen($this->key('queue')));
        $this->assertSame(0, $this->stat('reclaimed'));
        $this->assertSame(1, $this->stat('success'));
        $this->assertSame(0, $this->stat('processing'));
    }

    public function testConcurrentRecoveryLoopsProduceOneReplacement(): void
    {
        $this->prepare();
        $broker = $this->broker();
        $broker->enqueue($this->queue, ['race' => true]);
        $message = $broker->receive($this->queue, 1);
        $this->assertInstanceOf(Message::class, $message);
        $this->redis->zAdd($this->key('processing'), 0, $message->getPid());
        $claim = $broker->expired($this->queue, 1)[0];
        $results = [];

        Coroutine\run(function () use ($claim, &$results): void {
            $waitGroup = new WaitGroup();
            for ($index = 0; $index < 2; $index++) {
                $waitGroup->add();
                Coroutine::create(function () use ($claim, $waitGroup, &$results): void {
                    try {
                        $results[] = $this->broker()->reclaim($this->queue, $claim);
                    } finally {
                        $waitGroup->done();
                    }
                });
            }
            $waitGroup->wait();
        });

        $messages = array_values(array_filter($results, static fn(mixed $result): bool => $result instanceof Message));
        $this->assertCount(1, $messages);
        $this->assertSame(1, $this->redis->lLen($this->key('queue')));
        $this->assertSame(1, $this->stat('reclaimed'));
        $this->assertSame(0, $this->stat('processing'));
    }

    public function testRecoveryDrainsConsecutiveBoundedBatchesInOneScan(): void
    {
        $this->prepare(scan: 1, batch: 2);
        $broker = $this->broker();
        for ($index = 0; $index < 5; $index++) {
            $broker->enqueue($this->queue, ['index' => $index]);
            $message = $broker->receive($this->queue, 1);
            $this->assertInstanceOf(Message::class, $message);
            $this->redis->zAdd($this->key('processing'), 0, $message->getPid());
        }
        $processed = 0;

        Coroutine\run(function () use (&$processed): void {
            $adapter = new Swoole(
                $this->broker(),
                1,
                $this->queue->name,
                self::NAMESPACE,
                maxCoroutines: 1,
                reliable: $this->queue->reliable,
            );
            $adapter->consume(
                function () use ($adapter, &$processed): void {
                    if (++$processed === 5) {
                        $adapter->stop();
                    }
                },
                static fn(): null => null,
                static fn(): null => null,
            );
        });

        $this->assertSame(5, $processed);
        $this->assertSame(5, $this->stat('reclaimed'));
        $this->assertSame(5, $this->stat('success'));
        $this->assertSame(0, $this->stat('processing'));
        $this->assertSame(0, $this->redis->lLen($this->key('queue')));
    }

    /** @return iterable<string, array{int}> */
    public static function capacityProvider(): iterable
    {
        yield 'one coroutine' => [1];
        yield 'three coroutines' => [3];
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->cleanup();
            $this->redis->close();
        }
    }

    private function prepare(int $visibility = 2, int $heartbeat = 1, int $scan = 1, int $batch = 100): void
    {
        $this->redis = new \Redis();
        $this->redis->connect('127.0.0.1', self::PORT, 1.0);
        $this->queue = new Queue(
            'atomic-' . bin2hex(random_bytes(6)),
            self::NAMESPACE,
            reliable: new Reliable(visibility: $visibility, heartbeat: $heartbeat, scan: $scan, batch: $batch),
        );
        $this->cleanup();
    }

    private function broker(): RedisBroker
    {
        return new RedisBroker(
            new RedisConnection('127.0.0.1', self::PORT, connectTimeout: 1.0, readTimeout: 2.0),
            new Locking(new RedisConnection('127.0.0.1', self::PORT, connectTimeout: 1.0, readTimeout: 2.0)),
        );
    }

    private function cleanup(): void
    {
        $this->redis->del([
            $this->key('queue'),
            $this->key('jobs'),
            $this->key('processing'),
            $this->key('failed'),
            $this->key('quarantine'),
            $this->statKey('total'),
            $this->statKey('processing'),
            $this->statKey('success'),
            $this->statKey('failed'),
            $this->statKey('retried'),
            $this->statKey('reclaimed'),
            $this->statKey('quarantined'),
        ]);
    }

    private function key(string $type): string
    {
        if ($type === 'queue') {
            return self::NAMESPACE . '.queue.' . $this->queue->name;
        }

        return self::NAMESPACE . '.atomic.' . $type . '.' . $this->queue->name;
    }

    private function statKey(string $stat): string
    {
        return self::NAMESPACE . '.stats.' . $this->queue->name . '.' . $stat;
    }

    private function stat(string $stat): int
    {
        return (int) ($this->redis->get($this->statKey($stat)) ?: 0);
    }
}
