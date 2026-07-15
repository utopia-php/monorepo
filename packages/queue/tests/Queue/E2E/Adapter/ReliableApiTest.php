<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Lock\Lock;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Claim;
use Utopia\Queue\Connection\Atomic;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis;
use Utopia\Queue\Connection\RedisCluster;
use Utopia\Queue\Message;
use Utopia\Queue\Option\Reliable;
use Utopia\Queue\Queue;

final class ReliableApiTest extends TestCase
{
    public function testLegacyMessageShapeDoesNotExposeClaimMetadata(): void
    {
        $message = new Message([
            'pid' => 'pid',
            'queue' => 'queue',
            'timestamp' => 123,
            'payload' => ['value' => true],
        ], '123:456');

        $this->assertSame('123:456', $message->getClaimedAt());
        $this->assertSame([
            'pid' => 'pid',
            'queue' => 'queue',
            'timestamp' => 123,
            'payload' => ['value' => true],
        ], $message->asArray());
    }

    public function testReliableQueueRejectsJobTtlBeforeAnyConnectionCall(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reliable queues do not support job TTL.');

        new Queue('ttl', jobTtl: 1, reliable: new Reliable());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedOperationProvider')]
    public function testRedisClusterReliableOperationsFailBeforeConnectingOrMutating(string $operation): void
    {
        $connection = new RedisCluster(['127.0.0.1:1'], connectTimeout: 0.01, readTimeout: 0.01);
        $broker = new RedisBroker($connection, $connection);
        $queue = new Queue('cluster', reliable: new Reliable());
        $message = new Message([
            'pid' => 'pid',
            'queue' => $queue->name,
            'timestamp' => 1,
            'payload' => [],
        ], '1:1');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Reliable queues require a single Redis connection with atomic scripting support.');

        match ($operation) {
            'enqueue' => $broker->enqueue($queue, ['value' => true]),
            'receive' => $broker->receive($queue, 0),
            'commit' => $broker->commit($queue, $message),
            'reject' => $broker->reject($queue, $message),
            'retry' => $broker->retry($queue),
            'pending size' => $broker->getQueueSize($queue),
            'failed size' => $broker->getQueueSize($queue, failedJobs: true),
            'extend' => $broker->extend($queue, $message),
            'expired' => $broker->expired($queue, 1),
            'reclaim' => $broker->reclaim($queue, new Claim('pid', '1:1')),
            default => throw new \InvalidArgumentException("Unknown operation: {$operation}"),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedOperationProvider(): iterable
    {
        foreach (['enqueue', 'receive', 'commit', 'reject', 'retry', 'pending size', 'failed size', 'extend', 'expired', 'reclaim'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('queueSizeProvider')]
    public function testReliableQueueSizeFailsBeforeReadingUnsupportedConnection(bool $failedJobs): void
    {
        $connection = new UnsupportedAtomicConnection();
        $broker = new RedisBroker($connection, $connection);
        $queue = new Queue('unsupported-size', reliable: new Reliable());

        try {
            $broker->getQueueSize($queue, $failedJobs);
            $this->fail('Reliable queue size should reject unsupported atomic connections.');
        } catch (\LogicException $error) {
            $this->assertSame(
                'Reliable queues require a single Redis connection with atomic scripting support.',
                $error->getMessage(),
            );
        }

        $this->assertSame(0, $connection->reads);
    }

    /** @return iterable<string, array{bool}> */
    public static function queueSizeProvider(): iterable
    {
        yield 'pending' => [false];
        yield 'failed' => [true];
    }

    public function testLockingRedisExposesAtomicCapability(): void
    {
        $this->assertInstanceOf(Atomic::class, new Locking(new Redis('127.0.0.1', 1)));
    }

    public function testLockingDoesNotAdvertiseAtomicSupportForRedisCluster(): void
    {
        $locking = new Locking(new RedisCluster(['127.0.0.1:1']));

        $this->assertInstanceOf(Atomic::class, $locking);
        $this->assertFalse($locking->supportsAtomic());
    }

    public function testLockingSerializesAtomicEvaluation(): void
    {
        $recorder = new ReliableRecorder();
        $connection = new RecordingAtomicConnection($recorder);
        $locking = new Locking($connection, new RecordingAtomicLock($recorder));

        $result = $locking->evaluate('return ARGV[1]', ['key', 'value'], 1);

        $this->assertSame('value', $result);
        $this->assertSame(['acquire', 'evaluate', 'release'], $recorder->events);
        $this->assertSame([['return ARGV[1]', ['key', 'value'], 1]], $connection->calls);
    }

    public function testDefaultLockPreventsConcurrentAtomicEvaluationFromInterleaving(): void
    {
        $connection = new ConcurrentAtomicConnection();
        $locking = new Locking($connection);

        \Swoole\Coroutine\run(function () use ($locking): void {
            $waitGroup = new \Swoole\Coroutine\WaitGroup();
            for ($index = 0; $index < 2; $index++) {
                $waitGroup->add();
                \Swoole\Coroutine::create(function () use ($locking, $waitGroup): void {
                    try {
                        $locking->evaluate('return 1');
                    } finally {
                        $waitGroup->done();
                    }
                });
            }
            $waitGroup->wait();
        });

        $this->assertSame(1, $connection->peak);
        $this->assertSame(['start', 'end', 'start', 'end'], $connection->events);
    }

    public function testReliableEmptyReceiveUsesBoundedNonBusyPolling(): void
    {
        $connection = new PollingAtomicConnection();
        $broker = new RedisBroker($connection, $connection);
        $queue = new Queue('polling', reliable: new Reliable(
            visibility: 2,
            heartbeat: 1,
            scan: 1,
            batch: 10,
            pollMinimum: 10,
            pollMaximum: 20,
        ));

        $started = hrtime(true);
        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $broker->receive($queue, 1));
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        $this->assertGreaterThanOrEqual(0.9, $elapsed);
        $this->assertLessThan(1.3, $elapsed);
        $this->assertGreaterThanOrEqual(10, $connection->evaluations);
        $this->assertLessThanOrEqual(110, $connection->evaluations);
    }

    public function testReliableReceiveReconnectsAndResetsAfterSuccessfulEmptyEvaluation(): void
    {
        $connection = new PollingAtomicConnection(failFirst: true);
        $broker = new RedisBroker($connection, $connection);
        $queue = new Queue('reconnect', reliable: new Reliable(
            visibility: 2,
            heartbeat: 1,
            scan: 1,
            batch: 10,
            pollMinimum: 10,
            pollMaximum: 20,
        ));
        $failures = [];
        $successes = [];
        $broker->setReconnectCallback(function (Queue $queue, \Throwable $error, int $attempt, int $sleep) use (&$failures): void {
            $failures[] = [$queue, $error, $attempt, $sleep];
        });
        $broker->setReconnectSuccessCallback(function (Queue $queue, int $attempts) use (&$successes): void {
            $successes[] = [$queue, $attempts];
        });

        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $broker->receive($queue, 1));

        $this->assertSame(1, $connection->closes);
        $this->assertGreaterThan(1, $connection->evaluations);
        $this->assertCount(1, $failures);
        $this->assertSame($queue, $failures[0][0]);
        $this->assertInstanceOf(\RedisException::class, $failures[0][1]);
        $this->assertSame(1, $failures[0][2]);
        $this->assertGreaterThanOrEqual(0, $failures[0][3]);
        $this->assertLessThanOrEqual(100, $failures[0][3]);
        $this->assertSame([[$queue, 1]], $successes);
    }

    public function testReliableReceiveDropsClaimCompletedWhileBrokerCloses(): void
    {
        $connection = new ClosingClaimConnection();
        $broker = new RedisBroker($connection, $connection);
        $queue = new Queue('closed-claim', reliable: new Reliable());
        $connection->closeWith($broker->close(...));

        $message = $broker->receive($queue, 0);

        $this->assertTrue($connection->claimed);
        $this->assertNotInstanceOf(\Utopia\Queue\Message::class, $message);
    }
}

final class ClosingClaimConnection extends InMemoryConnection implements Atomic
{
    public bool $claimed = false;

    private ?\Closure $close = null;

    public function closeWith(\Closure $close): void
    {
        $this->close = $close;
    }

    public function supportsAtomic(): bool
    {
        return true;
    }

    public function evaluate(string $script, array $arguments = [], int $keyCount = 0): mixed
    {
        $this->claimed = true;
        ($this->close ?? throw new \LogicException('Close callback is missing.'))();

        return [
            1,
            '{"pid":"claimed","queue":"closed-claim","timestamp":1,"payload":{"value":true}}',
            '1:1',
        ];
    }
}

final class UnsupportedAtomicConnection extends InMemoryConnection implements Atomic
{
    public int $reads = 0;

    public function supportsAtomic(): bool
    {
        return false;
    }

    public function evaluate(string $script, array $arguments = [], int $keyCount = 0): mixed
    {
        throw new \LogicException('Atomic evaluation should not be attempted.');
    }

    #[\Override]
    public function listSize(string $key): int
    {
        $this->reads++;

        return parent::listSize($key);
    }
}

final class RecordingAtomicConnection extends InMemoryConnection implements Atomic
{
    /** @var list<array{string, array<mixed>, int}> */
    public array $calls = [];

    public function __construct(private readonly ReliableRecorder $recorder) {}

    public function supportsAtomic(): bool
    {
        return true;
    }

    public function evaluate(string $script, array $arguments = [], int $keyCount = 0): mixed
    {
        $this->recorder->events[] = 'evaluate';
        $this->calls[] = [$script, $arguments, $keyCount];

        return $arguments[$keyCount] ?? null;
    }
}

final readonly class RecordingAtomicLock implements Lock
{
    public function __construct(private ReliableRecorder $recorder) {}

    public function acquire(float $timeout = 0.0): bool
    {
        return true;
    }

    public function tryAcquire(): bool
    {
        return true;
    }

    public function release(): void {}

    public function withLock(callable $callback, float $timeout = 0.0): mixed
    {
        $this->recorder->events[] = 'acquire';

        try {
            return $callback();
        } finally {
            $this->recorder->events[] = 'release';
        }
    }
}

final class ReliableRecorder
{
    /** @var list<string> */
    public array $events = [];
}

final class ConcurrentAtomicConnection extends InMemoryConnection implements Atomic
{
    public int $active = 0;
    public int $peak = 0;

    /** @var list<string> */
    public array $events = [];

    public function supportsAtomic(): bool
    {
        return true;
    }

    public function evaluate(string $script, array $arguments = [], int $keyCount = 0): mixed
    {
        $this->events[] = 'start';
        $this->active++;
        $this->peak = max($this->peak, $this->active);
        \Swoole\Coroutine::sleep(0.01);
        $this->active--;
        $this->events[] = 'end';

        return 1;
    }
}

final class PollingAtomicConnection extends InMemoryConnection implements Atomic
{
    public int $closes = 0;
    public int $evaluations = 0;

    public function __construct(private readonly bool $failFirst = false) {}

    public function supportsAtomic(): bool
    {
        return true;
    }

    public function evaluate(string $script, array $arguments = [], int $keyCount = 0): mixed
    {
        $this->evaluations++;
        if ($this->failFirst && $this->evaluations === 1) {
            throw new \RedisException('Redis is unavailable.');
        }

        return [0];
    }

    #[\Override]
    public function close(): void
    {
        $this->closes++;
    }
}
